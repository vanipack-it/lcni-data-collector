<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_Overview_Shortcode {

    const VERSION = '2.0.7';

    const DEFAULT_FIELDS = [
        'symbol',
        'exchange',
        'icb2_name',
        'eps',
        'eps_1y_pct',
        'dt_1y_pct',
        'bien_ln_gop',
        'bien_ln_rong',
        'roe',
        'de_ratio',
        'pe_ratio',
        'pb_ratio',
        'ev_ebitda',
        'tcbs_khuyen_nghi',
        'co_tuc_pct',
        'tc_rating',
        'so_huu_nn_pct',
        'tien_mat_rong_von_hoa',
        'tien_mat_rong_tong_tai_san',
        'loi_nhuan_4_quy_gan_nhat',
        'tang_truong_dt_quy_gan_nhat',
        'tang_truong_dt_quy_gan_nhi',
        'tang_truong_ln_quy_gan_nhat',
        'tang_truong_ln_quy_gan_nhi',
    ];

    private $ajax;

    public function __construct() {
        $this->ajax = new LCNI_Overview_Ajax(self::DEFAULT_FIELDS);

        add_action('init', [$this, 'register_shortcodes']);
        add_action('wp_enqueue_scripts', [$this, 'register_assets']);
        add_action('rest_api_init', [$this->ajax, 'register_routes']);
    }

    public function register_shortcodes() {
        add_shortcode('lcni_stock_overview', [$this, 'render']);
        add_shortcode('lcni_stock_overview_query', [$this, 'render']);
    }

    public function register_assets() {
        $sync_script_path = LCNI_PATH . 'assets/js/lcni-stock-sync.js';
        $sync_version = file_exists($sync_script_path) ? (string) filemtime($sync_script_path) : self::VERSION;

        wp_register_script('lcni-stock-sync', LCNI_URL . 'assets/js/lcni-stock-sync.js', [], $sync_version, true);

        $script_path = LCNI_PATH . 'modules/overview/assets/overview.js';
        $version = file_exists($script_path) ? (string) filemtime($script_path) : self::VERSION;

        wp_register_script('lcni-stock-overview', LCNI_URL . 'modules/overview/assets/overview.js', ['lcni-stock-sync'], $version, true);
        wp_register_style('lcni-stock-overview', LCNI_URL . 'modules/overview/assets/overview.css', [], $version);
    }

    public function render($atts = []) {
        $atts = shortcode_atts([
            'symbol' => '',
        ], $atts, 'lcni_stock_overview');

        $symbol = lcni_get_current_symbol($atts['symbol']);
        if ($symbol === '') {
            return '';
        }

        wp_enqueue_script('lcni-stock-overview');
        wp_enqueue_style('lcni-stock-overview');

        return '<div data-lcni-overview></div>';
    }
}

if (!class_exists('LCNI_Stock_Overview_Shortcodes')) {
    class LCNI_Stock_Overview_Shortcodes extends LCNI_Overview_Shortcode {
    }
}
