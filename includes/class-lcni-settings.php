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
        add_action('wp_ajax_lcni_update_data_status_snapshot', [$this, 'ajax_update_data_status_snapshot']);
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
        register_setting('lcni_settings_group', 'lcni_seed_batch_requests_per_run', ['type' => 'integer', 'sanitize_callback' => [$this, 'sanitize_positive_int'], 'default' => 5]);
        register_setting('lcni_settings_group', 'lcni_seed_rate_limit_microseconds', ['type' => 'integer', 'sanitize_callback' => [$this, 'sanitize_positive_int'], 'default' => 100000]);
        register_setting('lcni_settings_group', 'lcni_api_key', ['type' => 'string', 'sanitize_callback' => [$this, 'sanitize_api_credential'], 'default' => '']);
        register_setting('lcni_settings_group', 'lcni_api_secret', ['type' => 'string', 'sanitize_callback' => [$this, 'sanitize_api_credential'], 'default' => '']);
        register_setting('lcni_settings_group', 'lcni_access_token', ['type' => 'string', 'sanitize_callback' => [$this, 'sanitize_api_credential'], 'default' => '']);
        register_setting('lcni_settings_group', 'lcni_secdef_url', ['type' => 'string', 'sanitize_callback' => [$this, 'sanitize_secdef_url'], 'default' => LCNI_API::SECDEF_URL]);
        register_setting('lcni_settings_group', 'lcni_update_interval_minutes', ['type' => 'integer', 'sanitize_callback' => [$this, 'sanitize_update_interval'], 'default' => 5]);
        register_setting('lcni_settings_group', 'lcni_ohlc_latest_interval_minutes', ['type' => 'integer', 'sanitize_callback' => [$this, 'sanitize_update_interval'], 'default' => 5]);
        register_setting('lcni_settings_group', 'lcni_frontend_settings_signals', ['type' => 'array', 'sanitize_callback' => [$this, 'sanitize_frontend_module_settings'], 'default' => []]);
        register_setting('lcni_settings_group', 'lcni_frontend_settings_overview', ['type' => 'array', 'sanitize_callback' => [$this, 'sanitize_frontend_module_settings'], 'default' => []]);
        register_setting('lcni_settings_group', 'lcni_frontend_settings_chart', ['type' => 'array', 'sanitize_callback' => [$this, 'sanitize_frontend_chart_settings'], 'default' => []]);
        register_setting('lcni_settings_group', 'lcni_frontend_stock_detail_page', ['type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 0]);
        register_setting('lcni_settings_group', 'lcni_watchlist_stock_page', ['type' => 'string', 'sanitize_callback' => 'sanitize_title', 'default' => '']);
        register_setting('lcni_settings_group', 'lcni_frontend_overview_title', ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => 'Stock Overview']);
        register_setting('lcni_settings_group', 'lcni_frontend_chart_title', ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => 'Stock Chart']);
        register_setting('lcni_settings_group', 'lcni_frontend_signal_title', ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => 'LCNi Signals']);
        register_setting('lcni_settings_group', 'lcni_filter_criteria_columns', ['type' => 'array', 'sanitize_callback' => ['LCNI_FilterAdmin', 'sanitize_columns'], 'default' => []]);
        register_setting('lcni_settings_group', 'lcni_filter_table_columns', ['type' => 'array', 'sanitize_callback' => ['LCNI_FilterAdmin', 'sanitize_columns'], 'default' => []]);
        register_setting('lcni_settings_group', 'lcni_filter_style', ['type' => 'array', 'sanitize_callback' => ['LCNI_FilterAdmin', 'sanitize_style'], 'default' => []]);
        register_setting('lcni_settings_group', 'lcni_filter_style_config', ['type' => 'array', 'sanitize_callback' => ['LCNI_FilterAdmin', 'sanitize_style'], 'default' => []]);
        register_setting('lcni_settings_group', 'lcni_filter_default_values', ['type' => 'string', 'sanitize_callback' => ['LCNI_FilterAdmin', 'sanitize_default_filter_values'], 'default' => '']);
        register_setting('lcni_settings_group', 'lcni_button_style_config', ['type' => 'array', 'sanitize_callback' => [$this, 'sanitize_button_style_config'], 'default' => []]);
        register_setting('lcni_settings_group', 'lcni_column_labels', ['type' => 'array', 'default' => []]);
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
            $batch_requests_per_run = isset($_POST['lcni_seed_batch_requests_per_run']) ? $this->sanitize_positive_int(wp_unslash($_POST['lcni_seed_batch_requests_per_run'])) : (int) get_option('lcni_seed_batch_requests_per_run', 5);
            $rate_limit_microseconds = isset($_POST['lcni_seed_rate_limit_microseconds']) ? $this->sanitize_positive_int(wp_unslash($_POST['lcni_seed_rate_limit_microseconds'])) : (int) get_option('lcni_seed_rate_limit_microseconds', 100000);

            update_option('lcni_seed_range_mode', $seed_mode);
            update_option('lcni_seed_from_date', $seed_from_date);
            update_option('lcni_seed_to_date', $seed_to_date);
            update_option('lcni_seed_session_count', $seed_sessions);
            update_option('lcni_seed_batch_requests_per_run', $batch_requests_per_run);
            update_option('lcni_seed_rate_limit_microseconds', $rate_limit_microseconds);

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
            $allowed_modules = ['signals', 'overview', 'chart', 'chart_analyst', 'watchlist', 'filter', 'column_labels', 'button_style', 'data_format'];

            if (!in_array($module, $allowed_modules, true)) {
                $this->set_notice('error', 'Module frontend không hợp lệ.');
            } else {
                if ($module === 'chart') {
                    $input = [
                        'allowed_panels' => isset($_POST['lcni_frontend_allowed_panels']) ? (array) wp_unslash($_POST['lcni_frontend_allowed_panels']) : [],
                        'default_mode' => isset($_POST['lcni_frontend_default_mode']) ? wp_unslash($_POST['lcni_frontend_default_mode']) : '',
                        'compact_mode' => isset($_POST['lcni_frontend_compact_mode']) ? wp_unslash($_POST['lcni_frontend_compact_mode']) : '',
                        'default_visible_bars' => isset($_POST['lcni_frontend_default_visible_bars']) ? wp_unslash($_POST['lcni_frontend_default_visible_bars']) : 120,
                        'chart_sync_enabled' => isset($_POST['lcni_frontend_chart_sync_enabled']) ? wp_unslash($_POST['lcni_frontend_chart_sync_enabled']) : '',
                        'fit_to_screen_on_load' => isset($_POST['lcni_frontend_fit_to_screen_on_load']) ? wp_unslash($_POST['lcni_frontend_fit_to_screen_on_load']) : '',
                        'default_ma20' => isset($_POST['lcni_frontend_default_ma20']) ? wp_unslash($_POST['lcni_frontend_default_ma20']) : '',
                        'default_ma50' => isset($_POST['lcni_frontend_default_ma50']) ? wp_unslash($_POST['lcni_frontend_default_ma50']) : '',
                        'default_rsi' => isset($_POST['lcni_frontend_default_rsi']) ? wp_unslash($_POST['lcni_frontend_default_rsi']) : '',
                        'default_macd' => isset($_POST['lcni_frontend_default_macd']) ? wp_unslash($_POST['lcni_frontend_default_macd']) : '',
                        'default_rs_1w_by_exchange' => isset($_POST['lcni_frontend_default_rs_1w_by_exchange']) ? wp_unslash($_POST['lcni_frontend_default_rs_1w_by_exchange']) : '',
                        'default_rs_1m_by_exchange' => isset($_POST['lcni_frontend_default_rs_1m_by_exchange']) ? wp_unslash($_POST['lcni_frontend_default_rs_1m_by_exchange']) : '',
                        'default_rs_3m_by_exchange' => isset($_POST['lcni_frontend_default_rs_3m_by_exchange']) ? wp_unslash($_POST['lcni_frontend_default_rs_3m_by_exchange']) : '',
                    ];
                    update_option('lcni_frontend_settings_chart', $this->sanitize_frontend_chart_settings($input));
                    update_option('lcni_frontend_chart_title', $this->sanitize_module_title(isset($_POST['lcni_frontend_module_title']) ? wp_unslash($_POST['lcni_frontend_module_title']) : '', 'Stock Chart'));

                } elseif ($module === 'chart_analyst') {
                    $templates = [];
                    foreach (['momentum', 'trend', 'swing'] as $template_key) {
                        $templates[$template_key] = [
                            'label' => isset($_POST['lcni_chart_analyst_template_label'][$template_key])
                                ? wp_unslash($_POST['lcni_chart_analyst_template_label'][$template_key])
                                : '',
                            'indicators' => isset($_POST['lcni_chart_analyst_template_indicators'][$template_key])
                                ? (array) wp_unslash($_POST['lcni_chart_analyst_template_indicators'][$template_key])
                                : [],
                        ];
                    }

                    $input = [
                        'templates' => $templates,
                        'default_template' => [
                            'stock_detail' => isset($_POST['lcni_chart_analyst_default_template_stock_detail']) ? wp_unslash($_POST['lcni_chart_analyst_default_template_stock_detail']) : '',
                            'dashboard' => isset($_POST['lcni_chart_analyst_default_template_dashboard']) ? wp_unslash($_POST['lcni_chart_analyst_default_template_dashboard']) : '',
                            'watchlist' => isset($_POST['lcni_chart_analyst_default_template_watchlist']) ? wp_unslash($_POST['lcni_chart_analyst_default_template_watchlist']) : '',
                        ],
                    ];

                    update_option('lcni_chart_analyst_settings', LCNI_Chart_Analyst_Settings::sanitize_config($input));
                } elseif ($module === 'watchlist') {
                    $existing_watchlist = $this->sanitize_watchlist_settings(get_option('lcni_watchlist_settings', []));
                    $existing_label_pairs = $this->normalize_watchlist_column_label_pairs($existing_watchlist['column_labels'] ?? []);
                    $watchlist_section = isset($_POST['lcni_frontend_watchlist_section']) ? sanitize_key((string) wp_unslash($_POST['lcni_frontend_watchlist_section'])) : '';
                    $input = [
                        'allowed_columns' => $existing_watchlist['allowed_columns'] ?? [],
                        'default_columns_desktop' => $existing_watchlist['default_columns_desktop'] ?? [],
                        'default_columns_mobile' => $existing_watchlist['default_columns_mobile'] ?? [],
                        'stock_detail_page_id' => $existing_watchlist['stock_detail_page_id'] ?? 0,
                        'column_label_keys' => array_map('sanitize_key', wp_list_pluck($existing_label_pairs, 'data_key')),
                        'column_label_values' => array_map('sanitize_text_field', wp_list_pluck($existing_label_pairs, 'label')),
                        'styles' => $existing_watchlist['styles'] ?? [],
                        'value_color_rule_columns' => array_map('sanitize_key', wp_list_pluck((array) ($existing_watchlist['value_color_rules'] ?? []), 'column')),
                        'value_color_rule_operators' => wp_list_pluck((array) ($existing_watchlist['value_color_rules'] ?? []), 'operator'),
                        'value_color_rule_values' => wp_list_pluck((array) ($existing_watchlist['value_color_rules'] ?? []), 'value'),
                        'value_color_rule_bg_colors' => wp_list_pluck((array) ($existing_watchlist['value_color_rules'] ?? []), 'bg_color'),
                        'value_color_rule_text_colors' => wp_list_pluck((array) ($existing_watchlist['value_color_rules'] ?? []), 'text_color'),
                        'add_button' => $existing_watchlist['add_button'] ?? [],
                        'add_form_button' => $existing_watchlist['add_form_button'] ?? [],
                    ];

                    if ($watchlist_section === 'columns') {
                        $input['allowed_columns'] = isset($_POST['lcni_frontend_watchlist_allowed_columns']) ? (array) wp_unslash($_POST['lcni_frontend_watchlist_allowed_columns']) : [];
                    } elseif ($watchlist_section === 'stock_detail_page') {
                        $input['stock_detail_page_id'] = isset($_POST['lcni_frontend_stock_detail_page']) ? wp_unslash($_POST['lcni_frontend_stock_detail_page']) : 0;
                    } elseif ($watchlist_section === 'default_columns') {
                        $input['default_columns_desktop'] = isset($_POST['lcni_frontend_watchlist_default_columns_desktop']) ? (array) wp_unslash($_POST['lcni_frontend_watchlist_default_columns_desktop']) : [];
                        $input['default_columns_mobile'] = isset($_POST['lcni_frontend_watchlist_default_columns_mobile']) ? (array) wp_unslash($_POST['lcni_frontend_watchlist_default_columns_mobile']) : [];
                    } elseif ($watchlist_section === 'column_labels') {
                        $input['column_label_keys'] = isset($_POST['lcni_frontend_watchlist_column_label_key']) ? (array) wp_unslash($_POST['lcni_frontend_watchlist_column_label_key']) : [];
                        $input['column_label_values'] = isset($_POST['lcni_frontend_watchlist_column_label']) ? (array) wp_unslash($_POST['lcni_frontend_watchlist_column_label']) : [];
                        $pairs = [];
                        $count = max(count($input['column_label_keys']), count($input['column_label_values']));
                        for ($i = 0; $i < $count; $i++) {
                            $k = sanitize_key($input['column_label_keys'][$i] ?? '');
                            $v = sanitize_text_field((string) ($input['column_label_values'][$i] ?? ''));
                            if ($k !== '' && $v !== '') {
                                $pairs[] = ['data_key' => $k, 'label' => $v];
                            }
                        }
                        update_option('lcni_column_labels', $pairs);
                    } elseif ($watchlist_section === 'style_config') {
                        $input['styles'] = [
                            'font' => isset($_POST['lcni_frontend_watchlist_style_font']) ? wp_unslash($_POST['lcni_frontend_watchlist_style_font']) : '',
                            'text_color' => isset($_POST['lcni_frontend_watchlist_style_text_color']) ? wp_unslash($_POST['lcni_frontend_watchlist_style_text_color']) : '',
                            'background' => isset($_POST['lcni_frontend_watchlist_style_background']) ? wp_unslash($_POST['lcni_frontend_watchlist_style_background']) : '',
                            'border' => isset($_POST['lcni_frontend_watchlist_style_border']) ? wp_unslash($_POST['lcni_frontend_watchlist_style_border']) : '',
                            'border_radius' => isset($_POST['lcni_frontend_watchlist_style_border_radius']) ? wp_unslash($_POST['lcni_frontend_watchlist_style_border_radius']) : 8,
                            'label_font_size' => isset($_POST['lcni_frontend_watchlist_style_label_font_size']) ? wp_unslash($_POST['lcni_frontend_watchlist_style_label_font_size']) : 12,
                            'row_font_size' => isset($_POST['lcni_frontend_watchlist_style_row_font_size']) ? wp_unslash($_POST['lcni_frontend_watchlist_style_row_font_size']) : 13,
                            'header_background' => isset($_POST['lcni_frontend_watchlist_style_header_background']) ? wp_unslash($_POST['lcni_frontend_watchlist_style_header_background']) : '',
                            'header_text_color' => isset($_POST['lcni_frontend_watchlist_style_header_text_color']) ? wp_unslash($_POST['lcni_frontend_watchlist_style_header_text_color']) : '',
                            'value_background' => isset($_POST['lcni_frontend_watchlist_style_value_background']) ? wp_unslash($_POST['lcni_frontend_watchlist_style_value_background']) : '',
                            'value_text_color' => isset($_POST['lcni_frontend_watchlist_style_value_text_color']) ? wp_unslash($_POST['lcni_frontend_watchlist_style_value_text_color']) : '',
                            'row_divider_color' => isset($_POST['lcni_frontend_watchlist_style_row_divider_color']) ? wp_unslash($_POST['lcni_frontend_watchlist_style_row_divider_color']) : '',
                            'row_divider_width' => isset($_POST['lcni_frontend_watchlist_style_row_divider_width']) ? wp_unslash($_POST['lcni_frontend_watchlist_style_row_divider_width']) : 1,
                            'row_hover_bg' => isset($_POST['lcni_frontend_watchlist_style_row_hover_bg']) ? wp_unslash($_POST['lcni_frontend_watchlist_style_row_hover_bg']) : '',
                            'head_height' => isset($_POST['lcni_frontend_watchlist_style_head_height']) ? wp_unslash($_POST['lcni_frontend_watchlist_style_head_height']) : 40,
                            'sticky_column' => isset($_POST['lcni_frontend_watchlist_style_sticky_column']) ? wp_unslash($_POST['lcni_frontend_watchlist_style_sticky_column']) : 'symbol',
                            'sticky_header' => isset($_POST['lcni_frontend_watchlist_style_sticky_header']) ? 1 : 0,
                            'dropdown_height' => isset($_POST['lcni_frontend_watchlist_style_dropdown_height']) ? wp_unslash($_POST['lcni_frontend_watchlist_style_dropdown_height']) : 34,
                            'dropdown_width' => isset($_POST['lcni_frontend_watchlist_style_dropdown_width']) ? wp_unslash($_POST['lcni_frontend_watchlist_style_dropdown_width']) : 220,
                            'dropdown_font_size' => isset($_POST['lcni_frontend_watchlist_style_dropdown_font_size']) ? wp_unslash($_POST['lcni_frontend_watchlist_style_dropdown_font_size']) : 13,
                            'dropdown_border_color' => isset($_POST['lcni_frontend_watchlist_style_dropdown_border_color']) ? wp_unslash($_POST['lcni_frontend_watchlist_style_dropdown_border_color']) : '',
                            'dropdown_border_radius' => isset($_POST['lcni_frontend_watchlist_style_dropdown_border_radius']) ? wp_unslash($_POST['lcni_frontend_watchlist_style_dropdown_border_radius']) : 8,
                            'input_height' => isset($_POST['lcni_frontend_watchlist_style_input_height']) ? wp_unslash($_POST['lcni_frontend_watchlist_style_input_height']) : 34,
                            'input_width' => isset($_POST['lcni_frontend_watchlist_style_input_width']) ? wp_unslash($_POST['lcni_frontend_watchlist_style_input_width']) : 160,
                            'input_font_size' => isset($_POST['lcni_frontend_watchlist_style_input_font_size']) ? wp_unslash($_POST['lcni_frontend_watchlist_style_input_font_size']) : 13,
                            'input_border_color' => isset($_POST['lcni_frontend_watchlist_style_input_border_color']) ? wp_unslash($_POST['lcni_frontend_watchlist_style_input_border_color']) : '',
                            'input_border_radius' => isset($_POST['lcni_frontend_watchlist_style_input_border_radius']) ? wp_unslash($_POST['lcni_frontend_watchlist_style_input_border_radius']) : 8,
                            'scroll_speed' => isset($_POST['lcni_frontend_watchlist_style_scroll_speed']) ? wp_unslash($_POST['lcni_frontend_watchlist_style_scroll_speed']) : 1,
                        ];
                        $input['value_color_rule_columns'] = isset($_POST['lcni_watchlist_value_color_rule_column']) ? (array) wp_unslash($_POST['lcni_watchlist_value_color_rule_column']) : [];
                        $input['value_color_rule_operators'] = isset($_POST['lcni_watchlist_value_color_rule_operator']) ? (array) wp_unslash($_POST['lcni_watchlist_value_color_rule_operator']) : [];
                        $input['value_color_rule_values'] = isset($_POST['lcni_watchlist_value_color_rule_value']) ? (array) wp_unslash($_POST['lcni_watchlist_value_color_rule_value']) : [];
                        $input['value_color_rule_bg_colors'] = isset($_POST['lcni_watchlist_value_color_rule_bg_color']) ? (array) wp_unslash($_POST['lcni_watchlist_value_color_rule_bg_color']) : [];
                        $input['value_color_rule_text_colors'] = isset($_POST['lcni_watchlist_value_color_rule_text_color']) ? (array) wp_unslash($_POST['lcni_watchlist_value_color_rule_text_color']) : [];
                        $input['add_button'] = [
                            'icon' => isset($_POST['lcni_frontend_watchlist_btn_icon']) ? wp_unslash($_POST['lcni_frontend_watchlist_btn_icon']) : '',
                            'background' => isset($_POST['lcni_frontend_watchlist_btn_background']) ? wp_unslash($_POST['lcni_frontend_watchlist_btn_background']) : '',
                            'text_color' => isset($_POST['lcni_frontend_watchlist_btn_text_color']) ? wp_unslash($_POST['lcni_frontend_watchlist_btn_text_color']) : '',
                            'font_size' => isset($_POST['lcni_frontend_watchlist_btn_font_size']) ? wp_unslash($_POST['lcni_frontend_watchlist_btn_font_size']) : 14,
                            'size' => isset($_POST['lcni_frontend_watchlist_btn_size']) ? wp_unslash($_POST['lcni_frontend_watchlist_btn_size']) : 26,
                        ];
                        $input['add_form_button'] = [
                            'background' => isset($_POST['lcni_frontend_watchlist_form_btn_background']) ? wp_unslash($_POST['lcni_frontend_watchlist_form_btn_background']) : '',
                            'text_color' => isset($_POST['lcni_frontend_watchlist_form_btn_text_color']) ? wp_unslash($_POST['lcni_frontend_watchlist_form_btn_text_color']) : '',
                            'font_size' => isset($_POST['lcni_frontend_watchlist_form_btn_font_size']) ? wp_unslash($_POST['lcni_frontend_watchlist_form_btn_font_size']) : 14,
                            'height' => isset($_POST['lcni_frontend_watchlist_form_btn_height']) ? wp_unslash($_POST['lcni_frontend_watchlist_form_btn_height']) : 34,
                            'icon' => isset($_POST['lcni_frontend_watchlist_form_btn_icon']) ? wp_unslash($_POST['lcni_frontend_watchlist_form_btn_icon']) : '',
                        ];
                    }

                    $watchlist_settings = $this->sanitize_watchlist_settings($input);
                    update_option('lcni_watchlist_settings', $watchlist_settings);
                    update_option('lcni_frontend_stock_detail_page', (int) $watchlist_settings['stock_detail_page_id']);
                    update_option('lcni_watchlist_stock_page', sanitize_title((string) ($watchlist_settings['stock_detail_page_slug'] ?? '')));
                } elseif ($module === 'filter') {
                    $section = isset($_POST['lcni_filter_section']) ? sanitize_key((string) wp_unslash($_POST['lcni_filter_section'])) : '';
                    if ($section === 'criteria') {
                        update_option('lcni_filter_criteria_columns', LCNI_FilterAdmin::sanitize_columns(isset($_POST['lcni_filter_criteria_columns']) ? (array) wp_unslash($_POST['lcni_filter_criteria_columns']) : []));
                    } elseif ($section === 'table_columns') {
                        update_option('lcni_filter_table_columns', LCNI_FilterAdmin::sanitize_columns(isset($_POST['lcni_filter_table_columns']) ? (array) wp_unslash($_POST['lcni_filter_table_columns']) : []));
                    } elseif ($section === 'style') {
                        $raw_style = isset($_POST['lcni_filter_style_config']) ? (array) wp_unslash($_POST['lcni_filter_style_config']) : (isset($_POST['lcni_filter_style']) ? (array) wp_unslash($_POST['lcni_filter_style']) : []);
                        $style_config = LCNI_FilterAdmin::sanitize_style($raw_style);
                        update_option('lcni_filter_style', $style_config);
                        update_option('lcni_filter_style_config', $style_config);
                    } elseif ($section === 'default_values') {
                        update_option('lcni_filter_default_values', LCNI_FilterAdmin::sanitize_default_filter_values(isset($_POST['lcni_filter_default_values']) ? (string) wp_unslash($_POST['lcni_filter_default_values']) : ''));
                    } elseif ($section === 'default_criteria') {
                        update_option('lcni_filter_default_admin_saved_filter_id', absint(isset($_POST['lcni_filter_default_admin_saved_filter_id']) ? wp_unslash($_POST['lcni_filter_default_admin_saved_filter_id']) : 0));
                    }
                } elseif ($module === 'button_style') {
                    update_option('lcni_button_style_config', $this->sanitize_button_style_config(isset($_POST['lcni_button_style_config']) ? (array) wp_unslash($_POST['lcni_button_style_config']) : []));
                } elseif ($module === 'data_format') {
                    $input = isset($_POST[LCNI_Data_Format_Settings::OPTION_KEY]) ? (array) wp_unslash($_POST[LCNI_Data_Format_Settings::OPTION_KEY]) : [];
                    update_option(LCNI_Data_Format_Settings::OPTION_KEY, LCNI_Data_Format_Settings::sanitize_settings($input));
                } elseif ($module === 'column_labels') {
                    $keys = isset($_POST['lcni_global_column_label_key']) ? (array) wp_unslash($_POST['lcni_global_column_label_key']) : [];
                    $values = isset($_POST['lcni_global_column_label']) ? (array) wp_unslash($_POST['lcni_global_column_label']) : [];
                    $pairs = [];
                    $count = max(count($keys), count($values));
                    for ($i = 0; $i < $count; $i++) {
                        $key = sanitize_key($keys[$i] ?? '');
                        $label = sanitize_text_field((string) ($values[$i] ?? ''));
                        if ($key === '' || $label === '') {
                            continue;
                        }
                        $pairs[] = ['data_key' => $key, 'label' => $label];
                    }
                    update_option('lcni_column_labels', $pairs);
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
                    $title_option = $module === 'signals' ? 'lcni_frontend_signal_title' : 'lcni_frontend_overview_title';
                    $title_fallback = $module === 'signals' ? 'LCNi Signals' : 'Stock Overview';
                    update_option($title_option, $this->sanitize_module_title(isset($_POST['lcni_frontend_module_title']) ? wp_unslash($_POST['lcni_frontend_module_title']) : '', $title_fallback));
                }
                $this->set_notice('success', 'Đã lưu Frontend Settings cho module ' . $module . '.');
            }
        }

        $redirect_tab = isset($_POST['lcni_redirect_tab']) ? sanitize_key(wp_unslash($_POST['lcni_redirect_tab'])) : '';
        $redirect_page = isset($_POST['lcni_redirect_page']) ? sanitize_key(wp_unslash($_POST['lcni_redirect_page'])) : 'lcni-settings';
        $redirect_page = in_array($redirect_page, ['lcni-settings', 'lcni-data-viewer'], true) ? $redirect_page : 'lcni-settings';
        $redirect_url = admin_url('admin.php?page=' . $redirect_page);

        if ($redirect_page === 'lcni-settings' && in_array($redirect_tab, ['general', 'seed_dashboard', 'update_data', 'rule_settings', 'frontend_settings', 'change_logs', 'lcni-tab-rule-xay-nen', 'lcni-tab-rule-xay-nen-count-30', 'lcni-tab-rule-nen-type', 'lcni-tab-rule-pha-nen', 'lcni-tab-rule-tang-gia-kem-vol', 'lcni-tab-rule-rs-exchange', 'lcni-tab-update-runtime', 'lcni-tab-update-ohlc-latest', 'lcni-tab-frontend-signals', 'lcni-tab-frontend-overview', 'lcni-tab-frontend-chart', 'lcni-tab-frontend-chart-analyst', 'lcni-tab-frontend-watchlist', 'lcni-tab-frontend-filter', 'lcni-tab-frontend-column-label', 'lcni-tab-frontend-data-format'], true)) {
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

        $saved_file = trailingslashit($target_dir) . 'import-' . get_current_user_id() . '-' . current_time('timestamp') . '-' . wp_generate_password(8, false, false) . '.csv';
        if (!@copy($tmp_file_path, $saved_file)) {
            return new WP_Error('copy_failed', 'Không thể lưu file CSV tạm thời để map cột.');
        }

        $suggested_mapping = LCNI_DB::suggest_csv_mapping($table_key, $headers);

        return [
            'table_key' => $table_key,
            'file_path' => $saved_file,
            'headers' => $headers,
            'suggested_mapping' => $suggested_mapping,
            'created_at' => current_time('timestamp'),
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
            return $end_of_day ? current_time('timestamp') : 1;
        }

        $time_suffix = $end_of_day ? ' 23:59:59' : ' 00:00:00';
        $timezone = wp_timezone();

        try {
            $date_time = new DateTimeImmutable($date . $time_suffix, $timezone);
            $timestamp = $date_time->getTimestamp();
        } catch (Exception $e) {
            $timestamp = false;
        }

        return $timestamp === false ? ($end_of_day ? current_time('timestamp') : 1) : (int) $timestamp;
    }

    private function format_task_progress($task) {
        $status = isset($task['status']) ? (string) $task['status'] : 'pending';

        if ($status === 'done') {
            return 100;
        }

        $seed_constraints = LCNI_SeedScheduler::get_seed_constraints();
        $seed_to_time = max(1, (int) ($seed_constraints['to_time'] ?? current_time('timestamp')));
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

    private function format_mysql_datetime_to_gmt7($datetime) {
        $raw = trim((string) $datetime);
        if ($raw === '') {
            return '-';
        }

        try {
            $wp_timezone = wp_timezone();
            $date = new DateTimeImmutable($raw, $wp_timezone);

            return $date->setTimezone(new DateTimeZone('Asia/Ho_Chi_Minh'))->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            return $raw;
        }
    }

    private function format_timestamp_to_gmt7($timestamp) {
        $ts = (int) $timestamp;
        if ($ts <= 0) {
            return '-';
        }

        return wp_date('Y-m-d H:i:s', $ts, new DateTimeZone('Asia/Ho_Chi_Minh'));
    }

    private function normalize_runtime_status_for_display($status) {
        $status = is_array($status) ? $status : [];
        $diagnostics = LCNI_Update_Manager::get_runtime_diagnostics();
        $runtime_symbol = $this->get_latest_symbol_price_snapshot('lcni_ohlc', 'CEO');

        $message = (string) ($status['message'] ?? '-');
        if (!empty($status['waiting_for_trading_session'])) {
            $message = 'Waiting for trading session';
        }

        return [
            'running_label' => !empty($status['running']) ? 'Đang chạy' : 'Đã dừng',
            'processed_symbols' => (int) ($status['processed_symbols'] ?? 0),
            'success_symbols' => (int) ($status['success_symbols'] ?? 0),
            'error_symbols' => (int) ($status['error_symbols'] ?? 0),
            'pending_symbols' => (int) ($status['pending_symbols'] ?? 0),
            'changed_symbols' => (int) ($status['changed_symbols'] ?? 0),
            'execution_seconds' => (int) ($status['execution_seconds'] ?? 0),
            'indicators_done_label' => !empty($status['indicators_done']) ? 'Đã xong' : 'Chưa xong',
            'started_at' => $this->format_mysql_datetime_to_gmt7($status['started_at'] ?? ''),
            'ended_at' => $this->format_mysql_datetime_to_gmt7($status['ended_at'] ?? ''),
            'next_run_at' => $this->format_timestamp_to_gmt7($status['next_run_ts'] ?? 0),
            'message' => $message,
            'error' => (string) ($status['error'] ?? ''),
            'symbol_check_label' => (string) $runtime_symbol['label'],
            'symbol_check_open_price' => (string) $runtime_symbol['open_price'],
            'symbol_check_high_price' => (string) $runtime_symbol['high_price'],
            'symbol_check_low_price' => (string) $runtime_symbol['low_price'],
            'symbol_check_close_price' => (string) $runtime_symbol['close_price'],
            'symbol_check_event_time' => (string) $runtime_symbol['event_time'],
            'wordpress_timezone' => (string) ($diagnostics['wordpress_timezone'] ?? '-'),
            'market_timezone' => (string) ($diagnostics['market_timezone'] ?? '-'),
            'server_timezone' => (string) ($diagnostics['server_timezone'] ?? '-'),
            'current_time_mysql' => (string) ($diagnostics['current_time_mysql'] ?? '-'),
            'current_time_timestamp' => (string) ($diagnostics['current_time_timestamp'] ?? '-'),
            'is_trading_time' => !empty($diagnostics['is_trading_time']) ? 'true' : 'false',
        ];
    }

    private function normalize_ohlc_latest_status_for_display($status) {
        $status = is_array($status) ? $status : [];
        $latest_symbol = $this->get_latest_symbol_price_snapshot('lcni_ohlc_latest', 'CEO');

        return [
            'running_label' => !empty($status['running']) ? 'Đang chạy' : 'Đã dừng',
            'rows_affected' => (int) ($status['rows_affected'] ?? 0),
            'started_at' => $this->format_mysql_datetime_to_gmt7($status['started_at'] ?? ''),
            'ended_at' => $this->format_mysql_datetime_to_gmt7($status['ended_at'] ?? ''),
            'message' => (string) ($status['message'] ?? '-'),
            'error' => (string) ($status['error'] ?? ''),
            'symbol_check_label' => (string) $latest_symbol['label'],
            'symbol_check_open_price' => (string) $latest_symbol['open_price'],
            'symbol_check_high_price' => (string) $latest_symbol['high_price'],
            'symbol_check_low_price' => (string) $latest_symbol['low_price'],
            'symbol_check_close_price' => (string) $latest_symbol['close_price'],
            'symbol_check_event_time' => (string) $latest_symbol['event_time'],
            'last_run_status' => empty($status['error']) ? 'Thành công' : 'Thất bại',
        ];
    }

    private function get_latest_symbol_price_snapshot($table_suffix, $symbol) {
        global $wpdb;

        $table_name = $wpdb->prefix . $table_suffix;
        $safe_symbol = strtoupper(sanitize_text_field((string) $symbol));
        if ($safe_symbol === '') {
            $safe_symbol = 'CEO';
        }

        $table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_name));
        if ($table_exists !== $table_name) {
            return [
                'label' => sprintf('%s (chưa có bảng %s)', $safe_symbol, $table_name),
                'open_price' => '-',
                'high_price' => '-',
                'low_price' => '-',
                'close_price' => '-',
                'event_time' => '-',
            ];
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT symbol, open_price, high_price, low_price, close_price, event_time
                FROM {$table_name}
                WHERE symbol = %s
                ORDER BY event_time DESC
                LIMIT 1",
                $safe_symbol
            ),
            ARRAY_A
        );

        if (empty($row)) {
            return [
                'label' => sprintf('%s (không có dữ liệu)', $safe_symbol),
                'open_price' => '-',
                'high_price' => '-',
                'low_price' => '-',
                'close_price' => '-',
                'event_time' => '-',
            ];
        }

        return [
            'label' => strtoupper((string) ($row['symbol'] ?? $safe_symbol)),
            'open_price' => (string) ($row['open_price'] ?? '-'),
            'high_price' => (string) ($row['high_price'] ?? '-'),
            'low_price' => (string) ($row['low_price'] ?? '-'),
            'close_price' => (string) ($row['close_price'] ?? '-'),
            'event_time' => $this->format_mysql_datetime_to_gmt7($row['event_time'] ?? ''),
        ];
    }

    public function ajax_update_data_status_snapshot() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Forbidden'], 403);
        }

        check_ajax_referer('lcni_update_data_status_nonce', 'nonce');

        wp_send_json_success([
            'runtime' => $this->normalize_runtime_status_for_display(LCNI_Update_Manager::get_status()),
            'snapshot' => $this->normalize_ohlc_latest_status_for_display(LCNI_OHLC_Latest_Manager::get_status()),
            'updated_at' => wp_date('Y-m-d H:i:s', null, new DateTimeZone('Asia/Ho_Chi_Minh')),
        ]);
    }

    public function settings_page() {
        global $wpdb;

        $active_tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'general';
        $rule_sub_tabs = ['lcni-tab-rule-xay-nen', 'lcni-tab-rule-xay-nen-count-30', 'lcni-tab-rule-nen-type', 'lcni-tab-rule-pha-nen', 'lcni-tab-rule-tang-gia-kem-vol', 'lcni-tab-rule-rs-exchange'];
        $frontend_sub_tabs = ['lcni-tab-frontend-signals', 'lcni-tab-frontend-overview', 'lcni-tab-frontend-chart', 'lcni-tab-frontend-chart-analyst', 'lcni-tab-frontend-watchlist', 'lcni-tab-frontend-filter', 'lcni-tab-frontend-style-config', 'lcni-tab-frontend-column-label', 'lcni-tab-frontend-data-format'];
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
                    <p class="description" style="flex:1 1 100%;margin:0;">Workflow: nhận diện cột → chọn bảng → map cột CSV với cột DB → chạy import. Bảng thường dùng upsert theo Primary Key; riêng LCNI OHLC sẽ append hàng mới, tự gán <code>id</code> và <code>event_time</code> = Unix timestamp tại thời điểm import.</p>
                </form>

                <?php if (!empty($csv_import_draft) && is_array($csv_import_draft) && !empty($csv_import_draft['headers']) && !empty($csv_import_draft['table_key']) && isset($csv_import_targets[$csv_import_draft['table_key']])) : ?>
                    <?php $target_meta = $csv_import_targets[$csv_import_draft['table_key']]; ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=lcni-settings')); ?>" style="margin-bottom:12px;padding:10px;background:#fff;border:1px solid #dcdcde;border-radius:6px;">
                        <?php wp_nonce_field('lcni_admin_actions', 'lcni_action_nonce'); ?>
                        <input type="hidden" name="lcni_redirect_tab" value="seed_dashboard">
                        <input type="hidden" name="lcni_admin_action" value="run_csv_import">
                        <p style="margin-top:0;"><strong>Map cột cho bảng:</strong> <?php echo esc_html($target_meta['label']); ?> (Primary Key: <code><?php echo esc_html((string) $target_meta['primary_key']); ?></code>)</p>
                        <?php if (($csv_import_draft['table_key'] ?? '') === 'lcni_ohlc') : ?>
                            <p class="description" style="margin-top:-6px;">Yêu cầu map đủ 6 cột bắt buộc: <code>symbol</code>, <code>timeframe</code>, <code>open_price</code>, <code>high_price</code>, <code>low_price</code>, <code>close_price</code>. Hệ thống tự thêm <code>event_time</code> (Unix timestamp lúc import), tự tăng <code>id</code>, và chèn theo chế độ append.</p>
                        <?php endif; ?>
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
                    <input type="number" name="lcni_seed_batch_requests_per_run" value="<?php echo esc_attr((string) get_option('lcni_seed_batch_requests_per_run', 5)); ?>" min="1" style="width:90px;margin-right:6px;" title="BATCH_REQUESTS_PER_RUN">
                    <input type="number" name="lcni_seed_rate_limit_microseconds" value="<?php echo esc_attr((string) get_option('lcni_seed_rate_limit_microseconds', 100000)); ?>" min="1" style="width:130px;margin-right:6px;" title="RATE_LIMIT_MICROSECONDS">
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
                $ohlc_latest_settings = LCNI_OHLC_Latest_Manager::get_settings();
                $update_status = $this->normalize_runtime_status_for_display(LCNI_Update_Manager::get_status());
                $ohlc_latest_status = $this->normalize_ohlc_latest_status_for_display(LCNI_OHLC_Latest_Manager::get_status());
                $requested_update_sub_tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'lcni-tab-update-runtime';
                $active_update_sub_tab = in_array($requested_update_sub_tab, ['lcni-tab-update-runtime', 'lcni-tab-update-ohlc-latest'], true) ? $requested_update_sub_tab : 'lcni-tab-update-runtime';
                $update_status_nonce = wp_create_nonce('lcni_update_data_status_nonce');
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
                    <p id="lcni-runtime-status-updated-at" style="margin:0 0 8px;color:#50575e;">Timezone hiển thị: GMT+7 (Asia/Ho_Chi_Minh).</p>
                    <table class="widefat striped" style="max-width:980px;">
                        <tbody>
                            <tr><th>Đang chạy</th><td data-lcni-runtime-status="running_label"><?php echo esc_html((string) $update_status['running_label']); ?></td></tr>
                            <tr><th>Số symbol đã cập nhật</th><td data-lcni-runtime-status="processed_symbols"><?php echo esc_html((string) ($update_status['processed_symbols'] ?? 0)); ?></td></tr>
                            <tr><th>Số symbol thành công</th><td data-lcni-runtime-status="success_symbols"><?php echo esc_html((string) ($update_status['success_symbols'] ?? 0)); ?></td></tr>
                            <tr><th>Số symbol lỗi</th><td data-lcni-runtime-status="error_symbols"><?php echo esc_html((string) ($update_status['error_symbols'] ?? 0)); ?></td></tr>
                            <tr><th>Số symbol chờ cập nhật</th><td data-lcni-runtime-status="pending_symbols"><?php echo esc_html((string) ($update_status['pending_symbols'] ?? 0)); ?></td></tr>
                            <tr><th>Số symbol thay đổi giá</th><td data-lcni-runtime-status="changed_symbols"><?php echo esc_html((string) ($update_status['changed_symbols'] ?? 0)); ?></td></tr>
                            <tr><th>Cột tính toán hoàn tất</th><td data-lcni-runtime-status="indicators_done_label"><?php echo esc_html((string) $update_status['indicators_done_label']); ?></td></tr>
                            <tr><th>Thời gian bắt đầu</th><td data-lcni-runtime-status="started_at"><?php echo esc_html((string) ($update_status['started_at'] ?? '-')); ?></td></tr>
                            <tr><th>Thời gian kết thúc</th><td data-lcni-runtime-status="ended_at"><?php echo esc_html((string) ($update_status['ended_at'] ?? '-')); ?></td></tr>
                            <tr><th>Thời gian chạy (giây)</th><td data-lcni-runtime-status="execution_seconds"><?php echo esc_html((string) ($update_status['execution_seconds'] ?? 0)); ?></td></tr>
                            <tr><th>Dự kiến phiên cập nhật tiếp theo</th><td data-lcni-runtime-status="next_run_at"><?php echo esc_html((string) ($update_status['next_run_at'] ?? '-')); ?></td></tr>
                            <tr><th>Thông báo</th><td data-lcni-runtime-status="message"><?php echo esc_html((string) ($update_status['message'] ?? '-')); ?></td></tr>
                            <tr><th>Lỗi</th><td data-lcni-runtime-status="error"><?php echo esc_html((string) ($update_status['error'] ?? '')); ?></td></tr>
                            <tr><th>Symbol check</th><td data-lcni-runtime-status="symbol_check_label"><?php echo esc_html((string) ($update_status['symbol_check_label'] ?? 'CEO')); ?></td></tr>
                            <tr><th>Open</th><td data-lcni-runtime-status="symbol_check_open_price"><?php echo esc_html((string) ($update_status['symbol_check_open_price'] ?? '-')); ?></td></tr>
                            <tr><th>High</th><td data-lcni-runtime-status="symbol_check_high_price"><?php echo esc_html((string) ($update_status['symbol_check_high_price'] ?? '-')); ?></td></tr>
                            <tr><th>Low</th><td data-lcni-runtime-status="symbol_check_low_price"><?php echo esc_html((string) ($update_status['symbol_check_low_price'] ?? '-')); ?></td></tr>
                            <tr><th>Close</th><td data-lcni-runtime-status="symbol_check_close_price"><?php echo esc_html((string) ($update_status['symbol_check_close_price'] ?? '-')); ?></td></tr>
                            <tr><th>Updated_at (GMT+7)</th><td data-lcni-runtime-status="symbol_check_event_time"><?php echo esc_html((string) ($update_status['symbol_check_event_time'] ?? '-')); ?></td></tr>
                            <tr><th>WordPress timezone</th><td data-lcni-runtime-status="wordpress_timezone"><?php echo esc_html((string) ($update_status['wordpress_timezone'] ?? '-')); ?></td></tr>
                            <tr><th>Market timezone</th><td data-lcni-runtime-status="market_timezone"><?php echo esc_html((string) ($update_status['market_timezone'] ?? '-')); ?></td></tr>
                            <tr><th>Server timezone</th><td data-lcni-runtime-status="server_timezone"><?php echo esc_html((string) ($update_status['server_timezone'] ?? '-')); ?></td></tr>
                            <tr><th>current_time('mysql')</th><td data-lcni-runtime-status="current_time_mysql"><?php echo esc_html((string) ($update_status['current_time_mysql'] ?? '-')); ?></td></tr>
                            <tr><th>current_time('timestamp')</th><td data-lcni-runtime-status="current_time_timestamp"><?php echo esc_html((string) ($update_status['current_time_timestamp'] ?? '-')); ?></td></tr>
                            <tr><th>Trading check</th><td data-lcni-runtime-status="is_trading_time"><?php echo esc_html((string) ($update_status['is_trading_time'] ?? 'false')); ?></td></tr>
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
                    <p id="lcni-snapshot-status-updated-at" style="margin:0 0 8px;color:#50575e;">Timezone hiển thị: GMT+7 (Asia/Ho_Chi_Minh).</p>
                    <table class="widefat striped" style="max-width:980px;">
                        <tbody>
                            <tr><th>Đang chạy</th><td data-lcni-snapshot-status="running_label"><?php echo esc_html((string) $ohlc_latest_status['running_label']); ?></td></tr>
                            <tr><th>Rows affected lần chạy cuối</th><td data-lcni-snapshot-status="rows_affected"><?php echo esc_html((string) ($ohlc_latest_status['rows_affected'] ?? 0)); ?></td></tr>
                            <tr><th>Thời gian bắt đầu</th><td data-lcni-snapshot-status="started_at"><?php echo esc_html((string) ($ohlc_latest_status['started_at'] ?? '-')); ?></td></tr>
                            <tr><th>Thời gian kết thúc</th><td data-lcni-snapshot-status="ended_at"><?php echo esc_html((string) ($ohlc_latest_status['ended_at'] ?? '-')); ?></td></tr>
                            <tr><th>Thông báo</th><td data-lcni-snapshot-status="message"><?php echo esc_html((string) ($ohlc_latest_status['message'] ?? '-')); ?></td></tr>
                            <tr><th>Trạng thái lần chạy gần nhất</th><td data-lcni-snapshot-status="last_run_status"><?php echo esc_html((string) ($ohlc_latest_status['last_run_status'] ?? '-')); ?></td></tr>
                            <tr><th>Lỗi</th><td data-lcni-snapshot-status="error"><?php echo esc_html((string) ($ohlc_latest_status['error'] ?? '')); ?></td></tr>
                            <tr><th>Symbol check</th><td data-lcni-snapshot-status="symbol_check_label"><?php echo esc_html((string) ($ohlc_latest_status['symbol_check_label'] ?? 'CEO')); ?></td></tr>
                            <tr><th>Open</th><td data-lcni-snapshot-status="symbol_check_open_price"><?php echo esc_html((string) ($ohlc_latest_status['symbol_check_open_price'] ?? '-')); ?></td></tr>
                            <tr><th>High</th><td data-lcni-snapshot-status="symbol_check_high_price"><?php echo esc_html((string) ($ohlc_latest_status['symbol_check_high_price'] ?? '-')); ?></td></tr>
                            <tr><th>Low</th><td data-lcni-snapshot-status="symbol_check_low_price"><?php echo esc_html((string) ($ohlc_latest_status['symbol_check_low_price'] ?? '-')); ?></td></tr>
                            <tr><th>Close</th><td data-lcni-snapshot-status="symbol_check_close_price"><?php echo esc_html((string) ($ohlc_latest_status['symbol_check_close_price'] ?? '-')); ?></td></tr>
                            <tr><th>Thời gian cập nhật</th><td data-lcni-snapshot-status="symbol_check_event_time"><?php echo esc_html((string) ($ohlc_latest_status['symbol_check_event_time'] ?? '-')); ?></td></tr>
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
                        const endpoint = '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';
                        const statusNonce = '<?php echo esc_js($update_status_nonce); ?>';
                        const runtimeUpdatedAt = document.getElementById('lcni-runtime-status-updated-at');
                        const snapshotUpdatedAt = document.getElementById('lcni-snapshot-status-updated-at');

                        const activate = function(tabId) {
                            buttons.forEach((btn) => btn.classList.toggle('active', btn.getAttribute('data-sub-tab') === tabId));
                            panes.forEach((pane) => pane.classList.toggle('active', pane.id === tabId));
                        };

                        buttons.forEach((btn) => {
                            btn.addEventListener('click', () => activate(btn.getAttribute('data-sub-tab')));
                        });

                        const setFields = function(selectorPrefix, data) {
                            if (!data || typeof data !== 'object') {
                                return;
                            }

                            Object.keys(data).forEach((key) => {
                                const el = document.querySelector('[' + selectorPrefix + '="' + key + '"]');
                                if (el) {
                                    el.textContent = String(data[key] ?? '');
                                }
                            });
                        };

                        const refreshStatuses = function() {
                            const body = new URLSearchParams();
                            body.append('action', 'lcni_update_data_status_snapshot');
                            body.append('nonce', statusNonce);

                            fetch(endpoint, {
                                method: 'POST',
                                credentials: 'same-origin',
                                headers: {
                                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                                },
                                body: body.toString()
                            })
                            .then((response) => response.json())
                            .then((payload) => {
                                if (!payload || !payload.success || !payload.data) {
                                    return;
                                }

                                setFields('data-lcni-runtime-status', payload.data.runtime || {});
                                setFields('data-lcni-snapshot-status', payload.data.snapshot || {});

                                const updatedLabel = 'Timezone hiển thị: GMT+7 (Asia/Ho_Chi_Minh). Cập nhật gần nhất: ' + (payload.data.updated_at || '-');
                                if (runtimeUpdatedAt) {
                                    runtimeUpdatedAt.textContent = updatedLabel;
                                }
                                if (snapshotUpdatedAt) {
                                    snapshotUpdatedAt.textContent = updatedLabel;
                                }
                            });
                        };

                        activate(active);
                        refreshStatuses();
                        setInterval(refreshStatuses, 5000);
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
            'default_visible_bars' => 120,
            'chart_sync_enabled' => true,
            'fit_to_screen_on_load' => true,
            'default_ma20' => true,
            'default_ma50' => true,
            'default_rsi' => true,
            'default_macd' => false,
            'default_rs_1w_by_exchange' => true,
            'default_rs_1m_by_exchange' => true,
            'default_rs_3m_by_exchange' => false,
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
        $sync_raw = $value['chart_sync_enabled'] ?? $default['chart_sync_enabled'];
        $chart_sync_enabled = in_array($sync_raw, [1, '1', true, 'true', 'yes', 'on'], true);
        $fit_to_screen_raw = $value['fit_to_screen_on_load'] ?? $default['fit_to_screen_on_load'];
        $fit_to_screen_on_load = in_array($fit_to_screen_raw, [1, '1', true, 'true', 'yes', 'on'], true);
        $default_visible_bars = max(20, min(1000, (int) ($value['default_visible_bars'] ?? $default['default_visible_bars'])));
        $default_ma20 = in_array(($value['default_ma20'] ?? $default['default_ma20']), [1, '1', true, 'true', 'yes', 'on'], true);
        $default_ma50 = in_array(($value['default_ma50'] ?? $default['default_ma50']), [1, '1', true, 'true', 'yes', 'on'], true);
        $default_rsi = in_array(($value['default_rsi'] ?? $default['default_rsi']), [1, '1', true, 'true', 'yes', 'on'], true);
        $default_macd = in_array(($value['default_macd'] ?? $default['default_macd']), [1, '1', true, 'true', 'yes', 'on'], true);
        $default_rs_1w_by_exchange = in_array(($value['default_rs_1w_by_exchange'] ?? $default['default_rs_1w_by_exchange']), [1, '1', true, 'true', 'yes', 'on'], true);
        $default_rs_1m_by_exchange = in_array(($value['default_rs_1m_by_exchange'] ?? $default['default_rs_1m_by_exchange']), [1, '1', true, 'true', 'yes', 'on'], true);
        $default_rs_3m_by_exchange = in_array(($value['default_rs_3m_by_exchange'] ?? $default['default_rs_3m_by_exchange']), [1, '1', true, 'true', 'yes', 'on'], true);

        return [
            'default_mode' => $mode,
            'allowed_panels' => $allowed_panels,
            'compact_mode' => $compact_mode,
            'default_visible_bars' => $default_visible_bars,
            'chart_sync_enabled' => $chart_sync_enabled,
            'fit_to_screen_on_load' => $fit_to_screen_on_load,
            'default_ma20' => $default_ma20,
            'default_ma50' => $default_ma50,
            'default_rsi' => $default_rsi,
            'default_macd' => $default_macd,
            'default_rs_1w_by_exchange' => $default_rs_1w_by_exchange,
            'default_rs_1m_by_exchange' => $default_rs_1m_by_exchange,
            'default_rs_3m_by_exchange' => $default_rs_3m_by_exchange,
        ];
    }

