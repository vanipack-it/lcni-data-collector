<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_Watchlist_Shortcodes {

    const VERSION = '1.0.0';

    public function __construct() {
        add_action('init', [$this, 'register_shortcodes']);
        add_action('wp_enqueue_scripts', [$this, 'register_assets']);
    }

    public function register_shortcodes() {
        add_shortcode('lcni_watchlist', [$this, 'render_watchlist']);
        add_shortcode('lcni_watchlist_add_button', [$this, 'render_add_button']);
    }

    public function register_assets() {
        $js_path = LCNI_PATH . 'assets/js/lcni-watchlist.js';
        $css_path = LCNI_PATH . 'assets/css/lcni-watchlist.css';
        $version = file_exists($js_path) ? (string) filemtime($js_path) : self::VERSION;
        $style_version = file_exists($css_path) ? (string) filemtime($css_path) : self::VERSION;

        wp_register_script('lcni-watchlist', LCNI_URL . 'assets/js/lcni-watchlist.js', [], $version, true);
        wp_register_style('lcni-watchlist', LCNI_URL . 'assets/css/lcni-watchlist.css', [], $style_version);
    }

    public function render_watchlist($atts = []) {
        $atts = shortcode_atts([
            'title' => 'Watchlist',
            'popup' => '1',
        ], $atts, 'lcni_watchlist');

        wp_enqueue_script('lcni-watchlist');
        wp_enqueue_style('lcni-watchlist');

        return sprintf(
            '<div class="lcni-watchlist" data-lcni-watchlist data-api-base="%1$s" data-popup="%2$s"><div class="lcni-watchlist__header"><strong>%3$s</strong><button type="button" class="lcni-watchlist__settings">⚙</button></div><div class="lcni-watchlist__body"></div></div>',
            esc_url(rest_url('lcni/v1')),
            esc_attr($atts['popup'] === '1' ? '1' : '0'),
            esc_html($atts['title'])
        );
    }

    public function render_add_button($atts = []) {
        $atts = shortcode_atts([
            'symbol' => '',
            'label' => '<i class="fa-solid fa-heart-circle-plus"></i>',
        ], $atts, 'lcni_watchlist_add_button');

        $symbol = strtoupper(sanitize_text_field($atts['symbol']));
        if ($symbol === '') {
            return '';
        }

        wp_enqueue_script('lcni-watchlist');
        wp_enqueue_style('lcni-watchlist');

        return sprintf(
            '<button type="button" class="lcni-watchlist-add" data-lcni-watchlist-add data-symbol="%1$s">%2$s</button>',
            esc_attr($symbol),
            wp_kses_post($atts['label'])
        );
    }
}
