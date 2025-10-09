jQuery(function($) {
    'use strict';

    // Lógica del Acordeón para variables y emojis
    $(document.body).on('click', '.wc-wa-accordion-trigger', function() {
        var content = $(this).next('.wc-wa-accordion-content');
        content.slideToggle(200);
        $(this).toggleClass('active');
        $(this).find('.dashicons').toggleClass('dashicons-arrow-down-alt2 dashicons-arrow-up-alt2');
    });

    // Lógica para insertar variables y emojis en los textareas
    $(document.body).on('click', '.wc-wa-accordion-content button', function(e) {
        e.preventDefault();
        var targetId = $(this).closest('.wc-wa-accordion-content').data('target-id');
        var textarea = $('#' + targetId);
        var textToInsert = $(this).data('value');
        
        if (!textarea.length) {
            return;
        }

        var cursorPos = textarea.prop('selectionStart');
        var v = textarea.val();
        var textBefore = v.substring(0, cursorPos);
        var textAfter = v.substring(cursorPos, v.length);

        textarea.val(textBefore + textToInsert + textAfter);
        textarea.focus();
        textarea.prop('selectionStart', cursorPos + textToInsert.length);
        textarea.prop('selectionEnd', cursorPos + textToInsert.length);
    });

    // Lógica del Botón de Prueba (actualizado con nuevos IDs y nonces)
    $('#wse_pro_send_test_button').on('click', function(e) {
        e.preventDefault();
        var button = $(this);
        var statusSpan = $('#test_send_status');
        var testNumber = $('#wse_pro_test_number').val();

        if (!testNumber) {
            alert('Por favor, ingresa un número de teléfono para la prueba.');
            return;
        }

        button.prop('disabled', true);
        statusSpan.html('<span class="spinner is-active" style="float:left; margin-top:2px;"></span>Enviando...').css('color', 'blue');

        $.ajax({
            url: wse_pro_admin_params.ajax_url,
            type: 'POST',
            data: {
                action: 'wse_pro_send_test',
                security: wse_pro_admin_params.nonce,
                test_number: testNumber
            },
            success: function(response) {
                if (response.success && response.data.success) {
                    statusSpan.text('✓ ' + response.data.message).css('color', 'green');
                } else {
                    var errorMessage = response.data.message ? response.data.message : 'Error desconocido.';
                    statusSpan.text('✗ ' + errorMessage).css('color', 'red');
                }
            },
            error: function() {
                statusSpan.text('✗ Error de conexión con el servidor.').css('color', 'red');
            },
            complete: function() {
                button.prop('disabled', false);
                statusSpan.find('.spinner').remove();
            }
        });
    });
});