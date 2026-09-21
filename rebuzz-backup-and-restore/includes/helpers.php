<?php

if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.WP.AlternativeFunctions -- locks need fopen('x') for atomic create-if-not-exists; paths stay inside our own uploads folder.

/**
 * Name of this plugin's storage folder inside uploads.
 *
 * Fixed, not randomised, and deliberately so.
 *
 * This folder is only where backups live when storage is already known
 * to be private - either WPCB_BACKUP_DIR is set to somewhere outside
 * the served tree, or wpcb_storage_is_private() has confirmed the
 * server refuses to hand files out of here. When neither holds, the
 * plugin declines to create a backup at all rather than put one here,
 * so this folder's name is a convenience, not a protection.
 *
 * An earlier 1.4.0 build put a random suffix on this folder instead.
 * That was a mistake with a specific, serious failure: the name was
 * derived from a site option, and a restore drops and refills
 * wp_options in ~2MB chunks across many requests, so mid-import the
 * option read empty and each request minted a new folder - moving the
 * lock file, the log and the workspace out from under the running
 * restore, which then looped until the browser gave up. A fixed name
 * has no state to lose and keeps the folder findable for anyone
 * dropping an archive in over FTP; the random component now lives in
 * the filename, purely as defence in depth.
 */
function wpcb_data_dirname()
{
    return 'rebuzz-backup-and-restore';
}

/**
 * Storage folder names used by earlier releases. A site that upgraded
 * still has one of these on disk full of old archives -
 * wpcb_migrate_legacy_data_dir() moves it, and WPCB_FileScanner keeps
 * recognising it as this plugin's own either way. Never remove an entry.
 *
 * @return string[]
 */
function wpcb_legacy_data_dirnames()
{
    return [
        'wp-complete-backup',
    ];
}

/**
 * The admin menu icon, as something add_menu_page() can use.
 *
 * Returns a base64 SVG data URI when the SVG asset is readable, and the
 * PNG's URL otherwise.
 *
 * The difference matters for how the icon looks. WordPress renders a
 * PNG URL as an <img> at its natural size, so the file has to be
 * exactly 20x20 - which has far too few pixels for a high-density
 * screen and is why the icon looked blurred. A data:image/svg+xml
 * URI takes a different path in menu-header.php: it becomes a
 * background-image with "background-size: 20px auto", so the browser
 * scales a larger source down to 20px and the result stays sharp.
 *
 * @return string
 */
function wpcb_menu_icon()
{
    static $icon = null;

    if ($icon !== null) {
        return $icon;
    }

    $svg = WPCB_PATH . 'assets/img/menu-icon.svg';

    if (is_readable($svg)) {

        $markup = file_get_contents($svg);

        if ($markup !== false && $markup !== '') {

            $icon = 'data:image/svg+xml;base64,' . base64_encode($markup);

            return $icon;
        }
    }

    $icon = WPCB_URL . 'assets/img/menu-icon.png';

    return $icon;
}

/**
 * Absolute path with symlinks, "..", "." and trailing slashes resolved -
 * for a path that does not exist yet as well as one that does.
 *
 * realpath() alone returns false for a directory that has not been
 * created, and WPCB_BACKUP_DIR is routinely pointed at one. So the
 * deepest ancestor that does exist is realpath()'d - which is what
 * resolves any symlink in the part of the path that is real - and the
 * remaining segments are then applied literally, with ".." collapsed.
 *
 * @return string Normalised absolute path, or '' if it cannot be resolved.
 */
function wpcb_canonical_path($path)
{
    $path = rtrim(str_replace('\\', '/', (string) $path), '/');

    if ($path === '') {
        return '';
    }

    /*
     * Absolute only. A relative path would be resolved against whatever
     * the current working directory happens to be for this request,
     * which is not something a security decision may depend on - and
     * walking up such a path lands on "." and silently anchors it to the
     * CWD, which could sit either side of the document root.
     */
    if (strpos($path, '/') !== 0 && !preg_match('#^[A-Za-z]:/#', $path)) {
        return '';
    }

    $real = realpath($path);

    if ($real !== false) {
        return rtrim(str_replace('\\', '/', $real), '/');
    }

    // Walk up to the deepest part that exists, keeping what we trim.
    $trailing = [];
    $current  = $path;

    while ($current !== '' && realpath($current) === false) {

        $parent = dirname($current);

        // dirname() stops changing at a filesystem root; without this
        // an unresolvable path would loop forever.
        if ($parent === $current) {
            return '';
        }

        array_unshift($trailing, basename($current));
        $current = $parent;
    }

    $base = realpath($current);

    if ($base === false) {
        return '';
    }

    $segments = explode('/', rtrim(str_replace('\\', '/', $base), '/'));

    foreach ($trailing as $segment) {

        if ($segment === '' || $segment === '.') {
            continue;
        }

        if ($segment === '..') {
            array_pop($segments);
            continue;
        }

        $segments[] = $segment;
    }

    return implode('/', $segments);
}

/**
 * Whether $path is $root, or sits underneath it.
 *
 * Compares canonical forms and appends a slash to both, so
 * "/var/www/html-backups" is not mistaken for a child of
 * "/var/www/html". Case-insensitive on Windows, where the filesystem is.
 */
function wpcb_path_is_within($path, $root)
{
    $path = wpcb_canonical_path($path);
    $root = wpcb_canonical_path($root);

    if ($path === '' || $root === '') {
        return false;
    }

    if (DIRECTORY_SEPARATOR === '\\') {
        $path = strtolower($path);
        $root = strtolower($root);
    }

    return ($path === $root) || strpos($path . '/', $root . '/') === 0;
}

/**
 * Every directory tree the web server may serve from.
 *
 * ABSPATH alone is not enough: WordPress is often installed in a
 * subdirectory of the document root, so a sibling folder can be outside
 * ABSPATH and still be served. DOCUMENT_ROOT covers that. WP_CONTENT_DIR
 * is included because it can be relocated outside ABSPATH while
 * remaining public.
 *
 * @return string[] Canonical paths, no duplicates.
 */
function wpcb_public_roots()
{
    $roots = [ABSPATH];

    if (!empty($_SERVER['DOCUMENT_ROOT'])) {
        $roots[] = sanitize_text_field(wp_unslash($_SERVER['DOCUMENT_ROOT']));
    }

    if (defined('WP_CONTENT_DIR')) {
        $roots[] = WP_CONTENT_DIR;
    }

    $canonical = [];

    foreach ($roots as $root) {

        $resolved = wpcb_canonical_path($root);

        if ($resolved !== '' && !in_array($resolved, $canonical, true)) {
            $canonical[] = $resolved;
        }
    }

    return $canonical;
}

/**
 * Whether $dir is genuinely outside every tree the web server serves.
 *
 * Deliberately conservative: a directory is only private if it can be
 * resolved AND is outside all of wpcb_public_roots(). Anything that
 * cannot be resolved is treated as public, because "I could not tell"
 * and "it is safe" are not the same answer when the file at stake is a
 * full database dump.
 */
function wpcb_path_is_outside_webroot($dir)
{
    $dir = wpcb_canonical_path($dir);

    if ($dir === '') {
        return false;
    }

    $roots = wpcb_public_roots();

    if (empty($roots)) {
        return false;
    }

    foreach ($roots as $root) {

        if (wpcb_path_is_within($dir, $root)) {
            return false;
        }
    }

    return true;
}

/**
 * A storage directory set by the site owner in wp-config.php, or '' if
 * none is set.
 *
 * define('WPCB_BACKUP_DIR', '/home/account/private/rebuzz-backups');
 *
 * This is the only way a backup archive can be made genuinely
 * unreachable over HTTP. Everything inside the WordPress installation -
 * uploads, wp-content, anywhere - is under the web server's document
 * root and is served as a static file by Nginx, which does not read
 * .htaccess. Pointing this constant at a directory outside the document
 * root removes the archives from the served tree entirely.
 *
 * It is opt-in rather than automatic on purpose: only the site owner
 * knows their server's layout, the plugin guidelines forbid a plugin
 * writing outside the WordPress installation of its own accord, and a
 * wrong guess would put backups somewhere unwritable or, worse, still
 * public.
 *
 * @return string Absolute path with no trailing slash, or ''.
 */
