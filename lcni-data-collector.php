<?php
/*
Plugin Name: LCNI Data Collector
Description: Lưu dữ liệu OHLC và Security Definition từ DNSE API
Version: 1.1
*/

if (!defined('ABSPATH')) {
    exit;
}

define('LCNI_PATH', plugin_dir_path(__FILE__));

require_once LCNI_PATH . 'includes/class-lcni-db.php';
require_once LCNI_PATH . 'includes/class-lcni-settings.php';
require_once LCNI_PATH . 'includes/class-lcni-api.php';

register_activation_hook(__FILE__, ['LCNI_DB', 'create_tables']);

new LCNI_Settings();
