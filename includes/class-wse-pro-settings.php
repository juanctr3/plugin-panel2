<?php
/**
 * Maneja la creación de la página de ajustes para WooWApp.
 * @package WooWApp
 * @version 1.1
 */
if (!defined('ABSPATH')) exit;

class WSE_Pro_Settings {

    public function __construct() {
        add_filter('woocommerce_settings_tabs_array', [$this, 'add_settings_tab'], 50);
        add_action('woocommerce_settings_tabs_woowapp', [$this, 'settings_tab_content']);
        add_action('woocommerce_update_options_woowapp', [$this, 'update_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
        add_action('wp_ajax_wse_pro_send_test', [WSE_Pro_API_Handler::class, 'ajax_send_test_whatsapp']);
        
        add_action('woocommerce_admin_field_textarea_with_pickers', [$this, 'render_textarea_with_pickers']);
        add_action('woocommerce_admin_field_button', [$this, 'render_button_field']);
        add_action('woocommerce_admin_field_coupon_config', [$this, 'render_coupon_config']);
        add_action('woocommerce_admin_field_message_header', [$this, 'render_message_header']);

        add_filter('woocommerce_settings_api_sanitized_fields_woowapp', [$this, 'sanitize_textarea_fields']);
    }

    public function add_settings_tab($settings_tabs) {
        $settings_tabs['woowapp'] = __('WooWApp', 'woowapp-smsenlinea-pro');
        return $settings_tabs;
    }

    public function settings_tab_content() {
        $current_section = isset($_GET['section']) ? sanitize_key($_GET['section']) : 'administration';
        $tabs = [
            'administration'    => __('Administración', 'woowapp-smsenlinea-pro'),
            'admin_messages'    => __('Mensajes Admin', 'woowapp-smsenlinea-pro'),
            'customer_messages' => __('Mensajes Cliente', 'woowapp-smsenlinea-pro'),
            'notifications'     => __('Notificaciones', 'woowapp-smsenlinea-pro'),
        ];

        echo '<h2 class="nav-tab-wrapper woo-nav-tab-wrapper">';
        foreach ($tabs as $id => $name) {
            $class = ($current_section === $id) ? 'nav-tab-active' : '';
            echo '<a href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=woowapp&section=' . $id)) . '" class="nav-tab ' . esc_attr($class) . '">' . esc_html($name) . '</a>';
        }
        echo '</h2>';

        woocommerce_admin_fields($this->get_settings($current_section));
    }

    public function update_settings() {
        $current_section = isset($_GET['section']) ? sanitize_key($_GET['section']) : 'administration';
        woocommerce_update_options($this->get_settings($current_section));
    }

    public function sanitize_textarea_fields($sanitized_settings) {
        $all_settings = $this->get_settings(true);
        foreach ($all_settings as $setting) {
            if (isset($setting['id'], $setting['type']) && in_array($setting['type'], ['textarea', 'textarea_with_pickers'])) {
                $option_id = $setting['id'];
                if (isset($_POST[$option_id])) {
                    $sanitized_settings[$option_id] = sanitize_textarea_field(wp_unslash($_POST[$option_id]));
                }
            }
        }
        return $sanitized_settings;
    }

    public function get_settings($section = '') {
        if ($section === true) {
            return array_merge(
                $this->get_administration_settings(),
                $this->get_admin_messages_settings(),
                $this->get_customer_messages_settings(),
                $this->get_notifications_settings()
            );
        }
        switch ($section) {
            case 'admin_messages': return $this->get_admin_messages_settings();
            case 'customer_messages': return $this->get_customer_messages_settings();
            case 'notifications': return $this->get_notifications_settings();
            default: return $this->get_administration_settings();
        }
    }

