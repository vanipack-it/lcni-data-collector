<?php
/**
 * Plugin Name: LCNI Industry Monitor
 * Description: Industry monitor module with shortcode and AJAX data endpoint.
 * Version: 5.5.3
 * Author: LCNI
 * Text Domain: lcni-industry-monitor
 */

if (! defined('ABSPATH')) {
    exit;
}

define('LCNI_INDUSTRY_MONITOR_VERSION', '5.5.3');
define('LCNI_INDUSTRY_MONITOR_FILE', __FILE__);
define('LCNI_INDUSTRY_MONITOR_PATH', plugin_dir_path(__FILE__));
define('LCNI_INDUSTRY_MONITOR_URL', plugin_dir_url(__FILE__));

require_once LCNI_INDUSTRY_MONITOR_PATH . 'includes/class-industry-data.php';
require_once LCNI_INDUSTRY_MONITOR_PATH . 'includes/class-industry-monitor.php';
require_once LCNI_INDUSTRY_MONITOR_PATH . 'admin/class-industry-settings.php';

function lcni_industry_monitor_bootstrap()
{
    $data = new LCNI_Industry_Data();
    $monitor = new LCNI_Industry_Monitor($data);
    $monitor->register_hooks();

    if (is_admin()) {
        $settings = new LCNI_Industry_Settings();
        $settings->register_hooks();
    }
}
add_action('init', 'lcni_industry_monitor_bootstrap');