function wpcb_configured_data_dir()
{
    static $resolved = null;

    if ($resolved !== null) {
        return $resolved;
    }

    $resolved = '';

    if (!defined('WPCB_BACKUP_DIR')) {
        return $resolved;
    }

    $dir = rtrim(str_replace('\\', '/', (string) WPCB_BACKUP_DIR), '/');

    // Must be absolute, or it would resolve against whatever the
    // current working directory happens to be for this request.
    $isAbsolute = (strpos($dir, '/') === 0) || preg_match('#^[A-Za-z]:/#', $dir);

    if ($dir === '' || !$isAbsolute) {
        return $resolved;
    }

    /*
     * Checked before the directory is created, not after: being set is
     * not the same as being private. A path inside the document root -
     * /var/www/html/rebuzz-backups when /var/www/html is served, or the
     * document root itself - is exactly as public as the uploads folder
     * it was meant to replace. Symlinks and ".." are resolved first, so
     * neither can be used to slip a served directory past this.
     */
    if (!wpcb_path_is_outside_webroot($dir)) {
        return $resolved;
    }

    /*
     * Fall back to uploads if the directory cannot actually be used.
     * Silently writing nowhere would leave a site believing it had
     * backups when it had none - and the storage check will then report
     * the uploads folder, which is the accurate thing to act on.
     */
    if (!is_dir($dir) && !wp_mkdir_p($dir)) {
        return $resolved;
    }

    if (!is_writable($dir)) {
        return $resolved;
    }

    $resolved = $dir;

    return $resolved;
}

/**
 * Why a configured WPCB_BACKUP_DIR was rejected, for the Dashboard.
 * Empty string when none is set, or when it was accepted.
 */
function wpcb_configured_data_dir_problem()
{
    if (!defined('WPCB_BACKUP_DIR') || wpcb_configured_data_dir() !== '') {
        return '';
    }

    $dir = rtrim(str_replace('\\', '/', (string) WPCB_BACKUP_DIR), '/');

    $isAbsolute = ($dir !== '')
        && ((strpos($dir, '/') === 0) || preg_match('#^[A-Za-z]:/#', $dir));

    if (!$isAbsolute) {
        return __('it is not an absolute path', 'rebuzz-backup-and-restore');
    }

    if (!wpcb_path_is_outside_webroot($dir)) {
        return __('it is inside the folder your web server publishes, so backups kept there could be downloaded directly', 'rebuzz-backup-and-restore');
    }

    if (!is_dir($dir)) {
        return __('it does not exist and could not be created', 'rebuzz-backup-and-restore');
    }

    return __('it is not writable by PHP', 'rebuzz-backup-and-restore');
}

/**
 * Whether WPCB_BACKUP_DIR is set but unusable, so the plugin has
 * quietly fallen back to the uploads folder. Surfaced on the Dashboard;
 * without it a misconfigured path looks like it is working.
 */
function wpcb_configured_data_dir_failed()
{
    return defined('WPCB_BACKUP_DIR') && wpcb_configured_data_dir() === '';
}

/**
 * Absolute path to this plugin's storage folder.
 *
 * WPCB_BACKUP_DIR wins when set (see wpcb_configured_data_dir());
 * otherwise resolved at runtime from wp_upload_dir(), never hardcoded,
 * so a custom UPLOADS constant or a per-site multisite uploads path is
 * honoured. On multisite the uploads fallback is per-site, which keeps
 * one site's archives out of another's folder.
 */
function wpcb_data_dir()
{
    $configured = wpcb_configured_data_dir();

    if ($configured !== '') {

        /*
         * Still per-site on multisite: wp_upload_dir()'s basedir differs
         * per site, and collapsing every site into one directory would
         * let a site admin list and restore another site's archives.
         */
        if (is_multisite()) {
            return $configured . '/site-' . get_current_blog_id();
        }

        return $configured;
    }

    $upload = wp_upload_dir();

    return $upload['basedir'] . '/' . wpcb_data_dirname();
}

/**
 * URL of this plugin's storage folder, or '' when it has none.
 *
 * Returns '' whenever WPCB_BACKUP_DIR is in use: that directory is
 * outside the uploads tree and therefore has no URL at all, which is
 * the entire point of setting it. Callers must treat '' as "not
 * reachable over HTTP" rather than building a URL anyway.
 */
function wpcb_data_url()
{
    if (wpcb_configured_data_dir() !== '') {
        return '';
    }

    $upload = wp_upload_dir();

    return $upload['baseurl'] . '/' . wpcb_data_dirname();
}

/** Where finished backup archives are kept. */
function wpcb_backups_dir()
{
    return wpcb_data_dir() . '/backups';
}

/** URL counterpart of wpcb_backups_dir(), or '' when it has none. */
function wpcb_backups_url()
{
    $base = wpcb_data_url();

    return ($base === '') ? '' : $base . '/backups';
}

/** Scratch space for a backup in progress. */
function wpcb_temp_dir()
{
    return wpcb_data_dir() . '/temp';
}

/** Where backup.log / restore.log are written. */
function wpcb_logs_dir()
{
    return wpcb_data_dir() . '/logs';
}

/**
 * $absolute rendered relative to the WordPress root, for showing a
 * storage path in an admin message. The absolute path is a server path
 * that may expose the hosting account's home directory, so messages
 * show this shortened form - and deriving it from wp_upload_dir()
 * rather than hardcoding "wp-content/uploads/..." keeps it correct on a
 * site with a custom uploads location.
 */
function wpcb_display_path($absolute)
{
    $absolute = str_replace('\\', '/', (string) $absolute);
    $root = rtrim(str_replace('\\', '/', ABSPATH), '/');

    if ($root !== '' && strpos($absolute . '/', $root . '/') === 0) {
        return trim(substr($absolute, strlen($root)), '/');
    }

    return $absolute;
}

/**
 * Move a previous release's storage folder to the current name, so an
 * upgrading site keeps its existing backup archives instead of finding
 * an empty list. Runs on activation only; no-op once migrated, and
 * silently left alone if the folder can't be moved (the old archives
 * stay readable where they are rather than being lost to a half-done
 * move).
 */
function wpcb_migrate_legacy_data_dir()
{
    $upload = wp_upload_dir();

    if (empty($upload['basedir'])) {
        return;
    }

    $target = wpcb_data_dir();

    if (is_dir($target)) {
        return;
    }

    foreach (wpcb_legacy_data_dirnames() as $legacyName) {

        $legacyDir = $upload['basedir'] . '/' . $legacyName;

        if (!is_dir($legacyDir)) {
            continue;
        }

        if (@rename($legacyDir, $target)) {
            return;
        }
    }
}

/**
 * Create this plugin's storage folders and write the access-blocking
 * files into each. Shared by activation and the version-change upgrade
 * path (see WPCB_Admin::maybeUpgrade()), since an in-place update never
 * re-runs the activation hook and the folders must exist either way.
 */
function wpcb_prepare_data_directories()
{
    $base = wpcb_data_dir();

    wp_mkdir_p($base);
    wp_mkdir_p(wpcb_backups_dir());
    wp_mkdir_p(wpcb_temp_dir());
    wp_mkdir_p(wpcb_logs_dir());

    // Rules cascade to subdirectories, but each is protected in its own
    // right too - a server that ignores the parent's still gets one here.
    wpcb_protect_directory($base);
    wpcb_protect_directory(wpcb_backups_dir());
    wpcb_protect_directory(wpcb_temp_dir());
    wpcb_protect_directory(wpcb_logs_dir());
}

/**
 * Archives still sitting in the uploads folder after WPCB_BACKUP_DIR
 * moved storage elsewhere.
 *
 * They are not deleted or moved automatically: they can be many
 * gigabytes, moving them across filesystems mid-request is exactly the
 * kind of thing that dies half-way, and they may be the only copy. The
 * Dashboard reports them so the workflow is never silently broken -
 * they stay listed, downloadable and restorable from wherever they are
 * once moved into the new folder.
 *
 * @return array{dir: string, files: string[], size: int}|null
 */
