jQuery(document).ready(function ($) {
    $('.polylang-sync-btn').on('click', function (e) {
        e.preventDefault();

        var button = $(this);
        var langName = button.data('lang-name');
        var sourceId = button.data('source-id');
        var targetId = button.data('target-id');
        var nonce = $('#polylang_sync_nonce').val();
        var messageDiv = $('#polylang-sync-message');

        if (!confirm(polylangDuplicateContent.messages.confirm.replace('%s', langName))) {
            return;
        }

        button.prop('disabled', true).text(polylangDuplicateContent.messages.copying);
        messageDiv.html('<p style=\"color: #0073aa;\">' + polylangDuplicateContent.messages.copyingContent + '</p>');

        $.ajax({
            url: polylangDuplicateContent.ajaxurl,
            type: 'POST',
            data: {
                action: 'polylang_sync_content',
                source_id: sourceId,
                target_id: targetId,
                nonce: nonce
            },
            success: function (response) {
                try {
                    var data = typeof response === 'string' ? JSON.parse(response) : response;
                    if (data.success) {
                        messageDiv.html('<p style=\"color: #46b450;\">' + data.message + '</p>');
                        setTimeout(function () {
                            location.reload();
                        }, 2000);
                    } else {
                        messageDiv.html('<p style=\"color: #dc3232;\">' + polylangDuplicateContent.messages.error + ' ' + data.message + '</p>');
                    }
                } catch (e) {
                    messageDiv.html('<p style=\"color: #dc3232;\">' + polylangDuplicateContent.messages.serverError + '</p>');
                }
            },
            error: function (xhr, status, error) {
                console.error('AJAX Error:', status, error);
                messageDiv.html('<p style=\"color: #dc3232;\">' + polylangDuplicateContent.messages.serverError + '</p>');
            },
            complete: function () {
                button.prop('disabled', false).text(langName);
            }
        });
    });
});