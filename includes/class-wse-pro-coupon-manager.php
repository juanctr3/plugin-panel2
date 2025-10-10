<?php
/**
 * Gestor de Cupones Automáticos para WooWApp
 * 
 * Maneja la creación, tracking y limpieza de cupones de descuento
 * para recuperación de carritos y recompensas por reseñas.
 *
 * @package WooWApp
 * @version 1.1
 */

if (!defined('ABSPATH')) {
    exit;
}

class WSE_Pro_Coupon_Manager {

    /**
     * Nombre de la tabla de tracking de cupones
     * @var string
     */
    private static $table_name;

    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        self::$table_name = $wpdb->prefix . 'wse_pro_coupons_generated';
    }

    /**
     * Crea la tabla de tracking de cupones al activar el plugin
     */
    public static function create_coupons_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wse_pro_coupons_generated';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
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
            KEY cart_id (cart_id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * Genera un cupón único de WooCommerce con tracking
     *
     * @param array $args Argumentos del cupón
     * @return array|WP_Error Resultado con código del cupón o error
     */
    public function generate_coupon($args = []) {
        $defaults = [
            'discount_type'   => 'percent',     // 'percent' o 'fixed_cart'
            'discount_amount' => 10,
            'expiry_days'     => 7,
            'usage_limit'     => 1,
            'customer_phone'  => '',
            'customer_email'  => '',
            'cart_id'         => 0,
            'order_id'        => 0,
            'message_number'  => 0,
            'coupon_type'     => 'cart_recovery', // 'cart_recovery' o 'review_reward'
            'prefix'          => 'WOOWAPP'
        ];

        $args = wp_parse_args($args, $defaults);

        // Validar tipo de descuento
        if (!in_array($args['discount_type'], ['percent', 'fixed_cart', 'fixed_product'])) {
            return new WP_Error('invalid_discount_type', __('Tipo de descuento no válido', 'woowapp-smsenlinea-pro'));
        }

        // Generar código único
        $coupon_code = $this->generate_unique_code($args['prefix'], $args['message_number']);

        // Calcular fecha de expiración
        $expires_at = date('Y-m-d H:i:s', strtotime('+' . $args['expiry_days'] . ' days'));

        // Crear cupón en WooCommerce
        $coupon_id = $this->create_woocommerce_coupon($coupon_code, $args, $expires_at);

        if (is_wp_error($coupon_id)) {
            return $coupon_id;
        }

        // Registrar en la base de datos para tracking
        global $wpdb;
        $inserted = $wpdb->insert(
            self::$table_name,
            [
                'coupon_code'     => $coupon_code,
                'customer_phone'  => $args['customer_phone'],
                'customer_email'  => $args['customer_email'],
                'cart_id'         => $args['cart_id'],
                'order_id'        => $args['order_id'],
                'message_number'  => $args['message_number'],
                'coupon_type'     => $args['coupon_type'],
                'discount_type'   => $args['discount_type'],
                'discount_amount' => $args['discount_amount'],
                'usage_limit'     => $args['usage_limit'],
                'used'            => 0,
                'created_at'      => current_time('mysql'),
                'expires_at'      => $expires_at
            ],
            ['%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%f', '%d', '%d', '%s', '%s']
        );

        if (!$inserted) {
            // Si falla el tracking, eliminar el cupón de WooCommerce
            wp_delete_post($coupon_id, true);
            return new WP_Error('db_error', __('Error al registrar el cupón', 'woowapp-smsenlinea-pro'));
        }

        return [
            'success'         => true,
            'coupon_code'     => $coupon_code,
            'coupon_id'       => $coupon_id,
            'discount_amount' => $args['discount_amount'],
            'discount_type'   => $args['discount_type'],
            'expires_at'      => $expires_at,
            'formatted_discount' => $this->format_discount($args['discount_amount'], $args['discount_type']),
            'formatted_expiry'   => date_i18n(get_option('date_format'), strtotime($expires_at))
        ];
    }

    /**
     * Genera un código único para el cupón
     *
     * @param string $prefix Prefijo del cupón
     * @param int $message_number Número de mensaje (para diferenciación)
     * @return string Código único
     */
    private function generate_unique_code($prefix, $message_number = 0) {
        $suffix = strtoupper(substr(md5(uniqid(rand(), true)), 0, 6));
        
        $msg_suffix = '';
        if ($message_number > 0) {
            $msg_suffix = 'M' . $message_number . '-';
        }

        $code = $prefix . '-' . $msg_suffix . $suffix;

        // Verificar que no exista (aunque es altamente improbable)
        if (get_page_by_title($code, OBJECT, 'shop_coupon')) {
            return $this->generate_unique_code($prefix, $message_number); // Recursivo
        }

        return $code;
    }

    /**
     * Crea el cupón en WooCommerce
     *
     * @param string $coupon_code Código del cupón
     * @param array $args Argumentos del cupón
     * @param string $expires_at Fecha de expiración
     * @return int|WP_Error ID del cupón o error
     */
    private function create_woocommerce_coupon($coupon_code, $args, $expires_at) {
        $coupon = new WC_Coupon();
        
        $coupon->set_code($coupon_code);
        $coupon->set_discount_type($args['discount_type']);
        $coupon->set_amount($args['discount_amount']);
        $coupon->set_date_expires(strtotime($expires_at));
        $coupon->set_usage_limit($args['usage_limit']);
        $coupon->set_usage_limit_per_user($args['usage_limit']);
        $coupon->set_individual_use(true);
        $coupon->set_description(
            sprintf(
                __('Cupón automático generado por WooWApp - %s', 'woowapp-smsenlinea-pro'),
                $args['coupon_type']
            )
        );

        // Si hay email, restringir a ese email
        if (!empty($args['customer_email'])) {
            $coupon->set_email_restrictions([$args['customer_email']]);
        }

        try {
            $coupon_id = $coupon->save();
            return $coupon_id;
        } catch (Exception $e) {
            return new WP_Error('coupon_creation_failed', $e->getMessage());
        }
    }

    /**
     * Marca un cupón como usado
     *
     * @param string $coupon_code Código del cupón
     * @return bool Éxito de la operación
     */
    public function mark_as_used($coupon_code) {
        global $wpdb;
        
        return $wpdb->update(
            self::$table_name,
            ['used' => 1],
            ['coupon_code' => $coupon_code],
            ['%d'],
            ['%s']
        );
    }

    /**
     * Verifica si un cliente ya tiene un cupón activo para un carrito
     *
     * @param string $phone Teléfono del cliente
     * @param int $cart_id ID del carrito
     * @return bool True si ya existe
     */
    public function customer_has_active_coupon($phone, $cart_id = 0) {
        global $wpdb;
        
        $where = "customer_phone = %s AND used = 0 AND expires_at > NOW()";
        $params = [$phone];
        
        if ($cart_id > 0) {
            $where .= " AND cart_id = %d";
            $params[] = $cart_id;
        }

        $count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM " . self::$table_name . " WHERE " . $where,
                $params
            )
        );

        return $count > 0;
    }

    /**
     * Obtiene el último cupón generado para un cliente
     *
     * @param string $phone Teléfono del cliente
     * @param int $message_number Número de mensaje (opcional)
     * @return object|null Datos del cupón o null
     */
    public function get_customer_latest_coupon($phone, $message_number = 0) {
        global $wpdb;
        
        $where = "customer_phone = %s AND used = 0 AND expires_at > NOW()";
        $params = [$phone];
        
        if ($message_number > 0) {
            $where .= " AND message_number = %d";
            $params[] = $message_number;
        }

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM " . self::$table_name . " 
                WHERE " . $where . " 
                ORDER BY created_at DESC 
                LIMIT 1",
                $params
            )
        );
    }

    /**
     * Limpia cupones expirados (cron job)
     */
    public static function cleanup_expired_coupons() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wse_pro_coupons_generated';

        // Obtener cupones expirados
        $expired_coupons = $wpdb->get_col(
            "SELECT coupon_code FROM $table_name 
            WHERE expires_at < NOW() AND used = 0"
        );

        if (empty($expired_coupons)) {
            return;
        }

        foreach ($expired_coupons as $coupon_code) {
            // Eliminar de WooCommerce
            $coupon_id = wc_get_coupon_id_by_code($coupon_code);
            if ($coupon_id) {
                wp_delete_post($coupon_id, true);
            }

            // Eliminar de la tabla de tracking
            $wpdb->delete($table_name, ['coupon_code' => $coupon_code], ['%s']);
        }
    }

    /**
     * Formatea el descuento para mostrar
     *
     * @param float $amount Cantidad
     * @param string $type Tipo de descuento
     * @return string Descuento formateado
     */
    private function format_discount($amount, $type) {
        if ($type === 'percent') {
            return $amount . '%';
        } else {
            return wc_price($amount);
        }
    }

    /**
     * Hook para marcar cupones como usados cuando se aplican a un pedido
     */
    public static function track_coupon_usage($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) return;

        $used_coupons = $order->get_coupon_codes();
        
        if (empty($used_coupons)) return;

        $manager = new self();
        foreach ($used_coupons as $coupon_code) {
            // Solo marcar cupones generados por nuestro sistema
            if (strpos($coupon_code, 'WOOWAPP') !== false) {
                $manager->mark_as_used($coupon_code);
            }
        }
    }

    /**
     * Obtiene estadísticas de cupones
     *
     * @return array Estadísticas
     */
    public function get_stats() {
        global $wpdb;
        
        $stats = [
            'total_generated' => $wpdb->get_var("SELECT COUNT(*) FROM " . self::$table_name),
            'total_used'      => $wpdb->get_var("SELECT COUNT(*) FROM " . self::$table_name . " WHERE used = 1"),
            'total_active'    => $wpdb->get_var("SELECT COUNT(*) FROM " . self::$table_name . " WHERE used = 0 AND expires_at > NOW()"),
            'total_expired'   => $wpdb->get_var("SELECT COUNT(*) FROM " . self::$table_name . " WHERE used = 0 AND expires_at < NOW()"),
        ];

        $stats['conversion_rate'] = $stats['total_generated'] > 0 
            ? round(($stats['total_used'] / $stats['total_generated']) * 100, 2)
            : 0;

        return $stats;
    }
}
