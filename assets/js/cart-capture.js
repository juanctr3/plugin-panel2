jQuery(document).ready(function($) {
    'use strict';
    
    // 🔍 Auto-detectar configuración del servidor
    const SERVER_CONFIG = {
        type: document.documentElement.getAttribute('data-server-type') || 'unknown',
        debug: document.documentElement.getAttribute('data-wse-debug') === 'true',
        ajaxUrl: (window.wseProCapture && window.wseProCapture.ajax_url) || '/wp-admin/admin-ajax.php',
        nonce: (window.wseProCapture && window.wseProCapture.nonce) || '',
    };

    if (SERVER_CONFIG.debug) {
        console.log('%c🚀 WooWApp - Modo Debug Activo', 'color: #f59e0b; font-weight: bold; font-size: 14px');
        console.log('Configuración detectada:', SERVER_CONFIG);
    }

    let captureQueue = [];
    let isProcessing = false;

    // 📊 Selectores mejorados - Se prueban en orden
    const FIELD_SELECTORS = {
        billing_email: [
            '#billing_email',
            'input[name="billing_email"]',
            '.woocommerce-billing-email input',
            'input[type="email"][name*="billing"]',
        ],
        billing_phone: [
            '#billing_phone',
            'input[name="billing_phone"]',
            'input[name="phone"]',
            '.woocommerce-billing-phone input',
        ],
        billing_first_name: [
            '#billing_first_name',
            'input[name="billing_first_name"]',
            '.woocommerce-billing-first_name input',
        ],
        billing_last_name: [
            '#billing_last_name',
            'input[name="billing_last_name"]',
            '.woocommerce-billing-last_name input',
        ],
        billing_address_1: [
            '#billing_address_1',
            'textarea[name="billing_address_1"]',
            'input[name="billing_address_1"]',
        ],
        billing_city: [
            '#billing_city',
            'input[name="billing_city"]',
            '.woocommerce-billing-city input',
        ],
        billing_state: [
            '#billing_state',
            'select[name="billing_state"]',
            'input[name="billing_state"]',
        ],
        billing_postcode: [
            '#billing_postcode',
            'input[name="billing_postcode"]',
        ],
        billing_country: [
            '#billing_country',
            'select[name="billing_country"]',
        ],
    };

    /**
     * 🔍 Encontrar elemento por múltiples selectores
     */
    function findField(fieldName) {
        const selectors = FIELD_SELECTORS[fieldName];
        if (!selectors) return null;

        for (let selector of selectors) {
            const $el = $(selector).first();
            if ($el.length) {
                return $el;
            }
        }

        return null;
    }

    /**
     * 📋 Obtener valor de campo
     */
    function getFieldValue(fieldName) {
        const $field = findField(fieldName);
        if (!$field) return '';

        let value = $field.val() || '';
        value = String(value).trim();

        if (SERVER_CONFIG.debug && value) {
            console.log(`✅ ${fieldName}: "${value}"`);
        }

        return value;
    }

    /**
     * 🌐 Enviar datos al servidor
     */
    function sendCaptureData(data) {
        return new Promise((resolve) => {
            $.ajax({
                url: SERVER_CONFIG.ajaxUrl,
                type: 'POST',
                data: data,
                timeout: 15000,
                cache: false,
                dataType: 'json',

                // ✅ Éxito
                success: function(response) {
                    if (SERVER_CONFIG.debug) {
                        console.log('%c✅ Datos capturados', 'color: #10b981', response);
                    }
                    resolve(true);
                },

                // ❌ Error
                error: function(xhr, status, error) {
                    if (SERVER_CONFIG.debug) {
                        console.warn('%c⚠️  Error al enviar', 'color: #ef4444', {
                            status: status,
                            error: error,
                            response: xhr.responseText
                        });
                    }
                    resolve(false); // No fallar, continuar
                }
            });
        });
    }

    /**
     * 📤 Capturar y enviar datos
     */
    async function captureAndSend() {
        if (isProcessing) return;

        const data = {
            action: 'wse_pro_capture_cart',
            billing_email: getFieldValue('billing_email'),
            billing_phone: getFieldValue('billing_phone'),
            billing_first_name: getFieldValue('billing_first_name'),
            billing_last_name: getFieldValue('billing_last_name'),
            billing_address_1: getFieldValue('billing_address_1'),
            billing_city: getFieldValue('billing_city'),
            billing_state: getFieldValue('billing_state'),
            billing_postcode: getFieldValue('billing_postcode'),
            billing_country: getFieldValue('billing_country'),
        };

        // Validación: Al menos email o teléfono
        if (!data.billing_email && !data.billing_phone) {
            if (SERVER_CONFIG.debug) {
                console.log('⏭️  Sin email o teléfono');
            }
            return;
        }

        if (SERVER_CONFIG.nonce) {
            data.nonce = SERVER_CONFIG.nonce;
        }

        isProcessing = true;

        if (SERVER_CONFIG.debug) {
            console.log('%c📤 Enviando datos...', 'color: #6366f1', data);
        }

        await sendCaptureData(data);

        isProcessing = false;
    }

    /**
     * 🎯 Adjuntar listeners a todos los campos
     */
    function attachListeners() {
        Object.keys(FIELD_SELECTORS).forEach(fieldName => {
            const selectors = FIELD_SELECTORS[fieldName];
            
            selectors.forEach(selector => {
                $(document).off('change blur input', selector);
                $(document).on('change blur input', selector, function() {
                    if (SERVER_CONFIG.debug) {
                        console.log(`👁️  Campo cambió: ${fieldName}`);
                    }
                    
                    // Debounce: Esperar 2 segundos
                    clearTimeout(window.captureDebounceTimer);
                    window.captureDebounceTimer = setTimeout(captureAndSend, 2000);
                });
            });
        });

        // Listener para Select2 (WooCommerce)
        $(document).off('select2:select').on('select2:select', 'select[name*="billing"]', function() {
            if (SERVER_CONFIG.debug) {
                console.log('✓ Select2 cambió');
            }
            clearTimeout(window.captureDebounceTimer);
            window.captureDebounceTimer = setTimeout(captureAndSend, 1500);
        });

        // Listener para actualizaciones de checkout
        $(document.body).off('updated_checkout').on('updated_checkout', function() {
            if (SERVER_CONFIG.debug) {
                console.log('%c🔄 Checkout actualizado', 'color: #6366f1');
            }
            clearTimeout(window.captureDebounceTimer);
            window.captureDebounceTimer = setTimeout(captureAndSend, 2500);
        });

        if (SERVER_CONFIG.debug) {
            console.log('%c✅ Listeners adjuntados', 'color: #10b981');
        }
    }

    /**
     * 🚀 Inicialización
     */
    function init() {
        if (!$('form.checkout').length) {
            if (SERVER_CONFIG.debug) {
                console.log('⏳ Esperando formulario de checkout...');
            }
            return;
        }

        if (SERVER_CONFIG.debug) {
            console.log('%c✅ Formulario encontrado - Inicializando', 'color: #10b981');
        }

        attachListeners();

        // Primera captura después de 3 segundos
        setTimeout(captureAndSend, 3000);

        // Captura periódica cada 30 segundos
        setInterval(captureAndSend, 30000);
    }

    // Iniciar cuando esté listo
    if (document.readyState === 'loading') {
        $(document).on('ready', init);
    } else {
        init();
    }

    // También iniciar cuando checkout se actualice
    $(document.body).on('updated_checkout', function() {
        if (!window.wseInitialized) {
            window.wseInitialized = true;
            init();
        }
    });
});
