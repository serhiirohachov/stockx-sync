<?php
namespace StockXSync;

use Facebook\WebDriver\Remote\RemoteWebDriver;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Chrome\ChromeOptions;

abstract class BaseSelenium {
    protected string $host;
    protected string $binary;
    protected string $profileDir;
    protected ?RemoteWebDriver $driver = null;

    public function __construct() {
        $this->host = get_option('stockx_sync_selenium_hub', 'http://localhost:9515');
        $this->binary = get_option('stockx_sync_browser_binary', '/usr/bin/google-chrome');

        if (! file_exists($this->binary)) {
            throw new \Exception("Chrome binary not found at {$this->binary}");
        }

        $check = @fsockopen(parse_url($this->host, PHP_URL_HOST), (int)parse_url($this->host, PHP_URL_PORT), $errno, $errstr, 2);
        if (!is_resource($check)) {
            throw new \Exception("ChromeDriver not reachable at {$this->host}");
        }
        fclose($check);

        $this->profileDir = sys_get_temp_dir() . '/stockx_sync_' . uniqid();
        mkdir($this->profileDir, 0700, true);

        $options = new ChromeOptions();
        $options->setBinary($this->binary);
        $options->addArguments([
            '--headless',
            '--no-sandbox',
            '--disable-gpu',
            '--disable-dev-shm-usage',
            '--window-size=1920,1080',
            "--user-data-dir={$this->profileDir}"
        ]);

        $capabilities = DesiredCapabilities::chrome();
        $capabilities->setCapability(ChromeOptions::CAPABILITY, $options);

        $this->driver = RemoteWebDriver::create($this->host, $capabilities, 10000, 10000);
    }

    public function log(string $msg): void {
        error_log('[BaseSelenium] ' . $msg);
    }

    public function cleanup(): void {
        if ($this->driver) {
            $this->driver->quit();
        }

        if (is_dir($this->profileDir)) {
            shell_exec('rm -rf ' . escapeshellarg($this->profileDir));
        }
    }

    public function __destruct() {
        $this->cleanup();
    }
}
