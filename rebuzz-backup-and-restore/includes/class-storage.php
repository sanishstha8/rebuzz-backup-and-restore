<?php

if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.WP.AlternativeFunctions -- chunks are read from and appended to the archive with fopen/fread/fwrite; WP_Filesystem has no streaming API.

/**
 * Cloud storage: the settings on the Storage tab, the encrypted
 * credentials, sending finished backups to S3-compatible storage and
 * Dropbox, keeping the last N there, and bringing a backup back.
 *
 * Credentials are encrypted with a key derived from the site's secret
 * keys in wp-config.php. wp-config.php is never part of a backup, but
 * the database is - so every backup holds only the encrypted form, and
 * a backup that leaks doesn't hand over the storage account with it.
 */
class WPCB_Storage
{
    const OPTION = 'wpcb_storage';
    const SECRETS = 'wpcb_remote_secrets';
    const DROPBOX = 'wpcb_dropbox';
    const UPLOADED = 'wpcb_remote_uploaded';
    const SENT = 'wpcb_remote_sent';

    /** Bytes per transfer request. S3 needs at least 5 MB for every part but the last. */
    const CHUNK = 8388608;

    /** Retries of one chunk before a transfer gives up. */
    const MAX_RETRIES = 5;

    /** Seconds untouched after which a half-downloaded .part file is removed (2 hours; its job expires after 1). */
    const PART_MAX_AGE = 7200;

    public function __construct()
    {
        add_action('admin_init', [__CLASS__, 'registerSetting']);

        $actions = [
            'wpcb_s3_test'            => 's3Test',
            'wpcb_dropbox_start'      => 'dropboxStart',
            'wpcb_dropbox_finish'     => 'dropboxFinish',
            'wpcb_dropbox_disconnect' => 'dropboxDisconnect',
            'wpcb_dropbox_test'       => 'dropboxTest',
            'wpcb_remote_list'        => 'remoteList',
            'wpcb_remote_delete'      => 'remoteDelete',
            'wpcb_transfer_start'     => 'transferStart',
            'wpcb_transfer_step'      => 'transferStep',
            'wpcb_transfer_cancel'    => 'transferCancel',
        ];

        foreach ($actions as $action => $method) {
            add_action('wp_ajax_' . $action, [$this, $method]);
        }
    }

    public static function registerSetting()
    {
        register_setting('wpcb_storage_group', self::OPTION, [
            'type'              => 'array',
            'sanitize_callback' => [__CLASS__, 'sanitize'],
        ]);
    }

    /* Settings */

    public static function defaults()
    {
        // One folder per site, so sites sharing a bucket never see each other's backups.
        $site = trim(preg_replace('/[^a-z0-9.]+/', '-', strtolower(preg_replace('#^https?://#', '', home_url()))), '-');

        return [
            'send'       => ['s3' => false, 'dropbox' => false],
            'which'      => 'scheduled',
            'keep_local' => true,
            's3'         => [
                'provider'   => 'aws',
                'region'     => '',
                'endpoint'   => '',
                'bucket'     => '',
                'folder'     => 'rebuzz-backups/' . $site,
                'access_key' => '',
                'path_style' => true,
                'keep'       => 10,
            ],
            'dropbox'    => [
                'folder' => $site,
                'keep'   => 10,
            ],
        ];
    }

    public static function settings()
    {
        $saved = get_option(self::OPTION, []);
        $saved = is_array($saved) ? $saved : [];
        $defaults = self::defaults();

        foreach (['send', 's3', 'dropbox'] as $group) {
            $saved[$group] = array_merge($defaults[$group], isset($saved[$group]) && is_array($saved[$group]) ? $saved[$group] : []);
        }

        return array_merge($defaults, $saved);
    }

    /**
     * register_setting() sanitize callback. options.php has already
     * unslashed $input. The S3 secret goes to the encrypted store, never
     * into this option.
     */
    public static function sanitize($input)
    {
        $input = is_array($input) ? $input : [];
        $old = self::settings();
        $clean = self::defaults();

        $s3 = isset($input['s3']) && is_array($input['s3']) ? $input['s3'] : [];

        $clean['s3'] = self::cleanS3($s3);

        // Left blank means "keep the saved one"; the form never shows it.
        $secret = trim((string) ($s3['secret'] ?? ''));

        if ($secret !== '') {
            self::setSecret('s3_secret', $secret);
        }

        $dropbox = isset($input['dropbox']) && is_array($input['dropbox']) ? $input['dropbox'] : [];

        // Dropbox's fields are only on the form while it's connected; otherwise keep what was saved.
        $clean['dropbox']['folder'] = self::sanitizeFolder($dropbox['folder'] ?? $old['dropbox']['folder']);
        $clean['dropbox']['keep'] = min(100, max(1, (int) ($dropbox['keep'] ?? $old['dropbox']['keep'])));

        $send = isset($input['send']) && is_array($input['send']) ? $input['send'] : [];

        $clean['send'] = ['s3' => !empty($send['s3']), 'dropbox' => !empty($send['dropbox'])];
        $clean['which'] = ($input['which'] ?? '') === 'all' ? 'all' : 'scheduled';
        $clean['keep_local'] = !empty($input['keep_local']);

        return $clean;
    }

