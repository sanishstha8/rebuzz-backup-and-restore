<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Replaces WordPress core (wp-admin, wp-includes and the root *.php
 * files) in one request by directory renames.
 *
 * Copying core file by file across many requests left the site running
 * a half-old, half-new core between requests, and new core running
 * against the old database sends wp-admin to upgrade.php. So the
 * backup's core is first copied, in chunks, into STAGE_DIR beside the
 * live one - the same filesystem, so a rename is near-instant - and
 * WPCB_Restore_Job swaps it in together with the database.
 *
 * Every rename is journaled in the restore workspace as it happens, so
 * a crash between the core swap and the database swap can be undone by
 * rollback() and core never runs against a database it doesn't match.
 */
// phpcs:disable WordPress.WP.AlternativeFunctions -- directory renames; WP_Filesystem offers no rename that helps here.

class WPCB_Core_Swap
{
    const STAGE_DIR = 'wpcb-core-new';
    const OLD_DIR = 'wpcb-core-old';
    const JOURNAL = 'core.swap.json';
    const DIRECTORIES = ['wp-admin', 'wp-includes'];

    private $workspace;
    private $journal = [];

    public function __construct(WPCB_Restore_Workspace $workspace)
    {
        $this->workspace = $workspace;
    }

    public static function root()
    {
        return rtrim(str_replace('\\', '/', ABSPATH), '/');
    }

    public static function stagePath()
    {
        return self::root() . '/' . self::STAGE_DIR;
    }

    public static function oldPath()
    {
        return self::root() . '/' . self::OLD_DIR;
    }

    /**
     * True if the extracted backup holds a whole core to swap in; a
     * backup made with "Include WordPress Core" off has none.
     */
    public static function backupHasCore($filesDir)
    {
        return is_dir($filesDir . '/wp-admin') && is_file($filesDir . '/wp-includes/version.php');
    }

    /** True for a path, relative to ABSPATH, that belongs to core. */
    public static function isCorePath($relative)
    {
        foreach (self::DIRECTORIES as $dir) {

            if (strpos($relative, $dir . '/') === 0) {
                return true;
            }
        }

        return strpos($relative, '/') === false && substr($relative, -4) === '.php';
    }

    /** Creates STAGE_DIR, denied to web visitors while it exists. */
    public static function prepareStage()
    {
        $stage = self::stagePath();

        if (!is_dir($stage) && !wp_mkdir_p($stage)) {
            return false;
        }

        wpcb_protect_directory($stage);

        return true;
    }

    /**
     * Swap staged core in. Live entries are renamed into OLD_DIR, not
     * deleted, so a failure part way is undone by renaming back.
     *
     * @param string[] $rootFiles Root *.php files the backup holds - named
     *                            rather than globbed, since STAGE_DIR also
     *                            holds wpcb_protect_directory()'s index.php.
     * @return array{ok: bool, error: string, swapped: int}
     */
    public function swap(array $rootFiles)
    {
        // Left by an attempt that died part way: put it back and start over.
        if ($this->rollback() === false) {
            return ['ok' => false, 'error' => 'could not undo an earlier, interrupted core swap', 'swapped' => 0];
        }

        $stage = self::stagePath();
        $old = self::oldPath();

        if (is_dir($old)) {
            wpcb_delete_path($old);
        }

        if (!wp_mkdir_p($old)) {
            return ['ok' => false, 'error' => 'could not create ' . self::OLD_DIR, 'swapped' => 0];
        }

        wpcb_protect_directory($old);

        // Live index.php is renamed in here; the placeholder must not be in the way.
        wp_delete_file($old . '/index.php');

        $names = [];

        foreach (self::DIRECTORIES as $dir) {

            if (is_dir($stage . '/' . $dir)) {
                $names[] = $dir;
            }
        }

        foreach ($rootFiles as $file) {

            if (is_file($stage . '/' . $file)) {
                $names[] = $file;
            }
        }

        $this->journal = ['committed' => false, 'ops' => []];

        if (!$this->workspace->put(self::JOURNAL, $this->journal)) {
            return ['ok' => false, 'error' => 'could not write to the restore workspace', 'swapped' => 0];
        }

        foreach ($names as $name) {

            $error = $this->moveIn($name);

            if ($error !== null) {

                $this->rollback();

                return ['ok' => false, 'error' => $error, 'swapped' => 0];
            }
        }

        self::clearOpcache();

        return ['ok' => true, 'error' => '', 'swapped' => count($names)];
    }

