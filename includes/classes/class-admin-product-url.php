<?php
namespace StockXSync;

if ( ! defined( 'ABSPATH' ) ) exit;

class Admin_Product_URL {
    public function __construct() {
        // Legacy meta box
        add_action( 'add_meta_boxes', [ $this, 'add_meta_box' ] );
        add_action( 'save_post_product', [ $this, 'save_url_meta' ], 10, 1 );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_scripts' ] );

        // Add controls to Product Data > Inventory (SKU) section
        add_action( 'woocommerce_product_options_sku', [ $this, 'inventory_tab_controls' ] );
        add_action( 'woocommerce_process_product_meta', [ $this, 'save_inventory_tab_controls' ], 20, 2 );

        // AJAX fetch
        add_action( 'wp_ajax_stockx_fetch_url', [ $this, 'ajax_fetch_url' ] );
    }

    // Adds button and input under SKU in inventory tab
    public function inventory_tab_controls() {
        global $post;
        $sku   = get_post_meta( $post->ID, '_sku', true );
        $url   = get_post_meta( $post->ID, '_stockx_url', true );
        echo '<p class="form-field stockx_url_field">
                <label for="stockx_url_input">' . esc_html__( 'StockX URL', 'stockx-sync' ) . '</label>
                <input type="text" id="stockx_url_input" name="stockx_url_input" value="' . esc_attr( $url ) . '" placeholder="Paste or fetch via SKU" />
                <button type="button" class="button button-secondary" id="stockx_fetch_btn" data-post_id="' . esc_attr( $post->ID ) . '">' . esc_html__( 'Fetch by SKU', 'stockx-sync' ) . '</button>
                <span class="description">' . esc_html__( 'or enter URL manually', 'stockx-sync' ) . '</span>
              </p>';
    }

    // Save manual input from inventory tab
    public function save_inventory_tab_controls( $post_id, $post ) {
        if ( isset( $_POST['stockx_url_input'] ) ) {
            update_post_meta( $post_id, '_stockx_url', esc_url_raw( wp_unslash( $_POST['stockx_url_input'] ) ) );
        }
    }

    public function add_meta_box() {
        add_meta_box(
            'stockx-url-metabox',
            __( 'StockX URL', 'stockx-sync' ),
            [ $this, 'render_meta_box' ],
            'product',
            'side'
        );
    }

    public function render_meta_box( $post ) {
        wp_nonce_field( 'stockx_url_nonce', 'stockx_url_nonce' );
        $value = get_post_meta( $post->ID, '_stockx_url', true );
        echo '<input type="text" id="stockx_url_field" name="stockx_url" value="' . esc_attr( $value ) . '" style="width:100%;" placeholder="Paste or fetch via SKU" />';
        echo '<p><button type="button" id="stockx_fetch_url_button" class="button button-primary" data-post_id="' . esc_attr( $post->ID ) . '">' . esc_html__( 'Fetch from StockX', 'stockx-sync' ) . '</button></p>';
        echo '<div id="stockx_fetch_result" style="margin-top:5px;"></div>';
    }

    public function save_url_meta( $post_id ) {
        if ( ! isset( $_POST['stockx_url_nonce'] ) || ! wp_verify_nonce( $_POST['stockx_url_nonce'], 'stockx_url_nonce' ) ) return;
        if ( isset( $_POST['stockx_url'] ) ) {
            update_post_meta( $post_id, '_stockx_url', esc_url_raw( wp_unslash( $_POST['stockx_url'] ) ) );
        }
    }

    public function enqueue_scripts( $hook ) {
        global $post;
        if ( ( $hook === 'post.php' || $hook === 'post-new.php' ) && isset( $post ) && $post->post_type === 'product' ) {
            wp_enqueue_script(
                'stockx-admin',
                plugin_dir_url( __FILE__ ) . '../assets/js/admin-stockx.js',
                [ 'jquery' ],
                '1.0',
                true
            );
            wp_localize_script( 'stockx-admin', 'StockXAdmin', [
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'post_id'  => $post->ID,
            ] );
        }
    }

    public function ajax_fetch_url() {
        if ( ! current_user_can( 'edit_posts' ) ) wp_send_json_error( 'Permission denied' );
        $post_id = intval( $_POST['post_id'] );
        $product = wc_get_product( $post_id );
        if ( ! $product ) wp_send_json_error( 'Invalid product' );
        $sku     = $product->get_sku();
        if ( empty( $sku ) ) wp_send_json_error( 'SKU is empty' );
        $fetcher = new StockX_Fetcher();
        $url     = $fetcher->get_product_url_by_sku( $sku );
        if ( $url ) wp_send_json_success( $url );
        wp_send_json_error( 'URL not found' );
    }
}
new Admin_Product_URL();