    private function get_administration_settings() {
        $log_url = admin_url('admin.php?page=wc-status&tab=logs');
        $log_handle = WSE_Pro_API_Handler::$log_handle;
        $panel1_docs_url = 'https://documenter.getpostman.com/view/20356708/2s93zB5c3s#intro';
        $panel2_login_url = 'https://app.smsenlinea.com/login';

        return [
            ['name' => __('Ajustes de API y Generales', 'woowapp-smsenlinea-pro'), 'type' => 'title', 'id' => 'wse_pro_api_settings_title'],
            
            ['name' => __('Seleccionar API', 'woowapp-smsenlinea-pro'), 'type' => 'select', 'id' => 'wse_pro_api_panel_selection', 'options' => ['panel2' => __('API Panel 2 (WhatsApp QR)', 'woowapp-smsenlinea-pro'), 'panel1' => __('API Panel 1 (SMS y WhatsApp Clásico)', 'woowapp-smsenlinea-pro')], 'desc' => __('Elige el panel de SMSenlinea que deseas utilizar.', 'woowapp-smsenlinea-pro'), 'desc_tip' => true, 'default' => 'panel2'],
            ['name' => __('Token de Autenticación (Panel 2)', 'woowapp-smsenlinea-pro'), 'type' => 'text', 'id' => 'wse_pro_api_token', 'css' => 'min-width:300px;', 'desc' => sprintf(__('Ingresa el token de tu instancia. Inicia sesión en <a href="%s" target="_blank">Panel 2</a>.', 'woowapp-smsenlinea-pro'), esc_url($panel2_login_url)), 'custom_attributes' => ['data-panel' => 'panel2']],
            ['name' => __('Número de Remitente (Panel 2)', 'woowapp-smsenlinea-pro'), 'type' => 'text', 'id' => 'wse_pro_from_number', 'desc' => __('Incluye el código de país. Ej: 5211234567890.', 'woowapp-smsenlinea-pro'), 'desc_tip' => true, 'custom_attributes' => ['data-panel' => 'panel2']],
            ['name' => __('API Secret (Panel 1)', 'woowapp-smsenlinea-pro'), 'type' => 'text', 'id' => 'wse_pro_api_secret_panel1', 'css' => 'min-width:300px;', 'desc' => sprintf(__('Copia tu API Secret desde <a href="%s" target="_blank">Panel 1</a>.', 'woowapp-smsenlinea-pro'), esc_url($panel1_docs_url)), 'custom_attributes' => ['data-panel' => 'panel1']],
            ['name' => __('Tipo de Mensaje (Panel 1)', 'woowapp-smsenlinea-pro'), 'type' => 'select', 'id' => 'wse_pro_message_type_panel1', 'options' => ['whatsapp' => __('WhatsApp', 'woowapp-smsenlinea-pro'), 'sms' => __('SMS', 'woowapp-smsenlinea-pro')], 'desc' => __('Selecciona el tipo de mensaje.', 'woowapp-smsenlinea-pro'), 'desc_tip' => true, 'default' => 'whatsapp', 'custom_attributes' => ['data-panel' => 'panel1']],
            ['name' => __('WhatsApp Account ID (Panel 1)', 'woowapp-smsenlinea-pro'), 'type' => 'text', 'id' => 'wse_pro_whatsapp_account_panel1', 'css' => 'min-width:300px;', 'desc' => __('ID único de tu cuenta de WhatsApp.', 'woowapp-smsenlinea-pro'), 'desc_tip' => true, 'custom_attributes' => ['data-panel' => 'panel1', 'data-msg-type' => 'whatsapp']],
            ['name' => __('Modo de Envío SMS (Panel 1)', 'woowapp-smsenlinea-pro'), 'type' => 'select', 'id' => 'wse_pro_sms_mode_panel1', 'options' => ['devices' => __('Usar mis dispositivos', 'woowapp-smsenlinea-pro'), 'credits' => __('Usar créditos', 'woowapp-smsenlinea-pro')], 'desc' => __('devices=Android; credits=gateway.', 'woowapp-smsenlinea-pro'), 'desc_tip' => true, 'default' => 'devices', 'custom_attributes' => ['data-panel' => 'panel1', 'data-msg-type' => 'sms']],
            ['name' => __('Device / Gateway ID (Panel 1)', 'woowapp-smsenlinea-pro'), 'type' => 'text', 'id' => 'wse_pro_sms_device_panel1', 'css' => 'min-width:300px;', 'desc' => __('ID de tu dispositivo o gateway.', 'woowapp-smsenlinea-pro'), 'desc_tip' => true, 'custom_attributes' => ['data-panel' => 'panel1', 'data-msg-type' => 'sms']],
            ['name' => __('Código de País Predeterminado', 'woowapp-smsenlinea-pro'), 'type' => 'text', 'id' => 'wse_pro_default_country_code', 'desc' => __('Ej: 57 para Colombia.', 'woowapp-smsenlinea-pro'), 'desc_tip' => true],
            ['name' => __('Adjuntar Imagen de Producto (Pedidos)', 'woowapp-smsenlinea-pro'), 'type' => 'checkbox', 'id' => 'wse_pro_attach_product_image', 'desc' => __('<strong>Activa para adjuntar imagen.</strong> (Solo WhatsApp)', 'woowapp-smsenlinea-pro'), 'default' => 'no'],
            ['name' => __('Activar Registro de Actividad (Log)', 'woowapp-smsenlinea-pro'), 'type' => 'checkbox', 'id' => 'wse_pro_enable_log', 'default' => 'yes', 'desc' => sprintf(__('Ver en <a href="%s">WooCommerce > Registros</a> (<code>%s</code>).', 'woowapp-smsenlinea-pro'), esc_url($log_url), esc_html($log_handle))],
            ['type' => 'sectionend', 'id' => 'wse_pro_api_settings_end'],
            
            ['name' => __('Prueba de Envío', 'woowapp-smsenlinea-pro'), 'type' => 'title', 'id' => 'wse_pro_test_settings_title'],
            ['name' => __('Número de Destino', 'woowapp-smsenlinea-pro'), 'type' => 'text', 'id' => 'wse_pro_test_number', 'css' => 'min-width:300px;', 'placeholder' => __('Ej: 573001234567', 'woowapp-smsenlinea-pro')],
            ['name' => '', 'type' => 'button', 'id' => 'wse_pro_send_test_button', 'class' => 'button-secondary', 'value' => __('Enviar Mensaje de Prueba', 'woowapp-smsenlinea-pro'), 'desc' => '<span id="test_send_status"></span>'],
            ['type' => 'sectionend', 'id' => 'wse_pro_test_settings_end'],
        ];
    }