function wpcb_stranded_uploads_backups()
{
    if (wpcb_configured_data_dir() === '') {
        return null;
    }

    $upload = wp_upload_dir();

    if (empty($upload['basedir'])) {
        return null;
    }

    $dir = $upload['basedir'] . '/' . wpcb_data_dirname() . '/backups';

    if (!is_dir($dir)) {
        return null;
    }

    $files = glob($dir . '/*.zip') ?: [];

    if (empty($files)) {
        return null;
    }

    $size = 0;

    foreach ($files as $file) {
        $bytes = filesize($file);
        $size += ($bytes !== false) ? $bytes : 0;
    }

    return [
        'dir'   => $dir,
        'files' => array_map('basename', $files),
        'size'  => $size,
    ];
}

/**
 * Whether finished archives can be fetched over HTTP without logging in.
 *
 * Actually tests it rather than assuming: writes a short-lived file of
 * random bytes into the backups folder, requests its own URL over HTTP
 * with no cookies, and compares what comes back. .htaccess and
 * web.config only take effect on Apache and IIS, so on Nginx the
 * archives are plain static files and this returns true - which is
 * exactly the condition an administrator needs told about.
 *
 * @return string 'private'  nothing came back, or there is no URL at all
 *                'public'   the canary was served verbatim
 *                'unknown'  the check could not complete (loopback
 *                           blocked, DNS, timeout) - never reported as
 *                           safe, since a failed check proves nothing.
 */
function wpcb_storage_exposure()
{
    // No URL means the folder is outside the served tree entirely.
    if (wpcb_backups_url() === '') {
        return 'private';
    }

    $dir = wpcb_backups_dir();

    if (!is_dir($dir)) {
        return 'unknown';
    }

    $name = 'wpcb-access-check-' . wp_generate_password(16, false, false) . '.txt';
    $token = wp_generate_password(32, false, false);

    if (file_put_contents($dir . '/' . $name, $token) === false) {
        return 'unknown';
    }

    $response = wp_remote_get(
        wpcb_backups_url() . '/' . $name,
        [
            'timeout'     => 10,
            'redirection' => 0,
            'sslverify'   => false,
            // No cookies: this must mimic a logged-out stranger.
            'cookies'     => [],
        ]
    );

    wp_delete_file($dir . '/' . $name);

    if (is_wp_error($response)) {
        return 'unknown';
    }

    $code = (int) wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);

    if ($code === 200 && trim($body) === $token) {
        return 'public';
    }

    // Any other outcome - 403, 404, a redirect to a login page, an
    // error document - means the bytes were not handed over.
    return 'private';
}

/**
 * wpcb_storage_exposure(), cached for a day so the HTTP round trip
 * happens on an occasional admin page load rather than constantly.
 * A completed backup clears it, since that is when it matters most.
 */
function wpcb_storage_exposure_cached()
{
    $cached = get_transient('wpcb_storage_exposure');

    if (is_string($cached) && $cached !== '') {
        return $cached;
    }

    $result = wpcb_storage_exposure();

    set_transient('wpcb_storage_exposure', $result, DAY_IN_SECONDS);

    return $result;
}

/**
 * Whether a finished archive stored right now would be safe from being
 * fetched over HTTP by someone who is not logged in.
 *
 * This is the security boundary. A completed backup contains the whole
 * database - every user row, every password hash, every key kept in
 * options - so it must not be left where the web server will hand it
 * out. The plugin refuses to create one when this returns false, rather
 * than producing a publicly downloadable archive and warning about it.
 *
 * Three ways it can be satisfied, strongest first:
 *
 *  1. WPCB_BACKUP_DIR points outside the served tree. There is then no
 *     URL that maps to the file at all.
 *  2. The canary check actually fetched a test file from the backups
 *     folder and the server refused - an .htaccess or web.config rule
 *     really is in force, or the host blocks the folder itself.
 *  3. The canary could not run (many hosts block loopback HTTP), so
 *     fall back to what the server says it is. Only Apache-family and
 *     IIS read the rule files this plugin writes; Nginx does not.
 *
 * Anything else - including "the check could not run and the server is
 * Nginx or unrecognised" - is treated as unsafe. Guessing in the other
 * direction is what leaves a database dump on the open web.
 *
 * @return bool
 */
function wpcb_storage_is_private()
{
    if (wpcb_configured_data_dir() !== '') {
        return true;
    }

    $exposure = wpcb_storage_exposure_cached();

    if ($exposure === 'public') {
        return false;
    }

    if ($exposure === 'private') {
        return true;
    }

    // 'unknown' - the loopback request failed, which proves nothing.
    return wpcb_server_honours_directory_rules();
}

/**
 * Whether this server reads the per-directory rule files
 * wpcb_protect_directory() writes.
 *
 * Only consulted when the canary check could not complete. Apache and
 * LiteSpeed read .htaccess; IIS reads web.config; Nginx reads neither,
 * and an unrecognised server is assumed not to.
 */
function wpcb_server_honours_directory_rules()
{
    $software = isset($_SERVER['SERVER_SOFTWARE'])
        ? strtolower(sanitize_text_field(wp_unslash($_SERVER['SERVER_SOFTWARE'])))
        : '';

    if ($software === '') {
        return false;
    }

    if (strpos($software, 'nginx') !== false) {
        return false;
    }

    $readsHtaccess = (strpos($software, 'apache') !== false)
        || (strpos($software, 'litespeed') !== false);

    if ($readsHtaccess) {
        return file_exists(wpcb_backups_dir() . '/.htaccess');
    }

    if (strpos($software, 'microsoft-iis') !== false) {
        return file_exists(wpcb_backups_dir() . '/web.config');
    }

    return false;
}

/**
 * Why storage is not private, as a sentence for an administrator, with
 * the line that fixes it. Empty string when storage is private.
 */
function wpcb_storage_insecure_reason()
{
    if (wpcb_storage_is_private()) {
        return '';
    }

    return sprintf(
        /* translators: 1: path to the backups folder, relative to the WordPress root, 2: the line to add to wp-config.php */
        __('Backups are currently stored in %1$s, which this server will hand to anyone who requests the file - a backup contains your entire database. Add this line to wp-config.php, pointing at a folder outside your public web root, then try again: %2$s', 'rebuzz-backup-and-restore'),
        wpcb_display_path(wpcb_backups_dir()) . '/',
        "define( 'WPCB_BACKUP_DIR', '/full/path/outside/public_html/rebuzz-backups' );"
    );
}

/**
 * Tighten a finished archive's permissions so that, where the web
 * server and PHP run as different users (Nginx plus PHP-FPM with a
 * per-site user, a common modern layout), the web server cannot read
 * the file even if it is asked for it directly.
 *
 * Best-effort and not a guarantee: on hosts where both run as the same
 * user - shared hosting, mod_php - this changes nothing. It is one more
 * layer, not the boundary.
 */
function wpcb_restrict_file_permissions($path)
{
    if (file_exists($path)) {
        @chmod($path, 0600);
    }
}

/**
 * Convert php.ini shorthand (e.g. "500M") to bytes. 0 if empty/unlimited.
 */
function wpcb_ini_bytes($value)
{
    $value = trim((string) $value);

    if ($value === '' || $value === '0' || $value === '-1') {
        return 0;
    }

    $unit = strtolower(substr($value, -1));
    $number = (float) $value;

    switch ($unit) {
        case 'g':
            $number *= 1024;
            // fall through
        case 'm':
            $number *= 1024;
            // fall through
        case 'k':
            $number *= 1024;
    }

    return (int) $number;
}

/**
 * Max upload size given PHP config: min(upload_max_filesize, post_max_size).
 * post_max_size covers the whole request so it's usually the tighter
 * limit. Returns 0 if either is unset/unlimited.
 */
function wpcb_max_upload_bytes()
{
    $uploadMax = wpcb_ini_bytes(ini_get('upload_max_filesize'));
    $postMax = wpcb_ini_bytes(ini_get('post_max_size'));

    if ($uploadMax <= 0) {
        return $postMax;
    }

    if ($postMax <= 0) {
        return $uploadMax;
    }

    return min($uploadMax, $postMax);
}

