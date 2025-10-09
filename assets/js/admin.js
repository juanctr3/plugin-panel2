jQuery(function($) {
    'use strict';

    /**
     * Lógica para mostrar/ocultar campos de configuración de API dinámicamente.
     * Esta función se asegura de que el usuario solo vea las opciones relevantes
     * para el panel de API y el tipo de mensaje que ha seleccionado.
     */
    function toggleApiFields() {
        var selectedPanel = $('#wse_pro_api_panel_selection').val();

        // Primero, oculta todas las filas de tabla que contengan un campo de panel específico.
        $('tr:has([data-panel])').hide();
        
        // Muestra solo las filas de tabla para el panel seleccionado.
        var panelRows = $('tr:has([data-panel="' + selectedPanel + '"])');
        panelRows.show();

        // Si el Panel 1 está seleccionado, se aplica una lógica adicional para el tipo de mensaje.
        if (selectedPanel === 'panel1') {
            var selectedMessageType = $('#wse_pro_message_type_panel1').val();
            
            // Oculta todos los campos específicos de tipo de mensaje (data-msg-type) dentro del panel 1.
            panelRows.filter(':has([data-msg-type])').hide();
            
            // Muestra solo los campos que corresponden al tipo de mensaje seleccionado (whatsapp o sms).
            panelRows.filter(':has([data-msg-type="' + selectedMessageType + '"])').show();
        }
    }

    // Ejecuta la función al cargar la página para establecer el estado inicial correcto.
    toggleApiFields();

    // Vuelve a ejecutar la función cada vez que el usuario cambia el selector de API o el tipo de mensaje del Panel 1.
    $('#wse_pro_api_panel_selection, #wse_pro_message_type_panel1').on('change', function() {
        toggleApiFields();
    });

    /**
     * Lógica del Acordeón para variables y emojis.
     * Permite expandir y contraer la sección de ayuda.
     */
    $(document.body).on('click', '.wc-wa-accordion-trigger', function() {
        var content = $(this).next('.wc-wa-accordion-content');
        content.slideToggle(200); // Animación suave
        $(this).toggleClass('active');
        // Cambia el icono de la flecha
        $(this).find('.dashicons').toggleClass('dashicons-arrow-down-alt2 dashicons-arrow-up-alt2');
    });

    /**
     * Lógica para insertar variables y emojis en los textareas.
     * Inserta el valor del botón presionado en la posición actual del cursor.
     */
    $(document.body).on('click', '.wc-wa-accordion-content button', function(e) {
        e.preventDefault(); // Evita que el botón envíe el formulario
        var targetId = $(this).closest('.wc-wa-accordion-content').data('target-id');
        var textarea = $('#' + targetId);
        var textToInsert = $(this).data('value');
        
        if (!textarea.length) {
            console.error('Textarea target not found: ' + targetId);
            return;
        }

        var cursorPos = textarea.prop('selectionStart');
        var currentVal = textarea.val();
        var textBefore = currentVal.substring(0, cursorPos);
        var textAfter = currentVal.substring(cursorPos, currentVal.length);

        // Actualiza el valor del textarea y ajusta la posición del cursor
        textarea.val(textBefore + textToInsert + textAfter);
        textarea.focus();
        textarea.prop('selectionStart', cursorPos + textToInsert.length);
        textarea.prop('selectionEnd', cursorPos + textToInsert.length);
    });

    /**
     * Lógica del Botón de Prueba de Envío (AJAX).
     * Envía los datos al backend de WordPress sin recargar la página.
     */
    $('#wse_pro_send_test_button').on('click', function(e) {
        e.preventDefault();
        var button = $(this);
        var statusSpan = $('#test_send_status');
        var testNumber = $('#wse_pro_test_number').val();

        if (!testNumber) {
            alert('Por favor, ingresa un número de teléfono para la prueba.');
            return;
        }

        // Deshabilita el botón y muestra un mensaje de "Enviando"
        button.prop('disabled', true);
        statusSpan.html('<span class="spinner is-active" style="float:left; margin-top:2px;"></span>Enviando...').css('color', 'blue');

        $.ajax({
            url: wse_pro_admin_params.ajax_url,
            type: 'POST',
            data: {
                action: 'wse_pro_send_test',
                security: wse_pro_admin_params.nonce, // Nonce de seguridad
                test_number: testNumber
            },
            success: function(response) {
                // Maneja la respuesta del servidor
                if (response.success && response.data.success) {
                    statusSpan.text('✓ ' + response.data.message).css('color', 'green');
                } else {
                    var errorMessage = response.data.message ? response.data.message : 'Error desconocido.';
                    statusSpan.text('✗ ' + errorMessage).css('color', 'red');
                }
            },
            error: function() {
                // Maneja errores de conexión
                statusSpan.text('✗ Error de conexión con el servidor.').css('color', 'red');
            },
            complete: function() {
                // Se ejecuta siempre, al finalizar la llamada
                button.prop('disabled', false); // Rehabilita el botón
                statusSpan.find('.spinner').remove(); // Quita el spinner
            }
        });
    });
});