    private function get_admin_messages_settings() {
        $settings = [
            ['name' => __('Notificaciones para Administradores', 'woowapp-smsenlinea-pro'), 'type' => 'title', 'id' => 'wse_pro_admin_settings_title', 'desc' => __('Define números y mensajes para administradores.', 'woowapp-smsenlinea-pro')],
            ['name' => __('Números de Administradores', 'woowapp-smsenlinea-pro'), 'type' => 'textarea', 'id' => 'wse_pro_admin_numbers', 'css' => 'width:100%; height:100px;', 'desc' => __('Uno por línea con código de país (Ej: 573001234567).', 'woowapp-smsenlinea-pro')],
            ['name' => __('Plantillas de Mensajes para Administradores', 'woowapp-smsenlinea-pro'), 'type' => 'title', 'id' => 'wse_pro_admin_templates_title_sub'],
        ];
        foreach (wc_get_order_statuses() as $slug => $name) {
            $slug_clean = str_replace('wc-', '', $slug);
            $settings[] = ['name' => sprintf(__('Activar para: %s', 'woowapp-smsenlinea-pro'), esc_html($name)), 'type' => 'checkbox', 'id' => 'wse_pro_enable_admin_' . $slug_clean, 'default' => 'no'];
            $settings[] = ['name' => __('Plantilla para Administradores', 'woowapp-smsenlinea-pro'), 'type' => 'textarea_with_pickers', 'id' => 'wse_pro_admin_message_' . $slug_clean, 'css' => 'width:100%; height:75px;', 'default' => sprintf(__('🔔 Pedido #{order_id} de {customer_fullname} cambió a: %s.', 'woowapp-smsenlinea-pro'), esc_html($name))];
        }
        $settings[] = ['type' => 'sectionend', 'id' => 'wse_pro_admin_settings_end'];
        return $settings;
    }

