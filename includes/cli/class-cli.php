<?php

namespace StockXSync;

use WP_CLI;
use WC_Product_Variable;

if (!class_exists('\WP_CLI')) return;

class CLI_Sync {



    public static function register() {
        WP_CLI::add_command('stockx sync-all', [__CLASS__, 'sync_all_products']);
        WP_CLI::add_command('stockx sync-product', [__CLASS__, 'sync_single_product']);
        WP_CLI::add_command( 'stockx sync-single-product-women', [ __CLASS__, 'sync_single_product_women' ] );
        WP_CLI::add_command( 'stockx check-women', [ __CLASS__, 'check_women_checkbox' ] );
        WP_CLI::add_command( 'stockx sync-women', [ __CLASS__, 'sync_women_products' ] );
        WP_CLI::add_command('stockx parse-buynow', [__CLASS__, 'parse_products_buynow']);
        WP_CLI::add_command(
            'stockx sync-product-women',
            [ __CLASS__, 'sync_single_product_women' ],
            [
            'shortdesc' => 'Sync single variable product using Women sizes',
            'synopsis'  => [
                [
                'type'        => 'positional',
                'name'        => 'product_id',
                'description' => 'ID of the variable product',
                'optional'    => false,
                ],
            ],
            ]
        );

        WP_CLI::add_command(
                'stockx fetch-all',
                [ __CLASS__, 'cli_fetch_all_variations' ],
                [
                    'shortdesc' => 'Fetch all variation sizes & prices via StockX for a variable product',
                    'synopsis'  => [
                        [
                            'type'        => 'positional',
                            'name'        => 'product_id',
                            'description' => 'ID of the variable product',
                            'optional'    => false,
                            'repeats'     => false,
                        ],
                    ],
                ]
            );

    }

    /**
     * Парсинг “Buy Now” ціни для всіх варіацій backorder/preorder продукції
     * Пропускає товари з ID, що вказані у $excluded_ids.
     * Замість прямого Selenium-навігатора (як було $client->get(...)),
     * використовуємо існуючий метод $client->get_price($slug, $us_size).
     */

