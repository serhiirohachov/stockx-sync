<?php

namespace StockXSync;

use Facebook\WebDriver\Remote\RemoteWebDriver;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverExpectedCondition;
use Facebook\WebDriver\Chrome\ChromeOptions;
use GuzzleHttp\Client;

require_once plugin_dir_path(__FILE__) . '/class-2captcha-client.php';

class SeleniumClient extends BaseSelenium {

    protected ?CaptchaSolver $captchaSolver = null;
    protected ?RemoteWebDriver $driver = null;
    protected ?string $tempProfileDir = null;
    protected string $captchaApiKey = '38ece602d7cbbd0e650ba84a0531f673';
    protected string $region = 'US';

    protected function get_solver(): CaptchaSolver {
        if (!$this->captchaSolver) {
            $this->captchaSolver = new CaptchaSolver($this->captchaApiKey);
        }
        return $this->captchaSolver;
    }

    public function get_driver(): ?RemoteWebDriver {
        if (!isset($this->driver) || !$this->driver || !$this->driver->getSessionID()) {
            $host = 'http://localhost:9515';

            $this->tempProfileDir = sys_get_temp_dir() . '/chrome-profile-' . uniqid();
            if (!is_dir($this->tempProfileDir)) {
                mkdir($this->tempProfileDir, 0700, true);
            }

            $options = new ChromeOptions();
            $options->addArguments([
                '--headless=new',
                '--no-sandbox',
                '--disable-dev-shm-usage',
                "--user-data-dir={$this->tempProfileDir}",
            ]);

            $capabilities = DesiredCapabilities::chrome();
            $capabilities->setCapability(ChromeOptions::CAPABILITY, $options);

            $this->driver = RemoteWebDriver::create($host, $capabilities);
        }

        return $this->driver;
    }


   public function get_price(string $style, string $euSize): ?float {
    $mappingFile = __DIR__ . '/../admin/size-mappings.php';
    $attributeMap = [];

    if (file_exists($mappingFile)) {
        include $mappingFile;
        $attributeKey = $this->guess_attribute_key($style);
        $attributeMap = $size_mappings[$attributeKey] ?? [];
    } else {
        $this->log("⚠️ Mapping file not found at {$mappingFile}");
    }

    $use_eu_directly = in_array($attributeKey ?? '', [
        'attribute_pa_eu-mihara-yasuhiro',
        'attribute_pa_miharayasuhiroe',
        'attribute_pa_off-whiteeu',
    ]);

    $rawSize = $attributeMap[$euSize] ?? null;
    $finalSize = null;

    if ($rawSize) {
        if ($use_eu_directly) {
            $finalSize = $rawSize['EU Size'] ?? $euSize;
            $this->log("📐 Using EU size '{$finalSize}' for '{$attributeKey}'");
        } elseif (!empty($rawSize['US Size'])) {
            $finalSize = $rawSize['US Size'];
            $this->log("📐 Using US size '{$finalSize}' for '{$attributeKey}'");
        } else {
            $finalSize = $euSize;
            $this->log("⚠️ Missing 'US Size' for '{$attributeKey}', using EU size '{$euSize}' as fallback");
        }
    } else {
        $finalSize = $euSize;
        $this->log("⚠️ No mapping found for '{$attributeKey}'[{$euSize}], using as-is");
    }

    $slug = rawurlencode($style);
    $sizeParam = rawurlencode($finalSize);

    $queryParams = [
        'catchallFilters' => $slug,
        'size' => $sizeParam,
    ];

    $queryString = http_build_query($queryParams);
    $url = "https://stockx.com/{$slug}?{$queryString}";
    $this->log("Final URL: {$url}");

    try {
        $this->get_driver()->get($url);

        $this->get_driver()->wait(30, 500)->until(
            fn($d) => $d->executeScript('return document.readyState') === 'complete'
        );

        if ($this->isCaptchaPresent()) {
            $this->log("CAPTCHA triggered at {$url}");
            $this->handleCaptcha('', $url);
        }

        $priceSelector = 'h2[data-testid="trade-box-buy-amount"]';
        $this->log("🕒 Waiting for selector: {$priceSelector}");

        try {
            $el = $this->get_driver()->wait(40, 500)->until(
                WebDriverExpectedCondition::presenceOfElementLocated(
                    WebDriverBy::cssSelector($priceSelector)
                )
            );
        } catch (\Throwable $e) {
            $this->log("⚠️ Selector '{$priceSelector}' not found for size '{$euSize}' at {$url}");
            return null;
        }

        $basePrice = $this->getRawPrice($el);
        if ($basePrice === null) {
            $this->log("Raw price not found for style '{$style}', EU '{$euSize}', Final size '{$finalSize}'");
            return null;
        }

        // 💰 Ціна з доставкою і націнкою
            $product_id = $this->find_product_id_by_slug($style);
            if ($product_id && get_post($product_id) && has_term(431, 'product_cat', $product_id)) {
                $markup = 2;
                $shipping = 70;
                $this->log("🎯 Applying BERBRICK markup for product ID {$product_id}");
            } elseif ($basePrice < 1000) {
                $shipping = 50;
                $markup = 1.5;
            } elseif ($basePrice <= 3000) {
                $shipping = 70;
                $markup = 1.3;
            } else {
                $shipping = 100;
                $markup = 1.1;
            }

        $usd = $basePrice + $shipping;
        $this->log("➕ Added shipping: Base \${$basePrice} + Shipping \${$shipping} = \${$usd}");


        $finalPrice = round($usd * $markup * 43, 2);
        $this->log("📦 Base: \${$usd}, Markup: x{$markup}, Final: ₴{$finalPrice}");

        return $finalPrice;
    } catch (\Throwable $e) {
        $message = "🔴 EXCEPTION for {$style} EU:{$euSize} => " . get_class($e) . ": " . $e->getMessage();
        $trace = $e->getTraceAsString();

        $this->log($message . "\n" . $trace);
        file_put_contents(__DIR__ . '/../../debug-log.txt', "[{$style} {$euSize}] " . $message . "\n" . $trace . "\n\n", FILE_APPEND);

        return null;
    } finally {
        if ($this->driver) {
            $this->driver->quit();
            $this->driver = null;
        }

        if ($this->tempProfileDir && is_dir($this->tempProfileDir)) {
            exec('rm -rf ' . escapeshellarg($this->tempProfileDir));
            $this->tempProfileDir = null;
        }
    }
}

protected function find_product_id_by_slug(string $slug): ?int {
    global $wpdb;
    $like = '%' . $wpdb->esc_like($slug) . '%';

    $post_id = $wpdb->get_var(
        $wpdb->prepare(
            "
            SELECT post_id
            FROM {$wpdb->postmeta}
            WHERE meta_key = '_stockx_product_base_url'
              AND meta_value LIKE %s
            LIMIT 1
            ",
            $like
        )
    );

    return $post_id ? intval($post_id) : null;
}



