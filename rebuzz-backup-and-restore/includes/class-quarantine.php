<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Removes what a restore left behind, the way UpdraftPlus does - by
 * moving it aside rather than deleting it.
 *
 * The plain overlay restore (copy each backed-up file over the live
 * one) can only add or replace; anything the live site has and the
 * backup does not survives. That makes a restore a merge rather than a
 * return to the backup's state, so a plugin installed after the backup
 * keeps its files while the backup's wp_options wipes its settings - a
 * half-present plugin nobody asked for.
 *
 * Two things this deliberately does NOT do.
 *
 * It does not delete. A restore that empties wp-content/plugins and
 * then dies - a host hard-kill mid-restore is exactly what this plugin
 * has had to handle - would leave a site with no plugins and no way
 * back. Folders are renamed into a sibling "-old" directory and stay
 * there until an admin confirms deletion.
 *
 * And it does not run *before* the copy. An earlier version renamed the
 * live directories aside first and let the restore refill them, which
 * left WordPress loading an empty or half-written plugins folder across
 * every AJAX request of a multi-minute copy. On a real site that ended
 * in a fatal inside a half-restored Jetpack, and the site could not
 * self-heal because the crash happened while plugins were still being
 * included - before any hook this plugin could listen on. So the sweep
 * runs *after* the copy has finished, when every folder on disk is
 * whole, and only moves folders the backup never contained. The site is
 * never in a partial state at any point.
 *
 * Scope is plugins/themes/uploads, and only their immediate children:
 * enough to clear a plugin or theme that should no longer be there,
 * cheap enough to be a handful of renames rather than a second pass
 * over tens of thousands of files.
 */
// phpcs:disable WordPress.WP.AlternativeFunctions -- directory moves; WP_Filesystem offers no rename that helps here.

class WPCB_Quarantine
{
    /** Legacy home of the record; see readRecord(). */
    const OPTION = 'wpcb_quarantine';

    /** How stale an active record must be before recover() acts; see recover(). */
    const RECOVER_AFTER = 1800;

    /**
     * This plugin's own folder. Never moved aside: its code is what
     * serves the remaining AJAX requests of the restore doing the
     * moving.
     */
    const OWN_DIR = 'rebuzz-backup-and-restore';

    /**
     * The directories swept, as absolute paths, resolved from the
     * constants rather than assumed - WP_PLUGIN_DIR, the theme root and
     * the uploads basedir are all relocatable, and on multisite the
     * uploads basedir is per-site.
     *
     * @return array<string,string> label => absolute path
     */
    public static function targets()
    {
        $targets = [];

        $targets['plugins'] = defined('WP_PLUGIN_DIR')
            ? WP_PLUGIN_DIR
            : WP_CONTENT_DIR . '/plugins';

        $targets['themes'] = function_exists('get_theme_root')
            ? get_theme_root()
            : WP_CONTENT_DIR . '/themes';

        $upload = wp_upload_dir();

        if (!empty($upload['basedir'])) {
            $targets['uploads'] = $upload['basedir'];
        }

        return $targets;
    }

    /**
     * Path relative to ABSPATH, matching how the backup stores entries.
     * Returns '' for anything outside ABSPATH, which is then skipped -
     * the backup holds no entry for it, so "not in the backup" would be
     * true of everything in it.
     */
    public static function relativeFromRoot($path)
    {
        $path = rtrim(str_replace('\\', '/', (string) $path), '/');
        $root = rtrim(str_replace('\\', '/', ABSPATH), '/');

        if ($path === '' || strpos($path . '/', $root . '/') !== 0) {
            return '';
        }

        return trim(substr($path, strlen($root)), '/');
    }

