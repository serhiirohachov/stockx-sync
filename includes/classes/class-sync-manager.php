<?php
namespace StockXSync;

use StockXSync\SeleniumClient;
use StockXSync\StockXClient;
use WC_Logger;

/**
 * Handles synchronization of product prices with StockX.
 */
class SyncManager {
    /**
     * Apply exchange rate and markup.
     */
    protected static function apply_markup(float $usd_price): float {
        $rate = 42;
        $markup_factor = 1 + 0.30;
        return round($usd_price * $rate * $markup_factor, 2);
    }

    public static function run(): int {
        $count = 0;
        $products = wc_get_products([
            'status' => 'publish',
            'type'   => ['variable', 'variation'],
            'limit'  => -1,
        ]);
        foreach ($products as $product) {
            if ($product->is_type('variable')) {
                foreach ($product->get_children() as $vid) {
                    $variation = wc_get_product($vid);
                    if ($variation instanceof \WC_Product_Variation && ! $variation->is_in_stock()) {
                        $count += self::update_variation_price($variation);
                    }
                }
            } elseif ($product->is_type('variation') && ! $product->is_in_stock()) {
                $count += self::update_variation_price($product);
            }
        }
        if (defined('DOING_AJAX') && DOING_AJAX) {
            wp_send_json_success(['count' => $count]);
        }
        return $count;
    }

    protected static function update_variation_price(\WC_Product_Variation $variation): int {
        try {
            $style = trim($variation->get_sku());
            $attributes = $variation->get_attributes();
            if (empty($style) || empty($attributes)) return 0;
            $size = reset($attributes);
            $client = new SeleniumClient();
            $raw_price = $client->get_price($style, $size);
            if ($raw_price !== null && is_numeric($raw_price)) {
                $final_price = self::apply_markup((float)$raw_price);
                if ($final_price != $variation->get_regular_price()) {
                    $variation->set_regular_price($final_price);
                    $variation->save();
                    wc_delete_product_transients($variation->get_id());
                    return 1;
                }
            }
        } catch (\Exception $e) {
            $logger = new WC_Logger();
            $logger->error($e->getMessage(), ['source' => 'stockx-sync']);
            wp_mail(get_option('stockx_sync_email_notify'), 'StockX Sync Error', $e->getMessage());
        }
        return 0;
    }

    public static function sync_product_variations(int $product_id, string $url): void {
        $product = wc_get_product($product_id);
        if (! $product || ! $product->is_type('variable')) return;
        $size_attr = 'pa_size';
        foreach ($product->get_children() as $variation_id) {
            $variation = wc_get_product($variation_id);
            if (! $variation instanceof \WC_Product_Variation) continue;
            $size = $variation->get_attribute($size_attr);
            if (! $size) continue;
            $slug = basename(parse_url($url, PHP_URL_PATH));
            $variation_url = $url . '?catchallFilters=' . urlencode($slug) . '&size=' . urlencode($size);
            $raw_price = StockXClient::get_price_by_url($variation_url);
            if ($raw_price !== false && is_numeric($raw_price)) {
                $final_price = self::apply_markup((float)$raw_price);
                if ($final_price != $variation->get_regular_price()) {
                    $variation->set_regular_price($final_price);
                    $variation->save();
                    wc_delete_product_transients($variation->get_id());
                    error_log("StockXSync: Updated {$product_id} / {$size} → {$final_price}");
                }
            }
        }
    }

    public static function sync_simple_product(int $product_id, string $url): void {
        $product = wc_get_product($product_id);
        if (! $product || ! $product->is_type('simple')) return;
        $slug = basename(parse_url($url, PHP_URL_PATH));
        $client = new SeleniumClient();
        $raw_price = $client->get_price($slug, '');
        if ($raw_price === null || ! is_numeric($raw_price)) {
            error_log("StockXSync: Could not fetch raw price for simple product {$product_id}");
            return;
        }
        $final_price = self::apply_markup((float)$raw_price);
        $product->set_regular_price($final_price);
        $product->save();
        wc_delete_product_transients($product_id);
        error_log("StockXSync: Updated simple product {$product_id} → {$final_price}");
    }

    public static function ajax_run(): void {
        self::run();
    }
}
