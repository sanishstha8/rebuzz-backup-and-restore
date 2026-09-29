<?php

if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- included from inside a method (WPCB_Admin::dashboard() etc), so these are function-scoped, not globals.

$wpcbTab = 'wpcb-dashboard';

/* Backup history */

$history = new WPCB_History();
$backups = $history->get_backups();

$backupCount = count($backups);
$backupBytes = array_sum(array_column($backups, 'bytes'));
$latest = $backups ? $backups[0] : null;

/* Scratch files from unfinished jobs - see wpcb_temp_usage(). */

$tempSize = wpcb_temp_usage();

/* Other plugins' leftover data; empty folders have nothing to reclaim. */

$foreignBackups = array_values(array_filter(wpcb_detect_foreign_backups(), function ($folder) {
    return $folder['size'] > 0;
}));

$foreignBackupsTotal = array_sum(array_column($foreignBackups, 'size'));

$storagePrivate = wpcb_storage_is_private();

/* Schedule */

$schedule = WPCB_Scheduler::settings();
$nextRun = $schedule['frequency'] !== 'off' ? WPCB_Scheduler::nextRun() : 0;
$scheduledNames = WPCB_Scheduler::scheduledBackups();
$backgroundRunning = WPCB_Scheduler::runningJob() !== null;

?>