    public static function parse_products_buynow($args) {
        $excluded_ids = [
            19966, 20548, 20285,
            19229, 19228, 19227,
            8534,  8533, 21337,
        ];

        $last_product_id   = (int) get_option('stockx_sync_last_product', 0);
        $last_variation_id = (int) get_option('stockx_sync_last_variation', 0);
        $resuming_products = ($last_product_id > 0);

        $client   = new SeleniumClient();
        $size_map = require plugin_dir_path(__FILE__) . '../admin/size-mappings.php';
        $processed_variations = 0;

        $query = new \WP_Query([
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ]);

        foreach ($query->posts as $product_post) {
            $product_id = $product_post->ID;

            if (in_array($product_id, $excluded_ids, true)) {
                WP_CLI::log("⏭️ Skipping excluded product ID {$product_id}");
                continue;
            }

            if ($resuming_products) {
                if ($product_id !== $last_product_id) {
                    continue;
                } else {
                    $resuming_products = false;
                }
            }

            update_option('stockx_sync_last_product', $product_id, false);

            $product = wc_get_product($product_id);
            if (!$product || !$product->is_type('variable')) {
                WP_CLI::log("⏭️ Skipping product ID {$product_id} (not variable)");
                continue;
            }

            $base_url = get_post_meta($product_id, '_stockx_product_base_url', true);
            if (!$base_url) {
                WP_CLI::log("⏭️ Skipping product ID {$product_id} (no base URL)");
                continue;
            }

            $slug = trim(parse_url($base_url, PHP_URL_PATH), '/');
            $variation_ids = $product->get_children();

            WP_CLI::log("📦 Processing product ID {$product_id} ({$slug}) with " . count($variation_ids) . " variations");

            $skip_variations = ($last_variation_id > 0 && get_option('stockx_sync_last_product') === $last_product_id);

            foreach ($variation_ids as $variation_id) {
                if ($skip_variations) {
                    if ($variation_id === $last_variation_id) {
                        $skip_variations = false;
                    }
                    continue;
                }

                update_option('stockx_sync_last_variation', $variation_id, false);

                $variation = wc_get_product($variation_id);
                if (!$variation) {
                    continue;
                }

                $is_preorder  = get_post_meta($variation_id, '_is_preorder', true);
                $is_backorder = $variation->is_on_backorder();
                if (!$is_preorder && !$is_backorder) {
                    WP_CLI::log("⏭️ Variation {$variation_id} skipped (in-stock)");
                    continue;
                }

                // —————— 1) Визначаємо EU-розмір та ключ атрибута ——————
                $eu_size  = '';
                $attr_key = '';
                foreach ($variation->get_attributes() as $key => $value) {
                    if (strpos($key, 'size') !== false && $value) {
                        $eu_size  = $value;        // напр.: “44”
                        $attr_key = $key;          // напр.: “attribute_pa_jordaneu”
                        break;
                    }
                }
                if (!$eu_size) {
                    foreach ($variation->get_attributes() as $key => $value) {
                        $clean_key = str_replace('attribute_', '', $key);
                        if ($value = $variation->get_attribute($clean_key)) {
                            $eu_size  = $value;
                            $attr_key = $key;
                            break;
                        }
                    }
                }
                if (!$eu_size) {
                    WP_CLI::log("❌ Variation {$variation_id} has no size, skipping");
                    continue;
                }

                // —————— 2) Мапимо EU → US або беремо EU, якщо мапи нема ——————
                $us_size = $eu_size;
                if (
                    !empty($attr_key)
                    && isset($size_map[$attr_key])
                    && isset($size_map[$attr_key][$eu_size])
                    && isset($size_map[$attr_key][$eu_size]['US'])
                ) {
                    $us_raw   = $size_map[$attr_key][$eu_size]['US']; // “US 10”
                    $us_size  = trim(str_replace('US ', '', $us_raw)); // “10”
                }

                // —————— 3) Формуємо “Buy Now” URL ——————
                $full_url = "https://stockx.com/buy/{$slug}?size=" . urlencode($us_size) . "&defaultBuy=true";

                WP_CLI::log("🔍 Getting Buy Now price for variation {$variation_id} (EU: {$eu_size}, US: {$us_size})");
                WP_CLI::log("   URL: {$full_url}");

                try {
                    // —————— 4) Викликаємо navigateTo() замість $client->driver->get() ——————
                    $client->navigateTo($full_url);

                    // —————— 5) Чекаємо кнопку-аккордеон “Subtotal” і клікаємо її ——————
                    $accordion_xpath = '//p[normalize-space(text())="Subtotal"]/ancestor::button';
                    $client->waitFor('xpath', $accordion_xpath, 10000);
                    $client->find('xpath', $accordion_xpath)->click();

                    // —————— 6) Чекаємо “Subtotal” у розгорнутому блоці ——————
                    $subtotal_xpath = '
                        //div[@data-component="item-row"]
                          //div[text()="Subtotal"]
                          /following-sibling::div//*[@data-testid="bid-total"]//*[name()="span"]
                    ';
                    $client->waitFor('xpath', $subtotal_xpath, 10000);
                    $price_text = $client->find('xpath', $subtotal_xpath)->getText();

                    // Прибираємо $ і коми, конвертуємо в float
                    $basePrice = floatval(str_replace(['$', ','], '', $price_text));

                    if ($basePrice <= 0) {
                        WP_CLI::warning("⚠️ No valid “Subtotal” found for variation {$variation_id}");
                        $variation->set_price('');
                        $variation->set_regular_price('');
                        $variation->set_stock_status('outofstock');
                        $variation->save();

                        WP_CLI::log("⛔ Variation {$variation_id} set to out-of-stock (no subtotal)");
                        continue;
                    }

                    self::log("🔢 Raw “Subtotal” price: \${$basePrice}");

                    // —————— 7) Обчислюємо націнку (Subtotal вже включає всі збори) ——————
                    if ($basePrice < 1000) {
                        $markup_factor = 1.5;
                    } elseif ($basePrice <= 3000) {
                        $markup_factor = 1.3;
                    } else {
                        $markup_factor = 1.1;
                    }
                    $finalPrice = round($basePrice * $markup_factor, 2);
                    self::log("💲 After markup (×{$markup_factor}): \${$finalPrice}");

                    // —————— 8) Оновлюємо ціну у WooCommerce ——————
                    $variation->set_price($finalPrice);
                    $variation->set_regular_price($finalPrice);
                    $variation->set_catalog_visibility('visible');
                    $variation->save();

                    // —————— 9) Записуємо мета-поля ——————
                    update_post_meta($variation_id, '_stockx_product_url',    $full_url);
                    update_post_meta($variation_id, '_stockx_price_synced_at', current_time('mysql'));
                    update_post_meta($product_id,    '_stockx_last_cli_sync',  current_time('mysql'));

                    // ==== ВІДПРАВКА В GOOGLE SHEET ====
                    $data = [
                        'product_id'    => $product_id,
                        'variation_id'  => $variation_id,
                        'price'         => $price,
                        'synced_at'     => current_time('mysql'),
                    ];

                    $response = wp_remote_post('https://script.google.com/macros/s/AKfycbyT-dAT7-P66SWOfcoM22YhbmEhL12PYsOvKOGNEzi4rF8tBcviH134fMwb664ea22U/exec', [
                        'method'  => 'POST',
                        'timeout' => 10,
                        'headers' => [
                            'Content-Type' => 'application/json',
                        ],
                        'body' => json_encode($data),
                    ]);

                    if (is_wp_error($response)) {
                        WP_CLI::warning("❌ Webhook error: " . $response->get_error_message());
                    } else {
                        WP_CLI::log("📤 Sent to Google Sheet: " . wp_remote_retrieve_body($response));
                    }

                    WP_CLI::success("✅ Variation {$variation_id} synced: final price \${$finalPrice}");
                    $processed_variations++;

                } catch (\Throwable $e) {
                    WP_CLI::warning("⚠️ Variation {$variation_id} error: " . $e->getMessage());
                    continue;
                }

                // —————— 10) Затримка, щоб не перевантажити сервер ——————
                $delay = rand(5, 10);
                WP_CLI::log("⏳ Sleeping {$delay}s…");
                sleep($delay);
            }

            // Очищаємо чекпоінт варіацій після кожного продукту
            delete_option('stockx_sync_last_variation');
        }

        // Після завершення видаляємо всі чекпоінти
        delete_option('stockx_sync_last_product');
        delete_option('stockx_sync_last_variation');

        WP_CLI::success("🏁 Buy Now parsing complete: {$processed_variations} variations processed.");
    }

