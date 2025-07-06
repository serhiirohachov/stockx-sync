<?php
/**
 * Plugin Name:       StockX Price Sync
 * Description:       Sync out-of-stock variation prices from StockX via Selenium scraping.
 * Version:           1.1.0
 * Author:            Serhii Rohachov
 * Text Domain:       stockx-sync
 */

namespace StockXSync;

if (!defined('ABSPATH')) exit;

// Autoload
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

// Classes
$includes = [
    'class-plugin', 'class-2captcha-client', 'class-settings', 'class-scheduler',
    'class-admin-page', 'class-sync-manager', 'class-base-selenium',
    'class-selenium-client', 'class-stockx-fetcher', 'class-size-mapper'
];
foreach ($includes as $file) {
    require_once __DIR__ . "/includes/classes/{$file}.php";
}
require_once __DIR__ . '/includes/functions/stockx-utils.php';
require_once __DIR__ . '/includes/admin/admin-ui.php';

// CLI
if (defined('WP_CLI') && WP_CLI) {
    require_once __DIR__ . '/includes/cli/class-cli.php';
    \StockXSync\CLI_Sync::register(); // або ::init(), якщо ти так назвав метод
}

// Hooks
register_activation_hook(__FILE__, [Plugin::class, 'activate']);
register_deactivation_hook(__FILE__, [Plugin::class, 'deactivate']);

register_activation_hook(__FILE__, function () {
    $log = WP_CONTENT_DIR . '/stockx-sync-log.txt';
    if (!file_exists($log)) file_put_contents($log, '');
});

add_action('plugins_loaded', [Plugin::class, 'init'], 10);
