<?php

if (! defined('ABSPATH')) {
    exit;
}

class LCNI_Industry_Settings
{
    public function register_hooks()
    {
        add_action('admin_menu', array($this, 'register_menu'));
    }

    public function register_menu()
    {
        add_menu_page(
            __('LCNI Industry Monitor', 'lcni-industry-monitor'),
            __('Industry Monitor', 'lcni-industry-monitor'),
            'manage_options',
            'lcni-industry-monitor',
            array($this, 'render_page'),
            'dashicons-chart-bar',
            58
        );
    }

    public function render_page()
    {
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('LCNI Industry Monitor', 'lcni-industry-monitor') . '</h1>';
        echo '<p>' . esc_html__('Use shortcode [lcni_industry_monitor] to display monitor table.', 'lcni-industry-monitor') . '</p>';
        echo '</div>';
    }
}
