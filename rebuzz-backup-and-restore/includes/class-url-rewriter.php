<?php

if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL -- restoring/rewriting a site's own tables; identifiers come from SHOW TABLES and can't be placeholders, and caching would defeat the point.

// phpcs:disable PluginCheck.Security.DirectDB -- table/column names come from SHOW TABLES/COLUMNS/KEYS and are backtick-quoted by quoteIdentifier(); identifiers can't be placeholders.

/**
 * Rewrites old-site strings to new-site ones across the whole DB.
 * Second half of the URL fix: restorePreservedSiteUrl() handles the
 * dynamic siteurl/home options; this handles hardcoded values saved
 * as literal text (post content, GUIDs, page-builder JSON, etc).
 *
 * Never uses plain str_replace(): serialize() prefixes strings with
 * their byte length, so a length-changing replace on raw serialized
 * data corrupts it. Values are unserialized, rewritten, then
 * re-serialized so length prefixes stay correct (same approach as
 * WPCB_Database::rewriteTablePrefix()).
 */
class WPCB_Url_Rewriter
{
    /**
     * Most rows fetched at once; the real limit is BATCH_TARGET_BYTES,
     * since a row can hold a large serialized blob.
     */
    const ROWS_PER_BATCH = 200;

    /** Approx bytes of text fetched per batch - see ROWS_PER_BATCH. */
    const BATCH_TARGET_BYTES = 8388608;

    /**
     * Max seconds per run() call before returning control to browser.
     * See WPCB_Zip_Batch::TIME_BUDGET_SECONDS.
     */
    const TIME_BUDGET_SECONDS = 20;

    /**
     * Consecutive fetchNextRows() failures allowed on a table before
     * skipping it, so transient errors get retries but a persistently
     * broken table can't block the restore.
     */
    const MAX_TABLE_ERROR_RETRIES = 3;

