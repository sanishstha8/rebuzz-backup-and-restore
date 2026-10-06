<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Amazon S3 and services with the same API (Backblaze B2, Cloudflare R2,
 * DigitalOcean Spaces, Wasabi and others), signed with Signature V4 -
 * no SDK needed.
 *
 * Uploads are always multipart: one part per chunk, numbered from the
 * chunk's offset so a retried chunk overwrites its own part instead of
 * adding a duplicate.
 */
class WPCB_Remote_S3 extends WPCB_Remote
{
    /** @var array provider, region, endpoint, bucket, folder, access_key, secret, path_style */
    private $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Endpoint templates and addressing per service. Spaces is signed as
     * us-east-1 whatever its datacenter; R2 always uses the region "auto".
     */
    public static function providers()
    {
        return [
            'aws'    => ['label' => 'Amazon S3', 'endpoint' => 's3.{region}.amazonaws.com', 'region' => 'us-east-1', 'path_style' => false],
            'b2'     => ['label' => 'Backblaze B2', 'endpoint' => 's3.{region}.backblazeb2.com', 'region' => 'us-west-004', 'path_style' => true],
            'r2'     => ['label' => 'Cloudflare R2', 'endpoint' => '', 'region' => 'auto', 'path_style' => true],
            'spaces' => ['label' => 'DigitalOcean Spaces', 'endpoint' => '{region}.digitaloceanspaces.com', 'region' => 'nyc3', 'path_style' => false, 'sign_region' => 'us-east-1'],
            'wasabi' => ['label' => 'Wasabi', 'endpoint' => 's3.{region}.wasabisys.com', 'region' => 'us-east-1', 'path_style' => false],
            'other'  => ['label' => __('S3-compatible storage', 'rebuzz-backup-and-restore'), 'endpoint' => '', 'region' => 'us-east-1', 'path_style' => true],
        ];
    }

    private function preset()
    {
        $providers = self::providers();

        return $providers[$this->config['provider'] ?? 'aws'] ?? $providers['other'];
    }

    public function id()
    {
        return 's3';
    }

    public function label()
    {
        return $this->preset()['label'];
    }

    public function location()
    {
        return trim($this->config['bucket'] . '/' . $this->folder(), '/');
    }

    private function folder()
    {
        return trim((string) ($this->config['folder'] ?? ''), '/');
    }

    private function key($name)
    {
        return $this->folder() !== '' ? $this->folder() . '/' . $name : $name;
    }

    /* Signing */

    /** @return array{0: string, 1: string} Scheme and host (with any port), no bucket. */
    private function endpoint()
    {
        $preset = $this->preset();
        $endpoint = $preset['endpoint'] !== ''
            ? str_replace('{region}', (string) $this->config['region'], $preset['endpoint'])
            : (string) $this->config['endpoint'];

        $scheme = 'https';

        if (preg_match('#^(https?)://#i', $endpoint, $match)) {
            $scheme = strtolower($match[1]);
            $endpoint = substr($endpoint, strlen($match[0]));
        }

        // Dashboards often show the endpoint with the bucket on the end; only the host counts.
        $host = strtolower(trim(explode('/', $endpoint)[0]));

        return [$scheme, $host];
    }

    private function signRegion()
    {
        $preset = $this->preset();

        if (!empty($preset['sign_region'])) {
            return $preset['sign_region'];
        }

        if (($this->config['provider'] ?? '') === 'r2') {
            return 'auto';
        }

        return (string) $this->config['region'] !== '' ? (string) $this->config['region'] : 'us-east-1';
    }

    private function pathStyle()
    {
        if (($this->config['provider'] ?? '') === 'other') {
            return !empty($this->config['path_style']);
        }

        // A dotted bucket name breaks the TLS certificate match of bucket.host.
        return $this->preset()['path_style'] || strpos((string) $this->config['bucket'], '.') !== false;
    }

    private static function encodeKey($key)
    {
        return implode('/', array_map('rawurlencode', explode('/', $key)));
    }

