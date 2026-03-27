<?php
/**
 * Custom Index Module — Entry Point
 *
 * KHÔNG cần nhúng file này trực tiếp.
 * Dùng loader thay thế — thêm 1 dòng vào lcni-data-collector.php:
 *
 *   require_once LCNI_PATH . 'includes/CustomIndex/lcni-custom-index-loader.php';
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class LCNI_Custom_Index_Module {

    private LCNI_Custom_Index_Repository    $repo;
    private LCNI_Custom_Index_Calculator    $calc;
    private LCNI_Custom_Index_Cron          $cron;

    public function __construct() {
        global $wpdb;

        // Đảm bảo bảng tồn tại (an toàn để gọi mọi lúc)
        LCNI_Custom_Index_DB::ensure();

        $this->repo = new LCNI_Custom_Index_Repository( $wpdb );
        $this->calc = new LCNI_Custom_Index_Calculator( $wpdb );
        $this->cron = new LCNI_Custom_Index_Cron( $this->repo, $this->calc );

        // Cron
        $this->cron->register_hooks();
        LCNI_Custom_Index_Cron::schedule();

        // REST API
        add_action( 'rest_api_init', function () {
            ( new LCNI_Custom_Index_Rest_Controller( $this->repo, $this->calc ) )->register_routes();
        } );

        // Admin — không dùng is_admin() guard vì constructor tự hook vào admin_menu action
        // (giống pattern của LCNINotificationAdminPage trong plugin này)
        new LCNI_Custom_Index_Admin( $this->repo, $this->calc );

        // Frontend shortcode
        new LCNI_Custom_Index_Shortcode( $this->repo, $this->calc );
    }
}
