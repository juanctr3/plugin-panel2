jQuery(document).ready(function($) {
    let captureTimeout = null;
    let lastCapturedData = null;

    // Función para obtener el valor de un campo usando múltiples selectores (más robusto)
    function getFieldValue(selectors) {
        for (let i = 0; i < selectors.length; i++) {
            const value = $(selectors[i]).val();
            if (value) {
                return value;
            }
        }
        return '';
    }

    // Función principal para capturar los datos del carrito
    function captureCart() {
        console.log('WooWApp: Intentando capturar datos del carrito...');

        // Recopila todos los campos de facturación usando selectores más flexibles
        const billingData = {
            action: 'wse_pro_capture_cart',
            nonce: wseProCapture.nonce,
            billing_email: getFieldValue(['#billing_email', 'input[name="billing_email"]']),
            billing_phone: getFieldValue(['#billing_phone', 'input[name="billing_phone"]', 'input[type="tel"]']),
            billing_first_name: getFieldValue(['#billing_first_name', 'input[name="billing_first_name"]']),
            billing_last_name: getFieldValue(['#billing_last_name', 'input[name="billing_last_name"]']),
            billing_address_1: getFieldValue(['#billing_address_1', 'input[name="billing_address_1"]']),
            billing_city: getFieldValue(['#billing_city', 'input[name="billing_city"]']),
            billing_state: getFieldValue(['#billing_state', 'select[name="billing_state"]']),
            billing_postcode: getFieldValue(['#billing_postcode', 'input[name="billing_postcode"]']),
            billing_country: getFieldValue(['#billing_country', 'select[name="billing_country"]'])
        };

        // Verifica si se ha capturado al menos un email o un teléfono. Si no, no hace nada.
        if (!billingData.billing_email && !billingData.billing_phone) {
            console.log('WooWApp: No se encontró email ni teléfono. Captura cancelada.');
            return;
        }

        const dataHash = JSON.stringify(billingData);
        
        // Solo envía la solicitud si los datos han cambiado desde la última vez.
        if (dataHash === lastCapturedData) {
            console.log('WooWApp: Los datos no han cambiado. No se enviará la solicitud.');
            return;
        }
        
        console.log('WooWApp: Datos de facturación recopilados. Enviando al servidor...', billingData);

        // Envía los datos al servidor vía AJAX
        $.ajax({
            url: wseProCapture.ajax_url,
            type: 'POST',
            data: billingData,
            success: function(response) {
                if (response.success && response.data.captured) {
                    lastCapturedData = dataHash;
                    console.log('WooWApp: ¡Éxito! Carrito capturado y guardado en el servidor.', response.data);
                } else {
                    console.warn('WooWApp: El servidor respondió, pero no se pudo capturar el carrito.', response);
                }
            },
            error: function(xhr, status, error) {
                console.error('WooWApp: Error de AJAX al intentar capturar el carrito.', error);
            }
        });
    }

    // Función que programa la captura para que no se ejecute en cada tecla pulsada (debounce)
    function scheduleCapture() {
        clearTimeout(captureTimeout);
        captureTimeout = setTimeout(captureCart, 1500); // Espera 1.5 segundos después del último cambio
    }

    // Lista de selectores de campos que activarán la captura
    const triggerFields = [
        '#billing_email', 'input[name="billing_email"]',
        '#billing_phone', 'input[name="billing_phone"]', 'input[type="tel"]',
        '#billing_first_name', 'input[name="billing_first_name"]'
    ];

    // Asigna el evento 'input' y 'change' a los campos de la lista
    $(document).on('input change', triggerFields.join(','), scheduleCapture);

    // También se activa cuando WooCommerce actualiza el checkout (ej. al cambiar método de envío)
    $(document.body).on('updated_checkout', function() {
        console.log('WooWApp: Evento "updated_checkout" detectado.');
        scheduleCapture();
    });

    // Captura inicial después de 3 segundos por si el navegador autocompleta los campos
    setTimeout(function() {
        console.log('WooWApp: Realizando captura inicial por autocompletado.');
        captureCart();
    }, 3000);

    console.log('WooWApp: Script de captura de carritos v2.2.2 (mejorado) cargado y listo.');
});