    // Єдина реалізація методу log()
    protected static function log(string $msg) {
        WP_CLI::log($msg);
    }
    
public static function sync_all_products() {
    // Масив ID продуктів, які потрібно пропустити
    $excluded_ids = [
        19966,
        20548,
        20285,
        19229,
        19228,
        19227,
        8534,
        8533,
        21337,
    ];

    // 1) Зчитуємо останні чекпоінти
    $last_product_id   = (int) get_option('stockx_sync_last_product', 0);
    $last_variation_id = (int) get_option('stockx_sync_last_variation', 0);
    $resuming_products = $last_product_id > 0;

    $client   = new SeleniumClient();
    $size_map = require plugin_dir_path(__FILE__) . '../admin/size-mappings.php';
    $processed_variations = 0;

    // 2) Отримуємо всі товари
    $query = new \WP_Query([
        'post_type'      => 'product',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'orderby'        => 'date',   // сортування за датою
        'order'          => 'DESC',   // від нових до старих
    ]);

    foreach ($query->posts as $product_post) {
        $product_id = $product_post->ID;

        // Якщо цей ID у списку виключених — пропускаємо його
        if (in_array($product_id, $excluded_ids, true)) {
            WP_CLI::log("⏭️ Skipping excluded product ID {$product_id}");
            continue;
        }

        // 3) Якщо резюмимо — пропускаємо, доки не знайдемо місце зупинки
        if ($resuming_products) {
            if ($product_id !== $last_product_id) {
                continue;
            } else {
                // знайшли останній оброблений товар — наступні обробляємо
                $resuming_products = false;
            }
        }

        // зберігаємо поточний товар як чекпоінт
        update_option('stockx_sync_last_product', $product_id, false);

        $product = wc_get_product($product_id);
        if (!$product || !$product->is_type('variable')) {
            WP_CLI::log("⏭️ Skipping product ID {$product_id} (not variable)");
            continue;
        }

        $base_url = get_post_meta($product_id, '_stockx_product_base_url', true);
        if (!$base_url) {
            WP_CLI::log("⏭️ Skipping product ID {$product_id} (no base URL)");
            continue;
        }

        $slug = trim(parse_url($base_url, PHP_URL_PATH), '/');
        $variation_ids = $product->get_children();

        WP_CLI::log("📦 Processing product ID {$product_id} with " . count($variation_ids) . " variations");

        // 4) Визначаємо, чи треба пропускати варіації до last_variation
        $skip_variations = $last_variation_id > 0 && get_option('stockx_sync_last_product') == $last_product_id;

        foreach ($variation_ids as $variation_id) {
            // пропускаємо, доки не зустрінемо last_variation
            if ($skip_variations) {
                if ($variation_id === $last_variation_id) {
                    $skip_variations = false; // починаємо обробку наступних
                }
                continue;
            }

            // зберігаємо чекпоінт на рівні варіації
            update_option('stockx_sync_last_variation', $variation_id, false);

            $variation = wc_get_product($variation_id);
            if (!$variation) {
                continue;
            }

            // синхронимо тільки backorder/preorder
            $is_preorder   = get_post_meta($variation_id, '_is_preorder', true);
            $is_backorder  = $variation->is_on_backorder();
            if (! $is_preorder && ! $is_backorder) {
                WP_CLI::log("⏭️ Variation {$variation_id} skipped (in-stock)");
                continue;
            }

            // детектимо розмір
            $size = '';
            foreach ($variation->get_attributes() as $key => $value) {
                if (strpos($key, 'size') !== false && $value) {
                    $size = $value; break;
                }
            }
            if (! $size) {
                foreach ($variation->get_attributes() as $key => $value) {
                    $attr_key = str_replace('attribute_', '', $key);
                    if ($value = $variation->get_attribute($attr_key)) {
                        $size = $value; break;
                    }
                }
            }
            if (! $size) {
                WP_CLI::log("❌ Variation {$variation_id} has no size, skipping");
                continue;
            }

            // готуємо URL
            $us_size = $size_map[$size]['US Size'] ?? $size;
            $full_url = "https://stockx.com/{$slug}?catchallFilters={$slug}&size=" . urlencode($us_size);

            // отримуємо і оновлюємо ціну
            try {
                WP_CLI::log("🔍 Getting price for variation {$variation_id} (EU: {$size})");
                $price = floatval($client->get_price($slug, $us_size));

                if (! $price) {
                    WP_CLI::warning("⚠️ No price for variation {$variation_id}");
                    // Видаляємо стару ціну та ставимо out-of-stock
                    $variation->set_price('');
                    $variation->set_regular_price('');
                    $variation->set_stock_status('outofstock');
                    $variation->save();

                    WP_CLI::log("⛔ Variation {$variation_id} set to out-of-stock (no price found)");
                    continue;
                }

                $variation->set_price($price);
                $variation->set_regular_price($price);
                $variation->set_catalog_visibility('visible');
                $variation->save();
                update_post_meta($variation_id, '_stockx_product_url', $full_url);
                update_post_meta($variation_id, '_stockx_price_synced_at', current_time('mysql') );
                update_post_meta( $product_id, '_stockx_last_cli_sync', current_time( 'mysql' ) );

                
                $data = [
                    'product_id'    => $product_id,
                    'variation_id'  => $variation_id,
                    'price'         => $price,
                    'synced_at'     => current_time('mysql'),
                ];

                $response = wp_remote_post('https://script.google.com/macros/s/AKfycbyT-dAT7-P66SWOfcoM22YhbmEhL12PYsOvKOGNEzi4rF8tBcviH134fMwb664ea22U/exec', [
                    'method'  => 'POST',
                    'timeout' => 10,
                    'headers' => [ 'Content-Type' => 'application/json' ],
                    'body'    => json_encode($data),
                ]);

                if (is_wp_error($response)) {
                    WP_CLI::warning("❌ Webhook error: " . $response->get_error_message());
                } else {
                    WP_CLI::log("📤 Sent to Google Sheet: " . wp_remote_retrieve_body($response));
                }

                $synced_at = get_post_meta( $variation_id, '_stockx_price_synced_at', true );
                if ( $synced_at ) {
                    echo "Ціна взята зі StockX (остання синхронізація: $synced_at)";
                } else {
                    echo "Ціна введена вручну через адмінку";
                }

                WP_CLI::log("✅ Price {$price} set for variation {$variation_id}");
                $processed_variations++;

            } catch (\Throwable $e) {
                WP_CLI::warning("⚠️ Variation {$variation_id} error: " . $e->getMessage());
                continue;
            }

            // невелика затримка
            $delay = rand( 5, 10 );
            WP_CLI::log( "⏳ Sleeping {$delay}s…" );
            sleep( $delay );
        }

        // 5) Після продукту очищаємо чекпоінт варіацій
        delete_option('stockx_sync_last_variation');
    }

    // 6) Після всіх — видаляємо обидва чекпоінти
    delete_option('stockx_sync_last_product');
    delete_option('stockx_sync_last_variation');

    WP_CLI::success("🏁 Sync complete: {$processed_variations} variations processed.");
}

/**
 * WP-CLI: Синхронізувати всі варіації товарів з увімкненим флагом «Women Sizes»
 */
public static function sync_women_products() {
    // Зчитуємо карту розмірів
    $size_map = require plugin_dir_path( __FILE__ ) . '../admin/size-mappings.php';

    // Шукаємо всі товари з _stockx_is_women = 'yes'
    $query = new \WP_Query([
        'post_type'      => 'product',
        'posts_per_page' => -1,
        'meta_key'       => '_stockx_is_women',
        'meta_value'     => 'yes',
    ]);

    if ( empty( $query->posts ) ) {
        WP_CLI::log( '🔍 Товари з позначкою Women Sizes не знайдено.' );
        return;
    }

    $processed = 0;

    foreach ( $query->posts as $post ) {
        $product = wc_get_product( $post->ID );

        if ( ! $product || ! $product->is_type( 'variable' ) ) {
            WP_CLI::warning( "⏭️ Product ID {$post->ID} не є варіативним." );
            continue;
        }

        $base_url = get_post_meta( $post->ID, '_stockx_product_base_url', true );
        if ( ! $base_url ) {
            WP_CLI::warning( "⚠️ Product ID {$post->ID} відсутній базовий URL." );
            continue;
        }

        $slug = trim( parse_url( $base_url, PHP_URL_PATH ), '/' );

        foreach ( $product->get_children() as $vid ) {
            $variation = wc_get_product( $vid );
            if ( ! $variation ) {
                WP_CLI::warning( "⚠️ Варіація {$vid} не знайдена." );
                continue;
            }

            // Витягуємо EU-розмір
            $size = '';
            foreach ( $variation->get_attributes() as $attr_key => $attr_val ) {
                if ( strpos( $attr_key, 'size' ) !== false && $attr_val ) {
                    $size = $attr_val;
                    break;
                }
            }

            if ( ! $size ) {
                WP_CLI::warning( "⚠️ Розмір не знайдено для варіації {$vid}." );
                continue;
            }

            // Мапимо в US Size та додаємо 'W'
            $us = $size_map[ $size ]['US Size'] ?? $size;
            if ( strpos( $us, 'W' ) === false ) {
                $us .= 'W';
            }

            WP_CLI::log( "🔍 Отримуємо ціну для варіації {$vid} (US: {$us})…" );

            try {
                $client = new SeleniumClient();
                $price  = floatval( $client->get_price( $slug, $us ) );

                if ( ! $price ) {
                    WP_CLI::warning( "⚠️ Ціна не знайдена для варіації {$vid}." );
                    continue;
                }

                // Оновлюємо варіацію
                $variation->set_price( $price );
                $variation->set_regular_price( $price );
                $variation->save();

                WP_CLI::log( "✅ Встановлено ціну {$price} для варіації {$vid}." );
                $processed++;

            } catch ( \Throwable $e ) {
                WP_CLI::warning( "⚠️ Помилка для варіації {$vid}: " . $e->getMessage() );
            }
        }
    }

    WP_CLI::success( "✅ Синхронізовано ціни для {$processed} варіацій." );
}


