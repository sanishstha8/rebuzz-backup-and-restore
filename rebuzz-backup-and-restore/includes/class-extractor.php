<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Extracts a backup ZIP in chunks (bounded entries per call) so large
 * archives don't hit max_execution_time in one extractTo() call - same
 * chunked/resumable pattern as WPCB_Restore_Job.
 */
// phpcs:disable WordPress.WP.AlternativeFunctions -- WP_Filesystem has no streaming API; archives are moved in chunks to stay inside memory limits.

class WPCB_Extractor
{
    /** Sanity ceiling per call; TIME_BUDGET_SECONDS is the real governor. Stops runaway loops on huge counts of tiny files. */
    const BATCH_SIZE = 2000;

    /** Max seconds per extractNext() call, checked between entries and mid-copy for one large file - extractTo() can't be interrupted, so big entries use a manual resumable copy instead. */
    const TIME_BUDGET_SECONDS = 20;

    /** Chunk size used when streaming an entry's bytes out to disk. */
    const COPY_CHUNK_BYTES = 1048576;

    /** Entries at/under this size use the fast native extractTo() path instead of the resumable manual copy - small enough to always finish within the time budget. */
    const SMALL_ENTRY_THRESHOLD_BYTES = 20971520; // 20MB

    /**
     * Check every entry for anything unsafe (Zip Slip, absolute paths, symlinks) before extraction. Metadata-only, so stays fast on huge archives.
     * @return array {success, message?, total?}
     */
    public function validate($zipFile)
    {
        if (!file_exists($zipFile)) {
            return [
                'success' => false,
                'message' => __('Backup ZIP not found.', 'rebuzz-backup-and-restore')
            ];
        }

        $zip = new ZipArchive();

        if ($zip->open($zipFile) !== true) {
            return [
                'success' => false,
                'message' => __('Unable to open ZIP archive.', 'rebuzz-backup-and-restore')
            ];
        }

        // Block Zip Slip / absolute-path / symlink entries - a symlink could let cleanup delete files outside the workspace.
        for ($i = 0; $i < $zip->numFiles; $i++) {

            $entry = $zip->getNameIndex($i);

            if ($this->isUnsafeEntry($zip, $i, $entry)) {
                $zip->close();

                return [
                    'success' => false,
                    'message' => __('Unsafe ZIP archive detected.', 'rebuzz-backup-and-restore')
                ];
            }
        }

        $total = $zip->numFiles;

        $zip->close();

        return [
            'success' => true,
            'total'   => $total
        ];
    }

    /**
     * Extract from $position until BATCH_SIZE entries or TIME_BUDGET_SECONDS is hit, then return so the caller can persist
     * progress and resume. Small entries use fast extractTo(); larger ones are copied manually so the time check can fire
     * mid-file, returning $byte_offset to resume that same entry next call (re-reading/discarding the already-written prefix,
     * since streams can't be seeked). Assumes validate() already checked safety - not repeated here for performance.
     * @return array {success, message?, position, byte_offset, finished}
     */
    public function extractNext($zipFile, $destination, $position, $total, $byteOffset = 0)
    {
        if (!file_exists($destination)) {
            wp_mkdir_p($destination);
        }

        $zip = new ZipArchive();

        if ($zip->open($zipFile) !== true) {
            return [
                'success' => false,
                'message' => __('Unable to open ZIP archive.', 'rebuzz-backup-and-restore')
            ];
        }

        $startTime = microtime(true);
        $processed = 0;

        while (
            $position < $total &&
            $processed < self::BATCH_SIZE &&
            (microtime(true) - $startTime) < self::TIME_BUDGET_SECONDS
        ) {

            $name = $zip->getNameIndex($position);

            if ($name === false) {
                $position++;
                $processed++;
                continue;
            }

            $stat = $zip->statIndex($position);

            // byteOffset > 0 means this entry is mid-resume - must finish via copyEntry(), regardless of size.
            $useFastPath = (
                $byteOffset === 0 &&
                $stat !== false &&
                $stat['size'] <= self::SMALL_ENTRY_THRESHOLD_BYTES
            );

            if ($useFastPath) {

                /*
                 * Cleared first so extractionFailureReason() reads the
                 * error from this write and not some unrelated warning
                 * raised earlier in the same request, which would give a
                 * confidently wrong diagnosis.
                 */
                error_clear_last();

                if (!@$zip->extractTo($destination, [$name])) {

                    $reason = $this->extractionFailureReason($zip, $destination, $name);

                    $zip->close();

                    return [
                        'success' => false,
                        'message' => $this->failureMessage($name, $reason)
                    ];
                }

                $position++;
                $processed++;
                continue;
            }

            // Same reasoning as the fast path above.
            error_clear_last();

            $result = $this->copyEntry(
                $zip,
                $name,
                $destination,
                $byteOffset,
                $startTime
            );

            if ($result === false) {

                $reason = $this->extractionFailureReason($zip, $destination, $name);

                $zip->close();

                return [
                    'success' => false,
                    'message' => $this->failureMessage($name, $reason)
                ];
            }

            if (!$result['finished']) {

                // Time budget ran out mid-entry - stop; position/byteOffset carry forward to resume this same entry next call.
                $zip->close();

                return [
                    'success'     => true,
                    'position'    => $position,
                    'byte_offset' => $result['bytes_written'],
                    'finished'    => false
                ];
            }

            $byteOffset = 0;
            $position++;
            $processed++;
        }

        $zip->close();

        return [
            'success'     => true,
            'position'    => $position,
            'byte_offset' => 0,
            'finished'    => $position >= $total
        ];
    }