    /**
     * Move live $relative into OLD_DIR and the staged one into its place.
     *
     * A directory that won't rename has its entries swapped one by one
     * instead: on Windows the directory holding the running script -
     * wp-admin, for admin-ajax.php - can't be renamed while the request
     * runs. That script itself can't be renamed either, but can be
     * overwritten, so it is copied aside and then overwritten.
     *
     * @return string|null Error, or null on success.
     */
    private function moveIn($relative)
    {
        $live = self::root() . '/' . $relative;
        $stage = self::stagePath() . '/' . $relative;
        $old = self::oldPath() . '/' . $relative;

        if (file_exists($live)) {

            if ($this->rename($live, $old)) {

                // Moved out; the staged copy goes in below.

            } elseif (is_dir($live) && !is_link($live)) {

                if (!is_dir($old) && !wp_mkdir_p($old)) {
                    return sprintf('could not create %s', $this->display($old));
                }

                $children = array_unique(array_merge(
                    self::children($live),
                    is_dir($stage) ? self::children($stage) : []
                ));

                foreach ($children as $child) {

                    $error = $this->moveIn($relative . '/' . $child);

                    if ($error !== null) {
                        return $error;
                    }
                }

                return null;

            } elseif (is_file($live) && is_file($stage)) {

                if (!@copy($live, $old)) {
                    return sprintf('could not copy %s to %s', $this->display($live), $this->display($old));
                }

                $this->record(['overwrite', $live, $old]);

                if (!@copy($stage, $live)) {
                    return sprintf('could not overwrite %s', $this->display($live));
                }

                return null;

            } else {

                return sprintf('could not rename %s to %s', $this->display($live), $this->display($old));
            }
        }

        if (file_exists($stage) && !$this->rename($stage, $live)) {
            return sprintf('could not rename %s to %s', $this->display($stage), $this->display($live));
        }

        return null;
    }

    /** Journaled before the rename, so a crash mid-rename is still undone. */
    private function rename($from, $to)
    {
        $this->record(['rename', $from, $to]);

        if (@rename($from, $to)) {
            return true;
        }

        array_pop($this->journal['ops']);
        $this->workspace->put(self::JOURNAL, $this->journal);

        return false;
    }

    private function record(array $op)
    {
        $this->journal['ops'][] = $op;
        $this->workspace->put(self::JOURNAL, $this->journal);
    }

    private static function children($dir)
    {
        return array_values(array_diff(scandir($dir) ?: [], ['.', '..']));
    }

    /** The database matches the new core now; never roll it back after this. */
    public function commit()
    {
        $journal = $this->workspace->getJson(self::JOURNAL);

        if (!empty($journal)) {
            $journal['committed'] = true;
            $this->workspace->put(self::JOURNAL, $journal);
        }
    }

    public function isCommitted()
    {
        $journal = $this->workspace->getJson(self::JOURNAL);

        return !empty($journal['committed']);
    }

    /**
     * Undo an uncommitted swap, newest step first.
     *
     * @return bool|null Null if there was nothing to undo.
     */
    public function rollback()
    {
        $journal = $this->workspace->getJson(self::JOURNAL);

        if (empty($journal['ops']) || !empty($journal['committed'])) {
            return null;
        }

        $ok = true;

        foreach (array_reverse($journal['ops']) as $op) {

            list($type, $a, $b) = $op;

            if ($type === 'overwrite') {

                // $a was overwritten after its original was copied to $b.
                if (!@copy($b, $a)) {
                    $ok = false;
                }

                continue;
            }

            // Journaled but never done (crash between journal write and rename).
            if (!file_exists($b) && file_exists($a)) {
                continue;
            }

            if (!@rename($b, $a)) {
                $ok = false;
            }
        }

        // Kept on failure so the next attempt retries what is left.
        if ($ok) {
            wp_delete_file($this->workspace->file(self::JOURNAL));
        }

        self::clearOpcache();

        return $ok;
    }

    /** Remove the staged and replaced copies of core. */
    public static function removeLeftovers()
    {
        $root = self::root();

        // With live core missing, these may hold the only copy of it.
        if (!is_dir($root . '/wp-admin') || !is_dir($root . '/wp-includes')) {
            return;
        }

        foreach ([self::stagePath(), self::oldPath()] as $dir) {

            if (is_dir($dir)) {
                wpcb_delete_path($dir);
            }
        }
    }

    private static function clearOpcache()
    {
        // @ for opcache.restrict_api.
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
    }

    private function display($path)
    {
        return ltrim(substr($path, strlen(self::root())), '/');
    }
}
