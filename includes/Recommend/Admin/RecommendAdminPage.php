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
            $this->rule_repository->save([
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
        global $wpdb;

        return [
            $wpdb->prefix . 'lcni_ohlc' => 'wp_lcni_ohlc',
            $wpdb->prefix . 'lcni_symbol_tong_quan' => 'wp_lcni_symbol_tong_quan',
            $wpdb->prefix . 'lcni_icb2' => 'wp_lcni_icb2',
            $wpdb->prefix . 'lcni_marketid' => 'wp_lcni_marketid',
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
        $table_sources = $this->get_builder_table_sources();
        $columns_map = [];

        foreach ($table_sources as $real_table => $display_table) {
            $columns_map[$display_table] = $this->get_table_columns_for_builder($real_table);
        }

        echo '<style>
            .lcni-recommend-builder-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-top:12px}
            .lcni-recommend-panel{background:#fff;border:1px solid #dcdcde;padding:12px;min-height:380px}
            .lcni-recommend-panel h3{margin-top:0}
            .lcni-recommend-row{display:flex;gap:10px;align-items:center;margin-bottom:8px;flex-wrap:wrap}
            .lcni-recommend-drop{border:1px dashed #8c8f94;background:#f6f7f7;min-height:120px;padding:8px}
            .lcni-recommend-columns{max-height:220px;overflow:auto;border:1px solid #dcdcde;padding:6px;background:#fff}
            .lcni-recommend-pill{display:inline-block;padding:4px 8px;border:1px solid #c3c4c7;border-radius:12px;background:#f0f0f1;cursor:grab;margin:0 6px 6px 0}
            .lcni-recommend-condition-item{border:1px solid #dcdcde;padding:8px;background:#fff;margin-bottom:8px}
            .lcni-recommend-condition-item strong{display:block;margin-bottom:6px}
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
        echo '<h3>Entry Conditions JSON</h3>';
        echo '<div id="lcni-recommend-dropzone" class="lcni-recommend-drop"><em>Kéo thả cột từ bên phải vào đây.</em></div>';
        echo '<p><textarea id="lcni_recommend_entry_conditions" name="entry_conditions" rows="8" class="large-text code">{"is_break_h1m":1,"rs_rank_min":80,"smart_money":1}</textarea></p>';
        echo '</div>';

        echo '<div class="lcni-recommend-panel">';
        echo '<h3>Table source (1)</h3>';
        echo '<p><select id="lcni-recommend-source-table" class="regular-text">';
        foreach ($table_sources as $display_table) {
            echo '<option value="' . esc_attr($display_table) . '">' . esc_html($display_table) . '</option>';
        }
        echo '</select></p>';
        echo '<h3>Column of table (2)</h3>';
        echo '<div id="lcni-recommend-columns" class="lcni-recommend-columns"></div>';
        echo '</div>';

        echo '</div>';

        submit_button('Save Rule');
        echo '</form>';

        echo '<script>';
        echo 'window.lcniRecommendColumnsMap=' . wp_json_encode($columns_map) . ';';
        echo '(function(){
            const columnsMap=window.lcniRecommendColumnsMap||{};
            const source=document.getElementById("lcni-recommend-source-table");
            const columnsHost=document.getElementById("lcni-recommend-columns");
            const dropzone=document.getElementById("lcni-recommend-dropzone");
            const jsonField=document.getElementById("lcni_recommend_entry_conditions");
            const selected=[];

            function renderColumns(){
                const table=source.value;
                const cols=columnsMap[table]||[];
                columnsHost.innerHTML="";
                cols.forEach((col)=>{
                    const item=document.createElement("span");
                    item.className="lcni-recommend-pill";
                    item.draggable=true;
                    item.textContent=col.field + (col.is_numeric ? " (number)" : " (text)");
                    item.dataset.payload=JSON.stringify(col);
                    item.addEventListener("dragstart",(e)=>{ e.dataTransfer.setData("text/plain", item.dataset.payload || ""); });
                    columnsHost.appendChild(item);
                });
            }

            function syncJson(){
                const payload={};
                selected.forEach((cond)=>{
                    if(cond.is_numeric){
                        if(cond.min!=="") payload[cond.field+"_min"]=Number(cond.min);
                        if(cond.max!=="") payload[cond.field+"_max"]=Number(cond.max);
                    } else if (cond.enabled && cond.value!=="") {
                        payload[cond.field]=cond.value;
                    }
                });
                jsonField.value=JSON.stringify(payload, null, 2);
            }

            function renderSelected(){
                dropzone.innerHTML="";
                if(!selected.length){
                    dropzone.innerHTML="<em>Kéo thả cột từ bên phải vào đây.</em>";
                    syncJson();
                    return;
                }

                selected.forEach((cond,idx)=>{
                    const row=document.createElement("div");
                    row.className="lcni-recommend-condition-item";
                    const title=document.createElement("strong");
                    title.textContent=cond.field;
                    row.appendChild(title);

                    if(cond.is_numeric){
                        const min=document.createElement("input");
                        min.type="number";
                        min.step="any";
                        min.placeholder="Min";
                        min.value=cond.min;
                        min.addEventListener("input",()=>{ cond.min=min.value; syncJson(); });

                        const max=document.createElement("input");
                        max.type="number";
                        max.step="any";
                        max.placeholder="Max";
                        max.value=cond.max;
                        max.style.marginLeft="8px";
                        max.addEventListener("input",()=>{ cond.max=max.value; syncJson(); });

                        row.appendChild(min);
                        row.appendChild(max);
                    } else {
                        const enabled=document.createElement("input");
                        enabled.type="checkbox";
                        enabled.checked=!!cond.enabled;
                        enabled.addEventListener("change",()=>{ cond.enabled=enabled.checked; syncJson(); });

                        const label=document.createElement("label");
                        label.style.marginLeft="6px";
                        label.textContent="Check box if value = text";

                        const value=document.createElement("input");
                        value.type="text";
                        value.placeholder="Value";
                        value.value=cond.value;
                        value.style.display="block";
                        value.style.marginTop="6px";
                        value.addEventListener("input",()=>{ cond.value=value.value; syncJson(); });

                        row.appendChild(enabled);
                        row.appendChild(label);
                        row.appendChild(value);
                    }

                    const remove=document.createElement("button");
                    remove.type="button";
                    remove.className="button-link-delete";
                    remove.style.marginLeft="8px";
                    remove.textContent="Xóa";
                    remove.addEventListener("click",()=>{ selected.splice(idx,1); renderSelected(); });
                    row.appendChild(remove);

                    dropzone.appendChild(row);
                });

                syncJson();
            }

            dropzone.addEventListener("dragover",(e)=>e.preventDefault());
            dropzone.addEventListener("drop",(e)=>{
                e.preventDefault();
                const raw=e.dataTransfer.getData("text/plain");
                if(!raw){ return; }
                try {
                    const col=JSON.parse(raw);
                    if(selected.some((item)=>item.field===col.field)){ return; }
                    selected.push({ field:col.field, is_numeric:!!col.is_numeric, min:"", max:"", enabled:true, value:"" });
                    renderSelected();
                } catch(err){}
            });

            source.addEventListener("change",renderColumns);
            renderColumns();
            renderSelected();
        })();';
        echo '</script>';

        $table = new LCNI_Recommend_Rules_List_Table($this->rule_repository->all(200));
        $table->prepare_items();
        $table->display();
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
