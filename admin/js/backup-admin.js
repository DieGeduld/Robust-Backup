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

    function showNotice(message, type, details = '') {
        const $done = $('#wprb-done-section');
        const $msg = $('#wprb-done-message');
        const icon = type === 'success' ? 'yes-alt' : 'warning';
        
        // Map types to CSS classes
        const noticeClass = type === 'success' ? 'wprb-notice-success' : (type === 'error' ? 'wprb-notice-error' : 'wprb-notice-warning');

        $done.find('.wprb-notice')
            .removeClass('wprb-notice-success wprb-notice-error wprb-notice-warning')
            .addClass(noticeClass);
        $done.find('.dashicons')
            .removeClass('dashicons-yes-alt dashicons-warning')
            .addClass('dashicons-' + icon);

        $msg.text(message);
        
        // Remove old details if any
        $done.find('.wprb-notice-details').remove();
        
        if (details) {
            $msg.after('<div class="wprb-notice-details">' + details + '</div>');
        }

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
        updateProgress({ progress: 0, phase: 'init', message: data.strings.backupStarted });

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

                updateProgress(state);

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

    function getPhaseLabel(phase) {
        const labels = {
            'init': 'Initialisierung',
            'database': 'Datenbank-Export',
            'files': 'Dateien-Archivierung',
            'compress': 'Komprimierung',
            'upload': 'Upload zu Cloud',
            'cleanup': 'Bereinigung',
            'done': 'Abgeschlossen',
            'idle': 'Wartet'
        };
        return labels[phase] || phase;
    }

    function updateStepper(phase, type) {
        // Set type class on wrapper for CSS filtering
        const $stepper = $('.wprb-stepper');
        $stepper.removeClass('type-full type-db_only type-files_only');
        if (type) {
            $stepper.addClass('type-' + type);
        }

        // Map backend phases to stepper steps
        // Steps: database, files, upload, done
        const steps = ['database', 'files', 'upload', 'done'];
        
        // Map 'init' to database for visual purposes
        let currentStep = phase;
        if (currentStep === 'init') currentStep = 'database';
        if (currentStep === 'compress') currentStep = 'files';
        if (currentStep === 'cleanup') currentStep = 'done';

        const currentIndex = steps.indexOf(currentStep);

        // Update steps
        steps.forEach((step, index) => {
            const $el = $('#step-' + step);
            $el.removeClass('active done');

            if (index < currentIndex || phase === 'done') {
                $el.addClass('done');
                // Change icon to checkmark if done
                $el.find('.step-icon').removeClass('dashicons-database dashicons-media-archive dashicons-cloud-upload').addClass('dashicons-yes');
            } else if (index === currentIndex && phase !== 'done') {
                $el.addClass('active');
                // Restore original icon (simplified logic, ideally store original class)
                if (step === 'database') $el.find('.step-icon').removeClass('dashicons-yes').addClass('dashicons-database');
                if (step === 'files') $el.find('.step-icon').removeClass('dashicons-yes').addClass('dashicons-media-archive');
                if (step === 'upload') $el.find('.step-icon').removeClass('dashicons-yes').addClass('dashicons-cloud-upload');
                if (step === 'done') $el.find('.step-icon').removeClass('dashicons-yes').addClass('dashicons-flag');
            } else {
                // Future steps
                // Restore icons
                if (step === 'database') $el.find('.step-icon').removeClass('dashicons-yes').addClass('dashicons-database');
                if (step === 'files') $el.find('.step-icon').removeClass('dashicons-yes').addClass('dashicons-media-archive');
                if (step === 'upload') $el.find('.step-icon').removeClass('dashicons-yes').addClass('dashicons-cloud-upload');
                if (step === 'done') $el.find('.step-icon').removeClass('dashicons-yes').addClass('dashicons-flag');
            }
        });

        // Update lines (simplified: if step 1 is done, line 1 is done)
        const $lines = $('.wprb-step-line');
        $lines.removeClass('done');
        
        if (currentIndex > 0 || phase === 'done') $lines.eq(0).addClass('done'); // db -> files line
        if (currentIndex > 1 || phase === 'done') $lines.eq(1).addClass('done'); // files -> upload line
        if (currentIndex > 2 || phase === 'done') $lines.eq(2).addClass('done'); // upload -> done line
    }

    let currentPhase = null;
    let phaseStartTime = null;

    function updateProgress(state) {
        const percent = Math.min(100, Math.max(0, state.progress || 0));
        const phaseLabel = getPhaseLabel(state.phase);

        // Detect phase change
        if (state.phase !== currentPhase) {
            currentPhase = state.phase;
            phaseStartTime = Date.now();
        }

        $('#wprb-progress-fill').css('width', percent + '%');
        $('#wprb-progress-percent').text(Math.round(percent) + '%');
        
        // Update Stepper
        updateStepper(state.phase, state.type);
        
        // Show phase clearly above message
        const messageHtml = '<strong>' + phaseLabel + '</strong><br>' + (state.message || '');
        $('#wprb-progress-message').html(messageHtml);

        // Update elapsed time & ETA
        let timeText = '';
        const now = Date.now();

        if (startTime) {
            const elapsed = Math.floor((now - startTime) / 1000);
            timeText += 'Laufzeit: ' + formatTime(elapsed);
        }

        // Calculate ETA for current phase (only if running > 2s and progress > 2% to avoid noise)
        if (phaseStartTime && percent > 2 && percent < 100 && (now - phaseStartTime) > 2000) {
            const phaseElapsed = (now - phaseStartTime) / 1000;
            const progressRatio = percent / 100;
            
            // Estimated Total Time for this phase = Elapsed / Ratio
            const estimatedTotal = phaseElapsed / progressRatio;
            const remaining = Math.max(0, Math.ceil(estimatedTotal - phaseElapsed));
            
            // Only show if realistic (e.g. not 0s and not huge)
            if (remaining > 0 && remaining < 3600) {
                timeText += ' <span style="margin: 0 5px; color: #dcdcde;">|</span> Noch ca. ' + formatTime(remaining);
            }
        }

        $('#wprb-progress-time').html(timeText);
    }

    function backupComplete(state) {
        isRunning = false;
        startTime = null;

        $('#wprb-progress-section').slideUp(200, function () {
            updateProgress({ progress: 100, phase: 'done', message: '' });
        });

        let message = data.strings.backupDone;
        let type = 'success';
        let details = '';

        if (state.errors && state.errors.length > 0) {
            message += ' wurde mit ' + state.errors.length + ' Fehler(n) abgeschlossen.';
            type = 'warning'; // Use warning instead of error so it's not red/scary if it partially succeeded
            
            // Format errors as a list for display
            details = '<ul class="wprb-error-list" style="margin-top: 10px; list-style: disc inside; text-align: left;">';
            state.errors.forEach(function(err) {
                details += '<li>' + err + '</li>';
            });
            details += '</ul>';
        }

        showNotice(message, type, details);

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
    // Settings & Storage
    // ─────────────────────────────────────────────

    function saveSettings(form) {
        const $form = $(form);
        const $btn = $form.find('button[type="submit"]');
        const $status = $form.find('.wprb-muted').last(); // Usually the status span is after the button

        $btn.prop('disabled', true);
        $status.text(data.strings.saving);

        const formData = $form.serialize();

        ajax('wprb_save_settings', $.deparam ? $.deparam(formData) : parseFormData(form))
            .done(function (response) {
                if (response.success) {
                    $status.text(data.strings.saved).css('color', '#00a32a');

                    setTimeout(function () {
                        location.reload();
                    }, 1000);
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
    
    // Toggle storage settings visibility
    function toggleStorageSettings() {
        $('.wprb-storage-checkbox').each(function() {
            const target = $(this).data('target');
            if (target) {
                if ($(this).is(':checked')) {
                    $(target).slideDown(200);
                } else {
                    $(target).slideUp(200);
                }
            }
        });
    }

    // ─────────────────────────────────────────────
    // Schedules
    // ─────────────────────────────────────────────

    function saveSchedule(form) {
        const $btn = $(form).find('button[type="submit"]');
        $btn.prop('disabled', true);

        const formData = $(form).serialize();
        const dataObj = $.deparam ? $.deparam(formData) : parseFormData(form);
        const action = dataObj.action || 'wprb_add_schedule';

        ajax(action, dataObj)
            .done(function (response) {
                if (response.success) {
                    location.reload();
                } else {
                    alert(response.data ? response.data.message : data.strings.error);
                    $btn.prop('disabled', false);
                }
            })
            .fail(function () {
                alert(data.strings.error);
                $btn.prop('disabled', false);
            });
    }

    function deleteSchedule(id) {
        if (!confirm('Diesen Zeitplan wirklich löschen?')) return;

        ajax('wprb_delete_schedule', { schedule_id: id })
            .done(function (response) {
                if (response.success) {
                    location.reload();
                } else {
                    alert(response.data ? response.data.message : data.strings.error);
                }
            })
            .fail(function () {
                alert(data.strings.error);
            });
    }

    // ─────────────────────────────────────────────
    // Restore
    // ─────────────────────────────────────────────

    let restoreRunning = false;
    let restoreStartTime = null;
    let restorePollTimer = null;
    let currentAnalysis = null;

    function analyzeBackup(backupId) {
        const $btn = $('#wprb-analyze-btn');
        $btn.prop('disabled', true).text('Analysiere...');

        ajax('wprb_analyze_backup', { backup_id: backupId })
            .done(function (response) {
                if (response.success) {
                    currentAnalysis = response.data;
                    showAnalysisResult(response.data);
                } else {
                    alert(response.data ? response.data.message : data.strings.error);
                }
            })
            .fail(function () {
                alert(data.strings.error);
            })
            .always(function () {
                $btn.prop('disabled', false).html('<span class="dashicons dashicons-search"></span> Backup analysieren');
            });
    }

    function showAnalysisResult(info) {
        const typeLabels = { full: 'Vollständig', db_only: 'Nur Datenbank', files_only: 'Nur Dateien' };

        $('#wprb-ri-date').text(info.date);
        $('#wprb-ri-type').text(typeLabels[info.type] || info.type);
        $('#wprb-ri-wp').text(info.wp_version);
        $('#wprb-ri-url').text(info.site_url);
        $('#wprb-ri-db').text(info.has_db ? '✅ Vorhanden (' + info.db_size + ')' : '❌ Nicht vorhanden');
        $('#wprb-ri-files').text(info.has_files ? '✅ ' + info.archives.length + ' Archiv(e) (' + info.archive_size + ')' : '❌ Nicht vorhanden');

        // Enable/disable checkboxes based on what's available
        const $dbCheck = $('#wprb-restore-db');
        const $filesCheck = $('#wprb-restore-files');

        $dbCheck.prop('disabled', !info.has_db).prop('checked', info.has_db);
        $filesCheck.prop('disabled', !info.has_files).prop('checked', info.has_files);

        $('#wprb-opt-db-card').toggleClass('wprb-checkbox-disabled', !info.has_db);
        $('#wprb-opt-files-card').toggleClass('wprb-checkbox-disabled', !info.has_files);

        $('#wprb-restore-step1').slideUp(200, function () {
            $('#wprb-restore-step2').slideDown(200);
        });
    }

    function startRestore() {
        if (restoreRunning) return;

        const backupId       = $('#wprb-restore-select').val();
        const restoreDb      = $('#wprb-restore-db').is(':checked');
        const restoreFiles   = $('#wprb-restore-files').is(':checked');
        const createSnapshot = $('#wprb-restore-snapshot').is(':checked');

        if (!restoreDb && !restoreFiles) {
            alert('Bitte wähle mindestens Datenbank oder Dateien.');
            return;
        }

        if (!confirm('Wiederherstellung wirklich starten? Die aktuellen Daten werden überschrieben!')) {
            return;
        }

        restoreRunning = true;
        restoreStartTime = Date.now();

        // Show progress
        $('#wprb-restore-step2').slideUp(200, function () {
            $('#wprb-restore-progress').slideDown(200);
        });

        ajax('wprb_start_restore', {
            backup_id: backupId,
            restore_db: restoreDb ? 1 : 0,
            restore_files: restoreFiles ? 1 : 0,
            create_snapshot: createSnapshot ? 1 : 0,
        })
            .done(function (response) {
                if (response.success) {
                    processRestoreNext();
                } else {
                    restoreError(response.data ? response.data.message : data.strings.error);
                }
            })
            .fail(function () {
                restoreError(data.strings.error);
            });
    }

    function processRestoreNext() {
        if (!restoreRunning) return;

        ajax('wprb_process_restore')
            .done(function (response) {
                if (!response.success) {
                    restoreError(response.data ? response.data.message : data.strings.error);
                    return;
                }

                const state = response.data;
                updateRestoreProgress(state.progress || 0, state.message || '');

                if (state.phase === 'done') {
                    restoreComplete(state);
                } else {
                    restorePollTimer = setTimeout(processRestoreNext, 300);
                }
            })
            .fail(function (xhr) {
                if (xhr.status === 0 || xhr.status >= 500) {
                    restorePollTimer = setTimeout(processRestoreNext, 2000);
                } else {
                    restoreError(data.strings.error);
                }
            });
    }

    function updateRestoreProgress(percent, message) {
        percent = Math.min(100, Math.max(0, percent));
        $('#wprb-restore-fill').css('width', percent + '%');
        $('#wprb-restore-percent').text(Math.round(percent) + '%');
        $('#wprb-restore-message').text(message);

        if (restoreStartTime) {
            const elapsed = Math.floor((Date.now() - restoreStartTime) / 1000);
            $('#wprb-restore-time').text('Laufzeit: ' + formatTime(elapsed));
        }
    }

    function restoreComplete(state) {
        restoreRunning = false;
        restoreStartTime = null;

        $('#wprb-restore-progress').slideUp(200, function () {
            const hasErrors = state.errors && state.errors.length > 0;
            const $notice = $('#wprb-restore-done-notice');

            if (hasErrors) {
                $notice.removeClass('wprb-notice-success').addClass('wprb-notice-error');
                $notice.find('.dashicons').removeClass('dashicons-yes-alt').addClass('dashicons-warning');
                $('#wprb-restore-done-title').text('Wiederherstellung mit Fehlern abgeschlossen');
                $('#wprb-restore-done-details').text(state.errors.length + ' Fehler: ' + state.errors.slice(0, 3).join(', '));
            } else {
                $notice.removeClass('wprb-notice-error').addClass('wprb-notice-success');
                $notice.find('.dashicons').removeClass('dashicons-warning').addClass('dashicons-yes-alt');
                $('#wprb-restore-done-title').text('Wiederherstellung erfolgreich abgeschlossen!');

                let details = '';
                if (state.snapshot_id) {
                    details = 'Sicherheitskopie: ' + state.snapshot_id;
                }
                $('#wprb-restore-done-details').text(details);
            }

            $('#wprb-restore-done').slideDown(200);
        });
    }

    function restoreError(message) {
        restoreRunning = false;
        restoreStartTime = null;

        if (restorePollTimer) {
            clearTimeout(restorePollTimer);
            restorePollTimer = null;
        }

        $('#wprb-restore-progress').slideUp(200, function () {
            const $notice = $('#wprb-restore-done-notice');
            $notice.removeClass('wprb-notice-success').addClass('wprb-notice-error');
            $notice.find('.dashicons').removeClass('dashicons-yes-alt').addClass('dashicons-warning');
            $('#wprb-restore-done-title').text('Fehler bei der Wiederherstellung');
            $('#wprb-restore-done-details').text(message);
            $('#wprb-restore-done').slideDown(200);
        });
    }

    function cancelRestore() {
        if (!confirm('Wiederherstellung wirklich abbrechen? Der aktuelle Zustand könnte inkonsistent sein!')) return;

        if (restorePollTimer) {
            clearTimeout(restorePollTimer);
            restorePollTimer = null;
        }

        ajax('wprb_cancel_restore').always(function () {
            restoreRunning = false;
            restoreStartTime = null;
            $('#wprb-restore-progress').slideUp(200);
            $('#wprb-restore-step1').slideDown(200);
        });
    }

    function resetRestore() {
        currentAnalysis = null;
        $('#wprb-restore-done').hide();
        $('#wprb-restore-step2').hide();
        $('#wprb-restore-progress').hide();
        $('#wprb-restore-select').val('');
        $('#wprb-analyze-btn').prop('disabled', true);
        $('#wprb-restore-step1').slideDown(200);
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

        // Schedules
        function updateScheduleIntervalUI() {
            var interval = $('#wprb-schedule-interval').val();
            $('#wprb-weekly-options').hide();
            $('#wprb-monthly-options').hide();
            
            if (interval === 'weekly') {
                $('#wprb-weekly-options').show();
            } else if (interval === 'monthly') {
                $('#wprb-monthly-options').show();
            }
        }
        
        $(document).on('change', '#wprb-schedule-interval', updateScheduleIntervalUI);

        function resetScheduleForm() {
            $('#wprb-add-schedule-form')[0].reset();
            $('#wprb-schedule-id').val('');
            $('#wprb-add-schedule-form input[name="action"]').val('wprb_add_schedule');
            $('#wprb-save-schedule-btn .dashicons').removeClass('dashicons-saved').addClass('dashicons-plus');
            $('#wprb-save-schedule-btn .btn-text').text('Zeitplan hinzufügen');
            $('#wprb-add-schedule-wrapper h2').text('Neuen Zeitplan erstellen');
            updateScheduleIntervalUI();
        }

        $(document).on('click', '#wprb-show-add-schedule', function (e) {
            e.preventDefault();
            resetScheduleForm();
            $('#wprb-add-schedule-wrapper').slideDown(200);
            $(this).prop('disabled', true);
        });

        $(document).on('click', '.wprb-edit-schedule', function(e) {
            e.preventDefault();
            var row = $(this).closest('tr');
            var id = $(this).data('id');
            var interval = row.data('interval');
            var time = row.data('time');
            var dayOfWeek = row.data('day-of-week');
            var dayOfMonth = row.data('day-of-month');
            var type = row.data('type');
            var destinations = row.data('destinations'); // Already parsed by jQuery if properly encoded, else need JSON.parse

            // Fill form
            $('#wprb-schedule-id').val(id);
            $('#wprb-add-schedule-form select[name="interval"]').val(interval);
            $('#wprb-add-schedule-form input[name="time"]').val(time);
            $('#wprb-add-schedule-form select[name="day_of_week"]').val(dayOfWeek);
            $('#wprb-add-schedule-form select[name="day_of_month"]').val(dayOfMonth);
            $('#wprb-add-schedule-form select[name="type"]').val(type);
            
            // Destinations
            $('#wprb-add-schedule-form input[name="destinations[]"]').prop('checked', false);
            if (destinations) {
                // If jQuery auto-parsed JSON, fine. If string, parse it.
                if (typeof destinations === 'string') {
                     try { destinations = JSON.parse(destinations); } catch(e){}
                }
                $(destinations).each(function(i, val) {
                    $('#wprb-add-schedule-form input[name="destinations[]"][value="' + val + '"]').prop('checked', true);
                });
            }

            // Update UI for Edit Mode
            $('#wprb-add-schedule-form input[name="action"]').val('wprb_update_schedule');
            $('#wprb-save-schedule-btn .dashicons').removeClass('dashicons-plus').addClass('dashicons-saved');
            $('#wprb-save-schedule-btn .btn-text').text('Änderungen speichern');
            $('#wprb-add-schedule-wrapper h2').text('Zeitplan bearbeiten');
            
            updateScheduleIntervalUI();

            // Show Form
            $('#wprb-add-schedule-wrapper').slideDown(200);
            $('#wprb-show-add-schedule').prop('disabled', true);
            
            // Scroll to form
            $('html, body').animate({
                scrollTop: $("#wprb-add-schedule-wrapper").offset().top - 50
            }, 500);
        });

        $(document).on('click', '#wprb-cancel-add-schedule', function (e) {
            e.preventDefault();
            $('#wprb-add-schedule-wrapper').slideUp(200);
            $('#wprb-show-add-schedule').prop('disabled', false);
            setTimeout(resetScheduleForm, 200);
        });

        $(document).on('submit', '#wprb-add-schedule-form', function (e) {
            e.preventDefault();
            saveSchedule(this);
        });

        $(document).on('click', '.wprb-delete-schedule', function (e) {
            e.preventDefault();
            deleteSchedule($(this).data('id'));
        });

        // Save settings
        $(document).on('submit', '#wprb-settings-form, #wprb-storage-settings-form', function (e) {
            e.preventDefault();
            saveSettings(this);
        });

        // Storage Checkbox Toggle
        $(document).on('change', '.wprb-storage-checkbox', function() {
            const target = $(this).data('target');
            if (target) {
                if ($(this).is(':checked')) {
                    $(target).slideDown(200);
                } else {
                    $(target).slideUp(200);
                }
            }
        });

        // Restore: select change
        $(document).on('change', '#wprb-restore-select', function () {
            $('#wprb-analyze-btn').prop('disabled', !$(this).val());
        });

        // Restore: analyze
        $(document).on('click', '#wprb-analyze-btn', function (e) {
            e.preventDefault();
            const id = $('#wprb-restore-select').val();
            if (id) analyzeBackup(id);
        });

        // Restore: start
        $(document).on('click', '#wprb-start-restore-btn', function (e) {
            e.preventDefault();
            startRestore();
        });

        // Restore: cancel
        $(document).on('click', '#wprb-cancel-restore-btn', function (e) {
            e.preventDefault();
            cancelRestore();
        });

        // Restore: back
        $(document).on('click', '#wprb-restore-back-btn', function (e) {
            e.preventDefault();
            $('#wprb-restore-step2').slideUp(200, function () {
                $('#wprb-restore-step1').slideDown(200);
            });
        });

        // Restore: reset
        $(document).on('click', '#wprb-restore-reset-btn', function (e) {
            e.preventDefault();
            resetRestore();
        });

        // Check if a backup is already running on page load
        ajax('wprb_get_status').done(function (response) {
            if (response.success && response.data.running) {
                isRunning = true;
                startTime = Date.now() - (response.data.elapsed * 1000);

                $('.wprb-start-backup').prop('disabled', true);
                $('#wprb-progress-section').show();
                updateProgress(response.data);
                processNext();
            }
        });

        // Disconnect storage
        $(document).on('click', '.wprb-disconnect-storage', function (e) {
            e.preventDefault();
            const service = $(this).data('service');
            
            if (!confirm('Verbindung zu ' + service + ' wirklich trennen?')) {
                return;
            }

            ajax('wprb_disconnect_storage', { service: service })
                .done(function (response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert(response.data ? response.data.message : data.strings.error);
                    }
                })
                .fail(function () {
                    alert(data.strings.error);
                });
        });
    });

})(jQuery);
