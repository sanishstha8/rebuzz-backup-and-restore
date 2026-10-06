<?php
if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- included from inside a method (WPCB_Admin::dashboard() etc), so these are function-scoped, not globals.

$wpcbTab = 'wpcb-settings';

$options = get_option('wpcb_settings', []);
?>

<div class="wrap wpcb-wrap">

    <?php include WPCB_PATH . 'admin/header.php'; ?>

    <?php settings_errors(); ?>

    <div class="wpcb-card">

        <h2><?php esc_html_e('Backup contents', 'rebuzz-backup-and-restore'); ?></h2>

        <form method="post" action="options.php">

            <?php settings_fields('wpcb_settings_group'); ?>

            <table class="form-table" role="presentation">

                <tr>
                    <th scope="row"><?php esc_html_e('WordPress core files', 'rebuzz-backup-and-restore'); ?></th>
                    <td>
                        <label for="wpcb-include-core">
                            <input
                                type="checkbox"
                                id="wpcb-include-core"
                                name="wpcb_settings[include_core]"
                                value="1"
                                <?php checked(!empty($options['include_core'])); ?>
                            >
                            <?php esc_html_e('Include WordPress core in each backup', 'rebuzz-backup-and-restore'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('Core is the wp-admin and wp-includes folders and the WordPress files in your site\'s main folder. With it included, a backup can rebuild the site on an empty server and puts back the exact WordPress version it was made with. Without it, backups are smaller and a restore keeps the WordPress version already installed.', 'rebuzz-backup-and-restore'); ?>
                        </p>
                        <p class="description">
                            <?php esc_html_e('Your themes, plugins, uploads and the whole database are always included. wp-config.php never is, so a restore keeps this site\'s database login.', 'rebuzz-backup-and-restore'); ?>
                        </p>
                    </td>
                </tr>

            </table>

            <?php submit_button(); ?>

        </form>

    </div>

    <div class="wpcb-card">

        <h2><?php esc_html_e('System information', 'rebuzz-backup-and-restore'); ?></h2>

        <p><?php esc_html_e('Useful when asking for help.', 'rebuzz-backup-and-restore'); ?></p>

        <table class="widefat striped wpcb-details" style="max-width:720px;">

            <tbody>

                <tr>
                    <th><?php esc_html_e('Plugin Version', 'rebuzz-backup-and-restore'); ?></th>
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
                    <td><code><?php echo esc_html(wpcb_backups_dir()); ?></code></td>
                </tr>

                <tr>
                    <th><?php esc_html_e('Logs', 'rebuzz-backup-and-restore'); ?></th>
                    <td><code><?php echo esc_html(wpcb_display_path(wpcb_logs_dir()) . '/'); ?></code></td>
                </tr>

            </tbody>

        </table>

    </div>

</div>
