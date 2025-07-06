<?php
/**
 * Plugin Name: StockX Sync for WooCommerce
 */

add_action('plugins_loaded', function() {
    require_once __DIR__ . '/admin-columns.php';
    require_once __DIR__ . '/admin-menu.php';
    require_once __DIR__ . '/admin-actions.php';
    add_action('admin_enqueue_scripts', function() {
        wp_enqueue_script('admin-stockx', plugin_dir_url(__FILE__) . 'admin-stockx.js', ['jquery'], false, true);
    });
});
