<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_Member_Module {

    public static function activate() {
        LCNI_SaaS_Repository::create_tables();
    }

    public function __construct() {
        LCNI_SaaS_Repository::maybe_create_tables();

        $repo    = new LCNI_SaaS_Repository();
        $service = new LCNI_SaaS_Service( $repo );

        new LCNI_Member_Settings_Page( $service );
        new LCNI_Member_Auth_Shortcodes( $service );
        new LCNI_Member_Profile_Shortcode( $service );
        new LCNI_Member_Package_Shortcode( $service );
        new LCNI_Member_Permission_Middleware( $service );
        new LCNI_Member_Admin_User_Fields( $service ); // field gói SaaS trong admin user
        new LCNI_Member_Pricing_Shortcode( $service ); // bảng so sánh gói frontend
        new LCNI_Google_OAuth_Handler(); // đăng nhập bằng Google One Tap
    }
}
