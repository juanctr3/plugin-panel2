jQuery(document).ready(function($) {
    let captureTimeout = null;
    let lastCapturedData = null;
    let captureAttempts = 0;
    const MAX_ATTEMPTS = 5;

    // 🔧 Selectores mejorados con múltiples alternativas
    const FIELD_SELECTORS = {
        billing_email: '#billing_email, input[name="billing_email"]',
        billing_phone: '#billing_phone, input[name="billing_phone"], input[name="phone"]',
        billing_first_name: '#billing_first_name, input[name="billing_first_name"]',
        billing_last_name: '#billing_last_name, input[name="billing_last_name"]',
        billing_address_1: '#billing_address_1, textarea[name="billing_address_1"]',
        billing_city: '#billing_city, input[name="billing_city"]',
        billing_state: '#billing_state, select[name="billing_state"], input[name="billing_state"]',
        billing_postcode: '#billing_postcode, input[name="billing_postcode"]',
        billing_country: '#billing_country, select[name="billing_country"]'
    };

    /**
     * 🔍 Obtener valor de campo con múltiples intentos
     */
    function getFieldValue(fieldName) {
        const selectors = FIELD_SELECTORS[fieldName];
        if (!selectors) return '';
        
        const $element = $(selectors).first();
        
        if (!$element.length) {
            console.debug(`Campo no encontrado: ${fieldName}`);
            return '';
        }

        let value = $element.val() || '';
        
        // Trim y sanitize
        value = String(value).trim();
        
        console.debug(`${fieldName}: "${value}"`);
        return value;
    }

    /**
     * 📦 Capturar todos los datos del formulario
     */
    function captureCart() {
        const billingData = {
            action: 'wse_pro_capture_cart',
            nonce: wseProCapture.nonce,
            billing_email: getFieldValue('billing_email'),
            billing_phone: getFieldValue('billing_phone'),
            billing_first_name: getFieldValue('billing_first_name'),
            billing_last_name: getFieldValue('billing_last_name'),
            billing_address_1: getFieldValue('billing_address_1'),
            billing_city: getFieldValue('billing_city'),
            billing_state: getFieldValue('billing_state'),
            billing_postcode: getFieldValue('billing_postcode'),
            billing_country: getFieldValue('billing_country')
        };

        // ✅ Validación: Al menos email O teléfono
        if (!billingData.billing_email && !billingData.billing_phone) {
            console.log('⏭️  Sin email ni teléfono - no capturar');
            return;
        }

        // 🔄 Verificar si los datos cambieron
        const dataHash = JSON.stringify(billingData);
        if (dataHash === lastCapturedData) {
            console.log('📝 Datos sin cambios - no enviar');
            return;
        }

        console.log('📤 Enviando datos al servidor...', billingData);

        // 🌐 AJAX
        $.ajax({
            url: wseProCapture.ajax_url,
            type: 'POST',
            data: billingData,
            timeout: 10000,
            success: function(response) {
                if (response.success && response.data.captured) {
                    lastCapturedData = dataHash;
                    console.log('✅ Carrito capturado correctamente');
                    captureAttempts = 0; // Reset contador
                } else {
                    console.warn('⚠️  Servidor rechazó los datos:', response.data);
                }
            },
            error: function(xhr, status, error) {
                console.error('❌ Error al capturar:', error);
                captureAttempts++;
            }
        });
    }

    /**
     * ⏱️ Programar captura con debounce mejorado
     */
    function scheduleCapture() {
        // Si alcanzó max intentos, no seguir
        if (captureAttempts >= MAX_ATTEMPTS) {
            console.warn('❌ Máximo de intentos alcanzado');
            return;
        }

        clearTimeout(captureTimeout);
        
        // Esperar 1.5 segundos después del último cambio
        captureTimeout = setTimeout(function() {
            captureCart();
        }, 1500);
    }

    /**
     * 🎯 Añadir listeners a campos
     */
    function attachListeners() {
        // Listeners para cada selector
        Object.values(FIELD_SELECTORS).forEach(function(selector) {
            $(document).off('change blur input', selector);
            $(document).on('change blur input', selector, function() {
                console.log('👁️  Campo cambiado:', this.name);
                scheduleCapture();
            });
        });

        // 🆕 Listener para eventos de WooCommerce
        $(document.body).off('updated_checkout').on('updated_checkout', function() {
            console.log('🔄 Checkout actualizado - reintentando captura');
            scheduleCapture();
        });

        // 🆕 Listener para Select2 (si está activo)
        $(document).off('select2:select').on('select2:select', 'select[name*="billing"]', function() {
            console.log('✓ Select2 cambió');
            scheduleCapture();
        });

        console.log('✅ Listeners adjuntados correctamente');
    }

    /**
     * 🚀 Inicialización
     */
    function init() {
        console.log('%c🛒 WooWApp Cart Capture Iniciado', 'color: #10b981; font-weight: bold');
        
        // Adjuntar listeners
        attachListeners();

        // Captura inicial después de 3 segundos (campo de teléfono personalizado requiere espera)
        setTimeout(function() {
            console.log('📌 Captura inicial (3 segundos después)');
            captureCart();
        }, 3000);

        // Reintentar cada 10 segundos si hay cambios
        setInterval(function() {
            if (captureAttempts > 0 && captureAttempts < MAX_ATTEMPTS) {
                console.log(`🔁 Reintentando... (${captureAttempts}/${MAX_ATTEMPTS})`);
                scheduleCapture();
            }
        }, 10000);
    }

    // Iniciar cuando jQuery esté listo
    if ($('form.checkout').length) {
        init();
    } else {
        // Esperar a que aparezca el formulario
        $(document).on('updated_checkout', function() {
            if ($('form.checkout').length && captureAttempts === 0) {
                init();
            }
        });
    }
});
