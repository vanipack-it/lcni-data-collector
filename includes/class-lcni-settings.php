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
        add_action('wp_ajax_lcni_rule_rebuild_status', [$this, 'ajax_rule_rebuild_status']);
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
        register_setting('lcni_settings_group', 'lcni_update_interval_minutes', ['type' => 'integer', 'sanitize_callback' => [$this, 'sanitize_update_interval'], 'default' => 5]);
        register_setting('lcni_settings_group', 'lcni_ohlc_latest_interval_minutes', ['type' => 'integer', 'sanitize_callback' => [$this, 'sanitize_update_interval'], 'default' => 5]);
        register_setting('lcni_settings_group', 'lcni_frontend_settings_signals', ['type' => 'array', 'sanitize_callback' => [$this, 'sanitize_frontend_module_settings'], 'default' => []]);
        register_setting('lcni_settings_group', 'lcni_frontend_settings_overview', ['type' => 'array', 'sanitize_callback' => [$this, 'sanitize_frontend_module_settings'], 'default' => []]);
        register_setting('lcni_settings_group', 'lcni_frontend_settings_chart', ['type' => 'array', 'sanitize_callback' => [$this, 'sanitize_frontend_chart_settings'], 'default' => []]);
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

    public function sanitize_update_interval($value) {
        return max(1, (int) $value);
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
        } elseif ($action === 'prepare_csv_import') {
            $table_key = isset($_POST['lcni_import_table']) ? sanitize_key(wp_unslash($_POST['lcni_import_table'])) : 'lcni_symbols';
            if (empty($_FILES['lcni_import_csv']['tmp_name'])) {
                $this->set_notice('error', 'Vui lòng chọn file CSV để import dữ liệu.');
            } else {
                $existing_draft = get_transient($this->get_csv_import_draft_key());
                if (is_array($existing_draft) && !empty($existing_draft['file_path']) && is_string($existing_draft['file_path']) && file_exists($existing_draft['file_path'])) {
                    @unlink($existing_draft['file_path']);
                }

                $draft = $this->prepare_csv_import_draft((string) $_FILES['lcni_import_csv']['tmp_name'], $table_key);
                if (is_wp_error($draft)) {
                    $this->set_notice('error', 'Không thể đọc CSV: ' . $draft->get_error_message());
                } else {
                    set_transient($this->get_csv_import_draft_key(), $draft, 15 * MINUTE_IN_SECONDS);
                    $this->set_notice('success', 'Đã nhận diện cột CSV. Vui lòng map cột và bấm chạy import.');
                }
            }
        } elseif ($action === 'run_csv_import') {
            $draft = get_transient($this->get_csv_import_draft_key());
            if (!is_array($draft) || empty($draft['file_path']) || empty($draft['table_key'])) {
                $this->set_notice('error', 'Phiên import đã hết hạn. Vui lòng upload CSV lại.');
            } else {
                $mapping = isset($_POST['lcni_import_mapping']) ? (array) wp_unslash($_POST['lcni_import_mapping']) : [];
                $import_summary = LCNI_DB::import_csv_with_mapping((string) $draft['file_path'], (string) $draft['table_key'], $mapping);
                if (is_wp_error($import_summary)) {
                    $this->set_notice('error', 'Import CSV thất bại: ' . $import_summary->get_error_message());
                } else {
                    $this->set_notice('success', sprintf('Đã import CSV vào %s: updated %d / total %d.', esc_html((string) ($import_summary['table'] ?? 'N/A')), (int) ($import_summary['updated'] ?? 0), (int) ($import_summary['total'] ?? 0)));
                    delete_transient($this->get_csv_import_draft_key());
                    if (!empty($draft['file_path']) && is_string($draft['file_path']) && file_exists($draft['file_path'])) {
                        @unlink($draft['file_path']);
                    }
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
        } elseif ($action === 'save_rule_settings') {
            $raw_rules = isset($_POST['lcni_rule_settings']) ? (array) wp_unslash($_POST['lcni_rule_settings']) : [];
            $force_recalculate = !empty($_POST['lcni_rule_execute']);
            $validated_rules = LCNI_DB::validate_rule_settings_input($raw_rules);

            if (is_wp_error($validated_rules)) {
                $this->set_notice('error', $validated_rules->get_error_message());
            } else {
                @set_time_limit(0);
                wp_raise_memory_limit('admin');

                $result = LCNI_DB::update_rule_settings($validated_rules, $force_recalculate);
                $queued = (int) ($result['queued'] ?? 0);

                if (!empty($result['updated'])) {
                    $this->set_notice('success', sprintf('Đã lưu Rule Setting và đưa %d symbol/timeframe vào hàng đợi chạy nền.', $queued));
                } elseif ($force_recalculate) {
                    $this->set_notice('success', sprintf('Đã thực thi Rule Setting và đưa %d symbol/timeframe vào hàng đợi chạy nền.', $queued));
                } else {
                    $this->set_notice('success', 'Rule Setting không thay đổi, giữ nguyên dữ liệu hiện tại.');
                }
            }
        } elseif ($action === 'save_update_data_settings') {
            $enabled = !empty($_POST['lcni_update_enabled']);
            $interval = isset($_POST['lcni_update_interval_minutes']) ? $this->sanitize_update_interval(wp_unslash($_POST['lcni_update_interval_minutes'])) : 5;
            $saved = LCNI_Update_Manager::save_settings($enabled, $interval);
            update_option('lcni_update_interval_minutes', (int) $saved['interval_minutes']);
            $this->set_notice('success', 'Đã lưu cài đặt Update Data.');
        } elseif ($action === 'run_manual_update_data') {
            $status = LCNI_Update_Manager::trigger_manual_update();
            if (!empty($status['error'])) {
                $this->set_notice('error', 'Cập nhật thủ công thất bại: ' . $status['error']);
            } else {
                $this->set_notice('success', 'Đã chạy cập nhật thủ công.');
            }
        } elseif ($action === 'save_ohlc_latest_settings') {
            $enabled = !empty($_POST['lcni_ohlc_latest_enabled']);
            $interval = isset($_POST['lcni_ohlc_latest_interval_minutes']) ? $this->sanitize_update_interval(wp_unslash($_POST['lcni_ohlc_latest_interval_minutes'])) : 5;
            LCNI_OHLC_Latest_Manager::save_settings($enabled, $interval);
            $this->set_notice('success', 'Đã lưu cài đặt OHLC Latest Snapshot.');
        } elseif ($action === 'run_manual_ohlc_latest_sync') {
            $status = LCNI_OHLC_Latest_Manager::trigger_manual_sync();
            if (!empty($status['error'])) {
                $this->set_notice('error', 'Đồng bộ thủ công OHLC latest thất bại: ' . $status['error']);
            } else {
                $this->set_notice('success', 'Đã đồng bộ thủ công OHLC latest.');
            }
        } elseif ($action === 'save_frontend_settings') {
            $module = isset($_POST['lcni_frontend_module']) ? sanitize_key(wp_unslash($_POST['lcni_frontend_module'])) : '';
            $allowed_modules = ['signals', 'overview', 'chart'];

            if (!in_array($module, $allowed_modules, true)) {
                $this->set_notice('error', 'Module frontend không hợp lệ.');
            } else {
                if ($module === 'chart') {
                    $input = [
                        'allowed_panels' => isset($_POST['lcni_frontend_allowed_panels']) ? (array) wp_unslash($_POST['lcni_frontend_allowed_panels']) : [],
                        'default_mode' => isset($_POST['lcni_frontend_default_mode']) ? wp_unslash($_POST['lcni_frontend_default_mode']) : '',
                        'compact_mode' => isset($_POST['lcni_frontend_compact_mode']) ? wp_unslash($_POST['lcni_frontend_compact_mode']) : '',
                    ];
                    update_option('lcni_frontend_settings_chart', $this->sanitize_frontend_chart_settings($input));
                } else {
                    $rule_fields = isset($_POST['lcni_frontend_rule_field']) ? (array) wp_unslash($_POST['lcni_frontend_rule_field']) : [];
                    $rule_operators = isset($_POST['lcni_frontend_rule_operator']) ? (array) wp_unslash($_POST['lcni_frontend_rule_operator']) : [];
                    $rule_values = isset($_POST['lcni_frontend_rule_value']) ? (array) wp_unslash($_POST['lcni_frontend_rule_value']) : [];
                    $rule_colors = isset($_POST['lcni_frontend_rule_color']) ? (array) wp_unslash($_POST['lcni_frontend_rule_color']) : [];
                    $value_rules = [];

                    $rule_count = max(count($rule_fields), count($rule_operators), count($rule_values), count($rule_colors));
                    for ($i = 0; $i < $rule_count; $i++) {
                        $value_rules[] = [
                            'field' => $rule_fields[$i] ?? '',
                            'operator' => $rule_operators[$i] ?? '',
                            'value' => $rule_values[$i] ?? '',
                            'color' => $rule_colors[$i] ?? '',
                        ];
                    }

                    $input = [
                        'allowed_fields' => isset($_POST['lcni_frontend_allowed_fields']) ? (array) wp_unslash($_POST['lcni_frontend_allowed_fields']) : [],
                        'styles' => [
                            'label_color' => isset($_POST['lcni_frontend_style_label_color']) ? wp_unslash($_POST['lcni_frontend_style_label_color']) : '',
                            'value_color' => isset($_POST['lcni_frontend_style_value_color']) ? wp_unslash($_POST['lcni_frontend_style_value_color']) : '',
                            'item_background' => isset($_POST['lcni_frontend_style_item_background']) ? wp_unslash($_POST['lcni_frontend_style_item_background']) : '',
                            'container_background' => isset($_POST['lcni_frontend_style_container_background']) ? wp_unslash($_POST['lcni_frontend_style_container_background']) : '',
                            'container_border' => isset($_POST['lcni_frontend_style_container_border']) ? wp_unslash($_POST['lcni_frontend_style_container_border']) : '',
                            'item_height' => isset($_POST['lcni_frontend_style_item_height']) ? wp_unslash($_POST['lcni_frontend_style_item_height']) : '',
                            'label_font_size' => isset($_POST['lcni_frontend_style_label_font_size']) ? wp_unslash($_POST['lcni_frontend_style_label_font_size']) : '',
                            'value_font_size' => isset($_POST['lcni_frontend_style_value_font_size']) ? wp_unslash($_POST['lcni_frontend_style_value_font_size']) : '',
                            'value_rules' => $value_rules,
                        ],
                    ];

                    update_option('lcni_frontend_settings_' . $module, $this->sanitize_frontend_module_settings($input));
                }
                $this->set_notice('success', 'Đã lưu Frontend Settings cho module ' . $module . '.');
            }
        }

        $redirect_tab = isset($_POST['lcni_redirect_tab']) ? sanitize_key(wp_unslash($_POST['lcni_redirect_tab'])) : '';
        $redirect_page = isset($_POST['lcni_redirect_page']) ? sanitize_key(wp_unslash($_POST['lcni_redirect_page'])) : 'lcni-settings';
        $redirect_page = in_array($redirect_page, ['lcni-settings', 'lcni-data-viewer'], true) ? $redirect_page : 'lcni-settings';
        $redirect_url = admin_url('admin.php?page=' . $redirect_page);

        if ($redirect_page === 'lcni-settings' && in_array($redirect_tab, ['general', 'seed_dashboard', 'update_data', 'rule_settings', 'frontend_settings', 'change_logs', 'lcni-tab-rule-xay-nen', 'lcni-tab-rule-xay-nen-count-30', 'lcni-tab-rule-nen-type', 'lcni-tab-rule-pha-nen', 'lcni-tab-rule-tang-gia-kem-vol', 'lcni-tab-rule-rs-exchange', 'lcni-tab-update-runtime', 'lcni-tab-update-ohlc-latest', 'lcni-tab-frontend-signals', 'lcni-tab-frontend-overview', 'lcni-tab-frontend-chart'], true)) {
            $redirect_url = add_query_arg('tab', $redirect_tab, $redirect_url);
        }

        if ($redirect_page === 'lcni-data-viewer' && in_array($redirect_tab, ['lcni-tab-symbols', 'lcni-tab-market', 'lcni-tab-icb2', 'lcni-tab-sym-icb-market', 'lcni-tab-ohlc'], true)) {
            $redirect_url = add_query_arg('tab', $redirect_tab, $redirect_url);
        }

        wp_safe_redirect($redirect_url);
        exit;
    }

    private function set_notice($type, $message, $debug = []) {
        set_transient('lcni_settings_notice', ['type' => $type, 'message' => $message, 'debug' => $debug], 60);
    }

    private function get_csv_import_draft_key() {
        return 'lcni_csv_import_draft_' . get_current_user_id();
    }

    private function prepare_csv_import_draft($tmp_file_path, $table_key) {
        $targets = LCNI_DB::get_csv_import_targets();
        if (!isset($targets[$table_key])) {
            return new WP_Error('invalid_table', 'Bảng import không hợp lệ.');
        }

        $headers = LCNI_DB::detect_csv_columns($tmp_file_path);
        if (is_wp_error($headers)) {
            return $headers;
        }

        $upload_dir = wp_upload_dir();
        if (!empty($upload_dir['error'])) {
            return new WP_Error('upload_dir_error', (string) $upload_dir['error']);
        }

        $target_dir = trailingslashit($upload_dir['basedir']) . 'lcni-imports';
        if (!wp_mkdir_p($target_dir)) {
            return new WP_Error('mkdir_failed', 'Không thể tạo thư mục tạm cho import CSV.');
        }

        $saved_file = trailingslashit($target_dir) . 'import-' . get_current_user_id() . '-' . time() . '-' . wp_generate_password(8, false, false) . '.csv';
        if (!@copy($tmp_file_path, $saved_file)) {
            return new WP_Error('copy_failed', 'Không thể lưu file CSV tạm thời để map cột.');
        }

        $suggested_mapping = LCNI_DB::suggest_csv_mapping($table_key, $headers);

        return [
            'table_key' => $table_key,
            'file_path' => $saved_file,
            'headers' => $headers,
            'suggested_mapping' => $suggested_mapping,
            'created_at' => time(),
        ];
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

    public function ajax_rule_rebuild_status() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Forbidden'], 403);
        }

        check_ajax_referer('lcni_rule_rebuild_nonce', 'nonce');

        $status = LCNI_DB::get_rule_rebuild_status();
        wp_send_json_success($status);
    }

    public function settings_page() {
        global $wpdb;

        $active_tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'general';
        $rule_sub_tabs = ['lcni-tab-rule-xay-nen', 'lcni-tab-rule-xay-nen-count-30', 'lcni-tab-rule-nen-type', 'lcni-tab-rule-pha-nen', 'lcni-tab-rule-tang-gia-kem-vol', 'lcni-tab-rule-rs-exchange'];
        $frontend_sub_tabs = ['lcni-tab-frontend-signals', 'lcni-tab-frontend-overview', 'lcni-tab-frontend-chart'];
        $update_data_sub_tabs = ['lcni-tab-update-runtime', 'lcni-tab-update-ohlc-latest'];
        if (in_array($active_tab, $rule_sub_tabs, true)) {
            $active_tab = 'rule_settings';
        }
        if (in_array($active_tab, $frontend_sub_tabs, true)) {
            $active_tab = 'frontend_settings';
        }
        if (in_array($active_tab, $update_data_sub_tabs, true)) {
            $active_tab = 'update_data';
        }

        if (!in_array($active_tab, ['general', 'seed_dashboard', 'update_data', 'rule_settings', 'frontend_settings', 'change_logs'], true)) {
            $active_tab = 'general';
        }

        $rule_settings = LCNI_DB::get_rule_settings();
        $stats = LCNI_SeedRepository::get_dashboard_stats();
        $tasks = LCNI_SeedRepository::get_recent_tasks(30);
        $logs = $wpdb->get_results("SELECT action, message, created_at FROM {$wpdb->prefix}lcni_change_logs ORDER BY id DESC LIMIT 50", ARRAY_A);
        $notice = get_transient('lcni_settings_notice');
        $csv_import_targets = LCNI_DB::get_csv_import_targets();
        $csv_import_draft = get_transient($this->get_csv_import_draft_key());

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
                <a href="<?php echo esc_url(admin_url('admin.php?page=lcni-settings&tab=update_data')); ?>" class="nav-tab <?php echo $active_tab === 'update_data' ? 'nav-tab-active' : ''; ?>">Update Data</a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=lcni-settings&tab=rule_settings')); ?>" class="nav-tab <?php echo $active_tab === 'rule_settings' ? 'nav-tab-active' : ''; ?>">Rule Setting</a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=lcni-settings&tab=frontend_settings')); ?>" class="nav-tab <?php echo $active_tab === 'frontend_settings' ? 'nav-tab-active' : ''; ?>">Frontend Setting</a>
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
                <form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin.php?page=lcni-settings')); ?>" style="margin-bottom:12px;padding:10px;background:#fff;border:1px solid #dcdcde;border-radius:6px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                    <?php wp_nonce_field('lcni_admin_actions', 'lcni_action_nonce'); ?>
                    <input type="hidden" name="lcni_redirect_tab" value="seed_dashboard">
                    <input type="hidden" name="lcni_admin_action" value="prepare_csv_import">
                    <label for="lcni_import_table"><strong>Import CSV (chung):</strong></label>
                    <select id="lcni_import_table" name="lcni_import_table">
                        <?php foreach ($csv_import_targets as $table_key => $target) : ?>
                            <option value="<?php echo esc_attr($table_key); ?>" <?php selected(($csv_import_draft['table_key'] ?? 'lcni_symbols'), $table_key); ?>><?php echo esc_html($target['label']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="file" id="lcni_import_csv" name="lcni_import_csv" accept=".csv" required>
                    <?php submit_button('Nhận diện cột CSV', 'secondary', 'submit', false); ?>
                    <p class="description" style="flex:1 1 100%;margin:0;">Workflow: nhận diện cột → chọn bảng → map cột CSV với cột DB → chạy import (upsert, giữ nguyên cấu trúc bảng và Primary Key).</p>
                </form>

                <?php if (!empty($csv_import_draft) && is_array($csv_import_draft) && !empty($csv_import_draft['headers']) && !empty($csv_import_draft['table_key']) && isset($csv_import_targets[$csv_import_draft['table_key']])) : ?>
                    <?php $target_meta = $csv_import_targets[$csv_import_draft['table_key']]; ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=lcni-settings')); ?>" style="margin-bottom:12px;padding:10px;background:#fff;border:1px solid #dcdcde;border-radius:6px;">
                        <?php wp_nonce_field('lcni_admin_actions', 'lcni_action_nonce'); ?>
                        <input type="hidden" name="lcni_redirect_tab" value="seed_dashboard">
                        <input type="hidden" name="lcni_admin_action" value="run_csv_import">
                        <p style="margin-top:0;"><strong>Map cột cho bảng:</strong> <?php echo esc_html($target_meta['label']); ?> (Primary Key: <code><?php echo esc_html((string) $target_meta['primary_key']); ?></code>)</p>
                        <table class="widefat striped" style="max-width:980px;">
                            <thead><tr><th>Cột CSV</th><th>Cột DB</th></tr></thead>
                            <tbody>
                                <?php foreach ((array) $csv_import_draft['headers'] as $header) : ?>
                                    <?php $normalized = (string) ($header['normalized'] ?? ''); ?>
                                    <tr>
                                        <td><code><?php echo esc_html((string) ($header['raw'] ?? '')); ?></code><br><small><?php echo esc_html($normalized); ?></small></td>
                                        <td>
                                            <select name="lcni_import_mapping[<?php echo esc_attr($normalized); ?>]">
                                                <option value="">-- Bỏ qua --</option>
                                                <?php foreach ((array) $target_meta['columns'] as $db_column => $db_type) : ?>
                                                    <option value="<?php echo esc_attr($db_column); ?>" <?php selected(($csv_import_draft['suggested_mapping'][$normalized] ?? ''), $db_column); ?>><?php echo esc_html($db_column . ' (' . $db_type . ')'); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php submit_button('Chạy Import CSV', 'primary', 'submit', false); ?>
                    </form>
                <?php endif; ?>

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

            <?php elseif ($active_tab === 'update_data') : ?>
                <?php
                $update_settings = LCNI_Update_Manager::get_settings();
                $update_status = LCNI_Update_Manager::get_status();
                $ohlc_latest_settings = LCNI_OHLC_Latest_Manager::get_settings();
                $ohlc_latest_status = LCNI_OHLC_Latest_Manager::get_status();
                $requested_update_sub_tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'lcni-tab-update-runtime';
                $active_update_sub_tab = in_array($requested_update_sub_tab, ['lcni-tab-update-runtime', 'lcni-tab-update-ohlc-latest'], true) ? $requested_update_sub_tab : 'lcni-tab-update-runtime';
                ?>
                <h2>Update Data</h2>
                <div class="lcni-sub-tab-nav" id="lcni-update-sub-tabs">
                    <button type="button" data-sub-tab="lcni-tab-update-runtime">Update Data Runtime (wp_lcni_ohlc)</button>
                    <button type="button" data-sub-tab="lcni-tab-update-ohlc-latest">OHLC Latest Snapshot (wp_lcni_ohlc_latest)</button>
                </div>

                <div id="lcni-tab-update-runtime" class="lcni-sub-tab-content">
                    <h3>Update Data Runtime (wp_lcni_ohlc)</h3>
                    <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=lcni-settings')); ?>" style="max-width:720px;margin-bottom:16px;">
                        <?php wp_nonce_field('lcni_admin_actions', 'lcni_action_nonce'); ?>
                        <input type="hidden" name="lcni_redirect_tab" value="lcni-tab-update-runtime">
                        <input type="hidden" name="lcni_admin_action" value="save_update_data_settings">
                        <table class="form-table" role="presentation">
                            <tr>
                                <th>Bật tự động cập nhật</th>
                                <td><label><input type="checkbox" name="lcni_update_enabled" value="1" <?php checked(!empty($update_settings['enabled'])); ?>> Kích hoạt</label></td>
                            </tr>
                            <tr>
                                <th>Chu kỳ (phút)</th>
                                <td><input type="number" min="1" name="lcni_update_interval_minutes" value="<?php echo esc_attr((string) ($update_settings['interval_minutes'] ?? 5)); ?>"></td>
                            </tr>
                        </table>
                        <?php submit_button('Lưu & Thực thi tự động', 'primary', 'submit', false); ?>
                    </form>

                    <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=lcni-settings')); ?>" style="margin-bottom:16px;display:inline-block;">
                        <?php wp_nonce_field('lcni_admin_actions', 'lcni_action_nonce'); ?>
                        <input type="hidden" name="lcni_redirect_tab" value="lcni-tab-update-runtime">
                        <input type="hidden" name="lcni_admin_action" value="run_manual_update_data">
                        <?php submit_button('Cập nhật thủ công ngay', 'secondary', 'submit', false); ?>
                    </form>

                    <h3>Trạng thái cập nhật</h3>
                    <table class="widefat striped" style="max-width:980px;">
                        <tbody>
                            <tr><th>Đang chạy</th><td><?php echo !empty($update_status['running']) ? 'Đang chạy' : 'Đã dừng'; ?></td></tr>
                            <tr><th>Số symbol đã cập nhật</th><td><?php echo esc_html((string) ($update_status['processed_symbols'] ?? 0)); ?></td></tr>
                            <tr><th>Số symbol chờ cập nhật</th><td><?php echo esc_html((string) ($update_status['pending_symbols'] ?? 0)); ?></td></tr>
                            <tr><th>Số symbol thay đổi giá</th><td><?php echo esc_html((string) ($update_status['changed_symbols'] ?? 0)); ?></td></tr>
                            <tr><th>Cột tính toán hoàn tất</th><td><?php echo !empty($update_status['indicators_done']) ? 'Đã xong' : 'Chưa xong'; ?></td></tr>
                            <tr><th>Thời gian bắt đầu</th><td><?php echo esc_html((string) ($update_status['started_at'] ?? '-')); ?></td></tr>
                            <tr><th>Thời gian kết thúc</th><td><?php echo esc_html((string) ($update_status['ended_at'] ?? '-')); ?></td></tr>
                            <tr><th>Dự kiến phiên cập nhật tiếp theo</th><td><?php echo !empty($update_status['next_run_ts']) ? esc_html(wp_date('Y-m-d H:i:s', (int) $update_status['next_run_ts'])) : '-'; ?></td></tr>
                            <tr><th>Thông báo</th><td><?php echo esc_html((string) ($update_status['message'] ?? '-')); ?></td></tr>
                            <tr><th>Lỗi</th><td><?php echo esc_html((string) ($update_status['error'] ?? '')); ?></td></tr>
                        </tbody>
                    </table>
                </div>

                <div id="lcni-tab-update-ohlc-latest" class="lcni-sub-tab-content">
                    <h3>OHLC Latest Snapshot (wp_lcni_ohlc_latest)</h3>
                    <p>Tự động sync cấu trúc + dữ liệu bằng MySQL Event + Stored Procedure.</p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=lcni-settings')); ?>" style="max-width:720px;margin-bottom:16px;">
                        <?php wp_nonce_field('lcni_admin_actions', 'lcni_action_nonce'); ?>
                        <input type="hidden" name="lcni_redirect_tab" value="lcni-tab-update-ohlc-latest">
                        <input type="hidden" name="lcni_admin_action" value="save_ohlc_latest_settings">
                        <table class="form-table" role="presentation">
                            <tr>
                                <th>Bật event tự động</th>
                                <td><label><input type="checkbox" name="lcni_ohlc_latest_enabled" value="1" <?php checked(!empty($ohlc_latest_settings['enabled'])); ?>> Kích hoạt</label></td>
                            </tr>
                            <tr>
                                <th>Chu kỳ Event (phút)</th>
                                <td><input type="number" min="1" name="lcni_ohlc_latest_interval_minutes" value="<?php echo esc_attr((string) ($ohlc_latest_settings['interval_minutes'] ?? 5)); ?>"></td>
                            </tr>
                        </table>
                        <?php submit_button('Lưu cài đặt Snapshot', 'primary', 'submit', false); ?>
                    </form>

                    <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=lcni-settings')); ?>" style="margin-bottom:16px;display:inline-block;">
                        <?php wp_nonce_field('lcni_admin_actions', 'lcni_action_nonce'); ?>
                        <input type="hidden" name="lcni_redirect_tab" value="lcni-tab-update-ohlc-latest">
                        <input type="hidden" name="lcni_admin_action" value="run_manual_ohlc_latest_sync">
                        <?php submit_button('Sync Snapshot thủ công ngay', 'secondary', 'submit', false); ?>
                    </form>

                    <h3>Trạng thái snapshot</h3>
                    <table class="widefat striped" style="max-width:980px;">
                        <tbody>
                            <tr><th>Đang chạy</th><td><?php echo !empty($ohlc_latest_status['running']) ? 'Đang chạy' : 'Đã dừng'; ?></td></tr>
                            <tr><th>Rows affected lần chạy cuối</th><td><?php echo esc_html((string) ($ohlc_latest_status['rows_affected'] ?? 0)); ?></td></tr>
                            <tr><th>Thời gian bắt đầu</th><td><?php echo esc_html((string) ($ohlc_latest_status['started_at'] ?? '-')); ?></td></tr>
                            <tr><th>Thời gian kết thúc</th><td><?php echo esc_html((string) ($ohlc_latest_status['ended_at'] ?? '-')); ?></td></tr>
                            <tr><th>Thông báo</th><td><?php echo esc_html((string) ($ohlc_latest_status['message'] ?? '-')); ?></td></tr>
                            <tr><th>Lỗi</th><td><?php echo esc_html((string) ($ohlc_latest_status['error'] ?? '')); ?></td></tr>
                        </tbody>
                    </table>
                </div>

                <script>
                    (function() {
                        const nav = document.getElementById('lcni-update-sub-tabs');
                        if (!nav) {
                            return;
                        }
                        const buttons = nav.querySelectorAll('button[data-sub-tab]');
                        const panes = document.querySelectorAll('#lcni-tab-update-runtime, #lcni-tab-update-ohlc-latest');
                        const active = '<?php echo esc_js($active_update_sub_tab); ?>';

                        const activate = function(tabId) {
                            buttons.forEach((btn) => btn.classList.toggle('active', btn.getAttribute('data-sub-tab') === tabId));
                            panes.forEach((pane) => pane.classList.toggle('active', pane.id === tabId));
                        };

                        buttons.forEach((btn) => {
                            btn.addEventListener('click', () => activate(btn.getAttribute('data-sub-tab')));
                        });

                        activate(active);
                    })();
                </script>

            <?php elseif ($active_tab === 'rule_settings') : ?>
                <?php $this->render_rule_settings_section($rule_settings, 'lcni-settings'); ?>
            <?php elseif ($active_tab === 'frontend_settings') : ?>
                <?php $this->render_frontend_settings_section(); ?>
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

        $ohlc_columns = [
            'symbol' => 'Symbol',
            'timeframe' => 'Timeframe',
            'event_time' => 'Event Time',
            'trading_index' => 'Trading Index',
            'open_price' => 'Open',
            'high_price' => 'High',
            'low_price' => 'Low',
            'close_price' => 'Close',
            'volume' => 'Volume',
            'value_traded' => 'Value Traded',
            'pct_t_1' => '%T-1',
            'pct_t_3' => '%T-3',
            'pct_1w' => '%1W',
            'pct_1m' => '%1M',
            'rs_1m_by_exchange' => 'RS 1M (Exchange)',
            'rs_1w_by_exchange' => 'RS 1W (Exchange)',
            'rs_3m_by_exchange' => 'RS 3M (Exchange)',
            'rs_exchange_status' => 'RS Exchange Status',
            'rs_exchange_recommend' => 'RS Exchange Recommend',
            'pct_3m' => '%3M',
            'pct_6m' => '%6M',
            'pct_1y' => '%1Y',
            'ma10' => 'MA10',
            'ma20' => 'MA20',
            'ma50' => 'MA50',
            'ma100' => 'MA100',
            'ma200' => 'MA200',
            'h1m' => 'H1M',
            'h3m' => 'H3M',
            'h6m' => 'H6M',
            'h1y' => 'H1Y',
            'l1m' => 'L1M',
            'l3m' => 'L3M',
            'l6m' => 'L6M',
            'l1y' => 'L1Y',
            'vol_ma10' => 'VolMA10',
            'vol_ma20' => 'VolMA20',
            'gia_sv_ma10' => 'Gia sv MA10',
            'gia_sv_ma20' => 'Gia sv MA20',
            'gia_sv_ma50' => 'Gia sv MA50',
            'gia_sv_ma100' => 'Gia sv MA100',
            'gia_sv_ma200' => 'Gia sv MA200',
            'vol_sv_vol_ma10' => 'Vol sv Vol MA10',
            'vol_sv_vol_ma20' => 'Vol sv Vol MA20',
            'macd' => 'MACD',
            'macd_signal' => 'MACD Signal',
            'rsi' => 'RSI',
            'xay_nen' => 'Xây nền',
            'xay_nen_count_30' => 'Xây nền count 30',
            'nen_type' => 'Nền type',
            'pha_nen' => 'Phá nền',
            'tang_gia_kem_vol' => 'Tăng giá kèm Vol',
            'created_at' => 'Created At',
        ];

        $ohlc_rows = $wpdb->get_results("SELECT symbol, timeframe, event_time, trading_index, open_price, high_price, low_price, close_price, volume, value_traded, pct_t_1, pct_t_3, pct_1w, pct_1m, rs_1m_by_exchange, rs_1w_by_exchange, rs_3m_by_exchange, rs_exchange_status, rs_exchange_recommend, pct_3m, pct_6m, pct_1y, ma10, ma20, ma50, ma100, ma200, h1m, h3m, h6m, h1y, l1m, l3m, l6m, l1y, vol_ma10, vol_ma20, gia_sv_ma10, gia_sv_ma20, gia_sv_ma50, gia_sv_ma100, gia_sv_ma200, vol_sv_vol_ma10, vol_sv_vol_ma20, macd, macd_signal, rsi, xay_nen, xay_nen_count_30, nen_type, pha_nen, tang_gia_kem_vol, smart_money, created_at FROM {$wpdb->prefix}lcni_ohlc ORDER BY event_time DESC LIMIT 50", ARRAY_A);
        $symbol_rows = $wpdb->get_results("SELECT s.symbol, s.market_id, m.exchange, s.id_icb2, i.name_icb2, s.board_id, s.isin, s.basic_price, s.ceiling_price, s.floor_price, s.security_status, s.source, s.updated_at FROM {$wpdb->prefix}lcni_symbols s LEFT JOIN {$wpdb->prefix}lcni_marketid m ON m.market_id = s.market_id LEFT JOIN {$wpdb->prefix}lcni_icb2 i ON i.id_icb2 = s.id_icb2 ORDER BY s.updated_at DESC LIMIT 50", ARRAY_A);
        $market_rows = $wpdb->get_results("SELECT market_id, exchange, updated_at FROM {$wpdb->prefix}lcni_marketid ORDER BY CAST(market_id AS UNSIGNED), market_id", ARRAY_A);
        $icb2_rows = $wpdb->get_results("SELECT id_icb2, name_icb2, updated_at FROM {$wpdb->prefix}lcni_icb2 ORDER BY id_icb2 ASC", ARRAY_A);
        $mapping_rows = $wpdb->get_results("SELECT map.symbol, map.market_id, map.exchange, map.id_icb2, i.name_icb2, map.updated_at FROM {$wpdb->prefix}lcni_sym_icb_market map LEFT JOIN {$wpdb->prefix}lcni_icb2 i ON i.id_icb2 = map.id_icb2 ORDER BY map.updated_at DESC LIMIT 50", ARRAY_A);
        $active_tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'lcni-tab-symbols';
        ?>
        <div class="wrap">
            <h1>Saved Data</h1>
            <p>Trang này hiển thị dữ liệu đã lưu từ API/CSV (50 bản ghi gần nhất mỗi bảng).</p>

            <style>
                .lcni-tab-nav { display: flex; gap: 8px; border-bottom: 1px solid #dcdcde; margin-top: 20px; flex-wrap: wrap; }
                .lcni-tab-nav button { border: 1px solid #dcdcde; border-bottom: 0; background: #f6f7f7; padding: 8px 12px; cursor: pointer; }
                .lcni-tab-nav button.active { background: #fff; font-weight: 600; }
                .lcni-tab-content { display: none; margin-top: 16px; }
                .lcni-tab-content.active { display: block; }
                .lcni-column-picker { border: 1px solid #dcdcde; padding: 10px; margin-bottom: 10px; max-width: 980px; background: #fff; }
                .lcni-column-picker-actions { display: flex; gap: 8px; flex-wrap: wrap; margin: 8px 0; }
                .lcni-column-picker-list { display: grid; gap: 6px; grid-template-columns: repeat(3, minmax(0, 1fr)); max-height: 240px; overflow: auto; }
                .lcni-column-picker-item { font-size: 12px; }
                #lcni-ohlc-table th, #lcni-ohlc-table td { font-size: 11px; padding: 6px 8px; line-height: 1.3; white-space: nowrap; }
            </style>

            <div class="lcni-tab-nav" id="lcni-saved-data-tabs" data-active-tab="<?php echo esc_attr($active_tab); ?>">
                <button data-tab="lcni-tab-symbols">LCNI Symbols</button>
                <button data-tab="lcni-tab-market">LCNI Market Mapping</button>
                <button data-tab="lcni-tab-icb2">LCNI ICB2</button>
                <button data-tab="lcni-tab-sym-icb-market">LCNI Symbol-Market-ICB</button>
                <button data-tab="lcni-tab-ohlc">OHLC Data + Indicators</button>
            </div>

            <div id="lcni-tab-symbols" class="lcni-tab-content">
                <?php if (!empty($symbol_rows)) : ?>
                    <table class="widefat striped" style="max-width: 1500px;"><thead><tr><th>Symbol</th><th>Market ID</th><th>Exchange</th><th>ID ICB2</th><th>Tên ngành</th><th>Board</th><th>ISIN</th><th>Basic</th><th>Ceiling</th><th>Floor</th><th>Status</th><th>Source</th><th>Updated At</th></tr></thead><tbody>
                        <?php foreach ($symbol_rows as $row) : ?><tr><td><?php echo esc_html($row['symbol']); ?></td><td><?php echo esc_html($row['market_id']); ?></td><td><?php echo esc_html($row['exchange'] ?: 'N/A'); ?></td><td><?php echo esc_html($row['id_icb2']); ?></td><td><?php echo esc_html($row['name_icb2'] ?: 'N/A'); ?></td><td><?php echo esc_html($row['board_id']); ?></td><td><?php echo esc_html($row['isin']); ?></td><td><?php echo esc_html($row['basic_price']); ?></td><td><?php echo esc_html($row['ceiling_price']); ?></td><td><?php echo esc_html($row['floor_price']); ?></td><td><?php echo esc_html($row['security_status']); ?></td><td><?php echo esc_html($row['source']); ?></td><td><?php echo esc_html($row['updated_at']); ?></td></tr><?php endforeach; ?>
                    </tbody></table>
                <?php else : ?><p>Chưa có dữ liệu lcni_symbols.</p><?php endif; ?>
            </div>

            <div id="lcni-tab-market" class="lcni-tab-content">
                <?php if (!empty($market_rows)) : ?>
                    <table class="widefat striped" style="max-width: 700px;"><thead><tr><th>Market ID</th><th>Exchange</th><th>Updated At</th></tr></thead><tbody>
                        <?php foreach ($market_rows as $row) : ?><tr><td><?php echo esc_html($row['market_id']); ?></td><td><?php echo esc_html($row['exchange']); ?></td><td><?php echo esc_html($row['updated_at']); ?></td></tr><?php endforeach; ?>
                    </tbody></table>
                <?php else : ?><p>Chưa có dữ liệu lcni_marketid.</p><?php endif; ?>
            </div>

            <div id="lcni-tab-icb2" class="lcni-tab-content">
                <?php if (!empty($icb2_rows)) : ?>
                    <table class="widefat striped" style="max-width: 900px;"><thead><tr><th>ID ICB2</th><th>Tên ngành</th><th>Updated At</th></tr></thead><tbody>
                        <?php foreach ($icb2_rows as $row) : ?><tr><td><?php echo esc_html($row['id_icb2']); ?></td><td><?php echo esc_html($row['name_icb2']); ?></td><td><?php echo esc_html($row['updated_at']); ?></td></tr><?php endforeach; ?>
                    </tbody></table>
                <?php else : ?><p>Chưa có dữ liệu lcni_icb2.</p><?php endif; ?>
            </div>

            <div id="lcni-tab-sym-icb-market" class="lcni-tab-content">
                <?php if (!empty($mapping_rows)) : ?>
                    <table class="widefat striped" style="max-width: 1000px;"><thead><tr><th>Symbol</th><th>Market ID</th><th>Exchange</th><th>ID ICB2</th><th>Tên ngành</th><th>Updated At</th></tr></thead><tbody>
                        <?php foreach ($mapping_rows as $row) : ?><tr><td><?php echo esc_html($row['symbol']); ?></td><td><?php echo esc_html($row['market_id']); ?></td><td><?php echo esc_html($row['exchange'] ?: 'N/A'); ?></td><td><?php echo esc_html($row['id_icb2']); ?></td><td><?php echo esc_html($row['name_icb2'] ?: 'N/A'); ?></td><td><?php echo esc_html($row['updated_at']); ?></td></tr><?php endforeach; ?>
                    </tbody></table>
                <?php else : ?><p>Chưa có dữ liệu lcni_sym_icb_market.</p><?php endif; ?>
            </div>

            <div id="lcni-tab-ohlc" class="lcni-tab-content">
                <?php if (!empty($ohlc_rows)) : ?>
                    <div class="lcni-column-picker">
                        <label for="lcni-ohlc-column-filter"><strong>Lọc cột hiển thị:</strong></label>
                        <input type="text" id="lcni-ohlc-column-filter" class="regular-text" placeholder="Nhập tên cột để lọc danh sách cột" style="margin:6px 0;display:block;">
                        <div class="lcni-column-picker-actions">
                            <button type="button" class="button" id="lcni-ohlc-select-all">Chọn tất cả</button>
                            <button type="button" class="button" id="lcni-ohlc-clear-all">Bỏ chọn tất cả</button>
                            <button type="button" class="button" id="lcni-ohlc-select-rule">Chọn nhanh cột Rule</button>
                            <button type="button" class="button" id="lcni-ohlc-reset-filter">Hiện lại danh sách</button>
                        </div>
                        <div id="lcni-ohlc-column-picker" class="lcni-column-picker-list">
                            <?php foreach ($ohlc_columns as $column_key => $column_label) : ?>
                                <?php $default_checked = in_array($column_key, ['symbol', 'timeframe', 'event_time', 'close_price', 'volume', 'macd', 'rsi', 'xay_nen', 'xay_nen_count_30', 'nen_type', 'pha_nen', 'tang_gia_kem_vol', 'created_at'], true); ?>
                                <label class="lcni-column-picker-item" data-column-key="<?php echo esc_attr($column_key); ?>" data-column-label="<?php echo esc_attr(strtolower($column_label)); ?>">
                                    <input type="checkbox" data-column-toggle="<?php echo esc_attr($column_key); ?>" <?php checked($default_checked); ?>>
                                    <?php echo esc_html($column_label); ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div style="overflow-x:auto; max-width: 100%;">
                        <table class="widefat striped" style="min-width: 2800px;" id="lcni-ohlc-table"><thead><tr>
                            <?php foreach ($ohlc_columns as $column_key => $column_label) : ?>
                                <th data-col="<?php echo esc_attr($column_key); ?>"><?php echo esc_html($column_label); ?></th>
                            <?php endforeach; ?>
                        </tr></thead><tbody>
                            <?php foreach ($ohlc_rows as $row) : ?>
                                <tr>
                                    <?php foreach ($ohlc_columns as $column_key => $column_label) : ?>
                                        <td data-col="<?php echo esc_attr($column_key); ?>">
                                            <?php
                                            $value = isset($row[$column_key]) ? $row[$column_key] : '';
                                            if ($column_key === 'event_time' && $value !== '') {
                                                $value = gmdate('Y-m-d H:i:s', (int) $value);
                                            }
                                            echo esc_html((string) $value);
                                            ?>
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody></table>
                    </div>
                <?php else : ?><p>Chưa có dữ liệu OHLC.</p><?php endif; ?>
            </div>

            <script>
                (function() {
                    const nav = document.getElementById('lcni-saved-data-tabs');
                    if (!nav) {
                        return;
                    }

                    const buttons = nav.querySelectorAll('button[data-tab]');
                    const panes = document.querySelectorAll('.lcni-tab-content');
                    const activeTabFromUrl = nav.getAttribute('data-active-tab') || 'lcni-tab-symbols';

                    const activateTab = function(tabId) {
                        buttons.forEach((btn) => btn.classList.toggle('active', btn.getAttribute('data-tab') === tabId));
                        panes.forEach((pane) => pane.classList.toggle('active', pane.id === tabId));
                    };

                    buttons.forEach((button) => {
                        button.addEventListener('click', () => activateTab(button.getAttribute('data-tab')));
                    });

                    activateTab(activeTabFromUrl);

                    const filterInput = document.getElementById('lcni-ohlc-column-filter');
                    const picker = document.getElementById('lcni-ohlc-column-picker');
                    const table = document.getElementById('lcni-ohlc-table');
                    const selectAllBtn = document.getElementById('lcni-ohlc-select-all');
                    const clearAllBtn = document.getElementById('lcni-ohlc-clear-all');
                    const selectRuleBtn = document.getElementById('lcni-ohlc-select-rule');
                    const resetFilterBtn = document.getElementById('lcni-ohlc-reset-filter');

                    if (filterInput && picker && table) {
                        const ruleColumns = ['xay_nen', 'xay_nen_count_30', 'nen_type', 'pha_nen', 'tang_gia_kem_vol', 'smart_money', 'rs_exchange_status', 'rs_exchange_recommend', 'macd', 'rsi', 'symbol', 'timeframe', 'event_time', 'close_price', 'volume'];
                        const checkboxes = Array.from(picker.querySelectorAll('input[data-column-toggle]'));
                        const storageKey = 'lcni_ohlc_visible_columns';

                        const tableCells = Array.from(table.querySelectorAll('[data-col]'));

                        const toggleColumn = function(columnKey, isVisible) {
                            tableCells.forEach((cell) => {
                                if (cell.getAttribute('data-col') !== columnKey) {
                                    return;
                                }

                                if (isVisible) {
                                    cell.hidden = false;
                                    cell.style.display = '';
                                } else {
                                    cell.hidden = true;
                                    cell.style.display = 'none';
                                }
                            });
                        };

                        const saveSelection = function() {
                            const selected = checkboxes
                                .filter((checkbox) => checkbox.checked)
                                .map((checkbox) => checkbox.getAttribute('data-column-toggle'));
                            window.localStorage.setItem(storageKey, JSON.stringify(selected));
                        };

                        const applyColumnSelection = function() {
                            checkboxes.forEach((checkbox) => {
                                toggleColumn(checkbox.getAttribute('data-column-toggle'), checkbox.checked);
                            });
                        };

                        const setSelectionForAll = function(isChecked) {
                            checkboxes.forEach((checkbox) => {
                                checkbox.checked = isChecked;
                            });
                            applyColumnSelection();
                            saveSelection();
                        };

                        const hydrateSelectionFromStorage = function() {
                            const savedRaw = window.localStorage.getItem(storageKey);
                            if (!savedRaw) {
                                return;
                            }

                            let savedColumns = [];
                            try {
                                savedColumns = JSON.parse(savedRaw);
                            } catch (error) {
                                return;
                            }

                            if (!Array.isArray(savedColumns) || savedColumns.length === 0) {
                                return;
                            }

                            checkboxes.forEach((checkbox) => {
                                checkbox.checked = savedColumns.includes(checkbox.getAttribute('data-column-toggle'));
                            });
                        };

                        checkboxes.forEach((checkbox) => {
                            checkbox.addEventListener('change', () => {
                                toggleColumn(checkbox.getAttribute('data-column-toggle'), checkbox.checked);
                                saveSelection();
                            });
                        });

                        hydrateSelectionFromStorage();
                        applyColumnSelection();

                        if (selectAllBtn) {
                            selectAllBtn.addEventListener('click', () => setSelectionForAll(true));
                        }

                        if (clearAllBtn) {
                            clearAllBtn.addEventListener('click', () => setSelectionForAll(false));
                        }

                        if (selectRuleBtn) {
                            selectRuleBtn.addEventListener('click', () => {
                                checkboxes.forEach((checkbox) => {
                                    checkbox.checked = ruleColumns.includes(checkbox.getAttribute('data-column-toggle'));
                                });
                                applyColumnSelection();
                                saveSelection();
                            });
                        }

                        const applyFilter = function(needle) {
                            picker.querySelectorAll('.lcni-column-picker-item').forEach((item) => {
                                const key = item.getAttribute('data-column-key') || '';
                                const label = item.getAttribute('data-column-label') || '';
                                const isMatch = needle === '' || key.includes(needle) || label.includes(needle);
                                item.style.display = isMatch ? '' : 'none';
                            });
                        };

                        filterInput.addEventListener('input', () => {
                            applyFilter(filterInput.value.trim().toLowerCase());
                        });

                        if (resetFilterBtn) {
                            resetFilterBtn.addEventListener('click', () => {
                                filterInput.value = '';
                                applyFilter('');
                            });
                        }
                    }

                })();
            </script>
        </div>
        <?php
    }

    public function sanitize_frontend_module_settings($value) {
        if (!is_array($value)) {
            $value = [];
        }

        $default = $this->get_default_frontend_module_settings();
        $allowed_fields = isset($value['allowed_fields']) && is_array($value['allowed_fields'])
            ? array_values(array_intersect($default['fields'], array_map('sanitize_key', $value['allowed_fields'])))
            : $default['fields'];

        if (empty($allowed_fields)) {
            $allowed_fields = $default['fields'];
        }

        $styles = isset($value['styles']) && is_array($value['styles']) ? $value['styles'] : [];

        return [
            'allowed_fields' => $allowed_fields,
            'styles' => [
                'label_color' => sanitize_hex_color((string) ($styles['label_color'] ?? $default['styles']['label_color'])) ?: $default['styles']['label_color'],
                'value_color' => sanitize_hex_color((string) ($styles['value_color'] ?? $default['styles']['value_color'])) ?: $default['styles']['value_color'],
                'item_background' => sanitize_hex_color((string) ($styles['item_background'] ?? $default['styles']['item_background'])) ?: $default['styles']['item_background'],
                'container_background' => sanitize_hex_color((string) ($styles['container_background'] ?? $default['styles']['container_background'])) ?: $default['styles']['container_background'],
                'container_border' => sanitize_hex_color((string) ($styles['container_border'] ?? $default['styles']['container_border'])) ?: $default['styles']['container_border'],
                'item_height' => $this->sanitize_frontend_item_height($styles['item_height'] ?? $default['styles']['item_height'], $default['styles']['item_height']),
                'label_font_size' => $this->sanitize_frontend_font_size($styles['label_font_size'] ?? $default['styles']['label_font_size'], $default['styles']['label_font_size']),
                'value_font_size' => $this->sanitize_frontend_font_size($styles['value_font_size'] ?? $default['styles']['value_font_size'], $default['styles']['value_font_size']),
                'value_rules' => $this->sanitize_frontend_value_rules($styles['value_rules'] ?? [], $default['fields']),
            ],
        ];
    }


    public function sanitize_frontend_chart_settings($value) {
        if (!is_array($value)) {
            $value = [];
        }

        $default = [
            'default_mode' => 'line',
            'allowed_panels' => ['volume', 'macd', 'rsi', 'rs'],
            'compact_mode' => true,
        ];

        $allowed_panels = isset($value['allowed_panels']) && is_array($value['allowed_panels'])
            ? array_values(array_intersect($default['allowed_panels'], array_map('sanitize_key', $value['allowed_panels'])))
            : $default['allowed_panels'];

        if (empty($allowed_panels)) {
            $allowed_panels = $default['allowed_panels'];
        }

        $mode = sanitize_key((string) ($value['default_mode'] ?? $default['default_mode']));
        if (!in_array($mode, ['line', 'candlestick'], true)) {
            $mode = $default['default_mode'];
        }

        $compact_raw = $value['compact_mode'] ?? $default['compact_mode'];
        $compact_mode = in_array($compact_raw, [1, '1', true, 'true', 'yes', 'on'], true);

        return [
            'default_mode' => $mode,
            'allowed_panels' => $allowed_panels,
            'compact_mode' => $compact_mode,
        ];
    }

    private function sanitize_frontend_font_size($size, $fallback) {
        $value = (int) $size;

        return $value >= 10 && $value <= 40 ? $value : (int) $fallback;
    }

    private function sanitize_frontend_item_height($height, $fallback) {
        $value = (int) $height;

        return $value >= 40 && $value <= 300 ? $value : (int) $fallback;
    }

    private function sanitize_frontend_value_rules($rules, $allowed_fields) {
        if (!is_array($rules)) {
            return [];
        }

        $operators = ['equals', 'contains', 'gt', 'gte', 'lt', 'lte'];
        $normalized = [];

        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                continue;
            }

            $field = sanitize_key((string) ($rule['field'] ?? ''));
            $operator = sanitize_key((string) ($rule['operator'] ?? ''));
            $value = sanitize_text_field((string) ($rule['value'] ?? ''));
            $color = sanitize_hex_color((string) ($rule['color'] ?? ''));

            if ($field === '' || $field === 'all') {
                $field = '*';
            }

            if ($field !== '*' && !in_array($field, $allowed_fields, true)) {
                continue;
            }

            if (!in_array($operator, $operators, true) || $value === '' || !$color) {
                continue;
            }

            $normalized[] = [
                'field' => $field,
                'operator' => $operator,
                'value' => $value,
                'color' => $color,
            ];
        }

        return array_slice($normalized, 0, 50);
    }

    private function get_default_frontend_module_settings() {
        return [
            'fields' => ['xay_nen', 'xay_nen_count_30', 'nen_type', 'pha_nen', 'tang_gia_kem_vol', 'smart_money', 'rs_exchange_status', 'rs_exchange_recommend', 'rs_recommend_status', 'symbol', 'exchange', 'icb2_name', 'eps', 'eps_1y_pct', 'dt_1y_pct', 'bien_ln_gop', 'bien_ln_rong', 'roe', 'de_ratio', 'pe_ratio', 'pb_ratio', 'ev_ebitda', 'tcbs_khuyen_nghi', 'co_tuc_pct', 'tc_rating', 'so_huu_nn_pct', 'tien_mat_rong_von_hoa', 'tien_mat_rong_tong_tai_san', 'loi_nhuan_4_quy_gan_nhat', 'tang_truong_dt_quy_gan_nhat', 'tang_truong_dt_quy_gan_nhi', 'tang_truong_ln_quy_gan_nhat', 'tang_truong_ln_quy_gan_nhi'],
            'styles' => [
                'label_color' => '#4b5563',
                'value_color' => '#111827',
                'item_background' => '#f9fafb',
                'container_background' => '#ffffff',
                'container_border' => '#e5e7eb',
                'item_height' => 56,
                'label_font_size' => 12,
                'value_font_size' => 14,
                'value_rules' => [],
            ],
        ];
    }

    private function render_frontend_settings_section() {
        $signals_labels = [
            'xay_nen' => 'Nền giá',
            'xay_nen_count_30' => 'Số phiên đi nền trong 30 phiên',
            'nen_type' => 'Dạng nền',
            'pha_nen' => 'Tín hiệu phá nền',
            'tang_gia_kem_vol' => 'Tăng giá kèm Vol',
            'smart_money' => 'Tín hiệu smart',
            'rs_exchange_status' => 'Trạng thái sức mạnh giá',
            'rs_exchange_recommend' => 'Gợi ý sức mạnh giá',
            'rs_recommend_status' => 'Gợi ý trạng thái sức mạnh giá',
        ];
        $overview_labels = [
            'symbol' => 'Mã', 'exchange' => 'Sàn', 'icb2_name' => 'Ngành ICB 2', 'eps' => 'EPS', 'eps_1y_pct' => '% EPS 1 năm', 'dt_1y_pct' => '% DT 1 năm', 'bien_ln_gop' => 'Biên LN gộp', 'bien_ln_rong' => 'Biên LN ròng', 'roe' => 'ROE', 'de_ratio' => 'D/E', 'pe_ratio' => 'P/E', 'pb_ratio' => 'P/B', 'ev_ebitda' => 'EV/EBITDA', 'tcbs_khuyen_nghi' => 'TCBS khuyến nghị', 'co_tuc_pct' => '% Cổ tức', 'tc_rating' => 'TC Rating', 'so_huu_nn_pct' => '% Sở hữu NN', 'tien_mat_rong_von_hoa' => 'Tiền mặt ròng/Vốn hóa', 'tien_mat_rong_tong_tai_san' => 'Tiền mặt ròng/Tổng tài sản', 'loi_nhuan_4_quy_gan_nhat' => 'Lợi nhuận 4 quý gần nhất', 'tang_truong_dt_quy_gan_nhat' => 'Tăng trưởng DT quý gần nhất', 'tang_truong_dt_quy_gan_nhi' => 'Tăng trưởng DT quý gần nhì', 'tang_truong_ln_quy_gan_nhat' => 'Tăng trưởng LN quý gần nhất', 'tang_truong_ln_quy_gan_nhi' => 'Tăng trưởng LN quý gần nhì'
        ];
        $signals = $this->sanitize_frontend_module_settings(get_option('lcni_frontend_settings_signals', ['allowed_fields' => array_keys($signals_labels)]));
        $overview = $this->sanitize_frontend_module_settings(get_option('lcni_frontend_settings_overview', ['allowed_fields' => array_keys($overview_labels)]));
        $chart = $this->sanitize_frontend_chart_settings(get_option('lcni_frontend_settings_chart', []));
        ?>
        <style>
            .lcni-sub-tab-nav { display: flex; gap: 8px; flex-wrap: wrap; margin: 12px 0; border-bottom: 1px solid #dcdcde; }
            .lcni-sub-tab-nav button { border: 1px solid #dcdcde; border-bottom: 0; background: #f6f7f7; padding: 6px 10px; cursor: pointer; }
            .lcni-sub-tab-nav button.active { background: #fff; font-weight: 600; }
            .lcni-sub-tab-content { display: none; }
            .lcni-sub-tab-content.active { display: block; }
            .lcni-front-form { max-width: 980px; background:#fff; border:1px solid #dcdcde; padding:12px; }
            .lcni-front-grid { display:grid; grid-template-columns: repeat(auto-fit, minmax(240px,1fr)); gap:8px 16px; margin:12px 0;}
        </style>
        <p>Cấu hình hiển thị frontend cho từng shortcode module.</p>
        <div class="lcni-sub-tab-nav" id="lcni-front-sub-tabs">
            <button type="button" data-sub-tab="lcni-tab-frontend-signals">LCNi Signals</button>
            <button type="button" data-sub-tab="lcni-tab-frontend-overview">Stock Overview</button>
            <button type="button" data-sub-tab="lcni-tab-frontend-chart">Stock Chart</button>
        </div>
        <?php $this->render_frontend_module_form('signals', 'lcni-tab-frontend-signals', $signals_labels, $signals); ?>
        <?php $this->render_frontend_module_form('overview', 'lcni-tab-frontend-overview', $overview_labels, $overview); ?>
        <?php $this->render_frontend_chart_form('chart', 'lcni-tab-frontend-chart', $chart); ?>
        <script>
            (function() {
                const nav = document.getElementById('lcni-front-sub-tabs');
                if (!nav) { return; }
                const buttons = nav.querySelectorAll('button[data-sub-tab]');
                const panes = document.querySelectorAll('.lcni-sub-tab-content');
                const current = (new URLSearchParams(window.location.search).get('tab')) || 'lcni-tab-frontend-signals';
                const activate = function(tabId){
                    buttons.forEach((btn) => btn.classList.toggle('active', btn.getAttribute('data-sub-tab') === tabId));
                    panes.forEach((pane) => {
                        if (pane.id === 'lcni-tab-frontend-signals' || pane.id === 'lcni-tab-frontend-overview' || pane.id === 'lcni-tab-frontend-chart') {
                            pane.classList.toggle('active', pane.id === tabId);
                        }
                    });
                };
                buttons.forEach((btn) => btn.addEventListener('click', () => activate(btn.getAttribute('data-sub-tab'))));
                const validTabs = ['lcni-tab-frontend-signals', 'lcni-tab-frontend-overview', 'lcni-tab-frontend-chart'];
                activate(validTabs.includes(current) ? current : 'lcni-tab-frontend-signals');
            })();
        </script>
        <?php
    }

    private function render_frontend_module_form($module, $tab_id, $labels, $settings) {
        $value_rules = isset($settings['styles']['value_rules']) && is_array($settings['styles']['value_rules']) ? $settings['styles']['value_rules'] : [];
        $rule_rows = max(5, count($value_rules) + 1);
        ?>
        <div id="<?php echo esc_attr($tab_id); ?>" class="lcni-sub-tab-content">
            <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=lcni-settings')); ?>" class="lcni-front-form">
                <?php wp_nonce_field('lcni_admin_actions', 'lcni_action_nonce'); ?>
                <input type="hidden" name="lcni_redirect_tab" value="<?php echo esc_attr($tab_id); ?>">
                <input type="hidden" name="lcni_admin_action" value="save_frontend_settings">
                <input type="hidden" name="lcni_frontend_module" value="<?php echo esc_attr($module); ?>">
                <h3><?php echo esc_html($module === 'signals' ? 'LCNi Signals' : 'Stock Overview'); ?></h3>
                <p>Chọn chỉ báo được phép hiển thị để user frontend tùy chọn yêu thích.</p>
                <div class="lcni-front-grid">
                    <?php foreach ($labels as $key => $label) : ?>
                        <label><input type="checkbox" name="lcni_frontend_allowed_fields[]" value="<?php echo esc_attr($key); ?>" <?php checked(in_array($key, (array) ($settings['allowed_fields'] ?? []), true)); ?>> <?php echo esc_html($label); ?></label>
                    <?php endforeach; ?>
                </div>
                <table class="form-table" role="presentation"><tbody>
                    <tr><th>Màu label</th><td><input type="color" name="lcni_frontend_style_label_color" value="<?php echo esc_attr((string) ($settings['styles']['label_color'] ?? '#4b5563')); ?>"></td></tr>
                    <tr><th>Màu value</th><td><input type="color" name="lcni_frontend_style_value_color" value="<?php echo esc_attr((string) ($settings['styles']['value_color'] ?? '#111827')); ?>"></td></tr>
                    <tr><th>Màu nền item</th><td><input type="color" name="lcni_frontend_style_item_background" value="<?php echo esc_attr((string) ($settings['styles']['item_background'] ?? '#f9fafb')); ?>"></td></tr>
                    <tr><th>Màu nền box lớn</th><td><input type="color" name="lcni_frontend_style_container_background" value="<?php echo esc_attr((string) ($settings['styles']['container_background'] ?? '#ffffff')); ?>"></td></tr>
                    <tr><th>Màu viền box lớn</th><td><input type="color" name="lcni_frontend_style_container_border" value="<?php echo esc_attr((string) ($settings['styles']['container_border'] ?? '#e5e7eb')); ?>"></td></tr>
                    <tr><th>Chiều cao box</th><td><input type="number" min="40" max="300" name="lcni_frontend_style_item_height" value="<?php echo esc_attr((string) ($settings['styles']['item_height'] ?? 56)); ?>"> px</td></tr>
                    <tr><th>Cỡ chữ label</th><td><input type="number" min="10" max="40" name="lcni_frontend_style_label_font_size" value="<?php echo esc_attr((string) ($settings['styles']['label_font_size'] ?? 12)); ?>"> px</td></tr>
                    <tr><th>Cỡ chữ value</th><td><input type="number" min="10" max="40" name="lcni_frontend_style_value_font_size" value="<?php echo esc_attr((string) ($settings['styles']['value_font_size'] ?? 14)); ?>"> px</td></tr>
                    <tr>
                        <th>Rule màu theo value</th>
                        <td>
                            <p class="description">Thiết lập màu value theo điều kiện. Chọn "Tất cả fields" để áp dụng cho mọi field.</p>
                            <table style="border-collapse:collapse; width:100%; max-width:760px;">
                                <thead>
                                    <tr>
                                        <th style="text-align:left; padding:4px;">Field</th>
                                        <th style="text-align:left; padding:4px;">Điều kiện</th>
                                        <th style="text-align:left; padding:4px;">Giá trị so sánh</th>
                                        <th style="text-align:left; padding:4px;">Màu</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php for ($i = 0; $i < $rule_rows; $i++) :
                                        $rule = $value_rules[$i] ?? [];
                                        $rule_field = (string) ($rule['field'] ?? '*');
                                        $rule_operator = (string) ($rule['operator'] ?? 'equals');
                                        $rule_value = (string) ($rule['value'] ?? '');
                                        $rule_color = (string) ($rule['color'] ?? '#111827');
                                        ?>
                                        <tr>
                                            <td style="padding:4px;">
                                                <select name="lcni_frontend_rule_field[]">
                                                    <option value="*" <?php selected($rule_field, '*'); ?>>Tất cả fields</option>
                                                    <?php foreach ($labels as $field_key => $field_label) : ?>
                                                        <option value="<?php echo esc_attr($field_key); ?>" <?php selected($rule_field, $field_key); ?>><?php echo esc_html($field_label); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </td>
                                            <td style="padding:4px;">
                                                <select name="lcni_frontend_rule_operator[]">
                                                    <option value="equals" <?php selected($rule_operator, 'equals'); ?>>Bằng (=)</option>
                                                    <option value="contains" <?php selected($rule_operator, 'contains'); ?>>Chứa</option>
                                                    <option value="gt" <?php selected($rule_operator, 'gt'); ?>>Lớn hơn (&gt;)</option>
                                                    <option value="gte" <?php selected($rule_operator, 'gte'); ?>>Lớn hơn hoặc bằng (&ge;)</option>
                                                    <option value="lt" <?php selected($rule_operator, 'lt'); ?>>Nhỏ hơn (&lt;)</option>
                                                    <option value="lte" <?php selected($rule_operator, 'lte'); ?>>Nhỏ hơn hoặc bằng (&le;)</option>
                                                </select>
                                            </td>
                                            <td style="padding:4px;"><input type="text" name="lcni_frontend_rule_value[]" value="<?php echo esc_attr($rule_value); ?>" placeholder="Ví dụ: 0, MUA, breakout"></td>
                                            <td style="padding:4px;"><input type="color" name="lcni_frontend_rule_color[]" value="<?php echo esc_attr($rule_color); ?>"></td>
                                        </tr>
                                    <?php endfor; ?>
                                </tbody>
                            </table>
                        </td>
                    </tr>
                </tbody></table>
                <?php submit_button('Lưu Frontend Settings'); ?>
            </form>
        </div>
        <?php
    }


    private function render_frontend_chart_form($module, $tab_id, $settings) {
        $allowed_panels = (array) ($settings['allowed_panels'] ?? []);
        $default_mode = (string) ($settings['default_mode'] ?? 'line');
        $compact_mode = !empty($settings['compact_mode']);
        $panel_labels = [
            'volume' => 'Volume',
            'macd' => 'MACD',
            'rsi' => 'RSI',
            'rs' => 'RS by LCNi',
        ];
        ?>
        <div id="<?php echo esc_attr($tab_id); ?>" class="lcni-sub-tab-content">
            <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=lcni-settings')); ?>" class="lcni-front-form">
                <?php wp_nonce_field('lcni_admin_actions', 'lcni_action_nonce'); ?>
                <input type="hidden" name="lcni_redirect_tab" value="<?php echo esc_attr($tab_id); ?>">
                <input type="hidden" name="lcni_admin_action" value="save_frontend_settings">
                <input type="hidden" name="lcni_frontend_module" value="<?php echo esc_attr($module); ?>">
                <h3>Stock Chart</h3>
                <p>Chọn panel cho phép user bật/tắt, kiểu chart mặc định và chế độ hiển thị gọn.</p>
                <div class="lcni-front-grid">
                    <?php foreach ($panel_labels as $key => $label) : ?>
                        <label><input type="checkbox" name="lcni_frontend_allowed_panels[]" value="<?php echo esc_attr($key); ?>" <?php checked(in_array($key, $allowed_panels, true)); ?>> <?php echo esc_html($label); ?></label>
                    <?php endforeach; ?>
                </div>
                <table class="form-table" role="presentation"><tbody>
                    <tr>
                        <th>Kiểu chart mặc định</th>
                        <td>
                            <select name="lcni_frontend_default_mode">
                                <option value="line" <?php selected($default_mode, 'line'); ?>>Line</option>
                                <option value="candlestick" <?php selected($default_mode, 'candlestick'); ?>>Candlestick</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th>Compact mode</th>
                        <td><label><input type="checkbox" name="lcni_frontend_compact_mode" value="1" <?php checked($compact_mode); ?>> Bật chế độ gọn (đưa controls vào vùng chart)</label></td>
                    </tr>
                </tbody></table>
                <?php submit_button('Lưu Frontend Settings'); ?>
            </form>
        </div>
        <?php
    }

    private function render_rule_settings_section($rule_settings, $redirect_page = 'lcni-settings') {
        $rule_rebuild_status = LCNI_DB::get_rule_rebuild_status();
        ?>
        <style>
            .lcni-sub-tab-nav { display: flex; gap: 8px; flex-wrap: wrap; margin: 12px 0; border-bottom: 1px solid #dcdcde; }
            .lcni-sub-tab-nav button { border: 1px solid #dcdcde; border-bottom: 0; background: #f6f7f7; padding: 6px 10px; cursor: pointer; }
            .lcni-sub-tab-nav button.active { background: #fff; font-weight: 600; }
            .lcni-sub-tab-content { display: none; }
            .lcni-sub-tab-content.active { display: block; }
            .lcni-rule-form { max-width: 980px; background:#fff; border:1px solid #dcdcde; padding:12px; }
            .lcni-rule-form .description { margin-top: 0; }
            .lcni-rule-progress { max-width: 980px; border:1px solid #dcdcde; background:#fff; padding:12px; margin: 10px 0 16px; }
            .lcni-rule-progress-track { position: relative; width: 100%; height: 16px; border-radius: 999px; background: #f0f0f1; overflow: hidden; }
            .lcni-rule-progress-fill { position: absolute; top: 0; left: 0; height: 100%; background: #2271b1; transition: width 0.4s ease; }
            .lcni-rule-progress-text { margin-top: 8px; font-size: 12px; color: #1d2327; }
        </style>
        <p>Tùy chỉnh công thức theo từng cột để dễ hiểu, dễ thực thi và hạn chế xung đột giữa các rule.</p>
        <div class="lcni-rule-progress" id="lcni-rule-progress-wrapper"
            data-total="<?php echo esc_attr((string) ($rule_rebuild_status['total'] ?? 0)); ?>"
            data-processed="<?php echo esc_attr((string) ($rule_rebuild_status['processed'] ?? 0)); ?>"
            data-status="<?php echo esc_attr((string) ($rule_rebuild_status['status'] ?? 'idle')); ?>"
            data-progress="<?php echo esc_attr((string) ($rule_rebuild_status['progress_percent'] ?? 100)); ?>">
            <strong>Tiến trình thực thi rule nền:</strong>
            <div class="lcni-rule-progress-track">
                <div class="lcni-rule-progress-fill" id="lcni-rule-progress-fill" style="width: <?php echo esc_attr((string) ($rule_rebuild_status['progress_percent'] ?? 100)); ?>%;"></div>
            </div>
            <div class="lcni-rule-progress-text" id="lcni-rule-progress-text">
                <?php echo esc_html(sprintf('%s - %d/%d (%d%%)', strtoupper((string) ($rule_rebuild_status['status'] ?? 'idle')), (int) ($rule_rebuild_status['processed'] ?? 0), (int) ($rule_rebuild_status['total'] ?? 0), (int) ($rule_rebuild_status['progress_percent'] ?? 100))); ?>
            </div>
        </div>
        <div class="lcni-sub-tab-nav" id="lcni-rule-sub-tabs">
            <button type="button" data-sub-tab="lcni-tab-rule-xay-nen">xay_nen</button>
            <button type="button" data-sub-tab="lcni-tab-rule-xay-nen-count-30">xay_nen_count_30</button>
            <button type="button" data-sub-tab="lcni-tab-rule-nen-type">nen_type</button>
            <button type="button" data-sub-tab="lcni-tab-rule-pha-nen">pha_nen</button>
            <button type="button" data-sub-tab="lcni-tab-rule-tang-gia-kem-vol">tang_gia_kem_vol</button>
            <button type="button" data-sub-tab="lcni-tab-rule-rs-exchange">rs_exchange</button>
        </div>

        <div id="lcni-tab-rule-xay-nen" class="lcni-sub-tab-content">
            <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=' . $redirect_page)); ?>" class="lcni-rule-form">
                <?php wp_nonce_field('lcni_admin_actions', 'lcni_action_nonce'); ?>
                <input type="hidden" name="lcni_admin_action" value="save_rule_settings">
                <input type="hidden" name="lcni_rule_execute" value="1">
                <input type="hidden" name="lcni_redirect_page" value="<?php echo esc_attr($redirect_page); ?>">
                <input type="hidden" name="lcni_redirect_tab" value="lcni-tab-rule-xay-nen">
                <p class="description">Thiết lập điều kiện nhận diện cổ phiếu đang xây nền.</p>
                <table class="form-table" role="presentation"><tbody>
                    <tr><th scope="row">RSI min / max</th><td><input type="number" step="0.0001" name="lcni_rule_settings[xay_nen_rsi_min]" value="<?php echo esc_attr((string) $rule_settings['xay_nen_rsi_min']); ?>"> / <input type="number" step="0.0001" name="lcni_rule_settings[xay_nen_rsi_max]" value="<?php echo esc_attr((string) $rule_settings['xay_nen_rsi_max']); ?>"></td></tr>
                    <tr><th scope="row">|Giá/MA10|, |Giá/MA20|, |Giá/MA50| tối đa</th><td><input type="number" step="0.0001" name="lcni_rule_settings[xay_nen_gia_sv_ma10_abs_max]" value="<?php echo esc_attr((string) $rule_settings['xay_nen_gia_sv_ma10_abs_max']); ?>"> / <input type="number" step="0.0001" name="lcni_rule_settings[xay_nen_gia_sv_ma20_abs_max]" value="<?php echo esc_attr((string) $rule_settings['xay_nen_gia_sv_ma20_abs_max']); ?>"> / <input type="number" step="0.0001" name="lcni_rule_settings[xay_nen_gia_sv_ma50_abs_max]" value="<?php echo esc_attr((string) $rule_settings['xay_nen_gia_sv_ma50_abs_max']); ?>"></td></tr>
                    <tr><th scope="row">Vol sv Vol MA20 max / Volume min</th><td><input type="number" step="0.0001" name="lcni_rule_settings[xay_nen_vol_sv_vol_ma20_max]" value="<?php echo esc_attr((string) $rule_settings['xay_nen_vol_sv_vol_ma20_max']); ?>"> / <input type="number" step="1" name="lcni_rule_settings[xay_nen_volume_min]" value="<?php echo esc_attr((string) $rule_settings['xay_nen_volume_min']); ?>"></td></tr>
                    <tr><th scope="row">Biên độ |%T-1|, |%1W|, |%1M|, |%3M| tối đa</th><td><input type="number" step="0.0001" name="lcni_rule_settings[xay_nen_pct_t_1_abs_max]" value="<?php echo esc_attr((string) $rule_settings['xay_nen_pct_t_1_abs_max']); ?>"> / <input type="number" step="0.0001" name="lcni_rule_settings[xay_nen_pct_1w_abs_max]" value="<?php echo esc_attr((string) $rule_settings['xay_nen_pct_1w_abs_max']); ?>"> / <input type="number" step="0.0001" name="lcni_rule_settings[xay_nen_pct_1m_abs_max]" value="<?php echo esc_attr((string) $rule_settings['xay_nen_pct_1m_abs_max']); ?>"> / <input type="number" step="0.0001" name="lcni_rule_settings[xay_nen_pct_3m_abs_max]" value="<?php echo esc_attr((string) $rule_settings['xay_nen_pct_3m_abs_max']); ?>"></td></tr>
                </tbody></table>
                <?php submit_button('Lưu & thực thi rule xay_nen'); ?>
            </form>
        </div>

        <div id="lcni-tab-rule-xay-nen-count-30" class="lcni-sub-tab-content">
            <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=' . $redirect_page)); ?>" class="lcni-rule-form">
                <?php wp_nonce_field('lcni_admin_actions', 'lcni_action_nonce'); ?>
                <input type="hidden" name="lcni_admin_action" value="save_rule_settings">
                <input type="hidden" name="lcni_rule_execute" value="1">
                <input type="hidden" name="lcni_redirect_page" value="<?php echo esc_attr($redirect_page); ?>">
                <input type="hidden" name="lcni_redirect_tab" value="lcni-tab-rule-xay-nen-count-30">
                <p class="description">xay_nen_count_30 được tính tự động từ kết quả xay_nen trong 30 phiên gần nhất. Tab này dùng để thực thi nhanh sau khi chỉnh xay_nen.</p>
                <?php submit_button('Thực thi lại xay_nen_count_30'); ?>
            </form>
        </div>

        <div id="lcni-tab-rule-nen-type" class="lcni-sub-tab-content">
            <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=' . $redirect_page)); ?>" class="lcni-rule-form">
                <?php wp_nonce_field('lcni_admin_actions', 'lcni_action_nonce'); ?>
                <input type="hidden" name="lcni_admin_action" value="save_rule_settings">
                <input type="hidden" name="lcni_rule_execute" value="1">
                <input type="hidden" name="lcni_redirect_page" value="<?php echo esc_attr($redirect_page); ?>">
                <input type="hidden" name="lcni_redirect_tab" value="lcni-tab-rule-nen-type">
                <p class="description">Xếp loại nền theo ngưỡng của xay_nen_count_30.</p>
                <table class="form-table" role="presentation"><tbody>
                    <tr><th scope="row">Ngưỡng Nền chặt / Nền vừa (xay_nen_count_30)</th><td><input type="number" step="1" name="lcni_rule_settings[nen_type_chat_min_count_30]" value="<?php echo esc_attr((string) $rule_settings['nen_type_chat_min_count_30']); ?>"> / <input type="number" step="1" name="lcni_rule_settings[nen_type_vua_min_count_30]" value="<?php echo esc_attr((string) $rule_settings['nen_type_vua_min_count_30']); ?>"></td></tr>
                </tbody></table>
                <?php submit_button('Lưu & thực thi rule nen_type'); ?>
            </form>
        </div>

        <div id="lcni-tab-rule-pha-nen" class="lcni-sub-tab-content">
            <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=' . $redirect_page)); ?>" class="lcni-rule-form">
                <?php wp_nonce_field('lcni_admin_actions', 'lcni_action_nonce'); ?>
                <input type="hidden" name="lcni_admin_action" value="save_rule_settings">
                <input type="hidden" name="lcni_rule_execute" value="1">
                <input type="hidden" name="lcni_redirect_page" value="<?php echo esc_attr($redirect_page); ?>">
                <input type="hidden" name="lcni_redirect_tab" value="lcni-tab-rule-pha-nen">
                <p class="description">Điều kiện xác định phá nền dựa theo biến động giá và thanh khoản.</p>
                <table class="form-table" role="presentation"><tbody>
                    <tr><th scope="row">Điều kiện phá nền (%T-1 min, Vol sv Vol MA20 min)</th><td><input type="number" step="0.0001" name="lcni_rule_settings[pha_nen_pct_t_1_min]" value="<?php echo esc_attr((string) $rule_settings['pha_nen_pct_t_1_min']); ?>"> / <input type="number" step="0.0001" name="lcni_rule_settings[pha_nen_vol_sv_vol_ma20_min]" value="<?php echo esc_attr((string) $rule_settings['pha_nen_vol_sv_vol_ma20_min']); ?>"></td></tr>
                </tbody></table>
                <?php submit_button('Lưu & thực thi rule pha_nen'); ?>
            </form>
        </div>

        <div id="lcni-tab-rule-tang-gia-kem-vol" class="lcni-sub-tab-content">
            <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=' . $redirect_page)); ?>" class="lcni-rule-form">
                <?php wp_nonce_field('lcni_admin_actions', 'lcni_action_nonce'); ?>
                <input type="hidden" name="lcni_admin_action" value="save_rule_settings">
                <input type="hidden" name="lcni_rule_execute" value="1">
                <input type="hidden" name="lcni_redirect_page" value="<?php echo esc_attr($redirect_page); ?>">
                <input type="hidden" name="lcni_redirect_tab" value="lcni-tab-rule-tang-gia-kem-vol">
                <p class="description">JOIN trực tiếp symbol -&gt; exchange từ bảng lcni_sym_icb_market để gắn nhãn “Tăng giá kèm Vol”.</p>
                <table class="form-table" role="presentation"><tbody>
                    <tr><th scope="row">Ngưỡng %T-1 theo sàn (HOSE / HNX / UPCOM)</th><td><input type="number" step="0.0001" name="lcni_rule_settings[tang_gia_kem_vol_hose_pct_t_1_min]" value="<?php echo esc_attr((string) $rule_settings['tang_gia_kem_vol_hose_pct_t_1_min']); ?>"> / <input type="number" step="0.0001" name="lcni_rule_settings[tang_gia_kem_vol_hnx_pct_t_1_min]" value="<?php echo esc_attr((string) $rule_settings['tang_gia_kem_vol_hnx_pct_t_1_min']); ?>"> / <input type="number" step="0.0001" name="lcni_rule_settings[tang_gia_kem_vol_upcom_pct_t_1_min]" value="<?php echo esc_attr((string) $rule_settings['tang_gia_kem_vol_upcom_pct_t_1_min']); ?>"></td></tr>
                    <tr><th scope="row">Ngưỡng Vol ratio (Vol/VolMA10, Vol/VolMA20)</th><td><input type="number" step="0.0001" name="lcni_rule_settings[tang_gia_kem_vol_vol_ratio_ma10_min]" value="<?php echo esc_attr((string) $rule_settings['tang_gia_kem_vol_vol_ratio_ma10_min']); ?>"> / <input type="number" step="0.0001" name="lcni_rule_settings[tang_gia_kem_vol_vol_ratio_ma20_min]" value="<?php echo esc_attr((string) $rule_settings['tang_gia_kem_vol_vol_ratio_ma20_min']); ?>"></td></tr>
                </tbody></table>
                <?php submit_button('Lưu & thực thi rule tang_gia_kem_vol'); ?>
            </form>
        </div>

        <div id="lcni-tab-rule-rs-exchange" class="lcni-sub-tab-content">
            <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=' . $redirect_page)); ?>" class="lcni-rule-form">
                <?php wp_nonce_field('lcni_admin_actions', 'lcni_action_nonce'); ?>
                <input type="hidden" name="lcni_admin_action" value="save_rule_settings">
                <input type="hidden" name="lcni_rule_execute" value="1">
                <input type="hidden" name="lcni_redirect_page" value="<?php echo esc_attr($redirect_page); ?>">
                <input type="hidden" name="lcni_redirect_tab" value="lcni-tab-rule-rs-exchange">
                <p class="description">Thiết lập rule cho <code>rs_exchange_status</code> và <code>rs_exchange_recommend</code>.</p>
                <table class="form-table" role="presentation"><tbody>
                    <tr><th scope="row">Vào Sóng Mạnh (1W min / 1M min / 3M max)</th><td><input type="number" step="0.01" name="lcni_rule_settings[rs_exchange_status_song_manh_1w_min]" value="<?php echo esc_attr((string) $rule_settings['rs_exchange_status_song_manh_1w_min']); ?>"> / <input type="number" step="0.01" name="lcni_rule_settings[rs_exchange_status_song_manh_1m_min]" value="<?php echo esc_attr((string) $rule_settings['rs_exchange_status_song_manh_1m_min']); ?>"> / <input type="number" step="0.01" name="lcni_rule_settings[rs_exchange_status_song_manh_3m_max]" value="<?php echo esc_attr((string) $rule_settings['rs_exchange_status_song_manh_3m_max']); ?>"></td></tr>
                    <tr><th scope="row">Giữ Trend Mạnh (1W min / 1M min / 3M min)</th><td><input type="number" step="0.01" name="lcni_rule_settings[rs_exchange_status_giu_trend_1w_min]" value="<?php echo esc_attr((string) $rule_settings['rs_exchange_status_giu_trend_1w_min']); ?>"> / <input type="number" step="0.01" name="lcni_rule_settings[rs_exchange_status_giu_trend_1m_min]" value="<?php echo esc_attr((string) $rule_settings['rs_exchange_status_giu_trend_1m_min']); ?>"> / <input type="number" step="0.01" name="lcni_rule_settings[rs_exchange_status_giu_trend_3m_min]" value="<?php echo esc_attr((string) $rule_settings['rs_exchange_status_giu_trend_3m_min']); ?>"></td></tr>
                    <tr><th scope="row">Yếu (1W max / 1M max / 3M max)</th><td><input type="number" step="0.01" name="lcni_rule_settings[rs_exchange_status_yeu_1w_max]" value="<?php echo esc_attr((string) $rule_settings['rs_exchange_status_yeu_1w_max']); ?>"> / <input type="number" step="0.01" name="lcni_rule_settings[rs_exchange_status_yeu_1m_max]" value="<?php echo esc_attr((string) $rule_settings['rs_exchange_status_yeu_1m_max']); ?>"> / <input type="number" step="0.01" name="lcni_rule_settings[rs_exchange_status_yeu_3m_max]" value="<?php echo esc_attr((string) $rule_settings['rs_exchange_status_yeu_3m_max']); ?>"></td></tr>
                    <tr><th scope="row">Gợi ý mua (Volume min / RS1W min / RS1W-1M min)</th><td><input type="number" step="1" name="lcni_rule_settings[rs_exchange_recommend_volume_min]" value="<?php echo esc_attr((string) $rule_settings['rs_exchange_recommend_volume_min']); ?>"> / <input type="number" step="0.01" name="lcni_rule_settings[rs_exchange_recommend_buy_1w_min]" value="<?php echo esc_attr((string) $rule_settings['rs_exchange_recommend_buy_1w_min']); ?>"> / <input type="number" step="0.01" name="lcni_rule_settings[rs_exchange_recommend_buy_1w_gain_over_1m]" value="<?php echo esc_attr((string) $rule_settings['rs_exchange_recommend_buy_1w_gain_over_1m']); ?>"></td></tr>
                    <tr><th scope="row">Gợi ý mua (%1W min / %1M max / %3M max)</th><td><input type="number" step="0.0001" name="lcni_rule_settings[rs_exchange_recommend_buy_pct_1w_min]" value="<?php echo esc_attr((string) $rule_settings['rs_exchange_recommend_buy_pct_1w_min']); ?>"> / <input type="number" step="0.0001" name="lcni_rule_settings[rs_exchange_recommend_buy_pct_1m_max]" value="<?php echo esc_attr((string) $rule_settings['rs_exchange_recommend_buy_pct_1m_max']); ?>"> / <input type="number" step="0.0001" name="lcni_rule_settings[rs_exchange_recommend_buy_pct_3m_max]" value="<?php echo esc_attr((string) $rule_settings['rs_exchange_recommend_buy_pct_3m_max']); ?>"></td></tr>
                    <tr><th scope="row">Gợi ý mua (%T-1 min / Volume boost ratio)</th><td><input type="number" step="0.0001" name="lcni_rule_settings[rs_exchange_recommend_buy_pct_t_1_min]" value="<?php echo esc_attr((string) $rule_settings['rs_exchange_recommend_buy_pct_t_1_min']); ?>"> / <input type="number" step="0.0001" name="lcni_rule_settings[rs_exchange_recommend_buy_volume_boost_ratio]" value="<?php echo esc_attr((string) $rule_settings['rs_exchange_recommend_buy_volume_boost_ratio']); ?>"></td></tr>
                    <tr><th scope="row">Gợi ý bán (RS1W max / %1W max)</th><td><input type="number" step="0.01" name="lcni_rule_settings[rs_exchange_recommend_sell_1w_max]" value="<?php echo esc_attr((string) $rule_settings['rs_exchange_recommend_sell_1w_max']); ?>"> / <input type="number" step="0.0001" name="lcni_rule_settings[rs_exchange_recommend_sell_pct_1w_max]" value="<?php echo esc_attr((string) $rule_settings['rs_exchange_recommend_sell_pct_1w_max']); ?>"></td></tr>
                </tbody></table>
                <?php submit_button('Lưu & thực thi rule rs_exchange'); ?>
            </form>
        </div>

        <script>
            (function() {
                const ruleSubTabNav = document.getElementById('lcni-rule-sub-tabs');
                if (!ruleSubTabNav) {
                    return;
                }

                const subButtons = ruleSubTabNav.querySelectorAll('button[data-sub-tab]');
                const subPanes = document.querySelectorAll('.lcni-sub-tab-content');
                const subTabDefault = (new URLSearchParams(window.location.search).get('tab')) || 'lcni-tab-rule-xay-nen';

                const activateSubTab = function(tabId) {
                    subButtons.forEach((btn) => btn.classList.toggle('active', btn.getAttribute('data-sub-tab') === tabId));
                    subPanes.forEach((pane) => pane.classList.toggle('active', pane.id === tabId));
                };

                subButtons.forEach((button) => {
                    button.addEventListener('click', () => activateSubTab(button.getAttribute('data-sub-tab')));
                });

                const hasRequestedSubTab = Array.from(subButtons).some((btn) => btn.getAttribute('data-sub-tab') === subTabDefault);
                activateSubTab(hasRequestedSubTab ? subTabDefault : 'lcni-tab-rule-xay-nen');

                const progressWrapper = document.getElementById('lcni-rule-progress-wrapper');
                const progressFill = document.getElementById('lcni-rule-progress-fill');
                const progressText = document.getElementById('lcni-rule-progress-text');

                if (!progressWrapper || !progressFill || !progressText) {
                    return;
                }

                const endpoint = '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';
                const nonce = '<?php echo esc_js(wp_create_nonce('lcni_rule_rebuild_nonce')); ?>';

                const renderProgress = function(payload) {
                    const total = Number(payload.total || 0);
                    const processed = Number(payload.processed || 0);
                    const status = String(payload.status || 'idle').toUpperCase();
                    const progress = Number(payload.progress_percent || (total === 0 ? 100 : 0));

                    progressFill.style.width = Math.max(0, Math.min(100, progress)) + '%';
                    progressText.textContent = status + ' - ' + processed + '/' + total + ' (' + progress + '%)';

                    return status === 'RUNNING';
                };

                const pollProgress = function() {
                    const body = new URLSearchParams();
                    body.set('action', 'lcni_rule_rebuild_status');
                    body.set('nonce', nonce);

                    fetch(endpoint, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                        },
                        body: body.toString(),
                    })
                        .then((response) => response.json())
                        .then((result) => {
                            if (!result || !result.success || !result.data) {
                                return;
                            }

                            const shouldContinue = renderProgress(result.data);
                            if (shouldContinue) {
                                window.setTimeout(pollProgress, 2000);
                            }
                        })
                        .catch(() => {
                            window.setTimeout(pollProgress, 4000);
                        });
                };

                const initialStatus = String(progressWrapper.getAttribute('data-status') || '').toLowerCase();
                if (initialStatus === 'running') {
                    window.setTimeout(pollProgress, 1200);
                }
            })();
        </script>
        <?php
    }

}
