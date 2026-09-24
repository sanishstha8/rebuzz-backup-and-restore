<?php

if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.WP.AlternativeFunctions -- download_backup() streams the archive in 8KB chunks; WP_Filesystem would buffer it all in memory.

class WPCB_Admin
{
    /**
     * Hook suffixes for our own admin pages, as WordPress actually
     * assigns them - populated by menu(), read by enqueue_assets().
     * Never hardcoded: a submenu's hook suffix is derived from the
     * top-level menu's *title text* (sanitized), not just its slug, so
     * a hardcoded guess silently goes stale the moment that title
     * changes (e.g. a rebrand) - admin.js/admin.css would stop loading
     * on the affected pages with no visible error, breaking every
     * button on them.
     *
     * @var string[]
     */
    private $pageHooks = [];

    /**
     * Bytes read per iteration when streaming an archive to the browser
     * - see download_backup(). Large enough that the per-read overhead
     * stops dominating, small enough that one chunk is never a
     * meaningful share of a modest memory_limit.
     */
    const DOWNLOAD_CHUNK_BYTES = 1048576; // 1MB

    public function __construct()
    {
        add_action('admin_menu', [$this, 'menu']);

    
        add_action('admin_init', [$this, 'register_settings']);

        add_action('admin_init', [$this, 'maybeUpgrade']);

        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);

        add_action('admin_post_wpcb_download_backup', [$this, 'download_backup']);

        add_action('wp_ajax_wpcb_start_backup', [$this, 'start_backup']);

        add_action('wp_ajax_wpcb_backup_step', [$this, 'backup_step']);

        add_action('admin_post_wpcb_upload_backup', [$this, 'upload_backup']);

        add_action('wp_ajax_wpcb_start_restore', [$this, 'start_restore']);

        add_action('wp_ajax_wpcb_restore_step', [$this, 'restore_step']);

        add_action('wp_ajax_wpcb_delete_backup', [$this, 'delete_backup']);

        add_action('wp_ajax_wpcb_clear_temp', [$this, 'clear_temp']);

        add_action('wp_ajax_wpcb_clear_foreign_backups', [$this, 'clear_foreign_backups']);

