<?php

if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.WP.AlternativeFunctions -- WP_Filesystem has no streaming API; archives are moved in chunks to stay inside memory limits.
// phpcs:disable Squiz.PHP.DiscouragedFunctions -- set_time_limit() keeps one chunked step inside the host's timeout; it is per-request and best-effort.

class WPCB_Backup_Job
{
    /**
     * Max stack entries per call; safety net, not the main time governor.
     */
    const SCAN_BATCH_SIZE = 20000;

    /**
     * Max seconds stepScanFiles() runs before returning control to the browser.
     */
    const TIME_BUDGET_SECONDS = 20;

    private $job;


    public function __construct(WPCB_Job $job)
    {
        $this->job = $job;
    }


    private function workspace()
    {
        $state = $this->job->get();

        if (!empty($state['workspace'])) {

            return new WPCB_Workspace(
                $state['workspace']
            );

        }

        return new WPCB_Workspace();
    }



    public function processNextStep()
    {
        $state = $this->job->get();


        switch ((int) $state['step']) {


            case 0:
                return $this->stepScanFiles();


            case 1:
                return $this->stepExportDatabase();


            case 2:
                return $this->stepStatistics();


            case 3:
                return $this->stepZip();


            case 4:
                return $this->stepValidation();


            default:
                return $this->finish();

        }
    }



    /**
     * Scan files in batches, persisting stack/count between calls to
     * avoid PHP timeout/memory limits on large sites.
     */
    private function stepScanFiles()
    {
        @set_time_limit(60);

        $startTime = microtime(true);

        $workspace = $this->workspace();

        $scanner = new WPCB_FileScanner();

        $root = $scanner->root();

        if ($root === false) {
            return $this->fail(
                __('Could not resolve the site root path (ABSPATH) - cannot scan files for backup.', 'rebuzz-backup-and-restore')
            );
        }

        $state = $workspace->getJson('files.state.json');

        if (empty($state)) {

            $handle = $workspace->openWrite('files.txt');

            if (!$handle) {
                return $this->fail(__('Cannot create files list.', 'rebuzz-backup-and-restore'));
            }

            fclose($handle);

            $state = [
                'stack' => $scanner->initialStack(),
                'count' => 0,
                'folders' => 0
            ];
        }

        $stack = $state['stack'];
        $count = (int) $state['count'];
        $folders = isset($state['folders']) ? (int) $state['folders'] : 0;

        $handle = $workspace->openAppend('files.txt');

        if (!$handle) {
            return $this->fail(__('Cannot open files list.', 'rebuzz-backup-and-restore'));
        }

        $processed = 0;

        while (
            $processed < self::SCAN_BATCH_SIZE &&
            (microtime(true) - $startTime) < self::TIME_BUDGET_SECONDS &&
            !empty($stack)
        ) {

            $path = array_pop($stack);

            if (is_dir($path)) {

                if (!$scanner->shouldSkipDirectory($path, $root)) {

                    $folders++;

                    $entries = @scandir($path);

                    if ($entries !== false) {

                        foreach ($entries as $entry) {

                            if ($entry === '.' || $entry === '..') {
                                continue;
                            }

                            $stack[] = $path . DIRECTORY_SEPARATOR . $entry;

                            // Count each entry, else one huge dir bypasses the batch/time budget.
                            $processed++;
                        }

                    } else {

                        (new WPCB_Logger('backup'))->log(
                            "Could not read directory, its contents were skipped: {$path}"
                        );
                    }
                }

            } elseif (!$scanner->shouldSkipFile($path, $root)) {

                // A literal newline byte in a filename (rare, but legal
                // on POSIX filesystems) would corrupt files.txt's
                // one-path-per-line format, silently misaligning every
                // entry after it and dropping the rest of the backup.
                // Skipped and logged instead of written.
                if (strpos($path, "\n") !== false || strpos($path, "\r") !== false) {

                    (new WPCB_Logger('backup'))->log(
                        "Skipped a file with a newline/carriage-return character in its path (can't be safely listed): {$path}"
                    );

                    $processed++;
                    continue;
                }

                $line = $path . PHP_EOL;

                if (fwrite($handle, $line) !== strlen($line)) {

                    fclose($handle);

                    // A miscounted $count here becomes zip.state.json's
                    // 'total', which the ZIP step can never reach if
                    // fewer lines actually landed in files.txt than
                    // claimed - the job would poll forever instead of
                    // failing. Stop cleanly instead (likely a
                    // disk-full/permissions issue).
                    return $this->fail(
                        __('Could not write to the file list (files.txt) - the disk may be full or out of space.', 'rebuzz-backup-and-restore')
                    );
                }

                $count++;
            }

            $processed++;
        }

        fclose($handle);

        $finished = empty($stack);

        $workspace->put('files.state.json', [
            'stack' => $stack,
            'count' => $count,
            'folders' => $folders
        ]);

        if (!$finished) {

            $this->job->update([
                'status' => 'running',
                'step' => 0,
                'progress' => 5,
                /* translators: %d: number of files found so far while scanning */
                'message' => sprintf(__('Scanning files (%d found so far)...', 'rebuzz-backup-and-restore'), $count),
                'workspace' => $workspace->path()
            ]);

            return true;
        }

        $workspace->put(
            'zip.state.json',
            [
                'position' => 0,
                'total' => $count
            ]
        );



        $this->job->update([

            'status' => 'running',

            'step' => 1,

            'progress' => 10,

            'message' => __('Files scanned.', 'rebuzz-backup-and-restore'),

            'workspace' => $workspace->path()

        ]);



        return true;
    }





