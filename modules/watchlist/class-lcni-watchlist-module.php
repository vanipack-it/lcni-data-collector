<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_Watchlist_Module {

    private $controller;

    public function __construct() {
        $repository = new LCNI_WatchlistRepository();
        $service = new LCNI_WatchlistService($repository);
        $this->controller = new LCNI_WatchlistController($service);

        new LCNI_WatchlistShortcode($service);

        add_action('rest_api_init', [$this->controller, 'register_routes']);
    }
}
