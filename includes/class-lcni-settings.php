<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_Settings {

    private static $credentials_checked = false;

    public function __construct() {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('updated_option', [$this, 'maybe_validate_credentials'], 10, 3);
    }

    public function menu() {
        add_menu_page(
            'LCNI Settings',
            'LCNI Data',
            'manage_options',
            'lcni-settings',
            [$this, 'settings_page']
        );
    }

    public function register_settings() {
        register_setting('lcni_settings_group', 'lcni_api_key');
        register_setting('lcni_settings_group', 'lcni_api_secret');
    }

    public function maybe_validate_credentials($option, $old_value, $value) {
        if (self::$credentials_checked) {
            return;
        }

        if (!in_array($option, ['lcni_api_key', 'lcni_api_secret'], true)) {
            return;
        }

        if (!is_admin() || !current_user_can('manage_options')) {
            return;
        }

        if (!isset($_POST['option_page']) || $_POST['option_page'] !== 'lcni_settings_group') {
            return;
        }

        self::$credentials_checked = true;

        $api_key = get_option('lcni_api_key');
        $api_secret = get_option('lcni_api_secret');

        $test_result = LCNI_API::test_connection($api_key, $api_secret);

        if (is_wp_error($test_result)) {
            set_transient(
                'lcni_settings_notice',
                [
                    'type' => 'error',
                    'message' => 'Kết nối DNSE API thất bại: ' . $test_result->get_error_message(),
                ],
                60
            );

            LCNI_DB::log_change('api_connection_failed', 'DNSE API connection test failed after saving credentials.');

            return;
        }

        LCNI_DB::log_change('api_connection_success', 'DNSE API connection test passed. Start collecting data.');
        LCNI_DB::collect_all_data();

        set_transient(
            'lcni_settings_notice',
            [
                'type' => 'success',
                'message' => 'Kết nối DNSE API thành công. Dữ liệu đã được đồng bộ ban đầu.',
            ],
            60
        );
    }

    public function settings_page() {
        global $wpdb;

        $log_table = $wpdb->prefix . 'lcni_change_logs';
        $logs = [];

        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $log_table)) === $log_table) {
            $logs = $wpdb->get_results("SELECT action, message, created_at FROM {$log_table} ORDER BY created_at DESC LIMIT 10", ARRAY_A);
        }

        $notice = get_transient('lcni_settings_notice');
        if ($notice) {
            delete_transient('lcni_settings_notice');
        }
        ?>
        <div class="wrap">
            <h1>LCNI API Settings</h1>
            <?php if ($notice) : ?>
                <div class="notice notice-<?php echo esc_attr($notice['type'] === 'error' ? 'error' : 'success'); ?> is-dismissible">
                    <p><?php echo esc_html($notice['message']); ?></p>
                </div>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php settings_fields('lcni_settings_group'); ?>

                <table class="form-table">
                    <tr>
                        <th>API Key</th>
                        <td>
                            <input type="text" name="lcni_api_key"
                                   value="<?php echo esc_attr(get_option('lcni_api_key')); ?>"
                                   size="50">
                        </td>
                    </tr>

                    <tr>
                        <th>API Secret</th>
                        <td>
                            <input type="password" name="lcni_api_secret"
                                   value="<?php echo esc_attr(get_option('lcni_api_secret')); ?>"
                                   size="50">
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>

            <h2>Change Logs</h2>
            <?php if (!empty($logs)) : ?>
                <table class="widefat striped" style="max-width: 1100px;">
                    <thead>
                        <tr>
                            <th style="width: 180px;">Time</th>
                            <th style="width: 180px;">Action</th>
                            <th>Message</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log) : ?>
                            <tr>
                                <td><?php echo esc_html($log['created_at']); ?></td>
                                <td><?php echo esc_html($log['action']); ?></td>
                                <td><?php echo esc_html($log['message']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else : ?>
                <p>No change logs available yet.</p>
            <?php endif; ?>
        </div>
        <?php
    }
}
