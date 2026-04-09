<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class LCNI_Recommend_Rules_List_Table extends WP_List_Table {
    private $items_data;

    public function __construct($items_data) {
        parent::__construct(['singular' => 'rule', 'plural' => 'rules', 'ajax' => false]);
        $this->items_data = $items_data;
    }

    public function get_columns() {
        return [
            'id' => 'ID',
            'name' => 'Name',
            'timeframe' => 'Timeframe',
            'description' => 'Description',
            'is_active' => 'Active',
            'add_at_r' => 'Add at R',
            'exit_at_r' => 'Exit at R',
            'max_hold_days' => 'Max hold',
        ];
    }

    public function prepare_items() {
        $columns = $this->get_columns();
        $hidden = [];
        $sortable = [];
        $this->_column_headers = [$columns, $hidden, $sortable];
        $this->items = $this->items_data;
    }

    protected function column_default($item, $column_name) {
        return esc_html((string) ($item[$column_name] ?? ''));
    }
}

class LCNI_Recommend_Signals_List_Table extends WP_List_Table {
    private $items_data;

    public function __construct($items_data) {
        parent::__construct(['singular' => 'signal', 'plural' => 'signals', 'ajax' => false]);
        $this->items_data = $items_data;
    }

    public function get_columns() {
        return [
            'id'            => 'ID',
            'symbol'        => 'Symbol',
            'rule_name'     => 'Rule',
            'entry_date'    => 'Entry Date',
            'holding_days'  => 'Số ngày nắm giữ',
            'entry_price'   => 'Entry',
            'current_price' => 'Current',
            'r_multiple'    => 'R',
            'position_state'=> 'State',
            'status'        => 'Status',
            'exit_reason'   => 'Lý do thoát',
        ];
    }

    public function prepare_items() {
        $columns  = $this->get_columns();
        $hidden   = [];
        $sortable = [];
        $this->_column_headers = [$columns, $hidden, $sortable];
        $this->items = $this->items_data;
    }

    protected function column_default($item, $column_name) {
        if ( $column_name === 'exit_reason' ) {
            $reason = (string) ( $item['exit_reason'] ?? '' );
            $map    = [
                'stop_loss'   => '<span style="color:#dc2626;font-weight:600;">🛑 Cắt lỗ SL</span>',
                'max_loss'    => '<span style="color:#dc2626;font-weight:600;">⚠️ Cắt lỗ tối đa</span>',
                'take_profit' => '<span style="color:#16a34a;font-weight:600;">✅ Chốt lời</span>',
                'max_hold'    => '<span style="color:#d97706;font-weight:600;">⏱ Hết thời gian</span>',
            ];
            return $map[$reason] ?? ( $reason !== '' ? esc_html( $reason ) : '—' );
        }
        return esc_html( (string) ( $item[$column_name] ?? '' ) );
    }
}

class LCNI_Recommend_Performance_List_Table extends WP_List_Table {
    private $items_data;

    public function __construct($items_data) {
        parent::__construct(['singular' => 'performance', 'plural' => 'performances', 'ajax' => false]);
        $this->items_data = $items_data;
    }

    public function get_columns() {
        return [
            'rule_name'     => 'Rule',
            'total_trades'  => 'Total',
            'win_trades'    => 'Win',
            'lose_trades'   => 'Lose',
            'winrate'       => 'Winrate',
            'avg_r'         => 'Avg R',
            'expectancy'    => 'Expectancy',
            'avg_win_r'     => 'Avg Win R',
            'avg_loss_r'    => 'Avg Loss R',
            'profit_factor' => 'Profit Factor',
            'kelly_pct'     => 'Kelly %',
            'avg_hold_days' => 'Avg Hold',
            'max_r'         => 'Max R',
            'min_r'         => 'Min R',
            'score'         => 'Score',
        ];
    }

    public function prepare_items() {
        $columns = $this->get_columns();
        $hidden = [];
        $sortable = [];
        $this->_column_headers = [$columns, $hidden, $sortable];
        $this->items = $this->items_data;
    }

    protected function column_default($item, $column_name) {
        switch ( $column_name ) {
            case 'winrate':
                return esc_html( number_format( (float) ( $item['winrate'] ?? 0 ) * 100, 2 ) ) . '%';
            case 'avg_r':
            case 'expectancy':
            case 'avg_win_r':
            case 'avg_loss_r':
            case 'max_r':
            case 'min_r':
            case 'avg_hold_days':
                return esc_html( number_format( (float) ( $item[$column_name] ?? 0 ), 4 ) );
            case 'profit_factor':
                $pf = (float) ( $item['profit_factor'] ?? 0 );
                return esc_html( number_format( $pf, 4 ) );
            case 'kelly_pct':
                $k = (float) ( $item['kelly_pct'] ?? 0 );
                $half = $k / 2;
                return esc_html( number_format( $k * 100, 2 ) . '% (½K: ' . number_format( $half * 100, 2 ) . '%)' );
            case 'score':
                $score = PerformanceCalculator::compute_score( $item );
                $badge = PerformanceCalculator::score_badge( $score );
                $colors = [ 'good' => '#16a34a', 'neutral' => '#d97706', 'weak' => '#dc2626' ];
                $labels = [ 'good' => 'Tốt', 'neutral' => 'Trung bình', 'weak' => 'Kém' ];
                $color  = $colors[$badge] ?? '#6b7280';
                $label  = $labels[$badge] ?? '';
                return '<span style="font-weight:700;color:' . esc_attr( $color ) . '">' . esc_html( $score ) . '</span>'
                    . ' <span style="font-size:11px;padding:2px 6px;border-radius:4px;background:' . esc_attr( $color ) . ';color:#fff;">' . esc_html( $label ) . '</span>'
                    . ' <button type="button" class="button button-small lcni-equity-btn" data-rule-id="' . esc_attr( (string) ( $item['rule_id'] ?? 0 ) ) . '" data-rule-name="' . esc_attr( (string) ( $item['rule_name'] ?? '' ) ) . '">📈 Equity</button>';
            default:
                return esc_html( (string) ( $item[$column_name] ?? '' ) );
        }
    }
}

class LCNI_Recommend_Admin_Page {
    private $rule_repository;
    private $signal_repository;
    private $performance_calculator;
    private $daily_cron_service;

