<?php
namespace StockXSync;

if ( ! defined( 'ABSPATH' ) ) exit;

class Ajax_Handlers {

    public static function init() {
        add_action('wp_ajax_stockx_sync_variation_url', [self::class, 'sync_variation_url']);
        add_action('wp_ajax_stockx_sync_variation_price', [self::class, 'sync_variation_price']);
    }

    public static function sync_variation_url() {
        $variation_id = absint($_POST['variation_id'] ?? 0);
        if (!$variation_id) wp_send_json_error(['message' => 'Missing variation ID']);

        $variation = wc_get_product($variation_id);
        if (!$variation || !$variation->is_type('variation')) {
            wp_send_json_error(['message' => 'Invalid variation']);
        }

        $parent_id = wp_get_post_parent_id($variation_id);
        $base_url = get_post_meta($parent_id, '_stockx_product_base_url', true);
        if (!$base_url) {
            wp_send_json_error(['message' => 'Base URL not set']);
        }

        $size = sanitize_text_field($_POST['size'] ?? '');
        if (empty($size)) {
            foreach ($variation->get_attributes() as $key => $val) {
                if (strpos($key, 'size') !== false && !empty($val)) {
                    $size = $val;
                    break;
                }
            }
        }

        if (!$size) {
            wp_send_json_error(['message' => 'Size not set']);
        }

        $url = $base_url . '?catchallFilters=' . basename($base_url) . '&size=' . urlencode($size);
        update_post_meta($variation_id, '_stockx_product_url', $url);

        wp_send_json_success(['url' => $url]);
    }

    public static function sync_variation_price() {
        $variation_id = absint($_POST['variation_id'] ?? 0);
        if (!$variation_id) wp_send_json_error(['message' => 'Missing variation ID']);

        $variation = wc_get_product($variation_id);
        if (!$variation || !$variation->is_type('variation')) {
            wp_send_json_error(['message' => 'Invalid variation']);
        }

        $parent_id = wp_get_post_parent_id($variation_id);
        $style = get_post_meta($parent_id, '_sku', true);
        if (!$style) {
            wp_send_json_error(['message' => 'Missing style/SKU']);
        }

        $size = sanitize_text_field($_POST['size'] ?? '');
        if (empty($size)) {
            foreach ($variation->get_attributes() as $key => $val) {
                if (strpos($key, 'size') !== false && !empty($val)) {
                    $size = $val;
                    break;
                }
            }
        }

        if (!$size) {
            wp_send_json_error(['message' => 'Size not set']);
        }

        try {
            $client = new SeleniumClient();
            $raw_price = $client->get_price($style, $size);

            if (!is_numeric($raw_price)) {
                throw new \Exception('StockX price not found');
            }

            // 💱 Apply conversion rate (USD → local currency)
            $rate = 42;
            $margin = 1.30;
            $final_price = round($raw_price * $rate * $margin, 2);

            $variation->set_regular_price($final_price);
            $variation->save();

            wp_send_json_success([
                'price' => $final_price,
                'raw_price' => $raw_price,
                'rate' => $rate,
                'size' => $size,
            ]);
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }
}
