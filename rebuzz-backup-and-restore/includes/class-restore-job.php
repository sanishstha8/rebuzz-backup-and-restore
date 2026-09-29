<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Chunked restore engine (mirrors WPCB_Backup_Job): processNextStep()
 * does bounded work per call, driven by repeated AJAX calls.
 *
 * Steps:
 *   0 - Extract ZIP into a restore workspace.
 *   1 - Validate manifest.json and DB checksum.
 *   2 - Verify each file's checksum before touching anything live.
 *   3 - Import database.sql into staging tables (live DB untouched).
 *   4 - Build lists of files to restore: WordPress core, and the rest.
 *   5 - Copy core into a staging folder beside the live one.
 *   6 - Copy the rest (plugins, themes, uploads...) into place.
 *   7 - In one request: swap core in by directory renames, then the
 *       staging tables with one atomic RENAME TABLE.
 *   8 - Rewrite old domain references (skipped if same domain).
 *   9+ - Finish: clean up workspace, report success.
 *
 * Everything that can fail on the database - a bad collation, a
 * DEFINER, a full disk - happens in step 3, and staging core in step 5,
 * before any file is overwritten, so a failure there leaves the site
 * exactly as it was. The swap waits until the files are in place so
 * active_plugins/theme options never point at files that aren't on
 * disk yet - which would have WordPress deactivating plugins
 * mid-restore. Core and database swap together: new core against the
 * old database sends wp-admin to upgrade.php (see WPCB_Core_Swap).
 */
// phpcs:disable WordPress.WP.AlternativeFunctions -- WP_Filesystem has no streaming API; archives are moved in chunks to stay inside memory limits.
// phpcs:disable PluginCheck.CodeAnalysis.WriteFile -- restoring a site means writing its files back under ABSPATH; that is the feature.
/*
 * No file-level database suppression here, unlike class-database.php
 * and class-url-rewriter.php, which exist only to move a database
 * about. This file is 2,000+ lines of mostly unrelated restore logic
 * and holds just three queries; silencing the sniffs across all of it
 * would mean any query added later went unchecked. Each of the three
 * carries its own justification instead.
 */

class WPCB_Restore_Job
{
    /**
     * Per-call entry caps for verify/scan/restore. TIME_BUDGET_SECONDS
     * is the real limiter; these just guard against looping forever
     * on huge file counts. Raised from 200-500 since low caps wasted
     * round-trips (each a full WP bootstrap) for no safety benefit.
     */
    const FILE_BATCH_SIZE = 5000;
    const VERIFY_BATCH_SIZE = 5000;
    const DB_BYTES_PER_STEP = 2097152; // 2MB per step

    /**
     * How often stepRestoreFiles() checkpoints restore.state.json
     * mid-loop. Matters specifically for the "keep going past budget
     * until the plugin/theme folder changes" overtime case (see that
     * method), which has no other time/count bound - without a
     * mid-loop checkpoint, a process kill during a very large single
     * plugin/theme folder loses the entire call's progress, not just
     * the overtime portion.
     */
    const CHECKPOINT_INTERVAL = 1000;

    /**
     * Max seconds per verify/scan/restore-files call before returning
     * control to the browser. Same pattern as WPCB_Zip_Batch.
     */
    const TIME_BUDGET_SECONDS = 20;

    /**
     * Files describing *this* environment, not site content - never
     * overwritten by restore even if the backup ZIP has them (enforced
     * in stepRestoreFiles(), not just at backup time). wp-config.php
     * has DB credentials; .htaccess/web.config are server-specific
     * rewrite rules; robots.txt often hardcodes the source's Sitemap URL.
     */
    const ENVIRONMENT_FILES = [
        'wp-config.php',
        '.htaccess',
        'web.config',
        'robots.txt'
    ];

    private $job;
    private $logger;

    public function __construct(WPCB_Job $job)
    {
        $this->job = $job;
        $this->logger = new WPCB_Logger();
    }

    private function workspace()
    {
        $state = $this->job->get();

        if (!empty($state['workspace'])) {
            return new WPCB_Restore_Workspace($state['workspace']);
        }

        return new WPCB_Restore_Workspace();
    }

    public function processNextStep()
    {
        $state = $this->job->get();

        // Refresh session at the top of every step (not just during
        // DB import) so a stale session never causes a silent
        // "AJAX request failed" with no explanation in restore.log.
        $this->refreshAdminSession();

        switch ((int) $state['step']) {

            case 0:
                return $this->stepExtract();

            case 1:
                return $this->stepValidate();

            case 2:
                return $this->stepVerifyFiles();

            case 3:
                return $this->stepStageDatabase();

            case 4:
                return $this->stepScanFiles();

            case 5:
                return $this->stepStageCore();

            case 6:
                return $this->stepRestoreFiles();

            case 7:
                return $this->stepSwapDatabase();

            case 8:
                return $this->stepRewriteUrls();

            default:
                return $this->finish();
        }
    }

    /**
     * Step 0: extract backup ZIP into a workspace, chunked across
     * calls - see WPCB_Extractor for why a single extractTo() isn't
     * safe on multi-GB (Zip64) backups.
     */
    private function stepExtract()
    {
        wpcb_extend_time_limit(60);

        $state = $this->job->get();

        if (empty($state['zip']) || !file_exists($state['zip'])) {
            return $this->fail(__('Backup ZIP not found.', 'rebuzz-backup-and-restore'));
        }

        $workspace = $this->workspace();

        $extractor = new WPCB_Extractor();

        $extractState = $workspace->getJson('extract.state.json');

        if (empty($extractState)) {

            $this->logger->log('Extracting backup: ' . $state['zip']);

            $validation = $extractor->validate($state['zip']);

            if (!$validation['success']) {
                return $this->fail($validation['message']);
            }

            $extractState = [
                'position' => 0,
                'total' => $validation['total'],
                'byte_offset' => 0
            ];
        }

        $result = $extractor->extractNext(
            $state['zip'],
            $workspace->path(),
            $extractState['position'],
            $extractState['total'],
            isset($extractState['byte_offset']) ? (int) $extractState['byte_offset'] : 0
        );

        if (!$result['success']) {
            return $this->fail($result['message']);
        }

        $workspace->put('extract.state.json', [
            'position'    => $result['position'],
            'total'       => $extractState['total'],
            'byte_offset' => $result['byte_offset']
        ]);

        if (!$result['finished']) {

            $progress = $extractState['total'] > 0
                ? (int) (($result['position'] / $extractState['total']) * 10)
                : 0;

            $this->job->update([
                'status' => 'running',
                'step' => 0,
                'progress' => $progress,
                'message' => sprintf(
                    /* translators: 1: number of entries extracted so far, 2: total number of entries */
                    __('Extracting backup (%1$d/%2$d)...', 'rebuzz-backup-and-restore'),
                    $result['position'],
                    $extractState['total']
                ),
                'workspace' => $workspace->path()
            ]);

            return true;
        }

        $this->logger->log("Backup extracted: {$extractState['total']} entries.");

        $this->job->update([
            'status' => 'running',
            'step' => 1,
            'progress' => 10,
            'message' => __('Backup extracted.', 'rebuzz-backup-and-restore'),
            'workspace' => $workspace->path()
        ]);

        return true;
    }

    /**
     * Step 1: verify manifest.json and DB checksum before touching
     * the live site.
     */
    private function stepValidate()
    {
        $workspace = $this->workspace();

        if (!$workspace->exists('manifest.json')) {
            return $this->fail(__('manifest.json is missing from the backup.', 'rebuzz-backup-and-restore'));
        }

        $manifest = json_decode(
            (string) $workspace->get('manifest.json'),
            true
        );

        if (!is_array($manifest)) {
            return $this->fail(__('manifest.json is invalid or corrupted.', 'rebuzz-backup-and-restore'));
        }

        $dbRelative = 'database/database.sql';
        $dbFile = $workspace->file($dbRelative);

        if (!file_exists($dbFile)) {
            return $this->fail(__('database.sql is missing from the backup.', 'rebuzz-backup-and-restore'));
        }

        $integrity = new WPCB_Integrity();
        $checksums = $integrity->checksumsFromManifest($manifest);

        // Fail closed: every file this plugin backs up gets a checksum
        // entry (streamed to checksums.jsonl as it's added to the ZIP -
        // see WPCB_Zip_Batch), so a missing entry means something
        // dropped it, not that verification legitimately doesn't apply.
        if (
            empty($checksums[$dbRelative]) ||
            !$integrity->verifyFile($dbFile, $checksums[$dbRelative])
        ) {

            return $this->fail(
                empty($checksums[$dbRelative])
                    ? __('No checksum on record for database.sql - the manifest may be corrupted or tampered with.', 'rebuzz-backup-and-restore')
                    : __('Database checksum does not match the manifest. The backup may be corrupted.', 'rebuzz-backup-and-restore')
            );
        }

        $this->logger->log('Manifest and database checksum validated.');

        // Multisite: block a site admin from restoring another
        // site's/network's data via a crafted or pre-fix backup.
        // Network super-admins are exempt. Checked before anything
        // live is touched.
        if (is_multisite()) {

            $multisite = new WPCB_Multisite();

            if (!$multisite->isNetworkAdmin()) {

                $database = new WPCB_Database();
                $dumpTables = $database->tablesInDump($dbFile);
                $foreignTable = $multisite->findForeignTable($dumpTables, get_current_blog_id());

                if ($foreignTable !== null) {

                    return $this->fail(
                        sprintf(
                            /* translators: %s: name of a database table belonging to another site or the network */
                            __('This backup contains database tables for another site or for the network itself (found `%s`), not just this site. Restoring it would read or overwrite that data - only a network administrator can restore a backup like this.', 'rebuzz-backup-and-restore'),
                            $foreignTable
                        )
                    );
                }

                $sitesDir = $workspace->file('files/' . $this->uploadsSitesRelativePath());
                $foreignSite = $multisite->foreignUploadsSiteId($sitesDir, get_current_blog_id());

                if ($foreignSite !== null) {

                    return $this->fail(
                        sprintf(
                            /* translators: %s: ID of the other site whose uploaded media was found in this backup */
                            __("This backup contains another site's own uploaded files (site ID %s), not just this site's. Restoring it would overwrite that site's media - only a network administrator can restore a backup like this.", 'rebuzz-backup-and-restore'),
                            $foreignSite
                        )
                    );
                }
            }
        }

        // Not a hard gate - just informational, so an old-version
        // backup can still restore after updating the plugin.
        $backupVersion = $manifest['plugin']['version'] ?? null;

        if ($backupVersion !== null && $backupVersion !== WPCB_VERSION) {

            $this->logger->log(sprintf(
                'Note: this backup was made with plugin version %s; this site is running %s.',
                $backupVersion,
                WPCB_VERSION
            ));
        }

        $this->job->update([
            'status' => 'running',
            'step' => 2,
            'progress' => 15,
            'message' => __('Backup validated.', 'rebuzz-backup-and-restore'),
            'backup_plugin_version' => $backupVersion
        ]);

        return true;
    }