    public function __construct(RuleRepository $rule_repository, SignalRepository $signal_repository, PerformanceCalculator $performance_calculator, DailyCronService $daily_cron_service) {
        $this->rule_repository = $rule_repository;
        $this->signal_repository = $signal_repository;
        $this->performance_calculator = $performance_calculator;
        $this->daily_cron_service = $daily_cron_service;

        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_init', [$this, 'handle_actions']);
        add_action('wp_ajax_lcni_recommend_distinct_values', [$this, 'ajax_distinct_values']);
        add_action('wp_ajax_lcni_recommend_equity_curve', [$this, 'ajax_equity_curve']);
    }

    public function register_menu() {
        add_submenu_page('lcni-settings', 'LCNi Recommend', 'Recommend', 'manage_options', 'lcni-recommend', [$this, 'render_page']);
    }

    public function handle_actions() {
        if (!is_admin() || !current_user_can('manage_options') || empty($_POST['lcni_recommend_action'])) {
            return;
        }

        check_admin_referer('lcni_recommend_admin_action');

        if ($_POST['lcni_recommend_action'] === 'create_rule') {
            $entry_conditions = isset($_POST['entry_conditions']) ? wp_unslash((string) $_POST['entry_conditions']) : '{}';
            $saved_rule_id = $this->rule_repository->save([
                'name' => wp_unslash((string) ($_POST['name'] ?? '')),
                'timeframe' => wp_unslash((string) ($_POST['timeframe'] ?? '1D')),
                'description' => wp_unslash((string) ($_POST['description'] ?? '')),
                'entry_conditions' => $entry_conditions,
                'initial_sl_pct' => (float) ($_POST['initial_sl_pct'] ?? 8),
                'max_loss_pct' => (float) ($_POST['max_loss_pct'] ?? 8),
                'risk_reward' => (float) ($_POST['risk_reward'] ?? 3),
                'add_at_r' => (float) ($_POST['add_at_r'] ?? 2),
                'exit_at_r' => (float) ($_POST['exit_at_r'] ?? 4),
                'max_hold_days' => (int) ($_POST['max_hold_days'] ?? 20),
                'apply_from_date' => wp_unslash((string) ($_POST['apply_from_date'] ?? '')),
                'scan_times' => isset($_POST['scan_times']) ? (array) wp_unslash($_POST['scan_times']) : [wp_unslash((string) ($_POST['scan_time'] ?? '18:00'))],
                'is_active' => !empty($_POST['is_active']) ? 1 : 0,
            ]);

            if (is_wp_error($saved_rule_id) || (int) $saved_rule_id <= 0) {
                wp_safe_redirect(admin_url('admin.php?page=lcni-recommend&tab=create-rule&created=0'));
                exit;
            }

            $created_rule = $this->rule_repository->find((int) $saved_rule_id);
            if ($created_rule) {
                $this->daily_cron_service->backfill_rule_history($created_rule);
            }

            wp_safe_redirect(admin_url('admin.php?page=lcni-recommend&tab=create-rule&created=1'));
            exit;
        }

        if ($_POST['lcni_recommend_action'] === 'update_rule') {
            $rule_id = (int) ($_POST['rule_id'] ?? 0);
            if ($rule_id <= 0) {
                wp_safe_redirect(admin_url('admin.php?page=lcni-recommend&tab=rule-list&updated=0'));
                exit;
            }

            $updated = $this->rule_repository->update($rule_id, [
                'name' => wp_unslash((string) ($_POST['name'] ?? '')),
                'timeframe' => wp_unslash((string) ($_POST['timeframe'] ?? '1D')),
                'description' => wp_unslash((string) ($_POST['description'] ?? '')),
                'entry_conditions' => isset($_POST['entry_conditions']) ? wp_unslash((string) $_POST['entry_conditions']) : '{}',
                'initial_sl_pct' => (float) ($_POST['initial_sl_pct'] ?? 8),
                'max_loss_pct' => (float) ($_POST['max_loss_pct'] ?? 8),
                'risk_reward' => (float) ($_POST['risk_reward'] ?? 3),
                'add_at_r' => (float) ($_POST['add_at_r'] ?? 2),
                'exit_at_r' => (float) ($_POST['exit_at_r'] ?? 4),
                'max_hold_days' => (int) ($_POST['max_hold_days'] ?? 20),
                'apply_from_date' => wp_unslash((string) ($_POST['apply_from_date'] ?? '')),
                'scan_times' => isset($_POST['scan_times']) ? (array) wp_unslash($_POST['scan_times']) : [wp_unslash((string) ($_POST['scan_time'] ?? '18:00'))],
                'is_active' => !empty($_POST['is_active']) ? 1 : 0,
            ]);

            if (is_wp_error($updated) || $updated === false) {
                wp_safe_redirect(admin_url('admin.php?page=lcni-recommend&tab=create-rule&edit=' . $rule_id . '&updated=0'));
                exit;
            }

            $updated_rule = $this->rule_repository->find($rule_id);
            if ($updated_rule) {
                $this->daily_cron_service->backfill_rule_history($updated_rule);
            }

            wp_safe_redirect(admin_url('admin.php?page=lcni-recommend&tab=rule-list&updated=1'));
            exit;
        }

        if ($_POST['lcni_recommend_action'] === 'scan_rule_now') {
            $rule_id = (int) ($_POST['rule_id'] ?? 0);
            if ($rule_id <= 0) {
                wp_safe_redirect(admin_url('admin.php?page=lcni-recommend&tab=rule-list&scanned=0'));
                exit;
            }

            $rule = $this->rule_repository->find($rule_id);
            if (!$rule || empty($rule['is_active'])) {
                wp_safe_redirect(admin_url('admin.php?page=lcni-recommend&tab=rule-list&scanned=0'));
                exit;
            }

            $scan_from_date = sanitize_text_field((string) ($_POST['scan_from_date'] ?? ''));
            $scan_to_date = sanitize_text_field((string) ($_POST['scan_to_date'] ?? ''));

            $scan_count = $this->daily_cron_service->scan_rule_now($rule, $scan_from_date, $scan_to_date);
            wp_safe_redirect(admin_url('admin.php?page=lcni-recommend&tab=rule-list&scanned=1&scan_count=' . (int) $scan_count));
            exit;
        }

        if ($_POST['lcni_recommend_action'] === 'refresh_performance') {
            $this->performance_calculator->refresh_all();
            wp_safe_redirect(admin_url('admin.php?page=lcni-recommend&tab=performance&refreshed=1'));
            exit;
        }
    }

