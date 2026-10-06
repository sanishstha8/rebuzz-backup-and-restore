<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Dropbox, through the app registered for this plugin (App folder access,
 * so it only ever sees its own folder under Apps/).
 *
 * Connecting uses OAuth with PKCE and no redirect: Dropbox shows the
 * administrator a code to paste back, so no page on the site has to be
 * reachable from Dropbox and no app secret ships with the plugin. The
 * refresh token it returns is stored encrypted (WPCB_Storage) and swapped
 * for a short-lived access token whenever one is needed.
 */
class WPCB_Remote_Dropbox extends WPCB_Remote
{
    const AUTHORIZE = 'https://www.dropbox.com/oauth2/authorize';
    const TOKEN = 'https://api.dropboxapi.com/oauth2/token';
    const API = 'https://api.dropboxapi.com/2/';
    const CONTENT = 'https://content.dropboxapi.com/2/';

    /** @var string Folder inside the app folder, no slashes at the ends. */
    private $folder;

    public function __construct($folder)
    {
        $this->folder = trim((string) $folder, '/');
    }

    /** The plugin's Dropbox app key; a site can set WPCB_DROPBOX_APP_KEY in wp-config.php. */
    public static function appKey()
    {
        return defined('WPCB_DROPBOX_APP_KEY') ? (string) WPCB_DROPBOX_APP_KEY : '';
    }

    public function id()
    {
        return 'dropbox';
    }

    public function label()
    {
        return 'Dropbox';
    }

    public function location()
    {
        /* translators: %s: folder name inside the plugin's Dropbox app folder */
        return sprintf(__('the app folder in Dropbox, under %s', 'rebuzz-backup-and-restore'), '/' . $this->folder);
    }

    private function path($name)
    {
        return ($this->folder !== '' ? '/' . $this->folder : '') . '/' . $name;
    }

    /* Connecting */

    public static function authorizeUrl($challenge)
    {
        return add_query_arg([
            'client_id'             => self::appKey(),
            'response_type'         => 'code',
            'code_challenge'        => $challenge,
            'code_challenge_method' => 'S256',
            'token_access_type'     => 'offline',
        ], self::AUTHORIZE);
    }

