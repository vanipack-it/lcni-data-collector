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
        add_action('wp_ajax_lcni_recommend_distinct_values', [$this, 'ajax_distinct_values']);
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
                wp_safe_redirect(admin_url('admin.php?page=lcni-recommend&tab=create-rule&created=0'));
                exit;
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
                'risk_reward' => (float) ($_POST['risk_reward'] ?? 3),
                'add_at_r' => (float) ($_POST['add_at_r'] ?? 2),
                'exit_at_r' => (float) ($_POST['exit_at_r'] ?? 4),
                'max_hold_days' => (int) ($_POST['max_hold_days'] ?? 20),
                'is_active' => !empty($_POST['is_active']) ? 1 : 0,
            ]);

            if (is_wp_error($updated) || $updated === false) {
                wp_safe_redirect(admin_url('admin.php?page=lcni-recommend&tab=create-rule&edit=' . $rule_id . '&updated=0'));
                exit;
            }

            wp_safe_redirect(admin_url('admin.php?page=lcni-recommend&tab=rule-list&updated=1'));
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

        if ($tab === 'create-rule') {
            $this->render_rules_tab();
        } elseif ($tab === 'rule-list') {
            $this->render_rules_list_tab();
        } elseif ($tab === 'signals') {
            $this->render_signals_tab();
        } else {
            $this->render_performance_tab();
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
        echo '<div class="lcni-recommend-row"><label>Risk Reward <input type="number" step="0.01" name="risk_reward" value="' . esc_attr((string) ($editing_rule['risk_reward'] ?? '3')) . '" /></label></div>';
        echo '<div class="lcni-recommend-row"><label>Add at R <input type="number" step="0.01" name="add_at_r" value="' . esc_attr((string) ($editing_rule['add_at_r'] ?? '2')) . '" /></label></div>';
        echo '<div class="lcni-recommend-row"><label>Exit at R <input type="number" step="0.01" name="exit_at_r" value="' . esc_attr((string) ($editing_rule['exit_at_r'] ?? '4')) . '" /></label></div>';
        echo '<div class="lcni-recommend-row"><label>Max Hold Days <input type="number" name="max_hold_days" value="' . esc_attr((string) ($editing_rule['max_hold_days'] ?? '20')) . '" /></label></div>';
        echo '<p><label><input type="checkbox" name="is_active" value="1" ' . (!array_key_exists('is_active', (array) $editing_rule) || !empty($editing_rule['is_active']) ? 'checked' : '') . ' /> Active</label></p>';
        echo '</div>';

        echo '<div class="lcni-recommend-panel">';
        echo '<h3>Điều kiện kích hoạt</h3>';
        echo '<p class="lcni-recommend-rules-help">Tạo nhiều rule với 3 cột: <strong>Field</strong>, <strong>Điều kiện</strong>, <strong>Giá trị so sánh</strong>. Các rule sẽ kết hợp với nhau để tạo Entry Conditions (AND).</p>';
        echo '<table class="lcni-recommend-rules" id="lcni-recommend-rules-table">';
        echo '<thead><tr><th>Cột 1: Field</th><th>Cột 2: Điều kiện</th><th>Cột 3: Giá trị so sánh</th><th></th></tr></thead>';
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
                jsonField.value=JSON.stringify({ rules:validRows }, null, 2);
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
                    const fieldSelect=buildSelect(fieldOptions, rule.field);
                    fieldSelect.addEventListener("change",()=>{ rule.field=fieldSelect.value; syncJson(); });
                    fieldCell.appendChild(fieldSelect);

                    const operatorCell=document.createElement("td");
                    const operatorSelect=buildSelect(operators.map((op)=>({ value:op, label:op })), rule.operator || "=");
                    operatorSelect.addEventListener("change",()=>{ rule.operator=operatorSelect.value || "="; syncJson(); });
                    operatorCell.appendChild(operatorSelect);

                    const valueCell=document.createElement("td");
                    const valueInput=document.createElement("input");
                    valueInput.type="text";
                    valueInput.value=rule.value || "";
                    valueInput.placeholder="Nhập giá trị so sánh";
                    valueInput.addEventListener("input",()=>{ rule.value=valueInput.value.trim(); syncJson(); });
                    valueCell.appendChild(valueInput);

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
                    tr.appendChild(actionCell);
                    tableBody.appendChild(tr);
                });

                syncJson();
            }

            function addRule(initialRule){
                rows.push({
                    field:(initialRule && initialRule.field) || "",
                    operator:(initialRule && initialRule.operator) || "=",
                    value:(initialRule && initialRule.value) || ""
                });
                renderRows();
            }

            addRuleButton.addEventListener("click",()=>addRule());

            try {
                const parsed=JSON.parse(jsonField.value || "{}");
                if (parsed && Array.isArray(parsed.rules) && parsed.rules.length) {
                    parsed.rules.forEach((rule)=>{
                        addRule({
                            field:String(rule.field || ""),
                            operator:String(rule.operator || "="),
                            value:String(rule.value || "")
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
        echo '<th>Active</th>';
        echo '<th>Created At</th>';
        echo '<th>Updated At</th>';
        echo '<th>Action</th>';
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
            echo '<td>' . (!empty($rule['is_active']) ? '1' : '0') . '</td>';
            echo '<td>' . esc_html((string) ($rule['created_at'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($rule['updated_at'] ?? '')) . '</td>';
            echo '<td><a class="button button-small" href="' . esc_url(admin_url('admin.php?page=lcni-recommend&tab=create-rule&edit=' . (int) ($rule['id'] ?? 0))) . '">Chỉnh sửa</a></td>';
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