    /**
     * Move aside every immediate child of the swept directories that
     * the backup does not contain. Call once the file copy has
     * finished, never before it.
     *
     * A target is only swept when the backup actually contains that
     * directory. Without that check, restoring a database-only backup -
     * or one taken with uploads excluded - would move the whole live
     * uploads folder aside, which is the one way this could destroy
     * data rather than preserve it.
     *
     * @param string $jobId    Restore job, recorded for the recovery pass.
     * @param string $filesDir Extracted "files" directory in the workspace.
     * @return array{moved: string[], skipped: string[], count: int}
     */
    public static function sweepExtras($jobId, $filesDir)
    {
        $existing = self::readRecord();

        $previous = [];

        /*
         * A finished restore whose "-old" directories the admin has not
         * deleted yet. Carry them forward so the notice keeps offering
         * them; overwriting the record would leave them on disk with
         * nothing pointing at them. They are held apart from this
         * restore's own, because rollback() must never undo a restore
         * that already succeeded.
         */
        if (
            !empty($existing['dirs']) &&
            is_array($existing['dirs']) &&
            (isset($existing['state']) ? $existing['state'] : '') === 'completed'
        ) {

            $carried = (!empty($existing['previous']) && is_array($existing['previous']))
                ? $existing['previous']
                : [];

            $previous = array_merge($carried, $existing['dirs']);
        }

        $record = [
            'job'      => (string) $jobId,
            'time'     => time(),
            'state'    => 'active',
            'dirs'     => [],
            'previous' => $previous,
        ];

        $moved = [];
        $skipped = [];
        $count = 0;

        foreach (self::targets() as $label => $live) {

            $relative = self::relativeFromRoot($live);

            if ($relative === '' || !is_dir($live)) {
                $skipped[] = $label;
                continue;
            }

            // Only when the backup covers it - see the docblock.
            $source = $filesDir . '/' . $relative;

            if (!is_dir($source)) {
                $skipped[] = $label;
                continue;
            }

            $old = self::asidePath($live);
            $movedHere = 0;

            foreach (self::children($live) as $name) {

                // In the backup, so it belongs here.
                if (file_exists($source . '/' . $name)) {
                    continue;
                }

                // This plugin's own code, its storage, other sites'
                // media - see preservedPaths().
                if (self::isPreserved($live . '/' . $name)) {
                    continue;
                }

                if (!is_dir($old) && !wp_mkdir_p($old)) {
                    break;
                }

                /*
                 * Recorded before the first move rather than after the
                 * last, so a process killed part-way still leaves a
                 * record naming this "-old" directory. Nothing here can
                 * leave the site broken either way: everything moved is
                 * something the backup never had.
                 */
                if ($movedHere === 0) {

                    $record['dirs'][] = [
                        'label' => $label,
                        'live'  => $live,
                        'old'   => $old,
                    ];

                    self::writeRecord($record);
                }

                if (@rename($live . '/' . $name, $old . '/' . $name)) {
                    $movedHere++;
                    $count++;
                }
            }

            if ($movedHere > 0) {
                $moved[] = $label . ' (' . $movedHere . ')';
            } else {
                $skipped[] = $label;

                // Nothing needed moving; don't leave an empty shell.
                if (is_dir($old)) {
                    @rmdir($old);
                }
            }
        }

        if (!empty($record['dirs']) || !empty($record['previous'])) {
            self::writeRecord($record);
        }

        return ['moved' => $moved, 'skipped' => $skipped, 'count' => $count];
    }

    /** "<dir>-old", or the first numbered variant that is free. */
    private static function asidePath($live)
    {
        $base = rtrim($live, '/\\') . '-old';

        if (!file_exists($base)) {
            return $base;
        }

        for ($i = 2; $i < 1000; $i++) {

            if (!file_exists($base . '-' . $i)) {
                return $base . '-' . $i;
            }
        }

        return $base . '-' . uniqid();
    }

    /**
     * Put back everything this restore moved aside.
     *
     * Nothing is deleted here, because nothing was deleted on the way
     * out: the sweep only ever renames a folder from the live directory
     * into its "-old" sibling, so undoing it is the same rename in
     * reverse.
     */
    public static function rollback()
    {
        $record = self::readRecord();

        /*
         * Only an in-flight sweep may be undone. Without this check, a
         * restore that failed before reaching the sweep would undo the
         * *previous* restore's, silently putting back folders an admin
         * had already accepted the removal of.
         */
        if (
            empty($record['dirs']) ||
            !is_array($record['dirs']) ||
            (isset($record['state']) ? $record['state'] : '') !== 'active'
        ) {
            return false;
        }

        foreach ($record['dirs'] as $dir) {

            $live = isset($dir['live']) ? $dir['live'] : '';
            $old  = isset($dir['old']) ? $dir['old'] : '';

            if ($live === '' || $old === '' || !is_dir($old)) {
                continue;
            }

            if (!is_dir($live)) {
                wp_mkdir_p($live);
            }

            foreach (self::children($old) as $name) {

                // The restore put something back at this name; leave
                // the restored copy rather than overwrite it.
                if (file_exists($live . '/' . $name)) {
                    continue;
                }

                @rename($old . '/' . $name, $live . '/' . $name);
            }

            // Removed only if the moves emptied it; anything still
            // there is kept rather than silently deleted.
            @rmdir($old);
        }

        // An earlier restore's directories are not this restore's to
        // undo, but they still need the notice offering to delete them.
        if (!empty($record['previous'])) {

            self::writeRecord([
                'job'      => isset($record['job']) ? $record['job'] : '',
                'time'     => time(),
                'state'    => 'completed',
                'dirs'     => $record['previous'],
                'previous' => [],
            ]);

            return true;
        }

        self::clearRecord();

        return true;
    }

