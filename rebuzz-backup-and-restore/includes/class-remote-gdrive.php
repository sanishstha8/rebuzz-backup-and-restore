<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Google Drive, with the drive.file permission: the plugin only ever sees
 * the folders and files it created itself.
 *
 * Google needs a client secret to sign in, which can't ship in a plugin,
 * so signing in and renewing access go through the plugin's relay
 * (WPCB_GDRIVE_RELAY). The relay keeps nothing: the site holds the
 * refresh token, stored encrypted (WPCB_Storage), and swaps the code
 * with a PKCE verifier that never leaves the site. Files go straight
 * between the site and Google, never through the relay.
 */
class WPCB_Remote_GDrive extends WPCB_Remote
{
    const API = 'https://www.googleapis.com/drive/v3/';
    const UPLOAD = 'https://www.googleapis.com/upload/drive/v3/files';
    const REVOKE = 'https://oauth2.googleapis.com/revoke';
    const SCOPE = 'https://www.googleapis.com/auth/drive.file';
    const FOLDER_TYPE = 'application/vnd.google-apps.folder';

    /** @var string Folder path from the top of My Drive, no slashes at the ends. */
    private $folder;

    /** @var string|null ID of $folder, once looked up in this request. */
    private $folderId = null;

    public function __construct($folder)
    {
        $this->folder = trim((string) $folder, '/');
    }

    /** The plugin's sign-in relay; a site can set WPCB_GDRIVE_RELAY in wp-config.php. */
    public static function relay()
    {
        return defined('WPCB_GDRIVE_RELAY') ? untrailingslashit((string) WPCB_GDRIVE_RELAY) : '';
    }

    public function id()
    {
        return 'gdrive';
    }

    public function label()
    {
        return 'Google Drive';
    }

    public function location()
    {
        /* translators: %s: folder path in Google Drive, e.g. "ReBuzz Backups/example.com" */
        return sprintf(__('the folder %s in Google Drive', 'rebuzz-backup-and-restore'), '"' . $this->folder . '"');
    }

    /* Connecting */