    /**
     * Resume (or start) one bounded pass of the rewrite.
     *
     * @param array $pairs [search => replace, ...]
     * @param array $state  Prior call's state, or [] to start fresh.
     *
     * @return array {
     *     @type bool  finished
     *     @type array state        Pass back in on next call.
     *     @type int   rows_changed Running total.
     * }
     */
    public function run(array $pairs, array $state = [])
    {
        global $wpdb;

        $pairs = array_filter($pairs, function ($search) {
            return $search !== '';
        }, ARRAY_FILTER_USE_KEY);

        if (empty($pairs)) {
            return ['finished' => true, 'state' => $state, 'rows_changed' => 0];
        }

        $pairs = $this->withJsonEscapedVariants($pairs);

        $tables = isset($state['tables'])
            ? $state['tables']
            : $this->initialTableList($wpdb);

        $tableIndex = isset($state['table_index']) ? (int) $state['table_index'] : 0;
        $lastKey = isset($state['last_key']) && is_array($state['last_key']) ? $state['last_key'] : null;
        $rowsChanged = isset($state['rows_changed']) ? (int) $state['rows_changed'] : 0;
        $tableErrorCount = isset($state['table_error_count']) ? (int) $state['table_error_count'] : 0;
        $batchRows = isset($state['batch_rows']) ? (int) $state['batch_rows'] : 0;

        $startTime = microtime(true);

        while (
            $tableIndex < count($tables) &&
            (microtime(true) - $startTime) < self::TIME_BUDGET_SECONDS
        ) {

            $table = $tables[$tableIndex];

            $keyColumns = $this->primaryKeyColumns($wpdb, $table);
            $textTypes = $this->textColumns($wpdb, $table);
            $textColumns = array_keys($textTypes);

            if (empty($keyColumns) || empty($textColumns)) {

                // No PK to target a row, or no text columns to rewrite - skip table.
                if (empty($keyColumns)) {

                    (new WPCB_Logger())->log(
                        "URL rewrite: skipped table `{$table}` - no PRIMARY KEY found (a UNIQUE key alone isn't enough to target a row for update)."
                    );
                }

                $tableIndex++;
                $lastKey = null;
                $tableErrorCount = 0;
                $batchRows = 0;
                continue;
            }

            $selectColumns = array_unique(array_merge($keyColumns, $textColumns));
            $columnList = implode(',', array_map([$this, 'quoteIdentifier'], $selectColumns));
            $orderBy = implode(',', array_map([$this, 'quoteIdentifier'], $keyColumns));

            if ($batchRows <= 0) {
                $batchRows = wpcb_initial_batch_rows(self::ROWS_PER_BATCH);
            }

            $requested = $batchRows;
            $rows = null;

            // Size the batch from the next rows' real sizes; see wpcb_has_large_columns().
            $probe = wpcb_has_large_columns($textTypes);

            if ($probe) {

                $sizes = $this->fetchNextRows($wpdb, $table, wpcb_length_select($textColumns), $keyColumns, $orderBy, $lastKey, self::ROWS_PER_BATCH);

                if ($sizes === false || empty($sizes)) {
                    $rows = $sizes;
                } else {
                    $requested = wpcb_rows_within_bytes(array_column($sizes, 'wpcb_len'), self::BATCH_TARGET_BYTES);
                }
            }

            if ($rows === null) {
                $rows = $this->fetchNextRows($wpdb, $table, $columnList, $keyColumns, $orderBy, $lastKey, $requested);
            }

            if ($rows === false) {

                // Query failed (not just "no more rows") - retry a bounded
                // number of times before giving up on this table.
                $tableErrorCount++;

                if ($tableErrorCount >= self::MAX_TABLE_ERROR_RETRIES) {

                    if (class_exists('WPCB_Logger')) {
                        (new WPCB_Logger())->log(sprintf(
                            'URL rewrite: query on table %s failed %d time(s) in a row (%s) - skipping this table; some references to the old domain may remain in it.',
                            $table,
                            $tableErrorCount,
                            $wpdb->last_error
                        ));
                    }

                    $tableIndex++;
                    $lastKey = null;
                    $tableErrorCount = 0;
                    $batchRows = 0;
                    continue;
                }

                if (class_exists('WPCB_Logger')) {
                    (new WPCB_Logger())->log(sprintf(
                        'URL rewrite: query failed on table %s (attempt %d/%d, will retry): %s',
                        $table,
                        $tableErrorCount,
                        self::MAX_TABLE_ERROR_RETRIES,
                        $wpdb->last_error
                    ));
                }

                break;
            }

            $tableErrorCount = 0;

            if (empty($rows)) {
                $tableIndex++;
                $lastKey = null;
                $batchRows = 0;
                continue;
            }

            $batchBytes = 0;

            foreach ($rows as $row) {

                $updates = [];

                foreach ($textColumns as $column) {

                    $original = $row[$column];

                    $batchBytes += strlen((string) $original);

                    if ($original === null || $original === '') {
                        continue;
                    }

                    $rewritten = $this->rewriteValue($original, $pairs);

                    if ($rewritten !== $original) {
                        $updates[$column] = $rewritten;
                    }
                }

                if (!empty($updates)) {

                    $where = [];

                    foreach ($keyColumns as $keyColumn) {
                        $where[$keyColumn] = $row[$keyColumn];
                    }

                    // Checked like every other write here - a silent failure would count as a success.
                    if ($wpdb->update($table, $updates, $where) === false) {

                        if (class_exists('WPCB_Logger')) {
                            (new WPCB_Logger())->log(sprintf(
                                'URL rewrite: update failed on table %s: %s',
                                $table,
                                $wpdb->last_error
                            ));
                        }

                        continue;
                    }

                    $rowsChanged++;
                }
            }

            $lastRow = end($rows);

            $lastKey = array_map(function ($column) use ($lastRow) {
                return $lastRow[$column];
            }, $keyColumns);

            $fetched = count($rows);

            unset($rows, $lastRow);

            $batchRows = $probe ? $requested : wpcb_adapt_batch_rows($requested, $batchBytes, self::BATCH_TARGET_BYTES, self::ROWS_PER_BATCH);

            if ($fetched < $requested) {
                $tableIndex++;
                $lastKey = null;
                $batchRows = 0;
            }
        }

        return [
            'finished' => $tableIndex >= count($tables),
            'state' => [
                'tables' => $tables,
                'table_index' => $tableIndex,
                'last_key' => $lastKey,
                'rows_changed' => $rowsChanged,
                'table_error_count' => $tableErrorCount,
                'batch_rows' => $batchRows
            ],
            'rows_changed' => $rowsChanged
        ];
    }

