<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_Stock_Detail_Router {

    const STOCK_QUERY_VAR = 'lcni_stock_symbol';

    public function __construct() {
        add_action('init', [$this, 'register_rewrite_rule']);
        add_filter('query_vars', [$this, 'register_query_vars']);
        add_filter('template_include', [$this, 'load_stock_template']);
    }

    public function register_rewrite_rule() {
        add_rewrite_rule('^stock/([^/]+)/?$', 'index.php?symbol=$matches[1]&' . self::STOCK_QUERY_VAR . '=$matches[1]', 'top');
    }

    public function register_query_vars($vars) {
        $vars[] = self::STOCK_QUERY_VAR;
        $vars[] = 'symbol';

        return $vars;
    }

    public function load_stock_template($template) {
        $symbol = $this->get_current_symbol();
        if ($symbol === '') {
            return $template;
        }

        $page_id = (int) get_option('lcni_frontend_stock_detail_page', 0);
        if ($page_id <= 0) {
            return $template;
        }

        $page = get_post($page_id);
        if (!$page instanceof WP_Post || $page->post_type !== 'page' || $page->post_status !== 'publish') {
            return $template;
        }

        global $wp_query, $post;

        status_header(200);
        $wp_query->is_404 = false;
        $wp_query->is_home = false;
        $wp_query->is_archive = false;
        $wp_query->is_search = false;
        $wp_query->is_page = true;
        $wp_query->is_single = false;
        $wp_query->is_singular = true;
        $wp_query->queried_object = $page;
        $wp_query->queried_object_id = $page->ID;
        $wp_query->post = $page;
        $wp_query->posts = [$page];
        $wp_query->post_count = 1;
        $wp_query->found_posts = 1;
        $wp_query->max_num_pages = 1;

        $wp_query->set('page_id', (string) $page->ID);
        $wp_query->set('pagename', $page->post_name);
        $wp_query->set('symbol', $symbol);
        $wp_query->set(self::STOCK_QUERY_VAR, $symbol);

        $post = $page;
        setup_postdata($page);

        add_filter('the_content', [$this, 'inject_symbol_to_shortcodes'], 20);

        $template_slug = get_page_template_slug($page->ID);
        if (is_string($template_slug) && $template_slug !== '') {
            $located = locate_template($template_slug);
            if (is_string($located) && $located !== '') {
                return $located;
            }
        }

        $fallback_template = get_page_template();

        return is_string($fallback_template) && $fallback_template !== '' ? $fallback_template : $template;
    }

    public function inject_symbol_to_shortcodes($content) {
        $symbol = $this->get_current_symbol();
        if ($symbol === '') {
            return $content;
        }

        $targets = ['lcni_stock_overview', 'lcni_stock_chart', 'lcni_stock_signals'];

        foreach ($targets as $shortcode_tag) {
            $pattern = '/\[' . preg_quote($shortcode_tag, '/') . '(\s[^\]]*)?\]/i';
            $content = preg_replace_callback($pattern, static function ($matches) use ($symbol) {
                $source = (string) $matches[0];
                if (preg_match('/\ssymbol\s*=\s*["\"][^"\"]+["\"]/i', $source)) {
                    return $source;
                }

                return rtrim(substr($source, 0, -1)) . ' symbol="' . esc_attr($symbol) . '"]';
            }, $content);
        }

        return $content;
    }

    private function get_current_symbol() {
        $symbol = get_query_var('symbol');

        if (!is_string($symbol) || $symbol === '') {
            $symbol = get_query_var(self::STOCK_QUERY_VAR);
        }

        $symbol = strtoupper(sanitize_text_field((string) $symbol));

        return preg_match('/^[A-Z0-9_.-]{1,20}$/', $symbol) ? $symbol : '';
    }
}
