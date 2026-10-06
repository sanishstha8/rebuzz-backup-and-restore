<?php

if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- included from inside a method (WPCB_Admin::dashboard() etc), so these are function-scoped, not globals.

// A Storage-tab transfer whose page was left or reloaded, on every tab: admin.js carries it on from here.
$wpcbTransfer = WPCB_Storage::runningTransfer();

if (!$wpcbTransfer) {
    return;
}

$wpcbTransferState = $wpcbTransfer->get();
$wpcbTransferInfo = $wpcbTransferState['transfer'];
$wpcbTransferMine = $wpcbTransfer->isOwnedBy(get_current_user_id());
$wpcbTransferProgress = (int) ($wpcbTransferState['progress'] ?? 0);

?>

<div
    id="wpcb-transfer"
    class="wpcb-card wpcb-running"
    data-job="<?php echo esc_attr($wpcbTransfer->id()); ?>"
    data-mine="<?php echo $wpcbTransferMine ? '1' : '0'; ?>">

    <h2>
        <?php
        echo esc_html(sprintf(
            $wpcbTransferInfo['direction'] === 'up'
                /* translators: %s: storage name, e.g. "Dropbox" */
                ? __('Sending a backup to %s', 'rebuzz-backup-and-restore')
                /* translators: %s: storage name, e.g. "Dropbox" */
                : __('Downloading a backup from %s', 'rebuzz-backup-and-restore'),
            WPCB_Storage::label($wpcbTransferInfo['remote'])
        ));
        ?>
    </h2>

    <p class="wpcb-backup-file"><?php echo esc_html($wpcbTransferInfo['name']); ?></p>

    <p class="wpcb-transfer-note">
        <?php
        echo $wpcbTransferMine
            ? esc_html__('It carries on here from where it stopped. Keep this page open until it finishes - leaving it pauses the transfer again.', 'rebuzz-backup-and-restore')
            : esc_html__('Started by another administrator. It only moves while their page is open, but you can cancel it here.', 'rebuzz-backup-and-restore');
        ?>
    </p>

    <div class="wpcb-progress wpcb-transfer-progress">
        <div class="wpcb-progress-bar" style="width:<?php echo esc_attr($wpcbTransferProgress); ?>%;"><?php echo esc_html($wpcbTransferProgress . '%'); ?></div>
    </div>

    <p class="wpcb-loading wpcb-remote-status" aria-live="polite"><?php echo esc_html((string) ($wpcbTransferState['message'] ?? '')); ?></p>

    <button type="button" class="button wpcb-transfer-cancel"><?php esc_html_e('Cancel', 'rebuzz-backup-and-restore'); ?></button>

</div>
