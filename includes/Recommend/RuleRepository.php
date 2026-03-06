<?php

if (!defined('ABSPATH')) {
    exit;
}

class RuleRepository {
    private $wpdb;
    private $table;
    private $log_table;

    public function __construct(wpdb $wpdb) {
        $this->wpdb = $wpdb;
        $this->table = $wpdb->prefix . 'lcni_recommend_rule';
        $this->log_table = $wpdb->prefix . 'lcni_recommend_rule_log';
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

    public function list_logs($limit = 200) {
        $limit = max(1, min(500, (int) $limit));

        $sql = "SELECT l.*, r.name AS rule_name
            FROM {$this->log_table} l
            LEFT JOIN {$this->table} r ON r.id = l.rule_id
            ORDER BY l.id DESC
            LIMIT %d";

        return $this->wpdb->get_results($this->wpdb->prepare($sql, $limit), ARRAY_A);
    }

    public function save($data) {
        $payload = $this->build_payload($data);

        if ($payload['name'] === '') {
            return new WP_Error('invalid_rule_name', 'Rule name is required.');
        }

        $this->wpdb->insert($this->table, $payload);
        $rule_id = (int) $this->wpdb->insert_id;

        if ($rule_id > 0) {
            $this->log_rule_change($rule_id, 'created', 'Tạo rule mới.', ['after' => $payload]);
        }

        return $rule_id;
    }

    public function update($id, $data) {
        $rule_id = (int) $id;
        if ($rule_id <= 0) {
            return new WP_Error('invalid_rule_id', 'Rule ID is invalid.');
        }

        $before = $this->find($rule_id);
        $payload = $this->build_payload($data);

        if ($payload['name'] === '') {
            return new WP_Error('invalid_rule_name', 'Rule name is required.');
        }

        $updated = $this->wpdb->update(
            $this->table,
            $payload,
            ['id' => $rule_id]
        );

        if ($updated === false) {
            return false;
        }

        $this->log_rule_change($rule_id, 'updated', 'Cập nhật rule.', ['before' => $before, 'after' => $payload]);

        return true;
    }

    public function update_last_scan_at($id, $timestamp) {
        return $this->wpdb->update(
            $this->table,
            ['last_scan_at' => (int) $timestamp],
            ['id' => (int) $id],
            ['%d'],
            ['%d']
        );
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

        if (is_array($decoded) && !isset($decoded['rules']) && array_is_list($decoded)) {
            $normalized_rules = [];
            foreach ($decoded as $item) {
                if (!is_array($item) || !isset($item['rules']) || !is_array($item['rules'])) {
                    continue;
                }
                foreach ($item['rules'] as $rule_item) {
                    if (is_array($rule_item)) {
                        $normalized_rules[] = $rule_item;
                    }
                }
            }

            if (!empty($normalized_rules)) {
                return ['match' => 'AND', 'rules' => $normalized_rules];
            }
        }

        return is_array($decoded) ? $decoded : [];
    }

    public function find_candidate_symbols($rule) {
        return $this->find_candidate_symbols_by_window($rule, null, null);
    }

    public function find_candidate_symbols_by_window($rule, $start_event_time = null, $end_event_time = null) {
        $conditions = $this->decode_conditions($rule);
        $ohlc_table = $this->wpdb->prefix . 'lcni_ohlc';
        $symbol_table = $this->wpdb->prefix . 'lcni_symbols';
        $icb2_table = $this->wpdb->prefix . 'lcni_icb2';
        $mapping_table = $this->wpdb->prefix . 'lcni_sym_icb_market';
        $tongquan_table = $this->wpdb->prefix . 'lcni_symbol_tongquan';
        $timeframe = sanitize_text_field((string) ($rule['timeframe'] ?? '1D'));

        $where = ['o.timeframe = %s'];
        $params = [$timeframe];

        if ($start_event_time !== null) {
            $where[] = 'o.event_time >= %d';
            $params[] = max(0, (int) $start_event_time);
        }

        if ($end_event_time !== null) {
            $where[] = 'o.event_time <= %d';
            $params[] = max(0, (int) $end_event_time);
        }

        $condition_match = strtoupper(sanitize_text_field((string) ($conditions['match'] ?? 'AND')));
        if (!in_array($condition_match, ['AND', 'OR'], true)) {
            $condition_match = 'AND';
        }

        $rule_where = [];
        $rule_params = [];
        $rule_joins = [];

        if (isset($conditions['rules']) && is_array($conditions['rules'])) {
            foreach ($conditions['rules'] as $rule_item) {
                if (!is_array($rule_item)) {
                    continue;
                }

                $raw_field = sanitize_text_field((string) ($rule_item['field'] ?? ''));
                $operator = $this->normalize_rule_operator($rule_item['operator'] ?? '=');
                $raw_value = sanitize_text_field((string) ($rule_item['value'] ?? ''));

                if ($raw_field === '' || $raw_value === '') {
                    continue;
                }

                $parts = explode('.', $raw_field, 2);
                if (count($parts) !== 2) {
                    continue;
                }

                $table_key = $this->normalize_condition_table_key((string) $parts[0]);
                $column_key = sanitize_key((string) $parts[1]);

                if ($table_key === '' || $column_key === '') {
                    continue;
                }

                $alias_map = [
                    'lcni_ohlc' => 'o',
                    'lcni_symbols' => 's',
                    'lcni_icb2' => 'i',
                    'lcni_sym_icb_market' => 'm',
                    'lcni_symbol_tongquan' => 't',
                ];

                if (!isset($alias_map[$table_key])) {
                    continue;
                }

                $column_ref = $alias_map[$table_key] . '.`' . $column_key . '`';
                $clause = '';
                $clause_param = null;

                if ($operator === '>' || $operator === '<') {
                    if (!is_numeric($raw_value)) {
                        continue;
                    }

                    $clause = $column_ref . ' ' . $operator . ' %f';
                    $clause_param = (float) $raw_value;
                } elseif ($operator === 'contains') {
                    $clause = $column_ref . ' LIKE %s';
                    $clause_param = '%' . $this->wpdb->esc_like($raw_value) . '%';
                } elseif ($operator === 'not_contains') {
                    $clause = $column_ref . ' NOT LIKE %s';
                    $clause_param = '%' . $this->wpdb->esc_like($raw_value) . '%';
                } elseif (is_numeric($raw_value)) {
                    $clause = $column_ref . ' = %f';
                    $clause_param = (float) $raw_value;
                } else {
                    $clause = $column_ref . ' = %s';
                    $clause_param = $raw_value;
                }

                if ($clause === '') {
                    continue;
                }

                $join_with_next = strtoupper(sanitize_text_field((string) ($rule_item['join_with_next'] ?? $rule_item['join'] ?? $condition_match)));
                if (!in_array($join_with_next, ['AND', 'OR'], true)) {
                    $join_with_next = 'AND';
                }

                $rule_where[] = $clause;
                $rule_params[] = $clause_param;
                $rule_joins[] = $join_with_next;
            }
        }

        if (!empty($rule_where)) {
            $combined_rule_sql = $rule_where[0];
            for ($rule_index = 1; $rule_index < count($rule_where); $rule_index++) {
                $join_operator = $rule_joins[$rule_index - 1] ?? 'AND';
                if (!in_array($join_operator, ['AND', 'OR'], true)) {
                    $join_operator = 'AND';
                }
                $combined_rule_sql .= ' ' . $join_operator . ' ' . $rule_where[$rule_index];
            }

            $where[] = '(' . $combined_rule_sql . ')';
            $params = array_merge($params, $rule_params);
        }

        foreach ($conditions as $field => $value) {
            if ($field === 'rules' || $field === 'match') {
                continue;
            }

            $field = sanitize_key((string) $field);
            if ($field === '') {
                continue;
            }

            if (is_array($value)) {
                $values = array_values(array_filter(array_map(static function ($item) {
                    return sanitize_text_field((string) $item);
                }, $value), static function ($item) {
                    return $item !== '';
                }));

                if (empty($values)) {
                    continue;
                }

                $placeholders = implode(', ', array_fill(0, count($values), '%s'));
                $where[] = "o.`{$field}` IN ({$placeholders})";
                foreach ($values as $item) {
                    $params[] = $item;
                }
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

        $is_window_scan = $start_event_time !== null || $end_event_time !== null;

        if ($is_window_scan) {
            $sql = "SELECT o.symbol, o.event_time, o.close_price
                FROM {$ohlc_table} o
                LEFT JOIN {$symbol_table} s ON s.symbol = o.symbol
                LEFT JOIN {$mapping_table} m ON m.symbol = o.symbol
                LEFT JOIN {$icb2_table} i ON i.id_icb2 = m.id_icb2
                LEFT JOIN {$tongquan_table} t ON t.symbol = o.symbol
                WHERE " . implode(' AND ', $where) . "
                ORDER BY o.event_time ASC, o.symbol ASC
                LIMIT 2000";

            return $this->wpdb->get_results($this->wpdb->prepare($sql, $params), ARRAY_A);
        }

        $sql = "SELECT o.symbol, MAX(o.event_time) AS event_time, MAX(CASE WHEN o.event_time = latest.max_event_time THEN o.close_price END) AS close_price
            FROM {$ohlc_table} o
            LEFT JOIN {$symbol_table} s ON s.symbol = o.symbol
            LEFT JOIN {$mapping_table} m ON m.symbol = o.symbol
            LEFT JOIN {$icb2_table} i ON i.id_icb2 = m.id_icb2
            LEFT JOIN {$tongquan_table} t ON t.symbol = o.symbol
            INNER JOIN (
                SELECT symbol, timeframe, MAX(event_time) AS max_event_time
                FROM {$ohlc_table}
                WHERE timeframe = %s
                GROUP BY symbol, timeframe
            ) latest ON latest.symbol = o.symbol AND latest.timeframe = o.timeframe AND latest.max_event_time = o.event_time
            WHERE " . implode(' AND ', $where) . "
            GROUP BY o.symbol
            ORDER BY o.symbol ASC
            LIMIT 300";

        $params = array_merge([$timeframe], $params);

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

        if (!isset($raw['rules']) && array_is_list($raw)) {
            $flattened_rules = [];
            foreach ($raw as $item) {
                if (!is_array($item) || !isset($item['rules']) || !is_array($item['rules'])) {
                    continue;
                }
                foreach ($item['rules'] as $rule_item) {
                    if (is_array($rule_item)) {
                        $flattened_rules[] = $rule_item;
                    }
                }
            }

            if (!empty($flattened_rules)) {
                $raw = ['rules' => $flattened_rules];
            }
        }

        if (isset($raw['rules']) && is_array($raw['rules'])) {
            $normalized_rules = [];
            foreach ($raw['rules'] as $rule) {
                if (!is_array($rule)) {
                    continue;
                }

                $field = sanitize_text_field((string) ($rule['field'] ?? ''));
                $operator = $this->normalize_rule_operator($rule['operator'] ?? '=');
                $value = sanitize_text_field((string) ($rule['value'] ?? ''));

                if ($field === '' || $value === '') {
                    continue;
                }

                $join_with_next = strtoupper(sanitize_text_field((string) ($rule['join_with_next'] ?? $rule['join'] ?? 'AND')));
                if (!in_array($join_with_next, ['AND', 'OR'], true)) {
                    $join_with_next = 'AND';
                }

                $normalized_rule = [
                    'field' => $field,
                    'operator' => $operator,
                    'value' => $value,
                ];

                if (array_key_exists('join_with_next', $rule) || array_key_exists('join', $rule)) {
                    $normalized_rule['join_with_next'] = $join_with_next;
                }

                $normalized_rules[] = $normalized_rule;
            }

            $match = strtoupper(sanitize_text_field((string) ($raw['match'] ?? 'AND')));
            if (!in_array($match, ['AND', 'OR'], true)) {
                $match = 'AND';
            }

            return ['match' => $match, 'rules' => $normalized_rules];
        }

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

    private function normalize_condition_table_key($raw_table_key) {
        $table_key = sanitize_key((string) $raw_table_key);
        if ($table_key === '') {
            return '';
        }

        if (strpos($table_key, 'lcni_') === 0) {
            return $table_key;
        }

        $lcni_pos = strpos($table_key, '_lcni_');
        if ($lcni_pos !== false) {
            return substr($table_key, $lcni_pos + 1);
        }

        return $table_key;
    }

    private function sanitize_apply_from_date($value) {
        $value = sanitize_text_field((string) $value);
        if ($value === '') {
            return null;
        }

        $dt = DateTimeImmutable::createFromFormat('Y-m-d', $value);
        if ($dt === false) {
            return null;
        }

        return $dt->format('Y-m-d');
    }

    private function sanitize_scan_time($value) {
        $value = sanitize_text_field((string) $value);
        $allowed_scan_times = ['06:00', '09:00', '12:00', '15:00', '18:00'];
        if (in_array($value, $allowed_scan_times, true)) {
            return $value;
        }

        return '18:00';
    }

    private function build_payload($data) {
        return [
            'name' => sanitize_text_field((string) ($data['name'] ?? '')),
            'timeframe' => strtoupper(sanitize_text_field((string) ($data['timeframe'] ?? '1D'))),
            'description' => sanitize_textarea_field((string) ($data['description'] ?? '')),
            'entry_conditions' => wp_json_encode($this->normalize_conditions($data['entry_conditions'] ?? [])),
            'initial_sl_pct' => (float) ($data['initial_sl_pct'] ?? 8),
            'risk_reward' => (float) ($data['risk_reward'] ?? 3),
            'add_at_r' => (float) ($data['add_at_r'] ?? 2),
            'exit_at_r' => (float) ($data['exit_at_r'] ?? 4),
            'max_hold_days' => max(1, (int) ($data['max_hold_days'] ?? 20)),
            'apply_from_date' => $this->sanitize_apply_from_date($data['apply_from_date'] ?? null),
            'scan_time' => $this->sanitize_scan_time($data['scan_time'] ?? '18:00'),
            'is_active' => !empty($data['is_active']) ? 1 : 0,
        ];
    }

    private function normalize_rule_operator($raw_operator) {
        $operator = strtolower(trim(html_entity_decode((string) $raw_operator, ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        $operator_aliases = [
            'gt' => '>',
            'lt' => '<',
            'eq' => '=',
            'neq' => '!=',
            'gte' => '>=',
            'lte' => '<=',
            '-' => '>',
        ];

        if (isset($operator_aliases[$operator])) {
            $operator = $operator_aliases[$operator];
        }

        if (!in_array($operator, ['=', '>', '<', 'contains', 'not_contains'], true)) {
            return '=';
        }

        return $operator;
    }

    public function log_rule_change($rule_id, $action, $message, $payload = []) {
        $this->wpdb->insert($this->log_table, [
            'rule_id' => (int) $rule_id,
            'action' => sanitize_key((string) $action),
            'changed_by' => get_current_user_id() ?: null,
            'message' => sanitize_text_field((string) $message),
            'payload' => wp_json_encode($payload),
        ]);

        return (int) $this->wpdb->insert_id;
    }
}
