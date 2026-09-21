<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Wraps WPCB_Checksum: checks files against manifest before restore.
 * Own class so DB check and per-file check share one code path.
 */
class WPCB_Integrity
{
    private $checksum;

    public function __construct()
    {
        $this->checksum = new WPCB_Checksum();
    }

    /**
     * Check a file's SHA-256 hash against the manifest's expected hash.
     *
     * @param string $path         File to check.
     * @param string $expectedHash Hash from manifest.
     * @return bool True if match.
     */
    public function verifyFile($path, $expectedHash)
    {
        if (empty($expectedHash) || !file_exists($path)) {
            return false;
        }

        $actual = $this->checksum->file($path);

        if ($actual === false) {
            return false;
        }

        return hash_equals($expectedHash, $actual);
    }

    /** Get checksums from decoded manifest; tolerate missing/bad data. */
    public function checksumsFromManifest($manifest)
    {
        if (
            is_array($manifest) &&
            !empty($manifest['checksums']) &&
            is_array($manifest['checksums'])
        ) {
            return $manifest['checksums'];
        }

        return [];
    }
}
