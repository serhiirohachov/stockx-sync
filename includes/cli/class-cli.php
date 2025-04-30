<?php
namespace StockXSync;

class CLI {
    public static function init() {
        \WP_CLI::add_command( 'stockx-sync get-price', [ __CLASS__, 'get_price' ] );
        \WP_CLI::add_command( 'stockx-sync sync-all', [ __CLASS__, 'sync_all' ] );
        \WP_CLI::add_command( 'stockx-sync sync-by-sku', [ __CLASS__, 'sync_by_sku' ] );
        \WP_CLI::add_command( 'stockx-slug', function( $args ) {
            if ( empty( $args[0] ) ) {
                \WP_CLI::error( "SKU is required. Usage: wp stockx-slug <SKU>" );
            }

            $sku = $args[0];
            $fetcher = new StockXFetcher();
            $slug = $fetcher->getSlugBySku( $sku );

            \WP_CLI::log( "Slug for SKU {$sku}: {$slug}" );
        } );
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
}
