<?php
// Меню StockX Sync
add_action('admin_menu', function() {
    add_menu_page('StockX Sync', 'StockX Sync', 'manage_woocommerce', 'stockx-sync', 'render_stockx_sync_page', 'dashicons-update', 56);
});

function render_stockx_sync_page() {
    ?>
    <div class="wrap">
        <h1>StockX Sync</h1>
        <button class="button button-primary" id="stockx-get-all-urls">Отримати всі URL-и</button>
        <button class="button button-secondary" id="stockx-sync-all-prices">Синхронізувати всі ціни</button>
        <div id="stockx-sync-progress" style="margin-top:20px;"></div>
    </div>
    <?php
}
