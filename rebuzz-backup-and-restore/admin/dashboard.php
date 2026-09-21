<?php

if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- included from inside a method (WPCB_Admin::dashboard() etc), so these are function-scoped, not globals.

$backupDir = wpcb_backups_dir();

/* Dashboard statistics */

$backupCount = 0;
$lastBackup = '-';

if (is_dir($backupDir)) {

    $files = glob($backupDir . '/*.zip');

    if ($files) {

        $backupCount = count($files);

        usort($files, function ($a, $b) {
            return filemtime($b) - filemtime($a);
        });

        $lastBackup = basename($files[0]);
    }
}

/* Backup history */

$history = new WPCB_History();
$backups = $history->get_backups();

/* Temp workspace usage - includes stale restore-* workspaces, which
   live as siblings of temp/ rather than inside it (see
   wpcb_clear_stale_restore_workspaces()), so the panel below and its
   "Clear Temporary Files" button reflect/reclaim all of it. */

$tempSize = wpcb_directory_size(wpcb_temp_dir());

foreach (wpcb_restore_workspace_dirs() as $restoreDir) {
    $tempSize += wpcb_directory_size($restoreDir);
}

/* Other plugins' leftover data */

$foreignBackups = wpcb_detect_foreign_backups();

$foreignBackupsTotal = array_sum(array_column($foreignBackups, 'size'));

?>