    /**
     * Step 2: verify each extracted file's checksum before any DB
     * import or file copy - safe to fail here since nothing live is
     * touched yet. Same bounded stack-walk as stepScanFiles(), state
     * in verify.state.json.
     */
    private function stepVerifyFiles()
    {
        wpcb_extend_time_limit(60);

        $startTime = microtime(true);

        $workspace = $this->workspace();

        $filesDir = $workspace->file('files');
        $root = realpath($filesDir);

        if ($root === false || !is_dir($filesDir)) {

            $this->logger->log('No files/ directory in backup to verify.');

            $this->job->update([
                'status' => 'running',
                'step' => 3,
                'progress' => 25,
                'message' => __('No files to verify.', 'rebuzz-backup-and-restore')
            ]);

            return true;
        }

        $state = $workspace->getJson('verify.state.json');

        if (empty($state)) {
            $state = [
                'stack' => [$filesDir],
                'checked' => 0
            ];
        }

        $stack = $state['stack'];
        $checked = (int) $state['checked'];

        $manifest = json_decode((string) $workspace->get('manifest.json'), true);

        $integrity = new WPCB_Integrity();
        $checksums = $integrity->checksumsFromManifest($manifest);

        $processed = 0;

        while (
            $processed < self::VERIFY_BATCH_SIZE &&
            (microtime(true) - $startTime) < self::TIME_BUDGET_SECONDS &&
            !empty($stack)
        ) {

            $path = array_pop($stack);

            if (is_dir($path)) {

                $entries = @scandir($path);

                if ($entries !== false) {

                    foreach ($entries as $entry) {

                        if ($entry === '.' || $entry === '..') {
                            continue;
                        }

                        $stack[] = $path . '/' . $entry;

                        // Count pushed entries too, so one huge
                        // directory can't blow past the budget unchecked.
                        $processed++;
                    }

                } else {

                    $this->logger->log(
                        "Could not read directory during verification, its contents were skipped: {$path}"
                    );
                }

            } else {

                $real = realpath($path);

                if ($real !== false) {

                    $relative = ltrim(
                        str_replace('\\', '/', str_replace($root, '', $real)),
                        '/'
                    );

                    $key = 'files/' . $relative;

                    // Fail closed: every file this plugin backs up gets
                    // a checksum entry (see stepValidate()'s database
                    // checksum check for the same reasoning), so a
                    // missing entry means something dropped it, not
                    // that verification legitimately doesn't apply.
                    if (
                        !isset($checksums[$key]) ||
                        !$integrity->verifyFile($real, $checksums[$key])
                    ) {

                        return $this->fail(
                            isset($checksums[$key])
                                ? sprintf(
                                    /* translators: %s: relative path of the file whose checksum didn't match */
                                    __('File checksum mismatch for %s. The backup may be corrupted.', 'rebuzz-backup-and-restore'),
                                    $relative
                                )
                                : sprintf(
                                    /* translators: %s: relative path of the file with no checksum on record */
                                    __('No checksum on record for %s - the manifest may be corrupted or tampered with.', 'rebuzz-backup-and-restore'),
                                    $relative
                                )
                        );
                    }

                    $checked++;
                }
            }

            $processed++;
        }

        $finished = empty($stack);

        $workspace->put('verify.state.json', [
            'stack' => $stack,
            'checked' => $checked
        ]);

        if (!$finished) {

            $this->job->update([
                'status' => 'running',
                'step' => 2,
                'progress' => 20,
                /* translators: %d: number of files checked so far */
                'message' => sprintf(__('Verifying files (%d checked)...', 'rebuzz-backup-and-restore'), $checked)
            ]);

            return true;
        }

        $this->logger->log("File integrity verified: {$checked} files checked.");

        $this->job->update([
            'status' => 'running',
            'step' => 3,
            'progress' => 25,
            /* translators: %d: total number of files whose checksum was verified */
            'message' => sprintf(__('File integrity verified (%d files).', 'rebuzz-backup-and-restore'), $checked)
        ]);

        return true;
    }

    /**
     * Step 3: import database.sql in chunks into staging tables (see
     * WPCB_Database::import()). Nothing live is touched, so a failure
     * here only has staging tables to drop - see the class docblock
     * for why this runs before the file copy.
     */
    private function stepStageDatabase()
    {
        wpcb_extend_time_limit(60);

        $workspace = $this->workspace();
        $state = $this->job->get();

        $dbFile = $workspace->file('database/database.sql');

        $offset = isset($state['db_offset'])
            ? (int) $state['db_offset']
            : 0;

        $database = new WPCB_Database();

        $plan = $workspace->getJson('stage.plan.json');

        if (empty($plan)) {

            // Left behind by a restore that died mid-import.
            $database->dropWorkTables(WPCB_Database::STAGE_PREFIX);

            // Different source table prefix needs rewriting in the dump,
            // or the import creates orphaned tables nothing reads.
            // Detected from the dump itself; manifest.json is a fallback.
            $sourcePrefix = $database->detectSourcePrefix($dbFile);

            if (empty($sourcePrefix)) {

                $manifest = json_decode((string) $workspace->get('manifest.json'), true);

                if (is_array($manifest) && !empty($manifest['wordpress']['table_prefix'])) {
                    $sourcePrefix = $manifest['wordpress']['table_prefix'];
                }
            }

            $plan = [
                'source_prefix'    => $sourcePrefix,
                'dump_tables'      => $database->liveNamesInDump($dbFile, $sourcePrefix),
                'live_constraints' => $database->liveConstraintNames(),
                'compat'           => $database->compatibility()
            ];

            if (!$workspace->put('stage.plan.json', $plan)) {
                return $this->fail(__('Could not write to the restore workspace - the disk may be full.', 'rebuzz-backup-and-restore'));
            }

            if (!empty($plan['compat']['collation'])) {
                $this->logger->log(sprintf(
                    'This database server has no utf8mb4_0900_* collations (MySQL 8 only); tables using them are created with %s instead.',
                    $plan['compat']['collation']
                ));
            }

            if (!empty($plan['compat']['utf8mb3'])) {
                $this->logger->log('This database server does not recognise the utf8mb3 character set name; tables using it are created as utf8, its older name.');
            }
        }

        $result = $database->import(
            $dbFile,
            $offset,
            self::DB_BYTES_PER_STEP,
            [
                'source_prefix'    => $plan['source_prefix'],
                'staging'          => true,
                'dump_tables'      => $plan['dump_tables'],
                'live_constraints' => $plan['live_constraints'],
                'compat'           => $plan['compat'],
                'known_views'      => isset($state['dump_views']) ? (array) $state['dump_views'] : []
            ]
        );

        if (!empty($result['error'])) {

            $table = $result['error_table'] ?? null;

            // Plain, untranslated fragment for the log file (kept
            // consistent/English there for support purposes); the
            // user-facing fail() message below is built and translated
            // separately.
            $logDetail = $table ? sprintf(' (table: %s)', $table) : '';

            $this->logger->log(
                'Database import error' . $logDetail . ': ' . $result['error'] .
                (!empty($result['error_sql']) ? ' | statement: ' . $result['error_sql'] : '')
            );

            return $this->fail(
                $table
                    ? sprintf(
                        /* translators: 1: name of the database table where the import error occurred, 2: the underlying database error message */
                        __('Database import failed (table: %1$s): %2$s. Your site has not been changed.', 'rebuzz-backup-and-restore'),
                        $table,
                        $result['error']
                    )
                    : sprintf(
                        /* translators: %s: the underlying database error message */
                        __('Database import failed: %s. Your site has not been changed.', 'rebuzz-backup-and-restore'),
                        $result['error']
                    )
            );
        }

        // Keyed by the chunk's start offset, so a retried chunk replaces its entry instead of adding twice.
        if (!empty($result['deferred'])) {

            $deferred = $workspace->getJson('deferred.json') ?: [];
            $deferred[(string) $offset] = $result['deferred'];

            if (!$workspace->put('deferred.json', $deferred)) {
                return $this->fail(__('Could not write to the restore workspace - the disk may be full.', 'rebuzz-backup-and-restore'));
            }
        }

        $tablesRestored = (isset($state['tables_restored']) ? (int) $state['tables_restored'] : 0)
            + (int) ($result['tables_created'] ?? 0);

        $ratio = $result['size'] > 0
            ? ($result['offset'] / $result['size'])
            : 1;

        if (!$result['finished']) {

            $this->job->update([
                'status' => 'running',
                'step' => 3,
                'progress' => (int) (25 + ($ratio * 30)),
                'message' => sprintf(
                    /* translators: 1: bytes of the database imported so far, 2: total database size */
                    __('Importing database (%1$s / %2$s)', 'rebuzz-backup-and-restore'),
                    size_format($result['offset']),
                    size_format($result['size'])
                ),
                'db_offset' => $result['offset'],
                'tables_restored' => $tablesRestored,
                'dump_views' => $result['views']
            ]);

            return true;
        }

        $this->logger->log(sprintf('Database imported into staging tables: %d table(s). The live database is untouched until the files are in place.', $tablesRestored));

        $this->job->update([
            'status' => 'running',
            'step' => 4,
            'progress' => 55,
            'message' => __('Database imported.', 'rebuzz-backup-and-restore'),
            'db_offset' => $result['offset'],
            'tables_restored' => $tablesRestored,
            'dump_views' => $result['views']
        ]);

        return true;
    }