    /**
     * Copy one entry's bytes to $destination, resuming from $byteOffset, stopping once TIME_BUDGET_SECONDS is reached.
     * @return array|false {finished, bytes_written} or false on failure.
     */
    /**
     * Why an entry could not be written, in the user's language.
     *
     * Without this the plugin reported only which file failed, which is
     * not enough to act on: a full disk, a read-only folder, a corrupt
     * archive and a path the filesystem rejects all produced the same
     * sentence, and telling them apart meant guessing.
     *
     * Three sources, most specific first: PHP's last error (which
     * carries the real errno text such as "No space left on device"),
     * ZipArchive's own status, and finally a live writability probe of
     * the destination.
     *
     * @return string A sentence, or '' if nothing could be determined.
     */
    private function extractionFailureReason(ZipArchive $zip, $destination, $name = '')
    {
        $error = error_get_last();

        $message = isset($error['message']) ? (string) $error['message'] : '';

        if ($message !== '') {

            /*
             * Out of room. Two different errnos say this and they
             * carry different text: ENOSPC is "No space left on
             * device" (the filesystem itself is full), EDQUOT is
             * "Disk quota exceeded" (the account's own cap, with room
             * still free on the partition). Shared hosting hits the
             * second far more often than the first, and matching only
             * the first reported the commonest cause of a failed
             * restore as an unexplained one.
             */
            if (
                stripos($message, 'No space left') !== false ||
                stripos($message, 'disk full') !== false ||
                stripos($message, 'quota exceeded') !== false
            ) {
                return __('the disk is full, or this hosting account has reached its storage quota. Note that a host often reports far more free space than your account is actually allowed to use.', 'rebuzz-backup-and-restore');
            }

            if (stripos($message, 'Permission denied') !== false) {
                return __('the destination folder is not writable by PHP.', 'rebuzz-backup-and-restore');
            }

            // ENOTDIR: Linux says "Not a directory" where Windows says ENOENT.
            if (stripos($message, 'Not a directory') !== false) {
                return __('a folder in its path already exists as a file, so the rest of the path could not be created.', 'rebuzz-backup-and-restore');
            }

            if (stripos($message, 'File name too long') !== false || stripos($message, 'name too long') !== false) {
                return __('the resulting path is longer than this filesystem allows.', 'rebuzz-backup-and-restore');
            }

            if (stripos($message, 'Too many open files') !== false) {
                return __('the server ran out of available file handles.', 'rebuzz-backup-and-restore');
            }

            /*
             * ENOENT. copyEntry() creates the parent directory before
             * every write, so a missing path here is almost always
             * Windows refusing a path past its 260-character limit -
             * which reports "No such file or directory", not the
             * "name too long" that the check above expects.
             */
            if (stripos($message, 'No such file or directory') !== false) {
                return __('the full path could not be created. On Windows this usually means it exceeds the 260-character path limit - moving the site closer to the drive root avoids that.', 'rebuzz-backup-and-restore');
            }
        }

        /*
         * ZipArchive's own view. ER_READ/ER_CRC mean the archive itself
         * is damaged, which is a different problem from the destination
         * being unwritable and needs a different answer.
         */
        if (isset($zip->status) && $zip->status !== ZipArchive::ER_OK) {

            if (in_array($zip->status, [ZipArchive::ER_READ, ZipArchive::ER_CRC, ZipArchive::ER_INCONS, ZipArchive::ER_NOZIP], true)) {
                return __('the backup archive is damaged or incomplete - re-create it, or re-upload it in binary mode if it was transferred by FTP.', 'rebuzz-backup-and-restore');
            }

            if (method_exists($zip, 'getStatusString')) {

                $status = $zip->getStatusString();

                if (is_string($status) && $status !== '') {
                    return $status;
                }
            }
        }

        // Nothing conclusive - ask the filesystem directly.
        if (is_dir($destination) && !is_writable($destination)) {
            return __('the destination folder is not writable by PHP.', 'rebuzz-backup-and-restore');
        }

        /*
         * Nothing recognised, but PHP did report something. Pass it
         * through rather than dropping it: an unrecognised errno is
         * exactly the case where the raw text is the only clue anyone
         * has, and a bare "Extraction failed for: <file>" leaves the
         * person reading it with nothing to act on.
         */
        if ($message !== '') {
            return $this->rawErrorReason($message);
        }

        return $this->silentFailureReason($zip, $destination, $name);
    }