    /**
     * Signs and sends one request. Only host and the x-amz-* headers are
     * signed; anything in $extraHeaders (Range) travels unsigned.
     *
     * @return array|WP_Error Response array for a 2xx, WP_Error otherwise.
     */
    private function send($method, $key, array $query = [], $body = '', array $extraHeaders = [], $timeout = 60)
    {
        [$scheme, $host] = $this->endpoint();

        if ($host === '' || (string) $this->config['bucket'] === '') {
            return new WP_Error('wpcb_s3_config', __('S3 storage: the endpoint and bucket are needed.', 'rebuzz-backup-and-restore'));
        }

        $bucket = (string) $this->config['bucket'];

        if ($this->pathStyle()) {
            $uri = '/' . rawurlencode($bucket) . ($key !== '' ? '/' . self::encodeKey($key) : '');
        } else {
            $host = $bucket . '.' . $host;
            $uri = '/' . self::encodeKey($key);
        }

        $pairs = [];

        foreach ($query as $name => $value) {
            $pairs[rawurlencode($name)] = rawurlencode((string) $value);
        }

        ksort($pairs, SORT_STRING);

        $canonicalQuery = implode('&', array_map(function ($name, $value) {
            return $name . '=' . $value;
        }, array_keys($pairs), $pairs));

        $payloadHash = hash('sha256', (string) $body);
        $amzDate = gmdate('Ymd\THis\Z');
        $date = substr($amzDate, 0, 8);
        $region = $this->signRegion();

        $signed = [
            'host'                 => $host,
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date'           => $amzDate,
        ];

        $canonicalHeaders = '';

        foreach ($signed as $name => $value) {
            $canonicalHeaders .= $name . ':' . trim($value) . "\n";
        }

        $signedHeaders = implode(';', array_keys($signed));

        $canonicalRequest = implode("\n", [$method, $uri, $canonicalQuery, $canonicalHeaders, $signedHeaders, $payloadHash]);

        $scope = $date . '/' . $region . '/s3/aws4_request';
        $stringToSign = "AWS4-HMAC-SHA256\n" . $amzDate . "\n" . $scope . "\n" . hash('sha256', $canonicalRequest);

        $signingKey = hash_hmac('sha256', 'aws4_request',
            hash_hmac('sha256', 's3',
                hash_hmac('sha256', $region,
                    hash_hmac('sha256', $date, 'AWS4' . $this->config['secret'], true),
                true),
            true),
        true);

        $headers = array_merge($extraHeaders, [
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date'           => $amzDate,
            'Authorization'        => 'AWS4-HMAC-SHA256 Credential=' . $this->config['access_key'] . '/' . $scope .
                ', SignedHeaders=' . $signedHeaders .
                ', Signature=' . hash_hmac('sha256', $stringToSign, $signingKey),
        ]);

        // The HTTP API sets Host from the URL, which is exactly the signed value.
        $url = $scheme . '://' . $host . $uri . ($canonicalQuery !== '' ? '?' . $canonicalQuery : '');

        $response = $this->http($method, $url, $headers, $body === '' ? null : $body, $timeout);

        if (is_wp_error($response)) {
            return $response;
        }

        if ($response['code'] < 200 || $response['code'] >= 300) {
            return $this->error($response);
        }

        return $response;
    }

    private static function xml($body, $tag)
    {
        return preg_match('#<' . $tag . '>(.*?)</' . $tag . '>#s', $body, $match)
            ? html_entity_decode($match[1], ENT_QUOTES | ENT_XML1, 'UTF-8')
            : '';
    }

    /** A service error as a sentence an administrator can act on. */
    private function error(array $response)
    {
        $code = self::xml($response['body'], 'Code');
        $message = self::xml($response['body'], 'Message');

        $hints = [
            'InvalidAccessKeyId'           => __('the access key ID is not recognised.', 'rebuzz-backup-and-restore'),
            'SignatureDoesNotMatch'        => __('the secret access key is wrong, or the region or endpoint doesn\'t match the bucket.', 'rebuzz-backup-and-restore'),
            'NoSuchBucket'                 => __('that bucket doesn\'t exist at this endpoint.', 'rebuzz-backup-and-restore'),
            'AccessDenied'                 => __('the key works but isn\'t allowed to do this. It needs permission to list, read, write and delete files in the bucket.', 'rebuzz-backup-and-restore'),
            'AuthorizationHeaderMalformed' => __('the region is wrong for this bucket.', 'rebuzz-backup-and-restore'),
            'PermanentRedirect'            => __('the bucket is in a different region, or at a different endpoint.', 'rebuzz-backup-and-restore'),
            'RequestTimeTooSkewed'         => __('this server\'s clock is wrong, so the service refused the request. Ask your host to correct the server time.', 'rebuzz-backup-and-restore'),
        ];

        if (isset($hints[$code])) {
            $text = $hints[$code];
        } elseif ($message !== '') {
            $text = $message;
        } else {
            /* translators: %d: HTTP status code */
            $text = sprintf(__('the service answered with HTTP %d.', 'rebuzz-backup-and-restore'), $response['code']);
        }

        return new WP_Error(
            'wpcb_s3',
            $this->label() . ': ' . $text . ($code !== '' ? ' (' . $code . ')' : ''),
            [
                'retry' => self::transient($response['code']) || in_array($code, ['SlowDown', 'InternalError', 'ServiceUnavailable'], true),
                'wait'  => self::retryAfter($response),
                'code'  => $code,
            ]
        );
    }

    /* Operations */

