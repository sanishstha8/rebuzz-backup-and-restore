<?php

if (!defined('ABSPATH')) {
    exit;
}

class WPCB_History
{
    /**
     * Get all backup files
     */
    public function get_backups()
    {
        $dir = wpcb_backups_dir();

        if (!is_dir($dir)) {
            return [];
        }

        $files = glob($dir . '/*.zip');

        if (!$files) {
            return [];
        }

        usort($files, function ($a, $b) {
            return filemtime($b) - filemtime($a);
        });

        $backups = [];

        foreach ($files as $file) {

            $backups[] = [
                'name' => basename($file),
                'path' => $file,
                'size' => size_format(filesize($file), 2),
                // wp_date(), not gmdate(): this one is shown to the user in
                // the backups list, so it should read in the site's own
                // timezone rather than UTC.
                'date' => wp_date('Y-m-d H:i:s', filemtime($file)),
                'time' => filemtime($file)
            ];
        }

        return $backups;
    }
}