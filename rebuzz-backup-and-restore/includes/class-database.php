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
     * Number of rows to export at once
     */
    const BATCH_SIZE = 500;

    /**
     * Max seconds import() spends executing statements per call.
     * Kept under stepRestoreDatabase()'s set_time_limit(60) so a slow chunk bails before PHP fatals.
     */
    const IMPORT_TIME_BUDGET_SECONDS = 15;

    /**
     * Export database
     */
    public function export($destination)
    {
        global $wpdb;

        $directory = dirname($destination);

        if (!is_dir($directory)) {
            wp_mkdir_p($directory);
        }

        $handle = fopen($destination, 'w');

        if (!$handle) {
            return false;
        }

        // One REPEATABLE READ transaction so all paginated SELECTs share a
        // consistent snapshot - avoids duplicate rows when e.g. a transient
        // gets deleted+re-inserted (new PK) mid-export and shifts pages.
        $wpdb->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $wpdb->query('START TRANSACTION');

        $tables = $wpdb->get_col("SHOW TABLES");

        // Multisite: unscoped SHOW TABLES would leak every other site's
        // tables, incl. shared wp_users/wp_usermeta password hashes.
        if (is_multisite()) {

            $networkTableCount = count($tables);

            $tables = (new WPCB_Multisite())->tablesForSite($tables, get_current_blog_id());

            (new WPCB_Logger('backup'))->log(sprintf(
                "Multisite: scoped database export to this site's own %d table(s) (of %d on the network).",
                count($tables),
                $networkTableCount
            ));
        }

        $failure = null;

        foreach ($tables as $table) {

            if (!$this->exportTable($wpdb, $handle, $table)) {

                $failure = $table;

                break;
            }
        }

        $wpdb->query('COMMIT');

        /*
         * fclose() flushes PHP's own write buffer, so a disk that
         * filled up part-way through can surface here rather than at
         * any earlier fwrite(). Checked for the same reason those are:
         * a dump that is silently short restores a site with tables
         * missing or half-populated, and the whole point of the backup
         * is that it can be trusted later.
         */
        if (!fclose($handle) && $failure === null) {
            $failure = '(flush on close)';
        }

        if ($failure !== null) {

            (new WPCB_Logger('backup'))->log(sprintf(
                'Database export aborted: could not write the dump while exporting table %s. The disk may be full or the temp folder may not be writable.',
                $failure
            ));

            // Leaving a truncated dump behind risks it being treated as
            // a usable backup by anything that only checks the file exists.
            if (file_exists($destination)) {
                wp_delete_file($destination);
            }

            return false;
        }

        return $destination;
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
     * Export one table.
     *
     * @return bool False if any write failed - see write().
     */
    private function exportTable($wpdb, $handle, $table)
    {
        $header =
            "\n\n" .
            "-- ---------------------------------\n" .
            "-- Table: {$table}\n" .
            "-- ---------------------------------\n\n" .
            "DROP TABLE IF EXISTS `{$table}`;\n";

        if (!$this->write($handle, $header)) {
            return false;
        }

        $create = $wpdb->get_row(
            "SHOW CREATE TABLE `$table`",
            ARRAY_N
        );

        // No CREATE statement means no way to recreate the table, so
        // the dump would restore this table as missing rather than
        // empty - fail rather than write a schema-less section.
        if (!is_array($create) || !isset($create[1])) {
            return false;
        }

        if (!$this->write($handle, $create[1] . ";\n\n")) {
            return false;
        }

        $orderBy = $this->primaryKeyOrderBy($wpdb, $table);

        $offset = 0;

        while (true) {

            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM `$table`{$orderBy} LIMIT %d OFFSET %d",
                    self::BATCH_SIZE,
                    $offset
                ),
                ARRAY_A
            );

            if (empty($rows)) {
                break;
            }

            if (!$this->writeRows($wpdb, $handle, $table, $rows)) {
                return false;
            }

            $offset += self::BATCH_SIZE;
        }

        return $this->write($handle, "\n");
    }

    /**
     * ORDER BY primary key so paginated LIMIT/OFFSET stays stable while
     * rows change mid-export (else a moved row can be exported twice,
     * causing a "Duplicate entry" import failure). No PK -> no ORDER BY.
     */
    private function primaryKeyOrderBy($wpdb, $table)
    {
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SHOW KEYS FROM `$table` WHERE Key_name = %s",
                'PRIMARY'
            ),
            ARRAY_A
        );

        if (empty($rows)) {
            return '';
        }

        usort($rows, function ($a, $b) {
            return $a['Seq_in_index'] <=> $b['Seq_in_index'];
        });

        $columns = array_map(function ($row) {
            return '`' . $row['Column_name'] . '`';
        }, $rows);

        return ' ORDER BY ' . implode(',', $columns);
    }

    /**
     * Import a SQL dump, resuming from a byte offset. Called once per
     * AJAX step; never re-reads consumed bytes, so cost stays constant.
     *
     * @param string $sqlFile    Path to .sql dump.
     * @param int    $offset     Byte offset to resume from.
     * @param int    $limitBytes Approx bytes to read per call.
     * @return array{finished:bool,offset:int,executed:int,size:int,error:?string}
     */
    public function import($sqlFile, $offset = 0, $limitBytes = 2097152, $sourcePrefix = null)
    {
        if (!file_exists($sqlFile)) {
            return [
                'finished' => true,
                'offset' => $offset,
                'executed' => 0,
                'size' => 0,
                'error' => __('Database dump not found.', 'rebuzz-backup-and-restore')
            ];
        }

        $size = filesize($sqlFile);

        if ($offset >= $size) {
            return [
                'finished' => true,
                'offset' => $offset,
                'executed' => 0,
                'size' => $size,
                'error' => null
            ];
        }

        $handle = fopen($sqlFile, 'rb');

        if (!$handle) {
            return [
                'finished' => false,
                'offset' => $offset,
                'executed' => 0,
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
            return [
                'finished' => false,
                'offset' => $offset,
                'executed' => 0,
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

            if ($rewritePrefix) {
                $sql = $this->rewriteTablePrefix($sql, $sourcePrefix, $destPrefix);
            }

            $sql = $this->useReplaceForInserts($sql);

            $wpdb->query($sql);

            if (!empty($wpdb->last_error)) {

                return [
                    'finished' => false,
                    'offset' => $offset,
                    'executed' => $executed,
                    'tables_created' => $tablesCreated,
                    'size' => $size,
                    'error' => $wpdb->last_error,
                    // Table + statement, so "restore failed" is diagnosable
                    // without opening restore.log.
                    'error_table' => $this->statementTable($sql),
                    'error_sql' => mb_strimwidth($this->redactSqlForLog($sql), 0, 300, '...')
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

        $newOffset = $timedOut
            ? $offset + $bytesExecuted
            : $offset + $consumed;

        return [
            'finished' => !$timedOut && $newOffset >= $size,
            'offset' => $newOffset,
            'executed' => $executed,
            'tables_created' => $tablesCreated,
            'size' => $size,
            'error' => null
        ];
    }

    /**
     * Best-effort table name from a statement, for error reporting only.
     */
    private function statementTable($sql)
    {
        if (preg_match('/^(?:CREATE TABLE|DROP TABLE(?: IF EXISTS)?|INSERT INTO|REPLACE INTO)\s+`([^`]+)`/i', $sql, $matches)) {
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
     * All table names in the dump, from its DROP TABLE statements. Used
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

            if (preg_match_all('/DROP TABLE IF EXISTS `([^`]+)`;/', $window, $matches)) {

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