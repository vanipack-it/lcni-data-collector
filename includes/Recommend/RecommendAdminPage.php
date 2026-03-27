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
                'scan_interval_minutes' => absint( $_POST['scan_interval_minutes'] ?? 0 ),
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
                'scan_interval_minutes' => absint( $_POST['scan_interval_minutes'] ?? 0 ),
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
            wp_safe_redirect(admin_url('admin.php?page=lcni-recommend&tab=rule-list&scanned=1&scan_count=' . (int) $scan_count . '&rule_id=' . (int) $rule_id));
            exit;
        }

        if ($_POST['lcni_recommend_action'] === 'rebuild_rule') {
            // Xóa toàn bộ signals của rule → backfill lại từ apply_from_date
            // Dùng khi chart "Chưa có lịch sử" do signals bị corrupt hoặc chưa được close đúng
            $rule_id = (int) ($_POST['rule_id'] ?? 0);
            if ( $rule_id <= 0 ) {
                wp_safe_redirect( admin_url( 'admin.php?page=lcni-recommend&tab=rule-list&rebuilt=0' ) );
                exit;
            }
            $rule = $this->rule_repository->find( $rule_id );
            if ( ! $rule ) {
                wp_safe_redirect( admin_url( 'admin.php?page=lcni-recommend&tab=rule-list&rebuilt=0' ) );
                exit;
            }

            // Xóa signals cũ của rule này
            global $wpdb;
            $wpdb->delete(
                $wpdb->prefix . 'lcni_recommend_signal',
                [ 'rule_id' => $rule_id ],
                [ '%d' ]
            );

            // Backfill lại toàn bộ từ apply_from_date
            $created = $this->daily_cron_service->backfill_rule_history( $rule );

            // Refresh performance sau khi rebuild
            $this->performance_calculator->refresh_all( [ $rule_id ] );

            wp_safe_redirect( admin_url(
                'admin.php?page=lcni-recommend&tab=rule-list&rebuilt=1&rule_id=' . $rule_id . '&created=' . (int) $created
            ) );
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
        echo '<a href="' . esc_url(admin_url('admin.php?page=lcni-recommend&tab=market-context')) . '" class="nav-tab ' . ($tab === 'market-context' ? 'nav-tab-active' : '') . '">Market Context</a>';
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
            $scanned_rule_id = (int) ($_GET['rule_id'] ?? 0);
            echo '<div class="notice notice-success is-dismissible"><p>Đã quét thủ công rule. Số mã <strong>tìm thấy</strong>: <strong>' . esc_html((string) $scan_count) . '</strong>. (Xem chi tiết bên dưới)</p></div>';

            // ── DIAGNOSTIC: hiển thị chi tiết từ log scan vừa xong ────────────
            {
                global $wpdb;
                $log_table    = $wpdb->prefix . 'lcni_recommend_rule_log';
                $signal_table = $wpdb->prefix . 'lcni_recommend_signal';
                $ohlc         = $wpdb->prefix . 'lcni_ohlc';
                $ohlc_latest  = $wpdb->prefix . 'lcni_ohlc_latest';

                // Lấy log manual_scanned mới nhất
                $last_log = $scanned_rule_id > 0 ? $wpdb->get_row( $wpdb->prepare(
                    "SELECT payload FROM {$log_table}
                     WHERE rule_id = %d AND action = 'manual_scanned'
                     ORDER BY id DESC LIMIT 1",
                    $scanned_rule_id
                ), ARRAY_A ) : null;

                $payload         = $last_log ? json_decode( (string) $last_log['payload'], true ) : [];
                $candidates_list = is_array( $payload['candidates'] ?? null ) ? $payload['candidates'] : [];
                $created_count   = isset( $payload['created_count'] ) ? (int) $payload['created_count'] : '?';

                // Kiểm tra từng candidate: tại sao không tạo được signal
                $rejection_info = [];
                foreach ( $candidates_list as $c ) {
                    $parts  = explode( '@', (string) $c, 2 );
                    $sym    = $parts[0] ?? '';
                    $et     = isset( $parts[1] ) ? (int) $parts[1] : 0;
                    $dup    = $wpdb->get_var( $wpdb->prepare(
                        "SELECT id FROM {$signal_table} WHERE rule_id=%d AND symbol=%s AND entry_time=%d LIMIT 1",
                        $scanned_rule_id, $sym, $et
                    ) );
                    if ( $dup ) {
                        $reason = "✅ Signal đã tạo #$dup";
                    } else {
                        $sym_exists = $wpdb->get_var( $wpdb->prepare(
                            "SELECT symbol FROM {$wpdb->prefix}lcni_symbols WHERE symbol=%s LIMIT 1", $sym
                        ) );
                        if ( ! $sym_exists ) {
                            $reason = "❌ REJECT: '{$sym}' không có trong wp_lcni_symbols (is_valid_symbol fail)";
                        } else {
                            $price = (float) $wpdb->get_var( $wpdb->prepare(
                                "SELECT close_price FROM {$ohlc} WHERE symbol=%s AND timeframe='1D' AND event_time=%d LIMIT 1",
                                $sym, $et
                            ) );
                            if ( $price <= 0 ) {
                                $reason = "❌ REJECT: close_price = 0 tại event_time=$et";
                            } else {
                                $open_sig = $wpdb->get_var( $wpdb->prepare(
                                    "SELECT id FROM {$signal_table} WHERE rule_id=%d AND symbol=%s AND status='open' LIMIT 1",
                                    $scanned_rule_id, $sym
                                ) );
                                $reason = $open_sig
                                    ? "⚠️ Đã có signal open #$open_sig — create_signal tạo mới theo entry_time=$et"
                                    : "❓ Không rõ — symbol OK, price=$price, không duplicate, không open";
                            }
                        }
                    }
                    $rejection_info[] = "<strong>$sym</strong> (et=$et): $reason";
                }

                $latest_exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $ohlc_latest ) );
                $latest_total  = $latest_exists ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$ohlc_latest}" ) : 0;
                $ready_exists  = (bool) $wpdb->get_var(
                    "SELECT COUNT(*) FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{$ohlc}' AND COLUMN_NAME='indicators_ready'"
                );

                echo '<div class="notice notice-info" style="font-family:monospace;font-size:12px;line-height:2">';
                echo '<p><strong>🔍 Diagnostic Rule #' . $scanned_rule_id . '</strong> | Tìm thấy: <strong>' . $scan_count . '</strong> | Tạo signal: <strong>' . $created_count . '</strong></p>';
                echo '<p>ohlc_latest: ' . ( $latest_exists ? "<strong>tồn tại</strong> ($latest_total rows)" : '<strong style="color:red">KHÔNG TỒN TẠI</strong>' ) . ' | indicators_ready col: ' . ( $ready_exists ? '<strong>có</strong>' : '<strong style="color:red">chưa migration</strong>' ) . '</p>';
                foreach ( $rejection_info as $info ) {
                    echo '<p style="margin-left:16px">→ ' . $info . '</p>';
                }
                if ( empty( $rejection_info ) && $scan_count > 0 ) {
                    echo '<p>→ candidates list chưa có trong log (cần deploy DailyCronService.php mới)</p>';
                }
                if ( $wpdb->last_error ) {
                    echo '<p style="color:red">DB Error: ' . esc_html( $wpdb->last_error ) . '</p>';
                }
                echo '</div>';
            }
            // ─────────────────────────────────────────────────────────────────
        } elseif ($scanned === '0') {
            echo '<div class="notice notice-error"><p>Quét thủ công thất bại. Vui lòng thử lại.</p></div>';
        }

        $refreshed = isset($_GET['refreshed']) ? sanitize_text_field((string) $_GET['refreshed']) : '';
        if ($refreshed === '1') {
            echo '<div class="notice notice-success is-dismissible"><p>✅ Đã tính lại toàn bộ Performance metrics thành công.</p></div>';
        }

        $rebuilt = isset($_GET['rebuilt']) ? sanitize_text_field((string) $_GET['rebuilt']) : '';
        if ($rebuilt === '1') {
            $rebuilt_cnt = (int) ($_GET['created'] ?? 0);
            $rebuilt_rid = (int) ($_GET['rule_id'] ?? 0);
            echo '<div class="notice notice-success is-dismissible"><p>✅ Rebuild rule #' . $rebuilt_rid . ' thành công. Đã tạo <strong>' . $rebuilt_cnt . '</strong> signals mới.</p></div>';
        } elseif ($rebuilt === '0') {
            echo '<div class="notice notice-error"><p>❌ Rebuild thất bại. Kiểm tra apply_from_date đã được đặt chưa.</p></div>';
        }

        if ($tab === 'create-rule') {
            $this->render_rules_tab();
        } elseif ($tab === 'rule-list') {
            $this->render_rules_list_tab();
        } elseif ($tab === 'signals') {
            $this->render_signals_tab();
        } elseif ($tab === 'performance') {
            $this->render_performance_tab();
        } elseif ($tab === 'market-context') {
            $this->render_market_context_tab();
        } else {
            $this->render_logs_tab();
        }

        echo '</div>';
    }

    private function get_builder_table_sources() {
        global $wpdb;

        // Đảm bảo bảng market_context tồn tại trong DB trước khi SHOW COLUMNS
        if ( class_exists( 'LCNI_MarketDashboardRepository' ) ) {
            try {
                LCNI_MarketDashboardRepository::ensure_context_table();
            } catch ( \Throwable $e ) {
                // Bỏ qua nếu class chưa load
            }
        }

        $all = [
            $wpdb->prefix . 'lcni_ohlc'                                  => $wpdb->prefix . 'lcni_ohlc',
            $wpdb->prefix . 'lcni_symbols'                                => $wpdb->prefix . 'lcni_symbols',
            $wpdb->prefix . 'lcni_icb2'                                   => $wpdb->prefix . 'lcni_icb2',
            $wpdb->prefix . 'lcni_sym_icb_market'                         => $wpdb->prefix . 'lcni_sym_icb_market',
            $wpdb->prefix . 'lcni_symbol_tongquan'                        => $wpdb->prefix . 'lcni_symbol_tongquan',
            $wpdb->prefix . 'lcni_industry_return'                        => $wpdb->prefix . 'lcni_industry_return',
            $wpdb->prefix . 'lcni_industry_index'                         => $wpdb->prefix . 'lcni_industry_index',
            $wpdb->prefix . 'lcni_industry_metrics'                       => $wpdb->prefix . 'lcni_industry_metrics',
            $wpdb->prefix . 'lcni_recommend_rule'                         => $wpdb->prefix . 'lcni_recommend_rule',
            $wpdb->prefix . 'lcni_recommend_signal'                       => $wpdb->prefix . 'lcni_recommend_signal',
            $wpdb->prefix . 'lcni_recommend_performance'                  => $wpdb->prefix . 'lcni_recommend_performance',
            $wpdb->prefix . 'lcni_thong_ke_thi_truong'                   => $wpdb->prefix . 'lcni_thong_ke_thi_truong',
            $wpdb->prefix . 'lcni_thong_ke_nganh_icb_2'                  => $wpdb->prefix . 'lcni_thong_ke_nganh_icb_2',
            $wpdb->prefix . 'lcni_thong_ke_nganh_icb_2_toan_thi_truong' => $wpdb->prefix . 'lcni_thong_ke_nganh_icb_2_toan_thi_truong',
            $wpdb->prefix . 'lcni_market_context'                         => $wpdb->prefix . 'lcni_market_context',
        ];

        // Chỉ giữ bảng thực sự tồn tại trong DB — tránh SHOW COLUMNS lỗi im lặng
        $prev = $wpdb->suppress_errors( true );
        $existing = [];
        foreach ( $all as $real => $display ) {
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $real ) ) === $real ) {
                $existing[ $real ] = $display;
            }
        }
        $wpdb->suppress_errors( $prev );

        return $existing;
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

        // ── CSS ─────────────────────────────────────────────────────────────
        echo '<style>