    private function get_customer_messages_settings() {
        $settings = [['name' => __('Plantillas de Mensajes para Clientes', 'woowapp-smsenlinea-pro'), 'type' => 'title', 'id' => 'wse_pro_notifications_title']];
        $templates = ['note' => ['name' => __('Nueva Nota de Pedido', 'woowapp-smsenlinea-pro'), 'default' => __('Hola {customer_name}, nueva nota en #{order_id}: {note_content}', 'woowapp-smsenlinea-pro')]];
        foreach (wc_get_order_statuses() as $slug => $name) {
            $slug_clean = str_replace('wc-', '', $slug);
            $templates[$slug_clean] = ['name' => $name, 'default' => sprintf(__('Hola {customer_name}, tu pedido #{order_id} cambió a: %s. ¡Gracias!', 'woowapp-smsenlinea-pro'), strtolower($name))];
        }
        foreach($templates as $key => $template) {
            $settings[] = ['name' => sprintf(__('Activar para: %s', 'woowapp-smsenlinea-pro'), esc_html($template['name'])), 'type' => 'checkbox', 'id' => 'wse_pro_enable_' . $key, 'default' => 'no'];
            $settings[] = ['name' => __('Plantilla de Mensaje', 'woowapp-smsenlinea-pro'), 'type' => 'textarea_with_pickers', 'id' => 'wse_pro_message_' . $key, 'css' => 'width:100%; height:75px;', 'default' => $template['default']];
        }
        $settings[] = ['type' => 'sectionend', 'id' => 'wse_pro_notifications_end'];
        return $settings;
    }

    private function get_notifications_settings() {
        $settings = [
            ['name' => __('Recordatorio de Reseña de Producto', 'woowapp-smsenlinea-pro'), 'type' => 'title', 'id' => 'wse_pro_review_reminders_title', 'desc' => __('Envía un mensaje para incentivar reseñas.', 'woowapp-smsenlinea-pro')],
            ['name' => __('Activar recordatorio de reseña', 'woowapp-smsenlinea-pro'), 'type' => 'checkbox', 'id' => 'wse_pro_enable_review_reminder', 'desc' => __('<strong>Activar solicitudes automáticas.</strong>', 'woowapp-smsenlinea-pro'), 'default' => 'no'],
            ['name' => __('Enviar después de', 'woowapp-smsenlinea-pro'), 'type' => 'number', 'id' => 'wse_pro_review_reminder_days', 'desc_tip' => true, 'desc' => __('días desde "Completado".', 'woowapp-smsenlinea-pro'), 'custom_attributes' => ['min' => '1'], 'default' => '7'],
            ['name' => __('Plantilla del mensaje', 'woowapp-smsenlinea-pro'), 'type' => 'textarea_with_pickers', 'id' => 'wse_pro_review_reminder_message', 'css' => 'width:100%; height:75px;', 'default' => __('¡Hola {customer_name}! ¿Te importaría dejar una reseña de {first_product_name}? {first_product_review_link}', 'woowapp-smsenlinea-pro')],
            ['type' => 'sectionend', 'id' => 'wse_pro_review_reminders_end'],
            
            // NUEVA SECCIÓN: Recuperación de Carrito con 3 Mensajes
            ['name' => __('🛒 Recuperación de Carrito Abandonado', 'woowapp-smsenlinea-pro'), 'type' => 'title', 'id' => 'wse_pro_abandoned_cart_title', 'desc' => __('Configura hasta 3 mensajes progresivos con descuentos crecientes para recuperar ventas.', 'woowapp-smsenlinea-pro')],
            ['name' => __('Activar recuperación de carrito', 'woowapp-smsenlinea-pro'), 'type' => 'checkbox', 'id' => 'wse_pro_enable_abandoned_cart', 'desc' => __('<strong>Activar sistema de recuperación.</strong>', 'woowapp-smsenlinea-pro'), 'default' => 'no'],
            ['name' => __('Adjuntar imagen del primer producto', 'woowapp-smsenlinea-pro'), 'type' => 'checkbox', 'id' => 'wse_pro_abandoned_cart_attach_image', 'desc' => __('Incluir imagen en mensajes.', 'woowapp-smsenlinea-pro'), 'default' => 'no'],
            ['type' => 'sectionend', 'id' => 'wse_pro_abandoned_cart_general_end'],
        ];

        // Configuración de los 3 mensajes
        for ($i = 1; $i <= 3; $i++) {
            $settings = array_merge($settings, $this->get_cart_message_settings($i));
        }

        return $settings;
    }

