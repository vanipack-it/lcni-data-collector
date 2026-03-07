<?php

if (!defined('ABSPATH')) {
    exit;
}

class SignalRepository {
    private $wpdb;
    private $table;
    private $symbols_table;
    private $market_table;
    private $icb2_table;
    private $has_timeframe_column;
    private $recommend_column_catalog;

    public function __construct(wpdb $wpdb) {
        $this->wpdb = $wpdb;
        $this->table = $wpdb->prefix . 'lcni_recommend_signal';
        $this->symbols_table = $wpdb->prefix . 'lcni_symbols';
        $this->market_table = $wpdb->prefix . 'lcni_marketid';
        $this->icb2_table = $wpdb->prefix . 'lcni_icb2';
        $this->has_timeframe_column = null;
        $this->recommend_column_catalog = null;
    }

    public function get_open_signals() {
        return $this->wpdb->get_results("SELECT * FROM {$this->table} WHERE status = 'open' ORDER BY entry_time ASC", ARRAY_A);
    }

    public function find_open_by_rule_symbol($rule_id, $symbol) {
        return $this->wpdb->get_row(
            $this->wpdb->prepare("SELECT * FROM {$this->table} WHERE rule_id = %d AND symbol = %s AND status = 'open' LIMIT 1", (int) $rule_id, strtoupper($symbol)),
            ARRAY_A
        );
    }