        // Left-over directories from a rename-then-replace restore -
        // shown on any admin screen, and deleted only when asked.
        add_action('admin_notices', [$this, 'old_directories_notice']);
        add_action('admin_post_wpcb_delete_old_directories', [$this, 'delete_old_directories']);
    }

    /** Admin menu */
    public function menu()
    {
        $this->pageHooks[] = add_menu_page(
            __('ReBuzz Backup and Restore', 'rebuzz-backup-and-restore'),
            __('ReBuzz Backup', 'rebuzz-backup-and-restore'),
            'manage_options',
            'wpcb-dashboard',
            [$this, 'dashboard'],
            wpcb_menu_icon(),
            26
        );

        $this->pageHooks[] = add_submenu_page(
            'wpcb-dashboard',
            __('Dashboard', 'rebuzz-backup-and-restore'),
            __('Dashboard', 'rebuzz-backup-and-restore'),
            'manage_options',
            'wpcb-dashboard',
            [$this, 'dashboard']
        );

        $this->pageHooks[] = add_submenu_page(
            'wpcb-dashboard',
            __('Restore', 'rebuzz-backup-and-restore'),
            __('Restore', 'rebuzz-backup-and-restore'),
            'manage_options',
            'wpcb-restore',
            [$this, 'restore']
        );

        $this->pageHooks[] = add_submenu_page(
            'wpcb-dashboard',
            __('Settings', 'rebuzz-backup-and-restore'),
            __('Settings', 'rebuzz-backup-and-restore'),
            'manage_options',
            'wpcb-settings',
            [$this, 'settings']
        );
    }

    /**
     * Run the per-site setup the activation hook does, when the
     * installed version has changed since it last ran.
     *
     * Updating a plugin in place never fires register_activation_hook(),
     * so nothing else would carry a pre-rebrand install's storage folder
     * over to the current name, and nothing would recreate the folders
     * or their access-blocking files if something had removed them.
     *
     * Runs on 'admin_init' rather than every request: it only matters
     * before an administrator uses the plugin, and the version check
     * ahead of it is a single autoloaded option read.
     */
    public function maybeUpgrade()
    {
        if (get_option('wpcb_version') === WPCB_VERSION) {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }

        wpcb_migrate_legacy_data_dir();

        wpcb_prepare_data_directories();

        update_option('wpcb_version', WPCB_VERSION);
    }

    /** Register settings */
    public function register_settings()
    {
        register_setting(
            'wpcb_settings_group',
            'wpcb_settings',
            [
                'type' => 'array',
                'sanitize_callback' => [$this, 'sanitize_settings'],
                'default' => ['include_core' => true],
            ]
        );
    }

    /** Sanitize the settings form's submitted value before it's saved. */
    public function sanitize_settings($input)
    {
        return [
            'include_core' => !empty($input['include_core']),
        ];
    }

    /** Load CSS/JS */
    public function enqueue_assets($hook)
    {
        // Our pages only - see $pageHooks docblock for why these are
        // never hardcoded strings.
        if (!in_array($hook, $this->pageHooks, true)) {
            return;
        }

        wp_enqueue_style(
            'wpcb-admin',
            WPCB_URL . 'assets/css/admin.css',
            [],
            WPCB_VERSION
        );

        wp_enqueue_script(
            'wpcb-admin',
            WPCB_URL . 'assets/js/admin.js',
            ['jquery'],
            WPCB_VERSION,
            true
        );

        wp_localize_script(
            'wpcb-admin',
            'wpcb',
            [
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce'    => wp_create_nonce('wpcb_backup')
            ]
        );
    }

    /** Dashboard page */
    public function dashboard()
    {
        include WPCB_PATH . 'admin/dashboard.php';
    }

    /** Settings page */
    public function settings()
    {
        include WPCB_PATH . 'admin/settings.php';
    }

    public function download_backup()
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('Permission denied.', 'rebuzz-backup-and-restore'));
    }

    check_admin_referer('wpcb_download');

    if (empty($_GET['file'])) {
        wp_die(esc_html__('No backup file specified.', 'rebuzz-backup-and-restore'));
    }

    $filename = sanitize_file_name(wp_unslash($_GET['file']));

    $backupDir = wpcb_backups_dir();

    $file = $backupDir . '/' . $filename;

    // Defense-in-depth vs path traversal (same as delete_backup()).
    $realBackupDir = realpath($backupDir);
    $realFile = realpath($file);

    if (
        $realBackupDir === false ||
        $realFile === false ||
        strpos($realFile, $realBackupDir . DIRECTORY_SEPARATOR) !== 0
    ) {
        wp_die(esc_html__('Backup file not found.', 'rebuzz-backup-and-restore'));
    }

    $file = $realFile;

    // Clear buffers
    while (ob_get_level()) {
        ob_end_clean();
    }

    if (headers_sent()) {
        wp_die(esc_html__('Headers already sent.', 'rebuzz-backup-and-restore'));
    }

    /*
     * A multi-hundred-megabyte archive over a domestic upload link takes
     * far longer than max_execution_time. Without this the script is
     * killed mid-transfer and the user is left with a truncated ZIP that
     * looks like a complete download.
     */
    wpcb_extend_time_limit(0);

    /*
     * A ZIP is already compressed, so gzipping it again costs CPU for
     * nothing - and it silently invalidates the Content-Length below,
     * which is what browsers use to show progress and to notice a short
     * download. Only touched when a host has actually turned it on.
     */
    if (ini_get('zlib.output_compression')) {
        // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- per-request output setting on a request that exits immediately; see above.
        @ini_set('zlib.output_compression', 'Off');
    }

    header('Content-Description: File Transfer');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . basename($file) . '"');
    header('Content-Transfer-Encoding: binary');
    header('Expires: 0');
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    header('Content-Length: ' . filesize($file));

    $handle = fopen($file, 'rb');

    if ($handle === false) {
        wp_die(esc_html__('Unable to open backup.', 'rebuzz-backup-and-restore'));
    }

    /*
     * 1MB per read, and no flush() between reads.
     *
     * This previously read 8KB at a time and flushed after every one,
     * which for a 600MB archive meant ~77,000 iterations each forcing a
     * write through the whole SAPI stack. Measured on a local Apache,
     * that ran at 132 MB/s against 1,900 MB/s for the same bytes served
     * as a static file - the loop, not the disk or the network, was the
     * limit. Larger reads and letting the server flush when it is ready
     * remove that overhead; echo already blocks once the socket buffer
     * is full, so memory stays flat however slow the client is.
     */
    while (!feof($handle)) {

        // Stop reading a 600MB file into a connection nobody is on the
        // other end of any more.
        if (connection_aborted()) {
            break;
        }

        $chunk = fread($handle, self::DOWNLOAD_CHUNK_BYTES);

        if ($chunk === false) {
            break;
        }

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary ZIP body, not markup; escaping would corrupt the archive.
        echo $chunk;
    }

    fclose($handle);
    exit;
}

/** Delete a backup ZIP */
public function delete_backup()
{
    check_ajax_referer('wpcb_backup', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Permission denied.', 'rebuzz-backup-and-restore'));
    }

    $filename = sanitize_file_name(wp_unslash($_POST['file'] ?? ''));

    if (empty($filename)) {
        wp_send_json_error(__('No backup file specified.', 'rebuzz-backup-and-restore'));
    }

    if (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'zip') {
        wp_send_json_error(__('Invalid backup file.', 'rebuzz-backup-and-restore'));
    }

    $backupDir = wpcb_backups_dir();

    $file = $backupDir . '/' . $filename;

    // Guard against path traversal.
    $realBackupDir = realpath($backupDir);
    $realFile = realpath($file);

    if (
        $realBackupDir === false ||
        $realFile === false ||
        strpos($realFile, $realBackupDir . DIRECTORY_SEPARATOR) !== 0
    ) {
        wp_send_json_error(__('Invalid backup file.', 'rebuzz-backup-and-restore'));
    }

    if (!file_exists($realFile)) {
        wp_send_json_error(__('Backup file not found.', 'rebuzz-backup-and-restore'));
    }

    // wp_delete_file() returns nothing, unlike PHP's unlink(), so success
    // is confirmed by re-checking rather than by a return value.
    wp_delete_file($realFile);

    if (file_exists($realFile)) {
        wp_send_json_error(__('Could not delete the backup file. Check file permissions.', 'rebuzz-backup-and-restore'));
    }

    wp_send_json_success([
        'file' => $filename
    ]);
}