    /**
     * Restore finished. The moved-aside folders are now the admin's to
     * keep or delete, so stop treating them as something to roll back.
     */
    public static function markCompleted()
    {
        $record = self::readRecord();

        // Either this restore's own, or ones carried over from an
        // earlier restore the admin has not cleaned up yet.
        if (!is_array($record) || empty(self::allOldDirs($record))) {
            return;
        }

        $record['state'] = 'completed';
        $record['time']  = time();

        self::writeRecord($record);
    }

    /** The completed record, for the "delete old directories" notice. */
    public static function pending()
    {
        $record = self::readRecord();

        if (
            !is_array($record) ||
            (isset($record['state']) ? $record['state'] : '') !== 'completed'
        ) {
            return null;
        }

        $record['dirs'] = self::allOldDirs($record);

        if (empty($record['dirs'])) {
            return null;
        }

        return $record;
    }

    /**
     * Delete the moved-aside folders. Only ever reached from the admin
     * notice's button, never automatically - so a restore that looked
     * fine but wasn't can still be undone by hand.
     *
     * @return int Bytes freed.
     */
    public static function deletePending()
    {
        $record = self::pending();

        if ($record === null) {
            return 0;
        }

        $freed = 0;

        foreach ($record['dirs'] as $dir) {

            if (!empty($dir['old']) && is_dir($dir['old'])) {
                $freed += wpcb_delete_path($dir['old']);
            }
        }

        self::clearRecord();

        return $freed;
    }

    /**
     * Put back a sweep whose restore never finished.
     *
     * WPCB_Restore_Job::fail() covers an orderly failure. This covers
     * the disorderly one: a host killing the PHP process outright (an
     * LVE limit, an FPM request_terminate_timeout, an OOM kill) runs no
     * shutdown handler at all. Runs on a later request, once the
     * restore lock reports nothing in flight.
     *
     * Note this can only ever be a tidy-up, never a rescue: the sweep
     * runs after the copy, and only moves folders the backup did not
     * contain, so an interrupted sweep leaves a working site either way.
     */
    public static function recover()
    {
        $record = self::readRecord();

        if (empty($record['dirs']) || (isset($record['state']) ? $record['state'] : '') !== 'active') {
            return;
        }

        // A restore genuinely still running owns these directories.
        if (function_exists('wpcb_restore_lock_check') && wpcb_restore_lock_check() !== null) {
            return;
        }

        /*
         * That check alone is not enough to conclude the restore died.
         * It reads the job's status from a transient in wp_options, and
         * the database import drops and recreates that table - so any
         * request bootstrapping during the import (a wp-cron loopback, a
         * second admin tab, an uptime monitor) finds no status and
         * concludes "not running". This runs on plugins_loaded, on every
         * such request.
         *
         * That undid a live restore's sweep mid-flight in testing: moved
         * aside at 06:53:42, put back at 06:53:44, database restored at
         * 06:53:46 - leaving remove_extra_files silently doing nothing.
         *
         * Waiting costs nothing, because a killed restore is not coming
         * back and an interrupted sweep leaves a working site either way
         * (only folders the backup never contained are ever moved). The
         * wait just has to outlast the slowest gap between the sweep and
         * finish(), which is one whole database import.
         */
        $age = time() - (int) (isset($record['time']) ? $record['time'] : 0);

        if ($age < self::RECOVER_AFTER) {
            return;
        }

        if (class_exists('WPCB_Logger')) {

            (new WPCB_Logger('restore'))->log(
                'Found folders moved aside by a restore that never finished. Putting them back.'
            );
        }

        self::rollback();
    }

