<?php
// Колонка в таблиці продуктів
add_filter('manage_edit-product_columns', function($columns) {
    $columns['stockx_url'] = 'StockX URL';
    return $columns;
});

add_action('manage_product_posts_custom_column', function($column, $post_id) {
    if ($column === 'stockx_url') {
        $url = get_post_meta($post_id, '_stockx_product_base_url', true);
        echo '<input type="text" class="stockx-url-input" data-product="' . esc_attr($post_id) . '" value="' . esc_url($url) . '" style="width:90%;" />';
        echo '<button class="button stockx-save-url" data-product="' . esc_attr($post_id) . '">💾</button>';
        echo '<button class="button stockx-sync-product" data-product="' . esc_attr($post_id) . '">🔄</button>';
    }
}, 10, 2);

// JS+AJAX підключається в окремому файлі admin-stockx.js
