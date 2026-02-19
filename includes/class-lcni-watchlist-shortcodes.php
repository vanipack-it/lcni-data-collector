<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_Watchlist_Shortcodes {
    public function __construct() {
        add_action('init', [$this, 'register_shortcodes']);
        add_action('wp_enqueue_scripts', [$this, 'register_assets']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_global_watchlist_assets']);
    }

    public function register_shortcodes() {
        add_shortcode('lcni_watchlist', [$this, 'render_watchlist']);
        add_shortcode('lcni_watchlist_add', [$this, 'render_add_button']);
    }

    public function register_assets() {
        $script_path = LCNI_PATH . 'assets/js/lcni-watchlist.js';
        $style_path = LCNI_PATH . 'assets/css/lcni-watchlist.css';
        $version = file_exists($script_path) ? (string) filemtime($script_path) : '1.9.0';

        wp_register_script('lcni-watchlist', LCNI_URL . 'assets/js/lcni-watchlist.js', [], $version, true);
        wp_register_style('lcni-watchlist', LCNI_URL . 'assets/css/lcni-watchlist.css', [], file_exists($style_path) ? (string) filemtime($style_path) : $version);
        wp_register_style('lcni-fontawesome', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css', [], '6.5.2');
    }



    public function enqueue_global_watchlist_assets() {
        if (!is_user_logged_in()) {
            return;
        }

        wp_enqueue_script('lcni-watchlist');
        wp_enqueue_style('lcni-watchlist');
        wp_enqueue_style('lcni-fontawesome');

        add_action('wp_footer', function () {
            printf(
                '<script>document.body.dataset.lcniWatchlistApi=%s;document.body.dataset.lcniWatchlistNonce=%s;</script>',
                wp_json_encode(esc_url_raw(rest_url('lcni/v1/watchlist'))),
                wp_json_encode(wp_create_nonce('wp_rest'))
            );
        }, 20);
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
        wp_enqueue_style('lcni-watchlist');
        wp_enqueue_style('lcni-fontawesome');

        return sprintf(
            '<div data-lcni-watchlist data-watchlist-api="%1$s" data-watchlist-settings-api="%2$s" data-watchlist-preferences-api="%3$s" data-stock-api="%4$s" data-empty-message="%5$s" data-rest-nonce="%6$s"></div>',
            esc_url(rest_url('lcni/v1/watchlist')),
            esc_url(rest_url('lcni/v1/watchlist/settings')),
            esc_url(rest_url('lcni/v1/watchlist/preferences')),
            esc_url(rest_url('lcni/v1/stock')),
            esc_attr($atts['empty_message']),
            esc_attr(wp_create_nonce('wp_rest'))
        );
    }

    public function render_add_button($atts) {
        $atts = shortcode_atts([
            'symbol' => '',
            'param' => 'symbol',
            'label' => 'Thêm vào Watchlist',
            'icon' => '<i class="fa-solid fa-heart-circle-plus" aria-hidden="true"></i>',
            'class' => '',
        ], $atts, 'lcni_watchlist_add');

        $symbol = $this->normalize_symbol((string) $atts['symbol']);
        if ($symbol === '') {
            $param = sanitize_key((string) $atts['param']);
            if ($param !== '' && isset($_GET[$param])) {
                $symbol = $this->normalize_symbol((string) wp_unslash($_GET[$param]));
            }
        }

        if ($symbol === '' && ((string) $atts['symbol'] !== '' || (isset($param) && $param !== '' && isset($_GET[$param])))) {
            return '<span class="lcni-watchlist-invalid-symbol">Symbol không hợp lệ.</span>';
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
        wp_enqueue_style('lcni-watchlist');
        wp_enqueue_style('lcni-fontawesome');

        return sprintf(
            '<button type="button" class="lcni-watchlist-add-btn %1$s" data-lcni-watchlist-add="1" data-symbol="%2$s" data-watchlist-api="%3$s" data-rest-nonce="%4$s"><span class="lcni-watchlist-add-icon">%5$s</span> <span class="lcni-watchlist-add-label">%6$s</span></button>',
            esc_attr((string) $atts['class']),
            esc_attr($symbol),
            esc_url(rest_url('lcni/v1/watchlist')),
            esc_attr(wp_create_nonce('wp_rest')),
            wp_kses_post((string) $atts['icon']),
            esc_html((string) $atts['label'])
        );
    }

    private function normalize_symbol($raw_symbol) {
        $symbol = strtoupper(sanitize_text_field((string) $raw_symbol));
        if ($symbol === '') {
            return '';
        }

        if (preg_match('/^[A-Z0-9._-]{1,15}$/', $symbol) === 1) {
            return $symbol;
        }

        if (preg_match('/[A-Z0-9._-]{1,15}/', $symbol, $matches) === 1) {
            return $matches[0];
        }

        return '';
    }
}