    public function render_page() {
        $tab = isset($_GET['tab']) ? sanitize_key((string) $_GET['tab']) : 'create-rule';
        if ($tab === 'rules') {
            $tab = 'create-rule';
        }

        echo '<div class="wrap"><h1>LCNi Recommend</h1>';
        echo '<h2 class="nav-tab-wrapper">';
        echo '<a href="' . esc_url(admin_url('admin.php?page=lcni-recommend&tab=create-rule')) . '" class="nav-tab ' . ($tab === 'create-rule' ? 'nav-tab-active' : '') . '">Tạo Rule</a>';
        echo '<a href="' . esc_url(admin_url('admin.php?page=lcni-recommend&tab=rule-list')) . '" class="nav-tab ' . ($tab === 'rule-list' ? 'nav-tab-active' : '') . '">Danh sách Rule</a>';
        echo '<a href="' . esc_url(admin_url('admin.php?page=lcni-recommend&tab=signals')) . '" class="nav-tab ' . ($tab === 'signals' ? 'nav-tab-active' : '') . '">Signals</a>';
        echo '<a href="' . esc_url(admin_url('admin.php?page=lcni-recommend&tab=performance')) . '" class="nav-tab ' . ($tab === 'performance' ? 'nav-tab-active' : '') . '">Performance</a>';
        echo '<a href="' . esc_url(admin_url('admin.php?page=lcni-recommend&tab=logs')) . '" class="nav-tab ' . ($tab === 'logs' ? 'nav-tab-active' : '') . '">Logs</a>';
        echo '</h2>';

        $created = isset($_GET['created']) ? sanitize_text_field((string) $_GET['created']) : '';
        if ($created === '1') {
            echo '<div class="notice notice-success is-dismissible"><p>Đã lưu rule thành công.</p></div>';
        } elseif ($created === '0') {
            echo '<div class="notice notice-error"><p>Lưu rule thất bại. Vui lòng kiểm tra dữ liệu và thử lại.</p></div>';
        }

        $updated = isset($_GET['updated']) ? sanitize_text_field((string) $_GET['updated']) : '';
        if ($updated === '1') {
            echo '<div class="notice notice-success is-dismissible"><p>Đã cập nhật rule thành công.</p></div>';
        } elseif ($updated === '0') {
            echo '<div class="notice notice-error"><p>Cập nhật rule thất bại. Vui lòng kiểm tra dữ liệu và thử lại.</p></div>';
        }

        $scanned = isset($_GET['scanned']) ? sanitize_text_field((string) $_GET['scanned']) : '';
        if ($scanned === '1') {
            $scan_count = (int) ($_GET['scan_count'] ?? 0);
            echo '<div class="notice notice-success is-dismissible"><p>Đã quét thủ công rule. Số mã thỏa điều kiện: <strong>' . esc_html((string) $scan_count) . '</strong>.</p></div>';
        } elseif ($scanned === '0') {
            echo '<div class="notice notice-error"><p>Quét thủ công thất bại. Vui lòng thử lại.</p></div>';
        }

        $refreshed = isset($_GET['refreshed']) ? sanitize_text_field((string) $_GET['refreshed']) : '';
        if ($refreshed === '1') {
            echo '<div class="notice notice-success is-dismissible"><p>✅ Đã tính lại toàn bộ Performance metrics thành công.</p></div>';
        }

        if ($tab === 'create-rule') {
            $this->render_rules_tab();
        } elseif ($tab === 'rule-list') {
            $this->render_rules_list_tab();
        } elseif ($tab === 'signals') {
            $this->render_signals_tab();
        } elseif ($tab === 'performance') {
            $this->render_performance_tab();
        } else {
            $this->render_logs_tab();
        }

        echo '</div>';
    }

    private function get_builder_table_sources() {
        global $wpdb;

        return [
            $wpdb->prefix . 'lcni_ohlc' => $wpdb->prefix . 'lcni_ohlc',
            $wpdb->prefix . 'lcni_symbols' => $wpdb->prefix . 'lcni_symbols',
            $wpdb->prefix . 'lcni_icb2' => $wpdb->prefix . 'lcni_icb2',
            $wpdb->prefix . 'lcni_sym_icb_market' => $wpdb->prefix . 'lcni_sym_icb_market',
            $wpdb->prefix . 'lcni_symbol_tongquan' => $wpdb->prefix . 'lcni_symbol_tongquan',
            $wpdb->prefix . 'lcni_industry_return' => $wpdb->prefix . 'lcni_industry_return',
            $wpdb->prefix . 'lcni_industry_index' => $wpdb->prefix . 'lcni_industry_index',
            $wpdb->prefix . 'lcni_industry_metrics' => $wpdb->prefix . 'lcni_industry_metrics',
            $wpdb->prefix . 'lcni_recommend_rule' => $wpdb->prefix . 'lcni_recommend_rule',
            $wpdb->prefix . 'lcni_recommend_signal' => $wpdb->prefix . 'lcni_recommend_signal',
            $wpdb->prefix . 'lcni_recommend_performance' => $wpdb->prefix . 'lcni_recommend_performance',
            $wpdb->prefix . 'lcni_thong_ke_thi_truong' => $wpdb->prefix . 'lcni_thong_ke_thi_truong',
            $wpdb->prefix . 'lcni_thong_ke_nganh_icb_2' => $wpdb->prefix . 'lcni_thong_ke_nganh_icb_2',
            $wpdb->prefix . 'lcni_thong_ke_nganh_icb_2_toan_thi_truong' => $wpdb->prefix . 'lcni_thong_ke_nganh_icb_2_toan_thi_truong',
        ];
    }

    private function get_table_columns_for_builder($table_name) {
        global $wpdb;

        $columns = [];
        $rows = $wpdb->get_results("SHOW COLUMNS FROM `{$table_name}`", ARRAY_A);

        foreach ((array) $rows as $row) {
            $field = sanitize_key((string) ($row['Field'] ?? ''));
            if ($field === '') {
                continue;
            }

            $raw_type = strtolower((string) ($row['Type'] ?? ''));
            $is_numeric = (bool) preg_match('/int|decimal|numeric|float|double|real|bit|serial/', $raw_type);

            $columns[] = [
                'field' => $field,
                'raw_type' => $raw_type,
                'is_numeric' => $is_numeric,
            ];
        }

        return $columns;
    }