    /**
     * Genera la configuración para un mensaje específico de carrito
     */
    private function get_cart_message_settings($message_number) {
        $default_times = [1 => 60, 2 => 24, 3 => 72];
        $default_units = [1 => 'minutes', 2 => 'hours', 3 => 'hours'];
        $default_discounts = [1 => 10, 2 => 15, 3 => 20];
        $default_expiry = [1 => 7, 2 => 5, 3 => 3];

        $message_names = [
            1 => __('Primer Mensaje de Recuperación', 'woowapp-smsenlinea-pro'),
            2 => __('Segundo Mensaje de Recuperación', 'woowapp-smsenlinea-pro'),
            3 => __('Tercer Mensaje (Última Oportunidad)', 'woowapp-smsenlinea-pro')
        ];

        $default_messages = [
            1 => __('¡Hola {customer_name}! 👋 Notamos que dejaste productos en tu carrito. ¡Completa tu compra ahora! {checkout_link}', 'woowapp-smsenlinea-pro'),
            2 => __('¡Hola {customer_name}! 🎁 Tus productos te esperan. Usa el código {coupon_code} para {coupon_amount} de descuento. ¡Válido hasta {coupon_expires}! {checkout_link}', 'woowapp-smsenlinea-pro'),
            3 => __('⏰ {customer_name}, ¡ÚLTIMA OPORTUNIDAD! {coupon_amount} de descuento con {coupon_code}. Expira: {coupon_expires}. ¡No lo pierdas! {checkout_link}', 'woowapp-smsenlinea-pro')
        ];

        return [
            ['name' => $message_names[$message_number], 'type' => 'message_header', 'id' => 'wse_pro_cart_msg_' . $message_number . '_header', 'message_number' => $message_number],
            
            ['name' => __('Activar este mensaje', 'woowapp-smsenlinea-pro'), 'type' => 'checkbox', 'id' => 'wse_pro_abandoned_cart_enable_msg_' . $message_number, 'desc' => sprintf(__('<strong>Enviar mensaje #%d</strong>', 'woowapp-smsenlinea-pro'), $message_number), 'default' => 'no'],
            
            ['name' => __('Enviar después de', 'woowapp-smsenlinea-pro'), 'type' => 'number', 'id' => 'wse_pro_abandoned_cart_time_' . $message_number, 'custom_attributes' => ['min' => '1'], 'default' => $default_times[$message_number], 'css' => 'width:100px;'],
            
            ['name' => '', 'type' => 'select', 'id' => 'wse_pro_abandoned_cart_unit_' . $message_number, 'options' => ['minutes' => __('Minutos', 'woowapp-smsenlinea-pro'), 'hours' => __('Horas', 'woowapp-smsenlinea-pro')], 'default' => $default_units[$message_number]],
            
            ['name' => __('Plantilla del mensaje', 'woowapp-smsenlinea-pro'), 'type' => 'textarea_with_pickers', 'id' => 'wse_pro_abandoned_cart_message_' . $message_number, 'css' => 'width:100%; height:90px;', 'default' => $default_messages[$message_number]],
            
            ['name' => __('💳 Configuración de Cupón', 'woowapp-smsenlinea-pro'), 'type' => 'coupon_config', 'id' => 'wse_pro_coupon_config_' . $message_number, 'message_number' => $message_number, 'default_discount' => $default_discounts[$message_number], 'default_expiry' => $default_expiry[$message_number]],
            
            ['type' => 'sectionend', 'id' => 'wse_pro_cart_msg_' . $message_number . '_end'],
        ];
    }

