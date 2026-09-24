<?php

if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- included from inside a method (WPCB_Admin::dashboard() etc), so these are function-scoped, not globals.

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only page selection behind manage_options; the restore POST verifies its own nonce.
$file = isset($_GET['file'])
    ? sanitize_file_name(wp_unslash($_GET['file']))
    : '';
// phpcs:enable WordPress.Security.NonceVerification.Recommended

$zip = wpcb_backups_dir() . '/' . $file;

?>

<div class="wrap">

    <h1><?php esc_html_e('Restore Backup', 'rebuzz-backup-and-restore'); ?></h1>

    <div class="card" style="max-width:800px;">

        <h2><?php esc_html_e('Upload a Backup ZIP', 'rebuzz-backup-and-restore'); ?></h2>

        <p><?php esc_html_e('Upload a backup file created on this site (or another ReBuzz Backup and Restore install) to restore it.', 'rebuzz-backup-and-restore'); ?></p>

        <?php

        $maxUploadBytes = wpcb_max_backup_upload_bytes();

        if ($maxUploadBytes > 0) : ?>

            <p>
                <em>
                    <?php
                    printf(
                        wp_kses(
                            /* translators: 1 & 2: the maximum upload size, e.g. "300 MB" (shown twice); 3: path to the backups folder, relative to the WordPress root */
                            __("This form accepts uploads up to %1\$s in a single request (the smaller of your host's <code>upload_max_filesize</code> / <code>post_max_size</code> PHP settings, and this plugin's own safety limit, which exists because very large browser uploads can overload shared hosting). A backup larger than %2\$s will fail through this form - simply place the file directly in <code>%3\$s</code> on the server (via FTP, a file manager, etc.) and it'll show up in the list below &rarr; restoring it from there works at any size.", 'rebuzz-backup-and-restore'),
                            ['code' => []]
                        ),
                        esc_html(size_format($maxUploadBytes)),
                        esc_html(size_format($maxUploadBytes)),
                        esc_html(wpcb_display_path(wpcb_backups_dir()) . '/')
                    );
                    ?>
                </em>
            </p>

        <?php endif; ?>

        <form
            method="post"
            enctype="multipart/form-data"
            action="<?php echo esc_url(admin_url('admin-post.php?action=wpcb_upload_backup')); ?>">

            <?php wp_nonce_field('wpcb_upload_backup'); ?>
            <input type="hidden" name="action" value="wpcb_upload_backup">
            <?php if ($maxUploadBytes > 0) : ?>
                <input type="hidden" name="MAX_FILE_SIZE" value="<?php echo esc_attr($maxUploadBytes); ?>">
            <?php endif; ?>

            <input type="file" name="backup_file" accept=".zip" required>

            <button type="submit" class="button button-secondary">
                <?php esc_html_e('Upload & Inspect', 'rebuzz-backup-and-restore'); ?>
            </button>

        </form>

    </div>

    <br>

    <?php

    if (empty($file)) {

        $history = new WPCB_History();
        $backups = $history->get_backups();

        if (empty($backups)) {

            echo '<div class="notice notice-info"><p>'
                . esc_html__('No backups on this site yet. Upload a backup ZIP above, or create one from the Dashboard first.', 'rebuzz-backup-and-restore')
                . '</p></div>';

            return;
        }

        ?>

        <h2><?php esc_html_e('Or Restore an Existing Backup', 'rebuzz-backup-and-restore'); ?></h2>

        <table class="widefat striped" style="max-width:800px">

            <thead>
                <tr>
                    <th><?php esc_html_e('Backup File', 'rebuzz-backup-and-restore'); ?></th>
                    <th><?php esc_html_e('Size', 'rebuzz-backup-and-restore'); ?></th>
                    <th><?php esc_html_e('Date', 'rebuzz-backup-and-restore'); ?></th>
                    <th width="120"><?php esc_html_e('Action', 'rebuzz-backup-and-restore'); ?></th>
                </tr>
            </thead>

            <tbody>

            <?php foreach ($backups as $backup) : ?>

                <tr>
                    <td><?php echo esc_html($backup['name']); ?></td>
                    <td><?php echo esc_html($backup['size']); ?></td>
                    <td><?php echo esc_html($backup['date']); ?></td>
                    <td>
                        <a
                            class="button button-secondary"
                            href="<?php echo esc_url(
                                admin_url(
                                    'admin.php?page=wpcb-restore&file=' .
                                    rawurlencode($backup['name'])
                                )
                            ); ?>">
                            <?php esc_html_e('Select', 'rebuzz-backup-and-restore'); ?>
                        </a>
                    </td>
                </tr>

            <?php endforeach; ?>

            </tbody>

        </table>

        <?php

        return;
    }

    if (!file_exists($zip)) {

        echo '<div class="notice notice-error"><p>' . esc_html__('Backup not found.', 'rebuzz-backup-and-restore') . '</p></div>';
        return;

    }

    $inspector = new WPCB_Backup_Inspector();

    $result = $inspector->inspect($zip);

    if (!$result['success']) {

        echo '<div class="notice notice-error"><p>'
            . esc_html($result['message'])
            . '</p></div>';

        return;
    }

    $manifest = $result['manifest'];

    ?>

    <table class="widefat striped" style="max-width:800px">

        <tbody>

        <tr>
            <th width="250"><?php esc_html_e('Backup Type', 'rebuzz-backup-and-restore'); ?></th>
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
            <td><?php echo esc_html($manifest['statistics']['files']); ?></td>
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

    <br>

    <div class="notice notice-warning">

        <p>

            <strong><?php esc_html_e('Warning', 'rebuzz-backup-and-restore'); ?></strong>

            <?php esc_html_e('Restoring a backup will overwrite your current website.', 'rebuzz-backup-and-restore'); ?>

        </p>

    </div>

    <?php

    $backupWpVersion = (string) ($manifest['wordpress']['version'] ?? '');
    $siteWpVersion = get_bloginfo('version');

    if ($backupWpVersion !== '' && $backupWpVersion !== $siteWpVersion) : ?>

        <div class="notice notice-warning">
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



    <p>
        <label for="wpcb-remove-extra-files">

            <input
                type="checkbox"
                id="wpcb-remove-extra-files"
                value="1">

            <strong><?php esc_html_e("Remove files that aren't in this backup", 'rebuzz-backup-and-restore'); ?></strong>

        </label>
    </p>

    <p class="description" style="max-width:660px;">
        <?php esc_html_e('Leave this unticked and a restore only adds and overwrites, so a plugin or theme installed since the backup stays behind. Tick it and, once every file has been restored, any plugin, theme or uploads folder the backup did not contain is moved out of the way.', 'rebuzz-backup-and-restore'); ?>
        <?php esc_html_e('Nothing is deleted either way: what is moved goes to a folder alongside it with an "-old" suffix, and you get a button to remove those once you have checked the site over.', 'rebuzz-backup-and-restore'); ?>
    </p>


    <button
        id="wpcb-restore-backup"
        class="button button-primary button-hero"
        data-file="<?php echo esc_attr($file); ?>">

        <?php esc_html_e('Restore Backup', 'rebuzz-backup-and-restore'); ?>

    </button>

    <span
        id="wpcb-restore-loading"
        style="display:none;margin-left:15px;">
        <?php esc_html_e('Restoring...', 'rebuzz-backup-and-restore'); ?>
    </span>

    <br><br>

    <div
        id="wpcb-restore-progress"
        style="
            width:500px;
            height:24px;
            border:1px solid #ccc;
            display:none;
            background:#f1f1f1;
        ">

        <div
            id="wpcb-restore-progress-bar"
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

    <div id="wpcb-restore-status"></div>

</div>