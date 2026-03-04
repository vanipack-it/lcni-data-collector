<?php

if (!defined('ABSPATH')) {
    exit;
}

class RuleRepository {
    private $wpdb;
    private $table;

    public function __construct(wpdb $wpdb) {
        $this->wpdb = $wpdb;
        $this->table = $wpdb->prefix . 'lcni_recommend_rule';
    }

    public function get_active_rules() {
        return $this->wpdb->get_results("SELECT * FROM {$this->table} WHERE is_active = 1 ORDER BY id ASC", ARRAY_A);
    }

    public function find($id) {
        return $this->wpdb->get_row($this->wpdb->prepare("SELECT * FROM {$this->table} WHERE id = %d", (int) $id), ARRAY_A);
    }

    public function all($limit = 100) {
        $limit = max(1, min(500, (int) $limit));

        return $this->wpdb->get_results($this->wpdb->prepare("SELECT * FROM {$this->table} ORDER BY id DESC LIMIT %d", $limit), ARRAY_A);
    }

    public function save($data) {
        $payload = [
            'name' => sanitize_text_field((string) ($data['name'] ?? '')),
            'timeframe' => strtoupper(sanitize_text_field((string) ($data['timeframe'] ?? '1D'))),
            'entry_conditions' => wp_json_encode($this->normalize_conditions($data['entry_conditions'] ?? [])),
            'initial_sl_pct' => (float) ($data['initial_sl_pct'] ?? 8),
            'risk_reward' => (float) ($data['risk_reward'] ?? 3),
            'add_at_r' => (float) ($data['add_at_r'] ?? 2),
            'exit_at_r' => (float) ($data['exit_at_r'] ?? 4),
            'max_hold_days' => max(1, (int) ($data['max_hold_days'] ?? 20)),
            'is_active' => !empty($data['is_active']) ? 1 : 0,
        ];

        if ($payload['name'] === '') {
            return new WP_Error('invalid_rule_name', 'Rule name is required.');
        }

        $this->wpdb->insert($this->table, $payload);

        return (int) $this->wpdb->insert_id;
    }

    public function update_active($id, $is_active) {
        return $this->wpdb->update(
            $this->table,
            ['is_active' => $is_active ? 1 : 0],
            ['id' => (int) $id],
            ['%d'],
            ['%d']
        );
    }

    public function decode_conditions($rule) {
        $decoded = json_decode((string) ($rule['entry_conditions'] ?? '{}'), true);

        return is_array($decoded) ? $decoded : [];
    }

    public function find_candidate_symbols($rule) {
        $conditions = $this->decode_conditions($rule);
        $ohlc_table = $this->wpdb->prefix . 'lcni_ohlc';
        $timeframe = sanitize_text_field((string) ($rule['timeframe'] ?? '1D'));

        $where = ['o.timeframe = %s'];
        $params = [$timeframe];

        foreach ($conditions as $field => $value) {
            $field = sanitize_key((string) $field);
            if ($field === '') {
                continue;
            }

            if (substr($field, -4) === '_min') {
                $column = sanitize_key(substr($field, 0, -4));
                if ($column === '') {
                    continue;
                }
                $where[] = "o.`{$column}` >= %f";
                $params[] = (float) $value;
                continue;
            }

            if (substr($field, -4) === '_max') {
                $column = sanitize_key(substr($field, 0, -4));
                if ($column === '') {
                    continue;
                }
                $where[] = "o.`{$column}` <= %f";
                $params[] = (float) $value;
                continue;
            }

            if (is_numeric($value)) {
                $where[] = "o.`{$field}` = %f";
                $params[] = (float) $value;
            } else {
                $where[] = "o.`{$field}` = %s";
                $params[] = sanitize_text_field((string) $value);
            }
        }

        $sql = "SELECT o.symbol, o.event_time, o.close_price
            FROM {$ohlc_table} o
            INNER JOIN (
                SELECT symbol, timeframe, MAX(event_time) AS max_event_time
                FROM {$ohlc_table}
                WHERE timeframe = %s
                GROUP BY symbol, timeframe
            ) latest ON latest.symbol = o.symbol AND latest.timeframe = o.timeframe AND latest.max_event_time = o.event_time
            WHERE " . implode(' AND ', $where) . "
            ORDER BY o.symbol ASC
            LIMIT 300";

        array_unshift($params, $timeframe);

        return $this->wpdb->get_results($this->wpdb->prepare($sql, $params), ARRAY_A);
    }

    private function normalize_conditions($raw) {
        if (is_string($raw)) {
            $decoded = json_decode(wp_unslash($raw), true);
            $raw = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($raw)) {
            return [];
        }

        $normalized = [];
        foreach ($raw as $field => $value) {
            $key = sanitize_key((string) $field);
            if ($key === '') {
                continue;
            }
            $normalized[$key] = is_numeric($value) ? (float) $value : sanitize_text_field((string) $value);
        }

        return $normalized;
    }
}
