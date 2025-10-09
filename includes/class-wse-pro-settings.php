<?php
/**
 * Maneja la creación de la página de ajustes para WooWApp.
 * @package WooWApp
 * @version 1.8.0 // Version incrementada
 */
if (!defined('ABSPATH')) exit;

class WSE_Pro_Settings {

    // ... (El resto de la clase permanece igual hasta get_administration_settings) ...

    /**
     * Define los ajustes para la pestaña "Administración".
     * @return array
     */
    private function get_administration_settings() {
        $log_url = admin_url('admin.php?page=wc-status&tab=logs');
        $log_handle = WSE_Pro_API_Handler::$log_handle;

        $panel1_docs_url = 'https://documenter.getpostman.com/view/20356708/2s93zB5c3s#intro'; // URL de referencia
        $panel2_login_url = 'https://app.smsenlinea.com/login';

        return [
            ['name' => __('Ajustes de API y Generales', 'woowapp-smsenlinea-pro'), 'type' => 'title', 'id' => 'wse_pro_api_settings_title'],
            
            [
                'name'    => __('Seleccionar API', 'woowapp-smsenlinea-pro'),
                'type'    => 'select',
                'id'      => 'wse_pro_api_panel_selection',
                'options' => [
                    'panel2' => __('API Panel 2 (WhatsApp QR)', 'woowapp-smsenlinea-pro'),
                    'panel1' => __('API Panel 1 (SMS y WhatsApp Clásico)', 'woowapp-smsenlinea-pro'),
                ],
                'desc'    => __('Elige el panel de SMSenlinea que deseas utilizar. Los ajustes cambiarán según tu selección.', 'woowapp-smsenlinea-pro'),
                'desc_tip' => true,
                'default' => 'panel2',
            ],

            // --- CAMPOS PARA API PANEL 2 (WhatsApp QR) ---
            [
                'name' => __('Token de Autenticación (Panel 2)', 'woowapp-smsenlinea-pro'), 
                'type' => 'text', 
                'id' => 'wse_pro_api_token', 
                'css' => 'min-width:300px;',
                'desc' => sprintf(__('Ingresa el token de tu instancia. Inicia sesión en <a href="%s" target="_blank">Panel 2</a> para obtenerlo.', 'woowapp-smsenlinea-pro'), esc_url($panel2_login_url)),
                'custom_attributes' => ['data-panel' => 'panel2'],
            ],
            [
                'name' => __('Número de Remitente (Panel 2)', 'woowapp-smsenlinea-pro'), 
                'type' => 'text', 
                'id' => 'wse_pro_from_number', 
                'desc' => __('Incluye el código de país. Ej: 5211234567890. Debe ser el número de la instancia QR.', 'woowapp-smsenlinea-pro'), 
                'desc_tip' => true,
                'custom_attributes' => ['data-panel' => 'panel2'],
            ],

            // --- NUEVO: CAMPOS CORREGIDOS PARA API PANEL 1 ---
            [
                'name' => __('API Secret (Panel 1)', 'woowapp-smsenlinea-pro'), 
                'type' => 'text', 
                'id' => 'wse_pro_api_secret_panel1', 
                'css' => 'min-width:300px;',
                'desc' => sprintf(__('Copia tu API Secret desde la sección "Herramientas -> API Keys" en el <a href="%s" target="_blank">Panel 1</a>.', 'woowapp-smsenlinea-pro'), esc_url($panel1_docs_url)),
                'custom_attributes' => ['data-panel' => 'panel1'],
            ],
            [
                'name'    => __('Tipo de Mensaje (Panel 1)', 'woowapp-smsenlinea-pro'),
                'type'    => 'select',
                'id'      => 'wse_pro_message_type_panel1',
                'options' => [
                    'whatsapp' => __('WhatsApp', 'woowapp-smsenlinea-pro'),
                    'sms'      => __('SMS', 'woowapp-smsenlinea-pro'),
                ],
                'desc'    => __('Selecciona el tipo de mensaje a enviar. Los campos de abajo cambiarán según tu elección.', 'woowapp-smsenlinea-pro'),
                'desc_tip' => true,
                'default' => 'whatsapp',
                'custom_attributes' => ['data-panel' => 'panel1'],
            ],
            // Campos específicos para WhatsApp (Panel 1)
            [
                'name' => __('WhatsApp Account ID (Panel 1)', 'woowapp-smsenlinea-pro'),
                'type' => 'text',
                'id'   => 'wse_pro_whatsapp_account_panel1',
                'css'  => 'min-width:300px;',
                'desc' => __('El ID único de tu cuenta de WhatsApp. Puedes obtenerlo desde el dashboard del Panel 1 o la API.', 'woowapp-smsenlinea-pro'),
                'desc_tip' => true,
                'custom_attributes' => ['data-panel' => 'panel1', 'data-msg-type' => 'whatsapp'],
            ],
            // Campos específicos para SMS (Panel 1)
             [
                'name'    => __('Modo de Envío SMS (Panel 1)', 'woowapp-smsenlinea-pro'),
                'type'    => 'select',
                'id'      => 'wse_pro_sms_mode_panel1',
                'options' => [
                    'devices' => __('Usar mis dispositivos (devices)', 'woowapp-smsenlinea-pro'),
                    'credits' => __('Usar créditos (credits)', 'woowapp-smsenlinea-pro'),
                ],
                'desc'    => __('"devices" usa tus dispositivos Android; "credits" usa gateways y requiere saldo.', 'woowapp-smsenlinea-pro'),
                'desc_tip' => true,
                'default' => 'devices',
                'custom_attributes' => ['data-panel' => 'panel1', 'data-msg-type' => 'sms'],
            ],
            [
                'name' => __('Device / Gateway ID (Panel 1)', 'woowapp-smsenlinea-pro'),
                'type' => 'text',
                'id'   => 'wse_pro_sms_device_panel1',
                'css'  => 'min-width:300px;',
                'desc' => __('El ID de tu dispositivo (si usas "devices") o el ID del gateway (si usas "credits").', 'woowapp-smsenlinea-pro'),
                'desc_tip' => true,
                'custom_attributes' => ['data-panel' => 'panel1', 'data-msg-type' => 'sms'],
            ],

            // --- AJUSTES GENERALES (Comunes a ambos) ---
            ['name' => __('Código de País Predeterminado', 'woowapp-smsenlinea-pro'), 'type' => 'text', 'id' => 'wse_pro_default_country_code', 'desc' => __('Ej: 57 para Colombia. Usado si el cliente no tiene un país de facturación.', 'woowapp-smsenlinea-pro'), 'desc_tip' => true],
            ['name' => __('Adjuntar Imagen de Producto (Pedidos)', 'woowapp-smsenlinea-pro'), 'type' => 'checkbox', 'id' => 'wse_pro_attach_product_image', 'desc' => __('<strong>Activa esta opción para adjuntar la imagen del producto en los mensajes de estado de pedido.</strong> (Solo para WhatsApp)', 'woowapp-smsenlinea-pro'), 'default' => 'no'],
            ['name' => __('Activar Registro de Actividad (Log)', 'woowapp-smsenlinea-pro'), 'type' => 'checkbox', 'id' => 'wse_pro_enable_log', 'default' => 'yes', 'desc' => sprintf(__('Guarda un registro. Puedes verlo en <a href="%s">WooCommerce > Estado > Registros</a> (busca "<code>%s</code>").', 'woowapp-smsenlinea-pro'), esc_url($log_url), esc_html($log_handle))],
            ['type' => 'sectionend', 'id' => 'wse_pro_api_settings_end'],
            ['name' => __('Prueba de Envío', 'woowapp-smsenlinea-pro'), 'type' => 'title', 'id' => 'wse_pro_test_settings_title'],
            ['name' => __('Número de Destino', 'woowapp-smsenlinea-pro'), 'type' => 'text', 'id' => 'wse_pro_test_number', 'css' => 'min-width:300px;', 'placeholder' => __('Ej: 573001234567', 'woowapp-smsenlinea-pro')],
            ['name' => '', 'type' => 'button', 'id' => 'wse_pro_send_test_button', 'class' => 'button-secondary', 'value' => __('Enviar Mensaje de Prueba', 'woowapp-smsenlinea-pro'), 'desc' => '<span id="test_send_status"></span>'],
            ['type' => 'sectionend', 'id' => 'wse_pro_test_settings_end'],
        ];
    }

