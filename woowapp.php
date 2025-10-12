<?php
/**
 * Plugin Name:       WooWApp
 * Plugin URI:        https://smsenlinea.com
 * Description:       Una solución robusta para enviar notificaciones de WhatsApp a los clientes de WooCommerce utilizando la API de SMSenlinea. Incluye recordatorios de reseñas y recuperación de carritos abandonados con cupones personalizables.
 * Version:           2.2.1
 * Author:            smsenlinea
 * Author URI:        https://smsenlinea.com
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       woowapp-smsenlinea-pro
 * Domain Path:       /languages
 * WC requires at least: 3.0.0
 * WC tested up to:   8.5.0
 * 
 * CHANGELOG v2.2.1:
 * - Sistema de recuperación de carrito robusto con manejo de errores
 * - Soporte para prefijos personalizables en cupones
 * - Restauración completa de datos del usuario en checkout
 * - Sistema de migración automática de base de datos
 * - Logging mejorado para diagnóstico
 * - Validaciones reforzadas en todos los procesos
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Constantes del plugin
define('WSE_PRO_VERSION', '2.2.1');
define('WSE_PRO_DB_VERSION', '2.2.1');
define('WSE_PRO_PATH', plugin_dir_path(__FILE__));
define('WSE_PRO_URL', plugin_dir_url(__FILE__));

// Hooks de activación y desactivación
register_activation_hook(__FILE__, ['WooWApp', 'on_activation']);
register_deactivation_hook(__FILE__, ['WooWApp', 'on_deactivation']);

// Declarar compatibilidad con HPOS (High-Performance Order Storage)
add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            __FILE__,
            true
        );
    }
});

/**
 * Clase principal del Plugin WooWApp
 * 
 * Maneja toda la inicialización, hooks y funcionalidad principal del plugin
 */
final class WooWApp {

    /**
     * Instancia singleton
     * @var WooWApp
     */
    private static $instance;

    /**
     * Nombre de la tabla de carritos abandonados
     * @var string
     */
    private static $abandoned_cart_table_name;

    /**
     * Obtiene la instancia singleton
     * 
     * @return WooWApp
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor privado (patrón singleton)
     */
    private function __construct() {
        global $wpdb;
        self::$abandoned_cart_table_name = $wpdb->prefix . 'wse_pro_abandoned_carts';
        
        add_action('plugins_loaded', [$this, 'init']);
        add_action('plugins_loaded', [$this, 'maybe_upgrade_database'], 5);
    }

    /**
     * ========================================
     * ACTIVACIÓN Y CONFIGURACIÓN INICIAL
     * ========================================
     */

    /**
     * Se ejecuta al activar el plugin
     */
    public static function on_activation() {
        // Crear tablas con estructura correcta
        self::create_database_tables();
        
        // Crear página de reseñas
        self::create_review_page();
        
        // Programar crons
        self::schedule_cron_events();
        
        // Guardar versión de BD
        update_option('wse_pro_db_version', WSE_PRO_DB_VERSION);
        
        // Refrescar permalinks
        flush_rewrite_rules();
        
        // Log de activación
        if (function_exists('wc_get_logger')) {
            wc_get_logger()->info(
                'WooWApp v' . WSE_PRO_VERSION . ' activado correctamente',
                ['source' => 'woowapp-' . date('Y-m-d')]
            );
        }
    }

    /**
     * Crea las tablas de base de datos con estructura v2.2.1
     */
    private static function create_database_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        $table_name = $wpdb->prefix . 'wse_pro_abandoned_carts';
        $coupons_table = $wpdb->prefix . 'wse_pro_coupons_generated';