    /**
     * Database export
     */
    private function stepExportDatabase()
    {
        $workspace = $this->workspace();


        $database = new WPCB_Database();


        $sqlFile = $database->export(
            $workspace->file('database.sql')
        );



        if (!$sqlFile) {

            return $this->fail(
                sprintf(
                    /* translators: %s: path to the log file, relative to the WordPress root */
                    __('Database export failed - the dump could not be written in full, which usually means the disk is full or the plugin\'s temp folder is not writable. No partial dump was kept. See %s for the table it stopped on.', 'rebuzz-backup-and-restore'),
                    wpcb_display_path(wpcb_logs_dir() . '/backup.log')
                )
            );

        }



        $this->job->update([

            'step' => 2,

            'progress' => 30,

            'message' => __('Database exported.', 'rebuzz-backup-and-restore')

        ]);



        return true;
    }





    /**
     * Statistics
     */
    private function stepStatistics()
    {
        $workspace = $this->workspace();


        $statistics = new WPCB_Statistics();

        $scanState = $workspace->getJson('files.state.json');
        $folders = isset($scanState['folders']) ? (int) $scanState['folders'] : 0;

        $stats = $statistics->generate(

            $this->readFileList($workspace),

            $workspace->file(
                'database.sql'
            ),

            $folders

        );



        $workspace->put(

            'statistics.json',

            $stats

        );



        $this->job->update([

            'step' => 3,

            'progress' => 45,

            'message' => __('Preparing ZIP.', 'rebuzz-backup-and-restore')

        ]);



        return true;

    }





    /**
     * ZIP batches. Hashes each file in the same pass it's zipped (not a
     * separate pass) so the checksum can never drift from what's archived.
     * Hashes stream to checksums.jsonl; manifest is built and appended last.
     */
    private function stepZip()
    {
        $workspace = $this->workspace();


        $batch = new WPCB_Zip_Batch();



        $result = $batch->process(
            $workspace
        );



        if ($result === false) {

            return $this->fail(
                __('ZIP creation failed.', 'rebuzz-backup-and-restore')
            );

        }




        if (!$result['finished']) {


            $progress = 45 + (

                ($result['position']
                /
                max(1, $result['total']))
                * 50

            );



            $this->job->update([

                'status' => 'running',

                'step' => 3,

                'progress' => (int)$progress,

                'message' => sprintf(

                    /* translators: 1: number of files compressed so far, 2: total number of files to compress */
                    __('Compressing & verifying files %1$d/%2$d', 'rebuzz-backup-and-restore'),

                    $result['position'],

                    $result['total']

                )

            ]);



            return true;

        }




        $backupDir = wpcb_backups_dir();


        if (!is_dir($backupDir)) {

            wp_mkdir_p($backupDir);

        }

        wpcb_protect_directory($backupDir);


        /*
         * 32 characters from wp_generate_password() (CSPRNG-backed via
         * wp_rand()), with the timestamp kept only for human legibility.
         *
         * This is defence in depth, not the protection. What keeps a
         * finished archive from being downloaded is that it is only ever
         * written somewhere private: either outside the served tree via
         * WPCB_BACKUP_DIR, or a folder wpcb_storage_is_private() has
         * confirmed the server will not serve. Without one of those the
         * backup is refused before it starts, and discarded below if the
         * situation changed while it ran. The random name simply means
         * that if an archive is ever exposed by some other mistake, its
         * URL still cannot be guessed.
         *
         * It replaced 6 hex characters, which carried only 24 bits -
         * inside brute-force range, and at the time it was wrongly the
         * only thing standing between the archive and the open web.
         *
         * gmdate(), not date(): this is a filename, so it must be stable
         * regardless of the site's timezone setting.
         */
        $token = function_exists('wp_generate_password')
            ? wp_generate_password(32, false, false)
            : bin2hex(random_bytes(16));

        $base = $backupDir . '/backup-' . gmdate('Y-m-d-H-i-s') . '-' . $token;

        $finalZip = $base . '.zip';

        $suffix = 1;

        while (file_exists($finalZip)) {
            $finalZip = $base . '-' . $suffix . '.zip';
            $suffix++;
        }


        // rename() can fail silently across filesystems (e.g. network storage); fall back to copy+unlink.
        if (!@rename($result['zip'], $finalZip)) {

            if (!@copy($result['zip'], $finalZip)) {

                return $this->fail(
                    __('Could not move the finished backup into the backups folder.', 'rebuzz-backup-and-restore')
                );
            }

            wp_delete_file($result['zip']);
        }

        wpcb_restrict_file_permissions($finalZip);

        /*
         * Re-test now the archive is actually on disk, rather than
         * trusting the result from when the job started - a rule file
         * could have been removed, or the site moved, during the run.
         */
        delete_transient('wpcb_storage_exposure');

        if (!wpcb_storage_is_private()) {

            /*
             * Deleted, not kept and warned about. The archive is a
             * complete database dump sitting somewhere the web server
             * will serve on request, and leaving it there is the exact
             * hole this check exists to close. The backup fails loudly
             * so nobody believes they have a backup they do not have.
             */
            wp_delete_file($finalZip);

            (new WPCB_Logger('backup'))->log(
                'Backup discarded after completion: the backups folder is publicly readable over HTTP, so keeping the archive would have exposed the database.'
            );

            return $this->fail(wpcb_storage_insecure_reason());
        }


        $this->job->update([

            'step' => 4,

            'progress' => 95,

            'message' => __('ZIP completed.', 'rebuzz-backup-and-restore'),

            'zip' => $finalZip,

            'files_failed' => $result['failed'] ?? 0

        ]);



        return true;

    }