.lcni-rb-wrap{display:grid;grid-template-columns:360px 1fr;gap:16px;margin-top:12px;align-items:start}
.lcni-rb-left{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px}
.lcni-rb-left h3{margin:0 0 14px;font-size:15px;color:#1d2327}
.lcni-rb-right{display:flex;flex-direction:column;gap:12px}
.lcni-rb-panel{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px}
.lcni-rb-panel h3{margin:0 0 10px;font-size:15px;color:#1d2327}
/* Table selector */
.lcni-rb-tables{display:flex;flex-direction:column;gap:6px;max-height:220px;overflow-y:auto}
.lcni-rb-tbl-btn{text-align:left;background:#f6f7f7;border:1px solid #dcdcde;border-radius:6px;padding:7px 12px;cursor:pointer;font-size:13px;color:#1d2327;transition:background .15s}
.lcni-rb-tbl-btn:hover,.lcni-rb-tbl-btn.active{background:#e0f0ff;border-color:#72aee6;color:#005fa3}
/* Field chips */
.lcni-rb-fields-wrap{margin-top:12px;border-top:1px solid #dcdcde;padding-top:10px}
.lcni-rb-fields-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#50575e;margin-bottom:8px}
.lcni-rb-chips{display:flex;flex-wrap:wrap;gap:6px}
.lcni-rb-chip{display:inline-flex;align-items:center;gap:5px;padding:4px 10px;border-radius:20px;font-size:12px;border:1px solid;cursor:grab;user-select:none;transition:box-shadow .15s}
.lcni-rb-chip.numeric{background:#fff8e1;border-color:#f0b429;color:#7a4f00}
.lcni-rb-chip.text{background:#e7f5ff;border-color:#74b9e7;color:#1a5276}
.lcni-rb-chip:hover{box-shadow:0 2px 8px rgba(0,0,0,.15);transform:translateY(-1px)}
.lcni-rb-chip.dragging{opacity:.5;box-shadow:0 4px 12px rgba(0,0,0,.2)}
/* Drop zone */
.lcni-rb-dropzone{min-height:120px;border:2px dashed #c3c4c7;border-radius:8px;padding:12px;transition:border-color .15s,background .15s;position:relative}
.lcni-rb-dropzone.drag-over{border-color:#2271b1;background:#f0f7ff}
.lcni-rb-dropzone-hint{color:#8c8f94;font-size:13px;text-align:center;padding:24px 0;pointer-events:none}
/* Condition rows */
.lcni-rb-conditions{display:flex;flex-direction:column;gap:8px}
.lcni-rb-cond-row{display:flex;align-items:center;gap:8px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:8px;padding:10px 12px;flex-wrap:wrap}
.lcni-rb-cond-row.drag-target{border:2px solid #2271b1}
.lcni-rb-cond-field{font-size:12px;font-weight:600;padding:3px 8px;border-radius:12px}
.lcni-rb-cond-field.numeric{background:#fff8e1;color:#7a4f00;border:1px solid #f0b429}
.lcni-rb-cond-field.text{background:#e7f5ff;color:#1a5276;border:1px solid #74b9e7}
.lcni-rb-cond-op{padding:4px 8px;border:1px solid #dcdcde;border-radius:6px;font-size:13px;background:#fff;cursor:pointer}
/* Checkbox group */
.lcni-rb-checkgroup{display:flex;flex-wrap:wrap;gap:5px}
.lcni-rb-checkitem{display:inline-flex;align-items:center;gap:4px;background:#fff;border:1px solid #dcdcde;border-radius:5px;padding:3px 8px;font-size:12px;cursor:pointer;transition:background .1s}
.lcni-rb-checkitem:hover{background:#f0f7ff}
.lcni-rb-checkitem input{cursor:pointer;margin:0}
.lcni-rb-checkitem.checked{background:#e7f5ff;border-color:#74b9e7}
/* Range slider */
.lcni-rb-range-wrap{display:flex;flex-direction:column;gap:4px;min-width:200px}
.lcni-rb-range-track{position:relative;height:6px;background:#dcdcde;border-radius:3px;margin:8px 4px}
.lcni-rb-range-fill{position:absolute;height:100%;background:#2271b1;border-radius:3px}
.lcni-rb-range-row{display:flex;align-items:center;gap:8px}
.lcni-rb-range-row input[type=range]{flex:1;margin:0}
.lcni-rb-range-val{font-size:12px;font-weight:600;color:#1d2327;min-width:52px;text-align:right;background:#fff;border:1px solid #dcdcde;border-radius:4px;padding:2px 6px}
.lcni-rb-range-numops{display:flex;gap:4px;flex-wrap:wrap;margin-top:2px}
.lcni-rb-numop-btn{padding:2px 8px;border:1px solid #dcdcde;border-radius:4px;font-size:11px;cursor:pointer;background:#fff}
.lcni-rb-numop-btn.active{background:#2271b1;color:#fff;border-color:#2271b1}
/* Connector badge */
.lcni-rb-connector{display:flex;justify-content:center;margin:2px 0}
.lcni-rb-conn-btn{padding:2px 12px;border-radius:10px;font-size:11px;font-weight:700;cursor:pointer;border:1px solid;transition:background .1s}
.lcni-rb-conn-btn.and{background:#f0f7ff;border-color:#72aee6;color:#005fa3}
.lcni-rb-conn-btn.or{background:#fdf2f8;border-color:#c27ba0;color:#6d2b5e}
/* Remove btn */
.lcni-rb-rm{background:none;border:none;color:#a00;cursor:pointer;font-size:16px;padding:2px 4px;line-height:1;flex-shrink:0}
.lcni-rb-rm:hover{color:#d63638}
/* Loading spinner */
.lcni-rb-loading{color:#72aee6;font-size:12px}
</style>';

        // ── Form wrap ────────────────────────────────────────────────────────
        echo '<form method="post" style="margin:16px 0;" id="lcni-rb-form">';
        wp_nonce_field('lcni_recommend_admin_action');
        echo '<input type="hidden" name="lcni_recommend_action" value="' . ($is_edit_mode ? 'update_rule' : 'create_rule') . '" />';
        if ($is_edit_mode) {
            echo '<input type="hidden" name="rule_id" value="' . esc_attr((string) ($editing_rule['id'] ?? '0')) . '" />';
        }

        // ── Wrap ─────────────────────────────────────────────────────────────
        echo '<div class="lcni-rb-wrap">';

        // ── LEFT: Rule settings ──────────────────────────────────────────────
        echo '<div class="lcni-rb-left">';
        echo '<h3>Cài đặt Rule</h3>';
        echo '<p><label>Tên Rule<br><input type="text" name="name" required class="regular-text" value="' . esc_attr((string) ($editing_rule['name'] ?? '')) . '" /></label></p>';
        echo '<p><label>Timeframe<br><input type="text" name="timeframe" value="' . esc_attr((string) ($editing_rule['timeframe'] ?? '1D')) . '" class="small-text" /></label></p>';
        echo '<p><label>Mô tả<br><textarea name="description" rows="2" class="large-text">' . esc_textarea((string) ($editing_rule['description'] ?? '')) . '</textarea></label></p>';
        echo '<p><label>Initial SL % <input type="number" step="0.01" name="initial_sl_pct" value="' . esc_attr((string) ($editing_rule['initial_sl_pct'] ?? '8')) . '" class="small-text" /></label></p>';
        echo '<p><label>Mức lỗ tối đa % <input type="number" step="0.01" min="0" max="100" name="max_loss_pct" value="' . esc_attr((string) ($editing_rule['max_loss_pct'] ?? ($editing_rule['initial_sl_pct'] ?? '8'))) . '" class="small-text" /></label></p>';
        echo '<p><label>Risk Reward <input type="number" step="0.01" name="risk_reward" value="' . esc_attr((string) ($editing_rule['risk_reward'] ?? '3')) . '" class="small-text" /></label></p>';
        echo '<p style="display:flex;gap:12px;flex-wrap:wrap">';
        echo '<label>Add at R <input type="number" step="0.01" name="add_at_r" value="' . esc_attr((string) ($editing_rule['add_at_r'] ?? '2')) . '" class="small-text" /></label>';
        echo '<label>Exit at R <input type="number" step="0.01" name="exit_at_r" value="' . esc_attr((string) ($editing_rule['exit_at_r'] ?? '4')) . '" class="small-text" /></label>';
        echo '<label>Max Hold <input type="number" name="max_hold_days" value="' . esc_attr((string) ($editing_rule['max_hold_days'] ?? '20')) . '" class="small-text" /></label>';
        echo '</p>';
        echo '<p><label>Ngày áp dụng <input type="date" name="apply_from_date" value="' . esc_attr((string) ($editing_rule['apply_from_date'] ?? '')) . '" /></label></p>';

        // Scan times
        $scan_time_options = RuleRepository::ALLOWED_SCAN_TIMES;
        $selected_scan_times_raw = (string) ($editing_rule['scan_times'] ?? '');
        if ($selected_scan_times_raw === '') {
            $selected_scan_times_raw = (string) ($editing_rule['scan_time'] ?? '18:00');
        }
        $selected_scan_times = array_values(array_unique(array_filter(array_map('sanitize_text_field', explode(',', $selected_scan_times_raw)))));
        if (empty($selected_scan_times)) {
            $selected_scan_times = ['18:00'];
        }
        echo '<p style="margin-bottom:4px"><strong>Lịch quét</strong></p>';
        echo '<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:8px">';
        foreach ($scan_time_options as $scan_time_option) {
            $checked = in_array($scan_time_option, $selected_scan_times, true) ? 'checked' : '';
            echo '<label><input type="checkbox" name="scan_times[]" value="' . esc_attr($scan_time_option) . '" ' . $checked . ' /> ' . esc_html($scan_time_option) . '</label>';
        }
        echo '</div>';
        $saved_interval = absint($editing_rule['scan_interval_minutes'] ?? 0);
        $interval_options = [0 => 'Tắt'] + array_combine(
            RuleRepository::ALLOWED_INTRADAY_INTERVALS,
            array_map(fn($m) => "Mỗi {$m} phút nội phiên", RuleRepository::ALLOWED_INTRADAY_INTERVALS)
        );
        echo '<label>Quét nội phiên <select name="scan_interval_minutes">';
        foreach ($interval_options as $val => $label) {
            $sel = $saved_interval === (int)$val ? ' selected' : '';
            echo '<option value="' . esc_attr($val) . '"' . $sel . '>' . esc_html($label) . '</option>';
        }
        echo '</select></label>';
        echo '<p><label><input type="checkbox" name="is_active" value="1" ' . (!array_key_exists('is_active', (array)$editing_rule) || !empty($editing_rule['is_active']) ? 'checked' : '') . ' /> Active</label></p>';
        echo '</div>';

        // ── RIGHT: Condition builder ─────────────────────────────────────────
        echo '<div class="lcni-rb-right">';
        echo '<div class="lcni-rb-panel">';
        echo '<h3>Điều kiện kích hoạt</h3>';

        // Table list
        echo '<div style="display:flex;gap:12px;align-items:flex-start">';
        echo '<div style="min-width:200px">';
        echo '<div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#50575e;margin-bottom:6px">Chọn bảng dữ liệu</div>';
        echo '<div class="lcni-rb-tables" id="lcni-rb-tables">';
        global $wpdb;
        foreach ($table_sources as $real_table => $display_table) {
            $short = str_replace($wpdb->prefix, '', $display_table);
            echo '<button type="button" class="lcni-rb-tbl-btn" data-table="' . esc_attr($display_table) . '">' . esc_html($short) . '</button>';
        }
        echo '</div>';
        echo '</div>';

        // Field chips panel
        echo '<div style="flex:1">';
        echo '<div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#50575e;margin-bottom:6px">Fields — kéo thả vào điều kiện</div>';
        echo '<div id="lcni-rb-fields-area">';
        echo '<p style="color:#8c8f94;font-size:13px">← Chọn bảng để xem fields</p>';
        echo '</div>';
        echo '</div>';
        echo '</div>';

        // Drop zone
        echo '<div style="margin-top:14px">';
        echo '<div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#50575e;margin-bottom:6px">Điều kiện Entry</div>';
        echo '<div class="lcni-rb-dropzone" id="lcni-rb-dropzone">';
        echo '<div class="lcni-rb-dropzone-hint" id="lcni-rb-hint">Kéo field vào đây để thêm điều kiện</div>';
        echo '<div class="lcni-rb-conditions" id="lcni-rb-conditions"></div>';
        echo '</div>';
        echo '</div>';

        // Hidden JSON field
        echo '<textarea id="lcni_recommend_entry_conditions" name="entry_conditions" style="display:none">' . esc_textarea((string)($editing_rule['entry_conditions'] ?? '{}')) . '</textarea>';
        echo '</div>';
        echo '</div>';
        echo '</div>';

        submit_button($is_edit_mode ? 'Update Rule' : 'Save Rule');
        if ($is_edit_mode) {
            echo ' <a class="button" href="' . esc_url(admin_url('admin.php?page=lcni-recommend&tab=create-rule')) . '">Tạo rule mới</a>';
        }
        echo '</form>';

        // ── JavaScript ───────────────────────────────────────────────────────
        $columns_map_json = wp_json_encode($columns_map, JSON_UNESCAPED_UNICODE);
        $ajax_url         = esc_url_raw(admin_url('admin-ajax.php'));
        $nonce            = wp_create_nonce('lcni_recommend_distinct_values');

        echo '<script>';
        echo '(function(){';
        echo 'var COLS=' . $columns_map_json . ';';
        echo 'var AJAX=' . wp_json_encode($ajax_url) . ';';
        echo 'var NONCE=' . wp_json_encode($nonce) . ';';
        echo <<<'JSCODE'

// ── State ────────────────────────────────────────────────────────────────────
var conditions = [];   // [{field, table, isNumeric, operator, value, joinNext}]
var dragField  = null; // {field, table, isNumeric}

// ── Elements ─────────────────────────────────────────────────────────────────
var jsonField   = document.getElementById('lcni_recommend_entry_conditions');
var dropZone    = document.getElementById('lcni-rb-dropzone');
var condList    = document.getElementById('lcni-rb-conditions');
var hint        = document.getElementById('lcni-rb-hint');
var tablesEl    = document.getElementById('lcni-rb-tables');
var fieldsArea  = document.getElementById('lcni-rb-fields-area');

// ── syncJson: write conditions → hidden textarea ──────────────────────────────
function syncJson(){
    var valid = conditions.filter(function(c){
        if(!c.field) return false;
        if(c.operator === 'between') return c.value !== '' && c.valueHi !== '';
        return c.value !== '' && c.value !== null;
    });
    if(!valid.length){ jsonField.value = JSON.stringify({rules:[]}); return; }
    var rules = [];
    valid.forEach(function(c, i){
        if(c.operator === 'between'){
            // between → 2 rules: field >= lo AND field <= hi
            rules.push({field: c.table+'.'+c.field, operator:'>=', value:String(c.value),
                join_with_next:'AND'});
            var item2 = {field: c.table+'.'+c.field, operator:'<=', value:String(c.valueHi)};
            if(i < valid.length-1) item2.join_with_next = c.joinNext||'AND';
            rules.push(item2);
        } else if(c.operator === '=' && typeof c.value === 'string' && c.value.indexOf(',') !== -1){
            // multi-select → multiple OR conditions
            var vals = c.value.split(',').filter(function(v){ return v.trim() !== ''; });
            vals.forEach(function(v, vi){
                var item = {field: c.table+'.'+c.field, operator:'=', value:v.trim()};
                if(vi < vals.length-1){
                    item.join_with_next = 'OR';
                } else if(i < valid.length-1){
                    item.join_with_next = c.joinNext||'AND';
                }
                rules.push(item);
            });
        } else {
            var item = {field: c.table+'.'+c.field, operator: c.operator, value: String(c.value)};
            if(i < valid.length-1) item.join_with_next = c.joinNext||'AND';
            rules.push(item);
        }
    });
    jsonField.value = JSON.stringify({match:'AND', rules:rules}, null, 2);
}

// ── renderConditions ──────────────────────────────────────────────────────────
function renderConditions(){
    hint.style.display = conditions.length ? 'none' : '';
    condList.innerHTML = '';
    conditions.forEach(function(cond, idx){
        // Row
        var row = document.createElement('div');
        row.className = 'lcni-rb-cond-row';
        row.dataset.idx = idx;

        // Field badge
        var badge = document.createElement('span');
        badge.className = 'lcni-rb-cond-field ' + (cond.isNumeric ? 'numeric' : 'text');
        badge.textContent = cond.field;
        row.appendChild(badge);

        // Operator select — ẩn với numeric (dùng button riêng)
        var ops = cond.isNumeric
            ? ['between','>=','<=','>','<','=']
            : ['=','!=','contains','not_contains'];
        var opSel = document.createElement('select');
        opSel.className = 'lcni-rb-cond-op';
        if(cond.isNumeric) opSel.style.display = 'none';
        ops.forEach(function(op){
            var opt = document.createElement('option');
            opt.value = op; opt.textContent = op;
            if(op === cond.operator) opt.selected = true;
            opSel.appendChild(opt);
        });
        opSel.addEventListener('change', function(){
            cond.operator = opSel.value;
            cond.value = ''; cond.valueHi = '';
            renderConditions(); syncJson();
        });
        row.appendChild(opSel);

        // Value input (numeric → range slider / text → checkboxes)
        var valueWrap = document.createElement('div');
        valueWrap.style.flex = '1';
        valueWrap.style.minWidth = '180px';

        if(cond.isNumeric){
            buildNumericInput(cond, valueWrap, idx);
        } else {
            buildTextInput(cond, valueWrap, idx);
        }
        row.appendChild(valueWrap);

        // Remove button
        var rm = document.createElement('button');
        rm.type = 'button'; rm.className = 'lcni-rb-rm'; rm.textContent = '×';
        rm.title = 'Xóa điều kiện';
        rm.addEventListener('click', function(){ conditions.splice(idx,1); renderConditions(); syncJson(); });
        row.appendChild(rm);

        condList.appendChild(row);

        // Connector (AND/OR) between rows
        if(idx < conditions.length-1){
            var conn = document.createElement('div');
            conn.className = 'lcni-rb-connector';
            var btn = document.createElement('button');
            btn.type = 'button';
            var jn = cond.joinNext || 'AND';
            btn.className = 'lcni-rb-conn-btn ' + jn.toLowerCase();
            btn.textContent = jn;
            btn.addEventListener('click', function(){
                cond.joinNext = cond.joinNext === 'OR' ? 'AND' : 'OR';
                renderConditions(); syncJson();
            });
            conn.appendChild(btn);
            condList.appendChild(conn);
        }
    });
}

// ── buildNumericInput — dual-thumb range slider ────────────────────────────────
// cond.operator:  '>=' | '<=' | '>' | '<' | '=' | 'between'
// cond.value:     single number string HOẶC "min,max" khi operator=between
function buildNumericInput(cond, wrap, idx){
    var meta  = getFieldMeta(cond.table, cond.field);
    var rMin  = meta ? meta.min : 0;
    var rMax  = meta ? meta.max : 100;
    var step  = (rMax - rMin) > 100 ? 1 : ((rMax - rMin) > 10 ? 0.5 : 0.01);

    // Nếu chưa có value, set default giữa range
    if(cond.value === '' || cond.value === null){
        cond.value   = String(rMin);
        cond.valueHi = String(rMax);
    } else if(cond.operator === 'between'){
        var parts    = String(cond.value).split(',');
        cond.value   = parts[0] || String(rMin);
        cond.valueHi = parts[1] || String(rMax);
    } else {
        if(!cond.valueHi) cond.valueHi = String(rMax);
    }

    // ── Operator buttons ─────────────────────────────────────────────────────
    var opBtns = document.createElement('div');
    opBtns.className = 'lcni-rb-range-numops';
    var opList = ['between','>=','<=','>','<','='];
    var opLabels = {between:'↔ giữa', '>=':'≥', '<=':'≤', '>':'>', '<':'<', '=':'='};
    opList.forEach(function(op){
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'lcni-rb-numop-btn' + (cond.operator === op ? ' active' : '');
        b.textContent = opLabels[op] || op;
        b.addEventListener('click', function(){
            cond.operator = op;
            opBtns.querySelectorAll('.lcni-rb-numop-btn').forEach(function(x){ x.classList.remove('active'); });
            b.classList.add('active');
            rebuildSlider();
            syncJson();
        });
        opBtns.appendChild(b);
    });
    wrap.appendChild(opBtns);

    // ── Slider container (rebuilt on operator change) ─────────────────────────
    var sliderContainer = document.createElement('div');
    sliderContainer.style.cssText = 'margin-top:8px';
    wrap.appendChild(sliderContainer);

    function rebuildSlider(){
        sliderContainer.innerHTML = '';
        if(cond.operator === 'between'){
            buildDualSlider(cond, sliderContainer, rMin, rMax, step);
        } else {
            buildSingleSlider(cond, sliderContainer, rMin, rMax, step);
        }
    }
    rebuildSlider();
}

// ── Dual-thumb range slider (between) ─────────────────────────────────────────
function buildDualSlider(cond, wrap, min, max, step){
    var loVal = parseFloat(cond.value)   || min;
    var hiVal = parseFloat(cond.valueHi) || max;
    loVal = Math.max(min, Math.min(loVal, max));
    hiVal = Math.max(min, Math.min(hiVal, max));

    // Value display row
    var dispRow = document.createElement('div');
    dispRow.style.cssText = 'display:flex;align-items:center;gap:8px;margin-bottom:6px;font-size:13px';

    var loDisp = document.createElement('input');
    loDisp.type = 'number'; loDisp.step = step; loDisp.min = min; loDisp.max = max;
    loDisp.value = loVal;
    loDisp.style.cssText = 'width:80px;padding:3px 6px;border:1px solid #2271b1;border-radius:4px;font-size:13px;font-weight:600;color:#2271b1';

    var sep = document.createElement('span');
    sep.textContent = '→'; sep.style.color = '#8c8f94';

    var hiDisp = document.createElement('input');
    hiDisp.type = 'number'; hiDisp.step = step; hiDisp.min = min; hiDisp.max = max;
    hiDisp.value = hiVal;
    hiDisp.style.cssText = 'width:80px;padding:3px 6px;border:1px solid #2271b1;border-radius:4px;font-size:13px;font-weight:600;color:#2271b1';

    dispRow.appendChild(loDisp); dispRow.appendChild(sep); dispRow.appendChild(hiDisp);
    wrap.appendChild(dispRow);

    // Track
    var trackWrap = document.createElement('div');
    trackWrap.style.cssText = 'position:relative;height:28px;margin:0 4px';

    var track = document.createElement('div');
    track.style.cssText = 'position:absolute;top:50%;transform:translateY(-50%);left:0;right:0;height:5px;background:#dcdcde;border-radius:3px';

    var fill = document.createElement('div');
    fill.style.cssText = 'position:absolute;height:5px;background:#2271b1;border-radius:3px;top:0';

    track.appendChild(fill);
    trackWrap.appendChild(track);

    // Two range inputs stacked
    var styleBase = 'position:absolute;width:100%;top:50%;transform:translateY(-50%);height:5px;appearance:none;-webkit-appearance:none;background:transparent;pointer-events:none;outline:none';

    var sLo = document.createElement('input');
    sLo.type='range'; sLo.min=min; sLo.max=max; sLo.step=step; sLo.value=loVal;
    sLo.style.cssText = styleBase;

    var sHi = document.createElement('input');
    sHi.type='range'; sHi.min=min; sHi.max=max; sHi.step=step; sHi.value=hiVal;
    sHi.style.cssText = styleBase;

    // Thumb CSS injected once
    if(!document.getElementById('lcni-dual-thumb-style')){
        var st = document.createElement('style');
        st.id = 'lcni-dual-thumb-style';
        st.textContent = [
            'input.lcni-dt::-webkit-slider-thumb{-webkit-appearance:none;appearance:none;',
            'width:18px;height:18px;border-radius:50%;background:#2271b1;',
            'border:2px solid #fff;box-shadow:0 1px 4px rgba(0,0,0,.25);pointer-events:all;cursor:pointer}',
            'input.lcni-dt::-moz-range-thumb{width:16px;height:16px;border-radius:50%;',
            'background:#2271b1;border:2px solid #fff;pointer-events:all;cursor:pointer}'
        ].join('');
        document.head.appendChild(st);
    }
    sLo.className = 'lcni-dt';
    sHi.className = 'lcni-dt';

    trackWrap.appendChild(sLo);
    trackWrap.appendChild(sHi);
    wrap.appendChild(trackWrap);

    // Labels
    var labels = document.createElement('div');
    labels.style.cssText = 'display:flex;justify-content:space-between;font-size:11px;color:#8c8f94;margin-top:3px';
    labels.innerHTML = '<span>'+min+'</span><span>'+max+'</span>';
    wrap.appendChild(labels);

    function updateFill(){
        var lo = parseFloat(sLo.value);
        var hi = parseFloat(sHi.value);
        var pLo = (lo - min)/(max - min)*100;
        var pHi = (hi - min)/(max - min)*100;
        fill.style.left  = pLo + '%';
        fill.style.width = (pHi - pLo) + '%';
    }
    updateFill();

    function onLoChange(){
        var lo = parseFloat(sLo.value);
        var hi = parseFloat(sHi.value);
        if(lo > hi){ sLo.value = hi; lo = hi; }
        loDisp.value = lo;
        cond.value   = String(lo);
        updateFill(); syncJson();
    }
    function onHiChange(){
        var lo = parseFloat(sLo.value);
        var hi = parseFloat(sHi.value);
        if(hi < lo){ sHi.value = lo; hi = lo; }
        hiDisp.value   = hi;
        cond.valueHi   = String(hi);
        updateFill(); syncJson();
    }

    sLo.addEventListener('input', onLoChange);
    sHi.addEventListener('input', onHiChange);

    loDisp.addEventListener('change', function(){
        var v = Math.max(min, Math.min(parseFloat(loDisp.value)||min, parseFloat(sHi.value)));
        sLo.value = v; cond.value = String(v);
        updateFill(); syncJson();
    });
    hiDisp.addEventListener('change', function(){
        var v = Math.min(max, Math.max(parseFloat(hiDisp.value)||max, parseFloat(sLo.value)));
        sHi.value = v; cond.valueHi = String(v);
        updateFill(); syncJson();
    });
}

// ── Single-thumb slider (>=, <=, >, <, =) ─────────────────────────────────────
function buildSingleSlider(cond, wrap, min, max, step){
    var curVal = parseFloat(cond.value) || min;

    var dispRow = document.createElement('div');
    dispRow.style.cssText = 'display:flex;align-items:center;gap:8px;margin-bottom:6px';

    var numInput = document.createElement('input');
    numInput.type='number'; numInput.step=step; numInput.min=min; numInput.max=max;
    numInput.value = curVal;
    numInput.style.cssText = 'width:100px;padding:3px 6px;border:1px solid #2271b1;border-radius:4px;font-size:13px;font-weight:600;color:#2271b1';
    dispRow.appendChild(numInput);
    wrap.appendChild(dispRow);

    var trackWrap = document.createElement('div');
    trackWrap.style.cssText = 'position:relative;height:24px;margin:0 4px';

    var track = document.createElement('div');
    track.style.cssText = 'position:absolute;top:50%;transform:translateY(-50%);left:0;right:0;height:5px;background:#dcdcde;border-radius:3px';
    var fill = document.createElement('div');
    fill.style.cssText = 'position:absolute;height:5px;background:#2271b1;border-radius:3px;top:0;left:0';
    track.appendChild(fill);
    trackWrap.appendChild(track);

    var slider = document.createElement('input');
    slider.type='range'; slider.min=min; slider.max=max; slider.step=step; slider.value=curVal;
    slider.className='lcni-dt';
    slider.style.cssText = 'position:absolute;width:100%;top:50%;transform:translateY(-50%);height:5px;appearance:none;-webkit-appearance:none;background:transparent;outline:none';
    trackWrap.appendChild(slider);
    wrap.appendChild(trackWrap);

    var labels = document.createElement('div');
    labels.style.cssText = 'display:flex;justify-content:space-between;font-size:11px;color:#8c8f94;margin-top:3px';
    labels.innerHTML = '<span>'+min+'</span><span>'+max+'</span>';
    wrap.appendChild(labels);

    function updateFill(){
        var pct = (parseFloat(slider.value) - min)/(max - min)*100;
        // For <= operators, fill from right; for >= fill from left
        if(cond.operator === '<=' || cond.operator === '<'){
            fill.style.left='0'; fill.style.width=pct+'%';
        } else {
            fill.style.left=pct+'%'; fill.style.width=(100-pct)+'%';
        }
    }
    updateFill();

    slider.addEventListener('input', function(){
        var v = slider.value;
        cond.value = v; numInput.value = v;
        updateFill(); syncJson();
    });
    numInput.addEventListener('change', function(){
        var v = Math.max(min, Math.min(parseFloat(numInput.value)||min, max));
        slider.value = v; cond.value = String(v);
        numInput.value = v;
        updateFill(); syncJson();
    });
}

// ── buildTextInput ────────────────────────────────────────────────────────────
function buildTextInput(cond, wrap, idx){
    var key = cond.table + '.' + cond.field;

    // Loading indicator
    var loading = document.createElement('span');
    loading.className = 'lcni-rb-loading';
    loading.textContent = '⟳ Đang tải...';
    wrap.appendChild(loading);

    // Check if we have cached values
    if(window.__lcniDistinct && window.__lcniDistinct[key]){
        loading.remove();
        buildCheckboxes(cond, wrap, window.__lcniDistinct[key]);
        return;
    }

    // Fetch distinct values via AJAX
    var fd = new FormData();
    fd.append('action','lcni_recommend_distinct_values');
    fd.append('nonce', NONCE);
    fd.append('table', cond.table);
    fd.append('field', cond.field);

    fetch(AJAX, {method:'POST', body:fd})
        .then(function(r){ return r.json(); })
        .then(function(res){
            loading.remove();
            if(!window.__lcniDistinct) window.__lcniDistinct = {};
            var vals = (res.success && Array.isArray(res.data)) ? res.data : [];
            window.__lcniDistinct[key] = vals;
            buildCheckboxes(cond, wrap, vals);
        })
        .catch(function(){
            loading.textContent = '';
            buildFallbackTextInput(cond, wrap);
        });
}

// ── buildCheckboxes — multi-select checkboxes ─────────────────────────────────
function buildCheckboxes(cond, wrap, values){
    if(!values || !values.length){
        buildFallbackTextInput(cond, wrap);
        return;
    }

    // Parse current selected (comma-separated)
    var selected = cond.value ? String(cond.value).split(',').map(function(v){ return v.trim(); }) : [];

    var group = document.createElement('div');
    group.className = 'lcni-rb-checkgroup';

    values.forEach(function(val){
        var isChecked = selected.indexOf(val) !== -1;

        var item = document.createElement('label');
        item.className = 'lcni-rb-checkitem' + (isChecked ? ' checked' : '');
        item.style.cursor = 'pointer';

        var cb = document.createElement('input');
        cb.type  = 'checkbox';
        cb.value = val;
        cb.checked = isChecked;
        cb.style.cssText = 'cursor:pointer;margin:0 4px 0 0;accent-color:#2271b1';

        var lbl = document.createElement('span');
        lbl.textContent = val;

        item.appendChild(cb);
        item.appendChild(lbl);

        cb.addEventListener('change', function(){
            if(cb.checked){
                item.classList.add('checked');
                if(selected.indexOf(val) === -1) selected.push(val);
            } else {
                item.classList.remove('checked');
                selected = selected.filter(function(v){ return v !== val; });
            }
            cond.value = selected.join(',');
            syncJson();
        });

        group.appendChild(item);
    });

    wrap.appendChild(group);
}

// ── buildFallbackTextInput ────────────────────────────────────────────────────
function buildFallbackTextInput(cond, wrap, small){
    var input = document.createElement('input');
    input.type = 'text';
    input.value = cond.value || '';
    input.placeholder = 'Hoặc nhập thủ công...';
    input.style.cssText = 'width:100%;padding:4px 8px;border:1px solid #dcdcde;border-radius:4px;font-size:'+(small?'12':'13')+'px';
    input.addEventListener('input', function(){
        cond.value = input.value.trim();
        // Uncheck all checkboxes
        wrap.closest('.lcni-rb-cond-row')
            && wrap.closest('.lcni-rb-cond-row').querySelectorAll('.lcni-rb-checkitem').forEach(function(x){
                x.classList.remove('checked');
                var cb = x.querySelector('input');
                if(cb) cb.checked = false;
            });
        syncJson();
    });
    wrap.appendChild(input);
}

// ── getFieldMeta: get min/max hints per field ─────────────────────────────────
function getFieldMeta(table, field){
    // Gợi ý min/max cho các field phổ biến
    var hints = {
        rsi:            {min:0,   max:100},
        macd:           {min:-50, max:50},
        macd_signal:    {min:-50, max:50},
        pct_t_1:        {min:-0.1, max:0.1},
        pct_t_3:        {min:-0.15,max:0.15},
        pct_1w:         {min:-0.2, max:0.2},
        pct_1m:         {min:-0.3, max:0.3},
        pct_3m:         {min:-0.5, max:0.5},
        close_price:    {min:1,   max:200},
        volume:         {min:0,   max:50000000},
        vol_sv_vol_ma10:{min:0,   max:10},
        vol_sv_vol_ma20:{min:0,   max:10},
        gia_sv_ma10:    {min:-0.3,max:0.3},
        gia_sv_ma20:    {min:-0.3,max:0.3},
        gia_sv_ma50:    {min:-0.5,max:0.5},
        industry_return:{min:-0.5,max:0.5},
        breadth:        {min:0,   max:1},
        relative_strength:{min:-3,max:3},
    };
    return hints[field] || null;
}

// ── Table click → render chips ────────────────────────────────────────────────
tablesEl.querySelectorAll('.lcni-rb-tbl-btn').forEach(function(btn){
    btn.addEventListener('click', function(){
        tablesEl.querySelectorAll('.lcni-rb-tbl-btn').forEach(function(b){ b.classList.remove('active'); });
        btn.classList.add('active');
        var table = btn.dataset.table;
        renderFieldChips(table);
    });
});

function renderFieldChips(table){
    fieldsArea.innerHTML = '';
    var cols = COLS[table] || [];
    if(!cols.length){
        fieldsArea.innerHTML = '<p style="color:#8c8f94;font-size:13px">Không có field.</p>';
        return;
    }
    // Group: numeric và text
    var numCols = cols.filter(function(c){ return c.is_numeric; });
    var txtCols = cols.filter(function(c){ return !c.is_numeric; });

    if(numCols.length){
        var numLabel = document.createElement('div');
        numLabel.style.cssText = 'font-size:11px;color:#7a4f00;font-weight:700;margin:0 0 4px';
        numLabel.textContent = '📊 Số (kéo thả hoặc click)';
        fieldsArea.appendChild(numLabel);
        var numRow = document.createElement('div');
        numRow.className = 'lcni-rb-chips';
        numCols.forEach(function(col){ numRow.appendChild(makeChip(col, table, true)); });
        fieldsArea.appendChild(numRow);
    }
    if(txtCols.length){
        var txtLabel = document.createElement('div');
        txtLabel.style.cssText = 'font-size:11px;color:#1a5276;font-weight:700;margin:8px 0 4px';
        txtLabel.textContent = '🏷 Văn bản (kéo thả hoặc click)';
        fieldsArea.appendChild(txtLabel);
        var txtRow = document.createElement('div');
        txtRow.className = 'lcni-rb-chips';
        txtCols.forEach(function(col){ txtRow.appendChild(makeChip(col, table, false)); });
        fieldsArea.appendChild(txtRow);
    }
}

function makeChip(col, table, isNumeric){
    var chip = document.createElement('div');
    chip.className = 'lcni-rb-chip ' + (isNumeric ? 'numeric' : 'text');
    chip.draggable = true;
    chip.textContent = col.field;
    chip.title = col.raw_type + ' — kéo vào điều kiện hoặc click để thêm nhanh';

    var meta = {field: col.field, table: table, isNumeric: isNumeric};

    // Click → add directly
    chip.addEventListener('click', function(){
        addCondition(meta);
    });

    // Drag
    chip.addEventListener('dragstart', function(e){
        dragField = meta;
        chip.classList.add('dragging');
        e.dataTransfer.effectAllowed = 'copy';
    });
    chip.addEventListener('dragend', function(){
        chip.classList.remove('dragging');
        dragField = null;
    });

    return chip;
}

// ── Drop zone events ──────────────────────────────────────────────────────────
dropZone.addEventListener('dragover', function(e){
    e.preventDefault();
    dropZone.classList.add('drag-over');
});
dropZone.addEventListener('dragleave', function(e){
    if(!dropZone.contains(e.relatedTarget)) dropZone.classList.remove('drag-over');
});
dropZone.addEventListener('drop', function(e){
    e.preventDefault();
    dropZone.classList.remove('drag-over');
    if(dragField) addCondition(dragField);
});

// ── addCondition ──────────────────────────────────────────────────────────────
function addCondition(meta){
    var defaultOp = meta.isNumeric ? 'between' : '=';
    var rMeta     = getFieldMeta(meta.table, meta.field);
    conditions.push({
        field:    meta.field,
        table:    meta.table,
        isNumeric:meta.isNumeric,
        operator: defaultOp,
        value:    rMeta ? String(rMeta.min) : '',
        valueHi:  rMeta ? String(rMeta.max) : '',
        joinNext: 'AND'
    });
    renderConditions();
    syncJson();
}

// ── Load existing conditions from hidden textarea ─────────────────────────────
(function loadExisting(){
    try{
        var parsed = JSON.parse(jsonField.value || '{}');
        if(!parsed || !Array.isArray(parsed.rules) || !parsed.rules.length) return;
        parsed.rules.forEach(function(rule){
            var parts = String(rule.field||'').split('.');
            if(parts.length < 2) return;
            var table = parts.slice(0,-1).join('.');
            var field = parts[parts.length-1];
            // Tìm is_numeric từ COLS
            var isNumeric = false;
            var cols = COLS[table] || [];
            cols.forEach(function(c){ if(c.field===field) isNumeric = c.is_numeric; });
            conditions.push({
                field:    field,
                table:    table,
                isNumeric:isNumeric,
                operator: rule.operator || (isNumeric ? '>=' : '='),
                value:    rule.value || '',
                joinNext: rule.join_with_next || 'AND'
            });
        });
        renderConditions();
        // Auto-select first table
        var firstTable = conditions[0] && conditions[0].table;
        if(firstTable){
            var btn = tablesEl.querySelector('[data-table="'+firstTable+'"]');
            if(btn){ btn.click(); }
        }
    }catch(e){}
})();

// Nếu chưa có conditions, render trống
if(!conditions.length) renderConditions();

// ── Form submit validation ────────────────────────────────────────────────────
document.getElementById('lcni-rb-form').addEventListener('submit', function(e){
    syncJson();
    var parsed = {};
    try{ parsed = JSON.parse(jsonField.value||'{}'); }catch(ex){}
    var rules = parsed.rules || [];
    var incomplete = rules.filter(function(r){ return !r.field || r.value===''||r.value===null; });
    if(incomplete.length){
        e.preventDefault();
        alert('Vui lòng điền đủ giá trị cho tất cả các điều kiện trước khi lưu.');
    }
});

JSCODE;
        echo '})();';
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

                // Nút Rebuild: xóa signals cũ → backfill lại từ đầu
                // Dùng khi chart "Chưa có lịch sử" dù rule đã có dữ liệu
                echo '<form method="post" style="margin:4px 0 0;display:inline-block;">';
                wp_nonce_field('lcni_recommend_admin_action');
                echo '<input type="hidden" name="lcni_recommend_action" value="rebuild_rule" />';
                echo '<input type="hidden" name="rule_id" value="' . esc_attr((string) ((int) ($rule['id'] ?? 0))) . '" />';
                echo '<button type="submit" class="button button-small" style="color:#b91c1c;border-color:#b91c1c;" onclick="return confirm(\'Xóa toàn bộ signals của rule này và backfill lại từ đầu?\')">🔄 Rebuild</button>';
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

    private function render_market_context_tab(): void {
        global $wpdb;

        // Xử lý POST backfill
        if (
            isset( $_POST['lcni_mc_action'] )
            && $_POST['lcni_mc_action'] === 'backfill'
            && check_admin_referer( 'lcni_mc_backfill' )
            && current_user_can( 'manage_options' )
        ) {
            $tf    = strtoupper( sanitize_text_field( (string) ( $_POST['timeframe'] ?? '1D' ) ) );
            $tf    = in_array( $tf, [ '1D', '1W', '1M' ], true ) ? $tf : '1D';
            $limit = max( 1, min( 500, (int) ( $_POST['limit'] ?? 200 ) ) );
            $repo  = new LCNI_MarketDashboardRepository();
            $saved = $repo->backfill_history( $tf, $limit );
            wp_safe_redirect( admin_url(
                'admin.php?page=lcni-recommend&tab=market-context&backfilled=1&bf_count=' . $saved . '&bf_tf=' . urlencode( $tf )
            ) );
            exit;
        }

        // Thông báo kết quả
        if ( isset( $_GET['backfilled'] ) ) {
            $cnt = (int) ( $_GET['bf_count'] ?? 0 );
            $tf  = sanitize_text_field( (string) ( $_GET['bf_tf'] ?? '' ) );
            echo '<div class="notice notice-success is-dismissible"><p>Backfill xong: <strong>' . esc_html( (string) $cnt ) . '</strong> snapshot cho timeframe <strong>' . esc_html( $tf ) . '</strong>.</p></div>';
        }

        $history_tbl = $wpdb->prefix . 'lcni_market_context';
        $latest_tbl  = $wpdb->prefix . 'lcni_market_context_latest';
        $history_ok  = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $history_tbl ) ) === $history_tbl;
        $latest_ok   = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $latest_tbl ) ) === $latest_tbl;

        echo '<div style="max-width:860px;margin-top:16px">';
        echo '<h2>Market Context — Lịch sử & Backfill</h2>';
        echo '<p>Bảng <code>' . esc_html( $history_tbl ) . '</code> lưu n phiên lịch sử. '
           . 'Bảng <code>' . esc_html( $latest_tbl ) . '</code> lưu 1 row per timeframe cho Rule engine JOIN.</p>';

        // Thống kê
        echo '<table class="wp-list-table widefat fixed striped" style="margin-bottom:18px"><thead><tr>'
           . '<th>Timeframe</th><th>Phiên nguồn</th><th>History đã lưu</th><th>Còn thiếu</th><th>Latest snapshot</th>'
           . '</tr></thead><tbody>';

        foreach ( [ '1D', '1W', '1M' ] as $tf ) {
            $src = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(DISTINCT event_time) FROM {$wpdb->prefix}lcni_thong_ke_thi_truong WHERE timeframe = %s", $tf
            ) );
            $hist = $history_ok ? (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$history_tbl} WHERE timeframe = %s", $tf
            ) ) : 0;
            $missing = max( 0, $src - $hist );
            $lat_et  = $latest_ok ? (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT event_time FROM {$latest_tbl} WHERE timeframe = %s", $tf
            ) ) : 0;
            $miss_html = $missing > 0
                ? '<span style="color:#d63638;font-weight:bold">' . $missing . '</span>'
                : '<span style="color:#00a32a">0 ✓</span>';
            echo '<tr><td><strong>' . esc_html( $tf ) . '</strong></td><td>' . $src . '</td><td>' . $hist . '</td>'
               . '<td>' . $miss_html . '</td>'
               . '<td>' . ( $lat_et > 0 ? esc_html( date( 'd/m/Y', $lat_et ) ) : '—' ) . '</td></tr>';
        }
        echo '</tbody></table>';

        // Form backfill
        echo '<div style="background:#fff;border:1px solid #c3c4c7;padding:16px;border-radius:4px;margin-bottom:18px">';
        echo '<h3 style="margin-top:0">Backfill lịch sử</h3>';
        echo '<form method="post">';
        wp_nonce_field( 'lcni_mc_backfill' );
        echo '<input type="hidden" name="lcni_mc_action" value="backfill">';
        echo '<p><label>Timeframe: <select name="timeframe">';
        foreach ( [ '1D', '1W', '1M' ] as $opt ) {
            echo '<option value="' . esc_attr( $opt ) . '">' . esc_html( $opt ) . '</option>';
        }
        echo '</select></label> &nbsp; ';
        echo '<label>Số phiên tối đa: <input type="number" name="limit" value="200" min="1" max="500" style="width:70px"></label>';
        echo ' &nbsp; <button type="submit" class="button button-primary">Chạy Backfill</button></p>';
        echo '</form></div>';

        // Hướng dẫn
        echo '<div style="background:#f0f6fc;border-left:4px solid #2271b1;padding:12px 16px;border-radius:2px">';
        echo '<strong>Dùng trong Rule Builder:</strong> chọn field từ bảng <code>' . esc_html( $latest_tbl ) . '</code>. Ví dụ:<br>';
        echo '<code>' . esc_html( $latest_tbl ) . '.market_bias = "Tích cực"</code><br>';
        echo '<code>' . esc_html( $latest_tbl ) . '.market_composite_score >= 55</code><br>';
        echo '<code>' . esc_html( $latest_tbl ) . '.breadth_pct_above_ma50 >= 50</code>';
        echo '</div>';
        echo '</div>';
    }
}
