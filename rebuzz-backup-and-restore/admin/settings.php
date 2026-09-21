<?php
if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- included from inside a method (WPCB_Admin::dashboard() etc), so these are function-scoped, not globals.

$options = get_option('wpcb_settings', []);
?>

<div class="wrap">
    <h1><?php esc_html_e('ReBuzz Backup and Restore Settings', 'rebuzz-backup-and-restore'); ?></h1>

    <form method="post" action="options.php">

        <?php settings_fields('wpcb_settings_group'); ?>

        <table class="form-table">

            <tr>
                <th scope="row"><?php esc_html_e('Include WordPress Core', 'rebuzz-backup-and-restore'); ?></th>
                <td>
                    <input
                        type="checkbox"
                        name="wpcb_settings[include_core]"
                        value="1"
                        <?php checked(!empty($options['include_core'])); ?>
                    >
                </td>
            </tr>

        </table>

        <?php submit_button(); ?>

    </form>
</div>