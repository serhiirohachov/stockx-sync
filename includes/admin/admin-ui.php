<?php

namespace StockXSync;

$size_map = require plugin_dir_path(__FILE__) . 'size-mappings.php';

add_action('woocommerce_product_after_variable_attributes', function($loop, $variation_data, $variation) use ($size_map) {
    $product_id = wp_get_post_parent_id($variation->ID);
    $base_url   = get_post_meta($product_id, '_stockx_product_base_url', true);

    $manual_url = get_post_meta($variation->ID, '_stockx_product_url', true);
    $product = wc_get_product($variation->ID);
    $size = '';

    if ($product && method_exists($product, 'get_attributes')) {
        foreach ($product->get_attributes() as $attr => $value) {
            if (!empty($value)) {
                $size = $value;
                break;
            }
        }
    }

    // === MAP EU Size to US Size ===
    $attribute_key = 'attribute_pa_nikejordeu'; // замініть на динамічний ключ при потребі
    $us_size = $size;
    if (!empty($size_map[$attribute_key][$size]['US Size'])) {
        $us_size = $size_map[$attribute_key][$size]['US Size'];
    }

    // === GENERATE FINAL URL ===
    if (!empty($manual_url)) {
        $variation_url = $manual_url;
    } elseif (!empty($base_url) && !empty($us_size)) {
        $variation_url = $base_url . '?catchallFilters=' . basename($base_url) . '&size=' . urlencode($us_size);
    } elseif (!empty($base_url)) {
        $variation_url = $base_url;
    } else {
        $variation_url = '';
    }
    ?>
<div class="stockx-sync-row">
        <label><?php _e('StockX URL:', 'stockx-sync'); ?></label>
        <input type="text"
            class="stockx_manual_url"
            data-variation-id="<?php echo esc_attr($variation->ID); ?>"
            … />

        <button type="button"
                class="button stockx-save-manual-url"
                data-variation-id="<?php echo esc_attr($variation->ID); ?>">
        <?php _e('Save URL', 'stockx-sync'); ?>
        </button>
        <span class="stockx-sync-status" style="margin-top: 4px; display: block;"></span>
    </div>
    <script>
    jQuery(function($){
        // Delegate Sync URL and Sync Price for variations
        $(document).on('click', '.stockx-sync-variation-url, .stockx-sync-variation-price', function(){
            var btn     = $(this),
                vid     = btn.data('id'),
                isUrl   = btn.hasClass('stockx-sync-variation-url'),
                input   = isUrl ? btn.siblings('.stockx-variation-url-input') : null,
                status  = btn.siblings('.stockx-sync-status'),
                action  = isUrl ? 'stockx_sync_variation_url' : 'stockx_sync_variation_price';

            status.text('⏳ ' + (isUrl ? '<?php echo esc_js( __( 'Syncing URL…', 'stockx-sync' ) ); ?>' : '<?php echo esc_js( __( 'Syncing price…', 'stockx-sync' ) ); ?>'));
            btn.prop('disabled', true);

            $.post(ajaxurl, { action: action, variation_id: vid })
                .done(function(response){
                    if (response.success) {
                        if (isUrl) {
                            input.val(response.data);
                            status.text('✅ <?php echo esc_js( __( 'URL synced', 'stockx-sync' ) ); ?>');
                        } else {
                            status.text('✅ ' + response.data + ' UAH');
                        }
                    } else {
                        var msg;
                        if (response.data && typeof response.data.message === 'string') {
                            msg = response.data.message;
                        } else if (typeof response.data === 'string') {
                            msg = response.data;
                        } else {
                            msg = JSON.stringify(response.data);
                        }
                        status.text('❌ ' + msg);
                    }
                })
                .fail(function(){
                    status.text('❌ <?php echo esc_js( __( 'AJAX error', 'stockx-sync' ) ); ?>');
                })
                .always(function(){
                    btn.prop('disabled', false);
                });
        });
    });
    </script>
    <?php
}, 10, 3);
// Admin UI Hooks for single product general data
add_action('woocommerce_product_options_general_product_data', function () {
    global $post;
    $base_url = get_post_meta($post->ID, '_stockx_product_base_url', true);
    ?>
    <div class="options_group">
        <p class="form-field">
            <label for="stockx_base_url"><?php _e('StockX Base URL', 'stockx-sync'); ?></label>
            <input type="text" id="stockx_base_url" value="<?php echo esc_attr($base_url); ?>" class="short" style="width:60%;" />
            <?php $is_women = get_post_meta($post->ID, '_stockx_is_women', true) === 'yes'; ?>
            <label style="margin-left: 10px;">
                <input type="checkbox" id="stockx_women_flag" <?php checked($is_women); ?> />
                <?php _e('Women Sizes (add W)', 'stockx-sync'); ?>
            </label>
            <button type="button" class="button stockx-save-base-url"><?php _e('Save Base URL', 'stockx-sync'); ?></button>
        </p>
        <p class="form-field">
            <button type="button" class="button" id="get_stockx_url_single"><?php _e('Get StockX URL for this Product', 'stockx-sync'); ?></button>
            <button type="button" class="button button-primary" id="stockx_sync_all"><?php _e('Sync All URLs & Prices', 'stockx-sync'); ?></button>
        </p>
        
    </div>
    
    <script>
    jQuery(function($){
        // Save Base URL button
        $('.stockx-save-base-url').on('click', function(){
            var url = $('#stockx_base_url').val();
            var is_women = $('#stockx_women_flag').is(':checked'); // ← this line is crucial

            var data = {
                action: 'stockx_save_base_url',
                product_id: <?php echo (int) $post->ID; ?>,
                url: url,
                is_women: is_women
            };

            $.post(ajaxurl, data, function(res){
                alert(res.success ? '<?php _e('Base URL saved', 'stockx-sync'); ?>' : '<?php _e('Error saving Base URL', 'stockx-sync'); ?>');
            });
        });

        $('#get_stockx_url_single').on('click', function(){
            $.post(ajaxurl, { action: 'stockx_get_url_single', product_id: <?php echo (int) $post->ID; ?> }, function(response){
                alert(response.success ? '<?php _e('URL fetched: ', 'stockx-sync'); ?>'+response.data : '<?php _e('Error: ', 'stockx-sync'); ?>'+response.data);
                location.reload();
            });
        });    });
    </script>
    <?php
    $log = get_post_meta($post->ID, '_stockx_last_sync_log', true);
    if (!empty($log) && is_array($log)) {
        echo '<div class="form-field">';
        echo '<h4 style="margin-top:1em;">🧾 ' . __('StockX Sync Log', 'stockx-sync') . '</h4>';
        echo '<ul style="max-height:200px; overflow:auto; font-size:13px; padding-left:1em;">';
        echo '<li><strong>Last sync:</strong> ' . esc_html($log['timestamp']) . '</li>';
    
        foreach ($log['results'] as $var_id => $entry) {
            echo '<li>';
            echo 'Variation #' . $var_id . ': ';
            if ($entry['success']) {
                echo '✅ Price: ' . esc_html($entry['price']) . ' UAH';
            } else {
                echo '❌ ' . esc_html($entry['error']);
            }
            echo '</li>';
        }
    
        echo '</ul></div>';
    }
});


