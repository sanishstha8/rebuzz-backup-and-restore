<?php

if (!defined('ABSPATH')) {
    exit;
}

class WPCB_FileScanner
{
    /**
     * Exact-match excluded files (not substring match). wp-config.php
     * holds this env's DB creds, so it's never backed up (restore also
     * blocks writing it back - see WPCB_Restore_Job::ENVIRONMENT_FILES).
     */
    private $excludedFiles = [
        'wp-config.php',
    ];

    /**
     * WP core files/folders. Only excluded when "Include WordPress
     * Core" setting is off (see applyCoreExclusion()); defaults on.
     * wp-config.php not repeated here - already excluded above for
     * an unrelated reason (env DB creds, not core code).
     */
    private $coreDirectories = [
        'wp-admin',
        'wp-includes',
    ];

    private $coreFiles = [
        'index.php',
        'license.txt',
        'readme.html',
        'wp-activate.php',
        'wp-blog-header.php',
        'wp-comments-post.php',
        'wp-config-sample.php',
        'wp-cron.php',
        'wp-links-opml.php',
        'wp-load.php',
        'wp-login.php',
        'wp-mail.php',
        'wp-settings.php',
        'wp-signup.php',
        'wp-trackback.php',
        'xmlrpc.php',
    ];

    /** @var bool */
    private $includeCore;

    /** @var WPCB_Multisite|null */
    private $multisiteHelper;

    public function __construct()
    {
        $settings = get_option('wpcb_settings', []);

        $this->includeCore = !isset($settings['include_core']) || !empty($settings['include_core']);

        if (!$this->includeCore) {
            $this->excludedFiles = array_merge($this->excludedFiles, $this->coreFiles);
        }
    }

    /**
     * Dirs ignored anywhere in the tree.
     *
     * 'vendor' deliberately NOT excluded - plugins need it at runtime,
     * excluding it broke them on restore. node_modules stays excluded
     * (dev-only). cache/upgrade/backups moved to $excludedPaths below,
     * top-level only - matching "cache" anywhere broke plugins that
     * ship their own cache/ folder.
     */
    private $excluded = [
        'node_modules',
        '.git',
        '.svn',
        '.idea',
    ];

    /**
     * Exact top-level paths only (not any folder sharing the name).
     * Last two are other backup plugins' storage folders - unexcluded,
     * their old archive ZIPs get copied into every new backup forever.
     */
    private $excludedPaths = [
        'wp-content/cache',
        'wp-content/upgrade',
        'wp-content/upgrade-temp-backup',
        'wp-content/backups',
        // Other backup/migration plugins' storage. Left in, their old
        // archives get copied into every new backup forever, and each
        // backup then contains the one before it.
        'wp-content/ai1wm-backups',            // All-in-One WP Migration
        'wp-content/updraft',                  // UpdraftPlus
        'wp-content/backups-dup-lite',         // Duplicator (Lite)
        'wp-content/backups-dup-pro',          // Duplicator Pro
        'wp-content/wpvivid_backup',           // WPvivid
        'wp-content/aiowps_backups',           // All In One WP Security
        'wp-content/uploads/backupbuddy_backups',
        'wp-content/uploads/duplicator',
        'wp-content/uploads/wp-migrate-db',
        'wp-content/uploads/wpvivid_backup',
        'wp-content/uploads/wp-clone',
    ];

    /**
     * Other backup plugins whose folder name carries a per-install
     * random or dated suffix, so it can't be listed above as a fixed
     * path. Matched as a prefix against directories directly inside the
     * uploads folder - see isForeignBackupDirectory().
     *
     * @var string[]
     */
    private $excludedUploadPrefixes = [
        'backwpup-',   // BackWPup: backwpup-<hash>-backups
        'wpvivid-',
        'ai1wm-',
    ];

    /** Absolute, realpath()'d site root all scans are relative to. */
    public function root()
    {
        return realpath(ABSPATH);
    }

    /**
     * Stack-walk start point; persists between AJAX round-trips
     * (see WPCB_Backup_Job::stepScanFiles()).
     */
    public function initialStack()
    {
        $root = $this->root();

        return $root !== false ? [$root] : [];
    }

