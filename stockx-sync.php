<?php
/**
 * Plugin Name:       StockX Price Sync
 * Plugin URI:        https://example.com/stockx-sync
 * Description:       Sync out-of-stock variation prices from StockX via Selenium scraping.
 * Version:           1.0.9
 * Author:            Serhii Rohachov
 * Author URI:        https://example.com
 * Text Domain:       stockx-sync
 */

namespace StockXSync;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
    require_once __DIR__ . '/vendor/autoload.php';
}

require_once __DIR__ . '/includes/classes/class-plugin.php';
require_once __DIR__ . '/includes/classes/class-settings.php';
require_once __DIR__ . '/includes/classes/class-scheduler.php';
require_once __DIR__ . '/includes/classes/class-admin-page.php';
require_once __DIR__ . '/includes/classes/class-sync-manager.php';
require_once __DIR__ . '/includes/classes/class-selenium-client.php';
require_once __DIR__ . '/includes/classes/class-stockx-fetcher.php';
require_once __DIR__ . '/includes/cli/class-cli.php';
require_once __DIR__ . '/includes/admin/admin-ui.php';
require_once __DIR__ . '/includes/classes/class-base-selenium.php';
require_once __DIR__ . '/includes/classes/class-selenium-client.php';


if (defined('WP_CLI') && WP_CLI) {
    require_once __DIR__ . '/includes/classes/class-cli.php';
    \StockXSync\CLI::init();
}




register_activation_hook( __FILE__, [ 'StockXSync\\Plugin', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'StockXSync\\Plugin', 'deactivate' ] );

register_activation_hook( __FILE__, function() {
    if (!file_exists(WP_CONTENT_DIR . '/stockx-sync-log.txt')) {
        file_put_contents(WP_CONTENT_DIR . '/stockx-sync-log.txt', '');
    }
});

add_action( 'plugins_loaded', [ 'StockXSync\\Plugin', 'init' ] );
