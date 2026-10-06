<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Scheduled backups: started by WP-Cron and driven to the end with no
 * browser open, then trimmed to the number to keep and reported by email.
 *
 * A manual backup moves forward because admin.js asks for one step at a
 * time. Here the site asks itself: each step ends by firing a
 * non-blocking request back to admin-ajax.php for the next one, so the
 * steps and their time limits are exactly those of a manual backup. A
 * cron event a few minutes out is the safety net for requests that stop
 * arriving - some hosts drop loopback requests - and either carries the
 * backup on itself or fails one that has stalled for good.
 */
class WPCB_Scheduler
{
    const HOOK = 'wpcb_scheduled_backup';
    const RESUME_HOOK = 'wpcb_scheduled_backup_resume';
    const STEP_ACTION = 'wpcb_background_step';

    const OPTION = 'wpcb_schedule';
    const STATE_OPTION = 'wpcb_schedule_state';
    const LIST_OPTION = 'wpcb_scheduled_backups';

    /** Seconds between the safety net's checks on a running backup. */
    const RESUME_DELAY = 180;

    /** Seconds of steps the safety net runs itself when self-requests aren't arriving. */
    const INLINE_BUDGET = 20;

    /** @var bool One after-request handler per request, however many steps it runs. */
    private static $afterRequestRegistered = false;

    public function __construct()
    {
        add_action(self::HOOK, [$this, 'run']);
        add_action(self::RESUME_HOOK, [$this, 'resume']);

        // Loopback requests carry no cookies, so they arrive logged out; the job's token is the check.
        add_action('wp_ajax_nopriv_' . self::STEP_ACTION, [$this, 'backgroundStep']);
        add_action('wp_ajax_' . self::STEP_ACTION, [$this, 'backgroundStep']);

        add_action('add_option_' . self::OPTION, [__CLASS__, 'reschedule']);
        add_action('update_option_' . self::OPTION, [__CLASS__, 'reschedule']);
        add_action('admin_init', [__CLASS__, 'ensureScheduled']);
    }

    /* Settings */

    public static function defaults()
    {
        return [
            'frequency' => 'off',
            'hour'      => 2,
            'weekday'   => 0,
            'keep'      => 5,
            'notify'    => 'failure',
            'email'     => (string) get_option('admin_email'),
            'user'      => 0,
        ];
    }

    public static function settings()
    {
        $saved = get_option(self::OPTION, []);

        return array_merge(self::defaults(), is_array($saved) ? $saved : []);
    }

    /** register_setting() sanitize callback for the Schedule tab's form. */
    public static function sanitize($input)
    {
        $input = is_array($input) ? $input : [];
        $clean = self::defaults();

        if (in_array($input['frequency'] ?? '', ['off', 'daily', 'weekly'], true)) {
            $clean['frequency'] = $input['frequency'];
        }

        $clean['hour'] = min(23, max(0, (int) ($input['hour'] ?? 2)));
        $clean['weekday'] = min(6, max(0, (int) ($input['weekday'] ?? 0)));
        $clean['keep'] = min(50, max(1, (int) ($input['keep'] ?? 5)));

        if (in_array($input['notify'] ?? '', ['off', 'failure', 'always'], true)) {
            $clean['notify'] = $input['notify'];
        }

        $clean['email'] = self::sanitizeEmails($input['email'] ?? '');

        if ($clean['email'] === '' && $clean['notify'] !== 'off') {
            add_settings_error(self::OPTION, 'wpcb_email', __('Enter at least one valid email address, or turn email notices off.', 'rebuzz-backup-and-restore'));
            $clean['email'] = (string) get_option('admin_email');
        }

        // Backups run as this administrator, so multisite scope matches a manual backup by them.
        $clean['user'] = get_current_user_id();

        return $clean;
    }

    /** Comma-separated addresses, invalid ones dropped. */
    public static function sanitizeEmails($raw)
    {
        $emails = [];

        foreach (explode(',', (string) $raw) as $email) {
            $email = sanitize_email(trim($email));

            if ($email !== '' && is_email($email)) {
                $emails[] = $email;
            }
        }

        return implode(', ', array_unique($emails));
    }

    /* When it runs */

