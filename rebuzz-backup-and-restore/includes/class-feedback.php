<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Asking for a rating, and pointing people with a problem to the support
 * forum.
 *
 * The rating is asked for only after a backup or restore the person ran
 * themselves has just finished cleanly, and only on this plugin's own
 * pages. Every answer ends it: "Maybe later" waits SNOOZE before the next
 * success may ask again, the other two never ask again.
 */
class WPCB_Feedback
{
    /** ['answer' => 'rated'|'later', 'at' => timestamp]; absent until the first answer. */
    const OPTION = 'wpcb_review';

    const SNOOZE = 30 * DAY_IN_SECONDS;

    const SUPPORT_URL = 'https://wordpress.org/support/plugin/rebuzz-backup-and-restore/';

    const REVIEW_URL = 'https://wordpress.org/support/plugin/rebuzz-backup-and-restore/reviews/#new-post';

    public function __construct()
    {
        add_action('wp_ajax_wpcb_review', [$this, 'answer']);
        add_filter('plugin_row_meta', [$this, 'row_meta'], 10, 2);
    }

    /** Whether the next clean backup or restore should ask. */
    public static function due()
    {
        if (!current_user_can('manage_options')) {
            return false;
        }

        $state = get_option(self::OPTION, []);

        if (!is_array($state) || empty($state['answer'])) {
            return true;
        }

        return $state['answer'] === 'later' && time() - (int) ($state['at'] ?? 0) >= self::SNOOZE;
    }

    /** Records the answer to the rating dialog: 'rate', 'done' or 'later'. */
    public function answer()
    {
        check_ajax_referer('wpcb_backup', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied.', 'rebuzz-backup-and-restore'));
        }

        $answer = isset($_POST['answer']) && is_scalar($_POST['answer'])
            ? sanitize_key(wp_unslash($_POST['answer']))
            : '';

        update_option(self::OPTION, [
            'answer' => $answer === 'later' ? 'later' : 'rated',
            'at'     => time(),
        ], false);

        wp_send_json_success();
    }

    /** Support and rating links under the plugin's row on the Plugins screen. */
    public function row_meta($links, $file)
    {
        if ($file !== plugin_basename(WPCB_PATH . 'rebuzz-backup-and-restore.php')) {
            return $links;
        }

        $links[] = sprintf(
            '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
            esc_url(self::SUPPORT_URL),
            esc_html__('Support', 'rebuzz-backup-and-restore')
        );

        $links[] = sprintf(
            '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
            esc_url(self::REVIEW_URL),
            esc_html__('Rate this plugin', 'rebuzz-backup-and-restore')
        );

        return $links;
    }
}