    // for all products
    // public static function sync_all_products() {
        
    //     $client = new SeleniumClient();
    //     $size_map = require plugin_dir_path(__FILE__) . '../admin/size-mappings.php';
    //     $processed_variations = 0;

    //     $query = new \WP_Query([
    //         'post_type'      => 'product',
    //         'post_status'    => 'publish',
    //         'posts_per_page' => -1,
    //         'orderby'        => 'date',   // сортування за датою
    //         'order'          => 'DESC',   // від нових до старих
    //     ]);

    //     // $query = new \WP_Query([
    //     //     'post_type'      => 'product',
    //     //     'post_status'    => 'publish',
    //     //     'posts_per_page' => -1,
    //     //     'orderby'        => 'date',
    //     //     'order'          => 'DESC',
    //     //     'tax_query'      => [
    //     //         [
    //     //             'taxonomy'         => 'product_cat',
    //     //             'field'            => 'term_id',
    //     //             'terms'            => [619], // 👈 тільки категорія ID 58
    //     //             'include_children' => true,
    //     //             'operator'         => 'IN',
    //     //         ],
    //     //     ],
    //     // ]);



    //     $total_products = count($query->posts);
    //     $current_product_index = 0;
    //     $total_variations = 0;
    //     $processed_variations = 0;
    //     $start_time = microtime(true);

    //     foreach ($query->posts as $product_post) {
    //         $current_product_index++;
    //         $product = wc_get_product($product_post->ID);
    //         if (!$product || !$product->is_type('variable')) continue;