    /**
     * Absolute paths that must survive a sweep.
     *
     * This plugin's own folder is the obvious one: its code serves the
     * AJAX requests that drive the restore. Its storage directory
     * matters as much - wpcb_data_dir() defaults to
     * wp-content/uploads/rebuzz-backup-and-restore and holds the backup
     * being restored, the extraction workspace, restore.lock and the
     * logs. Neither is in the backup, because WPCB_FileScanner keeps
     * this plugin's storage out of archives, so without this both would
     * look like "not in the backup" and be moved aside.
     *
     * @return string[]
     */
    private static function preservedPaths()
    {
        $paths = [];

        $plugins = defined('WP_PLUGIN_DIR')
            ? WP_PLUGIN_DIR
            : WP_CONTENT_DIR . '/plugins';

        $paths[] = $plugins . '/' . self::OWN_DIR;

        if (function_exists('wpcb_data_dir')) {
            $paths[] = wpcb_data_dir();
        }

        /*
         * On multisite the lock deliberately lives with the network's
         * primary site, which can be a different uploads directory than
         * the one wpcb_data_dir() returns for the site being restored.
         */
        if (function_exists('wpcb_restore_lock_path')) {
            $paths[] = dirname(wpcb_restore_lock_path());
        }

        /*
         * Also multisite: sweeping the primary site's uploads would
         * otherwise catch uploads/sites, where every subsite's media
         * lives. That media is not in this site's backup - stepValidate()
         * actively refuses a backup containing another site's.
         */
        if (function_exists('is_multisite') && is_multisite() && function_exists('wpcb_restore_lock_upload_dir')) {

            $networkUploads = wpcb_restore_lock_upload_dir();

            if (!empty($networkUploads['basedir'])) {
                $paths[] = $networkUploads['basedir'] . '/sites';
            }
        }

        return array_values(array_unique(array_filter($paths)));
    }

    /** Trailing slash removed and separators normalised, for prefix tests. */
    private static function normalise($path)
    {
        return rtrim(str_replace('\\', '/', (string) $path), '/');
    }

    /**
     * True when $path is a preserved path or an ancestor of one -
     * either way, moving it would take something the restore needs.
     */
    private static function isPreserved($path)
    {
        $p = self::normalise($path);

        foreach (self::preservedPaths() as $keep) {

            $k = self::normalise($keep);

            if ($p === $k || strpos($k . '/', $p . '/') === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Where the record of moved-aside folders is kept.
     *
     * A file, not an option: a restore imports the backup's database,
     * which drops and recreates wp_options and would take the record
     * with it, leaving the folders orphaned with no notice offering to
     * delete them. WPCB_Job survives that only because it rewrites its
     * row after every import chunk.
     *
     * It sits directly in wp-content rather than in this plugin's
     * storage under uploads, because uploads is itself swept.
     */
    private static function recordPath()
    {
        if (!defined('WP_CONTENT_DIR')) {
            return '';
        }

        return WP_CONTENT_DIR . '/.wpcb-quarantine.json';
    }

    /** The stored record, or null when there is none. */
    private static function readRecord()
    {
        $path = self::recordPath();

        if ($path !== '' && file_exists($path)) {

            $data = json_decode((string) file_get_contents($path), true);

            if (is_array($data)) {
                return $data;
            }
        }

        /*
         * Fall back to the option this used to live in, so a site
         * upgrading mid-sweep still finds its folders rather than
         * stranding them.
         */
        $legacy = get_option(self::OPTION);

        return is_array($legacy) ? $legacy : null;
    }

    private static function writeRecord($record)
    {
        $path = self::recordPath();

        if ($path === '') {
            return false;
        }

        $dir = dirname($path);

        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }

        return file_put_contents($path, wp_json_encode($record)) !== false;
    }

    private static function clearRecord()
    {
        $path = self::recordPath();

        if ($path !== '' && file_exists($path)) {
            wp_delete_file($path);
        }

        // Legacy home; see readRecord().
        delete_option(self::OPTION);
    }

    /** This restore's moved-aside directories plus any carried over. */
    private static function allOldDirs($record)
    {
        $dirs = (!empty($record['previous']) && is_array($record['previous']))
            ? $record['previous']
            : [];

        if (!empty($record['dirs']) && is_array($record['dirs'])) {
            $dirs = array_merge($dirs, $record['dirs']);
        }

        return $dirs;
    }

    /** Immediate children of $dir, excluding . and .. */
    private static function children($dir)
    {
        $entries = @scandir($dir);

        if ($entries === false) {
            return [];
        }

        return array_values(array_diff($entries, ['.', '..']));
    }
}
