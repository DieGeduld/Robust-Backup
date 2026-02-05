/**
 * WP Robust Backup - Admin JavaScript
 *
 * Handles backup start, progress polling, cancellation, settings save, and backup deletion.
 */
(function ($) {
    'use strict';

    const data = window.wprbData || {};
    let isRunning = false;
    let startTime = null;
    let pollTimer = null;

    // ─────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────

    function formatTime(seconds) {
        if (seconds < 60) return seconds + 's';
        const m = Math.floor(seconds / 60);
        const s = seconds % 60;
        return m + 'min ' + s + 's';
    }

    function ajax(action, extraData) {
        return $.ajax({
            url: data.ajaxUrl,
            type: 'POST',
            data: Object.assign({
                action: action,
                nonce: data.nonce,
            }, extraData || {}),
        });
    }

    function showNotice(message, type) {
        const $done = $('#wprb-done-section');
        const $msg = $('#wprb-done-message');
        const icon = type === 'success' ? 'yes-alt' : 'warning';

        $done.find('.wprb-notice')
            .removeClass('wprb-notice-success wprb-notice-error')
            .addClass('wprb-notice-' + type);
        $done.find('.dashicons')
            .removeClass('dashicons-yes-alt dashicons-warning')
            .addClass('dashicons-' + icon);

        $msg.text(message);
        $done.slideDown(200);
    }

    // ─────────────────────────────────────────────
    // Backup Process
    // ─────────────────────────────────────────────

    function startBackup(type) {
        if (isRunning) return;

        isRunning = true;
        startTime = Date.now();

        // UI updates
        $('.wprb-start-backup').prop('disabled', true);
        $('#wprb-done-section').hide();
        $('#wprb-progress-section').slideDown(200);
        updateProgress(0, data.strings.backupStarted);

        ajax('wprb_start_backup', { type: type })
            .done(function (response) {
                if (response.success) {
                    processNext();
                } else {
                    backupError(response.data ? response.data.message : data.strings.error);
                }
            })
            .fail(function () {
                backupError(data.strings.error);
            });
    }

    function processNext() {
        if (!isRunning) return;

        ajax('wprb_process_backup')
            .done(function (response) {
                if (!response.success) {
                    backupError(response.data ? response.data.message : data.strings.error);
                    return;
                }

                const state = response.data;

                updateProgress(state.progress || 0, state.message || '');

                if (state.phase === 'done') {
                    backupComplete(state);
                } else {
                    // Continue processing with a small delay to not overload the server
                    pollTimer = setTimeout(processNext, 300);
                }
            })
            .fail(function (xhr) {
                // Retry on network errors (up to a point)
                if (xhr.status === 0 || xhr.status >= 500) {
                    pollTimer = setTimeout(processNext, 2000);
                } else {
                    backupError(data.strings.error);
                }
            });
    }

    function updateProgress(percent, message) {
        percent = Math.min(100, Math.max(0, percent));

        $('#wprb-progress-fill').css('width', percent + '%');
        $('#wprb-progress-percent').text(Math.round(percent) + '%');
        $('#wprb-progress-message').text(message);

        // Update elapsed time
        if (startTime) {
            const elapsed = Math.floor((Date.now() - startTime) / 1000);
            $('#wprb-progress-time').text('Laufzeit: ' + formatTime(elapsed));
        }
    }

    function backupComplete(state) {
        isRunning = false;
        startTime = null;

        $('#wprb-progress-section').slideUp(200, function () {
            updateProgress(0, '');
        });

        let message = data.strings.backupDone;
        if (state.errors && state.errors.length > 0) {
            message += ' (mit ' + state.errors.length + ' Fehler(n))';
            showNotice(message, 'error');
        } else {
            showNotice(message, 'success');
        }

        $('.wprb-start-backup').prop('disabled', false);
    }

    function backupError(message) {
        isRunning = false;
        startTime = null;

        if (pollTimer) {
            clearTimeout(pollTimer);
            pollTimer = null;
        }

        $('#wprb-progress-section').slideUp(200);
        showNotice(message || data.strings.error, 'error');
        $('.wprb-start-backup').prop('disabled', false);
    }

    function cancelBackup() {
        if (!confirm(data.strings.confirmCancel)) return;

        if (pollTimer) {
            clearTimeout(pollTimer);
            pollTimer = null;
        }

        ajax('wprb_cancel_backup').always(function () {
            isRunning = false;
            startTime = null;
            $('#wprb-progress-section').slideUp(200);
            showNotice(data.strings.backupCanceled, 'success');
            $('.wprb-start-backup').prop('disabled', false);
        });
    }

    // ─────────────────────────────────────────────
    // Backup Management
    // ─────────────────────────────────────────────

    function deleteBackup(backupId) {
        if (!confirm(data.strings.confirmDelete)) return;

        ajax('wprb_delete_backup', { backup_id: backupId })
            .done(function (response) {
                if (response.success) {
                    $('tr[data-id="' + backupId + '"]').fadeOut(300, function () {
                        $(this).remove();
                        if ($('#wprb-backup-list tr').length === 0) {
                            $('#wprb-backup-list').html(
                                '<tr><td colspan="6" class="wprb-muted">Keine Backups vorhanden.</td></tr>'
                            );
                        }
                    });
                } else {
                    alert(response.data ? response.data.message : data.strings.error);
                }
            })
            .fail(function () {
                alert(data.strings.error);
            });
    }

    // ─────────────────────────────────────────────
    // Settings
    // ─────────────────────────────────────────────

    function saveSettings(form) {
        const $btn = $('#wprb-save-settings');
        const $status = $('#wprb-settings-status');

        $btn.prop('disabled', true);
        $status.text(data.strings.saving);

        const formData = $(form).serialize();

        ajax('wprb_save_settings', $.deparam ? $.deparam(formData) : parseFormData(form))
            .done(function (response) {
                if (response.success) {
                    $status.text(data.strings.saved).css('color', '#00a32a');
                    setTimeout(function () {
                        $status.text('').css('color', '');
                    }, 3000);
                } else {
                    $status.text(response.data ? response.data.message : data.strings.error).css('color', '#d63638');
                }
            })
            .fail(function () {
                $status.text(data.strings.error).css('color', '#d63638');
            })
            .always(function () {
                $btn.prop('disabled', false);
            });
    }

    /**
     * Parse form data into an object (handles arrays like storage[]).
     */
    function parseFormData(form) {
        const obj = {};
        const formData = new FormData(form);

        for (const [key, value] of formData.entries()) {
            if (key.endsWith('[]')) {
                const cleanKey = key.slice(0, -2);
                if (!obj[cleanKey]) obj[cleanKey] = [];
                obj[cleanKey].push(value);
            } else {
                obj[key] = value;
            }
        }

        return obj;
    }

    // ─────────────────────────────────────────────
    // Event Bindings
    // ─────────────────────────────────────────────

    $(document).ready(function () {

        // Start backup buttons
        $(document).on('click', '.wprb-start-backup', function (e) {
            e.preventDefault();
            startBackup($(this).data('type'));
        });

        // Cancel backup
        $(document).on('click', '#wprb-cancel-btn', function (e) {
            e.preventDefault();
            cancelBackup();
        });

        // Delete backup
        $(document).on('click', '.wprb-delete-backup', function (e) {
            e.preventDefault();
            deleteBackup($(this).data('id'));
        });

        // Save settings
        $(document).on('submit', '#wprb-settings-form', function (e) {
            e.preventDefault();
            saveSettings(this);
        });

        // Check if a backup is already running on page load
        ajax('wprb_get_status').done(function (response) {
            if (response.success && response.data.running) {
                isRunning = true;
                startTime = Date.now() - (response.data.elapsed * 1000);

                $('.wprb-start-backup').prop('disabled', true);
                $('#wprb-progress-section').show();
                updateProgress(response.data.progress, response.data.message);
                processNext();
            }
        });
    });

})(jQuery);
