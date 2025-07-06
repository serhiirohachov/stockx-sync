<?php
namespace StockXSync;


use Facebook\WebDriver\Remote\RemoteWebDriver;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\WebDriverBy;

class StockXFetcher {
    public function getSlugBySku($sku) {
        $tmpUserDir = '/tmp/chrome-profile-' . uniqid();
    
        $options = new ChromeOptions();
        $options->addArguments([
            '--headless',
            '--no-sandbox',
            '--disable-gpu',
            '--disable-dev-shm-usage',
            '--user-data-dir=' . $tmpUserDir
        ]);
    
        $capabilities = DesiredCapabilities::chrome();
        $capabilities->setCapability(ChromeOptions::CAPABILITY, $options);
    
        try {
            $driver = RemoteWebDriver::create('http://localhost:9515', $capabilities);
            $driver->get('https://stockx.com/search?s=' . urlencode($sku));
    
            sleep(2); // час на завантаження сторінки
    
        
            $linkElement = $driver->findElement(WebDriverBy::cssSelector('a[data-testid="productTile-ProductSwitcherLink"]'))->getAttribute('href');
            $href        = $linkElement->getAttribute('href');
            error_log( "[StockXFetcher] URL fetched: {$href}" );
            return $href;
            
            $driver->quit();
            return 'https://stockx.com' . $link;
    
        } catch (\Throwable $e) {
            error_log('[StockXFetcher] Error: ' . $e->getMessage());
            return false;
    
        } finally {
            if (is_dir($tmpUserDir)) {
                shell_exec('rm -rf ' . escapeshellarg($tmpUserDir));
            }
        }
    }
    
}