    /**
     * Unix time of the first run strictly after $after, at the chosen
     * hour in the site's timezone. Worked out from local time on every
     * run rather than repeating every 86400 seconds, so the backup stays
     * at the chosen hour across daylight-saving changes.
     */
    public static function nextRunAfter(array $settings, $after)
    {
        $local = (new DateTimeImmutable('@' . (int) $after))->setTimezone(wp_timezone());
        $run = $local->setTime((int) $settings['hour'], 0);

        if ($settings['frequency'] === 'weekly') {

            $days = ((int) $settings['weekday'] - (int) $run->format('w') + 7) % 7;
            $run = $run->modify('+' . $days . ' days');

            if ($run->getTimestamp() <= $after) {
                $run = $run->modify('+7 days');
            }

        } elseif ($run->getTimestamp() <= $after) {
            $run = $run->modify('+1 day');
        }

        return $run->getTimestamp();
    }

    /** Replaces the next scheduled run with one that matches the current settings. */
    public static function reschedule()
    {
        wp_clear_scheduled_hook(self::HOOK);

        $settings = self::settings();

        if ($settings['frequency'] !== 'off') {
            wp_schedule_single_event(self::nextRunAfter($settings, time()), self::HOOK);
        }
    }

    /** Puts back a lost event, e.g. one another plugin cleared. */
    public static function ensureScheduled()
    {
        if (self::settings()['frequency'] !== 'off' && !wp_next_scheduled(self::HOOK)) {
            self::reschedule();
        }
    }

    public static function nextRun()
    {
        $next = wp_next_scheduled(self::HOOK);

        return $next ? (int) $next : 0;
    }

    /** Clears everything a schedule leaves in WP-Cron - for deactivation and uninstall. */
    public static function unscheduleAll()
    {
        wp_unschedule_hook(self::HOOK);
        wp_unschedule_hook(self::RESUME_HOOK);
    }

    /* Starting a backup */

    /** WP-Cron callback for the scheduled time. */
    public function run()
    {
        $settings = self::settings();

        if ($settings['frequency'] === 'off') {
            return;
        }

        // Next run first, so nothing below can stop the schedule.
        wp_schedule_single_event(self::nextRunAfter($settings, time()), self::HOOK);

        self::start((int) $settings['user']);
    }

    /**
     * Starts a background backup as $userId, with the checks a manual
     * backup makes, plus one: never while a restore is running.
     *
     * @return true|string True once started, otherwise why it didn't.
     */
    public static function start($userId)
    {
        $user = get_userdata($userId);

        if (!$user || !user_can($user, 'manage_options')) {
            return self::skip(__('The administrator who saved the schedule no longer exists or can no longer manage this site. Open the Schedule tab and save it again.', 'rebuzz-backup-and-restore'));
        }

        // Restored before returning, so other cron callbacks in this request don't run as an administrator.
        $previousUser = get_current_user_id();
        wp_set_current_user($user->ID);

        try {

            if (wpcb_restore_lock_check() !== null) {
                return self::skip(__('A restore was running, so no backup was made.', 'rebuzz-backup-and-restore'));
            }

            if (wpcb_backup_lock_check() !== null) {
                return self::skip(__('Another backup was already running.', 'rebuzz-backup-and-restore'));
            }

            if (!wpcb_storage_is_private()) {
                return self::skip(wpcb_storage_insecure_reason());
            }

            // The previous run's finish() claim has done its job.
            $previousClaim = (string) (self::state()['claim'] ?? '');

            if (strpos($previousClaim, 'wpcb_schedule_done_') === 0) {
                delete_option($previousClaim);
            }

            $job = new WPCB_Job();

            if (!wpcb_backup_lock_acquire($job->id())) {
                return self::skip(__('Could not take the backup lock. Check that the plugin\'s storage folder is writable and the disk is not full.', 'rebuzz-backup-and-restore'));
            }

            $job->update([
                'status'    => 'running',
                'step'      => 0,
                'progress'  => 0,
                'message'   => __('Starting scheduled backup...', 'rebuzz-backup-and-restore'),
                'scheduled' => true,
                'token'     => wp_generate_password(40, false, false),
            ]);

            self::saveState(['job' => $job->id()]);

            (new WPCB_Logger('backup'))->log('Scheduled backup started (job ' . $job->id() . ').');

            wp_schedule_single_event(time() + self::RESUME_DELAY, self::RESUME_HOOK, [$job->id()]);

            self::kick($job);

            return true;

        } finally {
            wp_set_current_user($previousUser);
        }
    }

    /** Records and reports a run that couldn't start. */
    private static function skip($reason)
    {
        $last = [
            'status'  => 'skipped',
            'time'    => time(),
            'message' => $reason,
        ];

        self::saveState(['last' => $last]);

        (new WPCB_Logger('backup'))->log('Scheduled backup did not run: ' . $reason);

        self::notify($last);

        return $reason;
    }

