<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * LCNI_MarketChartShortcode
 *
 * Shortcode: [lcni_market_chart]
 *
 * Hiển thị biểu đồ lịch sử biến động các chỉ số thị trường
 * từ bảng wp_lcni_market_context bằng Apache ECharts.
 *
 * Attributes:
 *   timeframe="1D"    — '1D' | '1W' | '1M'
 *   days="60"         — Số phiên lịch sử muốn hiển thị (max 200)
 *   height="520"      — Chiều cao biểu đồ (px)
 *   title=""          — Tiêu đề widget (để trống = ẩn)
 *   theme="dark"      — 'dark' | 'light'
 */
class LCNI_MarketChartShortcode {

    const VERSION  = '1.0.0';
    const SC_TAG   = 'lcni_market_chart';
    const REST_NS  = 'lcni/v1';
    const REST_BASE = 'market-chart';

    public function __construct() {
        add_action( 'init',               [ $this, 'register_shortcode' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'register_assets' ] );
        add_action( 'rest_api_init',      [ $this, 'register_rest_routes' ] );
    }

    public function register_shortcode(): void {
        add_shortcode( self::SC_TAG, [ $this, 'render' ] );
    }

    public function register_assets(): void {
        $base_url = defined( 'LCNI_URL' ) ? LCNI_URL : trailingslashit( plugin_dir_url( dirname( dirname( __FILE__ ) ) ) );
        $base_path = defined( 'LCNI_PATH' ) ? LCNI_PATH : trailingslashit( dirname( dirname( dirname( __FILE__ ) ) ) );

        // ECharts CDN (giống với module chart + recommend)
        wp_register_script(
            'echarts-cdn',
            'https://cdn.jsdelivr.net/npm/echarts@5/dist/echarts.min.js',
            [],
            '5',
            true
        );

        $js_file = $base_path . 'assets/js/lcni-market-chart.js';
        $js_ver  = file_exists( $js_file ) ? (string) filemtime( $js_file ) : self::VERSION;

        wp_register_script(
            'lcni-market-chart',
            $base_url . 'assets/js/lcni-market-chart.js',
            [ 'echarts-cdn' ],
            $js_ver,
            true
        );

        $css_file = $base_path . 'assets/css/lcni-market-chart.css';
        $css_ver  = file_exists( $css_file ) ? (string) filemtime( $css_file ) : self::VERSION;

        wp_register_style(
            'lcni-market-chart',
            $base_url . 'assets/css/lcni-market-chart.css',
            [],
            $css_ver
        );
    }

    // =========================================================
    // REST endpoint: GET /wp-json/lcni/v1/market-chart/history
    // =========================================================