/**
 * Clear temp/ (only way to reclaim space without FTP access).
 * Failed backups leave files here since finish() cleanup never runs.
 */
public function clear_temp()
{
    check_ajax_referer('wpcb_backup', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Permission denied.', 'rebuzz-backup-and-restore'));
    }

    $freed = wpcb_clear_directory(wpcb_temp_dir());

    // Restore workspaces live as siblings of temp/, not inside it - see
    // wpcb_clear_stale_restore_workspaces(). Swept here too so this is
    // the one button that reclaims all of this plugin's scratch space.
    $freed += wpcb_clear_stale_restore_workspaces();

    wp_send_json_success([
        'freed' => size_format($freed)
    ]);
}

/**
 * Clear other backup plugins' storage folders (no FTP needed).
 * Only contents cleared, not the folder, so it stays safe if in use.
 */
public function clear_foreign_backups()
{
    check_ajax_referer('wpcb_backup', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Permission denied.', 'rebuzz-backup-and-restore'));
    }

    // These folders (ai1wm-backups/, updraft/) live under WP_CONTENT_DIR,
    // which is shared network-wide on multisite - not scoped per site
    // like wp_upload_dir(). A per-site admin could otherwise delete
    // another site's (or the network's) backup archives. Only a
    // network super-admin may clear them there; single-site is
    // unaffected (isNetworkAdmin() always true).
    if (!(new WPCB_Multisite())->isNetworkAdmin()) {
        wp_send_json_error(__('Only a network administrator can clear these shared folders.', 'rebuzz-backup-and-restore'));
    }

    $freed = 0;

    foreach (wpcb_detect_foreign_backups() as $folder) {
        $freed += wpcb_clear_directory($folder['path']);
    }

    wp_send_json_success([
        'freed' => size_format($freed)
    ]);
}

public function restore()
{
    include WPCB_PATH . 'admin/restore.php';
}

public function start_backup()
{
    check_ajax_referer('wpcb_backup', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Permission denied.', 'rebuzz-backup-and-restore'));
    }

    /*
     * Refuse before doing any work, rather than spending ten minutes
     * building a complete copy of the database and then leaving it
     * somewhere the web server will hand to anyone who asks. See
     * wpcb_storage_is_private().
     */
    if (!wpcb_storage_is_private()) {
        wp_send_json_error(wpcb_storage_insecure_reason());
    }

    // Without this, a dropped connection that makes the JS re-enable
    // "Create Backup" (see admin.js's retry-exhaustion handling) while
    // the original job is still running server-side would let a second
    // job start and write into the same temp/backups directories
    // concurrently, corrupting either or both archives.
    $activeJobId = wpcb_backup_lock_check();

    if ($activeJobId !== null) {
        wp_send_json_error(
            sprintf(
                /* translators: %s: internal job ID of the backup that is already running */
                __('A backup is already running (job %s). Two backups writing to the same site at once would corrupt both - wait for it to finish, or check backup.log if you believe it is actually stuck.', 'rebuzz-backup-and-restore'),
                $activeJobId
            )
        );
    }

    $job = new WPCB_Job();

    if (!wpcb_backup_lock_acquire($job->id())) {
        wp_send_json_error(__('Could not start: either a backup is already running (wait for it to finish), or the lock file could not be written - check that the plugin\'s folder under uploads is writable and that the disk is not full.', 'rebuzz-backup-and-restore'));
    }

    $job->update([
        'status' => 'running',
        'step' => 0,
        'progress' => 0,
        'message' => __('Starting backup...', 'rebuzz-backup-and-restore')
    ]);

    wp_send_json_success([
        'job_id' => $job->id()
    ]);
}

public function backup_step()
{
    check_ajax_referer('wpcb_backup', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Permission denied.', 'rebuzz-backup-and-restore'));
    }

    $jobId = sanitize_text_field(wp_unslash($_POST['job_id'] ?? ''));

    if (empty($jobId)) {
        wp_send_json_error(__('Missing job ID.', 'rebuzz-backup-and-restore'));
    }

    $job = new WPCB_Job($jobId);

    // job_id alone isn't access control; enforce ownership.
    if (!$job->isOwnedBy(get_current_user_id())) {
        wp_send_json_error(__('Permission denied.', 'rebuzz-backup-and-restore'));
    }

    // See restore_step()'s identical guards.
    if (($job->get()['status'] ?? '') === 'failed') {
        wp_send_json_success($job->getPublic());
    }

    if ($job->isProcessing()) {
        wp_send_json_success($job->getPublic());
    }

    $job->markProcessing();

    $backup = new WPCB_Backup_Job($job);

    $this->guardAgainstFatalError($job, 'backup');

    $this->runStepBuffered(function () use ($backup) {
        return $backup->processNextStep();
    }, 'backup');

    $job->clearProcessing();

    wp_send_json_success(
        $job->getPublic()
    );
}