    //         $base_url = get_post_meta($product->get_id(), '_stockx_product_base_url', true);
    //         if (!$base_url) {
    //             WP_CLI::log("⏭️ Skipping product ID {$product->get_id()} (no StockX base URL)");
    //             continue;
    //         }

    //         $variation_ids = $product->get_children();
    //         $total_variations += count($variation_ids);

    //         WP_CLI::log("📦 Product {$current_product_index} / {$total_products} (ID: {$product->get_id()})");

    //         $slug = trim(parse_url($base_url, PHP_URL_PATH), '/');

    //         // for product with equal price for all variations
    //         foreach ($variation_ids as $variation_id) {
    //             $processed_variations++;

    //             $elapsed = microtime(true) - $start_time;
    //             $avg_time = $elapsed / max($processed_variations, 1);
    //             $remaining = $total_variations - $processed_variations;
    //             $eta = gmdate('i:s', (int) round($avg_time * $remaining));

    //             WP_CLI::log("👟 Variation {$processed_variations} / {$total_variations} ⏱️ ETA: {$eta} ⌛ Avg: " . round($avg_time, 1) . "s");

    //             $variation = wc_get_product($variation_id);
    //             if (!$variation) continue;
    //             // ✅ Only sync if backorder or preorder
    //             $is_preorder = get_post_meta($variation_id, '_is_preorder', true);
    //             $is_backorder = $variation->is_on_backorder();

    //             if (!$is_preorder && !$is_backorder) {
    //                 WP_CLI::log("⏭️ Variation {$variation_id} is not preorder or backorder, skipping.");
    //                 continue;
    //             }

    //             // ✅ STEP 1: Detect size
    //             $size = '';
    //             foreach ($variation->get_attributes() as $key => $value) {
    //                 if (empty($size) && strpos($key, 'size') !== false && !empty($value)) {
    //                     $size = $value;
    //                 }
    //             }

    //             if (empty($size)) {
    //                 foreach ($variation->get_attributes() as $key => $value) {
    //                     $attr_key = str_replace('attribute_', '', $key);
    //                     $size = $variation->get_attribute($attr_key);
    //                     if (!empty($size)) break;
    //                 }
    //             }

    //             if (!$size) {
    //                 WP_CLI::log("❌ Variation $variation_id has no size, skipping");
    //                 continue;
    //             }

    //             // ✅ STEP 2: Map to US size and prepare URL
    //             $us_size = $size_map[$size]['US Size'] ?? $size;
    //             $full_url = "https://stockx.com/{$slug}?catchallFilters={$slug}&size=" . urlencode($us_size);

    //             // ✅ STEP 3: Compare and maybe update price
    //             $existing_price = floatval($variation->get_price());

                
    //             try {
    //                 WP_CLI::log("🔍 Getting price for variation {$variation_id} (EU: {$size})");
    //                 $price = floatval($client->get_price($slug, $us_size));

    //                 if (!$price || !is_numeric($price)) {
    //                     WP_CLI::warning("⚠️ No price for variation {$variation_id}");

    //                     // 🔻 Очистити стару ціну, якщо є
    //                     if ($variation->get_price()) {
    //                         $variation->set_price('');
    //                         $variation->set_regular_price('');
    //                         $variation->set_catalog_visibility('hidden');
    //                         $variation->save();
    //                         WP_CLI::log("❌ Removed old price and hid variation {$variation_id}");
    //                     }

    //                     continue;
    //                 }

    //                 $variation->set_price($price);
    //                 $variation->set_regular_price($price);
    //                 $variation->set_catalog_visibility('visible');
    //                 $variation->save();

    //                 update_post_meta($variation_id, '_stockx_product_url', $full_url);
                    

    //                 WP_CLI::log("✅ Price {$price} UAH set for variation {$variation_id}");
    //                 $processed_variations++;

    //             } catch (\Throwable $e) {
    //                 WP_CLI::warning("⚠️ Variation {$variation_id} error: " . $e->getMessage());
    //                 continue;
    //             }

    //             $delay = rand(10, 20);
    //             WP_CLI::log("⏳ Sleeping for {$delay}s...");
    //             sleep($delay);
    //         }

    //         // for all variations with different prices
    //         // foreach ($variation_ids as $variation_id) {
    //         //     $processed_variations++;

    //         //     $elapsed = microtime(true) - $start_time;
    //         //     $avg_time = $elapsed / max($processed_variations, 1);
    //         //     $remaining = $total_variations - $processed_variations;
    //         //     $eta = gmdate('i:s', (int) round($avg_time * $remaining));

    //         //     WP_CLI::log("👟 Variation {$processed_variations} / {$total_variations} ⏱️ ETA: {$eta} ⌛ Avg: " . round($avg_time, 1) . "s");


    //         //     $variation = wc_get_product($variation_id);
    //         //     if (!$variation) continue;

    //         //         $existing_price = floatval($variation->get_price());

    //         //         try {
    //         //             WP_CLI::log("🔍 Отримую ціну для variation {$variation_id} (EU: {$size})");

    //         //             $price = $client->get_price($slug, $us_size);
    //         //             $price = floatval($price);

    //         //             if (!$price || !is_numeric($price)) {
    //         //                 WP_CLI::warning("⚠️ Немає ціни для variation {$variation_id}");
    //         //                 continue;
    //         //             }

    //         //             if (abs($existing_price - $price) < 0.01) {
    //         //                 WP_CLI::log("⏭️ Variation {$variation_id} already has correct price ({$existing_price}), skipping");
    //         //                 continue;
    //         //             }

    //         //             $variation->set_price($price);
    //         //             $variation->set_regular_price($price);
    //         //             $variation->save();

    //         //             update_post_meta($variation_id, '_stockx_product_url', $full_url);