private function sanitize_module_title($value, $fallback) {
        $title = sanitize_text_field((string) $value);

        return $title !== '' ? $title : $fallback;
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
        $watchlist = $this->sanitize_watchlist_settings(get_option('lcni_watchlist_settings', []));
        $chart_analyst = LCNI_Chart_Analyst_Settings::sanitize_config(get_option('lcni_chart_analyst_settings', []));
        ?>
        <style>
            .lcni-sub-tab-nav { display: flex; gap: 8px; flex-wrap: wrap; margin: 12px 0; border-bottom: 1px solid #dcdcde; }
            .lcni-sub-tab-nav button { border: 1px solid #dcdcde; border-bottom: 0; background: #f6f7f7; padding: 6px 10px; cursor: pointer; }
            .lcni-sub-tab-nav button.active { background: #fff; font-weight: 600; }
            .lcni-sub-tab-content { display: none; }
            .lcni-sub-tab-content.active { display: block; }
            .lcni-front-form { max-width: 980px; background:#fff; border:1px solid #dcdcde; padding:12px; }
            .lcni-front-grid { display:grid; grid-template-columns: repeat(auto-fit, minmax(240px,1fr)); gap:8px 16px; margin:12px 0;}
            .lcni-watchlist-pane { display: none; }
            .lcni-watchlist-pane.active { display: block; }
        </style>
        <p>Cấu hình hiển thị frontend cho từng shortcode module.</p>
        <div class="lcni-sub-tab-nav" id="lcni-front-sub-tabs">
            <button type="button" data-sub-tab="lcni-tab-frontend-signals">LCNi Signals</button>
            <button type="button" data-sub-tab="lcni-tab-frontend-overview">Stock Overview</button>
            <button type="button" data-sub-tab="lcni-tab-frontend-chart">Stock Chart</button>
            <button type="button" data-sub-tab="lcni-tab-frontend-chart-analyst">Chart Analyst</button>
            <button type="button" data-sub-tab="lcni-tab-frontend-watchlist">Watchlist</button>
            <button type="button" data-sub-tab="lcni-tab-frontend-filter">Filter</button>
            <button type="button" data-sub-tab="lcni-tab-frontend-style-config">Style Config</button>
            <button type="button" data-sub-tab="lcni-tab-frontend-column-label">Column Label</button>
            <button type="button" data-sub-tab="lcni-tab-frontend-data-format">Data Format</button>
        </div>
        <?php $this->render_frontend_module_form('signals', 'lcni-tab-frontend-signals', $signals_labels, $signals); ?>
        <?php $this->render_frontend_module_form('overview', 'lcni-tab-frontend-overview', $overview_labels, $overview); ?>
        <?php $this->render_frontend_chart_form('chart', 'lcni-tab-frontend-chart', $chart); ?>
        <?php $this->render_frontend_chart_analyst_form('chart_analyst', 'lcni-tab-frontend-chart-analyst', $chart_analyst); ?>
        <?php $this->render_frontend_watchlist_form('watchlist', 'lcni-tab-frontend-watchlist', $watchlist); ?>
        <?php LCNI_FilterAdmin::render_filter_form('lcni-tab-frontend-filter'); ?>
        <?php $this->render_frontend_button_style_form('button_style', 'lcni-tab-frontend-style-config'); ?>
        <?php $this->render_global_column_label_form('column_labels', 'lcni-tab-frontend-column-label', $watchlist); ?>
        <?php $this->render_frontend_data_format_form('data_format', 'lcni-tab-frontend-data-format'); ?>
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
                        if (pane.id === 'lcni-tab-frontend-signals' || pane.id === 'lcni-tab-frontend-overview' || pane.id === 'lcni-tab-frontend-chart' || pane.id === 'lcni-tab-frontend-chart-analyst' || pane.id === 'lcni-tab-frontend-watchlist' || pane.id === 'lcni-tab-frontend-filter' || pane.id === 'lcni-tab-frontend-style-config' || pane.id === 'lcni-tab-frontend-column-label' || pane.id === 'lcni-tab-frontend-data-format') {
                            pane.classList.toggle('active', pane.id === tabId);
                        }
                    });
                };
                buttons.forEach((btn) => btn.addEventListener('click', () => activate(btn.getAttribute('data-sub-tab'))));
                const validTabs = ['lcni-tab-frontend-signals', 'lcni-tab-frontend-overview', 'lcni-tab-frontend-chart', 'lcni-tab-frontend-chart-analyst', 'lcni-tab-frontend-watchlist', 'lcni-tab-frontend-filter', 'lcni-tab-frontend-style-config', 'lcni-tab-frontend-column-label', 'lcni-tab-frontend-data-format'];
                activate(validTabs.includes(current) ? current : 'lcni-tab-frontend-signals');
            })();
        </script>
        <?php
    }

    private function render_frontend_data_format_form($module, $tab_id) {
        $settings = LCNI_Data_Format_Settings::get_settings();
        $option_key = LCNI_Data_Format_Settings::OPTION_KEY;
        $multiply_100_fields = LCNI_Data_Format_Settings::get_multiply_100_fields();
        $already_percent_fields = LCNI_Data_Format_Settings::get_already_percent_fields();
        $module_scope_labels = LCNI_Data_Format_Settings::get_module_scope_labels();
        ?>
        <div id="<?php echo esc_attr($tab_id); ?>" class="lcni-sub-tab-content">
            <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=lcni-settings')); ?>" class="lcni-front-form">
                <?php wp_nonce_field('lcni_admin_actions', 'lcni_action_nonce'); ?>
                <input type="hidden" name="lcni_admin_action" value="save_frontend_settings">
                <input type="hidden" name="lcni_frontend_module" value="<?php echo esc_attr($module); ?>">
                <input type="hidden" name="lcni_redirect_tab" value="<?php echo esc_attr($tab_id); ?>">
                <h3>Data Format</h3>
                <p><label><input type="checkbox" name="<?php echo esc_attr($option_key); ?>[use_intl]" value="1" <?php checked(!empty($settings['use_intl'])); ?>> Use Intl.NumberFormat</label></p>
                <p><label>Locale <select name="<?php echo esc_attr($option_key); ?>[locale]"><option value="vi-VN" <?php selected($settings['locale'], 'vi-VN'); ?>>vi-VN</option><option value="en-US" <?php selected($settings['locale'], 'en-US'); ?>>en-US</option></select></label></p>
                <p><label><input type="checkbox" name="<?php echo esc_attr($option_key); ?>[compact_numbers]" value="1" <?php checked(!empty($settings['compact_numbers'])); ?>> Use compact numbers</label></p>
                <p><label>Compact threshold <input type="number" min="0" step="1" name="<?php echo esc_attr($option_key); ?>[compact_threshold]" value="<?php echo esc_attr((string) $settings['compact_threshold']); ?>"></label></p>
                <h4>Decimals</h4>
                <?php foreach ((array) ($settings['decimals'] ?? []) as $type => $precision) : ?>
                    <p><label><?php echo esc_html(ucfirst(str_replace('_', ' ', (string) $type))); ?> <input type="number" min="0" max="8" step="1" name="<?php echo esc_attr($option_key); ?>[decimals][<?php echo esc_attr((string) $type); ?>]" value="<?php echo esc_attr((string) $precision); ?>"></label></p>
                <?php endforeach; ?>

                <h4>Percent Normalization</h4>
                <fieldset style="border:1px solid #dcdcde;padding:12px;margin:0 0 12px;">
                    <legend><strong>Fields require *100</strong></legend>
                    <?php foreach ($multiply_100_fields as $field_key) : ?>
                        <p>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr($option_key); ?>[percent_normalization][multiply_100_fields][]" value="<?php echo esc_attr($field_key); ?>" <?php checked(in_array($field_key, (array) ($settings['percent_normalization']['multiply_100_fields'] ?? []), true)); ?>>
                                <?php echo esc_html($field_key); ?>
                            </label>
                        </p>
                    <?php endforeach; ?>
                </fieldset>

                <fieldset style="border:1px solid #dcdcde;padding:12px;margin:0 0 12px;">
                    <legend><strong>Already percent (NO *100)</strong></legend>
                    <?php foreach ($already_percent_fields as $field_key) : ?>
                        <p>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr($option_key); ?>[percent_normalization][already_percent_fields][]" value="<?php echo esc_attr($field_key); ?>" <?php checked(in_array($field_key, (array) ($settings['percent_normalization']['already_percent_fields'] ?? []), true)); ?>>
                                <?php echo esc_html($field_key); ?>
                            </label>
                        </p>
                    <?php endforeach; ?>
                </fieldset>

                <h4>Module Scope</h4>
                <fieldset style="border:1px solid #dcdcde;padding:12px;margin:0 0 12px;">
                    <?php foreach ($module_scope_labels as $module_key => $module_label) : ?>
                        <p>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr($option_key); ?>[module_scope][<?php echo esc_attr($module_key); ?>]" value="1" <?php checked(!empty($settings['module_scope'][$module_key])); ?>>
                                <?php echo esc_html($module_label); ?>
                            </label>
                        </p>
                    <?php endforeach; ?>
                </fieldset>
                <?php submit_button('Save Data Format'); ?>
            </form>
        </div>
        <?php
    }

    public function sanitize_button_style_config($input) {
        return LCNI_Button_Style_Config::sanitize_config($input);
    }

    private function render_frontend_button_style_form($module, $tab_id) {
        $settings = LCNI_Button_Style_Config::get_config();
        $button_keys = LCNI_Button_Style_Config::get_button_keys();
        ?>
        <div id="<?php echo esc_attr($tab_id); ?>" class="lcni-sub-tab-content">
            <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=lcni-settings')); ?>" class="lcni-front-form">
                <?php wp_nonce_field('lcni_admin_actions', 'lcni_action_nonce'); ?>
                <input type="hidden" name="lcni_admin_action" value="save_frontend_settings">
                <input type="hidden" name="lcni_frontend_module" value="<?php echo esc_attr($module); ?>">
                <input type="hidden" name="lcni_redirect_tab" value="<?php echo esc_attr($tab_id); ?>">

                <h3>Button Settings</h3>
                <?php foreach ($button_keys as $button_key => $button_label) : $button = $settings[$button_key] ?? []; ?>
                    <fieldset style="border:1px solid #dcdcde;padding:12px;margin:0 0 12px;">
                        <legend><strong><?php echo esc_html($button_label . ' (' . $button_key . ')'); ?></strong></legend>
                        <p><label>label_text <input type="text" name="lcni_button_style_config[<?php echo esc_attr($button_key); ?>][label_text]" value="<?php echo esc_attr((string) ($button['label_text'] ?? '')); ?>" class="regular-text"></label></p>
                        <p><label>icon_class <input type="text" name="lcni_button_style_config[<?php echo esc_attr($button_key); ?>][icon_class]" value="<?php echo esc_attr((string) ($button['icon_class'] ?? '')); ?>" class="regular-text" placeholder="fa-solid fa-filter"></label></p>
                        <p><label>icon_position
                            <select name="lcni_button_style_config[<?php echo esc_attr($button_key); ?>][icon_position]">
                                <option value="left" <?php selected((string) ($button['icon_position'] ?? 'left'), 'left'); ?>>left</option>
                                <option value="right" <?php selected((string) ($button['icon_position'] ?? 'left'), 'right'); ?>>right</option>
                            </select>
                        </label></p>
                        <p><label>background_color <input type="color" name="lcni_button_style_config[<?php echo esc_attr($button_key); ?>][background_color]" value="<?php echo esc_attr((string) ($button['background_color'] ?? '#2563eb')); ?>"></label></p>
                        <p><label>text_color <input type="color" name="lcni_button_style_config[<?php echo esc_attr($button_key); ?>][text_color]" value="<?php echo esc_attr((string) ($button['text_color'] ?? '#ffffff')); ?>"></label></p>
                        <p><label>hover_background_color <input type="color" name="lcni_button_style_config[<?php echo esc_attr($button_key); ?>][hover_background_color]" value="<?php echo esc_attr((string) ($button['hover_background_color'] ?? '#1d4ed8')); ?>"></label></p>
                        <p><label>hover_text_color <input type="color" name="lcni_button_style_config[<?php echo esc_attr($button_key); ?>][hover_text_color]" value="<?php echo esc_attr((string) ($button['hover_text_color'] ?? '#ffffff')); ?>"></label></p>
                        <p><label>border <input type="text" name="lcni_button_style_config[<?php echo esc_attr($button_key); ?>][border]" value="<?php echo esc_attr((string) ($button['border'] ?? '0')); ?>" placeholder="1px solid #d1d5db"></label></p>
                        <p><label>height <input type="text" name="lcni_button_style_config[<?php echo esc_attr($button_key); ?>][height]" value="<?php echo esc_attr((string) ($button['height'] ?? '36px')); ?>" placeholder="36px"></label></p>
                        <p><label>border_radius <input type="text" name="lcni_button_style_config[<?php echo esc_attr($button_key); ?>][border_radius]" value="<?php echo esc_attr((string) ($button['border_radius'] ?? '8px')); ?>" placeholder="8px"></label></p>
                        <p><label>padding_left_right <input type="text" name="lcni_button_style_config[<?php echo esc_attr($button_key); ?>][padding_left_right]" value="<?php echo esc_attr((string) ($button['padding_left_right'] ?? '12px')); ?>" placeholder="12px"></label></p>
                        <p><label>font_size <input type="text" name="lcni_button_style_config[<?php echo esc_attr($button_key); ?>][font_size]" value="<?php echo esc_attr((string) ($button['font_size'] ?? '14px')); ?>" placeholder="14px"></label></p>
                    </fieldset>
                <?php endforeach; ?>
                <?php submit_button('Save'); ?>
            </form>
        </div>
        <?php
    }

    private function render_global_column_label_form($module, $tab_id, $watchlist_settings) {
        $service = new LCNI_WatchlistService(new LCNI_WatchlistRepository());
        $all_columns = $service->get_all_columns();
        $configured = get_option('lcni_column_labels', $watchlist_settings['column_labels'] ?? []);
        $map = [];
        foreach ((array) $configured as $key => $item) {
            if (is_array($item)) {
                $data_key = sanitize_key($item['data_key'] ?? '');
                $label = sanitize_text_field((string) ($item['label'] ?? ''));
            } else {
                $data_key = sanitize_key($key);
                $label = sanitize_text_field((string) $item);
            }
            if ($data_key !== '' && $label !== '') {
                $map[$data_key] = $label;
            }
        }
        ?>
        <div id="<?php echo esc_attr($tab_id); ?>" class="lcni-sub-tab-content">
            <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=lcni-settings')); ?>" class="lcni-front-form">
                <?php wp_nonce_field('lcni_admin_actions', 'lcni_action_nonce'); ?>
                <input type="hidden" name="lcni_admin_action" value="save_frontend_settings">
                <input type="hidden" name="lcni_frontend_module" value="<?php echo esc_attr($module); ?>">
                <input type="hidden" name="lcni_redirect_tab" value="<?php echo esc_attr($tab_id); ?>">
                <h3>Global Column Label</h3>
                <table class="form-table" role="presentation"><tbody>
                <?php foreach ($all_columns as $column) : ?>
                    <tr>
                        <th><?php echo esc_html($column); ?></th>
                        <td>
                            <input type="hidden" name="lcni_global_column_label_key[]" value="<?php echo esc_attr($column); ?>">
                            <input type="text" name="lcni_global_column_label[]" value="<?php echo esc_attr((string) ($map[$column] ?? '')); ?>" class="regular-text" placeholder="<?php echo esc_attr($column); ?>">
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody></table>
                <?php submit_button('Save'); ?>
            </form>
        </div>
        <?php
    }

    private function sanitize_watchlist_settings($input) {
        $service = new LCNI_WatchlistService(new LCNI_WatchlistRepository());
        $all_columns = $service->get_all_columns();
        $default_columns = $service->get_default_columns();
        $allowed_columns = isset($input['allowed_columns']) && is_array($input['allowed_columns'])
            ? array_values(array_intersect($all_columns, array_map('sanitize_key', $input['allowed_columns'])))
            : $default_columns;

        if (empty($allowed_columns)) {
            $allowed_columns = $default_columns;
        }

        $default_columns_desktop = isset($input['default_columns_desktop']) && is_array($input['default_columns_desktop'])
            ? array_values(array_intersect($allowed_columns, array_map('sanitize_key', $input['default_columns_desktop'])))
            : [];
        if (empty($default_columns_desktop)) {
            $default_columns_desktop = array_slice($allowed_columns, 0, 6);
        }

        $default_columns_mobile = isset($input['default_columns_mobile']) && is_array($input['default_columns_mobile'])
            ? array_values(array_intersect($allowed_columns, array_map('sanitize_key', $input['default_columns_mobile'])))
            : [];
        if (empty($default_columns_mobile)) {
            $default_columns_mobile = array_slice($default_columns_desktop, 0, 4);
        }

        $styles = isset($input['styles']) && is_array($input['styles']) ? $input['styles'] : [];
        $button = isset($input['add_button']) && is_array($input['add_button']) ? $input['add_button'] : [];
        $add_form_button = isset($input['add_form_button']) && is_array($input['add_form_button']) ? $input['add_form_button'] : [];
        $label_keys = isset($input['column_label_keys']) && is_array($input['column_label_keys']) ? $input['column_label_keys'] : [];
        $label_values = isset($input['column_label_values']) && is_array($input['column_label_values']) ? $input['column_label_values'] : [];
        $rule_columns = isset($input['value_color_rule_columns']) && is_array($input['value_color_rule_columns']) ? $input['value_color_rule_columns'] : [];
        $rule_operators = isset($input['value_color_rule_operators']) && is_array($input['value_color_rule_operators']) ? $input['value_color_rule_operators'] : [];
        $rule_values = isset($input['value_color_rule_values']) && is_array($input['value_color_rule_values']) ? $input['value_color_rule_values'] : [];
        $rule_bg_colors = isset($input['value_color_rule_bg_colors']) && is_array($input['value_color_rule_bg_colors']) ? $input['value_color_rule_bg_colors'] : [];
        $rule_text_colors = isset($input['value_color_rule_text_colors']) && is_array($input['value_color_rule_text_colors']) ? $input['value_color_rule_text_colors'] : [];
        $column_labels = [];
        $value_color_rules = [];
        if (empty($label_keys) && empty($label_values) && isset($input['column_labels'])) {
            $legacy_label_pairs = $this->normalize_watchlist_column_label_pairs($input['column_labels']);
            $label_keys = wp_list_pluck($legacy_label_pairs, 'data_key');
            $label_values = wp_list_pluck($legacy_label_pairs, 'label');
        }
        $label_count = max(count($label_keys), count($label_values));
        $allowed_operators = ['>', '>=', '<', '<=', '=', '!='];

        for ($index = 0; $index < $label_count; $index++) {
            $key = sanitize_key($label_keys[$index] ?? '');
            if ($key === '' || !in_array($key, $all_columns, true)) {
                continue;
            }

            $label = sanitize_text_field((string) ($label_values[$index] ?? ''));
            if ($label === '') {
                continue;
            }

            $column_labels[] = [
                'data_key' => $key,
                'label' => $label,
            ];
        }

        $rule_count = max(count($rule_columns), count($rule_operators), count($rule_values), count($rule_bg_colors), count($rule_text_colors));
        for ($index = 0; $index < $rule_count; $index++) {
            $column = sanitize_key($rule_columns[$index] ?? '');
            $operator = sanitize_text_field((string) ($rule_operators[$index] ?? ''));
            $value = trim(sanitize_text_field((string) ($rule_values[$index] ?? '')));
            $bg_color = sanitize_hex_color((string) ($rule_bg_colors[$index] ?? ''));
            $text_color = sanitize_hex_color((string) ($rule_text_colors[$index] ?? ''));

            if ($column === '' || !in_array($column, $all_columns, true) || !in_array($operator, $allowed_operators, true) || $value === '' || !$bg_color || !$text_color) {
                continue;
            }

            $value_color_rules[] = [
                'column' => $column,
                'operator' => $operator,
                'value' => is_numeric($value) ? (float) $value : $value,
                'bg_color' => $bg_color,
                'text_color' => $text_color,
            ];
        }

        $stock_detail_page_id = absint($input['stock_detail_page_id'] ?? get_option('lcni_frontend_stock_detail_page', 0));
        $stock_detail_page_slug = '';
        if ($stock_detail_page_id > 0) {
            $selected_page = get_post($stock_detail_page_id);
            if ($selected_page instanceof WP_Post && $selected_page->post_type === 'page') {
                $stock_detail_page_slug = sanitize_title((string) $selected_page->post_name);
            }
        }

        return [
            'allowed_columns' => $allowed_columns,
            'default_columns_desktop' => $default_columns_desktop,
            'default_columns_mobile' => $default_columns_mobile,
            'column_labels' => $column_labels,
            'stock_detail_page_id' => $stock_detail_page_id,
            'stock_detail_page_slug' => $stock_detail_page_slug,
            'styles' => [
                'font' => sanitize_text_field($styles['font'] ?? 'inherit'),
                'text_color' => sanitize_hex_color($styles['text_color'] ?? '#111827') ?: '#111827',
                'background' => sanitize_hex_color($styles['background'] ?? '#ffffff') ?: '#ffffff',
                'border' => sanitize_text_field($styles['border'] ?? '1px solid #e5e7eb'),
                'border_radius' => max(0, min(24, (int) ($styles['border_radius'] ?? 8))),
                'label_font_size' => max(10, min(30, (int) ($styles['label_font_size'] ?? 12))),
                'row_font_size' => max(10, min(30, (int) ($styles['row_font_size'] ?? 13))),
                'header_background' => sanitize_hex_color($styles['header_background'] ?? '#ffffff') ?: '#ffffff',
                'header_text_color' => sanitize_hex_color($styles['header_text_color'] ?? '#111827') ?: '#111827',
                'value_background' => sanitize_hex_color($styles['value_background'] ?? '#ffffff') ?: '#ffffff',
                'value_text_color' => sanitize_hex_color($styles['value_text_color'] ?? '#111827') ?: '#111827',
                'row_divider_color' => sanitize_hex_color($styles['row_divider_color'] ?? '#e5e7eb') ?: '#e5e7eb',
                'row_divider_width' => max(1, min(6, (int) ($styles['row_divider_width'] ?? 1))),
                'row_hover_bg' => sanitize_hex_color($styles['row_hover_bg'] ?? '#f3f4f6') ?: '#f3f4f6',
                'head_height' => max(24, min(240, (int) ($styles['head_height'] ?? 40))),
                'sticky_column' => sanitize_key($styles['sticky_column'] ?? 'symbol'),
                'sticky_header' => !empty($styles['sticky_header']) ? 1 : 0,
                'dropdown_height' => max(28, min(80, (int) ($styles['dropdown_height'] ?? 34))),
                'dropdown_width' => max(120, min(520, (int) ($styles['dropdown_width'] ?? 220))),
                'dropdown_font_size' => max(10, min(24, (int) ($styles['dropdown_font_size'] ?? 13))),
                'dropdown_border_color' => sanitize_hex_color($styles['dropdown_border_color'] ?? '#d1d5db') ?: '#d1d5db',
                'dropdown_border_radius' => max(0, min(24, (int) ($styles['dropdown_border_radius'] ?? 8))),
                'input_height' => max(28, min(80, (int) ($styles['input_height'] ?? 34))),
                'input_width' => max(120, min(520, (int) ($styles['input_width'] ?? 160))),
                'input_font_size' => max(10, min(24, (int) ($styles['input_font_size'] ?? 13))),
                'input_border_color' => sanitize_hex_color($styles['input_border_color'] ?? '#d1d5db') ?: '#d1d5db',
                'input_border_radius' => max(0, min(24, (int) ($styles['input_border_radius'] ?? 8))),
                'scroll_speed' => max(1, min(5, (int) ($styles['scroll_speed'] ?? 1))),
                'column_order' => array_values(array_map('sanitize_key', is_array($styles['column_order'] ?? null) ? $styles['column_order'] : [])),
            ],
            'value_color_rules' => array_slice($value_color_rules, 0, 100),
            'add_button' => [
                'icon' => sanitize_text_field($button['icon'] ?? 'fa-solid fa-heart-circle-plus'),
                'background' => sanitize_hex_color($button['background'] ?? '#dc2626') ?: '#dc2626',
                'text_color' => sanitize_hex_color($button['text_color'] ?? '#ffffff') ?: '#ffffff',
                'font_size' => max(10, min(24, (int) ($button['font_size'] ?? 14))),
                'size' => max(20, min(48, (int) ($button['size'] ?? 26))),
            ],
            'add_form_button' => [
                'icon' => sanitize_text_field($add_form_button['icon'] ?? 'fa-solid fa-heart-circle-plus'),
                'background' => sanitize_hex_color($add_form_button['background'] ?? '#2563eb') ?: '#2563eb',
                'text_color' => sanitize_hex_color($add_form_button['text_color'] ?? '#ffffff') ?: '#ffffff',
                'font_size' => max(10, min(24, (int) ($add_form_button['font_size'] ?? 14))),
                'height' => max(28, min(56, (int) ($add_form_button['height'] ?? 34))),
            ],
        ];
    }

