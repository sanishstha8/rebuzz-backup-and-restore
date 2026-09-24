<?php

if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.WP.AlternativeFunctions -- WP_Filesystem has no streaming API; archives are moved in chunks to stay inside memory limits.

class WPCB_Zip_Batch
{
    // sanity ceiling, not the real governor (TIME_BUDGET_SECONDS is) -
    // stops a pathological case (millions of zero-byte files) looping forever
    const BATCH_SIZE = 5000;

    /*
     * Max seconds per batch - file count alone isn't safe (big media
     * files can exceed PHP/proxy timeouts). Wall-clock cap keeps every
     * batch finishing inside typical timeouts.
     */
    const TIME_BUDGET_SECONDS = 20;


    public function process(WPCB_Workspace $workspace)
    {
        // best-effort; some hosts disable this. TIME_BUDGET_SECONDS is the real guard.
        wpcb_extend_time_limit(60);

        $startTime = microtime(true);

        /*
         * Guards against two overlapping AJAX "continue" calls for the
         * same job (a slow response plus a client retry, or a stray
         * duplicate trigger) both reading the same offset and appending
         * to backup.zip concurrently - without this, the two entries'
         * headers/data interleave in the shared file and recorded
         * central-directory offsets desync from actual byte positions,
         * corrupting the archive. Non-blocking: a losing request just
         * reports back "not finished yet" using whatever's already on
         * disk, so the poll loop retries shortly instead of the whole
         * job being marked failed. Released automatically when this
         * request ends (fclose()/script exit) - each call is short-lived.
         */
        $lockHandle = @fopen($workspace->file('zip.lock'), 'c');
        $locked = ($lockHandle !== false) && flock($lockHandle, LOCK_EX | LOCK_NB);

        if (!$locked) {

            if ($lockHandle) {
                fclose($lockHandle);
            }

            $staleState = $workspace->getJson('zip.state.json');

            return [
                'finished' => false,
                'position' => isset($staleState['position']) ? (int) $staleState['position'] : 0,
                'total'    => isset($staleState['total']) ? (int) $staleState['total'] : 0,
                'zip'      => $workspace->file('backup.zip'),
                'failed'   => isset($staleState['failed']) ? (int) $staleState['failed'] : 0
            ];
        }

        $state = $workspace->getJson('zip.state.json');


        if (empty($state)) {

            fclose($lockHandle);

            return false;

        }


        $zipFile  = $workspace->file('backup.zip');
        $cdirFile = $workspace->file('backup.zip.cdir');

        $stream = new WPCB_Zip_Stream($zipFile, $cdirFile);


        /*
         * Persisted across batches (not kept in memory) so cost scales
         * with new files added, not archive size so far.
         */
        $zipOffset  = isset($state['zip_offset'])  ? (int) $state['zip_offset']  : 0;
        $cdirOffset = isset($state['cdir_offset']) ? (int) $state['cdir_offset'] : 0;
        $entryCount = isset($state['entry_count']) ? (int) $state['entry_count'] : 0;
        $fileOffset = isset($state['file_offset']) ? (int) $state['file_offset'] : 0;

        $position = isset($state['position'])
            ? (int) $state['position']
            : 0;

        $total = isset($state['total'])
            ? (int) $state['total']
            : 0;

        $failed = isset($state['failed']) ? (int) $state['failed'] : 0;

        /*
         * State saves only on return, but bytes hit disk immediately -
         * a kill mid-request can leave disk ahead of saved offsets.
         * Truncate orphaned trailing bytes to resync. If disk is
         * *shorter* than checkpoint, data was lost - fail, don't build on it.
         */
        if (
            !$this->truncateToOffset($zipFile, $zipOffset) ||
            !$this->truncateToOffset($cdirFile, $cdirOffset)
        ) {
            return false;
        }


        /*
         * First run only: seed the database dump. manifest.json is NOT
         * seeded here - appended last, once checksums are complete.
         */
        if ($zipOffset === 0) {

            if (!file_exists($cdirFile)) {
                touch($cdirFile);
            }

            $database = $workspace->file('database.sql');

            if (file_exists($database)) {

                $written = $stream->addFile(
                    $database,
                    'database/database.sql',
                    $zipOffset
                );

                if ($written !== false) {

                    $zipOffset += $written['written'];
                    $cdirOffset += $written['cdir_written'];
                    $entryCount++;

                    if (
                        !$this->recordChecksum(
                            $workspace,
                            'database/database.sql',
                            $written['sha256']
                        )
                    ) {

                        (new WPCB_Logger('backup'))->log(
                            'Could not record the checksum for the database dump - the disk may be full. Failing this step rather than building an archive that would be rejected as corrupt at restore time.'
                        );

                        fclose($lockHandle);

                        // Deliberately returns without persisting state:
                        // the retry resumes from the last checkpoint and
                        // truncateToOffset() trims the bytes written
                        // since, so the entry is redone cleanly rather
                        // than duplicated.
                        return false;
                    }
                } else {

                    /*
                     * Same rollback as the per-file loop below, and for
                     * the same reason: a partly-written database entry
                     * leaves bytes on disk that $zipOffset does not
                     * account for, which would silently misplace the
                     * local-header offset of every entry after it.
                     * Fail the step outright here - a backup with no
                     * database in it is not worth continuing.
                     */
                    $this->truncateToOffset($zipFile, $zipOffset);
                    $this->truncateToOffset($cdirFile, $cdirOffset);

                    (new WPCB_Logger('backup'))->log(
                        'Could not add the database dump to the archive. Stopping this step so a retry starts from the last good offset, rather than continuing with an archive whose remaining entries would be unreadable at restore time.'
                    );

                    fclose($lockHandle);

                    return false;
                }
            }

        }


        if ($position < $total) {

            $handle = $workspace->openRead('files.txt');

            if (!$handle) {

                return false;

            }


            /*
             * Resume by byte offset, not by re-reading prior lines with
             * fgets() - that used to make later batches slower for no reason.
             */
            if ($fileOffset > 0) {
                fseek($handle, $fileOffset);
            }


            $root = realpath(ABSPATH);

            $processedCount = 0;


            /*
             * Read+process in one loop so offset/position only advance
             * for files actually finished - safe to bail early on time.
             */
            while (
                $processedCount < self::BATCH_SIZE &&
                (microtime(true) - $startTime) < self::TIME_BUDGET_SECONDS &&
                ($line = fgets($handle)) !== false
            ) {

                $fileOffset = ftell($handle);

                $line = trim($line);

                $position++;
                $processedCount++;

                if (empty($line) || !file_exists($line)) {
                    continue;
                }

                $real = realpath($line);

                if ($real === false) {
                    continue;
                }

                $relative = str_replace(
                    $root,
                    '',
                    $real
                );

                $relative = ltrim(
                    $relative,
                    '\\/'
                );

                $relative = str_replace(
                    '\\',
                    '/',
                    $relative
                );

                /*
                 * addFile() reads the file once, computing CRC32 + SHA-256
                 * together - hash can never disagree with what's archived.
                 */
                $written = $stream->addFile(
                    $real,
                    'files/' . $relative,
                    $zipOffset
                );

                if ($written !== false) {

                    $zipOffset += $written['written'];
                    $cdirOffset += $written['cdir_written'];
                    $entryCount++;

                    if (
                        !$this->recordChecksum(
                            $workspace,
                            'files/' . $relative,
                            $written['sha256']
                        )
                    ) {

                        (new WPCB_Logger('backup'))->log(
                            "Could not record the checksum for {$relative} - the disk may be full. Failing this step rather than building an archive that would be rejected as corrupt at restore time."
                        );

                        fclose($handle);
                        fclose($lockHandle);

                        // See the database dump's equivalent bail above
                        // for why no state is persisted here.
                        return false;
                    }

                } else {

                    /*
                     * addFile() can fail after it has already written a
                     * local header and part of the entry's data. Those
                     * bytes are on disk, but $zipOffset was not advanced -
                     * so the next entry gets appended past $zipOffset
                     * while recording $zipOffset as its own local-header
                     * position, and every entry from here on points into
                     * the orphaned bytes.
                     *
                     * The central directory stays self-consistent, so an
                     * archive damaged this way still opens, still reports
                     * the right entry count, and still passes
                     * WPCB_Extractor::validate() - which reads only the
                     * central directory. It fails at restore instead, on
                     * the first affected entry, with nothing to connect it
                     * back to the backup that produced it. A lenient
                     * libzip is worse still: it returns those entries as
                     * empty rather than erroring.
                     *
                     * Roll back to the last good offset so a skipped file
                     * costs one file, not every file after it.
                     */
                    if (
                        !$this->truncateToOffset($zipFile, $zipOffset) ||
                        !$this->truncateToOffset($cdirFile, $cdirOffset)
                    ) {

                        (new WPCB_Logger('backup'))->log(
                            "Failed to add file to backup: {$relative} - and the archive could not be rolled back to its last good offset. Stopping here, because every entry added after this point would be unreadable at restore time."
                        );

                        fclose($handle);
                        fclose($lockHandle);

                        return false;
                    }

                    // was previously silent; now counted+logged (like
                    // WPCB_Restore_Job::stepRestoreFiles())
                    $failed++;

                    (new WPCB_Logger('backup'))->log(
                        "Failed to add file to backup: {$relative}"
                    );
                }
            }


            fclose($handle);

        }


        $finished = ($position >= $total);


        if ($finished) {

            /*
             * All files hashed - fold checksums.jsonl into manifest.json,
             * append it as the last entry, then write central directory once.
             *
             * 'manifest_added' guard: without it, a retry after a later
             * finalize() failure would re-append a duplicate manifest entry.
             */
            if (empty($state['manifest_added'])) {

                $checksums = $this->collectChecksums($workspace);

                $workspace->put('checksums.json', $checksums);
                $workspace->delete('checksums.jsonl');

                $stats = $workspace->getJson('statistics.json');

                $manifest = new WPCB_Manifest();

                $manifestFile = $manifest->create(
                    $workspace->file('manifest.json'),
                    $stats,
                    $checksums
                );

                /*
                 * A manifest that couldn't be written in full is
                 * treated exactly like a failed addFile() below: keep
                 * the state, fail the step, let the retry try again.
                 * Passing false through to addFile() would otherwise
                 * be the one path that produces an archive with no
                 * usable manifest in it.
                 */
                $written = ($manifestFile === false)
                    ? false
                    : $stream->addFile(
                        $manifestFile,
                        'manifest.json',
                        $zipOffset
                    );

                if ($written === false) {

                    // persist+fail so a retry actually retries this step,
                    // instead of a transient failure blocking it forever
                    $state['position']    = $position;
                    $state['file_offset'] = $fileOffset;
                    $state['zip_offset']  = $zipOffset;
                    $state['cdir_offset'] = $cdirOffset;
                    $state['entry_count'] = $entryCount;
                    $state['failed']      = $failed;

                    $workspace->put('zip.state.json', $state);

                    return false;
                }

                $zipOffset += $written['written'];
                $cdirOffset += $written['cdir_written'];
                $entryCount++;

                $state['manifest_added'] = true;
            }

            $finalized = $stream->finalize($entryCount);

            if (!$finalized) {

                // persist real state, fail instead of returning a broken
                // archive (see WPCB_Zip_Stream::finalize())
                $state['position']    = $position;
                $state['file_offset'] = $fileOffset;
                $state['zip_offset']  = $zipOffset;
                $state['cdir_offset'] = $cdirOffset;
                $state['entry_count'] = $entryCount;
                $state['failed']      = $failed;

                $workspace->put('zip.state.json', $state);

                return false;
            }

            wp_delete_file($cdirFile);

        }


        $state['position']    = $position;
        $state['file_offset'] = $fileOffset;
        $state['zip_offset']  = $zipOffset;
        $state['cdir_offset'] = $cdirOffset;
        $state['entry_count'] = $entryCount;
        $state['failed']      = $failed;


        $workspace->put(
            'zip.state.json',
            $state
        );


        return [

            'finished' => $finished,

            'position' => $position,

            'total' => $total,

            'zip' => $zipFile,

            'failed' => $failed

        ];

    }