    //         //             WP_CLI::log("✅ Ціна {$price} UAH встановлена для variation {$variation_id}");
    //         //         } catch (\Throwable $e) {
    //         //             WP_CLI::warning("⚠️ Variation {$variation_id} skipped due to error: " . $e->getMessage());
    //         //             continue;
    //         //         }


    //         //     $size = '';
    //         //     foreach ($variation->get_attributes() as $key => $value) {
    //         //         if (empty($size) && strpos($key, 'size') !== false && !empty($value)) {
    //         //             $size = $value;
    //         //         }
    //         //     }

    //         //     if (empty($size)) {
    //         //         foreach ($variation->get_attributes() as $key => $value) {
    //         //             $attr_key = str_replace('attribute_', '', $key);
    //         //             $size = $variation->get_attribute($attr_key);
    //         //             if (!empty($size)) break;
    //         //         }
    //         //     }

    //         //     if (!$size) {
    //         //         WP_CLI::log("❌ Variation $variation_id has no size, skipping");
    //         //         continue;
    //         //     }

    //         //     $us_size = $size_map[$size]['US Size'] ?? $size;
    //         //     $full_url = "https://stockx.com/{$slug}?catchallFilters={$slug}&size=" . urlencode($us_size);

    //         //     try {
    //         //         WP_CLI::log("🔍 Отримую ціну для variation {$variation_id} (EU: {$size})");

    //         //         $price = $client->get_price($slug, $us_size);

    //         //         if ($price && is_numeric($price)) {
    //         //             $variation->set_price($price);
    //         //             $variation->set_regular_price($price);
    //         //             $variation->save();

    //         //             update_post_meta($variation_id, '_stockx_product_url', $full_url);

    //         //             WP_CLI::log("✅ Ціна {$price} UAH встановлена для variation {$variation_id}");
    //         //         } else {
    //         //             WP_CLI::warning("⚠️ Немає ціни для variation {$variation_id}");
    //         //         }
    //         //     } catch (\Throwable $e) {
    //         //         WP_CLI::warning("⚠️ Variation {$variation_id} skipped due to error: " . $e->getMessage());
    //         //         continue;
    //         //     }

    //         //     $delay = rand(10, 20);
    //         //     WP_CLI::log("⏳ Sleeping for {$delay}s...");
    //         //     sleep($delay);
    //         // }
    //     }

    //     $total_time = round(microtime(true) - $start_time);
    //     WP_CLI::success("✅ Done: {$current_product_index} products, {$processed_variations} variations in {$total_time}s.");
    // }


