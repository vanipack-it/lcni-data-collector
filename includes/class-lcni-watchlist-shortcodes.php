<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_Watchlist_Shortcodes {
    public function __construct() {
        add_action('init', [$this, 'register_shortcodes']);
        add_action('wp_enqueue_scripts', [$this, 'register_assets']);
    }

    public function register_shortcodes() {
        add_shortcode('lcni_watchlist', [$this, 'render_watchlist']);
        add_shortcode('lcni_watchlist_add', [$this, 'render_add_button']);
    }

    public function register_assets() {
        $script_path = LCNI_PATH . 'assets/js/lcni-watchlist.js';
        $version = file_exists($script_path) ? (string) filemtime($script_path) : '1.8.0';
        wp_register_script('lcni-watchlist', LCNI_URL . 'assets/js/lcni-watchlist.js', [], $version, true);
    }

    public function render_watchlist($atts) {
        $atts = shortcode_atts([
            'login_label' => 'Đăng nhập',
            'register_label' => 'Tạo tài khoản',
            'empty_message' => 'Bạn chưa có mã nào trong watchlist.',
        ], $atts, 'lcni_watchlist');

        if (!is_user_logged_in()) {
            $login_url = wp_login_url((string) home_url(add_query_arg([])));
            $register_url = wp_registration_url();

            return sprintf(
                '<div class="lcni-watchlist-guest">Vui lòng <a href="%1$s">%2$s</a> hoặc <a href="%3$s">%4$s</a> để dùng Watchlist.</div>',
                esc_url($login_url),
                esc_html($atts['login_label']),
                esc_url($register_url),
                esc_html($atts['register_label'])
            );
        }

        wp_enqueue_script('lcni-watchlist');

        return sprintf(
            '<div data-lcni-watchlist data-watchlist-api="%1$s" data-stock-api="%2$s" data-empty-message="%3$s"></div>',
            esc_url(rest_url('lcni/v1/watchlist')),
            esc_url(rest_url('lcni/v1/stock')),
            esc_attr($atts['empty_message'])
        );
    }

    public function render_add_button($atts) {
        $atts = shortcode_atts([
            'symbol' => '',
            'label' => 'Thêm vào Watchlist',
            'icon' => '<i class="fa-solid fa-heart-circle-plus" aria-hidden="true"></i>',
            'class' => '',
        ], $atts, 'lcni_watchlist_add');

        $symbol = strtoupper(sanitize_text_field((string) $atts['symbol']));
        if ($symbol === '') {
            return '';
        }

        if (!is_user_logged_in()) {
            $login_url = wp_login_url((string) home_url(add_query_arg([])));
            return sprintf(
                '<a class="lcni-watchlist-login-link" href="%1$s" title="%2$s">%3$s %4$s</a>',
                esc_url($login_url),
                esc_attr__('Vui lòng đăng nhập để thêm vào watchlist', 'lcni-data-collector'),
                wp_kses_post((string) $atts['icon']),
                esc_html((string) $atts['label'])
            );
        }

        wp_enqueue_script('lcni-watchlist');

        return sprintf(
            '<button type="button" class="lcni-watchlist-add-btn %1$s" data-lcni-watchlist-add="1" data-symbol="%2$s" data-watchlist-api="%3$s">%4$s %5$s</button>',
            esc_attr((string) $atts['class']),
            esc_attr($symbol),
            esc_url(rest_url('lcni/v1/watchlist')),
            wp_kses_post((string) $atts['icon']),
            esc_html((string) $atts['label'])
        );
    }
}