    /**
     * Truncate $file to exactly $offset bytes (discard unsaved trailing
     * writes). No-op if already that size.
     *
     * @return bool True if now/already $offset bytes. False if file is
     *              shorter than $offset - data loss, unsafe to resume.
     */
    private function truncateToOffset($file, $offset)
    {
        if (!file_exists($file)) {
            return $offset === 0;
        }

        $size = filesize($file);

        if ($size === $offset) {
            return true;
        }

        if ($size < $offset) {
            return false;
        }

        $handle = @fopen($file, 'r+b');

        if (!$handle) {
            return false;
        }

        ftruncate($handle, $offset);
        fclose($handle);

        return true;
    }


    /**
     * Append one [path, hash] pair to checksums.jsonl, streamed to
     * disk so memory stays flat regardless of file count.
     *
     * The write is checked, and a failure is fatal to the backup rather
     * than something to shrug off. Restore verification fails closed: a
     * file present in the archive but absent from the manifest's
     * checksum map aborts the whole restore with "no checksum on record
     * ... may be corrupted or tampered with" (see
     * WPCB_Restore_Job::stepVerify()). Dropping a line here would
     * therefore produce an archive that reports success today and
     * cannot be restored at all later - and would blame corruption for
     * what was really a full disk at backup time.
     *
     * @return bool True if the line was written in full.
     */
    private function recordChecksum(WPCB_Workspace $workspace, $path, $hash)
    {
        $out = $workspace->openAppend('checksums.jsonl');

        if (!$out) {
            return false;
        }

        $line = wp_json_encode([$path, $hash]) . PHP_EOL;

        $written = fwrite($out, $line);

        // fclose() flushes, so a full disk can surface here rather than
        // at the fwrite() above.
        $closed = fclose($out);

        return ($written !== false && $written === strlen($line) && $closed);
    }


    /**
     * Read checksums.jsonl into a path => hash map. Called once,
     * on the final batch, to embed in the manifest.
     */
    private function collectChecksums(WPCB_Workspace $workspace)
    {
        $checksums = [];

        if (!$workspace->exists('checksums.jsonl')) {
            return $checksums;
        }

        $handle = $workspace->openRead('checksums.jsonl');

        if (!$handle) {
            return $checksums;
        }

        while (($line = fgets($handle)) !== false) {

            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $pair = json_decode($line, true);

            if (is_array($pair) && count($pair) === 2) {
                $checksums[$pair[0]] = $pair[1];
            }
        }

        fclose($handle);

        return $checksums;
    }

}
