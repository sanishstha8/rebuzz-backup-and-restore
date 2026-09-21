<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Central place for "which tables/files belong to *this* site" on a
 * shared-DB/uploads network. No-op pass-through on single-site.
 */
class WPCB_Multisite
{
    /** @var array|null */
    private $otherSitesUploadsDirsCache;

    /**
     * WP's always-shared, network-wide tables - never "this site's own", regardless of prefix.
     */
    public function globalTableNames()
    {
        global $wpdb;

        if (!is_multisite()) {
            return array_values((array) $wpdb->tables('global'));
        }

        return array_values(array_unique(array_merge(
            (array) $wpdb->tables('global'),
            (array) $wpdb->tables('ms_global')
        )));
    }

    /**
     * Every site ID in the network (single-site: just the current one).
     */
    private function networkBlogIds()
    {
        if (!is_multisite()) {
            return [get_current_blog_id()];
        }

        return get_sites(['fields' => 'ids', 'number' => 0]);
    }

    /**
     * Prefixes of every other site, to avoid mis-attributing tables when
     * one site's prefix (e.g. "wp_") is itself a prefix of another's ("wp_2_").
     */
    private function otherSitePrefixes($blogId)
    {
        global $wpdb;

        if (!is_multisite()) {
            return [];
        }

        $prefixes = [];

        foreach ($this->networkBlogIds() as $id) {

            if ((int) $id === (int) $blogId) {
                continue;
            }

            $prefixes[] = $wpdb->get_blog_prefix($id);
        }

        return array_values(array_filter($prefixes));
    }

    /**
     * Filters $allTables down to ones genuinely owned by $blogId (own prefix,
     * not global, not claimed by a more specific site prefix). Includes
     * plugin tables too, not just core. Single-site: returns $allTables as-is.
     */
    public function tablesForSite(array $allTables, $blogId)
    {
        global $wpdb;

        if (!is_multisite()) {
            return $allTables;
        }

        $prefix = $wpdb->get_blog_prefix($blogId);
        $globals = $this->globalTableNames();
        $otherPrefixes = $this->otherSitePrefixes($blogId);

        $mine = [];

        foreach ($allTables as $table) {

            if (in_array($table, $globals, true)) {
                continue;
            }

            if (strpos($table, $prefix) !== 0) {
                continue;
            }

            $claimedByAnother = false;

            foreach ($otherPrefixes as $otherPrefix) {

                if (strpos($table, $otherPrefix) === 0) {
                    $claimedByAnother = true;
                    break;
                }
            }

            if (!$claimedByAnother) {
                $mine[] = $table;
            }
        }

        return $mine;
    }

    /**
     * First table belonging to another site or to global data, else null.
     * Unrelated prefixes (e.g. restoring a single-site backup onto a
     * subsite) aren't flagged - that's handled by rewriteTablePrefix().
     */
    public function findForeignTable(array $tables, $blogId)
    {
        if (!is_multisite()) {
            return null;
        }

        $globals = $this->globalTableNames();

        foreach ($tables as $table) {

            if (in_array($table, $globals, true)) {
                return $table;
            }
        }

        foreach ($this->otherSitePrefixes($blogId) as $otherPrefix) {

            foreach ($tables as $table) {

                if (strpos($table, $otherPrefix) === 0) {
                    return $table;
                }
            }
        }

        return null;
    }

    /**
     * Whether $userId is a network super-admin (single-site: always true).
     */
    public function isNetworkAdmin($userId = null)
    {
        if (!is_multisite()) {
            return true;
        }

        return is_super_admin($userId ? $userId : get_current_user_id());
    }