    /**
     * Renderiza el header visual de cada mensaje
     */
    public function render_message_header($value) {
        $icons = [1 => '📧', 2 => '🎁', 3 => '⏰'];
        $colors = [1 => '#6366f1', 2 => '#f59e0b', 3 => '#ef4444'];
        $msg_num = $value['message_number'];
        ?>
        <tr valign="top">
            <td colspan="2" style="padding: 0;">
                <div style="background: linear-gradient(135deg, <?php echo $colors[$msg_num]; ?> 0%, <?php echo $colors[$msg_num]; ?>dd 100%); color: white; padding: 15px 20px; border-radius: 8px; margin: 20px 0 10px 0; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
                    <h3 style="margin: 0; font-size: 18px; display: flex; align-items: center; gap: 10px;">
                        <span style="font-size: 24px;"><?php echo $icons[$msg_num]; ?></span>
                        <?php echo esc_html($value['name']); ?>
                    </h3>
                </div>
            </td>
        </tr>
        <?php
    }

    /**
     * Renderiza la configuración de cupón
     */
    public function render_coupon_config($value) {
        $msg_num = $value['message_number'];
        $enable = get_option('wse_pro_abandoned_cart_coupon_enable_' . $msg_num, 'no');
        $type = get_option('wse_pro_abandoned_cart_coupon_type_' . $msg_num, 'percent');
        $amount = get_option('wse_pro_abandoned_cart_coupon_amount_' . $msg_num, $value['default_discount']);
        $expiry = get_option('wse_pro_abandoned_cart_coupon_expiry_' . $msg_num, $value['default_expiry']);
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc">
                <label><?php echo esc_html($value['name']); ?></label>
            </th>
            <td class="forminp">
                <div style="background: #f9fafb; padding: 20px; border-radius: 8px; border: 2px solid #e5e7eb;">
                    <p style="margin: 0 0 15px 0;">
                        <label style="display: flex; align-items: center; gap: 10px; font-weight: 600;">
                            <input type="checkbox" name="wse_pro_abandoned_cart_coupon_enable_<?php echo $msg_num; ?>" value="yes" <?php checked($enable, 'yes'); ?> style="width: 20px; height: 20px;">
                            <span><?php _e('Incluir cupón de descuento en este mensaje', 'woowapp-smsenlinea-pro'); ?></span>
                        </label>
                    </p>
                    
                    <div class="coupon-options" style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #e5e7eb;">
                        <p style="margin: 0 0 10px 0;">
                            <label style="font-weight: 600; display: block; margin-bottom: 5px;">
                                <?php _e('Tipo de descuento:', 'woowapp-smsenlinea-pro'); ?>
                            </label>
                            <select name="wse_pro_abandoned_cart_coupon_type_<?php echo $msg_num; ?>" style="width: 200px;">
                                <option value="percent" <?php selected($type, 'percent'); ?>><?php _e('Porcentaje (%)', 'woowapp-smsenlinea-pro'); ?></option>
                                <option value="fixed_cart" <?php selected($type, 'fixed_cart'); ?>><?php _e('Monto Fijo', 'woowapp-smsenlinea-pro'); ?></option>
                            </select>
                        </p>
                        
                        <p style="margin: 0 0 10px 0;">
                            <label style="font-weight: 600; display: block; margin-bottom: 5px;">
                                <?php _e('Cantidad de descuento:', 'woowapp-smsenlinea-pro'); ?>
                            </label>
                            <input type="number" name="wse_pro_abandoned_cart_coupon_amount_<?php echo $msg_num; ?>" value="<?php echo esc_attr($amount); ?>" min="1" step="0.01" style="width: 120px;">
                            <span style="color: #6b7280; font-size: 13px; margin-left: 8px;">
                                <?php _e('(Ej: 10 para 10% o $10)', 'woowapp-smsenlinea-pro'); ?>
                            </span>
                        </p>
                        
                        <p style="margin: 0;">
                            <label style="font-weight: 600; display: block; margin-bottom: 5px;">
                                <?php _e('Válido por:', 'woowapp-smsenlinea-pro'); ?>
                            </label>
                            <input type="number" name="wse_pro_abandoned_cart_coupon_expiry_<?php echo $msg_num; ?>" value="<?php echo esc_attr($expiry); ?>" min="1" max="365" style="width: 80px;">
                            <span style="margin-left: 8px;"><?php _e('días', 'woowapp-smsenlinea-pro'); ?></span>
                        </p>
                    </div>
                    
                    <div style="margin-top: 15px; padding: 12px; background: white; border-radius: 6px; border-left: 4px solid #6366f1;">
                        <p style="margin: 0; font-size: 13px; color: #6b7280;">
                            <strong style="color: #1f2937;">💡 Tip:</strong> 
                            <?php _e('Usa las variables {coupon_code}, {coupon_amount} y {coupon_expires} en tu plantilla para mostrar la información del cupón.', 'woowapp-smsenlinea-pro'); ?>
                        </p>
                    </div>
                </div>
            </td>
        </tr>
        <?php
    }

