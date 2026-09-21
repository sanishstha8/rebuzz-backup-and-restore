<?php

if (!defined('ABSPATH')) {
    exit;
}

class WPCB_Checksum
{
    /**
     * Calculate SHA-256 hash of a file
     */
    public function file($path)
    {
        if (!file_exists($path)) {
            return false;
        }

        return hash_file('sha256', $path);
    }

    /**
     * Calculate hashes for multiple files
     */
    public function generate(array $files)
    {
        $checksums = [];

        foreach ($files as $name => $path) {

            if (!file_exists($path)) {
                continue;
            }

            $checksums[$name] = hash_file('sha256', $path);

        }

        return $checksums;
    }
}