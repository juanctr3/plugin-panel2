<?php
/**
 * Maneja la lógica de reemplazo de placeholders para los mensajes y define las variables disponibles.
 *
 * @package WooWApp
 * @version 1.9.0
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Clase WSE_Pro_Placeholders.
 */
class WSE_Pro_Placeholders {

    /**
     * Reemplaza todos los placeholders en una plantilla con datos de un pedido.
     * @param string   $template La plantilla con placeholders.
     * @param WC_Order $order    El objeto del pedido.
     * @param array    $extras   Placeholders adicionales (ej. para notas).
     * @return string            La plantilla con los valores reemplazados.
     */
    public static function replace($template, $order, $extras = []) {
        $placeholders = self::get_placeholder_values($order, $extras);
        return str_replace(array_keys($placeholders), array_values($placeholders), $template);
    }
    
    /**
     * Reemplaza placeholders para mensajes de carritos abandonados.
     * @param string   $template La plantilla del mensaje.
     * @param stdClass $cart_row El objeto de la fila de la base de datos del carrito.
     * @return string            El mensaje con los valores reemplazados.
     */
    public static function replace_for_cart($template, $cart_row) {
        $cart_contents = maybe_unserialize($cart_row->cart_contents);
        $items_list = '';
        $first_product_name = '';

        if (is_array($cart_contents)) {
            $first_item = reset($cart_contents);
            if ($first_item && isset($first_item['data']) && is_object($first_item['data'])) {
                $first_product_name = $first_item['data']->get_name();
            }

            foreach ($cart_contents as $item) {
                if (isset($item['data']) && is_object($item['data'])) {
                    $product_name = $item['data']->get_name();
                    $quantity = $item['quantity'];
                    $line_total = self::clean_for_whatsapp(wc_price($item['line_total']));
                    $items_list .= '  - ' . $product_name . ' (x' . $quantity . ") - " . $line_total . "\n";
                }
            }
        }
        
        // Lógica mejorada para obtener el nombre del cliente
        $customer_name = $cart_row->first_name;
        if (empty($customer_name) && !empty($cart_row->checkout_data)) {
            parse_str($cart_row->checkout_data, $checkout_fields);
            $customer_name = $checkout_fields['billing_first_name'] ?? '';
        }
        if (empty($customer_name) && $cart_row->user_id) {
            $user_info = get_userdata($cart_row->user_id);
            if($user_info) $customer_name = $user_info->first_name;
        }
        if (empty($customer_name)) {
            $customer_name = ''; // Valor por defecto si no se encuentra
        }

        $recovery_link = add_query_arg('recover-cart-wse', $cart_row->recovery_token, wc_get_checkout_url());

        $values = [
            '{shop_name}'          => get_bloginfo('name'),
            '{customer_name}'      => trim($customer_name),
            '{cart_items}'         => trim($items_list),
            '{cart_total}'         => self::clean_for_whatsapp(wc_price($cart_row->cart_total)),
            '{checkout_link}'      => $recovery_link,
            '{first_product_name}' => $first_product_name,
        ];

        // Rellenar otros placeholders con valores vacíos para evitar que se muestren.
        $all_placeholders = self::get_all_placeholders_grouped();
        foreach($all_placeholders as $group) {
            foreach($group as $placeholder) {
                if (!isset($values[$placeholder])) {
                    $values[$placeholder] = '';
                }
            }
        }

        return str_replace(array_keys($values), array_values($values), $template);
    }

    /**
     * Limpia una cadena de texto para ser segura y legible en WhatsApp.
     * @param string $string El texto a limpiar.
     * @return string        El texto limpio.
     */
    private static function clean_for_whatsapp($string) {
        if (empty($string)) return '';
        $text = html_entity_decode($string, ENT_QUOTES, 'UTF-8');
        $text = wp_strip_all_tags($text);
        $text = preg_replace('/\s+/', ' ', $text);
        return trim($text);
    }

    /**
     * Obtiene la URL de la imagen del primer producto de un PEDIDO.
     * @param WC_Order $order El objeto del pedido.
     * @return string         La URL de la imagen o una cadena vacía.
     */
    public static function get_first_product_image_url($order) {
        if (!$order || empty($order->get_items())) return '';
        
        $first_item = reset($order->get_items());
        $product = $first_item->get_product();
        if (!$product) return '';

        $image_id = $product->get_image_id();
        if (!$image_id && $product->is_type('variation')) {
            $parent_product = wc_get_product($product->get_parent_id());
            if ($parent_product) {
                $image_id = $parent_product->get_image_id();
            }
        }
        return $image_id ? wp_get_attachment_image_url($image_id, 'full') : '';
    }

