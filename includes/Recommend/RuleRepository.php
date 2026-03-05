<?php

if (!defined('ABSPATH')) {
    exit;
}

class RuleRepository {
    private $wpdb;
    private $table;
    private $ohlc_table;

    public function __construct(wpdb $wpdb) {
        $this->wpdb = $wpdb;
        $this->table = $this->resolve_table_name('lcni_recommend_rule');
        $this->ohlc_table = $this->resolve_table_name('lcni_ohlc');
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
            'description' => sanitize_textarea_field((string) ($data['description'] ?? '')),
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
        $ohlc_table = $this->ohlc_table;
        $timeframe = sanitize_text_field((string) ($rule['timeframe'] ?? '1D'));

        $where = ['o.timeframe = %s'];
        $params = [$timeframe];

        if (isset($conditions['conditions']) && is_array($conditions['conditions'])) {
            foreach ($conditions['conditions'] as $condition) {
                if (!is_array($condition)) {
                    continue;
                }

                $this->append_builder_condition($where, $params, $condition);
            }
        }

        foreach ($conditions as $field => $value) {
            if ($field === 'conditions' || $field === 'logic') {
                continue;
            }

            $field = sanitize_key((string) $field);
            if ($field === '') {
                continue;
            }

            $this->append_legacy_condition($where, $params, $field, $value);
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

    private function append_builder_condition(&$where, &$params, $condition) {
        $field = sanitize_key((string) ($condition['field'] ?? ''));
        $operator = sanitize_text_field((string) ($condition['operator'] ?? '='));
        $value = trim((string) ($condition['value'] ?? ''));

        if ($field === '' || $value === '') {
            return;
        }

        $is_numeric = is_numeric($value);

        if (in_array($operator, ['>', '>=', '<', '<='], true)) {
            if (!$is_numeric) {
                return;
            }
            $where[] = "o.`{$field}` {$operator} %f";
            $params[] = (float) $value;
            return;
        }

        if ($operator === '!=') {
            $where[] = $is_numeric ? "o.`{$field}` != %f" : "o.`{$field}` != %s";
            $params[] = $is_numeric ? (float) $value : sanitize_text_field($value);
            return;
        }

        if ($operator === 'contains') {
            $where[] = "o.`{$field}` LIKE %s";
            $params[] = '%' . $this->wpdb->esc_like($value) . '%';
            return;
        }

        if ($operator === 'not_contains') {
            $where[] = "o.`{$field}` NOT LIKE %s";
            $params[] = '%' . $this->wpdb->esc_like($value) . '%';
            return;
        }

        $where[] = $is_numeric ? "o.`{$field}` = %f" : "o.`{$field}` = %s";
        $params[] = $is_numeric ? (float) $value : sanitize_text_field($value);
    }

    private function append_legacy_condition(&$where, &$params, $field, $value) {
        if (is_array($value)) {
            $values = array_values(array_filter(array_map(static function ($item) {
                return sanitize_text_field((string) $item);
            }, $value), static function ($item) {
                return $item !== '';
            }));

            if (empty($values)) {
                return;
            }

            $placeholders = implode(', ', array_fill(0, count($values), '%s'));
            $where[] = "o.`{$field}` IN ({$placeholders})";
            foreach ($values as $item) {
                $params[] = $item;
            }
            return;
        }

        if (substr($field, -4) === '_min') {
            $column = sanitize_key(substr($field, 0, -4));
            if ($column === '') {
                return;
            }
            $where[] = "o.`{$column}` >= %f";
            $params[] = (float) $value;
            return;
        }

        if (substr($field, -4) === '_max') {
            $column = sanitize_key(substr($field, 0, -4));
            if ($column === '') {
                return;
            }
            $where[] = "o.`{$column}` <= %f";
            $params[] = (float) $value;
            return;
        }

        if (is_numeric($value)) {
            $where[] = "o.`{$field}` = %f";
            $params[] = (float) $value;
        } else {
            $where[] = "o.`{$field}` = %s";
            $params[] = sanitize_text_field((string) $value);
        }
    }

    private function normalize_conditions($raw) {
        if (is_string($raw)) {
            $decoded = json_decode(wp_unslash($raw), true);
            $raw = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($raw)) {
            return [];
        }

        if (isset($raw['conditions']) && is_array($raw['conditions'])) {
            $normalized_conditions = [];

            foreach ($raw['conditions'] as $condition) {
                if (!is_array($condition)) {
                    continue;
                }

                $table = sanitize_text_field((string) ($condition['table'] ?? ''));
                $field = sanitize_key((string) ($condition['field'] ?? ''));
                $operator = sanitize_text_field((string) ($condition['operator'] ?? '='));
                $value = sanitize_text_field((string) ($condition['value'] ?? ''));

                if ($table === '' || $field === '' || $value === '') {
                    continue;
                }

                if (!in_array($operator, ['=', '!=', '>', '>=', '<', '<=', 'contains', 'not_contains'], true)) {
                    $operator = '=';
                }

                $normalized_conditions[] = [
                    'table' => $table,
                    'field' => $field,
                    'operator' => $operator,
                    'value' => $value,
                ];
            }

            return [
                'logic' => 'AND',
                'conditions' => $normalized_conditions,
            ];
        }

        $normalized = [];
        foreach ($raw as $field => $value) {
            $key = sanitize_key((string) $field);
            if ($key === '') {
                continue;
            }

            if (is_array($value)) {
                $values = array_values(array_filter(array_map(static function ($item) {
                    return sanitize_text_field((string) $item);
                }, $value), static function ($item) {
                    return $item !== '';
                }));

                if (!empty($values)) {
                    $normalized[$key] = $values;
                }
                continue;
            }

            $normalized[$key] = is_numeric($value) ? (float) $value : sanitize_text_field((string) $value);
        }

        return $normalized;
    }

    private function resolve_table_name($suffix) {
        $suffix = sanitize_key((string) $suffix);
        $candidates = [
            $this->wpdb->prefix . $suffix,
            'wp_' . $suffix,
        ];

        foreach (array_unique($candidates) as $table) {
            $exists = $this->wpdb->get_var($this->wpdb->prepare('SHOW TABLES LIKE %s', $table));
            if ($exists === $table) {
                return $table;
            }
        }

        return $this->wpdb->prefix . $suffix;
    }
}