    /**
     * Last resort when PHP raised nothing and the archive reports ER_OK.
     *
     * extractTo() and getStream() both resolve the entry by name and
     * return false with no warning when that lookup fails, which left a
     * bare "Extraction failed for: X" as the only output for a whole
     * class of failures. Probe the causes directly instead.
     */
    private function silentFailureReason(ZipArchive $zip, $destination, $name)
    {
        if ($name === '') {
            return '';
        }

        // Listed by index, yet not resolvable by name - inconsistent index.
        if (method_exists($zip, 'locateName') && $zip->locateName($name) === false) {
            return __('the archive lists this entry but it cannot be looked up by name, so the ZIP index is inconsistent - re-create the backup.', 'rebuzz-backup-and-restore');
        }

        $current = rtrim($destination, '/' . DIRECTORY_SEPARATOR);

        foreach (explode('/', trim(dirname($name), '/')) as $segment) {

            if ($segment === '' || $segment === '.') {
                continue;
            }

            $current .= '/' . $segment;

            // A path component occupied by a file is ENOTDIR waiting to happen.
            if (file_exists($current) && !is_dir($current)) {
                return sprintf(
                    /* translators: %s: path of the component that is a file where a folder is needed */
                    __('a folder in its path already exists as a file (%s), so the rest of the path cannot be created.', 'rebuzz-backup-and-restore'),
                    $current
                );
            }
        }

        // Nothing probed positive - report the status code rather than nothing.
        return sprintf(
            /* translators: %d: numeric ZipArchive status code */
            __('PHP reported no error and the archive reports status %d. Re-create the backup; if it recurs the host is likely blocking the write (security scanner, or an inode/disk quota).', 'rebuzz-backup-and-restore'),
            isset($zip->status) ? (int) $zip->status : -1
        );
    }

    /**
     * An unmatched PHP error, tidied enough to sit inside the failure
     * sentence: the "ZipArchive::extractTo(): " prefix carries nothing
     * for the reader, newlines would break the notice, and the text is
     * capped so a long message can't swamp it.
     */
    private function rawErrorReason($message)
    {
        $message = preg_replace('/^\w+(?:::\w+)?\(\):\s*/', '', $message);

        $message = trim(preg_replace('/\s+/', ' ', $message));

        if ($message === '') {
            return '';
        }

        if (strlen($message) > 200) {
            $message = substr($message, 0, 197) . '...';
        }

        /* translators: %s: the raw error text PHP reported for the failed write */
        return sprintf(__('PHP reported: %s', 'rebuzz-backup-and-restore'), $message);
    }