    /**
     * Obtiene la URL de la imagen del primer producto de un CARRITO.
     * @param string $cart_contents Contenido del carrito serializado.
     * @return string                 La URL de la imagen o una cadena vacía.
     */
    public static function get_first_cart_item_image_url($cart_contents) {
        $cart_array = maybe_unserialize($cart_contents);
        if (empty($cart_array) || !is_array($cart_array)) return '';

        $first_item = reset($cart_array);
        if (!isset($first_item['data']) || !is_a($first_item['data'], 'WC_Product')) return '';
        
        $product = $first_item['data'];
        $image_id = $product->get_image_id();
        if (!$image_id && $product->is_type('variation')) {
            $parent_product = wc_get_product($product->get_parent_id());
            if ($parent_product) {
                $image_id = $parent_product->get_image_id();
            }
        }
        return $image_id ? wp_get_attachment_image_url($image_id, 'full') : '';
    }
    
    /**
     * Construye el array de valores para todos los placeholders a partir de un pedido.
     * @param WC_Order $order El objeto del pedido.
     * @param array    $extras Placeholders adicionales.
     * @return array           Un array asociativo de placeholder => valor.
     */
    private static function get_placeholder_values($order, $extras = []) {
        $items = $order->get_items();
        $first_item = !empty($items) ? reset($items) : null;
        $first_product = $first_item ? $first_item->get_product() : null;

        $items_list_with_price = '';
        $items_list_no_price = '';
        $items_list_sku = '';
        foreach ($items as $item) {
            $product = $item->get_product();
            $sku = $product ? $product->get_sku() : __('N/A', 'woowapp-smsenlinea-pro');
            $price = self::clean_for_whatsapp(wc_price($item->get_total()));
            $items_list_with_price .= '  - ' . $item->get_name() . ' (x' . $item->get_quantity() . ") - " . $price . "\n";
            $items_list_no_price .= '  - ' . $item->get_name() . ' (x' . $item->get_quantity() . ")\n";
            $items_list_sku .= '  - ' . $item->get_name() . ' (SKU: ' . $sku . ') (x' . $item->get_quantity() . ")\n";
        }
        
        $my_account_link = wc_get_page_permalink('myaccount') ?: '';
        $payment_link = $order->get_checkout_payment_url() ?: '';
        $first_product_link = $first_product ? $first_product->get_permalink() : '';
        $first_product_review_link = $first_product_link ? $first_product_link . '#reviews' : '';

        $values = [
            '{shop_name}' => get_bloginfo('name'),
            '{shop_url}' => home_url(),
            '{my_account_link}' => $my_account_link,
            '{order_id}' => $order->get_order_number(),
            '{order_status}' => wc_get_order_status_name($order->get_status()),
            '{order_date}' => wc_format_datetime($order->get_date_created()),
            '{order_total}' => self::clean_for_whatsapp($order->get_formatted_order_total()),
            '{order_subtotal}' => self::clean_for_whatsapp($order->get_subtotal_to_display()),
            '{order_total_raw}' => $order->get_total(),
            '{order_currency}' => html_entity_decode(get_woocommerce_currency_symbol()),
            '{order_items}' => trim($items_list_with_price),
            '{order_items_no_price}' => trim($items_list_no_price),
            '{order_items_sku}' => trim($items_list_sku),
            '{order_item_count}' => $order->get_item_count(),
            '{order_shipping_total}' => self::clean_for_whatsapp(wc_price($order->get_shipping_total())),
            '{order_tax_total}' => self::clean_for_whatsapp(wc_price($order->get_total_tax())),
            '{order_discount_total}' => self::clean_for_whatsapp(wc_price($order->get_discount_total())),
            '{order_admin_url}' => $order->get_edit_order_url(),
            '{payment_method}' => $order->get_payment_method_title(),
            '{payment_link}' => $payment_link,
            '{shipping_method}' => $order->get_shipping_method(),
            '{customer_name}' => $order->get_billing_first_name(),
            '{customer_lastname}' => $order->get_billing_last_name(),
            '{customer_fullname}' => $order->get_formatted_billing_full_name(),
            '{billing_email}' => $order->get_billing_email(),
            '{billing_phone}' => $order->get_billing_phone(),
            '{billing_address}' => self::clean_for_whatsapp($order->get_formatted_billing_address()),
            '{shipping_address}' => self::clean_for_whatsapp($order->get_formatted_shipping_address()),
            '{customer_note}' => $order->get_customer_note(),
            '{first_product_name}' => $first_product ? $first_product->get_name() : '',
            '{first_product_link}' => $first_product_link,
            '{first_product_review_link}' => $first_product_review_link,
            '{product_image_url}' => self::get_first_product_image_url($order),
        ];

        return array_merge($values, $extras);
    }

