<?php

if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- included from inside a method (WPCB_Admin::dashboard() etc), so these are function-scoped, not globals.

// Progress of a background (scheduled) backup. Always printed so "Run now" can reveal it; admin.js polls it.
$wpcbRunning = WPCB_Scheduler::runningJob();
$wpcbRunState = $wpcbRunning ? $wpcbRunning->getPublic() : ['progress' => 0, 'message' => ''];
$wpcbRunProgress = (int) ($wpcbRunState['progress'] ?? 0);

?>

<div
    id="wpcb-bg"
    class="wpcb-card wpcb-running"
    data-running="<?php echo $wpcbRunning ? '1' : '0'; ?>"
    <?php echo $wpcbRunning ? '' : 'style="display:none;"'; ?>>

    <h2><?php esc_html_e('A scheduled backup is running', 'rebuzz-backup-and-restore'); ?></h2>

    <p><?php esc_html_e('It runs in the background, so you can leave this page. This panel updates by itself.', 'rebuzz-backup-and-restore'); ?></p>

    <div class="wpcb-progress">
        <div id="wpcb-bg-progress-bar" class="wpcb-progress-bar" style="width:<?php echo esc_attr($wpcbRunProgress); ?>%;">
            <?php echo esc_html($wpcbRunProgress . '%'); ?>
        </div>
    </div>

    <p id="wpcb-bg-message" class="wpcb-loading"><?php echo esc_html((string) ($wpcbRunState['message'] ?? '')); ?></p>

</div>