    /** "Extraction failed for: X" plus the reason, when one is known. */
    private function failureMessage($name, $reason)
    {
        if ($reason === '') {
            /* translators: %s: name of the ZIP entry that failed to extract */
            return sprintf(__('Extraction failed for: %s', 'rebuzz-backup-and-restore'), $name);
        }

        return sprintf(
            /* translators: 1: name of the ZIP entry that failed to extract, 2: the underlying reason */
            __('Extraction failed for %1$s because %2$s', 'rebuzz-backup-and-restore'),
            $name,
            $reason
        );
    }

    private function copyEntry(ZipArchive $zip, $name, $destination, $byteOffset, $startTime)
    {
        // Directory entries never reach here - always 0 bytes, so they use the fast extractTo() path instead.
        $destPath = rtrim($destination, '/\\') . '/' . $name;

        $destDir = dirname($destPath);

        if (!is_dir($destDir)) {
            wp_mkdir_p($destDir);
        }

        $source = $zip->getStream($name);

        if (!$source) {
            return false;
        }

        if ($byteOffset > 0) {

            // Resuming: append to the existing partial file, skipping byteOffset bytes from a fresh copy of the source stream.
            $dest = @fopen($destPath, 'ab');

            $toSkip = $byteOffset;

            while ($toSkip > 0 && !feof($source)) {

                $chunk = fread($source, min(self::COPY_CHUNK_BYTES, $toSkip));

                if ($chunk === false) {
                    break;
                }

                $toSkip -= strlen($chunk);
            }

        } else {

            $dest = @fopen($destPath, 'wb');
        }

        if (!$dest) {
            fclose($source);
            return false;
        }

        $written = $byteOffset;

        while (!feof($source)) {

            if ((microtime(true) - $startTime) >= self::TIME_BUDGET_SECONDS) {
                fclose($source);
                fclose($dest);
                return ['finished' => false, 'bytes_written' => $written];
            }

            $chunk = fread($source, self::COPY_CHUNK_BYTES);

            if ($chunk === false) {
                break;
            }

            // A short/failed write here (disk full) must not silently
            // advance $written - the resume logic trusts this value as
            // exactly how many bytes are really on disk, and re-reads
            // that many bytes from a fresh copy of the source stream
            // next call (streams can't be seeked). Overstating it would
            // skip real bytes on resume, silently truncating the file.
            $result = fwrite($dest, $chunk);

            if ($result === false || $result !== strlen($chunk)) {
                fclose($source);
                fclose($dest);
                return false;
            }

            $written += $result;
        }

        fclose($source);
        fclose($dest);

        return ['finished' => true, 'bytes_written' => $written];
    }

    /**
     * Reject an entry that would escape the extraction dir (path
     * traversal, absolute path) or become a symlink on disk. Public so
     * WPCB_Validator can apply the same check at upload time, before a
     * backup is even offered for restore - one implementation shared by
     * both callers, so they can't silently drift apart.
     */
    public function isUnsafeEntry(ZipArchive $zip, $index, $entry)
    {
        if (
            $entry === '' ||
            strpos($entry, '../') !== false ||
            strpos($entry, '..\\') !== false
        ) {
            return true;
        }

        // Absolute Unix or Windows path.
        if (
            $entry[0] === '/' ||
            $entry[0] === '\\' ||
            preg_match('#^[A-Za-z]:[\\\\/]#', $entry)
        ) {
            return true;
        }

        // A Unix symlink entry stores S_IFLNK in its upper external-attribute bits; extractTo() recreates it as a real symlink.
        if (
            $zip->getExternalAttributesIndex($index, $opsys, $attr) &&
            $opsys === ZipArchive::OPSYS_UNIX
        ) {
            $mode = ($attr >> 16) & 0xFFFF;

            if (($mode & 0170000) === 0120000) {
                return true;
            }
        }

        return false;
    }
}