    public function test()
    {
        $listed = $this->send('GET', '', ['list-type' => '2', 'max-keys' => '1', 'prefix' => $this->key('')]);

        if (is_wp_error($listed)) {
            return $listed;
        }

        $probe = $this->key('.rebuzz-connection-test.txt');

        $written = $this->send('PUT', $probe, [], 'ReBuzz Backup and Restore connection test. Safe to delete.');

        if (is_wp_error($written)) {
            return $written;
        }

        $deleted = $this->send('DELETE', $probe);

        if (is_wp_error($deleted)) {
            return new WP_Error('wpcb_s3', $deleted->get_error_message() . ' ' . __('Files could be written but not deleted; deleting is needed to keep only the newest backups.', 'rebuzz-backup-and-restore'));
        }

        return sprintf(
            /* translators: %s: bucket and folder, e.g. "my-bucket/backups" */
            __('Connected. A test file was written to %s and deleted again.', 'rebuzz-backup-and-restore'),
            $this->location()
        );
    }

    public function uploadChunk(array &$session, $name, $data, $offset, $total)
    {
        $key = $this->key($name);

        if (empty($session['upload_id'])) {

            $started = $this->send('POST', $key, ['uploads' => '']);

            if (is_wp_error($started)) {
                return $started;
            }

            $uploadId = self::xml($started['body'], 'UploadId');

            if ($uploadId === '') {
                return new WP_Error('wpcb_s3', $this->label() . ': ' . __('the service did not start the upload.', 'rebuzz-backup-and-restore'), ['retry' => true]);
            }

            $session = ['upload_id' => $uploadId, 'etags' => []];
        }

        // Numbered from the offset, so a retry of this chunk replaces its own part.
        $part = intdiv((int) $offset, WPCB_Storage::CHUNK) + 1;

        $sent = $this->send('PUT', $key, ['partNumber' => (string) $part, 'uploadId' => $session['upload_id']], $data, [], 300);

        if (is_wp_error($sent)) {
            return $sent;
        }

        $etag = trim((string) ($sent['headers']['etag'] ?? ''));

        if ($etag === '') {
            return new WP_Error('wpcb_s3', $this->label() . ': ' . __('the service did not confirm a part of the upload.', 'rebuzz-backup-and-restore'), ['retry' => true]);
        }

        $session['etags'][$part] = $etag;

        if ($offset + strlen($data) < $total) {
            return true;
        }

        ksort($session['etags']);

        $xml = '<CompleteMultipartUpload>';

        foreach ($session['etags'] as $number => $tag) {
            $xml .= '<Part><PartNumber>' . (int) $number . '</PartNumber><ETag>' . htmlspecialchars($tag, ENT_XML1) . '</ETag></Part>';
        }

        $xml .= '</CompleteMultipartUpload>';

        $completed = $this->send('POST', $key, ['uploadId' => $session['upload_id']], $xml, [], 300);

        if (is_wp_error($completed)) {
            return $completed;
        }

        // S3 can answer 200 and still report a failure in the body.
        if (strpos($completed['body'], '<Error>') !== false) {
            return $this->error($completed);
        }

        return true;
    }

    public function abortUpload(array $session, $name)
    {
        if (!empty($session['upload_id'])) {
            $this->send('DELETE', $this->key($name), ['uploadId' => $session['upload_id']]);
        }
    }

    public function listBackups()
    {
        $prefix = $this->key('');
        $items = [];
        $token = '';

        do {

            $query = ['list-type' => '2', 'prefix' => $prefix];

            if ($token !== '') {
                $query['continuation-token'] = $token;
            }

            $listed = $this->send('GET', '', $query);

            if (is_wp_error($listed)) {
                return $listed;
            }

            preg_match_all('#<Contents>(.*?)</Contents>#s', $listed['body'], $matches);

            foreach ($matches[1] as $entry) {

                $name = substr(self::xml($entry, 'Key'), strlen($prefix));

                // Only ZIPs directly in the folder, not in folders below it.
                if ($name === '' || strpos($name, '/') !== false || substr($name, -4) !== '.zip') {
                    continue;
                }

                $items[] = [
                    'name' => $name,
                    'size' => (int) self::xml($entry, 'Size'),
                    'time' => (int) strtotime(self::xml($entry, 'LastModified')),
                ];
            }

            $token = self::xml($listed['body'], 'IsTruncated') === 'true' ? self::xml($listed['body'], 'NextContinuationToken') : '';

        } while ($token !== '');

        return $items;
    }

    public function delete($name)
    {
        $deleted = $this->send('DELETE', $this->key($name));

        return is_wp_error($deleted) ? $deleted : true;
    }

    public function download($name, $offset, $length)
    {
        $got = $this->send('GET', $this->key($name), [], '', [
            'Range' => 'bytes=' . (int) $offset . '-' . ((int) $offset + (int) $length - 1),
        ], 300);

        return is_wp_error($got) ? $got : $got['body'];
    }
}
