<?php

if (!defined('ABSPATH')) {
    exit;
}

class WPCB_Validator
{
    /**
     * Validate a backup ZIP
     */
    public function validate($zipFile)
    {
        if (!file_exists($zipFile)) {
            return [
                'success' => false,
                'message' => __('Backup ZIP does not exist.', 'rebuzz-backup-and-restore')
            ];
        }

        $zip = new ZipArchive();

        if ($zip->open($zipFile) !== true) {
            return [
                'success' => false,
                'message' => __('Unable to open ZIP archive.', 'rebuzz-backup-and-restore')
            ];
        }

        // Required files
        $required = [
    'manifest.json',
    'database/database.sql'
];

        foreach ($required as $file) {

            if ($zip->locateName($file) === false) {

                $zip->close();

                return [
                    'success' => false,
                    /* translators: %s: required filename missing from the backup archive */
                    'message' => sprintf(__('%s is missing from the backup.', 'rebuzz-backup-and-restore'), $file)
                ];
            }
        }

        // ZIP must contain files
        if ($zip->numFiles == 0) {

            $zip->close();

            return [
                'success' => false,
                'message' => __('Backup archive is empty.', 'rebuzz-backup-and-restore')
            ];
        }

        // Same Zip Slip/absolute-path/symlink-entry check WPCB_Extractor
        // applies before actual extraction - run here too so an unsafe
        // archive is rejected immediately at upload time, not just later
        // when a restore happens to be started.
        $extractor = new WPCB_Extractor();

        for ($i = 0; $i < $zip->numFiles; $i++) {

            $entry = $zip->getNameIndex($i);

            if ($extractor->isUnsafeEntry($zip, $i, $entry)) {

                $zip->close();

                return [
                    'success' => false,
                    'message' => __('Unsafe ZIP archive detected.', 'rebuzz-backup-and-restore')
                ];
            }
        }

        $zip->close();

        return [
            'success' => true
        ];
    }
}