/** Validate an uploaded backup ZIP, store it, redirect to restore. */
public function upload_backup()
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('Permission denied.', 'rebuzz-backup-and-restore'));
    }

    // If POST exceeds post_max_size, PHP silently drops $_POST/$_FILES
    // (nonce too), so check Content-Length first to surface the real cause.
    if (empty($_POST) && empty($_FILES) && isset($_SERVER['CONTENT_LENGTH'])) {

        // The (int) cast is the sanitizer: this is only ever compared as
        // a number, and casting leaves nothing of an attacker-supplied
        // header behind. Nothing further is needed.
        $contentLength = (int) $_SERVER['CONTENT_LENGTH'];
        $maxUploadBytes = wpcb_max_upload_bytes();

        if ($contentLength > 0 && $maxUploadBytes > 0 && $contentLength > $maxUploadBytes) {

            wp_die(
                sprintf(
                    /* translators: 1: size of the file that was uploaded, 2: the server's maximum upload size */
                    esc_html__('That file (%1$s) is larger than this server allows in a single upload (%2$s, set by upload_max_filesize / post_max_size in PHP). Ask your host to raise those limits, or place the backup ZIP directly in the plugin\'s backups folder on the server instead of uploading it through the browser.', 'rebuzz-backup-and-restore'),
                    esc_html(size_format($contentLength)),
                    esc_html(size_format($maxUploadBytes))
                )
            );
        }
    }

    check_admin_referer('wpcb_upload_backup');

    /*
     * An uploaded archive is exactly as sensitive as a generated one -
     * it is somebody's whole site - so it gets the same refusal rather
     * than being stored somewhere publicly readable.
     */
    if (!wpcb_storage_is_private()) {
        wp_die(esc_html(wpcb_storage_insecure_reason()));
    }

    if (empty($_FILES['backup_file'])) {
        wp_die(esc_html__('No file was uploaded.', 'rebuzz-backup-and-restore'));
    }

    /*
     * Taken raw rather than passed through a sanitizing function,
     * because none of the standard ones apply to an upload array: its
     * members are set by PHP itself, not by the client, and each is
     * validated for what it actually is rather than filtered as text.
     *
     * ['error'] and ['size'] are compared as integers below, ['name']
     * goes through sanitize_file_name() and an extension check,
     * ['tmp_name'] is confirmed with is_uploaded_file(), and the file
     * is then moved by wp_handle_upload(), which re-verifies the upload
     * and the MIME type itself. The whole handler is behind
     * current_user_can('manage_options') and the check_admin_referer()
     * immediately above.
     */
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- upload array, validated member by member; see above.
    $file = $_FILES['backup_file'];

    $maxBackupUploadBytes = wpcb_max_backup_upload_bytes();

    /*
     * A file bigger than upload_max_filesize, or than the form's own
     * MAX_FILE_SIZE field (restore.php sets it to $maxBackupUploadBytes,
     * and PHP enforces that server-side too, not just as a browser
     * hint), lands here with an error code and an empty tmp_name/size -
     * PHP never actually receives the file. Checked before the tmp_name
     * check below so this specific, common case gets a clear "too
     * large" message instead of the generic "No file was uploaded."
     */
    if (
        !empty($file['error']) &&
        in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
    ) {

        wp_die(
            sprintf(
                /* translators: 1: the maximum upload size this plugin allows through the browser, 2: path to the backups folder, relative to the WordPress root */
                esc_html__('That file is larger than this plugin allows for browser uploads (%1$s). Large backups risk overloading shared hosting during upload/extraction - place the backup ZIP directly in %2$s via FTP or your host\'s file manager instead; it\'ll show up in the restore list below and works at any size.', 'rebuzz-backup-and-restore'),
                $maxBackupUploadBytes > 0
                    ? esc_html(size_format($maxBackupUploadBytes))
                    : esc_html__("this server's", 'rebuzz-backup-and-restore'),
                esc_html(wpcb_display_path(wpcb_backups_dir()) . '/')
            )
        );
    }

    if (!empty($file['error']) && $file['error'] !== UPLOAD_ERR_OK) {
        wp_die(esc_html__('Upload failed. Please try again.', 'rebuzz-backup-and-restore'));
    }

    if (empty($file['tmp_name'])) {
        wp_die(esc_html__('No file was uploaded.', 'rebuzz-backup-and-restore'));
    }

    if ($maxBackupUploadBytes > 0 && (int) $file['size'] > $maxBackupUploadBytes) {

        wp_die(
            sprintf(
                /* translators: 1: size of the file that was uploaded, 2: the maximum upload size this plugin allows through the browser, 3: path to the backups folder, relative to the WordPress root */
                esc_html__('That file (%1$s) is larger than this plugin allows for browser uploads (%2$s). Large backups risk overloading shared hosting during upload/extraction - place the backup ZIP directly in %3$s via FTP or your host\'s file manager instead; it\'ll show up in the restore list below and works at any size.', 'rebuzz-backup-and-restore'),
                esc_html(size_format((int) $file['size'])),
                esc_html(size_format($maxBackupUploadBytes)),
                esc_html(wpcb_display_path(wpcb_backups_dir()) . '/')
            )
        );
    }

    $originalName = sanitize_file_name($file['name']);

    if (strtolower(pathinfo($originalName, PATHINFO_EXTENSION)) !== 'zip') {
        wp_die(esc_html__('Please upload a .zip backup file.', 'rebuzz-backup-and-restore'));
    }

    if (!is_uploaded_file($file['tmp_name'])) {
        wp_die(esc_html__('Invalid upload.', 'rebuzz-backup-and-restore'));
    }

    $backupDir = wpcb_backups_dir();

    if (!is_dir($backupDir)) {
        wp_mkdir_p($backupDir);
    }

    wpcb_protect_directory($backupDir);

    $diskCheck = wpcb_check_upload_disk_space((int) $file['size'], $backupDir);

    if (!$diskCheck['ok']) {

        wp_die(
            sprintf(
                /* translators: 1: free disk space required, 2: free disk space actually available */
                esc_html__('Not enough free disk space to store this backup safely. Keeping it needs about %1$s free (with a safety margin); only %2$s is available on this hosting account. Free up space first (old backups, unused files/plugins), or place the file directly in the backups folder via FTP once space is available.', 'rebuzz-backup-and-restore'),
                esc_html(size_format($diskCheck['required'])),
                esc_html(size_format($diskCheck['free']))
            )
        );
    }

    // gmdate(), not date(): this is a filename, so it must be stable
    // regardless of the site's timezone setting.
    $destinationName = 'uploaded-' . gmdate('Y-m-d-H-i-s') . '-' . $originalName;

    /*
     * wp_handle_upload() rather than PHP's raw upload move: WordPress.org's
     * automated review forbids calling that function directly from plugin
     * code, and wp_handle_upload() is the supported equivalent - it does
     * the same move internally, plus its own is_uploaded_file() and
     * MIME/extension verification.
     *
     * It writes into whatever wp_upload_dir() reports, so the filter
     * below redirects it to this plugin's backups folder for the
     * duration of this one call, then is removed immediately. The
     * filename callback keeps the naming scheme the restore screen
     * expects.
     */
    require_once ABSPATH . 'wp-admin/includes/file.php';

    $redirectUploadDir = function ($dirs) use ($backupDir) {

        $dirs['path']   = $backupDir;
        // '' when storage sits outside the uploads tree (WPCB_BACKUP_DIR),
        // where no URL exists. Nothing here reads $uploaded['url'] - the
        // file is reached through download_backup(), never by URL - so an
        // empty string is correct rather than a broken link.
        $dirs['url']    = wpcb_backups_url();
        $dirs['subdir'] = '';
        $dirs['error']  = false;

        return $dirs;
    };

    add_filter('upload_dir', $redirectUploadDir);

    $uploaded = wp_handle_upload(
        $file,
        [
            // This form posts its own action/nonce, already verified
            // above, rather than the fields wp_handle_upload expects.
            'test_form' => false,
            'mimes'     => ['zip' => 'application/zip'],
            'unique_filename_callback' => function () use ($destinationName) {
                return $destinationName;
            },
        ]
    );

    remove_filter('upload_dir', $redirectUploadDir);

    if (!is_array($uploaded) || isset($uploaded['error']) || empty($uploaded['file'])) {

        wp_die(
            sprintf(
                /* translators: %s: the reason the upload could not be stored */
                esc_html__('Could not move the uploaded file into place: %s', 'rebuzz-backup-and-restore'),
                esc_html(is_array($uploaded) && !empty($uploaded['error'])
                    ? $uploaded['error']
                    : __('unknown error', 'rebuzz-backup-and-restore'))
            )
        );
    }

    $destination = $uploaded['file'];

    // wp_handle_upload() may deduplicate the name (e.g. a second upload
    // in the same second), so take the name it actually used.
    $destinationName = basename($destination);

    // Same treatment a generated archive gets - see stepFinalize().
    wpcb_restrict_file_permissions($destination);
    delete_transient('wpcb_storage_exposure');

    // Sanity check before restore screen.
    $validator = new WPCB_Validator();
    $validation = $validator->validate($destination);

    if (!$validation['success']) {

        wp_delete_file($destination);

        wp_die(
            sprintf(
                /* translators: %s: the specific reason the uploaded file failed validation */
                esc_html__('The uploaded file does not look like a valid backup: %s', 'rebuzz-backup-and-restore'),
                esc_html($validation['message'])
            )
        );
    }

    wp_safe_redirect(
        admin_url(
            'admin.php?page=wpcb-restore&file=' .
            rawurlencode($destinationName)
        )
    );

    exit;
}

