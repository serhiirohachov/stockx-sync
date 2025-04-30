<?php
namespace StockXSync;

class Scheduler {
    const HOOK = 'stockx_sync_event';

    public static function register() {
        add_action( self::HOOK, [ 'StockXSync\SyncManager', 'run' ] );
    }

    public static function schedule_event() {
        $interval = get_option( 'stockx_sync_cron_interval', 'daily' );
        if ( ! wp_next_scheduled( self::HOOK ) ) {
            wp_schedule_event( time(), $interval, self::HOOK );
        }
    }

    public static function clear_event() {
        if ( $ts = wp_next_scheduled( self::HOOK ) ) {
            wp_unschedule_event( $ts, self::HOOK );
        }
    }
}
