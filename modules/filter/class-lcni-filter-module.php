<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_Filter_Module {
    private $ajax;

    public function __construct() {
        $repository = new LCNI_WatchlistRepository();
        $watchlist_service = new LCNI_WatchlistService($repository);
        $table = new LCNI_FilterTable($repository, $watchlist_service);
        $this->ajax = new LCNI_FilterAjax($table);

        new LCNI_FilterShortcode($table);

        add_action('rest_api_init', [$this->ajax, 'register_routes']);
    }
}
