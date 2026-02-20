<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_Filter_Module {
    private $controller;

    public function __construct() {
        $repository = new LCNI_WatchlistRepository();
        $watchlist_service = new LCNI_WatchlistService($repository);
        $table = new LCNI_FilterTable($repository, $watchlist_service);
        $this->controller = new LCNI_FilterController($table);

        new LCNI_FilterShortcode($table);

        add_action('rest_api_init', [$this->controller, 'register_routes']);
    }
}