/**
 * Hard ceiling this plugin itself enforces on browser-uploaded backup
 * ZIPs, independent of the server's upload_max_filesize/post_max_size.
 * Those PHP settings can be raised far beyond what shared hosting can
 * safely absorb in one request/extraction - a ~500MB upload on a
 * shared Bluehost account once froze every site on the whole hosting
 * account. Placing the file directly in backups/ via FTP is unaffected
 * by this and still works at any size - this only guards the browser-
 * upload path. Override in wp-config.php if a bigger browser upload is
 * genuinely needed: define('WPCB_MAX_BACKUP_UPLOAD_BYTES', ...).
 */
if (!defined('WPCB_MAX_BACKUP_UPLOAD_BYTES')) {
    define('WPCB_MAX_BACKUP_UPLOAD_BYTES', 314572800); // 300MB
}

/**
 * Effective cap for a browser-uploaded backup: the plugin's own hard
 * ceiling, or the server's upload_max_filesize/post_max_size if that's
 * even lower.
 */
function wpcb_max_backup_upload_bytes()
{
    $serverMax = wpcb_max_upload_bytes();

    if ($serverMax > 0 && $serverMax < WPCB_MAX_BACKUP_UPLOAD_BYTES) {
        return $serverMax;
    }

    return WPCB_MAX_BACKUP_UPLOAD_BYTES;
}

/**
 * Whether there's enough free disk space to keep an uploaded backup ZIP
 * of $fileSize bytes in $dir. Checked before the upload is committed to
 * disk - unlike wpcb_check_restore_disk_space(), which only runs once a
 * restore actually starts, this catches an oversized upload before it
 * can eat a shared-hosting account's disk quota.
 *
 * @return array{free: int, required: int, ok: bool}
 */
function wpcb_check_upload_disk_space($fileSize, $dir)
{
    // 15% margin, same reasoning as wpcb_check_restore_disk_space().
    $required = (int) ($fileSize * 1.15);

    $freeRaw = @disk_free_space($dir);
    $unknown = ($freeRaw === false);
    $free = $unknown ? 0 : (int) $freeRaw;

    return [
        'free'     => $free,
        'required' => $required,
        'ok'       => $unknown ? true : ($free >= $required)
    ];
}

/**
 * Write protective .htaccess/web.config/index.php into $dir if missing -
 * blocks direct web access to backups/temp/logs (uploads/ is served
 * directly by default, no plugin checks apply there). Rules cascade
 * to subdirs, so one call on the top-level data folder covers everything
 * under it.
 *
 * Each file only covers the server that reads it: .htaccess is Apache
 * (and LiteSpeed in Apache-compatible mode), web.config is IIS, and
 * index.php only stops directory listing - none of them protect a
 * direct request for a known filename under Nginx.
 *
 * These are therefore secondary, never the security boundary. The
 * boundary is wpcb_storage_is_private(): storage must be outside the
 * served tree, or proven unreadable over HTTP, before a backup is
 * created at all. Where these rules do work they are one more layer,
 * and where they silently fail the canary check notices.
 */
function wpcb_protect_directory($dir)
{
    if (!is_dir($dir)) {
        return;
    }

    /*
     * The three writes below don't check their result, unlike every
     * other write in this plugin, and deliberately so.
     *
     * Whether these files were written is the wrong question; whether
     * the folder is actually reachable over HTTP is the right one, and
     * wpcb_storage_is_private() answers that directly by fetching a
     * canary from it. A failed .htaccess write therefore does not go
     * unnoticed - it shows up as the folder testing public, and backups
     * are refused. Checking the return value here would report the
     * proxy while the real check reports the fact.
     *
     * The log is also unavailable as somewhere to report to:
     * WPCB_Logger's constructor calls this function, so writing to it
     * from here would recurse.
     */

    $htaccess = $dir . '/.htaccess';

    if (!file_exists($htaccess)) {

        file_put_contents(
            $htaccess,
            "# Generated by rebuzz-backup-and-restore - deny direct access.\n" .
            "<IfModule mod_authz_core.c>\n" .
            "    Require all denied\n" .
            "</IfModule>\n" .
            "<IfModule !mod_authz_core.c>\n" .
            "    Order allow,deny\n" .
            "    Deny from all\n" .
            "</IfModule>\n"
        );
    }

    $webConfig = $dir . '/web.config';

    if (!file_exists($webConfig)) {

        file_put_contents(
            $webConfig,
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n" .
            "<configuration>\n" .
            "    <system.webServer>\n" .
            "        <rewrite>\n" .
            "            <rules>\n" .
            "                <rule name=\"rebuzz-backup-and-restore - block direct access\" stopProcessing=\"true\">\n" .
            "                    <match url=\".*\" />\n" .
            "                    <action type=\"CustomResponse\" statusCode=\"403\" statusReason=\"Forbidden\" statusDescription=\"Access Denied\" />\n" .
            "                </rule>\n" .
            "            </rules>\n" .
            "        </rewrite>\n" .
            "    </system.webServer>\n" .
            "</configuration>\n"
        );
    }

    // Stops a directory listing on hosts that have indexes enabled and
    // ignore the two files above. It cannot protect the archives
    // themselves - see this function's docblock for what does.
    $index = $dir . '/index.php';

    if (!file_exists($index)) {

        // Kept to the conventional comment-only file WordPress core
        // itself writes. It carries no executable statement on purpose:
        // it cannot protect the archives anyway, so there is no reason
        // for this plugin to generate working PHP in order to write it.
        file_put_contents(
            $index,
            "<?php\n" .
            "// Silence is golden.\n"
        );
    }
}

/**
 * Total size in bytes of everything under $dir (recursive). Used by
 * dashboard to show temp workspace usage - can be large if a backup
 * failed partway through (see wpcb_clear_directory()).
 */
function wpcb_directory_size($dir)
{
    if (!is_dir($dir)) {
        return 0;
    }

    $size = 0;

    foreach (scandir($dir) as $item) {

        if ($item === '.' || $item === '..') {
            continue;
        }

        $path = $dir . '/' . $item;

        if (is_link($path)) {
            continue;
        }

        if (is_dir($path)) {
            $size += wpcb_directory_size($path);
        } else {
            $fileSize = filesize($path);
            $size += ($fileSize !== false) ? $fileSize : 0;
        }
    }

    return $size;
}

/**
 * Delete everything inside $dir (not $dir itself); returns bytes freed.
 * Used to clear temp/ from wp-admin (see WPCB_Admin::clear_temp()).
 * Never follows a symlink into its target, only removes the link.
 */
function wpcb_clear_directory($dir)
{
    if (!is_dir($dir)) {
        return 0;
    }

    $freed = 0;

    foreach (scandir($dir) as $item) {

        if ($item === '.' || $item === '..') {
            continue;
        }

        $freed += wpcb_delete_path($dir . '/' . $item);
    }

    return $freed;
}

/**
 * Recursively delete a file/symlink/dir; returns bytes freed.
 * Helper for wpcb_clear_directory().
 */
function wpcb_delete_path($path)
{
    if (is_link($path)) {
        wp_delete_file($path);
        return 0;
    }

    if (is_dir($path)) {

        $freed = 0;

        foreach (scandir($path) as $item) {

            if ($item === '.' || $item === '..') {
                continue;
            }

            $freed += wpcb_delete_path($path . '/' . $item);
        }

        rmdir($path);

        return $freed;
    }

    $size = filesize($path);

    wp_delete_file($path);

    /*
     * wp_delete_file() returns nothing, so success is confirmed by
     * re-checking. Counting a file that is still on disk would make
     * "Clear temporary files" report space it never actually freed -
     * on a site that is short on disk (the situation that motivates
     * clearing in the first place) that is exactly the wrong thing to
     * be wrong about.
     */
    if (file_exists($path)) {
        return 0;
    }

    return $size !== false ? $size : 0;
}

/**
 * Other backup plugins' storage folders under wp-content - kept in
 * sync with WPCB_FileScanner::$excludedPaths. Lets the dashboard find
 * and offer to clear old leftovers from before that exclusion existed.
 */
function wpcb_foreign_backup_paths()
{
    return [
        'ai1wm-backups'    => 'All-in-One WP Migration',
        'updraft'          => 'UpdraftPlus',
        'backups-dup-lite' => 'Duplicator',
        'backups-dup-pro'  => 'Duplicator Pro',
        'wpvivid_backup'   => 'WPvivid',
        'aiowps_backups'   => 'All In One WP Security',
    ];
}

