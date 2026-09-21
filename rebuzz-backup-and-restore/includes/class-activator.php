<?php

if (!defined('ABSPATH')) {
    exit;
}

class WPCB_Activator
{
    /**
     * @param bool $networkWide True when activated via "Network Activate"
     *                          on the network admin plugins screen -
     *                          passed in by WordPress itself as this
     *                          hook's argument (register_activation_hook()).
     */
    public static function activate($networkWide = false)
    {
        self::checkRequirements();

        if (is_multisite() && $networkWide) {

            // Without this, WordPress only fires this hook once, in
            // whatever single site's context happens to be active
            // (typically the main site) - every other existing subsite
            // would never get its data directories/default options
            // created at activation time.
            foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $blogId) {

                switch_to_blog($blogId);
                self::activateSite();
                restore_current_blog();
            }

            return;
        }

        self::activateSite();
    }

    /**
     * Per-site setup: data directories + default options. Called once
     * directly for a normal/single-site activation, or once per
     * existing site (via switch_to_blog()) for a network activation -
     * see activate().
     */
    private static function activateSite()
    {
        // Carry over an earlier release's storage folder (and the
        // archives in it) before creating the current one, so an
        // upgrade doesn't look like it lost every existing backup.
        wpcb_migrate_legacy_data_dir();

        wpcb_prepare_data_directories();

        // add_option() won't overwrite an existing value, so a site
        // reactivating after an upgrade keeps whatever version it had -
        // update_option() here, or WPCB_Admin::maybeUpgrade() would go
        // on believing the upgrade still hasn't run.
        update_option('wpcb_version', WPCB_VERSION);

        add_option('wpcb_settings', [
            'include_core' => true
        ]);
    }

    /**
     * Refuse to activate on an environment this plugin can't safely run
     * on. A fatal error mid-backup/restore (missing ZipArchive) is much
     * worse than refusing to activate with a clear reason up front.
     */
    private static function checkRequirements()
    {
        $errors = [];

        if (version_compare(PHP_VERSION, '7.4', '<')) {
            $errors[] = sprintf(
                /* translators: %s: the PHP version currently installed on this server */
                __('PHP 7.4 or newer is required (this server has PHP %s).', 'rebuzz-backup-and-restore'),
                PHP_VERSION
            );
        }

        if (!class_exists('ZipArchive')) {
            $errors[] = __('The PHP zip extension (ZipArchive) is required, but is not enabled on this server.', 'rebuzz-backup-and-restore');
        }

        if (empty($errors)) {
            return;
        }

        deactivate_plugins(plugin_basename(WPCB_PATH . 'rebuzz-backup-and-restore.php'));

        wp_die(
            '<p><strong>' . esc_html__('ReBuzz Backup and Restore could not be activated:', 'rebuzz-backup-and-restore') . '</strong></p>' .
            '<ul><li>' . implode('</li><li>', array_map('esc_html', $errors)) . '</li></ul>' .
            '<p><a href="' . esc_url(admin_url('plugins.php')) . '">' . esc_html__('Return to Plugins', 'rebuzz-backup-and-restore') . '</a></p>'
        );
    }
}
