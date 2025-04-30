# StockX Sync for WooCommerce

This WordPress plugin syncs out-of-stock WooCommerce variation prices with StockX using Selenium WebDriver scraping.

## Features

- Sync price for specific SKU or all products
- CLI and Admin interface
- Daily or custom cron interval
- PSR-4 autoloading via Composer

## Setup

1. Install dependencies:

```bash
composer install
```

2. Set up Selenium + ChromeDriver at `localhost:9515` or update the path in plugin settings.

## Commands

```bash
wp stockx-sync get-price <style> <size>
wp stockx-sync sync-all --rate=42
wp stockx-sync sync-by-sku <sku> --rate=42
wp stockx-slug <sku>
```

## Folder Structure

- `includes/classes/`: Main plugin classes
- `includes/cli/`: WP-CLI command handlers
- `composer.json`: PSR-4 + dependency definitions

## License

MIT