    /**
     * Define los ajustes para la pestaña "Mensajes Admin".
     * @return array
     */
    private function get_admin_messages_settings() {
        $settings = [
            ['name' => __('Notificaciones para Administradores', 'woowapp-smsenlinea-pro'), 'type' => 'title', 'id' => 'wse_pro_admin_settings_title', 'desc' => __('Define aquí los números de los administradores y personaliza los mensajes que recibirán con cada cambio de estado.', 'woowapp-smsenlinea-pro')],
            ['name' => __('Números de Administradores', 'woowapp-smsenlinea-pro'), 'type' => 'textarea', 'id' => 'wse_pro_admin_numbers', 'css'  => 'width:100%; height: 100px;', 'desc' => __('Ingresa los números de teléfono, <strong>uno por línea</strong>. Incluye el código de país (Ej: 573001234567).', 'woowapp-smsenlinea-pro'), 'desc_tip' => false],
            ['name' => __('Plantillas de Mensajes para Administradores', 'woowapp-smsenlinea-pro'), 'type' => 'title', 'id' => 'wse_pro_admin_templates_title_sub'],
        ];
        foreach (wc_get_order_statuses() as $slug => $name) {
            $slug_clean = str_replace('wc-', '', $slug);
            $settings['enable_admin_' . $slug_clean] = ['name' => sprintf(__('Activar para: %s', 'woowapp-smsenlinea-pro'), esc_html($name)), 'type' => 'checkbox', 'id' => 'wse_pro_enable_admin_' . $slug_clean, 'default' => 'no'];
            $settings['admin_message_' . $slug_clean] = ['name' => __('Plantilla para Administradores', 'woowapp-smsenlinea-pro'), 'type' => 'textarea_with_pickers', 'id' => 'wse_pro_admin_message_' . $slug_clean, 'css' => 'width:100%; height: 75px;', 'default' => sprintf(__('🔔 Notificación: El pedido #{order_id} de {customer_fullname} ha cambiado su estado a: %s.', 'woowapp-smsenlinea-pro'), esc_html($name))];
        }
        $settings[] = ['type' => 'sectionend', 'id' => 'wse_pro_admin_settings_end'];
        return $settings;
    }