/** Start a restore job for an existing backup file. */
public function start_restore()
{
    check_ajax_referer('wpcb_backup', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Permission denied.', 'rebuzz-backup-and-restore'));
    }

    $filename = sanitize_file_name(wp_unslash($_POST['file'] ?? ''));

    if (empty($filename)) {
        wp_send_json_error(__('No backup file specified.', 'rebuzz-backup-and-restore'));
    }

    // Same checks as delete_backup(): a .zip extension, and realpath()
    // confirming the resolved file is actually inside the backups
    // directory - otherwise any non-.zip file that ends up in that
    // folder (a stray upload, a misnamed FTP drop) would be handed
    // straight to WPCB_Restore_Job instead of failing fast here.
    if (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'zip') {
        wp_send_json_error(__('Invalid backup file.', 'rebuzz-backup-and-restore'));
    }

    $backupDir = wpcb_backups_dir();

    $zip = $backupDir . '/' . $filename;

    $realBackupDir = realpath($backupDir);
    $realZip = realpath($zip);

    if (
        $realBackupDir === false ||
        $realZip === false ||
        strpos($realZip, $realBackupDir . DIRECTORY_SEPARATOR) !== 0
    ) {
        wp_send_json_error(__('Invalid backup file.', 'rebuzz-backup-and-restore'));
    }

    $zip = $realZip;

    if (!file_exists($zip)) {
        wp_send_json_error(__('Backup file not found.', 'rebuzz-backup-and-restore'));
    }

    $activeJobId = wpcb_restore_lock_check();

    if ($activeJobId !== null) {
        wp_send_json_error(
            sprintf(
                /* translators: %s: internal job ID of the restore that is already running */
                __('A restore is already running (job %s). Two restores writing to the same site at once would corrupt both - wait for it to finish, or check restore.log if you believe it is actually stuck.', 'rebuzz-backup-and-restore'),
                $activeJobId
            )
        );
    }

    $badPermissions = wpcb_check_restore_write_permissions();

    if (!empty($badPermissions)) {
        wp_send_json_error(
            sprintf(
                /* translators: %s: comma-separated list of directory labels that aren't writable */
                __('These directories need to be writable before a restore can run: %s', 'rebuzz-backup-and-restore'),
                implode(', ', array_keys($badPermissions))
            )
        );
    }

    /*
     * Reclaim the workspaces left by earlier failed restores, before
     * measuring free space or extracting anything.
     *
     * A failed restore deliberately keeps its half-extracted workspace
     * for post-mortem inspection. That is useful, but it made a
     * disk-space failure self-compounding: the attempt that ran out of
     * room left several hundred megabytes behind, so the next attempt
     * started with less room than the one that had already failed, and
     * got less far. Observed on a shared host, where a restore reached
     * 3% on the first try and 0% on the second.
     *
     * Safe here: start_restore() has already established that no
     * restore is running, and the helper skips an active one regardless.
     */
    $reclaimed = wpcb_clear_stale_restore_workspaces();

    if ($reclaimed > 0) {

        (new WPCB_Logger('restore'))->log(sprintf(
            'Reclaimed %s from the workspace of an earlier failed restore before starting this one.',
            size_format($reclaimed)
        ));
    }

    // Same reasoning for the database: staging/parked tables of a restore that died.
    $leftoverTables = (new WPCB_Database())->dropWorkTables();

    if ($leftoverTables > 0) {

        (new WPCB_Logger('restore'))->log(sprintf(
            'Dropped %d leftover staging table(s) from an earlier interrupted restore.',
            $leftoverTables
        ));
    }

    $space = wpcb_check_restore_disk_space($zip);

    if (!$space['ok']) {
        wp_send_json_error(sprintf(
            /* translators: 1: free disk space required, 2: free disk space actually available */
            __('Not enough free disk space to restore this backup. Roughly %1$s is needed, but only %2$s is free.', 'rebuzz-backup-and-restore'),
            size_format($space['required']),
            size_format($space['free'])
        ));
    }

    /*
     * Runs after the stale-workspace sweep above, so it measures the disk as
     * extraction will find it, and before the job and lock exist, so a
     * refusal cannot strand a lock file behind it.
     */
    $entries = isset($space['entries']) ? $space['entries'] : 0;

    $inodes = wpcb_backup_inode_estimate($zip, $entries);
    $probe = wpcb_probe_restore_storage($entries);

    $logger = new WPCB_Logger('restore');

    if ($probe['status'] === 'failed') {

        $logger->log(sprintf(
            'Restore refused by the storage probe (%s): wrote %d of %d bytes, created %d of %d files. Backup expects %d files and %d folders (%s). disk_free_space %s. Raw error: %s',
            $probe['kind'],
            $probe['bytes'],
            $probe['expected'],
            $probe['files'],
            $probe['attempted'],
            $inodes['files'],
            $inodes['directories'],
            $inodes['source'],
            empty($space['measured']) ? 'unmeasurable' : size_format($space['free']),
            ($probe['error'] === '') ? 'none' : $probe['error']
        ));

        wp_send_json_error(
            wpcb_restore_storage_probe_message($probe, $inodes, $space)
        );
    }

    // Logged on every outcome: the first question after the next failure is
    // whether this ran at all and what it saw.
    if ($probe['status'] === 'unknown') {

        $logger->log('The storage probe could not run, so this restore is starting without it.');

    } else {

        $logger->log(sprintf(
            'Storage probe passed: wrote %s and created %d test files.',
            size_format($probe['bytes']),
            $probe['files']
        ));
    }

    $job = new WPCB_Job();

    // Check + acquire aren't atomic (race possible between requests);
    // wpcb_restore_lock_acquire() does an atomic claim to close that gap.
    if (!wpcb_restore_lock_acquire($job->id())) {
        wp_send_json_error(
            __('Could not start the restore. Either one is already running (started by another request just now) - two restores writing to the same site at once would corrupt both, so wait for it to finish or check restore.log if you believe it is stuck - or the lock file could not be written, which means the plugin\'s folder under uploads is not writable or the disk is full.', 'rebuzz-backup-and-restore')
        );
    }

    $currentUserId = get_current_user_id();
    $currentUser = $currentUserId > 0 ? get_userdata($currentUserId) : false;

    $job->update([
        'status' => 'running',
        'step' => 0,
        'progress' => 0,
        'message' => __('Starting restore...', 'rebuzz-backup-and-restore'),
        'zip' => $zip,
        // Backup's DB has the old domain; capture current siteurl/home
        // now so they can be restored and avoid a wp-admin lockout.
        'preserve_site_url' => site_url(),
        'preserve_home_url' => home_url(),
        // Auth cookie is bound to user_login + a hash of user_pass; DB
        // import overwrites wp_users, so save both and restore after,
        // or the admin's own cookie stops validating (lockout).
        'preserve_admin_user_id' => $currentUserId,
        'preserve_admin_login' => $currentUser ? $currentUser->user_login : null,
        'preserve_admin_pass' => $currentUser ? $currentUser->user_pass : null,
        // Snapshot active_plugins pre-restore so isolateActivePlugins()
        // can tell the old value from the dump's incoming value.
        'pre_restore_active_plugins' => get_option('active_plugins', []),
        // Opt-in, default off (see WPCB_Quarantine). Renames the live
        // plugins/themes/uploads aside so the restore rebuilds them from
        // the backup instead of merging into whatever is there now.
        'remove_extra_files' => (bool) (int) sanitize_text_field(wp_unslash($_POST['remove_extra_files'] ?? '0'))
    ]);

    wp_send_json_success([
        'job_id' => $job->id()
    ]);
}

