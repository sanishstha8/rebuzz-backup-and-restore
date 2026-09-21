<?php

if (!defined('ABSPATH')) {
    exit;
}

class WPCB_Logger
{
    /**
     * Nothing else in this class - or anywhere else in the plugin -
     * ever calls clear() automatically, so left alone this file grows
     * for the entire installed lifetime of the plugin. Once it passes
     * this size, log() below drops it and starts fresh rather than
     * trying to trim it from the middle.
     */
    const MAX_LOG_BYTES = 5242880; // 5MB

    private $file;

    /**
     * @param string $kind 'backup' or 'restore' - selects which log
     *                     file messages go to, so a backup crash and
     *                     a restore crash don't get interleaved in
     *                     the same file under a name that only
     *                     describes one of them.
     */
    public function __construct($kind = 'restore')
    {
        $dir = wpcb_logs_dir();

        if (!file_exists($dir)) {
            wp_mkdir_p($dir);
        }

        wpcb_protect_directory($dir);

        $filename = ($kind === 'backup') ? 'backup.log' : 'restore.log';

        $this->file = $dir . '/' . $filename;
    }

    public function log($message)
    {
        $this->rotateIfTooLarge();

        $line =
            '[' . current_time('mysql') . '] ' .
            $message .
            PHP_EOL;

        /*
         * The one write in this plugin whose result is deliberately not
         * checked. There is nowhere to report a failed log write to -
         * reporting it would mean logging, which is what just failed -
         * and a lost log line must never be allowed to fail the backup
         * or restore it is only narrating. Everything that actually
         * affects the integrity of an archive (the database dump,
         * checksums.jsonl, manifest.json, the lock files) does check.
         */

        // LOCK_EX: this restore/backup's own polling loop is
        // sequential (one request at a time), but the concurrency
        // guard elsewhere (WPCB_Job::isProcessing()) doesn't
        // guarantee that in every case - and a backup and a restore
        // running at once (different jobs, different log files, but
        // e.g. two backups would share backup.log) can genuinely
        // write to the same file at the same time. Without an
        // exclusive lock, two concurrent appends can interleave
        // mid-line, corrupting a line's text rather than just losing
        // one; with it, PHP simply waits its turn.
        file_put_contents(
            $this->file,
            $line,
            FILE_APPEND | LOCK_EX
        );
    }

    public function clear()
    {
        if (file_exists($this->file)) {
            wp_delete_file($this->file);
        }
    }

    /**
     * Keep this log from growing without bound - see MAX_LOG_BYTES.
     * Checked on every log() call rather than on a schedule, since
     * this plugin has no cron-driven maintenance task of its own to
     * hang periodic cleanup off of.
     */
    private function rotateIfTooLarge()
    {
        if (!file_exists($this->file)) {
            return;
        }

        $size = filesize($this->file);

        if ($size !== false && $size > self::MAX_LOG_BYTES) {
            wp_delete_file($this->file);
        }
    }
}