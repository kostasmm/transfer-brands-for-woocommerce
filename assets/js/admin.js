/**
 * Admin JavaScript for WooCommerce Transfer Brands Enhanced
 * 
 * Handles all admin UI interactions
 *
 * @package WC_Transfer_Brands
 * @since 2.3.0
 */

jQuery(document).ready(function ($) {
    var ajaxUrl = tbfwTbe.ajaxUrl;
    var nonce = tbfwTbe.nonce;
    var i18n = tbfwTbe.i18n;
    var log = [];

    // Setup tooltips
    $('[data-tooltip]').tooltip({
        content: function () {
            return $(this).attr('data-tooltip');
        },
        position: {
            my: "center bottom-20",
            at: "center top",
            using: function (position, feedback) {
                $(this).css(position);
                $("<div>")
                    .addClass("arrow")
                    .addClass(feedback.vertical)
                    .addClass(feedback.horizontal)
                    .appendTo(this);
            }
        }
    });

    // Modal functions
    function openModal(modalId) {
        $('#' + modalId).fadeIn(300);
    }

    function closeModal(modalId) {
        $('#' + modalId).fadeOut(300);
    }

    // Close modal when clicking the X
    $('.tbfw-tb-modal-close').on('click', function () {
        $(this).closest('.tbfw-tb-modal').fadeOut(300);
    });

    // Close modal when clicking outside the modal content
    $('.tbfw-tb-modal').on('click', function (e) {
        if ($(e.target).hasClass('tbfw-tb-modal')) {
            $(this).fadeOut(300);
        }
    });

    /**
     * Confirmation dialog
     *
     * @param {string} message - The confirmation message
     * @param {Function} callback - Callback to execute if confirmed
     */
    function confirmAction(message, callback) {
        if (confirm(message)) {
            callback();
        }
    }

    /**
     * Add entry to the log
     *
     * @param {string} message - Log message to add
     */
    function addToLog(message) {
        var now = new Date();
        var timestamp = now.getHours() + ':' +
            (now.getMinutes() < 10 ? '0' : '') + now.getMinutes() + ':' +
            (now.getSeconds() < 10 ? '0' : '') + now.getSeconds();

        log.push('[' + timestamp + '] ' + message);

        // Update log display
        $('#tbfw-tb-log').html('<code>' + log.join('<br>') + '</code>');

        // Scroll to bottom
        var logDiv = document.getElementById('tbfw-tb-log');
        if (logDiv) {
            logDiv.scrollTop = logDiv.scrollHeight;
        }
    }

    /**
     * Run a step for the transfer process
     *
     * @param {string} step - Current step name
     * @param {number} offset - Current offset
     */
    function runStep(step, offset) {
        addToLog('Running step: ' + step + ' (offset: ' + offset + ')');

        $.post(ajaxUrl, {
            action: 'tbfw_transfer_brands',
            nonce: nonce,
            step: step,
            offset: offset
        }, function (response) {
            if (response.success) {
                $('#tbfw-tb-progress-bar').val(response.data.percent);
                $('#tbfw-tb-progress-text').text(i18n.progress + ' ' + response.data.percent + '% - ' + response.data.message);

                if (response.data.log) {
                    addToLog(response.data.log);
                }

                if (response.data.step === 'backup' ||
                    response.data.step === 'terms' ||
                    response.data.step === 'products') {
                    runStep(response.data.step, response.data.offset);
                } else {
                    addToLog('Transfer completed successfully!');
                    $('#tbfw-tb-progress-text').text(i18n.completed + ' ' + response.data.message);
                }
            } else {
                addToLog(i18n.error + ' ' + response.data.message);
                $('#tbfw-tb-progress-text').text(i18n.error + ' ' + response.data.message);
            }
        }).fail(function (xhr, status, error) {
            addToLog(i18n.ajax_error + ' ' + error);
            $('#tbfw-tb-progress-text').text(i18n.ajax_error + ' ' + error);
        });
    }

    /**
     * Run a step for the delete process with improved tracking
     * 
     * @param {number} offset - Current offset (always 0 with new implementation)
     * @since 2.5.0 Improved to better handle progress reporting
     */
    function runDeleteStep(offset) {
        addToLog(i18n.processing + ' (offset: ' + offset + ')');

        $.post(ajaxUrl, {
            action: 'tbfw_delete_old_brands',
            nonce: nonce,
            offset: offset
        }, function (response) {
            if (response.success) {
                $('#tbfw-tb-progress-bar').val(response.data.percent);
                $('#tbfw-tb-progress-text').text(i18n.progress + ' ' + response.data.percent + '% - ' + response.data.message);

                if (response.data.log) {
                    addToLog(response.data.log);
                }

                // Update progress statistics
                if (response.data.total && response.data.processed) {
                    $('#tbfw-tb-progress-stats').html(
                        'Processed: <span style="color:#135e96">' + response.data.processed + '</span> out of ' +
                        '<span style="color:#135e96">' + response.data.total + '</span> products ' +
                        '(<span style="color:#135e96">' + response.data.percent + '%</span> complete)'
                    );
                }

                if (response.data.complete === false) {
                    // Continue with next batch
                    runDeleteStep(0); // Always use 0 as offset since we're excluding by ID now
                } else {
                    addToLog('Delete old brands completed successfully!');
                    $('#tbfw-tb-progress-text').text(i18n.completed + ' ' + response.data.message);
                    // Reload page after 3 seconds
                    setTimeout(function () {
                        location.reload();
                    }, 3000);
                }
            } else {
                addToLog(i18n.error + ' ' + response.data.message);
                $('#tbfw-tb-progress-text').text(i18n.error + ' ' + response.data.message);
            }
        }).fail(function (xhr, status, error) {
            addToLog(i18n.ajax_error + ' ' + error);
            $('#tbfw-tb-progress-text').text(i18n.ajax_error + ' ' + error);
        });
    }

    // Analyze brands
    $('#tbfw-tb-check').on('click', function () {
        $('#tbfw-tb-analysis').show();
        $('#tbfw-tb-analysis-content').html('<p>Analyzing brands... please wait.</p>');

        $.post(ajaxUrl, {
            action: 'tbfw_check_brands',
            nonce: nonce
        }, function (response) {
            if (response.success) {
                $('#tbfw-tb-analysis-content').html(response.data.html);
            } else {
                $('#tbfw-tb-analysis-content').html('<p class="error">' + i18n.error + ' ' + response.data.message + '</p>');
            }
        }).fail(function (xhr, status, error) {
            $('#tbfw-tb-analysis-content').html('<p class="error">' + i18n.ajax_error + ' ' + error + '</p>');
        });
    });

    // Start transfer
    $('#tbfw-tb-start').on('click', function () {
        confirmAction(i18n.confirm_transfer, function () {
            $('#tbfw-tb-progress').show();
            $('#tbfw-tb-progress-title').text('Transfer Progress');
            $('#tbfw-tb-progress-bar').val(0);
            $('#tbfw-tb-log').show().html('');
            $('#tbfw-tb-progress-warning').show();
            log = [];

            // Initialize time tracking
            var startTime = new Date();
            var totalProducts = 0;
            var processedProducts = 0;
            var updateTimer;

            // Timer function
            function updateTimerInfo() {
                var currentTime = new Date();
                var elapsedSeconds = Math.floor((currentTime - startTime) / 1000);
                var minutes = Math.floor(elapsedSeconds / 60);
                var seconds = elapsedSeconds % 60;

                var timeText = i18n.time_elapsed + ' ' + minutes + ' ' + i18n.minutes + ' ' + seconds + ' ' + i18n.seconds;

                // Calculate estimated time remaining if we have processed some products
                if (processedProducts > 0 && totalProducts > 0) {
                    var remainingProducts = totalProducts - processedProducts;
                    var secondsPerProduct = elapsedSeconds / processedProducts;
                    var estimatedRemainingSeconds = Math.floor(remainingProducts * secondsPerProduct);

                    var estMinutes = Math.floor(estimatedRemainingSeconds / 60);
                    var estSeconds = estimatedRemainingSeconds % 60;

                    timeText += ' | ' + i18n.estimated_time + ' ' + estMinutes + ' ' + i18n.minutes + ' ' + estSeconds + ' ' + i18n.seconds;
                }

                $('#tbfw-tb-timer').text(timeText);
            }

            // Start timer
            updateTimer = setInterval(updateTimerInfo, 1000);

            runStep('backup', 0);

            // Modified runStep function for better progress tracking
            function runStep(step, offset) {
                addToLog('Running step: ' + step + ' (offset: ' + offset + ')');

                $.post(ajaxUrl, {
                    action: 'tbfw_transfer_brands',
                    nonce: nonce,
                    step: step,
                    offset: offset
                }, function (response) {
                    if (response.success) {
                        $('#tbfw-tb-progress-bar').val(response.data.percent);
                        $('#tbfw-tb-progress-text').text(i18n.progress + ' ' + response.data.percent + '% - ' + response.data.message);

                        // Update statistics
                        if (response.data.total) {
                            totalProducts = response.data.total;
                        }

                        if (response.data.processed_total) {
                            processedProducts = response.data.processed_total;

                            $('#tbfw-tb-progress-stats').html(
                                'Processed: <span style="color:#135e96">' + processedProducts + '</span> out of ' +
                                '<span style="color:#135e96">' + totalProducts + '</span> products ' +
                                '(<span style="color:#135e96">' + response.data.percent + '%</span> complete)'
                            );
                        }

                        if (response.data.log) {
                            addToLog(response.data.log);
                        }

                        if (response.data.step === 'backup' ||
                            response.data.step === 'terms' ||
                            response.data.step === 'products') {
                            runStep(response.data.step, response.data.offset);
                        } else {
                            // Clear timer when done
                            clearInterval(updateTimer);

                            $('#tbfw-tb-progress-warning').hide();

                            addToLog('Transfer completed successfully!');
                            $('#tbfw-tb-progress-text').text(i18n.completed + ' ' + response.data.message);

                            // Reload page after 3 seconds to update counts
                            addToLog(i18n.autorefresh);
                            setTimeout(function () {
                                location.reload();
                            }, 3000);
                        }
                    } else {
                        // Clear timer on error
                        clearInterval(updateTimer);
                        $('#tbfw-tb-progress-warning').hide();

                        addToLog(i18n.error + ' ' + response.data.message);
                        $('#tbfw-tb-progress-text').text(i18n.error + ' ' + response.data.message);
                    }
                }).fail(function (xhr, status, error) {
                    // Clear timer on error
                    clearInterval(updateTimer);
                    $('#tbfw-tb-progress-warning').hide();

                    addToLog(i18n.ajax_error + ' ' + error);
                    $('#tbfw-tb-progress-text').text(i18n.ajax_error + ' ' + error);
                });
            }
        });
    });

    // Delete old brands - with enhanced verification
    $('#tbfw-tb-delete-old').on('click', function () {
        // Show confirmation modal
        openModal('tbfw-tb-delete-confirm-modal');
        $('#tbfw-tb-delete-confirm-input').val('').focus();
    });

    // Cancel delete button in modal
    $('#tbfw-tb-cancel-delete').on('click', function () {
        closeModal('tbfw-tb-delete-confirm-modal');
    });

    // Confirm delete button in modal
    $('#tbfw-tb-confirm-delete').on('click', function () {
        var confirmText = $('#tbfw-tb-delete-confirm-input').val().trim();

        if (confirmText === 'YES') {
            closeModal('tbfw-tb-delete-confirm-modal');

            // First initialize the deletion process
            $.post(ajaxUrl, {
                action: 'tbfw_init_delete',
                nonce: nonce
            }, function () {
                $('#tbfw-tb-progress').show();
                $('#tbfw-tb-progress-title').text('Delete Old Brands');
                $('#tbfw-tb-progress-bar').val(0);
                $('#tbfw-tb-log').show().html('');
                $('#tbfw-tb-progress-warning').show();
                log = [];

                // Start the delete process
                runDeleteStep(0);
            });
        } else {
            // Show error if the confirmation text is incorrect
            alert(i18n.delete_verification_failed);
        }
    });

    // Rollback transfer
    $('#tbfw-tb-rollback').on('click', function () {
        confirmAction(i18n.confirm_rollback, function () {
            $('#tbfw-tb-progress').show();
            $('#tbfw-tb-progress-title').text('Rollback Progress');
            $('#tbfw-tb-progress-bar').val(0);
            $('#tbfw-tb-log').show().html('');
            log = [];

            // Show initial progress
            $('#tbfw-tb-progress-bar').val(10);
            $('#tbfw-tb-progress-text').text('Starting rollback...');
            addToLog('Starting rollback process...');

            setTimeout(function () {
                $('#tbfw-tb-progress-bar').val(50);
                $('#tbfw-tb-progress-text').text('Restoring previous state...');

                $.post(ajaxUrl, {
                    action: 'tbfw_rollback_transfer',
                    nonce: nonce
                }, function (response) {
                    if (response.success) {
                        $('#tbfw-tb-progress-bar').val(100);
                        $('#tbfw-tb-progress-text').text('Rollback completed successfully!');
                        addToLog('Rollback completed: ' + response.data.message);

                        // Reload page after 2 seconds
                        setTimeout(function () {
                            location.reload();
                        }, 2000);
                    } else {
                        $('#tbfw-tb-progress-text').text(i18n.error + ' ' + response.data.message);
                        addToLog(i18n.error + ' ' + response.data.message);
                    }
                }).fail(function (xhr, status, error) {
                    $('#tbfw-tb-progress-text').text(i18n.ajax_error + ' ' + error);
                    addToLog(i18n.ajax_error + ' ' + error);
                });
            }, 500);
        });
    });

    // Restore deleted brands
    $('#tbfw-tb-rollback-delete').on('click', function () {
        confirmAction(i18n.confirm_restore, function () {
            $('#tbfw-tb-progress').show();
            $('#tbfw-tb-progress-title').text('Restore Deleted Brands');
            $('#tbfw-tb-progress-bar').val(0);
            $('#tbfw-tb-log').show().html('');
            $('#tbfw-tb-progress-warning').show();
            log = [];

            // Initialize time tracking
            var startTime = new Date();

            addToLog('Starting brand restoration...');

            // Show initial progress
            $('#tbfw-tb-progress-bar').val(10);
            $('#tbfw-tb-progress-text').text(i18n.progress + ' 10% - ' + 'Retrieving backup data...');

            setTimeout(function () {
                $('#tbfw-tb-progress-bar').val(30);
                $('#tbfw-tb-progress-text').text(i18n.progress + ' 30% - ' + 'Processing products...');

                $.post(ajaxUrl, {
                    action: 'tbfw_rollback_deleted_brands',
                    nonce: nonce
                }, function (response) {
                    if (response.success) {
                        $('#tbfw-tb-progress-bar').val(100);
                        $('#tbfw-tb-progress-text').text('Restoration completed successfully!');

                        var elapsedTime = Math.floor(((new Date()) - startTime) / 1000);
                        var minutes = Math.floor(elapsedTime / 60);
                        var seconds = elapsedTime % 60;

                        $('#tbfw-tb-timer').text(i18n.time_elapsed + ' ' + minutes + ' ' + i18n.minutes + ' ' + seconds + ' ' + i18n.seconds);
                        $('#tbfw-tb-progress-warning').hide();

                        // Show detailed message with affected products count
                        $('#tbfw-tb-progress-stats').html(
                            'Restored brands to <span style="color:#135e96">' + response.data.restored + '</span> products'
                        );

                        // Add detailed log
                        var detailedLog = 'Deleted brands successfully restored to ' + response.data.restored + ' products.';
                        if (response.data.restored === 0) {
                            detailedLog += ' These products may already have the attribute.';
                        }
                        addToLog(detailedLog);

                        // Hide the restore button since we no longer have backup
                        $('#tbfw-tb-rollback-delete').hide();

                        // Reload page after 3 seconds to update counts
                        addToLog(i18n.autorefresh);
                        setTimeout(function () {
                            location.reload();
                        }, 3000);
                    } else {
                        $('#tbfw-tb-progress-text').text(i18n.error + ' ' + response.data.message);
                        $('#tbfw-tb-progress-warning').hide();
                        addToLog(i18n.error + ' ' + response.data.message);
                    }
                }).fail(function (xhr, status, error) {
                    $('#tbfw-tb-progress-text').text(i18n.ajax_error + ' ' + error);
                    $('#tbfw-tb-progress-warning').hide();
                    addToLog(i18n.ajax_error + ' ' + error);
                });
            }, 500);
        });
    });

    // Clean up backups
    $('#tbfw-tb-cleanup').on('click', function () {
        confirmAction(i18n.confirm_cleanup, function () {
            $('#tbfw-tb-progress').show();
            $('#tbfw-tb-progress-title').text('Clean Up Backups');
            $('#tbfw-tb-progress-bar').val(0);
            $('#tbfw-tb-log').show().html('');
            log = [];

            $.post(ajaxUrl, {
                action: 'tbfw_cleanup_backups',
                nonce: nonce
            }, function (response) {
                if (response.success) {
                    $('#tbfw-tb-progress-bar').val(100);
                    $('#tbfw-tb-progress-text').text('All backups deleted successfully!');
                    addToLog('All backups have been cleaned from the database.');

                    // Hide the buttons
                    $('#tbfw-tb-cleanup, #tbfw-tb-rollback, #tbfw-tb-rollback-delete').hide();

                    // Reload page after 2 seconds
                    setTimeout(function () {
                        location.reload();
                    }, 2000);
                } else {
                    $('#tbfw-tb-progress-text').text(i18n.error + ' ' + response.data.message);
                    addToLog(i18n.error + ' ' + response.data.message);
                }
            }).fail(function (xhr, status, error) {
                addToLog(i18n.ajax_error + ' ' + error);
                $('#tbfw-tb-progress-text').text(i18n.ajax_error + ' ' + error);
            });
        });
    });

    // Refresh Counts button
    $('#tbfw-tb-refresh-counts').on('click', function () {
        var $button = $(this);
        $button.prop('disabled', true).text('Refreshing...');

        $.post(ajaxUrl, {
            action: 'tbfw_refresh_counts',
            nonce: nonce
        }, function (response) {
            if (response.success) {
                // Reload the page to show updated counts
                location.reload();
            } else {
                alert(i18n.error + ' ' + response.data.message);
                $button.prop('disabled', false).text('Refresh Counts');
            }
        }).fail(function () {
            alert('Network error occurred while refreshing counts.');
            $button.prop('disabled', false).text('Refresh Counts');
        });
    });

    // Show count details
    $('#tbfw-tb-show-count-details').on('click', function (e) {
        e.preventDefault();
        $('#tbfw-tb-count-details').toggle();

        var $link = $(this);
        if ($('#tbfw-tb-count-details').is(':visible')) {
            $link.text('[Hide details]');
        } else {
            $link.text('[Show details]');
        }
    });
});