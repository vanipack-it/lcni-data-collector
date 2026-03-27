<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_Heatmap_Module {

    private $ajax;

    public function __construct() {
        $watchlist_repository = new LCNI_WatchlistRepository();
        $watchlist_service    = new LCNI_WatchlistService($watchlist_repository);
        $cache_service        = new CacheService('lcni_heatmap');
        $snapshot_repository  = new SnapshotRepository($cache_service);

        $this->ajax = new LCNI_Heatmap_Ajax($snapshot_repository, $watchlist_service);

        new LCNI_Heatmap_Shortcode($watchlist_service);
        new LCNI_Heatmap_Admin($watchlist_service);

        add_action('rest_api_init', [$this->ajax, 'register_routes']);
    }
}
