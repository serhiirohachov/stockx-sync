<?php
namespace StockXSync;

// Масове отримання base_url по SKU для основного продукту
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
        <button type="button" class="button button-primary" id="stockx-sync-all">Sync All URLs & Prices</button>
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

        $('#stockx-sync-all').on('click', function(){
            var btn = $(this);
            btn.prop('disabled', true).text('Working...');
            $.post(ajaxurl, {
                action: 'stockx_sync_all_variations',
                product_id: <?php echo (int) get_the_ID(); ?>
            }, function(response){
                alert(response.success ? ('✅ Done: ' + response.data + ' synced.') : ('❌ ' + response.message));
                btn.prop('disabled', false).text('Sync All URLs & Prices');
            });
        });
    });
    </script>
    <?php
});

// Variation UI fields and actions
add_action('woocommerce_product_after_variable_attributes', function($loop, $variation_data, $variation_post) {
    $variation = new \WC_Product_Variation($variation_post->ID);
    $product_id = $variation->get_parent_id();
    $base_url = get_post_meta($product_id, '_stockx_product_base_url', true);
    $size = $variation->get_attribute('pa_size');
    $variation_url = ($base_url && $size) ? $base_url . '?catchallFilters=' . basename($base_url) . '&size=' . $size : get_post_meta($variation->get_id(), '_stockx_product_url', true);
    ?>
    <div class="stockx-sync-row" style="margin-top: 15px;">
        <label><?php _e('StockX URL', 'stockx-sync'); ?></label>
        <input type="text" name="stockx_product_url_<?php echo esc_attr($variation->get_id()); ?>" value="<?php echo esc_attr($variation_url); ?>" style="width: 100%;" />
        <button type="button" class="button stockx-sync-fetch-url" data-id="<?php echo esc_attr($variation->get_id()); ?>">Get URL</button>
        <button type="button" class="button stockx-sync-fetch-price" data-id="<?php echo esc_attr($variation->get_id()); ?>">Get Price</button>
        <button type="button" class="button stockx-sync-variation-url" data-id="<?php echo esc_attr($variation->get_id()); ?>">Sync URL from base</button>
        <div class="stockx-sync-status" style="margin-top: 5px; font-size: 13px;"></div>
    </div>
    <?php
}, 10, 3);

// Save manual URL input
add_action('woocommerce_save_product_variation', function($variation_id, $i) {
    if (isset($_POST["stockx_product_url_{$variation_id}"])) {
        update_post_meta(
            $variation_id,
            '_stockx_product_url',
            esc_url_raw($_POST["stockx_product_url_{$variation_id}"])
        );
    }
}, 10, 2);

// AJAX: Get base URL from SKU
add_action('wp_ajax_stockx_get_url_single', function () {
    $product_id = absint($_POST['product_id']);
    $product = wc_get_product($product_id);
    if (! $product || ! $product->get_sku()) wp_send_json_error(['message' => 'No SKU found']);
    try {
        $client = new SeleniumClient();
        $slug = $client->getSlugBySku($product->get_sku());
        if ($slug && str_starts_with($slug, '/')) {
            $url = 'https://stockx.com' . $slug;
            update_post_meta($product_id, '_stockx_product_base_url', $url);
            wp_send_json_success($url);
        }
        wp_send_json_error(['message' => 'No valid slug returned']);
    } catch (\Throwable $e) {
        wp_send_json_error(['message' => $e->getMessage()]);
    }
});

// AJAX: Sync URL from base
add_action('wp_ajax_stockx_sync_variation_url', function () {
    $variation_id = absint($_POST['variation_id']);
    $variation = wc_get_product($variation_id);
    $parent_id = wp_get_post_parent_id($variation_id);
    $base_url = get_post_meta($parent_id, '_stockx_product_base_url', true);
    $size = $variation->get_attribute('pa_size');
    if (! $variation || ! $variation->get_sku()) wp_send_json_error(['message' => 'Missing SKU']);
    if (! $base_url || ! $size) wp_send_json_error(['message' => 'Base URL or size not set']);
    $full_url = $base_url . '?catchallFilters=' . basename($base_url) . '&size=' . $size;
    update_post_meta($variation_id, '_stockx_product_url', $full_url);
    wp_send_json_success($full_url);
});

// AJAX: Mass sync all variations
add_action('wp_ajax_stockx_sync_all_variations', function () {
    $product_id = absint($_POST['product_id'] ?? 0);
    $product = wc_get_product($product_id);
    if (! $product || ! $product->is_type('variable')) wp_send_json_error(['message' => 'Invalid product']);
    $children = $product->get_children();
    $base_url = get_post_meta($product_id, '_stockx_product_base_url', true);
    $count = 0;
    foreach ($children as $variation_id) {
        $variation = wc_get_product($variation_id);
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
        if ($base_url && $size) {
            $full_url = $base_url . '?catchallFilters=' . basename($base_url) . '&size=' . $size;
            update_post_meta($variation_id, '_stockx_product_url', $full_url);
        }
        try {
            $slug = basename($base_url);
            $price = (new SeleniumClient())->get_price($slug, $size);
            if ($price) {
                update_post_meta($variation_id, '_price', $price);
                update_post_meta($variation_id, '_regular_price', $price);
                $count++;
            }
        } catch (\Throwable $e) {
            continue;
        }
    }
    wp_send_json_success($count);
});

// JS actions
add_action('admin_footer', function () {
    $screen = get_current_screen();
    if ($screen && $screen->base === 'post' && $screen->post_type === 'product') {
        ?>
        <script>
        jQuery(document).ready(function($){
            $('.stockx-sync-fetch-url').on('click', function(){
                const btn = $(this);
                const id = btn.data('id');
                const row = btn.closest('.stockx-sync-row');
                const status = row.find('.stockx-sync-status');
                status.text('Fetching URL…');
                $.post(ajaxurl, {
                    action: 'stockx_fetch_variation_url',
                    variation_id: id
                }, function(response){
                    if (response.success) {
                        row.find('input[type="text"]').val(response.data);
                        status.text('✅ URL: ' + response.data);
                    } else {
                        status.text('❌ ' + response.data);
                    }
                });
            });

            $('.stockx-sync-fetch-price').on('click', function(){
                const btn = $(this);
                const id = btn.data('id');
                const row = btn.closest('.stockx-sync-row');
                const status = row.find('.stockx-sync-status');
                status.text('Fetching price…');
                $.post(ajaxurl, {
                    action: 'stockx_fetch_variation_price',
                    variation_id: id
                }, function(response){
                    if (response.success) {
                        status.text('💲 Price: ' + response.data + ' грн');
                    } else {
                        status.text('❌ ' + response.data);
                    }
                });
            });

            $('.stockx-sync-variation-url').on('click', function(){
                const button = $(this);
                const variationId = button.data('id');
                const row = button.closest('.stockx-sync-row');
                const status = row.find('.stockx-sync-status');
                status.text('Syncing…');
                $.post(ajaxurl, { action: 'stockx_sync_variation_url', variation_id: variationId }, function(response) {
                    if (response.success) {
                        row.find('input[type="text"]').val(response.data);
                        status.text('✅ URL synced');
                    } else {
                        status.text('❌ ' + response.message);
                    }
                });
            });
        });
        </script>
        <?php
    }
});