/** Advance a restore job by one step. */
public function restore_step()
{
    check_ajax_referer('wpcb_backup', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Permission denied.', 'rebuzz-backup-and-restore'));
    }

    $jobId = sanitize_text_field(wp_unslash($_POST['job_id'] ?? ''));

    if (empty($jobId)) {
        wp_send_json_error(__('Missing job ID.', 'rebuzz-backup-and-restore'));
    }

    $job = new WPCB_Job($jobId);

    // job_id alone isn't access control; without this a guessed job_id
    // could expose the preserved admin login credentials too.
    if (!$job->isOwnedBy(get_current_user_id())) {
        wp_send_json_error(__('Permission denied.', 'rebuzz-backup-and-restore'));
    }

    // Already failed (e.g. marked so by the crash handler): report that
    // instead of re-running the step that crashed and crashing again.
    if (($job->get()['status'] ?? '') === 'failed') {
        wp_send_json_success($job->getPublic());
    }

    // Prevents a retry from double-processing the same step
    // concurrently; see WPCB_Job::isProcessing().
    if ($job->isProcessing()) {
        wp_send_json_success($job->getPublic());
    }

    $job->markProcessing();

    $restore = new WPCB_Restore_Job($job);

    $this->guardAgainstFatalError($job, 'restore');

    $this->runStepBuffered(function () use ($restore) {
        return $restore->processNextStep();
    }, 'restore');

    $job->clearProcessing();

    wp_send_json_success(
        $job->getPublic()
    );
}