/**
 * Backup storage other plugins keep inside the uploads folder, and the
 * folders a 1.4.0 pre-release of this plugin left behind there.
 *
 * Kept separate from wpcb_foreign_backup_paths() because these are
 * matched as name prefixes: the folder carries a per-install hash or
 * timestamp, so no fixed path can name it. Mirrors
 * WPCB_FileScanner::$excludedUploadPrefixes.
 *
 * @return array prefix => label
 */
function wpcb_foreign_upload_backup_prefixes()
{
    return [
        'backwpup-'            => 'BackWPup',
        'backupbuddy_backups'  => 'BackupBuddy',
        'duplicator'           => 'Duplicator',
        'wp-migrate-db'        => 'WP Migrate DB',
        'wpvivid'              => 'WPvivid',
        'ai1wm-'               => 'All-in-One WP Migration',
        // Storage folders a 1.4.0 pre-release created under a random
        // name. Reported so a site that ran it can see - and reclaim -
        // archives it would otherwise have no way to find from wp-admin.
        'rebuzz-backup-and-restore-' => 'ReBuzz Backup (old 1.4.0 pre-release folder)',
    ];
}

/**
 * Which wpcb_foreign_backup_paths() exist on this site, and their size.
 *
 * @return array {path, label, size} per folder that actually exists.
 */
function wpcb_detect_foreign_backups()
{
    $found = [];

    foreach (wpcb_foreign_backup_paths() as $relative => $label) {

        $path = WP_CONTENT_DIR . '/' . $relative;

        if (!is_dir($path)) {
            continue;
        }

        $found[] = [
            'path'  => $path,
            'label' => $label,
            'size'  => wpcb_directory_size($path)
        ];
    }

    /*
     * Then the uploads folder, matched by prefix. Without this, storage
     * that lives under uploads rather than wp-content - which is where
     * BackWPup, BackupBuddy and this plugin's own 1.4.0 pre-release put
     * it - is invisible to an administrator who has no FTP or file
     * manager, even while it is the largest thing on the site.
     */
    $upload = wp_upload_dir();

    if (!empty($upload['basedir']) && is_dir($upload['basedir'])) {

        $current = wpcb_data_dirname();

        foreach (scandir($upload['basedir']) as $entry) {

            if ($entry === '.' || $entry === '..' || $entry === $current) {
                continue;
            }

            $path = $upload['basedir'] . '/' . $entry;

            if (!is_dir($path) || is_link($path)) {
                continue;
            }

            foreach (wpcb_foreign_upload_backup_prefixes() as $prefix => $label) {

                if (strpos($entry, $prefix) !== 0) {
                    continue;
                }

                $found[] = [
                    'path'  => $path,
                    'label' => $label . ' (' . $entry . ')',
                    'size'  => wpcb_directory_size($path)
                ];

                break;
            }
        }
    }

    return $found;
}

/**
 * Every directory a restore needs to write into. Checked before start,
 * since failing partway (files restored, DB not, or vice versa) is
 * much worse than refusing to start.
 *
 * @return array label => dir, for each one that isn't writable.
 */
function wpcb_check_restore_write_permissions()
{
    $upload = wp_upload_dir();

    $directories = [
        'wp-content'    => WP_CONTENT_DIR,
        'plugins'       => WP_PLUGIN_DIR,
        'themes'        => get_theme_root(),
        'uploads'       => $upload['basedir'],
        'site root'     => ABSPATH
    ];

    $failed = [];

    foreach ($directories as $label => $dir) {

        if (!is_dir($dir) || !is_writable($dir)) {
            $failed[$label] = $dir;
        }
    }

    return $failed;
}

/**
 * Estimated extra disk space a restore needs beyond the ZIP itself
 * (extracted workspace + margin). Reads sizes from the central
 * directory only, so stays fast even with many entries.
 *
 * Catches an obviously-full disk, and nothing more. It is not a
 * guarantee, because disk_free_space() reports the whole partition:
 * on shared hosting that is routinely hundreds of gigabytes while the
 * account itself is capped far lower, and an account over its quota
 * gets a cheerful "plenty of room" from this function moments before
 * every write fails. The same blind spot applies to inode limits,
 * which this cannot see at all.
 *
 * So a pass here means "not obviously impossible", not "this will
 * work". What closes that gap before a restore starts is
 * wpcb_probe_restore_storage(), which writes real bytes and real files
 * instead of trusting this arithmetic; and what reports a genuine
 * out-of-space condition once a restore is under way is
 * WPCB_Extractor::extractionFailureReason(), which reads the real
 * error from the failed write.
 *
 * @return array{uncompressed: int, free: int, required: int, ok: bool,
 *               entries: int, measured: bool}
 */
function wpcb_check_restore_disk_space($zipPath)
{
    $zip = new ZipArchive();

    $uncompressed = 0;
    $entries = 0;

    if ($zip->open($zipPath) === true) {

        // Captured while the archive is already open, so the probe that
        // runs next can scale itself to the archive without paying for a
        // second open.
        $entries = (int) $zip->numFiles;

        for ($i = 0; $i < $zip->numFiles; $i++) {

            $stat = $zip->statIndex($i);

            if ($stat !== false) {
                $uncompressed += (int) $stat['size'];
            }
        }

        $zip->close();
    }

    // 15% margin - filesystem block overhead adds up across many files.
    $required = (int) ($uncompressed * 1.15);

    // Measured at the restore workspace's real location, not ABSPATH - on
    // hosts where it is a separate mount (containerized deployments, a
    // custom UPLOADS constant, network/object storage), that's a different
    // filesystem than the code directory, and the extracted files land
    // there, not under ABSPATH.
    //
    // That location is wpcb_data_dir(), not wp_upload_dir()'s basedir:
    // WPCB_Restore_Workspace builds its directory under the former, and
    // when WPCB_BACKUP_DIR is defined - the arrangement the readme
    // actively recommends for Nginx - the two are different directories
    // and can be different mounts. Measuring the wrong one reports free
    // space for a filesystem the restore never writes to.
    //
    // false (not 0) means disk_free_space() couldn't measure - treat
    // as passing. An actual 0 means disk is really full - should fail.
    $restoreDir = wpcb_data_dir();

    if (!is_dir($restoreDir)) {
        $upload = wp_upload_dir();
        $restoreDir = !empty($upload['basedir']) ? $upload['basedir'] : ABSPATH;
    }

    $freeRaw = @disk_free_space($restoreDir);
    $unknown = ($freeRaw === false);
    $free = $unknown ? 0 : (int) $freeRaw;

    return [
        'uncompressed' => $uncompressed,
        'free'         => $free,
        'required'     => $required,
        'ok'           => $unknown ? true : ($free >= $required),
        'entries'      => $entries,
        // Whether 'free' is a real measurement or a placeholder zero, so
        // the caller can log why this check passed.
        'measured'     => !$unknown
    ];
}

/**
 * What the restore storage probe asks the filesystem for. Both stay well
 * under one extraction batch (WPCB_Extractor::BATCH_SIZE, 2000 entries), so
 * anything failing the probe would have failed seconds into extracting
 * anyway - raising them risks blocking restores that would have worked.
 * Override in wp-config.php; WPCB_RESTORE_PROBE_FILES of 0 disables it.
 */
if (!defined('WPCB_RESTORE_PROBE_BYTES')) {
    define('WPCB_RESTORE_PROBE_BYTES', 4194304); // 4MB
}

if (!defined('WPCB_RESTORE_PROBE_FILES')) {
    define('WPCB_RESTORE_PROBE_FILES', 200);
}

/** error_get_last()'s message, or '' when there isn't one. */
function wpcb_last_error_text()
{
    $error = error_get_last();

    return isset($error['message']) ? (string) $error['message'] : '';
}

/**
 * How many files and folders restoring $zipPath creates, read from
 * manifest.json without extracting. Falls back to the archive's own entry
 * count for pre-1.4 backups, which carry no statistics block.
 *
 * @return array{files: int, directories: int, total: int, source: string}
 */
