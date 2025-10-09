<?php
/**
 * Plugin Name:       WooWApp
 * Plugin URI:        https://smsenlinea.com
 * Description:       Una solución robusta para enviar notificaciones de WhatsApp a los clientes de WooCommerce utilizando la API de SMSenlinea. Incluye recordatorios de reseñas y recuperación de carritos abandonados.
 * Version:           1.6.0
 * Author:            smsenlinea
 * Author URI:        https://smsenlinea.com
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       woowapp-smsenlinea-pro
 * WC requires at least: 3.0.0
 * WC tested up to:   8.1.0
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

define('WSE_PRO_VERSION', '1.6.0');
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
     * Se ejecuta al activar el plugin para crear/actualizar la tabla de carritos abandonados.
     */
    public static function on_activation() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wse_pro_abandoned_carts';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id BIGINT(20) NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            session_id VARCHAR(191) NOT NULL,
            first_name VARCHAR(100) DEFAULT NULL,
            phone VARCHAR(40) NOT NULL,
            cart_contents LONGTEXT NOT NULL,
            cart_total DECIMAL(10, 2) NOT NULL,
            checkout_data LONGTEXT DEFAULT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            scheduled_time BIGINT(20) DEFAULT NULL,
            recovery_token VARCHAR(64) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY recovery_token (recovery_token),
            KEY session_id (session_id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
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
    }

    /**
     * Incluye los archivos de clases del plugin.
     */
    public function includes() {
        require_once WSE_PRO_PATH . 'includes/class-wse-pro-settings.php';
        require_once WSE_PRO_PATH . 'includes/class-wse-pro-api-handler.php';
        require_once WSE_PRO_PATH . 'includes/class-wse-pro-placeholders.php';
    }

    /**
     * Inicializa las clases y registra todos los hooks de acciones.
     */
    public function init_classes() {
        new WSE_Pro_Settings();
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
            add_action('wse_pro_send_abandoned_cart_event', [$this, 'send_abandoned_cart_notification'], 10, 1);
            add_action('template_redirect', [$this, 'handle_cart_recovery_link']);
        }
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
     * Captura el carrito y los datos del formulario vía AJAX.
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
        $existing_cart = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::$abandoned_cart_table_name . " WHERE session_id = %s AND status = 'active'", $session_id));
        $delay_time = (int) get_option('wse_pro_abandoned_cart_time', 60);
        $delay_unit = get_option('wse_pro_abandoned_cart_unit', 'minutes');
        $delay_in_seconds = $delay_unit === 'hours' ? $delay_time * HOUR_IN_SECONDS : $delay_time * MINUTE_IN_SECONDS;
        $scheduled_time = time() + $delay_in_seconds;
        $cart_data = [
            'user_id' => $user_id, 'session_id' => $session_id,
            'first_name' => $first_name, 'phone' => $phone,
            'cart_contents' => $cart_contents, 'cart_total' => $cart_total,
            'checkout_data' => $checkout_data,
            'updated_at' => $current_time, 'scheduled_time' => $scheduled_time,
        ];
        if ($existing_cart && $existing_cart->scheduled_time) {
            wp_unschedule_event($existing_cart->scheduled_time, 'wse_pro_send_abandoned_cart_event', [$existing_cart->id]);
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
        wp_schedule_single_event($scheduled_time, 'wse_pro_send_abandoned_cart_event', [$cart_id]);
        wp_send_json_success(['message' => 'Cart captured.', 'cart_id' => $cart_id]);
    }

    /**
     * Procesa el enlace de recuperación, restaura el carrito y los datos, y redirige al checkout.
     */
    public function handle_cart_recovery_link() {
        if (!isset($_GET['recover-cart-wse'])) return;

        global $wpdb;
        $token = sanitize_text_field($_GET['recover-cart-wse']);
        $cart_row = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::$abandoned_cart_table_name . " WHERE recovery_token = %s AND status IN ('active', 'sent')", $token));
        
        if ($cart_row) {
            // Restaurar productos
            WC()->cart->empty_cart();
            $cart_contents = maybe_unserialize($cart_row->cart_contents);
            if (is_array($cart_contents)) {
                foreach ($cart_contents as $item) {
                    WC()->cart->add_to_cart($item['product_id'], $item['quantity'], $item['variation_id'] ?? 0, $item['variation'] ?? []);
                }
            }

            // --- LÓGICA MEJORADA: Restaurar los datos del formulario en la sesión del cliente ---
            if (!empty($cart_row->checkout_data)) {
                parse_str($cart_row->checkout_data, $checkout_fields);
                $customer = WC()->customer;
                if ($customer && is_array($checkout_fields)) {
                    foreach ($checkout_fields as $key => $value) {
                        // Sanitizar antes de usar
                        $s_key = sanitize_key($key);
                        $s_value = sanitize_text_field(wp_unslash($value));

                        // Usar los métodos 'set' del objeto cliente de WooCommerce
                        if (is_callable([$customer, "set_{$s_key}"])) {
                            $customer->{"set_{$s_key}"}($s_value);
                        }
                    }
                    $customer->save();
                }
            }
            
            $wpdb->update(self::$abandoned_cart_table_name, ['status' => 'recovered'], ['id' => $cart_row->id]);
            wp_safe_redirect(wc_get_checkout_url());
            exit();
        }
    }

    /**
     * Cancela el recordatorio cuando se completa una compra.
     */
    public function cancel_abandoned_cart_reminder($order_id) {
        $session_id = WC()->session->get_customer_id();
        if (empty($session_id)) return;
        
        global $wpdb;
        $active_cart = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::$abandoned_cart_table_name . " WHERE session_id = %s AND status = 'active'", $session_id));

        if ($active_cart) {
            if ($active_cart->scheduled_time) {
                wp_unschedule_event($active_cart->scheduled_time, 'wse_pro_send_abandoned_cart_event', [$active_cart->id]);
            }
            $wpdb->update(self::$abandoned_cart_table_name, ['status' => 'recovered'], ['id' => $active_cart->id]);
        }
    }

    /**
     * Envía la notificación de carrito abandonado.
     */
    public function send_abandoned_cart_notification($cart_id) {
        global $wpdb;
        $cart_row = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::$abandoned_cart_table_name . " WHERE id = %d", $cart_id));
        if (!$cart_row || $cart_row->status !== 'active') return;

        $template = get_option('wse_pro_abandoned_cart_message');
        if (empty($template)) return;

        $api_handler = new WSE_Pro_API_Handler();
        $message = WSE_Pro_Placeholders::replace_for_cart($template, $cart_row);
        $api_handler->send_message($cart_row->phone, $message, $cart_row, 'customer');

        $wpdb->update(self::$abandoned_cart_table_name, ['status' => 'sent'], ['id' => $cart_id]);
    }

    /**
     * Dispara la notificación de cambio de estado.
     */
    public function trigger_status_change_notification($order_id, $order) {
        $api_handler = new WSE_Pro_API_Handler();
        $api_handler->handle_status_change($order_id, $order);
    }

    /**
     * Dispara la notificación de nueva nota.
     */
    public function trigger_new_note_notification($data) {
        $api_handler = new WSE_Pro_API_Handler();
        $api_handler->handle_new_note($data);
    }

    /**
     * Programa el envío del recordatorio de reseña.
     */
    public function schedule_review_reminder($order_id) {
        wp_clear_scheduled_hook('wse_pro_send_review_reminder_event', [$order_id]);
        if ('yes' !== get_option('wse_pro_enable_review_reminder', 'no')) return;

        $delay_days = (int) get_option('wse_pro_review_reminder_days', 7);
        if ($delay_days <= 0) return;
        
        $time_to_send = time() + ($delay_days * DAY_IN_SECONDS);
        wp_schedule_single_event($time_to_send, 'wse_pro_send_review_reminder_event', [$order_id]);
    }

    /**
     * Envía la notificación de recordatorio de reseña.
     */
    public function send_review_reminder_notification($order_id) {
        $order = wc_get_order($order_id);
        if (!$order || $order->get_status() !== 'completed') return;

        $template = get_option('wse_pro_review_reminder_message');
        if (empty($template)) return;

        $api_handler = new WSE_Pro_API_Handler();
        $message = WSE_Pro_Placeholders::replace($template, $order);
        $api_handler->send_message($order->get_billing_phone(), $message, $order, 'customer');
    }

    /**
     * Muestra un aviso si WooCommerce no está activo.
     */
    public function missing_wc_notice() {
        echo '<div class="error"><p><strong>' . esc_html__('WooWApp', 'woowapp-smsenlinea-pro') . ':</strong> ' . esc_html__('Este plugin requiere que WooCommerce esté instalado y activo para funcionar.', 'woowapp-smsenlinea-pro') . '</p></div>';
    }
}

/**
 * Inicia el plugin.
 */
WooWApp::get_instance();