private function render_frontend_watchlist_form($module, $tab_id, $settings) {
        $service = new LCNI_WatchlistService(new LCNI_WatchlistRepository());
        $all_columns = $service->get_all_columns();
        $pages = get_pages(['sort_column' => 'post_title', 'sort_order' => 'asc']);
        $configured_labels = [];

        foreach ($this->normalize_watchlist_column_label_pairs($settings['column_labels'] ?? []) as $label_item) {
            $configured_labels[$label_item['data_key']] = $label_item['label'];
        }

        $watchlist_rules = isset($settings['value_color_rules']) && is_array($settings['value_color_rules']) ? $settings['value_color_rules'] : [];
        ?>
        <div id="<?php echo esc_attr($tab_id); ?>" class="lcni-sub-tab-content">
            <div class="lcni-sub-tab-nav" id="lcni-watchlist-sub-tabs">
                <button type="button" data-watchlist-sub-tab="lcni-watchlist-columns">Columns</button>
                <button type="button" data-watchlist-sub-tab="lcni-watchlist-stock-detail-page">Stock Detail Page</button>
                <button type="button" data-watchlist-sub-tab="lcni-watchlist-default-columns">Default Columns for User</button>
                <button type="button" data-watchlist-sub-tab="lcni-watchlist-style-config">Style Config</button>
            </div>

            <div id="lcni-watchlist-columns" class="lcni-watchlist-pane">
                <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=lcni-settings')); ?>" class="lcni-front-form">
                    <?php wp_nonce_field('lcni_admin_actions', 'lcni_action_nonce'); ?>
                    <input type="hidden" name="lcni_admin_action" value="save_frontend_settings">
                    <input type="hidden" name="lcni_frontend_module" value="<?php echo esc_attr($module); ?>">
                    <input type="hidden" name="lcni_frontend_watchlist_section" value="columns">
                    <input type="hidden" name="lcni_redirect_tab" value="<?php echo esc_attr($tab_id); ?>">
                    <h3>Watchlist: danh sách cột cho phép user chọn</h3>
                    <div class="lcni-front-grid">
                        <?php foreach ($all_columns as $column) : ?>
                            <label><input type="checkbox" name="lcni_frontend_watchlist_allowed_columns[]" value="<?php echo esc_attr($column); ?>" <?php checked(in_array($column, (array) ($settings['allowed_columns'] ?? []), true)); ?>> <?php echo esc_html($column); ?></label>
                        <?php endforeach; ?>
                    </div>
                    <?php submit_button('Save'); ?>
                </form>
            </div>

            <div id="lcni-watchlist-stock-detail-page" class="lcni-watchlist-pane">
                <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=lcni-settings')); ?>" class="lcni-front-form">
                    <?php wp_nonce_field('lcni_admin_actions', 'lcni_action_nonce'); ?>
                    <input type="hidden" name="lcni_admin_action" value="save_frontend_settings">
                    <input type="hidden" name="lcni_frontend_module" value="<?php echo esc_attr($module); ?>">
                    <input type="hidden" name="lcni_frontend_watchlist_section" value="stock_detail_page">
                    <input type="hidden" name="lcni_redirect_tab" value="<?php echo esc_attr($tab_id); ?>">
                    <h3>Stock Detail Page</h3>
                    <p>
                        <select name="lcni_frontend_stock_detail_page">
                            <option value="0">-- Chọn page template --</option>
                            <?php foreach ($pages as $page) : ?>
                                <option value="<?php echo esc_attr((string) $page->ID); ?>" <?php selected((int) ($settings['stock_detail_page_id'] ?? 0), (int) $page->ID); ?>><?php echo esc_html($page->post_title . ' (#' . $page->ID . ')'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </p>
                    <?php submit_button('Save'); ?>
                </form>
            </div>

            <div id="lcni-watchlist-default-columns" class="lcni-watchlist-pane">
                <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=lcni-settings')); ?>" class="lcni-front-form">
                    <?php wp_nonce_field('lcni_admin_actions', 'lcni_action_nonce'); ?>
                    <input type="hidden" name="lcni_admin_action" value="save_frontend_settings">
                    <input type="hidden" name="lcni_frontend_module" value="<?php echo esc_attr($module); ?>">
                    <input type="hidden" name="lcni_frontend_watchlist_section" value="default_columns">
                    <input type="hidden" name="lcni_redirect_tab" value="<?php echo esc_attr($tab_id); ?>">
                    <h3>Default Columns for User</h3>
                    <p class="description">Global default (admin) ở đây; user override được lưu riêng qua API /watchlist/settings.</p>
                    <p><strong>Desktop (global default)</strong></p>
                    <div class="lcni-front-grid">
                        <?php foreach ((array) ($settings['allowed_columns'] ?? []) as $column) : ?>
                            <label><input type="checkbox" name="lcni_frontend_watchlist_default_columns_desktop[]" value="<?php echo esc_attr($column); ?>" <?php checked(in_array($column, (array) ($settings['default_columns_desktop'] ?? []), true)); ?>> <?php echo esc_html($column); ?></label>
                        <?php endforeach; ?>
                    </div>
                    <p><strong>Mobile (global default)</strong></p>
                    <div class="lcni-front-grid">
                        <?php foreach ((array) ($settings['allowed_columns'] ?? []) as $column) : ?>
                            <label><input type="checkbox" name="lcni_frontend_watchlist_default_columns_mobile[]" value="<?php echo esc_attr($column); ?>" <?php checked(in_array($column, (array) ($settings['default_columns_mobile'] ?? []), true)); ?>> <?php echo esc_html($column); ?></label>
                        <?php endforeach; ?>
                    </div>
                    <?php submit_button('Save'); ?>
                </form>
            </div>

            <div id="lcni-watchlist-column-labels" class="lcni-watchlist-pane">
                <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=lcni-settings')); ?>" class="lcni-front-form">
                    <?php wp_nonce_field('lcni_admin_actions', 'lcni_action_nonce'); ?>
                    <input type="hidden" name="lcni_admin_action" value="save_frontend_settings">
                    <input type="hidden" name="lcni_frontend_module" value="<?php echo esc_attr($module); ?>">
                    <input type="hidden" name="lcni_frontend_watchlist_section" value="column_labels">
                    <input type="hidden" name="lcni_redirect_tab" value="<?php echo esc_attr($tab_id); ?>">
                    <h3>Column Labels</h3>
                    <p>Admin có thể đặt label hiển thị cho từng cột (để trống sẽ dùng tên mặc định).</p>
                    <table class="form-table" role="presentation"><tbody>
                        <?php foreach ($all_columns as $column) : ?>
                            <tr>
                                <th><?php echo esc_html($column); ?></th>
                                <td>
                                    <input type="hidden" name="lcni_frontend_watchlist_column_label_key[]" value="<?php echo esc_attr($column); ?>">
                                    <input type="text" name="lcni_frontend_watchlist_column_label[]" value="<?php echo esc_attr((string) ($configured_labels[$column] ?? '')); ?>" class="regular-text" placeholder="<?php echo esc_attr($column); ?>">
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody></table>
                    <?php submit_button('Save'); ?>
                </form>
            </div>

            <div id="lcni-watchlist-style-config" class="lcni-watchlist-pane">
                <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=lcni-settings')); ?>" class="lcni-front-form">
                    <?php wp_nonce_field('lcni_admin_actions', 'lcni_action_nonce'); ?>
                    <input type="hidden" name="lcni_admin_action" value="save_frontend_settings">
                    <input type="hidden" name="lcni_frontend_module" value="<?php echo esc_attr($module); ?>">
                    <input type="hidden" name="lcni_frontend_watchlist_section" value="style_config">
                    <input type="hidden" name="lcni_redirect_tab" value="<?php echo esc_attr($tab_id); ?>">

                    <h3>Style config</h3>
                    <p><input type="text" name="lcni_frontend_watchlist_style_font" value="<?php echo esc_attr((string) ($settings['styles']['font'] ?? 'inherit')); ?>" placeholder="Font family"></p>
                    <p><label>Text color <input type="color" name="lcni_frontend_watchlist_style_text_color" value="<?php echo esc_attr((string) ($settings['styles']['text_color'] ?? '#111827')); ?>"></label></p>
                    <p><label>Background <input type="color" name="lcni_frontend_watchlist_style_background" value="<?php echo esc_attr((string) ($settings['styles']['background'] ?? '#ffffff')); ?>"></label></p>
                    <p><label>Border <input type="text" name="lcni_frontend_watchlist_style_border" value="<?php echo esc_attr((string) ($settings['styles']['border'] ?? '1px solid #e5e7eb')); ?>"></label></p>
                    <p><label>Border radius <input type="number" min="0" max="24" name="lcni_frontend_watchlist_style_border_radius" value="<?php echo esc_attr((string) ($settings['styles']['border_radius'] ?? 8)); ?>"></label></p>
                    <p><label>Header label font size <input type="number" min="10" max="30" name="lcni_frontend_watchlist_style_label_font_size" value="<?php echo esc_attr((string) ($settings['styles']['label_font_size'] ?? 12)); ?>"> px</label></p>
                    <p><label>Row font size <input type="number" min="10" max="30" name="lcni_frontend_watchlist_style_row_font_size" value="<?php echo esc_attr((string) ($settings['styles']['row_font_size'] ?? 13)); ?>"> px</label></p>
                    <p><label>Header background <input type="color" name="lcni_frontend_watchlist_style_header_background" value="<?php echo esc_attr((string) ($settings['styles']['header_background'] ?? '#ffffff')); ?>"></label></p>
                    <p><label>Header text color <input type="color" name="lcni_frontend_watchlist_style_header_text_color" value="<?php echo esc_attr((string) ($settings['styles']['header_text_color'] ?? '#111827')); ?>"></label></p>
                    <p><label>Value background <input type="color" name="lcni_frontend_watchlist_style_value_background" value="<?php echo esc_attr((string) ($settings['styles']['value_background'] ?? '#ffffff')); ?>"></label></p>
                    <p><label>Value text color <input type="color" name="lcni_frontend_watchlist_style_value_text_color" value="<?php echo esc_attr((string) ($settings['styles']['value_text_color'] ?? '#111827')); ?>"></label></p>
                    <p><label>Row divider color <input type="color" name="lcni_frontend_watchlist_style_row_divider_color" value="<?php echo esc_attr((string) ($settings['styles']['row_divider_color'] ?? '#e5e7eb')); ?>"></label></p>
                    <p><label>Row divider width <input type="number" min="1" max="6" name="lcni_frontend_watchlist_style_row_divider_width" value="<?php echo esc_attr((string) ($settings['styles']['row_divider_width'] ?? 1)); ?>"> px</label></p>
                    <p><label>Row hover background <input type="color" name="lcni_frontend_watchlist_style_row_hover_bg" value="<?php echo esc_attr((string) ($settings['styles']['row_hover_bg'] ?? '#f3f4f6')); ?>"></label></p>
                    <p><label>Header row height <input type="number" min="1" max="240" name="lcni_frontend_watchlist_style_head_height" value="<?php echo esc_attr((string) ($settings['styles']['head_height'] ?? 40)); ?>"> px</label></p>
                    <p><label>Sticky column
                        <select name="lcni_frontend_watchlist_style_sticky_column">
                            <option value="first" <?php selected((string) ($settings['styles']['sticky_column'] ?? 'symbol'), 'first'); ?>>First column</option>
                            <?php foreach ((array) ($settings['allowed_columns'] ?? []) as $sticky_col) : ?>
                                <option value="<?php echo esc_attr($sticky_col); ?>" <?php selected((string) ($settings['styles']['sticky_column'] ?? 'symbol'), (string) $sticky_col); ?>><?php echo esc_html($sticky_col); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label></p>
                    <p><label><input type="checkbox" name="lcni_frontend_watchlist_style_sticky_header" value="1" <?php checked((int) ($settings['styles']['sticky_header'] ?? 1), 1); ?>> Sticky header row</label></p>
                    <h4>Watchlist dropdown style</h4>
                    <p><label>Height <input type="number" min="28" max="80" name="lcni_frontend_watchlist_style_dropdown_height" value="<?php echo esc_attr((string) ($settings['styles']['dropdown_height'] ?? 34)); ?>"> px</label></p>
                    <p><label>Width <input type="number" min="120" max="520" name="lcni_frontend_watchlist_style_dropdown_width" value="<?php echo esc_attr((string) ($settings['styles']['dropdown_width'] ?? 220)); ?>"> px</label></p>
                    <p><label>Font size <input type="number" min="10" max="24" name="lcni_frontend_watchlist_style_dropdown_font_size" value="<?php echo esc_attr((string) ($settings['styles']['dropdown_font_size'] ?? 13)); ?>"> px</label></p>
                    <p><label>Border color <input type="color" name="lcni_frontend_watchlist_style_dropdown_border_color" value="<?php echo esc_attr((string) ($settings['styles']['dropdown_border_color'] ?? '#d1d5db')); ?>"></label></p>
                    <p><label>Border radius <input type="number" min="0" max="24" name="lcni_frontend_watchlist_style_dropdown_border_radius" value="<?php echo esc_attr((string) ($settings['styles']['dropdown_border_radius'] ?? 8)); ?>"> px</label></p>
                    <h4>Symbol input style</h4>
                    <p><label>Height <input type="number" min="28" max="80" name="lcni_frontend_watchlist_style_input_height" value="<?php echo esc_attr((string) ($settings['styles']['input_height'] ?? 34)); ?>"> px</label></p>
                    <p><label>Width <input type="number" min="120" max="520" name="lcni_frontend_watchlist_style_input_width" value="<?php echo esc_attr((string) ($settings['styles']['input_width'] ?? 160)); ?>"> px</label></p>
                    <p><label>Font size <input type="number" min="10" max="24" name="lcni_frontend_watchlist_style_input_font_size" value="<?php echo esc_attr((string) ($settings['styles']['input_font_size'] ?? 13)); ?>"> px</label></p>
                    <p><label>Border color <input type="color" name="lcni_frontend_watchlist_style_input_border_color" value="<?php echo esc_attr((string) ($settings['styles']['input_border_color'] ?? '#d1d5db')); ?>"></label></p>
                    <p><label>Border radius <input type="number" min="0" max="24" name="lcni_frontend_watchlist_style_input_border_radius" value="<?php echo esc_attr((string) ($settings['styles']['input_border_radius'] ?? 8)); ?>"> px</label></p>
                    <p><label>Table horizontal scroll speed <input type="number" min="1" max="5" name="lcni_frontend_watchlist_style_scroll_speed" value="<?php echo esc_attr((string) ($settings['styles']['scroll_speed'] ?? 1)); ?>"></label></p>

                    <h3>Conditional value colors</h3>
                    <p class="description">Mặc định hiển thị 5 rule. Nếu cần thêm, bấm nút "Thêm rule".</p>
                    <table class="form-table" role="presentation"><tbody id="lcni-watchlist-rule-rows">
                        <?php for ($i = 0; $i < max(5, count($watchlist_rules)); $i++) :
                            $rule = $watchlist_rules[$i] ?? [];
                            $rule_column = (string) ($rule['column'] ?? '');
                            $rule_operator = (string) ($rule['operator'] ?? '>');
                            $rule_value = (string) ($rule['value'] ?? '');
                            $rule_bg = (string) ($rule['bg_color'] ?? '#16a34a');
                            $rule_text = (string) ($rule['text_color'] ?? '#ffffff');
                            ?>
                            <tr>
                                <td>
                                    <select name="lcni_watchlist_value_color_rule_column[]">
                                        <option value="">-- Column --</option>
                                        <?php foreach ($all_columns as $column) : ?>
                                            <option value="<?php echo esc_attr($column); ?>" <?php selected($rule_column, $column); ?>><?php echo esc_html($column); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td>
                                    <select name="lcni_watchlist_value_color_rule_operator[]">
                                        <?php foreach (['>', '>=', '<', '<=', '=', '!='] as $operator) : ?>
                                            <option value="<?php echo esc_attr($operator); ?>" <?php selected($rule_operator, $operator); ?>><?php echo esc_html($operator); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td><input type="text" name="lcni_watchlist_value_color_rule_value[]" value="<?php echo esc_attr($rule_value); ?>" placeholder="70"></td>
                                <td><input type="color" name="lcni_watchlist_value_color_rule_bg_color[]" value="<?php echo esc_attr($rule_bg); ?>"></td>
                                <td><input type="color" name="lcni_watchlist_value_color_rule_text_color[]" value="<?php echo esc_attr($rule_text); ?>"></td>
                            </tr>
                        <?php endfor; ?>
                    </tbody></table>
                    <p><button type="button" class="button" id="lcni-add-watchlist-rule">Thêm rule</button></p>

                    <template id="lcni-watchlist-rule-template">
                        <tr>
                            <td>
                                <select name="lcni_watchlist_value_color_rule_column[]">
                                    <option value="">-- Column --</option>
                                    <?php foreach ($all_columns as $column) : ?>
                                        <option value="<?php echo esc_attr($column); ?>"><?php echo esc_html($column); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <select name="lcni_watchlist_value_color_rule_operator[]">
                                    <?php foreach (['>', '>=', '<', '<=', '=', '!='] as $operator) : ?>
                                        <option value="<?php echo esc_attr($operator); ?>"><?php echo esc_html($operator); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td><input type="text" name="lcni_watchlist_value_color_rule_value[]" value="" placeholder="70"></td>
                            <td><input type="color" name="lcni_watchlist_value_color_rule_bg_color[]" value="#16a34a"></td>
                            <td><input type="color" name="lcni_watchlist_value_color_rule_text_color[]" value="#ffffff"></td>
                        </tr>
                    </template>

                    <h3>Add to watchlist button style</h3>
                    <p><label>FontAwesome icon <input type="text" name="lcni_frontend_watchlist_btn_icon" value="<?php echo esc_attr((string) ($settings['add_button']['icon'] ?? 'fa-solid fa-heart-circle-plus')); ?>"></label></p>
                    <p><label>Background <input type="color" name="lcni_frontend_watchlist_btn_background" value="<?php echo esc_attr((string) ($settings['add_button']['background'] ?? '#dc2626')); ?>"></label></p>
                    <p><label>Text color <input type="color" name="lcni_frontend_watchlist_btn_text_color" value="<?php echo esc_attr((string) ($settings['add_button']['text_color'] ?? '#ffffff')); ?>"></label></p>
                    <p><label>Font size <input type="number" min="10" max="24" name="lcni_frontend_watchlist_btn_font_size" value="<?php echo esc_attr((string) ($settings['add_button']['font_size'] ?? 14)); ?>"></label></p>
                    <p><label>Kích thước nút <input type="number" min="20" max="48" name="lcni_frontend_watchlist_btn_size" value="<?php echo esc_attr((string) ($settings['add_button']['size'] ?? 26)); ?>"> px</label></p>

                    <h3>Shortcode button: [lcni_watchlist_add_form]</h3>
                    <p><label>Icon <input type="text" name="lcni_frontend_watchlist_form_btn_icon" value="<?php echo esc_attr((string) ($settings['add_form_button']['icon'] ?? 'fa-solid fa-heart-circle-plus')); ?>"></label></p>
                    <p><label>Background <input type="color" name="lcni_frontend_watchlist_form_btn_background" value="<?php echo esc_attr((string) ($settings['add_form_button']['background'] ?? '#2563eb')); ?>"></label></p>
                    <p><label>Text color <input type="color" name="lcni_frontend_watchlist_form_btn_text_color" value="<?php echo esc_attr((string) ($settings['add_form_button']['text_color'] ?? '#ffffff')); ?>"></label></p>
                    <p><label>Font size <input type="number" min="10" max="24" name="lcni_frontend_watchlist_form_btn_font_size" value="<?php echo esc_attr((string) ($settings['add_form_button']['font_size'] ?? 14)); ?>"></label></p>
                    <p><label>Chiều cao nút <input type="number" min="28" max="56" name="lcni_frontend_watchlist_form_btn_height" value="<?php echo esc_attr((string) ($settings['add_form_button']['height'] ?? 34)); ?>"> px</label></p>
                    <?php submit_button('Save'); ?>
                </form>
            </div>

            <script>
                (function () {
                    const addRuleBtn = document.getElementById('lcni-add-watchlist-rule');
                    const rows = document.getElementById('lcni-watchlist-rule-rows');
                    const template = document.getElementById('lcni-watchlist-rule-template');
                    if (addRuleBtn && rows && template) {
                        addRuleBtn.addEventListener('click', function () {
                            rows.insertAdjacentHTML('beforeend', template.innerHTML);
                        });
                    }

                    const nav = document.getElementById('lcni-watchlist-sub-tabs');
                    if (!nav) {
                        return;
                    }

                    const buttons = nav.querySelectorAll('button[data-watchlist-sub-tab]');
                    const panes = document.querySelectorAll('.lcni-watchlist-pane');
                    const url = new URL(window.location.href);
                    const current = url.searchParams.get('watchlist_tab') || 'lcni-watchlist-columns';
                    const validTabs = ['lcni-watchlist-columns', 'lcni-watchlist-stock-detail-page', 'lcni-watchlist-default-columns', 'lcni-watchlist-style-config'];

                    const activate = function (tabId) {
                        const resolvedTab = validTabs.includes(tabId) ? tabId : 'lcni-watchlist-columns';
                        buttons.forEach((btn) => btn.classList.toggle('active', btn.getAttribute('data-watchlist-sub-tab') === resolvedTab));
                        panes.forEach((pane) => pane.classList.toggle('active', pane.id === resolvedTab));
                        const nextUrl = new URL(window.location.href);
                        nextUrl.searchParams.set('watchlist_tab', resolvedTab);
                        window.history.replaceState({}, '', nextUrl.toString());
                    };

                    buttons.forEach((btn) => {
                        btn.addEventListener('click', function () {
                            activate(btn.getAttribute('data-watchlist-sub-tab'));
                        });
                    });

                    activate(current);
                })();
            </script>
        </div>
        <?php
    }

    private function normalize_watchlist_column_label_pairs($labels) {
        if (!is_array($labels)) {
            return [];
        }

        $normalized = [];
        foreach ($labels as $key => $item) {
            if (is_array($item)) {
                $data_key = sanitize_key($item['data_key'] ?? '');
                $label = sanitize_text_field((string) ($item['label'] ?? ''));
            } else {
                $data_key = sanitize_key($key);
                $label = sanitize_text_field((string) $item);
            }

            if ($data_key === '' || $label === '') {
                continue;
            }

            $normalized[] = [
                'data_key' => $data_key,
                'label' => $label,
            ];
        }

        return $normalized;
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
                <p><label>Tên module <input type="text" name="lcni_frontend_module_title" value="<?php echo esc_attr((string) get_option($module === 'signals' ? 'lcni_frontend_signal_title' : 'lcni_frontend_overview_title', $module === 'signals' ? 'LCNi Signals' : 'Stock Overview')); ?>" class="regular-text"></label></p>
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
        $chart_sync_enabled = !array_key_exists('chart_sync_enabled', $settings) || !empty($settings['chart_sync_enabled']);
        $fit_to_screen_on_load = !array_key_exists('fit_to_screen_on_load', $settings) || !empty($settings['fit_to_screen_on_load']);
        $default_indicators = [
            'default_ma20' => !array_key_exists('default_ma20', $settings) || !empty($settings['default_ma20']),
            'default_ma50' => !array_key_exists('default_ma50', $settings) || !empty($settings['default_ma50']),
            'default_rsi' => !array_key_exists('default_rsi', $settings) || !empty($settings['default_rsi']),
            'default_macd' => !empty($settings['default_macd']),
            'default_rs_1w_by_exchange' => !array_key_exists('default_rs_1w_by_exchange', $settings) || !empty($settings['default_rs_1w_by_exchange']),
            'default_rs_1m_by_exchange' => !array_key_exists('default_rs_1m_by_exchange', $settings) || !empty($settings['default_rs_1m_by_exchange']),
            'default_rs_3m_by_exchange' => !empty($settings['default_rs_3m_by_exchange']),
        ];
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
                <p><label>Tên module <input type="text" name="lcni_frontend_module_title" value="<?php echo esc_attr((string) get_option('lcni_frontend_chart_title', 'Stock Chart')); ?>" class="regular-text"></label></p>
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
                    <tr>
                        <th>Mặc định số nến hiển thị</th>
                        <td><input type="number" min="20" max="1000" name="lcni_frontend_default_visible_bars" value="<?php echo esc_attr((string) ($settings['default_visible_bars'] ?? 120)); ?>"> bars</td>
                    </tr>
                    <tr>
                        <th>Đồng bộ zoom/scroll giữa các panel</th>
                        <td><label><input type="checkbox" name="lcni_frontend_chart_sync_enabled" value="1" <?php checked($chart_sync_enabled); ?>> Bật sync visible range</label></td>
                    </tr>
                    <tr>
                        <th>Fit to screen on load</th>
                        <td><label><input type="checkbox" name="lcni_frontend_fit_to_screen_on_load" value="1" <?php checked($fit_to_screen_on_load); ?>> Enable "Fit to screen on load"</label></td>
                    </tr>
                    <tr>
                        <th>Default enabled indicators</th>
                        <td>
                            <div class="lcni-front-grid">
                                <label><input type="checkbox" name="lcni_frontend_default_ma20" value="1" <?php checked($default_indicators['default_ma20']); ?>> MA20</label>
                                <label><input type="checkbox" name="lcni_frontend_default_ma50" value="1" <?php checked($default_indicators['default_ma50']); ?>> MA50</label>
                                <label><input type="checkbox" name="lcni_frontend_default_rsi" value="1" <?php checked($default_indicators['default_rsi']); ?>> RSI</label>
                                <label><input type="checkbox" name="lcni_frontend_default_macd" value="1" <?php checked($default_indicators['default_macd']); ?>> MACD</label>
                                <label><input type="checkbox" name="lcni_frontend_default_rs_1w_by_exchange" value="1" <?php checked($default_indicators['default_rs_1w_by_exchange']); ?>> RS 1W</label>
                                <label><input type="checkbox" name="lcni_frontend_default_rs_1m_by_exchange" value="1" <?php checked($default_indicators['default_rs_1m_by_exchange']); ?>> RS 1M</label>
                                <label><input type="checkbox" name="lcni_frontend_default_rs_3m_by_exchange" value="1" <?php checked($default_indicators['default_rs_3m_by_exchange']); ?>> RS 3M</label>
                            </div>
                        </td>
                    </tr>
                </tbody></table>
                <?php submit_button('Lưu Frontend Settings'); ?>
            </form>
        </div>
        <?php
    }


    private function render_frontend_chart_analyst_form($module, $tab_id, $settings) {
        $templates = (array) ($settings['templates'] ?? []);
        $default_template = (array) ($settings['default_template'] ?? []);
        $indicator_labels = [
            'ma20' => 'MA20',
            'ma50' => 'MA50',
            'ma100' => 'MA100',
            'ma200' => 'MA200',
            'rsi' => 'RSI(14)',
            'macd' => 'MACD(12,26,9)',
            'rs_1w_by_exchange' => 'RS 1W',
            'rs_1m_by_exchange' => 'RS 1M',
            'rs_3m_by_exchange' => 'RS 3M',
        ];
        $context_labels = [
            'stock_detail' => 'Stock Detail',
            'dashboard' => 'Dashboard',
            'watchlist' => 'Watchlist',
        ];
        ?>
        <div id="<?php echo esc_attr($tab_id); ?>" class="lcni-sub-tab-content">
            <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=lcni-settings')); ?>" class="lcni-front-form">
                <?php wp_nonce_field('lcni_admin_actions', 'lcni_action_nonce'); ?>
                <input type="hidden" name="lcni_redirect_tab" value="<?php echo esc_attr($tab_id); ?>">
                <input type="hidden" name="lcni_admin_action" value="save_frontend_settings">
                <input type="hidden" name="lcni_frontend_module" value="<?php echo esc_attr($module); ?>">
                <h3>Chart Analyst</h3>
                <p>Thiết lập template chỉ báo mặc định cho các ngữ cảnh hiển thị frontend.</p>
                <?php foreach ($templates as $template_key => $template_config) :
                    $template_label = (string) ($template_config['label'] ?? $template_key);
                    $enabled_indicators = isset($template_config['indicators']) && is_array($template_config['indicators']) ? $template_config['indicators'] : [];
                    ?>
                    <h4><?php echo esc_html($template_label); ?></h4>
                    <p><label>Tên template <input type="text" name="lcni_chart_analyst_template_label[<?php echo esc_attr($template_key); ?>]" value="<?php echo esc_attr($template_label); ?>" class="regular-text"></label></p>
                    <div class="lcni-front-grid" style="margin-bottom:16px;">
                        <?php foreach ($indicator_labels as $indicator_key => $indicator_label) : ?>
                            <label><input type="checkbox" name="lcni_chart_analyst_template_indicators[<?php echo esc_attr($template_key); ?>][]" value="<?php echo esc_attr($indicator_key); ?>" <?php checked(in_array($indicator_key, $enabled_indicators, true)); ?>> <?php echo esc_html($indicator_label); ?></label>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>

                <h4>Default template by context</h4>
                <table class="form-table" role="presentation"><tbody>
                    <?php foreach ($context_labels as $context_key => $context_label) : ?>
                        <tr>
                            <th><?php echo esc_html($context_label); ?></th>
                            <td>
                                <select name="lcni_chart_analyst_default_template_<?php echo esc_attr($context_key); ?>">
                                    <?php foreach ($templates as $template_key => $template_config) : ?>
                                        <option value="<?php echo esc_attr($template_key); ?>" <?php selected((string) ($default_template[$context_key] ?? ''), (string) $template_key); ?>>
                                            <?php echo esc_html((string) ($template_config['label'] ?? $template_key)); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                    <?php endforeach; ?>
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
