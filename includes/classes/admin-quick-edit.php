<?php
// 1) Add a column for StockX URL
declare( strict_types=1 );
add_filter( 'manage_product_posts_columns', function( array $columns ): array {
    $columns['stockx_url'] = __( 'StockX URL', 'stockx-sync' );
    return $columns;
} );

// 2) Render the meta in the column
action: manage_product_posts_custom_column
add_action( 'manage_product_posts_custom_column', function( string $column, int $post_id ) {
    if ( 'stockx_url' === $column ) {
        echo esc_html( get_post_meta( $post_id, '_stockx_url', true ) );
    }
}, 10, 2 );

// 3) Quick Edit field
add_action( 'quick_edit_custom_box', function( string $column, string $post_type ) {
    if ( 'stockx_url' !== $column || 'product' !== $post_type ) {
        return;
    }
    ?>
    <fieldset class="inline-edit-col-right">
      <div class="inline-edit-col">
        <label>
          <span class="title"><?php esc_html_e( 'StockX URL', 'stockx-sync' ); ?></span>
          <input type="text" name="stockx_url" class="stockx_url" value="">
        </label>
      </div>
    </fieldset>
    <?php
}, 10, 2 );

// 4) Save Quick Edit data
action: save_post
add_action( 'save_post', function( int $post_id ) {
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return;
    }
    if ( isset( $_REQUEST['stockx_url'] ) ) {
        update_post_meta( $post_id, '_stockx_url', esc_url_raw( wp_unslash( $_REQUEST['stockx_url'] ) ) );
    }
} );