    /**
     * Step 7: swap staged core in (directory renames), then the staged
     * tables (one atomic RENAME TABLE), in the same request - so new
     * core never runs against the old database or the other way round.
     * If the database swap fails, core is renamed back. Then redo what
     * the imported tables overwrote: this site's URL, the admin's login
     * and session, and plugin isolation. Views, triggers and routines
     * deferred by step 3 run last; a failure there is a warning, not a
     * failed restore - undoing the swap now would put the old database
     * back under the new files.
     */
    private function stepSwapDatabase()
    {
        wpcb_extend_time_limit(60);

        $workspace = $this->workspace();
        $state = $this->job->get();

        $database = new WPCB_Database();

        $plan = $workspace->getJson('stage.plan.json');

        if (empty($state['db_swapped'])) {

            $core = new WPCB_Core_Swap($workspace);
            $withCore = !empty($state['core_staged']);

            if ($withCore) {

                $coreSwap = $core->swap($this->coreRootFiles($workspace));

                if (!$coreSwap['ok']) {

                    $this->logger->log('WordPress core swap failed: ' . $coreSwap['error']);

                    return $this->fail(sprintf(
                        /* translators: %s: the underlying error */
                        __('Could not switch the restored WordPress core in: %s. The previous core and database are still in place.', 'rebuzz-backup-and-restore'),
                        $coreSwap['error']
                    ));
                }
            }

            $swap = $database->swapStaged(!empty($plan['dump_tables']) ? (array) $plan['dump_tables'] : []);

            if (!$swap['ok']) {

                $this->logger->log('Database swap failed: ' . $swap['error']);

                if ($withCore && $core->rollback() === false) {
                    $this->logger->log('Could not put the previous WordPress core back after the failed database swap - it is in ' . $core->oldPath() . '.');
                }

                return $this->fail(sprintf(
                    /* translators: %s: the underlying database error message */
                    __('Could not switch the restored database in: %s. The previous database and WordPress core are still in place.', 'rebuzz-backup-and-restore'),
                    $swap['error']
                ));
            }

            // First, before anything else can crash: from here a rollback would mismatch core and database.
            $core->commit();

            // Also re-creates the job's transient - the swap replaced the wp_options it lived in.
            $this->job->update(['db_swapped' => true]);

            if ($withCore) {
                $this->logger->log(sprintf('Swapped in the restored WordPress core: %d item(s).', $coreSwap['swapped']));
            }

            $this->logger->log(sprintf('Swapped in the restored database: %d table(s).', count($swap['swapped'])));
        }

        // Once, right after the swap - see this method's docblock.
        $this->restorePreservedSiteUrl();
        $this->restorePreservedAdminIdentity();
        $this->refreshAdminSession();
        $this->isolateActivePlugins();

        $deferredFailed = [];

        $deferredChunks = $workspace->getJson('deferred.json');

        if (!empty($deferredChunks) && empty($state['deferred_done'])) {

            ksort($deferredChunks, SORT_NUMERIC);

            $statements = [];

            foreach ($deferredChunks as $chunk) {
                $statements = array_merge($statements, (array) $chunk);
            }

            $deferredFailed = $database->runDeferred($statements);

            foreach ($deferredFailed as $error) {
                $this->logger->log('Could not recreate a view/trigger/routine from the backup: ' . $error);
            }

            $this->logger->log(sprintf(
                'Recreated views/triggers/routines from the backup: %d statement(s), %d failed.',
                count($statements),
                count($deferredFailed)
            ));
        }

        $this->job->update([
            'status' => 'running',
            'step' => 8,
            'progress' => 90,
            'message' => __('Database restored.', 'rebuzz-backup-and-restore'),
            'deferred_done' => true,
            'deferred_failed' => count($deferredFailed) + (int) ($state['deferred_failed'] ?? 0)
        ]);

        return true;
    }

    /**
     * Step 8: rewrite hardcoded old-domain references (post content,
     * GUIDs, serialized values) to this site's domain - see
     * WPCB_Url_Rewriter for why not a plain str_replace(). Complements
     * restorePreservedSiteUrl(), which only fixes siteurl/home
     * themselves. No-ops if the backup was already on this domain.
     */
    private function stepRewriteUrls()
    {
        wpcb_extend_time_limit(60);

        $workspace = $this->workspace();
        $state = $this->job->get();

        $manifest = json_decode((string) $workspace->get('manifest.json'), true);

        $sourceSiteUrl = (is_array($manifest) && !empty($manifest['site']['site_url']))
            ? $manifest['site']['site_url']
            : '';

        $sourceHomeUrl = (is_array($manifest) && !empty($manifest['site']['home_url']))
            ? $manifest['site']['home_url']
            : '';

        $destSiteUrl = !empty($state['preserve_site_url']) ? $state['preserve_site_url'] : site_url();
        $destHomeUrl = !empty($state['preserve_home_url']) ? $state['preserve_home_url'] : home_url();

        $pairs = [];

        if ($sourceSiteUrl !== '' && $sourceSiteUrl !== $destSiteUrl) {
            $pairs[$sourceSiteUrl] = $destSiteUrl;
        }

        if (
            $sourceHomeUrl !== '' &&
            $sourceHomeUrl !== $destHomeUrl &&
            $sourceHomeUrl !== $sourceSiteUrl
        ) {
            $pairs[$sourceHomeUrl] = $destHomeUrl;
        }

        $pairs = $this->withSchemeVariants($pairs);

        if (empty($pairs)) {

            $this->logger->log('Source and destination URLs match - no domain rewrite needed.');

            $this->job->update([
                'status' => 'running',
                'step' => 9,
                'progress' => 98,
                'message' => __('No domain rewrite needed.', 'rebuzz-backup-and-restore')
            ]);

            return true;
        }

        $rewriteState = $workspace->getJson('rewrite.state.json');

        $rewriter = new WPCB_Url_Rewriter();

        $result = $rewriter->run($pairs, $rewriteState ?: []);

        $workspace->put('rewrite.state.json', $result['state']);

        if (!$result['finished']) {

            $tableIndex = isset($result['state']['table_index']) ? (int) $result['state']['table_index'] : 0;
            $totalTables = isset($result['state']['tables']) ? count($result['state']['tables']) : 1;

            $progress = 90 + ((min($tableIndex, $totalTables) / max(1, $totalTables)) * 8);

            $this->job->update([
                'status' => 'running',
                'step' => 8,
                'progress' => (int) $progress,
                'message' => sprintf(
                    /* translators: %d: number of database rows changed so far */
                    __('Updating domain references (%d changed so far)...', 'rebuzz-backup-and-restore'),
                    $result['rows_changed']
                ),
                'urls_updated' => $result['rows_changed']
            ]);

            return true;
        }

        $this->logger->log(sprintf(
            'Domain references updated: %d row(s) changed across the database.',
            $result['rows_changed']
        ));

        $this->job->update([
            'status' => 'running',
            'step' => 9,
            'progress' => 98,
            'message' => __('Domain references updated.', 'rebuzz-backup-and-restore'),
            'urls_updated' => $result['rows_changed']
        ]);

        return true;
    }

    /**
     * Adds http:// and protocol-relative (//) forms of each source URL,
     * all pointing at the same destination URL.
     *
     * A site's stored content is very often mixed: a site recorded as
     * "https://example.com" in its own options can still hold
     * "http://example.com/..." and "//example.com/..." in post content,
     * page-builder data and cached markup, left over from before it
     * moved to HTTPS. Matching only the one form the manifest recorded
     * leaves those behind pointing at the source domain - and worse,
     * anything that rewrites just the host would leave the *old* scheme
     * in place, producing URLs like "https://localhost/site" that no
     * local server can answer, so images and other assets silently fail
     * to load.
     *
     * The http:// and https:// variants map to the destination URL
     * exactly as configured, so the scheme always ends up matching the
     * destination site rather than being inherited from the source. The
     * // variant stays protocol-relative, since that's what the page
     * was written to rely on.
     *
     * @param array $pairs search => replace, keyed by source URL.
     * @return array
     */
    private function withSchemeVariants(array $pairs)
    {
        $expanded = $pairs;

        foreach ($pairs as $search => $replace) {

            $withoutScheme = preg_replace('#^https?://#i', '', $search);

            if ($withoutScheme === null || $withoutScheme === $search) {
                // No recognisable scheme to vary - leave it alone.
                continue;
            }

            foreach (['https://', 'http://', '//'] as $scheme) {

                $variant = $scheme . $withoutScheme;

                if (!isset($expanded[$variant])) {
                    $expanded[$variant] = ($scheme === '//')
                        ? preg_replace('#^https?:(?=//)#i', '', $replace)
                        : $replace;
                }
            }
        }

        return $expanded;
    }

