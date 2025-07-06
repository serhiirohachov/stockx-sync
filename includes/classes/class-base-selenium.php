<?php
namespace StockXSync;

use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use GuzzleHttp\Client;

class BaseSelenium {
    protected string $host = 'http://127.0.0.1:9515';  // ChromeDriver host
    protected string $binary = '/usr/bin/chromium-browser';  // Path to the Chromium browser
    protected string $profileDir;  // Temporary profile directory
    protected string $logFile;  // Log file path
    protected ?RemoteWebDriver $driver = null;  // WebDriver instance (nullable)
    protected string $region = 'US';  // Set region to United States by default

    public function __construct() {
        // Check if the Chrome binary exists
        if (!file_exists($this->binary)) {
            throw new \Exception("Chrome binary not found at {$this->binary}");
        }

        // Setup the profile directory with a unique name
        $this->profileDir = sys_get_temp_dir() . '/stockx_sync_' . uniqid();
        $this->cleanOldProfiles();  // Clean up old profiles
        mkdir($this->profileDir, 0700, true);

        // Setup log file
        $logDir = WP_CONTENT_DIR . '/uploads/stockx-sync/';
        if (!file_exists($logDir)) {
            wp_mkdir_p($logDir);
        }
        $this->logFile = $logDir . 'selenium-' . date('Ymd') . '.log';
        $this->log('=== New session started ===');
        $this->log("Profile Dir: {$this->profileDir}");
        $this->log("Region: {$this->region}");  // Log the region

        // Ensure the WebDriver is available
        $this->ensureDriverIsAvailable();

        // Setup Chrome options
        $options = new ChromeOptions();
        $options->setBinary($this->binary);
        $options->addArguments([
            '--headless=new',
            '--no-sandbox',
            '--disable-gpu',
            '--disable-dev-shm-usage',
            '--window-size=1920,1080',
            "--user-data-dir={$this->profileDir}",
            '--enable-logging',
            '--v=1',
            '--disable-extensions',
            '--disable-background-networking',
            '--disable-sync',
            '--no-first-run',
            '--log-level=0',
        ]);

        // Create DesiredCapabilities for Chrome
        $capabilities = DesiredCapabilities::chrome();
        $capabilities->setCapability(ChromeOptions::CAPABILITY, $options);

        // Initialize RemoteWebDriver
        $this->driver = RemoteWebDriver::create($this->host, $capabilities, 10000, 10000);

        // Register shutdown function to clean up
        register_shutdown_function([$this, 'cleanup']);

        // Log successful driver initialization
        $this->log('Driver initialized successfully.');
    }

    // Ensure ChromeDriver is available and ready
    protected function ensureDriverIsAvailable(): void {
        $error = '';
        for ($i = 0; $i < 5; $i++) {
            try {
                $client = new Client(['timeout' => 5]);
                $res = $client->get($this->host . '/status');
                $data = json_decode($res->getBody(), true);
                if (!empty($data['value']['ready'])) return;
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
            sleep(1);
        }

        throw new \Exception("ChromeDriver not reachable at {$this->host}: {$error}");
    }

    // Clean up old profile directories
    protected function cleanOldProfiles(): void {
        foreach (glob(sys_get_temp_dir() . '/stockx_sync_*') as $oldDir) {
            if (is_dir($oldDir)) {
                shell_exec('rm -rf ' . escapeshellarg($oldDir));
            }
        }
    }

    // Capture a screenshot and save it
    public function captureScreenshot(string $name): string {
        $dir = WP_CONTENT_DIR . '/uploads/stockx-sync/';
        if (!file_exists($dir)) {
            wp_mkdir_p($dir);
        }
        $path = $dir . $name;
        $this->driver->takeScreenshot($path);
        $this->log("Captured screenshot: {$path}");
        return $path;
    }

    // Log messages to file and error_log
    public function log(string $msg): void {
        $entry = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
        error_log('[BaseSelenium] ' . $msg);
        if (!empty($this->logFile)) {
            file_put_contents($this->logFile, $entry, FILE_APPEND);
        }
    }

    // Cleanup method to quit WebDriver and clean up profile directory
    public function cleanup(): void {
        if (isset($this->driver)) {
            try {
                $this->driver->quit();
                $this->log("Driver quit successfully");
            } catch (\Throwable $e) {
                $this->log("Driver quit failed: " . $e->getMessage());
            }
        }

        if (is_dir($this->profileDir)) {
            shell_exec('rm -rf ' . escapeshellarg($this->profileDir));
            $this->log("Removed profile dir: {$this->profileDir}");
        }

        $this->log('=== Session ended ===');
    }

    // Destructor to ensure cleanup happens when object is destroyed
    public function __destruct() {
        $this->cleanup();
    }

    /**
     * Method to retrieve the current WebDriver instance.
     *
     * @return RemoteWebDriver|null
     */
    public function get_driver(): ?RemoteWebDriver {
        return $this->driver;
    }

    // New function to handle the price sync for all variations
    public function syncPricesForAllVariations($product_id) {
        $product = wc_get_product($product_id);
        $variations = $product->get_children(); // Get all variations

        foreach ($variations as $variation_id) {
            $this->syncPriceForVariation($variation_id);
        }
    }

// Sync price for a single variation
private function syncPriceForVariation($variation_id) {
    // Get the variation product object
    $variation = wc_get_product($variation_id);
    
    // Get the StockX URL from the product meta
    $stockxUrl = get_post_meta($variation_id, '_stockx_product_url', true);

    // Check if the StockX URL exists
    if ($stockxUrl) {
        try {
            // Assuming you have a method to fetch the price from StockX
            $price = $this->get_price_from_stockx($stockxUrl);

            // Check if the price is valid
            if ($price !== null) {
                // Update the variation price in WooCommerce
                $variation->set_regular_price($price); // Set regular price
                $variation->set_price($price); // Set sale price (if any)
                $variation->save(); // Save the variation with the new price

                $this->log("Updated variation price for variation ID {$variation_id} to {$price} from StockX");
            } else {
                $this->log("Failed to fetch price for variation ID {$variation_id} from StockX");
            }
        } catch (\Exception $e) {
            $this->log("Error syncing price for variation ID {$variation_id}: " . $e->getMessage());
        }
    } else {
        $this->log("No StockX URL found for variation ID {$variation_id}");
    }
}

// Fetch the price from StockX using the URL
private function get_price_from_stockx($url) {
    try {
        // You would make the necessary request to StockX here, perhaps using Guzzle or Selenium

        // For example, using Guzzle to get the page content (simplified)
        $client = new Client();
        $response = $client->get($url);
        $html = (string) $response->getBody();

        // Extract the price from the HTML content using regular expressions or DOM parsing
        if (preg_match('/"currentPrice":"([\d\.,]+)"/', $html, $matches)) {
            // Normalize the price and return it
            return floatval(str_replace([',', ' '], ['', ''], $matches[1]));
        }

        return null; // Return null if the price cannot be found
    } catch (\Exception $e) {
        $this->log("Error fetching price from StockX: " . $e->getMessage());
        return null;
    }
}

}