function wpcb_backup_inode_estimate($zipPath, $entries = 0)
{
    $files = 0;
    $directories = 0;
    $source = 'unknown';

    $inspector = new WPCB_Backup_Inspector();
    $result = $inspector->inspect($zipPath);

    if (!empty($result['success']) && isset($result['manifest']['statistics'])) {

        $stats = $result['manifest']['statistics'];

        $files = isset($stats['files']) ? (int) $stats['files'] : 0;
        $directories = isset($stats['directories']) ? (int) $stats['directories'] : 0;

        if ($files > 0) {
            $source = 'manifest';
        }
    }

    // No usable manifest: every ZIP entry becomes at least one inode.
    if ($files < 1 && $entries > 0) {
        $files = (int) $entries;
        $source = 'archive';
    }

    return [
        'files'       => $files,
        'directories' => $directories,
        'total'       => $files + $directories,
        'source'      => $source
    ];
}

/**
 * Whether this account can actually write what a restore needs, tested
 * rather than calculated. is_writable() and disk_free_space() both pass on
 * a shared account that is over its storage or inode quota; writing real
 * bytes and creating real files does not.
 *
 * @return array{status: string, kind: string, bytes: int, expected: int,
 *               files: int, attempted: int, dir: string, error: string}
 *               status 'unknown' never blocks a restore - a check that
 *               could not run proves nothing.
 */
function wpcb_probe_restore_storage($expectedEntries = 0)
{
    $unknown = [
        'status'    => 'unknown',
        'kind'      => '',
        'bytes'     => 0,
        'expected'  => (int) WPCB_RESTORE_PROBE_BYTES,
        'files'     => 0,
        'attempted' => 0,
        'dir'       => '',
        'error'     => ''
    ];

    if ((int) WPCB_RESTORE_PROBE_FILES < 1) {
        return $unknown;
    }

    $base = wpcb_data_dir();

    // preflight-*, never restore-*: wpcb_restore_workspace_dirs() globs the
    // latter and would sweep this away mid-probe as failed-restore debris.
    foreach (glob($base . '/preflight-*', GLOB_ONLYDIR) ?: [] as $stale) {
        wpcb_delete_path($stale);
    }

    $dir = $base . '/preflight-' . wp_generate_password(12, false, false);

    // A directory that cannot be created is outside this probe's competence;
    // WPCB_Restore_Workspace fails on it at step 0 for free anyway.
    if (!wp_mkdir_p($dir)) {
        $unknown['dir'] = $dir;
        $unknown['error'] = wpcb_last_error_text();

        return $unknown;
    }

    $result = wpcb_run_restore_storage_probe($dir, $expectedEntries);

    // Every worker path returns through here, so none of them can skip cleanup.
    wpcb_delete_path($dir);

    return $result;
}

/**
 * The probe itself. How far it gets is the diagnosis: a refused open means
 * the folder rejects writes outright, a short write means no room, and small
 * files failing after a clean 4MB write means a file-count limit. Matching
 * errno text would be locale-dependent; this is not.
 */
function wpcb_run_restore_storage_probe($dir, $expectedEntries)
{
    $started = microtime(true);
    $budget = 3.0;
    $target = (int) WPCB_RESTORE_PROBE_BYTES;

    $result = [
        'status'    => 'failed',
        'kind'      => 'write',
        'bytes'     => 0,
        'expected'  => $target,
        'files'     => 0,
        'attempted' => 0,
        'dir'       => $dir,
        'error'     => ''
    ];

    error_clear_last();

    $path = $dir . '/bytes.tmp';

    // phpcs:disable WordPress.WP.AlternativeFunctions -- probing the real filesystem is the point; WP_Filesystem would abstract away the failure being measured.
    $handle = @fopen($path, 'wb');

    if ($handle === false) {
        $result['error'] = wpcb_last_error_text();

        return $result;
    }

    $chunk = str_repeat('0', 1048576);
    $written = 0;
    $short = false;

    while ($written < $target) {

        $take = min(strlen($chunk), $target - $written);
        $bytes = @fwrite($handle, substr($chunk, 0, $take));

        if ($bytes === false || $bytes !== $take) {
            $short = true;
            break;
        }

        $written += $bytes;
    }

    @fflush($handle);
    $closed = @fclose($handle);
    // phpcs:enable WordPress.WP.AlternativeFunctions

    clearstatcache(true, $path);
    $onDisk = @filesize($path);
    $result['bytes'] = ($onDisk === false) ? 0 : (int) $onDisk;

    if ($short || !$closed || $result['bytes'] !== $target) {
        $result['kind'] = 'space';
        $result['error'] = wpcb_last_error_text();

        return $result;
    }

    // Three levels deep: the failure this exists to catch hit a deep vendor
    // path, and mkdir is what fails first once inodes run out.
    $deep = $dir . '/deep/a/b';

    if (!wp_mkdir_p($deep)) {
        $result['kind'] = 'inode';
        $result['error'] = wpcb_last_error_text();

        return $result;
    }

    $want = min(
        (int) WPCB_RESTORE_PROBE_FILES,
        max(20, (int) ceil(((int) $expectedEntries) / 500))
    );

    $result['attempted'] = $want;

    for ($i = 0; $i < $want; $i++) {

        // A slow host is not a full one - running out of time passes.
        if ((microtime(true) - $started) >= $budget) {
            break;
        }

        if (@file_put_contents($deep . '/p' . $i, 'x') !== 1) {
            $result['kind'] = 'inode';
            $result['files'] = $i;
            $result['error'] = wpcb_last_error_text();

            return $result;
        }

        $result['files'] = $i + 1;
    }

    $result['status'] = 'ok';
    $result['kind'] = '';

    return $result;
}

/**
 * The refusal shown when wpcb_probe_restore_storage() fails, phrased in
 * terms of what the person can check rather than errno terms.
 */
function wpcb_restore_storage_probe_message($probe, $inodes, $space)
{
    $lead = __('This restore would almost certainly fail part-way through, so it has not been started.', 'rebuzz-backup-and-restore');

    // True at every call site: the refusal happens before the job is created,
    // and extraction stages under uploads rather than over the live site.
    $safe = __('Nothing on your site has been changed.', 'rebuzz-backup-and-restore');

    if ($probe['kind'] === 'space') {

        return $lead . "\n\n" . sprintf(
            /* translators: 1: amount actually written, 2: amount the test tried to write */
            __('A test write into the plugin\'s storage folder stopped after %1$s of %2$s. That is what happens when a hosting account has reached its storage quota. Note that a host often reports far more free space than your account is actually allowed to use.', 'rebuzz-backup-and-restore'),
            size_format($probe['bytes']),
            size_format($probe['expected'])
        ) . "\n\n" . sprintf(
            /* translators: %s: free space the restore needs */
            __('Restoring this backup needs roughly %s of free space.', 'rebuzz-backup-and-restore'),
            size_format($space['required'])
        ) . ' ' . $safe;
    }

    if ($probe['kind'] === 'inode') {

        $message = $lead . "\n\n" . sprintf(
            /* translators: 1: test files created, 2: test files attempted */
            __('A test write into the plugin\'s storage folder created only %1$s of %2$s small test files. Disk space and folder permissions both tested fine, which points at this hosting account reaching its file-count limit - cPanel calls this "File Usage" or "Inodes".', 'rebuzz-backup-and-restore'),
            number_format_i18n($probe['files']),
            number_format_i18n($probe['attempted'])
        );

        // Omitted rather than printed as zero for backups with no statistics.
        if ($inodes['files'] > 0 && $inodes['directories'] > 0) {

            $message .= "\n\n" . sprintf(
                /* translators: 1: number of files in the backup, 2: number of folders */
                __('This backup contains %1$s files and %2$s folders, and a restore writes them twice: once into a staging folder, then once into the live site.', 'rebuzz-backup-and-restore'),
                number_format_i18n($inodes['files']),
                number_format_i18n($inodes['directories'])
            );

        } elseif ($inodes['files'] > 0) {

            $message .= "\n\n" . sprintf(
                /* translators: %s: number of files in the backup */
                __('This backup contains %s files, and a restore writes them twice: once into a staging folder, then once into the live site.', 'rebuzz-backup-and-restore'),
                number_format_i18n($inodes['files'])
            );
        }

        return $message . "\n\n" . $safe . ' ' . __('Check your account\'s file usage against its limit in your hosting control panel, delete files you no longer need, then try again.', 'rebuzz-backup-and-restore');
    }

    return sprintf(
        /* translators: %s: absolute path to the plugin's storage folder */
        __('This restore has not been started: the plugin\'s storage folder (%s) passed a permissions check but could not actually be written to.', 'rebuzz-backup-and-restore'),
        $probe['dir']
    ) . "\n\n" . __('A folder can look writable and still reject every write when the hosting account is out of disk space or has reached its file limit. Check both in your hosting control panel, then try again.', 'rebuzz-backup-and-restore') . ' ' . $safe;
}