    /**
     * Whether to skip descending into a dir entirely. Result is the
     * same either way (contents are excluded anyway) - just avoids
     * wastefully walking huge trees like node_modules.
     */
    public function shouldSkipDirectory($dir, $root)
    {
        // Never follow symlinked dirs - avoids infinite loops or
        // escaping the site root; matches how restore treats symlinks.
        if (is_link($dir)) {
            return true;
        }

        if ($this->isOwnDirectory($dir, $root)) {
            return true;
        }

        if ($this->isAnotherSitesUploadsPath($dir)) {
            return true;
        }

        if ($this->isForeignBackupDirectory($dir)) {
            return true;
        }

        if (in_array(basename($dir), $this->excluded, true)) {
            return true;
        }

        foreach ($this->absoluteExcludedPaths($root) as $excludedPath) {

            if ($dir === $excludedPath) {
                return true;
            }
        }

        if (!$this->includeCore) {

            foreach ($this->absoluteCoreDirectories($root) as $coreDir) {

                if ($dir === $coreDir) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Whether $dir is another backup plugin's storage folder sitting
     * directly inside uploads, matched by name prefix because those
     * plugins append a per-install hash or date that no fixed path can
     * cover - see $excludedUploadPrefixes.
     *
     * Only folders one level under the uploads root are considered, so
     * an ordinary media folder that happens to share a prefix deeper in
     * the tree is not affected.
     */
    private function isForeignBackupDirectory($dir)
    {
        $upload = wp_upload_dir();

        if (empty($upload['basedir'])) {
            return false;
        }

        $basedir = rtrim(str_replace('\\', '/', $upload['basedir']), '/');
        $normalized = rtrim(str_replace('\\', '/', $dir), '/');

        if (strpos($normalized, $basedir . '/') !== 0) {
            return false;
        }

        // Directly inside uploads only - no slash left in the remainder.
        $relative = substr($normalized, strlen($basedir) + 1);

        if ($relative === '' || strpos($relative, '/') !== false) {
            return false;
        }

        foreach ($this->excludedUploadPrefixes as $prefix) {

            if (strpos($relative, $prefix) === 0) {
                return true;
            }
        }

        return false;
    }

    /** Whether a file should be left out of the backup. */
    public function shouldSkipFile($file, $root)
    {
        // Same reasoning as shouldSkipDirectory(): a symlinked file
        // could point anywhere, so it's excluded.
        if (is_link($file)) {
            return true;
        }

        // A loose file sitting directly in the shared multisite
        // uploads root (not organized into month/year folders) would
        // otherwise slip past the directory-level check above, since
        // that only runs when a directory is popped off the walk
        // stack - see isAnotherSitesUploadsPath().
        if ($this->isAnotherSitesUploadsPath($file)) {
            return true;
        }

        $relativeToRoot = $this->relativeToRoot($file, $root);

        return in_array($relativeToRoot, $this->excludedFiles, true);
    }

    /**
     * $absolutePath relative to $root, anchored to the start (unlike
     * a blind str_replace, which could strip $root from anywhere in
     * the string). Shared by shouldSkipFile() and matchesOwnLocation()
     * so there's one implementation, not two that could drift apart.
     *
     * @return string '' if $absolutePath isn't under $root.
     */
    private function relativeToRoot($absolutePath, $root)
    {
        $normalizedPath = str_replace(DIRECTORY_SEPARATOR, '/', $absolutePath);
        $normalizedRoot = rtrim(str_replace(DIRECTORY_SEPARATOR, '/', $root), '/');

        if (strpos($normalizedPath . '/', $normalizedRoot . '/') !== 0) {
            return '';
        }

        return ltrim(substr($normalizedPath, strlen($normalizedRoot)), '/');
    }

    /**
     * Whether $path (file or directory) is inside another site's
     * uploads folder on a multisite network (uploads is one tree
     * shared by every site). Without this, one site's backup sweeps in
     * every other site's media and old backup ZIPs, causing compounding
     * bloat. Always false on single-site.
     */
    private function isAnotherSitesUploadsPath($path)
    {
        foreach ($this->multisite()->otherSitesUploadsDirs() as $otherPath) {

            if (
                $path === $otherPath ||
                strpos($path . DIRECTORY_SEPARATOR, $otherPath . DIRECTORY_SEPARATOR) === 0
            ) {
                return true;
            }
        }

        return false;
    }

    private function multisite()
    {
        if ($this->multisiteHelper === null) {
            $this->multisiteHelper = new WPCB_Multisite();
        }

        return $this->multisiteHelper;
    }

    /**
     * Whether $dir is the plugin's own data folder (backups/temp/logs)
     * or code folder, or inside either. Code folder: without this a
     * restore could overwrite the running plugin with an old captured
     * version mid-restore. Data folder: without this, old backup ZIPs
     * get swept into new backups, multiplying their size.
     */
    private function isOwnDirectory($dir, $root)
    {
        // Current data folder, plus every name it has used before -
        // see dataDirNames().
        foreach ($this->dataDirNames() as $folderName) {

            if (
                $this->matchesOwnLocation(
                    $dir,
                    $root,
                    $this->dataDirFor($folderName),
                    $this->dataDirRelativeFor($folderName, $root)
                )
            ) {
                return true;
            }
        }

        if ($this->matchesOwnLocation($dir, $root, $this->ownCodeDir(), $this->ownCodeDirRelative($root))) {
            return true;
        }

        return false;
    }

    /**
     * Current data folder name first, then every name earlier releases
     * used (see wpcb_legacy_data_dirnames()).
     *
     * A site that upgraded may still have an older folder on disk full
     * of old backup ZIPs - wpcb_migrate_legacy_data_dir() moves it on
     * activation, but only when the current folder doesn't already
     * exist, so an install that has both keeps the old one. Since the
     * exclusion is derived from the folder name, dropping a legacy name
     * from this list would make the scanner stop recognizing that
     * folder as its own and start sweeping those old archives into
     * every new backup, roughly doubling its size each time.
     *
     * @return string[]
     */
    private function dataDirNames()
    {
        $names = array_merge(
            [wpcb_data_dirname()],
            wpcb_legacy_data_dirnames()
        );

        /*
         * Plus any folder actually on disk whose name starts with the
         * current one.
         *
         * A 1.4.0 pre-release briefly stored data in
         * "rebuzz-backup-and-restore-<20 random characters>", a
         * different name on every install, so no fixed list can name
         * them. Sites that ran that build still have those folders full
         * of archives, and without this the scanner would not recognise
         * them as its own and would sweep them into every new backup -
         * roughly doubling its size each time.
         */
        $upload = wp_upload_dir();

        if (!empty($upload['basedir'])) {

            $matches = glob($upload['basedir'] . '/' . wpcb_data_dirname() . '-*', GLOB_ONLYDIR) ?: [];

            foreach ($matches as $dir) {
                $names[] = basename($dir);
            }
        }

        return array_unique($names);
    }

    /**
     * Whether $dir is (or is inside) $ownAbsolute, checked two ways:
     * a realpath() comparison (can miss if symlinks resolve
     * differently) and a $root-relative string comparison as a
     * symlink-proof fallback. Checking both is safer than either alone.
     */
    private function matchesOwnLocation($dir, $root, $ownAbsolute, $ownRelative)
    {
        if (
            $ownAbsolute &&
            ($dir === $ownAbsolute || strpos($dir . DIRECTORY_SEPARATOR, $ownAbsolute . DIRECTORY_SEPARATOR) === 0)
        ) {
            return true;
        }

        if ($ownRelative === '') {
            return false;
        }

        $relativeToRoot = $this->relativeToRoot($dir, $root);

        return (
            $relativeToRoot === $ownRelative ||
            strpos($relativeToRoot . '/', $ownRelative . '/') === 0
        );
    }

    /**
     * A plugin data dir (backups/temp/logs) under uploads, realpath()'d.
     * See matchesOwnLocation() for why this alone isn't relied on.
     *
     * @param string $folderName Current or legacy folder name.
     */
    private function dataDirFor($folderName)
    {
        $upload = wp_upload_dir();

        return realpath($upload['basedir'] . '/' . $folderName);
    }

    /**
     * dataDirFor() as a $root-relative path (string-based, not
     * realpath), so both comparison sides share the same base.
     * Returns '' if uploads isn't under $root at all.
     *
     * @param string $folderName Current or legacy folder name.
     */
    private function dataDirRelativeFor($folderName, $root)
    {
        $basedir = str_replace('\\', '/', wp_upload_dir()['basedir']);
        $root = rtrim(str_replace('\\', '/', $root), '/');

        if (strpos($basedir . '/', $root . '/') !== 0) {
            return '';
        }

        $relativeUploads = trim(substr($basedir, strlen($root)), '/');

        return $relativeUploads . '/' . $folderName;
    }

    /**
     * Plugin's own code folder, realpath()'d. WPCB_PATH is defined
     * by the main plugin file as plugin_dir_path(__FILE__).
     */
    private function ownCodeDir()
    {
        return defined('WPCB_PATH') ? realpath(WPCB_PATH) : false;
    }

    /** ownCodeDir() as a $root-relative path - see dataDirRelativeFor(). */
    private function ownCodeDirRelative($root)
    {
        if (!defined('WPCB_PATH')) {
            return '';
        }

        $pluginPath = rtrim(str_replace('\\', '/', WPCB_PATH), '/');
        $root = rtrim(str_replace('\\', '/', $root), '/');

        if (strpos($pluginPath . '/', $root . '/') !== 0) {
            return '';
        }

        return trim(substr($pluginPath, strlen($root)), '/');
    }

    /** Absolute, OS-native versions of $excludedPaths. */
    private function absoluteExcludedPaths($root)
    {
        $paths = [];

        foreach ($this->excludedPaths as $relative) {
            $native = str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $paths[] = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $native;
        }

        return $paths;
    }

    /** Absolute, OS-native $coreDirectories paths. Only used when $includeCore is false. */
    private function absoluteCoreDirectories($root)
    {
        $paths = [];

        foreach ($this->coreDirectories as $relative) {
            $paths[] = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $relative;
        }

        return $paths;
    }
}