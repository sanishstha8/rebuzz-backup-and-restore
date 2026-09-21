<?php

if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- SHOW TABLES to size the backup; there is no $wpdb helper for it and caching would defeat the point.

class WPCB_Statistics
{
    /**
     * Build backup statistics.
     *
     * @param iterable $files       File paths only, no dirs - see $directories.
     * @param string   $databaseFile
     * @param int      $directories Dir count, from files.state.json 'folders'.
     */
    public function generate(iterable $files, $databaseFile, $directories = 0)
    {
        global $wpdb;

        $stats = [
            'files'           => 0,
            'directories'     => (int) $directories,
            'total_size'      => 0,
            'database_tables' => 0,
            'database_size'   => 0,
        ];

        foreach ($files as $file) {

            if (!file_exists($file)) {
                continue;
            }

            $stats['files']++;

            $stats['total_size'] += filesize($file);
        }

        $tables = $wpdb->get_col("SHOW TABLES");

        // Unscoped SHOW TABLES returns every table on the network's
        // shared database, not just this site's - see class-database.php's
        // export() and class-url-rewriter.php, which both already scope
        // this the same way, for the same reason.
        if (is_multisite()) {
            $tables = (new WPCB_Multisite())->tablesForSite($tables, get_current_blog_id());
        }

        $stats['database_tables'] = count($tables);

        if (file_exists($databaseFile)) {

            $stats['database_size'] = filesize($databaseFile);

        }

        return $stats;
    }
}