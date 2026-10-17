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
    <a class="wpcb-header-help" href="<?php echo esc_url(admin_url('admin.php?page=wpcb-settings#wpcb-help')); ?>">
        <span class="dashicons dashicons-sos" aria-hidden="true"></span>
        <?php esc_html_e('Help & feedback', 'rebuzz-backup-and-restore'); ?>
    </a>
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

<?php
// Opened by admin.js after a backup or restore finishes cleanly - see WPCB_Feedback.
if (in_array($wpcbTab, ['wpcb-dashboard', 'wpcb-restore'], true) && WPCB_Feedback::due()) : ?>

    <dialog id="wpcb-review" class="wpcb-review" aria-labelledby="wpcb-review-title">

        <button type="button" class="wpcb-review-close" data-answer="later" aria-label="<?php esc_attr_e('Close', 'rebuzz-backup-and-restore'); ?>">
            <span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
        </button>

        <div class="wpcb-review-stars" aria-hidden="true">
            <?php for ($wpcbStar = 0; $wpcbStar < 5; $wpcbStar++) : ?>
                <span class="dashicons dashicons-star-filled"></span>
            <?php endfor; ?>
        </div>

        <h2 id="wpcb-review-title">
            <span data-kind="backup"><?php esc_html_e('Your backup is ready', 'rebuzz-backup-and-restore'); ?></span>
            <span data-kind="restore"><?php esc_html_e('Your site is restored', 'rebuzz-backup-and-restore'); ?></span>
        </h2>

        <p><?php esc_html_e('If ReBuzz Backup and Restore did its job, would you rate it on WordPress.org? It takes a minute, and it helps other site owners find a backup plugin they can rely on.', 'rebuzz-backup-and-restore'); ?></p>

        <div class="wpcb-review-actions">
            <a class="button button-primary" href="<?php echo esc_url(WPCB_Feedback::REVIEW_URL); ?>" target="_blank" rel="noopener noreferrer" data-answer="rate">
                <?php esc_html_e('Rate it on WordPress.org', 'rebuzz-backup-and-restore'); ?>
            </a>
            <button type="button" class="button" data-answer="later"><?php esc_html_e('Maybe later', 'rebuzz-backup-and-restore'); ?></button>
            <button type="button" class="button-link" data-answer="done"><?php esc_html_e('I already did', 'rebuzz-backup-and-restore'); ?></button>
        </div>

        <p class="wpcb-review-help">
            <?php
            printf(
                wp_kses(
                    /* translators: %s: URL of the plugin's support forum */
                    __('Something not right? <a href="%s" target="_blank" rel="noopener noreferrer" data-answer="later">Tell us on the support forum</a> and we\'ll help.', 'rebuzz-backup-and-restore'),
                    ['a' => ['href' => [], 'target' => [], 'rel' => [], 'data-answer' => []]]
                ),
                esc_url(WPCB_Feedback::SUPPORT_URL)
            );
            ?>
        </p>

    </dialog>

<?php endif; ?>

<?php include WPCB_PATH . 'admin/transfer.php'; ?>
