<?php
namespace StockXSync;

// ✅ Підключаємо Ajax Handlers
$ajax_path = plugin_dir_path(__DIR__) . 'classes/class-ajax-handlers.php';
if ( file_exists($ajax_path) ) {
    require_once $ajax_path;
}

use StockXSync\Ajax_Handlers;

add_action('init', function() {
    Ajax_Handlers::init();
});
class Plugin {
    /**
     * Точка входу плагіна.
     * Завантажує переклади та реєструє AJAX-екшен.
     */
    public static function init() {
        // Завантажуємо переклади плагіна
        load_plugin_textdomain(
            'stockx-sync',
            false,
            dirname( plugin_basename( __FILE__ ) ) . '/../../languages'
        );

        // Реєструємо AJAX-екшен для синхронізації
        add_action( 'wp_ajax_stockx_sync_run', [ __CLASS__, 'ajax_run' ] );
    }

    /**
     * Логіка при активації плагіна
     */
    public static function activate() {
        // TODO: Додайте дію при активації (створення таблиць, cron тощо)
    }

    /**
     * Логіка при деактивації плагіна
     */
    public static function deactivate() {
        // TODO: Додайте дію при деактивації (очищення cron, видалення опцій тощо)
    }

    /**
     * Обробник AJAX для запуску синхронізації
     */
    public static function ajax_run() {
        SyncManager::run();
    }

    /**
     * Завантажує сторінку через headless-браузер з Puppeteer Stealth
     *
     * @param string $url
     * @return string|false HTML або false при помилці
     */
    public static function fetchViaHeadless(string $url) {
        try {
            return Browsershot::url($url)
                ->noSandbox()
                ->setUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64)…')
                ->waitUntilNetworkIdle()
                ->setDelay(8000)
                ->bodyHtml();
        } catch (\Exception $e) {
            error_log('[StockXSync] Cloudflare bypass error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Приклад: шукаємо slug за style (SKU)
     */
    public static function resolve_slug_from_style(string $style) {
        $searchUrl = 'https://stockx.com/search?s=' . urlencode($style);
        $html = self::fetchViaHeadless($searchUrl);
        if (! $html) {
            return null;
        }

        if (preg_match('#<a[^>]+href="([^\"]+)"[^>]*data-testid="productTile-ProductSwitcherLink"#', $html, $m)) {
            return $m[1];
        }

        return null;
    }
}
