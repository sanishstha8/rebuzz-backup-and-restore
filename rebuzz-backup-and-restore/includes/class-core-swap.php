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
 * against the old database sends wp-admin to upgrade.php. So core is
 * renamed in, and WPCB_Restore_Job swaps it together with the database.
 *
 * The staged core is the extracted backup itself and the replaced core
 * goes into the restore workspace, so neither sits in the web root -
 * where a server without .htaccess support (Nginx) would serve it. Only
 * when the workspace is on another filesystem, and so can't be renamed
 * from, is core copied into STAGE_DIR/OLD_DIR in the WordPress root.
 *
 * Every rename is journaled in the restore workspace as it happens, so
 * a crash between the core swap and the database swap can be undone by
 * rollback() and core never runs against a database it doesn't match.
 */
// phpcs:disable WordPress.WP.AlternativeFunctions -- directory renames; WP_Filesystem offers no rename that helps here.

class WPCB_Core_Swap
{
    /** Fallback staging folders in the WordPress root; see the class docblock. */
    const STAGE_DIR = 'wpcb-core-new';
    const OLD_DIR = 'wpcb-core-old';

    const JOURNAL = 'core.swap.json';
    const PATHS = 'core.paths.json';
    const DIRECTORIES = ['wp-admin', 'wp-includes'];

    private $workspace;
    private $journal = [];
    private $stage;
    private $old;

    public function __construct(WPCB_Restore_Workspace $workspace)
    {
        $this->workspace = $workspace;

        $paths = $workspace->getJson(self::PATHS);

        $this->stage = !empty($paths['stage']) ? $paths['stage'] : self::root() . '/' . self::STAGE_DIR;
        $this->old = !empty($paths['old']) ? $paths['old'] : self::root() . '/' . self::OLD_DIR;
    }

    public static function root()
    {
        return rtrim(str_replace('\\', '/', ABSPATH), '/');
    }

    public function stagePath()
    {
        return $this->stage;
    }

    public function oldPath()
    {
        return $this->old;
    }

    /** True once prepare() chose to swap straight from the extracted backup. */
    public function isInPlace()
    {
        return !empty($this->workspace->getJson(self::PATHS)['in_place']);
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

    /**
     * Choose where the staged and replaced core live: the workspace if a
     * directory renames between it and the WordPress root (then nothing
     * needs copying), else the fallback folders in the root.
     *
     * @param string $filesDir The workspace's extracted files/ folder.
     * @return string|false 'in_place', 'copy', or false if neither works.
     */
    public function prepare($filesDir)
    {
        $filesDir = rtrim(str_replace('\\', '/', $filesDir), '/');
        $old = rtrim(str_replace('\\', '/', $this->workspace->path()), '/') . '/core-old';

        if (self::canRenameBetween($filesDir, self::root()) && wp_mkdir_p($old)) {
            return $this->savePaths($filesDir, $old, true) ? 'in_place' : false;
        }

        $stage = self::root() . '/' . self::STAGE_DIR;

        if (!is_dir($stage) && !wp_mkdir_p($stage)) {
            return false;
        }

        wpcb_protect_directory($stage);

        return $this->savePaths($stage, self::root() . '/' . self::OLD_DIR, false) ? 'copy' : false;
    }

    /**
     * Everything swap() will need, checked while nothing has been
     * changed yet - so a server that can't swap core refuses the restore
     * before any plugin, theme or upload is overwritten.
     *
     * @return string|null What is in the way, or null if the swap can run.
     */
    public function preflight()
    {
        $root = self::root();

        if (!is_dir($this->stage)) {
            return sprintf('the staged WordPress core is missing (%s)', $this->display($this->stage));
        }

        if (!is_dir($this->old) && !wp_mkdir_p($this->old)) {
            return sprintf('could not create %s', $this->display($this->old));
        }

        // Real renames, both ways: permissions and filesystem boundaries both show up here.
        if (!self::canRenameBetween($this->stage, $root) || !self::canRenameBetween($root, $this->old)) {
            return 'folders cannot be renamed in the WordPress root folder - check that it is writable';
        }

        foreach (self::DIRECTORIES as $dir) {

            // Moving a directory to a new parent rewrites its ".." entry.
            if (is_dir($root . '/' . $dir) && !is_writable($root . '/' . $dir)) {
                return sprintf('%s is not writable', $dir);
            }
        }

        // On Windows the running script can't be renamed, so swap() overwrites it instead.
        $script = isset($_SERVER['SCRIPT_FILENAME']) ? realpath((string) $_SERVER['SCRIPT_FILENAME']) : false;

        if ($script !== false && strpos(str_replace('\\', '/', $script), $root . '/') === 0 && !is_writable($script)) {
            return sprintf('%s is not writable', $this->display(str_replace('\\', '/', $script)));
        }

        return null;
    }

    /** Rename a scratch directory from $from into $to and back. */
    private static function canRenameBetween($from, $to)
    {
        $name = 'wpcb-probe-' . wp_generate_password(8, false);

        if (!wp_mkdir_p($from . '/' . $name)) {
            return false;
        }

        $moved = @rename($from . '/' . $name, $to . '/' . $name);
        $back = $moved && @rename($to . '/' . $name, $from . '/' . $name);

        @rmdir($back || !$moved ? $from . '/' . $name : $to . '/' . $name);

        return $back;
    }

    private function savePaths($stage, $old, $inPlace)
    {
        $this->stage = $stage;
        $this->old = $old;

        return (bool) $this->workspace->put(self::PATHS, ['stage' => $stage, 'old' => $old, 'in_place' => $inPlace]);
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

        $stage = $this->stage;
        $old = $this->old;

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
        $stage = $this->stage . '/' . $relative;
        $old = $this->old . '/' . $relative;

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

    /** Remove the fallback staged and replaced copies of core from the WordPress root. */
    public static function removeLeftovers()
    {
        $root = self::root();

        // With live core missing, these may hold the only copy of it.
        if (!is_dir($root . '/wp-admin') || !is_dir($root . '/wp-includes')) {
            return;
        }

        foreach ([$root . '/' . self::STAGE_DIR, $root . '/' . self::OLD_DIR] as $dir) {

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
        $root = self::root() . '/';

        return strpos($path, $root) === 0 ? substr($path, strlen($root)) : $path;
    }
}
