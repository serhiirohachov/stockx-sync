<?php
namespace StockXSync;

use Facebook\WebDriver\Remote\RemoteWebDriver;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverExpectedCondition;

class SeleniumClient extends BaseSelenium {
    /**
     * Дістає базову (чисту) ціну зі StockX-сторінки
     *
     * @param \Facebook\WebDriver\Remote\RemoteWebElement $el Елемент із текстом ціни
     * @return float|null
     */
    protected function getRawPrice($el) {
        $txt = $el->getText(); // наприклад "345.50"
        if (preg_match('/[\d\.,]+/', $txt, $m)) {
            return floatval(str_replace([',',' '], ['', ''], $m[0]));
        }
        return null;
    }

    /**
     * Отримує ціну зі StockX, конвертує в гривні з маржею і логуватиме синхронізований розмір
     *
     * @param string $style Стиль (SKU) товару
     * @param string $euSize Розмір у форматі EU (наприклад '36-5')
     * @return float|null
     * @throws \Exception
     */
    public function get_price(string $style, string $euSize): ?float {
        // Визначаємо шлях до файлу мапінгу розмірів
        $mappingFile = __DIR__ . '/../admin/size-mappings.php';
        $attributeMap = [];
        if (file_exists($mappingFile)) {
            include $mappingFile; // має визначити масив $size_mappings
            $attributeKey = 'attribute_pa_nikejordeu';
            $attributeMap = $size_mappings[$attributeKey] ?? [];
        }

        // Перекладаємо EU розмір у US, якщо є в мапі
        $usSize = $attributeMap[$euSize]['US Size'] ?? $euSize;
        $this->log("Mapping size EU '{$euSize}' -> US '{$usSize}'");

        // Формуємо URL
        $slug      = rawurlencode($style);
        $sizeParam = rawurlencode($usSize);
        $url       = "https://stockx.com/{$slug}?catchallFilters={$slug}&size={$sizeParam}";
        $this->log("Visiting: {$url}");
        $this->driver->get($url);

        // Чекаємо готовності документу
        try {
            $this->driver->wait(30, 500)
                ->until(fn($d) => $d->executeScript('return document.readyState') === 'complete');
        } catch (\Exception $e) {
            throw new \Exception("Timeout loading page at {$url}");
        }

        // Селектор ціни
        $priceSelector = 'h2[data-testid="trade-box-buy-amount"]';
        try {
            $el = $this->driver->wait(20, 200)
                ->until(WebDriverExpectedCondition::presenceOfElementLocated(
                    WebDriverBy::cssSelector($priceSelector)
                ));
        } catch (\Exception $e) {
            file_put_contents('/tmp/stockx_debug.html', $this->driver->getPageSource());
            throw new \Exception("Timeout locating price element '{$priceSelector}' on {$url}");
        }

        // Парсимо чисту ціну
        $basePrice = $this->getRawPrice($el);
        if ($basePrice === null) {
            $this->log("Raw price not found for style '{$style}', EU '{$euSize}', US '{$usSize}'");
            return null;
        }

        // Конвертуємо за курсом 42 та додаємо 30% маржі
        $finalPrice = round($basePrice * 42 * 1.3, 2);
        $this->log("Synchronized size EU '{$euSize}', US '{$usSize}', final price: {$finalPrice}");

        return $finalPrice;
    }

    /**
     * Отримує slug товару за його SKU через пошук на StockX
     *
     * @param string $sku SKU товару
     * @return string|null
     */
    public function getSlugBySku(string $sku): ?string {
        $url = "https://stockx.com/search?s=" . urlencode($sku);
        $this->log("Searching SKU: {$sku}");
        $this->driver->get($url);

        try {
            $this->driver->wait(15, 200)
                ->until(WebDriverExpectedCondition::presenceOfElementLocated(
                    WebDriverBy::cssSelector('a[data-testid="productTile-ProductSwitcherLink"]')
                ));
            $link = $this->driver->findElement(
                WebDriverBy::cssSelector('a[data-testid="productTile-ProductSwitcherLink"]')
            );
            $path = parse_url($link->getAttribute('href'), PHP_URL_PATH);
            return ltrim($path, '/');
        } catch (\Exception $e) {
            return null;
        }
    }
}
