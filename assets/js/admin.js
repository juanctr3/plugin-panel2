jQuery(function($) {
    'use strict';

    // --- NUEVO: Lógica para mostrar/ocultar campos de API ---
    function toggleApiSettings() {
        var selectedApi = $('input[name="wse_pro_api_provider"]:checked').val();
        
        if (selectedApi === 'panel_1') {
            $('.panel-1-settings').closest('tr').show();
            $('.panel-2-settings').closest('tr').hide();
            
            // Lógica interna para Panel 1 (WhatsApp vs SMS)
            var selectedMethod = $('input[name="wse_pro_panel1_send_method"]:checked').val();
            if (selectedMethod === 'sms') {
                $('.panel-1-sms-settings').closest('tr').show();
                $('.panel-1-whatsapp-settings').closest('tr').hide();
            } else {
                $('.panel-1-whatsapp-settings').closest('tr').show();
                $('.panel-1-sms-settings').closest('tr').hide();
            }

        } else { // panel_2 o por defecto
            $('.panel-2-settings').closest('tr').show();
            $('.panel-1-settings').closest('tr').hide();
        }
    }

    // Ejecutar al cargar la página y cada vez que se cambia la selección
    toggleApiSettings();
    $('input[name="wse_pro_api_provider"], input[name="wse_pro_panel1_send_method"]').on('change', function() {
        toggleApiSettings();
    });
    // --- FIN DE NUEVA LÓGICA ---

    // Lógica del Acordeón para variables y emojis (sin cambios)
    $(document.body).on('click', '.wc-wa-accordion-trigger', function() { /* ... */ });
    $(document.body).on('click', '.wc-wa-accordion-content button', function(e) { /* ... */ });

    // Lógica del Botón de Prueba (MODIFICADO para enviar mensaje personalizado)
    $('#wse_pro_send_test_button').on('click', function(e) {
        e.preventDefault();
        var button = $(this);
        var statusSpan = $('#test_send_status');
        var testNumber = $('#wse_pro_test_number').val();
        var testMessage = $('#wse_pro_test_message').val(); // Capturar el mensaje

        if (!testNumber) {
            alert('Por favor, ingresa un número de teléfono para la prueba.');
            return;
        }
        if (!testMessage) {
            alert('Por favor, escribe un mensaje de prueba.');
            return;
        }

        button.prop('disabled', true);
        statusSpan.html('<span class="spinner is-active"></span>Enviando...').css('color', 'blue');

        $.ajax({
            url: wse_pro_admin_params.ajax_url,
            type: 'POST',
            data: {
                action: 'wse_pro_send_test',
                security: wse_pro_admin_params.nonce,
                test_number: testNumber,
                test_message: testMessage // Enviar el mensaje
            },
            success: function(response) {
                if (response.success && response.data.success) {
                    statusSpan.text('✓ ' + response.data.message).css('color', 'green');
                } else {
                    var errorMessage = response.data ? response.data.message : 'Error desconocido.';
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