    /**
     * Define los ajustes para la pestaña "Mensajes Cliente".
     * @return array
     */
    private function get_customer_messages_settings() {
        $settings = [['name' => __('Plantillas de Mensajes para Clientes', 'woowapp-smsenlinea-pro'), 'type' => 'title', 'id' => 'wse_pro_notifications_title']];
        $templates = ['note' => ['name' => __('Nueva Nota de Pedido', 'woowapp-smsenlinea-pro'), 'default' => __('Hola {customer_name}, tienes una nueva nota en tu pedido #{order_id}: {note_content}', 'woowapp-smsenlinea-pro')]];
        foreach (wc_get_order_statuses() as $slug => $name) {
            $slug_clean = str_replace('wc-', '', $slug);
            $templates[$slug_clean] = ['name' => $name, 'default' => sprintf(__('Hola {customer_name}, el estado de tu pedido #{order_id} ha cambiado a: %s. ¡Gracias por tu compra!', 'woowapp-smsenlinea-pro'), strtolower($name))];
        }
        foreach($templates as $key => $template) {
            $settings['enable_' . $key] = ['name' => sprintf(__('Activar para: %s', 'woowapp-smsenlinea-pro'), esc_html($template['name'])), 'type' => 'checkbox', 'id' => 'wse_pro_enable_' . $key, 'default' => 'no'];
            $settings['message_' . $key] = ['name' => __('Plantilla de Mensaje', 'woowapp-smsenlinea-pro'), 'type' => 'textarea_with_pickers', 'id' => 'wse_pro_message_' . $key, 'css' => 'width:100%; height: 75px;', 'default' => $template['default']];
        }
        $settings[] = ['type' => 'sectionend', 'id' => 'wse_pro_notifications_end'];
        return $settings;
    }

