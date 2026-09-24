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

        $journal = ['committed' => false, 'renames' => []];

        if (!$this->workspace->put(self::JOURNAL, $journal)) {
            return ['ok' => false, 'error' => 'could not write to the restore workspace', 'swapped' => 0];
        }

        $root = self::root();

        foreach ($names as $name) {

            $live = $root . '/' . $name;

            $moves = file_exists($live)
                ? [[$live, $old . '/' . $name], [$stage . '/' . $name, $live]]
                : [[$stage . '/' . $name, $live]];

            foreach ($moves as $move) {

                // Journaled before the rename, so a crash mid-rename is still undone.
                $journal['renames'][] = $move;
                $this->workspace->put(self::JOURNAL, $journal);

                if (!@rename($move[0], $move[1])) {

                    array_pop($journal['renames']);
                    $this->workspace->put(self::JOURNAL, $journal);

                    $this->rollback();

                    return [
                        'ok' => false,
                        'error' => sprintf('could not rename %s to %s', $this->display($move[0]), $this->display($move[1])),
                        'swapped' => 0
                    ];
                }
            }
        }

        self::clearOpcache();

        return ['ok' => true, 'error' => '', 'swapped' => count($names)];
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
     * Undo an uncommitted swap, newest rename first.
     *
     * @return bool|null Null if there was nothing to undo.
     */
    public function rollback()
    {
        $journal = $this->workspace->getJson(self::JOURNAL);

        if (empty($journal['renames']) || !empty($journal['committed'])) {
            return null;
        }

        $ok = true;

        foreach (array_reverse($journal['renames']) as $move) {

            list($from, $to) = $move;

            // Journaled but never done (crash between journal write and rename).
            if (!file_exists($to) && file_exists($from)) {
                continue;
            }

            if (!@rename($to, $from)) {
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
