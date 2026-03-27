<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_Filter_Module {
    private $ajax;

    public function __construct() {
        $watchlist_repository = new LCNI_WatchlistRepository();
        $watchlist_service = new LCNI_WatchlistService($watchlist_repository);
        $cache_service = new CacheService('lcni_filter');
        $snapshot_repository = new SnapshotRepository($cache_service);
        $filter_service = new FilterService($snapshot_repository, $watchlist_service, $cache_service);

        $table = new LCNI_FilterTable($snapshot_repository, $watchlist_service);
        $this->ajax = new LCNI_FilterAjax($table, $filter_service);

        new LCNI_FilterShortcode($table);

        add_action('rest_api_init', [$this->ajax, 'register_routes']);
    }
}
