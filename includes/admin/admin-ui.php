<?php
namespace StockXSync;

add_action('woocommerce_product_after_variable_attributes', function($loop, $variation_data, $variation) {
    <div class="stockx-sync-row">
        <button type="button" class="button stockx-sync-variation" data-id="<?php echo esc_attr($variation->ID); ?>">
            <?php esc_html_e('Sync StockX Price', 'stockx-sync'); ?>
        </button>
        <div class="stockx-progress-variation" style="width: 100%; background: #f1f1f1; border: 1px solid #ccc; margin-top: 5px; display: none;">
            <div class="stockx-bar" style="width: 0%; height: 15px; background: #2271b1;"></div>
        </div>
        <span class="stockx-sync-status" style="margin-top: 4px; display: block;"></span>
    </div>
    
    $stockx_url = get_post_meta($variation->ID, '_stockx_product_url', true);
    ?>
    <div>
        <label><?php _e('StockX URL:', 'stockx-sync'); ?></label>
        <input type="text" name="stockx_product_url_<?php echo esc_attr($variation->ID); ?>" value="<?php echo esc_attr($stockx_url); ?>" style="width:100%;" readonly />
    </div>
    <?php
}, 10, 3);

add_action('woocommerce_product_options_general_product_data', function () {
    global $post;
    echo '<div class="options_group">';
    echo '<button type="button" class="button" id="get_stockx_url_single">Get StockX URL for this Product</button>';
    echo '</div>';
    ?>
    <script>
    jQuery(document).ready(function($){
        $('.stockx-sync-variation').off('click').on('click', function(){
            var btn = $(this);
            var vid = btn.data('id');
            var row = btn.closest('.stockx-sync-row');
            var status = row.find('.stockx-sync-status');
            var bar = row.find('.stockx-progress-variation');
            var barfill = row.find('.stockx-bar');
            status.text('');
            bar.show();
            var progress = 0;
            barfill.css('width', '0%');
            var interval = setInterval(function(){
                progress += 50;
                if(progress >= 90) progress = 90;
                barfill.css('width', progress + '%');
            }, 400);

            $.post(ajaxurl, {
                action: 'stockx_sync_variation_price',
                variation_id: vid
            }, function(response){
                clearInterval(interval);
                barfill.css('width', '100%');
                status.text(response.success ? 'Оновлено: ' + response.data : 'Помилка: ' + response.data);
            });
        });
    });
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
            jQuery(document).ready(function($){
        $('.stockx-sync-variation').off('click').on('click', function(){
            var btn = $(this);
            var vid = btn.data('id');
            var row = btn.closest('.stockx-sync-row');
            var status = row.find('.stockx-sync-status');
            var bar = row.find('.stockx-progress-variation');
            var barfill = row.find('.stockx-bar');
            status.text('');
            bar.show();
            var progress = 0;
            barfill.css('width', '0%');
            var interval = setInterval(function(){
                progress += 50;
                if(progress >= 90) progress = 90;
                barfill.css('width', progress + '%');
            }, 400);

            $.post(ajaxurl, {
                action: 'stockx_sync_variation_price',
                variation_id: vid
            }, function(response){
                clearInterval(interval);
                barfill.css('width', '100%');
                status.text(response.success ? 'Оновлено: ' + response.data : 'Помилка: ' + response.data);
            });
        });
    });
                $('#get_all_stockx_urls').on('click', function(){
                    var btn = $(this);
                    btn.prop('disabled', true).text('Fetching…');
                    
        $('#stockx-progress-container').show();
        var progress = 0;
        var interval = setInterval(function(){
            progress += 10;
            if(progress >= 90) progress = 90;
            $('#stockx-progress-bar').css('width', progress + '%');
            $('#stockx-progress-text').text('Зачекайте, обробка триває… ' + progress + '%');
        }, 500);
        $.post(ajaxurl, { action: 'stockx_get_urls_all' }, function(response){
            clearInterval(interval);
            $('#stockx-progress-bar').css('width', '100%');
            $('#stockx-progress-text').text(response.success ? 'Готово: оновлено ' + response.data + ' варіацій.' : 'Помилка: ' + response.message);

                        $('#stockx-fetch-status').text(response.success ? 'Done: ' + response.data + ' updated' : 'Error: ' + response.message);
                        btn.prop('disabled', false).text('Fetch Now');
                    });
                });
            });
            </script>
            <?php
        }
);

