<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * LCNI_MarketDashboardShortcode
 *
 * Shortcode: [lcni_market_dashboard]
 *
 * Attributes:
 *   timeframe="1D"         — '1D' | '1W' | '1M'
 *   title="..."            — Tiêu đề widget (ẩn nếu để trống)
 *   show_sectors="yes"     — Hiện top ngành dẫn dắt
 *   show_flow="yes"        — Hiện dòng tiền
 *   show_rotation="yes"    — Hiện rotation
 *   compact="no"           — 'yes' = layout compact 1 cột
 */
class LCNI_MarketDashboardShortcode {

    const VERSION = '1.0.0';

    public function __construct() {
        add_action( 'init',               [ $this, 'register_shortcode' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'register_assets' ] );
        add_action( 'rest_api_init',      [ $this, 'register_rest_routes' ] );
        add_action( 'init',               [ $this, 'maybe_ensure_schema' ], 1 );
    }

    public function maybe_ensure_schema(): void {
        static $done = false;
        if ( $done ) return;
        $done = true;
        LCNI_MarketDashboardRepository::ensure_context_table();
    }

    public function register_shortcode(): void {
        add_shortcode( 'lcni_market_dashboard', [ $this, 'render' ] );
        // Widget shortcodes — dùng độc lập hoặc kéo thả trong Gutenberg
        add_shortcode( 'lcni_market_health',   [ $this, 'render_widget_health' ] );
        add_shortcode( 'lcni_market_rotation', [ $this, 'render_widget_rotation' ] );
        add_shortcode( 'lcni_market_leaders',  [ $this, 'render_widget_leaders' ] );
        add_shortcode( 'lcni_market_flow',     [ $this, 'render_widget_flow' ] );
    }

    public function register_assets(): void {
        // Dùng LCNI_PATH / LCNI_URL nếu đã defined (plugin root constants)
        // Fallback: đi 3 cấp lên từ includes/MarketDashboard/ → plugin root
        if ( defined( 'LCNI_PATH' ) && defined( 'LCNI_URL' ) ) {
            $base_path = LCNI_PATH;
            $base_url  = LCNI_URL;
        } else {
            $base_path = trailingslashit( dirname( dirname( dirname( __FILE__ ) ) ) );
            $base_url  = trailingslashit( plugin_dir_url( dirname( dirname( dirname( __FILE__ ) ) ) ) );
        }

        $js_file  = $base_path . 'assets/js/lcni-market-dashboard.js';
        $css_file = $base_path . 'assets/css/lcni-market-dashboard.css';

        $js_ver  = file_exists( $js_file )  ? (string) filemtime( $js_file )  : self::VERSION;
        $css_ver = file_exists( $css_file ) ? (string) filemtime( $css_file ) : self::VERSION;

        wp_register_script(
            'lcni-market-dashboard',
            $base_url . 'assets/js/lcni-market-dashboard.js',
            [],
            $js_ver,
            true
        );

        wp_register_style(
            'lcni-market-dashboard',
            $base_url . 'assets/css/lcni-market-dashboard.css',
            [],
            $css_ver
        );
    }

    public function register_rest_routes(): void {
        $ctrl = new LCNI_MarketDashboardRestController();
        $ctrl->register_routes();

        register_rest_route( 'lcni/v1', '/market-dashboard/backfill', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'rest_backfill' ],
            'permission_callback' => function() {
                return current_user_can( 'manage_options' );
            },
            'args' => [
                'timeframe' => [
                    'default'           => '1D',
                    'sanitize_callback' => function( $v ) {
                        return strtoupper( sanitize_text_field( (string) $v ) );
                    },
                    'validate_callback' => function( $v ) {
                        return in_array( strtoupper( $v ), [ '1D', '1W', '1M' ], true );
                    },
                ],
                'limit' => [
                    'default'           => 200,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ] );
    }

    public function rest_backfill( WP_REST_Request $req ): WP_REST_Response {
        $repo  = new LCNI_MarketDashboardRepository();
        $tf    = $req->get_param( 'timeframe' );
        $limit = max( 1, min( 500, (int) $req->get_param( 'limit' ) ) );
        $saved = $repo->backfill_history( $tf, $limit );
        return new WP_REST_Response( [
            'success' => true,
            'message' => "Backfill xong: {$saved} snapshot cho timeframe {$tf}.",
            'saved'   => $saved,
        ], 200 );
    }

