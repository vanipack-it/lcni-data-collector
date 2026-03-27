<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_Member_Module {

    public static function activate() {
        LCNI_SaaS_Repository::create_tables();
        LCNI_Upgrade_Request_Repository::maybe_create_table();
    }

    public function __construct() {
        LCNI_SaaS_Repository::maybe_create_tables();
        LCNI_Upgrade_Request_Repository::maybe_create_table();

        $repo    = new LCNI_SaaS_Repository();
        $service = new LCNI_SaaS_Service( $repo );

        // Expose global để các module khác có thể kiểm tra quyền SaaS
        global $lcni_saas_service;
        $lcni_saas_service = $service;

        new LCNI_Member_Settings_Page( $service );
        new LCNI_Member_Auth_Shortcodes( $service );
        new LCNI_Member_Profile_Shortcode( $service );
        new LCNI_Member_Package_Shortcode( $service );
        new LCNI_Member_Permission_Middleware( $service );
        new LCNI_Member_Admin_User_Fields( $service ); // field gói SaaS trong admin user
        new LCNI_Member_Pricing_Shortcode( $service ); // bảng so sánh gói frontend
        new LCNI_Google_OAuth_Handler(); // đăng nhập bằng Google One Tap
        new LCNI_Dnse_Login_Handler();   // đăng nhập bằng tài khoản DNSE
        new LCNI_Member_Avatar_Helper(); // avatar thông minh: Google / initials / viền màu SaaS

        // ── Upgrade Request (nâng cấp gói SaaS) ────────────────────────────
        $ur_repo    = new LCNI_Upgrade_Request_Repository();
        $ur_service = new LCNI_Upgrade_Request_Service( $ur_repo, $service );
        new LCNI_Upgrade_Request_Shortcode( $ur_service, $ur_repo, $service );
        global $lcni_upgrade_request_admin_page;
        $lcni_upgrade_request_admin_page = new LCNI_Upgrade_Request_Admin_Page( $ur_service, $ur_repo );
    }
}
