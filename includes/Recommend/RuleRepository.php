<?php

if (!defined('ABSPATH')) {
    exit;
}

class RuleRepository {
    const ALLOWED_SCAN_TIMES = ['06:00', '09:00', '12:00', '15:00', '18:00'];
    const ALLOWED_INTRADAY_INTERVALS = [5, 10, 15, 20, 30]; // phút
    private $wpdb;
    private $table;
    private $log_table;
    /** @var bool|null — cached result of indicators_ready column existence check */
    private $has_indicators_ready_col = null;
    /** @var bool|null — cached result of lcni_ohlc_latest table existence check */
    private $has_ohlc_latest_table = null;

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
            // Full table keys (không có wp_ prefix)
            'lcni_ohlc'                     => 'o',
            'lcni_symbols'                  => 's',
            'lcni_sym_icb_market'           => 'm',
            'lcni_icb2'                     => 'i',
            'lcni_symbol_tongquan'          => 't',
            'lcni_industry_return'          => 'ir',
            'lcni_industry_metrics'         => 'im',
            'lcni_thong_ke_thi_truong'      => 'tk',
            'lcni_thong_ke_nganh_icb_2'     => 'tn',
            'lcni_market_context'           => 'mc',   // history: join theo event_time + timeframe
            // Short alias — frontend có thể gửi tên ngắn không có prefix lcni_
            'ohlc'                          => 'o',
            'symbols'                       => 's',
            'sym_icb_market'                => 'm',
            'icb2'                          => 'i',
            'symbol_tongquan'               => 't',
            'tongquan'                      => 't',
            'industry_return'               => 'ir',
            'industry_metrics'              => 'im',
            'thong_ke_thi_truong'           => 'tk',
            'thong_ke_nganh_icb_2'          => 'tn',
            'market_context'                => 'mc',
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
                    // Dùng %s thay %f để tránh PHP locale format dấu phẩy/chấm
                    // (một số server Việt Nam dùng dấu phẩy thập phân → MySQL lỗi)
                    $clause       = $column_ref . ' ' . $operator . ' %s';
                    $clause_param = $this->format_numeric_param((float) $raw_value);
                } elseif ($operator === 'contains') {
                    $clause       = $column_ref . ' LIKE %s';
                    $clause_param = '%' . $this->wpdb->esc_like($raw_value) . '%';
                } elseif ($operator === 'not_contains') {
                    $clause       = $column_ref . ' NOT LIKE %s';
                    $clause_param = '%' . $this->wpdb->esc_like($raw_value) . '%';
                } elseif ($operator === '!=') {
                    if (is_numeric($raw_value)) {
                        $clause       = $column_ref . ' != %s';
                        $clause_param = $this->format_numeric_param((float) $raw_value);
                    } else {
                        $clause       = $column_ref . ' != %s';
                        $clause_param = $raw_value;
                    }
                } elseif (is_numeric($raw_value)) {
                    $clause       = $column_ref . ' = %s';
                    $clause_param = $this->format_numeric_param((float) $raw_value);
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
                $explicit_join  = $item['join_with_next']; // 'AND' hoặc 'OR'

                // Ưu tiên join_with_next tường minh hơn same_field heuristic:
                //   - OR tường minh  → luôn gộp vào segment hiện tại (kể cả khác field)
                //   - AND tường minh → luôn tách segment mới (kể cả cùng field)
                //   - Cùng field mà không có explicit override → gộp (backward compat)
                if ($explicit_join === 'OR') {
                    // Người dùng chọn OR tường minh → tiếp tục segment hiện tại
                    continue;
                }

                if ($explicit_join === 'AND' && $same_field) {
                    // Người dùng chọn AND tường minh cho cùng field → tách segment
                    // (VD: xep_hang = 'A' AND xep_hang = 'B+' → 2 điều kiện độc lập)
                    $segments[] = $current;
                    $current    = [];
                    continue;
                }

                if ($same_field) {
                    // Không có explicit join, cùng field → gộp theo behavior cũ
                    continue;
                }

                // Khác field + AND (explicit hoặc default) → tách segment mới
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
                $where[]        = "o.`{$column}` >= %s";
                $where_params[] = $this->format_numeric_param((float) $value);
                continue;
            }

            if (substr($field, -4) === '_max') {
                $column = sanitize_key(substr($field, 0, -4));
                if ($column === '') {
                    continue;
                }
                $where[]        = "o.`{$column}` <= %s";
                $where_params[] = $this->format_numeric_param((float) $value);
                continue;
            }

            if (is_numeric($value)) {
                $where[]        = "o.`{$field}` = %s";
                $where_params[] = $this->format_numeric_param((float) $value);
            } else {
                $where[]        = "o.`{$field}` = %s";
                $where_params[] = sanitize_text_field((string) $value);
            }
        }

        // ── Build JOIN clauses (chỉ JOIN bảng thực sự được dùng) ─────────────
        $ohlc_table        = $this->wpdb->prefix . 'lcni_ohlc';
        // non-window scan dùng ohlc_latest (snapshot 1 row/symbol/timeframe, luôn có đủ indicators)
        // → nhất quán với Filter module, không cần MAX(event_time) subquery
        $ohlc_latest_table = $this->wpdb->prefix . 'lcni_ohlc_latest';
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
            // Dùng bảng history lcni_market_context, join theo event_time + timeframe
            // → lấy đúng snapshot thị trường của phiên giao dịch đó (dùng cho backtest/window scan)
            // Với non-window scan (realtime): o.event_time là nến mới nhất → MC snapshot tương ứng
            $mc_table = $this->wpdb->prefix . 'lcni_market_context';
            $joins[]  = "LEFT JOIN {$mc_table} mc"
                . " ON mc.event_time = o.event_time"
                . " AND mc.timeframe = o.timeframe";
        }

        $join_sql = !empty($joins) ? "\n                " . implode("\n                ", $joins) : '';

        // ── Assemble final WHERE ──────────────────────────────────────────────
        $is_window_scan = $start_event_time !== null || $end_event_time !== null;

        // indicators_ready = 1: window scan (backfill lịch sử) chỉ đọc rows Python đã sync đầy đủ.
        // Guard: chỉ thêm filter khi cột thực sự tồn tại trong DB (migration chưa chạy → bỏ qua).
        // Cache vào instance property để không query information_schema lặp lại mỗi lần scan.
        if ( $this->has_indicators_ready_col === null ) {
            $this->has_indicators_ready_col = (bool) $this->wpdb->get_var(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME   = '{$ohlc_table}'
                   AND COLUMN_NAME  = 'indicators_ready'"
            );
        }

        $base_where        = ['o.timeframe = %s'];
        $base_where_params = [$timeframe];

        if ( $this->has_indicators_ready_col ) {
            $base_where[] = 'o.indicators_ready = 1';
        }

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
            // Window scan: lấy nến EARLIEST khớp điều kiện cho mỗi symbol.
            // Logic:
            //   Subquery (f): WHERE conditions lọc trước → GROUP BY → MIN(event_time) per symbol
            //   JOIN o2: lấy close_price đúng của nến MIN(event_time) đó
            //
            // join_sql và where_sql (có timeframe + event_time range + conditions) nằm
            // trong subquery f — đảm bảo chỉ nến thỏa điều kiện mới được GROUP BY.
            $sql = "SELECT f.symbol, f.event_time, o2.close_price
                FROM (
                    SELECT o.symbol, MIN(o.event_time) AS event_time
                    FROM {$ohlc_table} o{$join_sql}
                    WHERE {$where_sql}
                    GROUP BY o.symbol
                ) f
                INNER JOIN {$ohlc_table} o2
                    ON o2.symbol     = f.symbol
                   AND o2.event_time = f.event_time
                   AND o2.timeframe  = %s
                ORDER BY f.event_time ASC, f.symbol ASC
                LIMIT 2000";

            // all_params cho WHERE trong subquery f, thêm timeframe cho JOIN o2
            $window_params = array_merge($all_params, [$timeframe]);

            return $this->wpdb->get_results(
                $this->wpdb->prepare($sql, $window_params),
                ARRAY_A
            );
        }

        // Non-window scan: dùng lcni_ohlc_latest thay vì lcni_ohlc + MAX(event_time) subquery.
        //
        // lcni_ohlc_latest là snapshot 1 row/(symbol, timeframe), luôn chứa candle mới nhất
        // với đầy đủ indicators (Python ghi trực tiếp, MySQL Event sync định kỳ).
        // Filter module cũng dùng bảng này → kết quả nhất quán với Filter.
        //
        // Lợi ích:
        //   - Không cần MAX(event_time) subquery → nhanh hơn, đơn giản hơn
        //   - Không cần indicators_ready filter (ohlc_latest luôn sẵn sàng)
        //   - Nhất quán với Filter module: "8 mã trong Filter → 8 mã trong Recommend"
        $filter_where = array_merge(['o.timeframe = %s'], $where);

        // Fallback về lcni_ohlc nếu lcni_ohlc_latest chưa tồn tại (cài mới)
        // Cache kết quả vào instance property.
        if ( $this->has_ohlc_latest_table === null ) {
            $this->has_ohlc_latest_table = (bool) $this->wpdb->get_var(
                $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $ohlc_latest_table )
            );
        }
        $scan_table = $this->has_ohlc_latest_table ? $ohlc_latest_table : $ohlc_table;

        $sql = "SELECT o.symbol,
                       o.event_time,
                       o.close_price
                FROM {$scan_table} o{$join_sql}
                WHERE " . implode("\n                    AND ", $filter_where) . "
                ORDER BY o.symbol ASC
                LIMIT 500";

        // $final_params: chỉ timeframe cho filter_where[0] + where_params cho rule conditions
        $final_params = array_merge(
            [$timeframe],   // WHERE o.timeframe = %s
            $where_params   // các condition params
        );

        $prepared_sql = $this->wpdb->prepare($sql, $final_params);

        // DEBUG: log query và kết quả khi WP_DEBUG bật
        if ( defined('WP_DEBUG') && WP_DEBUG ) {
            error_log( '[LCNI RuleRepo] non-window scan table=' . $scan_table );
            error_log( '[LCNI RuleRepo] SQL=' . $prepared_sql );
        }

        $results = $this->wpdb->get_results( $prepared_sql, ARRAY_A );

        if ( defined('WP_DEBUG') && WP_DEBUG ) {
            error_log( '[LCNI RuleRepo] wpdb->last_error=' . $this->wpdb->last_error );
            error_log( '[LCNI RuleRepo] result count=' . count( (array) $results ) );
        }

        return $results;
    }

    /**
     * Normalize entry_conditions từ nhiều format (array, JSON string, legacy flat)
     * về format chuẩn: { match: 'AND'|'OR', rules: [{field, operator, value, join_with_next}] }
     */
    private function normalize_conditions($raw): array {
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

                // Luôn persist join_with_next để không mất thông tin khi đọc lại từ DB
                $normalized_rule['join_with_next'] = $join_with_next;

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

    /**
     * Format số thực thành string với dấu chấm thập phân, tránh PHP locale dùng dấu phẩy.
     * Dùng thay %f trong wpdb->prepare() để MySQL nhận đúng giá trị số.
     *
     * @param float $value
     * @return string  VD: 60.0 → "60", 1.5 → "1.5", 0.001 → "0.001"
     */
    private function format_numeric_param(float $value): string {
        // number_format với dấu chấm, đủ 10 chữ số thập phân rồi trim trailing zeros
        $formatted = number_format($value, 10, '.', '');
        // Bỏ số 0 thừa sau dấu chấm, bỏ luôn dấu chấm nếu không còn phần thập phân
        return rtrim(rtrim($formatted, '0'), '.');
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
            'scan_interval_minutes' => absint( $data['scan_interval_minutes'] ?? 0 ),
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
