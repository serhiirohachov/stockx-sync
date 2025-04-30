<?php
namespace StockXSync;

use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Facebook\WebDriver\WebDriverBy;

class StockXFetcher {
    public function getSlugBySku($sku) {
        $host = 'http://localhost:9515';

        $options = new ChromeOptions();
        $options->addArguments([
            '--no-sandbox',
            '--disable-dev-shm-usage',
            '--disable-gpu',
            '--window-size=1200,800',
            '--user-agent=Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36'
        ]);

        $capabilities = DesiredCapabilities::chrome();
        $capabilities->setCapability('goog:chromeOptions', $options);

        $driver = RemoteWebDriver::create($host, $capabilities);
        $driver->get("https://stockx.com/search?s=" . urlencode($sku));

        try {
            $link = $driver->findElement(
                WebDriverBy::cssSelector('a[data-testid="productTile-ProductSwitcherLink"]')
            );
            $href = $link->getAttribute('href');
        } catch (\Exception $e) {
            $href = 'Not found';
        }

        $driver->quit();
        return $href;
    }
}
