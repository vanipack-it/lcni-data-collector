<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_Settings {

    public function __construct() {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_init', [$this, 'handle_admin_actions']);
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
        register_setting('lcni_settings_group', 'lcni_timeframe', [
            'type' => 'string',
            'sanitize_callback' => [$this, 'sanitize_timeframe'],
            'default' => '1D',
        ]);

        register_setting('lcni_settings_group', 'lcni_days_to_load', [
            'type' => 'integer',
            'sanitize_callback' => [$this, 'sanitize_days_to_load'],
            'default' => 365,
        ]);

        register_setting('lcni_settings_group', 'lcni_test_symbols', [
            'type' => 'string',
            'sanitize_callback' => [$this, 'sanitize_test_symbols'],
            'default' => 'VNINDEX,VN30',
        ]);
    }

    public function sanitize_timeframe($value) {
        $value = strtoupper(trim((string) $value));

        return $value === '' ? '1D' : $value;
    }

    public function sanitize_days_to_load($value) {
        $days = (int) $value;

        return $days > 0 ? $days : 365;
    }

    public function sanitize_test_symbols($value) {
        $symbols = preg_split('/[,\s]+/', strtoupper((string) $value));
        $symbols = array_filter(array_map('trim', (array) $symbols));

        return implode(',', array_unique($symbols));
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
            LCNI_DB::collect_all_data(false);
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

        if ($action === 'test_api_multi_symbol') {
            $this->run_multi_symbol_test();
        }

        wp_safe_redirect(admin_url('admin.php?page=lcni-settings'));
        exit;
    }

    private function run_api_connection_test() {
        $test_result = LCNI_API::test_connection();

        if (is_wp_error($test_result)) {
            LCNI_DB::log_change('api_connection_failed', 'Manual chart-api connection test failed from admin button.');

            set_transient(
                'lcni_settings_notice',
                [
                    'type' => 'error',
                    'message' => 'Kết nối chart-api thất bại: ' . $test_result->get_error_message(),
                ],
                60
            );

            return;
        }

        LCNI_DB::log_change('api_connection_success', 'Manual chart-api connection test passed from admin button.');

        set_transient(
            'lcni_settings_notice',
            [
                'type' => 'success',
                'message' => 'Kết nối chart-api thành công.',
            ],
            60
        );
    }

    private function run_multi_symbol_test() {
        $raw_symbols = (string) get_option('lcni_test_symbols', 'VNINDEX,VN30');
        $symbols = preg_split('/[,\s]+/', strtoupper($raw_symbols));
        $symbols = array_values(array_unique(array_filter(array_map('trim', (array) $symbols))));

        if (empty($symbols)) {
            set_transient(
                'lcni_settings_notice',
                [
                    'type' => 'error',
                    'message' => 'Chưa có symbol để test. Vui lòng nhập danh sách symbol.',
                ],
                60
            );

            return;
        }

        $timeframe = strtoupper((string) get_option('lcni_timeframe', '1D'));
        $debug_logs = [];
        $success_count = 0;

        foreach ($symbols as $symbol) {
            $payload = LCNI_API::get_candles($symbol, $timeframe, 10);
            if (!is_array($payload) || empty($payload['t']) || !is_array($payload['t'])) {
                $debug_logs[] = sprintf('[FAIL] %s: Không lấy được dữ liệu nến.', $symbol);
                continue;
            }

            $candles = lcni_convert_candles($payload, $symbol, $timeframe);
            $latest = end($candles);
            $latest_time = isset($latest['candle_time']) ? $latest['candle_time'] : 'N/A';
            $debug_logs[] = sprintf('[OK] %s: %d nến, nến mới nhất=%s', $symbol, count($candles), $latest_time);
            $success_count++;
        }

        LCNI_DB::log_change('multi_symbol_test', sprintf('Tested %d symbols, success=%d.', count($symbols), $success_count), $debug_logs);

        set_transient(
            'lcni_settings_notice',
            [
                'type' => $success_count === count($symbols) ? 'success' : 'error',
                'message' => sprintf('Test chart-api nhiều symbol: thành công %d/%d.', $success_count, count($symbols)),
                'debug' => $debug_logs,
            ],
            120
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
            <h1>LCNI Market Data Settings</h1>
            <?php if ($notice) : ?>
                <div class="notice notice-<?php echo esc_attr($notice['type'] === 'error' ? 'error' : 'success'); ?> is-dismissible">
                    <p><?php echo esc_html($notice['message']); ?></p>
                    <?php if (!empty($notice['debug']) && is_array($notice['debug'])) : ?>
                        <ul style="margin-left: 20px; list-style: disc;">
                            <?php foreach ($notice['debug'] as $debug_line) : ?>
                                <li><code><?php echo esc_html($debug_line); ?></code></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php settings_fields('lcni_settings_group'); ?>

                <table class="form-table">
                    <tr>
                        <th>Timeframe</th>
                        <td>
                            <input type="text" name="lcni_timeframe"
                                   value="<?php echo esc_attr(get_option('lcni_timeframe', '1D')); ?>"
                                   placeholder="Ví dụ: 1D, 1W, 1H"
                                   size="20">
                        </td>
                    </tr>

                    <tr>
                        <th>Số ngày load</th>
                        <td>
                            <input type="number" min="1" max="5000" name="lcni_days_to_load"
                                   value="<?php echo esc_attr((int) get_option('lcni_days_to_load', 365)); ?>"
                                   size="10">
                            <p class="description">Dùng cho đồng bộ thủ công. Cron sẽ chỉ lấy nến mới nhất trong DB để giảm tải.</p>
                        </td>
                    </tr>

                    <tr>
                        <th>Test symbols</th>
                        <td>
                            <input type="text" name="lcni_test_symbols"
                                   value="<?php echo esc_attr(get_option('lcni_test_symbols', 'VNINDEX,VN30')); ?>"
                                   placeholder="Ví dụ: VNINDEX, VN30, HPG"
                                   size="40">
                            <p class="description">Dùng cho nút test nhiều symbol cùng lúc.</p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>

            <h2>Quick Actions</h2>
            <p>Dùng các nút bên dưới để test chart-api và chạy đồng bộ dữ liệu ngay trong trang admin.</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=lcni-settings')); ?>" style="display: inline-block; margin-right: 8px;">
                <?php wp_nonce_field('lcni_admin_actions', 'lcni_action_nonce'); ?>
                <input type="hidden" name="lcni_admin_action" value="test_api_connection">
                <?php submit_button('Test chart-api', 'secondary', 'submit', false); ?>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=lcni-settings')); ?>" style="display: inline-block; margin-right: 8px;">
                <?php wp_nonce_field('lcni_admin_actions', 'lcni_action_nonce'); ?>
                <input type="hidden" name="lcni_admin_action" value="run_sync_now">
                <?php submit_button('Chạy đồng bộ ngay', 'secondary', 'submit', false); ?>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=lcni-settings')); ?>" style="display: inline-block;">
                <?php wp_nonce_field('lcni_admin_actions', 'lcni_action_nonce'); ?>
                <input type="hidden" name="lcni_admin_action" value="test_api_multi_symbol">
                <?php submit_button('Test nhiều symbol', 'secondary', 'submit', false); ?>
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
            <p>Trang này hiển thị dữ liệu đã lưu từ API (50 bản ghi gần nhất mỗi bảng).</p>

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
