<?php   
use StockXSync\SeleniumClient;

require_once __DIR__ . '/class-selenium-client.php';

add_action('init', function() {
    if (!class_exists(SeleniumClient::class)) {
        error_log("SeleniumClient not found");
        return;
    }

    try {
        $client = new SeleniumClient();
        $client->solvePageCaptchaIfNeeded(); // test call
    } catch (\Throwable $e) {
        error_log("SeleniumClient error: " . $e->getMessage());
    }
});

// 🔧 Логування
function stockx_sync_log($message) {
    $logfile = WP_CONTENT_DIR . '/stockx-sync-log.txt';
    $time = date('Y-m-d H:i:s');
    error_log("[$time] $message\n", 3, $logfile);
}

// 🕓 Cron hook для щоденної синхронізації
if (!wp_next_scheduled('stockx_sync_daily_event')) {
    wp_schedule_event(time(), 'daily', 'stockx_sync_daily_event');
}

add_action('stockx_sync_daily_event', function () {
    $args = [
        'post_type'      => 'product',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'meta_query'     => [
            [
                'key'     => '_stockx_product_url',
                'compare' => 'EXISTS',
            ]
        ]
    ];

    $query = new WP_Query($args);
    if ($query->have_posts()) {
        $client = new \StockXSync\SeleniumClient();
        foreach ($query->posts as $post) {
            try {
                stockx_sync_log("CRON: Syncing product ID {$post->ID}");
                $client->fetch_all_variations_sizes_prices($post->ID);
            } catch (\Throwable $e) {
                stockx_sync_log("CRON ERROR product {$post->ID}: " . $e->getMessage());
            }
        }
    }
});

add_action('admin_footer', function () {
    if (get_current_screen()->id === 'product') {
        echo '<script>
        jQuery(function($){
            if (window.stockxCronInit) return;
            window.stockxCronInit = true;
            const cronBtn = $("<button/>", {
                text: "Run Daily StockX Sync Now",
                class: "button button-secondary",
                id: "run_stockx_sync_now"
            }).css({marginLeft: "10px"});
            $("#get_all_sizes_and_prices").after(cronBtn);

            cronBtn.on("click", function(){
                const btn = $(this);
                btn.prop("disabled", true).text("Running sync...");
                $.post(ajaxurl, {action: "stockx_sync_run_cron_now"}, function(res){
                    alert(res.success ? "✅ Sync completed!" : "❌ Error: " + res.data.message);
                    btn.prop("disabled", false).text("Run Daily StockX Sync Now");
                });
            });
        });
        </script>';
    }
});

add_action('wp_ajax_stockx_sync_run_cron_now', function () {
    try {
        $args = [
            'post_type'      => 'product',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'meta_query'     => [
                [
                    'key'     => '_stockx_product_url',
                    'compare' => 'EXISTS',
                ]
            ]
        ];
        $query = new WP_Query($args);
        if ($query->have_posts()) {
            $client = new \StockXSync\SeleniumClient();
            foreach ($query->posts as $post) {
                stockx_sync_log("MANUAL RUN: Syncing product ID {$post->ID}");
                $client->fetch_all_variations_sizes_prices($post->ID);
            }
            wp_send_json_success();
        } else {
            wp_send_json_error(['message' => 'No products found.']);
        }
    } catch (\Throwable $e) {
        stockx_sync_log("MANUAL RUN ERROR: " . $e->getMessage());
        wp_send_json_error(['message' => $e->getMessage()]);
    }
});

// 🖱️ Додаткова кнопка для глобального запуску синхронізації
add_action('woocommerce_product_options_general_product_data', function () {
    echo '<div class="options_group">';
    echo '<button type="button" class="button" id="run_global_sync">' . __('Run Global StockX Sync', 'stockx-sync') . '</button>';
    echo '</div>';
});

add_action('admin_footer', function () {
    if (get_current_screen()->id === 'product') {
        echo '<script>
        jQuery(function($){
            if (window.stockxSyncInitGlobal) return;
            window.stockxSyncInitGlobal = true;
            $("#run_global_sync").on("click", function(){
                const btn = $(this);
                btn.prop("disabled", true).text("Running full sync...");
                $.post(ajaxurl, {
                    action: "stockx_sync_global_cron"
                }, function(response){
                    alert(response.success ? "✅ Global sync complete" : "❌ Error: " + (response.data?.message || "Unknown error"));
                    btn.prop("disabled", false).text("Run Global StockX Sync");
                });
            });
        });
        </script>';
    }
});

add_action('wp_ajax_stockx_sync_global_cron', function () {
    try {
        $query = new WP_Query([
            'post_type' => 'product',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'meta_query' => [
                [
                    'key'     => '_stockx_product_url',
                    'compare' => 'EXISTS'
                ]
            ]
        ]);

        $client = new \StockXSync\SeleniumClient();

        foreach ($query->posts as $post) {
            stockx_sync_log("MANUAL GLOBAL SYNC: Product ID {$post->ID}");
            $client->fetch_all_variations_sizes_prices($post->ID);
        }

        wp_send_json_success();
    } catch (\Throwable $e) {
        stockx_sync_log("MANUAL GLOBAL SYNC ERROR: " . $e->getMessage());
        wp_send_json_error(['message' => $e->getMessage()]);
    }
});

// 🆕 Автосинхронізація при збереженні продукту
add_action('save_post_product', function($post_id, $post, $update) {
    if (wp_is_post_revision($post_id) || $post->post_status !== 'publish') return;
    $url = get_post_meta($post_id, '_stockx_product_url', true);
    if (!$url) return;

    try {
        $client = new \StockXSync\SeleniumClient();
        $client->fetch_all_variations_sizes_prices($post_id);
        stockx_sync_log("AUTO SYNC: Updated prices on save for product ID {$post_id}");
    } catch (\Throwable $e) {
        stockx_sync_log("AUTO SYNC ERROR product {$post_id}: " . $e->getMessage());
    }
}, 10, 3);

// 📊 Адмін-дашборд
add_action('admin_menu', function () {
    add_menu_page(
        'StockX Sync Dashboard',
        'StockX Sync',
        'manage_woocommerce',
        'stockx-sync-dashboard',
        'stockx_sync_dashboard_page',
        'dashicons-update',
        56
    );
});

function stockx_sync_dashboard_page() {
    $logfile = WP_CONTENT_DIR . '/stockx-sync-log.txt';
    $logs = file_exists($logfile) ? array_reverse(file($logfile)) : ['No logs yet.'];
    $last_sync = 'N/A';
    $count = 0;

    foreach ($logs as $line) {
        if (strpos($line, 'SYNC') !== false) {
            $last_sync = trim($line);
            $count++;
            if ($count >= 1) break;
        }
    }

    echo '<div class="wrap"><h1>📊 StockX Sync Dashboard</h1>';
    echo '<p><strong>Last Sync:</strong> ' . esc_html($last_sync) . '</p>';
    echo '<h2>📄 Log Output</h2>';
    echo '<textarea rows="20" style="width:100%;">' . esc_textarea(implode("", array_slice($logs, 0, 200))) . '</textarea>';
    echo '</div>';
}