    /** The relay's page that confirms the site and goes on to Google. */
    public static function startUrl($returnUrl, $state, $challenge)
    {
        return self::relay() . '/gdrive/start?' . http_build_query([
            'return'    => $returnUrl,
            'state'     => $state,
            'challenge' => $challenge,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Exchanges the code Google sent back for tokens and stores them.
     *
     * @return string|WP_Error The connected account, e.g. "Jane Doe (jane@example.com)".
     */
    public static function connect($code, $verifier)
    {
        $client = new self('');

        $response = $client->relayPost('/gdrive/token', ['code' => $code, 'code_verifier' => $verifier]);

        if (is_wp_error($response)) {
            return $response;
        }

        $token = json_decode($response['body'], true);

        if ($response['code'] !== 200 || empty($token['refresh_token']) || empty($token['access_token'])) {
            return new WP_Error('wpcb_gdrive', __('Google did not accept the sign-in. It may have taken too long - click Connect Google Drive again.', 'rebuzz-backup-and-restore'));
        }

        // Google's consent screen lets the permission be unticked.
        if (!in_array(self::SCOPE, explode(' ', (string) ($token['scope'] ?? '')), true)) {
            return new WP_Error('wpcb_gdrive', __('Google Drive was not connected: the permission to store files was not given. Click Connect Google Drive again and leave that box ticked.', 'rebuzz-backup-and-restore'));
        }

        WPCB_Storage::saveTokens('gdrive', $token['refresh_token'], $token['access_token'], time() + (int) ($token['expires_in'] ?? 3600));

        $account = $client->account();

        if (is_wp_error($account)) {
            return $account;
        }

        WPCB_Storage::saveAccount('gdrive', $account);

        return $account;
    }

    /** "Name (email)" of the connected account. @return string|WP_Error */
    public function account()
    {
        $response = $this->call('GET', self::API . 'about?fields=' . rawurlencode('user(displayName,emailAddress)'));

        if (is_wp_error($response)) {
            return $response;
        }

        $user = (array) (json_decode($response['body'], true)['user'] ?? []);
        $name = (string) ($user['displayName'] ?? '');
        $email = (string) ($user['emailAddress'] ?? '');

        return trim($name . ($email !== '' ? ' (' . $email . ')' : ''));
    }

    /** Tells Google to forget the access, best effort; for disconnecting. */
    public function revoke()
    {
        $refresh = WPCB_Storage::oauthAuth('gdrive')['refresh'];

        if ($refresh !== '') {
            $this->http('POST', self::REVOKE, [], ['token' => $refresh], 15);
        }
    }

    /** A request to the relay, which adds the client secret before passing it to Google. */
    private function relayPost($path, array $body)
    {
        $response = wp_remote_post(self::relay() . $path, [
            'body'        => $body,
            'timeout'     => 30,
            'redirection' => 0,
            'user-agent'  => 'ReBuzz-Backup/' . WPCB_VERSION,
        ]);

        if (is_wp_error($response)) {
            return new WP_Error('wpcb_gdrive', sprintf(
                /* translators: 1: web address of the sign-in service, 2: the network error */
                __('Could not reach the Google Drive sign-in service at %1$s: %2$s', 'rebuzz-backup-and-restore'),
                wp_parse_url(self::relay(), PHP_URL_HOST),
                $response->get_error_message()
            ), ['retry' => true]);
        }

        return [
            'code'    => (int) wp_remote_retrieve_response_code($response),
            'body'    => (string) wp_remote_retrieve_body($response),
            'headers' => wp_remote_retrieve_headers($response),
        ];
    }

    /** A current access token, renewed through the relay when it's about to expire. @return string|WP_Error */
    private function accessToken($forceRefresh = false)
    {
        $auth = WPCB_Storage::oauthAuth('gdrive');

        if ($auth['refresh'] === '') {
            return new WP_Error('wpcb_gdrive', __('Google Drive isn\'t connected, or its saved access can\'t be read on this server. Connect Google Drive again on the Storage tab.', 'rebuzz-backup-and-restore'));
        }

        if (!$forceRefresh && $auth['access'] !== '' && $auth['expires'] > time() + 300) {
            return $auth['access'];
        }

        $response = $this->relayPost('/gdrive/refresh', ['refresh_token' => $auth['refresh']]);

        if (is_wp_error($response)) {
            return $response;
        }

        $token = json_decode($response['body'], true);

        if ($response['code'] !== 200 || empty($token['access_token'])) {

            if (self::transient($response['code'])) {
                return new WP_Error('wpcb_gdrive', __('Google Drive: could not renew access just now.', 'rebuzz-backup-and-restore'), ['retry' => true, 'wait' => self::retryAfter($response)]);
            }

            if (($token['error'] ?? '') === 'invalid_grant') {
                return new WP_Error('wpcb_gdrive', __('Google Drive access was removed or has expired. Connect Google Drive again on the Storage tab.', 'rebuzz-backup-and-restore'));
            }

            return new WP_Error('wpcb_gdrive', sprintf(
                /* translators: %s: error code, e.g. "invalid_client" or "HTTP 404" */
                __('Google Drive: could not renew access (%s).', 'rebuzz-backup-and-restore'),
                is_array($token) && !empty($token['error']) ? (string) $token['error'] : 'HTTP ' . $response['code']
            ));
        }

        WPCB_Storage::saveTokens('gdrive', null, $token['access_token'], time() + (int) ($token['expires_in'] ?? 3600));

        return $token['access_token'];
    }

    /**
     * An authorised request; renews the token once if Google rejects it.
     * Answers below 400 come back as they are (an upload in progress is 308).
     *
     * @return array|WP_Error
     */
    private function call($method, $url, array $headers = [], $body = null, $timeout = 60, $retried = false)
    {
        $token = $this->accessToken($retried);

        if (is_wp_error($token)) {
            return $token;
        }

        $headers['Authorization'] = 'Bearer ' . $token;

        $response = $this->http($method, $url, $headers, $body, $timeout);

        if (is_wp_error($response)) {
            return $response;
        }

        if ($response['code'] === 401 && !$retried) {
            return $this->call($method, $url, $headers, $body, $timeout, true);
        }

        return $response['code'] >= 400 ? $this->error($response) : $response;
    }

    private function json($method, $endpoint, array $args)
    {
        return $this->call($method, self::API . $endpoint, ['Content-Type' => 'application/json; charset=UTF-8'], wp_json_encode($args));
    }

    private function error(array $response)
    {
        $json = json_decode($response['body'], true);
        $error = is_array($json) && is_array($json['error'] ?? null) ? $json['error'] : [];
        $reason = (string) ($error['errors'][0]['reason'] ?? $error['status'] ?? '');
        $message = (string) ($error['message'] ?? '');

        $hints = [
            'storageQuotaExceeded'               => __('your Drive storage is full.', 'rebuzz-backup-and-restore'),
            'notFound'                           => __('the file or folder isn\'t there any more.', 'rebuzz-backup-and-restore'),
            'insufficientPermissions'            => __('the connection doesn\'t allow storing files. Click Disconnect, then connect Google Drive again and leave the permission box ticked.', 'rebuzz-backup-and-restore'),
            'ACCESS_TOKEN_SCOPE_INSUFFICIENT'    => __('the connection doesn\'t allow storing files. Click Disconnect, then connect Google Drive again and leave the permission box ticked.', 'rebuzz-backup-and-restore'),
            'authError'                          => __('access was removed. Connect Google Drive again on the Storage tab.', 'rebuzz-backup-and-restore'),
        ];

        $busy = ['rateLimitExceeded', 'userRateLimitExceeded', 'backendError', 'internalError', 'RESOURCE_EXHAUSTED'];

        if (isset($hints[$reason])) {
            $text = $hints[$reason];
        } elseif ($response['code'] === 401) {
            $text = $hints['authError'];
        } elseif ($message !== '') {
            $text = $message;
        } else {
            /* translators: %d: HTTP status code */
            $text = sprintf(__('Google Drive answered with HTTP %d.', 'rebuzz-backup-and-restore'), $response['code']);
        }

        return new WP_Error('wpcb_gdrive', 'Google Drive: ' . $text, [
            'retry'  => self::transient($response['code']) || in_array($reason, $busy, true),
            'wait'   => self::retryAfter($response),
            'status' => $response['code'],
            'reason' => $reason,
        ]);
    }

    /* Folders and files */

    /** A value inside a Drive search query: quoted, with \ and ' escaped. */
    private static function quote($value)
    {
        return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], (string) $value) . "'";
    }

    /** @return array<int, array>|WP_Error Every match of a Drive search, following the pages. */
    private function search($query, $fields)
    {
        $found = [];
        $page = '';

        do {
            $args = [
                'q'        => $query,
                'fields'   => 'nextPageToken,files(' . $fields . ')',
                'pageSize' => 1000,
                'spaces'   => 'drive',
                // Oldest first, so two folders made at once by a race always resolve to the same one.
                'orderBy'  => 'createdTime',
            ];

            if ($page !== '') {
                $args['pageToken'] = $page;
            }

            $response = $this->call('GET', self::API . 'files?' . http_build_query($args, '', '&', PHP_QUERY_RFC3986));

            if (is_wp_error($response)) {
                return $response;
            }

            $json = json_decode($response['body'], true);
            $found = array_merge($found, (array) ($json['files'] ?? []));
            $page = (string) ($json['nextPageToken'] ?? '');

        } while ($page !== '');

        return $found;
    }

    /**
     * The ID of the backups folder, looked up one level at a time from
     * the top of My Drive. Only folders this plugin made are visible.
     *
     * @return string|null|WP_Error Null when it doesn't exist and $create is false.
     */
    private function folderId($create)
    {
        if ($this->folderId !== null) {
            return $this->folderId;
        }

        $parent = 'root';

        foreach (array_filter(explode('/', $this->folder), 'strlen') as $name) {

            $found = $this->search('name = ' . self::quote($name) . ' and mimeType = ' . self::quote(self::FOLDER_TYPE) . ' and ' . self::quote($parent) . ' in parents and trashed = false', 'id');

            if (is_wp_error($found)) {
                return $found;
            }

            if ($found) {
                $parent = (string) $found[0]['id'];
                continue;
            }

            if (!$create) {
                return null;
            }

            $made = $this->json('POST', 'files?fields=id', ['name' => $name, 'mimeType' => self::FOLDER_TYPE, 'parents' => [$parent]]);

            if (is_wp_error($made)) {
                return $made;
            }

            $parent = (string) (json_decode($made['body'], true)['id'] ?? '');

            if ($parent === '') {
                return new WP_Error('wpcb_gdrive', __('Google Drive did not create the backups folder.', 'rebuzz-backup-and-restore'), ['retry' => true]);
            }
        }

        return $this->folderId = $parent;
    }

    /** @return array<int, array{id: string, size: int, md5: string}>|WP_Error Files called $name in the backups folder. */
    private function files($name)
    {
        $folder = $this->folderId(false);

        if ($folder === null || is_wp_error($folder)) {
            return $folder === null ? [] : $folder;
        }

        $found = $this->search('name = ' . self::quote($name) . ' and ' . self::quote($folder) . ' in parents and trashed = false and mimeType != ' . self::quote(self::FOLDER_TYPE), 'id,size,md5Checksum');

        if (is_wp_error($found)) {
            return $found;
        }

        return array_map(function ($file) {
            return ['id' => (string) $file['id'], 'size' => (int) ($file['size'] ?? 0), 'md5' => strtolower((string) ($file['md5Checksum'] ?? ''))];
        }, $found);
    }

    /* Operations */

    public function test()
    {
        $account = $this->account();

        if (is_wp_error($account)) {
            return $account;
        }

        $probe = '.rebuzz-connection-test.txt';
        $text = 'ReBuzz Backup and Restore connection test. Safe to delete.';
        $session = [];

        // One left behind by an earlier test would make this one look done without writing.
        $cleared = $this->delete($probe);

        if (is_wp_error($cleared)) {
            return $cleared;
        }

        $written = $this->uploadChunk($session, $probe, $text, 0, strlen($text));

        if (is_wp_error($written)) {
            return $written;
        }

        $deleted = $this->delete($probe);

        if (is_wp_error($deleted)) {
            return $deleted;
        }

        /* translators: %s: Google account, e.g. "Jane Doe (jane@example.com)" */
        return sprintf(__('Connected to the Google Drive of %s. A test file was written and deleted again.', 'rebuzz-backup-and-restore'), $account);
    }

    /**
     * A resumable upload: the first call opens a session, each call sends
     * one chunk. Before a chunk that follows a failed call, Google is
     * asked how much it already has, so a retry never sends a byte twice
     * and an answer lost on the way back isn't mistaken for a failure.
     */
    public function uploadChunk(array &$session, $name, $data, $offset, $total)
    {
        $offset = (int) $offset;
        $total = (int) $total;
        $length = strlen($data);

        if (!empty($session['done'])) {
            return true;
        }

        if (empty($session['uri'])) {

            $opened = $this->openUpload($session, $name, $total);

            if ($opened !== true) {
                return $opened;
            }

            if (!empty($session['done'])) {
                return true;
            }
        }

        if ((int) ($session['next'] ?? -1) !== $offset) {

            $received = $this->uploadStatus($session, $total);

            if (is_wp_error($received)) {
                return $received;
            }

            if (!empty($session['done']) || $received >= $offset + $length) {
                $session['next'] = $received;
                return true;
            }

            if ($received < $offset) {
                return new WP_Error('wpcb_gdrive', __('Google Drive lost part of the upload. Start it again.', 'rebuzz-backup-and-restore'));
            }

            // Google keeps whole 256 KB blocks, so it may have part of this chunk.
            $data = (string) substr($data, $received - $offset);
            $offset = $received;
            $length = strlen($data);
        }

        // Unknown until Google answers: if the answer is lost, the retry asks first.
        $session['next'] = -1;

        $response = $this->http('PUT', $session['uri'], [
            'Content-Range' => 'bytes ' . $offset . '-' . ($offset + $length - 1) . '/' . $total,
        ], $data, 300);

        if (is_wp_error($response)) {
            return $response;
        }

        if ($response['code'] === 200 || $response['code'] === 201) {
            $session['done'] = true;
            $session['next'] = $total;
            return true;
        }

        if ($response['code'] === 308) {

            $session['next'] = self::rangeEnd($response);

            return $session['next'] >= $offset + $length
                ? true
                : new WP_Error('wpcb_gdrive', __('Google Drive took only part of a chunk; sending the rest.', 'rebuzz-backup-and-restore'), ['retry' => true]);
        }

        if ($response['code'] === 404 || $response['code'] === 410) {
            return new WP_Error('wpcb_gdrive', __('The upload to Google Drive expired. Start it again.', 'rebuzz-backup-and-restore'));
        }

        return $this->error($response);
    }

    /** Opens a resumable upload into the backups folder, or finds the complete file already there. @return true|WP_Error */
    private function openUpload(array &$session, $name, $total)
    {
        $folder = $this->folderId(true);

        if (is_wp_error($folder)) {
            return $folder;
        }

        $existing = $this->files($name);

        if (is_wp_error($existing)) {
            return $existing;
        }

        foreach ($existing as $file) {

            // The same size isn't enough: only the same content means the local copy may go.
            $local = ($file['size'] === $total && $file['md5'] !== '' && $this->source !== '') ? (string) @md5_file($this->source) : '';

            if ($local === '' || !hash_equals($file['md5'], $local)) {
                /* translators: %s: backup file name */
                return new WP_Error('wpcb_gdrive', sprintf(__('Google Drive: a different file called %s is already in the backups folder.', 'rebuzz-backup-and-restore'), $name));
            }

            // Sent before, completely: an upload whose last answer was lost, for instance.
            $session['done'] = true;
            return true;
        }

        $response = $this->call('POST', self::UPLOAD . '?uploadType=resumable&fields=id', [
            'Content-Type'            => 'application/json; charset=UTF-8',
            'X-Upload-Content-Type'   => substr($name, -4) === '.zip' ? 'application/zip' : 'text/plain',
            'X-Upload-Content-Length' => (string) $total,
        ], wp_json_encode(['name' => $name, 'parents' => [$folder]]));

        if (is_wp_error($response)) {
            return $response;
        }

        $uri = (string) ($response['headers']['location'] ?? '');

        if (strpos($uri, 'https://') !== 0) {
            return new WP_Error('wpcb_gdrive', __('Google Drive did not start the upload.', 'rebuzz-backup-and-restore'), ['retry' => true]);
        }

        $session['uri'] = $uri;
        $session['next'] = 0;

        return true;
    }

    /** How many bytes Google has of the upload; marks the session done if it's complete. @return int|WP_Error */
    private function uploadStatus(array &$session, $total)
    {
        $response = $this->http('PUT', $session['uri'], ['Content-Range' => 'bytes */' . $total], '', 60);

        if (is_wp_error($response)) {
            return $response;
        }

        if ($response['code'] === 200 || $response['code'] === 201) {
            $session['done'] = true;
            return $total;
        }

        if ($response['code'] === 308) {
            return self::rangeEnd($response);
        }

        if ($response['code'] === 404 || $response['code'] === 410) {
            return new WP_Error('wpcb_gdrive', __('The upload to Google Drive expired. Start it again.', 'rebuzz-backup-and-restore'));
        }

        return $this->error($response);
    }

    /** Bytes received, from a 308's "Range: bytes=0-N" header; none at all when it's missing. */
    private static function rangeEnd(array $response)
    {
        $range = (string) ($response['headers']['range'] ?? '');

        return preg_match('/bytes=0-(\d+)/', $range, $m) ? (int) $m[1] + 1 : 0;
    }

    public function abortUpload(array $session, $name)
    {
        if (!empty($session['uri']) && empty($session['done'])) {
            $this->http('DELETE', $session['uri'], [], null, 15);
        }
    }

    public function listBackups()
    {
        $folder = $this->folderId(false);

        // No folder yet: nothing uploaded.
        if ($folder === null || is_wp_error($folder)) {
            return $folder === null ? [] : $folder;
        }

        $files = $this->search(self::quote($folder) . ' in parents and trashed = false and mimeType != ' . self::quote(self::FOLDER_TYPE), 'id,name,size,modifiedTime');

        if (is_wp_error($files)) {
            return $files;
        }

        $items = [];

        foreach ($files as $file) {

            $name = (string) ($file['name'] ?? '');

            // Drive allows two files of the same name; the list is by name, so the first one stands for both.
            if (substr($name, -4) !== '.zip' || isset($items[$name])) {
                continue;
            }

            $items[$name] = [
                'name' => $name,
                'size' => (int) ($file['size'] ?? 0),
                'time' => (int) strtotime((string) ($file['modifiedTime'] ?? '')),
            ];
        }

        return array_values($items);
    }

    public function delete($name)
    {
        $files = $this->files($name);

        if (is_wp_error($files)) {
            return $files;
        }

        foreach ($files as $file) {

            $deleted = $this->call('DELETE', self::API . 'files/' . rawurlencode($file['id']));

            if (is_wp_error($deleted) && (int) ($deleted->get_error_data()['status'] ?? 0) !== 404) {
                return $deleted;
            }
        }

        return true;
    }

    public function download($name, $offset, $length)
    {
        $files = $this->files($name);

        if (is_wp_error($files)) {
            return $files;
        }

        if (!$files) {
            return new WP_Error('wpcb_gdrive', __('Google Drive: that backup isn\'t there any more.', 'rebuzz-backup-and-restore'));
        }

        $got = $this->call('GET', self::API . 'files/' . rawurlencode($files[0]['id']) . '?alt=media', [
            'Range' => 'bytes=' . (int) $offset . '-' . ((int) $offset + (int) $length - 1),
        ], null, 300);

        return is_wp_error($got) ? $got : $got['body'];
    }
}
