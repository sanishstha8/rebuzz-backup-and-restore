<?php

if (!defined('ABSPATH')) {
    exit;
}

class WPCB_Deactivator
{
    /**
     * Undo the two things a restore switches off site-wide, since once
     * this plugin is deactivated none of its own code runs again and
     * nothing else would ever put them back.
     *
     * Deliberately leaves backups, logs, settings and the storage
     * folder alone - deactivation is not uninstallation, and a site
     * that reactivates should find its archives where it left them.
     */
    public static function deactivate()
    {
        // Deactivating mid-restore abandons that restore: the steps are
        // driven by this plugin's own AJAX handlers, so it cannot make
        // any further progress. Release the lock before the recovery
        // below, which correctly refuses to touch mu-plugins while a
        // restore still looks like it is running.
        if (function_exists('wpcb_restore_lock_release')) {
            wpcb_restore_lock_release();
        }

        if (function_exists('wpcb_backup_lock_release')) {
            wpcb_backup_lock_release();
        }

        /*
         * Re-enable any mu-plugin an in-flight restore had renamed
         * aside. These are ordinary site functionality - often
         * security-related - that was only ever meant to be off for the
         * few minutes a restore takes, and leaving them disabled by an
         * unrelated deactivation would be a silent, hard-to-trace
         * regression.
         */
        if (function_exists('wpcb_recover_disabled_mu_plugins')) {
            wpcb_recover_disabled_mu_plugins();
        }

        // The auto-updater guard needs no separate teardown: it is
        // derived from the restore lock file released above (see
        // wpcb_restore_guard_active()).
    }
}