add_action('wp_ajax_stockx_get_url_single', function() {
    $product_id = (int) ($_POST['product_id'] ?? 0);
    if (!$product_id) wp_send_json_error('Missing product_id');

    $product = wc_get_product($product_id);
    if (!$product || ! $product->is_type('variable')) {
        wp_send_json_error('Invalid product');
    }

    $children = $product->get_children();
    if (empty($children)) wp_send_json_error('No variations found');

    // Try to get first valid variation with SKU
    foreach ($children as $variation_id) {
        $variation = wc_get_product($variation_id);
        if ($variation && $variation->get_sku()) {
            $sku = $variation->get_sku();
            $fetcher = new \StockXSync\StockXFetcher();
            $slug = $fetcher->getSlugBySku($sku);
            if ($slug && str_starts_with($slug, '/')) {
                $url = "https://stockx.com" . $slug;
                update_post_meta($product_id, '_stockx_product_url', $url);
                wp_send_json_success($url);
            }
        }
    }

    wp_send_json_error('No valid SKU or slug found');
});


        });
    });
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
            jQuery(document).ready(function($){
        $('.stockx-sync-variation').off('click').on('click', function(){
            var btn = $(this);
            var vid = btn.data('id');
            var row = btn.closest('.stockx-sync-row');
            var status = row.find('.stockx-sync-status');
            var bar = row.find('.stockx-progress-variation');
            var barfill = row.find('.stockx-bar');
            status.text('');
            bar.show();
            var progress = 0;
            barfill.css('width', '0%');
            var interval = setInterval(function(){
                progress += 50;
                if(progress >= 90) progress = 90;
                barfill.css('width', progress + '%');
            }, 400);

            $.post(ajaxurl, {
                action: 'stockx_sync_variation_price',
                variation_id: vid
            }, function(response){
                clearInterval(interval);
                barfill.css('width', '100%');
                status.text(response.success ? 'Оновлено: ' + response.data : 'Помилка: ' + response.data);
            });
        });
    });
                $('#get_all_stockx_urls').on('click', function(){
                    var btn = $(this);
                    btn.prop('disabled', true).text('Fetching…');
                    
        $('#stockx-progress-container').show();
        var progress = 0;
        var interval = setInterval(function(){
            progress += 10;
            if(progress >= 90) progress = 90;
            $('#stockx-progress-bar').css('width', progress + '%');
            $('#stockx-progress-text').text('Зачекайте, обробка триває… ' + progress + '%');
        }, 500);
        $.post(ajaxurl, { action: 'stockx_get_urls_all' }, function(response){
            clearInterval(interval);
            $('#stockx-progress-bar').css('width', '100%');
            $('#stockx-progress-text').text(response.success ? 'Готово: оновлено ' + response.data + ' варіацій.' : 'Помилка: ' + response.message);

                        $('#stockx-fetch-status').text(response.success ? 'Done: ' + response.data + ' updated' : 'Error: ' + response.message);
                        btn.prop('disabled', false).text('Fetch Now');
                    });
                });
            });
            </script>
            <?php
        }
);

add_action('wp_ajax_stockx_get_url_single', function() {
    $product_id = (int) ($_POST['product_id'] ?? 0);
    if (!$product_id) wp_send_json_error('Missing product_id');
    $product = wc_get_product($product_id);
    if (!$product) wp_send_json_error('Invalid product');

    $count = 0;
    foreach ($product->get_children() as $variation_id) {
        $variation = wc_get_product($variation_id);
        $sku = $variation->get_sku();
        if ($sku) {
            $fetcher = new \StockXSync\StockXFetcher();
            $slug = $fetcher->getSlugBySku($sku);
            if ($slug && str_starts_with($slug, '/')) {
                $url = "https://stockx.com" . $slug;
                update_post_meta($variation_id, '_stockx_product_url', $url);
                $count++;
            }
        }
    }
    wp_send_json_success($count);
});

add_action('wp_ajax_stockx_get_urls_all', function() {
    $products = wc_get_products(['type' => 'variable', 'limit' => -1]);
    $count = 0;
    foreach ($products as $product) {
        foreach ($product->get_children() as $variation_id) {
            $variation = wc_get_product($variation_id);
            $sku = $variation->get_sku();
            if ($sku) {
                $fetcher = new \StockXSync\StockXFetcher();
                $slug = $fetcher->getSlugBySku($sku);
                if ($slug && str_starts_with($slug, '/')) {
                    $url = "https://stockx.com" . $slug;
                    update_post_meta($variation_id, '_stockx_product_url', $url);
                    $count++;
                }
            }
        }
    }
    wp_send_json_success($count);
});


    <div class="stockx-sync-row">
        <button type="button" class="button stockx-sync-variation" data-id="<?php echo esc_attr($variation->ID); ?>">
            <?php esc_html_e('Sync StockX Price', 'stockx-sync'); ?>
        </button>
        <div class="stockx-progress-container" style="width: 100%; background: #f1f1f1; border: 1px solid #ccc; margin-top: 5px; display: none;">
            <div class="stockx-progress-bar" style="width: 0%; height: 12px; background: #46b450;"></div>
        </div>
        <span class="stockx-sync-status" style="margin-top: 5px; display:block;"></span>
    </div>
    <script>
    jQuery(document).ready(function($){
        $('.stockx-sync-variation').off('click').on('click', function(){
            var btn = $(this);
            var vid = btn.data('id');
            var container = btn.closest('.stockx-sync-row');
            var progressBar = container.find('.stockx-progress-bar');
            var progressWrap = container.find('.stockx-progress-container');
            var status = container.find('.stockx-sync-status');
            status.text('Синхронізація…');
            progressWrap.show();
            progressBar.css('width', '0%');

            let progress = 0;
            const interval = setInterval(function(){
                progress += 20;
                if (progress >= 90) progress = 90;
                progressBar.css('width', progress + '%');
            }, 300);

            $.post(ajaxurl, {
                action: 'stockx_sync_variation_price',
                variation_id: vid
            }, function(response){
                clearInterval(interval);
                progressBar.css('width', '100%');
                if (response.success) {
                    status.text('Оновлено: ' + response.data + ' грн');
                } else {
                    status.text('Помилка: ' + response.data);
                }
            });
        });
    });
    </script>
