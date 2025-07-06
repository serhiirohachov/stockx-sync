<?php
namespace StockXSync;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CLI {
    /**
     * Register WP-CLI commands
     */
    public static function init() {
        if ( ! defined('WP_CLI') || ! WP_CLI ) {
            return;
        }

        \WP_CLI::add_command('stockx-sync get-price',    [ __CLASS__, 'get_price' ]);
        \WP_CLI::add_command('stockx-sync sync-all',      [ __CLASS__, 'sync_all' ]);
        \WP_CLI::add_command('stockx-sync sync-by-sku',   [ __CLASS__, 'sync_by_sku' ]);
        \WP_CLI::add_command('stockx-sync get-url-by-sku',[ __CLASS__, 'get_url_by_sku' ]);
        \WP_CLI::add_command('stockx-sync sync-all-urls', [ __CLASS__, 'sync_all_urls' ]);
        \WP_CLI::add_command('stockx-sync sync-missing-urls', [ __CLASS__, 'sync_missing_urls' ]);
    }

    /**
     * Fetch price for a given style and size
     */
    public static function get_price( $args ) {
        list( $style, $size ) = $args + [ null, null ];

        if ( ! $style || ! $size ) {
            \WP_CLI::error('Usage: wp stockx-sync get-price <STYLE> <SIZE>');
        }

        try {
            $price = ( new SeleniumClient() )->get_price( $style, $size );
            \WP_CLI::success( "Price: {$price}" );
        } catch ( \Exception $e ) {
            \WP_CLI::error( get_class( $e ) . ': ' . ( $e->getMessage() ?: 'Unknown error occurred' ) );
        }
    }

    /**
     * Sync all products (stub implementation)
     */
    public static function sync_all( $args, $assoc_args ) {
        \WP_CLI::log('sync_all logic goes here.');
    }

    /**
     * Sync a product by its SKU (stub implementation)
     */
    public static function sync_by_sku( $args, $assoc_args ) {
        \WP_CLI::log('sync_by_sku logic goes here.');
    }

    /**
     * Fetch and set StockX Base URL by SKU and update product meta
     * Usage: wp stockx-sync get-url-by-sku <SKU> <POST_ID>
     */
    public static function get_url_by_sku( $args ) {
        list( $sku, $post_id ) = $args + [ null, null ];

        if ( ! $sku || ! $post_id ) {
            \WP_CLI::error('Usage: wp stockx-sync get-url-by-sku <SKU> <POST_ID>');
        }

        try {
            $client = new SeleniumClient();
            $slug   = $client->getSlugBySku( $sku );

            if ( ! $slug || ! str_starts_with( $slug, '/' ) ) {
                \WP_CLI::error("Failed to find slug for SKU {$sku}");
            }

            $url = 'https://stockx.com' . $slug;
                WP_CLI::log("[DEBUG] Constructed base URL: $url");
            update_post_meta( (int) $post_id, '_stockx_product_base_url', $url );

            \WP_CLI::success("StockX Base URL for product {$post_id} set to: {$url}");
        } catch ( \Throwable $e ) {
            \WP_CLI::error('Error: ' . $e->getMessage());
        }
    }

    /**
     * Sync all variation URLs for variable products
     */
    public static function sync_all_urls( $args, $assoc_args ) {
        $products = wc_get_products([ 'type' => 'variable', 'limit' => -1 ]);
        $total = 0;

        foreach ( $products as $product ) {
            try {
                $sku    = $product->get_sku();
                $client = new SeleniumClient();
                $slug   = $client->getSlugBySku( $sku );

                if ( $slug && str_starts_with( $slug, '/' ) ) {
                    $base_url = 'https://stockx.com' . $slug;
                    update_post_meta( $product->get_id(), '_stockx_product_base_url', $base_url );

                    foreach ($product->get_children() as $variation_id) {
                        WP_CLI::log("[DEBUG] Processing variation: $variation_id"); {
                        $variation = wc_get_product( $variation_id );
                        $size      = $variation->get_attribute('pa_size');

                        if ( $size ) {
                            $variation_url = $base_url . '?catchallFilters=' . basename( $base_url ) . '&size=' . urlencode( $size );
                            update_post_meta( $variation_id, '_stockx_product_url', $variation_url );
                            \WP_CLI::log("✅ {$sku} ({$size}) → {$variation_url}");
                            $total++;
                        }
                    }
                }
            } catch ( \Throwable $e ) {
                \WP_CLI::warning("⚠️  {$sku} → " . $e->getMessage());
            }
        }

        \WP_CLI::success("🎯 Total updated: {$total} variations.");
    }

    /**
     * Sync missing variation URLs only
     */
    public static function sync_missing_urls( $args, $assoc_args ) {
        $products = wc_get_products([ 'type' => 'variable', 'limit' => -1 ]);
        $total = 0;

        foreach ( $products as $product ) {
            foreach ($product->get_children() as $variation_id) {
                        WP_CLI::log("[DEBUG] Processing variation: $variation_id"); {
                $variation = wc_get_product( $variation_id );
                $sku       = $variation->get_sku();
                $existing  = get_post_meta( $variation_id, '_stockx_product_url', true );

                if ( $sku && empty( $existing ) ) {
                    try {
                        $client = new SeleniumClient();
                        $slug   = $client->getSlugBySku( $sku );

                        if ( $slug && str_starts_with($slug, '/') ) {
                            $url = 'https://stockx.com' . $slug;
                WP_CLI::log("[DEBUG] Constructed base URL: $url");
                            update_post_meta( $variation_id, '_stockx_product_url', $url );
                            \WP_CLI::log("✅ {$sku} → {$url}");
                            $total++;
                        }
                    } catch ( \Throwable $e ) {
                        \WP_CLI::warning("⚠️  {$sku} → " . $e->getMessage());
                    }
                }
            }
        }

        \WP_CLI::success("🔎 Missing URLs updated: {$total}");
    }
}

// Initialize CLI commands
CLI::init();