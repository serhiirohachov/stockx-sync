<?php

// File: includes/class-frontend-display.php
namespace StockXSync;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Display StockX URL on single product page
 */
class Frontend_Display {
    public function __construct() {
        add_action( 'woocommerce_single_product_summary', [ $this, 'display_stockx_link' ], 25 );
    }

    public function display_stockx_link() {
        global $product;
        if ( ! $product || ! method_exists( $product, 'get_sku' ) ) {
            return;
        }
        $sku = $product->get_sku();
        if ( empty( $sku ) ) {
            return;
        }

        $fetcher = new StockX_Fetcher();
        $url     = $fetcher->get_product_url_by_sku( $sku );
        if ( $url ) {
            echo '<p class="stockx-link"><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer" class="button">' . esc_html__( 'View on StockX', 'stockx-sync' ) . '</a></p>';
        }
    }
}
new Frontend_Display();