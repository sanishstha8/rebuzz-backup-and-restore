<?php
/**
 * Plugin Name: ReBuzz Backup and Restore
 * Description: Complete WordPress Backup & Restore Solution
 * Version: 1.8.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: ReBuzz
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: rebuzz-backup-and-restore
 */

if (!defined('ABSPATH')) {
    exit;
}

/*
 * Two request-scoped guards for this plugin's own AJAX actions. Both
 * have to be set up before 'plugins_loaded', which is why they read
 * the action out of $_POST rather than waiting for WordPress to
 * dispatch it - neither does anything a request could exploit by
 * naming an action it isn't entitled to (see each below), and every
 * handler still runs its own nonce and capability checks.
 *
 * Anonymous, for the same reason as the loader call at the bottom of
 * this file: no named global function to collide with another copy.
 */
(function () {

    // is_scalar(): $_POST['action'] is attacker-controlled and can just
    // as easily arrive as an array (action[]=x), which older versions
    // of sanitize_key() would pass straight to strtolower().
    if (
        !defined('DOING_AJAX') || !DOING_AJAX ||
        !isset($_POST['action']) || !is_scalar($_POST['action']) // phpcs:ignore WordPress.Security.NonceVerification.Missing
    ) {
        return;
    }

    $action = sanitize_key(wp_unslash($_POST['action'])); // phpcs:ignore WordPress.Security.NonceVerification.Missing

    $ours = [
        'wpcb_start_backup',
        'wpcb_backup_step',
        'wpcb_start_restore',
        'wpcb_restore_step',
        'wpcb_delete_backup',
        'wpcb_clear_temp',
        'wpcb_clear_foreign_backups',
        'wpcb_run_schedule_now',
        'wpcb_background_step',
        'wpcb_background_status',
        'wpcb_test_email',
        'wpcb_s3_test',
        'wpcb_dropbox_start',
        'wpcb_dropbox_finish',
        'wpcb_dropbox_disconnect',
        'wpcb_dropbox_test',
        'wpcb_remote_list',
        'wpcb_remote_delete',
        'wpcb_transfer_start',
        'wpcb_transfer_step',
    ];

    if (!in_array($action, $ours, true)) {
        return;
    }

    /*
     * Earlier versions also called ini_set('display_errors', '0') and
     * opened an output buffer here, so a stray PHP warning from another
     * plugin during bootstrap could not leak into what must be pure
     * JSON. Both are gone.
     *
     * The buffer had no matching close in the same scope and could
     * misalign the buffer stack against whatever core or another plugin
     * opened later; the step handlers in class-admin.php now buffer
     * their own work instead, opening and closing within one function.
     *
     * The ini_set went with it: changing PHP's error reporting at
     * runtime is a global change this plugin has no business making. On
     * a production site display_errors is already off, so it bought
     * nothing there, and on a development site it hid errors the
     * developer had deliberately asked to see.
     */

    /*
     * Stops wp-cron from spawning during backup/restore step polling -
     * a cron run firing mid-restore can collide with it and leave the
     * site stuck in maintenance mode.
     *
     * Done by emptying the due-event list for this one request, which
     * is all wp_cron() consults before deciding whether to spawn. An
     * earlier version defined DOING_CRON instead; that is a global
     * constant other plugins branch on, and defining it made ordinary
     * authenticated AJAX polling look like a real cron run for the
     * rest of the request. This filter changes nothing beyond the
     * request that registers it, and wp_cron() runs on 'init', long
     * after this.
     */
    if (in_array($action, ['wpcb_backup_step', 'wpcb_restore_step', 'wpcb_background_step', 'wpcb_transfer_step'], true)) {
        add_filter('pre_get_ready_cron_jobs', '__return_empty_array');
    }

    /*
     * On a fatal, WordPress's own handler runs first and prints its
     * "critical error" page with a 500, ahead of any JSON - the browser
     * then only ever says "AJAX request failed". Silence it for these
     * requests so the real cause reaches the dashboard. (The
     * wp_should_handle_php_error filter can't do this: core only asks
     * it about non-fatal error types.)
     */
    add_filter('wp_php_error_message', '__return_empty_string', PHP_INT_MAX);
    add_filter('wp_php_error_args', function ($args) {

        $args['response'] = 200;
        $args['exit'] = false;

        return $args;
    }, PHP_INT_MAX);

    /*
     * Fallback for a fatal hit before a step's own crash handler exists
     * (during bootstrap, or in a start_* handler): answer with the real
     * error as JSON, and fail the job and free its lock the way the
     * step guard would - otherwise the lock blocks every later restore.
     * WPCB_Admin::guardAgainstFatalError() defines
     * WPCB_STEP_GUARD_ACTIVE when it takes over.
     */
    register_shutdown_function(function () use ($action) {

        if (defined('WPCB_STEP_GUARD_ACTIVE')) {
            return;
        }

        $error = error_get_last();

        if (!$error || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            return;
        }

        // Before 'init', translating this plugin's text raises a "loaded too early" notice on WP 6.7+.
        add_filter('doing_it_wrong_trigger_error', function ($trigger, $function, $notice) {
            return ($function === '_load_textdomain_just_in_time' && strpos($notice, 'rebuzz-backup-and-restore') !== false) ? false : $trigger;
        }, 10, 3);

        $message = sprintf(
            /* translators: 1: PHP error message, 2: line number, 3: file name */
            __('A PHP fatal error stopped this request: %1$s (line %2$d of %3$s).', 'rebuzz-backup-and-restore'),
            $error['message'],
            $error['line'],
            basename($error['file'])
        );

        // The crash may be in this plugin's own files, before its classes loaded.
        if (class_exists('WPCB_Admin') && function_exists('wpcb_fatal_error_hint')) {

            $message .= ' ' . wpcb_fatal_error_hint($error['message']);

            try {

                $crashed = WPCB_Admin::crashedRequestJob($action);

                if ($crashed !== null) {
                    WPCB_Admin::recordCrash($crashed[0], $crashed[1], $message);
                }

            } catch (\Throwable $e) {
                // Still answer with the original error below.
            }
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        if (!headers_sent()) {
            http_response_code(200);
            header('Content-Type: application/json; charset=utf-8');
        }

        echo json_encode([
            'success' => false,
            'data' => $message
        ]);
    });
})();

define('WPCB_VERSION', '1.8.0');
define('WPCB_PATH', plugin_dir_path(__FILE__));
define('WPCB_URL', plugin_dir_url(__FILE__));

// The plugin's Dropbox app (App folder access). wp-config.php can set its own.
if (!defined('WPCB_DROPBOX_APP_KEY')) {
    define('WPCB_DROPBOX_APP_KEY', '');
}

require_once WPCB_PATH . 'includes/class-activator.php';
require_once WPCB_PATH . 'includes/class-deactivator.php';
require_once WPCB_PATH . 'includes/class-loader.php';

register_activation_hook(__FILE__, ['WPCB_Activator', 'activate']);
register_deactivation_hook(__FILE__, ['WPCB_Deactivator', 'deactivate']);

// Anonymous, not a named global function - a same-named function from
// any other plugin (including an old/renamed copy of this one still
// present during a migration) would otherwise collide and fatal every
// site on the install, since PHP has no per-plugin function namespace.
(function () {
    $plugin = new WPCB_Loader();
    $plugin->run();
})();