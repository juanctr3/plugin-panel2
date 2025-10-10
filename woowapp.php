<?php
/**
 * Plugin Name:       WooWApp
 * Plugin URI:        https://smsenlinea.com
 * Description:       Una solución robusta para enviar notificaciones de WhatsApp a los clientes de WooCommerce utilizando la API de SMSenlinea. Incluye recordatorios de reseñas y recuperación de carritos abandonados.
 * Version:           1.1
 * Author:            smsenlinea
 * Author URI:        https://smsenlinea.com
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       woowapp-smsenlinea-pro
 * WC requires at least: 3.0.0
 * WC tested up to:   8.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

define('WSE_PRO_VERSION', '1.1');
define('WSE_PRO_PATH', plugin_dir_path(__FILE__));
define('WSE_PRO_URL', plugin_dir_url(__FILE__));

register_activation_hook(__FILE__, ['WooWApp', 'on_activation']);

add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

/**
 * Clase principal del Plugin WooWApp.
 */
final class WooWApp {

    private static $instance;
    private static $abandoned_cart_table_name;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        global $wpdb;
        self::$abandoned_cart_table_name = $wpdb->prefix . 'wse_pro_abandoned_carts';
        add_action('plugins_loaded', [$this, 'init']);
    }

    /**
     * Se ejecuta al activar el plugin
     */
    public static function on_activation() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wse_pro_abandoned_carts';
        $charset_collate = $wpdb->get_charset_collate();

        // Tabla de carritos abandonados con campos nuevos
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id BIGINT(20) NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            session_id VARCHAR(191) NOT NULL,
            first_name VARCHAR(100) DEFAULT NULL,
            phone VARCHAR(40) NOT NULL,
            cart_contents LONGTEXT NOT NULL,
            cart_total DECIMAL(10, 2) NOT NULL,
            checkout_data LONGTEXT DEFAULT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            messages_sent VARCHAR(20) DEFAULT '0,0,0',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            scheduled_time_1 BIGINT(20) DEFAULT NULL,
            scheduled_time_2 BIGINT(20) DEFAULT NULL,
            scheduled_time_3 BIGINT(20) DEFAULT NULL,
            recovery_token VARCHAR(64) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY recovery_token (recovery_token),
            KEY session_id (session_id),
            KEY status (status)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        // Crear tabla de cupones
        require_once WSE_PRO_PATH . 'includes/class-wse-pro-coupon-manager.php';
        WSE_Pro_Coupon_Manager::create_coupons_table();

        // Crear la página de reseñas
        self::create_review_page();
        
        // Programar limpieza diaria de cupones expirados
        if (!wp_next_scheduled('wse_pro_cleanup_coupons')) {
            wp_schedule_event(time(), 'daily', 'wse_pro_cleanup_coupons');
        }
        
        flush_rewrite_rules();
    }

    /**
     * Crea la página de reseñas si no existe.
     */
    private static function create_review_page() {
        $review_page_slug = 'escribir-resena';
        $existing_page = get_page_by_path($review_page_slug);
        
        if (null === $existing_page) {
            $page_id = wp_insert_post([
                'post_title'   => __('Escribir Reseña', 'woowapp-smsenlinea-pro'),
                'post_name'    => $review_page_slug,
                'post_status'  => 'publish',
                'post_type'    => 'page',
                'post_content' => '[woowapp_review_form]',
            ]);
            
            if ($page_id && !is_wp_error($page_id)) {
                update_option('wse_pro_review_page_id', $page_id);
            }
        } else {
            update_option('wse_pro_review_page_id', $existing_page->ID);
        }
    }

    /**
     * Inicializador principal del plugin.
     */
    public function init() {
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', [$this, 'missing_wc_notice']);
            return;
        }
        load_plugin_textdomain('woowapp-smsenlinea-pro', false, dirname(plugin_basename(__FILE__)) . '/languages');
        $this->includes();
        $this->init_classes();
        
        add_action('admin_notices', [$this, 'check_review_page_exists']);
    }

    /**
     * Verifica si la página de reseñas existe
     */
    public function check_review_page_exists() {
        $page_id = get_option('wse_pro_review_page_id');
        $page = $page_id ? get_post($page_id) : null;
        
        if (!$page || $page->post_status !== 'publish') {
            $screen = get_current_screen();
            if ($screen && $screen->id === 'woocommerce_page_wc-settings') {
                echo '<div class="notice notice-warning"><p><strong>WooWApp:</strong> ';
                echo __('La página de reseñas no existe o no está publicada. ', 'woowapp-smsenlinea-pro');
                echo '<a href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=woowapp&action=recreate_review_page')) . '">';
                echo __('Haz clic aquí para recrearla', 'woowapp-smsenlinea-pro');
                echo '</a></p></div>';
            }
        }
    }

    /**
     * Incluye los archivos de clases del plugin.
     */
    public function includes() {
        require_once WSE_PRO_PATH . 'includes/class-wse-pro-settings.php';
        require_once WSE_PRO_PATH . 'includes/class-wse-pro-api-handler.php';
        require_once WSE_PRO_PATH . 'includes/class-wse-pro-placeholders.php';
        require_once WSE_PRO_PATH . 'includes/class-wse-pro-coupon-manager.php';
    }

    /**
     * Inicializa las clases y registra todos los hooks.
     */
    public function init_classes() {
        new WSE_Pro_Settings();
        
        // Manejar recreación de página de reseñas
        if (isset($_GET['action']) && $_GET['action'] === 'recreate_review_page' && 
            isset($_GET['page']) && $_GET['page'] === 'wc-settings' &&
            isset($_GET['tab']) && $_GET['tab'] === 'woowapp') {
            self::create_review_page();
            flush_rewrite_rules();
            wp_redirect(admin_url('admin.php?page=wc-settings&tab=woowapp&section=notifications'));
            exit;
        }

        add_action('woocommerce_new_customer_note', [$this, 'trigger_new_note_notification'], 10, 1);
        foreach (array_keys(wc_get_order_statuses()) as $status) {
            add_action('woocommerce_order_status_' . str_replace('wc-', '', $status), [$this, 'trigger_status_change_notification'], 10, 2);
        }
        add_action('woocommerce_order_status_completed', [$this, 'schedule_review_reminder'], 10, 1);
        add_action('wse_pro_send_review_reminder_event', [$this, 'send_review_reminder_notification'], 10, 1);
        
        if ('yes' === get_option('wse_pro_enable_abandoned_cart', 'no')) {
            add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_scripts']);
            add_action('wp_ajax_wse_pro_capture_cart', [$this, 'capture_cart_via_ajax']);
            add_action('wp_ajax_nopriv_wse_pro_capture_cart', [$this, 'capture_cart_via_ajax']);
            add_action('woocommerce_new_order', [$this, 'cancel_abandoned_cart_reminder'], 10, 1);
            
            // NUEVO: Múltiples eventos para los 3 mensajes
            add_action('wse_pro_send_abandoned_cart_1', [$this, 'send_abandoned_cart_message_1'], 10, 1);
            add_action('wse_pro_send_abandoned_cart_2', [$this, 'send_abandoned_cart_message_2'], 10, 1);
            add_action('wse_pro_send_abandoned_cart_3', [$this, 'send_abandoned_cart_message_3'], 10, 1);
            
            add_action('template_redirect', [$this, 'handle_cart_recovery_link']);
            add_filter('woocommerce_checkout_get_value', [$this, 'populate_checkout_fields'], 10, 2);
        }

        // Limpieza de cupones
        add_action('wse_pro_cleanup_coupons', [WSE_Pro_Coupon_Manager::class, 'cleanup_expired_coupons']);
        
        // Tracking de uso de cupones
        add_action('woocommerce_checkout_order_processed', [WSE_Pro_Coupon_Manager::class, 'track_coupon_usage'], 10, 1);

        add_shortcode('woowapp_review_form', [$this, 'render_review_form_shortcode']);
        add_filter('the_content', [$this, 'handle_custom_review_page_content']);
        add_filter('woocommerce_order_actions', [$this, 'add_manual_review_request_action']);
        add_action('woocommerce_order_action_wse_send_review_request', [$this, 'process_manual_review_request_action']);
    }

    /**
     * Carga el script de JS solo en la página de pago.
     */
    public function enqueue_frontend_scripts() {
        if (is_checkout()) {
            wp_enqueue_script('wse-pro-frontend-js', WSE_PRO_URL . 'assets/js/frontend.js', ['jquery'], WSE_PRO_VERSION, true);
            wp_localize_script('wse-pro-frontend-js', 'wse_pro_frontend_params', [
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce'    => wp_create_nonce('wse_pro_capture_cart_nonce')
            ]);
        }
    }

    /**
     * Captura el carrito y programa los 3 mensajes
     */
    public function capture_cart_via_ajax() {
        check_ajax_referer('wse_pro_capture_cart_nonce', 'security');
        global $wpdb;
        
        $phone = isset($_POST['phone']) ? sanitize_text_field($_POST['phone']) : '';
        $first_name = isset($_POST['first_name']) ? sanitize_text_field($_POST['first_name']) : '';
        $checkout_data = isset($_POST['checkout_data']) ? wp_unslash($_POST['checkout_data']) : '';
        $cart = WC()->cart;
        
        if (empty($phone) || !$cart || $cart->is_empty()) {
            wp_send_json_error(['message' => 'Phone or cart empty.']);
            return;
        }
        
        $session_id = WC()->session->get_customer_id();
        $user_id = get_current_user_id();
        $cart_contents = maybe_serialize($cart->get_cart());
        $cart_total = $cart->get_total('edit');
        $current_time = current_time('mysql');
        
        $existing_cart = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::$abandoned_cart_table_name . " WHERE session_id = %s AND status = 'active'", 
            $session_id
        ));

        // Programar los 3 mensajes
        $scheduled_times = $this->calculate_scheduled_times();
        
        $cart_data = [
            'user_id'          => $user_id,
            'session_id'       => $session_id,
            'first_name'       => $first_name,
            'phone'            => $phone,
            'cart_contents'    => $cart_contents,
            'cart_total'       => $cart_total,
            'checkout_data'    => $checkout_data,
            'updated_at'       => $current_time,
            'messages_sent'    => '0,0,0',
            'scheduled_time_1' => $scheduled_times[1],
            'scheduled_time_2' => $scheduled_times[2],
            'scheduled_time_3' => $scheduled_times[3],
        ];

        // Cancelar eventos anteriores si existen
        if ($existing_cart) {
            for ($i = 1; $i <= 3; $i++) {
                $time_field = 'scheduled_time_' . $i;
                if ($existing_cart->$time_field) {
                    wp_unschedule_event($existing_cart->$time_field, 'wse_pro_send_abandoned_cart_' . $i, [$existing_cart->id]);
                }
            }
        }

        if ($existing_cart) {
            $wpdb->update(self::$abandoned_cart_table_name, $cart_data, ['id' => $existing_cart->id]);
            $cart_id = $existing_cart->id;
        } else {
            $cart_data['created_at'] = $current_time;
            $cart_data['recovery_token'] = bin2hex(random_bytes(16));
            $wpdb->insert(self::$abandoned_cart_table_name, $cart_data);
            $cart_id = $wpdb->insert_id;
        }

        // Programar los 3 mensajes
        for ($i = 1; $i <= 3; $i++) {
            if (get_option('wse_pro_abandoned_cart_enable_msg_' . $i, 'no') === 'yes' && $scheduled_times[$i]) {
                wp_schedule_single_event($scheduled_times[$i], 'wse_pro_send_abandoned_cart_' . $i, [$cart_id]);
            }
        }

        wp_send_json_success(['message' => 'Cart captured.', 'cart_id' => $cart_id]);
    }

    /**
     * Calcula los tiempos programados para los 3 mensajes
     *
     * @return array Array con los timestamps
     */
    private function calculate_scheduled_times() {
        $times = [1 => null, 2 => null, 3 => null];
        $now = time();

        for ($i = 1; $i <= 3; $i++) {
            if (get_option('wse_pro_abandoned_cart_enable_msg_' . $i, 'no') === 'yes') {
                $delay = (int) get_option('wse_pro_abandoned_cart_time_' . $i, 60);
                $unit = get_option('wse_pro_abandoned_cart_unit_' . $i, 'minutes');
                
                $seconds = $unit === 'hours' ? $delay * HOUR_IN_SECONDS : $delay * MINUTE_IN_SECONDS;
                $times[$i] = $now + $seconds;
            }
        }

        return $times;
    }

    /**
     * Envía el mensaje 1 de recuperación
     */
    public function send_abandoned_cart_message_1($cart_id) {
        $this->send_abandoned_cart_message($cart_id, 1);
    }

    /**
     * Envía el mensaje 2 de recuperación
     */
    public function send_abandoned_cart_message_2($cart_id) {
        $this->send_abandoned_cart_message($cart_id, 2);
    }

    /**
     * Envía el mensaje 3 de recuperación
     */
    public function send_abandoned_cart_message_3($cart_id) {
        $this->send_abandoned_cart_message($cart_id, 3);
    }

    /**
     * Envía un mensaje específico de recuperación de carrito
     *
     * @param int $cart_id ID del carrito
     * @param int $message_number Número de mensaje (1, 2 o 3)
     */
    private function send_abandoned_cart_message($cart_id, $message_number) {
        global $wpdb;
        
        $cart_row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::$abandoned_cart_table_name . " WHERE id = %d", 
            $cart_id
        ));

        if (!$cart_row || $cart_row->status !== 'active') {
            return;
        }

        // Verificar que este mensaje no se haya enviado ya
        $messages_sent = explode(',', $cart_row->messages_sent);
        if ($messages_sent[$message_number - 1] == '1') {
            return; // Ya se envió este mensaje
        }

        $template = get_option('wse_pro_abandoned_cart_message_' . $message_number);
        if (empty($template)) {
            return;
        }

        // Generar cupón si está habilitado
        $coupon_data = null;
        if (get_option('wse_pro_abandoned_cart_coupon_enable_' . $message_number, 'no') === 'yes') {
            $coupon_manager = new WSE_Pro_Coupon_Manager();
            
            // Extraer email del checkout_data si existe
            $customer_email = '';
            if (!empty($cart_row->checkout_data)) {
                parse_str($cart_row->checkout_data, $checkout_fields);
                $customer_email = $checkout_fields['billing_email'] ?? '';
            }

            $coupon_result = $coupon_manager->generate_coupon([
                'discount_type'   => get_option('wse_pro_abandoned_cart_coupon_type_' . $message_number, 'percent'),
                'discount_amount' => (float) get_option('wse_pro_abandoned_cart_coupon_amount_' . $message_number, 10),
                'expiry_days'     => (int) get_option('wse_pro_abandoned_cart_coupon_expiry_' . $message_number, 7),
                'customer_phone'  => $cart_row->phone,
                'customer_email'  => $customer_email,
                'cart_id'         => $cart_id,
                'message_number'  => $message_number,
                'coupon_type'     => 'cart_recovery'
            ]);

            if (!is_wp_error($coupon_result)) {
                $coupon_data = $coupon_result;
            }
        }

        $api_handler = new WSE_Pro_API_Handler();
        $message = WSE_Pro_Placeholders::replace_for_cart($template, $cart_row, $coupon_data);
        $api_handler->send_message($cart_row->phone, $message, $cart_row, 'customer');

        // Marcar mensaje como enviado
        $messages_sent[$message_number - 1] = '1';
        $new_status = implode(',', $messages_sent);
        
        $wpdb->update(
            self::$abandoned_cart_table_name,
            ['messages_sent' => $new_status],
            ['id' => $cart_id]
        );

        // Si es el último mensaje, cambiar status a 'sent'
        if ($message_number == 3 || !$this->has_more_messages_pending($message_number)) {
            $wpdb->update(
                self::$abandoned_cart_table_name,
                ['status' => 'sent'],
                ['id' => $cart_id]
            );
        }
    }

    /**
     * Verifica si hay más mensajes pendientes después del actual
     */
    private function has_more_messages_pending($current_message) {
        for ($i = $current_message + 1; $i <= 3; $i++) {
            if (get_option('wse_pro_abandoned_cart_enable_msg_' . $i, 'no') === 'yes') {
                return true;
            }
        }
        return false;
    }

    /**
     * Procesa el enlace de recuperación
     */
    public function handle_cart_recovery_link() {
        if (!isset($_GET['recover-cart-wse'])) return;

        global $wpdb;
        $token = sanitize_text_field($_GET['recover-cart-wse']);
        $cart_row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::$abandoned_cart_table_name . " WHERE recovery_token = %s AND status IN ('active', 'sent')", 
            $token
        ));
        
        if (!$cart_row) {
            return;
        }

        WC()->cart->empty_cart();
        $cart_contents = maybe_unserialize($cart_row->cart_contents);
        if (is_array($cart_contents)) {
            foreach ($cart_contents as $item_key => $item) {
                WC()->cart->add_to_cart(
                    $item['product_id'], 
                    $item['quantity'], 
                    $item['variation_id'] ?? 0, 
                    $item['variation'] ?? []
                );
            }
        }

        if (!empty($cart_row->checkout_data)) {
            parse_str($cart_row->checkout_data, $checkout_fields);
            
            if (is_array($checkout_fields) && !empty($checkout_fields)) {
                WC()->session->set('wse_pro_recovered_checkout_data', $checkout_fields);
                
                $customer = WC()->customer;
                if ($customer) {
                    $billing_fields = [
                        'first_name', 'last_name', 'company', 'address_1', 'address_2',
                        'city', 'state', 'postcode', 'country', 'email', 'phone'
                    ];
                    
                    foreach ($billing_fields as $field) {
                        $key = 'billing_' . $field;
                        if (isset($checkout_fields[$key]) && !empty($checkout_fields[$key])) {
                            $value = is_array($checkout_fields[$key]) 
                                ? array_map('sanitize_text_field', $checkout_fields[$key]) 
                                : sanitize_text_field($checkout_fields[$key]);
                            
                            $setter = 'set_billing_' . $field;
                            if (is_callable([$customer, $setter])) {
                                $customer->$setter($value);
                            }
                        }
                    }
                    
                    $shipping_fields = [
                        'first_name', 'last_name', 'company', 'address_1', 'address_2',
                        'city', 'state', 'postcode', 'country'
                    ];
                    
                    foreach ($shipping_fields as $field) {
                        $key = 'shipping_' . $field;
                        if (isset($checkout_fields[$key]) && !empty($checkout_fields[$key])) {
                            $value = is_array($checkout_fields[$key]) 
                                ? array_map('sanitize_text_field', $checkout_fields[$key]) 
                                : sanitize_text_field($checkout_fields[$key]);
                            
                            $setter = 'set_shipping_' . $field;
                            if (is_callable([$customer, $setter])) {
                                $customer->$setter($value);
                            }
                        }
                    }
                    
                    $customer->save();
                }
            }
        }
        
        $wpdb->update(
            self::$abandoned_cart_table_name, 
            ['status' => 'recovered'], 
            ['id' => $cart_row->id]
        );
        
        wp_safe_redirect(wc_get_checkout_url());
        exit();
    }

    /**
     * Rellena campos del checkout con datos guardados
     */
    public function populate_checkout_fields($value, $input) {
        $recovered_data = WC()->session->get('wse_pro_recovered_checkout_data');
        
        if (!$recovered_data || !is_array($recovered_data)) {
            return $value;
        }
        
        if (isset($recovered_data[$input]) && !empty($recovered_data[$input])) {
            return is_array($recovered_data[$input]) 
                ? array_map('sanitize_text_field', $recovered_data[$input])
                : sanitize_text_field($recovered_data[$input]);
        }
        
        return $value;
    }

    /**
     * Cancela recordatorios cuando se completa una compra
     */
    public function cancel_abandoned_cart_reminder($order_id) {
        $session_id = WC()->session->get_customer_id();
        if (empty($session_id)) return;
        
        global $wpdb;
        $active_cart = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::$abandoned_cart_table_name . " WHERE session_id = %s AND status = 'active'", 
            $session_id
        ));

        if ($active_cart) {
            // Cancelar los 3 eventos programados
            for ($i = 1; $i <= 3; $i++) {
                $time_field = 'scheduled_time_' . $i;
                if ($active_cart->$time_field) {
                    wp_unschedule_event($active_cart->$time_field, 'wse_pro_send_abandoned_cart_' . $i, [$active_cart->id]);
                }
            }
            
            $wpdb->update(
                self::$abandoned_cart_table_name, 
                ['status' => 'recovered'], 
                ['id' => $active_cart->id]
            );
        }
        
        if (WC()->session) {
            WC()->session->set('wse_pro_recovered_checkout_data', null);
        }
    }

    // [El resto de los métodos permanecen igual]
    
    public function trigger_status_change_notification($order_id, $order) {
        $api_handler = new WSE_Pro_API_Handler();
        $api_handler->handle_status_change($order_id, $order);
    }

    public function trigger_new_note_notification($data) {
        $api_handler = new WSE_Pro_API_Handler();
        $api_handler->handle_new_note($data);
    }

    public function schedule_review_reminder($order_id) {
        wp_clear_scheduled_hook('wse_pro_send_review_reminder_event', [$order_id]);
        if ('yes' !== get_option('wse_pro_enable_review_reminder', 'no')) return;

        $delay_days = (int) get_option('wse_pro_review_reminder_days', 7);
        if ($delay_days <= 0) return;
        
        $time_to_send = time() + ($delay_days * DAY_IN_SECONDS);
        wp_schedule_single_event($time_to_send, 'wse_pro_send_review_reminder_event', [$order_id]);
    }

    public function send_review_reminder_notification($order_id) {
        $order = wc_get_order($order_id);
        if (!$order || ($order->get_status() !== 'completed' && $order_id > 0)) return;

        $template = get_option('wse_pro_review_reminder_message');
        if (empty($template)) return;

        $api_handler = new WSE_Pro_API_Handler();
        $message = WSE_Pro_Placeholders::replace($template, $order);
        $api_handler->send_message($order->get_billing_phone(), $message, $order, 'customer');
    }

    public function render_review_form_shortcode($atts) {
        return $this->get_review_form_html();
    }

    private function get_review_form_html() {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['wse_review_nonce'])) {
            if (!wp_verify_nonce($_POST['wse_review_nonce'], 'wse_submit_review')) {
                return '<div class="woocommerce-error">' . __('Error de seguridad. Inténtalo de nuevo.', 'woowapp-smsenlinea-pro') . '</div>';
            }

            $order_id = isset($_POST['review_order_id']) ? absint($_POST['review_order_id']) : 0;
            $product_id = isset($_POST['review_product_id']) ? absint($_POST['review_product_id']) : 0;
            $rating = isset($_POST['review_rating']) ? absint($_POST['review_rating']) : 5;
            $comment_text = isset($_POST['review_comment']) ? sanitize_textarea_field($_POST['review_comment']) : '';
            
            $order = wc_get_order($order_id);
            if (!$order) {
                return '<div class="woocommerce-error">' . __('Pedido no válido.', 'woowapp-smsenlinea-pro') . '</div>';
            }

            $commentdata = [
                'comment_post_ID'      => $product_id,
                'comment_author'       => $order->get_billing_first_name(),
                'comment_author_email' => $order->get_billing_email(),
                'comment_content'      => $comment_text,
                'user_id'              => $order->get_user_id() ?: 0,
                'comment_approved'     => 0,
                'comment_type'         => 'review',
            ];
            
            $comment_id = wp_insert_comment($commentdata);

            if ($comment_id) {
                add_comment_meta($comment_id, 'rating', $rating);
                return '<div class="woocommerce-message">' . __('¡Gracias por tu reseña! Ha sido enviada y será publicada tras la aprobación.', 'woowapp-smsenlinea-pro') . '</div>';
            } else {
                return '<div class="woocommerce-error">' . __('Hubo un error al enviar tu reseña.', 'woowapp-smsenlinea-pro') . '</div>';
            }
        }

        $order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
        $order_key = isset($_GET['key']) ? sanitize_text_field($_GET['key']) : '';
        
        if ($order_id > 0 && !empty($order_key)) {
            $order = wc_get_order($order_id);

            if ($order && $order->get_order_key() === $order_key) {
                $html = '<div class="woowapp-review-container">';
                $html .= '<h3>' . __('Deja una reseña para los productos de tu pedido #', 'woowapp-smsenlinea-pro') . $order->get_order_number() . '</h3>';
                
                foreach ($order->get_items() as $item) {
                    $product = $item->get_product();
                    if (!$product) continue;
                    
                    $html .= '<div class="review-form-wrapper" style="border:1px solid #ddd; padding:20px; margin-bottom:20px; border-radius: 5px;">';
                    $html .= '<h4>' . esc_html($product->get_name()) . '</h4>';
                    $html .= '<form method="post" class="woowapp-review-form">';
                    $html .= '<p class="comment-form-rating"><label for="review_rating-' . $product->get_id() . '">' . __('Tu calificación', 'woowapp-smsenlinea-pro') . '&nbsp;<span class="required">*</span></label><select name="review_rating" id="review_rating-' . $product->get_id() . '" required><option value="5">★★★★★</option><option value="4">★★★★☆</option><option value="3">★★★☆☆</option><option value="2">★★☆☆☆</option><option value="1">★☆☆☆☆</option></select></p>';
                    $html .= '<p class="comment-form-comment"><label for="review_comment-' . $product->get_id() . '">' . __('Tu reseña', 'woowapp-smsenlinea-pro') . '</label><textarea name="review_comment" id="review_comment-' . $product->get_id() . '" cols="45" rows="8"></textarea></p>';
                    $html .= '<input type="hidden" name="review_order_id" value="' . esc_attr($order_id) . '" />';
                    $html .= '<input type="hidden" name="review_product_id" value="' . esc_attr($product->get_id()) . '" />';
                    $html .= wp_nonce_field('wse_submit_review', 'wse_review_nonce', true, false);
                    $html .= '<p class="form-submit"><input name="submit" type="submit" class="submit button" value="' . __('Enviar Reseña', 'woowapp-smsenlinea-pro') . '" /></p>';
                    $html .= '</form></div>';
                }
                $html .= '</div>';
                return $html;
            }
        }

        return '<div class="woocommerce-error">' . __('Enlace de reseña no válido o caducado.', 'woowapp-smsenlinea-pro') . '</div>';
    }

    public function handle_custom_review_page_content($content) {
        $page_id = get_option('wse_pro_review_page_id');
        
        if (!is_page($page_id)) {
            return $content;
        }

        if (has_shortcode($content, 'woowapp_review_form')) {
            return $content;
        }

        return $content . $this->get_review_form_html();
    }

    public function add_manual_review_request_action($actions) {
        $actions['wse_send_review_request'] = __('Enviar solicitud de reseña por WhatsApp/SMS', 'woowapp-smsenlinea-pro');
        return $actions;
    }

    public function process_manual_review_request_action($order) {
        $template = get_option('wse_pro_review_reminder_message');
        if (!empty($template)) {
            $api_handler = new WSE_Pro_API_Handler();
            $message = WSE_Pro_Placeholders::replace($template, $order);
            $api_handler->send_message($order->get_billing_phone(), $message, $order, 'customer');

            $order->add_order_note(
                __('Solicitud de reseña enviada manualmente al cliente.', 'woowapp-smsenlinea-pro')
            );
        } else {
            $order->add_order_note(
                __('Fallo al enviar solicitud de reseña: La plantilla de mensaje está vacía.', 'woowapp-smsenlinea-pro')
            );
        }
    }

    public function missing_wc_notice() {
        echo '<div class="error"><p><strong>' . esc_html__('WooWApp', 'woowapp-smsenlinea-pro') . ':</strong> ' . esc_html__('Este plugin requiere que WooCommerce esté instalado y activo para funcionar.', 'woowapp-smsenlinea-pro') . '</p></div>';
    }
}

WooWApp::get_instance();
