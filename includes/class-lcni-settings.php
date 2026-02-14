<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_Settings {

    public function __construct() {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_init', [$this, 'register_settings']);
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

    public function settings_page() {
        global $wpdb;

        $log_table = $wpdb->prefix . 'lcni_change_logs';
        $logs = [];

        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $log_table)) === $log_table) {
            $logs = $wpdb->get_results("SELECT action, message, created_at FROM {$log_table} ORDER BY created_at DESC LIMIT 10", ARRAY_A);
        }
        ?>
        <div class="wrap">
            <h1>LCNI API Settings</h1>
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
