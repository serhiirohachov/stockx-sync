<?php
namespace StockXSync;

add_action('woocommerce_product_after_variable_attributes', function($loop, $variation_data, $variation_post) {
    $variation = new \WC_Product_Variation($variation_post->ID);
    $stockx_url = get_post_meta($variation->get_id(), '_stockx_product_url', true);
    ?>
    <div class="stockx-sync-row" style="margin-top: 15px;">
        <label><?php _e('StockX URL (manual or auto)', 'stockx-sync'); ?></label>
        <input type="text" name="stockx_product_url_<?php echo esc_attr($variation->get_id()); ?>" value="<?php echo esc_attr($stockx_url); ?>" style="width: 100%;" />
        <button type="button" class="button stockx-sync-fetch-url" data-id="<?php echo esc_attr($variation->get_id()); ?>" style="margin-top: 5px;"><?php _e('Get from StockX', 'stockx-sync'); ?></button>
        <div class="stockx-sync-status" style="margin-top: 5px; display: block; font-size: 13px;"></div>
    </div>
    <?php
}, 10, 3);

// JS для кнопки отримання URL
add_action('admin_footer', function () {
    $screen = get_current_screen();
    if ($screen && $screen->base === 'post' && $screen->post_type === 'product') {
        ?>
        <script>
        jQuery(document).ready(function($){
            $('.stockx-sync-fetch-url').on('click', function(){
                const btn = $(this);
                const id = btn.data('id');
                const status = btn.closest('.stockx-sync-row').find('.stockx-sync-status');
                status.text('Зачекайте…');

                $.post(ajaxurl, {
                    action: 'stockx_fetch_variation_url',
                    variation_id: id
                }, function(response){
                    if (response.success) {
                        btn.closest('.stockx-sync-row').find('input[type="text"]').val(response.data);
                        status.text('✅ Отримано: ' + response.data);
                    } else {
                        status.text('❌ Помилка: ' + response.data);
                    }
                });
            });
        });
        </script>
        <?php
    }
});

// Ajax handler
add_action('wp_ajax_stockx_fetch_variation_url', function() {
    $variation_id = (int) ($_POST['variation_id'] ?? 0);
    if (!$variation_id) wp_send_json_error('Invalid ID');

    $variation = wc_get_product($variation_id);
    if (!$variation || ! $variation->get_sku()) wp_send_json_error('No SKU');

    $fetcher = new \StockXSync\StockXFetcher();
    $slug = $fetcher->getSlugBySku($variation->get_sku());

    if ($slug && str_starts_with($slug, '/')) {
        $url = 'https://stockx.com' . $slug;
        update_post_meta($variation_id, '_stockx_product_url', $url);
        wp_send_json_success($url);
    }

    wp_send_json_error('Slug not found');
});

// Зберігання ручного URL
add_action('woocommerce_save_product_variation', function($variation_id, $i) {
    if (isset($_POST["stockx_product_url_{$variation_id}"])) {
        update_post_meta(
            $variation_id,
            '_stockx_product_url',
            sanitize_text_field($_POST["stockx_product_url_{$variation_id}"])
        );
    }
}, 10, 2);
