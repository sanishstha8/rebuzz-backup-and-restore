<?php

if (!defined('ABSPATH')) {
    exit;
}

class WPCB_Loader
{
    public function run()
    {
        require_once WPCB_PATH . 'includes/helpers.php';
        require_once WPCB_PATH . 'includes/class-multisite.php';
        require_once WPCB_PATH . 'includes/class-filescanner.php';
        require_once WPCB_PATH . 'includes/class-admin.php';
        require_once WPCB_PATH . 'includes/class-database.php';
        require_once WPCB_PATH . 'includes/class-history.php';
        require_once WPCB_PATH . 'includes/class-manifest.php';
        require_once WPCB_PATH . 'includes/class-workspace.php';
        require_once WPCB_PATH . 'includes/class-validator.php';
        require_once WPCB_PATH . 'includes/class-statistics.php';
        require_once WPCB_PATH . 'includes/class-checksum.php';
        require_once WPCB_PATH . 'includes/class-backup-inspector.php';
        require_once WPCB_PATH . 'includes/class-extractor.php';
        require_once WPCB_PATH . 'includes/class-restore-workspace.php';
        require_once WPCB_PATH . 'includes/class-restore-job.php';
        require_once WPCB_PATH . 'includes/class-quarantine.php';
        require_once WPCB_PATH . 'includes/class-url-rewriter.php';
        require_once WPCB_PATH . 'includes/class-logger.php';
        require_once WPCB_PATH . 'includes/class-job.php';
        require_once WPCB_PATH . 'includes/class-backup-job.php';
        require_once WPCB_PATH . 'includes/class-zip-stream.php';
        require_once WPCB_PATH . 'includes/class-zip-batch.php';
        require_once WPCB_PATH . 'includes/class-integrity.php';

        /*
         * Holds WordPress's auto-updater off while a restore is
         * running, and puts back any mu-plugin a crashed restore left
         * renamed to *.php.disabled.
         *
         * Both run on every request rather than admin-only: the
         * auto-updater fires from wp-cron.php's own bootstrap, and a
         * site left without its mu-plugins needs healing whether or
         * not an administrator happens to log in.
         *
         * Both are hooked to 'plugins_loaded' rather than called
         * inline here. They resolve the restore lock's path, which on
         * multisite means a switch_to_blog() - not something to do
         * while plugins are still being included, since that fires
         * 'switch_blog' at other plugins that may not have registered
         * their handlers yet. Nothing either of them affects is read
         * before 'init', so this is comfortably early enough.
         */
        add_action('plugins_loaded', 'wpcb_register_restore_guard_filters');

        add_action('plugins_loaded', 'wpcb_recover_disabled_mu_plugins');
        // Same reasoning: a hard-killed restore leaves directories renamed
        // aside with no shutdown handler to put them back.
        add_action('plugins_loaded', ['WPCB_Quarantine', 'recover']);

        new WPCB_Admin();
    }
}
