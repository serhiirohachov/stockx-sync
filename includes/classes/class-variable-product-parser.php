<?php
namespace StockXSync;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles parsing and syncing of variable products from StockX
 */
class Variable_Product_Parser {
    /** @var StockX_Fetcher */
    protected $fetcher;

    public function __construct( StockX_Fetcher $fetcher ) {
        $this->fetcher = $fetcher;
    }

    /**
     * Syncs all variations for a given WC variable product
     *
     * @param \WC_Product_Variable $product
     */
    public function sync_variations_for_product( \WC_Product_Variable $product ) {
        $sku        = $product->get_sku();
        $stockx_url = $this->fetcher->get_product_url_by_sku( $sku );

        if ( $stockx_url ) {
            update_post_meta( $product->get_id(), '_stockx_url', esc_url_raw( $stockx_url ) );
        }

        // Loop through each variation
        foreach ( $product->get_children() as $variation_id ) {
            $variation = wc_get_product( $variation_id );
            // Assume 'pa_size' is the size attribute slug
            $size  = $variation->get_attribute( 'pa_size' );
            $price = $this->fetcher->get_price_by_url_and_size( $stockx_url, $size );

            if ( false !== $price ) {
                $variation->set_price( floatval( $price ) );
                $variation->save();
            }
        }
    }
}