jQuery(document).ready(function($) {
    let captureTimeout = null;
    let lastCapturedData = null;

    // Función para capturar el carrito con todos los datos
    function captureCart() {
        // Recopilar todos los campos de billing del formulario
        const billingData = {
            action: 'wse_pro_capture_cart',
            nonce: wseProCapture.nonce,
            billing_email: $('#billing_email').val() || '',
            billing_phone: $('#billing_phone').val() || '',
            billing_first_name: $('#billing_first_name').val() || '',
            billing_last_name: $('#billing_last_name').val() || '',
            billing_address_1: $('#billing_address_1').val() || '',
            billing_city: $('#billing_city').val() || '',
            billing_state: $('#billing_state').val() || '',
            billing_postcode: $('#billing_postcode').val() || '',
            billing_country: $('#billing_country').val() || ''
        };

        // Verificar si hay al menos email o teléfono
        if (!billingData.billing_email && !billingData.billing_phone) {
            return;
        }

        // Crear un hash de los datos para comparar
        const dataHash = JSON.stringify(billingData);
        
        // Solo enviar si los datos han cambiado
        if (dataHash === lastCapturedData) {
            return;
        }

        // Enviar datos vía AJAX
        $.ajax({
            url: wseProCapture.ajax_url,
            type: 'POST',
            data: billingData,
            success: function(response) {
                if (response.success && response.data.captured) {
                    lastCapturedData = dataHash;
                    console.log('Carrito capturado exitosamente');
                }
            },
            error: function(xhr, status, error) {
                console.error('Error al capturar carrito:', error);
            }
        });
    }

    // Función para programar captura con debounce
    function scheduleCapture() {
        clearTimeout(captureTimeout);
        captureTimeout = setTimeout(captureCart, 2000); // Esperar 2 segundos después del último cambio
    }

    // Escuchar cambios en campos de billing
    const billingFields = [
        '#billing_email',
        '#billing_phone',
        '#billing_first_name',
        '#billing_last_name',
        '#billing_address_1',
        '#billing_city',
        '#billing_state',
        '#billing_postcode',
        '#billing_country'
    ];

    // Agregar listener a cada campo
    billingFields.forEach(function(selector) {
        $(document).on('change blur', selector, scheduleCapture);
    });

    // También capturar cuando se actualiza el checkout
    $(document.body).on('updated_checkout', function() {
        scheduleCapture();
    });

    // Captura inicial después de 3 segundos si hay datos
    setTimeout(function() {
        if ($('#billing_email').val() || $('#billing_phone').val()) {
            captureCart();
        }
    }, 3000);
});