    private function render_rules_tab() {
        $editing_rule = null;
        $edit_id = (int) ($_GET['edit'] ?? 0);
        if ($edit_id > 0) {
            $found_rule = $this->rule_repository->find($edit_id);
            if (is_array($found_rule)) {
                $editing_rule = $found_rule;
            }
        }

        $is_edit_mode = is_array($editing_rule);

        $table_sources = $this->get_builder_table_sources();
        $columns_map = [];

        foreach ($table_sources as $real_table => $display_table) {
            $columns_map[$display_table] = $this->get_table_columns_for_builder($real_table);
        }

        echo '<style>
            .lcni-recommend-builder-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:12px}
            .lcni-recommend-panel{background:#fff;border:1px solid #dcdcde;padding:12px;min-height:380px}
            .lcni-recommend-panel h3{margin-top:0}
            .lcni-recommend-row{display:flex;gap:10px;align-items:center;margin-bottom:8px;flex-wrap:wrap}
            .lcni-recommend-rules{width:100%;border-collapse:collapse}
            .lcni-recommend-rules th,.lcni-recommend-rules td{border:1px solid #dcdcde;padding:8px;vertical-align:top}
            .lcni-recommend-rules th{background:#f6f7f7;text-align:left}
            .lcni-recommend-rules select,.lcni-recommend-rules input{width:100%}
            .lcni-recommend-rules td:last-child{width:56px;text-align:center}
            .lcni-recommend-rules-help{margin:8px 0 12px;color:#50575e}
        </style>';

        echo '<form method="post" style="margin:16px 0;">';
        wp_nonce_field('lcni_recommend_admin_action');
        echo '<input type="hidden" name="lcni_recommend_action" value="' . ($is_edit_mode ? 'update_rule' : 'create_rule') . '" />';
        if ($is_edit_mode) {
            echo '<input type="hidden" name="rule_id" value="' . esc_attr((string) ($editing_rule['id'] ?? '0')) . '" />';
        }

        echo '<div class="lcni-recommend-builder-grid">';

        echo '<div class="lcni-recommend-panel">';
        echo '<h3>Rule</h3>';
        echo '<p><label>Name<br><input type="text" name="name" required class="regular-text" value="' . esc_attr((string) ($editing_rule['name'] ?? '')) . '" /></label></p>';
        echo '<p><label>Timeframe<br><input type="text" name="timeframe" value="' . esc_attr((string) ($editing_rule['timeframe'] ?? '1D')) . '" class="small-text" /></label></p>';
        echo '<p><label>Description recommend<br><textarea name="description" rows="3" class="large-text">' . esc_textarea((string) ($editing_rule['description'] ?? '')) . '</textarea></label></p>';
        echo '<div class="lcni-recommend-row"><label>Initial SL % <input type="number" step="0.01" name="initial_sl_pct" value="' . esc_attr((string) ($editing_rule['initial_sl_pct'] ?? '8')) . '" /></label></div>';
        echo '<div class="lcni-recommend-row"><label>Mức lỗ tối đa % <input type="number" step="0.01" min="0" max="100" name="max_loss_pct" value="' . esc_attr((string) ($editing_rule['max_loss_pct'] ?? ($editing_rule['initial_sl_pct'] ?? '8'))) . '" /></label><p class="description">Nhập dạng phần trăm trực tiếp. Ví dụ: 8 = cắt lỗ khi giảm 8% so với giá entry.</p></div>';
        echo '<div class="lcni-recommend-row"><label>Risk Reward <input type="number" step="0.01" name="risk_reward" value="' . esc_attr((string) ($editing_rule['risk_reward'] ?? '3')) . '" /></label></div>';
        echo '<div class="lcni-recommend-row"><label>Add at R <input type="number" step="0.01" name="add_at_r" value="' . esc_attr((string) ($editing_rule['add_at_r'] ?? '2')) . '" /></label></div>';
        echo '<div class="lcni-recommend-row"><label>Exit at R <input type="number" step="0.01" name="exit_at_r" value="' . esc_attr((string) ($editing_rule['exit_at_r'] ?? '4')) . '" /></label></div>';
        echo '<div class="lcni-recommend-row"><label>Max Hold Days <input type="number" name="max_hold_days" value="' . esc_attr((string) ($editing_rule['max_hold_days'] ?? '20')) . '" /></label></div>';
        echo '<div class="lcni-recommend-row"><label>Ngày áp dụng <input type="date" name="apply_from_date" value="' . esc_attr((string) ($editing_rule['apply_from_date'] ?? '')) . '" /></label></div>';
        $scan_time_options = RuleRepository::ALLOWED_SCAN_TIMES;
        $selected_scan_times_raw = (string) ($editing_rule['scan_times'] ?? '');
        if ($selected_scan_times_raw === '') {
            $selected_scan_times_raw = (string) ($editing_rule['scan_time'] ?? '18:00');
        }
        $selected_scan_times = array_values(array_unique(array_filter(array_map('sanitize_text_field', explode(',', $selected_scan_times_raw)))));
        if (empty($selected_scan_times)) {
            $selected_scan_times = ['18:00'];
        }
        echo '<div class="lcni-recommend-row"><label>Lịch quét hàng ngày (chọn nhiều mốc giờ)</label><div style="display:flex;gap:12px;flex-wrap:wrap;">';
        foreach ($scan_time_options as $scan_time_option) {
            $checked = in_array($scan_time_option, $selected_scan_times, true) ? 'checked' : '';
            echo '<label><input type="checkbox" name="scan_times[]" value="' . esc_attr($scan_time_option) . '" ' . $checked . ' /> ' . esc_html($scan_time_option) . '</label>';
        }
        echo '</div></div>';
        echo '<p><label><input type="checkbox" name="is_active" value="1" ' . (!array_key_exists('is_active', (array) $editing_rule) || !empty($editing_rule['is_active']) ? 'checked' : '') . ' /> Active</label></p>';
        echo '</div>';

        echo '<div class="lcni-recommend-panel">';
        echo '<h3>Điều kiện kích hoạt</h3>';
        echo '<p class="lcni-recommend-rules-help">Tạo nhiều rule với 3 cột: <strong>Field</strong>, <strong>Điều kiện</strong>, <strong>Giá trị so sánh</strong>. Mỗi dòng rule có thể chọn <strong>AND/OR</strong> riêng để kết hợp với dòng tiếp theo.</p>';
        echo '<table class="lcni-recommend-rules" id="lcni-recommend-rules-table">';
        echo '<thead><tr><th>Cột 1: Field</th><th>Cột 2: Điều kiện</th><th>Cột 3: Giá trị so sánh</th><th>Kết hợp với dòng dưới</th><th></th></tr></thead>';
        echo '<tbody id="lcni-recommend-rules-body"></tbody>';
        echo '</table>';
        echo '<p><button type="button" class="button" id="lcni-recommend-add-rule">+ Thêm rule</button></p>';
        echo '<textarea id="lcni_recommend_entry_conditions" name="entry_conditions" rows="8" class="large-text code" style="display:none;">' . esc_textarea((string) ($editing_rule['entry_conditions'] ?? '{}')) . '</textarea>';
        echo '</div>';

        echo '</div>';

        submit_button($is_edit_mode ? 'Update Rule' : 'Save Rule');
        if ($is_edit_mode) {
            echo ' <a class="button" href="' . esc_url(admin_url('admin.php?page=lcni-recommend&tab=create-rule')) . '">Tạo rule mới</a>';
        }
        echo '</form>';

        echo '<script>';
        echo 'window.lcniRecommendColumnsMap=' . wp_json_encode($columns_map) . ';';
        echo '(function(){
            const columnsMap=window.lcniRecommendColumnsMap||{};
            const jsonField=document.getElementById("lcni_recommend_entry_conditions");
            const tableBody=document.getElementById("lcni-recommend-rules-body");
            const addRuleButton=document.getElementById("lcni-recommend-add-rule");

            const operators=["=",">","<","contains","not_contains"];
            const rows=[];
            const fieldOptions=[];

            function normalizeOperator(operator){
                const raw=String(operator || "").trim().toLowerCase();
                const aliases={
                    gt:">",
                    lt:"<",
                    eq:"=",
                    neq:"!=",
                    gte:">=",
                    lte:"<=",
                    "-":">"
                };
                const mapped=aliases[raw] || raw;
                return operators.includes(mapped) ? mapped : "=";
            }

            Object.keys(columnsMap).forEach((tableName)=>{
                (columnsMap[tableName]||[]).forEach((column)=>{
                    fieldOptions.push({
                        value:tableName+"."+column.field,
                        label:tableName+"."+column.field
                    });
                });
            });

            function syncJson(){
                const validRows=rows.filter((row)=>row.field!=="" && row.value!=="");
                if (!validRows.length) {
                    jsonField.value=JSON.stringify({ rules:[] }, null, 2);
                    return;
                }

                const normalizedRows=validRows.map((row, index)=>{
                    const item={
                        field:row.field,
                        operator:normalizeOperator(row.operator),
                        value:row.value
                    };
                    if (index < validRows.length - 1) {
                        item.join_with_next=row.join_with_next === "OR" ? "OR" : "AND";
                    }
                    return item;
                });

                jsonField.value=JSON.stringify({ rules:normalizedRows }, null, 2);
            }

            function buildSelect(options, selectedValue){
                const select=document.createElement("select");
                const empty=document.createElement("option");
                empty.value="";
                empty.textContent="-- Chọn --";
                select.appendChild(empty);
                options.forEach((item)=>{
                    const option=document.createElement("option");
                    option.value=item.value;
                    option.textContent=item.label;
                    option.selected=item.value===selectedValue;
                    select.appendChild(option);
                });
                return select;
            }

            function renderRows(){
                tableBody.innerHTML="";

                rows.forEach((rule, index)=>{
                    const tr=document.createElement("tr");

                    const fieldCell=document.createElement("td");
                    const fieldListId="lcni-recommend-field-options-"+index;
                    const fieldInput=document.createElement("input");
                    fieldInput.type="search";
                    fieldInput.placeholder="Tìm hoặc chọn field...";
                    fieldInput.setAttribute("list", fieldListId);
                    fieldInput.value=rule.field || "";

                    const fieldDataList=document.createElement("datalist");
                    fieldDataList.id=fieldListId;
                    fieldOptions.forEach((item)=>{
                        const option=document.createElement("option");
                        option.value=item.value;
                        option.label=item.label;
                        fieldDataList.appendChild(option);
                    });

                    fieldInput.addEventListener("input",()=>{
                        const keyword=String(fieldInput.value || "").toLowerCase().trim();
                        if (keyword === "") {
                            fieldDataList.innerHTML="";
                            fieldOptions.forEach((item)=>{
                                const option=document.createElement("option");
                                option.value=item.value;
                                option.label=item.label;
                                fieldDataList.appendChild(option);
                            });
                            rule.field="";
                            syncJson();
                            return;
                        }

                        const filtered=fieldOptions.filter((item)=>String(item.label || "").toLowerCase().includes(keyword));
                        fieldDataList.innerHTML="";
                        filtered.forEach((item)=>{
                            const option=document.createElement("option");
                            option.value=item.value;
                            option.label=item.label;
                            fieldDataList.appendChild(option);
                        });

                        const selected=fieldOptions.find((item)=>item.value===fieldInput.value);
                        rule.field=selected ? selected.value : "";
                        syncJson();
                    });

                    fieldCell.appendChild(fieldInput);
                    fieldCell.appendChild(fieldDataList);

                    const operatorCell=document.createElement("td");
                    const operatorSelect=buildSelect(operators.map((op)=>({ value:op, label:op })), normalizeOperator(rule.operator));
                    operatorSelect.addEventListener("change",()=>{ rule.operator=normalizeOperator(operatorSelect.value); syncJson(); });
                    operatorCell.appendChild(operatorSelect);

                    const valueCell=document.createElement("td");
                    const valueInput=document.createElement("input");
                    valueInput.type="text";
                    valueInput.value=rule.value || "";
                    valueInput.placeholder="Nhập giá trị so sánh";
                    valueInput.addEventListener("input",()=>{ rule.value=valueInput.value.trim(); syncJson(); });
                    valueCell.appendChild(valueInput);

                    const joinCell=document.createElement("td");
                    if (index < rows.length - 1) {
                        const joinSelect=buildSelect([{ value:"AND", label:"AND" }, { value:"OR", label:"OR" }], rule.join_with_next || "AND");
                        joinSelect.addEventListener("change",()=>{
                            rule.join_with_next=joinSelect.value === "OR" ? "OR" : "AND";
                            syncJson();
                        });
                        joinCell.appendChild(joinSelect);
                    } else {
                        joinCell.textContent="-";
                    }

                    const actionCell=document.createElement("td");
                    const removeButton=document.createElement("button");
                    removeButton.type="button";
                    removeButton.className="button-link-delete";
                    removeButton.textContent="Xóa";
                    removeButton.disabled=rows.length===1;
                    removeButton.addEventListener("click",()=>{
                        if(rows.length===1){ return; }
                        rows.splice(index,1);
                        renderRows();
                    });
                    actionCell.appendChild(removeButton);

                    tr.appendChild(fieldCell);
                    tr.appendChild(operatorCell);
                    tr.appendChild(valueCell);
                    tr.appendChild(joinCell);
                    tr.appendChild(actionCell);
                    tableBody.appendChild(tr);
                });

                syncJson();
            }

            function addRule(initialRule){
                rows.push({
                    field:(initialRule && initialRule.field) || "",
                    operator:normalizeOperator((initialRule && initialRule.operator) || "="),
                    value:(initialRule && initialRule.value) || "",
                    join_with_next:(initialRule && initialRule.join_with_next) === "OR" ? "OR" : "AND"
                });
                renderRows();
            }

            addRuleButton.addEventListener("click",()=>addRule());

            try {
                const parsed=JSON.parse(jsonField.value || "{}");
                if (parsed && Array.isArray(parsed.rules) && parsed.rules.length) {
                    const legacyMatch=String(parsed.match || "AND").toUpperCase() === "OR" ? "OR" : "AND";
                    parsed.rules.forEach((rule)=>{
                        addRule({
                            field:String(rule.field || ""),
                            operator:String(rule.operator || "="),
                            value:String(rule.value || ""),
                            join_with_next:String(rule.join_with_next || rule.join || legacyMatch || "AND").toUpperCase() === "OR" ? "OR" : "AND"
                        });
                    });
                } else {
                    addRule();
                }
            } catch(err){
                addRule();
            }
        })();';
        echo '</script>';

    }

    private function render_rules_list_tab() {
        $rules = $this->rule_repository->all(200);
        $this->render_rules_list(is_array($rules) ? $rules : []);
    }

    private function render_rules_list(array $rules) {
        echo '<hr style="margin:24px 0 16px;" />';
        echo '<h2>Created Rules</h2>';

        if (empty($rules)) {
            echo '<p><em>Chưa có rule nào được tạo.</em></p>';
            return;
        }

        echo '<p>Tổng số rule: <strong>' . esc_html((string) count($rules)) . '</strong></p>';
        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>ID</th>';
        echo '<th>Name</th>';
        echo '<th>Timeframe</th>';
        echo '<th>Description</th>';
        echo '<th>Entry Conditions</th>';
        echo '<th>Initial SL %</th>';
        echo '<th>Risk Reward</th>';
        echo '<th>Add at R</th>';
        echo '<th>Exit at R</th>';
        echo '<th>Max Hold</th>';
        echo '<th>Ngày áp dụng</th>';
        echo '<th>Giờ quét</th>';
        echo '<th>Mức lỗ tối đa %</th>';
        echo '<th>Lần quét gần nhất</th>';
        echo '<th>Active</th>';
        echo '<th>Created At</th>';
        echo '<th>Updated At</th>';
        echo '<th>Action</th>';
        echo '<th>Quét thủ công</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        foreach ($rules as $rule) {
            echo '<tr>';
            echo '<td>' . esc_html((string) ($rule['id'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($rule['name'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($rule['timeframe'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($rule['description'] ?? '')) . '</td>';
            echo '<td><code>' . esc_html((string) ($rule['entry_conditions'] ?? '{}')) . '</code></td>';
            echo '<td>' . esc_html((string) ($rule['initial_sl_pct'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($rule['risk_reward'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($rule['add_at_r'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($rule['exit_at_r'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($rule['max_hold_days'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($rule['apply_from_date'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($rule['scan_times'] ?? ($rule['scan_time'] ?? '')) ) . '</td>';
            echo '<td>' . esc_html((string) ($rule['max_loss_pct'] ?? ($rule['initial_sl_pct'] ?? ''))) . '</td>';
            echo '<td>' . esc_html(!empty($rule['last_scan_at']) ? wp_date('Y-m-d H:i:s', (int) $rule['last_scan_at'], wp_timezone()) : '') . '</td>';
            echo '<td>' . (!empty($rule['is_active']) ? '1' : '0') . '</td>';
            echo '<td>' . esc_html((string) ($rule['created_at'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($rule['updated_at'] ?? '')) . '</td>';
            echo '<td><a class="button button-small" href="' . esc_url(admin_url('admin.php?page=lcni-recommend&tab=create-rule&edit=' . (int) ($rule['id'] ?? 0))) . '">Chỉnh sửa</a></td>';
            echo '<td>';
            if (!empty($rule['is_active'])) {
                echo '<form method="post" style="margin:0;">';
                wp_nonce_field('lcni_recommend_admin_action');
                echo '<input type="hidden" name="lcni_recommend_action" value="scan_rule_now" />';
                echo '<input type="hidden" name="rule_id" value="' . esc_attr((string) ((int) ($rule['id'] ?? 0))) . '" />';
                echo '<label style="font-size:11px;">Từ ngày <input type="date" name="scan_from_date" value="' . esc_attr((string) ($rule['apply_from_date'] ?? '')) . '" /></label>';
                echo '<label style="font-size:11px;">Đến ngày <input type="date" name="scan_to_date" value="" /></label>';
                echo '<button type="submit" class="button button-small">Quét ngay</button>'; 
                echo '</form>';
            } else {
                echo '<span style="color:#777;">Inactive</span>';
            }
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody>';
        echo '</table>';
    }


    private function render_logs_tab() {
        $logs = $this->rule_repository->list_logs(300);

        if (empty($logs)) {
            echo '<p><em>Chưa có lịch sử thay đổi.</em></p>';
            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr><th>ID</th><th>Rule</th><th>Action</th><th>Message</th><th>User</th><th>Payload</th><th>Created At</th></tr></thead>';
        echo '<tbody>';

        foreach ($logs as $log) {
            echo '<tr>';
            echo '<td>' . esc_html((string) ($log['id'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($log['rule_name'] ?? $log['rule_id'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($log['action'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($log['message'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($log['changed_by'] ?? '')) . '</td>';
            echo '<td><code>' . esc_html((string) ($log['payload'] ?? '')) . '</code></td>';
            echo '<td>' . esc_html((string) ($log['created_at'] ?? '')) . '</td>';
            echo '</tr>';
        }

        echo '</tbody>';
        echo '</table>';
    }

    public function ajax_distinct_values() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied'], 403);
        }

        check_ajax_referer('lcni_recommend_distinct_values', 'nonce');

        $table = sanitize_text_field(wp_unslash((string) ($_POST['table'] ?? '')));
        $field = sanitize_key((string) ($_POST['field'] ?? ''));

        if ($table === '' || $field === '') {
            wp_send_json_error(['message' => 'Invalid params'], 400);
        }

        $table_sources = array_values($this->get_builder_table_sources());
        if (!in_array($table, $table_sources, true)) {
            wp_send_json_error(['message' => 'Unknown table'], 400);
        }

        $columns = $this->get_table_columns_for_builder($table);
        $is_allowed_field = false;
        foreach ($columns as $column) {
            if (($column['field'] ?? '') === $field && empty($column['is_numeric'])) {
                $is_allowed_field = true;
                break;
            }
        }

        if (!$is_allowed_field) {
            wp_send_json_error(['message' => 'Unknown text field'], 400);
        }

        global $wpdb;

        $query = sprintf(
            'SELECT DISTINCT `%1$s` AS value FROM `%2$s` WHERE `%1$s` IS NOT NULL AND `%1$s` <> "" ORDER BY `%1$s` ASC LIMIT 100',
            esc_sql($field),
            esc_sql($table)
        );

        $rows = $wpdb->get_col($query);
        $values = array_values(array_filter(array_map('strval', (array) $rows), static function ($value) {
            return $value !== '';
        }));

        wp_send_json_success($values);
    }

    private function render_signals_tab() {
        $this->daily_cron_service->refresh_open_positions_now();

        $signals = $this->signal_repository->list_signals(['limit' => 200]);
        $now_timestamp = current_time('timestamp');

        $signals = array_map(static function ($signal) use ($now_timestamp) {
            // list_signals trả về key dạng signal__xxx — map lại cho admin table
            foreach ($signal as $k => $v) {
                if (strpos($k, 'signal__') === 0) {
                    $short = substr($k, strlen('signal__'));
                    if (!isset($signal[$short])) {
                        $signal[$short] = $v;
                    }
                } elseif (strpos($k, 'rule__') === 0) {
                    $short = substr($k, strlen('rule__'));
                    if (!isset($signal[$short])) {
                        $signal[$short] = $v;
                    }
                }
            }

            $entry_time = (int) ($signal['entry_time'] ?? $signal['entry_time_raw'] ?? 0);
            $signal['entry_date'] = $entry_time > 0 ? wp_date('Y-m-d', $entry_time, wp_timezone()) : '';

            $holding_days = isset($signal['holding_days']) ? (int) $signal['holding_days'] : 0;
            if ($holding_days <= 0 && $entry_time > 0) {
                $holding_days = max(0, (int) floor(($now_timestamp - $entry_time) / DAY_IN_SECONDS));
            }
            $signal['holding_days'] = $holding_days;

            return $signal;
        }, is_array($signals) ? $signals : []);

        $table = new LCNI_Recommend_Signals_List_Table($signals);
        $table->prepare_items();
        $table->display();
    }

    private function render_performance_tab() {
        $nonce = wp_create_nonce( 'lcni_equity_curve' );
        $ajax_url = esc_url( admin_url( 'admin-ajax.php' ) );

        // Nút tính lại toàn bộ metrics
        echo '<form method="post" style="margin:12px 0 4px;">';
        wp_nonce_field('lcni_recommend_admin_action');
        echo '<input type="hidden" name="lcni_recommend_action" value="refresh_performance" />';
        echo '<button type="submit" class="button button-primary">🔄 Tính lại Performance (refresh_all)</button>';
        echo ' <span style="color:#6b7280;font-size:12px;margin-left:8px;">Bấm 1 lần sau khi cài bản cập nhật để điền đầy Avg Win R, Avg Loss R, Profit Factor, Kelly %</span>';
        echo '</form>';

        $table = new LCNI_Recommend_Performance_List_Table( $this->performance_calculator->list_performance() );
        $table->prepare_items();

        echo '<style>
            .lcni-equity-btn{cursor:pointer;margin-left:6px;}
            #lcni-equity-panel{display:none;margin-top:20px;background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:16px;}
            #lcni-equity-panel h3{margin-top:0;}
            #lcni-equity-chart{width:100%;height:340px;}
            #lcni-equity-stats{display:flex;gap:16px;flex-wrap:wrap;margin-top:12px;}
            .lcni-equity-stat{background:#f6f7f7;border-radius:6px;padding:8px 14px;font-size:13px;}
            .lcni-equity-stat strong{display:block;font-size:18px;}
        </style>';

        $table->display();

        echo '<div id="lcni-equity-panel">
            <h3 id="lcni-equity-title">Equity Curve</h3>
            <div id="lcni-equity-chart"></div>
            <div id="lcni-equity-stats"></div>
        </div>';

        echo '<script>
        (function(){
            const ajaxUrl = ' . wp_json_encode( $ajax_url ) . ';
            const nonce   = ' . wp_json_encode( $nonce ) . ';

            function loadECharts(cb){
                if(window.echarts && typeof window.echarts.init === "function"){ cb(); return; }
                const s = document.createElement("script");
                s.src = "https://cdn.jsdelivr.net/npm/echarts@5/dist/echarts.min.js";
                s.onload = cb;
                document.head.appendChild(s);
            }

            document.addEventListener("click", function(e){
                const btn = e.target.closest(".lcni-equity-btn");
                if(!btn) return;
                const ruleId   = btn.dataset.ruleId;
                const ruleName = btn.dataset.ruleName;

                document.getElementById("lcni-equity-title").textContent = "Equity Curve — " + ruleName;
                document.getElementById("lcni-equity-stats").innerHTML = "<em>Đang tải…</em>";
                document.getElementById("lcni-equity-panel").style.display = "block";
                document.getElementById("lcni-equity-chart").innerHTML = "";
                document.getElementById("lcni-equity-panel").scrollIntoView({behavior:"smooth",block:"start"});

                fetch(ajaxUrl + "?action=lcni_recommend_equity_curve&rule_id=" + encodeURIComponent(ruleId) + "&nonce=" + encodeURIComponent(nonce))
                    .then(function(r){ return r.json(); })
                    .then(function(resp){
                        if(!resp.success){ document.getElementById("lcni-equity-stats").textContent = "Chưa có dữ liệu đóng lệnh."; return; }
                        const data = resp.data;
                        loadECharts(function(){ renderChart(data, ruleName); });
                    })
                    .catch(function(){ document.getElementById("lcni-equity-stats").textContent = "Lỗi tải dữ liệu."; });
            });

            function renderChart(data, ruleName){
                const panel = document.getElementById("lcni-equity-panel");
                if(!data.points || !data.points.length){
                    document.getElementById("lcni-equity-stats").textContent = "Chưa có lệnh đã đóng.";
                    return;
                }
                const points   = data.points;
                const dates    = points.map(function(p){ return p.date || ""; });
                const xLabels  = points.map(function(p,i){ return i+1; });
                const cumVals  = points.map(function(p){ return p.cumulative_r; });
                const tradeRs  = points.map(function(p){ return p.trade_r; });
                const dateFirst= dates[0]||"";
                const dateLast = dates[dates.length-1]||"";

                // Stats
                const final = cumVals[cumVals.length-1];
                let peak=0, maxDD=0;
                for(let i=0;i<cumVals.length;i++){
                    if(cumVals[i]>peak) peak=cumVals[i];
                    const dd = peak - cumVals[i];
                    if(dd>maxDD) maxDD=dd;
                }
                const wins  = tradeRs.filter(function(r){ return r>=0; }).length;
                const total = tradeRs.length;

                document.getElementById("lcni-equity-stats").innerHTML =
                    stat("Tổng R", (final>=0?"+":"") + final.toFixed(2) + "R", final>=0?"#16a34a":"#dc2626") +
                    stat("Số lệnh", total) +
                    stat("Max Drawdown", "-" + maxDD.toFixed(2) + "R", "#dc2626") +
                    stat("Winrate (closed)", (total>0?(wins/total*100).toFixed(1):0) + "%");

                const chartDom = document.getElementById("lcni-equity-chart");
                const myChart  = window.echarts.init(chartDom);

                myChart.setOption({
                    tooltip:{
                        trigger:"axis",
                        formatter:function(params){
                            const i = params[0].dataIndex;
                            const p = points[i];
                            return "<strong>Lệnh #"+(i+1)+"</strong><br/>" +
                                   "Mã: <b>" + p.symbol + "</b><br/>" +
                                   "Mua: "+(p.entry_date||"—")+" · Bán: "+(p.date||"—")+"<br/>" +
                                   "Nắm giữ: "+(p.holding_days||"—")+" ngày<br/>" +
                                   "Lệnh: " + (p.trade_r>=0?"+":"") + p.trade_r.toFixed(2) + "R<br/>" +
                                   "Cộng dồn: " + (p.cumulative_r>=0?"+":"") + p.cumulative_r.toFixed(2) + "R";
                        }
                    },
                    grid:{left:"60px",right:"20px",bottom:"54px",top:"30px"},
                    xAxis:{
                        type:"category",
                        data:xLabels,
                        name: dateFirst&&dateLast ? dateFirst+" → "+dateLast : "",
                        nameLocation:"middle",
                        nameGap:36,
                        nameTextStyle:{fontSize:11,color:"#6b7280"},
                        axisLabel:{fontSize:11,formatter:function(v){
                            const n=xLabels.length;
                            const step=Math.max(1,Math.floor(n/6));
                            return (v===1||v===n||v%step===0)?v:"";
                        }}
                    },
                    yAxis:{
                        type:"value",
                        name:"R",
                        axisLine:{show:true},
                        splitLine:{lineStyle:{type:"dashed"}}
                    },
                    series:[
                        {
                            name:"Equity",
                            type:"line",
                            data:cumVals,
                            symbol:"circle",
                            symbolSize:4,
                            lineStyle:{width:2, color:"#16a34a"},
                            itemStyle:{
                                color:function(params){
                                    return params.data >= 0 ? "#16a34a" : "#dc2626";
                                }
                            },
                            areaStyle:{
                                color:{
                                    type:"linear",
                                    x:0, y:0, x2:0, y2:1,
                                    colorStops:[
                                        {offset:0, color:"rgba(22,163,74,0.25)"},
                                        {offset:1, color:"rgba(22,163,74,0.02)"}
                                    ]
                                }
                            },
                            markLine:{
                                silent:true,
                                lineStyle:{color:"#9ca3af",type:"dashed"},
                                data:[{yAxis:0}]
                            }
                        },
                        {
                            name:"Trade R",
                            type:"bar",
                            data:tradeRs,
                            barMaxWidth:6,
                            itemStyle:{
                                color:function(params){
                                    return params.data >= 0 ? "rgba(22,163,74,0.5)" : "rgba(220,38,38,0.5)";
                                }
                            },
                            tooltip:{show:false}
                        }
                    ],
                    dataZoom:[{type:"inside"},{type:"slider",height:20,bottom:4}]
                });

                window.addEventListener("resize", function(){ myChart.resize(); });
            }

            function stat(label, value, color){
                return "<div class=\"lcni-equity-stat\"><strong style=\"color:"+(color||"#111827")+"\">" + value + "</strong>" + label + "</div>";
            }
        })();
        </script>';
    }

    public function ajax_equity_curve() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Permission denied' ], 403 );
        }

        check_ajax_referer( 'lcni_equity_curve', 'nonce' );

        $rule_id = (int) ( $_GET['rule_id'] ?? 0 );
        if ( $rule_id <= 0 ) {
            wp_send_json_error( [ 'message' => 'Invalid rule_id' ], 400 );
        }

        $points = $this->performance_calculator->get_equity_curve( $rule_id );
        wp_send_json_success( [ 'points' => $points ] );
    }
}