// збереження вручну введеної URL-ки для варіації
add_action('wp_ajax_stockx_save_manual_url', function(){
    $vid = absint( $_POST['variation_id'] ?? 0 );
    $url = esc_url_raw( $_POST['url'] ?? '' );
    if ( ! $vid ) {
        wp_send_json_error( 'Invalid variation ID' );
    }
    update_post_meta( $vid, '_stockx_product_url', $url );
    wp_send_json_success();
});

/**
 * 1) Зберігаємо базовий URL + прапорець «Women Sizes»
 */
add_action('wp_ajax_stockx_save_base_url', function(){
    $pid      = absint( $_POST['product_id'] ?? 0 );
    $url      = esc_url_raw( $_POST['url'] ?? '' );
    // підтримуємо і 'true' і '1'
    $is_women = isset( $_POST['is_women'] ) 
               && in_array( $_POST['is_women'], ['true','1','on','yes'], true );

    if( ! $pid || ! $url ) {
        wp_send_json_error('Invalid data');
    }

    update_post_meta( $pid, '_stockx_product_base_url', $url );
    update_post_meta( $pid, '_stockx_is_women', $is_women ? 'yes' : 'no' );

    wp_send_json_success([ 'is_women' => $is_women ]);
});



// Update WooCommerce variation price via AJAX
add_action('wp_ajax_stockx_sync_variation_price', function () {
    $variation_id = absint($_POST['variation_id'] ?? 0);
    if (! $variation_id) {
        wp_send_json_error('Invalid variation ID');
    }

    $variation = wc_get_product($variation_id);
    $parent_id = wp_get_post_parent_id( $variation_id );
    $base_url  = get_post_meta( $parent_id, '_stockx_product_base_url', true );
    if ( ! $base_url || ! $variation ) {
        wp_send_json_error( 'Missing base URL or variation' );
    }

    $size = '';
    foreach ($variation->get_attributes() as $attr => $value) {
        if (!empty($value)) {
            $size = $value;
            break;
        }
    }

    if (! $size) {
        wp_send_json_error('Missing size');
    }

    try {
        $slug = basename(parse_url($base_url, PHP_URL_PATH));
        $client = new SeleniumClient();
        $price = $client->get_price($slug, $size);

        if ($price) {
            $variation->set_price($price);
            $variation->set_regular_price($price);
            $variation->save();

            $is_women = get_post_meta($parent_id, '_stockx_is_women', true) === 'yes';
            $size_param = $size . ($is_women ? 'W' : '');

            $variation_url = 'https://stockx.com/' . $slug . '?catchallFilters=' . $slug . '&size=' . urlencode($size_param);
            update_post_meta($variation_id, '_stockx_product_url', $variation_url);

            wp_send_json_success($price);
        } else {
            wp_send_json_error('Could not fetch price');
        }
    } catch (\Throwable $e) {
        wp_send_json_error($e->getMessage());
    }
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

/**
 * 2) Синхронізація URL варіації з урахуванням «Women Sizes»
 */
add_action('wp_ajax_stockx_sync_variation_url', function() use($size_map) {
    $vid       = absint( $_POST['variation_id'] ?? 0 );
    $parent_id = wp_get_post_parent_id( $vid );
    $base_url  = get_post_meta( $parent_id, '_stockx_product_base_url', true );
    $is_women  = get_post_meta( $parent_id, '_stockx_is_women', true ) === 'yes';
    $variation = wc_get_product( $vid );

    if( ! $vid || ! $base_url || ! $variation ) {
        wp_send_json_error('Missing base URL or invalid variation');
    }

    // витягаємо «сирий» EU-розмір
    $size = '';
    foreach( $variation->get_attributes() as $key => $val ) {
        if( strpos( $key, 'size' ) !== false && $val ) {
            $size = $val; break;
        }
    }
    if( ! $size ) {
        wp_send_json_error('Size not found');
    }

    // мапимо в US Size
    $us_size = $size_map[ $size ]['US Size'] ?? $size;
    // додаємо «W», якщо прапорець увімкнений
    if( $is_women && strpos( $us_size, 'W' ) === false ) {
        $us_size .= 'W';
    }

    // будуємо фінальний URL
    $slug = trim( parse_url($base_url, PHP_URL_PATH), '/' );
    $full_url = sprintf(
        '%s?catchallFilters=%1$s&size=%s',
        $base_url,
        urlencode($us_size)
    );

    update_post_meta( $vid, '_stockx_product_url', $full_url );
    wp_send_json_success([ 'url' => $full_url, 'us_size' => $us_size ]);
});



/**
 * 3) Синхронізація ціни через AJAX
 */
add_action('wp_ajax_stockx_sync_variation_price', function() use($size_map) {
    $vid      = absint( $_POST['variation_id'] ?? 0 );
    $variation = wc_get_product( $vid );
    $parent_id = wp_get_post_parent_id( $vid );
    $base_url  = get_post_meta( $parent_id, '_stockx_product_base_url', true );
    $is_women  = get_post_meta( $parent_id, '_stockx_is_women', true ) === 'yes';

    if( ! $vid || ! $variation || ! $base_url ) {
        wp_send_json_error('Missing variation or base URL');
    }

    // отримуємо URL (щоб бути упевненими в коректності slug)
    $stored_url = get_post_meta( $vid, '_stockx_product_url', true );
    $slug       = $stored_url
        ? trim( parse_url($stored_url, PHP_URL_PATH), '/' )
        : basename( parse_url($base_url, PHP_URL_PATH) );

    // знову витягаємо розмір для запиту ціни
    $size = '';
    foreach( $variation->get_attributes() as $key => $val ) {
        if( strpos($key,'size') !== false && $val ) {
            $size = $val; break;
        }
    }
    if( ! $size ) {
        wp_send_json_error('Size not found');
    }

    // мапимо + «W» якщо потрібно
    $us_size = $size_map[ $size ]['US Size'] ?? $size;
    if( $is_women && strpos( $us_size, 'W' ) === false ) {
        $us_size .= 'W';
    }

    try {
        $client = new \StockXSync\SeleniumClient();
        $price  = floatval( $client->get_price($slug, $us_size) );

        if( ! $price ) {
            wp_send_json_error('Price not found on StockX');
        }

        // оновлюємо товар
        $variation->set_price($price);
        $variation->set_regular_price($price);
        $variation->save();

        // записуємо час синхронізації
        update_post_meta( $vid, '_stockx_price_synced_at', current_time('mysql') );

        wp_send_json_success([ 'price' => $price ]);
    } catch(\Throwable $e) {
        wp_send_json_error($e->getMessage());
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


add_action('wp_ajax_stockx_sync_simple_price', function(){
    $product_id = absint($_POST['product_id'] ?? 0);
    if ( ! $product_id ) {
        wp_send_json_error('Invalid product ID');
    }

    $url = get_post_meta($product_id, '_stockx_product_url', true);
    if ( empty($url) ) {
        wp_send_json_error('Missing StockX URL');
    }

    try {
        // отримуємо slug продукту
        $slug = basename( parse_url($url, PHP_URL_PATH) );

        // отримуємо "сиру" ціну зі StockX
        $client    = new SeleniumClient();
        $raw_price = $client->get_price($slug, '');
        if ( $raw_price === null || ! is_numeric($raw_price) ) {
            throw new \Exception('Could not fetch raw price');
        }

        // використовуємо оригінальну ціну без множення чи додаткових відсотків
        $final_price = round( $raw_price, 2 );

        // оновлюємо WooCommerce-продукт
        $product = wc_get_product($product_id);
        $product->set_regular_price( $final_price );
        $product->save();

        wp_send_json_success(['price' => $final_price]);
    } catch (\Throwable $e) {
        wp_send_json_error( $e->getMessage() );
    }
});


add_action('wp_ajax_stockx_bulk_sync_variation_data', function () use ($size_map) {
    $product_id = absint($_POST['product_id'] ?? 0);
    $product = wc_get_product($product_id);

    if (! $product || ! $product->is_type('variable')) {
        wp_send_json_error(['message' => 'Invalid variable product']);
    }

    $results = [];

    foreach ($product->get_children() as $variation_id) {
        $variation = wc_get_product($variation_id);
        if (! $variation) {
            $results[$variation_id] = ['success' => false, 'error' => 'Variation not found'];
            continue;
        }

        // === Розбір атрибуту розміру ===
        $size = $variation->get_attribute('pa_size') ?: '';
        if (!$size) {
            foreach ($variation->get_attributes() as $key => $val) {
                if (strpos($key, 'size') !== false) {
                    $size = $val;
                    break;
                }
            }
        }

        if (! $size) {
            $results[$variation_id] = ['success' => false, 'error' => 'Size not found'];
            continue;
        }

        // === Маппінг ===
        $us_size = isset($size_map[$size]['US Size']) ? $size_map[$size]['US Size'] : $size;

        // === Отримання URL-адреси ===
        $base_url = get_post_meta($product_id, '_stockx_product_base_url', true);
        if (!$base_url) {
            $results[$variation_id] = ['success' => false, 'error' => 'Missing base URL'];
            continue;
        }

        $slug = trim(parse_url($base_url, PHP_URL_PATH), '/');
        $is_women = get_post_meta($product_id, '_stockx_is_women', true) === 'yes';
        $size_param = $us_size . ($is_women ? 'W' : '');
        $full_url = 'https://stockx.com/' . $slug . '?catchallFilters=' . $slug . '&size=' . urlencode($size_param);

        update_post_meta($variation_id, '_stockx_product_url', $full_url);

        // === Отримання ціни ===
        try {
            $client = new \StockXSync\SeleniumClient();
            $price = $client->get_price($slug, $us_size);

            if ($price && is_numeric($price)) {
                $variation->set_price($price);
                $variation->set_regular_price($price);
                $variation->save();

                $results[$variation_id] = ['success' => true, 'price' => $price];
            } else {
                $results[$variation_id] = ['success' => false, 'error' => 'No price found'];
                continue;
            }
        } catch (\Throwable $e) {
            $results[$variation_id] = ['success' => false, 'error' => $e->getMessage()];
        }
    }
    // Save sync log to post meta
    update_post_meta($product_id, '_stockx_last_sync_log', [
        'timestamp' => current_time('mysql'),
        'results' => $results,
    ]);

    wp_send_json_success($results);
});


add_filter('manage_product_posts_columns', function ($columns) {
    $columns['stockx_sync'] = __('StockX Sync', 'stockx-sync');
    return $columns;
});

add_action('manage_product_posts_custom_column', function ($column, $post_id) {
    if ($column === 'stockx_sync') {
        echo '<button type="button" class="button stockx-bulk-sync" data-id="' . esc_attr($post_id) . '">⚡ Sync All</button>';
    }
}, 10, 2);

add_action('admin_footer', function () {
    $screen = get_current_screen();
    if ($screen->post_type !== 'product') return;
    ?>
    <script>
    jQuery(function($){
        $(document).on('click', '.stockx-bulk-sync', function(){
            const btn = $(this);
            const pid = btn.data('id');
            btn.prop('disabled', true).text('⏳ Syncing...');
            $.post(ajaxurl, { action: 'stockx_bulk_sync_variation_data', product_id: pid }, function(res){
                if (res.success) {
                    let count = 0;
                    for (let id in res.data) {
                        if (res.data[id].success) count++;
                    }
                    alert(`✅ Synced ${count} variations`);
                } else {
                    alert('❌ Error: ' + (res.data.message || 'Unknown'));
                }
            }).fail(function(){
                alert('❌ AJAX error');
            }).always(function(){
                btn.prop('disabled', false).text('⚡ Sync All');
            });
        });
        

        $('#stockx_sync_all').on('click', function(){
            const btn = $(this);
            btn.prop('disabled', true).text('⏳ Syncing...');
            $.post(ajaxurl, {
                action: 'stockx_bulk_sync_variation_data',
                product_id: <?php echo (int) $post->ID; ?>
            }, function(res){
                if (res.success) {
                    let count = 0;
                    for (let id in res.data) {
                        if (res.data[id].success) count++;
                    }
                    alert(`✅ Synced ${count} variations`);
                } else {
                    alert('❌ Error: ' + (res.data.message || 'Unknown'));
                }
                location.reload();
            }).fail(function(){
                alert('❌ AJAX error');
            }).always(function(){
                btn.prop('disabled', false).text('Sync All URLs & Prices');
            });
        });

    });
    </script>
    <?php
});



// 2) Додаємо колонку «Last StockX Sync» у список продуктів
add_filter('manage_product_posts_columns', function($columns){
    // вставляємо після назви (Title)
    $new = [];
    foreach($columns as $key => $label){
        $new[$key] = $label;
        if ($key === 'title') {
            $new['stockx_last_sync'] = __('Last StockX Sync', 'stockx-sync');
        }
    }
    return $new;
});

// 3) Заповнюємо значення цієї колонки
add_action( 'manage_product_posts_custom_column', function( $column, $post_id ) {
    if ( $column === 'stockx_last_sync' ) {
        // читаємо саме з CLI-мітки
        $last = get_post_meta( $post_id, '_stockx_last_cli_sync', true );
        if ( $last ) {
            echo esc_html( date_i18n( 'd.m.Y H:i', strtotime( $last ) ) );
        } else {
            echo '–';
        }
    }
}, 10, 2 );



/**
 * Додаємо посилання “StockX” в Quick Actions у списку продуктів
 */
add_filter( 'post_row_actions', function( $actions, $post ) {
    // тільки для CPT = product
    if ( $post->post_type === 'product' ) {
        // базовий URL з мета-поля
        $url = get_post_meta( $post->ID, '_stockx_product_base_url', true );
        if ( $url ) {
            // вставляємо посилання першим
            $stockx_link = sprintf(
                '<a href="%1$s" target="_blank" title="%2$s">%3$s</a>',
                esc_url( $url ),
                esc_attr__( 'Перейти на StockX', 'stockx-sync' ),
                esc_html__( 'StockX', 'stockx-sync' )
            );
            // додаємо під ключем stockx_link
            $actions = array_merge(
                [ 'stockx_link' => $stockx_link ],
                $actions
            );
        }
    }
    return $actions;
}, 10, 2 );

// 2) Додаємо колонки Price Range і Last StockX Sync
add_filter('manage_product_posts_columns', function($columns){
    $new = [];
    foreach($columns as $key => $label){
        $new[$key] = $label;
        if ($key === 'title') {
            // після назви вставляємо Price Range
            $new['price_range']     = __('Price Range', 'stockx-sync');
            $new['stockx_last_sync'] = __('Last StockX Sync', 'stockx-sync');
        }
    }
    return $new;
});

// 3) Заповнюємо ці колонки
add_action('manage_product_posts_custom_column', function($column, $post_id){
    if ($column === 'price_range') {
        $product = wc_get_product($post_id);
        if (! $product) {
            echo '–';
            return;
        }
        if ($product->is_type('variable')) {
            $prices = $product->get_variation_prices( true )['price'];
            if (! empty($prices)) {
                $min = min($prices);
                $max = max($prices);
                echo wc_price($min) . ' &ndash; ' . wc_price($max);
            } else {
                echo '–';
            }
        } else {
            $price = $product->get_price();
            echo $price !== '' ? wc_price($price) : '–';
        }
    }

    if ($column === 'stockx_last_sync') {
        $last = get_post_meta($post_id, '_stockx_price_synced_at', true);
        if ($last) {
            // формат d.m.Y H:i
            echo esc_html( date_i18n('d.m.Y H:i', strtotime($last)) );
        } else {
            echo '–';
        }
    }
}, 10, 2);

// 4) Зареєструвати колонку як сортувальну
add_filter('manage_edit-product_sortable_columns', function($columns){
    $columns['stockx_last_sync'] = 'stockx_last_sync';
    return $columns;
});

// 5) Обробити запит при сортуванні
add_action('pre_get_posts', function($query){
    // тільки в адмінці, головний запит і список продуктів
    if ( is_admin()
      && $query->is_main_query()
      && $query->get('post_type') === 'product'
      && $orderby = $query->get('orderby')
      && $orderby === 'stockx_last_sync'
    ) {
        // сортуємо за мета-полем
        $query->set('meta_key', '_stockx_price_synced_at');
        // TIMESTAMP-рядок — сортуємо як DATETIME
        $query->set('meta_type', 'DATETIME');
        $query->set('orderby', 'meta_value');
    }
});

add_action('admin_enqueue_scripts', function($hook){
  if ( $hook === 'post.php' && get_post_type() === 'product' ) {
    wp_enqueue_script(
      'stockx-admin-js',
      plugin_dir_url( __DIR__ ) . 'admin/admin-stockx.js',
      ['jquery'],
      '1.0',
      true
    );
    wp_localize_script('stockx-admin-js', 'StockXAdmin', [
      'ajax_url' => admin_url('admin-ajax.php'),
      'post_id'  => get_the_ID(),
    ]);
  }
});