    /* Driving it */

    /** Asks the site, without waiting, to run the next step in a request of its own. */
    private static function kick(WPCB_Job $job)
    {
        $state = $job->get();

        wp_remote_post(admin_url('admin-ajax.php'), [
            'timeout'   => 0.01,
            'blocking'  => false,
            // Same default core uses for its own cron loopback.
            'sslverify' => apply_filters('https_local_ssl_verify', false),
            'body'      => [
                'action' => self::STEP_ACTION,
                'job_id' => $job->id(),
                'token'  => (string) ($state['token'] ?? ''),
            ],
        ]);
    }

    /** The loopback request: one step, then the next request. */
    public function backgroundStep()
    {
        $job = self::jobFromRequest();

        if ($job === null) {
            wp_send_json_error(null, 403);
        }

        // The sender stopped listening as soon as the request left.
        ignore_user_abort(true);

        self::runSteps($job, 0);

        wp_send_json_success();
    }

    /**
     * The scheduled job a background request names, if its token
     * matches. Looked up with exists() first: constructing a WPCB_Job
     * for an unknown ID would create one, from a logged-out request.
     */
    public static function jobFromRequest()
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- the job's own random token is the check; these requests come from the site itself, without cookies.
        $jobId = isset($_POST['job_id']) && is_scalar($_POST['job_id']) ? sanitize_text_field(wp_unslash($_POST['job_id'])) : '';
        $token = isset($_POST['token']) && is_scalar($_POST['token']) ? sanitize_text_field(wp_unslash($_POST['token'])) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        if ($token === '' || !WPCB_Job::exists($jobId)) {
            return null;
        }

        $job = new WPCB_Job($jobId);
        $state = $job->get();

        if (empty($state['scheduled']) || !hash_equals((string) ($state['token'] ?? ''), $token)) {
            return null;
        }