    /** The S3 fields as typed, cleaned - without the secret, and without saving anything. */
    private static function cleanS3(array $s3)
    {
        return [
            'provider'   => array_key_exists($s3['provider'] ?? '', WPCB_Remote_S3::providers()) ? $s3['provider'] : 'aws',
            'region'     => sanitize_text_field($s3['region'] ?? ''),
            'endpoint'   => esc_url_raw(trim((string) ($s3['endpoint'] ?? '')), ['http', 'https']),
            'bucket'     => sanitize_text_field($s3['bucket'] ?? ''),
            'folder'     => self::sanitizeFolder($s3['folder'] ?? ''),
            'access_key' => sanitize_text_field($s3['access_key'] ?? ''),
            'path_style' => !empty($s3['path_style']),
            'keep'       => min(100, max(1, (int) ($s3['keep'] ?? 10))),
        ];
    }

    private static function sanitizeFolder($folder)
    {
        $parts = array_filter(array_map(function ($part) {
            return trim(preg_replace('/[^A-Za-z0-9._ -]+/', '', $part), ' .');
        }, explode('/', str_replace('\\', '/', (string) $folder))), 'strlen');

        return implode('/', $parts);
    }

    /* Encrypted credentials */

    private static function cryptoKey()
    {
        return hash_hmac('sha256', 'rebuzz-backup-remote-credentials', wp_salt('secure_auth'), true);
    }

