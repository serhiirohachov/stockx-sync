<?php
namespace StockXSync;

use WP_CLI;
use WP_CLI_Command;

if (!class_exists('\WP_CLI')) return;

class CLI_Commands extends WP_CLI_Command {

    public static function init() {
        WP_CLI::add_command('stockx-sync', self::class);
    }

    public function get_slug_safe($sku): ?string {
        try {
            $client = new SeleniumClient();
            $slug = $client->getSlugBySku($sku);
            $client->cleanup();
            return $slug;
        } catch (\Throwable $e) {
            WP_CLI::warning("❌ {$sku} → " . $e->getMessage());
            return null;
        }
    }

    public function sync_by_sku($args) {
        $sku = $args[0] ?? null;
        if (!$sku) WP_CLI::error("SKU is required");

        $products = wc_get_products(['sku' => $sku, 'limit' => 1]);
        if (empty($products)) WP_CLI::error("Product with SKU {$sku} not found.");

        $product = $products[0];
        $pid = $product->get_id();
        $client = new SeleniumClient();

        try {
            $slug = $client->getSlugBySku($sku);
            if (!$slug) {
                throw new \Exception("No slug resolved for {$sku}");
            }

            $url = 'https://stockx.com' . $slug;
            update_post_meta($pid, '_stockx_product_base_url', $url);
            WP_CLI::success("Set base URL: {$url}");

            if ($product->is_type('variable')) {
                foreach ($product->get_children() as $variation_id) {
                    $_POST['variation_id'] = $variation_id;

                    ob_start(); do_action('wp_ajax_stockx_sync_variation_url'); ob_end_clean();
                    ob_start(); do_action('wp_ajax_stockx_sync_variation_price'); ob_end_clean();

                    WP_CLI::log("↪ Synced variation {$variation_id}");
                    sleep(2);
                }
            } elseif ($product->is_type('simple')) {
                $_POST['product_id'] = $pid;
                ob_start(); do_action('wp_ajax_stockx_sync_simple_price'); ob_end_clean();
                WP_CLI::success("💲 Synced simple product price.");
            }

        } catch (\Throwable $e) {
            WP_CLI::error("Failed for SKU {$sku}: " . $e->getMessage());
        } finally {
            $client->cleanup();
        }

        WP_CLI::success("🎯 Sync complete for SKU: {$sku}");
    }

    public function get_price($args) {
        [$style, $size] = $args + [null, null];
        if (!$style || !$size) WP_CLI::error("Usage: wp stockx-sync get-price <STYLE> <SIZE>");

        try {
            $client = new SeleniumClient();
            $price = $client->get_price($style, $size);
            $client->cleanup();
            WP_CLI::success("Price: {$price}");
        } catch (\Throwable $e) {
            WP_CLI::error("Error: " . $e->getMessage());
        }
    }
}

CLI_Commands::init();
