<?php
namespace StockXSync;

class SyncManager {
    public static function run() {
        $count = 0;

        $products = wc_get_products([
            'status' => 'publish',
            'type'   => [ 'variable', 'variation' ],
            'limit'  => -1,
        ]);

        foreach ($products as $product) {
            if ($product->is_type('variable')) {
                $variations = $product->get_children();
                foreach ($variations as $vid) {
                    $variation = wc_get_product($vid);
                    if ($variation && ! $variation->is_in_stock()) {
                        $count += self::update_variation_price($variation);
                    }
                }
            } elseif ($product->is_type('variation')) {
                if (! $product->is_in_stock()) {
                    $count += self::update_variation_price($product);
                }
            }
        }

        if (defined('DOING_AJAX') && DOING_AJAX) {
            wp_send_json_success([ 'count' => $count ]);
        }
        return $count;
    }

    protected static function update_variation_price($variation): int {
        try {
            $style = $variation->get_sku();
            $attrs = $variation->get_attributes();
            if (empty($style) || empty($attrs)) {
                return 0;
            }
            $size = reset($attrs);
            $price = (new SeleniumClient())->get_price($style, $size);
            if ($price !== null && $price != $variation->get_price()) {
                $variation->set_price($price);
                $variation->save();
                return 1;
            }
        } catch (\Exception $e) {
            if (class_exists('WC_Logger')) {
                (new \WC_Logger())->error($e->getMessage(), [ 'source' => 'stockx-sync' ]);
            }
            wp_mail(get_option('stockx_sync_email_notify'), 'StockX Sync Error', $e->getMessage());
        }
        return 0;
    }

    public static function ajax_run() {
        self::run();
    }
}
