<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_Member_Permission_Middleware {

    private $service;

    private $shortcode_map = [
        'lcni_stock_chart' => ['module' => 'chart', 'capability' => 'view'],
        'lcni_stock_chart_query' => ['module' => 'chart', 'capability' => 'view'],
        'lcni_stock_filter' => ['module' => 'filter', 'capability' => 'filter'],
        'lcni_filter' => ['module' => 'filter', 'capability' => 'filter'],
        'lcni_watchlist' => ['module' => 'watchlist', 'capability' => 'view'],
        'lcni_member_login' => ['module' => 'member-login', 'capability' => 'view'],
        'lcni_member_register' => ['module' => 'member-register', 'capability' => 'view'],
    ];

    public function __construct(LCNI_SaaS_Service $service) {
        $this->service = $service;
        add_filter('pre_do_shortcode_tag', [$this, 'guard_shortcode'], 10, 4);
        add_filter('rest_request_before_callbacks', [$this, 'guard_rest_request'], 10, 3);
    }

    public function guard_shortcode($return, $tag, $attr, $m) {
        if (!isset($this->shortcode_map[$tag])) {
            return $return;
        }

        $map = $this->shortcode_map[$tag];
        if ($this->service->can($map['module'], $map['capability'])) {
            return $return;
        }

        return '<p>Access denied for shortcode: ' . esc_html($tag) . '</p>';
    }

    public function guard_rest_request($response, $handler, $request) {
        $route = $request->get_route();
        if (strpos($route, '/lcni/v1/filter') === 0 && !$this->service->can('filter', 'filter')) {
            return new WP_Error('lcni_forbidden_filter', 'Bạn không có quyền filter.', ['status' => 403]);
        }

        if (strpos($route, '/lcni/v1/stock') === 0 && !$this->service->can('chart', 'view')) {
            return new WP_Error('lcni_forbidden_chart', 'Bạn không có quyền xem chart/data.', ['status' => 403]);
        }

        return $response;
    }
}
