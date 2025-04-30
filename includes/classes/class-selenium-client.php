<?php
namespace StockXSync;

use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverExpectedCondition;

class SeleniumClient extends BaseSelenium {

    public function get_price(string $style, string $size): ?float {
        $slug = rawurlencode($style);
        $sizeParam = rawurlencode($size);
        $url = "https://stockx.com/{$slug}?catchallFilters={$slug}&size={$sizeParam}";
        $this->log("Visiting: $url");
        $this->driver->get($url);

        try {
            $this->driver->wait(30, 500)->until(
                fn($d) => $d->executeScript('return document.readyState') === 'complete'
            );
        } catch (\Exception $e) {
            throw new \Exception("Timeout loading page at {$url}");
        }

        $priceSelector = 'h2[data-testid="trade-box-buy-amount"]';

        try {
            $el = $this->driver->wait(20, 200)->until(
                WebDriverExpectedCondition::presenceOfElementLocated(
                    WebDriverBy::cssSelector($priceSelector)
                )
            );
        } catch (\Exception $e) {
            file_put_contents('/tmp/stockx_debug.html', $this->driver->getPageSource());
            throw new \Exception("Timeout locating price element with selector '{$priceSelector}' on {$url}");
        }

        $txt = $el->getText();
        if (preg_match('/[\d\.,]+/', $txt, $m)) {
            return floatval(str_replace(',', '', $m[0]));
        }

        return null;
    }

    public function getSlugBySku(string $sku): ?string {
        $url = "https://stockx.com/search?s=" . urlencode($sku);
        $this->log("Searching SKU: {$sku}");
        $this->driver->get($url);

        try {
            $this->driver->wait(15, 200)->until(
                WebDriverExpectedCondition::presenceOfElementLocated(
                    WebDriverBy::cssSelector('a[data-testid="productTile-ProductSwitcherLink"]')
                )
            );

            $link = $this->driver->findElement(WebDriverBy::cssSelector('a[data-testid="productTile-ProductSwitcherLink"]'));
            return $link->getAttribute('href');
        } catch (\Exception $e) {
            return null;
        }
    }
}
