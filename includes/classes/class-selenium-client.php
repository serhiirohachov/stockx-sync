<?php
namespace StockXSync;

use Facebook\WebDriver\Remote\RemoteWebDriver;
use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverExpectedCondition;

class SeleniumClient {
    protected string $host;
    protected ?RemoteWebDriver $driver = null;
    private $profileDir;

    public function __construct() {
        $this->host = get_option('stockx_sync_selenium_hub');
        $binary = get_option('stockx_sync_browser_binary');

        if (! file_exists($binary)) {
            throw new \Exception("Browser binary not found at {$binary}");
        }

        // Check if ChromeDriver is reachable
        $check = @fsockopen(parse_url($this->host, PHP_URL_HOST), (int)parse_url($this->host, PHP_URL_PORT), $errno, $errstr, 2);
        if (!is_resource($check)) {
            throw new \Exception("Selenium/ChromeDriver not reachable at {$this->host}");
        }
        fclose($check);

        $this->profileDir = sys_get_temp_dir() . '/stockx_sync_' . uniqid();
        mkdir($this->profileDir, 0700, true);

        $opts = new ChromeOptions();
        $opts->setBinary($binary);
        $opts->addArguments([
            '--headless', '--disable-gpu', '--no-sandbox', '--disable-dev-shm-usage',
            "--user-data-dir={$this->profileDir}", '--window-size=1920,1080'
        ]);

        $caps = DesiredCapabilities::chrome();
        $caps->setCapability(ChromeOptions::CAPABILITY, $opts);

        $this->driver = RemoteWebDriver::create($this->host, $caps, 10000, 10000);
    }

    public function __destruct() {
        if ($this->driver) {
            $this->driver->quit();
        }
        if (is_dir($this->profileDir)) {
            foreach (glob("{$this->profileDir}/*") as $file) {
                @unlink($file);
            }
            @rmdir($this->profileDir);
        }
    }

    public function get_price(string $style, string $size): ?float {
        $slug = rawurlencode($style);
        $sizeParam = rawurlencode($size);
        $url = "https://stockx.com/{$slug}?catchallFilters={$slug}&size={$sizeParam}";
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
