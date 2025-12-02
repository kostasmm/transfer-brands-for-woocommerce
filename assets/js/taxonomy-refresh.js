/**
 * Taxonomy Refresh JavaScript
 * 
 * Handles refreshing the destination taxonomy
 */
jQuery(document).ready(function ($) {
    $('#tbfw-tb-refresh-taxonomy').on('click', function () {
        var $button = $(this);
        var $status = $('#tbfw-tb-refresh-taxonomy-status');

        $button.prop('disabled', true).text(tbfwRefresh.refreshing);
        $status.hide();

        $.post(tbfwRefresh.ajaxUrl, {
            action: 'tbfw_refresh_destination_taxonomy',
            nonce: tbfwRefresh.nonce
        }, function (response) {
            $button.prop('disabled', false).text(tbfwRefresh.refreshText);

            if (response.success) {
                $status.text(tbfwRefresh.updated + ' ' + response.data.taxonomy)
                    .css('color', 'green')
                    .show();

                // Update the displayed taxonomy name
                $('.tbfw-tb-permalink-info strong').text(response.data.taxonomy);
            } else {
                $status.text(tbfwRefresh.error + ' ' + response.data.message)
                    .css('color', 'red')
                    .show();
            }
        }).fail(function () {
            $button.prop('disabled', false).text(tbfwRefresh.refreshText);
            $status.text(tbfwRefresh.networkError)
                .css('color', 'red')
                .show();
        });
    });
});