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
                    var noticeClass = state.files_failed
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