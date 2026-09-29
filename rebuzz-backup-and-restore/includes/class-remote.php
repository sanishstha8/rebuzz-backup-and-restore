<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * A place backups can be sent to: S3-compatible storage or Dropbox.
 *
 * Every transfer moves one piece of at most WPCB_Storage::CHUNK bytes
 * per call, so a multi-gigabyte backup crosses many short requests the
 * same way the backup itself is built. Implementations keep what they
 * need between calls (an upload ID, a session) in the $session array
 * the caller stores with the job.
 *
 * Errors come back as WP_Error. Error data 'retry' => true marks a
 * failure worth repeating (rate limits, server errors, timeouts), with
 * an optional 'wait' in seconds from Retry-After.
 */
abstract class WPCB_Remote
{
    /** 's3' or 'dropbox'. */
    abstract public function id();

    /** Service name for messages, e.g. "Backblaze B2". */
    abstract public function label();

    /** Where files go, for messages: a bucket and folder, or a Dropbox folder. */
    abstract public function location();

    /** Checks the credentials can list, write and delete. @return string|WP_Error What was checked. */
    abstract public function test();

    /**
     * Sends $data, the bytes of backup $name that start at $offset of
     * $total. The call that sends the last byte also completes the
     * upload. A repeated call for the same offset - a retry - must not
     * duplicate data.
     *
     * @return true|WP_Error
     */
    abstract public function uploadChunk(array &$session, $name, $data, $offset, $total);

    /** Frees what an unfinished upload holds on the service, best effort. */
    abstract public function abortUpload(array $session, $name);

    /** @return array<int, array{name: string, size: int, time: int}>|WP_Error Backup ZIPs in the folder. */
    abstract public function listBackups();

    /** @return true|WP_Error Deleting a file that's already gone counts as success. */
    abstract public function delete($name);

    /** @return string|WP_Error Up to $length bytes of $name starting at $offset. */
    abstract public function download($name, $offset, $length);

    /**
     * One HTTP request through WordPress's HTTP API.
     *
     * @return array{code: int, body: string, headers: mixed}|WP_Error
     */
    protected function http($method, $url, array $headers = [], $body = null, $timeout = 60)
    {
        $response = wp_remote_request($url, [
            'method'      => $method,
            'headers'     => $headers,
            'body'        => $body,
            'timeout'     => $timeout,
            'redirection' => 0,
            // No site address: the services don't need to know which site is calling.
            'user-agent'  => 'ReBuzz-Backup/' . WPCB_VERSION,
        ]);

        if (is_wp_error($response)) {
            return new WP_Error(
                'wpcb_remote_network',
                sprintf(
                    /* translators: 1: service name, e.g. "Dropbox", 2: the network error */
                    __('Could not reach %1$s: %2$s', 'rebuzz-backup-and-restore'),
                    $this->label(),
                    $response->get_error_message()
                ),
                ['retry' => true]
            );
        }

        return [
            'code'    => (int) wp_remote_retrieve_response_code($response),
            'body'    => (string) wp_remote_retrieve_body($response),
            'headers' => wp_remote_retrieve_headers($response),
        ];
    }

    /** Whether an HTTP status is worth retrying. */
    protected static function transient($code)
    {
        return in_array((int) $code, [408, 429, 500, 502, 503, 504], true);
    }

    /** Seconds from a Retry-After header, if any. */
    protected static function retryAfter(array $response)
    {
        $value = $response['headers']['retry-after'] ?? '';

        return is_numeric($value) ? (int) $value : 0;
    }
}
