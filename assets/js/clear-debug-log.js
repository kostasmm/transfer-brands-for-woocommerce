/**
 * Clear Debug Log functionality
 *
 * @package Transfer_Brands_For_WooCommerce
 * @since 2.3.0
 */

(function($) {
    'use strict';

    $(document).ready(function() {
        $('#clear-debug-log').on('click', function(e) {
            e.preventDefault();

            var $button = $(this);

            // Confirm before clearing
            if (!confirm(tbfwDebug.confirmClear)) {
                return;
            }

            // Disable button and show loading state
            var originalText = $button.text();
            $button.prop('disabled', true).text('Clearing...');

            $.ajax({
                url: tbfwDebug.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'tbfw_view_debug_log',
                    nonce: tbfwDebug.nonce,
                    clear: true
                },
                success: function(response) {
                    if (response.success) {
                        // Reload page to show empty log
                        location.reload();
                    } else {
                        alert('Error: ' + (response.data.message || 'Unknown error'));
                        $button.prop('disabled', false).text(originalText);
                    }
                },
                error: function() {
                    alert('AJAX request failed. Please try again.');
                    $button.prop('disabled', false).text(originalText);
                }
            });
        });
    });

})(jQuery);
