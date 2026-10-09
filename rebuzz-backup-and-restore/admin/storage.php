<?php

if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- included from inside a method (WPCB_Admin::dashboard() etc), so these are function-scoped, not globals.

$wpcbTab = 'wpcb-storage';

$storage = WPCB_Storage::settings();
$providers = WPCB_Remote_S3::providers();
$remotes = WPCB_Storage::remotes();
$s3 = $remotes['s3'] ?? null;
$dropbox = $remotes['dropbox'] ?? null;
$gdrive = $remotes['gdrive'] ?? null;
$s3SecretSaved = WPCB_Storage::hasSecret('s3_secret');
$dropboxKey = WPCB_Remote_Dropbox::appKey();
$gdriveRelay = WPCB_Remote_GDrive::relay();
$gdriveNotice = WPCB_Storage::gdriveNotice();
$history = (new WPCB_History())->get_backups();
$name = WPCB_Storage::OPTION;

WPCB_Storage::clearStaleParts();

?>

<div class="wrap wpcb-wrap">

    <?php include WPCB_PATH . 'admin/header.php'; ?>

    <?php settings_errors(); ?>

    <form method="post" action="options.php">

        <?php settings_fields('wpcb_storage_group'); ?>

        <div class="wpcb-grid wpcb-grid-2 wpcb-grid-top">

            <div class="wpcb-card">

                <h2>
                    <?php esc_html_e('Amazon S3 and compatible', 'rebuzz-backup-and-restore'); ?>
                    <?php if ($s3) : ?>
                        <span class="wpcb-tag wpcb-tag-ok"><?php esc_html_e('Set up', 'rebuzz-backup-and-restore'); ?></span>
                    <?php endif; ?>
                </h2>

                <p><?php esc_html_e('Amazon S3, Backblaze B2, Cloudflare R2, DigitalOcean Spaces, Wasabi, or any other service with the S3 API.', 'rebuzz-backup-and-restore'); ?></p>

                <?php if (WPCB_Storage::secretUnreadable('s3_secret')) : ?>
                    <div class="notice notice-warning inline"><p><?php esc_html_e('The saved secret access key can\'t be read on this server - the site\'s secret keys in wp-config.php have changed. Enter it again.', 'rebuzz-backup-and-restore'); ?></p></div>
                <?php endif; ?>

                <table class="form-table wpcb-compact" role="presentation">

                    <tr>
                        <th scope="row"><label for="wpcb-s3-provider"><?php esc_html_e('Service', 'rebuzz-backup-and-restore'); ?></label></th>
                        <td>
                            <select id="wpcb-s3-provider" name="<?php echo esc_attr($name); ?>[s3][provider]">
                                <?php foreach ($providers as $key => $provider) : ?>
                                    <option value="<?php echo esc_attr($key); ?>" data-region="<?php echo esc_attr($provider['region']); ?>" <?php selected($storage['s3']['provider'], $key); ?>><?php echo esc_html($provider['label']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>

                    <tr class="wpcb-s3-region-row">
                        <th scope="row"><label for="wpcb-s3-region"><?php esc_html_e('Region', 'rebuzz-backup-and-restore'); ?></label></th>
                        <td>
                            <input type="text" id="wpcb-s3-region" class="regular-text" name="<?php echo esc_attr($name); ?>[s3][region]" value="<?php echo esc_attr($storage['s3']['region']); ?>">
                            <p class="description wpcb-s3-b2-note"><?php esc_html_e('As shown on the bucket\'s page, e.g. us-west-004.', 'rebuzz-backup-and-restore'); ?></p>
                        </td>
                    </tr>

                    <tr class="wpcb-s3-endpoint-row">
                        <th scope="row"><label for="wpcb-s3-endpoint"><?php esc_html_e('Endpoint', 'rebuzz-backup-and-restore'); ?></label></th>
                        <td>
                            <input type="url" id="wpcb-s3-endpoint" class="regular-text" name="<?php echo esc_attr($name); ?>[s3][endpoint]" value="<?php echo esc_attr($storage['s3']['endpoint']); ?>" placeholder="https://">
                            <p class="description wpcb-s3-r2-note"><?php esc_html_e('The S3 API address from your R2 dashboard: https://<account ID>.r2.cloudflarestorage.com', 'rebuzz-backup-and-restore'); ?></p>
                            <p class="description wpcb-s3-other-note"><?php esc_html_e('The service\'s S3 endpoint address.', 'rebuzz-backup-and-restore'); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="wpcb-s3-bucket"><?php esc_html_e('Bucket', 'rebuzz-backup-and-restore'); ?></label></th>
                        <td><input type="text" id="wpcb-s3-bucket" class="regular-text" name="<?php echo esc_attr($name); ?>[s3][bucket]" value="<?php echo esc_attr($storage['s3']['bucket']); ?>"></td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="wpcb-s3-folder"><?php esc_html_e('Folder', 'rebuzz-backup-and-restore'); ?></label></th>
                        <td>
                            <input type="text" id="wpcb-s3-folder" class="regular-text" name="<?php echo esc_attr($name); ?>[s3][folder]" value="<?php echo esc_attr($storage['s3']['folder']); ?>">
                            <p class="description"><?php esc_html_e('Inside the bucket. Give each site its own folder.', 'rebuzz-backup-and-restore'); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="wpcb-s3-access-key"><?php esc_html_e('Access key ID', 'rebuzz-backup-and-restore'); ?></label></th>
                        <td><input type="text" id="wpcb-s3-access-key" class="regular-text" autocomplete="off" name="<?php echo esc_attr($name); ?>[s3][access_key]" value="<?php echo esc_attr($storage['s3']['access_key']); ?>"></td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="wpcb-s3-secret"><?php esc_html_e('Secret access key', 'rebuzz-backup-and-restore'); ?></label></th>
                        <td>
                            <input type="password" id="wpcb-s3-secret" class="regular-text" autocomplete="new-password" name="<?php echo esc_attr($name); ?>[s3][secret]" value="" placeholder="<?php echo $s3SecretSaved ? esc_attr__('Saved - leave blank to keep it', 'rebuzz-backup-and-restore') : ''; ?>">
                            <p class="description"><?php esc_html_e('Stored encrypted with a key from your wp-config.php, so your backups never contain it in readable form.', 'rebuzz-backup-and-restore'); ?></p>
                        </td>
                    </tr>

                    <tr class="wpcb-s3-path-row">
                        <th scope="row"><?php esc_html_e('Addresses', 'rebuzz-backup-and-restore'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" id="wpcb-s3-path-style" name="<?php echo esc_attr($name); ?>[s3][path_style]" value="1" <?php checked(!empty($storage['s3']['path_style'])); ?>>
                                <?php esc_html_e('Path-style (endpoint/bucket)', 'rebuzz-backup-and-restore'); ?>
                            </label>
                            <p class="description"><?php esc_html_e('Most S3-compatible services want this. Untick only if yours asks for bucket.endpoint addresses.', 'rebuzz-backup-and-restore'); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="wpcb-s3-keep"><?php esc_html_e('Keep', 'rebuzz-backup-and-restore'); ?></label></th>
                        <td>
                            <input type="number" id="wpcb-s3-keep" class="small-text" min="1" max="100" name="<?php echo esc_attr($name); ?>[s3][keep]" value="<?php echo esc_attr((int) $storage['s3']['keep']); ?>">
                            <?php esc_html_e('backups here', 'rebuzz-backup-and-restore'); ?>
                            <p class="description"><?php esc_html_e('When a new one arrives, older backups this site sent here are deleted. Nothing else in the bucket is touched.', 'rebuzz-backup-and-restore'); ?></p>
                        </td>
                    </tr>

                </table>

                <div class="wpcb-action">
                    <button type="button" id="wpcb-s3-test" class="button"><?php esc_html_e('Test connection', 'rebuzz-backup-and-restore'); ?></button>
                    <span id="wpcb-s3-status" class="wpcb-loading" aria-live="polite"></span>
                </div>

            </div>

            <div class="wpcb-stack">

            <div class="wpcb-card">

                <h2>
                    Dropbox
                    <?php if ($dropbox) : ?>
                        <span class="wpcb-tag wpcb-tag-ok"><?php esc_html_e('Connected', 'rebuzz-backup-and-restore'); ?></span>
                    <?php endif; ?>
                </h2>

                <?php if ($dropboxKey === '') : ?>

                    <p><?php esc_html_e('Dropbox needs this plugin\'s Dropbox app key, which isn\'t in this copy of the plugin.', 'rebuzz-backup-and-restore'); ?></p>

                <?php elseif (!$dropbox) : ?>

                    <p><?php esc_html_e('Backups go into a folder of their own under Apps in your Dropbox. The plugin can\'t see anything else there.', 'rebuzz-backup-and-restore'); ?></p>

                    <?php if (WPCB_Storage::secretUnreadable('dropbox_refresh')) : ?>
                        <div class="notice notice-warning inline"><p><?php esc_html_e('Dropbox was connected, but its saved access can\'t be read on this server - the site\'s secret keys in wp-config.php have changed. Connect it again.', 'rebuzz-backup-and-restore'); ?></p></div>
                    <?php endif; ?>

                    <ol class="wpcb-steps">
                        <li>
                            <button type="button" id="wpcb-dropbox-start" class="button button-primary"><?php esc_html_e('Connect Dropbox', 'rebuzz-backup-and-restore'); ?></button>
                            <p class="description"><?php esc_html_e('Opens Dropbox in a new tab. Sign in and click Allow.', 'rebuzz-backup-and-restore'); ?></p>
                            <p id="wpcb-dropbox-link" class="description" style="display:none;"></p>
                        </li>
                        <li>
                            <label for="wpcb-dropbox-code"><?php esc_html_e('Paste the code Dropbox shows you:', 'rebuzz-backup-and-restore'); ?></label><br>
                            <input type="text" id="wpcb-dropbox-code" class="regular-text" autocomplete="off">
                            <button type="button" id="wpcb-dropbox-finish" class="button"><?php esc_html_e('Finish connecting', 'rebuzz-backup-and-restore'); ?></button>
                        </li>
                    </ol>

                    <p id="wpcb-dropbox-status" class="wpcb-inline-status" aria-live="polite"></p>

                <?php else : ?>

                    <p>
                        <?php
                        printf(
                            /* translators: %s: Dropbox account, e.g. "Jane Doe (jane@example.com)" */
                            esc_html__('Connected to the Dropbox account of %s.', 'rebuzz-backup-and-restore'),
                            '<strong>' . esc_html(WPCB_Storage::account('dropbox')) . '</strong>'
                        );
                        ?>
                    </p>

                    <table class="form-table wpcb-compact" role="presentation">

                        <tr>
                            <th scope="row"><label for="wpcb-dropbox-folder"><?php esc_html_e('Folder', 'rebuzz-backup-and-restore'); ?></label></th>
                            <td>
                                <input type="text" id="wpcb-dropbox-folder" class="regular-text" name="<?php echo esc_attr($name); ?>[dropbox][folder]" value="<?php echo esc_attr($storage['dropbox']['folder']); ?>">
                                <p class="description"><?php esc_html_e('Inside the plugin\'s folder under Apps. Give each site its own folder.', 'rebuzz-backup-and-restore'); ?></p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row"><label for="wpcb-dropbox-keep"><?php esc_html_e('Keep', 'rebuzz-backup-and-restore'); ?></label></th>
                            <td>
                                <input type="number" id="wpcb-dropbox-keep" class="small-text" min="1" max="100" name="<?php echo esc_attr($name); ?>[dropbox][keep]" value="<?php echo esc_attr((int) $storage['dropbox']['keep']); ?>">
                                <?php esc_html_e('backups here', 'rebuzz-backup-and-restore'); ?>
                                <p class="description"><?php esc_html_e('When a new one arrives, older backups this site sent here are deleted.', 'rebuzz-backup-and-restore'); ?></p>
                            </td>
                        </tr>

                    </table>

                    <div class="wpcb-action">
                        <button type="button" id="wpcb-dropbox-test" class="button"><?php esc_html_e('Test connection', 'rebuzz-backup-and-restore'); ?></button>
                        <button type="button" id="wpcb-dropbox-disconnect" class="button button-link-delete"><?php esc_html_e('Disconnect', 'rebuzz-backup-and-restore'); ?></button>
                        <span id="wpcb-dropbox-status" class="wpcb-loading" aria-live="polite"></span>
                    </div>

                <?php endif; ?>

            </div>

            <div class="wpcb-card" id="wpcb-gdrive-card">

                <h2>
                    Google Drive
                    <?php if ($gdrive) : ?>
                        <span class="wpcb-tag wpcb-tag-ok"><?php esc_html_e('Connected', 'rebuzz-backup-and-restore'); ?></span>
                    <?php endif; ?>
                </h2>

                <?php // A success needs no notice: the card itself now says connected, and to which account. ?>
                <?php if ($gdriveNotice && !$gdriveNotice['ok']) : ?>
                    <div class="notice notice-error inline"><p><?php echo esc_html($gdriveNotice['text']); ?></p></div>
                <?php endif; ?>

                <?php if ($gdriveRelay === '') : ?>

                    <p><?php esc_html_e('Google Drive needs this plugin\'s sign-in service, which isn\'t set in this copy of the plugin.', 'rebuzz-backup-and-restore'); ?></p>

                <?php elseif (!$gdrive) : ?>

                    <p><?php esc_html_e('Backups go into a folder in your Google Drive. The plugin can only see the files it creates there - nothing else in your Drive.', 'rebuzz-backup-and-restore'); ?></p>

                    <?php if (WPCB_Storage::secretUnreadable('gdrive_refresh')) : ?>
                        <div class="notice notice-warning inline"><p><?php esc_html_e('Google Drive was connected, but its saved access can\'t be read on this server - the site\'s secret keys in wp-config.php have changed. Connect it again.', 'rebuzz-backup-and-restore'); ?></p></div>
                    <?php endif; ?>

                    <div class="wpcb-action">
                        <button type="button" id="wpcb-gdrive-start" class="button button-primary"><?php esc_html_e('Connect Google Drive', 'rebuzz-backup-and-restore'); ?></button>
                        <span id="wpcb-gdrive-status" class="wpcb-loading" aria-live="polite"></span>
                    </div>

                    <p class="description">
                        <?php
                        printf(
                            /* translators: %s: web address of the plugin's sign-in service */
                            esc_html__('Goes to Google through the plugin\'s sign-in service at %s, which confirms this site, then brings you back here. Your backups go straight between this site and Google.', 'rebuzz-backup-and-restore'),
                            esc_html((string) wp_parse_url($gdriveRelay, PHP_URL_HOST))
                        );
                        ?>
                    </p>

                <?php else : ?>

                    <p>
                        <?php
                        printf(
                            /* translators: %s: Google account, e.g. "Jane Doe (jane@example.com)" */
                            esc_html__('Connected to the Google Drive of %s.', 'rebuzz-backup-and-restore'),
                            '<strong>' . esc_html(WPCB_Storage::account('gdrive')) . '</strong>'
                        );
                        ?>
                    </p>

                    <table class="form-table wpcb-compact" role="presentation">

                        <tr>
                            <th scope="row"><label for="wpcb-gdrive-folder"><?php esc_html_e('Folder', 'rebuzz-backup-and-restore'); ?></label></th>
                            <td>
                                <input type="text" id="wpcb-gdrive-folder" class="regular-text" name="<?php echo esc_attr($name); ?>[gdrive][folder]" value="<?php echo esc_attr($storage['gdrive']['folder']); ?>">
                                <p class="description"><?php esc_html_e('In My Drive; made when the first backup arrives. Give each site its own folder.', 'rebuzz-backup-and-restore'); ?></p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row"><label for="wpcb-gdrive-keep"><?php esc_html_e('Keep', 'rebuzz-backup-and-restore'); ?></label></th>
                            <td>
                                <input type="number" id="wpcb-gdrive-keep" class="small-text" min="1" max="100" name="<?php echo esc_attr($name); ?>[gdrive][keep]" value="<?php echo esc_attr((int) $storage['gdrive']['keep']); ?>">
                                <?php esc_html_e('backups here', 'rebuzz-backup-and-restore'); ?>
                                <p class="description"><?php esc_html_e('When a new one arrives, older backups this site sent here are deleted for good, not moved to the bin.', 'rebuzz-backup-and-restore'); ?></p>
                            </td>
                        </tr>

                    </table>

                    <div class="wpcb-action">
                        <button type="button" id="wpcb-gdrive-test" class="button"><?php esc_html_e('Test connection', 'rebuzz-backup-and-restore'); ?></button>
                        <button type="button" id="wpcb-gdrive-disconnect" class="button button-link-delete"><?php esc_html_e('Disconnect', 'rebuzz-backup-and-restore'); ?></button>
                        <span id="wpcb-gdrive-status" class="wpcb-loading" aria-live="polite"></span>
                    </div>

                <?php endif; ?>

            </div>

            </div>

        </div>

        <div class="wpcb-card">

            <h2><?php esc_html_e('Sending backups to the cloud', 'rebuzz-backup-and-restore'); ?></h2>

            <table class="form-table" role="presentation">

                <tr>
                    <th scope="row"><?php esc_html_e('Send new backups to', 'rebuzz-backup-and-restore'); ?></th>
                    <td>
                        <fieldset>
                            <?php foreach (WPCB_Storage::ids() as $id) : ?>
                                <?php $remote = $remotes[$id] ?? null; ?>
                                <label>
                                    <input type="checkbox" name="<?php echo esc_attr($name); ?>[send][<?php echo esc_attr($id); ?>]" value="1" <?php checked(!empty($storage['send'][$id])); ?> <?php disabled($remote === null && empty($storage['send'][$id])); ?>>
                                    <?php if ($remote) : ?>
                                        <?php echo esc_html($remote->label()); ?> <span class="description">&mdash; <?php echo esc_html($remote->location()); ?></span>
                                    <?php else : ?>
                                        <?php echo esc_html(WPCB_Storage::label($id)); ?> <span class="description">(<?php esc_html_e('not set up yet', 'rebuzz-backup-and-restore'); ?>)</span>
                                    <?php endif; ?>
                                </label><br>
                            <?php endforeach; ?>
                        </fieldset>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><?php esc_html_e('Which backups', 'rebuzz-backup-and-restore'); ?></th>
                    <td>
                        <fieldset>
                            <label>
                                <input type="radio" name="<?php echo esc_attr($name); ?>[which]" value="scheduled" <?php checked($storage['which'], 'scheduled'); ?>>
                                <?php esc_html_e('Scheduled backups', 'rebuzz-backup-and-restore'); ?>
                            </label><br>
                            <label>
                                <input type="radio" name="<?php echo esc_attr($name); ?>[which]" value="all" <?php checked($storage['which'], 'all'); ?>>
                                <?php esc_html_e('Every new backup, including ones you start yourself', 'rebuzz-backup-and-restore'); ?>
                            </label>
                        </fieldset>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><?php esc_html_e('On this server', 'rebuzz-backup-and-restore'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="<?php echo esc_attr($name); ?>[keep_local]" value="1" <?php checked(!empty($storage['keep_local'])); ?>>
                            <?php esc_html_e('Keep a copy here too', 'rebuzz-backup-and-restore'); ?>
                        </label>
                        <p class="description"><?php esc_html_e('Untick to save disk space: the copy here is deleted once the backup has reached every place you chose. If any upload fails, the copy here is kept.', 'rebuzz-backup-and-restore'); ?></p>
                    </td>
                </tr>

            </table>

        </div>

        <?php submit_button(__('Save Storage Settings', 'rebuzz-backup-and-restore')); ?>

    </form>

    <?php if ($remotes) : ?>

        <div class="wpcb-card">

            <h2><?php esc_html_e('Backups in the cloud', 'rebuzz-backup-and-restore'); ?></h2>

            <p><?php esc_html_e('Bring a backup back to this server to restore it - for instance onto a new site after a server failure - or delete one you no longer need.', 'rebuzz-backup-and-restore'); ?></p>

            <?php foreach ($remotes as $id => $remote) : ?>

                <div class="wpcb-remote" data-remote="<?php echo esc_attr($id); ?>">

                    <div class="wpcb-remote-head">
                        <strong><?php echo esc_html($remote->label()); ?></strong>
                        <span class="description"><?php echo esc_html($remote->location()); ?></span>
                        <button type="button" class="button wpcb-remote-show"><?php esc_html_e('Show backups', 'rebuzz-backup-and-restore'); ?></button>
                    </div>

                    <div class="wpcb-remote-body"></div>

                    <div class="wpcb-progress wpcb-transfer-progress" style="display:none;">
                        <div class="wpcb-progress-bar">0%</div>
                    </div>

                    <p class="wpcb-loading wpcb-remote-status" aria-live="polite"></p>

                    <button type="button" class="button wpcb-transfer-cancel" style="display:none;"><?php esc_html_e('Cancel', 'rebuzz-backup-and-restore'); ?></button>

                </div>

            <?php endforeach; ?>

        </div>

        <?php if ($history) : ?>

            <div class="wpcb-card" id="wpcb-send-existing">

                <h2><?php esc_html_e('Send a backup you already have', 'rebuzz-backup-and-restore'); ?></h2>

                <div class="wpcb-action">

                    <select id="wpcb-send-file">
                        <?php foreach ($history as $backup) : ?>
                            <option value="<?php echo esc_attr($backup['name']); ?>"><?php echo esc_html($backup['date'] . ' (' . $backup['size'] . ')'); ?></option>
                        <?php endforeach; ?>
                    </select>

                    <select id="wpcb-send-remote">
                        <?php foreach ($remotes as $id => $remote) : ?>
                            <option value="<?php echo esc_attr($id); ?>"><?php echo esc_html($remote->label()); ?></option>
                        <?php endforeach; ?>
                    </select>

                    <button type="button" id="wpcb-send-start" class="button"><?php esc_html_e('Send', 'rebuzz-backup-and-restore'); ?></button>

                </div>

                <div class="wpcb-progress wpcb-transfer-progress" style="display:none;">
                    <div class="wpcb-progress-bar">0%</div>
                </div>

                <p class="wpcb-loading wpcb-remote-status" aria-live="polite"></p>

                <button type="button" class="button wpcb-transfer-cancel" style="display:none;"><?php esc_html_e('Cancel', 'rebuzz-backup-and-restore'); ?></button>

            </div>

        <?php endif; ?>

    <?php endif; ?>

</div>
