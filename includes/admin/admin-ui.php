<?php
namespace StockXSync;

// Admin UI Hooks for WooCommerce
add_action('woocommerce_product_after_variable_attributes', function($loop, $variation_data, $variation) {
    $product_id = wp_get_post_parent_id($variation->ID);
    $base_url = get_post_meta($product_id, '_stockx_product_base_url', true);
    $size = $variation->get_attribute('pa_size');
    $variation_url = ($base_url && $size) ? $base_url . '?catchallFilters=' . basename($base_url) . '&size=' . $size : get_post_meta($variation->ID, '_stockx_product_url', true);
    ?>
    <div class="stockx-sync-row">
        <label><?php _e('StockX URL:', 'stockx-sync'); ?></label>
        <input type="text" name="stockx_product_url_<?php echo esc_attr($variation->ID); ?>" value="<?php echo esc_attr($variation_url); ?>" style="width:100%;" readonly />
        <button type="button" class="button stockx-sync-variation-url" data-id="<?php echo esc_attr($variation->ID); ?>">Sync URL</button>
        <span class="stockx-sync-status" style="margin-top: 4px; display: block;"></span>
    </div>
    <?php
}, 10, 3);

add_action('woocommerce_product_options_general_product_data', function () {
    global $post;
    $base_url = get_post_meta($post->ID, '_stockx_product_base_url', true);
    ?>
    <div class="options_group">
        <p class="form-field">
            <label for="stockx_base_url"><?php _e('StockX Base URL', 'stockx-sync'); ?></label>
            <input type="text" id="stockx_base_url" value="<?php echo esc_attr($base_url); ?>" class="short" readonly>
        </p>
        <button type="button" class="button" id="get_stockx_url_single">Get StockX URL for this Product</button>
    </div>
    <script>
    jQuery(function($){
        $('#get_stockx_url_single').on('click', function(){
            var data = {
                action: 'stockx_get_url_single',
                product_id: <?php echo (int) get_the_ID(); ?>
            };
            $.post(ajaxurl, data, function(response){
                alert(response.data ? 'URL fetched: ' + response.data : 'Error: ' + response.message);
                location.reload();
            });
        });

        $('.stockx-sync-variation-url').on('click', function(){
            const button = $(this);
            const variationId = button.data('id');
            button.prop('disabled', true).text('Syncing...');
            $.post(ajaxurl, { action: 'stockx_sync_variation_url', variation_id: variationId }, function(response) {
                if (response.success) {
                    button.siblings('input').val(response.data);
                    button.siblings('.stockx-sync-status').text('✅ URL synced');
                } else {
                    button.siblings('.stockx-sync-status').text('❌ ' + response.message);
                }
                button.prop('disabled', false).text('Sync URL');
            });
        });
    });
    </script>
    <?php
});

add_action('wp_ajax_stockx_get_url_single', function () {
    $product_id = absint($_POST['product_id']);
    $product = wc_get_product($product_id);

    if (! $product || ! $product->get_sku()) {
        wp_send_json_error(['message' => 'No SKU found']);
    }

    try {
        $client = new \StockXSync\SeleniumClient();
        $slug = $client->getSlugBySku($product->get_sku());

        if ($slug && str_starts_with($slug, '/')) {
            $url = 'https://stockx.com' . $slug;
            update_post_meta($product_id, '_stockx_product_base_url', $url);
            wp_send_json_success($url);
        } else {
            wp_send_json_error(['message' => 'No valid slug returned']);
        }
    } catch (\Throwable $e) {
        wp_send_json_error(['message' => $e->getMessage()]);
    }
});

add_action('wp_ajax_stockx_sync_variation_url', function () {
    $variation_id = absint($_POST['variation_id']);
    $variation = wc_get_product($variation_id);
    $parent_id = wp_get_post_parent_id($variation_id);
    $base_url = get_post_meta($parent_id, '_stockx_product_base_url', true);
    $size = $variation->get_attribute('pa_size');

    if (! $variation || ! $variation->get_sku()) {
        wp_send_json_error(['message' => 'Missing SKU']);
    }

    if (! $base_url || ! $size) {
        wp_send_json_error(['message' => 'Base URL or size not set']);
    }

    $full_url = $base_url . '?catchallFilters=' . basename($base_url) . '&size=' . $size;
    update_post_meta($variation_id, '_stockx_product_url', $full_url);
    wp_send_json_success($full_url);
});
