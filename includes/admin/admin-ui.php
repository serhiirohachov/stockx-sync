<?php
namespace StockXSync;

// Admin UI Hooks for WooCommerce
add_action('woocommerce_product_after_variable_attributes', function($loop, $variation_data, $variation) {
    $product_id = wp_get_post_parent_id($variation->ID);
    $base_url = get_post_meta($product_id, '_stockx_product_base_url', true);

    $size = '';
    if (method_exists($variation, 'get_attributes')) {
        $attributes = $variation->get_attributes();
        if (!empty($attributes)) {
            foreach ($attributes as $attr => $value) {
                if (!empty($value)) {
                    $size = $value;
                    break;
                }
            }
        }
    }

    $variation_url = ($base_url && $size) ? $base_url . '?catchallFilters=' . basename($base_url) . '&size=' . urlencode($size) : get_post_meta($variation->ID, '_stockx_product_url', true);
    ?>
    <div class="stockx-sync-row">
        <label><?php _e('StockX URL:', 'stockx-sync'); ?></label>
        <input type="text" name="stockx_product_url_<?php echo esc_attr($variation->ID); ?>" value="<?php echo esc_attr($variation_url); ?>" style="width:100%;" />
        <button type="button" class="button stockx-sync-variation-url" data-id="<?php echo esc_attr($variation->ID); ?>">Sync URL</button>
        <button type="button" class="button stockx-sync-variation-price" data-id="<?php echo esc_attr($variation->ID); ?>">Sync Price</button>
        <span class="stockx-sync-status" style="margin-top: 4px; display: block;"></span>
    </div>
    <?php
}, 10, 3);

// ...rest of the code remains unchanged...


add_action('woocommerce_product_options_general_product_data', function () {
    global $post;
    $base_url = get_post_meta($post->ID, '_stockx_product_base_url', true);
    ?>
    <div class="options_group">
        <p class="form-field">
            <label for="stockx_base_url"><?php _e('StockX Base URL', 'stockx-sync'); ?></label>
            <input type="text" id="stockx_base_url" value="<?php echo esc_attr($base_url); ?>" class="short">
        </p>
        <button type="button" class="button" id="get_stockx_url_single">Get StockX URL for this Product</button>
        <button type="button" class="button button-primary" id="stockx_sync_all">Sync All URLs & Prices</button>
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

        $('.stockx-sync-variation-price').on('click', function(){
            const button = $(this);
            const variationId = button.data('id');
            button.prop('disabled', true).text('Syncing...');
            $.post(ajaxurl, { action: 'stockx_sync_variation_price', variation_id: variationId }, function(response) {
                if (response.success) {
                    button.siblings('.stockx-sync-status').text('✅ Price synced: ' + response.data);
                } else {
                    button.siblings('.stockx-sync-status').text('❌ ' + response.message);
                }
                button.prop('disabled', false).text('Sync Price');
            });
        });

        $('#stockx_sync_all').on('click', function(){
            var btn = $(this);
            btn.prop('disabled', true).text('Syncing all...');
            $.post(ajaxurl, {
                action: 'stockx_sync_all_variation_data',
                product_id: <?php echo (int) get_the_ID(); ?>
            }, function(response){
                alert(response.success ? 'All synced: ' + response.data : 'Error: ' + response.message);
                btn.prop('disabled', false).text('Sync All URLs & Prices');
                location.reload();
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

    if (! $size) {
        $attributes = $variation->get_attributes();
        foreach ($attributes as $key => $val) {
            if (str_contains($key, 'size')) {
                $size = $val;
                break;
            }
        }
    }

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

add_action('wp_ajax_stockx_sync_variation_price', function () {
    $variation_id = absint($_POST['variation_id']);
    $variation = wc_get_product($variation_id);
    $url = get_post_meta($variation_id, '_stockx_product_url', true);

    if (! $variation || ! $url) {
        wp_send_json_error(['message' => 'Missing variation or StockX URL']);
    }

    $size = $variation->get_attribute('pa_size');
    if (! $size) {
        $attributes = $variation->get_attributes();
        foreach ($attributes as $key => $val) {
            if (str_contains($key, 'size')) {
                $size = $val;
                break;
            }
        }
    }

    try {
        $client = new \StockXSync\SeleniumClient();
        $parsed_url = parse_url($url);
        $slug = trim($parsed_url['path'], '/');
        $price = $client->get_price($slug, $size);

        if ($price) {
            $variation->set_price($price);
            $variation->set_regular_price($price);
            $variation->save();
            wp_send_json_success($price);
        } else {
            wp_send_json_error(['message' => 'Price not found']);
        }
    } catch (\Throwable $e) {
        wp_send_json_error(['message' => $e->getMessage()]);
    }
});

add_action('wp_ajax_stockx_sync_all_variation_data', function () {
    $product_id = absint($_POST['product_id']);
    $product = wc_get_product($product_id);

    if (! $product || ! $product->is_type('variable')) {
        wp_send_json_error(['message' => 'Invalid variable product']);
    }

    $count = 0;
    foreach ($product->get_children() as $variation_id) {
        $_POST['variation_id'] = $variation_id;
        ob_start();
        do_action('wp_ajax_stockx_sync_variation_url');
        ob_end_clean();

        ob_start();
        do_action('wp_ajax_stockx_sync_variation_price');
        ob_end_clean();
        $count++;
    }
    wp_send_json_success($count);
});