    /**
     * Table list to walk, scoped to this site's own tables on
     * multisite (network super-admins unrestricted, matching
     * stepValidate()). SHOW TABLES has no site concept, so without
     * scoping this would also rewrite other sites' tables.
     */
    private function initialTableList($wpdb)
    {
        // Base tables only: views hold no rows of their own, and restore
        // work tables are about to be dropped.
        $tables = array_values(array_filter(
            (array) $wpdb->get_col("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'"),
            function ($table) {
                return !WPCB_Database::isWorkTable($table);
            }
        ));

        if (!is_multisite()) {
            return $tables;
        }

        $multisite = new WPCB_Multisite();

        if ($multisite->isNetworkAdmin()) {
            return $tables;
        }

        return $multisite->tablesForSite($tables, get_current_blog_id());
    }

    /**
     * Next batch of rows from $table by primary key, after $afterKey
     * (or from start if null).
     *
     * Uses keyset pagination, not LIMIT/OFFSET: this rewrite spans many
     * AJAX calls over real time, during which rows can shift (WP-Cron
     * etc. may delete/reinsert rows mid-scan - see
     * WPCB_Database::exportTable()). OFFSET would silently skip a row
     * when that happens; keyset ("key > last seen") doesn't.
     */
    private function fetchNextRows($wpdb, $table, $columnList, array $keyColumns, $orderBy, $afterKey, $limit)
    {
        if ($afterKey === null) {

            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT {$columnList} FROM `{$table}` ORDER BY {$orderBy} LIMIT %d",
                    $limit
                ),
                ARRAY_A
            );

        } else {

            $keyList = implode(',', array_map([$this, 'quoteIdentifier'], $keyColumns));
            $placeholders = implode(',', array_fill(0, count($keyColumns), '%s'));

            $args = array_values($afterKey);
            $args[] = $limit;

            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT {$columnList} FROM `{$table}` " .
                    "WHERE ({$keyList}) > ({$placeholders}) " .
                    "ORDER BY {$orderBy} LIMIT %d",
                    $args
                ),
                ARRAY_A
            );
        }

        // Empty table looks the same as [] - last_error tells them apart.
        if (!empty($wpdb->last_error)) {
            return false;
        }

        return $rows;
    }

    /**
     * Adds a JSON-escaped form of every pair, alongside the plain one.
     *
     * Page builders (Elementor especially, but also WPBakery, Divi and
     * others) store their entire layout as JSON inside postmeta, where
     * JSON encoding escapes forward slashes: "https:\/\/oldsite.com".
     * A search for the plain "https://oldsite.com" never matches that,
     * so without this every link and image inside page-builder content
     * silently keeps pointing at the source domain after a migrating
     * restore - while the rest of the site rewrites correctly, which
     * makes it look like a broken theme rather than a missed rewrite.
     *
     * Safe against double-matching: strtr() takes the longest match at
     * each position, so the plain and escaped keys can't overlap
     * destructively. Length changes are safe too - JSON has no length
     * prefixes, and genuinely serialized values still go through
     * unserialize/re-serialize, which recomputes them.
     */
    private function withJsonEscapedVariants(array $pairs)
    {
        $expanded = $pairs;

        foreach ($pairs as $search => $replace) {

            $escapedSearch = str_replace('/', '\\/', $search);
            $escapedReplace = str_replace('/', '\\/', $replace);

            if ($escapedSearch !== $search && !isset($expanded[$escapedSearch])) {
                $expanded[$escapedSearch] = $escapedReplace;
            }
        }

        return $expanded;
    }

    /**
     * Replace search strings in $value. Serialized data is
     * unserialized/rewritten/re-serialized (see class docblock);
     * otherwise plain strtr().
     */
    private function rewriteValue($value, array $pairs)
    {
        if (!is_serialized($value)) {
            return strtr($value, $pairs);
        }

        // allowed_classes: false - blocks PHP Object Injection from a
        // crafted backup's __wakeup()/__destruct() gadget chain.
        $unserialized = @unserialize(trim($value), ['allowed_classes' => false]);

        if ($unserialized === false && trim($value) !== 'b:0;') {
            // Looked serialized but failed to parse (corrupt) - leave as-is.
            return $value;
        }

        $rewritten = $this->rewriteRecursive($unserialized, $pairs, 0);

        $reserialized = @serialize($rewritten);

        // If re-serialize failed, keep original rather than write broken data.
        return $reserialized === false ? $value : $reserialized;
    }

    /**
     * Max recursion depth for rewriteRecursive(). Serialized data can
     * be circular, which would hang forever unbounded (and outside
     * the time-budget checks) - so depth-limit instead. 50 covers
     * real-world nesting.
     */
    const MAX_REWRITE_DEPTH = 50;

    private function rewriteRecursive($data, array $pairs, $depth)
    {
        if ($depth > self::MAX_REWRITE_DEPTH) {
            return $data;
        }

        if (is_string($data)) {
            return $this->rewriteStringLeaf($data, $pairs, $depth);
        }

        if (is_array($data)) {

            $out = [];

            foreach ($data as $key => $value) {
                $out[$this->rewriteRecursive($key, $pairs, $depth + 1)] = $this->rewriteRecursive($value, $pairs, $depth + 1);
            }

            return $out;
        }

        // rewriteValue() unserializes with allowed_classes:false (blocks
        // PHP Object Injection from a crafted backup's __wakeup()/
        // __destruct() gadget chain), which means every real object in
        // the data always comes back as __PHP_Incomplete_Class here -
        // there is never a genuine, reflectable object to rewrite
        // properties on. A serialized PHP object with a hardcoded
        // old-domain URL in one of its properties is therefore left
        // untouched by design; only arrays/scalars get rewritten.
        return $data;
    }

    /**
     * Rewrite a string leaf from rewriteRecursive(). Re-checks
     * is_serialized() first - data sometimes nests one serialized
     * blob inside another, and a plain strtr() there would corrupt it
     * the same way rewriteValue() avoids at the top level.
     */
    private function rewriteStringLeaf($data, array $pairs, $depth)
    {
        if ($depth < self::MAX_REWRITE_DEPTH && is_serialized($data)) {

            $nested = @unserialize(trim($data), ['allowed_classes' => false]);

            if ($nested !== false || trim($data) === 'b:0;') {

                $rewrittenNested = $this->rewriteRecursive($nested, $pairs, $depth + 1);

                $reserialized = @serialize($rewrittenNested);

                if ($reserialized !== false) {
                    return $reserialized;
                }
            }
        }

        return strtr($data, $pairs);
    }

    /**
     * $table's primary key columns, in index order (for a correct
     * composite-key WHERE clause).
     */
    private function primaryKeyColumns($wpdb, $table)
    {
        $rows = $wpdb->get_results(
            $wpdb->prepare("SHOW KEYS FROM `{$table}` WHERE Key_name = %s", 'PRIMARY'),
            ARRAY_A
        );

        if (empty($rows)) {
            return [];
        }

        usort($rows, function ($a, $b) {
            return $a['Seq_in_index'] <=> $b['Seq_in_index'];
        });

        return array_map(function ($row) {
            return $row['Column_name'];
        }, $rows);
    }

    /**
     * $table's text-type columns. Excludes BLOB/BINARY.
     */
    private function textColumns($wpdb, $table)
    {
        $columns = $wpdb->get_results("SHOW COLUMNS FROM `{$table}`", ARRAY_A);

        $textColumns = [];

        foreach ($columns as $column) {

            $type = strtolower($column['Type']);

            $isText = (
                strpos($type, 'char') !== false ||
                strpos($type, 'text') !== false ||
                strpos($type, 'enum') !== false ||
                strpos($type, 'set(') !== false ||
                strpos($type, 'json') !== false
            );

            if ($isText) {
                $textColumns[$column['Field']] = $type;
            }
        }

        // Field => type, so run() can tell which can hold large values.
        return $textColumns;
    }

    private function quoteIdentifier($identifier)
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}
