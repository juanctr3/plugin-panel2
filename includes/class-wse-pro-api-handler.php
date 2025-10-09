<?php
/**
 * Maneja toda la comunicación con la API de SMSenlinea y la lógica de envío de mensajes.
 *
 * @package WooWApp
 * @version 1.5.0
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Clase WSE_Pro_API_Handler.
 */
class WSE_Pro_API_Handler {

    /**
     * Identificador para los registros de WooCommerce.
     *
     * @var string
     */
    public static $log_handle = 'wse-pro';

    /**
     * URL de la API para enviar mensajes.
     *
     * @var string
     */
    private $api_url = 'https://api.smsenlinea.com/api/qr/rest/send_message';

    /**
     * Array de códigos de país.
     *
     * @var array
     */
    private $country_codes = [];

    /**
     * Constructor. Carga los códigos de país.
     */
    public function __construct() {
        if (file_exists(WSE_PRO_PATH . 'includes/country-codes.php')) {
            $this->country_codes = include(WSE_PRO_PATH . 'includes/country-codes.php');
        }
    }

    /**
     * Maneja el envío de notificaciones cuando cambia el estado de un pedido.
     *
     * @param int      $order_id ID del pedido.
     * @param WC_Order $order Objeto del pedido.
     */
    public function handle_status_change($order_id, $order) {
        if (!$order) {
            $order = wc_get_order($order_id);
        }
        if (!$order) {
            return;
        }
        
        $status = $order->get_status();
        $slug_clean = str_replace('wc-', '', $status);

        // 1. Notificación para el CLIENTE
        if ('yes' === get_option('wse_pro_enable_' . $status, 'no')) {
            $template = get_option('wse_pro_message_' . $status);
            if (!empty($template)) {
                $message = WSE_Pro_Placeholders::replace($template, $order);
                $this->send_message($order->get_billing_phone(), $message, $order, 'customer');
            }
        }
        
        // 2. Notificación para ADMINISTRADORES
        if ('yes' === get_option('wse_pro_enable_admin_' . $slug_clean, 'no')) {
            $admin_numbers_raw = get_option('wse_pro_admin_numbers', '');
            $admin_numbers = array_filter(array_map('trim', explode("\n", $admin_numbers_raw)));

            if (!empty($admin_numbers)) {
                $template = get_option('wse_pro_admin_message_' . $slug_clean);
                if (!empty($template)) {
                    $message = WSE_Pro_Placeholders::replace($template, $order);
                    foreach ($admin_numbers as $number) {
                        $this->send_message($number, $message, $order, 'admin');
                    }
                }
            }
        }
    }
    
    /**
     * Maneja el envío de notificaciones cuando se añade una nueva nota a un pedido.
     *
     * @param array $data Datos de la nota.
     */
    public function handle_new_note($data) {
        if ('yes' !== get_option('wse_pro_enable_note', 'no') || empty($data['order_id'])) {
            return;
        }

        $order = wc_get_order($data['order_id']);
        if (!$order) {
            return;
        }

        $template = get_option('wse_pro_message_note');
        if (empty($template)) {
            return;
        }
        
        $extras = ['{note_content}' => wp_strip_all_tags($data['customer_note'])];
        $message = WSE_Pro_Placeholders::replace($template, $order, $extras);
        $this->send_message($order->get_billing_phone(), $message, $order, 'customer');
    }

    /**
     * Envía un mensaje. Función centralizada que maneja diferentes fuentes de datos (pedidos, carritos).
     *
     * @param string $phone       Número de teléfono de destino.
     * @param string $message     El mensaje a enviar.
     * @param mixed  $data_source El objeto WC_Order, la fila de la BD del carrito, o null.
     * @param string $type        El tipo de destinatario (customer, admin, test).
     * @return array              Respuesta de la operación.
     */
    public function send_message($phone, $message, $data_source = null, $type = 'customer') {
        $token = get_option('wse_pro_api_token');
        $from = get_option('wse_pro_from_number');

        $country = ($data_source && is_a($data_source, 'WC_Order') && 'customer' === $type) ? $data_source->get_billing_country() : '';
        $full_phone = $this->format_phone($phone, $country);
        
        if (empty($full_phone) || empty($message)) {
            return ['success' => false, 'message' => __('Número de teléfono o mensaje vacío.', 'woowapp-smsenlinea-pro')];
        }
        if (empty($token) || empty($from)) {
            $this->log(__('Envío cancelado: Faltan credenciales de API.', 'woowapp-smsenlinea-pro'));
            if ($data_source && is_a($data_source, 'WC_Order')) {
                $data_source->add_order_note(__('Error WhatsApp: Faltan credenciales de API.', 'woowapp-smsenlinea-pro'));
            }
            return ['success' => false, 'message' => __('Faltan credenciales de API.', 'woowapp-smsenlinea-pro')];
        }

        $image_url = '';
        $attach_image = 'no';

        if ($data_source) {
            if (is_a($data_source, 'WC_Order')) {
                $attach_image = get_option('wse_pro_attach_product_image', 'no');
                if ('yes' === $attach_image) {
                    $image_url = WSE_Pro_Placeholders::get_first_product_image_url($data_source);
                }
            } elseif (isset($data_source->cart_contents)) {
                $attach_image = get_option('wse_pro_abandoned_cart_attach_image', 'no');
                if ('yes' === $attach_image) {
                    $image_url = WSE_Pro_Placeholders::get_first_cart_item_image_url($data_source->cart_contents);
                }
            }
        }
        
        $message_type = (!empty($image_url) && 'yes' === $attach_image) ? 'image' : 'text';

        $body = ['requestType' => 'POST', 'token' => $token, 'from' => $from, 'to' => $full_phone];

        if ('image' === $message_type) {
            $body['messageType'] = 'image';
            $body['imageUrl'] = $image_url;
            $body['caption'] = str_replace('{product_image_url}', '', $message);
        } else {
            $body['messageType'] = 'text';
            $body['text'] = str_replace('{product_image_url}', '', $message);
        }

        $response = wp_remote_post($this->api_url, ['body' => wp_json_encode($body), 'headers' => ['Content-Type' => 'application/json'], 'timeout' => 30]);

        return $this->handle_response($response, $full_phone, $data_source, $type);
    }
    
