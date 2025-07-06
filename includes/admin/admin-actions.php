<?php
// AJAX дії

add_action('wp_ajax_stockx_save_product_url', function(){
    $pid = absint($_POST['product_id']);
    $url = esc_url_raw($_POST['url'] ?? '');
    update_post_meta($pid, '_stockx_product_base_url', $url);
    wp_send_json_success();
});

add_action('wp_ajax_stockx_get_all_urls', function(){
    // Тут логіка витягування URL-ів зі StockX по SKU
    // Ідея: пройтись по всіх продуктів і оновити _stockx_product_base_url
    wp_send_json_success(['message' => 'URL-и отримані']);
});

add_action('wp_ajax_stockx_sync_all_prices_global', function(){
    // Тут логіка синхронізації всіх варіацій
    wp_send_json_success(['message' => 'Ціни синхронізовані']);
});

add_action('wp_ajax_stockx_generate_urls', function() {
    $product_id = absint($_POST['product_id'] ?? 0);
    if (!$product_id) wp_send_json_error('Invalid ID');

    try {
        stockx_generate_variation_urls($product_id);
        wp_send_json_success('URLs generated and saved');
    } catch (\Throwable $e) {
        wp_send_json_error($e->getMessage());
    }
});
function stockx_generate_variation_urls($product_id) {
    $product = wc_get_product($product_id);
    if (! $product || ! $product->is_type('variable')) return;

    $base_url = get_post_meta($product_id, '_stockx_product_base_url', true);
    if (!$base_url) return;

    $children = $product->get_children();
    foreach ($children as $vid) {
        $variation = wc_get_product($vid);
        $attributes = $variation->get_attributes();
        $size = '';

        foreach ($attributes as $attr => $value) {
            if (strpos($attr, 'size') !== false) {
                $size = SizeMapper::get_us_size($value) ?? $value;
                break;
            }
        }

        if (!$size) continue;

        $variation_url = $base_url . '?size=' . urlencode($size);
        update_post_meta($vid, '_stockx_product_url', $variation_url);
    }
}
