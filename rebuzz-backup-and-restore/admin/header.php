<?php

if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- included from inside a method (WPCB_Admin::dashboard() etc), so these are function-scoped, not globals.

// $wpcbTab is set by the page that includes this file.
$wpcbTabs = [
    'wpcb-dashboard' => __('Backups', 'rebuzz-backup-and-restore'),
    'wpcb-restore'   => __('Restore', 'rebuzz-backup-and-restore'),
    'wpcb-schedule'  => __('Schedule', 'rebuzz-backup-and-restore'),
    'wpcb-storage'   => __('Storage', 'rebuzz-backup-and-restore'),
    'wpcb-settings'  => __('Settings', 'rebuzz-backup-and-restore'),
];

?>

<div class="wpcb-header">
    <img src="<?php echo esc_url(WPCB_URL . 'assets/img/logo.png'); ?>" alt="">
    <h1><?php esc_html_e('ReBuzz Backup and Restore', 'rebuzz-backup-and-restore'); ?></h1>
    <span class="wpcb-version"><?php echo esc_html('v' . WPCB_VERSION); ?></span>
</div>

<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e('ReBuzz Backup sections', 'rebuzz-backup-and-restore'); ?>">
    <?php foreach ($wpcbTabs as $wpcbSlug => $wpcbLabel) : ?>
        <a
            href="<?php echo esc_url(admin_url('admin.php?page=' . $wpcbSlug)); ?>"
            class="nav-tab<?php echo $wpcbSlug === $wpcbTab ? ' nav-tab-active' : ''; ?>"
            <?php echo $wpcbSlug === $wpcbTab ? 'aria-current="page"' : ''; ?>>
            <?php echo esc_html($wpcbLabel); ?>
        </a>
    <?php endforeach; ?>
</nav>

<hr class="wp-header-end">
