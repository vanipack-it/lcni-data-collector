<?php
if ( ! defined( 'ABSPATH' ) ) exit;

$_lcni_ci_dir = plugin_dir_path( __FILE__ );
require_once $_lcni_ci_dir . 'class-lcni-custom-index-db.php';
require_once $_lcni_ci_dir . 'class-lcni-custom-index-calculator.php';
require_once $_lcni_ci_dir . 'class-lcni-custom-index-repository.php';
require_once $_lcni_ci_dir . 'class-lcni-custom-index-cron.php';
require_once $_lcni_ci_dir . 'class-lcni-custom-index-rest-controller.php';
require_once $_lcni_ci_dir . 'class-lcni-custom-index-admin.php';
require_once $_lcni_ci_dir . 'class-lcni-custom-index-shortcode.php';
unset( $_lcni_ci_dir );

add_action( 'plugins_loaded', 'lcni_ci_ensure_db', 20 );
function lcni_ci_ensure_db() {
    LCNI_Custom_Index_DB::ensure();
}

add_action( 'admin_menu', 'lcni_ci_register_menu', 20 );
function lcni_ci_register_menu() {
    global $wpdb;
    $admin = new LCNI_Custom_Index_Admin(
        new LCNI_Custom_Index_Repository( $wpdb ),
        new LCNI_Custom_Index_Calculator( $wpdb )
    );
    $admin->register_menu();
}

add_action( 'admin_init', 'lcni_ci_handle_post' );
function lcni_ci_handle_post() {
    if ( empty( $_POST['lcni_ci_action'] ) ) return;
    global $wpdb;
    $admin = new LCNI_Custom_Index_Admin(
        new LCNI_Custom_Index_Repository( $wpdb ),
        new LCNI_Custom_Index_Calculator( $wpdb )
    );
    $admin->handle_post();
}

add_action( 'rest_api_init', 'lcni_ci_register_rest' );
function lcni_ci_register_rest() {
    global $wpdb;
    $ctrl = new LCNI_Custom_Index_Rest_Controller(
        new LCNI_Custom_Index_Repository( $wpdb ),
        new LCNI_Custom_Index_Calculator( $wpdb )
    );
    $ctrl->register_routes();
}

add_action( 'init', 'lcni_ci_register_shortcode', 10 );
function lcni_ci_register_shortcode() {
    global $wpdb;
    $sc = new LCNI_Custom_Index_Shortcode(
        new LCNI_Custom_Index_Repository( $wpdb ),
        new LCNI_Custom_Index_Calculator( $wpdb )
    );
    $sc->register();
}

add_action( 'wp_enqueue_scripts', 'lcni_ci_register_assets' );
function lcni_ci_register_assets() {
    global $wpdb;
    $sc = new LCNI_Custom_Index_Shortcode(
        new LCNI_Custom_Index_Repository( $wpdb ),
        new LCNI_Custom_Index_Calculator( $wpdb )
    );
    $sc->register_assets();
}

add_action( 'init', 'lcni_ci_schedule_cron', 20 );
function lcni_ci_schedule_cron() {
    if ( ! wp_next_scheduled( LCNI_Custom_Index_Cron::CRON_HOOK ) ) {
        wp_schedule_event( time() + 300, 'daily', LCNI_Custom_Index_Cron::CRON_HOOK );
    }
}

add_action( LCNI_Custom_Index_Cron::CRON_HOOK, 'lcni_ci_run_cron' );
function lcni_ci_run_cron() {
    global $wpdb;
    $cron = new LCNI_Custom_Index_Cron(
        new LCNI_Custom_Index_Repository( $wpdb ),
        new LCNI_Custom_Index_Calculator( $wpdb )
    );
    $cron->run();
}