    /**
     * Validate
     */
    private function stepValidation()
    {
        $state = $this->job->get();


        $validator = new WPCB_Validator();



        $result = $validator->validate(

            $state['zip']

        );



        if (!$result['success']) {

            return $this->fail(
                $result['message']
            );

        }



        $this->job->update([

            'step' => 5,

            'progress' => 100,

            'message' => __('Backup validated.', 'rebuzz-backup-and-restore')

        ]);



        return true;

    }





    private function finish()
    {
        // ZIP already moved out; workspace is now scratch data - clean it up.

        $state = $this->job->get();

        if (!empty($state['workspace'])) {

            $workspace = new WPCB_Workspace(
                $state['workspace']
            );

            $workspace->cleanup();

        }

        wpcb_backup_lock_release();

        $failed = isset($state['files_failed']) ? (int) $state['files_failed'] : 0;

        // Surface skipped-file failures instead of reporting silent unqualified success.
        $message = $failed > 0
            ? sprintf(
                /* translators: 1: number of files that could not be added to the backup, 2: path to the log file, relative to the WordPress root */
                __('Backup completed, but %1$d file(s) could not be added and were skipped. Check %2$s for the list.', 'rebuzz-backup-and-restore'),
                $failed,
                wpcb_display_path(wpcb_logs_dir() . '/backup.log')
            )
            : __('Backup completed successfully.', 'rebuzz-backup-and-restore');

        $this->job->update([

            'status' => 'completed',

            'progress' => 100,

            'message' => $message,

            'files_failed' => $failed

        ]);



        return true;
    }





    /**
     * Stream files.txt back out one path at a time (avoids loading it all into memory).
     */
    private function readFileList(WPCB_Workspace $workspace)
    {
        $handle = $workspace->openRead('files.txt');

        if (!$handle) {
            return;
        }

        while (($line = fgets($handle)) !== false) {

            $line = rtrim($line, "\r\n");

            if ($line === '') {
                continue;
            }

            yield $line;
        }

        fclose($handle);
    }


    /**
     * Mark job failed and clean up its workspace (unlike restore, no debugging value in keeping it).
     */
    private function fail($message)
    {
        $state = $this->job->get();

        if (!empty($state['workspace'])) {

            $workspace = new WPCB_Workspace($state['workspace']);
            $workspace->cleanup();
        }

        // Release now so a failure never permanently blocks future
        // backups (belt-and-braces with wpcb_backup_lock_check()'s own
        // stale-lock detection).
        wpcb_backup_lock_release();

        $this->job->update([

            'status' => 'failed',

            'message' => $message

        ]);


        return false;
    }

}
