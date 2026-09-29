<?php

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// phpcs:disable WordPress.WP.AlternativeFunctions -- uninstall.php runs standalone, before WP_Filesystem is initialised.

/**
 * Recursive delete for uninstall (plugin classes aren't loaded yet).
 * Removes symlinks themselves, never follows them.
 */
function wpcb_uninstall_delete_dir($dir)
{
    if (!is_dir($dir)) {
        return;
    }

    foreach (scandir($dir) as $item) {

        if ($item === '.' || $item === '..') {
            continue;
        }

        $path = $dir . '/' . $item;

        if (is_link($path)) {
            wp_delete_file($path);
        } elseif (is_dir($path)) {
            wpcb_uninstall_delete_dir($path);
        } else {
            wp_delete_file($path);
        }
    }

    rmdir($dir);
}

/**
 * Storage folder names to clean up: the current one, plus every name
 * earlier releases used. Hardcoded rather than read from the plugin's
 * helpers - this file runs standalone, without the plugin's own code
 * loaded.
 *
 * @return string[]
 */
function wpcb_uninstall_data_dirnames()
{
    return [
        'rebuzz-backup-and-restore',
        'wp-complete-backup',
    ];
}

/**
 * Per-site cleanup: options + disposable temp/logs/locks.
 *
 * Doesn't delete $base/backups - those are finished backup archives,
 * often the only copy.
 */
function wpcb_uninstall_site()
{
    delete_option('wpcb_settings');
    delete_option('wpcb_version');
    delete_option('wpcb_permalink_reminder');

    // Scheduled backups. The plugin's classes aren't loaded here, hence the literal names.
    wp_unschedule_hook('wpcb_scheduled_backup');
    wp_unschedule_hook('wpcb_scheduled_backup_resume');
    $wpcbScheduleState = get_option('wpcb_schedule_state', []);
    if (!empty($wpcbScheduleState['claim']) && strpos($wpcbScheduleState['claim'], 'wpcb_schedule_done_') === 0) {
        delete_option($wpcbScheduleState['claim']);
    }
    delete_option('wpcb_schedule');
    delete_option('wpcb_schedule_state');
    delete_option('wpcb_scheduled_backups');

    // Cloud storage settings and the encrypted credentials. Backups already in the cloud stay there.
    delete_option('wpcb_storage');
    delete_option('wpcb_remote_secrets');
    delete_option('wpcb_dropbox');
    delete_option('wpcb_remote_uploaded');
    delete_option('wpcb_remote_sent');

    // Legacy home of the moved-aside-folders record; it lives in a file now.
    delete_option('wpcb_quarantine');

    $bases = [];

    $upload = wp_upload_dir();

    if (!empty($upload['basedir'])) {

        foreach (wpcb_uninstall_data_dirnames() as $dirname) {
            $bases[] = $upload['basedir'] . '/' . $dirname;
        }
    }

    /*
     * Storage moved out of the web root with WPCB_BACKUP_DIR. Read from
     * the constant rather than the plugin's helpers, which are not
     * loaded here - see wpcb_uninstall_data_dirnames(). Both the
     * single-site path and the multisite per-site subfolder are covered,
     * since this runs once per site.
     */
    if (defined('WPCB_BACKUP_DIR')) {

        $configured = rtrim(str_replace('\\', '/', (string) WPCB_BACKUP_DIR), '/');

        if ($configured !== '') {

            $bases[] = $configured;

            if (is_multisite()) {
                $bases[] = $configured . '/site-' . get_current_blog_id();
            }
        }
    }

    foreach ($bases as $base) {

        if (!is_dir($base)) {
            continue;
        }

        // backups/ is deliberately left alone - those are finished
        // archives and are often the only copy.
        wpcb_uninstall_delete_dir($base . '/temp');
        wpcb_uninstall_delete_dir($base . '/logs');

        /*
         * Checked before deleting: wp_delete_file() calls unlink()
         * without testing for the file first, so on the normal case -
         * no backup or restore was running when the plugin was removed -
         * these three would each emit a PHP warning during uninstall.
         */
        foreach (['restore.lock', 'backup.lock', 'disabled-mu-plugins.json'] as $leftover) {

            if (file_exists($base . '/' . $leftover)) {
                wp_delete_file($base . '/' . $leftover);
            }
        }
    }
}

if (is_multisite()) {

    /*
     * uninstall.php (the legacy mechanism this plugin uses, unlike
     * register_uninstall_hook()) only ever runs once, in whatever
     * single site's context WordPress happens to fire it in when the
     * plugin is deleted network-wide - it does NOT automatically loop
     * every site. Without this, every subsite besides that one keeps
     * its wpcb_settings/wpcb_version options and leftover temp/log
     * files indefinitely after the plugin is fully removed.
     */
    // Prefixed: uninstall.php runs at file scope, so this really is a global.
    foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $wpcb_blog_id) {

        switch_to_blog($wpcb_blog_id);
        wpcb_uninstall_site();
        restore_current_blog();
    }

} else {

    wpcb_uninstall_site();
}

/*
 * The one file this plugin keeps outside its storage folder: the record
 * of folders a restore moved aside. It sits in wp-content because
 * uploads is itself swept - see WPCB_Quarantine::recordPath().
 *
 * Only the record goes. The "-old" folders it names are the admin's
 * copy of whatever the restore replaced, and deleting a site's files
 * because a plugin was removed is not this file's call.
 */
if (defined('WP_CONTENT_DIR') && file_exists(WP_CONTENT_DIR . '/.wpcb-quarantine.json')) {
    wp_delete_file(WP_CONTENT_DIR . '/.wpcb-quarantine.json');
}

/*
 * Nothing else network-wide to remove: this plugin's only options are
 * the per-site ones cleared above. The restore guard and the
 * disabled-mu-plugins record are not options at all - both live on disk
 * inside the storage folder, and are removed with the lock files above.
 */
