<?php

if (!defined('ABSPATH')) {
    exit;
}

class WPCB_Manifest
{
    /**
     * Create manifest.json.
     *
     * @return string|false The path written, or false if it could not
     *                      be written in full - see the write check at
     *                      the end for why a partial manifest must not
     *                      be treated as success.
     */
    public function create($file, array $statistics, array $checksums = [])
    {
        $tempDir = wpcb_temp_dir();

        if (!file_exists($tempDir)) {
            wp_mkdir_p($tempDir);
        }

        global $wpdb;

    $manifest = [

        'plugin' => [
            'name'    => 'ReBuzz Backup and Restore',
            'version' => WPCB_VERSION
        ],

        'wordpress' => [
            'version' => get_bloginfo('version'),
            // Needed so restore can rewrite table names to the
            // destination's actual prefix, or it reads stale wp_* tables.
            'table_prefix' => $wpdb->prefix,
            // Flags whether table_prefix is a whole install or one
            // site in a network; see Database::export() multisite
            // scoping and Restore_Job::stepValidate() safety check.
            'multisite' => is_multisite(),
            'blog_id' => is_multisite() ? get_current_blog_id() : null
        ],

        'server' => [
            'php_version' => PHP_VERSION,
            'os' => PHP_OS
        ],

        'site' => [
            'site_url' => site_url(),
            'home_url' => home_url(),
            'charset' => get_option('blog_charset')
        ],

        'backup' => [
            'type' => 'full',
            'created_at' => current_time('mysql'),
            'database_file' => 'database/database.sql'
        ],

        'statistics' => $statistics,

        'checksums' => $checksums

    ];

        // $file comes from the workspace

        $encoded = wp_json_encode(
            $manifest,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        );

        if ($encoded === false) {
            return false;
        }

        $written = file_put_contents($file, $encoded);

        /*
         * The manifest carries the table prefix a restore rewrites to
         * and the SHA-256 checksums every restored file is verified
         * against. A short write (disk full, quota hit) leaves a file
         * that exists but is invalid JSON, and returning $file anyway
         * would put that truncated manifest into the archive - the
         * backup would look successful and only fail at restore time,
         * on the one occasion the archive is actually needed.
         */
        if ($written === false || $written !== strlen($encoded)) {

            (new WPCB_Logger('backup'))->log(sprintf(
                'Could not write manifest.json in full (%s of %d bytes). The disk may be full or the temp folder may not be writable.',
                $written === false ? 'write failed at' : $written,
                strlen($encoded)
            ));

            // Don't leave a truncated manifest where the next step
            // would pick it up and add it to the archive.
            if (file_exists($file)) {
                wp_delete_file($file);
            }

            return false;
        }

        return $file;
    }
}