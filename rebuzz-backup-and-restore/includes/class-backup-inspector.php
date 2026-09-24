<?php

if (!defined('ABSPATH')) {
    exit;
}

class WPCB_Backup_Inspector
{
    public function inspect($zipFile)
    {
        if (!file_exists($zipFile)) {
            return [
                'success' => false,
                'message' => __('Backup file not found.', 'rebuzz-backup-and-restore')
            ];
        }

        $zip = new ZipArchive();

        if ($zip->open($zipFile) !== true) {
            return [
                'success' => false,
                'message' => __('Unable to open backup.', 'rebuzz-backup-and-restore')
            ];
        }

        $manifest = $zip->getFromName('manifest.json');

        if ($manifest === false) {
            $zip->close();

            return [
                'success' => false,
                'message' => __('manifest.json not found.', 'rebuzz-backup-and-restore')
            ];
        }

        // Same test as WPCB_Core_Swap::backupHasCore(), without extracting.
        $hasCore = $zip->locateName('files/wp-includes/version.php') !== false &&
            $zip->locateName('files/wp-admin/admin.php') !== false;

        $zip->close();

        $data = json_decode($manifest, true);

        if (!is_array($data)) {
            return [
                'success' => false,
                'message' => __('Invalid manifest.json.', 'rebuzz-backup-and-restore')
            ];
        }

        return [
            'success' => true,
            'manifest' => $data,
            'has_core' => $hasCore
        ];
    }
}