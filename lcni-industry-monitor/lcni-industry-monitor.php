<?php
/**
 * Plugin Name: LCNI Industry Monitor
 * Description: Industry monitor module — đa shortcode, hỗ trợ mode ICB và Symbol.
 * Version: 6.0.0
 * Author: LCNI
 * Text Domain: lcni-industry-monitor
 */

if (! defined('ABSPATH')) {
    exit;
}

define('LCNI_INDUSTRY_MONITOR_VERSION', '6.0.0');
define('LCNI_INDUSTRY_MONITOR_FILE',    __FILE__);
define('LCNI_INDUSTRY_MONITOR_PATH',    plugin_dir_path(__FILE__));
define('LCNI_INDUSTRY_MONITOR_URL',     plugin_dir_url(__FILE__));

// ── Core classes ──────────────────────────────────────────────────────────────
require_once LCNI_INDUSTRY_MONITOR_PATH . 'admin/class-industry-settings.php';
require_once LCNI_INDUSTRY_MONITOR_PATH . 'includes/class-industry-data.php';
require_once LCNI_INDUSTRY_MONITOR_PATH . 'includes/class-im-monitor-db.php';
require_once LCNI_INDUSTRY_MONITOR_PATH . 'includes/class-im-symbol-data.php';
require_once LCNI_INDUSTRY_MONITOR_PATH . 'includes/class-im-shortcode.php';
require_once LCNI_INDUSTRY_MONITOR_PATH . 'includes/class-im-admin.php';

function lcni_industry_monitor_bootstrap(): void
{
    // Shortcode mới (đa instance, hỗ trợ ICB + Symbol)
    $shortcode = new LCNI_IM_Shortcode();
    $shortcode->register_hooks();

    if (is_admin()) {
        // Settings toàn cục
        $settings = new LCNI_Industry_Settings();
        $settings->register_hooks();

        // Quản lý monitor instances
        $admin = new LCNI_IM_Admin();
        $admin->register_hooks();
    }
}
add_action('init', 'lcni_industry_monitor_bootstrap');
