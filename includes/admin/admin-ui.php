<?php
namespace StockXSync;

// WooCommerce Variation Sync Button
add_action('woocommerce_product_after_variable_attributes', function($loop, $variation_data, $variation) {
    $stockx_url = get_post_meta($variation->ID, '_stockx_product_url', true);
    ?>
    <div class="stockx-sync-row">
        <button type="button" class="button stockx-sync-variation" data-id="<?php echo esc_attr($variation->ID); ?>">
            <?php esc_html_e('Sync StockX Price', 'stockx-sync'); ?>
        </button>
        <div class="stockx-progress-variation" style="width: 100%; background: #f1f1f1; border: 1px solid #ccc; margin-top: 5px; display: none;">
            <div class="stockx-bar" style="width: 0%; height: 15px; background: #2271b1;"></div>
        </div>
        <span class="stockx-sync-status" style="margin-top: 4px; display: block;"></span>
        <label><?php _e('StockX URL:', 'stockx-sync'); ?></label>
        <input type="text" name="stockx_product_url_<?php echo esc_attr($variation->ID); ?>" value="<?php echo esc_attr($stockx_url); ?>" style="width:100%;" readonly />
    </div>
    <?php
}, 10, 3);

// Single product StockX sync button
add_action('woocommerce_product_options_general_product_data', function () {
    global $post;
    ?>
    <div class="options_group">
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
            });
        });
    });
    </script>
    <?php
});

// WooCommerce submenu for bulk fetch
add_action('admin_menu', function () {
    add_submenu_page(
        'woocommerce',
        __('StockX Fetch URLs', 'stockx-sync'),
        __('StockX Fetch URLs', 'stockx-sync'),
        'manage_woocommerce',
        'stockx-fetch-all-urls',
        function () {
            ?>
            <div class="wrap">
                <h1><?php _e('Get StockX URLs for All Products', 'stockx-sync'); ?></h1>
                <p><?php _e('Ця дія отримає посилання на всі варіації продуктів зі StockX за допомогою Selenium. Зачекайте до завершення.', 'stockx-sync'); ?></p>
                <button id="get_all_stockx_urls" class="button button-primary"><?php _e('Fetch Now', 'stockx-sync'); ?></button>
                <div id="stockx-progress-container" style="width: 100%; background: #f1f1f1; border: 1px solid #ccc; margin-top: 10px; display: none;">
                    <div id="stockx-progress-bar" style="width: 0%; height: 20px; background: #2271b1;"></div>
                </div>
                <div id="stockx-progress-text" style="margin-top: 5px;"></div>
                <div id="stockx-fetch-status"></div>
            </div>
            <script>
            jQuery(function($){
                $('#get_all_stockx_urls').on('click', function(){
                    var btn = $(this);
                    btn.prop('disabled', true).text('Fetching…');

                    $('#stockx-progress-container').show();
                    var progress = 0;
                    var interval = setInterval(function(){
                        progress += 10;
                        if(progress >= 90) progress = 90;
                        $('#stockx-progress-bar').css('width', progress + '%');
                        $('#stockx-progress-text').text('Зачекайте… ' + progress + '%');
                    }, 400);л

                    $.post(ajaxurl, { action: 'stockx_get_urls_all' }, function(response){
                        clearInterval(interval);
                        $('#stockx-progress-bar').css('width', '100%');
                        $('#stockx-progress-text').text(response.success ? 'Готово: ' + response.data + ' оновлено' : 'Помилка: ' + response.message);
                        $('#stockx-fetch-status').text(response.success ? 'Done: ' + response.data : 'Error: ' + response.message);
                        btn.prop('disabled', false).text('Fetch Now');
                    });
                });
            });
            </script>
            <?php
        }
    );
});
