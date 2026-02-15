<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_Settings {

    public function __construct() {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_init', [$this, 'handle_admin_actions']);
        add_action('wp_ajax_lcni_seed_dashboard_snapshot', [$this, 'ajax_seed_dashboard_snapshot']);
    }

    public function menu() {
        add_menu_page('LCNI Settings', 'LCNI Data', 'manage_options', 'lcni-settings', [$this, 'settings_page']);
        add_submenu_page('lcni-settings', 'Saved Data', 'Saved Data', 'manage_options', 'lcni-data-viewer', [$this, 'data_viewer_page']);
    }

    public function register_settings() {
        register_setting('lcni_settings_group', 'lcni_timeframe', ['type' => 'string', 'sanitize_callback' => [$this, 'sanitize_timeframe'], 'default' => '1D']);
        register_setting('lcni_settings_group', 'lcni_days_to_load', ['type' => 'integer', 'sanitize_callback' => [$this, 'sanitize_days_to_load'], 'default' => 365]);
        register_setting('lcni_settings_group', 'lcni_test_symbols', ['type' => 'string', 'sanitize_callback' => [$this, 'sanitize_test_symbols'], 'default' => 'VNINDEX,VN30']);
        register_setting('lcni_settings_group', 'lcni_seed_timeframes', ['type' => 'string', 'sanitize_callback' => [$this, 'sanitize_seed_timeframes'], 'default' => '1D']);
        register_setting('lcni_settings_group', 'lcni_seed_range_mode', ['type' => 'string', 'sanitize_callback' => [$this, 'sanitize_seed_range_mode'], 'default' => 'full']);
        register_setting('lcni_settings_group', 'lcni_seed_from_date', ['type' => 'string', 'sanitize_callback' => [$this, 'sanitize_seed_date'], 'default' => '']);
        register_setting('lcni_settings_group', 'lcni_seed_to_date', ['type' => 'string', 'sanitize_callback' => [$this, 'sanitize_seed_date'], 'default' => '']);
        register_setting('lcni_settings_group', 'lcni_seed_session_count', ['type' => 'integer', 'sanitize_callback' => [$this, 'sanitize_seed_session_count'], 'default' => 300]);
        register_setting('lcni_settings_group', 'lcni_api_key', ['type' => 'string', 'sanitize_callback' => [$this, 'sanitize_api_credential'], 'default' => '']);
        register_setting('lcni_settings_group', 'lcni_api_secret', ['type' => 'string', 'sanitize_callback' => [$this, 'sanitize_api_credential'], 'default' => '']);
        register_setting('lcni_settings_group', 'lcni_access_token', ['type' => 'string', 'sanitize_callback' => [$this, 'sanitize_api_credential'], 'default' => '']);
        register_setting('lcni_settings_group', 'lcni_secdef_url', ['type' => 'string', 'sanitize_callback' => [$this, 'sanitize_secdef_url'], 'default' => LCNI_API::SECDEF_URL]);
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

    public function sanitize_seed_timeframes($value) {
        $timeframes = preg_split('/[,\s]+/', strtoupper((string) $value));
        $timeframes = array_filter(array_map('trim', (array) $timeframes));

        return implode(',', array_unique($timeframes));
    }

    public function sanitize_seed_range_mode($value) {
        $mode = sanitize_key((string) $value);

        return in_array($mode, ['full', 'date_range', 'sessions'], true) ? $mode : 'full';
    }

    public function sanitize_seed_date($value) {
        $value = trim((string) $value);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : '';
    }

    public function sanitize_seed_session_count($value) {
        return max(1, (int) $value);
    }

    public function sanitize_api_credential($value) {
        return trim((string) $value);
    }

    public function sanitize_secdef_url($value) {
        $url = esc_url_raw(trim((string) $value));

        return $url !== '' ? $url : LCNI_API::SECDEF_URL;
    }

    public function handle_admin_actions() {
        if (!is_admin() || !current_user_can('manage_options') || !isset($_POST['lcni_admin_action'])) {
            return;
        }

        $action = sanitize_text_field(wp_unslash($_POST['lcni_admin_action']));
        $nonce = isset($_POST['lcni_action_nonce']) ? sanitize_text_field(wp_unslash($_POST['lcni_action_nonce'])) : '';

        if (!wp_verify_nonce($nonce, 'lcni_admin_actions')) {
            $this->set_notice('error', 'Nonce không hợp lệ, vui lòng thử lại.');
            return;
        }

        if ($action === 'test_api_connection') {
            $this->run_api_connection_test();
        } elseif ($action === 'test_api_multi_symbol') {
            $this->run_multi_symbol_test();
        } elseif ($action === 'sync_securities') {
            $security_summary = LCNI_DB::collect_security_definitions();

            if (is_wp_error($security_summary)) {
                $this->set_notice('error', 'Sync securities thất bại: ' . $security_summary->get_error_message());
            } else {
                $this->set_notice('success', sprintf('Đã sync Security Definition vào lcni_symbols: updated %d / total %d.', (int) ($security_summary['updated'] ?? 0), (int) ($security_summary['total'] ?? 0)));
            }
        } elseif ($action === 'import_symbols_csv') {
            if (empty($_FILES['lcni_symbols_csv']['tmp_name'])) {
                $this->set_notice('error', 'Vui lòng chọn file CSV để import symbol.');
            } else {
                $import_summary = LCNI_DB::import_symbols_from_csv((string) $_FILES['lcni_symbols_csv']['tmp_name']);
                if (is_wp_error($import_summary)) {
                    $this->set_notice('error', 'Import CSV thất bại: ' . $import_summary->get_error_message());
                } else {
                    $this->set_notice('success', sprintf('Đã import CSV vào lcni_symbols: updated %d / total %d.', (int) ($import_summary['updated'] ?? 0), (int) ($import_summary['total'] ?? 0)));
                }
            }
        } elseif ($action === 'start_seed') {
            $seed_mode = isset($_POST['lcni_seed_mode']) ? $this->sanitize_seed_range_mode(wp_unslash($_POST['lcni_seed_mode'])) : 'full';
            $seed_from_date = isset($_POST['lcni_seed_from_date']) ? $this->sanitize_seed_date(wp_unslash($_POST['lcni_seed_from_date'])) : '';
            $seed_to_date = isset($_POST['lcni_seed_to_date']) ? $this->sanitize_seed_date(wp_unslash($_POST['lcni_seed_to_date'])) : '';
            $seed_sessions = isset($_POST['lcni_seed_session_count']) ? $this->sanitize_seed_session_count(wp_unslash($_POST['lcni_seed_session_count'])) : (int) get_option('lcni_seed_session_count', 300);

            update_option('lcni_seed_range_mode', $seed_mode);
            update_option('lcni_seed_from_date', $seed_from_date);
            update_option('lcni_seed_to_date', $seed_to_date);
            update_option('lcni_seed_session_count', $seed_sessions);

            $constraints = [
                'mode' => $seed_mode,
                'from_time' => $this->seed_date_to_timestamp($seed_from_date, false),
                'to_time' => $this->seed_date_to_timestamp($seed_to_date, true),
                'sessions' => $seed_sessions,
            ];

            $created = LCNI_SeedScheduler::start_seed($constraints);

            if (is_wp_error($created)) {
                $this->set_notice('error', $created->get_error_message());
            } else {
                $this->set_notice('success', sprintf('Đã khởi tạo queue seed với %d task.', (int) $created));
            }
        } elseif ($action === 'run_seed_batch') {
            $summary = LCNI_SeedScheduler::run_batch();
            $this->set_notice('success', 'Đã chạy batch tiếp theo: ' . wp_json_encode($summary));
        } elseif ($action === 'pause_seed') {
            LCNI_SeedScheduler::pause();
            $this->set_notice('success', 'Đã tạm dừng seed queue.');
        } elseif ($action === 'resume_seed') {
            LCNI_SeedScheduler::resume();
            $this->set_notice('success', 'Đã resume seed queue.');
        }

        $redirect_tab = isset($_POST['lcni_redirect_tab']) ? sanitize_key(wp_unslash($_POST['lcni_redirect_tab'])) : '';
        $redirect_url = admin_url('admin.php?page=lcni-settings');

        if (in_array($redirect_tab, ['general', 'seed_dashboard', 'change_logs'], true)) {
            $redirect_url = add_query_arg('tab', $redirect_tab, $redirect_url);
        }

        wp_safe_redirect($redirect_url);
        exit;
    }

    private function set_notice($type, $message, $debug = []) {
        set_transient('lcni_settings_notice', ['type' => $type, 'message' => $message, 'debug' => $debug], 60);
    }

    private function run_api_connection_test() {
        $test_result = LCNI_API::test_connection();

        if (is_wp_error($test_result)) {
            LCNI_DB::log_change('api_connection_failed', 'Manual chart-api connection test failed from admin button.');
            $this->set_notice('error', 'Kết nối chart-api thất bại: ' . $test_result->get_error_message());
            return;
        }

        LCNI_DB::log_change('api_connection_success', 'Manual chart-api connection test passed from admin button.');
        $this->set_notice('success', 'Kết nối chart-api thành công.');
    }

    private function run_multi_symbol_test() {
        $raw_symbols = (string) get_option('lcni_test_symbols', 'VNINDEX,VN30');
        $symbols = preg_split('/[,\s]+/', strtoupper($raw_symbols));
        $symbols = array_values(array_unique(array_filter(array_map('trim', (array) $symbols))));

        if (empty($symbols)) {
            $this->set_notice('error', 'Chưa có symbol để test. Vui lòng nhập danh sách symbol.');
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
        $this->set_notice($success_count === count($symbols) ? 'success' : 'error', sprintf('Test chart-api nhiều symbol: %d/%d thành công.', $success_count, count($symbols)), $debug_logs);
    }

    private function seed_date_to_timestamp($date, $end_of_day = false) {
        $date = trim((string) $date);
        if ($date === '') {
            return $end_of_day ? time() : 1;
        }

        $time_suffix = $end_of_day ? ' 23:59:59' : ' 00:00:00';
        $timestamp = strtotime($date . $time_suffix);

        return $timestamp === false ? ($end_of_day ? time() : 1) : (int) $timestamp;
    }

    private function format_task_progress($task) {
        $status = isset($task['status']) ? (string) $task['status'] : 'pending';

        if ($status === 'done') {
            return 100;
        }

        $seed_constraints = LCNI_SeedScheduler::get_seed_constraints();
        $seed_to_time = max(1, (int) ($seed_constraints['to_time'] ?? time()));
        $task_to_time = isset($task['last_to_time']) ? max(1, (int) $task['last_to_time']) : $seed_to_time;

        $min_from = max(1, (int) ($seed_constraints['from_time'] ?? 1));
        if ((string) ($seed_constraints['mode'] ?? 'full') === 'sessions') {
            $sessions = max(1, (int) ($seed_constraints['sessions'] ?? 300));
            $interval = LCNI_HistoryFetcher::timeframe_to_seconds((string) ($task['timeframe'] ?? '1D'));
            $min_from = max(1, $seed_to_time - ($interval * $sessions));
        }

        $total_span = max(1, $seed_to_time - $min_from);
        $processed_span = max(0, $seed_to_time - $task_to_time);
        $progress = (int) floor(($processed_span / $total_span) * 100);
        $progress = max(0, min(99, $progress));

        return $progress;
    }

    private function render_task_rows($tasks) {
        if (empty($tasks)) {
            return '<tr><td colspan="4">Chưa có task seed.</td></tr>';
        }

        $rows = '';
        $next_pending_marked = false;

        foreach ($tasks as $task) {
            $status = isset($task['status']) ? (string) $task['status'] : 'pending';
            $progress = $this->format_task_progress($task);
            $status_class = 'status-pending';
            $bar_class = 'progress-pending';

            if ($status === 'running') {
                $status_class = 'status-running';
                $bar_class = 'progress-running';
            } elseif ($status === 'done') {
                $status_class = 'status-done';
                $bar_class = 'progress-done';
            }

            $task_label = ($task['symbol'] ?? '') . ' ' . ($task['timeframe'] ?? '');
            if ($status === 'pending' && !$next_pending_marked) {
                $task_label .= ' (NEXT)';
                $next_pending_marked = true;
            }

            $rows .= sprintf(
                '<tr>' .
                    '<td>%s</td>' .
                    '<td><span class="lcni-status-pill %s">%s</span></td>' .
                    '<td>%s</td>' .
                    '<td><div class="lcni-progress-track"><div class="lcni-progress-fill %s" style="width:%d%%;"></div></div><span class="lcni-progress-text">%d%%</span></td>' .
                '</tr>',
                esc_html($task_label),
                esc_attr($status_class),
                esc_html(strtoupper($status)),
                esc_html((string) ($task['last_to_time'] ?? '')),
                esc_attr($bar_class),
                (int) $progress,
                (int) $progress
            );
        }

        return $rows;
    }

    public function ajax_seed_dashboard_snapshot() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Forbidden'], 403);
        }

        check_ajax_referer('lcni_seed_dashboard_nonce', 'nonce');

        $stats = LCNI_SeedRepository::get_dashboard_stats();
        $tasks = LCNI_SeedRepository::get_recent_tasks(30);

        wp_send_json_success([
            'stats' => [
                'total' => (int) ($stats['total'] ?? 0),
                'done' => (int) ($stats['done'] ?? 0),
                'running' => (int) ($stats['running'] ?? 0),
                'pending' => (int) ($stats['pending'] ?? 0),
                'error_tasks' => (int) ($stats['error_tasks'] ?? 0),
                'failed_attempts' => (int) ($stats['failed_attempts'] ?? 0),
                'paused' => LCNI_SeedScheduler::is_paused() ? 'YES' : 'NO',
            ],
            'rows_html' => $this->render_task_rows($tasks),
            'updated_at' => current_time('mysql'),
        ]);
    }

    public function settings_page() {
        global $wpdb;

        $active_tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'general';
        if (!in_array($active_tab, ['general', 'seed_dashboard', 'change_logs'], true)) {
            $active_tab = 'general';
        }

        $stats = LCNI_SeedRepository::get_dashboard_stats();
        $tasks = LCNI_SeedRepository::get_recent_tasks(30);
        $logs = $wpdb->get_results("SELECT action, message, created_at FROM {$wpdb->prefix}lcni_change_logs ORDER BY id DESC LIMIT 50", ARRAY_A);
        $notice = get_transient('lcni_settings_notice');

        if ($notice) {
            delete_transient('lcni_settings_notice');
        }
        ?>
        <div class="wrap">
            <h1>LCNI Data Collector</h1>

            <?php if (!empty($notice)) : ?>
                <div class="notice notice-<?php echo esc_attr($notice['type'] === 'success' ? 'success' : 'error'); ?> is-dismissible"><p><?php echo esc_html($notice['message']); ?></p>
                    <?php if (!empty($notice['debug']) && is_array($notice['debug'])) : ?><pre style="max-height:260px;overflow:auto;background:#fff;padding:10px;border:1px solid #ccd0d4;"><?php echo esc_html(implode("\n", $notice['debug'])); ?></pre><?php endif; ?>
                </div>
            <?php endif; ?>

            <h2 class="nav-tab-wrapper">
                <a href="<?php echo esc_url(admin_url('admin.php?page=lcni-settings&tab=general')); ?>" class="nav-tab <?php echo $active_tab === 'general' ? 'nav-tab-active' : ''; ?>">General</a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=lcni-settings&tab=seed_dashboard')); ?>" class="nav-tab <?php echo $active_tab === 'seed_dashboard' ? 'nav-tab-active' : ''; ?>">Seed Dashboard</a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=lcni-settings&tab=change_logs')); ?>" class="nav-tab <?php echo $active_tab === 'change_logs' ? 'nav-tab-active' : ''; ?>">Change Logs</a>
            </h2>

            <?php if ($active_tab === 'general') : ?>
                <form method="post" action="options.php">
                    <?php settings_fields('lcni_settings_group'); ?>
                    <table class="form-table" role="presentation">
                        <tr><th>Timeframe</th><td><input type="text" name="lcni_timeframe" value="<?php echo esc_attr(get_option('lcni_timeframe', '1D')); ?>" size="20"></td></tr>
                        <tr><th>Days to load</th><td><input type="number" name="lcni_days_to_load" value="<?php echo esc_attr((string) get_option('lcni_days_to_load', 365)); ?>" min="1"></td></tr>
                        <tr><th>Test symbols</th><td><input type="text" name="lcni_test_symbols" value="<?php echo esc_attr(get_option('lcni_test_symbols', 'VNINDEX,VN30')); ?>" size="60"></td></tr>
                        <tr><th>Seed timeframes</th><td><input type="text" name="lcni_seed_timeframes" value="<?php echo esc_attr(get_option('lcni_seed_timeframes', '1D')); ?>" size="40"><p class="description">Ví dụ: 1D,1H,15M</p></td></tr>
                    </table>
                    <?php submit_button(); ?>
                </form>

                <h2>Quick Actions</h2>
                <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=lcni-settings')); ?>" style="display:inline-block;margin-right:8px;"><?php wp_nonce_field('lcni_admin_actions', 'lcni_action_nonce'); ?><input type="hidden" name="lcni_redirect_tab" value="general"><input type="hidden" name="lcni_admin_action" value="test_api_connection"><?php submit_button('Test chart-api', 'secondary', 'submit', false); ?></form>
                <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=lcni-settings')); ?>" style="display:inline-block;"><?php wp_nonce_field('lcni_admin_actions', 'lcni_action_nonce'); ?><input type="hidden" name="lcni_redirect_tab" value="general"><input type="hidden" name="lcni_admin_action" value="test_api_multi_symbol"><?php submit_button('Test nhiều symbol', 'secondary', 'submit', false); ?></form>
            <?php elseif ($active_tab === 'seed_dashboard') : ?>
                <h2>Seed Manager</h2>
                <form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin.php?page=lcni-settings')); ?>" style="margin-bottom:12px;">
                    <?php wp_nonce_field('lcni_admin_actions', 'lcni_action_nonce'); ?>
                    <input type="hidden" name="lcni_redirect_tab" value="seed_dashboard">
                    <input type="hidden" name="lcni_admin_action" value="import_symbols_csv">
                    <label for="lcni_symbols_csv"><strong>Import CSV symbol:</strong></label>
                    <input type="file" id="lcni_symbols_csv" name="lcni_symbols_csv" accept=".csv" required>
                    <?php submit_button('Import CSV Symbol', 'secondary', 'submit', false); ?>
                    <p class="description">Hỗ trợ cột <code>symbol</code> hoặc chỉ 1 cột mã chứng khoán; có thể kèm thêm cột Security Definition.</p>
                </form>

                <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=lcni-settings')); ?>" style="display:inline-block;margin-right:8px;"><?php wp_nonce_field('lcni_admin_actions', 'lcni_action_nonce'); ?><input type="hidden" name="lcni_redirect_tab" value="seed_dashboard"><input type="hidden" name="lcni_admin_action" value="sync_securities"><?php submit_button('Sync Security Definition', 'secondary', 'submit', false); ?></form>
                <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=lcni-settings')); ?>" style="display:inline-block;margin-right:8px;padding:8px 10px;background:#fff;border:1px solid #dcdcde;border-radius:6px;">
                    <?php wp_nonce_field('lcni_admin_actions', 'lcni_action_nonce'); ?>
                    <input type="hidden" name="lcni_redirect_tab" value="seed_dashboard">
                    <input type="hidden" name="lcni_admin_action" value="start_seed">
                    <label style="margin-right:8px;"><strong>Khởi tạo SEED:</strong></label>
                    <select name="lcni_seed_mode" style="margin-right:6px;">
                        <?php $seed_mode = get_option('lcni_seed_range_mode', 'full'); ?>
                        <option value="full" <?php selected($seed_mode, 'full'); ?>>Toàn bộ</option>
                        <option value="date_range" <?php selected($seed_mode, 'date_range'); ?>>Theo ngày</option>
                        <option value="sessions" <?php selected($seed_mode, 'sessions'); ?>>Theo số phiên</option>
                    </select>
                    <input type="date" name="lcni_seed_from_date" value="<?php echo esc_attr(get_option('lcni_seed_from_date', '')); ?>" style="margin-right:6px;">
                    <input type="date" name="lcni_seed_to_date" value="<?php echo esc_attr(get_option('lcni_seed_to_date', '')); ?>" style="margin-right:6px;">
                    <input type="number" name="lcni_seed_session_count" value="<?php echo esc_attr((string) get_option('lcni_seed_session_count', 300)); ?>" min="1" style="width:90px;margin-right:6px;" title="Số phiên khi chọn mode sessions">
                    <?php submit_button('Start Seed', 'primary', 'submit', false); ?>
                </form>
                <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=lcni-settings')); ?>" style="display:inline-block;margin-right:8px;"><?php wp_nonce_field('lcni_admin_actions', 'lcni_action_nonce'); ?><input type="hidden" name="lcni_redirect_tab" value="seed_dashboard"><input type="hidden" name="lcni_admin_action" value="run_seed_batch"><?php submit_button('Run 1 Batch', 'secondary', 'submit', false); ?></form>
                <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=lcni-settings')); ?>" style="display:inline-block;margin-right:8px;"><?php wp_nonce_field('lcni_admin_actions', 'lcni_action_nonce'); ?><input type="hidden" name="lcni_redirect_tab" value="seed_dashboard"><input type="hidden" name="lcni_admin_action" value="pause_seed"><?php submit_button('Pause Seed', 'secondary', 'submit', false); ?></form>
                <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=lcni-settings')); ?>" style="display:inline-block;"><?php wp_nonce_field('lcni_admin_actions', 'lcni_action_nonce'); ?><input type="hidden" name="lcni_redirect_tab" value="seed_dashboard"><input type="hidden" name="lcni_admin_action" value="resume_seed"><?php submit_button('Resume Seed', 'secondary', 'submit', false); ?></form>

                <h2 style="margin-top:20px;">Seed Dashboard</h2>
                <p id="lcni-dashboard-updated-at" style="margin-top:0;color:#50575e;">Cập nhật realtime mỗi 5 giây.</p>
                <div id="lcni-seed-stats" style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:12px;">
                    <div style="background:#f6f7f7;border:1px solid #dcdcde;padding:8px 12px;border-radius:6px;min-width:120px;"><strong>Total</strong><br><span data-stat="total"><?php echo esc_html((string) ($stats['total'] ?? 0)); ?></span></div>
                    <div style="background:#ecf9f1;border:1px solid #b8e6cc;padding:8px 12px;border-radius:6px;min-width:120px;"><strong>Done</strong><br><span data-stat="done"><?php echo esc_html((string) ($stats['done'] ?? 0)); ?></span></div>
                    <div style="background:#fff8e5;border:1px solid #f0d898;padding:8px 12px;border-radius:6px;min-width:120px;"><strong>Running</strong><br><span data-stat="running"><?php echo esc_html((string) ($stats['running'] ?? 0)); ?></span></div>
                    <div style="background:#eef3ff;border:1px solid #c9d7ff;padding:8px 12px;border-radius:6px;min-width:120px;"><strong>Pending</strong><br><span data-stat="pending"><?php echo esc_html((string) ($stats['pending'] ?? 0)); ?></span></div>
                    <div style="background:#fff1f0;border:1px solid #f4b7b2;padding:8px 12px;border-radius:6px;min-width:120px;"><strong>Error tasks</strong><br><span data-stat="error_tasks"><?php echo esc_html((string) ($stats['error_tasks'] ?? 0)); ?></span></div>
                    <div style="background:#fff1f0;border:1px solid #f4b7b2;padding:8px 12px;border-radius:6px;min-width:120px;"><strong>Failed attempts</strong><br><span data-stat="failed_attempts"><?php echo esc_html((string) ($stats['failed_attempts'] ?? 0)); ?></span></div>
                    <div style="background:#f6f7f7;border:1px solid #dcdcde;padding:8px 12px;border-radius:6px;min-width:120px;"><strong>Paused</strong><br><span data-stat="paused"><?php echo LCNI_SeedScheduler::is_paused() ? 'YES' : 'NO'; ?></span></div>
                </div>

                <table class="widefat striped" style="max-width:1000px;">
                    <thead><tr><th>Task</th><th>Status</th><th>Last To Time</th><th>Progress</th></tr></thead>
                    <tbody id="lcni-seed-task-rows"><?php echo wp_kses_post($this->render_task_rows($tasks)); ?></tbody>
                </table>

                <script>
                    (function() {
                        const endpoint = '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';
                        const nonce = '<?php echo esc_js(wp_create_nonce('lcni_seed_dashboard_nonce')); ?>';
                        const rowsTarget = document.getElementById('lcni-seed-task-rows');
                        const updatedHint = document.getElementById('lcni-dashboard-updated-at');

                        if (!rowsTarget || !updatedHint) {
                            return;
                        }

                        const updateStatValue = function(name, value) {
                            const target = document.querySelector('[data-stat="' + name + '"]');
                            if (target) {
                                target.textContent = value;
                            }
                        };

                        const refreshDashboard = function() {
                            const body = new URLSearchParams();
                            body.append('action', 'lcni_seed_dashboard_snapshot');
                            body.append('nonce', nonce);

                            fetch(endpoint, {
                                method: 'POST',
                                credentials: 'same-origin',
                                headers: {
                                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                                },
                                body: body.toString()
                            })
                            .then(function(response) { return response.json(); })
                            .then(function(payload) {
                                if (!payload || !payload.success || !payload.data) {
                                    return;
                                }

                                rowsTarget.innerHTML = payload.data.rows_html || '';
                                const stats = payload.data.stats || {};
                                updateStatValue('total', String(stats.total ?? 0));
                                updateStatValue('done', String(stats.done ?? 0));
                                updateStatValue('running', String(stats.running ?? 0));
                                updateStatValue('pending', String(stats.pending ?? 0));
                                updateStatValue('error_tasks', String(stats.error_tasks ?? 0));
                                updateStatValue('failed_attempts', String(stats.failed_attempts ?? 0));
                                updateStatValue('paused', stats.paused ? String(stats.paused) : 'NO');

                                updatedHint.textContent = 'Cập nhật realtime mỗi 5 giây. Lần cập nhật gần nhất: ' + (payload.data.updated_at || 'N/A');
                            });
                        };

                        setInterval(refreshDashboard, 5000);
                        refreshDashboard();
                    })();
                </script>
            <?php else : ?>
                <h2>Change Logs</h2>
                <?php if (!empty($logs)) : ?>
                    <table class="widefat striped" style="max-width:1100px;"><thead><tr><th style="width:180px;">Time</th><th style="width:180px;">Action</th><th>Message</th></tr></thead><tbody>
                    <?php foreach ($logs as $log) : ?>
                        <tr><td><?php echo esc_html($log['created_at']); ?></td><td><?php echo esc_html($log['action']); ?></td><td><?php echo esc_html($log['message']); ?></td></tr>
                    <?php endforeach; ?>
                    </tbody></table>
                <?php else : ?><p>No change logs available yet.</p><?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    public function data_viewer_page() {
        global $wpdb;

        $ohlc_rows = $wpdb->get_results("SELECT symbol, timeframe, event_time, open_price, high_price, low_price, close_price, volume, value_traded, created_at FROM {$wpdb->prefix}lcni_ohlc ORDER BY event_time DESC LIMIT 50", ARRAY_A);
        $symbol_rows = $wpdb->get_results("SELECT s.symbol, s.market_id, m.exchange, s.board_id, s.isin, s.basic_price, s.ceiling_price, s.floor_price, s.security_status, s.source, s.updated_at FROM {$wpdb->prefix}lcni_symbols s LEFT JOIN {$wpdb->prefix}lcni_marketid m ON m.market_id = s.market_id ORDER BY s.updated_at DESC LIMIT 50", ARRAY_A);
        $market_rows = $wpdb->get_results("SELECT market_id, exchange, updated_at FROM {$wpdb->prefix}lcni_marketid ORDER BY CAST(market_id AS UNSIGNED), market_id", ARRAY_A);
        $icb2_rows = $wpdb->get_results("SELECT id_icb2, name_icb2, updated_at FROM {$wpdb->prefix}lcni_icb2 ORDER BY id_icb2 ASC", ARRAY_A);
        ?>
        <div class="wrap">
            <h1>Saved Data</h1>
            <p>Trang này hiển thị dữ liệu đã lưu từ API/CSV (50 bản ghi gần nhất mỗi bảng).</p>

            <h2>LCNI Symbols</h2>
            <?php if (!empty($symbol_rows)) : ?>
                <table class="widefat striped" style="max-width: 1400px;"><thead><tr><th>Symbol</th><th>Market ID</th><th>Exchange</th><th>Board</th><th>ISIN</th><th>Basic</th><th>Ceiling</th><th>Floor</th><th>Status</th><th>Source</th><th>Updated At</th></tr></thead><tbody>
                    <?php foreach ($symbol_rows as $row) : ?><tr><td><?php echo esc_html($row['symbol']); ?></td><td><?php echo esc_html($row['market_id']); ?></td><td><?php echo esc_html($row['exchange'] ?: 'N/A'); ?></td><td><?php echo esc_html($row['board_id']); ?></td><td><?php echo esc_html($row['isin']); ?></td><td><?php echo esc_html($row['basic_price']); ?></td><td><?php echo esc_html($row['ceiling_price']); ?></td><td><?php echo esc_html($row['floor_price']); ?></td><td><?php echo esc_html($row['security_status']); ?></td><td><?php echo esc_html($row['source']); ?></td><td><?php echo esc_html($row['updated_at']); ?></td></tr><?php endforeach; ?>
                </tbody></table>
            <?php else : ?><p>Chưa có dữ liệu lcni_symbols.</p><?php endif; ?>

            <h2 style="margin-top: 30px;">LCNI Market Mapping</h2>
            <?php if (!empty($market_rows)) : ?>
                <table class="widefat striped" style="max-width: 700px;"><thead><tr><th>Market ID</th><th>Exchange</th><th>Updated At</th></tr></thead><tbody>
                    <?php foreach ($market_rows as $row) : ?><tr><td><?php echo esc_html($row['market_id']); ?></td><td><?php echo esc_html($row['exchange']); ?></td><td><?php echo esc_html($row['updated_at']); ?></td></tr><?php endforeach; ?>
                </tbody></table>
            <?php else : ?><p>Chưa có dữ liệu lcni_marketid.</p><?php endif; ?>

            <h2 style="margin-top: 30px;">LCNI ICB2</h2>
            <?php if (!empty($icb2_rows)) : ?>
                <table class="widefat striped" style="max-width: 900px;"><thead><tr><th>ID ICB2</th><th>Tên ngành</th><th>Updated At</th></tr></thead><tbody>
                    <?php foreach ($icb2_rows as $row) : ?><tr><td><?php echo esc_html($row['id_icb2']); ?></td><td><?php echo esc_html($row['name_icb2']); ?></td><td><?php echo esc_html($row['updated_at']); ?></td></tr><?php endforeach; ?>
                </tbody></table>
            <?php else : ?><p>Chưa có dữ liệu lcni_icb2.</p><?php endif; ?>

            <h2 style="margin-top: 30px;">OHLC Data</h2>
            <?php if (!empty($ohlc_rows)) : ?>
                <table class="widefat striped" style="max-width: 1400px;"><thead><tr><th>Symbol</th><th>Timeframe</th><th>Event Time</th><th>Open</th><th>High</th><th>Low</th><th>Close</th><th>Volume</th><th>Value Traded</th><th>Created At</th></tr></thead><tbody>
                    <?php foreach ($ohlc_rows as $row) : ?><tr><td><?php echo esc_html($row['symbol']); ?></td><td><?php echo esc_html($row['timeframe']); ?></td><td><?php echo esc_html(gmdate('Y-m-d H:i:s', (int) $row['event_time'])); ?></td><td><?php echo esc_html($row['open_price']); ?></td><td><?php echo esc_html($row['high_price']); ?></td><td><?php echo esc_html($row['low_price']); ?></td><td><?php echo esc_html($row['close_price']); ?></td><td><?php echo esc_html($row['volume']); ?></td><td><?php echo esc_html($row['value_traded']); ?></td><td><?php echo esc_html($row['created_at']); ?></td></tr><?php endforeach; ?>
                </tbody></table>
            <?php else : ?><p>Chưa có dữ liệu OHLC.</p><?php endif; ?>
        </div>
        <?php
    }
}
