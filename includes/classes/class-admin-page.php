<?php
namespace StockXSync;

class AdminPage {
    public static function register() {
        add_action( 'admin_menu', [ __CLASS__, 'add_menu' ], 99 );
        add_action( 'wp_ajax_stockx_sync_run', [ 'StockXSync\SyncManager', 'ajax_run' ] );
    }

    public static function add_menu() {
        add_submenu_page(
            'woocommerce',
            __( 'Run StockX Sync', 'stockx-sync' ),
            __( 'Run StockX Sync', 'stockx-sync' ),
            'manage_woocommerce',
            'stockx-sync-run',
            [ __CLASS__, 'render_run_page' ]
        );
    }

    public static function render_run_page() {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'StockX Sync', 'stockx-sync' ); ?></h1>
            <button id="sync-btn" class="button button-primary"><?php esc_html_e( 'Run Now', 'stockx-sync' ); ?></button>
            <div id="sync-status" style="margin-top:1em;"></div>
        </div>
        <script>
        (function($){
            $('#sync-btn').on('click', function(){ var btn=$(this), status=$('#sync-status'); btn.prop('disabled',true).text('Running…'); status.text('In progress…'); $.post(ajaxurl,{action:'stockx_sync_run'},function(res){ status.text(res.success? res.data.count+' updated.':'Error'); btn.prop('disabled',false).text('Run Now'); }); });
        })(jQuery);
        </script>
        <?php
    }
}