/**
 * Every restore-* workspace directory under rebuzz-backup-and-restore/ still
 * on disk (see WPCB_Restore_Workspace) - these are siblings of temp/,
 * not inside it, so clear_temp() alone never reaches them, and a
 * failed/interrupted restore deliberately leaves its workspace behind
 * for post-mortem inspection with no other UI path to reclaim it.
 *
 * @return string[] Absolute paths.
 */
function wpcb_restore_workspace_dirs()
{
    return glob(wpcb_data_dir() . '/restore-*', GLOB_ONLYDIR) ?: [];
}

/**
 * Delete every restore-* workspace directory except one actively in
 * progress right now (if any - never touch a live restore's files).
 * Returns bytes freed.
 */
function wpcb_clear_stale_restore_workspaces()
{
    $activeWorkspace = null;
    $activeJobId = wpcb_restore_lock_check();

    if ($activeJobId !== null) {

        $job = new WPCB_Job($activeJobId);
        $state = $job->get();

        if (!empty($state['workspace'])) {
            $activeWorkspace = realpath($state['workspace']);
        }
    }

    $freed = 0;

    foreach (wpcb_restore_workspace_dirs() as $dir) {

        if ($activeWorkspace !== null && realpath($dir) === $activeWorkspace) {
            continue;
        }

        $freed += wpcb_delete_path($dir);
    }

    return $freed;
}

/**
 * Where the restore lock file lives - see wpcb_restore_lock_acquire().
 *
 * On multisite this is always resolved from the network's primary
 * site, never the current site. A restore's temporarilyDisableAllMuPlugins()
 * and the auto-update guard file both operate on wp-content/mu-plugins,
 * which is network-wide shared, not scoped per site like wp_upload_dir()
 * normally is. If the lock stayed per-site, two different subsites
 * could each pass their own lock check and restore at once, each
 * renaming/reactivating the same shared mu-plugins out from under the
 * other. Routing every site's lock to one shared location serializes
 * restores network-wide, which is what a shared resource requires.
 */
function wpcb_restore_lock_path()
{
    /*
     * Cached per request: this is now consulted on every page load, by
     * wpcb_restore_guard_active() and by the mu-plugin recovery pass,
     * and on multisite resolving it means a switch_to_blog() round
     * trip. Resolve it at most once.
     */
    static $path = null;

    if ($path !== null) {
        return $path;
    }

    $configured = wpcb_configured_data_dir();

    if ($configured !== '') {

        // One lock for the whole network, so it sits at the root of the
        // configured directory rather than in any one site's subfolder.
        $path = $configured . '/restore.lock';

        return $path;
    }

    $upload = wpcb_restore_lock_upload_dir();

    $path = $upload['basedir'] . '/' . wpcb_data_dirname() . '/restore.lock';

    return $path;
}

/**
 * wp_upload_dir(), but always for the network's primary site on
 * multisite - see wpcb_restore_lock_path() for why.
 */
function wpcb_restore_lock_upload_dir()
{
    if (!is_multisite()) {
        return wp_upload_dir();
    }

    $mainSiteId = function_exists('get_main_site_id') ? get_main_site_id() : 1;

    switch_to_blog($mainSiteId);
    $upload = wp_upload_dir();
    restore_current_blog();

    return $upload;
}

/**
 * Is a restore genuinely in progress - lock file exists AND its job
 * still reports "running" (a stale lock from a finished/expired job
 * shouldn't block future restores forever).
 *
 * @return string|null Other job's ID if active, else null.
 */
function wpcb_restore_lock_check()
{
    $path = wpcb_restore_lock_path();

    if (!file_exists($path)) {
        return null;
    }

    $jobId = trim((string) file_get_contents($path));

    if ($jobId === '') {
        return null;
    }

    $job = new WPCB_Job($jobId);
    $state = $job->get();

    if (($state['status'] ?? '') === 'running') {
        return $jobId;
    }

    return null;
}

/**
 * Write $jobId into the lock file at $path.
 *
 * The write is checked because a lock file that exists but is empty is
 * worse than no lock at all: wpcb_restore_lock_check() and
 * wpcb_backup_lock_check() both read the job ID out of it and treat an
 * empty file as "nothing running", so a silently failed write would let
 * a second backup or restore start alongside the first and have the two
 * write over each other.
 *
 * @param resource|null $handle An already-open handle to write through,
 *                              or null to write $path directly.
 * @return bool True if the whole ID reached disk.
 */
function wpcb_lock_write($path, $jobId, $handle = null)
{
    if ($handle === null) {

        $written = file_put_contents($path, $jobId);

        return ($written !== false && $written === strlen($jobId));
    }

    $written = fwrite($handle, $jobId);

    // fclose() flushes, so a failure can surface there rather than above.
    $closed = fclose($handle);

    return ($written !== false && $written === strlen($jobId) && $closed);
}

/**
 * Claim the restore lock for $jobId, overwriting a stale lock.
 * fopen(..., 'x') is atomic create-if-not-exists, closing the race
 * where two requests both pass wpcb_restore_lock_check() first.
 *
 * @return bool True if claimed; false if another active restore holds
 *              it, or if the lock couldn't be written (refusing to
 *              start unlocked is the safe direction - see
 *              wpcb_lock_write()).
 */
function wpcb_restore_lock_acquire($jobId)
{
    $path = wpcb_restore_lock_path();

    wp_mkdir_p(dirname($path));

    $handle = @fopen($path, 'x');

    if ($handle === false) {

        // Existing lock: only safe to take over if it's stale.
        if (wpcb_restore_lock_check() !== null) {
            return false;
        }

        if (!wpcb_lock_write($path, $jobId)) {
            return false;
        }

    } elseif (!wpcb_lock_write($path, $jobId, $handle)) {

        // Remove the empty file this left behind, so the next attempt
        // gets a clean atomic create rather than falling into the
        // stale-lock branch above.
        if (file_exists($path)) {
            wp_delete_file($path);
        }

        return false;
    }

    // No separate auto-updater flag to set: wpcb_restore_guard_active()
    // reads this very lock file, so claiming the lock arms the guard
    // and releasing it disarms the guard, with no second piece of state
    // that could drift out of step with this one.

    return true;
}

/**
 * Release the restore lock. Safe to call even if no lock is held.
 * Also disarms the auto-updater guard - see wpcb_restore_guard_active().
 */
function wpcb_restore_lock_release()
{
    $path = wpcb_restore_lock_path();

    if (file_exists($path)) {
        wp_delete_file($path);
    }
}

/**
 * Where the backup lock file lives - see wpcb_backup_lock_acquire().
 * Site-scoped (unlike the restore lock) since a backup only ever
 * touches this site's own files/database, nothing network-shared.
 */
function wpcb_backup_lock_path()
{
    return wpcb_data_dir() . '/backup.lock';
}

/**
 * Is a backup genuinely in progress - lock file exists AND its job
 * still reports "running" (a stale lock from a finished/expired job
 * shouldn't block future backups forever).
 *
 * @return string|null Other job's ID if active, else null.
 */
function wpcb_backup_lock_check()
{
    $path = wpcb_backup_lock_path();

    if (!file_exists($path)) {
        return null;
    }

    $jobId = trim((string) file_get_contents($path));

    if ($jobId === '') {
        return null;
    }

    $job = new WPCB_Job($jobId);
    $state = $job->get();

    if (($state['status'] ?? '') === 'running') {
        return $jobId;
    }

    return null;
}

/**
 * Claim the backup lock for $jobId, overwriting a stale lock. Same
 * atomic create-if-not-exists pattern as wpcb_restore_lock_acquire(),
 * closing the same race where two requests both pass
 * wpcb_backup_lock_check() first.
 *
 * @return bool True if claimed; false if another active backup holds
 *              it, or if the lock couldn't be written - see
 *              wpcb_restore_lock_acquire(), which mirrors this exactly.
 */