    public function find_by_rule_symbol_entry($rule_id, $symbol, $entry_time, $timeframe) {
        if (!$this->supports_timeframe_column()) {
            return $this->wpdb->get_row(
                $this->wpdb->prepare(
                    "SELECT id FROM {$this->table} WHERE rule_id = %d AND symbol = %s AND entry_time = %d LIMIT 1",
                    (int) $rule_id,
                    strtoupper((string) $symbol),
                    (int) $entry_time
                ),
                ARRAY_A
            );
        }

        return $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT id FROM {$this->table} WHERE rule_id = %d AND symbol = %s AND entry_time = %d AND timeframe = %s LIMIT 1",
                (int) $rule_id,
                strtoupper((string) $symbol),
                (int) $entry_time,
                strtoupper((string) $timeframe)
            ),
            ARRAY_A
        );
    }

    public function create_signal($rule, $symbol, $entry_time, $entry_price) {
        $symbol = strtoupper(trim((string) $symbol));
        $timeframe = strtoupper(sanitize_text_field((string) ($rule['timeframe'] ?? '1D')));

        if (!$this->is_valid_symbol($symbol) || $entry_price <= 0) {
            return 0;
        }

        if ($this->find_by_rule_symbol_entry((int) $rule['id'], $symbol, (int) $entry_time, $timeframe)) {
            return 0;
        }

        $initial_sl = (float) $entry_price * (1 - ((float) $rule['initial_sl_pct'] / 100));
        $risk = max(0.0001, (float) $entry_price - $initial_sl);

        $data = [
            'rule_id' => (int) $rule['id'],
            'symbol' => $symbol,
            'entry_time' => (int) $entry_time,
            'entry_price' => (float) $entry_price,
            'initial_sl' => (float) $initial_sl,
            'risk_per_share' => (float) $risk,
            'current_price' => (float) $entry_price,
            'r_multiple' => 0,
            'position_state' => 'EARLY',
            'status' => 'open',
            'holding_days' => 0,
        ];

        if ($this->supports_timeframe_column()) {
            $data['timeframe'] = $timeframe;
        }

        $this->wpdb->insert($this->table, $data);

        return (int) $this->wpdb->insert_id;
    }

    public function prune_invalid_signals() {
        $sql = "DELETE s FROM {$this->table} s
            LEFT JOIN {$this->symbols_table} sym ON sym.symbol = s.symbol
            WHERE sym.symbol IS NULL OR s.symbol = ''";

        return (int) $this->wpdb->query($sql);
    }

    public function update_open_signal_metrics($signal_id, $current_price, $r_multiple, $position_state, $holding_days) {
        return $this->wpdb->update(
            $this->table,
            [
                'current_price' => (float) $current_price,
                'r_multiple' => (float) $r_multiple,
                'position_state' => sanitize_text_field((string) $position_state),
                'holding_days' => (int) $holding_days,
            ],
            ['id' => (int) $signal_id],
            ['%f', '%f', '%s', '%d'],
            ['%d']
        );
    }

    public function close_signal($signal_id, $exit_price, $exit_time, $final_r, $holding_days) {
        return $this->wpdb->update(
            $this->table,
            [
                'status' => 'closed',
                'exit_price' => (float) $exit_price,
                'exit_time' => (int) $exit_time,
                'final_r' => (float) $final_r,
                'holding_days' => (int) $holding_days,
            ],
            ['id' => (int) $signal_id],
            ['%s', '%f', '%d', '%f', '%d'],
            ['%d']
        );
    }

    public function list_signals($filters = []) {
        $where = ['1=1'];
        $params = [];

        if (!empty($filters['rule_id'])) {
            $where[] = 's.rule_id = %d';
            $params[] = (int) $filters['rule_id'];
        }

        if (!empty($filters['status'])) {
            $where[] = 's.status = %s';
            $params[] = sanitize_text_field((string) $filters['status']);
        }

        if (!empty($filters['symbol'])) {
            $where[] = 's.symbol = %s';
            $params[] = strtoupper(sanitize_text_field((string) $filters['symbol']));
        }

        $catalog = $this->get_recommend_column_catalog();
        $selected_columns = isset($filters['selected_columns']) && is_array($filters['selected_columns'])
            ? array_values(array_intersect(array_keys($catalog), array_map('sanitize_key', $filters['selected_columns'])))
            : [];

        if (empty($selected_columns)) {
            $selected_columns = array_values(array_filter(['signal__symbol', 'rule__name', 'signal__entry_price', 'signal__current_price', 'signal__r_multiple', 'signal__position_state', 'signal__status'], static function ($column) use ($catalog) {
                return isset($catalog[$column]);
            }));
        }

        $selects = ['s.id AS signal__id'];
        foreach ($selected_columns as $key) {
            if (!isset($catalog[$key])) {
                continue;
            }
            $meta = $catalog[$key];
            $source = $meta['source'];
            $column = $meta['column'];
            if (!in_array($source, ['signal', 'rule', 'ohlc', 'market', 'icb2', 'calc'], true) || sanitize_key($column) !== $column) {
                continue;
            }
            if ($source === 'signal') {
                $selects[] = "s.{$column} AS {$key}";
            } elseif ($source === 'rule') {
                $selects[] = "r.{$column} AS {$key}";
            } elseif ($source === 'ohlc') {
                $selects[] = "o.{$column} AS {$key}";
            } elseif ($source === 'market') {
                $selects[] = "m.{$column} AS {$key}";
            } elseif ($source === 'icb2') {
                $selects[] = "i.{$column} AS {$key}";
            } elseif ($column === 'npl_current') {
                $selects[] = 'CASE WHEN s.entry_price IS NOT NULL AND s.entry_price > 0 THEN ((COALESCE(s.current_price, 0) - s.entry_price) / s.entry_price) * 100 ELSE NULL END AS signal__npl_current';
            } elseif ($column === 'npl_closed') {
                $selects[] = "CASE WHEN s.status = 'closed' AND s.entry_price IS NOT NULL AND s.entry_price > 0 AND s.exit_price IS NOT NULL THEN ((s.exit_price - s.entry_price) / s.entry_price) * 100 ELSE NULL END AS signal__npl_closed";
            }
        }

        $limit = max(1, min(300, (int) ($filters['limit'] ?? 20)));
        $sql = 'SELECT ' . implode(', ', array_unique($selects)) . "
            FROM {$this->table} s
            LEFT JOIN {$this->wpdb->prefix}lcni_recommend_rule r ON r.id = s.rule_id
            LEFT JOIN {$this->wpdb->prefix}lcni_ohlc_latest o ON o.symbol = s.symbol
            LEFT JOIN {$this->symbols_table} sym ON sym.symbol = s.symbol
            LEFT JOIN {$this->market_table} m ON m.market_id = sym.market_id
            LEFT JOIN {$this->icb2_table} i ON i.id_icb2 = sym.id_icb2
            WHERE " . implode(' AND ', $where) . "
            ORDER BY s.entry_time DESC
            LIMIT %d";

        $params[] = $limit;

        return $this->wpdb->get_results($this->wpdb->prepare($sql, $params), ARRAY_A);
    }

    public function get_recommend_column_catalog() {
        if (is_array($this->recommend_column_catalog)) {
            return $this->recommend_column_catalog;
        }

        $sources = [
            'signal' => $this->table,
            'rule' => $this->wpdb->prefix . 'lcni_recommend_rule',
            'ohlc' => $this->wpdb->prefix . 'lcni_ohlc_latest',
            'market' => $this->market_table,
            'icb2' => $this->icb2_table,
        ];

        $catalog = [];
        foreach ($sources as $source => $table) {
            $exists = $this->wpdb->get_var($this->wpdb->prepare('SHOW TABLES LIKE %s', $table));
            if ($exists !== $table) {
                continue;
            }

            $columns = $this->wpdb->get_col("SHOW COLUMNS FROM {$table}");
            if (!is_array($columns)) {
                continue;
            }

            foreach ($columns as $column) {
                $field = sanitize_key((string) $column);
                if ($field === '') {
                    continue;
                }

                $key = $source . '__' . $field;
                $catalog[$key] = [
                    'source' => $source,
                    'column' => $field,
                ];
            }
        }

        $catalog['signal__npl_current'] = [
            'source' => 'calc',
            'column' => 'npl_current',
        ];
        $catalog['signal__npl_closed'] = [
            'source' => 'calc',
            'column' => 'npl_closed',
        ];

        $this->recommend_column_catalog = $catalog;

        return $catalog;
    }

    public function find_open_signal_by_symbol($symbol) {
        return $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT s.*, r.name AS rule_name, r.add_at_r, r.exit_at_r FROM {$this->table} s
                LEFT JOIN {$this->wpdb->prefix}lcni_recommend_rule r ON r.id = s.rule_id
                WHERE s.symbol = %s AND s.status = 'open'
                ORDER BY s.entry_time DESC LIMIT 1",
                strtoupper(sanitize_text_field((string) $symbol))
            ),
            ARRAY_A
        );
    }

    private function is_valid_symbol($symbol) {
        if ($symbol === '' || preg_match('/^[A-Z0-9._-]{1,20}$/', $symbol) !== 1) {
            return false;
        }

        $exists = $this->wpdb->get_var(
            $this->wpdb->prepare("SELECT symbol FROM {$this->symbols_table} WHERE symbol = %s LIMIT 1", $symbol)
        );

        return strtoupper((string) $exists) === $symbol;
    }

    private function supports_timeframe_column() {
        if ($this->has_timeframe_column === null) {
            $column = $this->wpdb->get_var("SHOW COLUMNS FROM {$this->table} LIKE 'timeframe'");
            $this->has_timeframe_column = !empty($column);
        }

        return $this->has_timeframe_column;
    }
}
