<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_Settings {

    private static $credentials_checked = false;

    public function __construct() {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_init', [$this, 'handle_admin_actions']);
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

        add_submenu_page(
            'lcni-settings',
            'Saved Data',
            'Saved Data',
            'manage_options',
            'lcni-data-viewer',
            [$this, 'data_viewer_page']
        );
    }

    public function register_settings() {
        register_setting(
            'lcni_settings_group',
            'lcni_api_mode',
            [
                'type' => 'string',
                'sanitize_callback' => [$this, 'sanitize_api_mode'],
                'default' => 'market_data',
            ]
        );
        register_setting('lcni_settings_group', 'lcni_api_key');
        register_setting('lcni_settings_group', 'lcni_api_secret');
        register_setting('lcni_settings_group', 'lcni_auth_email', ['sanitize_callback' => 'sanitize_email']);
    }

    public function sanitize_api_mode($value) {
        return in_array($value, ['market_data', 'trading_api'], true) ? $value : 'market_data';
    }

    public function handle_admin_actions() {
        if (!is_admin() || !current_user_can('manage_options')) {
            return;
        }

        if (!isset($_POST['lcni_admin_action'])) {
            return;
        }

        $action = sanitize_text_field(wp_unslash($_POST['lcni_admin_action']));
        $nonce = isset($_POST['lcni_action_nonce']) ? sanitize_text_field(wp_unslash($_POST['lcni_action_nonce'])) : '';

        if (!wp_verify_nonce($nonce, 'lcni_admin_actions')) {
            set_transient(
                'lcni_settings_notice',
                [
                    'type' => 'error',
                    'message' => 'Nonce không hợp lệ, vui lòng thử lại.',
                ],
                60
            );

            return;
        }

        if ($action === 'test_api_connection') {
            $this->run_api_connection_test();
        }

        if ($action === 'run_sync_now') {
            LCNI_DB::collect_all_data();
            LCNI_DB::log_change('manual_sync', 'Manual sync triggered from admin page.');

            set_transient(
                'lcni_settings_notice',
                [
                    'type' => 'success',
                    'message' => 'Đã chạy đồng bộ dữ liệu thủ công.',
                ],
                60
            );
        }

        wp_safe_redirect(admin_url('admin.php?page=lcni-settings'));
        exit;
    }

    private function run_api_connection_test() {
        $api_mode = get_option('lcni_api_mode', 'market_data');
        $api_key = get_option('lcni_api_key');
        $api_secret = get_option('lcni_api_secret');
        $auth_email = get_option('lcni_auth_email');
        $test_result = LCNI_API::test_connection($api_mode, $api_key, $api_secret, $auth_email);

        if (is_wp_error($test_result)) {
            LCNI_DB::log_change('api_connection_failed', 'Manual DNSE API connection test failed from admin button.');

            set_transient(
                'lcni_settings_notice',
                [
                    'type' => 'error',
                    'message' => 'Kết nối DNSE API thất bại: ' . $test_result->get_error_message(),
                ],
                60
            );

            return;
        }

        LCNI_DB::log_change('api_connection_success', 'Manual DNSE API connection test passed from admin button.');

        set_transient(
            'lcni_settings_notice',
            [
                'type' => 'success',
                'message' => 'Kết nối DNSE API thành công.',
            ],
            60
        );
    }

    public function maybe_validate_credentials($option, $old_value, $value) {
        if (self::$credentials_checked) {
            return;
        }

        if (!in_array($option, ['lcni_api_mode', 'lcni_api_key', 'lcni_api_secret', 'lcni_auth_email'], true)) {
            return;
        }

        if (!is_admin() || !current_user_can('manage_options')) {
            return;
        }

        if (!isset($_POST['option_page']) || $_POST['option_page'] !== 'lcni_settings_group') {
            return;
        }

        self::$credentials_checked = true;

        $api_mode = get_option('lcni_api_mode', 'market_data');
        $api_key = get_option('lcni_api_key');
        $api_secret = get_option('lcni_api_secret');
        $auth_email = get_option('lcni_auth_email');

        $test_result = LCNI_API::test_connection($api_mode, $api_key, $api_secret, $auth_email);

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

                <?php $api_mode = get_option('lcni_api_mode', 'market_data'); ?>

                <table class="form-table">
                    <tr>
                        <th>Chế độ kết nối</th>
                        <td>
                            <label style="display: block; margin-bottom: 8px;">
                                <input type="radio" name="lcni_api_mode" value="market_data" <?php checked($api_mode, 'market_data'); ?>>
                                Market Data (không cần key)
                            </label>
                            <label style="display: block;">
                                <input type="radio" name="lcni_api_mode" value="trading_api" <?php checked($api_mode, 'trading_api'); ?>>
                                Trading API (cần key + email xác thực)
                            </label>
                        </td>
                    </tr>

                    <tr>
                        <th>API Key</th>
                        <td class="lcni-trading-only">
                            <input type="text" name="lcni_api_key"
                                   value="<?php echo esc_attr(get_option('lcni_api_key')); ?>"
                                   size="50">
                        </td>
                    </tr>

                    <tr>
                        <th>API Secret</th>
                        <td class="lcni-trading-only">
                            <input type="password" name="lcni_api_secret"
                                   value="<?php echo esc_attr(get_option('lcni_api_secret')); ?>"
                                   size="50">
                        </td>
                    </tr>

                    <tr>
                        <th>Email xác thực</th>
                        <td class="lcni-trading-only">
                            <input type="email" name="lcni_auth_email"
                                   value="<?php echo esc_attr(get_option('lcni_auth_email')); ?>"
                                   placeholder="you@example.com"
                                   size="50">
                            <p class="description">Chỉ dùng cho chế độ Trading API.</p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>

            <h2>Quick Actions</h2>
            <p>Dùng các nút bên dưới để test API và chạy đồng bộ dữ liệu ngay trong trang admin.</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=lcni-settings')); ?>" style="display: inline-block; margin-right: 8px;">
                <?php wp_nonce_field('lcni_admin_actions', 'lcni_action_nonce'); ?>
                <input type="hidden" name="lcni_admin_action" value="test_api_connection">
                <?php submit_button('Test API ngay', 'secondary', 'submit', false); ?>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=lcni-settings')); ?>" style="display: inline-block;">
                <?php wp_nonce_field('lcni_admin_actions', 'lcni_action_nonce'); ?>
                <input type="hidden" name="lcni_admin_action" value="run_sync_now">
                <?php submit_button('Chạy đồng bộ ngay', 'secondary', 'submit', false); ?>
            </form>

            <p>
                <?php
                $next_run = wp_next_scheduled(LCNI_CRON_HOOK);
                if ($next_run) {
                    printf(
                        'Cron đang bật. Lần chạy tiếp theo: <strong>%s</strong>.',
                        esc_html(wp_date('Y-m-d H:i:s', $next_run))
                    );
                } else {
                    echo 'Cron chưa được lên lịch. Plugin sẽ tự tạo lại lịch khi có request admin tiếp theo.';
                }
                ?>
            </p>

            <script>
                (function() {
                    var modeRadios = document.querySelectorAll('input[name="lcni_api_mode"]');
                    var tradingRows = document.querySelectorAll('.lcni-trading-only');

                    function toggleTradingFields() {
                        var selected = document.querySelector('input[name="lcni_api_mode"]:checked');
                        var isTradingMode = selected && selected.value === 'trading_api';

                        tradingRows.forEach(function(cell) {
                            var row = cell.closest('tr');
                            if (row) {
                                row.style.display = isTradingMode ? '' : 'none';
                            }
                        });
                    }

                    modeRadios.forEach(function(radio) {
                        radio.addEventListener('change', toggleTradingFields);
                    });

                    toggleTradingFields();
                })();
            </script>

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

    public function data_viewer_page() {
        global $wpdb;

        $ohlc_table = $wpdb->prefix . 'lcni_ohlc';
        $security_table = $wpdb->prefix . 'lcni_security_definition';

        $ohlc_rows = [];
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $ohlc_table)) === $ohlc_table) {
            $ohlc_rows = $wpdb->get_results("SELECT symbol, timeframe, event_time, open_price, high_price, low_price, close_price, volume, value_traded, created_at FROM {$ohlc_table} ORDER BY event_time DESC LIMIT 50", ARRAY_A);
        }

        $security_rows = [];
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $security_table)) === $security_table) {
            $security_rows = $wpdb->get_results("SELECT symbol, exchange, security_type, market, reference_price, ceiling_price, floor_price, lot_size, listed_volume, updated_at FROM {$security_table} ORDER BY updated_at DESC LIMIT 50", ARRAY_A);
        }
        ?>
        <div class="wrap">
            <h1>Saved Data</h1>
            <p>Trang này hiển thị dữ liệu đã lưu từ DNSE API (50 bản ghi gần nhất mỗi bảng).</p>

            <h2>Security Definitions</h2>
            <?php if (!empty($security_rows)) : ?>
                <table class="widefat striped" style="max-width: 1400px;">
                    <thead>
                    <tr>
                        <th>Symbol</th>
                        <th>Exchange</th>
                        <th>Security Type</th>
                        <th>Market</th>
                        <th>Reference</th>
                        <th>Ceiling</th>
                        <th>Floor</th>
                        <th>Lot Size</th>
                        <th>Listed Volume</th>
                        <th>Updated At</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($security_rows as $row) : ?>
                        <tr>
                            <td><?php echo esc_html($row['symbol']); ?></td>
                            <td><?php echo esc_html($row['exchange']); ?></td>
                            <td><?php echo esc_html($row['security_type']); ?></td>
                            <td><?php echo esc_html($row['market']); ?></td>
                            <td><?php echo esc_html($row['reference_price']); ?></td>
                            <td><?php echo esc_html($row['ceiling_price']); ?></td>
                            <td><?php echo esc_html($row['floor_price']); ?></td>
                            <td><?php echo esc_html($row['lot_size']); ?></td>
                            <td><?php echo esc_html($row['listed_volume']); ?></td>
                            <td><?php echo esc_html($row['updated_at']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else : ?>
                <p>Chưa có dữ liệu security definition.</p>
            <?php endif; ?>

            <h2 style="margin-top: 30px;">OHLC Data</h2>
            <?php if (!empty($ohlc_rows)) : ?>
                <table class="widefat striped" style="max-width: 1400px;">
                    <thead>
                    <tr>
                        <th>Symbol</th>
                        <th>Timeframe</th>
                        <th>Event Time</th>
                        <th>Open</th>
                        <th>High</th>
                        <th>Low</th>
                        <th>Close</th>
                        <th>Volume</th>
                        <th>Value Traded</th>
                        <th>Created At</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($ohlc_rows as $row) : ?>
                        <tr>
                            <td><?php echo esc_html($row['symbol']); ?></td>
                            <td><?php echo esc_html($row['timeframe']); ?></td>
                            <td><?php echo esc_html(gmdate('Y-m-d H:i:s', (int) $row['event_time'])); ?></td>
                            <td><?php echo esc_html($row['open_price']); ?></td>
                            <td><?php echo esc_html($row['high_price']); ?></td>
                            <td><?php echo esc_html($row['low_price']); ?></td>
                            <td><?php echo esc_html($row['close_price']); ?></td>
                            <td><?php echo esc_html($row['volume']); ?></td>
                            <td><?php echo esc_html($row['value_traded']); ?></td>
                            <td><?php echo esc_html($row['created_at']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else : ?>
                <p>Chưa có dữ liệu OHLC.</p>
            <?php endif; ?>
        </div>
        <?php
    }
}
