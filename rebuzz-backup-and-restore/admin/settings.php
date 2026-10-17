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

    <div class="wpcb-card" id="wpcb-help">

        <h2><?php esc_html_e('Help and feedback', 'rebuzz-backup-and-restore'); ?></h2>

        <p><?php esc_html_e('Something not working, or an idea that would make the plugin better? Post it on the support forum.', 'rebuzz-backup-and-restore'); ?></p>

        <p><?php esc_html_e('When reporting a problem, include:', 'rebuzz-backup-and-restore'); ?></p>

        <ul class="wpcb-help-list">
            <li><?php esc_html_e('What you were doing, and the message the plugin showed.', 'rebuzz-backup-and-restore'); ?></li>
            <li><?php esc_html_e('The system information below - the Copy button puts it on your clipboard.', 'rebuzz-backup-and-restore'); ?></li>
            <li>
                <?php
                printf(
                    wp_kses(
                        /* translators: %s: path to the plugin's logs folder */
                        __('The last 20 or so lines of <code>backup.log</code> or <code>restore.log</code>, from <code>%s</code>.', 'rebuzz-backup-and-restore'),
                        ['code' => []]
                    ),
                    esc_html(wpcb_display_path(wpcb_logs_dir()) . '/')
                );
                ?>
            </li>
        </ul>

        <p class="wpcb-help-actions">
            <a class="button button-primary" href="<?php echo esc_url(WPCB_Feedback::SUPPORT_URL); ?>" target="_blank" rel="noopener noreferrer">
                <?php esc_html_e('Get support', 'rebuzz-backup-and-restore'); ?>
            </a>
            <a class="button" href="<?php echo esc_url(WPCB_Feedback::REVIEW_URL); ?>" target="_blank" rel="noopener noreferrer">
                <span class="dashicons dashicons-star-filled" aria-hidden="true"></span>
                <?php esc_html_e('Rate the plugin', 'rebuzz-backup-and-restore'); ?>
            </a>
        </p>

    </div>

    <div class="wpcb-card">

        <h2><?php esc_html_e('System information', 'rebuzz-backup-and-restore'); ?></h2>

        <p class="wpcb-action">
            <?php esc_html_e('Useful when asking for help.', 'rebuzz-backup-and-restore'); ?>
            <button type="button" class="button" id="wpcb-copy-system-info"><?php esc_html_e('Copy', 'rebuzz-backup-and-restore'); ?></button>
            <span id="wpcb-copy-system-info-status" class="wpcb-inline-status" role="status"></span>
        </p>

        <table class="widefat striped wpcb-details" id="wpcb-system-info" style="max-width:720px;">

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
                    <th><?php esc_html_e('PHP Memory Limit', 'rebuzz-backup-and-restore'); ?></th>
                    <td><?php echo esc_html((string) ini_get('memory_limit')); ?></td>
                </tr>

                <tr>
                    <th><?php esc_html_e('PHP Time Limit', 'rebuzz-backup-and-restore'); ?></th>
                    <td><?php echo esc_html((string) ini_get('max_execution_time')); ?></td>
                </tr>

                <tr>
                    <th><?php esc_html_e('Web Server', 'rebuzz-backup-and-restore'); ?></th>
                    <td><?php echo esc_html(isset($_SERVER['SERVER_SOFTWARE']) ? sanitize_text_field(wp_unslash($_SERVER['SERVER_SOFTWARE'])) : '-'); ?></td>
                </tr>

                <tr>
                    <th><?php esc_html_e('Multisite', 'rebuzz-backup-and-restore'); ?></th>
                    <td><?php echo is_multisite() ? esc_html__('Yes', 'rebuzz-backup-and-restore') : esc_html__('No', 'rebuzz-backup-and-restore'); ?></td>
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
