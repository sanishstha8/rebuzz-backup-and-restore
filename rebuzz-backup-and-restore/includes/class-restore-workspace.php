<?php

if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.WP.AlternativeFunctions -- WP_Filesystem has no streaming API; archives are moved in chunks to stay inside memory limits.

class WPCB_Restore_Workspace
{
    private $path;

    public function __construct($path = '')
    {
        if (!empty($path)) {

            $this->path = $path;

        } else {

            $base = wpcb_data_dir();

            if (!is_dir($base)) {
                wp_mkdir_p($base);
            }

            wpcb_protect_directory($base);

            $this->path = $base . '/restore-' . uniqid();
        }

        if (!file_exists($this->path)) {
            wp_mkdir_p($this->path);
        }
    }

    public function path()
    {
        return $this->path;
    }

    public function file($filename)
    {
        return $this->path . '/' . $filename;
    }

    public function exists($filename)
    {
        return file_exists($this->file($filename));
    }

    public function put($filename, $data)
    {
        $path = $this->file($filename);

        $dir = dirname($path);

        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }

        if (is_array($data) || is_object($data)) {

            return file_put_contents(
                $path,
                wp_json_encode($data, JSON_PRETTY_PRINT)
            );
        }

        return file_put_contents($path, $data);
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

        $data = json_decode($content, true);

        return is_array($data) ? $data : [];
    }

    public function openWrite($filename)
    {
        return fopen($this->file($filename), 'w');
    }

    public function openAppend($filename)
    {
        return fopen($this->file($filename), 'ab');
    }

    public function openRead($filename)
    {
        return fopen($this->file($filename), 'r');
    }

    /**
     * Delete workspace recursively
     */
    public function cleanup()
    {
        $this->delete($this->path);
    }

    private function delete($dir)
    {
        if (!file_exists($dir)) {
            return;
        }

        foreach (scandir($dir) as $item) {

            if ($item == '.' || $item == '..') {
                continue;
            }

            $path = $dir . '/' . $item;

            if (is_link($path)) {

                // Don't follow symlink into target - just unlink it.
                wp_delete_file($path);

            } elseif (is_dir($path)) {

                $this->delete($path);

            } else {

                wp_delete_file($path);

            }
        }

        rmdir($dir);
    }
}