        return $job;
    }

    /**
     * Runs steps for up to $budget seconds (at least one), as the
     * backup's owner, under the same crash guard and output buffer as a
     * manual step.
     */
    private static function runSteps(WPCB_Job $job, $budget)
    {
        if (($job->get()['status'] ?? '') !== 'running' || $job->isProcessing()) {
            return;
        }

        wp_set_current_user((int) ($job->get()['owner'] ?? 0));

        // Guard first: shutdown functions run in order, so afterRequest() sees the crash it records.
        WPCB_Admin::guardAgainstFatalError($job, 'backup');

        if (!self::$afterRequestRegistered) {
            self::$afterRequestRegistered = true;
            register_shutdown_function([__CLASS__, 'afterRequest'], $job->id());
        }

        $started = microtime(true);
        $backup = new WPCB_Backup_Job($job);

        do {

            $job->markProcessing();

            WPCB_Admin::runStepBuffered(function () use ($backup) {
                return $backup->processNextStep();
            }, 'backup');

            $job->clearProcessing();

        } while (
            ($job->get()['status'] ?? '') === 'running' &&
            microtime(true) - $started < $budget
        );
    }

    /** Shutdown: ask for the next step, or wrap up a backup that ended here. */
    public static function afterRequest($jobId)
    {
        if (!WPCB_Job::exists($jobId)) {
            return;
        }

        $job = new WPCB_Job($jobId);

        if (($job->get()['status'] ?? '') === 'running') {
            self::kick($job);
            return;
        }

        self::finish($job);
    }

    /**
     * WP-Cron safety net, every RESUME_DELAY seconds while a scheduled
     * backup runs: wraps up one that ended unnoticed, fails one that has
     * stalled for good, and carries on one whose self-requests stopped.
     */
    public function resume($jobId)
    {
        if (!WPCB_Job::exists($jobId)) {

            if ((self::state()['job'] ?? '') === $jobId) {
                self::saveState(['job' => '']);
            }

            return;
        }

        $job = new WPCB_Job($jobId);
        $state = $job->get();

        if (($state['status'] ?? '') !== 'running') {
            self::finish($job);
            return;
        }

        wp_schedule_single_event(time() + self::RESUME_DELAY, self::RESUME_HOOK, [$jobId]);

        if (!wpcb_job_is_alive($job)) {

            WPCB_Admin::recordCrash($job, 'backup', __('The scheduled backup stopped making progress and was abandoned - the server may have ended it. Check backup.log for the last step it reached.', 'rebuzz-backup-and-restore'));

            self::finish($job);
            return;
        }

        // Recent progress: the self-requests are carrying it.
        if (time() - (int) ($state['heartbeat'] ?? 0) < self::RESUME_DELAY - 30) {
            return;
        }

        (new WPCB_Logger('backup'))->log('Background requests stopped arriving; continuing the scheduled backup from WP-Cron.');

        $previousUser = get_current_user_id();

        self::runSteps($job, self::INLINE_BUDGET);

        wp_set_current_user($previousUser);
    }

    /* Wrapping up */

    /** Keep-the-last-N, the state shown on the Schedule tab, and the email. Runs once per backup. */
    private static function finish(WPCB_Job $job)
    {
        $jobState = $job->get();
        $status = $jobState['status'] ?? '';

        if (!in_array($status, ['completed', 'failed'], true) || (self::state()['job'] ?? '') !== $job->id()) {
            return;
        }

        // Atomic claim: the after-request handler and the safety net can both get here.
        // Kept until the next run starts, so a late second caller can't claim it again.
        $claim = 'wpcb_schedule_done_' . md5($job->id());

        if (!add_option($claim, time(), '', false)) {
            return;
        }

        self::saveState(['job' => '', 'claim' => $claim]);

        wp_unschedule_hook(self::RESUME_HOOK);

        $last = [
            'status'       => $status,
            'time'         => time(),
            'message'      => (string) ($jobState['message'] ?? ''),
            'elapsed'      => (int) ($jobState['elapsed'] ?? 0),
            'files_failed' => (int) ($jobState['files_failed'] ?? 0),
        ];

        if ($status === 'completed' && !empty($jobState['zip']) && is_file($jobState['zip'])) {

            $last['file'] = basename($jobState['zip']);
            $last['size'] = (int) filesize($jobState['zip']);

            self::remember($last['file']);

            $last['deleted'] = self::applyRetention();
        }

        self::saveState(['last' => $last]);

        (new WPCB_Logger('backup'))->log('Scheduled backup ' . $status . ($status === 'completed' && !empty($last['deleted']) ? '; deleted ' . $last['deleted'] . ' older scheduled backup(s)' : '') . '.');

        self::notify($last);
    }

    /* Keep the last N */

    /** Names of the backups this site's schedule made, in the order they were made. */
    public static function scheduledBackups()
    {
        $list = get_option(self::LIST_OPTION, []);

        return is_array($list) ? array_values(array_map('basename', array_filter($list, 'is_string'))) : [];
    }

    public static function isScheduled($name)
    {
        return in_array($name, self::scheduledBackups(), true);
    }

    private static function remember($name)
    {
        $list = self::scheduledBackups();
        $list[] = $name;

        update_option(self::LIST_OPTION, array_values(array_unique($list)), false);
    }

    /**
     * Deletes the oldest scheduled backups beyond the number to keep.
     * Only names on this site's own list are candidates, so a manual
     * backup, or an upload that happens to share a name pattern, is
     * never removed.
     *
     * @return int How many were deleted.
     */
    private static function applyRetention()
    {
        $keep = (int) self::settings()['keep'];
        $dir = wpcb_backups_dir();

        $existing = [];

        foreach (self::scheduledBackups() as $name) {
            if (is_file($dir . '/' . $name)) {
                $existing[$name] = (int) filemtime($dir . '/' . $name);
            }
        }

        arsort($existing);

        $deleted = 0;

        foreach (array_slice(array_keys($existing), $keep) as $name) {

            wp_delete_file($dir . '/' . $name);

            if (!file_exists($dir . '/' . $name)) {
                unset($existing[$name]);
                $deleted++;
            }
        }

        // Names of files that are gone drop off the list here too.
        update_option(self::LIST_OPTION, array_keys(array_reverse($existing, true)), false);

        return $deleted;
    }

    /* State shown on the Schedule tab */

    public static function state()
    {
        $state = get_option(self::STATE_OPTION, []);

        return is_array($state) ? $state : [];
    }

    private static function saveState(array $changes)
    {
        update_option(self::STATE_OPTION, array_merge(self::state(), $changes), false);
    }

    /** The scheduled backup running now, if any. */
    public static function runningJob()
    {
        $jobId = (string) (self::state()['job'] ?? '');

        if (!WPCB_Job::exists($jobId)) {
            return null;
        }

        $job = new WPCB_Job($jobId);

        return wpcb_job_is_alive($job) ? $job : null;
    }

    /**
     * A restore replaced the database, bringing back the schedule's
     * state and WP-Cron events as they were when that backup was made -
     * possibly a backup still running, or a run already overdue. Drop
     * the former and schedule the next run from now.
     */
    public static function afterRestore()
    {
        wp_unschedule_hook(self::RESUME_HOOK);

        self::saveState(['job' => '']);

        self::reschedule();
    }

    /* Email */

    /** Emails the result when the settings ask for it. */
    private static function notify(array $last)
    {
        $settings = self::settings();

        if ($settings['notify'] === 'off' || $settings['email'] === '') {
            return;
        }

        $clean = $last['status'] === 'completed' && empty($last['files_failed']);

        if ($settings['notify'] === 'failure' && $clean) {
            return;
        }

        [$subject, $body] = self::message($last);

        wp_mail($settings['email'], $subject, $body);
    }

    /** @return array{0: string, 1: string} Subject and plain-text body. */
    public static function message(array $last)
    {
        $site = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        $when = wp_date(get_option('date_format') . ' ' . get_option('time_format'), (int) $last['time']);

        $lines = [];

        if ($last['status'] === 'completed') {

            $subject = empty($last['files_failed'])
                /* translators: %s: site name */
                ? sprintf(__('[%s] Backup completed', 'rebuzz-backup-and-restore'), $site)
                /* translators: %s: site name */
                : sprintf(__('[%s] Backup completed with warnings', 'rebuzz-backup-and-restore'), $site);

            /* translators: 1: site address, 2: date and time */
            $lines[] = sprintf(__('The scheduled backup of %1$s finished on %2$s.', 'rebuzz-backup-and-restore'), home_url(), $when);
            $lines[] = '';

            if (!empty($last['file'])) {
                /* translators: %s: backup file name */
                $lines[] = sprintf(__('Backup: %s', 'rebuzz-backup-and-restore'), $last['file']);
                /* translators: %s: file size, e.g. "34 MB" */
                $lines[] = sprintf(__('Size: %s', 'rebuzz-backup-and-restore'), size_format((int) ($last['size'] ?? 0)));
            }

            /* translators: %s: duration, e.g. "1m 12s" */
            $lines[] = sprintf(__('Took: %s', 'rebuzz-backup-and-restore'), self::duration((int) ($last['elapsed'] ?? 0)));

            if (!empty($last['deleted'])) {
                /* translators: %d: number of backups deleted */
                $lines[] = sprintf(_n('Older scheduled backups deleted: %d', 'Older scheduled backups deleted: %d', (int) $last['deleted'], 'rebuzz-backup-and-restore'), (int) $last['deleted']);
            }

            if (!empty($last['files_failed'])) {
                $lines[] = '';
                $lines[] = $last['message'];
            }

        } else {

            $subject = $last['status'] === 'skipped'
                /* translators: %s: site name */
                ? sprintf(__('[%s] Scheduled backup did not run', 'rebuzz-backup-and-restore'), $site)
                /* translators: %s: site name */
                : sprintf(__('[%s] Scheduled backup failed', 'rebuzz-backup-and-restore'), $site);

            $lines[] = $last['status'] === 'skipped'
                /* translators: 1: site address, 2: date and time */
                ? sprintf(__('The scheduled backup of %1$s did not run on %2$s.', 'rebuzz-backup-and-restore'), home_url(), $when)
                /* translators: 1: site address, 2: date and time */
                : sprintf(__('The scheduled backup of %1$s failed on %2$s.', 'rebuzz-backup-and-restore'), home_url(), $when);
            $lines[] = '';
            /* translators: %s: the reason the backup failed or did not run */
            $lines[] = sprintf(__('Reason: %s', 'rebuzz-backup-and-restore'), $last['message']);
            $lines[] = '';
            /* translators: %s: path to the log file, relative to the WordPress root */
            $lines[] = sprintf(__('Details are in %s.', 'rebuzz-backup-and-restore'), wpcb_display_path(wpcb_logs_dir() . '/backup.log'));
        }

        $lines[] = '';
        /* translators: %s: link to the plugin's screen in wp-admin */
        $lines[] = sprintf(__('Manage backups: %s', 'rebuzz-backup-and-restore'), admin_url('admin.php?page=wpcb-dashboard'));

        return [$subject, implode("\n", $lines)];
    }

    /** "Xm Ys", or "Xs" under a minute. */
    private static function duration($seconds)
    {
        $minutes = intdiv(max(0, $seconds), 60);

        return $minutes > 0 ? $minutes . 'm ' . ($seconds % 60) . 's' : max(0, $seconds) . 's';
    }
}