    // for single product (multiply)
    public static function sync_single_product($args) {
        if (empty($args)) {
            WP_CLI::error("❌ Please provide at least one product ID.");
            return;
        }

        $client = new SeleniumClient();
        $size_map = require plugin_dir_path(__FILE__) . '../admin/size-mappings.php';
        $processed_variations = 0;

        foreach ($args as $product_id_raw) {
            $product_id = intval($product_id_raw);
            $product = wc_get_product($product_id);

            if (!$product || !$product->is_type('variable')) {
                WP_CLI::warning("⏭️ Product ID {$product_id} is not a variable product or not found.");
                continue;
            }

            $variation_ids = $product->get_children();
            $slug = trim(parse_url(get_post_meta($product->get_id(), '_stockx_product_base_url', true), PHP_URL_PATH), '/');
            if (!$slug) {
                WP_CLI::warning("⏭️ No StockX base URL found for product ID {$product_id}");
                continue;
            }

            WP_CLI::log("📦 Syncing product ID {$product_id} with " . count($variation_ids) . " variations...");

            foreach ($variation_ids as $variation_id) {
                $variation = wc_get_product($variation_id);
                if (!$variation) continue;
                // ✅ Only sync if backorder or preorder
                $is_preorder = get_post_meta($variation_id, '_is_preorder', true);
                $is_backorder = $variation->is_on_backorder();

                if (!$is_preorder && !$is_backorder) {
                    WP_CLI::log("⏭️ Variation {$variation_id} is not preorder or backorder, skipping.");
                    continue;
                }

                // Step 1: Get size
                $size = '';
                foreach ($variation->get_attributes() as $key => $value) {
                    if (empty($size) && strpos($key, 'size') !== false && !empty($value)) {
                        $size = $value;
                    }
                }
                if (empty($size)) {
                    foreach ($variation->get_attributes() as $key => $value) {
                        $attr_key = str_replace('attribute_', '', $key);
                        $size = $variation->get_attribute($attr_key);
                        if (!empty($size)) break;
                    }
                }

                if (!$size) {
                    WP_CLI::log("❌ Variation $variation_id has no size, skipping");
                    continue;
                }

                // Step 2: Prepare URL
                $us_size = $size_map[$size]['US Size'] ?? $size;
                $full_url = "https://stockx.com/{$slug}?catchallFilters={$slug}&size=" . urlencode($us_size);


                // Step 3: Sync price
                try {
                    WP_CLI::log("🔍 Getting price for variation {$variation_id} (EU: {$size})");
                    $price = floatval($client->get_price($slug, $us_size));
                    
                    if (! $price) {
                        WP_CLI::warning("⚠️ No price for variation {$variation_id}");
                        // Видаляємо стару ціну та ставимо out-of-stock
                        $variation->set_price('');
                        $variation->set_regular_price('');
                        $variation->set_stock_status('outofstock');
                        $variation->save();

                        WP_CLI::log("⛔ Variation {$variation_id} set to out-of-stock (no price found)");
                        continue;
                    }


                    $variation->set_price($price);
                    $variation->set_regular_price($price);
                    $variation->set_catalog_visibility('visible');
                    $variation->save();

                    update_post_meta($variation_id, '_stockx_product_url', $full_url);
                    update_post_meta($variation_id, '_stockx_price_synced_at', current_time('mysql') );
                    $synced_at = get_post_meta( $variation_id, '_stockx_price_synced_at', true );
                    if ( $synced_at ) {
                        echo "Ціна взята зі StockX (остання синхронізація: $synced_at)";
                    } else {
                        echo "Ціна введена вручну через адмінку";
                    }

                    WP_CLI::log("✅ Price {$price} UAH set for variation {$variation_id}");
                    $processed_variations++;

                } catch (\Throwable $e) {
                    WP_CLI::warning("⚠️ Variation {$variation_id} error: " . $e->getMessage());
                    continue;
                }


                $delay = rand(10, 20);
                WP_CLI::log("⏳ Sleeping for {$delay}s...");
                sleep($delay);
            }

            WP_CLI::success("✅ Finished product ID {$product_id}");
        }

        WP_CLI::success("🏁 All requested products processed.");
    }
    /**
 * WP-CLI: Sync single variable product, but using Women sizes (US_W_Size)
 *
 * Usage: wp stockx sync-product-women <PRODUCT_ID> --allow-root
 */

public static function sync_single_product_women( $args ) {
    if ( empty( $args ) ) {
        WP_CLI::error( "❌ Please provide at least one product ID." );
    }

    // Список ID продуктів, для яких потрібно виконувати синхронізацію
    $allowed_ids = [
        19966,
        20548,
        20285,
        19229,
        19228,
        19227,
        8534,
        8533,
        21337,
    ];

    $client   = new SeleniumClient();
    $size_map = require plugin_dir_path( __FILE__ ) . '../admin/size-mappings.php';
    $processed = 0;

    foreach ( $args as $raw ) {
        $product_id = absint( $raw );

        // Якщо ID не входить до списку дозволених — пропускаємо
        if ( ! in_array( $product_id, $allowed_ids, true ) ) {
            WP_CLI::log( "⏭️ Skipping product {$product_id} (not in allowed list)" );
            continue;
        }

        $product = wc_get_product( $product_id );
        if ( ! $product || ! $product->is_type( 'variable' ) ) {
            WP_CLI::warning( "⏭️ Product ID {$product_id} is not a variable product." );
            continue;
        }

        $variation_ids = $product->get_children();
        $base_url      = get_post_meta( $product_id, '_stockx_product_base_url', true );
        $slug          = trim( parse_url( $base_url, PHP_URL_PATH ), '/' );
        if ( ! $slug ) {
            WP_CLI::warning( "⏭️ No StockX base URL for product {$product_id}" );
            continue;
        }

        WP_CLI::log( "📦 Syncing WOMEN sizes for product {$product_id} (" . count( $variation_ids ) . " variations)" );

        foreach ( $variation_ids as $variation_id ) {
            $variation = wc_get_product( $variation_id );
            if ( ! $variation ) {
                continue;
            }

            // Синхронізуємо тільки backorder/preorder
            $is_preorder  = get_post_meta( $variation_id, '_is_preorder', true );
            $is_backorder = $variation->is_on_backorder();
            if ( ! $is_preorder && ! $is_backorder ) {
                WP_CLI::log( "⏭️ Variation {$variation_id} in stock, skipping." );
                continue;
            }

            // Крок 1: виявляємо EU-розмір
            $size = '';
            foreach ( $variation->get_variation_attributes() as $attr_key => $attr_val ) {
                if ( $attr_val && preg_match( '/^\d+([.,-]\d+)?$/', $attr_val ) ) {
                    $size = str_replace( [ ',', '-' ], '.', $attr_val );
                    break;
                }
            }
            if ( ! $size ) {
                WP_CLI::warning( "⚠️ Could not detect EU size for variation {$variation_id}, skipping." );
                continue;
            }

            // Крок 2: готуємо URL з жіночим US_W_Size
            $attribute_key = $client->guess_attribute_key( $slug );
            $raw_map       = $size_map[ $attribute_key ][ $size ] ?? [];
            // беремо US_W_Size, якщо є, інакше фолбек на US Size чи навіть EU
            $us_w = $raw_map['US_W_Size'] ?? ( $raw_map['US Size'] ?? $size );
            $us_w .= 'W';

            $full_url = sprintf(
                '%1$s?catchallFilters=%1$s&size=%2$s',
                $slug,
                rawurlencode( $us_w )
            );

            // Крок 3: отримуємо ціну з StockX за допомогою $us_w
            try {
                WP_CLI::log( "🔍 Getting price for variation {$variation_id} (EU: {$size}, US_W: {$us_w})" );
                $price = floatval( $client->get_price( $slug, $us_w ) );

                if ( ! $price ) {
                    WP_CLI::warning( "⚠️ No price for variation {$variation_id}" );
                    // Якщо ціни немає — видаляємо стару та ставимо out-of-stock
                    $variation->set_price( '' );
                    $variation->set_regular_price( '' );
                    $variation->set_stock_status( 'outofstock' );
                    $variation->save();

                    WP_CLI::log( "⛔ Variation {$variation_id} set to out-of-stock (no price found)" );
                    continue;
                }

                $variation->set_price( $price );
                $variation->set_regular_price( $price );
                $variation->set_catalog_visibility( 'visible' );
                $variation->save();

                update_post_meta( $variation_id, '_stockx_product_url', $full_url );
                update_post_meta( $variation_id, '_stockx_price_synced_at', current_time( 'mysql' ) );
                $synced_at = get_post_meta( $variation_id, '_stockx_price_synced_at', true );
                if ( $synced_at ) {
                    echo "Ціна взята зі StockX (остання синхронізація: {$synced_at})";
                } else {
                    echo "Ціна введена вручну через адмінку";
                }

                WP_CLI::log( "✅ Price {$price} UAH set for variation {$variation_id}" );
                $processed++;

            } catch ( \Throwable $e ) {
                WP_CLI::warning( "⚠️ Variation {$variation_id} error: " . $e->getMessage() );
                continue;
            }

            // небагато затримки, щоб не перевантажувати StockX
            $delay = rand( 5, 10 );
            WP_CLI::log( "⏳ Sleeping {$delay}s…" );
            sleep( $delay );
        }

        WP_CLI::success( "✅ Finished product {$product_id}, {$processed} variations synced." );
    }
}