<div class="wrap wpcb-wrap">

    <?php include WPCB_PATH . 'admin/header.php'; ?>

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
    if (!$storagePrivate) : ?>

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
                    esc_html_e('This site could not make a request to itself to check whether the backup folder is readable from the web. Rather than assume it is safe, the plugin will not create a backup that might be publicly downloadable. If that was a passing network problem, the check runs again within five minutes.', 'rebuzz-backup-and-restore');
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

    <div class="wpcb-grid">

        <div class="wpcb-card">
            <span class="wpcb-stat-label"><?php esc_html_e('Last backup', 'rebuzz-backup-and-restore'); ?></span>
            <?php if ($latest) : ?>
                <span class="wpcb-stat-value">
                    <?php
                    printf(
                        /* translators: %s: how long ago, e.g. "2 hours" */
                        esc_html__('%s ago', 'rebuzz-backup-and-restore'),
                        esc_html(human_time_diff($latest['time']))
                    );
                    ?>
                </span>
                <span class="wpcb-stat-note"><?php echo esc_html($latest['date']); ?></span>
            <?php else : ?>
                <span class="wpcb-stat-value"><?php esc_html_e('Never', 'rebuzz-backup-and-restore'); ?></span>
                <span class="wpcb-stat-note"><?php esc_html_e('Create your first backup below.', 'rebuzz-backup-and-restore'); ?></span>
            <?php endif; ?>
        </div>

        <div class="wpcb-card">
            <span class="wpcb-stat-label"><?php esc_html_e('Stored backups', 'rebuzz-backup-and-restore'); ?></span>
            <span class="wpcb-stat-value"><?php echo esc_html(number_format_i18n($backupCount)); ?></span>
            <span class="wpcb-stat-note">
                <?php
                printf(
                    /* translators: %s: total size of all backups, e.g. "1.2 GB" */
                    esc_html__('%s in total', 'rebuzz-backup-and-restore'),
                    esc_html(size_format($backupBytes))
                );
                ?>
            </span>
        </div>

        <div class="wpcb-card">
            <span class="wpcb-stat-label"><?php esc_html_e('Backup folder', 'rebuzz-backup-and-restore'); ?></span>
            <?php if ($storagePrivate) : ?>
                <span class="wpcb-stat-value wpcb-badge wpcb-badge-ok">
                    <span class="dashicons dashicons-lock" aria-hidden="true"></span>
                    <?php esc_html_e('Private', 'rebuzz-backup-and-restore'); ?>
                </span>
            <?php else : ?>
                <span class="wpcb-stat-value wpcb-badge wpcb-badge-bad">
                    <span class="dashicons dashicons-warning" aria-hidden="true"></span>
                    <?php esc_html_e('Not private', 'rebuzz-backup-and-restore'); ?>
                </span>
            <?php endif; ?>
            <span class="wpcb-stat-note"><code><?php echo esc_html(wpcb_display_path(wpcb_backups_dir()) . '/'); ?></code></span>
        </div>

        <div class="wpcb-card">
            <span class="wpcb-stat-label"><?php esc_html_e('Next backup', 'rebuzz-backup-and-restore'); ?></span>
            <?php if ($nextRun) : ?>
                <span class="wpcb-stat-value">
                    <?php
                    printf(
                        /* translators: %s: time until the next backup, e.g. "5 hours" */
                        esc_html__('In %s', 'rebuzz-backup-and-restore'),
                        esc_html(human_time_diff(time(), $nextRun))
                    );
                    ?>
                </span>
                <span class="wpcb-stat-note">
                    <?php echo esc_html($schedule['frequency'] === 'weekly' ? __('Weekly', 'rebuzz-backup-and-restore') : __('Daily', 'rebuzz-backup-and-restore')); ?>
                    &middot; <?php echo esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), $nextRun)); ?>
                </span>
            <?php else : ?>
                <span class="wpcb-stat-value"><?php esc_html_e('Not scheduled', 'rebuzz-backup-and-restore'); ?></span>
                <span class="wpcb-stat-note"><a href="<?php echo esc_url(admin_url('admin.php?page=wpcb-schedule')); ?>"><?php esc_html_e('Set up automatic backups', 'rebuzz-backup-and-restore'); ?></a></span>
            <?php endif; ?>
        </div>

    </div>

    <?php include WPCB_PATH . 'admin/running.php'; ?>

    <div class="wpcb-card">

        <h2><?php esc_html_e('Create a backup', 'rebuzz-backup-and-restore'); ?></h2>

        <p><?php esc_html_e('Copies every file and the whole database into a single ZIP. It runs in small steps, so keep this page open until it finishes.', 'rebuzz-backup-and-restore'); ?></p>

        <div class="wpcb-action">

            <button
                id="wpcb-create-backup"
                class="button button-primary button-hero"
                <?php disabled(!$storagePrivate || $backgroundRunning); ?>>
                <?php esc_html_e('Create Backup', 'rebuzz-backup-and-restore'); ?>
            </button>

            <span id="wpcb-loading" class="wpcb-loading" style="display:none;">
                <?php esc_html_e('Creating backup...', 'rebuzz-backup-and-restore'); ?>
            </span>

        </div>

        <div id="wpcb-progress" class="wpcb-progress" style="display:none;">
            <div id="wpcb-progress-bar" class="wpcb-progress-bar">0%</div>
        </div>

        <div id="wpcb-status" class="wpcb-status"></div>

    </div>

    <div class="wpcb-card">

        <h2><?php esc_html_e('Your backups', 'rebuzz-backup-and-restore'); ?></h2>

        <?php if (empty($backups)) : ?>

            <p class="wpcb-empty"><?php esc_html_e('No backups yet. Backups you create, or ZIPs you upload to the backup folder, appear here.', 'rebuzz-backup-and-restore'); ?></p>

        <?php else : ?>

            <table class="widefat striped wpcb-backups">

                <thead>
                    <tr>
                        <th><?php esc_html_e('Backup', 'rebuzz-backup-and-restore'); ?></th>
                        <th class="wpcb-col-size"><?php esc_html_e('Size', 'rebuzz-backup-and-restore'); ?></th>
                        <th class="wpcb-col-actions"><span class="screen-reader-text"><?php esc_html_e('Actions', 'rebuzz-backup-and-restore'); ?></span></th>
                    </tr>
                </thead>

                <tbody>

                <?php foreach ($backups as $backup) : ?>

                    <?php
                    $download_url = wp_nonce_url(
                        admin_url(
                            'admin-post.php?action=wpcb_download_backup&file=' .
                            rawurlencode($backup['name'])
                        ),
                        'wpcb_download'
                    );
                    ?>

                    <tr data-backup-row="<?php echo esc_attr($backup['name']); ?>">

                        <td>
                            <span class="wpcb-backup-date">
                                <?php echo esc_html($backup['date']); ?>
                                <?php if (in_array($backup['name'], $scheduledNames, true)) : ?>
                                    <span class="wpcb-tag"><?php esc_html_e('Scheduled', 'rebuzz-backup-and-restore'); ?></span>
                                <?php endif; ?>
                                <?php foreach (WPCB_Storage::uploadedTo($backup['name']) as $cloudId) : ?>
                                    <span class="wpcb-tag wpcb-tag-ok"><?php echo esc_html($cloudId === 's3' ? 'S3' : 'Dropbox'); ?></span>
                                <?php endforeach; ?>
                            </span>
                            <span class="wpcb-backup-file"><?php echo esc_html($backup['name']); ?></span>
                        </td>

                        <td class="wpcb-col-size"><?php echo esc_html($backup['size']); ?></td>

                        <td class="wpcb-col-actions">

                            <a
                                class="button"
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
                                class="button button-link-delete wpcb-delete-backup"
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
        <p class="wpcb-footnote">
            <?php
            printf(
                wp_kses(
                    /* translators: %s: path to the backups folder, relative to the WordPress root */
                    __('Backups are kept in <code>%s</code> and downloaded through this screen, never by a public link. A backup ZIP copied into that folder over FTP shows up here too, at any size.', 'rebuzz-backup-and-restore'),
                    ['code' => []]
                ),
                esc_html(wpcb_display_path(wpcb_backups_dir()) . '/')
            );
            ?>
        </p>

    </div>

    <?php if ($tempSize > 0 || !empty($foreignBackups)) : ?>

        <div class="wpcb-card">

            <h2><?php esc_html_e('Free up space', 'rebuzz-backup-and-restore'); ?></h2>

            <?php if ($tempSize > 0) : ?>

                <div class="wpcb-tidy-row">
                    <p>
                        <?php
                        printf(
                            wp_kses(
                                /* translators: %s: current size of temporary files, e.g. "12 MB" */
                                __('<strong>%s of temporary files</strong> were left by a backup or restore that didn\'t finish. They aren\'t backups and are safe to delete.', 'rebuzz-backup-and-restore'),
                                ['strong' => []]
                            ),
                            esc_html(size_format($tempSize))
                        );
                        ?>
                        <span id="wpcb-clear-temp-status" class="wpcb-tidy-status"></span>
                    </p>
                    <button id="wpcb-clear-temp" class="button">
                        <?php esc_html_e('Clear Temporary Files', 'rebuzz-backup-and-restore'); ?>
                    </button>
                </div>

            <?php endif; ?>

            <?php if (!empty($foreignBackups)) : ?>

                <div class="wpcb-tidy-row">
                    <div>
                        <p><?php esc_html_e("Old backups from other backup plugins are still on disk. New backups leave them out, but they still take up space:", 'rebuzz-backup-and-restore'); ?></p>
                        <ul>
                            <?php foreach ($foreignBackups as $folder) : ?>
                                <li>
                                    <strong><?php echo esc_html($folder['label']); ?></strong>
                                    &mdash; <?php echo esc_html(size_format($folder['size'])); ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <span id="wpcb-clear-foreign-backups-status" class="wpcb-tidy-status"></span>
                    </div>
                    <button id="wpcb-clear-foreign-backups" class="button">
                        <?php
                        printf(
                            /* translators: %s: total size of other plugins' leftover backup data, e.g. "12 MB" */
                            esc_html__("Clear Other Plugins' Backup Data (%s)", 'rebuzz-backup-and-restore'),
                            esc_html(size_format($foreignBackupsTotal))
                        );
                        ?>
                    </button>
                </div>

            <?php endif; ?>

        </div>

    <?php endif; ?>

</div>
