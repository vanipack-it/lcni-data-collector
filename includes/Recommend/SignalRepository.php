<?php

if (!defined('ABSPATH')) {
    exit;
}

class SignalRepository {
    private $wpdb;
    private $table;

    public function __construct(wpdb $wpdb) {
        $this->wpdb = $wpdb;
        $this->table = $wpdb->prefix . 'lcni_recommend_signal';
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

    public function create_signal($rule, $symbol, $entry_time, $entry_price) {
        $symbol = strtoupper(trim((string) $symbol));
        if ($symbol === '' || $entry_price <= 0) {
            return 0;
        }

        if ($this->find_open_by_rule_symbol((int) $rule['id'], $symbol)) {
            return 0;
        }

        $initial_sl = (float) $entry_price * (1 - ((float) $rule['initial_sl_pct'] / 100));
        $risk = max(0.0001, (float) $entry_price - $initial_sl);

        $this->wpdb->insert($this->table, [
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
        ]);

        return (int) $this->wpdb->insert_id;
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

        $limit = max(1, min(300, (int) ($filters['limit'] ?? 20)));
        $sql = "SELECT s.*, r.name AS rule_name, r.add_at_r, r.exit_at_r
            FROM {$this->table} s
            LEFT JOIN {$this->wpdb->prefix}lcni_recommend_rule r ON r.id = s.rule_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY s.entry_time DESC
            LIMIT %d";

        $params[] = $limit;

        return $this->wpdb->get_results($this->wpdb->prepare($sql, $params), ARRAY_A);
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
}
