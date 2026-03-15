<?php

if (!defined('ABSPATH')) {
    exit;
}

class RuleRepository {
    const ALLOWED_SCAN_TIMES = ['06:00', '09:00', '12:00', '15:00', '18:00'];
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

        if (is_array($decoded) && !isset($decoded['rules']) && (array_values($decoded) === $decoded)) {
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
        $timeframe   = sanitize_text_field((string) ($rule['timeframe'] ?? '1D'));

        // ── Bảng tham chiếu ──────────────────────────────────────────────────
        // alias_map: table_key (không có wp_ prefix) → SQL alias được dùng trong query
        // JOIN map: những bảng nào cần join, điều kiện join là gì
        // Các bảng join theo symbol (1-1 với o.symbol):
        //   s  = lcni_symbols           (thông tin niêm yết cơ bản)
        //   m  = lcni_sym_icb_market    (mapping symbol → marketid, id_icb2, exchange)
        //   i  = lcni_icb2             (tên ngành ICB2)
        //   t  = lcni_symbol_tongquan   (chỉ số tài chính, xep_hang)
        // Các bảng join theo id_icb2 + event_time (1-many, cần subquery latest):
        //   ir = lcni_industry_return   (industry_return, breadth, stocks_up ...)
        //   im = lcni_industry_metrics  (momentum, relative_strength, industry_rating_vi ...)
        // Các bảng thống kê thị trường (join theo marketid + event_time):
        //   tk = lcni_thong_ke_thi_truong
        // Lưu ý: lcni_thong_ke_nganh_icb_2 join theo (marketid, id_icb2, event_time)
        //   tn = lcni_thong_ke_nganh_icb_2

        $alias_map = [
            'lcni_ohlc'                     => 'o',
            'lcni_symbols'                  => 's',
            'lcni_sym_icb_market'           => 'm',
            'lcni_icb2'                     => 'i',
            'lcni_symbol_tongquan'          => 't',
            'lcni_industry_return'          => 'ir',
            'lcni_industry_metrics'         => 'im',
            'lcni_thong_ke_thi_truong'      => 'tk',
            'lcni_thong_ke_nganh_icb_2'     => 'tn',
            'lcni_market_context_latest'    => 'mc',
        ];

        // Đánh dấu những alias nào thực sự được dùng trong conditions
        // → chỉ JOIN những bảng cần thiết (tránh JOIN thừa làm chậm query)
        $required_aliases = ['o' => true]; // ohlc luôn cần

        // ── Parse conditions → danh sách clause items ────────────────────────
        // Mỗi item: [ 'field_key' => 'table.column', 'clause' => SQL, 'param' => mixed,
        //             'join_with_next' => 'AND'|'OR', 'alias' => 'o'|'s'|... ]
        $clause_items = [];

        if (isset($conditions['rules']) && is_array($conditions['rules'])) {
            foreach ($conditions['rules'] as $rule_item) {
                if (!is_array($rule_item)) {
                    continue;
                }

                $raw_field = sanitize_text_field((string) ($rule_item['field'] ?? ''));
                $operator  = $this->normalize_rule_operator($rule_item['operator'] ?? '=');
                $raw_value = sanitize_text_field((string) ($rule_item['value'] ?? ''));

                if ($raw_field === '' || $raw_value === '') {
                    continue;
                }

                $parts = explode('.', $raw_field, 2);
                if (count($parts) !== 2) {
                    continue;
                }

                $table_key  = $this->normalize_condition_table_key((string) $parts[0]);
                $column_key = sanitize_key((string) $parts[1]);

                if ($table_key === '' || $column_key === '' || !isset($alias_map[$table_key])) {
                    continue;
                }

                $alias      = $alias_map[$table_key];
                $column_ref = $alias . '.`' . $column_key . '`';

                // Build clause + param
                $clause = '';
                $clause_param = null;

                if (in_array($operator, ['>', '<', '>=', '<='], true)) {
                    if (!is_numeric($raw_value)) {
                        continue;
                    }
                    $clause       = $column_ref . ' ' . $operator . ' %f';
                    $clause_param = (float) $raw_value;
                } elseif ($operator === 'contains') {
                    $clause       = $column_ref . ' LIKE %s';
                    $clause_param = '%' . $this->wpdb->esc_like($raw_value) . '%';
                } elseif ($operator === 'not_contains') {
                    $clause       = $column_ref . ' NOT LIKE %s';
                    $clause_param = '%' . $this->wpdb->esc_like($raw_value) . '%';
                } elseif ($operator === '!=') {
                    if (is_numeric($raw_value)) {
                        $clause       = $column_ref . ' != %f';
                        $clause_param = (float) $raw_value;
                    } else {
                        $clause       = $column_ref . ' != %s';
                        $clause_param = $raw_value;
                    }
                } elseif (is_numeric($raw_value)) {
                    $clause       = $column_ref . ' = %f';
                    $clause_param = (float) $raw_value;
                } else {
                    $clause       = $column_ref . ' = %s';
                    $clause_param = $raw_value;
                }

                if ($clause === '') {
                    continue;
                }

                // join_with_next = operator kết hợp clause NÀY với clause TIẾP THEO
                $join_with_next = strtoupper(sanitize_text_field(
                    (string) ($rule_item['join_with_next'] ?? $rule_item['join'] ?? 'AND')
                ));
                if (!in_array($join_with_next, ['AND', 'OR'], true)) {
                    $join_with_next = 'AND';
                }

                $required_aliases[$alias] = true;

                $clause_items[] = [
                    'field_key'      => $table_key . '.' . $column_key,
                    'alias'          => $alias,
                    'clause'         => $clause,
                    'param'          => $clause_param,
                    'join_with_next' => $join_with_next,
                ];
            }
        }

        // ── Build WHERE từ clause_items ───────────────────────────────────────
        //
        // Thuật toán: "same-field → OR, different-field → AND"
        // Cụ thể:
        //   1. Nhóm các clause liên tiếp có cùng field_key (table.column) VÀO một OR-group.
        //   2. Giữa các group khác field → AND (trừ khi join_with_next cuối của group là OR,
        //      tức người dùng muốn OR toàn group với group tiếp theo dù khác field).
        //   3. Kết quả: (f1_cond1 OR f1_cond2) AND (f2_cond1 OR f2_cond2) AND (f3_cond1)
        //
        // Ví dụ trong ảnh:
        //   tang_gia_kem_vol contains "giá"  AND
        //   xep_hang         contains "A"    OR
        //   xep_hang         = "B+"
        //   → (tang_gia_kem_vol LIKE '%giá%') AND (xep_hang LIKE '%A%' OR xep_hang = 'B+')
        //
        // Ví dụ phức tạp hơn (khác field, OR tường minh):
        //   rsi >= 60       AND
        //   macd > 0        OR     ← người dùng chọn OR dù khác field
        //   xay_nen = 'xây nền'
        //   → (rsi >= 60) AND ((macd > 0) OR (xay_nen = 'xây nền'))
        //   Nhóm theo join_with_next: nếu join_with_next = OR → tiếp tục trong cùng AND-group

        $where        = [];
        $where_params = [];

        if (!empty($clause_items)) {
            // Bước 1: Tạo các "AND-segment" — mỗi segment là danh sách các clause
            // sẽ được OR với nhau bên trong. Hai segment liền nhau được AND với nhau.
            //
            // Logic tách segment:
            //   - Nếu field hiện tại ≠ field kế tiếp VÀ join_with_next = AND → tách segment mới
            //   - Nếu join_with_next = OR → KHÔNG tách (OR override, kể cả khác field)
            //   - Nếu field hiện tại = field kế tiếp → KHÔNG tách (cùng field luôn OR)

            $segments = []; // mỗi phần tử: [ ['clause'=>..., 'param'=>...], ... ]
            $current  = [];

            $n = count($clause_items);
            for ($idx = 0; $idx < $n; $idx++) {
                $item = $clause_items[$idx];
                $current[] = ['clause' => $item['clause'], 'param' => $item['param']];

                $is_last = ($idx === $n - 1);
                if ($is_last) {
                    // Đây là clause cuối — đóng segment
                    $segments[] = $current;
                    break;
                }

                $next_item      = $clause_items[$idx + 1];
                $same_field     = ($item['field_key'] === $next_item['field_key']);
                $explicit_or    = ($item['join_with_next'] === 'OR');

                if ($same_field || $explicit_or) {
                    // Cùng field HOẶC người dùng tường minh chọn OR → tiếp tục segment hiện tại
                    continue;
                }

                // Khác field + AND → đóng segment hiện tại, bắt đầu segment mới
                $segments[] = $current;
                $current    = [];
            }

            // Bước 2: Build SQL từ segments
            // Mỗi segment → (clause1 OR clause2 OR ...)
            // Các segment → AND với nhau
            $segment_sqls = [];
            foreach ($segments as $seg) {
                $clauses = array_column($seg, 'clause');
                $params  = array_column($seg, 'param');

                if (count($clauses) === 1) {
                    $segment_sqls[] = '(' . $clauses[0] . ')';
                } else {
                    $segment_sqls[] = '(' . implode(' OR ', $clauses) . ')';
                }

                foreach ($params as $p) {
                    $where_params[] = $p;
                }
            }

            if (!empty($segment_sqls)) {
                $where[] = implode(' AND ', $segment_sqls);
            }
        }

        // ── Legacy conditions (flat key-value format) ─────────────────────────
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
                $where[]      = "o.`{$field}` IN ({$placeholders})";
                foreach ($values as $item) {
                    $where_params[] = $item;
                }
                continue;
            }

