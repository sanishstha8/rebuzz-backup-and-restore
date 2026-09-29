<?php

if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- included from inside a method (WPCB_Admin::dashboard() etc), so these are function-scoped, not globals.

$wpcbTab = 'wpcb-restore';

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only page selection behind manage_options; the restore POST verifies its own nonce.
$file = isset($_GET['file'])
    ? sanitize_file_name(wp_unslash($_GET['file']))
    : '';
// phpcs:enable WordPress.Security.NonceVerification.Recommended

$zip = wpcb_backups_dir() . '/' . $file;

$result = null;
$error = '';

if ($file !== '') {

    if (!file_exists($zip)) {
        $error = __('Backup not found.', 'rebuzz-backup-and-restore');
    } else {
        $inspector = new WPCB_Backup_Inspector();
        $result = $inspector->inspect($zip);

        if (!$result['success']) {
            $error = $result['message'];
        }
    }
}

$backToList = admin_url('admin.php?page=wpcb-restore');

?>

<div class="wrap wpcb-wrap">

    <?php include WPCB_PATH . 'admin/header.php'; ?>

    <?php if ($file === '') : ?>

        <?php
        $history = new WPCB_History();
        $backups = $history->get_backups();
        $maxUploadBytes = wpcb_max_backup_upload_bytes();
        ?>

        <div class="wpcb-grid wpcb-grid-2">

            <div class="wpcb-card">

                <h2><?php esc_html_e('Restore a backup from this site', 'rebuzz-backup-and-restore'); ?></h2>

                <p><?php esc_html_e('Choose a backup to see what it contains. Nothing changes until you confirm on the next screen.', 'rebuzz-backup-and-restore'); ?></p>

                <?php if (empty($backups)) : ?>

                    <p class="wpcb-empty"><?php esc_html_e('No backups on this site yet. Upload one, or create one on the Backups tab.', 'rebuzz-backup-and-restore'); ?></p>

                <?php else : ?>

                    <table class="widefat striped wpcb-backups">

                        <thead>
                            <tr>
                                <th><?php esc_html_e('Backup', 'rebuzz-backup-and-restore'); ?></th>
                                <th class="wpcb-col-size"><?php esc_html_e('Size', 'rebuzz-backup-and-restore'); ?></th>
                                <th class="wpcb-col-choose"><span class="screen-reader-text"><?php esc_html_e('Action', 'rebuzz-backup-and-restore'); ?></span></th>
                            </tr>
                        </thead>

                        <tbody>

                        <?php foreach ($backups as $backup) : ?>

                            <tr>
                                <td>
                                    <span class="wpcb-backup-date"><?php echo esc_html($backup['date']); ?></span>
                                    <span class="wpcb-backup-file"><?php echo esc_html($backup['name']); ?></span>
                                </td>
                                <td class="wpcb-col-size"><?php echo esc_html($backup['size']); ?></td>
                                <td class="wpcb-col-choose">
                                    <a
                                        class="button"
                                        href="<?php echo esc_url(
                                            admin_url(
                                                'admin.php?page=wpcb-restore&file=' .
                                                rawurlencode($backup['name'])
                                            )
                                        ); ?>">
                                        <?php esc_html_e('Choose', 'rebuzz-backup-and-restore'); ?>
                                    </a>
                                </td>
                            </tr>

                        <?php endforeach; ?>

                        </tbody>

                    </table>

                <?php endif; ?>

            </div>

            <div class="wpcb-card">

                <h2><?php esc_html_e('Upload a backup', 'rebuzz-backup-and-restore'); ?></h2>

                <p><?php esc_html_e('A backup ZIP made by ReBuzz Backup and Restore on this site or on another one. It is checked before anything is restored.', 'rebuzz-backup-and-restore'); ?></p>

                <?php if (WPCB_Storage::remotes()) : ?>
                    <p>
                        <?php
                        printf(
                            wp_kses(
                                /* translators: %s: link to the Storage tab */
                                __('Backup in the cloud? Download it to this site from the %s tab first, and it appears in the list.', 'rebuzz-backup-and-restore'),
                                ['a' => ['href' => []]]
                            ),
                            '<a href="' . esc_url(admin_url('admin.php?page=wpcb-storage')) . '">' . esc_html__('Storage', 'rebuzz-backup-and-restore') . '</a>'
                        );
                        ?>
                    </p>
                <?php endif; ?>

                <form
                    class="wpcb-upload-form"
                    method="post"
                    enctype="multipart/form-data"
                    action="<?php echo esc_url(admin_url('admin-post.php?action=wpcb_upload_backup')); ?>">

                    <?php wp_nonce_field('wpcb_upload_backup'); ?>
                    <input type="hidden" name="action" value="wpcb_upload_backup">
                    <?php if ($maxUploadBytes > 0) : ?>
                        <input type="hidden" name="MAX_FILE_SIZE" value="<?php echo esc_attr($maxUploadBytes); ?>">
                    <?php endif; ?>

                    <input type="file" name="backup_file" accept=".zip" required>

                    <button type="submit" class="button button-primary">
                        <?php esc_html_e('Upload & Inspect', 'rebuzz-backup-and-restore'); ?>
                    </button>

                </form>

                <?php if ($maxUploadBytes > 0) : ?>

                    <p class="wpcb-footnote">
                        <?php
                        // The limit is the smaller of upload_max_filesize, post_max_size and this plugin's own cap.
                        printf(
                            wp_kses(
                                /* translators: 1: the maximum upload size, e.g. "300 MB"; 2: path to the backups folder, relative to the WordPress root */
                                __('This form takes files up to %1$s. For a bigger backup, copy the ZIP into <code>%2$s</code> with FTP or your host\'s file manager. It then appears in the list, and restores at any size.', 'rebuzz-backup-and-restore'),
                                ['code' => []]
                            ),
                            esc_html(size_format($maxUploadBytes)),
                            esc_html(wpcb_display_path(wpcb_backups_dir()) . '/')
                        );
                        ?>
                    </p>

                <?php endif; ?>

            </div>

        </div>

    <?php elseif ($error !== '') : ?>

        <a class="wpcb-back" href="<?php echo esc_url($backToList); ?>">&larr; <?php esc_html_e('Back to all backups', 'rebuzz-backup-and-restore'); ?></a>

        <div class="notice notice-error inline"><p><?php echo esc_html($error); ?></p></div>

    <?php else : ?>

        <?php $manifest = $result['manifest']; ?>

        <a class="wpcb-back" href="<?php echo esc_url($backToList); ?>">&larr; <?php esc_html_e('Choose a different backup', 'rebuzz-backup-and-restore'); ?></a>

        <div class="wpcb-card">

            <h2><?php esc_html_e('Restore this backup', 'rebuzz-backup-and-restore'); ?></h2>

            <p class="wpcb-backup-file"><?php echo esc_html($file); ?></p>

            <table class="widefat striped wpcb-details" style="max-width:720px;margin-top:12px;">

                <tbody>

                <tr>
                    <th><?php esc_html_e('Backup Type', 'rebuzz-backup-and-restore'); ?></th>
                    <td><?php echo esc_html($manifest['backup']['type']); ?></td>
                </tr>

                <tr>
                    <th><?php esc_html_e('Created', 'rebuzz-backup-and-restore'); ?></th>
                    <td><?php echo esc_html($manifest['backup']['created_at']); ?></td>
                </tr>

                <tr>
                    <th><?php esc_html_e('WordPress', 'rebuzz-backup-and-restore'); ?></th>
                    <td><?php echo esc_html($manifest['wordpress']['version']); ?></td>
                </tr>

                <tr>
                    <th><?php esc_html_e('PHP', 'rebuzz-backup-and-restore'); ?></th>
                    <td><?php echo esc_html($manifest['server']['php_version']); ?></td>
                </tr>

                <tr>
                    <th><?php esc_html_e('Files', 'rebuzz-backup-and-restore'); ?></th>
                    <td><?php echo esc_html(number_format_i18n((int) $manifest['statistics']['files'])); ?></td>
                </tr>

                <tr>
                    <th><?php esc_html_e('Database Tables', 'rebuzz-backup-and-restore'); ?></th>
                    <td><?php echo esc_html($manifest['statistics']['database_tables']); ?></td>
                </tr>

                <tr>
                    <th><?php esc_html_e('Database Size', 'rebuzz-backup-and-restore'); ?></th>
                    <td><?php echo esc_html(size_format($manifest['statistics']['database_size'])); ?></td>
                </tr>

                </tbody>

            </table>

            <div class="notice notice-warning inline" style="margin-top:20px;">
                <p>
                    <strong><?php esc_html_e('Warning', 'rebuzz-backup-and-restore'); ?></strong>
                    <?php esc_html_e('Restoring a backup will overwrite your current website.', 'rebuzz-backup-and-restore'); ?>
                </p>
            </div>

            <?php
            // Different core version: says which version the site ends up on.
            $backupWpVersion = (string) ($manifest['wordpress']['version'] ?? '');
            $siteWpVersion = get_bloginfo('version');

            if ($backupWpVersion !== '' && $backupWpVersion !== $siteWpVersion) : ?>

                <div class="notice notice-warning inline">
                    <p>
                        <strong><?php esc_html_e('Different WordPress version', 'rebuzz-backup-and-restore'); ?></strong>
                        <?php
                        if (!empty($result['has_core'])) {
                            printf(
                                /* translators: 1: WordPress version in the backup, 2: WordPress version this site runs */
                                esc_html__('This backup was made on WordPress %1$s and this site runs %2$s. The restore puts WordPress %1$s back along with its database, so the site will run %1$s afterwards.', 'rebuzz-backup-and-restore'),
                                esc_html($backupWpVersion),
                                esc_html($siteWpVersion)
                            );
                        } elseif (version_compare($backupWpVersion, $siteWpVersion, '>')) {
                            printf(
                                /* translators: 1: WordPress version in the backup, 2: WordPress version this site runs */
                                esc_html__('This backup was made on WordPress %1$s but holds no WordPress core files, so this site keeps running %2$s against a database from a newer version. Update WordPress to %1$s before restoring.', 'rebuzz-backup-and-restore'),
                                esc_html($backupWpVersion),
                                esc_html($siteWpVersion)
                            );
                        } else {
                            printf(
                                /* translators: 1: WordPress version in the backup, 2: WordPress version this site runs */
                                esc_html__('This backup was made on WordPress %1$s but holds no WordPress core files, so this site keeps running %2$s. WordPress will ask to update the restored database the first time you open the dashboard afterwards.', 'rebuzz-backup-and-restore'),
                                esc_html($backupWpVersion),
                                esc_html($siteWpVersion)
                            );
                        }
                        ?>
                    </p>
                </div>

            <?php endif; ?>

            <p class="wpcb-option">
                <label for="wpcb-remove-extra-files">
                    <input
                        type="checkbox"
                        id="wpcb-remove-extra-files"
                        value="1">
                    <strong><?php esc_html_e("Remove files that aren't in this backup", 'rebuzz-backup-and-restore'); ?></strong>
                </label>
            </p>

            <p class="description">
                <?php esc_html_e('Leave this unticked and a restore only adds and overwrites, so a plugin or theme installed since the backup stays behind. Tick it and, once every file has been restored, any plugin, theme or uploads folder the backup did not contain is moved out of the way.', 'rebuzz-backup-and-restore'); ?>
                <?php esc_html_e('Nothing is deleted either way: what is moved goes to a folder alongside it with an "-old" suffix, and you get a button to remove those once you have checked the site over.', 'rebuzz-backup-and-restore'); ?>
            </p>

            <div class="wpcb-action">

                <button
                    id="wpcb-restore-backup"
                    class="button button-primary button-hero"
                    data-file="<?php echo esc_attr($file); ?>">
                    <?php esc_html_e('Restore Backup', 'rebuzz-backup-and-restore'); ?>
                </button>

                <span id="wpcb-restore-loading" class="wpcb-loading" style="display:none;">
                    <?php esc_html_e('Restoring...', 'rebuzz-backup-and-restore'); ?>
                </span>

            </div>

            <div id="wpcb-restore-progress" class="wpcb-progress" style="display:none;">
                <div id="wpcb-restore-progress-bar" class="wpcb-progress-bar">0%</div>
            </div>

            <div id="wpcb-restore-status" class="wpcb-status"></div>

        </div>

    <?php endif; ?>

</div>
