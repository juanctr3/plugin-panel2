/**
 * WooWApp - Captura de Carrito v3.0 (Definitiva)
 * Este script captura los datos del formulario de pago en tiempo real de forma eficiente y robusta.
 *
 * Mejoras clave:
 * - Selectores universales (ID y name) para máxima compatibilidad con temas.
 * - Lógica de "debounce" optimizada para reducir la carga del servidor.
 * - Solo envía datos si realmente han cambiado.
 * - Logging detallado en la consola para una depuración sencilla.
 */
jQuery(function($) {
    'use strict';

    let captureTimeout = null;
    let lastSentDataHash = null; // Almacena un hash de los últimos datos enviados.
    const DEBOUNCE_DELAY = 1800; // Aumentamos ligeramente el tiempo de espera a 1.8 segundos.

    /**
     * Obtiene el valor de un campo utilizando una lista de selectores posibles.
     * Devuelve el primer valor que no esté vacío.
     * @param {string[]} selectors - Un array de selectores jQuery.
     * @returns {string} El valor encontrado o una cadena vacía.
     */
    const getFieldValue = (selectors) => {
        for (const selector of selectors) {
            const value = $(selector).val();
            if (value && value.trim() !== '') {
                return value.trim();
            }
        }
        return '';
    };

    /**
     * Función principal que recopila y envía los datos del formulario.
     */
    const captureCartData = () => {
        // 1. Recopilar datos con selectores robustos
        const billingData = {
            billing_email:      getFieldValue(['#billing_email', 'input[name="billing_email"]']),
            billing_phone:      getFieldValue(['#billing_phone', 'input[name="billing_phone"]', 'form.checkout input[type="tel"]']),
            billing_first_name: getFieldValue(['#billing_first_name', 'input[name="billing_first_name"]']),
            billing_last_name:  getFieldValue(['#billing_last_name', 'input[name="billing_last_name"]']),
            billing_country:    getFieldValue(['#billing_country', 'select[name="billing_country"]']),
            billing_state:      getFieldValue(['#billing_state', 'select[name="billing_state"]', 'input[name="billing_state"]']),
            billing_city:       getFieldValue(['#billing_city', 'input[name="billing_city"]']),
            billing_address_1:  getFieldValue(['#billing_address_1', 'input[name="billing_address_1"]']),
            billing_postcode:   getFieldValue(['#billing_postcode', 'input[name="billing_postcode"]']),
        };

        // 2. Condición de salida: no hacer nada si no hay teléfono o email.
        if (!billingData.billing_phone && !billingData.billing_email) {
            console.log('WooWApp: Captura detenida. Email y teléfono están vacíos.');
            return;
        }
        
        // 3. Crear un "hash" (una firma única) de los datos actuales para compararlos.
        const currentDataHash = JSON.stringify(billingData);

        // 4. Condición de salida: no enviar si los datos son idénticos a los últimos enviados.
        if (currentDataHash === lastSentDataHash) {
            console.log('WooWApp: Los datos no han cambiado. No se requiere envío.');
            return;
        }

        console.log('WooWApp: Datos nuevos detectados. Preparando envío...', billingData);

        // 5. Enviar los datos al servidor.
        $.ajax({
            type: 'POST',
            url: wseProCapture.ajax_url,
            data: {
                action: 'wse_pro_capture_cart',
                nonce: wseProCapture.nonce,
                ...billingData // Añade todos los campos recopilados
            },
            success: (response) => {
                if (response.success && response.data.captured) {
                    // Si el envío fue exitoso, actualizamos el hash de los últimos datos enviados.
                    lastSentDataHash = currentDataHash;
                    console.log('%cWooWApp: ¡Éxito! Carrito capturado en el servidor.', 'color: #28a745; font-weight: bold;', 'ID:', response.data.cart_id);
                } else {
                    console.warn('WooWApp: El servidor no pudo capturar el carrito.', response.data?.message || 'Respuesta no exitosa.');
                }
            },
            error: (xhr, status, error) => {
                console.error('WooWApp: Fallo crítico en la solicitud AJAX.', { status, error });
            }
        });
    };
    
    /**
     * Programa la ejecución de `captureCartData` después de un breve retraso (debounce).
     * Esto evita enviar una solicitud al servidor cada vez que el usuario presiona una tecla.
     */
    const scheduleCapture = (event) => {
        // Si el evento viene de un cambio de país o estado, ejecuta la captura de inmediato.
        if ($(event.target).is('#billing_country, #billing_state')) {
            console.log(`WooWApp: Cambio detectado en ${event.target.id}. Ejecutando captura inmediata.`);
            clearTimeout(captureTimeout); // Cancela cualquier temporizador pendiente
            captureCartData();
        } else {
            clearTimeout(captureTimeout); // Reinicia el temporizador
            captureTimeout = setTimeout(captureCartData, DEBOUNCE_DELAY);
        }
    };
    
    // Lista de selectores que activarán la captura.
    // Usamos 'input' para texto y 'change' para desplegables.
    const triggerSelectors = [
        'input[name="billing_email"]',
        'input[name="billing_phone"]',
        'input[name="billing_first_name"]',
        'input[name="billing_last_name"]',
        'select[name="billing_country"]',
        'select[name="billing_state"]',
        'input[name="billing_state"]',
        'input[name="billing_city"]',
        'input[name="billing_address_1"]',
        'input[name="billing_postcode"]',
        'form.checkout input[type="tel"]'
    ].join(', ');

    // Asignar los listeners a los eventos.
    $(document).on('input change', triggerSelectors, scheduleCapture);

    // WooCommerce dispara 'updated_checkout' al cambiar métodos de envío, etc.
    $(document.body).on('updated_checkout', () => {
        console.log('WooWApp: Evento "updated_checkout" de WooCommerce detectado. Programando captura.');
        scheduleCapture({ target: {} }); // Llama con un objeto de evento vacío
    });

    // Ejecuta una captura inicial por si el navegador autocompleta los campos al cargar la página.
    setTimeout(captureCartData, 3000);

    console.log('%cWooWApp: Script de captura de carritos (v3.0 Definitiva) cargado.', 'color: #6366f1; font-weight: bold;');
});
