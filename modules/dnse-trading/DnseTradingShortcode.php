<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * DnseTradingShortcode
 *
 * Shortcode: [lcni_dnse_trading]
 *
 * Attributes:
 *   tab="portfolio"   — tab mặc định: portfolio | orders | connect
 *   compact="no"      — layout thu gọn
 */
class LCNI_DnseTradingShortcode {

    const VERSION = '1.0.0';

    /** @var LCNI_DnseTradingService */
    private $service;

    /** @var LCNI_DnseOrderService|null — Phase 2 */
    private $order_service;

    public function __construct( LCNI_DnseTradingService $service, $order_service = null ) {
        $this->service       = $service;
        $this->order_service = $order_service;
        add_shortcode( 'lcni_dnse_trading', [ $this, 'render' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'register_assets' ] );
        // REST routes đăng ký từ module
    }

    public function register_assets(): void {
        $base_path = defined( 'LCNI_PATH' ) ? LCNI_PATH : trailingslashit( dirname( dirname( dirname( __FILE__ ) ) ) );
        $base_url  = defined( 'LCNI_URL' )  ? LCNI_URL  : trailingslashit( plugin_dir_url( dirname( dirname( dirname( __FILE__ ) ) ) ) );

        $js_file  = $base_path . 'modules/dnse-trading/assets/js/lcni-dnse-trading.js';
        $css_file = $base_path . 'modules/dnse-trading/assets/css/lcni-dnse-trading.css';

        wp_register_script(
            'lcni-dnse-trading',
            $base_url . 'modules/dnse-trading/assets/js/lcni-dnse-trading.js',
            [],
            file_exists( $js_file ) ? (string) filemtime( $js_file ) : self::VERSION,
            true
        );

        wp_register_style(
            'lcni-dnse-trading',
            $base_url . 'modules/dnse-trading/assets/css/lcni-dnse-trading.css',
            ['lcni-ui-table'],
            file_exists( $css_file ) ? (string) filemtime( $css_file ) : self::VERSION
        );

        // Phase 2: Order assets
        $order_js  = $base_path . 'modules/dnse-trading/assets/js/lcni-dnse-order.js';
        $order_css = $base_path . 'modules/dnse-trading/assets/css/lcni-dnse-order.css';

        wp_register_script( 'lcni-dnse-order',
            $base_url . 'modules/dnse-trading/assets/js/lcni-dnse-order.js',
            [ 'lcni-dnse-trading' ],
            file_exists( $order_js ) ? (string) filemtime( $order_js ) : self::VERSION, true
        );
        wp_register_style( 'lcni-dnse-order',
            $base_url . 'modules/dnse-trading/assets/css/lcni-dnse-order.css',
            [ 'lcni-dnse-trading' ],
            file_exists( $order_css ) ? (string) filemtime( $order_css ) : self::VERSION
        );
    }

    public function render( $atts = [] ): string {
        if ( ! is_user_logged_in() ) {
            return '<p class="lcni-dnse-msg">Vui lòng đăng nhập để sử dụng tính năng này.</p>';
        }

        $atts = shortcode_atts( [
            'tab'     => 'portfolio',
            'compact' => 'no',
        ], $atts, 'lcni_dnse_trading' );

        $default_tab = in_array( $atts['tab'], [ 'portfolio', 'orders', 'connect' ], true )
            ? $atts['tab'] : 'portfolio';

        wp_enqueue_script( 'lcni-dnse-trading' );
        wp_enqueue_style( 'lcni-dnse-trading' );
        // Phase 2
        if ( class_exists( 'LCNI_DnseOrderService' ) ) {
            wp_enqueue_script( 'lcni-dnse-order' );
            wp_enqueue_style( 'lcni-dnse-order' );
        }

        wp_localize_script( 'lcni-dnse-trading', 'lcniDnseCfg', [
            'apiBase'    => esc_url( rest_url( 'lcni/v1/dnse' ) ),
            'nonce'      => wp_create_nonce( 'wp_rest' ),
            'defaultTab' => $default_tab,
            'compact'    => $atts['compact'] === 'yes',
            'i18n'       => [
                'loading'         => 'Đang tải...',
                'connect'         => 'Kết nối DNSE',
                'disconnect'      => 'Ngắt kết nối',
                'sync'            => 'Đồng bộ',
                'verify_otp'      => 'Xác thực OTP',
                'request_otp'     => 'Gửi Email OTP',
                'tab_portfolio'   => 'Danh mục',
                'tab_orders'      => 'Sổ lệnh',
                'tab_connect'     => 'Kết nối',
                'not_connected'   => 'Chưa kết nối DNSE',
                'connected'       => 'Đã kết nối',
                'trading_active'  => 'Trading token hoạt động',
                'trading_expired' => 'Trading token hết hạn — cần xác thực OTP',
                'buy'             => 'Mua',
                'sell'            => 'Bán',
                'pnl_label'       => 'Lãi/Lỗ',
                'sync_success'    => 'Đồng bộ thành công',
                'error_generic'   => 'Có lỗi xảy ra. Vui lòng thử lại.',
            ],
        ] );

        return sprintf(
            '<div class="lcni-dnse-trading%s" data-default-tab="%s"></div>',
            $atts['compact'] === 'yes' ? ' lcni-dnse-trading--compact' : '',
            esc_attr( $default_tab )
        );
    }
}
