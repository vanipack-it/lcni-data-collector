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
        $this->items = $this->items_data;
        $columns = $this->get_columns();
        $this->_column_headers = [$columns, [], []];
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
            'id' => 'ID',
            'symbol' => 'Symbol',
            'rule_name' => 'Rule',
            'entry_price' => 'Entry',
            'current_price' => 'Current',
            'r_multiple' => 'R',
            'position_state' => 'State',
            'status' => 'Status',
        ];
    }

    public function prepare_items() {
        $this->items = $this->items_data;
        $columns = $this->get_columns();
        $this->_column_headers = [$columns, [], []];
    }

    protected function column_default($item, $column_name) {
        return esc_html((string) ($item[$column_name] ?? ''));
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
            'rule_name' => 'Rule',
            'total_trades' => 'Total',
            'win_trades' => 'Win',
            'lose_trades' => 'Lose',
            'winrate' => 'Winrate',
            'avg_r' => 'Avg R',
            'expectancy' => 'Expectancy',
            'max_r' => 'Max R',
            'min_r' => 'Min R',
        ];
    }

    public function prepare_items() {
        $this->items = $this->items_data;
        $columns = $this->get_columns();
        $this->_column_headers = [$columns, [], []];
    }

    protected function column_default($item, $column_name) {
        return esc_html((string) ($item[$column_name] ?? ''));
    }
}

class LCNI_Recommend_Admin_Page {
    private $rule_repository;
    private $signal_repository;
    private $performance_calculator;