    public function register_rest_routes(): void {
        register_rest_route( self::REST_NS, '/' . self::REST_BASE . '/history', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'rest_get_history' ],
            'permission_callback' => [ $this, 'check_permission' ],
            'args'                => [
                'timeframe' => [
                    'default'           => '1D',
                    'sanitize_callback' => fn( $v ) => strtoupper( sanitize_text_field( (string) $v ) ),
                    'validate_callback' => fn( $v ) => in_array( strtoupper( $v ), [ '1D', '1W', '1M' ], true ),
                ],
                'days' => [
                    'default'           => 60,
                    'sanitize_callback' => fn( $v ) => max( 10, min( 200, absint( $v ) ) ),
                ],
            ],
        ] );
    }

    public function check_permission(): bool {
        if ( function_exists( 'lcni_user_has_permission' ) ) {
            return lcni_user_has_permission( 'market_dashboard', 'can_view' );
        }
        return true;
    }

    public function rest_get_history( WP_REST_Request $req ): WP_REST_Response {
        global $wpdb;

        $tf   = $req->get_param( 'timeframe' );
        $days = (int) $req->get_param( 'days' );
        $tbl  = $wpdb->prefix . 'lcni_market_context';

        // Kiểm tra bảng tồn tại
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl ) ) !== $tbl ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'Bảng market_context chưa tồn tại.', 'data' => [] ], 200 );
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT
                    event_time,
                    market_composite_score,
                    market_phase,
                    market_bias,
                    breadth_ad_ratio,
                    breadth_pct_above_ma50,
                    breadth_ma_trend_score,
                    sentiment_fear_greed,
                    sentiment_pct_smart_money,
                    flow_breadth_score,
                    flow_value_bn,
                    flow_value_change_pct,
                    rotation_score,
                    rotation_pct_uptrend,
                    rotation_leader_count
                 FROM {$tbl}
                 WHERE timeframe = %s
                 ORDER BY event_time DESC
                 LIMIT %d",
                $tf, $days
            ),
            ARRAY_A
        );

        if ( empty( $rows ) ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'Chưa có dữ liệu lịch sử.', 'data' => [] ], 200 );
        }

        // Đảo ngược để biểu đồ hiển thị theo thứ tự tăng dần (cũ → mới)
        $rows = array_reverse( $rows );

        // Format nhẹ: chuyển số
        $data = array_map( function ( $r ) {
            return [
                'event_time'             => (int)   $r['event_time'],
                'date'                   => date( 'd/m/Y', (int) $r['event_time'] ),
                'composite'              => (float) $r['market_composite_score'],
                'phase'                  => (string) $r['market_phase'],
                'bias'                   => (string) $r['market_bias'],
                'ad_ratio'               => (float) $r['breadth_ad_ratio'],
                'pct_ma50'               => (float) $r['breadth_pct_above_ma50'],
                'ma_score'               => (int)   $r['breadth_ma_trend_score'],
                'fear_greed'             => (float) $r['sentiment_fear_greed'],
                'smart_money'            => (float) $r['sentiment_pct_smart_money'],
                'flow_breadth'           => (float) $r['flow_breadth_score'],
                'flow_value'             => (float) $r['flow_value_bn'],
                'flow_change'            => (float) $r['flow_value_change_pct'],
                'rotation'               => (float) $r['rotation_score'],
                'pct_uptrend'            => (float) $r['rotation_pct_uptrend'],
                'leader_count'           => (int)   $r['rotation_leader_count'],
            ];
        }, $rows );

        return new WP_REST_Response( [ 'success' => true, 'data' => $data ], 200 );
    }

    // =========================================================
    // Render shortcode
    // =========================================================

    public function render( $atts = [] ): string {
        $atts = shortcode_atts( [
            'timeframe' => '1D',
            'days'      => '60',
            'height'    => '520',
            'title'     => '',
            'theme'     => 'dark',
        ], $atts, self::SC_TAG );

        $timeframe = in_array( strtoupper( $atts['timeframe'] ), [ '1D', '1W', '1M' ], true )
            ? strtoupper( $atts['timeframe'] ) : '1D';
        $days      = max( 10, min( 200, absint( $atts['days'] ) ) );
        $height    = max( 300, min( 900, absint( $atts['height'] ) ) );
        $theme     = in_array( $atts['theme'], [ 'dark', 'light' ], true ) ? $atts['theme'] : 'dark';
        $uid       = 'lcni-mc-' . wp_rand( 1000, 9999 );

        wp_enqueue_script( 'echarts-cdn' );
        wp_enqueue_script( 'lcni-market-chart' );
        wp_enqueue_style( 'lcni-market-chart' );

        $config = [
            'uid'       => $uid,
            'timeframe' => $timeframe,
            'days'      => $days,
            'height'    => $height,
            'theme'     => $theme,
            'rest_url'  => rest_url( self::REST_NS . '/' . self::REST_BASE . '/history' ),
            'nonce'     => wp_create_nonce( 'wp_rest' ),
        ];

        ob_start();
        ?>
        <div id="<?php echo esc_attr( $uid ); ?>" class="lcni-market-chart-wrap" data-theme="<?php echo esc_attr( $theme ); ?>" data-config="<?php echo esc_attr( wp_json_encode( $config ) ); ?>">
            <?php if ( ! empty( $atts['title'] ) ) : ?>
            <div class="lcni-mc-title"><?php echo esc_html( $atts['title'] ); ?></div>
            <?php endif; ?>

            <div class="lcni-mc-controls">
                <div class="lcni-mc-tabs">
                    <button class="lcni-mc-tab active" data-tf="1D">Ngày</button>
                    <button class="lcni-mc-tab" data-tf="1W">Tuần</button>
                    <button class="lcni-mc-tab" data-tf="1M">Tháng</button>
                </div>
                <div class="lcni-mc-series-btns">
                    <button class="lcni-mc-series-btn active" data-series="composite">Tổng hợp</button>
                    <button class="lcni-mc-series-btn active" data-series="breadth">Breadth</button>
                    <button class="lcni-mc-series-btn active" data-series="sentiment">Tâm lý</button>
                    <button class="lcni-mc-series-btn active" data-series="flow">Dòng tiền</button>
                    <button class="lcni-mc-series-btn active" data-series="rotation">Rotation</button>
                </div>
                <div class="lcni-mc-days-select">
                    <select class="lcni-mc-days">
                        <option value="30" <?php selected( $days, 30 ); ?>>30 phiên</option>
                        <option value="60" <?php selected( $days, 60 ); ?>>60 phiên</option>
                        <option value="90" <?php selected( $days, 90 ); ?>>90 phiên</option>
                        <option value="120" <?php selected( $days, 120 ); ?>>120 phiên</option>
                    </select>
                </div>
            </div>

            <div class="lcni-mc-chart-area" style="height:<?php echo esc_attr( $height ); ?>px;">
                <div class="lcni-mc-loader"><span></span><span></span><span></span></div>
                <div class="lcni-mc-echarts" style="width:100%;height:100%;"></div>
                <div class="lcni-mc-error" style="display:none;"></div>
            </div>

            <div class="lcni-mc-legend">
                <span class="lcni-mc-leg-item" data-series="composite"><i style="background:#f5a623"></i>Composite</span>
                <span class="lcni-mc-leg-item" data-series="breadth"><i style="background:#4fc3f7"></i>Breadth (A/D)</span>
                <span class="lcni-mc-leg-item" data-series="sentiment"><i style="background:#ef5350"></i>Fear&amp;Greed</span>
                <span class="lcni-mc-leg-item" data-series="flow"><i style="background:#66bb6a"></i>Dòng tiền</span>
                <span class="lcni-mc-leg-item" data-series="rotation"><i style="background:#ab47bc"></i>Rotation</span>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}
