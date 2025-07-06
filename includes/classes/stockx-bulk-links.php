<?php
// помістіть цей файл у свій плагін (наприклад, stockx-bulk-links.php) і активуйте плагін

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// 1) Функція, яка за SKU повертає URL StockX або null
function wcsx_get_stockx_url( string $sku ): ?string {
    $search_url = 'https://stockx.com/search?s=' . urlencode( $sku );
    $opts = [
        'http' => [
            'header'  => "User-Agent: Mozilla/5.0 (compatible; PHP script)\r\n",
            'timeout' => 10,
        ],
    ];
    $html = @file_get_contents( $search_url, false, stream_context_create( $opts ) );
    if ( ! $html ) {
        return null;
    }
    if ( preg_match(
        '#<a[^>]+href="(/[^"]+)"[^>]+aria-label="Product Tile Link"#',
        $html, $m
    ) ) {
        return 'https://stockx.com' . $m[1];
    }
    return null;
}

// 2) Реєструємо WP-CLI команду
if ( defined( 'WP_CLI' ) && WP_CLI ) {
    class StockX_Bulk_Links_Command {
        /**
         * Вивести всі SKU → StockX URL
         *
         * ## EXAMPLES
         *
         *     wp stockx-links generate
         */
        public function generate( $args, $assoc_args ) {
            // отримуємо ВСІ товари з ненульовим SKU
            $products = wc_get_products( [
                'limit'      => -1,
                'status'     => [ 'publish', 'private' ],
                'return'     => 'objects',
                'meta_query' => [
                    [
                        'key'     => '_sku',
                        'compare' => 'EXISTS',
                    ],
                    [
                        'key'     => '_sku',
                        'value'   => '',
                        'compare' => '!=',
                    ],
                ],
            ] );

            // заголовок
            WP_CLI::line( "SKU\tProduct ID\tНазва\tStockX URL" );

            foreach ( $products as $product ) {
                $sku       = $product->get_sku();
                $title     = $product->get_name();
                $id        = $product->get_id();
                $stockx    = wcsx_get_stockx_url( $sku ) ?: 'не знайдено на стокх';
                // виводимо таб-розділену строку
                WP_CLI::line( "{$sku}\t{$id}\t{$title}\t{$stockx}" );
            }
        }
    }

    WP_CLI::add_command( 'stockx-links', 'StockX_Bulk_Links_Command' );
}
