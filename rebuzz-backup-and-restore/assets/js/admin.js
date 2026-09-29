jQuery(function ($) {

    let jobId = null;

    const MAX_STEP_RETRIES = 5;
    const RETRY_DELAY_MS = 3000;

    /** Formats seconds as "1m 20s". */
    function formatDuration(seconds) {

        seconds = Math.max(0, parseInt(seconds, 10) || 0);

        var minutes = Math.floor(seconds / 60);
        var remainder = seconds % 60;

        if (minutes > 0) {
            return minutes + 'm ' + remainder + 's';
        }

        return remainder + 's';
    }

    function renderNotice($container, noticeClass, message, bold) {

        var $message = $('<p></p>').css('white-space', 'pre-line');

        if (bold) {
            $message.append($('<strong></strong>').text(message));
        } else {
            $message.text(message);
        }

        var $notice = $('<div></div>')
            .addClass('notice')
            .addClass(noticeClass)
            .append($message);

        $container.empty().append($notice);
    }

    /** Updates progress bar. */
    function updateProgress(state) {

        $('#wpcb-progress').show();

        $('#wpcb-progress-bar')
            .css('width', state.progress + '%')
            .text(state.progress + '%');

        var message = state.message;

        if (state.status === 'running') {

            message += ' — Elapsed: ' + formatDuration(state.elapsed);

            // state.eta legitimately rounds down to 0 both right at the
            // start (nothing to extrapolate from yet) and right before
            // completion (almost done) - progress > 0 tells those two
            // apart, so ETA shows as soon as it's meaningful instead of
            // silently vanishing again just before the job finishes.
            if (state.progress > 0) {
                message += ', ETA: ' + formatDuration(state.eta);
            }
        }

        $('#wpcb-loading').text(message);

    }

    /** Runs one backup step. */
    let stepRetryCount = 0;

    function processStep() {

        $.ajax({

            url: wpcb.ajax_url,

            type: 'POST',

            dataType: 'json',

            data: {

                action: 'wpcb_backup_step',

                nonce: wpcb.nonce,

                job_id: jobId

            },

            success: function (response) {

                // Real response received - resets retry count.
                stepRetryCount = 0;

                if (!response.success) {

                    $('#wpcb-loading').hide();

                    $('#wpcb-create-backup').prop('disabled', false);

                    renderNotice($('#wpcb-status'), 'notice-error', response.data, false);

                    return;

                }

                let state = response.data;

                updateProgress(state);

                if (state.status === 'running') {

                    setTimeout(processStep, 300);

                    return;

                }

                if (state.status === 'completed') {

                    $('#wpcb-create-backup').prop('disabled', false);

                    $('#wpcb-loading').hide();

                    // Same pattern as the restore flow's completion
                    // handler below - a backup can finish with some
                    // files skipped (state.files_failed), and that
                    // needs to actually reach the screen instead of
                    // always showing a plain success message.
                    var noticeClass = (state.files_failed || state.upload_failed)
                        ? 'notice-warning'
                        : 'notice-success';

                    renderNotice($('#wpcb-status'), noticeClass, state.message, true);

                    setTimeout(function () {

                        location.reload();

                    }, 1000);

                    return;

                }

                if (state.status === 'failed') {

                    $('#wpcb-create-backup').prop('disabled', false);

                    $('#wpcb-loading').hide();

                    renderNotice($('#wpcb-status'), 'notice-error', state.message, false);

                }

            },

            error: function () {

                if (stepRetryCount < MAX_STEP_RETRIES) {

                    stepRetryCount++;

                    $('#wpcb-loading').text(
                        'Connection hiccup - retrying (' + stepRetryCount + '/' + MAX_STEP_RETRIES + ')...'
                    );

                    setTimeout(processStep, RETRY_DELAY_MS);

                    return;
                }

                $('#wpcb-create-backup').prop('disabled', false);

                $('#wpcb-loading').hide();

                $('#wpcb-status').html(
                    '<div class="notice notice-error">' +
                    '<p>AJAX request failed after ' + MAX_STEP_RETRIES + ' retries. ' +
                    'The backup may still be running on the server - check backup.log, ' +
                    'or refresh this page and check Backup History before starting a new one.</p>' +
                    '</div>'
                );

            }

        });

    }

    /** Creates a backup. */
    $('#wpcb-create-backup').on('click', function () {

        // Otherwise a job that exhausted its retries and failed leaves
        // this at MAX_STEP_RETRIES, so the very first hiccup on this
        // new job immediately hits the exhausted-retry branch with zero
        // actual retries attempted.
        stepRetryCount = 0;

        $('#wpcb-status').html('');

        $('#wpcb-progress').show();

        $('#wpcb-loading')
            .show()
            .text('Starting backup...');

        $('#wpcb-progress-bar')
            .css('width', '0%')
            .text('0%');

        $(this).prop('disabled', true);

        $.ajax({

            url: wpcb.ajax_url,

            type: 'POST',

            dataType: 'json',

            data: {

                action: 'wpcb_start_backup',

                nonce: wpcb.nonce

            },

            success: function (response) {

                if (!response.success) {

                    $('#wpcb-create-backup').prop('disabled', false);

                    $('#wpcb-loading').hide();

                    // The restore flow's equivalent handler already
                    // shows the server's actual message (e.g. "a
                    // backup is already running, check backup.log") -
                    // this one was discarding it in favor of a generic
                    // string, losing that diagnostic detail.
                    renderNotice(
                        $('#wpcb-status'),
                        'notice-error',
                        response.data || 'Unable to start backup.',
                        false
                    );

                    return;

                }

                jobId = response.data.job_id;

                processStep();

            },

            error: function () {

                $('#wpcb-create-backup').prop('disabled', false);

                $('#wpcb-loading').hide();

                $('#wpcb-status').html(
                    '<div class="notice notice-error">' +
                    '<p>Unable to contact server.</p>' +
                    '</div>'
                );

            }

        });

    });

    /** Restore flow. */

    let restoreJobId = null;

    function updateRestoreProgress(state) {

        $('#wpcb-restore-progress').show();

        $('#wpcb-restore-progress-bar')
            .css('width', state.progress + '%')
            .text(state.progress + '%');

        var message = state.message;

        if (state.status === 'running') {

            message += ' — Elapsed: ' + formatDuration(state.elapsed);

            // state.eta legitimately rounds down to 0 both right at the
            // start (nothing to extrapolate from yet) and right before
            // completion (almost done) - progress > 0 tells those two
            // apart, so ETA shows as soon as it's meaningful instead of
            // silently vanishing again just before the job finishes.
            if (state.progress > 0) {
                message += ', ETA: ' + formatDuration(state.eta);
            }
        }

        $('#wpcb-restore-loading').text(message);
    }

    let restoreRetryCount = 0;

    function processRestoreStep() {

        $.ajax({

            url: wpcb.ajax_url,

            type: 'POST',

            dataType: 'json',

            data: {

                action: 'wpcb_restore_step',

                nonce: wpcb.nonce,

                job_id: restoreJobId

            },

            success: function (response) {

                // Real response received - resets retry count.
                restoreRetryCount = 0;

                if (!response.success) {

                    $('#wpcb-restore-loading').hide();

                    $('#wpcb-restore-backup').prop('disabled', false);

                    renderNotice($('#wpcb-restore-status'), 'notice-error', response.data, false);

                    return;
                }

                var state = response.data;

                updateRestoreProgress(state);

                if (state.status === 'running') {

                    setTimeout(processRestoreStep, 300);

                    return;
                }

                if (state.status === 'completed') {

                    $('#wpcb-restore-loading').hide();

                    $('#wpcb-restore-backup').prop('disabled', false);

                    var noticeClass = state.files_failed
                        ? 'notice-warning'
                        : 'notice-success';

                    renderNotice($('#wpcb-restore-status'), noticeClass, state.message, true);

                    // Rewrite rules were rebuilt before the restored plugins loaded - see permalink_notice().
                    $('#wpcb-restore-status').append(
                        $('<div class="notice notice-info"></div>').append(
                            $('<p></p>').text(wpcb.i18n.permalinks),
                            $('<p></p>').append(
                                $('<a class="button button-primary"></a>')
                                    .attr('href', wpcb.permalinks_url)
                                    .text(wpcb.i18n.permalinks_button)
                            )
                        )
                    );

                    return;
                }

                if (state.status === 'failed') {

                    $('#wpcb-restore-loading').hide();

                    $('#wpcb-restore-backup').prop('disabled', false);

                    renderNotice($('#wpcb-restore-status'), 'notice-error', state.message, false);
                }

            },

            error: function () {

                if (restoreRetryCount < MAX_STEP_RETRIES) {

                    restoreRetryCount++;

                    $('#wpcb-restore-loading').text(
                        'Connection hiccup - retrying (' + restoreRetryCount + '/' + MAX_STEP_RETRIES + ')...'
                    );

                    setTimeout(processRestoreStep, RETRY_DELAY_MS);

                    return;
                }

                $('#wpcb-restore-loading').hide();

                $('#wpcb-restore-backup').prop('disabled', false);

                $('#wpcb-restore-status').html(
                    '<div class="notice notice-error">' +
                    '<p>AJAX request failed after ' + MAX_STEP_RETRIES + ' retries. ' +
                    'The restore may still be running on the server - check restore.log ' +
                    'before starting a new attempt.</p>' +
                    '</div>'
                );

            }

        });

    }

    $('#wpcb-restore-backup').on('click', function () {

        var file = $(this).data('file');

        if (!file) {
            return;
        }

        var confirmed = window.confirm(
            'Restoring will overwrite your current site files and database. ' +
            'This cannot be undone. Continue?'
        );

        if (!confirmed) {
            return;
        }

        // Same reasoning as stepRetryCount in the backup flow above.
        restoreRetryCount = 0;

        $('#wpcb-restore-status').html('');

        $('#wpcb-restore-progress').show();

        $('#wpcb-restore-loading')
            .show()
            .text('Starting restore...');

        $('#wpcb-restore-progress-bar')
            .css('width', '0%')
            .text('0%');

        $(this).prop('disabled', true);

        $.ajax({

            url: wpcb.ajax_url,

            type: 'POST',

            dataType: 'json',

            data: {

                action: 'wpcb_start_restore',

                nonce: wpcb.nonce,

                file: file,

                remove_extra_files: $('#wpcb-remove-extra-files').is(':checked') ? 1 : 0

            },

            success: function (response) {

                if (!response.success) {

                    $('#wpcb-restore-backup').prop('disabled', false);

                    $('#wpcb-restore-loading').hide();

                    renderNotice(
                        $('#wpcb-restore-status'),
                        'notice-error',
                        response.data || 'Unable to start restore.',
                        false
                    );

                    return;
                }

                restoreJobId = response.data.job_id;

                processRestoreStep();

            },

            error: function () {

                $('#wpcb-restore-backup').prop('disabled', false);

                $('#wpcb-restore-loading').hide();

                $('#wpcb-restore-status').html(
                    '<div class="notice notice-error">' +
                    '<p>Unable to contact server.</p>' +
                    '</div>'
                );

            }

        });

    });

    /** Deletes a backup. */

    $(document).on('click', '.wpcb-delete-backup', function () {

        var $button = $(this);
        var file = $button.data('file');

        if (!file) {
            return;
        }

        var confirmed = window.confirm(
            'Delete this backup permanently? This cannot be undone.'
        );

        if (!confirmed) {
            return;
        }

        $button.prop('disabled', true).text('Deleting...');

        $.ajax({

            url: wpcb.ajax_url,

            type: 'POST',

            dataType: 'json',

            data: {

                action: 'wpcb_delete_backup',

                nonce: wpcb.nonce,

                file: file

            },

            success: function (response) {

                if (!response.success) {

                    $button.prop('disabled', false).text('Delete');

                    window.alert(response.data || 'Unable to delete backup.');

                    return;
                }

                var $row = $button.closest('tr[data-backup-row]');
                var $tbody = $row.closest('tbody');

                $row.fadeOut(200, function () {

                    $row.remove();

                    if ($tbody.find('tr').length === 0) {

                        $tbody.closest('table').replaceWith(
                            $('<p class="wpcb-empty"></p>').text(wpcb.i18n.no_backups)
                        );
                    }
                });

            },

            error: function () {

                $button.prop('disabled', false).text('Delete');

                window.alert('Unable to contact server.');

            }

        });

    });

    /** Clears temp files. */

    $('#wpcb-clear-temp').on('click', function () {

        var $button = $(this);

        var confirmed = window.confirm(
            'Delete all temporary files left behind by incomplete backups? ' +
            'This cannot be undone.'
        );

        if (!confirmed) {
            return;
        }

        $button.prop('disabled', true).text('Clearing...');

        $.ajax({

            url: wpcb.ajax_url,

            type: 'POST',

            dataType: 'json',

            data: {

                action: 'wpcb_clear_temp',

                nonce: wpcb.nonce

            },

            success: function (response) {

                if (!response.success) {

                    $button.prop('disabled', false).text('Clear Temporary Files');

                    window.alert(response.data || 'Unable to clear temporary files.');

                    return;
                }

                $('#wpcb-clear-temp-status').text('Freed ' + response.data.freed + '.');

                $button.hide();

            },

            error: function () {

                $button.prop('disabled', false).text('Clear Temporary Files');

                window.alert('Unable to contact server.');

            }

        });

    });

    /** Schedule tab: only the rows that apply to the chosen frequency. */

    function toggleScheduleRows() {

        var frequency = $('#wpcb-frequency').val();

        $('.wpcb-when-weekly').toggle(frequency === 'weekly');
        $('.wpcb-when-on').toggle(frequency !== 'off');
    }

    if ($('#wpcb-frequency').length) {
        toggleScheduleRows();
        $('#wpcb-frequency').on('change', toggleScheduleRows);
    }

    /** Sends a test email to the address typed in, saved or not. */

    $('#wpcb-test-email').on('click', function () {

        var $button = $(this);
        var $status = $('#wpcb-test-email-status');

        $button.prop('disabled', true);
        $status.removeClass('is-error').text(wpcb.i18n.sending);

        $.post(wpcb.ajax_url, {
            action: 'wpcb_test_email',
            nonce: wpcb.nonce,
            email: $('#wpcb-email').val()
        }, null, 'json')
            .done(function (response) {
                $status.toggleClass('is-error', !response.success).text(response.data);
            })
            .fail(function () {
                $status.addClass('is-error').text(wpcb.i18n.no_server);
            })
            .always(function () {
                $button.prop('disabled', false);
            });
    });

    /** Progress of a background (scheduled) backup; reloads the page when it ends. */

    function pollBackground() {

        $.post(wpcb.ajax_url, {
            action: 'wpcb_background_status',
            nonce: wpcb.nonce
        }, null, 'json')
            .done(function (response) {

                var state = response && response.success ? response.data : null;

                if (state && state.status === 'running') {

                    $('#wpcb-bg-progress-bar')
                        .css('width', state.progress + '%')
                        .text(state.progress + '%');

                    $('#wpcb-bg-message').text(state.message);

                    setTimeout(pollBackground, 3000);

                    return;
                }

                $('#wpcb-bg-message').text(wpcb.i18n.bg_done);

                setTimeout(function () {
                    location.reload();
                }, 1500);
            })
            .fail(function () {
                setTimeout(pollBackground, 6000);
            });
    }

    if (String($('#wpcb-bg').data('running')) === '1') {
        setTimeout(pollBackground, 3000);
    }

    /** Starts a scheduled-style backup in the background. */

    $('#wpcb-run-schedule-now').on('click', function () {

        var $button = $(this);
        var $status = $('#wpcb-run-now-status');

        $button.prop('disabled', true);
        $status.removeClass('is-error').text(wpcb.i18n.starting);

        $.post(wpcb.ajax_url, {
            action: 'wpcb_run_schedule_now',
            nonce: wpcb.nonce
        }, null, 'json')
            .done(function (response) {

                if (!response.success) {
                    $button.prop('disabled', false);
                    $status.addClass('is-error').text(response.data);
                    return;
                }

                $status.text('');
                $('#wpcb-bg').show();

                $('html, body').animate({ scrollTop: $('#wpcb-bg').offset().top - 60 }, 200);

                setTimeout(pollBackground, 1500);
            })
            .fail(function () {
                $button.prop('disabled', false);
                $status.addClass('is-error').text(wpcb.i18n.no_server);
            });
    });

    /** Storage tab: S3 fields that apply to the chosen service. */

    function toggleS3Rows() {

        var provider = $('#wpcb-s3-provider').val();
        var needsEndpoint = provider === 'r2' || provider === 'other';

        $('.wpcb-s3-region-row').toggle(provider !== 'r2');
        $('.wpcb-s3-endpoint-row').toggle(needsEndpoint);
        $('.wpcb-s3-path-row').toggle(provider === 'other');
        $('.wpcb-s3-r2-note').toggle(provider === 'r2');
        $('.wpcb-s3-other-note').toggle(provider === 'other');
        $('.wpcb-s3-b2-note').toggle(provider === 'b2');
        $('#wpcb-s3-region').attr('placeholder', $('#wpcb-s3-provider option:selected').data('region') || '');
    }

    if ($('#wpcb-s3-provider').length) {
        toggleS3Rows();
        $('#wpcb-s3-provider').on('change', toggleS3Rows);
    }

    /** Runs a Storage-tab action and shows its answer next to the button. */
    function storageAction($button, $status, data, onSuccess, onFail) {

        $button.prop('disabled', true);
        $status.removeClass('is-error').text(wpcb.i18n.working);

        $.post(wpcb.ajax_url, $.extend({ nonce: wpcb.nonce }, data), null, 'json')
            .done(function (response) {

                if (!response.success) {
                    $status.addClass('is-error').text(response.data);

                    if (onFail) {
                        onFail();
                    }

                    return;
                }

                $status.text(typeof response.data === 'string' ? response.data : '');

                if (onSuccess) {
                    onSuccess(response.data);
                }
            })
            .fail(function () {
                $status.addClass('is-error').text(wpcb.i18n.no_server);

                if (onFail) {
                    onFail();
                }
            })
            .always(function () {
                $button.prop('disabled', false);
            });
    }

    $('#wpcb-s3-test').on('click', function () {

        var data = { action: 'wpcb_s3_test' };

        // The fields as typed, saved or not.
        $('[name^="wpcb_storage[s3]"]').each(function () {

            var key = this.name.replace('wpcb_storage[s3]', 's3');

            if (this.type === 'checkbox') {
                data[key] = this.checked ? 1 : 0;
            } else {
                data[key] = $(this).val();
            }
        });

        storageAction($(this), $('#wpcb-s3-status'), data);
    });

    /** Dropbox: open the consent page, then trade the pasted code for access. */

    $('#wpcb-dropbox-start').on('click', function () {

        // Opened now, while the click still counts, or pop-up blockers stop it.
        var win = window.open('', '_blank');

        storageAction($(this), $('#wpcb-dropbox-status'), { action: 'wpcb_dropbox_start' }, function (data) {

            if (win) {
                win.location = data.url;
            } else {
                $('#wpcb-dropbox-link').show().empty().append(
                    $('<a target="_blank" rel="noopener"></a>').attr('href', data.url).text(wpcb.i18n.open_dropbox)
                );
            }

            $('#wpcb-dropbox-code').trigger('focus');
        }, function () {
            if (win) {
                win.close();
            }
        });
    });

    $('#wpcb-dropbox-finish').on('click', function () {

        storageAction($(this), $('#wpcb-dropbox-status'), {
            action: 'wpcb_dropbox_finish',
            code: $('#wpcb-dropbox-code').val()
        }, function () {
            setTimeout(function () {
                location.reload();
            }, 800);
        });
    });

    $('#wpcb-dropbox-test').on('click', function () {
        storageAction($(this), $('#wpcb-dropbox-status'), { action: 'wpcb_dropbox_test' });
    });

    $('#wpcb-dropbox-disconnect').on('click', function () {

        if (!window.confirm(wpcb.i18n.disconnect_confirm)) {
            return;
        }

        storageAction($(this), $('#wpcb-dropbox-status'), { action: 'wpcb_dropbox_disconnect' }, function () {
            location.reload();
        });
    });

    /** Drives a Storage-tab transfer one chunk per request, like a manual backup. */

    function runTransfer(jobId, $box, onDone) {

        var $bar = $box.find('.wpcb-progress-bar');
        var $status = $box.find('.wpcb-remote-status');
        var retries = 0;

        $box.find('.wpcb-transfer-progress').show();

        function step() {

            $.post(wpcb.ajax_url, {
                action: 'wpcb_transfer_step',
                nonce: wpcb.nonce,
                job_id: jobId
            }, null, 'json')
                .done(function (response) {

                    retries = 0;

                    if (!response.success) {
                        $status.addClass('is-error').text(response.data);
                        onDone(false);
                        return;
                    }

                    var state = response.data;

                    $bar.css('width', state.progress + '%').text(state.progress + '%');
                    $status.toggleClass('is-error', state.status === 'failed').text(state.message);

                    if (state.status === 'running') {
                        setTimeout(step, 200);
                        return;
                    }

                    onDone(state.status === 'completed');
                })
                .fail(function () {

                    if (retries++ < 5) {
                        setTimeout(step, 3000);
                        return;
                    }

                    $status.addClass('is-error').text(wpcb.i18n.no_server);
                    onDone(false);
                });
        }

        step();
    }

    function startTransfer(direction, remote, name, $box, onDone) {

        var $status = $box.find('.wpcb-remote-status');

        $status.removeClass('is-error').text(wpcb.i18n.starting);
        $box.find('.wpcb-progress-bar').css('width', '0%').text('0%');

        $.post(wpcb.ajax_url, {
            action: 'wpcb_transfer_start',
            nonce: wpcb.nonce,
            direction: direction,
            remote: remote,
            name: name
        }, null, 'json')
            .done(function (response) {

                if (!response.success) {
                    $status.addClass('is-error').text(response.data);
                    onDone(false);
                    return;
                }

                runTransfer(response.data.job_id, $box, onDone);
            })
            .fail(function () {
                $status.addClass('is-error').text(wpcb.i18n.no_server);
                onDone(false);
            });
    }

    /** Backups in one cloud destination, with Download and Delete. */

    // keepStatus: a refresh after a transfer, whose result message should stay on screen.
    function loadRemote($box, keepStatus) {

        var remote = $box.data('remote');
        var $body = $box.find('.wpcb-remote-body');
        var $status = $box.find('.wpcb-remote-status');

        if (!keepStatus) {
            $status.removeClass('is-error').text(wpcb.i18n.working);
        }

        $.post(wpcb.ajax_url, { action: 'wpcb_remote_list', nonce: wpcb.nonce, remote: remote }, null, 'json')
            .done(function (response) {

                if (!response.success) {
                    $status.addClass('is-error').text(response.data);
                    return;
                }

                if (!keepStatus) {
                    $status.text('');
                }

                $body.empty();

                if (!response.data.length) {
                    $body.append($('<p class="wpcb-empty"></p>').text(wpcb.i18n.cloud_empty));
                    return;
                }

                var $tbody = $('<tbody></tbody>');

                $.each(response.data, function (i, item) {

                    var $name = $('<td></td>')
                        .append($('<span class="wpcb-backup-date"></span>').text(item.date))
                        .append($('<span class="wpcb-backup-file"></span>').text(item.name));

                    if (item.local) {
                        $name.find('.wpcb-backup-date').append($('<span class="wpcb-tag"></span>').text(wpcb.i18n.on_server));
                    }

                    var $actions = $('<td class="wpcb-col-actions"></td>')
                        .append($('<button type="button" class="button wpcb-remote-download"></button>').text(wpcb.i18n.download).prop('disabled', item.local))
                        .append($('<button type="button" class="button button-link-delete wpcb-remote-delete"></button>').text(wpcb.i18n.delete));

                    $tbody.append(
                        $('<tr></tr>').attr('data-name', item.name)
                            .append($name)
                            .append($('<td class="wpcb-col-size"></td>').text(item.size))
                            .append($actions)
                    );
                });

                $body.append($('<table class="widefat striped wpcb-backups"></table>').append($tbody));
            })
            .fail(function () {
                $status.addClass('is-error').text(wpcb.i18n.no_server);
            });
    }

    $('.wpcb-remote-show').on('click', function () {
        loadRemote($(this).closest('.wpcb-remote'));
    });

    $(document).on('click', '.wpcb-remote-download', function () {

        var $box = $(this).closest('.wpcb-remote');
        var name = $(this).closest('tr').data('name');

        $box.find('button').prop('disabled', true);

        startTransfer('down', $box.data('remote'), name, $box, function () {
            $box.find('button').prop('disabled', false);
            loadRemote($box, true);
        });
    });

    $(document).on('click', '.wpcb-remote-delete', function () {

        var $box = $(this).closest('.wpcb-remote');
        var $row = $(this).closest('tr');

        if (!window.confirm(wpcb.i18n.cloud_delete_confirm)) {
            return;
        }

        storageAction($(this), $box.find('.wpcb-remote-status'), {
            action: 'wpcb_remote_delete',
            remote: $box.data('remote'),
            name: $row.data('name')
        }, function () {
            $row.fadeOut(200, function () {
                $row.remove();
            });
        });
    });

    $('#wpcb-send-start').on('click', function () {

        var $box = $('#wpcb-send-existing');
        var remote = $('#wpcb-send-remote').val();

        $box.find('button, select').prop('disabled', true);

        startTransfer('up', remote, $('#wpcb-send-file').val(), $box, function () {

            $box.find('button, select').prop('disabled', false);

            // Refresh that destination's list if it's open.
            var $list = $('.wpcb-remote[data-remote="' + remote + '"]');

            if ($list.find('table, .wpcb-empty').length) {
                loadRemote($list, true);
            }
        });
    });

    /** Clears other plugins' backup data. */

    $('#wpcb-clear-foreign-backups').on('click', function () {

        var $button = $(this);

        var confirmed = window.confirm(
            'Delete this old backup data from another plugin? ' +
            'This cannot be undone.'
        );

        if (!confirmed) {
            return;
        }

        $button.prop('disabled', true).text('Clearing...');

        $.ajax({

            url: wpcb.ajax_url,

            type: 'POST',

            dataType: 'json',

            data: {

                action: 'wpcb_clear_foreign_backups',

                nonce: wpcb.nonce

            },

            success: function (response) {

                if (!response.success) {

                    $button.prop('disabled', false).text('Clear Other Plugins\' Backup Data');

                    window.alert(response.data || 'Unable to clear that data.');

                    return;
                }

                $('#wpcb-clear-foreign-backups-status').text('Freed ' + response.data.freed + '.');

                $button.hide();

            },

            error: function () {

                $button.prop('disabled', false).text('Clear Other Plugins\' Backup Data');

                window.alert('Unable to contact server.');

            }

        });

    });

});