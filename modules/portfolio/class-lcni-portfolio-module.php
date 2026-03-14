<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_Portfolio_Module {

    private $service;

    public function __construct() {
        LCNI_Portfolio_Repository::maybe_create_tables();

        $repo          = new LCNI_Portfolio_Repository();
        $this->service = new LCNI_Portfolio_Service($repo);
        $controller    = new LCNI_Portfolio_Controller($this->service);

        new LCNI_Portfolio_Shortcode($this->service);
        new LCNI_Portfolio_Admin_Page($this->service);

        add_action('rest_api_init', [$controller, 'register_routes']);
    }

    public static function activate() {
        LCNI_Portfolio_Repository::create_tables();
    }
}
