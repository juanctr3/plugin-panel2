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
        $settings = $this->get_settings($current_section);
        
        // CORRECCIÓN: Forzar actualización de checkboxes de cupones
        $this->force_update_coupon_checkboxes($current_section);
        
        woocommerce_update_options($settings);
    }

    /**
     * Fuerza la actualización correcta de todos los checkboxes de cupones
     */
    private function force_update_coupon_checkboxes($section) {
        $checkbox_fields = [];
        
        if ($section === 'notifications') {
            // Checkboxes de carrito abandonado (3 mensajes)
            for ($i = 1; $i <= 3; $i++) {
                $checkbox_fields[] = "wse_pro_cart_msg{$i}_coupon_enable";
            }
            
            // Checkboxes de reseñas
            $checkbox_fields[] = 'wse_pro_review_coupon_enable';
        }
        
        // Actualizar cada checkbox
        foreach ($checkbox_fields as $field) {
            if (isset($_POST[$field])) {
                update_option($field, 'yes');
            } else {
                update_option($field, 'no');
            }
        }
    }

    public function get_settings($section = 'administration') {
        switch ($section) {
            case 'admin_messages':
                return $this->get_admin_messages_settings();
            case 'customer_messages':
                return $this->get_customer_messages_settings();
            case 'notifications':
                return $this->get_notifications_settings();
            default:
                return $this->get_administration_settings();
        }
    }

    private function get_administration_settings() {
        $log_handle = 'woowapp-' . date('Y-m-d');
        $log_url = admin_url('admin.php?page=wc-status&tab=logs&log_file=' . $log_handle);

        return [
            ['name' => __('Configuración General de WooWApp', 'woowapp-smsenlinea-pro'), 'type' => 'title', 'id' => 'wse_pro_general_settings_title'],
            ['name' => __('Habilitar WooWApp', 'woowapp-smsenlinea-pro'), 'type' => 'checkbox', 'id' => 'wse_pro_enable', 'default' => 'yes', 'desc' => __('Activa o desactiva todas las funciones.', 'woowapp-smsenlinea-pro')],
            ['type' => 'sectionend', 'id' => 'wse_pro_general_settings_end'],
            
            ['name' => __('Configuración del Panel SMSenlinea', 'woowapp-smsenlinea-pro'), 'type' => 'title', 'id' => 'wse_pro_api_settings_title'],
            ['name' => __('Panel a Utilizar', 'woowapp-smsenlinea-pro'), 'type' => 'select', 'id' => 'wse_pro_api_panel', 'default' => 'panel1', 'options' => ['panel1' => __('Panel 1 (SMS y WhatsApp)', 'woowapp-smsenlinea-pro'), 'panel2' => __('Panel 2 (WhatsApp)', 'woowapp-smsenlinea-pro')]],
            ['name' => __('URL del Panel', 'woowapp-smsenlinea-pro'), 'type' => 'text', 'id' => 'wse_pro_api_url', 'css' => 'min-width:400px;', 'default' => 'https://ws.smsenlinea.com/api/v1/', 'custom_attributes' => ['data-panel' => 'panel1']],
            ['name' => __('Token de Autenticación', 'woowapp-smsenlinea-pro'), 'type' => 'text', 'id' => 'wse_pro_api_token', 'css' => 'min-width:400px;', 'custom_attributes' => ['data-panel' => 'panel1']],
            ['name' => __('URL del Panel 2', 'woowapp-smsenlinea-pro'), 'type' => 'text', 'id' => 'wse_pro_api_url_panel2', 'css' => 'min-width:400px;', 'default' => 'https://wsapi.smsenlinea.com/api/v1/', 'custom_attributes' => ['data-panel' => 'panel2']],
            ['name' => __('Token Panel 2', 'woowapp-smsenlinea-pro'), 'type' => 'text', 'id' => 'wse_pro_api_token_panel2', 'css' => 'min-width:400px;', 'custom_attributes' => ['data-panel' => 'panel2']],
            ['name' => __('Método de Envío Predeterminado', 'woowapp-smsenlinea-pro'), 'type' => 'select', 'id' => 'wse_pro_default_send_method', 'default' => 'whatsapp', 'options' => ['sms' => 'SMS', 'whatsapp' => 'WhatsApp'], 'desc' => __('Elige SMS o WhatsApp (Panel 1 soporta ambos; Panel 2 solo WhatsApp).', 'woowapp-smsenlinea-pro'), 'desc_tip' => true, 'custom_attributes' => ['data-panel' => 'panel1', 'data-msg-type' => 'sms']],
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
            $settings[] = ['name' => __('Plantilla para Administradores', 'woowapp-smsenlinea-pro'), 'type' => 'textarea_with_pickers', 'id' => 'wse_pro_admin_message_' . $slug_clean, 'css' => 'width:100%; min-height:100px;', 'default' => "Nuevo pedido #{order_id}\nCliente: {customer_name}\nTotal: {order_total}\nVer: {order_link}"];
        }

        $settings[] = ['type' => 'sectionend', 'id' => 'wse_pro_admin_templates_end'];
        return $settings;
    }

    private function get_customer_messages_settings() {
        $settings = [
            ['name' => __('Notificaciones para Clientes', 'woowapp-smsenlinea-pro'), 'type' => 'title', 'id' => 'wse_pro_customer_settings_title', 'desc' => __('Define mensajes enviados a clientes.', 'woowapp-smsenlinea-pro')],
        ];

        foreach (wc_get_order_statuses() as $slug => $name) {
            $slug_clean = str_replace('wc-', '', $slug);
            $settings[] = ['name' => sprintf(__('Activar para: %s', 'woowapp-smsenlinea-pro'), esc_html($name)), 'type' => 'checkbox', 'id' => 'wse_pro_enable_customer_' . $slug_clean, 'default' => 'yes'];
            $settings[] = ['name' => __('Plantilla para Clientes', 'woowapp-smsenlinea-pro'), 'type' => 'textarea_with_pickers', 'id' => 'wse_pro_customer_message_' . $slug_clean, 'css' => 'width:100%; min-height:100px;', 'default' => "Hola {customer_name}, tu pedido #{order_id} está {order_status}.\nTotal: {order_total}\nVer: {order_link}"];
        }

        $settings[] = ['type' => 'sectionend', 'id' => 'wse_pro_customer_templates_end'];
        return $settings;
    }

    private function get_notifications_settings() {
        return [
            // CARRITO ABANDONADO - Mensaje 1
            ['type' => 'message_header', 'id' => 'cart_msg1_header', 'title' => __('📱 Mensaje 1 - Carrito Abandonado', 'woowapp-smsenlinea-pro')],
            ['name' => __('Activar Mensaje 1', 'woowapp-smsenlinea-pro'), 'type' => 'checkbox', 'id' => 'wse_pro_cart_msg1_enable', 'default' => 'yes'],
            ['name' => __('Enviar después de (minutos)', 'woowapp-smsenlinea-pro'), 'type' => 'number', 'id' => 'wse_pro_cart_msg1_delay', 'default' => '30', 'custom_attributes' => ['min' => '1']],
            ['name' => __('Plantilla del Mensaje', 'woowapp-smsenlinea-pro'), 'type' => 'textarea_with_pickers', 'id' => 'wse_pro_cart_msg1_template', 'css' => 'width:100%; min-height:100px;', 'default' => "Hola {customer_name}, olvidaste algo en tu carrito 🛒\nRecupera tus productos: {cart_link}"],
            
            ['type' => 'coupon_config', 'id' => 'cart_msg1_coupon', 'message_num' => '1'],
            
            ['type' => 'sectionend', 'id' => 'cart_msg1_end'],

            // CARRITO ABANDONADO - Mensaje 2
            ['type' => 'message_header', 'id' => 'cart_msg2_header', 'title' => __('📱 Mensaje 2 - Carrito Abandonado', 'woowapp-smsenlinea-pro')],
            ['name' => __('Activar Mensaje 2', 'woowapp-smsenlinea-pro'), 'type' => 'checkbox', 'id' => 'wse_pro_cart_msg2_enable', 'default' => 'no'],
            ['name' => __('Enviar después de (minutos)', 'woowapp-smsenlinea-pro'), 'type' => 'number', 'id' => 'wse_pro_cart_msg2_delay', 'default' => '120', 'custom_attributes' => ['min' => '1']],
            ['name' => __('Plantilla del Mensaje', 'woowapp-smsenlinea-pro'), 'type' => 'textarea_with_pickers', 'id' => 'wse_pro_cart_msg2_template', 'css' => 'width:100%; min-height:100px;', 'default' => "¡Última oportunidad! 🎁\nTu carrito te espera: {cart_link}"],
            
            ['type' => 'coupon_config', 'id' => 'cart_msg2_coupon', 'message_num' => '2'],
            
            ['type' => 'sectionend', 'id' => 'cart_msg2_end'],

            // CARRITO ABANDONADO - Mensaje 3
            ['type' => 'message_header', 'id' => 'cart_msg3_header', 'title' => __('📱 Mensaje 3 - Carrito Abandonado', 'woowapp-smsenlinea-pro')],
            ['name' => __('Activar Mensaje 3', 'woowapp-smsenlinea-pro'), 'type' => 'checkbox', 'id' => 'wse_pro_cart_msg3_enable', 'default' => 'no'],
            ['name' => __('Enviar después de (minutos)', 'woowapp-smsenlinea-pro'), 'type' => 'number', 'id' => 'wse_pro_cart_msg3_delay', 'default' => '1440', 'custom_attributes' => ['min' => '1']],
            ['name' => __('Plantilla del Mensaje', 'woowapp-smsenlinea-pro'), 'type' => 'textarea_with_pickers', 'id' => 'wse_pro_cart_msg3_template', 'css' => 'width:100%; min-height:100px;', 'default' => "¡No pierdas esta oportunidad! 💥\nCompleta tu compra: {cart_link}"],
            
            ['type' => 'coupon_config', 'id' => 'cart_msg3_coupon', 'message_num' => '3'],
            
            ['type' => 'sectionend', 'id' => 'cart_msg3_end'],

            // NOTAS DE PEDIDO
            ['name' => __('💬 Notificación de Notas', 'woowapp-smsenlinea-pro'), 'type' => 'title', 'id' => 'wse_pro_notes_title'],
            ['name' => __('Activar Notificación de Notas', 'woowapp-smsenlinea-pro'), 'type' => 'checkbox', 'id' => 'wse_pro_enable_notes', 'default' => 'yes'],
            ['name' => __('Plantilla de Nota', 'woowapp-smsenlinea-pro'), 'type' => 'textarea_with_pickers', 'id' => 'wse_pro_notes_message', 'css' => 'width:100%; min-height:100px;', 'default' => "Hola {customer_name}, nueva nota en tu pedido #{order_id}:\n{note_content}"],
            ['type' => 'sectionend', 'id' => 'wse_pro_notes_end'],

            // RECORDATORIO DE RESEÑAS
            ['name' => __('⭐ Recordatorio de Reseñas', 'woowapp-smsenlinea-pro'), 'type' => 'title', 'id' => 'wse_pro_review_title'],
            ['name' => __('Activar Recordatorio de Reseñas', 'woowapp-smsenlinea-pro'), 'type' => 'checkbox', 'id' => 'wse_pro_enable_review', 'default' => 'no'],
            ['name' => __('Enviar después de (días)', 'woowapp-smsenlinea-pro'), 'type' => 'number', 'id' => 'wse_pro_review_delay_days', 'default' => '7', 'custom_attributes' => ['min' => '1']],
            ['name' => __('Plantilla de Reseña', 'woowapp-smsenlinea-pro'), 'type' => 'textarea_with_pickers', 'id' => 'wse_pro_review_message', 'css' => 'width:100%; min-height:100px;', 'default' => "Hola {customer_name}, ¿qué tal tu compra?\nDéjanos tu opinión: {review_link}"],
            
            // Cupón por Reseña
            ['name' => __('🎁 Cupón por Reseña', 'woowapp-smsenlinea-pro'), 'type' => 'title', 'id' => 'wse_pro_review_coupon_title', 'desc' => __('Recompensa a clientes que dejen reseñas', 'woowapp-smsenlinea-pro')],
            ['name' => __('Activar Cupón por Reseña', 'woowapp-smsenlinea-pro'), 'type' => 'checkbox', 'id' => 'wse_pro_review_coupon_enable', 'default' => 'no', 'desc' => __('Se genera al publicar reseña', 'woowapp-smsenlinea-pro')],
            ['name' => __('Tipo de Descuento', 'woowapp-smsenlinea-pro'), 'type' => 'select', 'id' => 'wse_pro_review_coupon_type', 'options' => ['percent' => __('Porcentaje', 'woowapp-smsenlinea-pro'), 'fixed_cart' => __('Monto Fijo', 'woowapp-smsenlinea-pro')]],
            ['name' => __('Valor del Descuento', 'woowapp-smsenlinea-pro'), 'type' => 'number', 'id' => 'wse_pro_review_coupon_amount', 'default' => '10', 'custom_attributes' => ['step' => '0.01', 'min' => '0']],
            ['name' => __('Estrellas Mínimas', 'woowapp-smsenlinea-pro'), 'type' => 'select', 'id' => 'wse_pro_review_min_stars', 'default' => '4', 'options' => ['1' => '1⭐', '2' => '2⭐⭐', '3' => '3⭐⭐⭐', '4' => '4⭐⭐⭐⭐', '5' => '5⭐⭐⭐⭐⭐']],
            ['name' => __('Días de Validez', 'woowapp-smsenlinea-pro'), 'type' => 'number', 'id' => 'wse_pro_review_coupon_validity', 'default' => '30', 'custom_attributes' => ['min' => '1']],
            ['name' => __('Prefijo del Cupón', 'woowapp-smsenlinea-pro'), 'type' => 'text', 'id' => 'wse_pro_review_coupon_prefix', 'default' => 'REVIEW'],
            
            ['type' => 'sectionend', 'id' => 'wse_pro_review_end'],
        ];
    }

    public function render_message_header($value) {
        ?>
        <tr valign="top">
            <th colspan="2" style="padding: 20px 0 10px 0;">
                <h3 style="margin: 0; padding: 10px; background: #f0f0f1; border-left: 4px solid #2271b1;">
                    <?php echo esc_html($value['title']); ?>
                </h3>
            </th>
        </tr>
        <?php
    }

    public function render_coupon_config($value) {
        $msg_num = isset($value['message_num']) ? $value['message_num'] : '1';
        $enable_id = "wse_pro_cart_msg{$msg_num}_coupon_enable";
        $type_id = "wse_pro_cart_msg{$msg_num}_coupon_type";
        $amount_id = "wse_pro_cart_msg{$msg_num}_coupon_amount";
        $validity_id = "wse_pro_cart_msg{$msg_num}_coupon_validity";
        $prefix_id = "wse_pro_cart_msg{$msg_num}_coupon_prefix";

        $enable_value = get_option($enable_id, 'no');
        $type_value = get_option($type_id, 'percent');
        $amount_value = get_option($amount_id, '10');
        $validity_value = get_option($validity_id, '7');
        $prefix_value = get_option($prefix_id, "CART{$msg_num}");
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc">
                <label><?php _e('🎁 Cupón de Descuento', 'woowapp-smsenlinea-pro'); ?></label>
            </th>
            <td class="forminp">
                <fieldset style="border: 1px solid #ddd; padding: 15px; background: #f9f9f9;">
                    <legend style="padding: 0 10px; font-weight: 600;"><?php _e('Configuración del Cupón', 'woowapp-smsenlinea-pro'); ?></legend>
                    
                    <p style="margin-top: 0;">
                        <label>
                            <input type="checkbox" name="<?php echo esc_attr($enable_id); ?>" id="<?php echo esc_attr($enable_id); ?>" value="yes" <?php checked($enable_value, 'yes'); ?>>
                            <?php _e('Incluir cupón en este mensaje', 'woowapp-smsenlinea-pro'); ?>
                        </label>
                    </p>

                    <div class="coupon-fields" style="margin-top: 15px;">
                        <p>
                            <label><?php _e('Tipo de Descuento:', 'woowapp-smsenlinea-pro'); ?></label><br>
                            <select name="<?php echo esc_attr($type_id); ?>" style="width: 200px;">
                                <option value="percent" <?php selected($type_value, 'percent'); ?>><?php _e('Porcentaje', 'woowapp-smsenlinea-pro'); ?></option>
                                <option value="fixed_cart" <?php selected($type_value, 'fixed_cart'); ?>><?php _e('Monto Fijo', 'woowapp-smsenlinea-pro'); ?></option>
                            </select>
                        </p>

                        <p>
                            <label><?php _e('Valor del Descuento:', 'woowapp-smsenlinea-pro'); ?></label><br>
                            <input type="number" name="<?php echo esc_attr($amount_id); ?>" value="<?php echo esc_attr($amount_value); ?>" step="0.01" min="0" style="width: 100px;">
                        </p>

                        <p>
                            <label><?php _e('Días de Validez:', 'woowapp-smsenlinea-pro'); ?></label><br>
                            <input type="number" name="<?php echo esc_attr($validity_id); ?>" value="<?php echo esc_attr($validity_value); ?>" min="1" style="width: 100px;">
                        </p>

                        <p>
                            <label><?php _e('Prefijo del Cupón:', 'woowapp-smsenlinea-pro'); ?></label><br>
                            <input type="text" name="<?php echo esc_attr($prefix_id); ?>" value="<?php echo esc_attr($prefix_value); ?>" style="width: 150px;">
                            <span class="description"><?php _e('Se generará: PREFIX-XXXXX', 'woowapp-smsenlinea-pro'); ?></span>
                        </p>

                        <p class="description">
                            <?php _e('💡 Usa {coupon_code} en la plantilla para incluir el cupón', 'woowapp-smsenlinea-pro'); ?>
                        </p>
                    </div>
                </fieldset>
            </td>
        </tr>
        <?php
    }

    public function render_textarea_with_pickers($value) {
        $option_value = get_option($value['id'], isset($value['default']) ? $value['default'] : '');
        $placeholders = WSE_Pro_Placeholders::get_placeholders();
        $emojis = WSE_Pro_Placeholders::get_emojis();
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc">
                <label for="<?php echo esc_attr($value['id']); ?>"><?php echo esc_html($value['name']); ?></label>
            </th>
            <td class="forminp">
                <textarea 
                    name="<?php echo esc_attr($value['id']); ?>" 
                    id="<?php echo esc_attr($value['id']); ?>" 
                    style="<?php echo esc_attr($value['css']); ?>" 
                    class="wse-pro-message-template"><?php echo esc_textarea($option_value); ?></textarea>
                
                <div class="picker-buttons" style="margin-top: 10px;">
                    <button type="button" class="button wse-insert-placeholder" data-target="<?php echo esc_attr($value['id']); ?>">
                        📋 <?php _e('Insertar Variable', 'woowapp-smsenlinea-pro'); ?>
                    </button>
                    <button type="button" class="button wse-insert-emoji" data-target="<?php echo esc_attr($value['id']); ?>">
                        😊 <?php _e('Insertar Emoji', 'woowapp-smsenlinea-pro'); ?>
                    </button>
                </div>

                <div class="wse-placeholder-list" style="display:none; margin-top:10px; max-width:400px;">
                    <select class="wse-placeholder-select" style="width:100%;">
                        <option value=""><?php _e('-- Selecciona una variable --', 'woowapp-smsenlinea-pro'); ?></option>
                        <?php foreach ($placeholders as $key => $label): ?>
                            <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($key . ' - ' . $label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="wse-emoji-list" style="display:none; margin-top:10px;">
                    <div style="display:grid; grid-template-columns:repeat(auto-fill,minmax(40px,1fr)); gap:5px; max-width:400px;">
                        <?php foreach ($emojis as $emoji): ?>
                            <button type="button" class="button wse-emoji-btn" data-emoji="<?php echo esc_attr($emoji); ?>" style="padding:5px;"><?php echo $emoji; ?></button>
                        <?php endforeach; ?>
                    </div>
                </div>

                <?php if (isset($value['desc'])): ?>
                    <p class="description"><?php echo $value['desc']; ?></p>
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }

    public function render_button_field($value) {
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc"></th>
            <td class="forminp">
                <button type="button" id="<?php echo esc_attr($value['id']); ?>" class="<?php echo esc_attr($value['class']); ?>">
                    <?php echo esc_html($value['value']); ?>
                </button>
                <?php if (isset($value['desc'])): echo $value['desc']; endif; ?>
            </td>
        </tr>
        <?php
    }

    public function sanitize_textarea_fields($settings) {
        foreach ($settings as $key => $value) {
            if (strpos($key, '_message') !== false || strpos($key, '_template') !== false) {
                $settings[$key] = sanitize_textarea_field($value);
            }
        }
        return $settings;
    }

    public function enqueue_admin_scripts($hook) {
        if ($hook !== 'woocommerce_page_wc-settings') return;
        if (!isset($_GET['tab']) || $_GET['tab'] !== 'woowapp') return;

        wp_enqueue_style('wse-pro-admin-css', WSE_PRO_URL . 'assets/css/admin.css', [], '1.1');
        wp_enqueue_script('wse-pro-admin-js', WSE_PRO_URL . 'assets/js/admin.js', ['jquery'], '1.1', true);

        wp_localize_script('wse-pro-admin-js', 'wseProAdmin', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wse_pro_test_nonce'),
        ]);
    }
}

new WSE_Pro_Settings();