    public function render_textarea_with_pickers($value) {
        $option_value = get_option($value['id'], $value['default']);
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc">
                <label for="<?php echo esc_attr($value['id']); ?>"><?php echo esc_html($value['name']); ?></label>
            </th>
            <td class="forminp forminp-textarea">
                <div class="wse-pro-field-wrapper">
                    <div class="wse-pro-textarea-container">
                        <textarea name="<?php echo esc_attr($value['id']); ?>" id="<?php echo esc_attr($value['id']); ?>" style="<?php echo esc_attr($value['css']); ?>"><?php echo esc_textarea($option_value); ?></textarea>
                    </div>
                    <div class="wse-pro-pickers-container">
                        <div class="wc-wa-accordion-trigger">
                            <span><?php esc_html_e('Variables y Emojis', 'woowapp-smsenlinea-pro'); ?></span>
                            <span class="dashicons dashicons-arrow-down-alt2"></span>
                        </div>
                        
                        <div class="wc-wa-accordion-content" style="display: none;" data-target-id="<?php echo esc_attr($value['id']); ?>">
                            <div class="wc-wa-picker-group">
                                <strong><?php esc_html_e('Variables:', 'woowapp-smsenlinea-pro'); ?></strong>
                                <?php foreach (WSE_Pro_Placeholders::get_all_placeholders_grouped() as $group => $codes) : ?>
                                    <div class="picker-subgroup">
                                        <em><?php echo esc_html($group); ?>:</em><br>
                                        <?php foreach ($codes as $code) : ?>
                                            <button type="button" class="button button-small" data-value="<?php echo esc_attr($code); ?>"><?php echo esc_html($code); ?></button>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="wc-wa-picker-group">
                                <strong><?php esc_html_e('Emojis:', 'woowapp-smsenlinea-pro'); ?></strong>
                                <?php foreach (WSE_Pro_Placeholders::get_all_emojis_grouped() as $group => $icons) : ?>
                                    <div class="picker-subgroup">
                                        <em><?php echo esc_html($group); ?>:</em><br>
                                        <?php foreach ($icons as $icon) : ?>
                                            <button type="button" class="button button-small emoji-btn" data-value="<?php echo esc_attr($icon); ?>"><?php echo esc_html($icon); ?></button>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </td>
        </tr>
        <?php
    }

    public function render_button_field($value) {
        $field_description = WC_Admin_Settings::get_field_description($value);
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc">
                <label for="<?php echo esc_attr($value['id']); ?>"><?php echo esc_html($value['title']); ?></label>
                <?php echo $field_description['tooltip_html']; ?>
            </th>
            <td class="forminp forminp-button">
                <button type="button" id="<?php echo esc_attr($value['id']); ?>" class="<?php echo esc_attr($value['class']); ?>"><?php echo esc_html($value['value']); ?></button>
                <?php echo $field_description['description']; ?>
            </td>
        </tr>
        <?php
    }

    public function enqueue_admin_scripts($hook) {
        if ('woocommerce_page_wc-settings' !== $hook) return;
        if (!isset($_GET['tab']) || 'woowapp' !== $_GET['tab']) return;

        wp_enqueue_style('wse-pro-admin-css', WSE_PRO_URL . 'assets/css/admin.css', [], '1.1');
        wp_enqueue_script('wse-pro-admin-js', WSE_PRO_URL . 'assets/js/admin.js', ['jquery'], '1.1', true);
        wp_localize_script('wse-pro-admin-js', 'wse_pro_admin_params', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('wse_pro_send_test_nonce')
        ]);
    }
}