            if (substr($field, -4) === '_min') {
                $column = sanitize_key(substr($field, 0, -4));
                if ($column === '') {
                    continue;
                }
                $where[]        = "o.`{$column}` >= %f";
                $where_params[] = (float) $value;
                continue;
            }

            if (substr($field, -4) === '_max') {
                $column = sanitize_key(substr($field, 0, -4));
                if ($column === '') {
                    continue;
                }
                $where[]        = "o.`{$column}` <= %f";
                $where_params[] = (float) $value;
                continue;
            }

            if (is_numeric($value)) {
                $where[]        = "o.`{$field}` = %f";
                $where_params[] = (float) $value;
            } else {
                $where[]        = "o.`{$field}` = %s";
                $where_params[] = sanitize_text_field((string) $value);
            }
        }

        // ── Build JOIN clauses (chỉ JOIN bảng thực sự được dùng) ─────────────
        $ohlc_table      = $this->wpdb->prefix . 'lcni_ohlc';
        $symbol_table    = $this->wpdb->prefix . 'lcni_symbols';
        $icb2_table      = $this->wpdb->prefix . 'lcni_icb2';
        $mapping_table   = $this->wpdb->prefix . 'lcni_sym_icb_market';
        $tongquan_table  = $this->wpdb->prefix . 'lcni_symbol_tongquan';
        $ind_return_tbl  = $this->wpdb->prefix . 'lcni_industry_return';
        $ind_metrics_tbl = $this->wpdb->prefix . 'lcni_industry_metrics';
        $tkt_table       = $this->wpdb->prefix . 'lcni_thong_ke_thi_truong';
        $tkn_table       = $this->wpdb->prefix . 'lcni_thong_ke_nganh_icb_2';

        // Các bảng join theo symbol luôn được JOIN nếu cần (LEFT JOIN)
        // Bảng join theo id_icb2 + event_time cần mapping_table làm cầu
        $need_m  = isset($required_aliases['m'])
                || isset($required_aliases['i'])
                || isset($required_aliases['ir'])
                || isset($required_aliases['im'])
                || isset($required_aliases['tk'])
                || isset($required_aliases['tn']);

        $need_i  = isset($required_aliases['i']);
        $need_s  = isset($required_aliases['s']);
        $need_t  = isset($required_aliases['t']);
        $need_ir = isset($required_aliases['ir']);
        $need_im = isset($required_aliases['im']);
        $need_tk = isset($required_aliases['tk']);
        $need_tn = isset($required_aliases['tn']);
        $need_mc = isset($required_aliases['mc']);

        // Nếu cần ir/im/tn nhưng chưa có m → bắt buộc join m
        if ($need_ir || $need_im || $need_tn) {
            $need_m = true;
        }

        $joins = [];

        if ($need_s) {
            $joins[] = "LEFT JOIN {$symbol_table} s ON s.symbol = o.symbol";
        }

        if ($need_m) {
            $joins[] = "LEFT JOIN {$mapping_table} m ON m.symbol = o.symbol";
        }

        if ($need_i) {
            // i cần m đã join ở trên
            $joins[] = "LEFT JOIN {$icb2_table} i ON i.id_icb2 = m.id_icb2";
        }

        if ($need_t) {
            $joins[] = "LEFT JOIN {$tongquan_table} t ON t.symbol = o.symbol";
        }

        if ($need_ir) {
            // industry_return join theo (id_icb2 của symbol, timeframe, event_time của nến)
            $joins[] = "LEFT JOIN {$ind_return_tbl} ir"
                . " ON ir.id_icb2 = m.id_icb2"
                . " AND ir.event_time = o.event_time"
                . " AND ir.timeframe = o.timeframe";
        }

        if ($need_im) {
            $joins[] = "LEFT JOIN {$ind_metrics_tbl} im"
                . " ON im.id_icb2 = m.id_icb2"
                . " AND im.event_time = o.event_time"
                . " AND im.timeframe = o.timeframe";
        }

        if ($need_tk) {
            // thong_ke_thi_truong join theo (marketid của symbol, event_time, timeframe)
            // marketid trong bảng này là SMALLINT, cần CAST vì m.market_id là VARCHAR
            $joins[] = "LEFT JOIN {$tkt_table} tk"
                . " ON CAST(tk.marketid AS CHAR) = m.market_id"
                . " AND tk.event_time = o.event_time"
                . " AND tk.timeframe = o.timeframe";
        }

        if ($need_tn) {
            // thong_ke_nganh_icb_2 join theo (marketid, icb_level2-tên, event_time)
            // cần join qua icb2 để lấy name_icb2
            if (!$need_i) {
                // bắt buộc join i để lấy tên ngành
                $joins[] = "LEFT JOIN {$icb2_table} i ON i.id_icb2 = m.id_icb2";
            }
            $joins[] = "LEFT JOIN {$tkn_table} tn"
                . " ON CAST(tn.marketid AS CHAR) = m.market_id"
                . " AND tn.icb_level2 = i.name_icb2"
                . " AND tn.event_time = o.event_time"
                . " AND tn.timeframe = o.timeframe";
        }

        if ($need_mc) {
            $mc_table = $this->wpdb->prefix . 'lcni_market_context_latest';
            $joins[]  = "LEFT JOIN {$mc_table} mc ON mc.timeframe = o.timeframe";
        }

        $join_sql = !empty($joins) ? "\n                " . implode("\n                ", $joins) : '';

        // ── Assemble final WHERE ──────────────────────────────────────────────
        $is_window_scan = $start_event_time !== null || $end_event_time !== null;

        $base_where        = ['o.timeframe = %s'];
        $base_where_params = [$timeframe];

        if ($start_event_time !== null) {
            $base_where[]        = 'o.event_time >= %d';
            $base_where_params[] = max(0, (int) $start_event_time);
        }

        if ($end_event_time !== null) {
            $base_where[]        = 'o.event_time <= %d';
            $base_where_params[] = max(0, (int) $end_event_time);
        }

        $all_where  = array_merge($base_where, $where);
        $all_params = array_merge($base_where_params, $where_params);

        $where_sql = implode("\n                    AND ", $all_where);

        // ── Execute query ─────────────────────────────────────────────────────
        if ($is_window_scan) {
            $sql = "SELECT o.symbol, o.event_time, o.close_price
                FROM {$ohlc_table} o{$join_sql}
                WHERE {$where_sql}
                ORDER BY o.event_time ASC, o.symbol ASC
                LIMIT 2000";

            return $this->wpdb->get_results(
                $this->wpdb->prepare($sql, $all_params),
                ARRAY_A
            );
        }

        // Non-window scan: lấy nến mới nhất của mỗi symbol
        // Dùng INNER JOIN subquery để lọc đúng nến latest trước, rồi apply conditions
        // Điều này tránh việc LEFT JOIN với bảng industry bị nhân hàng do nhiều event_time
        $latest_params  = [$timeframe];
        $filter_params  = array_merge([$timeframe], $where_params);

        // Rebuild base_where không có event_time filter (vì đây là latest-scan)
        $filter_where = array_merge(['o.timeframe = %s'], $where);

        $sql = "SELECT o.symbol,
                       o.event_time,
                       o.close_price
                FROM {$ohlc_table} o
                INNER JOIN (
                    SELECT symbol, MAX(event_time) AS max_event_time
                    FROM {$ohlc_table}
                    WHERE timeframe = %s
                    GROUP BY symbol
                ) latest ON latest.symbol = o.symbol
                         AND o.event_time = latest.max_event_time
                         AND o.timeframe = %s{$join_sql}
                WHERE " . implode("\n                    AND ", $filter_where) . "
                ORDER BY o.symbol ASC
                LIMIT 500";

        $final_params = array_merge(
            [$timeframe, $timeframe],  // params cho 2 chỗ trong INNER JOIN subquery
            $filter_params
        );

        return $this->wpdb->get_results(
            $this->wpdb->prepare($sql, $final_params),
            ARRAY_A
        );
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

        if (!isset($raw['rules']) && (array_values($raw) === $raw)) {
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
        if (in_array($value, self::ALLOWED_SCAN_TIMES, true)) {
            return $value;
        }

        return '18:00';
    }

    private function sanitize_scan_times($values) {
        $raw_values = is_array($values) ? $values : explode(',', (string) $values);
        $sanitized = [];

        foreach ($raw_values as $value) {
            $scan_time = $this->sanitize_scan_time($value);
            if (!in_array($scan_time, $sanitized, true)) {
                $sanitized[] = $scan_time;
            }
        }

        sort($sanitized);

        return !empty($sanitized) ? $sanitized : ['18:00'];
    }

    private function sanitize_max_loss_pct($value) {
        $max_loss_pct = abs((float) $value);
        if ($max_loss_pct <= 0) {
            return 8.0;
        }

        return min($max_loss_pct, 100.0);
    }

    private function build_payload($data) {
        $scan_times = $this->sanitize_scan_times($data['scan_times'] ?? ($data['scan_time'] ?? '18:00'));

        return [
            'name' => sanitize_text_field((string) ($data['name'] ?? '')),
            'timeframe' => strtoupper(sanitize_text_field((string) ($data['timeframe'] ?? '1D'))),
            'description' => sanitize_textarea_field((string) ($data['description'] ?? '')),
            'entry_conditions' => wp_json_encode($this->normalize_conditions($data['entry_conditions'] ?? [])),
            'initial_sl_pct' => (float) ($data['initial_sl_pct'] ?? 8),
            'max_loss_pct' => $this->sanitize_max_loss_pct($data['max_loss_pct'] ?? ($data['initial_sl_pct'] ?? 8)),
            'risk_reward' => (float) ($data['risk_reward'] ?? 3),
            'add_at_r' => (float) ($data['add_at_r'] ?? 2),
            'exit_at_r' => (float) ($data['exit_at_r'] ?? 4),
            'max_hold_days' => max(1, (int) ($data['max_hold_days'] ?? 20)),
            'apply_from_date' => $this->sanitize_apply_from_date($data['apply_from_date'] ?? null),
            'scan_time' => $scan_times[0],
            'scan_times' => implode(',', $scan_times),
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

        if (!in_array($operator, ['=', '!=', '>', '>=', '<', '<=', 'contains', 'not_contains'], true)) {
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