/**
 * Run one backup/restore step with anything it prints captured, so a
 * stray echo or warning from third-party code hooked into the work
 * can't get in front of the JSON response and leave jQuery unable to
 * parse it.
 *
 * The buffer is opened and closed inside this one function, with no
 * hook, redirect or early return in between - the step methods signal
 * failure by return value and job state, never by exiting. Captured
 * output is logged rather than discarded silently, since something
 * printing mid-backup is usually worth knowing about.
 *
 * @param callable $step  Does the work; return value is passed through.
 * @param string   $kind  'backup' or 'restore', for the log entry.
 */
private function runStepBuffered(callable $step, $kind)
{
    ob_start();

    try {

        $result = $step();

    } finally {

        // finally, so an exception thrown by the step still closes the
        // buffer this function opened rather than leaving it on the
        // stack for the rest of the request.
        $stray = ob_get_clean();
    }

    if (is_string($stray) && trim($stray) !== '') {

        (new WPCB_Logger($kind))->log(
            'Discarded unexpected output during this step (it would have corrupted the JSON response): ' .
            trim(substr($stray, 0, 1000))
        );
    }

    return $result;
}

/**
 * Shutdown handler: turns a PHP fatal (timeout/OOM mid-step) into a
 * JSON error response instead of a broken one, and marks job failed.
 *
 * Opens no output buffer of its own - runStepBuffered() does that, and
 * closes it in the same function. This only records how deep the
 * buffer stack was when the guard was registered, so that on a fatal
 * it can discard the partial output above that depth without tearing
 * down buffers core or another plugin opened underneath it.
 */
