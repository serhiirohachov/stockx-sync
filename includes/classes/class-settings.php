<?php
namespace StockXSync;

class Settings {
    const GROUP = 'stockx_sync_group';
    const PAGE  = 'stockx-sync-settings';

    public static function get_price_conversion_settings(): array {
        return [
            'exchange_rate' => floatval(get_option('stockx_exchange_rate', 42)), // курс долара
            'markup'        => floatval(get_option('stockx_markup_percent', 30)), // націнка у відсотках
        ];
    }

    public static function init_defaults() {
        $defaults = [
            'stockx_sync_selector_button' => 'button[data-testid="size-selector-button"]',
            'stockx_sync_selector_label'  => '[data-testid="selector-label"]',
            'stockx_sync_selector_price'  => '[data-testid="selector-secondary-label"]',
            'stockx_sync_url_template'    => 'https://stockx.com/%1$s?catchallFilters=%1$s&size=%2$s',
            'stockx_sync_email_notify'    => get_option( 'admin_email' ),
            'stockx_sync_cron_interval'   => 'daily',
            'stockx_sync_selenium_hub'    => 'http://localhost:4444/wd/hub',
            'stockx_sync_browser_binary'  => '/usr/bin/google-chrome-stable',
            'stockx_exchange_rate'        => 42,
            'stockx_markup_percent'       => 30,
        ];
        foreach ( $defaults as $key => $value ) {
            if ( false === get_option( $key ) ) {
                add_option( $key, $value );
            }
        }
    }

    public static function register() {
        add_action( 'admin_menu', [ __CLASS__, 'add_page' ] );
        add_action( 'admin_init', [ __CLASS__, 'register_fields' ] );
        add_filter( 'cron_schedules', [ __CLASS__, 'add_schedules' ] );
    }

    public static function add_page() {
        add_submenu_page(
            'woocommerce',
            __( 'StockX Sync Settings', 'stockx-sync' ),
            __( 'StockX Sync', 'stockx-sync' ),
            'manage_woocommerce',
            self::PAGE,
            [ __CLASS__, 'render_page' ]
        );
    }

    public static function register_fields() {
        $fields = [
            'selector_button'   => 'Size Selector Button CSS Selector',
            'selector_label'    => 'Size Label CSS Selector',
            'selector_price'    => 'Price CSS Selector',
            'url_template'      => 'StockX URL Template',
            'email_notify'      => 'Notification Email',
            'cron_interval'     => 'Sync Interval',
            'selenium_hub'      => 'Selenium Hub URL',
            'browser_binary'    => 'Browser Binary Path',
        ];

        add_settings_section( 'main', '', '', self::PAGE );
        foreach ( $fields as $key => $label ) {
            register_setting( self::GROUP, 'stockx_sync_' . $key );
            add_settings_field(
                'stockx_sync_' . $key,
                $label,
                [ __CLASS__, 'render_field' ],
                self::PAGE,
                'main',
                [ 'field' => $key ]
            );
        }

        // Додаткові поля для курсу та націнки
        register_setting( self::GROUP, 'stockx_exchange_rate' );
        register_setting( self::GROUP, 'stockx_markup_percent' );

        add_settings_field(
            'stockx_exchange_rate',
            'Курс USD → UAH',
            function() {
                printf(
                    '<input name="stockx_exchange_rate" type="number" step="0.01" value="%s" class="regular-text"/>',
                    esc_attr(get_option('stockx_exchange_rate', 42))
                );
            },
            self::PAGE,
            'main'
        );

        add_settings_field(
            'stockx_markup_percent',
            'Націнка (%)',
            function() {
                printf(
                    '<input name="stockx_markup_percent" type="number" step="0.01" value="%s" class="regular-text"/>',
                    esc_attr(get_option('stockx_markup_percent', 30))
                );
            },
            self::PAGE,
            'main'
        );
    }

    public static function render_page() {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'StockX Sync Settings', 'stockx-sync' ); ?></h1>
            <form method="post" action="options.php">
                <?php settings_fields( self::GROUP ); ?>
                <?php do_settings_sections( self::PAGE ); ?>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    public static function render_field( $args ) {
        $name  = 'stockx_sync_' . $args['field'];
        $value = esc_attr( get_option( $name ) );
        if ( 'cron_interval' === $args['field'] ) {
            foreach ( [ 'hourly', 'twicedaily', 'daily' ] as $opt ) {
                printf(
                    '<label><input type="radio" name="%1$s" value="%2$s" %3$s/> %2$s</label><br>',
                    esc_attr( $name ),
                    esc_attr( $opt ),
                    checked( $value, $opt, false )
                );
            }
        } else {
            printf(
                '<input name="%1$s" value="%2$s" class="regular-text"/>',
                esc_attr( $name ),
                $value
            );
        }
    }

    public static function add_schedules( $schedules ) {
        $schedules['twicedaily'] = [
            'interval' => 12 * HOUR_IN_SECONDS,
            'display'  => __( 'Twice Daily', 'stockx-sync' ),
        ];
        return $schedules;
    }
}