    /**
     * WP-CLI: Fetch all variation sizes & prices for a variable product
     *
     * @param array $args [0] => product_id
    */
    /**
     * Отримує всі варіації, генерує URL, дістає ціну та оновлює WooCommerce.
     */

    public function fetch_all_variations_sizes_prices( $product_id ) {
        $product = wc_get_product( $product_id );
        if ( ! $product || ! $product->is_type( 'variable' ) ) {
            $this->log( "⚠️ Product {$product_id} is not variable." );
            return;
        }

        // Базовий URL із продукту
        $base_url = get_post_meta( $product_id, '_stockx_product_base_url', true );
        if ( ! $base_url ) {
            $this->log( "⚠️ Missing base URL for product {$product_id}" );
            return;
        }
        $style    = trim( parse_url( $base_url, PHP_URL_PATH ), '/' );
        $is_women = get_post_meta( $product_id, '_stockx_is_women', true ) === 'yes';
        $maps     = require plugin_dir_path( __FILE__ ) . '/../admin/size-mappings.php';
        $attrKey  = $this->guess_attribute_key( $style );

        foreach ( $product->get_children() as $variation_id ) {
            $variation = wc_get_product( $variation_id );
            if ( ! $variation ) {
                $this->log( "⚠️ Variation {$variation_id} not found." );
                continue;
            }

        // Динамічне виявлення EU-розміру серед усіх атрибутів варіації
        $size = '';
        foreach ( $variation->get_variation_attributes() as $attr_key => $attr_val ) {
            if ( ! $attr_val ) {
                continue;
            }
            // Міняємо дефіс і кому на крапку
            $candidate = str_replace( [ ',', '-' ], [ '.', '.' ], $attr_val );
            // Має бути число або число з крапкою
            if ( preg_match( '/^\d+(\.\d+)?$/', $candidate ) ) {
                $size = $candidate;
                break;
            }
        }
        if ( ! $size ) {
            $this->log( "Skipping variation {$variation_id} – could not detect EU size" );
            continue;
        }


            // Map EU → US та додати "W" якщо потрібно
            $raw = $maps[ $attrKey ][ $size ] ?? null;
            $us  = $raw['US Size'] ?? $size;
            if ( $is_women && strpos( $us, 'W' ) === false ) {
                $us .= 'W';
            }

            // Генеруємо кінцевий URL
            $size_param    = rawurlencode( $us );
            $variation_url = sprintf(
                '%1$s?catchallFilters=%1$s&size=%2$s',
                $base_url,
                $size_param
            );

            // Лог URL (за бажанням)
            $this->log( "Final URL: {$variation_url}" );

            // Отримуємо ціну
            $price = floatval( $this->get_price( $style, $us ) );
            if ( $price !== null ) {
                $variation->set_price( $price );
                $variation->set_regular_price( $price );
                $variation->set_catalog_visibility( 'visible' );
                $variation->save();

                $this->log( "✅ Price ₴{$price} set for variation {$variation_id}" );
            } else {
                // Якщо немає ціни — ховаємо варіацію
                $variation->set_price( '' );
                $variation->set_regular_price( '' );
                $variation->set_catalog_visibility( 'hidden' );
                $variation->save();

                $this->log( "⚠️ No price for variation {$variation_id}, hidden" );
            }
        }
    }

    public static function cli_fetch_all_variations( $args ) {
        $pid = absint( $args[0] ?? 0 );
        if ( ! $pid ) {
            WP_CLI::error( 'Please provide a valid product ID.' );
        }

        $product = wc_get_product( $pid );
        if ( ! $product || ! $product->is_type( 'variable' ) ) {
            WP_CLI::error( "Product {$pid} is not a variable product." );
        }

        WP_CLI::log( "🔄 Starting fetch_all_variations_sizes_prices for product {$pid}" );
        $client = new SeleniumClient();
        $client->fetch_all_variations_sizes_prices( $pid );
        WP_CLI::success( "✅ Done fetching variations for product {$pid}" );
    }

public static function check_women_checkbox( $args, $assoc_args ) {
    $meta_key = 'women_w_checkbox'; // замініть на свій реальний meta_key

    // Звертаємось до глобального WP_Query через слеш
    $query = new \WP_Query([
        'post_type'      => 'product',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'fields'         => 'ids',
    ]);

    if ( empty( $query->posts ) ) {
        WP_CLI::success( "Не знайдено жодного продукту." );
        return;
    }

    foreach ( $query->posts as $product_id ) {
        $has = get_post_meta( $product_id, $meta_key, true );
        if ( $has ) {
            WP_CLI::log( "✅ Product ID {$product_id} — має чекбокс Women W" );
        } else {
            WP_CLI::warning( "❌ Product ID {$product_id} — НЕ має чекбоксу Women W" );
        }
    }

    WP_CLI::success( 'Перевірка завершена.' );
}
}