private function guardAgainstFatalError(WPCB_Job $job, $kind)
{
    /*
     * No ini_set('display_errors') here either - see the note at the
     * top of rebuzz-backup-and-restore.php. Output a fatal produces is
     * dealt with below by discarding the buffers opened above
     * $baseBufferLevel, which handles the JSON-corruption problem
     * without changing PHP's error reporting for the whole request.
     */
    $baseBufferLevel = ob_get_level();

    // Tells the early fallback in rebuzz-backup-and-restore.php that this handler will answer.
    if (!defined('WPCB_STEP_GUARD_ACTIVE')) {
        define('WPCB_STEP_GUARD_ACTIVE', true);
    }

    register_shutdown_function(function () use ($job, $kind, $baseBufferLevel) {

        $error = error_get_last();

        $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];

        if (!$error || !in_array($error['type'], $fatalTypes, true)) {
            return;
        }

        // A fatal inside wpdb (e.g. out of memory fetching a huge row)
        // leaves its result half-read, and every query below would fail
        // with "Commands out of sync" - the job would stay "running".
        wpcb_recover_db_connection();

        // Crash skipped the normal clearProcessing(); do it here or
        // retries get rejected as "already running" for up to 90s.
        $job->clearProcessing();

        $message = sprintf(
            /* translators: 1: "backup" or "restore", 2: PHP error message, 3: line number, 4: file name */
            __('The %1$s step crashed (%2$s at line %3$d of %4$s). This usually means a PHP execution time or memory limit was hit while processing this step - check your host\'s PHP error log for the full trace, and consider raising max_execution_time or memory_limit.', 'rebuzz-backup-and-restore'),
            $kind,
            $error['message'],
            $error['line'],
            basename($error['file'])
        );

        // Log the crash too, not just browser/job state, so the
        // cause survives after the on-screen message is gone.
        if (class_exists('WPCB_Logger')) {

            $logger = new WPCB_Logger($kind);
            $logger->log('CRASH: ' . $message);
        }

        $job->update([
            'status' => 'failed',
            'message' => $message
        ]);

        /*
         * A crash bypasses fail(), so do its cleanup here: plugins back
         * on, mu-plugins renamed back, moved-aside folders returned,
         * staging tables dropped and the lock released. Otherwise a
         * mid-restore crash leaves every plugin deactivated and blocks
         * all future restores.
         */
        if ($kind === 'restore') {

            try {

                (new WPCB_Restore_Job($job))->cleanupAfterFailure();

            } catch (\Throwable $e) {

                if (function_exists('wpcb_restore_lock_release')) {
                    wpcb_restore_lock_release();
                }
            }

            // Belt-and-braces for the mu-plugins: they commonly carry
            // security-critical code. Runs after the lock is released so
            // it doesn't see this job as an active restore.
            if (function_exists('wpcb_recover_disabled_mu_plugins')) {
                wpcb_recover_disabled_mu_plugins();
            }
        }

        // Same for a backup: fail() would free the lock and the temp files.
        if ($kind === 'backup') {

            $state = $job->get();

            if (!empty($state['workspace'])) {
                (new WPCB_Workspace($state['workspace']))->cleanup();
            }

            if (function_exists('wpcb_backup_lock_release')) {
                wpcb_backup_lock_release();
            }
        }

        // Discard whatever partial output this request produced, so
        // only clean JSON goes out - but only down to the depth the
        // buffer stack had when this guard was registered, leaving
        // anything core or another plugin opened below it intact.
        while (ob_get_level() > $baseBufferLevel) {
            ob_end_clean();
        }

        if (!headers_sent()) {
            status_header(200);
            header('Content-Type: application/json; charset=utf-8');
        }

        echo wp_json_encode([
            'success' => false,
            'data' => $message
        ]);
    });
}

/**
 * Offers to delete the directories a rename-then-replace restore left
 * behind. Deliberately never automatic: the whole point of renaming
 * rather than deleting is that the previous site is still there if the
 * restore turns out to have been wrong, and that guarantee ends the
 * moment something deletes them without being asked.
 */
public function old_directories_notice()
{
    if (!current_user_can('manage_options') || !class_exists('WPCB_Quarantine')) {
        return;
    }

    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flag from our own redirect; only decides whether a success notice prints.
    if (!empty($_GET['wpcb_old_deleted'])) {

        echo '<div class="notice notice-success is-dismissible"><p>' .
            esc_html__('Old directories deleted.', 'rebuzz-backup-and-restore') .
            '</p></div>';
    }

    $pending = WPCB_Quarantine::pending();

    if ($pending === null) {
        return;
    }

    $names = [];

    foreach ($pending['dirs'] as $dir) {

        if (!empty($dir['old']) && is_dir($dir['old'])) {
            $names[] = '<code>' . esc_html(basename($dir['old'])) . '</code>';
        }
    }

    if (empty($names)) {

        // Removed by hand already - drop the record rather than nag
        // about directories that are no longer there.
        WPCB_Quarantine::deletePending();

        return;
    }

    ?>
    <div class="notice notice-error">

        <p>
            <strong><?php esc_html_e('Rebuzz Backup: delete the old directories from your restore', 'rebuzz-backup-and-restore'); ?></strong>
        </p>

        <p>
            <?php
            printf(
                /* translators: %s: comma-separated list of directory names */
                esc_html__('Items your backup did not contain were moved aside rather than deleted, so you can check the restored site first. They are still on disk in %s.', 'rebuzz-backup-and-restore'),
                wp_kses(implode(', ', $names), ['code' => []])
            );
            ?>
        </p>

        <p>
            <strong><?php esc_html_e('These folders sit inside your website and may be reachable from the internet - including old plugin and theme code, which can still be run and may contain known security holes, and any private uploads.', 'rebuzz-backup-and-restore'); ?></strong>
        </p>

        <p>
            <?php esc_html_e('Check the restored site now, copy out anything that is missing, then delete them. Don\'t leave them in place.', 'rebuzz-backup-and-restore'); ?>
        </p>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">

            <?php wp_nonce_field('wpcb_delete_old_directories'); ?>

            <input type="hidden" name="action" value="wpcb_delete_old_directories">

            <p>
                <button type="submit" class="button button-primary">
                    <?php esc_html_e('Delete old directories', 'rebuzz-backup-and-restore'); ?>
                </button>
            </p>

        </form>

    </div>
    <?php
}

/** Handles the notice's button. */
public function delete_old_directories()
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('Permission denied.', 'rebuzz-backup-and-restore'));
    }

    check_admin_referer('wpcb_delete_old_directories');

    $freed = WPCB_Quarantine::deletePending();

    (new WPCB_Logger('restore'))->log(
        sprintf('Deleted the directories renamed aside by the last restore, freeing %s.', size_format($freed))
    );

    $back = wp_get_referer();

    wp_safe_redirect(
        add_query_arg('wpcb_old_deleted', 1, $back ? $back : admin_url())
    );

    exit;
}
}