    public function render( $atts = [] ): string {
        $atts = shortcode_atts( [
            'timeframe'      => '1D',
            'title'          => 'Market Dashboard',
            'show_sectors'   => 'yes',
            'show_flow'      => 'yes',
            'show_rotation'  => 'yes',
            'compact'        => 'no',
        ], $atts, 'lcni_market_dashboard' );

        $tf = strtoupper( sanitize_text_field( $atts['timeframe'] ) );
        if ( ! in_array( $tf, ['1D', '1W', '1M'], true ) ) {
            $tf = '1D';
        }

        wp_enqueue_script( 'lcni-market-dashboard' );
        wp_enqueue_style( 'lcni-market-dashboard' );

        // wp_localize_script gọi sau enqueue vẫn được, WordPress merge payload
        wp_localize_script( 'lcni-market-dashboard', 'lcniMktDashCfg', [
            'apiBase'      => esc_url( rest_url( 'lcni/v1/market-dashboard' ) ),
            'nonce'        => wp_create_nonce( 'wp_rest' ),
            'defaultTf'    => $tf,
            'showSectors'  => $atts['show_sectors'] !== 'no',
            'showFlow'     => $atts['show_flow']    !== 'no',
            'showRotation' => $atts['show_rotation'] !== 'no',
            'compact'      => $atts['compact'] === 'yes',
            'i18n'         => [
                'loading' => 'Đang tải dữ liệu thị trường...',
                'noData'  => 'Chưa có dữ liệu. Vui lòng tính toán lại.',
                'error'   => 'Lỗi kết nối. Thử lại sau.',
                'refresh' => 'Làm mới',
                'updated' => 'Cập nhật lúc',
            ],
        ] );

        $title_html = $atts['title'] !== ''
            ? '<h3 class="lcni-mkt-title">' . esc_html( $atts['title'] ) . '</h3>'
            : '';

        return sprintf(
            '<div class="lcni-market-dashboard%s" data-timeframe="%s" data-show-sectors="%s" data-show-flow="%s" data-show-rotation="%s">%s<div class="lcni-mkt-body"></div></div>',
            $atts['compact'] === 'yes' ? ' lcni-market-dashboard--compact' : '',
            esc_attr( $tf ),
            esc_attr( $atts['show_sectors'] !== 'no' ? '1' : '0' ),
            esc_attr( $atts['show_flow']    !== 'no' ? '1' : '0' ),
            esc_attr( $atts['show_rotation'] !== 'no' ? '1' : '0' ),
            $title_html
        );
    }

    // ── Widget shortcodes ─────────────────────────────────────────────────

    /**
     * Helper chung: enqueue assets, parse atts cơ bản, output widget div.
     *
     * @param array  $atts       Shortcode attributes đã được shortcode_atts xử lý
     * @param string $widget_key 'health' | 'rotation' | 'leaders' | 'flow'
     */
    private function render_widget( array $atts, string $widget_key ): string {
        $tf = strtoupper( sanitize_text_field( $atts['timeframe'] ?? '1D' ) );
        if ( ! in_array( $tf, [ '1D', '1W', '1M' ], true ) ) {
            $tf = '1D';
        }

        wp_enqueue_script( 'lcni-market-dashboard' );
        wp_enqueue_style( 'lcni-market-dashboard' );

        wp_localize_script( 'lcni-market-dashboard', 'lcniMktDashCfg', [
            'apiBase'   => esc_url( rest_url( 'lcni/v1/market-dashboard' ) ),
            'nonce'     => wp_create_nonce( 'wp_rest' ),
            'defaultTf' => $tf,
            'i18n'      => [
                'loading' => 'Đang tải dữ liệu...',
                'error'   => 'Lỗi kết nối. Thử lại sau.',
            ],
        ] );

        $title_html = ! empty( $atts['title'] )
            ? '<h3 class="lcni-mkt-title">' . esc_html( $atts['title'] ) . '</h3>'
            : '';

        return sprintf(
            '<div class="lcni-market-dashboard" data-widget="%s" data-timeframe="%s">%s<div class="lcni-mkt-body"></div></div>',
            esc_attr( $widget_key ),
            esc_attr( $tf ),
            $title_html
        );
    }

    /**
     * [lcni_market_health timeframe="1D" title=""]
     * Sức khỏe thị trường: composite score, 4 gauges, breadth bars.
     */
    public function render_widget_health( $atts = [] ): string {
        $atts = shortcode_atts( [ 'timeframe' => '1D', 'title' => '' ], $atts, 'lcni_market_health' );
        return $this->render_widget( $atts, 'health' );
    }

    /**
     * [lcni_market_rotation timeframe="1D" title=""]
     * Phân phối pha ngành: dẫn dắt / cải thiện / suy yếu / tụt hậu.
     */
    public function render_widget_rotation( $atts = [] ): string {
        $atts = shortcode_atts( [ 'timeframe' => '1D', 'title' => '' ], $atts, 'lcni_market_rotation' );
        return $this->render_widget( $atts, 'rotation' );
    }

    /**
     * [lcni_market_leaders timeframe="1D" title=""]
     * Bảng top ngành dẫn dắt với score, pha, return 5D.
     */
    public function render_widget_leaders( $atts = [] ): string {
        $atts = shortcode_atts( [ 'timeframe' => '1D', 'title' => '' ], $atts, 'lcni_market_leaders' );
        return $this->render_widget( $atts, 'leaders' );
    }

    /**
     * [lcni_market_flow timeframe="1D" title=""]
     * Top 5 ngành theo dòng tiền + tổng GTGD.
     */
    public function render_widget_flow( $atts = [] ): string {
        $atts = shortcode_atts( [ 'timeframe' => '1D', 'title' => '' ], $atts, 'lcni_market_flow' );
        return $this->render_widget( $atts, 'flow' );
    }
}
