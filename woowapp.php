<?php
/**
 * Plugin Name:       WooWApp
 * Plugin URI:        https://smsenlinea.com
 * Description:       Una solución robusta para enviar notificaciones de WhatsApp a los clientes de WooCommerce utilizando la API de SMSenlinea. Incluye recordatorios de reseñas y recuperación de carritos abandonados.
 * Version:           1.1.0
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

define('WSE_PRO_VERSION', '1.1.0');
define('WSE_PRO_PATH', plugin_dir_path(__FILE__));
define('WSE_PRO_URL', plugin_dir_url(__FILE__));

register_activation_hook(__FILE__, ['WooWApp', 'on_activation']);
register_deactivation_hook(__FILE__, ['WooWApp', 'on_deactivation']);

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
    private static $coupons_table_name;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        global $wpdb;
        self::$abandoned_cart_table_name = $wpdb->prefix . 'wse_pro_abandoned_carts';
        self::$coupons_table_name = $wpdb->prefix . 'wse_pro_coupons_generated';
        add_action('plugins_loaded', [$this, 'init']);
    }

    /**
     * Se ejecuta al activar el plugin
     */
    public static function on_activation() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        // Tabla de carritos abandonados
        $table_name = $wpdb->prefix . 'wse_pro_abandoned_carts';
        $sql_carts = "CREATE TABLE $table_name (
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
            recovery_token VARCHAR(64) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY recovery_token (recovery_token),
            KEY session_id (session_id),
            KEY status (status)
        ) $charset_collate;";
        dbDelta($sql_carts);

        // Tabla de cupones
        require_once WSE_PRO_PATH . 'includes/class-wse-pro-coupon-manager.php';
        WSE_Pro_Coupon_Manager::create_coupons_table();

        // Crear la página de reseñas
        self::create_review_page();
        
        // Programar cron maestro de carritos y limpieza de cupones
        if (!wp_next_scheduled('wse_pro_process_abandoned_carts')) {
            wp_schedule_event(time(), 'five_minutes', 'wse_pro_process_abandoned_carts');
        }
        if (!wp_next_scheduled('wse_pro_cleanup_coupons')) {
            wp_schedule_event(time(), 'daily', 'wse_pro_cleanup_coupons');
        }
        
        flush_rewrite_rules();
    }

    /**
     * Se ejecuta al desactivar el plugin
     */
    public static function on_deactivation() {
        wp_clear_scheduled_hook('wse_pro_process_abandoned_carts');
        wp_clear_scheduled_hook('wse_pro_cleanup_coupons');
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
        } elseif (empty(get_option('wse_pro_review_page_id'))) {
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
        
        // Agregar intervalo de 5 minutos si no existe
        add_filter('cron_schedules', function($schedules){
            if(!isset($schedules['five_minutes'])){
                $schedules['five_minutes'] = [
                    'interval' => 5 * MINUTE_IN_SECONDS,
                    'display'  => __('Cada 5 minutos'),
                ];
            }
            return $schedules;
        });
    }

    /**
     * Verifica si la página de reseñas existe
     */
    public function check_review_page_exists() {
        // ... (código sin cambios)
    }

    /**
     * Incluye los archivos de clases del plugin.
     */
    public function includes() {
        // ... (código sin cambios)
    }

    /**
     * Inicializa las clases y registra todos los hooks.
     */
    public function init_classes() {
        new WSE_Pro_Settings();
        
        if (isset($_GET['action']) && $_GET['action'] === 'recreate_review_page' && is_admin()) {
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
            
            // Hook para el cron maestro
            add_action('wse_pro_process_abandoned_carts', [$this, 'process_abandoned_carts_cron']);
            
            add_action('template_redirect', [$this, 'handle_cart_recovery_link']);
            add_filter('woocommerce_checkout_get_value', [$this, 'populate_checkout_fields'], 10, 2);
        }

        add_action('wse_pro_cleanup_coupons', [WSE_Pro_Coupon_Manager::class, 'cleanup_expired_coupons']);
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
        // ... (código sin cambios)
    }

    /**
     * Captura el carrito y lo guarda en la BD. Ya no programa eventos.
     */
    public function capture_cart_via_ajax() {
        check_ajax_referer('wse_pro_capture_cart_nonce', 'security');
        global $wpdb;
        
        $phone = isset($_POST['phone']) ? sanitize_text_field($_POST['phone']) : '';
        $first_name = isset($_POST['first_name']) ? sanitize_text_field($_POST['first_name']) : '';
        $checkout_data = isset($_POST['checkout_data']) ? wp_unslash($_POST['checkout_data']) : '';
        $cart = WC()->cart;
        
        if (empty($phone) || !$cart || $cart->is_empty()) {
            wp_send_json_error(['message' => 'Teléfono o carrito vacíos.']);
            return;
        }
        
        $session_id = WC()->session->get_customer_id();
        $user_id = get_current_user_id();
        $cart_contents = maybe_serialize($cart->get_cart());
        $cart_total = $cart->get_total('edit');
        $current_time = current_time('mysql');
        
        $existing_cart = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM " . self::$abandoned_cart_table_name . " WHERE session_id = %s AND status = 'active'", 
            $session_id
        ));
        
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
        ];

        if ($existing_cart) {
            $wpdb->update(self::$abandoned_cart_table_name, $cart_data, ['id' => $existing_cart->id]);
            $cart_id = $existing_cart->id;
        } else {
            $cart_data['created_at'] = $current_time;
            $cart_data['recovery_token'] = bin2hex(random_bytes(16));
            $wpdb->insert(self::$abandoned_cart_table_name, $cart_data);
            $cart_id = $wpdb->insert_id;
        }

        if (empty($cart_id)) {
            wp_send_json_error(['message' => 'Error al guardar el carrito.']);
            return;
        }

        wp_send_json_success(['message' => 'Carrito capturado.', 'cart_id' => $cart_id]);
    }
    
    /**
     * Cron Maestro: Se ejecuta cada 5 minutos para procesar carritos pendientes
     */
    public function process_abandoned_carts_cron() {
        global $wpdb;
        $table_name = self::$abandoned_cart_table_name;
        
        $active_carts = $wpdb->get_results(
            "SELECT * FROM $table_name WHERE status = 'active' AND messages_sent != '1,1,1'"
        );

        if (empty($active_carts)) {
            return;
        }

        foreach ($active_carts as $cart) {
            $messages_sent = explode(',', $cart->messages_sent);
            $last_update_time = strtotime($cart->updated_at);

            for ($i = 1; $i <= 3; $i++) {
                if (isset($messages_sent[$i - 1]) && $messages_sent[$i - 1] == '1') {
                    continue;
                }

                if (get_option('wse_pro_abandoned_cart_enable_msg_' . $i, 'no') !== 'yes') {
                    continue;
                }

                $delay = (int) get_option('wse_pro_abandoned_cart_time_' . $i, 60);
                $unit = get_option('wse_pro_abandoned_cart_unit_' . $i, 'minutes');
                $seconds_to_wait = 0;
                switch ($unit) {
                    case 'days': $seconds_to_wait = $delay * DAY_IN_SECONDS; break;
                    case 'hours': $seconds_to_wait = $delay * HOUR_IN_SECONDS; break;
                    default: $seconds_to_wait = $delay * MINUTE_IN_SECONDS; break;
                }

                if (time() >= ($last_update_time + $seconds_to_wait)) {
                    $this->send_abandoned_cart_message($cart, $i);
                    break; 
                }
            }
        }
    }

    /**
     * Envía un mensaje específico de recuperación de carrito
     */
    private function send_abandoned_cart_message($cart_row, $message_number) {
        global $wpdb;
        
        $template = get_option('wse_pro_abandoned_cart_message_' . $message_number);
        if (empty($template)) {
            return;
        }

        $coupon_data = null;
        if (get_option('wse_pro_abandoned_cart_coupon_enable_' . $message_number, 'no') === 'yes') {
            $coupon_manager = new WSE_Pro_Coupon_Manager();
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
                'cart_id'         => $cart_row->id,
                'message_number'  => $message_number,
                'coupon_type'     => 'cart_recovery'
            ]);

            if (!is_wp_error($coupon_result)) {
                $coupon_data = $coupon_result;
            }
        }

        $message = WSE_Pro_Placeholders::replace_for_cart($template, $cart_row, $coupon_data);
        
        $cart_obj = (object) [
            'id' => $cart_row->id,
            'phone' => $cart_row->phone,
            'cart_contents' => $cart_row->cart_contents
        ];
        
        $api_handler = new WSE_Pro_API_Handler();
        $result = $api_handler->send_message($cart_row->phone, $message, $cart_obj, 'customer');

        if ($result['success']) {
            $messages_sent = explode(',', $cart_row->messages_sent);
            $messages_sent[$message_number - 1] = '1';
            $new_status_str = implode(',', $messages_sent);
            
            $update_data = ['messages_sent' => $new_status_str];
            
            if ($message_number == 3 || !$this->has_more_messages_pending($message_number)) {
                $update_data['status'] = 'sent';
            }
            
            $wpdb->update(self::$abandoned_cart_table_name, $update_data, ['id' => $cart_row->id]);
        }
    }
    
    // ... (El resto de las funciones, como `handle_cart_recovery_link`, `cancel_abandoned_cart_reminder`, etc., están completas y correctas en las respuestas anteriores, por lo que las incluyo aquí sin cambios significativos)

    public function handle_cart_recovery_link() {
        if (!isset($_GET['recover-cart-wse'])) return;

        global $wpdb;
        $token = sanitize_text_field($_GET['recover-cart-wse']);
        $cart_row = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::$abandoned_cart_table_name . " WHERE recovery_token = %s", $token));
        
        if ($cart_row) {
            WC()->cart->empty_cart();
            $cart_contents = maybe_unserialize($cart_row->cart_contents);
            if (is_array($cart_contents)) {
                foreach ($cart_contents as $item_key => $item) {
                    WC()->cart->add_to_cart($item['product_id'], $item['quantity'], $item['variation_id'] ?? 0, $item['variation'] ?? []);
                }
            }

            // Aplicar cupón si existe
            $coupon = WSE_Pro_Coupon_Manager::get_coupon_for_cart($cart_row->id);
            if($coupon && !WC()->cart->has_discount($coupon->coupon_code)){
                WC()->cart->apply_coupon($coupon->coupon_code);
            }

            if (!empty($cart_row->checkout_data)) {
                parse_str($cart_row->checkout_data, $checkout_fields);
                WC()->session->set('wse_pro_recovered_checkout_data', $checkout_fields);
            }
            
            $wpdb->update(self::$abandoned_cart_table_name, ['status' => 'recovered'], ['id' => $cart_row->id]);
            wp_safe_redirect(wc_get_checkout_url());
            exit();
        }
    }
    
    public function populate_checkout_fields($value, $input) {
        $recovered_data = WC()->session->get('wse_pro_recovered_checkout_data');
        if ($recovered_data && isset($recovered_data[$input])) {
            // Limpiar la sesión después de usar los datos
            WC()->session->set('wse_pro_recovered_checkout_data', null);
            return $recovered_data[$input];
        }
        return $value;
    }

    public function cancel_abandoned_cart_reminder($order_id) {
        $session_id = WC()->session->get_customer_id();
        if (empty($session_id)) return;
        
        global $wpdb;
        $active_cart = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::$abandoned_cart_table_name . " WHERE session_id = %s AND status = 'active'", $session_id));

        if ($active_cart) {
            $wpdb->update(self::$abandoned_cart_table_name, ['status' => 'recovered'], ['id' => $active_cart->id]);
        }
    }

    private function has_more_messages_pending($current_message) {
        for ($i = $current_message + 1; $i <= 3; $i++) {
            if (get_option('wse_pro_abandoned_cart_enable_msg_' . $i, 'no') === 'yes') {
                return true;
            }
        }
        return false;
    }

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
        if (!$order) return;
        $template = get_option('wse_pro_review_reminder_message');
        if (empty($template)) return;
        $api_handler = new WSE_Pro_API_Handler();
        $message = WSE_Pro_Placeholders::replace($template, $order);
        $api_handler->send_message($order->get_billing_phone(), $message, $order, 'customer');
    }

    public function render_review_form_shortcode($atts) { return $this->get_review_form_html(); }
    public function get_review_form_html(){ /* ... Contenido de la función ... */ }
    public function handle_custom_review_page_content($content){ /* ... Contenido de la función ... */ }
    public function add_manual_review_request_action($actions){ /* ... Contenido de la función ... */ }
    public function process_manual_review_request_action($order){ /* ... Contenido de la función ... */ }
    public function missing_wc_notice() { /* ... Contenido de la función ... */ }
}

WooWApp::get_instance();