    /**
     * Exchanges the code the administrator pasted for tokens and stores them.
     *
     * @return string|WP_Error The connected account, e.g. "Jane Doe (jane@example.com)".
     */
    public static function connect($code, $verifier)
    {
        $client = new self('');

        $response = $client->http('POST', self::TOKEN, [], [
            'code'          => $code,
            'grant_type'    => 'authorization_code',
            'code_verifier' => $verifier,
            'client_id'     => self::appKey(),
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $token = json_decode($response['body'], true);

        if ($response['code'] !== 200 || empty($token['refresh_token']) || empty($token['access_token'])) {
            return new WP_Error('wpcb_dropbox', __('Dropbox did not accept that code. Codes work once and expire after a few minutes - click Connect Dropbox again for a new one.', 'rebuzz-backup-and-restore'));
        }

        WPCB_Storage::saveDropboxTokens($token['refresh_token'], $token['access_token'], time() + (int) ($token['expires_in'] ?? 14400));

        $account = $client->account();

        if (is_wp_error($account)) {
            return $account;
        }

        WPCB_Storage::saveDropboxAccount($account);

        return $account;
    }

    /** "Name (email)" of the connected account. @return string|WP_Error */
    public function account()
    {
        $response = $this->rpc('users/get_current_account', null);

        if (is_wp_error($response)) {
            return $response;
        }

        $account = json_decode($response['body'], true);
        $name = (string) ($account['name']['display_name'] ?? '');
        $email = (string) ($account['email'] ?? '');

        return trim($name . ($email !== '' ? ' (' . $email . ')' : ''));
    }

    /** Tells Dropbox to forget the token, best effort; for disconnecting. */
    public function revoke()
    {
        $this->rpc('auth/token/revoke', null);
    }

    /** A current access token, refreshed when it's about to expire. @return string|WP_Error */
    private function accessToken($forceRefresh = false)
    {
        $auth = WPCB_Storage::dropboxAuth();

        if ($auth['refresh'] === '') {
            return new WP_Error('wpcb_dropbox', __('Dropbox isn\'t connected, or its saved access can\'t be read on this server. Connect Dropbox again on the Storage tab.', 'rebuzz-backup-and-restore'));
        }

        if (!$forceRefresh && $auth['access'] !== '' && $auth['expires'] > time() + 300) {
            return $auth['access'];
        }

        $response = $this->http('POST', self::TOKEN, [], [
            'grant_type'    => 'refresh_token',
            'refresh_token' => $auth['refresh'],
            'client_id'     => self::appKey(),
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $token = json_decode($response['body'], true);

        if ($response['code'] !== 200 || empty($token['access_token'])) {

            if (self::transient($response['code'])) {
                return new WP_Error('wpcb_dropbox', __('Dropbox: could not renew access just now.', 'rebuzz-backup-and-restore'), ['retry' => true, 'wait' => self::retryAfter($response)]);
            }

            return new WP_Error('wpcb_dropbox', __('Dropbox access was removed or has expired. Connect Dropbox again on the Storage tab.', 'rebuzz-backup-and-restore'));
        }

        WPCB_Storage::saveDropboxTokens(null, $token['access_token'], time() + (int) ($token['expires_in'] ?? 14400));

        return $token['access_token'];
    }

    /**
     * An authorised request; renews the token once if Dropbox rejects it.
     *
     * @return array|WP_Error
     */
    private function call($url, array $headers, $body, $timeout = 60, $retried = false)
    {
        $token = $this->accessToken($retried);

        if (is_wp_error($token)) {
            return $token;
        }

        $headers['Authorization'] = 'Bearer ' . $token;

        $response = $this->http('POST', $url, $headers, $body, $timeout);

        if (is_wp_error($response)) {
            return $response;
        }

        if ($response['code'] === 401 && !$retried) {
            return $this->call($url, $headers, $body, $timeout, true);
        }

        if ($response['code'] < 200 || $response['code'] >= 300) {
            return $this->error($response);
        }

        return $response;
    }

    private function rpc($endpoint, $args)
    {
        return $this->call(self::API . $endpoint, ['Content-Type' => 'application/json'], $args === null ? 'null' : wp_json_encode($args));
    }

    private function content($endpoint, array $apiArg, $data, array $extra = [], $timeout = 300)
    {
        // JSON in a header must be ASCII; json_encode escapes everything else by default.
        $headers = array_merge(['Dropbox-API-Arg' => wp_json_encode($apiArg)], $extra);

        if ($data !== '') {
            $headers['Content-Type'] = 'application/octet-stream';
        }

        return $this->call(self::CONTENT . $endpoint, $headers, $data, $timeout);
    }

    private function error(array $response)
    {
        $json = json_decode($response['body'], true);
        $summary = is_array($json) ? (string) ($json['error_summary'] ?? '') : '';

        $hints = [
            'path/not_found'       => __('the file or folder isn\'t in Dropbox.', 'rebuzz-backup-and-restore'),
            'path/insufficient_space' => __('the Dropbox account is full.', 'rebuzz-backup-and-restore'),
            'insufficient_space'   => __('the Dropbox account is full.', 'rebuzz-backup-and-restore'),
            'too_many_write_operations' => __('Dropbox is busy with other changes to this account.', 'rebuzz-backup-and-restore'),
            'invalid_access_token' => __('access was removed. Connect Dropbox again on the Storage tab.', 'rebuzz-backup-and-restore'),
            'expired_access_token' => __('access has expired. Connect Dropbox again on the Storage tab.', 'rebuzz-backup-and-restore'),
        ];

        $text = '';

        foreach ($hints as $prefix => $hint) {
            if (strpos($summary, $prefix) !== false) {
                $text = $hint;
                break;
            }
        }

        // A permission not ticked in the Dropbox app's settings; tokens from before it was ticked lack it too.
        if ($text === '' && strpos($summary, 'missing_scope') !== false) {
            $scope = is_array($json) ? (string) ($json['error']['required_scope'] ?? '') : '';
            $text = $scope !== ''
                /* translators: %s: Dropbox permission name, e.g. "files.content.write" */
                ? sprintf(__('the plugin\'s Dropbox app is missing the %s permission. Once it is ticked on the app\'s Permissions tab, click Disconnect and connect Dropbox again.', 'rebuzz-backup-and-restore'), $scope)
                : __('the plugin\'s Dropbox app is missing a permission. Once it is ticked on the app\'s Permissions tab, click Disconnect and connect Dropbox again.', 'rebuzz-backup-and-restore');
        }

        if ($text === '') {
            $text = $summary !== ''
                ? $summary
                /* translators: %d: HTTP status code */
                : sprintf(__('Dropbox answered with HTTP %d.', 'rebuzz-backup-and-restore'), $response['code']);
        }

        return new WP_Error('wpcb_dropbox', 'Dropbox: ' . $text, [
            'retry'   => self::transient($response['code']) || strpos($summary, 'too_many_write_operations') !== false,
            'wait'    => self::retryAfter($response),
            'summary' => $summary,
            'error'   => is_array($json) ? ($json['error'] ?? null) : null,
        ]);
    }

    /* Operations */

    public function test()
    {
        $account = $this->account();

        if (is_wp_error($account)) {
            return $account;
        }

        $probe = '.rebuzz-connection-test.txt';

        $written = $this->content('files/upload', ['path' => $this->path($probe), 'mode' => 'overwrite', 'mute' => true], 'ReBuzz Backup and Restore connection test. Safe to delete.');

        if (is_wp_error($written)) {
            return $written;
        }

        $deleted = $this->delete($probe);

        if (is_wp_error($deleted)) {
            return $deleted;
        }

        /* translators: %s: Dropbox account, e.g. "Jane Doe (jane@example.com)" */
        return sprintf(__('Connected to the Dropbox account of %s. A test file was written and deleted again.', 'rebuzz-backup-and-restore'), $account);
    }

    public function uploadChunk(array &$session, $name, $data, $offset, $total)
    {
        $path = $this->path($name);
        $length = strlen($data);
        $last = ($offset + $length >= $total);
        $commit = ['path' => $path, 'mode' => 'add', 'autorename' => false, 'mute' => true];

        // Small enough for one request.
        if ($offset === 0 && $last) {
            $sent = $this->content('files/upload', $commit, $data);

            return is_wp_error($sent) ? $this->alreadyThere($sent, $path, $total) : true;
        }

        if (empty($session['session_id'])) {

            $started = $this->content('files/upload_session/start', ['close' => false], $data);

            if (is_wp_error($started)) {
                return $started;
            }

            $json = json_decode($started['body'], true);

            if (empty($json['session_id'])) {
                return new WP_Error('wpcb_dropbox', __('Dropbox did not start the upload.', 'rebuzz-backup-and-restore'), ['retry' => true]);
            }

            $session['session_id'] = $json['session_id'];

            return true;
        }

        $cursor = ['session_id' => $session['session_id'], 'offset' => (int) $offset];

        $sent = $last
            ? $this->content('files/upload_session/finish', ['cursor' => $cursor, 'commit' => $commit], $data)
            : $this->content('files/upload_session/append_v2', ['cursor' => $cursor, 'close' => false], $data);

        if (!is_wp_error($sent)) {
            return true;
        }

        // A retry of a chunk Dropbox already has: it says where it's up to, and that's past this chunk.
        $error = $sent->get_error_data()['error'] ?? null;
        $correct = is_array($error) ? ($error['correct_offset'] ?? $error['lookup_failed']['correct_offset'] ?? null) : null;

        if (!$last && $correct !== null && (int) $correct === $offset + $length) {
            return true;
        }

        return $last ? $this->alreadyThere($sent, $path, $total) : $sent;
    }

    /** After a failed final request: if the complete file is there anyway, the upload succeeded. */
    private function alreadyThere(WP_Error $error, $path, $total)
    {
        $meta = $this->rpc('files/get_metadata', ['path' => $path]);

        if (!is_wp_error($meta)) {
            $json = json_decode($meta['body'], true);

            if ((int) ($json['size'] ?? -1) === (int) $total) {
                return true;
            }
        }

        return $error;
    }

    public function abortUpload(array $session, $name)
    {
        // Dropbox discards an unfinished upload session by itself after 48 hours.
    }

    public function listBackups()
    {
        $response = $this->rpc('files/list_folder', ['path' => $this->folder !== '' ? '/' . $this->folder : '', 'limit' => 2000]);

        if (is_wp_error($response)) {
            $summary = (string) ($response->get_error_data()['summary'] ?? '');

            // No folder yet: nothing uploaded.
            return strpos($summary, 'not_found') !== false ? [] : $response;
        }

        $items = [];

        while (true) {

            $json = json_decode($response['body'], true);

            foreach ((array) ($json['entries'] ?? []) as $entry) {

                if (($entry['.tag'] ?? '') !== 'file' || substr((string) $entry['name'], -4) !== '.zip') {
                    continue;
                }

                $items[] = [
                    'name' => (string) $entry['name'],
                    'size' => (int) ($entry['size'] ?? 0),
                    'time' => (int) strtotime((string) ($entry['server_modified'] ?? '')),
                ];
            }

            if (empty($json['has_more']) || empty($json['cursor'])) {
                break;
            }

            $response = $this->rpc('files/list_folder/continue', ['cursor' => $json['cursor']]);

            if (is_wp_error($response)) {
                return $response;
            }
        }

        return $items;
    }

    public function delete($name)
    {
        $deleted = $this->rpc('files/delete_v2', ['path' => $this->path($name)]);

        if (is_wp_error($deleted) && strpos((string) ($deleted->get_error_data()['summary'] ?? ''), 'not_found') === false) {
            return $deleted;
        }

        return true;
    }

    public function download($name, $offset, $length)
    {
        $got = $this->content('files/download', ['path' => $this->path($name)], '', [
            'Range' => 'bytes=' . (int) $offset . '-' . ((int) $offset + (int) $length - 1),
        ]);

        return is_wp_error($got) ? $got : $got['body'];
    }
}