function wpcb_backup_lock_acquire($jobId)
{
    $path = wpcb_backup_lock_path();

    wp_mkdir_p(dirname($path));

    $handle = @fopen($path, 'x');

    if ($handle === false) {

        if (wpcb_backup_lock_check() !== null) {
            return false;
        }

        if (!wpcb_lock_write($path, $jobId)) {
            return false;
        }

    } elseif (!wpcb_lock_write($path, $jobId, $handle)) {

        if (file_exists($path)) {
            wp_delete_file($path);
        }

        return false;
    }

    return true;
}

/**
 * Release the backup lock. Safe to call even if no lock is held.
 */
function wpcb_backup_lock_release()
{
    $path = wpcb_backup_lock_path();

    if (file_exists($path)) {
        wp_delete_file($path);
    }
}

/**
 * How long the restore guard flag stays honoured. A restore that dies
 * without releasing its lock (host kill, OOM) would otherwise leave
 * WordPress's auto-updater switched off indefinitely, which is a
 * security regression far worse than the maintenance-mode collision
 * the guard exists to prevent. Well past any realistic restore.
 */
if (!defined('WPCB_RESTORE_GUARD_MAX_SECONDS')) {
    define('WPCB_RESTORE_GUARD_MAX_SECONDS', 6 * HOUR_IN_SECONDS);
}

/**
 * Whether a restore is currently running and the auto-updater should
 * stay out of its way - see wpcb_register_restore_guard_filters().
 *
 * Derived from the restore lock file rather than from an option, and
 * deliberately so. The lock file already has exactly this flag's
 * lifetime (created by wpcb_restore_lock_acquire(), removed by
 * wpcb_restore_lock_release()), it already lives in the network-shared
 * location this needs, and above all it is on disk: a restore drops and
 * refills wp_options across many requests, so any option read during
 * the database import comes back empty. An option-backed flag would
 * therefore switch itself off during the import - the longest and
 * riskiest phase, and precisely when the auto-updater must stay away.
 */
function wpcb_restore_guard_active()
{
    $path = wpcb_restore_lock_path();

    // PHP caches stat results per request. This is normally read once,
    // early, but a request that also creates or releases the lock would
    // otherwise see the state from before it acted.
    clearstatcache(true, $path);

    if (!file_exists($path)) {
        return false;
    }

    // The lock's mtime is when the restore claimed it and does not move
    // while the restore runs, so this is genuinely "started at".
    $startedAt = @filemtime($path);

    if ($startedAt === false) {
        return false;
    }

    // A lock left behind by a killed restore must not hold WordPress's
    // auto-updater off forever; that would be a worse problem than the
    // maintenance-mode collision this exists to prevent.
    return ((time() - $startedAt) <= WPCB_RESTORE_GUARD_MAX_SECONDS);
}

/**
 * Hold WordPress's background auto-updater off for the duration of a
 * restore. Restoring core files while WP's own updater runs flips the
 * site into maintenance mode (503s) and breaks the restore's AJAX
 * polling - observed on this plugin's own test site.
 *
 * Registered on every request rather than only during restore_step(),
 * because the auto-updater runs from wp-cron.php, which loads its own
 * WordPress bootstrap. Active plugins are loaded in that bootstrap too,
 * so a filter registered here does apply there; only a filter added
 * inside the AJAX handler would have been missed. The filters
 * themselves are added only while a restore is actually running, so
 * nothing about the site's update behaviour changes at any other time.
 */
function wpcb_register_restore_guard_filters()
{
    if (!wpcb_restore_guard_active()) {
        return;
    }

    add_filter('automatic_updater_disabled', '__return_true');
    add_filter('auto_update_core', '__return_false');
    add_filter('allow_minor_auto_core_updates', '__return_false');
    add_filter('allow_major_auto_core_updates', '__return_false');
}

/** wp-content/mu-plugins, honouring a customized WPMU_PLUGIN_DIR. */
function wpcb_mu_plugins_dir()
{
    return defined('WPMU_PLUGIN_DIR') ? WPMU_PLUGIN_DIR : (WP_CONTENT_DIR . '/mu-plugins');
}

/**
 * Where the record of renamed-aside mu-plugins is kept: a file beside
 * the restore lock, so it shares the lock's network-shared location.
 */
function wpcb_disabled_mu_plugins_path()
{
    return dirname(wpcb_restore_lock_path()) . '/disabled-mu-plugins.json';
}

/**
 * Record which mu-plugins a restore has renamed to *.php.disabled.
 *
 * Kept in a file rather than an option, and separately from the job
 * state, for two reasons that both point the same way.
 *
 * A fatal (host kill, OOM) between the rename and the end of the
 * restore skips reactivateMuPlugins() entirely, and mu-plugins commonly
 * carry security-critical functionality that must not stay switched
 * off - so the recovery pass has to find them without knowing which job
 * renamed them, which rules out the job state.
 *
 * And it cannot live in the database: this list is written during a
 * restore, after the backup being restored was made, so it is not in
 * the dump. The import drops wp_options and refills it from the dump,
 * which would erase this record permanently - in the middle of exactly
 * the operation it exists to recover from.
 *
 * @param string[] $filenames
 */
function wpcb_record_disabled_mu_plugins(array $filenames)
{
    if (empty($filenames)) {

        wpcb_clear_recorded_disabled_mu_plugins();

        return;
    }

    $path = wpcb_disabled_mu_plugins_path();

    wp_mkdir_p(dirname($path));

    file_put_contents(
        $path,
        wp_json_encode(array_values(array_unique($filenames)))
    );
}

/** @return string[] Filenames recorded by wpcb_record_disabled_mu_plugins(). */
function wpcb_recorded_disabled_mu_plugins()
{
    $path = wpcb_disabled_mu_plugins_path();

    if (!file_exists($path)) {
        return [];
    }

    $recorded = json_decode((string) file_get_contents($path), true);

    return is_array($recorded) ? $recorded : [];
}

/** Forget the recorded list - everything in it is back in place. */
function wpcb_clear_recorded_disabled_mu_plugins()
{
    $path = wpcb_disabled_mu_plugins_path();

    if (file_exists($path)) {
        wp_delete_file($path);
    }
}

/**
 * Re-enable mu-plugins left renamed by a restore that died before it
 * could put them back. Registered on every request (see
 * WPCB_Loader::run()) and also called from the fatal-error shutdown
 * handler, so the site heals itself rather than silently running
 * without mu-plugins that were only ever meant to be off for the few
 * minutes a restore takes.
 *
 * Does nothing while a restore is genuinely in progress - those files
 * are supposed to be disabled right now, and reactivateMuPlugins()
 * will handle them, including the hosting mu-plugins it deliberately
 * leaves off on a local dev copy.
 *
 * @return string[] Filenames re-enabled.
 */
function wpcb_recover_disabled_mu_plugins()
{
    $recorded = wpcb_recorded_disabled_mu_plugins();

    if (empty($recorded)) {
        return [];
    }

    if (wpcb_restore_lock_check() !== null) {
        return [];
    }

    $muPluginsDir = wpcb_mu_plugins_dir();

    $restored = [];
    $stillDisabled = [];

    foreach ($recorded as $filename) {

        // basename(): the list is written by this plugin, but it ends
        // up in an option, and an option is never a safe source for a
        // path segment that gets concatenated into a filesystem write.
        $filename = basename((string) $filename);

        if ($filename === '') {
            continue;
        }

        $disabledPath = $muPluginsDir . '/' . $filename . '.disabled';

        if (!file_exists($disabledPath)) {
            continue;
        }

        if (@rename($disabledPath, $muPluginsDir . '/' . $filename)) {
            $restored[] = $filename;
        } else {
            $stillDisabled[] = $filename;
        }
    }

    // Keep only what genuinely could not be renamed back, so the next
    // request tries again instead of giving up on it.
    wpcb_record_disabled_mu_plugins($stillDisabled);

    if (!empty($restored) && class_exists('WPCB_Logger')) {

        (new WPCB_Logger('restore'))->log(sprintf(
            'Recovery: re-enabled %d mu-plugin(s) left disabled by an interrupted restore: %s',
            count($restored),
            implode(', ', $restored)
        ));
    }

    return $restored;
}
