<?php

if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.WP.AlternativeFunctions -- WP_Filesystem has no streaming API; the dump is written and re-read in chunks.
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- a dump must read the schema and rows directly; caching it would be meaningless.
// phpcs:disable WordPress.DB.PreparedSQL -- table/column identifiers cannot be placeholders; they come from SHOW TABLES, not from a request.
// phpcs:disable PluginCheck.Security.DirectDB -- as above.

class WPCB_Database
{
    /**
     * Most rows exported per SELECT; the real limit is BATCH_TARGET_BYTES.
     */
    const BATCH_SIZE = 500;

    /**
     * Approx bytes of row data per export SELECT - a fixed row count
     * ran out of memory on tables with big rows (serialized options,
     * page-builder postmeta).
     */
    const BATCH_TARGET_BYTES = 8388608;

    /**
     * Name prefixes of restore work tables: the backup is imported into
     * staging tables and swapped in with one RENAME (see
     * WPCB_Restore_Job::stepSwapDatabase()). Never exported or rewritten.
     */
    const STAGE_PREFIX = 'wpcbs_';
    const OLD_PREFIX = 'wpcbo_';

    /**
     * Matches a DEFINER clause's value: `user`@`host`, 'user'@'host',
     * user@host or CURRENT_USER.
     */
    const DEFINER_VALUE = '(?:CURRENT_USER(?:\s*\(\s*\))?|(?:`[^`]*`|\'[^\']*\'|"[^"]*"|[\w.%$-]+)(?:\s*@\s*(?:`[^`]*`|\'[^\']*\'|"[^"]*"|[\w.%:$-]+))?)';

    /**
     * Max seconds import() spends executing statements per call.
     * Kept under stepStageDatabase()'s set_time_limit(60) so a slow chunk bails before PHP fatals.
     */
    const IMPORT_TIME_BUDGET_SECONDS = 15;

    /**
     * Max seconds export() spends per call - same headroom under
     * stepExportDatabase()'s set_time_limit(60) as IMPORT_TIME_BUDGET_SECONDS.
     */
    const EXPORT_TIME_BUDGET_SECONDS = 15;

    /** Why the export's last table failed, for the log; empty for a failed write. */
    private $exportError = '';

    /**
     * Export the database to $destination one chunk per call: resumes
     * from $state and returns after about EXPORT_TIME_BUDGET_SECONDS, so
     * no single request can hit PHP's time limit however big the site is.
     *
     * @param array $state The previous call's 'state'; empty to start.
     * @return array{finished:bool,state:array,done:int,total:int}|false
     *         False if the dump could not be completed - the reason is
     *         logged and the partial dump deleted.
     */
    public function export($destination, array $state = [])
    {
        global $wpdb;

        $startTime = microtime(true);

        if (empty($state)) {

            $directory = dirname($destination);

            if (!is_dir($directory)) {
                wp_mkdir_p($directory);
            }

            $handle = fopen($destination, 'w');

            if (!$handle) {
                return false;
            }

            $state = $this->exportPlan($wpdb);

        } else {

            $handle = fopen($destination, 'c');

            // Cut whatever a killed request wrote past the last saved point, so a retry can't write rows twice.
            if (!$handle || !ftruncate($handle, (int) $state['bytes']) || fseek($handle, 0, SEEK_END) !== 0) {

                if ($handle) {
                    fclose($handle);
                }

                return $this->abortExport($destination, '(resume)', 'the partial dump could not be reopened');
            }
        }

        $tableCount = count($state['tables']);
        $total = $tableCount + count($state['views']);

        // A consistent snapshot within this chunk; across chunks, keyset
        // pagination on the primary key keeps rows from being written twice.
        $wpdb->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $wpdb->query('START TRANSACTION');

        $this->exportError = '';
        $failure = null;

        while ($state['index'] < $total && (microtime(true) - $startTime) < self::EXPORT_TIME_BUDGET_SECONDS) {

            $index = (int) $state['index'];

            // Views last: their definitions select from the tables.
            if ($index < $tableCount) {

                $name = $state['tables'][$index];
                $result = $this->exportTableChunk($wpdb, $handle, $name, $state['cursor'], $startTime);

            } else {

                $name = $state['views'][$index - $tableCount];
                $result = $this->exportView($wpdb, $handle, $name);
            }

            if ($result === false) {

                $failure = $name;

                break;
            }

            if ($result === true) {

                $state['index']++;
                $state['cursor'] = null;

            } else {

                $state['cursor'] = $result;
            }
        }

        $wpdb->query('COMMIT');

        // fflush()/fclose() can surface a disk that filled up after the last fwrite().
        if ($failure === null && !fflush($handle)) {
            $failure = '(flush)';
        }

        $state['bytes'] = ftell($handle);

        if (!fclose($handle) && $failure === null) {
            $failure = '(flush on close)';
        }

        if ($failure !== null) {
            return $this->abortExport(
                $destination,
                $failure,
                $this->exportError !== '' ? $this->exportError : 'the dump could not be written - the disk may be full or the temp folder may not be writable'
            );
        }

        return [
            'finished' => $state['index'] >= $total,
            'state' => $state,
            'done' => (int) $state['index'],
            'total' => $total,
        ];
    }

    /** What to export, fixed on the first call so every chunk works through the same list. */
    private function exportPlan($wpdb)
    {
        list($tables, $views) = $this->tablesAndViews($wpdb);

        // Multisite: unscoped SHOW TABLES would leak every other site's
        // tables, incl. shared wp_users/wp_usermeta password hashes.
        if (is_multisite()) {

            $networkTableCount = count($tables);

            $multisite = new WPCB_Multisite();

            $tables = $multisite->tablesForSite($tables, get_current_blog_id());
            $views = $multisite->tablesForSite($views, get_current_blog_id());

            (new WPCB_Logger('backup'))->log(sprintf(
                "Multisite: scoped database export to this site's own %d table(s) (of %d on the network).",
                count($tables),
                $networkTableCount
            ));
        }

        return [
            'tables' => array_values($tables),
            'views' => array_values($views),
            'index' => 0,
            'cursor' => null,
            'bytes' => 0,
        ];
    }

