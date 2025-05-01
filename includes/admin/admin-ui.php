<?php
namespace StockXSync;

use StockXSync\SyncManager;
use WC_Logger;

// Load SyncManager if not loaded
add_action('init', function() {
    if (! class_exists(SyncManager::class)) {
        require_once plugin_dir_path(__FILE__) . '/../class-sync-manager.php';
    }
});

// Save product meta for URL
add_action('woocommerce_process_product_meta', function($post_id) {
    if (isset($_POST['_stockx_product_url'])) {
        update_post_meta($post_id, '_stockx_product_url', esc_url_raw($_POST['_stockx_product_url']));
    }
});

// Auto-sync on save
add_action('save_post_product', function($post_id, $post, $update) {
    if ($post->post_status !== 'publish') return;
    $url = get_post_meta($post_id, '_stockx_product_url', true);
    if (empty($url)) return;
    $product = wc_get_product($post_id);
    if (! $product) return;
    try {
        if ($product->is_type('simple')) {
            SyncManager::sync_simple_product($post_id, $url);
        } elseif ($product->is_type('variable')) {
            SyncManager::sync_product_variations($post_id, $url);
        }
    } catch (\Exception $e) {
        (new WC_Logger())->error($e->getMessage(), ['source' => 'stockx-sync']);
        wp_mail(get_option('stockx_sync_email_notify'), 'StockX Sync Error', $e->getMessage());
    }
}, 20, 3);

// Add fields & buttons
add_action('woocommerce_product_options_general_product_data', function() {
    global $post;
    $product  = wc_get_product($post->ID);
    $base_url = get_post_meta($post->ID, '_stockx_product_url', true);

    // URL input
    woocommerce_wp_text_input([
        'id'          => '_stockx_product_url',
        'label'       => __('StockX Product URL', 'stockx-sync'),
        'description' => __('Enter the full StockX product URL', 'stockx-sync'),
        'type'        => 'url',
        'desc_tip'    => true,
        'value'       => esc_url($base_url),
    ]);

    // Simple sync button
    if ($product && $product->is_type('simple')) {
        echo '<p class="form-field stockx-simple-sync">';
        echo '<button type="button" class="button" id="stockx_sync_simple_price" data-id="'.esc_attr($post->ID).'">';
        esc_html_e('Sync Price from StockX','stockx-sync');
        echo '</button> ';
        echo '<span id="stockx_simple_status" style="margin-left:10px;"></span>';
        echo '</p>';
    }
});

// Admin JS for AJAX
add_action('admin_footer', function(){
    $screen = get_current_screen();
    if ($screen->post_type !== 'product') return;
?>
    <script>
    jQuery(function($){
        $('#stockx_sync_simple_price').on('click', function(){
            var btn=$(this), pid=btn.data('id'), status=$('#stockx_simple_status');
            btn.prop('disabled', true); status.text('⏳ Syncing…');
            $.post(ajaxurl, { action:'stockx_sync_simple_price', product_id:pid }, function(response){
                if(response.success){
                    status.text('✅ '+response.data.price+' UAH');
                } else {
                    status.text('❌ '+(response.data||response.message));
                }
                btn.prop('disabled', false);
            });
        });
    });
    </script>
<?php
});

// AJAX: simple product price sync
add_action('wp_ajax_stockx_sync_simple_price', function(){
    $product_id = absint($_POST['product_id'] ?? 0);
    if(!$product_id) wp_send_json_error('Invalid product ID');
    $url = get_post_meta($product_id, '_stockx_product_url', true);
    if(empty($url)) wp_send_json_error('Missing StockX URL');
    try {
        $slug  = basename(parse_url($url, PHP_URL_PATH));
        $client = new SeleniumClient();
        $raw_price = $client->get_price($slug, '');
        if($raw_price===null||!is_numeric($raw_price)){
            throw new \Exception('Could not fetch raw price');
        }
        // Apply conversion
        $final_price = round($raw_price*42*1.30,2);
        $product = wc_get_product($product_id);
        $product->set_regular_price($final_price);
        $product->save();
        wp_send_json_success(['price'=>$final_price]);
    } catch(\Throwable $e) {
        wp_send_json_error($e->getMessage());
    }
});
