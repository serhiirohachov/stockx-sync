<?php
namespace StockXSync;

class Plugin {
    public static function activate() {
        Settings::init_defaults();
        Scheduler::schedule_event();
    }
    public static function deactivate() {
        Scheduler::clear_event();
    }
    public static function init() {
        Settings::register();
        Scheduler::register();
        AdminPage::register();
        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            CLI::init();
        }
    }
}