    /**
     * Log why the export stopped and delete the partial dump - a
     * truncated dump restores a site with tables missing or half-populated.
     *
     * @return false
     */
    private function abortExport($destination, $table, $reason)
    {
        (new WPCB_Logger('backup'))->log(sprintf(
            'Database export aborted at table %s: %s.',
            $table,
            $reason
        ));

        if (file_exists($destination)) {
            wp_delete_file($destination);
        }

        return false;
    }

    /**
     * fwrite() that reports a short or failed write instead of letting
     * it pass unnoticed.
     *
     * fwrite() returns the number of bytes written, or false; on a full
     * disk or a quota limit it can write fewer bytes than asked without
     * raising anything. Every write in this class goes through here so
     * that a dump can never be reported as a successful backup while
     * actually being truncated mid-statement.
     *
     * @return bool True if the whole string was written.
     */
    private function write($handle, $data)
    {
        $length = strlen($data);

        if ($length === 0) {
            return true;
        }

        $written = fwrite($handle, $data);

        return ($written !== false && $written === $length);
    }

    /**
     * Export one table, or as much of it as fits in this call's budget.
     *
     * @param array|null $cursor Where the previous call stopped; null to start the table.
     * @return array|bool True when the table is done, a cursor to resume
     *                    from, or false on failure (see $exportError).
     */
    private function exportTableChunk($wpdb, $handle, $table, $cursor, $startTime)
    {
        if ($cursor === null) {

            $create = $wpdb->get_row(
                "SHOW CREATE TABLE `$table`",
                ARRAY_N
            );

            // No CREATE statement means no way to recreate the table, so
            // the dump would restore this table as missing rather than
            // empty - fail rather than write a schema-less section.
            if (!is_array($create) || !isset($create[1])) {

                $error = $wpdb->last_error;

                // Dropped since the export began (chunks span requests) - nothing left to back up.
                if (!$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)))) {

                    (new WPCB_Logger('backup'))->log("Skipped table {$table}: it was dropped while the backup was running.");

                    return true;
                }

                $this->exportError = 'its definition could not be read (' . ($error !== '' ? $error : 'no CREATE TABLE returned') . ')';