    protected function getRawPrice($el) {
        $txt = $el->getText();
        if (preg_match('/[\d\.,]+/', $txt, $m)) {
            return floatval(str_replace([',', ' '], ['', ''], $m[0]));
        }
        return null;
    }

    private function isCaptchaPresent(): bool {
        try {
            $this->get_driver()->findElement(WebDriverBy::cssSelector('iframe[src*="turnstile"]'));
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function guess_attribute_key(string $style): string {
        $style = strtolower($style);

        $map = [
            'mihara'             => 'attribute_pa_eu-mihara-yasuhiro',
            'mihara yasuhiro'    => 'attribute_pa_eu-mihara-yasuhiro',
            'mihara-yasuhiro'    => 'attribute_pa_eu-mihara-yasuhiro',

            'yeezy boost'        => 'attribute_pa_yeezy-eu',
            'yeezy slide'        => 'attribute_pa_yeezyslideseu',
            'yeezy foam'         => 'attribute_pa_yeezyfoameu',
            'yeezy'              => 'attribute_pa_yeezyadidaseu',

            'nike'               => 'attribute_pa_nikejordeu',
            'jordan'             => 'attribute_pa_jordaneu',

            'off-white'          => 'attribute_pa_off-whiteeu',
            'off white'          => 'attribute_pa_off-whiteeu',

            'dior'               => 'attribute_pa_dioreu',
            'mschf red'          => 'attribute_pa_mschfredbootseu',
            'mschf crocs'        => 'attribute_pa_mschfcrocsbootseu',
            'asics'              => 'attribute_pa_asicscpeu',
            'new balance'        => 'attribute_pa_newbalanceeu',
        ];

        foreach ($map as $keyword => $attribute) {
            if (str_contains($style, $keyword)) {
                return $attribute;
            }
        }

        // fallback
        return 'attribute_pa_nikejordeu';
    }


    public function fetch_all_variations_sizes_prices($product_id) {
        $product = wc_get_product($product_id);
        if ($product->is_type('variable')) {
            foreach ($product->get_children() as $variation_id) {
                $variation = wc_get_product($variation_id);
                $stockx_url = get_post_meta($variation_id, '_stockx_product_url', true);
                $size = $variation->get_attribute('pa_size');

                if (!$stockx_url || empty($size)) {
                    $this->log("Skipping variation {$variation_id} - missing URL or size");
                    continue;
                }

                $is_women = get_post_meta($product->get_id(), '_stockx_is_women', true) === 'yes';
                $eu_size = $variation->get_attribute('pa_size');
                $attribute_key = $this->guess_attribute_key($slug);
                $size_mappings = include plugin_dir_path(__FILE__) . '/../admin/size-mappings.php';

                $raw = $size_mappings[$attribute_key][$eu_size] ?? null;
                $us_size = $raw['US Size'] ?? $eu_size; // fallback

                $size_param = $us_size . ($is_women ? 'W' : '');
                $price = floatval($this->get_price($slug, $size_param));


                if ($price !== null) {
                    $variation->set_price($price);
                    $variation->set_regular_price($price);
                    $variation->set_catalog_visibility('visible');
                    $this->log("✅ Set price ₴{$price} for variation {$variation_id}");
                } else {
                    $existing_price = $variation->get_price();
                    if (!empty($existing_price)) {
                        $this->log("⚠️ No price from StockX, removing old price for variation {$variation_id}");
                        $variation->set_price('');
                        $variation->set_regular_price('');
                    }

                    $variation->set_catalog_visibility('hidden');
                }

                $variation->save();
            }
        }
    }

}