<div class="wrap">

    <h1><?php esc_html_e('ReBuzz Backup and Restore', 'rebuzz-backup-and-restore'); ?></h1>

    <?php
    /*
     * Result of actually fetching a canary file from the backups folder
     * over HTTP with no cookies - see wpcb_storage_exposure(). Shown
     * because on Nginx the .htaccess the plugin writes is ignored, and
     * an administrator with no FTP access has no other way to find out.
     */
    $wpcbExposure = wpcb_storage_exposure_cached();

    if (wpcb_configured_data_dir_failed()) : ?>

        <div class="notice notice-error">
            <p>
                <?php
                printf(
                    wp_kses(
                        /* translators: 1: the value of the WPCB_BACKUP_DIR constant, 2: the reason it was rejected */
                        __('<code>WPCB_BACKUP_DIR</code> is set to <code>%1$s</code>, but it is not being used because %2$s. Backups fall back to the uploads folder until this is corrected.', 'rebuzz-backup-and-restore'),
                        ['code' => []]
                    ),
                    esc_html((string) WPCB_BACKUP_DIR),
                    esc_html(wpcb_configured_data_dir_problem())
                );
                ?>
            </p>
        </div>

    <?php endif; ?>

    <?php
    // Archives left in the old location after WPCB_BACKUP_DIR was set -
    // reported rather than moved, since they can be many gigabytes and
    // may be the only copy.
    $wpcbStranded = wpcb_stranded_uploads_backups();

    if ($wpcbStranded !== null) : ?>

        <div class="notice notice-warning">
            <p>
                <?php
                printf(
                    wp_kses(
                        /* translators: 1: number of archives, 2: their total size, 3: old folder path, 4: new folder path */
                        __('%1$d backup archive(s) (%2$s) are still in the previous location, <code>%3$s</code>, and are no longer listed below. That folder is inside your public web root. Move them into <code>%4$s</code> to keep them, then delete the old folder.', 'rebuzz-backup-and-restore'),
                        ['code' => []]
                    ),
                    count($wpcbStranded['files']),
                    esc_html(size_format($wpcbStranded['size'])),
                    esc_html(wpcb_display_path($wpcbStranded['dir'])),
                    esc_html(wpcb_backups_dir())
                );
                ?>
            </p>
        </div>

    <?php endif; ?>

    <?php
    // Not a warning: backups are actually refused while this is true,
    // so the notice says so and gives the one line that fixes it.
    if (!wpcb_storage_is_private()) : ?>

        <div class="notice notice-error">
            <p><strong><?php esc_html_e('Backups are turned off until your backup folder is private.', 'rebuzz-backup-and-restore'); ?></strong></p>
            <p>
                <?php
                if ($wpcbExposure === 'public') {
                    printf(
                        wp_kses(
                            /* translators: %s: path to the backups folder, relative to the WordPress root */
                            __('This site served a test file from <code>%s</code> to a request that was not logged in. A backup archive contains your entire database, including every user account and password hash, so the plugin will not create one that anybody could download.', 'rebuzz-backup-and-restore'),
                            ['code' => []]
                        ),
                        esc_html(wpcb_display_path(wpcb_backups_dir()) . '/')
                    );
                } else {
                    esc_html_e('This site could not make a request to itself to check whether the backup folder is readable from the web, and the server is not one that reads the .htaccess rule this plugin writes. Rather than assume it is safe, the plugin will not create a backup that might be publicly downloadable.', 'rebuzz-backup-and-restore');
                }
                ?>
            </p>
            <p>
                <?php
                printf(
                    wp_kses(
                        /* translators: %s: the line to add to wp-config.php */
                        __('Point backups at a folder outside your public web root by adding this to <code>wp-config.php</code>, then create a backup: %s', 'rebuzz-backup-and-restore'),
                        ['code' => []]
                    ),
                    '<br><code>define( \'WPCB_BACKUP_DIR\', \'/full/path/outside/public_html/rebuzz-backups\' );</code>'
                );
                ?>
            </p>
            <p><?php esc_html_e('Your host can tell you a suitable path - usually a folder alongside public_html, not inside it. Restoring and downloading existing backups still works.', 'rebuzz-backup-and-restore'); ?></p>
        </div>

    <?php endif; ?>

    <p>
        <?php esc_html_e('Create complete backups of your WordPress website including files, database and settings.', 'rebuzz-backup-and-restore'); ?>
    </p>

    <hr>

    <h2><?php esc_html_e('System Information', 'rebuzz-backup-and-restore'); ?></h2>

    <table class="widefat striped" style="max-width:750px;">

        <tbody>

            <tr>
                <th width="250"><?php esc_html_e('Plugin Version', 'rebuzz-backup-and-restore'); ?></th>
                <td><?php echo esc_html(WPCB_VERSION); ?></td>
            </tr>

            <tr>
                <th><?php esc_html_e('WordPress Version', 'rebuzz-backup-and-restore'); ?></th>
                <td><?php echo esc_html(get_bloginfo('version')); ?></td>
            </tr>

            <tr>
                <th><?php esc_html_e('PHP Version', 'rebuzz-backup-and-restore'); ?></th>
                <td><?php echo esc_html(PHP_VERSION); ?></td>
            </tr>

            <tr>
                <th><?php esc_html_e('Backup Directory', 'rebuzz-backup-and-restore'); ?></th>
                <td><?php echo esc_html($backupDir); ?></td>
            </tr>

            <tr>
                <th><?php esc_html_e('Total Backups', 'rebuzz-backup-and-restore'); ?></th>
                <td><?php echo esc_html($backupCount); ?></td>
            </tr>

            <tr>
                <th><?php esc_html_e('Latest Backup', 'rebuzz-backup-and-restore'); ?></th>
                <td><?php echo esc_html($lastBackup); ?></td>
            </tr>

        </tbody>

    </table>

    <br>

    <button
        id="wpcb-create-backup"
        class="button button-primary button-hero">
        <?php esc_html_e('Create Backup', 'rebuzz-backup-and-restore'); ?>
    </button>

    <span
        id="wpcb-loading"
        style="display:none;margin-left:15px;">
        <?php esc_html_e('Creating backup...', 'rebuzz-backup-and-restore'); ?>
    </span>

    <br><br>

    <div
        id="wpcb-progress"
        style="
            width:500px;
            height:24px;
            border:1px solid #ccc;
            display:none;
            background:#f1f1f1;
        ">

        <div
            id="wpcb-progress-bar"
            style="
                width:0%;
                height:100%;
                background:#2271b1;
                color:#fff;
                text-align:center;
                line-height:24px;
                font-weight:bold;
            ">
            0%
        </div>

    </div>

    <br>

    <div id="wpcb-status"></div>

    <?php if ($tempSize > 0) : ?>

        <hr>

        <h2><?php esc_html_e('Temporary Files', 'rebuzz-backup-and-restore'); ?></h2>

        <p>
            <?php
            printf(
                wp_kses(
                    /* translators: %s: current size of temporary files, e.g. "12 MB" */
                    __("A backup or restore that didn't finish (hit a size/file-count limit, or was interrupted) can leave scratch files behind in a temporary folder. These aren't backups themselves and are safe to delete - currently using <strong>%s</strong>.", 'rebuzz-backup-and-restore'),
                    ['strong' => []]
                ),
                esc_html(size_format($tempSize))
            );
            ?>
        </p>

        <button
            id="wpcb-clear-temp"
            class="button button-secondary">
            <?php esc_html_e('Clear Temporary Files', 'rebuzz-backup-and-restore'); ?>
        </button>

        <span id="wpcb-clear-temp-status" style="margin-left:10px;"></span>

    <?php endif; ?>

    <?php if (!empty($foreignBackups)) : ?>

        <hr>

        <h2><?php esc_html_e("Other Backup Plugins' Data", 'rebuzz-backup-and-restore'); ?></h2>

        <p>
            <?php esc_html_e("This site has old backup data from another plugin still on disk. It isn't included in new backups made with this version, but the files themselves are still sitting there using space:", 'rebuzz-backup-and-restore'); ?>
        </p>

        <ul style="list-style:disc;margin-left:20px;">
            <?php foreach ($foreignBackups as $folder) : ?>
                <li>
                    <strong><?php echo esc_html($folder['label']); ?></strong>
                    &mdash; <?php echo esc_html(size_format($folder['size'])); ?>
                </li>
            <?php endforeach; ?>
        </ul>

        <button
            id="wpcb-clear-foreign-backups"
            class="button button-secondary">
            <?php
            printf(
                /* translators: %s: total size of other plugins' leftover backup data, e.g. "12 MB" */
                esc_html__("Clear Other Plugins' Backup Data (%s)", 'rebuzz-backup-and-restore'),
                esc_html(size_format($foreignBackupsTotal))
            );
            ?>
        </button>

        <span id="wpcb-clear-foreign-backups-status" style="margin-left:10px;"></span>

    <?php endif; ?>

    <hr>

    <h2><?php esc_html_e('Backup History', 'rebuzz-backup-and-restore'); ?></h2>

    <p>
        <?php
        printf(
            wp_kses(
                /* translators: %s: link to the Restore page, with "Restore" as the link text */
                __('To restore any backup below, or to upload a backup ZIP from elsewhere, use the %s page.', 'rebuzz-backup-and-restore'),
                ['a' => ['href' => []]]
            ),
            '<a href="' . esc_url(admin_url('admin.php?page=wpcb-restore')) . '">' . esc_html__('Restore', 'rebuzz-backup-and-restore') . '</a>'
        );
        ?>
    </p>

    <?php if (empty($backups)) : ?>

        <p><?php esc_html_e('No backups available.', 'rebuzz-backup-and-restore'); ?></p>

    <?php else : ?>

        <table class="widefat striped">

            <thead>

                <tr>
                    <th><?php esc_html_e('Backup File', 'rebuzz-backup-and-restore'); ?></th>
                    <th><?php esc_html_e('Size', 'rebuzz-backup-and-restore'); ?></th>
                    <th><?php esc_html_e('Date', 'rebuzz-backup-and-restore'); ?></th>
                    <th width="260"><?php esc_html_e('Actions', 'rebuzz-backup-and-restore'); ?></th>
                </tr>

            </thead>

            <tbody>

            <?php foreach ($backups as $backup) : ?>

                <tr data-backup-row="<?php echo esc_attr($backup['name']); ?>">

                    <td><?php echo esc_html($backup['name']); ?></td>

                    <td><?php echo esc_html($backup['size']); ?></td>

                    <td><?php echo esc_html($backup['date']); ?></td>

                    <td>

                        <?php

                        $download_url = wp_nonce_url(
                            admin_url(
                                'admin-post.php?action=wpcb_download_backup&file=' .
                                rawurlencode($backup['name'])
                            ),
                            'wpcb_download'
                        );

                        ?>

                        <a
                            class="button button-secondary"
                            href="<?php echo esc_url($download_url); ?>">
                            <?php esc_html_e('Download', 'rebuzz-backup-and-restore'); ?>
                        </a>

                        <a
                            class="button"
                            href="<?php echo esc_url(
                                admin_url(
                                    'admin.php?page=wpcb-restore&file=' .
                                    rawurlencode($backup['name'])
                                )
                            ); ?>">
                            <?php esc_html_e('Restore', 'rebuzz-backup-and-restore'); ?>
                        </a>

                        <button
                            class="button wpcb-delete-backup"
                            data-file="<?php echo esc_attr($backup['name']); ?>">
                            <?php esc_html_e('Delete', 'rebuzz-backup-and-restore'); ?>
                        </button>

                    </td>

                </tr>

            <?php endforeach; ?>

            </tbody>

        </table>

    <?php endif; ?>

    <?php
    /*
     * Read from wpcb_backups_dir() rather than written out as a fixed
     * string, so it stays correct on a site with a custom uploads
     * location. An FTP drop-in is the only way to restore an archive
     * too large for a browser upload, so the exact path belongs here.
     */
    ?>
    <p class="description" style="margin-top:12px;">
        <?php
        printf(
            wp_kses(
                /* translators: %s: path to the backups folder, relative to the WordPress root */
                __('Backups are stored in <code>%s</code>. Drop a backup ZIP there over FTP and it appears in the list above, with no upload size limit. Archives are only ever written to a location this plugin has confirmed is not reachable over the web, and are downloaded through this admin screen rather than by URL.', 'rebuzz-backup-and-restore'),
                ['code' => []]
            ),
            esc_html(wpcb_display_path(wpcb_backups_dir()) . '/')
        );
        ?>
    </p>

</div>