    public function __construct(RuleRepository $rule_repository, SignalRepository $signal_repository, PerformanceCalculator $performance_calculator) {
        $this->rule_repository = $rule_repository;
        $this->signal_repository = $signal_repository;
        $this->performance_calculator = $performance_calculator;

        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_init', [$this, 'handle_actions']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_ajax_lcni_recommend_distinct_values', [$this, 'ajax_distinct_values']);
        add_action('wp_ajax_lcni_recommend_table_columns', [$this, 'ajax_table_columns']);
    }

    public function enqueue_assets($hook) {
        if ($hook !== 'lcni-settings_page_lcni-recommend') {
            return;
        }

        $table_sources = $this->get_builder_table_sources();
        $columns_map = [];

        foreach ($table_sources as $real_table => $display_table) {
            $columns_map[$real_table] = $this->get_table_columns_for_builder($real_table);
        }

        wp_enqueue_script(
            'lcni-recommend-builder',
            LCNI_URL . 'assets/js/recommend-builder.js',
            [],
            '1.0.0',
            true
        );

        wp_localize_script('lcni-recommend-builder', 'LCNI_RECOMMEND', [
            'columnsMap' => $columns_map,
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonceDistinct' => wp_create_nonce('lcni_recommend_distinct_values'),
            'nonceColumns' => wp_create_nonce('lcni_recommend_table_columns'),
        ]);
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
                'risk_reward' => (float) ($_POST['risk_reward'] ?? 3),
                'add_at_r' => (float) ($_POST['add_at_r'] ?? 2),
                'exit_at_r' => (float) ($_POST['exit_at_r'] ?? 4),
                'max_hold_days' => (int) ($_POST['max_hold_days'] ?? 20),
                'is_active' => !empty($_POST['is_active']) ? 1 : 0,
            ]);

            if (is_wp_error($saved_rule_id) || (int) $saved_rule_id <= 0) {
                wp_safe_redirect(admin_url('admin.php?page=lcni-recommend&tab=rules&created=0'));
                exit;
            }

            wp_safe_redirect(admin_url('admin.php?page=lcni-recommend&tab=rules&created=1'));
            exit;
        }
    }

    public function render_page() {
        $tab = isset($_GET['tab']) ? sanitize_key((string) $_GET['tab']) : 'rules';
        echo '<div class="wrap"><h1>LCNi Recommend</h1>';
        echo '<h2 class="nav-tab-wrapper">';
        echo '<a href="' . esc_url(admin_url('admin.php?page=lcni-recommend&tab=rules')) . '" class="nav-tab ' . ($tab === 'rules' ? 'nav-tab-active' : '') . '">Rules</a>';
        echo '<a href="' . esc_url(admin_url('admin.php?page=lcni-recommend&tab=signals')) . '" class="nav-tab ' . ($tab === 'signals' ? 'nav-tab-active' : '') . '">Signals</a>';
        echo '<a href="' . esc_url(admin_url('admin.php?page=lcni-recommend&tab=performance')) . '" class="nav-tab ' . ($tab === 'performance' ? 'nav-tab-active' : '') . '">Performance</a>';
        echo '</h2>';

        $created = isset($_GET['created']) ? sanitize_text_field((string) $_GET['created']) : '';
        if ($created === '1') {
            echo '<div class="notice notice-success is-dismissible"><p>Đã lưu rule thành công.</p></div>';
        } elseif ($created === '0') {
            echo '<div class="notice notice-error"><p>Lưu rule thất bại. Vui lòng kiểm tra dữ liệu và thử lại.</p></div>';
        }

        if ($tab === 'rules') {
            $this->render_rules_tab();
        } elseif ($tab === 'signals') {
            $this->render_signals_tab();
        } else {
            $this->render_performance_tab();
        }

        echo '</div>';
    }

    private function get_builder_table_sources() {
        $suffixes = ['lcni_ohlc', 'lcni_symbol_tong_quan', 'lcni_icb2', 'lcni_marketid'];
        $sources = [];

        foreach ($suffixes as $suffix) {
            $resolved = $this->resolve_table_name($suffix);
            if (!$this->table_exists($resolved)) {
                continue;
            }

            $sources[$resolved] = $resolved;
        }

        return $sources;
    }

    private function get_table_columns_for_builder($table_name) {
        global $wpdb;

        $columns = [];
        $rows = $wpdb->get_results("SHOW COLUMNS FROM `{$table_name}`", ARRAY_A);

        if (!is_array($rows) || empty($rows)) {
            $rows = $wpdb->get_results("DESCRIBE `{$table_name}`", ARRAY_A);
        }

        foreach ((array) $rows as $row) {
            $field_value = '';
            if (isset($row['Field'])) {
                $field_value = (string) $row['Field'];
            } elseif (isset($row['field'])) {
                $field_value = (string) $row['field'];
            }

            $field = sanitize_key($field_value);
            if ($field === '') {
                continue;
            }

            $raw_type_value = '';
            if (isset($row['Type'])) {
                $raw_type_value = (string) $row['Type'];
            } elseif (isset($row['type'])) {
                $raw_type_value = (string) $row['type'];
            }

            $raw_type = strtolower($raw_type_value);
            $is_numeric = (bool) preg_match('/int|decimal|numeric|float|double|real|bit|serial/', $raw_type);

            $columns[] = [
                'field' => $field,
                'raw_type' => $raw_type,
                'is_numeric' => $is_numeric,
                'table' => $table_name,
            ];
        }

        return $columns;
    }

    private function render_rules_tab() {
        echo '<style>
            .lcni-recommend-builder-grid{display:grid;grid-template-columns:1fr 2fr;gap:12px;margin-top:12px}
            .lcni-recommend-panel{background:#fff;border:1px solid #dcdcde;padding:12px;min-height:380px}
            .lcni-recommend-panel h3{margin-top:0}
            .lcni-recommend-row{display:flex;gap:10px;align-items:center;margin-bottom:8px;flex-wrap:wrap}
            .lcni-recommend-conditions-wrap{display:flex;flex-direction:column;gap:8px}
            .lcni-recommend-condition-item{border:1px solid #dcdcde;padding:8px;background:#fff;display:grid;grid-template-columns:1fr 1fr 120px 1fr auto;gap:8px;align-items:center}
            .lcni-recommend-condition-item em{grid-column:1/-1}
            .lcni-recommend-json-hint{margin-top:10px}
            @media (max-width:1100px){
                .lcni-recommend-builder-grid{grid-template-columns:1fr}
                .lcni-recommend-condition-item{grid-template-columns:1fr}
            }
        </style>';

        echo '<form method="post" style="margin:16px 0;">';
        wp_nonce_field('lcni_recommend_admin_action');
        echo '<input type="hidden" name="lcni_recommend_action" value="create_rule" />';

        echo '<div class="lcni-recommend-builder-grid">';

        echo '<div class="lcni-recommend-panel">';
        echo '<h3>Rule</h3>';
        echo '<p><label>Name<br><input type="text" name="name" required class="regular-text" /></label></p>';
        echo '<p><label>Timeframe<br><input type="text" name="timeframe" value="1D" class="small-text" /></label></p>';
        echo '<p><label>Description recommend<br><textarea name="description" rows="3" class="large-text"></textarea></label></p>';
        echo '<div class="lcni-recommend-row"><label>Initial SL % <input type="number" step="0.01" name="initial_sl_pct" value="8" /></label></div>';
        echo '<div class="lcni-recommend-row"><label>Risk Reward <input type="number" step="0.01" name="risk_reward" value="3" /></label></div>';
        echo '<div class="lcni-recommend-row"><label>Add at R <input type="number" step="0.01" name="add_at_r" value="2" /></label></div>';
        echo '<div class="lcni-recommend-row"><label>Exit at R <input type="number" step="0.01" name="exit_at_r" value="4" /></label></div>';
        echo '<div class="lcni-recommend-row"><label>Max Hold Days <input type="number" name="max_hold_days" value="20" /></label></div>';
        echo '<p><label><input type="checkbox" name="is_active" value="1" checked /> Active</label></p>';
        echo '</div>';

        echo '<div class="lcni-recommend-panel">';
        echo '<h3>Entry Conditions</h3>';
        echo '<p>Mỗi rule là 1 điều kiện, nhiều rule sẽ kết hợp bằng <strong>AND</strong>.</p>';
        echo '<div id="lcni-recommend-conditions" class="lcni-recommend-conditions-wrap"></div>';
        echo '<p><button type="button" class="button" id="lcni-recommend-add-condition">+ Add condition</button></p>';
        echo '<p class="lcni-recommend-json-hint"><label>Entry Conditions JSON<br><textarea id="lcni_recommend_entry_conditions" name="entry_conditions" rows="10" class="large-text code"></textarea></label></p>';
        echo '</div>';

        echo '</div>';

        submit_button('Save Rule');
        echo '</form>';

        $table = new LCNI_Recommend_Rules_List_Table($this->rule_repository->all(200));
        $table->prepare_items();
        $table->display();
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

        $resolved_table = $this->resolve_builder_request_table($table);
        if ($resolved_table === '') {
            wp_send_json_error(['message' => 'Unknown table'], 400);
        }

        $columns = $this->get_table_columns_for_builder($resolved_table);
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
            esc_sql($resolved_table)
        );

        $rows = $wpdb->get_col($query);
        $values = array_values(array_filter(array_map('strval', (array) $rows), static function ($value) {
            return $value !== '';
        }));

        wp_send_json_success($values);
    }

    public function ajax_table_columns() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied'], 403);
        }

        check_ajax_referer('lcni_recommend_table_columns', 'nonce');

        $table = sanitize_text_field(wp_unslash((string) ($_POST['table'] ?? '')));
        if ($table === '') {
            wp_send_json_error(['message' => 'Invalid table'], 400);
        }

        $resolved_table = $this->resolve_builder_request_table($table);
        if ($resolved_table === '') {
            wp_send_json_error(['message' => 'Unknown table'], 400);
        }

        wp_send_json_success($this->get_table_columns_for_builder($resolved_table));
    }

    private function table_exists($table_name) {
        global $wpdb;

        $table_name = sanitize_text_field((string) $table_name);
        if ($table_name === '') {
            return false;
        }

        $like = $wpdb->esc_like($table_name);
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $like));

        return is_string($exists) && strtolower($exists) === strtolower($table_name);
    }

    private function resolve_builder_request_table($requested_table) {
        global $wpdb;

        $requested_table = sanitize_text_field((string) $requested_table);
        if ($requested_table === '') {
            return '';
        }

        $suffixes = ['lcni_ohlc', 'lcni_symbol_tong_quan', 'lcni_icb2', 'lcni_marketid'];

        foreach ($suffixes as $suffix) {
            $resolved = $this->resolve_table_name($suffix);
            $accepted_names = [
                $resolved,
                $wpdb->prefix . $suffix,
                'wp_' . $suffix,
                $suffix,
            ];

            if (!in_array($requested_table, array_unique($accepted_names), true)) {
                continue;
            }

            return $this->table_exists($resolved) ? $resolved : '';
        }

        return '';
    }

    private function resolve_table_name($suffix) {
        global $wpdb;

        $suffix = sanitize_key((string) $suffix);
        $candidates = [
            $wpdb->prefix . $suffix,
            'wp_' . $suffix,
        ];

        foreach (array_unique($candidates) as $table_name) {
            if ($this->table_exists($table_name)) {
                return $table_name;
            }
        }

        return $wpdb->prefix . $suffix;
    }

    private function render_signals_tab() {
        $table = new LCNI_Recommend_Signals_List_Table($this->signal_repository->list_signals(['limit' => 200]));
        $table->prepare_items();
        $table->display();
    }

    private function render_performance_tab() {
        $table = new LCNI_Recommend_Performance_List_Table($this->performance_calculator->list_performance());
        $table->prepare_items();
        $table->display();
    }
}
