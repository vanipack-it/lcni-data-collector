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

        $repo = new LCNI_SaaS_Repository();
        $service = new LCNI_SaaS_Service($repo);

        new LCNI_Member_Settings_Page($service);
        new LCNI_Member_Auth_Shortcodes($service);
        new LCNI_Member_Permission_Middleware($service);
    }
}