    /**
     * Blog ID of first other site's uploads folder found under $sitesDir, or null.
     * Just a directory listing (scan step hasn't run yet at this point).
     */
    public function foreignUploadsSiteId($sitesDir, $currentBlogId)
    {
        if (!is_multisite() || !is_dir($sitesDir)) {
            return null;
        }

        $entries = @scandir($sitesDir);

        if ($entries === false) {
            return null;
        }

        $currentBlogId = (string) $currentBlogId;

        foreach ($entries as $entry) {

            if ($entry === '.' || $entry === '..') {
                continue;
            }

            if ($entry !== $currentBlogId && is_dir($sitesDir . '/' . $entry)) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Every other site's uploads basedir, so a file scan excludes them
     * (including their backup ZIPs, to prevent compounding bloat).
     * Cached: switch_to_blog() is too expensive to repeat per directory.
     *
     * The primary site's own basedir is the shared physical uploads
     * root every subsite's basedir is nested inside
     * (.../uploads/sites/{id}) - blacklisting it wholesale for a
     * subsite backup would skip that subsite's own media too (it lives
     * under the same ancestor path). So an ancestor of the current
     * site's own basedir is never added here; its own loose top-level
     * entries (the primary site's real content) are excluded
     * individually instead, via primaryUploadsSiblingPaths().
     */
    public function otherSitesUploadsDirs()
    {
        if ($this->otherSitesUploadsDirsCache !== null) {
            return $this->otherSitesUploadsDirsCache;
        }

        $dirs = [];

        if (is_multisite()) {

            $currentBlogId = get_current_blog_id();
            $ownBasedir = realpath(wp_upload_dir()['basedir']);

            foreach ($this->networkBlogIds() as $blogId) {

                if ((int) $blogId === (int) $currentBlogId) {
                    continue;
                }

                switch_to_blog($blogId);
                $basedir = wp_upload_dir()['basedir'];
                restore_current_blog();

                $realBasedir = realpath($basedir);

                if ($realBasedir === false) {
                    continue;
                }

                if ($ownBasedir !== false && $this->isAncestorOrSame($realBasedir, $ownBasedir)) {
                    continue;
                }

                $dirs[] = $realBasedir;
            }

            $dirs = array_merge($dirs, $this->primaryUploadsSiblingPaths($ownBasedir));
        }

        $this->otherSitesUploadsDirsCache = array_values(array_unique($dirs));

        return $this->otherSitesUploadsDirsCache;
    }

    /**
     * Whether $ancestor is $path itself or a directory $path is nested
     * inside.
     */
    private function isAncestorOrSame($ancestor, $path)
    {
        return (
            $ancestor === $path ||
            strpos($path . DIRECTORY_SEPARATOR, $ancestor . DIRECTORY_SEPARATOR) === 0
        );
    }

    /**
     * Realpath of the network's primary site's uploads basedir - the
     * shared physical root every subsite's own uploads folder is nested
     * inside. False if it can't be resolved.
     */
    private function primaryUploadsBasedir()
    {
        $mainSiteId = function_exists('get_main_site_id') ? get_main_site_id() : 1;

        switch_to_blog($mainSiteId);
        $basedir = wp_upload_dir()['basedir'];
        restore_current_blog();

        return realpath($basedir);
    }

    /**
     * When the current site isn't the primary site, its own basedir is
     * nested inside the primary site's basedir alongside the primary
     * site's own loose top-level uploads (files/folders not under a
     * "sites" subfolder) - content that genuinely isn't ours and needs
     * excluding individually, since the shared ancestor itself can't be
     * blacklisted wholesale (see otherSitesUploadsDirs()). Returns each
     * sibling entry's absolute path, or [] if this site IS the primary
     * site (nothing to exclude - it's all legitimately ours) or the
     * primary basedir can't be resolved/read.
     */
    private function primaryUploadsSiblingPaths($ownBasedir)
    {
        if ($ownBasedir === false) {
            return [];
        }

        $primaryBasedir = $this->primaryUploadsBasedir();

        if ($primaryBasedir === false || $primaryBasedir === $ownBasedir) {
            return [];
        }

        if (!$this->isAncestorOrSame($primaryBasedir, $ownBasedir)) {
            return [];
        }

        $entries = @scandir($primaryBasedir);

        if ($entries === false) {
            return [];
        }

        $paths = [];

        foreach ($entries as $entry) {

            if ($entry === '.' || $entry === '..' || $entry === 'sites') {
                continue;
            }

            $paths[] = $primaryBasedir . DIRECTORY_SEPARATOR . $entry;
        }

        return $paths;
    }
}