    /**
     * Define los placeholders disponibles para la UI del admin, agrupados por categoría.
     * @return array
     */
    public static function get_all_placeholders_grouped() {
        return [
            __('Tienda', 'woowapp-smsenlinea-pro') => ['{shop_name}', '{shop_url}', '{my_account_link}'],
            __('Pedido (General)', 'woowapp-smsenlinea-pro') => ['{order_id}', '{order_status}', '{order_date}', '{order_admin_url}'],
            __('Pedido (Totales)', 'woowapp-smsenlinea-pro') => ['{order_total}', '{order_subtotal}', '{order_shipping_total}', '{order_tax_total}', '{order_discount_total}', '{order_currency}', '{order_total_raw}'],
            __('Pedido (Listas de Productos)', 'woowapp-smsenlinea-pro') => ['{order_items}', '{order_items_no_price}', '{order_items_sku}', '{order_item_count}'],
            __('Pagos y Envíos', 'woowapp-smsenlinea-pro') => ['{payment_method}', '{payment_link}', '{shipping_method}'],
            __('Cliente', 'woowapp-smsenlinea-pro') => ['{customer_name}', '{customer_lastname}', '{customer_fullname}', '{billing_email}', '{billing_phone}', '{customer_note}'],
            __('Direcciones', 'woowapp-smsenlinea-pro') => ['{billing_address}', '{shipping_address}'],
            __('Producto (Primer Ítem)', 'woowapp-smsenlinea-pro') => ['{first_product_name}', '{first_product_link}', '{first_product_review_link}', '{product_image_url}'],
            __('Carrito Abandonado', 'woowapp-smsenlinea-pro') => ['{cart_items}', '{cart_total}', '{checkout_link}', '{customer_name}']
        ];
    }
    
    /**
     * Define los emojis disponibles para la UI del admin, agrupados por categoría.
     * @return array
     */
    public static function get_all_emojis_grouped() {
        return [
            __('Caras y Emociones', 'woowapp-smsenlinea-pro') => ['😀', '😃', '😄', '😊', '😉', '😍', '😘', '🤗', '🤔', '😎', '🥳', '😥', '😭', '🤯', '🤩', '😇'],
            __('Gestos y Personas', 'woowapp-smsenlinea-pro') => ['👋', '👍', '👎', '👌', '✌️', '🤞', '🙏', '🙌', '💪', '👈', '👉', '👆', '👇', '👀', '👤', '👥'],
            __('Comercio y Dinero', 'woowapp-smsenlinea-pro') => ['🛒', '🛍️', '🎁', '📦', '💰', '💵', '💶', '💷', '💳', '🧾', '💯', '💲', '✅', '✨', '🔥', '🎉'],
            __('Objetos y Tecnología', 'woowapp-smsenlinea-pro') => ['📱', '💻', '📞', '✉️', '📨', '📤', '📎', '💡', '🔧', '⚙️', '🔒', '🔑', '🔔', '📢', '🔍'],
            __('Transporte y Lugares', 'woowapp-smsenlinea-pro') => ['🚚', '🛵', '✈️', '🚢', '🚀', '🏠', '🏢', '📍', '🗺️', '🌍', '⏰', '⏳', '⏱️', '📅', '🗓️'],
            __('Símbolos y Alertas', 'woowapp-smsenlinea-pro') => ['❤️', '💔', '⭐', '✔️', '❌', '⭕', '❗', '❓', 'ℹ️', '⚠️', '➡️', '⬅️', '⬆️', '⬇️', '🔄'],
            __('Animales y Naturaleza', 'woowapp-smsenlinea-pro') => ['🐶', '🐱', '🦋', '🌸', '☀️', '🌈', '⚡', '💧', '🌊'],
            __('Comida y Bebida', 'woowapp-smsenlinea-pro') => ['☕', '🍽️', '🍕', '🍔', '🍟', '🎂', '🍎', '🍇', '🍓']
        ];
    }
}
