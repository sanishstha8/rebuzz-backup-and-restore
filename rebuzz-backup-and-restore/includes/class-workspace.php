<?php

if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.WP.AlternativeFunctions -- WP_Filesystem has no streaming API; archives are moved in chunks to stay inside memory limits.

class WPCB_Workspace
{
    /**
     * Current workspace path
     */
    private $path;

    public function __construct($path = '')
{
    if (!empty($path)) {

        $this->path = $path;

    } else {

        $base = wpcb_temp_dir();

        if (!is_dir($base)) {
            wp_mkdir_p($base);
        }

        wpcb_protect_directory($base);

        $this->path = $base . '/backup_' . uniqid();

    }

    if (!file_exists($this->path)) {
        wp_mkdir_p($this->path);
    }
}

    /**
     * Get workspace directory
     */
    public function path()
    {
        return $this->path;
    }

    /**
     * Get file inside workspace
     */
    public function file($filename)
    {
        return $this->path . '/' . $filename;
    }

    /**
     * Delete workspace recursively
     */
    public function cleanup()
    {
        $this->deleteDirectory($this->path);
    }

    private function deleteDirectory($dir)
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) as $item) {

            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;

            if (is_link($path)) {

                // don't follow symlink target, just remove the link
                wp_delete_file($path);

            } elseif (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                wp_delete_file($path);
            }
        }

        rmdir($dir);
    }


    public function put($filename, $data)
{
    $path = $this->file($filename);

    if (is_array($data) || is_object($data)) {

        return file_put_contents(
            $path,
            wp_json_encode($data, JSON_PRETTY_PRINT)
        );

    }

    return file_put_contents(
        $path,
        $data
    );
}

    public function get($filename)
{
    $path = $this->file($filename);

    if (!file_exists($path)) {
        return null;
    }

    return file_get_contents($path);
}

    public function getJson($filename)
{
    $content = $this->get($filename);

    if ($content === null) {
        return [];
    }

    $data = json_decode(
        $content,
        true
    );

    // guard: corrupted/truncated JSON decodes to null, not an array
    // (callers expect array); mirrors WPCB_Restore_Workspace::getJson()
    return is_array($data) ? $data : [];
}

        public function exists($filename)
{
    return file_exists(
        $this->file($filename)
    );
}

    public function delete($filename)
{
    $path = $this->file($filename);

    if (file_exists($path)) {
        wp_delete_file($path);
    }
}

/**
 * Open a file for writing
 */
public function openWrite($filename)
{
    return fopen(
        $this->file($filename),
        'w'
    );
}

/**
 * Open a file for reading
 */
public function openRead($filename)
{
    return fopen(
        $this->file($filename),
        'r'
    );
}

/**
 * Open a file for appending (created if it doesn't exist yet)
 */
public function openAppend($filename)
{
    return fopen(
        $this->file($filename),
        'ab'
    );
}

}