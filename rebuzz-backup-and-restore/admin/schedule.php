<?php

if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- included from inside a method (WPCB_Admin::dashboard() etc), so these are function-scoped, not globals.

global $wp_locale;

$wpcbTab = 'wpcb-schedule';

$schedule = WPCB_Scheduler::settings();
$state = WPCB_Scheduler::state();
$last = isset($state['last']) && is_array($state['last']) ? $state['last'] : null;
$next = WPCB_Scheduler::nextRun();
$enabled = $schedule['frequency'] !== 'off';
$dateTimeFormat = get_option('date_format') . ' ' . get_option('time_format');

$name = WPCB_Scheduler::OPTION;

?>

<div class="wrap wpcb-wrap">

    <?php include WPCB_PATH . 'admin/header.php'; ?>

    <?php settings_errors(); ?>

    <?php if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) : ?>

        <div class="notice notice-warning inline">
            <p>
                <?php
                printf(
                    wp_kses(
                        /* translators: %s: the constant name, DISABLE_WP_CRON */
                        __('WP-Cron is turned off on this site (<code>%s</code> in wp-config.php). Scheduled backups will only start if your host runs wp-cron.php on a timer - check with your host.', 'rebuzz-backup-and-restore'),
                        ['code' => []]
                    ),
                    'DISABLE_WP_CRON'
                );
                ?>
            </p>
        </div>

    <?php endif; ?>

    <?php include WPCB_PATH . 'admin/running.php'; ?>

    <div class="wpcb-grid wpcb-grid-2">

        <div class="wpcb-card">
            <span class="wpcb-stat-label"><?php esc_html_e('Next backup', 'rebuzz-backup-and-restore'); ?></span>
            <?php if ($enabled && $next) : ?>
                <span class="wpcb-stat-value">
                    <?php
                    printf(
                        /* translators: %s: time until the next backup, e.g. "5 hours" */
                        esc_html__('In %s', 'rebuzz-backup-and-restore'),
                        esc_html(human_time_diff(time(), $next))
                    );
                    ?>
                </span>
                <span class="wpcb-stat-note"><?php echo esc_html(wp_date($dateTimeFormat, $next)); ?></span>
            <?php else : ?>
                <span class="wpcb-stat-value"><?php esc_html_e('Not scheduled', 'rebuzz-backup-and-restore'); ?></span>
                <span class="wpcb-stat-note"><?php esc_html_e('Choose daily or weekly below.', 'rebuzz-backup-and-restore'); ?></span>
            <?php endif; ?>
        </div>

        <div class="wpcb-card">
            <span class="wpcb-stat-label"><?php esc_html_e('Last scheduled backup', 'rebuzz-backup-and-restore'); ?></span>
            <?php if ($last === null) : ?>
                <span class="wpcb-stat-value"><?php esc_html_e('None yet', 'rebuzz-backup-and-restore'); ?></span>
            <?php else : ?>
                <?php if ($last['status'] === 'completed' && empty($last['files_failed'])) : ?>
                    <span class="wpcb-stat-value wpcb-badge wpcb-badge-ok">
                        <span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
                        <?php esc_html_e('Completed', 'rebuzz-backup-and-restore'); ?>
                    </span>
                <?php elseif ($last['status'] === 'completed') : ?>
                    <span class="wpcb-stat-value wpcb-badge wpcb-badge-warn">
                        <span class="dashicons dashicons-warning" aria-hidden="true"></span>
                        <?php esc_html_e('Completed with warnings', 'rebuzz-backup-and-restore'); ?>
                    </span>
                <?php elseif ($last['status'] === 'skipped') : ?>
                    <span class="wpcb-stat-value wpcb-badge wpcb-badge-bad">
                        <span class="dashicons dashicons-dismiss" aria-hidden="true"></span>
                        <?php esc_html_e('Did not run', 'rebuzz-backup-and-restore'); ?>
                    </span>
                <?php else : ?>
                    <span class="wpcb-stat-value wpcb-badge wpcb-badge-bad">
                        <span class="dashicons dashicons-dismiss" aria-hidden="true"></span>
                        <?php esc_html_e('Failed', 'rebuzz-backup-and-restore'); ?>
                    </span>
                <?php endif; ?>
                <span class="wpcb-stat-note">
                    <?php echo esc_html(wp_date($dateTimeFormat, (int) $last['time'])); ?>
                    <?php if (!empty($last['size'])) : ?>
                        &middot; <?php echo esc_html(size_format((int) $last['size'])); ?>
                    <?php endif; ?>
                </span>
                <?php if ($last['status'] !== 'completed' || !empty($last['files_failed'])) : ?>
                    <span class="wpcb-stat-note"><?php echo esc_html($last['message']); ?></span>
                <?php endif; ?>
            <?php endif; ?>
        </div>

    </div>

    <form method="post" action="options.php">

        <?php settings_fields('wpcb_schedule_group'); ?>

        <div class="wpcb-card">

            <h2><?php esc_html_e('Automatic backups', 'rebuzz-backup-and-restore'); ?></h2>

            <table class="form-table" role="presentation">

                <tr>
                    <th scope="row"><label for="wpcb-frequency"><?php esc_html_e('Back up', 'rebuzz-backup-and-restore'); ?></label></th>
                    <td>
                        <select id="wpcb-frequency" name="<?php echo esc_attr($name); ?>[frequency]">
                            <option value="off" <?php selected($schedule['frequency'], 'off'); ?>><?php esc_html_e('Never (off)', 'rebuzz-backup-and-restore'); ?></option>
                            <option value="daily" <?php selected($schedule['frequency'], 'daily'); ?>><?php esc_html_e('Every day', 'rebuzz-backup-and-restore'); ?></option>
                            <option value="weekly" <?php selected($schedule['frequency'], 'weekly'); ?>><?php esc_html_e('Every week', 'rebuzz-backup-and-restore'); ?></option>
                        </select>
                    </td>
                </tr>

                <tr class="wpcb-when-weekly">
                    <th scope="row"><label for="wpcb-weekday"><?php esc_html_e('On', 'rebuzz-backup-and-restore'); ?></label></th>
                    <td>
                        <select id="wpcb-weekday" name="<?php echo esc_attr($name); ?>[weekday]">
                            <?php for ($day = 0; $day < 7; $day++) : ?>
                                <option value="<?php echo esc_attr($day); ?>" <?php selected((int) $schedule['weekday'], $day); ?>><?php echo esc_html($wp_locale->get_weekday($day)); ?></option>
                            <?php endfor; ?>
                        </select>
                    </td>
                </tr>

                <tr class="wpcb-when-on">
                    <th scope="row"><label for="wpcb-hour"><?php esc_html_e('At', 'rebuzz-backup-and-restore'); ?></label></th>
                    <td>
                        <select id="wpcb-hour" name="<?php echo esc_attr($name); ?>[hour]">
                            <?php for ($hour = 0; $hour < 24; $hour++) : ?>
                                <option value="<?php echo esc_attr($hour); ?>" <?php selected((int) $schedule['hour'], $hour); ?>>
                                    <?php echo esc_html(wp_date(get_option('time_format'), $hour * HOUR_IN_SECONDS, new DateTimeZone('UTC'))); ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                        <p class="description">
                            <?php
                            printf(
                                /* translators: %s: the site's timezone, e.g. "Asia/Kathmandu" or "UTC+5:45" */
                                esc_html__('Your site\'s time (%s). A quiet hour is best: the backup adds some load while it runs.', 'rebuzz-backup-and-restore'),
                                esc_html(wp_timezone_string())
                            );
                            ?>
                        </p>
                    </td>
                </tr>

                <tr class="wpcb-when-on">
                    <th scope="row"><label for="wpcb-keep"><?php esc_html_e('Keep', 'rebuzz-backup-and-restore'); ?></label></th>
                    <td>
                        <input type="number" id="wpcb-keep" class="small-text" min="1" max="50" name="<?php echo esc_attr($name); ?>[keep]" value="<?php echo esc_attr((int) $schedule['keep']); ?>">
                        <?php esc_html_e('scheduled backups', 'rebuzz-backup-and-restore'); ?>
                        <p class="description"><?php esc_html_e('When a new one finishes, the oldest scheduled backups beyond this number are deleted. Backups you create yourself are never deleted automatically.', 'rebuzz-backup-and-restore'); ?></p>
                    </td>
                </tr>

            </table>

        </div>

        <div class="wpcb-card">

            <h2><?php esc_html_e('Email notices', 'rebuzz-backup-and-restore'); ?></h2>

            <table class="form-table" role="presentation">

                <tr>
                    <th scope="row"><?php esc_html_e('Send an email', 'rebuzz-backup-and-restore'); ?></th>
                    <td>
                        <fieldset>
                            <label>
                                <input type="radio" name="<?php echo esc_attr($name); ?>[notify]" value="failure" <?php checked($schedule['notify'], 'failure'); ?>>
                                <?php esc_html_e('Only when something goes wrong', 'rebuzz-backup-and-restore'); ?>
                            </label><br>
                            <label>
                                <input type="radio" name="<?php echo esc_attr($name); ?>[notify]" value="always" <?php checked($schedule['notify'], 'always'); ?>>
                                <?php esc_html_e('After every scheduled backup', 'rebuzz-backup-and-restore'); ?>
                            </label><br>
                            <label>
                                <input type="radio" name="<?php echo esc_attr($name); ?>[notify]" value="off" <?php checked($schedule['notify'], 'off'); ?>>
                                <?php esc_html_e('Never', 'rebuzz-backup-and-restore'); ?>
                            </label>
                        </fieldset>
                        <p class="description"><?php esc_html_e('"Something goes wrong" means a backup failed, finished with files skipped, or could not start.', 'rebuzz-backup-and-restore'); ?></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><label for="wpcb-email"><?php esc_html_e('To', 'rebuzz-backup-and-restore'); ?></label></th>
                    <td>
                        <input type="text" id="wpcb-email" class="regular-text" name="<?php echo esc_attr($name); ?>[email]" value="<?php echo esc_attr($schedule['email']); ?>">
                        <button type="button" id="wpcb-test-email" class="button"><?php esc_html_e('Send test email', 'rebuzz-backup-and-restore'); ?></button>
                        <p class="description"><?php esc_html_e('Separate several addresses with commas.', 'rebuzz-backup-and-restore'); ?></p>
                        <p id="wpcb-test-email-status" class="wpcb-inline-status" aria-live="polite"></p>
                    </td>
                </tr>

            </table>

        </div>

        <?php submit_button(__('Save Schedule', 'rebuzz-backup-and-restore')); ?>

    </form>

    <?php if ($enabled) : ?>

        <div class="wpcb-card">

            <h2><?php esc_html_e('Run it now', 'rebuzz-backup-and-restore'); ?></h2>

            <p><?php esc_html_e('Starts a backup in the background exactly as the schedule would, including deleting older scheduled backups and the email notice. Handy for checking that everything works.', 'rebuzz-backup-and-restore'); ?></p>

            <div class="wpcb-action">
                <button type="button" id="wpcb-run-schedule-now" class="button"><?php esc_html_e('Run scheduled backup now', 'rebuzz-backup-and-restore'); ?></button>
                <span id="wpcb-run-now-status" class="wpcb-loading" aria-live="polite"></span>
            </div>

        </div>

    <?php endif; ?>

    <p class="wpcb-footnote">
        <?php esc_html_e('WordPress starts scheduled tasks when someone visits the site, so on a very quiet site a backup can begin a little after the chosen time. If your host offers real cron jobs, pointing one at wp-cron.php makes it start on time.', 'rebuzz-backup-and-restore'); ?>
    </p>

</div>