                return false;
            }

            $section =
                "\n\n" .
                "-- ---------------------------------\n" .
                "-- Table: {$table}\n" .
                "-- ---------------------------------\n\n" .
                "DROP TABLE IF EXISTS `{$table}`;\n" .
                $create[1] . ";\n\n";

            if (!$this->write($handle, $section)) {
                return false;
            }

            $cursor = $this->tableCursor($wpdb, $table);
        }

        while ((microtime(true) - $startTime) < self::EXPORT_TIME_BUDGET_SECONDS) {

            $rows = $wpdb->get_results($this->batchQuery($wpdb, $table, $cursor), ARRAY_A);

            $error = $wpdb->last_error;

            // get_results() hands back a copy; drop wpdb's own so a batch isn't held twice.
            $wpdb->flush();

            // A failed SELECT returns no rows - without this the rest of the table would be silently left out.
            if ($error !== '') {

                $this->exportError = 'reading its rows failed (' . $error . ')';

                return false;
            }

            if (empty($rows)) {
                return $this->write($handle, "\n");
            }

            if (!$this->writeRows($wpdb, $handle, $table, $rows)) {
                return false;
            }

            $fetched = count($rows);
            $requested = $cursor['batch'];
            $cursor['batch'] = wpcb_adapt_batch_rows($requested, $this->rowsBytes($rows), self::BATCH_TARGET_BYTES, self::BATCH_SIZE);

            if (!empty($cursor['keys'])) {

                $last = end($rows);

                // base64: the saved state is JSON, which can't hold a binary key.
                $cursor['after'] = array_map(function ($column) use ($last) {
                    return base64_encode((string) $last[$column]);
                }, $cursor['keys']);

            } else {

                $cursor['offset'] += $fetched;
            }

            unset($rows);

            // A short batch is the last one - saves a final empty query.
            if ($fetched < $requested) {
                return $this->write($handle, "\n");
            }
        }

        return $cursor;
    }

    /**
     * Where to start reading a table: after the last primary key written
     * (stable while rows change between chunks, and no slower on the
     * millionth row), or by OFFSET for a table without one.
     */
    private function tableCursor($wpdb, $table)
    {
        $keys = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SHOW KEYS FROM `$table` WHERE Key_name = %s",
                'PRIMARY'
            ),
            ARRAY_A
        );

        usort($keys, function ($a, $b) {
            return $a['Seq_in_index'] <=> $b['Seq_in_index'];
        });

        $types = [];

        foreach ((array) $wpdb->get_results("SHOW COLUMNS FROM `$table`", ARRAY_A) as $column) {
            $types[$column['Field']] = $column['Type'];
        }

        $columns = array_map(function ($key) {
            return $key['Column_name'];
        }, $keys);

        // Integers are compared unquoted - as strings they'd be compared as doubles, which loses precision past 2^53.
        $integer = array_map(function ($column) use ($types) {
            return isset($types[$column]) && (bool) preg_match('/^(?:tiny|small|medium|big)?int\b/i', $types[$column]);
        }, $columns);

        return [
            'keys' => $columns,
            'integer' => $integer,
            'after' => null,
            'offset' => 0,
            'batch' => wpcb_initial_batch_rows($table, self::BATCH_TARGET_BYTES, self::BATCH_SIZE),
        ];
    }

    /** The SELECT for a table's next batch - see tableCursor(). */
    private function batchQuery($wpdb, $table, array $cursor)
    {
        if (empty($cursor['keys'])) {
            return $wpdb->prepare("SELECT * FROM `$table` LIMIT %d OFFSET %d", $cursor['batch'], $cursor['offset']);
        }

        $columns = array_map(function ($column) {
            return '`' . $column . '`';
        }, $cursor['keys']);

        $where = '';

        if ($cursor['after'] !== null) {

            $values = [];

            foreach ($cursor['after'] as $i => $encoded) {

                $value = base64_decode($encoded);

                $values[] = ($cursor['integer'][$i] && preg_match('/^-?\d+$/', $value)) ? $value : $wpdb->prepare('%s', $value);
            }

            // (a > x) OR (a = x AND b > y) ... - spelled out, as older MariaDB can't use the index for (a, b) > (x, y).
            $terms = [];

            foreach ($columns as $i => $column) {

                $term = [];

                for ($j = 0; $j < $i; $j++) {
                    $term[] = "{$columns[$j]} = {$values[$j]}";
                }

                $term[] = "{$column} > {$values[$i]}";

                $terms[] = '(' . implode(' AND ', $term) . ')';
            }

            $where = ' WHERE ' . implode(' OR ', $terms);
        }

        return "SELECT * FROM `$table`{$where} ORDER BY " . implode(',', $columns) . sprintf(' LIMIT %d', $cursor['batch']);
    }

    /** Total length of every value in $rows - the batch's real size. */
    private function rowsBytes(array $rows)
    {
        $bytes = 0;

        foreach ($rows as $row) {
            foreach ($row as $value) {
                $bytes += strlen((string) $value);
            }
        }

        return $bytes;
    }

    /**
     * Base tables and views, split apart - a view dumped as a table
     * restored its rows into the underlying table a second time, or
     * failed the whole import on a non-updatable view. Restore work
     * tables are left out of both.
     *
     * @return array{0: string[], 1: string[]} [tables, views]
     */
    private function tablesAndViews($wpdb)
    {
        $tables = [];
        $views = [];

        foreach ((array) $wpdb->get_results('SHOW FULL TABLES', ARRAY_N) as $row) {

            $name = (string) $row[0];

            if (self::isWorkTable($name)) {
                continue;
            }

            if (isset($row[1]) && strtoupper($row[1]) === 'VIEW') {
                $views[] = $name;
            } else {
                $tables[] = $name;
            }
        }

        return [$tables, $views];
    }

    /** A restore's staging or swapped-out table, not site data. */
    public static function isWorkTable($name)
    {
        return strpos($name, self::STAGE_PREFIX) === 0 || strpos($name, self::OLD_PREFIX) === 0;
    }

    /**
     * Export one view's definition - never its rows. DEFINER is removed
     * (the account rarely exists on the destination server), and so is
     * this database's name, so the view recreates under any name.
     *
     * @return bool False only if a write failed; an unreadable view is
     *              logged and skipped rather than failing the backup.
     */
    private function exportView($wpdb, $handle, $view)
    {
        $create = $wpdb->get_row("SHOW CREATE VIEW `$view`", ARRAY_N);

        if (!is_array($create) || !isset($create[1])) {

            (new WPCB_Logger('backup'))->log(sprintf(
                'Skipped view %s: its definition could not be read (%s). The backup is otherwise complete; recreate this view by hand after a restore.',
                $view,
                $wpdb->last_error !== '' ? $wpdb->last_error : 'no definition returned'
            ));

            return true;
        }

        $definition = str_replace('`' . DB_NAME . '`.', '', self::stripDefiner($create[1]));

        return $this->write(
            $handle,
            "\n\n" .
            "-- ---------------------------------\n" .
            "-- View: {$view}\n" .
            "-- ---------------------------------\n\n" .
            "DROP VIEW IF EXISTS `{$view}`;\n" .
            $definition . ";\n\n"
        );
    }

    /**
     * Header of a CREATE for a view, trigger or stored routine, up to
     * and including the object keyword. Only the clauses that may
     * precede the keyword are allowed, so a CREATE TABLE with a column
     * named `event` can never match.
     */
    private static function createObjectPattern()
    {
        return '/^CREATE\s+(?:OR\s+REPLACE\s+)?(?:ALGORITHM\s*=\s*\w+\s+)?(?:DEFINER\s*=\s*' . self::DEFINER_VALUE . '\s+)?(?:SQL\s+SECURITY\s+\w+\s+)?(VIEW|TRIGGER|PROCEDURE|FUNCTION|EVENT)\b/i';
    }

    /**
     * Remove DEFINER=... and SQL SECURITY DEFINER from a CREATE VIEW/
     * TRIGGER/PROCEDURE/FUNCTION/EVENT, so it runs as whoever restores
     * it. Anything else, including row data, is returned untouched.
     */
    public static function stripDefiner($sql)
    {
        if (!preg_match(self::createObjectPattern(), $sql, $match)) {
            return $sql;
        }

        $head = preg_replace('/\s*DEFINER\s*=\s*' . self::DEFINER_VALUE . '/i', '', $match[0]);
        $head = preg_replace('/\s*SQL\s+SECURITY\s+DEFINER\b/i', '', $head);

        return $head . substr($sql, strlen($match[0]));
    }

    /**
     * The object a CREATE VIEW/TRIGGER/... statement makes, or null for
     * any other statement (CREATE TABLE included).
     *
     * @return array{type: string, name: ?string}|null
     */
    private function createdObject($sql)
    {
        if (!preg_match(self::createObjectPattern(), $sql, $match)) {
            return null;
        }

        $name = null;

        if (preg_match('/^\s*(?:IF\s+NOT\s+EXISTS\s+)?(?:`[^`]+`\.)?`([^`]+)`/i', substr($sql, strlen($match[0])), $nameMatch)) {
            $name = $nameMatch[1];
        }

        return ['type' => strtoupper($match[1]), 'name' => $name];
    }

    /**
     * Import a SQL dump, resuming from a byte offset. Called once per
     * AJAX step; never re-reads consumed bytes, so cost stays constant.
     *
     * With 'staging' on, every table statement is redirected to its
     * stageName() table, so the live tables are untouched until
     * WPCB_Restore_Job swaps them in. Views, triggers and routines are
     * never run here - they are returned as 'deferred', to run once the
     * real tables exist under their real names.
     *
     * @param string $sqlFile    Path to .sql dump.
     * @param int    $offset     Byte offset to resume from.
     * @param int    $limitBytes Approx bytes to read per call.
     * @param array  $options {
     *     @type string|null $source_prefix    Dump's table prefix, rewritten to this site's.
     *     @type bool        $staging          Import into staging tables.
     *     @type string[]    $dump_tables      Live names of every table in the dump (see liveNamesInDump()).
     *     @type string[]    $live_constraints Constraint names already taken in this schema (see liveConstraintNames()).
     *     @type array       $compat           From compatibility().
     *     @type string[]    $known_views      Views seen in earlier calls (old dumps insert rows into them).
     * }
     * @return array{finished:bool,offset:int,executed:int,size:int,error:?string,deferred:string[],views:string[]}
     */
    public function import($sqlFile, $offset = 0, $limitBytes = 2097152, array $options = [])
    {
        $sourcePrefix = isset($options['source_prefix']) ? $options['source_prefix'] : null;
        $staging = !empty($options['staging']);
        $dumpTables = array_flip(isset($options['dump_tables']) ? (array) $options['dump_tables'] : []);
        $liveConstraints = array_flip(isset($options['live_constraints']) ? (array) $options['live_constraints'] : []);
        $compat = isset($options['compat']) ? (array) $options['compat'] : [];
        $views = array_flip(isset($options['known_views']) ? (array) $options['known_views'] : []);
        $deferred = [];

        $base = [
            'executed' => 0,
            'deferred' => [],
            'views' => array_keys($views)
        ];

        if (!file_exists($sqlFile)) {
            return $base + [
                'finished' => true,
                'offset' => $offset,
                'size' => 0,
                'error' => __('Database dump not found.', 'rebuzz-backup-and-restore')
            ];
        }

        $size = filesize($sqlFile);

        if ($offset >= $size) {
            return $base + [
                'finished' => true,
                'offset' => $offset,
                'size' => $size,
                'error' => null
            ];
        }

        $handle = fopen($sqlFile, 'rb');

        if (!$handle) {
            return $base + [
                'finished' => false,
                'offset' => $offset,
                'size' => $size,
                'error' => __('Unable to open database dump.', 'rebuzz-backup-and-restore')
            ];
        }

        fseek($handle, $offset);

        $buffer = '';
        $eof = false;

        // Extend buffer past $limitBytes to a clean statement boundary,
        // so each call only executes whole statements.
        while (true) {

            $chunk = fread($handle, 1048576);

            if ($chunk === false || $chunk === '') {
                $eof = feof($handle);
                break;
            }

            $buffer .= $chunk;

            $eof = feof($handle);

            if ($eof) {
                break;
            }

            if (strlen($buffer) >= $limitBytes) {

                // Just need one boundary past budget; remainder rolls to next call.
                list($found, ) = $this->tokenize($buffer);

                if (!empty($found)) {
                    break;
                }
            }
        }

        fclose($handle);

        list($statements, $remainder) = $this->tokenize($buffer);

        $consumed = $eof
            ? strlen($buffer)
            : strlen($buffer) - strlen($remainder);

        if (!$eof && empty($statements) && trim($remainder) !== '') {
            // Statement bigger than the read window; retry same offset later.
            return $base + [
                'finished' => false,
                'offset' => $offset,
                'size' => $size,
                'error' => null
            ];
        }

        global $wpdb;

        // Clear sql_mode so a stricter destination (e.g. NO_ZERO_DATE) doesn't
        // reject statements valid on the source. Per-connection only, every call.
        $wpdb->query("SET SESSION sql_mode = ''");

        // Disable FK checks: chunked replay can recreate tables/rows out
        // of reference order. Per-connection only, resets automatically.
        $wpdb->query('SET SESSION FOREIGN_KEY_CHECKS = 0');

        // One commit per call: with autocommit every INSERT waited for its own disk flush,
        // so a million-row table took hours. DDL still commits implicitly, which is harmless.
        $wpdb->query('SET SESSION autocommit = 0');

        $executed = 0;
        $tablesCreated = 0;
        $bytesExecuted = 0;
        $timedOut = false;
        $startTime = microtime(true);

        // Rewrite statements to this site's table prefix if source differs
        // (see 'table_prefix' note in class-manifest.php).
        $destPrefix = $wpdb->prefix;
        $rewritePrefix = (
            !empty($sourcePrefix) &&
            $sourcePrefix !== $destPrefix
        );

        $statementCount = count($statements);

        for ($index = 0; $index < $statementCount; $index++) {

            // Summing raw statement lengths gives the exact byte offset of
            // the next unexecuted statement - makes early-exit below safe.
            $raw = $statements[$index];
            $sql = trim($raw, " \t\n\r\0\x0B;");

            if ($sql === '') {
                $bytesExecuted += strlen($raw);
                continue;
            }

            // The dump's "-- Table: x" banners sit in front of the next
            // statement; without this, DROP TABLE is never recognised.
            $sql = $this->stripLeadingComments($sql);

            if ($sql === '') {
                $bytesExecuted += strlen($raw);
                continue;
            }

            if ($rewritePrefix) {
                $sql = $this->rewriteTablePrefix($sql, $sourcePrefix, $destPrefix);
            }

            // Pre-staging form, so errors name the real table.
            $liveSql = $sql;

            if ($staging) {

                $sql = $this->stageStatement($sql, $dumpTables, $liveConstraints, $compat, $views, $deferred);

                // Deferred or skipped - nothing to run now.
                if ($sql === null) {
                    $bytesExecuted += strlen($raw);
                    continue;
                }
            }

            $sql = $this->useReplaceForInserts($sql);

            $wpdb->query($sql);

            if (!empty($wpdb->last_error)) {

                $error = $wpdb->last_error;

                // The returned offset is this call's start, so none of its rows may stay.
                $this->endImportTransaction($wpdb, 'ROLLBACK');

                return [
                    'finished' => false,
                    'offset' => $offset,
                    'executed' => $executed,
                    'tables_created' => $tablesCreated,
                    'size' => $size,
                    'error' => $error,
                    // Table + statement, so "restore failed" is diagnosable
                    // without opening restore.log.
                    'error_table' => $this->statementTable($liveSql),
                    'error_sql' => mb_strimwidth($this->redactSqlForLog($liveSql), 0, 300, '...'),
                    'deferred' => [],
                    'views' => array_keys($views)
                ];
            }

            if (stripos($sql, 'CREATE TABLE') === 0) {
                $tablesCreated++;
            }

            $executed++;
            $bytesExecuted += strlen($raw);

            // $limitBytes caps bytes read, not runtime - a slow statement
            // (big serialized value, slow CREATE TABLE) can still blow the
            // time budget and fatal, stalling retries at the same offset
            // forever. Bail out here first so this call stays bounded.
            if (
                $index + 1 < $statementCount &&
                (microtime(true) - $startTime) >= self::IMPORT_TIME_BUDGET_SECONDS
            ) {
                $timedOut = true;
                break;
            }
        }

        $commitError = $this->endImportTransaction($wpdb, 'COMMIT');

        if ($commitError !== '') {
            return $base + [
                'finished' => false,
                'offset' => $offset,
                'size' => $size,
                'error' => $commitError
            ];
        }

        $newOffset = $timedOut
            ? $offset + $bytesExecuted
            : $offset + $consumed;

        return [
            'finished' => !$timedOut && $newOffset >= $size,
            'offset' => $newOffset,
            'executed' => $executed,
            'tables_created' => $tablesCreated,
            'size' => $size,
            'error' => null,
            'deferred' => $deferred,
            'views' => array_keys($views)
        ];
    }

    /**
     * COMMIT or ROLLBACK import()'s transaction and turn autocommit back
     * on - left off, every later write on this connection (the job's own
     * progress) would be silently discarded when the request ends.
     *
     * @return string The COMMIT/ROLLBACK error, or '' on success.
     */
    private function endImportTransaction($wpdb, $statement)
    {
        $wpdb->query($statement);

        $error = $wpdb->last_error;

        $wpdb->query('SET SESSION autocommit = 1');

        return (string) $error;
    }

    /**
     * Staging-mode rewrite of one statement (see import()).
     *
     * @return string|null The statement to run, or null when it was
     *                     deferred to $deferred or is to be skipped.
     */
    private function stageStatement($sql, array $dumpTables, array $liveConstraints, array $compat, array &$views, array &$deferred)
    {
        $object = $this->createdObject($sql);

        if ($object !== null) {

            $sql = $this->applyCompat(self::stripDefiner($sql), $compat);

            // Pre-1.5 exports qualify tables with the source database's name (`olddb`.`wp_posts`), which doesn't exist here.
            $sql = preg_replace_callback('/`[^`]+`\.`([^`]+)`/', function ($matches) use ($dumpTables) {
                return isset($dumpTables[$matches[1]]) ? '`' . $matches[1] . '`' : $matches[0];
            }, $sql);

            if ($object['type'] === 'VIEW' && $object['name'] !== null) {

                $views[$object['name']] = true;

                // Old dumps precede a view with DROP TABLE, not DROP VIEW.
                $deferred[] = 'DROP VIEW IF EXISTS `' . $object['name'] . '`';
            }

            $deferred[] = $sql;

            return null;
        }

        if (preg_match('/^DROP\s+(?:VIEW|TRIGGER|PROCEDURE|FUNCTION|EVENT)\b/i', $sql)) {
            $deferred[] = $sql;
            return null;
        }

        // Would lock/alter the live names (mysqldump's /*!40000 ALTER TABLE ... KEYS */ too); staging needs neither.
        if (preg_match('/^(?:(?:UNLOCK|LOCK)\s+TABLES\b|\/\*!\d+\s+ALTER\s+TABLE\b)/i', $sql)) {
            return null;
        }

        $table = $this->statementTable($sql);

        if ($table === null) {
            return $sql;
        }

        // Pre-1.5 backups dumped a view's rows too; they already live in its base table.
        if (isset($views[$table]) && preg_match('/^(?:INSERT|REPLACE)\b/i', $sql)) {
            return null;
        }

        $sql = preg_replace('/`' . preg_quote($table, '/') . '`/', '`' . self::stageName($table) . '`', $sql, 1);

        if (stripos($sql, 'CREATE TABLE') !== 0) {
            return $sql;
        }

        $sql = $this->applyCompat($sql, $compat);

        // Point foreign keys at the staged parent; RENAME TABLE carries them over.
        $sql = preg_replace_callback('/\bREFERENCES\s+`([^`]+)`/i', function ($matches) use ($dumpTables) {

            return isset($dumpTables[$matches[1]])
                ? 'REFERENCES `' . self::stageName($matches[1]) . '`'
                : $matches[0];
        }, $sql);

        // FK/CHECK names are schema-wide, and the live table still holds this one.
        return preg_replace_callback('/\bCONSTRAINT\s+`([^`]+)`/i', function ($matches) use ($liveConstraints) {

            return isset($liveConstraints[$matches[1]])
                ? 'CONSTRAINT `' . substr($matches[1], 0, 57) . '_r' . substr(md5($matches[1]), 0, 5) . '`'
                : $matches[0];
        }, $sql);
    }

    /** Drop the "-- ..." banner lines the dump puts before a statement. */
    private function stripLeadingComments($sql)
    {
        return (string) preg_replace('/^(?:[ \t]*(?:--|#)[^\n]*(?:\n|$)\s*)+/', '', $sql);
    }

    /**
     * Swap charset/collation names this server doesn't know (MySQL 8's
     * utf8mb4_0900_* and utf8mb3 on older MySQL/MariaDB) for the
     * closest ones it does. Schema statements only - never row data.
     */
    private function applyCompat($sql, array $compat)
    {
        if (!empty($compat['collation'])) {
            $sql = preg_replace('/\butf8mb4_0900_\w+/i', $compat['collation'], $sql);
        }

        if (!empty($compat['utf8mb3'])) {
            $sql = preg_replace('/\butf8mb3/i', 'utf8', $sql);
        }

        return $sql;
    }

    /**
     * What applyCompat() must rewrite on this server, checked once per
     * restore.
     *
     * @return array{collation: ?string, utf8mb3: bool}
     */
    public function compatibility()
    {
        global $wpdb;

        $collation = null;

        if (!$wpdb->get_var("SHOW COLLATION WHERE Collation = 'utf8mb4_0900_ai_ci'")) {

            $collation = $wpdb->get_var("SHOW COLLATION WHERE Collation = 'utf8mb4_unicode_520_ci'")
                ? 'utf8mb4_unicode_520_ci'
                : 'utf8mb4_unicode_ci';
        }

        // No SHOW CHARACTER SET check: older servers accept utf8mb3 without listing it.
        $suppressed = $wpdb->suppress_errors(true);
        $wpdb->query("SELECT CONVERT('a' USING utf8mb3)");
        $utf8mb3Unknown = ($wpdb->last_error !== '');
        $wpdb->suppress_errors($suppressed);

        return [
            'collation' => $collation,
            'utf8mb3' => $utf8mb3Unknown
        ];
    }

    /** Staging table for $table - short enough for any table name. */
    public static function stageName($table)
    {
        return self::STAGE_PREFIX . substr(md5($table), 0, 16);
    }

    /** Where the live $table is parked during the swap. */
    public static function oldName($table)
    {
        return self::OLD_PREFIX . substr(md5($table), 0, 16);
    }

    /**
     * Every table/view name in the dump, as it will be named on this
     * site - i.e. after the same prefix rewrite import() applies.
     *
     * @return string[]
     */
    public function liveNamesInDump($sqlFile, $sourcePrefix)
    {
        global $wpdb;

        $names = $this->tablesInDump($sqlFile);

        if (empty($sourcePrefix) || $sourcePrefix === $wpdb->prefix) {
            return $names;
        }

        return array_map(function ($name) use ($sourcePrefix, $wpdb) {
            return trim($this->rewriteTablePrefix('`' . $name . '`', $sourcePrefix, $wpdb->prefix), '`');
        }, $names);
    }

    /**
     * Foreign-key and CHECK constraint names already used in this
     * schema - see stageStatement().
     *
     * @return string[]
     */
    public function liveConstraintNames()
    {
        global $wpdb;

        return (array) $wpdb->get_col(
            "SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS " .
            "WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_TYPE IN ('FOREIGN KEY', 'CHECK')"
        );
    }

    /**
     * Name => 'BASE TABLE' | 'VIEW' for everything in this database.
     *
     * @return array<string,string>
     */
    private function tableTypes()
    {
        global $wpdb;

        $types = [];

        foreach ((array) $wpdb->get_results('SHOW FULL TABLES', ARRAY_N) as $row) {
            $types[(string) $row[0]] = isset($row[1]) ? strtoupper($row[1]) : 'BASE TABLE';
        }

        return $types;
    }

    /**
     * Swap every staged table in for its live one with a single RENAME
     * TABLE, which MySQL applies atomically: either all of them change
     * or none do. Live tables are parked under oldName() and dropped
     * afterwards. Staged tables already swapped (a retry after a crash)
     * are simply no longer there to swap.
     *
     * @param string[] $liveNames From liveNamesInDump().
     * @return array{ok: bool, error: string, swapped: string[]}
     */
    public function swapStaged(array $liveNames)
    {
        global $wpdb;

        $types = $this->tableTypes();

        $pairs = [];
        $swapped = [];

        foreach (array_unique($liveNames) as $table) {

            $stage = self::stageName($table);

            if (!isset($types[$stage])) {
                continue;
            }

            // A live view by this name would block the rename.
            if (isset($types[$table]) && $types[$table] === 'VIEW') {
                $wpdb->query("DROP VIEW IF EXISTS `{$table}`");
                unset($types[$table]);
            }

            if (isset($types[$table])) {

                $old = self::oldName($table);

                if (isset($types[$old])) {
                    $wpdb->query("DROP TABLE IF EXISTS `{$old}`");
                }

                $pairs[] = "`{$table}` TO `{$old}`";
            }

            $pairs[] = "`{$stage}` TO `{$table}`";
            $swapped[] = $table;
        }

        if (!empty($pairs)) {

            $wpdb->query('RENAME TABLE ' . implode(', ', $pairs));

            if ($wpdb->last_error !== '') {
                return ['ok' => false, 'error' => $wpdb->last_error, 'swapped' => []];
            }
        }

        $this->dropWorkTables(self::OLD_PREFIX);

        return ['ok' => true, 'error' => '', 'swapped' => $swapped];
    }

    /**
     * Drop restore work tables - staging ones after a failed import,
     * parked ones after a swap, or both when $prefix is null.
     *
     * @return int Tables dropped.
     */
    public function dropWorkTables($prefix = null)
    {
        global $wpdb;

        $prefixes = $prefix === null ? [self::STAGE_PREFIX, self::OLD_PREFIX] : [$prefix];

        $tables = [];

        foreach ($prefixes as $workPrefix) {

            $tables = array_merge($tables, (array) $wpdb->get_col(
                $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($workPrefix) . '%')
            ));
        }

        if (empty($tables)) {
            return 0;
        }

        $wpdb->query('SET SESSION FOREIGN_KEY_CHECKS = 0');

        $wpdb->query('DROP TABLE IF EXISTS `' . implode('`, `', $tables) . '`');

        return count($tables);
    }

    /**
     * Run what import() deferred, now that the real tables are in place.
     * Retried in passes so a view built on another view still lands
     * whatever order they came in.
     *
     * @param string[] $statements
     * @return string[] Error for each statement that never succeeded.
     */
    public function runDeferred(array $statements)
    {
        global $wpdb;

        $wpdb->query("SET SESSION sql_mode = ''");
        $wpdb->query('SET SESSION FOREIGN_KEY_CHECKS = 0');

        $pending = array_values($statements);
        $errors = [];

        while (!empty($pending)) {

            $failed = [];
            $errors = [];

            foreach ($pending as $sql) {

                $wpdb->query($sql);

                if ($wpdb->last_error !== '') {
                    $failed[] = $sql;
                    $errors[] = mb_strimwidth($sql, 0, 120, '...') . ' - ' . $wpdb->last_error;
                }
            }

            // No progress this pass - further passes would fail the same way.
            if (count($failed) === count($pending)) {
                break;
            }

            $pending = $failed;
        }

        return $errors;
    }

    /**
     * Best-effort table name from a statement - the one staging
     * redirects, and the one error reports name.
     */
    private function statementTable($sql)
    {
        if (preg_match('/^(?:CREATE TABLE(?: IF NOT EXISTS)?|DROP TABLE(?: IF EXISTS)?|INSERT(?: IGNORE)? INTO|REPLACE INTO|ALTER TABLE)\s+`([^`]+)`/i', $sql, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Strip row data after VALUES before logging - INSERTs can carry
     * password hashes/PII. Schema statements (CREATE/DROP/etc.) pass through.
     */
    private function redactSqlForLog($sql)
    {
        if (preg_match('/^(.*?\bVALUES\b\s*)/is', $sql, $matches)) {
            return rtrim($matches[1]) . '(...row data redacted...)';
        }

        return $sql;
    }

    /**
     * Detect the dump's real prefix from its own DROP TABLE statements,
     * not the manifest-recorded value (can drift if source DB had stray
     * foreign-prefixed tables). Scans for `{prefix}options` near file start.
     *
     * @return string|null Prefix, or null (caller falls back to manifest).
     */
    public function detectSourcePrefix($sqlFile)
    {
        $handle = @fopen($sqlFile, 'rb');

        if (!$handle) {
            return null;
        }

        $chunk = fread($handle, 5 * 1024 * 1024);
        fclose($handle);

        if ($chunk === false) {
            return null;
        }

        // Every candidate, not just the first - a plugin table like
        // `wp_pluginxoptions` also ends in "options" and would
        // otherwise be mistaken for the real wp_options table if it
        // happens to appear earlier in the dump than wp_options does.
        if (!preg_match_all(
            '/DROP TABLE IF EXISTS `([A-Za-z0-9_]*?)options`;/',
            $chunk,
            $matches,
            PREG_SET_ORDER
        )) {
            return null;
        }

        foreach ($matches as $match) {

            $prefix = $match[1];

            // Confirm it's really wp_options, not just a table that
            // happens to end in "options" - the real table's own
            // CREATE statement always defines an "option_name" column.
            // Bounded to this table's own CREATE block (up to the next
            // CREATE TABLE) so a distant, unrelated column with the
            // same name can't produce a false match.
            $createPattern = '/CREATE TABLE `' . preg_quote($prefix, '/') . 'options`(.*?)(?=CREATE TABLE|\z)/s';

            if (
                preg_match($createPattern, $chunk, $createMatch) &&
                strpos($createMatch[1], 'option_name') !== false
            ) {
                return $prefix;
            }
        }

        return null;
    }

    /**
     * All table and view names in the dump, from its DROP statements. Used
     * by multisite restore validation to catch foreign site tables.
     * Reads in chunks with a small overlap (max identifier 64 bytes)
     * so a boundary-straddling statement isn't missed.
     *
     * @return string[] Table names, in order first seen.
     */
    public function tablesInDump($sqlFile)
    {
        $handle = @fopen($sqlFile, 'rb');

        if (!$handle) {
            return [];
        }

        $tables = [];
        $carry = '';
        $overlapBytes = 256;

        while (!feof($handle)) {

            $chunk = fread($handle, 1048576);

            if ($chunk === false) {
                break;
            }

            $window = $carry . $chunk;

            if (preg_match_all('/DROP (?:TABLE|VIEW) IF EXISTS `([^`]+)`;/', $window, $matches)) {

                foreach ($matches[1] as $table) {
                    $tables[$table] = true;
                }
            }

            $carry = substr($window, -$overlapBytes);
        }

        fclose($handle);

        return array_keys($tables);
    }

    /**
     * INSERT -> REPLACE INTO. Tables were just freshly dropped/recreated,
     * so a key clash means something else (e.g. destination's own WP-Cron)
     * wrote the row first - the backup's data should win.
     */
    private function useReplaceForInserts($sql)
    {
        if (stripos($sql, 'INSERT INTO') === 0) {
            return 'REPLACE INTO' . substr($sql, strlen('INSERT INTO'));
        }

        return $sql;
    }

    /**
     * Rewrite a statement from $sourcePrefix to $destPrefix. Two things:
     * 1) backtick-quoted table identifiers, and
     * 2) exact quoted values like 'prefix_capabilities'/'user_roles'
     *    (control admin access) that also embed the prefix as string data.
     * Only these specific values are touched - a blanket replace risks
     * corrupting serialized data in unrelated option/usermeta values.
     */
    private function rewriteTablePrefix($sql, $sourcePrefix, $destPrefix)
    {
        // 1. Table identifiers: `sourceprefix_table` -> `destprefix_table`.
        // Matched as a complete backtick-delimited identifier (open AND
        // close backtick via the regex), not a blind substring replace -
        // the latter would also touch any occurrence of the same text
        // inside quoted row data (e.g. post content mentioning the
        // prefix as a literal string), corrupting it even though it
        // isn't an identifier. A multisite subsite table
        // (`{sourcePrefix}{blogId}_options`, e.g. wp_2_options) is left
        // untouched even though it textually starts with $sourcePrefix -
        // rewriting it here would misfile another site's tables under
        // this one's new prefix during what should be a single-site
        // restore.
        $sql = preg_replace_callback(
            '/`' . preg_quote($sourcePrefix, '/') . '([^`]*)`/',
            function ($matches) use ($destPrefix) {

                if (preg_match('/^\d+_/', $matches[1])) {
                    return $matches[0];
                }

                return '`' . $destPrefix . $matches[1] . '`';
            },
            $sql
        );

        // 2. Prefixed string values, only in statements now targeting
        // this site's options/usermeta tables.
        $touchesOptionsOrUserMeta = (
            stripos($sql, '`' . $destPrefix . 'options`') !== false ||
            stripos($sql, '`' . $destPrefix . 'usermeta`') !== false
        );

        if ($touchesOptionsOrUserMeta) {

            foreach (['capabilities', 'user_level', 'user_roles'] as $suffix) {

                $sql = str_replace(
                    "'" . $sourcePrefix . $suffix . "'",
                    "'" . $destPrefix . $suffix . "'",
                    $sql
                );
            }
        }

        return $sql;
    }

    /**
     * Split SQL into statements, tracking quoted strings so semicolons
     * inside values aren't mistaken for terminators.
     *
     * @return array [$statements, $remainder]
     */
    private function tokenize($sql)
    {
        $statements = [];
        $current = '';

        $inString = false;
        $quoteChar = '';

        $len = strlen($sql);

        for ($i = 0; $i < $len; $i++) {

            $ch = $sql[$i];
            $current .= $ch;

            if ($inString) {

                if ($ch === '\\' && $i + 1 < $len) {
                    $i++;
                    $current .= $sql[$i];
                    continue;
                }

                if ($ch === $quoteChar) {
                    $inString = false;
                }

                continue;
            }

            if ($ch === "'" || $ch === '"') {
                $inString = true;
                $quoteChar = $ch;
                continue;
            }

            if ($ch === ';') {
                $statements[] = $current;
                $current = '';
            }
        }

        return [$statements, $current];
    }

    /**
     * Write INSERT statements.
     *
     * @return bool False if any write failed - see write().
     */
    private function writeRows($wpdb, $handle, $table, array $rows)
    {
        foreach ($rows as $row) {

            $columns = [];

            foreach (array_keys($row) as $column) {
                $columns[] = "`{$column}`";
            }

            $values = [];

            foreach ($row as $value) {

                if ($value === null) {

                    $values[] = "NULL";

                } else {

                    /*
                     * esc_sql() escapes for SQL *and* then calls
                     * wpdb::add_placeholder_escape(), which deliberately
                     * replaces every "%" in the value with a per-request
                     * placeholder token ("{" + 64 hex chars + "}").
                     * WordPress normally strips that again inside
                     * wpdb::query(), via a 'query' filter registered at
                     * priority 0 - but this value is written to a dump
                     * file and never passed through query(), so nothing
                     * ever removes it.
                     *
                     * The token is derived from uniqid() and so differs
                     * between requests: by the time the dump is imported,
                     * the restoring request's placeholder no longer
                     * matches the one baked into the file, so it is not
                     * stripped then either. The result is that every "%"
                     * in the site's content is permanently replaced by a
                     * 66-character token - a CSS "--width:50%" becomes
                     * "--width:50{...}", which silently collapses
                     * page-builder layouts on the restored site.
                     *
                     * Removing the escape here yields exactly the SQL
                     * escaping we want, with literal "%" preserved.
                     */
                    $values[] = "'" . $wpdb->remove_placeholder_escape(esc_sql($value)) . "'";

                }
            }

            $sql =
                "INSERT INTO `{$table}` (" .
                implode(",", $columns) .
                ") VALUES (" .
                implode(",", $values) .
                ");\n";

            if (!$this->write($handle, $sql)) {
                return false;
            }
        }

        return true;
    }
}