    /**
     * Define los ajustes para la pestaña "Notificaciones".
     * @return array
     */
    private function get_notifications_settings() {
        return [
            ['name' => __('Recordatorio de Reseña de Producto', 'woowapp-smsenlinea-pro'), 'type' => 'title', 'id' => 'wse_pro_review_reminders_title', 'desc' => __('Envía un mensaje automático para incentivar las reseñas de productos.', 'woowapp-smsenlinea-pro')],
            ['name' => __('Activar recordatorio de reseña', 'woowapp-smsenlinea-pro'), 'type' => 'checkbox', 'id' => 'wse_pro_enable_review_reminder', 'desc' => __('<strong>Activar para enviar solicitudes de reseña automáticamente.</strong>', 'woowapp-smsenlinea-pro'), 'default' => 'no'],
            ['name' => __('Enviar después de', 'woowapp-smsenlinea-pro'), 'type' => 'number', 'id' => 'wse_pro_review_reminder_days', 'desc_tip' => true, 'desc' => __('días. El conteo inicia cuando el pedido se marca como "Completado".', 'woowapp-smsenlinea-pro'), 'custom_attributes' => ['min' => '1'], 'default' => '7'],
            ['name' => __('Plantilla del mensaje de reseña', 'woowapp-smsenlinea-pro'), 'type' => 'textarea_with_pickers', 'id' => 'wse_pro_review_reminder_message', 'css'  => 'width:100%; height: 75px;', 'default' => __('¡Hola {customer_name}! Esperamos que estés disfrutando tu {first_product_name}. ¿Te importaría dejarnos una reseña? Tu opinión es muy importante. Puedes hacerlo aquí: {first_product_review_link}', 'woowapp-smsenlinea-pro')],
            ['type' => 'sectionend', 'id' => 'wse_pro_review_reminders_end'],
            ['name' => __('Recuperación de Carrito Abandonado', 'woowapp-smsenlinea-pro'), 'type' => 'title', 'id' => 'wse_pro_abandoned_cart_title', 'desc' => __('Envía un recordatorio a los clientes que han dejado productos en su carrito.', 'woowapp-smsenlinea-pro')],
            ['name' => __('Activar recuperación de carrito', 'woowapp-smsenlinea-pro'), 'type' => 'checkbox', 'id'   => 'wse_pro_enable_abandoned_cart', 'desc' => __('<strong>Activar para enviar recordatorios de carritos abandonados.</strong>', 'woowapp-smsenlinea-pro'), 'default' => 'no'],
            ['name' => __('Adjuntar imagen del primer producto', 'woowapp-smsenlinea-pro'), 'type' => 'checkbox', 'id'   => 'wse_pro_abandoned_cart_attach_image', 'desc' => __(' Activa esta opción para adjuntar la imagen del primer producto del carrito.', 'woowapp-smsenlinea-pro'), 'default' => 'no'],
            ['name' => __('Enviar después de', 'woowapp-smsenlinea-pro'), 'type' => 'number', 'id'   => 'wse_pro_abandoned_cart_time', 'custom_attributes' => ['min' => '1'], 'default' => '60'],
            ['name' => '', 'type' => 'select', 'id'   => 'wse_pro_abandoned_cart_unit', 'options' => ['minutes' => __('Minutos', 'woowapp-smsenlinea-pro'), 'hours' => __('Horas', 'woowapp-smsenlinea-pro')], 'default' => 'minutes'],
            ['name' => __('Plantilla del mensaje de carrito', 'woowapp-smsenlinea-pro'), 'type' => 'textarea_with_pickers', 'id'   => 'wse_pro_abandoned_cart_message', 'css'  => 'width:100%; height: 75px;', 'default' => __('¡Hola {customer_name}! Notamos que dejaste algunos artículos en tu carrito. ¡No te los pierdas! Completa tu compra aquí: {checkout_link}', 'woowapp-smsenlinea-pro')],
            ['type' => 'sectionend', 'id' => 'wse_pro_abandoned_cart_end'],
        ];
    }
    
    /**
     * Renderiza un campo de texto con el acordeón de variables y emojis al costado.
     *
     * @param array $value El valor del campo.
     */
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
                        <textarea
                            name="<?php echo esc_attr($value['id']); ?>"
                            id="<?php echo esc_attr($value['id']); ?>"
                            style="<?php echo esc_attr($value['css']); ?>"
                        ><?php echo esc_textarea($option_value); ?></textarea>
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

    /**
     * Encola los scripts y estilos CSS necesarios en la página de ajustes.
     *
     * @param string $hook El hook de la página actual.
     */
    public function enqueue_admin_scripts($hook) {
        if ('woocommerce_page_wc-settings' !== $hook) return;
        if (!isset($_GET['tab']) || 'woowapp' !== $_GET['tab']) return;

        wp_enqueue_style('wse-pro-admin-css', WSE_PRO_URL . 'assets/css/admin.css', [], WSE_PRO_VERSION);
        wp_enqueue_script('wse-pro-admin-js', WSE_PRO_URL . 'assets/js/admin.js', ['jquery'], WSE_PRO_VERSION, true);
        wp_localize_script('wse-pro-admin-js', 'wse_pro_admin_params', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('wse_pro_send_test_nonce')
        ]);
    }

}
