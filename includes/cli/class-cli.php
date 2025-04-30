<?php
namespace StockXSync;

class CLI {
    public static function init() {
        \WP_CLI::add_command( 'stockx-sync get-price', [ __CLASS__, 'get_price' ] );
        \WP_CLI::add_command( 'stockx-sync sync-all', [ __CLASS__, 'sync_all' ] );
        \WP_CLI::add_command( 'stockx-sync sync-by-sku', [ __CLASS__, 'sync_by_sku' ] );
        \WP_CLI::add_command( 'stockx-sync get-url-by-sku', [ __CLASS__, 'get_url_by_sku' ]);
    
    }

    public static function get_price( $args ) {
        list( $style, $size ) = $args;

        try {
            $price = ( new SeleniumClient() )->get_price( $style, $size );
            \WP_CLI::success( "Price: {$price}" );
        } catch ( \Exception $e ) {
            \WP_CLI::error( get_class( $e ) . ': ' . ( $e->getMessage() ?: 'Unknown error occurred' ) );
        }
    }

    public static function sync_all( $args, $assoc_args ) {
        // Implementation similar to plugin file...
        \WP_CLI::log("sync_all logic goes here.");
    }

    public static function sync_by_sku( $args, $assoc_args ) {
        // Implementation similar to plugin file...
        \WP_CLI::log("sync_by_sku logic goes here.");
    }

    public static function get_url_by_sku( $args, $assoc_args ) {
        if (empty($args[0])) {
            \WP_CLI::error("SKU is required. Usage: wp stockx-sync get-url-by-sku <SKU>");
        }
    
        $sku = $args[0];
    
        try {
            $client = new SeleniumClient();
            $slug = $client->getSlugBySku($sku);
    
            if ($slug) {
                \WP_CLI::success("Found StockX URL for {$sku}: https://stockx.com{$slug}");
            } else {
                \WP_CLI::error("Failed to find StockX URL for {$sku}");
            }
    
        } catch (\Throwable $e) {
            \WP_CLI::error('Error: ' . $e->getMessage());
        }
    }
    
    public static function sync_all_urls( $args, $assoc_args ) {
        $products = wc_get_products(['type' => 'variable', 'limit' => -1]);
        $total = 0;
    
        foreach ($products as $product) {
            foreach ($product->get_children() as $variation_id) {
                $variation = wc_get_product($variation_id);
                $sku = $variation->get_sku();
                if ($sku) {
                    try {
                        $client = new \StockXSync\SeleniumClient();
                        $slug = $client->getSlugBySku($sku);
                        if ($slug && str_starts_with($slug, '/')) {
                            $url = "https://stockx.com" . $slug;
                            update_post_meta($variation_id, '_stockx_product_url', $url);
                            \WP_CLI::log("✅ $sku → $url");
                            $total++;
                        }
                    } catch (\Throwable $e) {
                        \WP_CLI::warning("⚠️  $sku → " . $e->getMessage());
                    }
                }
            }
        }
    
        \WP_CLI::success("🎯 Total updated: $total variations.");
    }
    
    
    
    
}