    /**
     * Neutralizes every other active plugin the moment the dump's
     * active_plugins value lands, since an active plugin can disrupt
     * the restore in progress (bad paths, maintenance mode, rewriting
     * .htaccess, etc.). Forces active_plugins to just this plugin;
     * the real final list is computed once and saved to job state,
     * then restored by reactivateFinalPlugins() in finish()/fail().
     * Called once, right after stepSwapDatabase() puts the imported
     * active_plugins in place.
     */
    private function isolateActivePlugins()
    {
        $state = $this->job->get();

        $ownPlugin = 'rebuzz-backup-and-restore/rebuzz-backup-and-restore.php';

        if (array_key_exists('final_active_plugins', $state)) {
            $this->forceOnlyOwnPluginActive($ownPlugin);
            return;
        }

        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL -- reads active_plugins straight from the options table because the restore has just replaced it underneath the cache.
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
                'active_plugins'
            )
        );

        /*
         * No row, or an unreadable value, means the import has dropped
         * and recreated wp_options but hasn't re-inserted active_plugins
         * yet. Returning here would leave this plugin inactive for the
         * next request - and since that request is the one meant to
         * continue this very restore, the restore strands itself
         * permanently with an unhelpful "AJAX request failed" in the
         * browser. Force ourselves active instead and let a later chunk
         * do the real computation once the source value actually lands.
         */
        if (!$row) {
            $this->forcePlaceholderActivePlugins($ownPlugin);
            return;
        }

        $active = maybe_unserialize($row->option_value);

        if (!is_array($active)) {
            $this->forcePlaceholderActivePlugins($ownPlugin);
            return;
        }

        $preRestore = $state['pre_restore_active_plugins'] ?? null;

        // Still the pre-restore value - active_plugins row not
        // reached yet by this chunk. (Old backups without this key
        // just cost one extra redundant cycle, no functional diff.)
        if (is_array($preRestore) && $active === $preRestore) {
            return;
        }

        /*
         * What's in the row right now is the placeholder this restore
         * wrote itself on an earlier chunk (see
         * forcePlaceholderActivePlugins()), not the value from the
         * dump. Computing the final plugin list from it would decide
         * that the site is supposed to end up with *only this plugin*
         * active, and finish() would then faithfully "restore" that -
         * silently switching off every other plugin on a restore that
         * otherwise succeeded. Wait for the dump's real value instead.
         */
        if (!empty($state['active_plugins_placeholder']) && $active === [$ownPlugin]) {
            return;
        }

        $computed = $this->computeFinalActivePlugins($active, $ownPlugin);

        $this->job->update([
            'final_active_plugins' => $computed['kept'],
            'missing_plugins' => $computed['missing'],
            'disabled_hosting_plugins' => $computed['hosting']
        ]);

        $this->forceOnlyOwnPluginActive($ownPlugin);

        $this->logger->log(sprintf(
            'Isolated active_plugins to just this plugin for the remainder of the restore - %d plugin(s) queued to reactivate once everything is safely restored (%d filtered out permanently: %d missing on disk, %d hosting-provider plugin(s) not applicable to this local environment).',
            count($computed['kept']),
            count($computed['missing']) + count($computed['hosting']),
            count($computed['missing']),
            count($computed['hosting'])
        ));
    }

    /**
     * Decides which imported active_plugins entries should stay
     * active. Permanently drops a plugin if its files are missing on
     * disk, or it's a known hosting-provider plugin on a local dev
     * copy (see isLocalDevEnvironment(), deliberately conservative).
     *
     * @return array{kept: string[], missing: string[], hosting: string[]}
     */
    private function computeFinalActivePlugins(array $active, $ownPlugin)
    {
        $hostingPlugins = $this->hostingPluginBasenames();
        $isLocal = $this->isLocalDevEnvironment();

        $kept = [$ownPlugin];
        $missing = [];
        $hosting = [];

        foreach ($active as $entry) {

            if (!is_string($entry)) {
                continue;
            }

            // Some hosts (e.g. Bluehost) store an absolute server
            // path here instead of "folder/file.php" - normalize it
            // or WP can never find the entry.
            $entry = $this->normalizePluginPath($entry);

            if ($entry === $ownPlugin) {
                continue;
            }

            if ($isLocal && in_array($entry, $hostingPlugins, true)) {
                $hosting[] = $entry;
                continue;
            }

            if (!file_exists(WP_PLUGIN_DIR . '/' . $entry)) {
                $missing[] = $entry;
                continue;
            }

            $kept[] = $entry;
        }

        return [
            'kept' => $kept,
            'missing' => $missing,
            'hosting' => $hosting
        ];
    }

    /**
     * Detects/fixes an absolute-path active_plugins entry (see
     * computeFinalActivePlugins()). Normal entries look like
     * "folder/file.php" and are left untouched.
     */
    private function normalizePluginPath($entry)
    {
        $looksAbsolute = (
            preg_match('#^[a-zA-Z]:[\\\\/]#', $entry) ||
            strpos($entry, '/wp-content/plugins/') !== false ||
            strpos($entry, '\\wp-content\\plugins\\') !== false
        );

        if (!$looksAbsolute) {
            return $entry;
        }

        $normalized = str_replace('\\', '/', $entry);

        $position = stripos($normalized, '/plugins/');

        if ($position === false) {
            return $entry;
        }

        $relative = substr($normalized, $position + strlen('/plugins/'));

        return $relative !== '' ? $relative : $entry;
    }

    /**
     * Forces active_plugins to just this plugin *and* records that the
     * value now in the database is this restore's own placeholder
     * rather than anything the backup contained.
     *
     * Without that record, the next chunk reads the placeholder back,
     * cannot tell it apart from the dump's imported value, and
     * concludes the site is meant to end up with only this plugin
     * active - deactivating everything else on an otherwise successful
     * restore. See isolateActivePlugins() and reactivateFinalPlugins(),
     * both of which check this flag.
     */
    private function forcePlaceholderActivePlugins($ownPlugin)
    {
        $this->forceOnlyOwnPluginActive($ownPlugin);

        $this->job->update(['active_plugins_placeholder' => true]);
    }

    /**
     * Forces active_plugins to just this plugin. See
     * isolateActivePlugins().
     *
     * Inserts the row when it's missing rather than only UPDATEing it.
     * The import drops and recreates wp_options in chunks, so there is
     * a window where the table exists but the active_plugins row hasn't
     * been re-inserted yet - and a bare UPDATE silently affects zero
     * rows there, leaving this plugin inactive. An inactive plugin
     * isn't loaded on the next request, so its AJAX handler never
     * registers and the restore it was driving can never continue.
     *
     * autoload 'yes' (not 'on') deliberately: WordPress 6.6+ accepts
     * 'yes', 'on', 'auto-on' and 'auto' as autoloaded, while older
     * versions only understand 'yes' - so 'yes' is the one value that
     * works across every supported release. active_plugins must be
     * autoloaded or WordPress won't see it during bootstrap.
     */
    private function forceOnlyOwnPluginActive($ownPlugin = 'rebuzz-backup-and-restore/rebuzz-backup-and-restore.php')
    {
        global $wpdb;

        $value = maybe_serialize([$ownPlugin]);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL -- as above - the option cache cannot be trusted mid-restore.
        $exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s",
                'active_plugins'
            )
        );

        if ($exists === null) {

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL -- insert() escapes its own values; the table is $wpdb->options.
            $wpdb->insert(
                $wpdb->options,
                [
                    'option_name'  => 'active_plugins',
                    'option_value' => $value,
                    'autoload'     => 'yes'
                ]
            );

        } else {

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL -- $wpdb->update() escapes its own values; the table is $wpdb->options.
            $wpdb->update(
                $wpdb->options,
                ['option_value' => $value],
                ['option_name' => 'active_plugins']
            );
        }

        wp_cache_flush();
    }

    /**
     * Restores the real active_plugins list computed by
     * isolateActivePlugins(). Called from finish()/fail() so the site
     * is never left with every plugin permanently switched off.
     * No-op if isolation never ran.
     */
    private function reactivateFinalPlugins()
    {
        $state = $this->job->get();

        if (array_key_exists('final_active_plugins', $state)) {

            $final = $state['final_active_plugins'];

        } elseif (
            !empty($state['active_plugins_placeholder']) &&
            !empty($state['pre_restore_active_plugins']) &&
            is_array($state['pre_restore_active_plugins'])
        ) {

            /*
             * A placeholder was written to keep this plugin loadable
             * mid-import, but the restore ended (usually failed) before
             * the dump's real active_plugins value ever arrived, so
             * there is nothing computed to restore. Returning here
             * would leave the site with only this plugin switched on.
             * Put back the list the site had before the restore began.
             */
            $final = $state['pre_restore_active_plugins'];

        } else {

            return;
        }

        if (!is_array($final)) {
            return;
        }

        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL -- $wpdb->update() escapes its own values; the table is $wpdb->options.
        $wpdb->update(
            $wpdb->options,
            ['option_value' => maybe_serialize($final)],
            ['option_name' => 'active_plugins']
        );

        wp_cache_flush();

        $this->logger->log(sprintf(
            'Reactivated %d plugin(s): %s',
            count($final),
            implode(', ', $final)
        ));
    }

    /**
     * Hosting-provider control-panel plugins that assume they're
     * running on that host's servers. Permanently filtered out (not
     * just isolated) by computeFinalActivePlugins() when this restore
     * landed on a local dev copy - see isLocalDevEnvironment().
     */
    private function hostingPluginBasenames()
    {
        return [
            'bluehost-wordpress-plugin/bluehost-wordpress-plugin.php',
            'hostinger/hostinger.php',
            'hostinger-easy-onboarding/hostinger-easy-onboarding.php',
            'sitepad-website-builder/sitepad-website-builder.php',
            'sg-cachepress/sg-cachepress.php',
            'all-in-one-wp-migration-godaddy-my-h/godaddy-my-h.php',
            'godaddy-mwp-migration/godaddy-mwp-migration.php',
            'wpaas/wpaas.php'
        ];
    }

    /**
     * Filenames (matched via basename()) of hosting mu-plugins kept
     * permanently disabled on a local dev copy - see
     * reactivateMuPlugins() for why mu-plugins need separate handling.
     */
    private function hostingMuPluginFilenames()
    {
        return [
            'endurance-page-cache.php',
            'sso.php',
        ];
    }

    /**
     * Renames every mu-plugins/*.php to *.php.disabled for the rest
     * of the restore - mu-plugins run unconditionally and
     * isolateActivePlugins() can't stop them (e.g. a host mu-plugin
     * repeatedly rewriting .htaccess mid-restore). Called right after
     * step 6, before mu-plugin files could be loaded on the next poll.
     * Filenames tracked in job state for reactivateMuPlugins() to
     * restore.
     *
     * @return array Filenames disabled this way.
     */
    private function temporarilyDisableAllMuPlugins()
    {
        $muPluginsDir = wpcb_mu_plugins_dir();

        if (!is_dir($muPluginsDir)) {
            return [];
        }

        // Seed from whatever a previous, crashed attempt at this exact
        // step already recorded, instead of starting empty - a process
        // kill mid-loop (OOM, host hard timeout) otherwise permanently
        // strands the mu-plugins already renamed before the crash: the
        // retry's glob() below only sees what's left to rename, and an
        // empty starting list would overwrite (not extend) job state,
        // losing track of those already-disabled files forever.
        $state = $this->job->get();

        $disabled = (!empty($state['disabled_mu_plugins']) && is_array($state['disabled_mu_plugins']))
            ? $state['disabled_mu_plugins']
            : [];

        /*
         * Same reasoning one level out: an earlier restore may have
         * recorded files the recovery pass still hasn't managed to
         * rename back (a permissions problem, say). Merging them in
         * rather than replacing the record means this restore's own
         * cleanup retries them - replacing it would drop them from
         * both lists at once, and glob() below never sees a file that
         * is already named *.disabled, so nothing would ever put them
         * back.
         */
        $disabled = array_values(array_unique(array_merge(
            wpcb_recorded_disabled_mu_plugins(),
            $disabled
        )));

        // Recorded (and mirrored into job state) up front as well as
        // after each rename: a crash between a rename call and the
        // record that follows it would otherwise strand that one file.
        // Anything recorded but not actually renamed is simply skipped
        // by the recovery pass.
        $this->job->update(['disabled_mu_plugins' => $disabled]);

        wpcb_record_disabled_mu_plugins($disabled);

        foreach (glob($muPluginsDir . '/*.php') ?: [] as $path) {

            if (is_link($path)) {
                continue;
            }

            if (@rename($path, $path . '.disabled')) {

                $disabled[] = basename($path);

                // Persisted after every rename, not just once when the
                // whole loop finishes - same crash-recovery reasoning
                // as above. Mirrored into a site option too, so
                // wpcb_recover_disabled_mu_plugins() can put these back
                // even when the crash was fatal enough that this job's
                // fail() path never ran at all.
                $this->job->update(['disabled_mu_plugins' => $disabled]);

                wpcb_record_disabled_mu_plugins($disabled);
            }
        }

        if (!empty($disabled)) {

            $this->logger->log(sprintf(
                'Temporarily disabled %d mu-plugin(s) for the duration of the restore: %s',
                count($disabled),
                implode(', ', $disabled)
            ));
        }

        return $disabled;
    }

    /**
     * Re-enables mu-plugins disabled by temporarilyDisableAllMuPlugins(),
     * except known hosting mu-plugins on a local dev copy (stay
     * disabled - see hostingMuPluginFilenames()). Called from
     * finish()/fail(); no-op if nothing was disabled.
     */
    private function reactivateMuPlugins()
    {
        $state = $this->job->get();

        $disabled = $state['disabled_mu_plugins'] ?? [];

        if (empty($disabled) || !is_array($disabled)) {

            // Nothing this job disabled. Anything an earlier crashed
            // attempt left recorded is handled by
            // wpcb_recover_disabled_mu_plugins() on a later request,
            // once the lock this job still holds has been released.
            return;
        }

        $muPluginsDir = wpcb_mu_plugins_dir();

        $hostingMuPlugins = $this->isLocalDevEnvironment() ? $this->hostingMuPluginFilenames() : [];

        $restored = [];
        $keptDisabled = [];
        $stillDisabled = [];

        foreach ($disabled as $filename) {

            $filename = basename((string) $filename);

            $path = $muPluginsDir . '/' . $filename . '.disabled';

            if (!file_exists($path)) {
                continue;
            }

            if (in_array($filename, $hostingMuPlugins, true)) {
                $keptDisabled[] = $filename;
                continue;
            }

            if (@rename($path, $muPluginsDir . '/' . $filename)) {
                $restored[] = $filename;
            } else {
                $stillDisabled[] = $filename;
            }
        }

        /*
         * Hand the recovery pass only what's still renamed *and* meant
         * to come back: the hosting mu-plugins in $keptDisabled are
         * deliberately left off on a local dev copy, so recording them
         * would have the next request undo that decision. An empty
         * list clears the record entirely.
         */
        wpcb_record_disabled_mu_plugins($stillDisabled);

        if (!empty($stillDisabled)) {

            $this->logger->log(sprintf(
                'Could not re-enable %d mu-plugin(s) yet (rename failed - check file permissions on the mu-plugins folder); will retry on the next request: %s',
                count($stillDisabled),
                implode(', ', $stillDisabled)
            ));
        }

        if (!empty($restored)) {
            $this->logger->log(sprintf(
                'Re-enabled %d mu-plugin(s): %s',
                count($restored),
                implode(', ', $restored)
            ));
        }

        if (!empty($keptDisabled)) {

            $this->job->update(['permanently_disabled_mu_plugins' => $keptDisabled]);

            $this->logger->log(sprintf(
                'Kept %d hosting-provider mu-plugin(s) permanently disabled (not applicable to this local environment): %s',
                count($keptDisabled),
                implode(', ', $keptDisabled)
            ));
        }
    }

    /**
     * Conservative "is this a local dev copy" check, used by
     * computeFinalActivePlugins()/reactivateMuPlugins(). Uses the
     * site URL's host, not $_SERVER.
     */
    private function isLocalDevEnvironment()
    {
        $host = strtolower((string) wp_parse_url(site_url(), PHP_URL_HOST));

        if ($host === '') {
            return false;
        }

        if (
            $host === 'localhost' ||
            $host === '127.0.0.1' ||
            $host === '::1' ||
            substr($host, -6) === '.local' ||
            substr($host, -5) === '.test'
        ) {
            return true;
        }

        // No dot at all (e.g. LAN machine name) = not a public domain.
        return strpos($host, '.') === false;
    }

    /**
     * Restores this site's real siteurl/home after the DB import
     * overwrites them with the source site's values - otherwise
     * wp-admin redirects to the wrong domain.
     */
    private function restorePreservedSiteUrl()
    {
        $state = $this->job->get();

        if (empty($state['preserve_site_url']) && empty($state['preserve_home_url'])) {
            return;
        }

        global $wpdb;

        /*
         * Only write, and only log, when the value has actually been
         * overwritten - it also runs from fail(), where nothing may have
         * changed, and a line logged for nothing buries what mattered.
         *
         * Read straight from the table rather than get_option(): the
         * import has just rewritten these rows underneath the cache.
         */
        $corrected = [];

        $preserved = [
            'siteurl' => $state['preserve_site_url'] ?? '',
            'home'    => $state['preserve_home_url'] ?? '',
        ];

        foreach ($preserved as $option => $wanted) {

            if ($wanted === '') {
                continue;
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL -- reads a single option the import has just rewritten, so the cache cannot be trusted.
            $current = $wpdb->get_var(
                $wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option)
            );

            if ($current === $wanted) {
                continue;
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL -- $wpdb->update() escapes its own values; the table is $wpdb->options.
            $wpdb->update(
                $wpdb->options,
                ['option_value' => $wanted],
                ['option_name' => $option]
            );

            $corrected[] = $option;
        }

        /*
         * Flushed unconditionally, and it must stay that way.
         *
         * The import drops and recreates wp_options, which is where this
         * job's own state transient lives. WordPress still holds the old
         * option cache, so without this flush the next set_transient()
         * compares against a stale value, concludes nothing changed and
         * writes nothing - the job state is never re-created in the new
         * table, the following request loads defaults, and the restore
         * restarts at step 0 with "Backup ZIP not found".
         *
         * That is exactly what happened when this flush was first made
         * conditional on something having been corrected. Only the
         * logging below is conditional.
         */
        wp_cache_flush();

        if (empty($corrected)) {
            return;
        }

        $this->logger->log(sprintf(
            "Preserved this site's URL after the database import overwrote it (%s).",
            implode(', ', $corrected)
        ));
    }

    /**
     * Restores the driving admin's user_login/user_pass after the DB
     * import overwrites them with the backup's admin account. Auth
     * cookie validation needs an exact user_login + password-hash-
     * salted HMAC match, which refreshAdminSession() alone can't fix
     * - without this the admin gets silently logged out mid-restore.
     */
    private function restorePreservedAdminIdentity()
    {
        $state = $this->job->get();

        if (empty($state['preserve_admin_user_id'])) {
            return;
        }

        $login = $state['preserve_admin_login'] ?? null;
        $pass = $state['preserve_admin_pass'] ?? null;

        if (empty($login) && empty($pass)) {
            return;
        }

        global $wpdb;

        $userId = (int) $state['preserve_admin_user_id'];

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL -- prepared read of a single option the restore has just overwritten.
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT user_login, user_pass FROM {$wpdb->users} WHERE ID = %d",
            $userId
        ));

        if ($row === null) {
            return;
        }

        $changes = [];

        if (!empty($login) && $row->user_login !== $login) {
            $changes['user_login'] = $login;
        }

        if (!empty($pass) && $row->user_pass !== $pass) {
            $changes['user_pass'] = $pass;
        }

        if (empty($changes)) {
            return;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL -- $wpdb->update() escapes its own values; the table is $wpdb->options.
        $wpdb->update(
            $wpdb->users,
            $changes,
            ['ID' => $userId]
        );

        wp_cache_flush();

        $this->logger->log(sprintf(
            "Preserved this site's admin login credentials after the database import changed them (fields restored: %s).",
            implode(', ', array_keys($changes))
        ));
    }

    /**
     * Re-registers the current session in wp_usermeta, which the DB
     * import drops/recreates mid-restore. Deliberately does NOT call
     * wp_set_auth_cookie() - a new token would invalidate the nonce
     * the browser is already polling with. Instead re-inserts the
     * browser's existing token (read via wp_get_session_token(),
     * which only needs wp-config.php's keys) so cookie and nonce stay
     * valid unchanged.
     */
    private function refreshAdminSession()
    {
        $userId = get_current_user_id();

        if ($userId <= 0) {
            return;
        }

        $token = wp_get_session_token();

        if (empty($token)) {
            return;
        }

        WP_Session_Tokens::get_instance($userId)->update($token, [
            'expiration' => time() + (2 * DAY_IN_SECONDS)
        ]);
    }

    const SCAN_BATCH_SIZE = 20000;

    /**
     * Step 4: walk files/ and write relative paths to core-files.txt
     * (WordPress core, for step 5 to stage - only when the backup holds
     * a whole core) and restore-files.txt (the rest, for step 6). Used to be one
     * RecursiveIteratorIterator pass, which could exceed PHP's time/
     * memory limit on large sites. Now walks via an explicit stack
     * persisted between calls (SCAN_BATCH_SIZE per call).
     */
    private function stepScanFiles()
    {
        wpcb_extend_time_limit(60);

        $startTime = microtime(true);

        $workspace = $this->workspace();

        $filesDir = $workspace->file('files');
        $root = realpath($filesDir);

        $state = $workspace->getJson('scan.state.json');

        if (empty($state)) {

            // First call: fresh file lists, seed stack with files/.
            foreach (['restore-files.txt', 'core-files.txt'] as $list) {

                $handle = $workspace->openWrite($list);

                if (!$handle) {
                    return $this->fail(__('Cannot create restore file list.', 'rebuzz-backup-and-restore'));
                }

                fclose($handle);
            }

            $state = [
                'stack' => ($root !== false && is_dir($filesDir)) ? [$filesDir] : [],
                'count' => 0,
                'core_count' => 0,
                'folders' => 0,
                // A backup without core must not get its stray root *.php files swapped in as one.
                'core' => WPCB_Core_Swap::backupHasCore($filesDir)
            ];
        }

        $stack = $state['stack'];
        $count = (int) $state['count'];
        $coreCount = (int) ($state['core_count'] ?? 0);
        $folders = isset($state['folders']) ? (int) $state['folders'] : 0;
        $withCore = !empty($state['core']);

        $handle = $workspace->openAppend('restore-files.txt');
        $coreHandle = $workspace->openAppend('core-files.txt');

        if (!$handle || !$coreHandle) {
            return $this->fail(__('Cannot open restore file list.', 'rebuzz-backup-and-restore'));
        }

        $processed = 0;

        // Each pop is one cheap unit of work; time check backstops
        // a slow single scandir() (e.g. network filesystem).
        while (
            $processed < self::SCAN_BATCH_SIZE &&
            (microtime(true) - $startTime) < self::TIME_BUDGET_SECONDS &&
            !empty($stack)
        ) {

            $path = array_pop($stack);

            if (is_dir($path)) {

                $folders++;

                $entries = @scandir($path);

                if ($entries !== false) {

                    foreach ($entries as $entry) {

                        if ($entry === '.' || $entry === '..') {
                            continue;
                        }

                        $stack[] = $path . '/' . $entry;

                        // Count pushed entries too, so one huge
                        // directory can't blow past the budget unchecked.
                        $processed++;
                    }

                } else {

                    $this->logger->log(
                        "Could not read directory, its contents were skipped: {$path}"
                    );
                }

            } else {

                $real = realpath($path);

                if ($real !== false) {

                    $relative = ltrim(
                        str_replace('\\', '/', str_replace($root, '', $real)),
                        '/'
                    );

                    // A literal newline byte in a filename (rare, but
                    // legal on POSIX filesystems) would corrupt
                    // restore-files.txt's one-path-per-line format,
                    // silently misaligning every entry after it and
                    // dropping the rest of the restore. Skipped and
                    // logged instead of written.
                    if (strpos($relative, "\n") !== false || strpos($relative, "\r") !== false) {

                        $this->logger->log(
                            "Skipped a file with a newline/carriage-return character in its path (can't be safely listed): {$relative}"
                        );

                    } else {

                        $line = $relative . PHP_EOL;

                        $isCore = $withCore && WPCB_Core_Swap::isCorePath($relative);

                        if (fwrite($isCore ? $coreHandle : $handle, $line) !== strlen($line)) {

                            fclose($handle);
                            fclose($coreHandle);

                            // Same reasoning as WPCB_Backup_Job's
                            // equivalent check: a miscounted $count
                            // here becomes restore.state.json's
                            // 'total', which stepRestoreFiles() could
                            // never reach if fewer lines actually
                            // landed than claimed.
                            return $this->fail(
                                __('Could not write to the restore file list - the disk may be full or out of space.', 'rebuzz-backup-and-restore')
                            );
                        }

                        if ($isCore) {
                            $coreCount++;
                        } else {
                            $count++;
                        }
                    }
                }
            }

            $processed++;
        }

        fclose($handle);
        fclose($coreHandle);

        $finished = empty($stack);

        $workspace->put('scan.state.json', [
            'stack' => $stack,
            'count' => $count,
            'core_count' => $coreCount,
            'folders' => $folders,
            'core' => $withCore
        ]);

        if (!$finished) {

            $this->job->update([
                'status' => 'running',
                'step' => 4,
                'progress' => 56,
                /* translators: %d: number of files found so far */
                'message' => sprintf(__('Scanning files (%d found so far)...', 'rebuzz-backup-and-restore'), $count + $coreCount)
            ]);

            return true;
        }

        foreach (['restore.state.json' => $count, 'core.state.json' => $coreCount] as $stateFile => $total) {

            $workspace->put($stateFile, [
                'position' => 0,
                'total' => $total,
                'file_offset' => 0,
                'failed' => 0
            ]);
        }

        $this->logger->log(
            "Prepared file list: {$count} files in {$folders} folder(s)" .
            ($withCore
                ? ", plus {$coreCount} WordPress core files to swap in together with the database."
                : '; the backup holds no WordPress core, so the live core is kept.')
        );

        $this->job->update([
            'status' => 'running',
            'step' => 5,
            'progress' => 57,
            /* translators: %d: total number of files about to be restored */
            'message' => sprintf(__('Preparing to restore %d files.', 'rebuzz-backup-and-restore'), $count + $coreCount),
            'folders_restored' => $folders
        ]);

        return true;
    }

    /**
     * Step 5: get the backup's core ready to swap in - straight from the
     * extracted backup where the filesystem allows, else copied in
     * batches into WPCB_Core_Swap::STAGE_DIR - then check the swap can
     * actually run. Nothing live is touched yet, so a failure here
     * leaves the site as it was; after this, step 6 overwrites files.
     */
    private function stepStageCore()
    {
        wpcb_extend_time_limit(60);

        $workspace = $this->workspace();
        $state = $workspace->getJson('core.state.json');

        if (empty($state['total'])) {

            $this->job->update([
                'status' => 'running',
                'step' => 6,
                'progress' => 60,
                'message' => __('Backup holds no WordPress core; keeping this site\'s.', 'rebuzz-backup-and-restore')
            ]);

            return true;
        }

        $core = new WPCB_Core_Swap($workspace);

        if (!$workspace->exists(WPCB_Core_Swap::PATHS)) {

            $mode = $core->prepare($workspace->file('files'));

            if ($mode === false) {
                return $this->fail(__('Could not create a folder for the restored WordPress core next to the live one - check that the WordPress root folder is writable. Your site has not been changed.', 'rebuzz-backup-and-restore'));
            }

            if ($mode === 'in_place') {

                // Nothing to copy: swap() renames core straight out of the extracted backup.
                $state['position'] = $state['total'];
                $workspace->put('core.state.json', $state);

                $this->logger->log('WordPress core will be swapped in straight from the extracted backup, which is on the same filesystem as the site.');

            } else {

                $this->logger->log('The restore workspace is on a different filesystem from the site, so WordPress core is copied next to the live one to be swapped in.');
            }
        }

        $copy = $this->copyListedFiles($workspace, 'core-files.txt', 'core.state.json', $core->stagePath());

        if ($copy === false) {
            return false;
        }

        if (!$copy['finished']) {

            $this->job->update([
                'status' => 'running',
                'step' => 5,
                'progress' => (int) (57 + (($copy['position'] / max(1, $copy['total'])) * 3)),
                'message' => sprintf(
                    /* translators: 1: number of core files staged so far, 2: total number of core files */
                    __('Preparing WordPress core %1$d/%2$d', 'rebuzz-backup-and-restore'),
                    $copy['position'],
                    $copy['total']
                )
            ]);

            return true;
        }

        // A partial core must never be swapped in.
        if ($copy['failed'] > 0) {

            return $this->fail(sprintf(
                /* translators: 1: number of WordPress core files that could not be copied, 2: path to the log file */
                __('%1$d WordPress core file(s) could not be copied - the disk may be full. See %2$s. Your site has not been changed.', 'rebuzz-backup-and-restore'),
                $copy['failed'],
                wpcb_display_path(wpcb_logs_dir() . '/restore.log')
            ));
        }

        $blocked = $core->preflight();

        if ($blocked !== null) {

            $this->logger->log('WordPress core swap preflight failed: ' . $blocked);

            return $this->fail(sprintf(
                /* translators: %s: what prevents the swap, e.g. "wp-admin is not writable" */
                __('WordPress core cannot be replaced on this server: %s. Your site has not been changed.', 'rebuzz-backup-and-restore'),
                $blocked
            ));
        }

        $this->logger->log("WordPress core staged: {$copy['total']} files, to be swapped in together with the database. Preflight passed.");

        $this->job->update([
            'status' => 'running',
            'step' => 6,
            'progress' => 60,
            'message' => __('WordPress core prepared.', 'rebuzz-backup-and-restore'),
            'core_staged' => true
        ]);

        return true;
    }

    /**
     * Step 6: copy everything but core back into place, in batches.
     */
    private function stepRestoreFiles()
    {
        wpcb_extend_time_limit(60);

        $workspace = $this->workspace();

        $filesDir = $workspace->file('files');

        if (empty($workspace->getJson('restore.state.json'))) {

            $this->job->update([
                'status' => 'running',
                'step' => 7,
                'progress' => 85,
                'message' => __('No files to restore.', 'rebuzz-backup-and-restore')
            ]);

            return true;
        }

        $copy = $this->copyListedFiles($workspace, 'restore-files.txt', 'restore.state.json', realpath(ABSPATH));

        if ($copy === false) {
            return false;
        }

        $position = $copy['position'];
        $total = $copy['total'];
        $failed = $copy['failed'];

        if (!$copy['finished']) {

            $progress = $total > 0
                ? 60 + (($position / $total) * 25)
                : 85;

            $this->job->update([
                'status' => 'running',
                'step' => 6,
                'progress' => (int) $progress,
                'message' => sprintf(
                    /* translators: 1: number of files restored so far, 2: total number of files */
                    __('Restoring files %1$d/%2$d', 'rebuzz-backup-and-restore'),
                    $position,
                    $total
                )
            ]);

            return true;
        }

        $this->logger->log("Files restored: {$position}/{$total}.");

        /*
         * Every file is now in place, so each folder on disk is whole.
         * Only now is it safe to move aside what the backup did not
         * contain: doing it before the copy left WordPress loading an
         * empty or half-written plugins directory on every AJAX request
         * of a multi-minute copy, which ended in a fatal inside a
         * half-restored plugin. See WPCB_Quarantine.
         */
        $jobState = $this->job->get();

        if (!empty($jobState['remove_extra_files']) && empty($jobState['swept'])) {

            $sweep = WPCB_Quarantine::sweepExtras($this->job->id(), $filesDir);

            $this->logger->log(sprintf(
                'Moved aside %d item(s) the backup did not contain: %s. Nothing to move in: %s.',
                $sweep['count'],
                !empty($sweep['moved']) ? implode(', ', $sweep['moved']) : 'nothing',
                !empty($sweep['skipped']) ? implode(', ', $sweep['skipped']) : 'nothing'
            ));

            $this->job->update(['swept' => true]);
        }

        // Covers anything invalidate missed (moved-aside plugins, symlinked paths); @ for opcache.restrict_api.
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        if ($failed > 0) {

            $this->logger->log(
                "{$failed} file(s) failed to restore - see entries above."
            );
        }

        // Mu-plugin files exist on disk now, so WP loads them on the
        // next poll - before the DB swap. Must disable here since
        // isolateActivePlugins() can't reach mu-plugins.
        $disabledMuPlugins = $this->temporarilyDisableAllMuPlugins();

        // 'files_failed' persists in job state (update() merges) so
        // finish() can report it later.
        $coreState = $workspace->getJson('core.state.json');

        $this->job->update([
            'status' => 'running',
            'step' => 7,
            'progress' => 85,
            'message' => __('Files restored.', 'rebuzz-backup-and-restore'),
            'files_failed' => $failed,
            'files_restored' => $position + (int) ($coreState['total'] ?? 0),
            'disabled_mu_plugins' => $disabledMuPlugins
        ]);

        return true;
    }

    /**
     * Copy the files named in $listName from the workspace to
     * $destRoot, resuming from and saving to $stateName. Bounded by
     * FILE_BATCH_SIZE/TIME_BUDGET_SECONDS per call.
     *
     * @return array{position: int, total: int, failed: int, finished: bool}|false
     *         False once fail() has been called.
     */
    private function copyListedFiles(WPCB_Restore_Workspace $workspace, $listName, $stateName, $destRoot)
    {
        $startTime = microtime(true);

        $state = $workspace->getJson($stateName);

        $position = (int) ($state['position'] ?? 0);
        $total = (int) ($state['total'] ?? 0);
        $fileOffset = isset($state['file_offset']) ? (int) $state['file_offset'] : 0;
        $failed = isset($state['failed']) ? (int) $state['failed'] : 0;

        $filesDir = $workspace->file('files');
        $ownPluginPath = $this->ownPluginRelativePath();

        if ($position < $total) {

            $handle = $workspace->openRead($listName);

            if (!$handle) {
                return $this->fail(__('Cannot read restore file list.', 'rebuzz-backup-and-restore'));
            }

            if ($fileOffset > 0) {
                fseek($handle, $fileOffset);
            }

            $processedCount = 0;
            $lastPluginOrTheme = null;

            // Read+copy in the same loop so position/fileOffset only
            // advance over finished files - safe to bail on the time
            // check (same pattern as WPCB_Zip_Batch).
            //
            // Once budget is used up, keep going past both limits
            // until the plugin/theme folder changes - never split one
            // plugin's files across two requests. Otherwise the next
            // poll's WP bootstrap could load a half-overwritten folder
            // (seen with Jetpack) and fatal with "class not found".
            while (($line = fgets($handle)) !== false) {

                $relative = trim($line);
                $pluginOrTheme = $this->pluginOrThemeKey($relative);

                $budgetExhausted = (
                    $processedCount >= self::FILE_BATCH_SIZE ||
                    (microtime(true) - $startTime) >= self::TIME_BUDGET_SECONDS
                );

                // Keep pulling past budget only while same
                // plugin/theme folder. $processedCount guards against
                // zero progress if budget's already gone at the start.
                if (
                    $processedCount > 0 &&
                    $budgetExhausted &&
                    ($lastPluginOrTheme === null || $pluginOrTheme !== $lastPluginOrTheme)
                ) {
                    break;
                }

                $fileOffset = ftell($handle);
                $lastPluginOrTheme = $pluginOrTheme;

                $position++;
                $processedCount++;

                if ($relative === '') {
                    continue;
                }

                /*
                 * wp-config.php holds this environment's DB
                 * credentials, not site content. Skipped here (not
                 * just at backup time) so an older backup with stale
                 * credentials can't break the live DB connection.
                 */
                if (in_array($relative, self::ENVIRONMENT_FILES, true)) {
                    continue;
                }

                /*
                 * Never overwrite this plugin's own running code with
                 * a captured copy from the backup - it's the code
                 * currently executing this very restore. Unlike
                 * ENVIRONMENT_FILES this is a folder prefix match, not
                 * an exact-name one.
                 */
                if (
                    $ownPluginPath !== '' &&
                    ($relative === $ownPluginPath || strpos($relative, $ownPluginPath . '/') === 0)
                ) {
                    continue;
                }

                $source = $filesDir . '/' . $relative;
                $destination = $destRoot . '/' . $relative;

                if (!file_exists($source)) {

                    // Listed by the scan but absent from the workspace - extraction dropped it.
                    $this->logger->log("Missing from extracted backup, not restored: {$relative}");

                    $failed++;

                    continue;
                }

                $destinationDir = dirname($destination);

                if (!is_dir($destinationDir)) {
                    wp_mkdir_p($destinationDir);
                }

                if (!@copy($source, $destination)) {

                    $this->logger->log(
                        "Failed to restore file: {$relative}"
                    );

                    $failed++;

                } elseif (
                    function_exists('opcache_invalidate') &&
                    substr($relative, -4) === '.php'
                ) {
                    // Else the next request runs the cached old code against the new files and fatals.
                    @opcache_invalidate($destination, true);
                }

                // See CHECKPOINT_INTERVAL's docblock: bounds how much
                // progress a process kill can lose during the
                // same-folder overtime case above.
                if ($processedCount % self::CHECKPOINT_INTERVAL === 0) {

                    $workspace->put($stateName, [
                        'position' => $position,
                        'total' => $total,
                        'file_offset' => $fileOffset,
                        'failed' => $failed
                    ]);
                }
            }

            fclose($handle);
        }

        $workspace->put($stateName, [
            'position' => $position,
            'total' => $total,
            'file_offset' => $fileOffset,
            'failed' => $failed
        ]);

        return [
            'position' => $position,
            'total' => $total,
            'failed' => $failed,
            'finished' => ($position >= $total)
        ];
    }

    /**
     * "wp-content/uploads/sites" relative to ABSPATH, resolved from
     * wp_upload_dir() rather than hardcoded - used by stepValidate() to
     * locate other sites' media inside the extracted backup. A custom
     * UPLOADS constant or non-default uploads location would otherwise
     * silently defeat that cross-site protection, since the hardcoded
     * path would never exist in the workspace to check.
     */
    private function uploadsSitesRelativePath()
    {
        $upload = wp_upload_dir();
        $basedir = str_replace('\\', '/', $upload['basedir']);
        $root = rtrim(str_replace('\\', '/', ABSPATH), '/');

        if (strpos($basedir . '/', $root . '/') !== 0) {
            return 'wp-content/uploads/sites';
        }

        $relativeUploads = trim(substr($basedir, strlen($root)), '/');

        // On a subsite, this site's own basedir already ends in
        // "/sites/{id}" - strip that back off to the shared root, since
        // "sites/" (not "sites/{id}/") is what holds every OTHER site's
        // media too.
        $sitesSuffixPos = strpos($relativeUploads, '/sites/');

        if ($sitesSuffixPos !== false) {
            $relativeUploads = substr($relativeUploads, 0, $sitesSuffixPos);
        }

        return $relativeUploads . '/sites';
    }

    /**
     * This plugin's own folder, relative to ABSPATH (e.g.
     * "wp-content/plugins/rebuzz-backup-and-restore") - see stepRestoreFiles()
     * for why it's excluded from restore. Resolved from WP_PLUGIN_DIR
     * rather than hardcoded, so a customized plugins directory still
     * matches. Returns '' if it can't be resolved (restore then simply
     * skips this exclusion rather than failing).
     */
    private function ownPluginRelativePath()
    {
        $pluginDir = defined('WP_PLUGIN_DIR') ? WP_PLUGIN_DIR : WP_CONTENT_DIR . '/plugins';

        $pluginDir = rtrim(str_replace('\\', '/', $pluginDir), '/');
        $root = rtrim(str_replace('\\', '/', ABSPATH), '/');

        if (strpos($pluginDir . '/', $root . '/') !== 0) {
            return '';
        }

        $relativePluginDir = trim(substr($pluginDir, strlen($root)), '/');

        return $relativePluginDir . '/rebuzz-backup-and-restore';
    }

    /**
     * Plugin/theme folder a file belongs to (e.g.
     * "wp-content/plugins/jetpack"), or null otherwise. Used by
     * stepRestoreFiles() to avoid splitting one plugin's files across
     * requests - only plugins/themes get bootstrap-loaded, so only
     * they can crash mid-copy.
     */
    private function pluginOrThemeKey($relative)
    {
        $parts = explode('/', $relative);

        if (
            count($parts) >= 3 &&
            $parts[0] === 'wp-content' &&
            ($parts[1] === 'plugins' || $parts[1] === 'themes')
        ) {
            return $parts[0] . '/' . $parts[1] . '/' . $parts[2];
        }

        return null;
    }

    /**
     * Clears stale update-check transients (old site's plugin/theme
     * paths and versions) and flushes rewrite rules (imported
     * 'rewrite_rules' reflects the source site's setup, which can
     * 404 real posts here). Runs once after a successful restore.
     */
    private function restoreCleanup()
    {
        delete_site_transient('update_plugins');
        delete_site_transient('update_themes');
        delete_site_transient('update_core');

        delete_transient('update_plugins');
        delete_transient('update_themes');
        delete_transient('update_core');

        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL -- fixed LIKE pattern against $wpdb->options, no request data involved.
        $stalePluginUpdateTransients = $wpdb->get_col(
            "SELECT option_name FROM {$wpdb->options} " .
            "WHERE option_name LIKE '\\_transient\\_wp\\_plugin\\_update\\_%' " .
            "OR option_name LIKE '\\_transient\\_timeout\\_wp\\_plugin\\_update\\_%'"
        );

        foreach ($stalePluginUpdateTransients as $optionName) {
            delete_option($optionName);
        }

        wp_cache_flush();

        flush_rewrite_rules();

        $this->logger->log('Cleared update caches and refreshed permalink rewrite rules.');

        $this->clearPageBuilderCaches();
    }

    /**
     * Clears page-builder caches that bake absolute URLs into generated
     * files on disk.
     *
     * Elementor's "load Google Fonts locally" option writes CSS files
     * containing the *source* site's absolute font URLs, and its
     * per-page CSS can do the same. Those are files, not database
     * values, so the URL rewrite in stepRewriteUrls() cannot reach
     * them: after a migrating restore they still point at the old
     * domain, every font request fails cross-origin, and the site
     * renders in fallback fonts whose character widths differ just
     * enough to shift layouts. That reads as "the restore broke my
     * theme" rather than "a cache needs regenerating", so it's worth
     * clearing automatically rather than leaving as a manual step.
     *
     * Deliberately reimplements what Elementor's own
     * Google_Font::clear_cache() and "Regenerate CSS & Data" tool do,
     * instead of calling them: this runs while every other plugin is
     * still isolated for the restore, so Elementor's classes aren't
     * loaded and its API isn't callable. Everything removed here is
     * regenerated on demand by Elementor on the next page load.
     */
    private function clearPageBuilderCaches()
    {
        $upload = wp_upload_dir();

        $elementorDir = $upload['basedir'] . '/elementor';

        $cleared = [];

        /*
         * No early return when this directory is absent: Elementor can
         * be configured to embed its CSS inline instead of writing
         * files, in which case there is no elementor/ upload directory
         * at all - but the per-post metadata below still exists and
         * still carries the source site's stale asset/font lists.
         */
        if (is_dir($elementorDir)) {

            foreach (['css', 'google-fonts'] as $subdirectory) {

                $path = $elementorDir . '/' . $subdirectory;

                if (!is_dir($path)) {
                    continue;
                }

                /*
                 * Only the generated caches - index.php/.htaccess/
                 * web.config in these folders are Elementor's own
                 * directory-listing guards, and it only recreates them
                 * when it creates the directory, not when it
                 * regenerates a file into an existing one. Wiping them
                 * would leave the folder browsable.
                 */
                $guards = ['index.php', '.htaccess', 'web.config'];

                foreach (glob($path . '/*') ?: [] as $entry) {

                    if (in_array(basename($entry), $guards, true)) {
                        continue;
                    }

                    wpcb_delete_path($entry);
                }

                $cleared[] = $subdirectory;
            }
        }

        /*
         * Elementor's record of which fonts it has already downloaded
         * locally. It's checked before the files are, so leaving it
         * behind makes Elementor keep enqueueing the CSS paths above
         * even though they've just been deleted - no fonts at all,
         * instead of regenerated ones.
         */
        delete_option('_elementor_local_google_fonts');

        /*
         * Deleting the CSS files alone is not enough. Elementor also
         * records, per post, which stylesheets and *which fonts* that
         * page needs. Regenerating a CSS file does not rebuild those
         * records, so a restored site keeps the source environment's
         * asset lists: the page's CSS asks for a font (say Manrope)
         * that is never enqueued, the browser silently substitutes a
         * fallback with different metrics, and the layout shifts on
         * exactly the pages using that font - while pages on the
         * default fonts look perfect, which makes it read as a random
         * theme bug rather than a stale cache.
         *
         * These are the same keys Elementor's own
         * Files_Manager::clear_cache() removes; see that method's
         * docblock reference in this function's header for why they're
         * reimplemented rather than called.
         */
        $metaKeys = [
            '_elementor_css',
            '_elementor_element_cache',
            '_elementor_page_assets'
        ];

        foreach ($metaKeys as $metaKey) {
            delete_post_meta_by_key($metaKey);
        }

        $this->logger->log(sprintf(
            'Cleared Elementor caches so they regenerate against this site\'s own URL: %s, plus its local-font record and per-post asset/font metadata.',
            empty($cleared)
                ? 'no generated CSS/font directories present'
                : 'generated directories (' . implode(', ', $cleared) . ')'
        ));
    }

    /**
     * Step 9+: clean up the (potentially large) extracted workspace
     * and report success.
     */
    private function finish()
    {
        $state = $this->job->get();

        if (!empty($state['workspace'])) {

            $workspace = new WPCB_Restore_Workspace($state['workspace']);
            $workspace->cleanup();
        }

        // Normally already gone; catches anything a retried swap left behind.
        (new WPCB_Database())->dropWorkTables();

        // The replaced core; the database no longer matches it.
        WPCB_Core_Swap::removeLeftovers();

        $this->restoreCleanup();

        // The flush above ran before the restored plugins loaded, so their rewrite rules may be missing.
        wpcb_request_permalink_resave();

        // The restored database brought back the schedule's cron events and state from backup time.
        WPCB_Scheduler::afterRestore();

        /*
         * Succeeded, so the renamed copies stop being something to roll
         * back and become something for the admin to delete when they
         * are satisfied - never deleted automatically here.
         */
        if (class_exists('WPCB_Quarantine')) {
            WPCB_Quarantine::markCompleted();
        }

        // Restore's done - put the real plugin list back (see
        // isolateActivePlugins()). Re-fetch state after: buildSummary()
        // needs reactivateMuPlugins()'s permanently-disabled list.
        $this->reactivateFinalPlugins();
        $this->reactivateMuPlugins();

        $state = $this->job->get();

        wpcb_restore_lock_release();

        $failed = isset($state['files_failed']) ? (int) $state['files_failed'] : 0;

        $summary = $this->buildSummary($state, $failed);

        if ($failed > 0) {

            // Surface skipped files instead of reporting clean
            // success - silent skips later look like a random plugin bug.
            $message = sprintf(
                /* translators: 1: number of files that could not be copied during the restore, 2: path to the log file, relative to the WordPress root */
                __('Restore completed, but %1$d file(s) could not be copied and were skipped. Check %2$s for the list, and consider re-uploading/replacing those files manually.', 'rebuzz-backup-and-restore'),
                $failed,
                wpcb_display_path(wpcb_logs_dir() . '/restore.log')
            ) . "\n\n" . $summary;

            $this->logger->log($message);

            $this->job->update([
                'status' => 'completed',
                'progress' => 100,
                'message' => $message
            ]);

            return true;
        }

        $message = __('Restore completed successfully.', 'rebuzz-backup-and-restore') . "\n\n" . $summary;

        $this->logger->log($message);

        $this->job->update([
            'status' => 'completed',
            'progress' => 100,
            'message' => $message
        ]);

        return true;
    }

    /**
     * "Xm Ys" (or "Xs" under a minute) - human_time_diff() rounds
     * sub-minute durations up to "1 minute", which reads wrong here.
     */
    private function formatDuration($seconds)
    {
        $seconds = max(0, $seconds);

        if ($seconds < 60) {
            return $seconds . 's';
        }

        $minutes = (int) floor($seconds / 60);
        $remainder = $seconds % 60;

        return $minutes . 'm ' . $remainder . 's';
    }

    /**
     * Human-readable recap shown on completion, built from state
     * already tracked step by step.
     */
    private function buildSummary(array $state, $failed)
    {
        $lines = [];

        /* translators: %d: number of files restored */
        $lines[] = sprintf(__('Files restored: %d', 'rebuzz-backup-and-restore'), (int) ($state['files_restored'] ?? 0));
        /* translators: %d: number of folders restored */
        $lines[] = sprintf(__('Folders restored: %d', 'rebuzz-backup-and-restore'), (int) ($state['folders_restored'] ?? 0));
        /* translators: %d: number of database tables restored */
        $lines[] = sprintf(__('Database tables restored: %d', 'rebuzz-backup-and-restore'), (int) ($state['tables_restored'] ?? 0));
        /* translators: %d: number of database rows with URL references updated */
        $lines[] = sprintf(__('URL references updated: %d', 'rebuzz-backup-and-restore'), (int) ($state['urls_updated'] ?? 0));

        if (isset($state['elapsed'])) {
            /* translators: %s: how long the restore took, e.g. "2m 30s" */
            $lines[] = sprintf(__('Duration: %s', 'rebuzz-backup-and-restore'), $this->formatDuration((int) $state['elapsed']));
        }

        $warnings = [];

        if ($failed > 0) {
            /* translators: %d: number of files that could not be copied */
            $warnings[] = sprintf(__('%d file(s) could not be copied', 'rebuzz-backup-and-restore'), $failed);
        }

        if (!empty($state['deferred_failed'])) {
            $warnings[] = sprintf(
                /* translators: 1: number of database views/triggers/routines, 2: path to the log file, relative to the WordPress root */
                __('%1$d database view/trigger/routine statement(s) could not be recreated - see %2$s', 'rebuzz-backup-and-restore'),
                (int) $state['deferred_failed'],
                wpcb_display_path(wpcb_logs_dir() . '/restore.log')
            );
        }

        if (!empty($state['missing_plugins'])) {
            $warnings[] = sprintf(
                /* translators: %s: comma-separated list of plugin files */
                __('plugin(s) deactivated (no matching files in this backup): %s', 'rebuzz-backup-and-restore'),
                implode(', ', $state['missing_plugins'])
            );
        }

        if (!empty($state['disabled_hosting_plugins'])) {
            $warnings[] = sprintf(
                /* translators: %s: comma-separated list of hosting-provider plugin files */
                __('hosting-provider plugin(s) deactivated (not applicable to this local environment): %s', 'rebuzz-backup-and-restore'),
                implode(', ', $state['disabled_hosting_plugins'])
            );
        }

        if (!empty($state['permanently_disabled_mu_plugins'])) {
            $warnings[] = sprintf(
                /* translators: %s: comma-separated list of hosting-provider mu-plugin filenames */
                __('hosting-provider mu-plugin(s) disabled (renamed with a .disabled suffix, not applicable to this local environment): %s', 'rebuzz-backup-and-restore'),
                implode(', ', $state['permanently_disabled_mu_plugins'])
            );
        }

        if (
            !empty($state['backup_plugin_version']) &&
            $state['backup_plugin_version'] !== WPCB_VERSION
        ) {
            $warnings[] = sprintf(
                /* translators: 1: plugin version the backup was made with, 2: plugin version this site is running */
                __('this backup was made with plugin version %1$s (this site is running %2$s)', 'rebuzz-backup-and-restore'),
                $state['backup_plugin_version'],
                WPCB_VERSION
            );
        }

        /* translators: %d: number of warnings */
        $lines[] = sprintf(__('Warnings: %d', 'rebuzz-backup-and-restore'), count($warnings));

        foreach ($warnings as $warning) {
            $lines[] = ' - ' . $warning;
        }

        return implode("\n", $lines);
    }

    /**
     * Marks the job failed. Workspace deliberately left in place for
     * post-mortem inspection.
     */
    private function fail($message)
    {
        $this->logger->log('Restore failed: ' . $message);

        $this->cleanupAfterFailure();

        $this->job->update([
            'status' => 'failed',
            'message' => $message
        ]);

        return false;
    }

    /**
     * Put the site back into a working state after a failed restore.
     * Shared by fail() and the crash handler in
     * WPCB_Admin::guardAgainstFatalError(), so a PHP fatal no longer
     * leaves every plugin but this one switched off.
     */
    public function cleanupAfterFailure()
    {
        $state = $this->job->get();

        // Before the swap the live database was never touched; the staging copy is just clutter.
        if (empty($state['db_swapped'])) {
            (new WPCB_Database())->dropWorkTables(WPCB_Database::STAGE_PREFIX);
        }

        $this->undoCoreSwap($state);

        /*
         * Put any renamed-aside directories back before anything else:
         * reactivateFinalPlugins() below writes active_plugins, and that
         * list has to describe a plugins directory that actually holds
         * those plugins again.
         */
        if (class_exists('WPCB_Quarantine')) {

            if (WPCB_Quarantine::rollback()) {
                $this->logger->log('Moved the renamed-aside directories back after the failed restore.');
            }
        }

        // If the swap put the backup's wp_options in place before the
        // failure, siteurl/home may now point at the source domain. Fix
        // here too so a failed restore never strands the site there.
        $this->restorePreservedSiteUrl();
        $this->restorePreservedAdminIdentity();

        // Same reasoning: undo any plugin/mu-plugin isolation now,
        // don't leave the site stuck that way on failure.
        $this->reactivateFinalPlugins();
        $this->reactivateMuPlugins();

        // Release the lock now so a failure never blocks future
        // restores (belt-and-braces with wpcb_restore_lock_check()).
        wpcb_restore_lock_release();
    }

    /**
     * Root *.php files in core-files.txt, minus ENVIRONMENT_FILES -
     * what step 5 staged beside wp-admin and wp-includes.
     *
     * @return string[]
     */
    private function coreRootFiles(WPCB_Restore_Workspace $workspace)
    {
        $files = [];

        foreach (file($workspace->file('core-files.txt'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $relative) {

            $relative = trim($relative);

            if (strpos($relative, '/') === false && !in_array($relative, self::ENVIRONMENT_FILES, true)) {
                $files[] = $relative;
            }
        }

        return $files;
    }

    /**
     * Core swapped in but the database not (a crash between the two):
     * put the previous core back so it matches the database again.
     * Once the database is swapped too, the new core stays.
     */
    private function undoCoreSwap(array $state)
    {
        // No workspace() here: it would create an empty one.
        if (empty($state['workspace']) || !is_dir($state['workspace'])) {
            return;
        }

        $core = new WPCB_Core_Swap(new WPCB_Restore_Workspace($state['workspace']));

        $undone = (empty($state['db_swapped']) && !$core->isCommitted()) ? $core->rollback() : null;

        if ($undone === false) {

            $this->logger->log('Could not put the previous WordPress core back after the failed restore. It is in ' . $core->oldPath() . ' - move wp-admin, wp-includes and the *.php files from there back into the WordPress root folder.');

            return;
        }

        if ($undone) {
            $this->logger->log('Put the previous WordPress core back after the failed restore.');
        }

        WPCB_Core_Swap::removeLeftovers();
    }
}