        // ===== TABLA DE CARRITOS ABANDONADOS =====
        // ESTRUCTURA v2.2.1: Incluye campos de billing para restauración
        $sql_carts = "CREATE TABLE IF NOT EXISTS $table_name (
            id BIGINT(20) NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            session_id VARCHAR(191) NOT NULL,
            first_name VARCHAR(100) DEFAULT NULL,
            phone VARCHAR(40) NOT NULL,
            cart_contents LONGTEXT NOT NULL,
            cart_total DECIMAL(10, 2) NOT NULL,
            checkout_data LONGTEXT DEFAULT NULL,
            billing_first_name VARCHAR(100) DEFAULT '',
            billing_last_name VARCHAR(100) DEFAULT '',
            billing_email VARCHAR(255) DEFAULT '',
            billing_phone VARCHAR(50) DEFAULT '',
            billing_address_1 VARCHAR(255) DEFAULT '',
            billing_city VARCHAR(100) DEFAULT '',
            billing_state VARCHAR(100) DEFAULT '',
            billing_postcode VARCHAR(20) DEFAULT '',
            billing_country VARCHAR(2) DEFAULT '',
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            messages_sent VARCHAR(20) DEFAULT '0,0,0',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            recovery_token VARCHAR(64) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY recovery_token (recovery_token),
            KEY session_id (session_id),
            KEY phone (phone),
            KEY billing_email (billing_email),
            KEY status (status),
            KEY updated_at (updated_at),
            KEY created_at (created_at)
        ) $charset_collate;";

        // ===== TABLA DE CUPONES =====
        $sql_coupons = "CREATE TABLE IF NOT EXISTS $coupons_table (
            id BIGINT(20) NOT NULL AUTO_INCREMENT,
            coupon_code VARCHAR(50) NOT NULL,
            customer_phone VARCHAR(40) DEFAULT NULL,
            customer_email VARCHAR(100) DEFAULT NULL,
            cart_id BIGINT(20) DEFAULT NULL,
            order_id BIGINT(20) DEFAULT NULL,
            message_number INT DEFAULT 0,
            coupon_type VARCHAR(20) NOT NULL,
            discount_type VARCHAR(20) NOT NULL,
            discount_amount DECIMAL(10,2) NOT NULL,
            usage_limit INT DEFAULT 1,
            used TINYINT DEFAULT 0,
            created_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY coupon_code (coupon_code),
            KEY customer_phone (customer_phone),
            KEY customer_email (customer_email),
            KEY cart_id (cart_id),
            KEY order_id (order_id),
            KEY used (used),
            KEY expires_at (expires_at)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql_carts);
        dbDelta($sql_coupons);
    }

    /**
     * Verifica y actualiza la base de datos si es necesario
     * Se ejecuta en cada carga para sitios existentes
     */
    public function maybe_upgrade_database() {
        $current_db_version = get_option('wse_pro_db_version', '0');
        
        // Si la versión de BD no coincide, ejecutar migración
        if (version_compare($current_db_version, WSE_PRO_DB_VERSION, '<')) {
            $this->upgrade_database($current_db_version);
            update_option('wse_pro_db_version', WSE_PRO_DB_VERSION);
            
            if (function_exists('wc_get_logger')) {
                wc_get_logger()->info(
                    "Base de datos migrada de v{$current_db_version} a v" . WSE_PRO_DB_VERSION,
                    ['source' => 'woowapp-' . date('Y-m-d')]
                );
            }
        }
        
        // Verificar que el cron esté programado
        $this->ensure_cron_scheduled();
    }

    /**
     * Ejecuta las migraciones necesarias según la versión anterior
     */
    private function upgrade_database($from_version) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wse_pro_abandoned_carts';
        
        // Verificar que la tabla existe
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
        
        if (!$table_exists) {
            // Si la tabla no existe, crearla con estructura correcta
            self::create_database_tables();
            return;
        }

        // Obtener columnas actuales
        $columns = $wpdb->get_results("DESCRIBE $table_name");
        $column_names = array_column($columns, 'Field');
        
        // MIGRACIÓN: Agregar messages_sent si no existe
        if (!in_array('messages_sent', $column_names)) {
            $wpdb->query(
                "ALTER TABLE $table_name 
                 ADD COLUMN messages_sent VARCHAR(20) DEFAULT '0,0,0' 
                 AFTER status"
            );
            
            // Inicializar carritos existentes
            $wpdb->query(
                "UPDATE $table_name 
                 SET messages_sent = '0,0,0' 
                 WHERE messages_sent IS NULL OR messages_sent = ''"
            );
        }
        
        // MIGRACIÓN: Agregar campos de billing si no existen
        $billing_fields = [
            'billing_first_name' => "VARCHAR(100) DEFAULT '' AFTER checkout_data",
            'billing_last_name' => "VARCHAR(100) DEFAULT '' AFTER billing_first_name",
            'billing_email' => "VARCHAR(255) DEFAULT '' AFTER billing_last_name",
            'billing_phone' => "VARCHAR(50) DEFAULT '' AFTER billing_email",
            'billing_address_1' => "VARCHAR(255) DEFAULT '' AFTER billing_phone",
            'billing_city' => "VARCHAR(100) DEFAULT '' AFTER billing_address_1",
            'billing_state' => "VARCHAR(100) DEFAULT '' AFTER billing_city",
            'billing_postcode' => "VARCHAR(20) DEFAULT '' AFTER billing_state",
            'billing_country' => "VARCHAR(2) DEFAULT '' AFTER billing_postcode",
        ];
        
        foreach ($billing_fields as $field => $definition) {
            if (!in_array($field, $column_names)) {
                $wpdb->query("ALTER TABLE $table_name ADD COLUMN $field $definition");
            }
        }
        
        // MIGRACIÓN: Eliminar scheduled_time si existe (columna obsoleta)
        if (in_array('scheduled_time', $column_names)) {
            $wpdb->query("ALTER TABLE $table_name DROP COLUMN scheduled_time");
        }
        
        // MIGRACIÓN: Agregar índices si no existen
        $indexes = $wpdb->get_results("SHOW INDEX FROM $table_name");
        $index_names = array_column($indexes, 'Key_name');
        
        if (!in_array('updated_at', $index_names)) {
            $wpdb->query("ALTER TABLE $table_name ADD KEY updated_at (updated_at)");
        }
        
        if (!in_array('phone', $index_names)) {
            $wpdb->query("ALTER TABLE $table_name ADD KEY phone (phone)");
        }
        
        if (!in_array('billing_email', $index_names)) {
            $wpdb->query("ALTER TABLE $table_name ADD KEY billing_email (billing_email)");
        }
    }

    /**
     * Asegura que los cron jobs estén programados
     */
    private function ensure_cron_scheduled() {
        // Registrar intervalo personalizado
        add_filter('cron_schedules', function($schedules) {
            if (!isset($schedules['five_minutes'])) {
                $schedules['five_minutes'] = [
                    'interval' => 5 * MINUTE_IN_SECONDS,
                    'display'  => __('Cada 5 minutos', 'woowapp-smsenlinea-pro')
                ];
            }
            return $schedules;
        });
        
        // Programar eventos si no están programados
        if (!wp_next_scheduled('wse_pro_process_abandoned_carts')) {
            wp_schedule_event(time(), 'five_minutes', 'wse_pro_process_abandoned_carts');
        }
        
        if (!wp_next_scheduled('wse_pro_cleanup_coupons')) {
            wp_schedule_event(time(), 'daily', 'wse_pro_cleanup_coupons');
        }
    }

    /**
     * Programa los eventos cron iniciales
     */
    private static function schedule_cron_events() {
        // Limpiar eventos anteriores
        wp_clear_scheduled_hook('wse_pro_process_abandoned_carts');
        wp_clear_scheduled_hook('wse_pro_cleanup_coupons');
        
        // Programar cron maestro de carritos (cada 5 minutos)
        if (!wp_next_scheduled('wse_pro_process_abandoned_carts')) {
            wp_schedule_event(time(), 'five_minutes', 'wse_pro_process_abandoned_carts');
        }
        
        // Programar limpieza de cupones (diaria)
        if (!wp_next_scheduled('wse_pro_cleanup_coupons')) {
            wp_schedule_event(time(), 'daily', 'wse_pro_cleanup_coupons');
        }
    }

    /**
     * Se ejecuta al desactivar el plugin
     */
    public static function on_deactivation() {
        // Limpiar cron jobs
        wp_clear_scheduled_hook('wse_pro_process_abandoned_carts');
        wp_clear_scheduled_hook('wse_pro_cleanup_coupons');
        
        // Log de desactivación
        if (function_exists('wc_get_logger')) {
            wc_get_logger()->info(
                'WooWApp desactivado',
                ['source' => 'woowapp-' . date('Y-m-d')]
            );
        }
    }

    /**
     * Crea la página de reseñas si no existe
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
     * ========================================
     * INICIALIZACIÓN DEL PLUGIN
     * ========================================
     */

    /**
     * Inicializador principal del plugin
     */
    public function init() {
        // Verificar que WooCommerce esté activo
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', [$this, 'missing_wc_notice']);
            return;
        }
        
        // Cargar traducciones
        load_plugin_textdomain(
            'woowapp-smsenlinea-pro',
            false,
            dirname(plugin_basename(__FILE__)) . '/languages'
        );
        
        // Incluir clases necesarias
        $this->includes();
        
        // Inicializar clases y hooks
        $this->init_classes();
        
        // Verificar página de reseñas
        add_action('admin_notices', [$this, 'check_review_page_exists']);
        
        // Registrar intervalo de cron
        add_filter('cron_schedules', function($schedules) {
            if (!isset($schedules['five_minutes'])) {
                $schedules['five_minutes'] = [
                    'interval' => 5 * MINUTE_IN_SECONDS,
                    'display'  => __('Cada 5 minutos', 'woowapp-smsenlinea-pro'),
                ];
            }
            return $schedules;
        });
    }

    /**
     * Incluye los archivos de clases del plugin
     */
    public function includes() {
        require_once WSE_PRO_PATH . 'includes/class-wse-pro-settings.php';
        require_once WSE_PRO_PATH . 'includes/class-wse-pro-api-handler.php';
        require_once WSE_PRO_PATH . 'includes/class-wse-pro-placeholders.php';
        require_once WSE_PRO_PATH . 'includes/class-wse-pro-coupon-manager.php';
    }

    /**
     * Inicializa las clases y registra todos los hooks
     */
    public function init_classes() {
        // Inicializar settings
        new WSE_Pro_Settings();
        
        // Manejar recreación de página de reseñas
        if (isset($_GET['action']) && $_GET['action'] === 'recreate_review_page' && is_admin()) {
            self::create_review_page();
            flush_rewrite_rules();
            wp_redirect(admin_url('admin.php?page=wc-settings&tab=woowapp&section=notifications'));
            exit;
        }

        // ===== HOOKS DE NOTIFICACIONES =====
        
        // Notificación de nueva nota en pedido
        add_action('woocommerce_new_customer_note', [$this, 'trigger_new_note_notification'], 10, 1);
        
        // Notificaciones de cambio de estado
        foreach (array_keys(wc_get_order_statuses()) as $status) {
            $status_clean = str_replace('wc-', '', $status);
            add_action(
                'woocommerce_order_status_' . $status_clean,
                [$this, 'trigger_status_change_notification'],
                10,
                2
            );
        }
        
        // Programar recordatorio de reseña
        add_action('woocommerce_order_status_completed', [$this, 'schedule_review_reminder'], 10, 1);
        add_action('wse_pro_send_review_reminder_event', [$this, 'send_review_reminder_notification'], 10, 1);
        
        // ===== HOOKS DE CARRITO ABANDONADO =====
        
        if ('yes' === get_option('wse_pro_enable_abandoned_cart', 'no')) {
            // Frontend: Captura de carrito
            add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_scripts']);
            add_action('wp_ajax_wse_pro_capture_cart', [$this, 'capture_cart_via_ajax']);
            add_action('wp_ajax_nopriv_wse_pro_capture_cart', [$this, 'capture_cart_via_ajax']);
            
            // Cancelar recordatorio al completar pedido
            add_action('woocommerce_new_order', [$this, 'cancel_abandoned_cart_reminder'], 10, 1);
            
            // Procesamiento de carritos (cron)
            add_action('wse_pro_process_abandoned_carts', [$this, 'process_abandoned_carts_cron']);
            
            // Recuperación de carrito
            add_action('template_redirect', [$this, 'handle_cart_recovery_link']);
            
            // Rellenar campos del checkout
            add_filter('woocommerce_checkout_get_value', [$this, 'populate_checkout_fields'], 10, 2);
        }

        // ===== HOOKS DE CUPONES =====
        
        add_action('wse_pro_cleanup_coupons', [WSE_Pro_Coupon_Manager::class, 'cleanup_expired_coupons']);
        add_action('woocommerce_checkout_order_processed', [WSE_Pro_Coupon_Manager::class, 'track_coupon_usage'], 10, 1);
        
        // ===== HOOKS DE RESEÑAS =====
        
        add_shortcode('woowapp_review_form', [$this, 'render_review_form_shortcode']);
        add_filter('the_content', [$this, 'handle_custom_review_page_content']);
        add_filter('woocommerce_order_actions', [$this, 'add_manual_review_request_action']);
        add_action('woocommerce_order_action_wse_send_review_request', [$this, 'process_manual_review_request_action']);
    }

    /**
     * ========================================
     * CAPTURA Y PROCESAMIENTO DE CARRITOS
     * ========================================
     */

    /**
     * Encola scripts del frontend
     */
    public function enqueue_frontend_scripts() {
        if (is_checkout() && !is_wc_endpoint_url('order-received')) {
            wp_enqueue_script(
                'wse-pro-cart-capture',
                WSE_PRO_URL . 'assets/js/cart-capture.js',
                ['jquery'],
                WSE_PRO_VERSION,
                true
            );
            
            wp_localize_script('wse-pro-cart-capture', 'wseProCapture', [
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce'    => wp_create_nonce('wse_pro_capture_cart_nonce')
            ]);
        }
    }

    /**
     * Captura datos del carrito vía AJAX
     * ACTUALIZADO: Ahora captura todos los campos de billing
     */
    public function capture_cart_via_ajax() {
        check_ajax_referer('wse_pro_capture_cart_nonce', 'nonce');
        
        global $wpdb;
        
        // Obtener datos del POST
        $billing_data = [
            'billing_email'      => isset($_POST['billing_email']) ? sanitize_email($_POST['billing_email']) : '',
            'billing_phone'      => isset($_POST['billing_phone']) ? sanitize_text_field($_POST['billing_phone']) : '',
            'billing_first_name' => isset($_POST['billing_first_name']) ? sanitize_text_field($_POST['billing_first_name']) : '',
            'billing_last_name'  => isset($_POST['billing_last_name']) ? sanitize_text_field($_POST['billing_last_name']) : '',
            'billing_address_1'  => isset($_POST['billing_address_1']) ? sanitize_text_field($_POST['billing_address_1']) : '',
            'billing_city'       => isset($_POST['billing_city']) ? sanitize_text_field($_POST['billing_city']) : '',
            'billing_state'      => isset($_POST['billing_state']) ? sanitize_text_field($_POST['billing_state']) : '',
            'billing_postcode'   => isset($_POST['billing_postcode']) ? sanitize_text_field($_POST['billing_postcode']) : '',
            'billing_country'    => isset($_POST['billing_country']) ? sanitize_text_field($_POST['billing_country']) : '',
        ];
        
        // Verificar que haya al menos email o teléfono
        if (empty($billing_data['billing_email']) && empty($billing_data['billing_phone'])) {
            wp_send_json_success(['captured' => false]);
            return;
        }
        
        // Verificar que el carrito no esté vacío
        $cart = WC()->cart;
        if (!$cart || $cart->is_empty()) {
            wp_send_json_success(['captured' => false]);
            return;
        }
        
        // Obtener datos del carrito
        $session_id = WC()->session->get_customer_id();
        $user_id = get_current_user_id();
        $cart_contents = maybe_serialize($cart->get_cart());
        $cart_total = $cart->get_total('edit');
        $current_time = current_time('mysql');
        
        // Verificar si ya existe un carrito para esta sesión
        $existing_cart = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM " . self::$abandoned_cart_table_name . " 
             WHERE session_id = %s AND status = 'active'",
            $session_id
        ));
        
        // Preparar datos para guardar
        $cart_data = array_merge([
            'user_id'         => $user_id,
            'session_id'      => $session_id,
            'first_name'      => $billing_data['billing_first_name'],
            'phone'           => $billing_data['billing_phone'],
            'cart_contents'   => $cart_contents,
            'cart_total'      => $cart_total,
            'checkout_data'   => '', // Mantenido por compatibilidad
            'updated_at'      => $current_time,
            'messages_sent'   => '0,0,0'
        ], $billing_data);
        
        $format = ['%d', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s'];

        if ($existing_cart) {
            // Actualizar carrito existente
            $wpdb->update(
                self::$abandoned_cart_table_name,
                $cart_data,
                ['id' => $existing_cart->id],
                $format,
                ['%d']
            );
            $cart_id = $existing_cart->id;
        } else {
            // Crear nuevo carrito
            $cart_data['created_at'] = $current_time;
            $cart_data['recovery_token'] = bin2hex(random_bytes(16));
            $format[] = '%s';
            $format[] = '%s';
            
            $wpdb->insert(
                self::$abandoned_cart_table_name,
                $cart_data,
                $format
            );
            $cart_id = $wpdb->insert_id;
        }

        if (empty($cart_id)) {
            wp_send_json_error(['message' => 'Error al guardar el carrito.']);
            return;
        }

        wp_send_json_success(['captured' => true, 'cart_id' => $cart_id]);
    }

    /**
     * Procesa carritos abandonados (cron job)
     */
    public function process_abandoned_carts_cron() {
        global $wpdb;
        
        // Obtener carritos activos que no han recibido todos los mensajes
        $active_carts = $wpdb->get_results(
            "SELECT * FROM " . self::$abandoned_cart_table_name . " 
             WHERE status = 'active' AND messages_sent != '1,1,1'"
        );
        
        if (empty($active_carts)) {
            return;
        }

        foreach ($active_carts as $cart) {
            $messages_sent = explode(',', $cart->messages_sent);
            $last_update_time = strtotime($cart->updated_at);

            // Verificar cada mensaje
            for ($i = 1; $i <= 3; $i++) {
                // Si ya se envió este mensaje, continuar
                if (isset($messages_sent[$i - 1]) && $messages_sent[$i - 1] == '1') {
                    continue;
                }
                
                // Si este mensaje no está activado, continuar
                if (get_option('wse_pro_abandoned_cart_enable_msg_' . $i, 'no') !== 'yes') {
                    continue;
                }

                // Calcular tiempo de espera
                $delay = (int) get_option('wse_pro_abandoned_cart_time_' . $i, 60);
                $unit = get_option('wse_pro_abandoned_cart_unit_' . $i, 'minutes');
                
                $seconds_to_wait = 0;
                switch ($unit) {
                    case 'days':
                        $seconds_to_wait = $delay * DAY_IN_SECONDS;
                        break;
                    case 'hours':
                        $seconds_to_wait = $delay * HOUR_IN_SECONDS;
                        break;
                    default:
                        $seconds_to_wait = $delay * MINUTE_IN_SECONDS;
                        break;
                }

                // Si ya pasó el tiempo, enviar mensaje
                if (time() >= ($last_update_time + $seconds_to_wait)) {
                    $this->send_abandoned_cart_message($cart, $i);
                    break; // Solo enviar un mensaje por vez
                }
            }
        }
    }

    /**
     * Envía un mensaje de carrito abandonado
     * ACTUALIZADO: Usa el prefijo personalizado del usuario
     */
    private function send_abandoned_cart_message($cart_row, $message_number) {
        global $wpdb;
        
        // Obtener plantilla del mensaje
        $template = get_option('wse_pro_abandoned_cart_message_' . $message_number);
        if (empty($template)) {
            return;
        }

        // Generar cupón si está activado
        $coupon_data = null;
        if (get_option('wse_pro_abandoned_cart_coupon_enable_' . $message_number, 'no') === 'yes') {
            $coupon_manager = WSE_Pro_Coupon_Manager::get_instance();
            
            // Obtener prefijo personalizado
            $prefix = get_option(
                'wse_pro_abandoned_cart_coupon_prefix_' . $message_number,
                'woowapp-m' . $message_number
            );
            
            $coupon_result = $coupon_manager->generate_coupon([
                'discount_type'   => get_option('wse_pro_abandoned_cart_coupon_type_' . $message_number, 'percent'),
                'discount_amount' => (float) get_option('wse_pro_abandoned_cart_coupon_amount_' . $message_number, 10),
                'expiry_days'     => (int) get_option('wse_pro_abandoned_cart_coupon_expiry_' . $message_number, 7),
                'customer_phone'  => $cart_row->phone,
                'customer_email'  => $cart_row->billing_email,
                'cart_id'         => $cart_row->id,
                'message_number'  => $message_number,
                'coupon_type'     => 'cart_recovery',
                'prefix'          => $prefix // ⭐ Usar prefijo personalizado
            ]);
            
            if (!is_wp_error($coupon_result)) {
                $coupon_data = $coupon_result;
            }
        }

        // Reemplazar variables en el mensaje
        $message = WSE_Pro_Placeholders::replace_for_cart($template, $cart_row, $coupon_data);
        
        // Crear objeto para API handler
        $cart_obj = (object)[
            'id' => $cart_row->id,
            'phone' => $cart_row->phone,
            'cart_contents' => $cart_row->cart_contents
        ];
        
        // Enviar mensaje
        $api_handler = new WSE_Pro_API_Handler();
        $result = $api_handler->send_message($cart_row->phone, $message, $cart_obj, 'customer');

        // Actualizar estado si se envió correctamente
        if ($result['success']) {
            $messages_sent = explode(',', $cart_row->messages_sent);
            $messages_sent[$message_number - 1] = '1';
            
            $update_data = ['messages_sent' => implode(',', $messages_sent)];
            
            // Si es el último mensaje o no hay más mensajes activados, marcar como enviado
            if ($message_number == 3 || !$this->has_more_messages_pending($message_number)) {
                $update_data['status'] = 'sent';
            }
            
            $wpdb->update(
                self::$abandoned_cart_table_name,
                $update_data,
                ['id' => $cart_row->id]
            );
        }
    }

    /**
     * Verifica si hay más mensajes pendientes de enviar
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
     * ========================================
     * RECUPERACIÓN DE CARRITO
     * ========================================
     */

    /**
     * Maneja los enlaces de recuperación de carrito
     * VERSIÓN ROBUSTA v2.2.1 con restauración de datos completa
     */
    public function handle_cart_recovery_link() {
        // Verificar parámetro de recuperación
        if (!isset($_GET['recover-cart-wse'])) {
            return;
        }

        // Verificar que WooCommerce esté disponible
        if (!function_exists('WC') || !WC()->cart) {
            $this->log_error('WooCommerce no disponible en recuperación');
            wp_die(__('Error: WooCommerce no está disponible. Por favor, contacta al administrador.', 'woowapp-smsenlinea-pro'));
            return;
        }

        try {
            global $wpdb;
            $token = sanitize_text_field($_GET['recover-cart-wse']);
            
            $this->log_info("Intento de recuperación - Token: {$token}");

            // Buscar el carrito
            $cart_row = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM " . self::$abandoned_cart_table_name . " WHERE recovery_token = %s",
                $token
            ));

            // Validar que existe
            if (!$cart_row) {
                $this->log_warning("Token no válido: {$token}");
                wc_add_notice(
                    __('El enlace de recuperación no es válido o ha expirado.', 'woowapp-smsenlinea-pro'),
                    'error'
                );
                wp_safe_redirect(wc_get_cart_url());
                exit();
            }

            // Validar que no fue recuperado ya
            if ($cart_row->status === 'recovered') {
                $this->log_info("Carrito ya recuperado - ID: {$cart_row->id}");
                wc_add_notice(
                    __('Este carrito ya fue recuperado anteriormente.', 'woowapp-smsenlinea-pro'),
                    'notice'
                );
                wp_safe_redirect(wc_get_cart_url());
                exit();
            }

            // Vaciar carrito actual
            WC()->cart->empty_cart();

            // Deserializar contenido
            $cart_contents = maybe_unserialize($cart_row->cart_contents);
            
            if (!is_array($cart_contents) || empty($cart_contents)) {
                $this->log_error("Carrito vacío - ID: {$cart_row->id}");
                wc_add_notice(
                    __('El carrito está vacío o no se pudo recuperar.', 'woowapp-smsenlinea-pro'),
                    'error'
                );
                wp_safe_redirect(wc_get_cart_url());
                exit();
            }

            // Restaurar productos
            $products_restored = 0;
            $products_failed = 0;

            foreach ($cart_contents as $item) {
                if (!isset($item['product_id']) || !isset($item['quantity'])) {
                    $products_failed++;
                    continue;
                }

                $product_id = absint($item['product_id']);
                $quantity = absint($item['quantity']);
                $variation_id = isset($item['variation_id']) ? absint($item['variation_id']) : 0;
                $variation = isset($item['variation']) && is_array($item['variation']) ? $item['variation'] : [];

                // Verificar disponibilidad
                $product = wc_get_product($variation_id > 0 ? $variation_id : $product_id);
                
                if (!$product || !$product->is_purchasable() || !$product->is_in_stock()) {
                    $products_failed++;
                    $this->log_warning("Producto no disponible - ID: {$product_id}, Var: {$variation_id}");
                    continue;
                }

                // Agregar al carrito
                $added = WC()->cart->add_to_cart($product_id, $quantity, $variation_id, $variation);
                
                if ($added) {
                    $products_restored++;
                } else {
                    $products_failed++;
                }
            }

            $this->log_info("Recuperación - ID: {$cart_row->id}, Restaurados: {$products_restored}, Fallidos: {$products_failed}");

            // Restaurar datos del cliente en la sesión de WooCommerce
            $customer = WC()->customer;
            if ($customer) {
                if (!empty($cart_row->billing_first_name)) {
                    $customer->set_billing_first_name($cart_row->billing_first_name);
                }
                if (!empty($cart_row->billing_last_name)) {
                    $customer->set_billing_last_name($cart_row->billing_last_name);
                }
                if (!empty($cart_row->billing_email)) {
                    $customer->set_billing_email($cart_row->billing_email);
                }
                if (!empty($cart_row->billing_phone)) {
                    $customer->set_billing_phone($cart_row->billing_phone);
                }
                if (!empty($cart_row->billing_address_1)) {
                    $customer->set_billing_address_1($cart_row->billing_address_1);
                }
                if (!empty($cart_row->billing_city)) {
                    $customer->set_billing_city($cart_row->billing_city);
                }
                if (!empty($cart_row->billing_state)) {
                    $customer->set_billing_state($cart_row->billing_state);
                }
                if (!empty($cart_row->billing_postcode)) {
                    $customer->set_billing_postcode($cart_row->billing_postcode);
                }
                if (!empty($cart_row->billing_country)) {
                    $customer->set_billing_country($cart_row->billing_country);
                }
                
                $customer->save();
            }

            // Aplicar cupón si existe
            try {
                $coupon_manager = WSE_Pro_Coupon_Manager::get_instance();
                $coupon = $coupon_manager->get_latest_coupon_for_cart($cart_row->id);
                
                if ($coupon && !WC()->cart->has_discount($coupon->coupon_code)) {
                    $applied = WC()->cart->apply_coupon($coupon->coupon_code);
                    
                    if ($applied) {
                        wc_add_notice(
                            sprintf(
                                __('¡Cupón "%s" aplicado exitosamente! 🎁', 'woowapp-smsenlinea-pro'),
                                $coupon->coupon_code
                            ),
                            'success'
                        );
                    }
                }
            } catch (Exception $e) {
                $this->log_warning("Error aplicando cupón: " . $e->getMessage());
            }

            // Marcar como recuperado
            $wpdb->update(
                self::$abandoned_cart_table_name,
                ['status' => 'recovered'],
                ['id' => $cart_row->id],
                ['%s'],
                ['%d']
            );

            // Mensajes de éxito
            if ($products_restored > 0) {
                wc_add_notice(
                    sprintf(
                        _n(
                            '¡Tu carrito ha sido restaurado con %d producto! 🛒',
                            '¡Tu carrito ha sido restaurado con %d productos! 🛒',
                            $products_restored,
                            'woowapp-smsenlinea-pro'
                        ),
                        $products_restored
                    ),
                    'success'
                );
            }

            if ($products_failed > 0) {
                wc_add_notice(
                    sprintf(
                        _n(
                            '%d producto ya no está disponible.',
                            '%d productos ya no están disponibles.',
                            $products_failed,
                            'woowapp-smsenlinea-pro'
                        ),
                        $products_failed
                    ),
                    'notice'
                );
            }

            if ($products_restored === 0) {
                wc_add_notice(
                    __('No se pudieron restaurar los productos de tu carrito.', 'woowapp-smsenlinea-pro'),
                    'error'
                );
                wp_safe_redirect(wc_get_cart_url());
                exit();
            }

            // Redirigir al checkout
            wp_safe_redirect(wc_get_checkout_url());
            exit();

        } catch (Exception $e) {
            $this->log_error("Error en recuperación: " . $e->getMessage());
            wc_add_notice(
                __('Ocurrió un error al recuperar tu carrito. Por favor, contacta al soporte.', 'woowapp-smsenlinea-pro'),
                'error'
            );
            wp_safe_redirect(wc_get_cart_url());
            exit();
        }
    }

    /**
     * Rellena campos del checkout con datos guardados
     */
    public function populate_checkout_fields($value, $input) {
        $customer = WC()->customer;
        
        if (!$customer) {
            return $value;
        }

        // Mapeo de campos
        $field_map = [
            'billing_first_name' => 'get_billing_first_name',
            'billing_last_name'  => 'get_billing_last_name',
            'billing_email'      => 'get_billing_email',
            'billing_phone'      => 'get_billing_phone',
            'billing_address_1'  => 'get_billing_address_1',
            'billing_city'       => 'get_billing_city',
            'billing_state'      => 'get_billing_state',
            'billing_postcode'   => 'get_billing_postcode',
            'billing_country'    => 'get_billing_country',
        ];

        if (isset($field_map[$input]) && method_exists($customer, $field_map[$input])) {
            $customer_value = $customer->{$field_map[$input]}();
            if (!empty($customer_value)) {
                return $customer_value;
            }
        }

        return $value;
    }

    /**
     * Cancela recordatorio de carrito al completar pedido
     */
    public function cancel_abandoned_cart_reminder($order_id) {
        $session_id = WC()->session->get_customer_id();
        
        if (empty($session_id)) {
            return;
        }
        
        global $wpdb;
        
        $active_cart = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::$abandoned_cart_table_name . " 
             WHERE session_id = %s AND status = 'active'",
            $session_id
        ));

        if ($active_cart) {
            $wpdb->update(
                self::$abandoned_cart_table_name,
                ['status' => 'recovered'],
                ['id' => $active_cart->id]
            );
            
            $this->log_info("Carrito marcado como recuperado al crear pedido #{$order_id}");
        }
    }

    /**
     * ========================================
     * NOTIFICACIONES DE PEDIDOS
     * ========================================
     */

    /**
     * Dispara notificación de cambio de estado
     */
    public function trigger_status_change_notification($order_id, $order) {
        $api_handler = new WSE_Pro_API_Handler();
        $api_handler->handle_status_change($order_id, $order);
    }

    /**
     * Dispara notificación de nueva nota
     */
    public function trigger_new_note_notification($data) {
        $api_handler = new WSE_Pro_API_Handler();
        $api_handler->handle_new_note($data);
    }

    /**
     * ========================================
     * RECORDATORIOS DE RESEÑAS
     * ========================================
     */

    /**
     * Programa recordatorio de reseña
     */
    public function schedule_review_reminder($order_id) {
        // Limpiar eventos anteriores
        wp_clear_scheduled_hook('wse_pro_send_review_reminder_event', [$order_id]);
        
        if ('yes' !== get_option('wse_pro_enable_review_reminder', 'no')) {
            return;
        }
        
        $delay_days = (int) get_option('wse_pro_review_reminder_days', 7);
        
        if ($delay_days <= 0) {
            return;
        }
        
        $time_to_send = time() + ($delay_days * DAY_IN_SECONDS);
        wp_schedule_single_event($time_to_send, 'wse_pro_send_review_reminder_event', [$order_id]);
    }

    /**
     * Envía recordatorio de reseña
     */
    public function send_review_reminder_notification($order_id) {
        $order = wc_get_order($order_id);
        
        if (!$order) {
            return;
        }
        
        $template = get_option('wse_pro_review_reminder_message');
        
        if (empty($template)) {
            return;
        }
        
        $api_handler = new WSE_Pro_API_Handler();
        $message = WSE_Pro_Placeholders::replace($template, $order);
        $api_handler->send_message($order->get_billing_phone(), $message, $order, 'customer');
    }

    /**
     * Renderiza formulario de reseña (shortcode)
     */
    public function render_review_form_shortcode($atts) {
        return $this->get_review_form_html();
    }

    /**
     * Genera HTML del formulario de reseña
     */
    private function get_review_form_html() {
        // Procesar envío del formulario
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['wse_review_nonce'])) {
            if (!wp_verify_nonce($_POST['wse_review_nonce'], 'wse_submit_review')) {
                return '<div class="woocommerce-error">' . 
                       __('Error de seguridad. Inténtalo de nuevo.', 'woowapp-smsenlinea-pro') . 
                       '</div>';
            }

            $order_id = isset($_POST['review_order_id']) ? absint($_POST['review_order_id']) : 0;
            $product_id = isset($_POST['review_product_id']) ? absint($_POST['review_product_id']) : 0;
            $rating = isset($_POST['review_rating']) ? absint($_POST['review_rating']) : 5;
            $comment_text = isset($_POST['review_comment']) ? sanitize_textarea_field($_POST['review_comment']) : '';
            
            $order = wc_get_order($order_id);
            
            if (!$order) {
                return '<div class="woocommerce-error">' . 
                       __('Pedido no válido.', 'woowapp-smsenlinea-pro') . 
                       '</div>';
            }

            $commentdata = [
                'comment_post_ID'      => $product_id,
                'comment_author'       => $order->get_billing_first_name(),
                'comment_author_email' => $order->get_billing_email(),
                'comment_content'      => $comment_text,
                'user_id'              => $order->get_user_id() ?: 0,
                'comment_approved'     => 0,
                'comment_type'         => 'review'
            ];
            
            $comment_id = wp_insert_comment($commentdata);

            if ($comment_id) {
                add_comment_meta($comment_id, 'rating', $rating);
                return '<div class="woocommerce-message">' . 
                       __('¡Gracias por tu reseña! Ha sido enviada y será publicada tras la aprobación.', 'woowapp-smsenlinea-pro') . 
                       '</div>';
            } else {
                return '<div class="woocommerce-error">' . 
                       __('Hubo un error al enviar tu reseña.', 'woowapp-smsenlinea-pro') . 
                       '</div>';
            }
        }

        // Mostrar formulario
        $order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
        $order_key = isset($_GET['key']) ? sanitize_text_field($_GET['key']) : '';
        
        if ($order_id > 0 && !empty($order_key)) {
            $order = wc_get_order($order_id);
            
            if ($order && $order->get_order_key() === $order_key) {
                $html = '<div class="woowapp-review-container">';
                $html .= '<h3>' . sprintf(
                    __('Deja una reseña para los productos de tu pedido #%s', 'woowapp-smsenlinea-pro'),
                    $order->get_order_number()
                ) . '</h3>';
                
                foreach ($order->get_items() as $item) {
                    $product = $item->get_product();
                    if (!$product) continue;
                    
                    $html .= '<div class="review-form-wrapper" style="border:1px solid #ddd; padding:20px; margin-bottom:20px; border-radius: 5px;">';
                    $html .= '<h4>' . esc_html($product->get_name()) . '</h4>';
                    $html .= '<form method="post" class="woowapp-review-form">';
                    $html .= '<p class="comment-form-rating">';
                    $html .= '<label for="review_rating-' . $product->get_id() . '">' . __('Tu calificación', 'woowapp-smsenlinea-pro') . '&nbsp;<span class="required">*</span></label>';
                    $html .= '<select name="review_rating" id="review_rating-' . $product->get_id() . '" required>';
                    $html .= '<option value="5">★★★★★</option>';
                    $html .= '<option value="4">★★★★☆</option>';
                    $html .= '<option value="3">★★★☆☆</option>';
                    $html .= '<option value="2">★★☆☆☆</option>';
                    $html .= '<option value="1">★☆☆☆☆</option>';
                    $html .= '</select></p>';
                    $html .= '<p class="comment-form-comment">';
                    $html .= '<label for="review_comment-' . $product->get_id() . '">' . __('Tu reseña', 'woowapp-smsenlinea-pro') . '</label>';
                    $html .= '<textarea name="review_comment" id="review_comment-' . $product->get_id() . '" cols="45" rows="8"></textarea>';
                    $html .= '</p>';
                    $html .= '<input type="hidden" name="review_order_id" value="' . esc_attr($order_id) . '" />';
                    $html .= '<input type="hidden" name="review_product_id" value="' . esc_attr($product->get_id()) . '" />';
                    $html .= wp_nonce_field('wse_submit_review', 'wse_review_nonce', true, false);
                    $html .= '<p class="form-submit">';
                    $html .= '<input name="submit" type="submit" class="submit button" value="' . __('Enviar Reseña', 'woowapp-smsenlinea-pro') . '" />';
                    $html .= '</p>';
                    $html .= '</form>';
                    $html .= '</div>';
                }
                
                $html .= '</div>';
                return $html;
            }
        }
        
        return '<div class="woocommerce-error">' . 
               __('Enlace de reseña no válido o caducado.', 'woowapp-smsenlinea-pro') . 
               '</div>';
    }

    /**
     * Maneja contenido de página de reseñas
     */
    public function handle_custom_review_page_content($content) {
        $page_id = get_option('wse_pro_review_page_id');
        
        if (!is_page($page_id) || has_shortcode($content, 'woowapp_review_form')) {
            return $content;
        }
        
        return $content . $this->get_review_form_html();
    }

    /**
     * Agrega acción manual de solicitud de reseña
     */
    public function add_manual_review_request_action($actions) {
        $actions['wse_send_review_request'] = __('Enviar solicitud de reseña por WhatsApp/SMS', 'woowapp-smsenlinea-pro');
        return $actions;
    }

    /**
     * Procesa acción manual de solicitud de reseña
     */
    public function process_manual_review_request_action($order) {
        $template = get_option('wse_pro_review_reminder_message');
        
        if (!empty($template)) {
            $this->send_review_reminder_notification($order->get_id());
            $order->add_order_note(__('Solicitud de reseña enviada manualmente al cliente.', 'woowapp-smsenlinea-pro'));
        } else {
            $order->add_order_note(__('Fallo al enviar solicitud de reseña: La plantilla de mensaje está vacía.', 'woowapp-smsenlinea-pro'));
        }
    }

    /**
     * ========================================
     * UTILIDADES Y LOGGING
     * ========================================
     */

    /**
     * Verifica que la página de reseñas existe
     */
    public function check_review_page_exists() {
        $page_id = get_option('wse_pro_review_page_id');
        
        if (!$page_id || get_post_status($page_id) !== 'publish') {
            $screen = get_current_screen();
            
            if ($screen && $screen->id === 'woocommerce_page_wc-settings') {
                echo '<div class="notice notice-warning">';
                echo '<p><strong>WooWApp:</strong> ';
                echo __('La página de reseñas no existe o no está publicada. ', 'woowapp-smsenlinea-pro');
                echo '<a href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=woowapp&action=recreate_review_page')) . '">';
                echo __('Haz clic aquí para recrearla', 'woowapp-smsenlinea-pro');
                echo '</a></p></div>';
            }
        }
    }

    /**
     * Muestra aviso de falta de WooCommerce
     */
    public function missing_wc_notice() {
        echo '<div class="error"><p>';
        echo '<strong>' . esc_html__('WooWApp', 'woowapp-smsenlinea-pro') . ':</strong> ';
        echo esc_html__('Este plugin requiere que WooCommerce esté instalado y activo para funcionar.', 'woowapp-smsenlinea-pro');
        echo '</p></div>';
    }

    /**
     * Registra mensaje informativo
     */
    private function log_info($message) {
        if (get_option('wse_pro_enable_log') === 'yes' && function_exists('wc_get_logger')) {
            wc_get_logger()->info($message, ['source' => 'woowapp-' . date('Y-m-d')]);
        }
    }

    /**
     * Registra advertencia
     */
    private function log_warning($message) {
        if (get_option('wse_pro_enable_log') === 'yes' && function_exists('wc_get_logger')) {
            wc_get_logger()->warning($message, ['source' => 'woowapp-' . date('Y-m-d')]);
        }
    }

    /**
     * Registra error
     */
    private function log_error($message) {
        if (get_option('wse_pro_enable_log') === 'yes' && function_exists('wc_get_logger')) {
            wc_get_logger()->error($message, ['source' => 'woowapp-' . date('Y-m-d')]);
        }
    }
}

// Inicializar el plugin
WooWApp::get_instance();