    /**
     * Formatea un número de teléfono con el código de país si es necesario.
     *
     * @param string $phone       Número de teléfono.
     * @param string $country_iso Código ISO del país.
     * @return string             Número de teléfono formateado.
     */
    private function format_phone($phone, $country_iso) {
        $phone = preg_replace('/[^\d]/', '', $phone);
        $default_code = get_option('wse_pro_default_country_code');
        
        $calling_code = !empty($country_iso) ? ($this->country_codes[$country_iso] ?? $default_code) : $default_code;

        if ($calling_code && strpos($phone, $calling_code) !== 0) {
            if (strlen($phone) > 10 && (substr($phone, 0, strlen($calling_code)) === $calling_code)) {
                return $phone;
            }
            return $calling_code . ltrim($phone, '0');
        }
        return $phone;
    }
    
    /**
     * Procesa la respuesta de la API y la registra.
     *
     * @param WP_Error|array $response    Respuesta de wp_remote_post.
     * @param string         $phone       Número de teléfono al que se envió.
     * @param mixed          $data_source Objeto de datos (pedido o carrito).
     * @param string         $type        Tipo de destinatario.
     * @return array                      Resultado de la operación.
     */
    private function handle_response($response, $phone, $data_source, $type = 'customer') {
        $order_id_log = 'N/A';
        if ($data_source && is_a($data_source, 'WC_Order')) $order_id_log = '#' . $data_source->get_id();
        if ($data_source && isset($data_source->cart_contents)) $order_id_log = 'Cart #' . $data_source->id;
        if (is_null($data_source)) $order_id_log = 'Test';
        
        $recipient_log = ('admin' === $type) ? __('Admin', 'woowapp-smsenlinea-pro') : __('Cliente', 'woowapp-smsenlinea-pro');

        if (is_wp_error($response)) {
            $error = $response->get_error_message();
            $this->log(sprintf('Fallo API (Ref: %s, Dest: %s). Error: %s', $order_id_log, $recipient_log, $error));
            if($data_source && is_a($data_source, 'WC_Order')) $data_source->add_order_note(sprintf(__('Error WhatsApp (%s): %s', 'woowapp-smsenlinea-pro'), $recipient_log, $error));
            return ['success' => false, 'message' => $error];
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!empty($body['success'])) {
            $note = sprintf(__('Notificación WhatsApp enviada a %s (%s).', 'woowapp-smsenlinea-pro'), $recipient_log, $phone);
            $this->log(sprintf('Éxito (Ref: %s, Tel: %s, Dest: %s). ID: %s', $order_id_log, $phone, $recipient_log, $body['data']['messageId'] ?? 'N/A'));
            if($data_source && is_a($data_source, 'WC_Order')) $data_source->add_order_note($note);
            return ['success' => true, 'message' => __('Enviado exitosamente.', 'woowapp-smsenlinea-pro')];
        } else {
            $error = $body['solution'] ?? $body['message'] ?? __('Error desconocido', 'woowapp-smsenlinea-pro');
            $note = sprintf(__('Fallo al enviar WhatsApp a %s (%s). Razón: %s', 'woowapp-smsenlinea-pro'), $recipient_log, $phone, $error);
            $this->log(sprintf('Fallo (Ref: %s, Tel: %s, Dest: %s). Razón: %s', $order_id_log, $phone, $recipient_log, $error));
            if($data_source && is_a($data_source, 'WC_Order')) $data_source->add_order_note($note);
            return ['success' => false, 'message' => $error];
        }
    }

    /**
     * Escribe un mensaje en el registro de WooCommerce.
     *
     * @param string $message Mensaje para registrar.
     */
    private function log($message) {
        if ('yes' === get_option('wse_pro_enable_log', 'yes') && class_exists('WC_Logger')) {
            $logger = wc_get_logger();
            $logger->add(self::$log_handle, $message);
        }
    }

    /**
     * Maneja la llamada AJAX para el botón de prueba de envío.
     */
    public static function ajax_send_test_whatsapp() {
        check_ajax_referer('wse_pro_send_test_nonce', 'security');
        $handler = new self();
        $test_number = isset($_POST['test_number']) ? sanitize_text_field($_POST['test_number']) : '';
        $test_message = "✅ " . __('¡Mensaje de prueba desde tu tienda! La API de SMSenlinea funciona.', 'woowapp-smsenlinea-pro');
        $result = $handler->send_message($test_number, $test_message, null, 'test');
        wp_send_json_success($result);
    }
}