    public static function encrypt($plain)
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return 'wpcb1:' . base64_encode($nonce . sodium_crypto_secretbox((string) $plain, $nonce, self::cryptoKey()));
    }

    /** '' when the value can't be decrypted - after a move to a server with other secret keys, for instance. */
    public static function decrypt($stored)
    {
        if (!is_string($stored) || strpos($stored, 'wpcb1:') !== 0) {
            return '';
        }

        $raw = base64_decode(substr($stored, 6), true);

        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return '';
        }

        try {
            $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), self::cryptoKey());
        } catch (\Throwable $e) {
            return '';
        }

        return $plain === false ? '' : $plain;
    }

    private static function secrets()
    {
        $secrets = get_option(self::SECRETS, []);

        return is_array($secrets) ? $secrets : [];
    }

    private static function setSecret($name, $plain)
    {
        $secrets = self::secrets();

        if ($plain === '') {
            unset($secrets[$name]);
        } else {
            $secrets[$name] = self::encrypt($plain);
        }

        update_option(self::SECRETS, $secrets, false);
    }

    private static function secret($name)
    {
        return self::decrypt(self::secrets()[$name] ?? '');
    }

    public static function hasSecret($name)
    {
        return self::secret($name) !== '';
    }

    /** Saved but unreadable: the secret keys in wp-config.php changed since it was stored. */
    public static function secretUnreadable($name)
    {
        return !empty(self::secrets()[$name]) && self::secret($name) === '';
    }

    public static function dropboxAuth()
    {
        $meta = get_option(self::DROPBOX, []);

        return [
            'refresh' => self::secret('dropbox_refresh'),
            'access'  => self::secret('dropbox_access'),
            'expires' => (int) (is_array($meta) ? ($meta['expires'] ?? 0) : 0),
        ];
    }

    /** $refresh null keeps the saved refresh token. */
    public static function saveDropboxTokens($refresh, $access, $expires)
    {
        if ($refresh !== null) {
            self::setSecret('dropbox_refresh', $refresh);
        }

        self::setSecret('dropbox_access', $access);

        $meta = get_option(self::DROPBOX, []);
        $meta = is_array($meta) ? $meta : [];
        $meta['expires'] = (int) $expires;

        update_option(self::DROPBOX, $meta, false);
    }

    public static function saveDropboxAccount($account)
    {
        $meta = get_option(self::DROPBOX, []);
        $meta = is_array($meta) ? $meta : [];
        $meta['account'] = (string) $account;

        update_option(self::DROPBOX, $meta, false);
    }

    public static function dropboxAccount()
    {
        $meta = get_option(self::DROPBOX, []);

        return is_array($meta) ? (string) ($meta['account'] ?? '') : '';
    }

    private static function forgetDropbox()
    {
        self::setSecret('dropbox_refresh', '');
        self::setSecret('dropbox_access', '');
        delete_option(self::DROPBOX);
    }

    /* Destinations */

    /** A ready-to-use destination, or null when it isn't set up. */
    public static function remote($id)
    {
        $settings = self::settings();

        if ($id === 's3') {

            $config = $settings['s3'];
            $config['secret'] = self::secret('s3_secret');

            if ($config['bucket'] === '' || $config['access_key'] === '' || $config['secret'] === '') {
                return null;
            }

            if (in_array($config['provider'], ['r2', 'other'], true) && $config['endpoint'] === '') {
                return null;
            }

            return new WPCB_Remote_S3($config);
        }

        if ($id === 'dropbox') {
            return WPCB_Remote_Dropbox::appKey() !== '' && self::secret('dropbox_refresh') !== ''
                ? new WPCB_Remote_Dropbox($settings['dropbox']['folder'])
                : null;
        }

        return null;
    }

    /** @return array<string, WPCB_Remote> Every destination that's set up, by ID. */
    public static function remotes()
    {
        return array_filter(['s3' => self::remote('s3'), 'dropbox' => self::remote('dropbox')]);
    }

    public static function label($id)
    {
        $remote = self::remote($id);

        if ($remote !== null) {
            return $remote->label();
        }

        return $id === 'dropbox' ? 'Dropbox' : __('S3 storage', 'rebuzz-backup-and-restore');
    }

    /** Destinations a finished backup goes to, per the Storage tab. */
    public static function plannedUploads(array $jobState)
    {
        $settings = self::settings();

        if ($settings['which'] !== 'all' && empty($jobState['scheduled'])) {
            return [];
        }

        return array_values(array_filter(array_keys(self::remotes()), function ($id) use ($settings) {
            return !empty($settings['send'][$id]);
        }));
    }

    /*
     * What this site uploaded. Two lists per destination: UPLOADED holds
     * automatic uploads, which keep-the-last-N trims; SENT holds backups
     * an administrator sent by hand, which are never deleted automatically.
     */

    private static function names($option, $id)
    {
        $all = get_option($option, []);
        $list = is_array($all) && isset($all[$id]) && is_array($all[$id]) ? $all[$id] : [];

        return array_values(array_map('basename', array_filter($list, 'is_string')));
    }

    private static function setNames($option, $id, array $names)
    {
        $all = get_option($option, []);
        $all = is_array($all) ? $all : [];
        $all[$id] = array_values(array_unique($names));

        update_option($option, $all, false);
    }

    /** Automatic uploads to $id, oldest first. */
    public static function uploaded($id)
    {
        return self::names(self::UPLOADED, $id);
    }

    private static function setUploaded($id, array $names)
    {
        self::setNames(self::UPLOADED, $id, $names);
    }

    private static function rememberUpload($id, $name)
    {
        self::setUploaded($id, array_merge(self::uploaded($id), [$name]));
    }

    private static function rememberSent($id, $name)
    {
        self::setNames(self::SENT, $id, array_merge(self::names(self::SENT, $id), [$name]));
    }

    private static function forget($id, $name)
    {
        self::setUploaded($id, array_diff(self::uploaded($id), [$name]));
        self::setNames(self::SENT, $id, array_diff(self::names(self::SENT, $id), [$name]));
    }

    /** Whether this site put $name on $id, automatically or by hand. */
    public static function isOurs($id, $name)
    {
        return in_array($name, self::uploaded($id), true) || in_array($name, self::names(self::SENT, $id), true);
    }

    /** Destination IDs a local backup is also in, as far as this site knows. */
    public static function uploadedTo($name)
    {
        return array_values(array_filter(['s3', 'dropbox'], function ($id) use ($name) {
            return self::isOurs($id, $name);
        }));
    }

    /**
     * Deletes this site's oldest automatic uploads on $remote beyond the
     * number to keep. Only names on the UPLOADED list are candidates, so
     * other files in the folder, backups sent by hand and other sites'
     * backups are never touched - nor is $protect, the one just uploaded.
     * Oldest by the time in the name, which is when the backup was made.
     *
     * @return int How many were deleted.
     */
    private static function applyRemoteRetention($id, WPCB_Remote $remote, $protect)
    {
        $listed = $remote->listBackups();

        if (is_wp_error($listed)) {
            (new WPCB_Logger('backup'))->log('Could not list ' . $remote->label() . ' to delete older backups: ' . $listed->get_error_message());
            return 0;
        }

        $present = array_values(array_intersect(self::uploaded($id), array_column($listed, 'name')));

        rsort($present, SORT_STRING);

        $keep = (int) self::settings()[$id]['keep'];
        $deleted = 0;

        foreach (array_slice($present, $keep) as $name) {

            if ($name === $protect) {
                continue;
            }

            $result = $remote->delete($name);

            if (is_wp_error($result)) {
                (new WPCB_Logger('backup'))->log('Could not delete ' . $name . ' from ' . $remote->label() . ': ' . $result->get_error_message());
                continue;
            }

            $present = array_values(array_diff($present, [$name]));
            $deleted++;
        }

        sort($present, SORT_STRING);
        self::setUploaded($id, $present);

        return $deleted;
    }

    /* Moving the bytes */

    /**
     * Sends the next chunk of $path to $remote.
     *
     * @return true|WP_Error
     */
    private static function sendChunk(WPCB_Remote $remote, array &$transfer, $path, $name, $size)
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return new WP_Error('wpcb_transfer', __('The backup file could not be opened for reading.', 'rebuzz-backup-and-restore'));
        }

        fseek($handle, (int) $transfer['offset']);

        $data = '';

        while (strlen($data) < self::CHUNK && !feof($handle)) {
            $piece = fread($handle, self::CHUNK - strlen($data));

            if ($piece === false || $piece === '') {
                break;
            }

            $data .= $piece;
        }

        fclose($handle);

        if ($data === '' && $transfer['offset'] < $size) {
            return new WP_Error('wpcb_transfer', __('The backup file could not be read.', 'rebuzz-backup-and-restore'));
        }

        $sent = $remote->uploadChunk($transfer['session'], $name, $data, (int) $transfer['offset'], (int) $size);

        if (is_wp_error($sent)) {
            return $sent;
        }

        $transfer['offset'] += strlen($data);

        return true;
    }

    /**
     * Fetches the next chunk of $name from $remote onto the end of $partPath.
     *
     * @return true|WP_Error
     */
    private static function fetchChunk(WPCB_Remote $remote, array &$transfer, $partPath, $name, $size)
    {
        $length = (int) min(self::CHUNK, $size - $transfer['offset']);
        $data = $remote->download($name, (int) $transfer['offset'], $length);

        if (is_wp_error($data)) {
            return $data;
        }

        if (strlen($data) !== $length) {
            return new WP_Error('wpcb_transfer', sprintf(
                /* translators: 1: service name, 2: bytes expected, 3: bytes received */
                __('%1$s sent %3$d bytes where %2$d were expected.', 'rebuzz-backup-and-restore'),
                $remote->label(),
                $length,
                strlen($data)
            ), ['retry' => true]);
        }

        // Written at the offset rather than appended, so a retried chunk can't be doubled.
        $handle = @fopen($partPath, $transfer['offset'] === 0 ? 'wb' : 'r+b');

        if ($handle === false || fseek($handle, (int) $transfer['offset']) !== 0 || fwrite($handle, $data) !== $length) {

            if ($handle !== false) {
                fclose($handle);
            }

            return new WP_Error('wpcb_transfer', __('The download could not be written to the backups folder. The disk may be full.', 'rebuzz-backup-and-restore'));
        }

        fclose($handle);

        $transfer['offset'] += $length;

        return true;
    }

    /**
     * A failed chunk: retry a passing error up to MAX_RETRIES times,
     * pausing briefly as the service asked, otherwise give up.
     *
     * @return bool True to try the same chunk again.
     */
    private static function shouldRetry(WP_Error $error, array &$transfer)
    {
        $data = $error->get_error_data();

        if (empty($data['retry']) || $transfer['retries'] >= self::MAX_RETRIES) {
            return false;
        }

        $transfer['retries']++;

        sleep(min(10, max(2, (int) ($data['wait'] ?? 0))));

        return true;
    }

    /* Sending a finished backup (a step of WPCB_Backup_Job) */

    /**
     * One step of sending the job's backup to its destinations: one chunk
     * to one destination per call. A destination that fails is recorded
     * and skipped; the backup itself is never failed by an upload.
     *
     * @return bool True once every destination is done.
     */
    public static function uploadStep(WPCB_Job $job)
    {
        $state = $job->get();
        $up = $state['upload'] ?? null;

        if (!is_array($up)) {

            $zip = (string) ($state['zip'] ?? '');
            $queue = ($zip !== '' && is_file($zip)) ? self::plannedUploads($state) : [];

            $up = [
                'queue'    => $queue,
                'total'    => count($queue),
                'results'  => [],
                'transfer' => null,
                'name'     => basename($zip),
                'size'     => $zip !== '' && is_file($zip) ? (int) filesize($zip) : 0,
            ];
        }

        if (empty($up['queue'])) {
            return self::afterUploads($job, $up);
        }

        wpcb_extend_time_limit(300);

        $id = $up['queue'][0];
        $remote = self::remote($id);
        $logger = new WPCB_Logger('backup');

        if ($remote === null) {
            $up['results'][$id] = ['ok' => false, 'error' => __('it is no longer set up.', 'rebuzz-backup-and-restore')];
            array_shift($up['queue']);
            $job->update(['upload' => $up]);
            return false;
        }

        if (!is_array($up['transfer'])) {
            $up['transfer'] = ['offset' => 0, 'session' => [], 'retries' => 0];
            $logger->log('Uploading ' . $up['name'] . ' to ' . $remote->label() . ' (' . $remote->location() . ').');
        }

        $sent = self::sendChunk($remote, $up['transfer'], $state['zip'], $up['name'], $up['size']);

        if (is_wp_error($sent)) {

            if (self::shouldRetry($sent, $up['transfer'])) {
                $job->update([
                    'upload'  => $up,
                    'message' => sprintf(
                        /* translators: 1: service name, 2: attempt number, 3: maximum attempts */
                        __('%1$s did not respond; trying again (%2$d of %3$d)...', 'rebuzz-backup-and-restore'),
                        $remote->label(),
                        $up['transfer']['retries'],
                        self::MAX_RETRIES
                    ),
                ]);
                return false;
            }

            $remote->abortUpload($up['transfer']['session'], $up['name']);
            $logger->log('Upload to ' . $remote->label() . ' failed: ' . $sent->get_error_message());

            $up['results'][$id] = ['ok' => false, 'error' => $sent->get_error_message()];
            $up['transfer'] = null;
            array_shift($up['queue']);

            $job->update(['upload' => $up]);
            return false;
        }

        $up['transfer']['retries'] = 0;
        $offset = $up['transfer']['offset'];

        if ($offset >= $up['size']) {

            self::rememberUpload($id, $up['name']);

            $up['results'][$id] = ['ok' => true, 'deleted' => self::applyRemoteRetention($id, $remote, $up['name'])];
            $up['transfer'] = null;
            array_shift($up['queue']);

            $logger->log('Uploaded to ' . $remote->label() . '.');
        }

        $done = $up['total'] - count($up['queue']) + ($up['transfer'] ? $offset / max(1, $up['size']) : 0);

        $job->update([
            'upload'   => $up,
            'progress' => min(99, 90 + (int) floor(10 * $done / max(1, $up['total']))),
            'message'  => $up['transfer']
                ? sprintf(
                    /* translators: 1: service name, 2: amount sent, 3: backup size */
                    __('Uploading to %1$s: %2$s of %3$s', 'rebuzz-backup-and-restore'),
                    $remote->label(),
                    size_format($offset, 1),
                    size_format($up['size'], 1)
                )
                : sprintf(
                    /* translators: %s: service name */
                    __('Uploaded to %s.', 'rebuzz-backup-and-restore'),
                    $remote->label()
                ),
        ]);

        return false;
    }

    /** Every destination is done: remove the local copy if asked to and it's safely stored everywhere. */
    private static function afterUploads(WPCB_Job $job, array $up)
    {
        $state = $job->get();
        $ok = array_filter($up['results'], function ($result) {
            return !empty($result['ok']);
        });

        if (!self::settings()['keep_local'] && $up['total'] > 0 && count($ok) === $up['total'] && !empty($state['zip']) && is_file($state['zip'])) {

            wp_delete_file($state['zip']);

            if (!file_exists($state['zip'])) {
                $up['local_removed'] = true;
                (new WPCB_Logger('backup'))->log('Removed the local copy after uploading, as set on the Storage tab.');
            }
        }

        $job->update([
            'upload'        => $up,
            'upload_failed' => count($up['results']) - count($ok),
        ]);

        return true;
    }

    /** Sentences about the uploads, for the finished-backup message and email. */
    public static function summaryLines(array $up)
    {
        $lines = [];

        foreach ($up['results'] ?? [] as $id => $result) {

            $label = self::label($id);

            if (!empty($result['ok'])) {
                $lines[] = !empty($result['deleted'])
                    ? sprintf(
                        /* translators: 1: service name, 2: number of older backups deleted there */
                        _n('Uploaded to %1$s; %2$d older backup deleted there.', 'Uploaded to %1$s; %2$d older backups deleted there.', (int) $result['deleted'], 'rebuzz-backup-and-restore'),
                        $label,
                        (int) $result['deleted']
                    )
                    /* translators: %s: service name */
                    : sprintf(__('Uploaded to %s.', 'rebuzz-backup-and-restore'), $label);
            } else {
                // Network errors arrive without a full stop; the next sentence needs one.
                $error = rtrim((string) $result['error']);
                $error .= preg_match('/[.!?)]$/', $error) ? '' : '.';

                /* translators: 1: service name, 2: what went wrong, ending with a full stop */
                $lines[] = sprintf(__('Upload to %1$s failed: %2$s The backup is still on this server.', 'rebuzz-backup-and-restore'), $label, $error);
            }
        }

        if (!empty($up['local_removed'])) {
            $lines[] = __('Removed from this server after uploading, as set on the Storage tab.', 'rebuzz-backup-and-restore');
        }

        return $lines;
    }

    /* AJAX: Storage tab */

    private static function check()
    {
        check_ajax_referer('wpcb_backup', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied.', 'rebuzz-backup-and-restore'));
        }
    }

    // phpcs:disable WordPress.Security.NonceVerification.Missing -- every handler below starts with self::check(), which verifies the nonce.

    /** Tests the S3 details as typed, before or after saving; a blank secret means the saved one. */
    public function s3Test()
    {
        self::check();

        $posted = isset($_POST['s3']) && is_array($_POST['s3']) ? wp_unslash($_POST['s3']) : [];
        $clean = self::cleanS3($posted);

        $secret = trim((string) ($posted['secret'] ?? ''));
        $clean['secret'] = $secret !== '' ? $secret : self::secret('s3_secret');

        if ($clean['bucket'] === '' || $clean['access_key'] === '' || $clean['secret'] === '') {
            wp_send_json_error(__('Fill in the bucket, access key ID and secret access key first.', 'rebuzz-backup-and-restore'));
        }

        $result = (new WPCB_Remote_S3($clean))->test();

        is_wp_error($result) ? wp_send_json_error($result->get_error_message()) : wp_send_json_success($result);
    }

    /** Starts connecting Dropbox: a PKCE verifier kept for this user, and the address to open. */
    public function dropboxStart()
    {
        self::check();

        if (WPCB_Remote_Dropbox::appKey() === '') {
            wp_send_json_error(__('This copy of the plugin has no Dropbox app key.', 'rebuzz-backup-and-restore'));
        }

        $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');

        set_transient('wpcb_dropbox_verifier_' . get_current_user_id(), $verifier, 15 * MINUTE_IN_SECONDS);

        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        wp_send_json_success(['url' => WPCB_Remote_Dropbox::authorizeUrl($challenge)]);
    }

    public function dropboxFinish()
    {
        self::check();

        $code = sanitize_text_field(wp_unslash($_POST['code'] ?? ''));
        $key = 'wpcb_dropbox_verifier_' . get_current_user_id();
        $verifier = (string) get_transient($key);

        if ($code === '') {
            wp_send_json_error(__('Paste the code Dropbox showed you first.', 'rebuzz-backup-and-restore'));
        }

        if ($verifier === '') {
            wp_send_json_error(__('That took too long. Click Connect Dropbox again.', 'rebuzz-backup-and-restore'));
        }

        $account = WPCB_Remote_Dropbox::connect($code, $verifier);

        if (is_wp_error($account)) {
            wp_send_json_error($account->get_error_message());
        }

        delete_transient($key);

        /* translators: %s: Dropbox account, e.g. "Jane Doe (jane@example.com)" */
        wp_send_json_success(sprintf(__('Connected to the Dropbox account of %s.', 'rebuzz-backup-and-restore'), $account));
    }

    public function dropboxDisconnect()
    {
        self::check();

        $remote = self::remote('dropbox');

        if ($remote instanceof WPCB_Remote_Dropbox) {
            $remote->revoke();
        }

        self::forgetDropbox();

        wp_send_json_success();
    }

    public function dropboxTest()
    {
        self::check();

        $remote = self::remote('dropbox');

        if ($remote === null) {
            wp_send_json_error(__('Dropbox isn\'t connected.', 'rebuzz-backup-and-restore'));
        }

        $result = $remote->test();

        is_wp_error($result) ? wp_send_json_error($result->get_error_message()) : wp_send_json_success($result);
    }

    /** The backups in one destination, newest first. */
    public function remoteList()
    {
        self::check();

        $id = sanitize_key(wp_unslash($_POST['remote'] ?? ''));
        $remote = self::remote($id);

        if ($remote === null) {
            wp_send_json_error(__('That storage isn\'t set up.', 'rebuzz-backup-and-restore'));
        }

        $items = $remote->listBackups();

        if (is_wp_error($items)) {
            wp_send_json_error($items->get_error_message());
        }

        // Newest first by the service's date; names only break ties.
        usort($items, function ($a, $b) {
            return ($b['time'] <=> $a['time']) ?: strcmp($b['name'], $a['name']);
        });

        $dir = wpcb_backups_dir();

        wp_send_json_success(array_map(function ($item) use ($id, $dir) {
            return [
                'name'  => $item['name'],
                'size'  => size_format($item['size'], 2),
                'date'  => $item['time'] ? wp_date(get_option('date_format') . ' ' . get_option('time_format'), $item['time']) : '',
                'local' => is_file($dir . '/' . $item['name']),
                'mine'  => self::isOurs($id, $item['name']),
            ];
        }, $items));
    }

    public function remoteDelete()
    {
        self::check();

        $id = sanitize_key(wp_unslash($_POST['remote'] ?? ''));
        $name = self::backupName(wp_unslash($_POST['name'] ?? ''));
        $remote = self::remote($id);

        if ($remote === null || $name === '') {
            wp_send_json_error(__('That backup or storage isn\'t available.', 'rebuzz-backup-and-restore'));
        }

        $result = $remote->delete($name);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        self::forget($id, $name);

        wp_send_json_success();
    }

    /** A plain backup file name, or '' - never a path. */
    private static function backupName($name)
    {
        $name = basename(str_replace('\\', '/', (string) $name));

        return preg_match('/^[A-Za-z0-9._-]+\.zip$/', $name) ? $name : '';
    }

    /* Transfers started from the Storage tab */

    /**
     * Starts sending a local backup up, or bringing one down, as a job
     * the page then drives one chunk at a time. Holds the backup lock, so
     * it never overlaps a backup, and never starts during a restore.
     */
    public function transferStart()
    {
        self::check();

        $direction = ($_POST['direction'] ?? '') === 'down' ? 'down' : 'up';
        $id = sanitize_key(wp_unslash($_POST['remote'] ?? ''));
        $name = self::backupName(wp_unslash($_POST['name'] ?? ''));
        $remote = self::remote($id);
        $local = wpcb_backups_dir() . '/' . $name;

        if ($remote === null || $name === '') {
            wp_send_json_error(__('That backup or storage isn\'t available.', 'rebuzz-backup-and-restore'));
        }

        if (wpcb_restore_lock_check() !== null) {
            wp_send_json_error(__('A restore is running. Try again when it has finished.', 'rebuzz-backup-and-restore'));
        }

        self::clearStaleParts();

        if ($direction === 'up') {

            if (!is_file($local)) {
                wp_send_json_error(__('That backup is no longer on this server.', 'rebuzz-backup-and-restore'));
            }

            $size = (int) filesize($local);

        } else {

            if (is_file($local)) {
                wp_send_json_error(__('That backup is already on this server - restore it from the Restore tab.', 'rebuzz-backup-and-restore'));
            }

            $listed = $remote->listBackups();

            if (is_wp_error($listed)) {
                wp_send_json_error($listed->get_error_message());
            }

            $found = array_values(array_filter($listed, function ($item) use ($name) {
                return $item['name'] === $name;
            }));

            if (!$found) {
                wp_send_json_error(__('That backup is no longer in the cloud.', 'rebuzz-backup-and-restore'));
            }

            $size = (int) $found[0]['size'];

            $space = wpcb_check_upload_disk_space($size, wpcb_backups_dir());

            if (!$space['ok']) {
                wp_send_json_error(sprintf(
                    /* translators: 1: space needed, 2: space free */
                    __('Not enough disk space for this backup: it needs about %1$s and %2$s is free.', 'rebuzz-backup-and-restore'),
                    size_format($space['required']),
                    size_format($space['free'])
                ));
            }
        }

        $job = new WPCB_Job();

        if (!wpcb_backup_lock_acquire($job->id())) {
            wp_send_json_error(self::runningTransfer()
                ? __('Another transfer is still going. Wait for it to finish, or reload this page to see it at the top, where you can cancel it.', 'rebuzz-backup-and-restore')
                : __('A backup is running. Try again when it has finished.', 'rebuzz-backup-and-restore'));
        }

        $job->update([
            'status'   => 'running',
            'kind'     => 'transfer',
            'progress' => 0,
            'message'  => __('Starting...', 'rebuzz-backup-and-restore'),
            'transfer' => [
                'direction' => $direction,
                'remote'    => $id,
                'name'      => $name,
                'size'      => $size,
                'offset'    => 0,
                'session'   => [],
                'retries'   => 0,
            ],
        ]);

        (new WPCB_Logger('backup'))->log(($direction === 'up' ? 'Sending ' : 'Downloading ') . $name . ($direction === 'up' ? ' to ' : ' from ') . $remote->label() . '.');

        wp_send_json_success(['job_id' => $job->id()]);
    }

    public function transferStep()
    {
        self::check();

        $jobId = sanitize_text_field(wp_unslash($_POST['job_id'] ?? ''));

        if (!WPCB_Job::exists($jobId)) {
            wp_send_json_error(__('That transfer has ended.', 'rebuzz-backup-and-restore'));
        }

        $job = new WPCB_Job($jobId);
        $state = $job->get();

        if (!$job->isOwnedBy(get_current_user_id()) || ($state['kind'] ?? '') !== 'transfer') {
            wp_send_json_error(__('Permission denied.', 'rebuzz-backup-and-restore'));
        }

        // Another step, or a cancel, is at work on it: report where it stands.
        if (($state['status'] ?? '') !== 'running' || !wpcb_lock('transfer_' . $jobId)) {
            wp_send_json_success($job->getPublic());
        }

        // As the request that held the lock last left it.
        $job = new WPCB_Job($jobId);

        self::stepLocked($job);

        wpcb_unlock('transfer_' . $jobId);

        wp_send_json_success($job->getPublic());
    }

    /** One step of a transfer, by the request holding its lock. */
    private static function stepLocked(WPCB_Job $job)
    {
        $jobId = $job->id();

        if (($job->get()['status'] ?? '') !== 'running') {
            return;
        }

        if (self::cancelRequested($jobId)) {
            self::finishCancel($job);
            return;
        }

        // Paused (its page was left) for long enough that a backup has taken the lock since. No lock at all: take it back.
        $owner = wpcb_backup_lock_owner();

        if ($owner !== $jobId && !($owner === '' && wpcb_backup_lock_acquire($jobId))) {
            self::discardTransfer($job);
            self::endTransfer($job, false, __('This transfer stopped because a backup started while it was paused. Start it again from the Storage tab.', 'rebuzz-backup-and-restore'));
            return;
        }

        $job->markProcessing();

        WPCB_Admin::guardAgainstFatalError($job, 'backup');

        WPCB_Admin::runStepBuffered(function () use ($job) {
            return self::transferChunk($job);
        }, 'backup');

        $job->clearProcessing();

        // Cancelled while that chunk was on its way.
        if (self::cancelRequested($jobId) && ($job->get()['status'] ?? '') === 'running') {
            self::finishCancel($job);
        }
    }

    /**
     * Cancels a Storage-tab transfer. Any administrator may, so one left
     * running by someone who has gone away can be stopped.
     */
    public function transferCancel()
    {
        self::check();

        $jobId = sanitize_text_field(wp_unslash($_POST['job_id'] ?? ''));

        if (!WPCB_Job::exists($jobId)) {
            wp_send_json_error(__('That transfer has ended.', 'rebuzz-backup-and-restore'));
        }

        $job = new WPCB_Job($jobId);
        $state = $job->get();

        if (($state['kind'] ?? '') !== 'transfer') {
            wp_send_json_error(__('Permission denied.', 'rebuzz-backup-and-restore'));
        }

        if (($state['status'] ?? '') !== 'running') {
            wp_send_json_success($job->getPublic());
        }

        set_transient(self::cancelKey($jobId), 1, HOUR_IN_SECONDS);

        // A step on its way holds the lock; it stops the transfer itself once its chunk is done.
        if (!wpcb_lock('transfer_' . $jobId)) {
            wp_send_json_success(array_merge($job->getPublic(), ['message' => __('Stopping...', 'rebuzz-backup-and-restore')]));
        }

        $job = new WPCB_Job($jobId);

        if (($job->get()['status'] ?? '') === 'running') {
            self::finishCancel($job);
        }

        wpcb_unlock('transfer_' . $jobId);

        wp_send_json_success($job->getPublic());
    }

    /**
     * The Storage-tab transfer that holds the backup lock and is still
     * marked running: going, or paused because its page was left - it
     * carries on from where it stopped as long as nothing else has taken
     * the lock since. A cancel left pending by a closed page is finished
     * here.
     *
     * @return WPCB_Job|null
     */
    public static function runningTransfer()
    {
        $jobId = wpcb_backup_lock_owner();

        if (!WPCB_Job::exists($jobId)) {
            return null;
        }

        $job = new WPCB_Job($jobId);
        $state = $job->get();

        if (($state['kind'] ?? '') !== 'transfer' || ($state['status'] ?? '') !== 'running') {
            return null;
        }

        // A cancel that no step has finished (its page was closed). A step holding the lock would finish it itself.
        if (self::cancelRequested($jobId) && wpcb_lock('transfer_' . $jobId)) {

            $job = new WPCB_Job($jobId);

            if (($job->get()['status'] ?? '') === 'running') {
                self::finishCancel($job);
            }

            wpcb_unlock('transfer_' . $jobId);

            return null;
        }

        return $job;
    }

    /** Removes half-downloaded files that no transfer has touched for PART_MAX_AGE - left by one that was never resumed. */
    public static function clearStaleParts()
    {
        $running = self::runningTransfer();
        $current = $running ? ($running->get()['transfer']['name'] ?? '') . '.part' : '';

        foreach (glob(wpcb_backups_dir() . '/*.zip.part') ?: [] as $part) {
            if (basename($part) !== $current && time() - (int) @filemtime($part) > self::PART_MAX_AGE) {
                wp_delete_file($part);
            }
        }
    }

    private static function cancelKey($jobId)
    {
        return 'wpcb_transfer_cancel_' . md5($jobId);
    }

    private static function cancelRequested($jobId)
    {
        return (bool) get_transient(self::cancelKey($jobId));
    }

    private static function finishCancel(WPCB_Job $job)
    {
        delete_transient(self::cancelKey($job->id()));
        self::discardTransfer($job);
        self::endTransfer($job, false, __('Cancelled. The part already transferred was removed.', 'rebuzz-backup-and-restore'), 'cancelled');
    }

    /** Removes what an unfinished transfer left: a download's .part file, or a send's unfinished S3 upload (Dropbox drops its own). */
    private static function discardTransfer(WPCB_Job $job)
    {
        $t = $job->get()['transfer'] ?? [];

        if (empty($t['name'])) {
            return;
        }

        if (($t['direction'] ?? '') === 'up') {

            $remote = self::remote($t['remote'] ?? '');

            if ($remote !== null) {
                $remote->abortUpload((array) ($t['session'] ?? []), $t['name']);
            }

            return;
        }

        $part = wpcb_backups_dir() . '/' . $t['name'] . '.part';

        if (file_exists($part)) {
            wp_delete_file($part);
        }
    }

    /** One chunk of a Storage-tab transfer. */
    private static function transferChunk(WPCB_Job $job)
    {
        wpcb_extend_time_limit(300);

        $state = $job->get();
        $t = $state['transfer'];
        $remote = self::remote($t['remote']);
        $local = wpcb_backups_dir() . '/' . $t['name'];
        $part = $local . '.part';

        if ($remote === null) {
            return self::endTransfer($job, false, __('That storage is no longer set up.', 'rebuzz-backup-and-restore'));
        }

        $result = $t['direction'] === 'up'
            ? self::sendChunk($remote, $t, $local, $t['name'], $t['size'])
            : self::fetchChunk($remote, $t, $part, $t['name'], $t['size']);

        if (is_wp_error($result)) {

            if (self::shouldRetry($result, $t)) {
                $job->update(['transfer' => $t]);
                return false;
            }

            if ($t['direction'] === 'up') {
                $remote->abortUpload($t['session'], $t['name']);
            } elseif (file_exists($part)) {
                wp_delete_file($part);
            }

            return self::endTransfer($job, false, $result->get_error_message());
        }

        $t['retries'] = 0;

        if ($t['offset'] < $t['size']) {

            $job->update([
                'transfer' => $t,
                'progress' => (int) floor(100 * $t['offset'] / max(1, $t['size'])),
                'message'  => sprintf(
                    /* translators: 1: amount transferred, 2: total size */
                    __('%1$s of %2$s', 'rebuzz-backup-and-restore'),
                    size_format($t['offset'], 1),
                    size_format($t['size'], 1)
                ),
            ]);

            return false;
        }

        if ($t['direction'] === 'up') {

            // Sent by hand, so it stays until deleted by hand - keep-the-last-N only trims automatic uploads.
            self::rememberSent($t['remote'], $t['name']);

            return self::endTransfer($job, true, sprintf(
                /* translators: %s: service name */
                __('Uploaded to %s. Backups you send yourself are never deleted automatically.', 'rebuzz-backup-and-restore'),
                $remote->label()
            ));
        }

        if (!@rename($part, $local)) {
            wp_delete_file($part);
            return self::endTransfer($job, false, __('The download could not be moved into the backups folder.', 'rebuzz-backup-and-restore'));
        }

        $valid = (new WPCB_Validator())->validate($local);

        if (!$valid['success']) {
            wp_delete_file($local);
            /* translators: %s: why the file isn't a usable backup */
            return self::endTransfer($job, false, sprintf(__('The downloaded file is not a usable backup: %s', 'rebuzz-backup-and-restore'), $valid['message']));
        }

        // Listed by when the backup was made (the UTC time in its name), not when it was downloaded.
        if (preg_match('/^backup-(\d{4})-(\d{2})-(\d{2})-(\d{2})-(\d{2})-(\d{2})-/', $t['name'], $m)) {
            @touch($local, gmmktime((int) $m[4], (int) $m[5], (int) $m[6], (int) $m[2], (int) $m[3], (int) $m[1]));
        }

        return self::endTransfer($job, true, __('Downloaded. It is now in your backups list; restore it from the Restore tab.', 'rebuzz-backup-and-restore'));
    }

    private static function endTransfer(WPCB_Job $job, $ok, $message, $status = null)
    {
        $status = $status ?? ($ok ? 'completed' : 'failed');

        // Only its own lock: a transfer paused long enough may have lost it to a backup.
        if (wpcb_backup_lock_owner() === $job->id()) {
            wpcb_backup_lock_release();
        }

        (new WPCB_Logger('backup'))->log('Transfer ' . $status . ': ' . $message);

        $job->update([
            'status'   => $status,
            'progress' => $ok ? 100 : (int) ($job->get()['progress'] ?? 0),
            'message'  => $message,
        ]);

        return $ok;
    }

    // phpcs:enable WordPress.Security.NonceVerification.Missing
}
