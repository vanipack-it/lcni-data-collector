<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * LCNI Industry Matrix Shortcode
 * Version: 5.5.2a
 *
 * Shortcode: [lcni_industry_matrix]
 * Hiển thị bảng pivot: Tên ngành (rows) × Event_time (cols) × giá trị field được chọn
 *
 * Nguồn dữ liệu:
 *   - wp_lcni_industry_metrics
 *   - wp_lcni_industry_return
 *   - wp_lcni_industry_index
 *
 * Cấu hình admin lưu tại option: lcni_industry_matrix_settings
 */
class LCNI_Industry_Matrix_Shortcode {

    const VERSION       = '5.5.2a';
    const OPTION_KEY    = 'lcni_industry_matrix_settings';
    const AJAX_ACTION   = 'lcni_industry_matrix_data';

    // -------------------------------------------------------------------------
    // Field definitions per source table
    // -------------------------------------------------------------------------

    public static function get_all_fields() {
        return [
            'lcni_industry_metrics' => [
                'label'  => 'Industry Metrics',
                'fields' => [
                    'industry_return'    => 'Tỷ suất sinh lời (%)',
                    'industry_value'     => 'Giá trị giao dịch',
                    'stocks_up'          => 'Số cổ phiếu tăng',
                    'total_stocks'       => 'Tổng số cổ phiếu',
                    'return_5d'          => 'Return 5 phiên (%)',
                    'return_10d'         => 'Return 10 phiên (%)',
                    'return_20d'         => 'Return 20 phiên (%)',
                    'momentum'           => 'Momentum',
                    'relative_strength'  => 'Relative Strength',
                    'money_flow_share'   => 'Tỷ trọng dòng tiền (%)',
                    'breadth'            => 'Breadth (%)',
                    'industry_score_raw' => 'Điểm ngành (raw)',
                    'industry_rating_vi' => 'Xếp loại ngành',
                ],
            ],
            'lcni_industry_return' => [
                'label'  => 'Industry Return',
                'fields' => [
                    'industry_return' => 'Tỷ suất sinh lời (%)',
                    'industry_value'  => 'Giá trị giao dịch',
                    'stocks_up'       => 'Số cổ phiếu tăng',
                    'total_stocks'    => 'Tổng số cổ phiếu',
                    'breadth'         => 'Breadth (%)',
                ],
            ],
            'lcni_industry_index' => [
                'label'  => 'Industry Index',
                'fields' => [
                    'industry_index'  => 'Chỉ số ngành',
                    'industry_return' => 'Tỷ suất sinh lời (%)',
                ],
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // Default & saved settings
    // -------------------------------------------------------------------------

    public static function get_default_settings() {
        return [
            // Which fields admin allows on frontend
            'allowed_fields' => [
                'lcni_industry_metrics__industry_return',
                'lcni_industry_metrics__industry_score_raw',
                'lcni_industry_metrics__industry_rating_vi',
                'lcni_industry_metrics__breadth',
                'lcni_industry_metrics__money_flow_share',
                'lcni_industry_index__industry_index',
            ],
            // Style
            'row_bg'            => '#ffffff',
            'row_alt_bg'        => '#f4f6f9',
            'row_height'        => 36,
            'row_separator_color' => '#e2e6ea',
            'row_separator_width' => 1,
            'header_bg'         => '#1a2332',
            'header_height'     => 44,
            'font_size'         => 13,
            // Stripe mode: 'row' | 'col' | 'none'
            'stripe_mode'       => 'row',
            // Col stripe colors (used when stripe_mode=col)
            'col_stripe_a'      => '#ffffff',
            'col_stripe_b'      => '#f0f4ff',
        ];
    }

    public static function get_settings() {
        $saved    = get_option(self::OPTION_KEY, []);
        $defaults = self::get_default_settings();
        return array_merge($defaults, is_array($saved) ? $saved : []);
    }

    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    public function __construct() {
        add_action('init',              [$this, 'register_shortcodes']);
        add_action('wp_enqueue_scripts', [$this, 'register_assets']);
        add_action('wp_ajax_'          . self::AJAX_ACTION, [$this, 'ajax_data']);
        add_action('wp_ajax_nopriv_'   . self::AJAX_ACTION, [$this, 'ajax_data']);
    }

    // -------------------------------------------------------------------------
    // Shortcode registration & assets
    // -------------------------------------------------------------------------

    public function register_shortcodes() {
        add_shortcode('lcni_industry_matrix', [$this, 'render']);
    }

    public function register_assets() {
        wp_register_style(
            'lcni-industry-matrix',
            LCNI_URL . 'assets/css/lcni-industry-matrix.css',
            [],
            self::VERSION
        );
    }

    // -------------------------------------------------------------------------
    // Shortcode render
    // -------------------------------------------------------------------------

    public function render($atts = []) {
        $atts = shortcode_atts([
            'timeframe' => '1D',
            'limit'     => 30,
        ], $atts, 'lcni_industry_matrix');

        wp_enqueue_style('lcni-industry-matrix');

        $settings       = self::get_settings();
        $allowed_fields = (array) ($settings['allowed_fields'] ?? []);
        $field_options  = $this->build_field_options($allowed_fields);

        // Inline CSS variables from admin settings
        $css_vars = $this->build_css_vars($settings);

        $nonce = wp_create_nonce(self::AJAX_ACTION . '_nonce');

        ob_start();
        ?>
        <div class="lcnim-wrap" style="<?php echo esc_attr($css_vars); ?>">

            <!-- Controls bar -->
            <div class="lcnim-controls">
                <div class="lcnim-field-select-wrap">
                    <label class="lcnim-label">Chỉ tiêu</label>
                    <div class="lcnim-select-search">
                        <input type="text" class="lcnim-search-input" placeholder="Tìm nhanh..." autocomplete="off">
                        <select class="lcnim-field-select" size="1">
                            <?php foreach ($field_options as $value => $label) : ?>
                                <option value="<?php echo esc_attr($value); ?>"><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="lcnim-tf-wrap">
                    <label class="lcnim-label">Timeframe</label>
                    <select class="lcnim-tf-select">
                        <?php foreach (['1D', '1W', '1M'] as $tf) : ?>
                            <option value="<?php echo esc_attr($tf); ?>" <?php selected(strtoupper($atts['timeframe']), $tf); ?>><?php echo esc_html($tf); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="lcnim-limit-wrap">
                    <label class="lcnim-label">Số phiên</label>
                    <input type="number" class="lcnim-limit-input" value="<?php echo esc_attr((int) $atts['limit']); ?>" min="5" max="120" step="1">
                </div>
                <button class="lcnim-view-btn" type="button">
                    <span class="lcnim-btn-icon">▶</span> Xem
                </button>
            </div>

            <!-- Status bar -->
            <div class="lcnim-status"></div>

            <!-- Scrollable table container -->
            <div class="lcnim-table-container">
                <div class="lcnim-table-inner">
                    <table class="lcnim-table">
                        <thead class="lcnim-thead">
                            <tr class="lcnim-header-row">
                                <th class="lcnim-th lcnim-th-name">Tên ngành</th>
                            </tr>
                        </thead>
                        <tbody class="lcnim-tbody">
                            <tr class="lcnim-empty-row">
                                <td colspan="1" class="lcnim-empty-cell">Chọn chỉ tiêu và nhấn <strong>Xem</strong> để tải dữ liệu.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <script>
        (function() {
            var wrap = document.currentScript.previousElementSibling;
            if (!wrap || !wrap.classList.contains('lcnim-wrap')) return;

            var ajaxUrl   = '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';
            var nonce     = '<?php echo esc_js($nonce); ?>';
            var action    = '<?php echo esc_js(self::AJAX_ACTION); ?>';
            var stripeMode = '<?php echo esc_js($settings['stripe_mode'] ?? 'row'); ?>';
            var colStripeA = '<?php echo esc_js($settings['col_stripe_a'] ?? '#ffffff'); ?>';
            var colStripeB = '<?php echo esc_js($settings['col_stripe_b'] ?? '#f0f4ff'); ?>';

            var searchInput  = wrap.querySelector('.lcnim-search-input');
            var fieldSelect  = wrap.querySelector('.lcnim-field-select');
            var tfSelect     = wrap.querySelector('.lcnim-tf-select');
            var limitInput   = wrap.querySelector('.lcnim-limit-input');
            var viewBtn      = wrap.querySelector('.lcnim-view-btn');
            var statusEl     = wrap.querySelector('.lcnim-status');
            var thead        = wrap.querySelector('.lcnim-thead');
            var tbody        = wrap.querySelector('.lcnim-tbody');
            var allOptions   = Array.from(fieldSelect.options);

            // --- Search filter ---
            searchInput.addEventListener('input', function() {
                var q = this.value.toLowerCase().trim();
                allOptions.forEach(function(opt) {
                    var match = !q || opt.text.toLowerCase().includes(q) || opt.value.toLowerCase().includes(q);
                    opt.hidden = !match;
                });
                // Auto-select first visible
                var first = allOptions.find(function(o) { return !o.hidden; });
                if (first) fieldSelect.value = first.value;
            });

            // --- View button ---
            viewBtn.addEventListener('click', function() {
                var field     = fieldSelect.value;
                var timeframe = tfSelect.value;
                var limit     = Math.max(5, Math.min(120, parseInt(limitInput.value) || 30));

                if (!field) {
                    setStatus('⚠ Vui lòng chọn chỉ tiêu.', 'warn');
                    return;
                }

                setStatus('⏳ Đang tải dữ liệu...', 'loading');
                viewBtn.disabled = true;

                var body = new URLSearchParams();
                body.append('action', action);
                body.append('nonce', nonce);
                body.append('field', field);
                body.append('timeframe', timeframe);
                body.append('limit', limit);

                fetch(ajaxUrl, {
                    method: 'POST',
                    body: body,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                })
                .then(function(r) { return r.json(); })
                .then(function(json) {
                    if (!json.success) {
                        setStatus('✗ ' + (json.data && json.data.message ? json.data.message : 'Lỗi không xác định.'), 'error');
                        return;
                    }
                    renderTable(json.data, field);
                    setStatus('✓ ' + json.data.event_times.length + ' phiên × ' + json.data.industries.length + ' ngành  |  ' + json.data.field_label, 'ok');
                })
                .catch(function(e) {
                    setStatus('✗ Lỗi mạng: ' + e.message, 'error');
                })
                .finally(function() {
                    viewBtn.disabled = false;
                });
            });

            function setStatus(msg, type) {
                statusEl.textContent = msg;
                statusEl.className = 'lcnim-status lcnim-status--' + (type || 'ok');
            }

            function formatValue(v, field) {
                if (v === null || v === undefined || v === '') return '—';
                // String fields
                if (typeof v === 'string' && isNaN(parseFloat(v))) return v;
                var n = parseFloat(v);
                if (isNaN(n)) return v;
                // Detect if pct-like field
                var pct_fields = ['industry_return','return_5d','return_10d','return_20d','momentum','relative_strength','money_flow_share','breadth'];
                var fname = field.split('__')[1] || field;
                if (pct_fields.indexOf(fname) !== -1) {
                    return (n >= 0 ? '+' : '') + n.toFixed(2) + '%';
                }
                if (fname === 'industry_value') {
                    // Tỷ đồng
                    return (n / 1e9).toFixed(1) + ' tỷ';
                }
                if (fname === 'industry_score_raw') return n.toFixed(2);
                if (fname === 'industry_index')     return n.toFixed(2);
                return n.toLocaleString('vi-VN');
            }

            function getCellColor(v, field) {
                var fname = field.split('__')[1] || field;
                var pct_fields = ['industry_return','return_5d','return_10d','return_20d','momentum'];
                if (pct_fields.indexOf(fname) === -1) return '';
                var n = parseFloat(v);
                if (isNaN(n)) return '';
                if (n > 2)  return 'lcnim-cell--strong-up';
                if (n > 0)  return 'lcnim-cell--up';
                if (n < -2) return 'lcnim-cell--strong-down';
                if (n < 0)  return 'lcnim-cell--down';
                return 'lcnim-cell--neutral';
            }

            function renderTable(data, field) {
                var eventTimes  = data.event_times;  // [{ts, label}]
                var industries  = data.industries;   // [{id_icb2, name}]
                var matrix      = data.matrix;       // {id_icb2: {ts: value}}

                // Build header
                var headerRow = document.createElement('tr');
                headerRow.className = 'lcnim-header-row';
                var th0 = document.createElement('th');
                th0.className = 'lcnim-th lcnim-th-name';
                th0.textContent = 'Tên ngành';
                headerRow.appendChild(th0);

                eventTimes.forEach(function(et, ci) {
                    var th = document.createElement('th');
                    th.className = 'lcnim-th lcnim-th-time';
                    th.textContent = et.label;
                    if (stripeMode === 'col' && ci % 2 === 1) {
                        th.style.background = colStripeB;
                    }
                    headerRow.appendChild(th);
                });

                thead.innerHTML = '';
                thead.appendChild(headerRow);

                // Build body
                var frag = document.createDocumentFragment();
                industries.forEach(function(ind, ri) {
                    var tr = document.createElement('tr');
                    tr.className = 'lcnim-row';

                    if (stripeMode === 'row') {
                        tr.style.background = ri % 2 === 0
                            ? (wrap.style.getPropertyValue('--lcnim-row-bg')     || '#fff')
                            : (wrap.style.getPropertyValue('--lcnim-row-alt-bg') || '#f4f6f9');
                    }

                    var td0 = document.createElement('td');
                    td0.className = 'lcnim-td lcnim-td-name';
                    td0.textContent = ind.name;
                    tr.appendChild(td0);

                    var rowData = matrix[String(ind.id_icb2)] || {};
                    eventTimes.forEach(function(et, ci) {
                        var td = document.createElement('td');
                        td.className = 'lcnim-td lcnim-td-val';
                        var val = rowData[String(et.ts)];
                        var formatted = formatValue(val, field);
                        td.textContent = formatted;

                        var colorClass = getCellColor(val, field);
                        if (colorClass) td.classList.add(colorClass);

                        if (stripeMode === 'col') {
                            td.style.background = ci % 2 === 0 ? colStripeA : colStripeB;
                        }
                        tr.appendChild(td);
                    });

                    frag.appendChild(tr);
                });

                tbody.innerHTML = '';
                tbody.appendChild(frag);

                // Update colspan on empty row guard
                var emptyRow = tbody.querySelector('.lcnim-empty-row');
                if (emptyRow) {
                    var emptyCell = emptyRow.querySelector('td');
                    if (emptyCell) emptyCell.setAttribute('colspan', eventTimes.length + 1);
                }
            }
        })();
        </script>
        <?php
        return ob_get_clean();
    }

    // -------------------------------------------------------------------------
    // AJAX data handler
    // -------------------------------------------------------------------------

    public function ajax_data() {
        check_ajax_referer(self::AJAX_ACTION . '_nonce', 'nonce');

        $field     = isset($_POST['field'])     ? sanitize_key((string) $_POST['field'])         : '';
        $timeframe = isset($_POST['timeframe']) ? strtoupper(sanitize_text_field((string) $_POST['timeframe'])) : '1D';
        $limit     = max(5, min(120, (int) ($_POST['limit'] ?? 30)));

        if (!$field) {
            wp_send_json_error(['message' => 'Thiếu tham số field.']);
        }

        // Validate field is allowed
        $settings       = self::get_settings();
        $allowed_fields = (array) ($settings['allowed_fields'] ?? []);
        if (!in_array($field, $allowed_fields, true)) {
            wp_send_json_error(['message' => 'Field không được phép.']);
        }

        // Parse field key: "{table}__{column}"
        $parts = explode('__', $field, 2);
        if (count($parts) !== 2) {
            wp_send_json_error(['message' => 'Field key không hợp lệ.']);
        }

        [$table_key, $column] = $parts;
        $all_fields = self::get_all_fields();

        if (!isset($all_fields[$table_key]['fields'][$column])) {
            wp_send_json_error(['message' => 'Field không tồn tại trong cấu hình.']);
        }

        $field_label = $all_fields[$table_key]['label'] . ' › ' . $all_fields[$table_key]['fields'][$column];

        global $wpdb;
        $table    = $wpdb->prefix . $table_key;
        $icb2_tbl = $wpdb->prefix . 'lcni_icb2';

        // Allowed columns (whitelist to prevent SQL injection)
        $allowed_cols = array_keys($all_fields[$table_key]['fields']);
        if (!in_array($column, $allowed_cols, true)) {
            wp_send_json_error(['message' => 'Cột không hợp lệ.']);
        }

        // Get distinct event_times (latest N)
        $event_times_raw = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT event_time FROM {$table} WHERE timeframe = %s ORDER BY event_time DESC LIMIT %d",
                $timeframe, $limit
            )
        );

        if (empty($event_times_raw)) {
            wp_send_json_error(['message' => 'Không có dữ liệu cho timeframe này.']);
        }

        // Reverse to chronological order (oldest → newest)
        $event_times_raw = array_reverse(array_map('intval', $event_times_raw));

        // Build event_time labels (date format)
        $event_times = array_map(function ($ts) {
            return [
                'ts'    => $ts,
                'label' => wp_date('d/m/y', $ts, new DateTimeZone('Asia/Ho_Chi_Minh')),
            ];
        }, $event_times_raw);

        // Get industries
        $industries_raw = $wpdb->get_results(
            "SELECT id_icb2, name_icb2 FROM {$icb2_tbl} ORDER BY name_icb2 ASC",
            ARRAY_A
        );

        if (empty($industries_raw)) {
            wp_send_json_error(['message' => 'Không có dữ liệu ngành (lcni_icb2 trống).']);
        }

        $industries = array_map(function ($row) {
            return ['id_icb2' => (int) $row['id_icb2'], 'name' => $row['name_icb2']];
        }, $industries_raw);

        // Build IN clause for event_times
        $placeholders = implode(', ', array_fill(0, count($event_times_raw), '%d'));
        $query_args   = array_merge([$timeframe], $event_times_raw);

        // Fetch all data in one query
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id_icb2, event_time, `{$column}` AS val
                 FROM {$table}
                 WHERE timeframe = %s AND event_time IN ({$placeholders})
                 ORDER BY id_icb2 ASC, event_time ASC",
                $query_args
            ),
            ARRAY_A
        );

        // Build matrix: {id_icb2 => {event_time => value}}
        $matrix = [];
        foreach ((array) $rows as $row) {
            $matrix[(string) $row['id_icb2']][(string) $row['event_time']] = $row['val'];
        }

        // Filter industries that actually have data
        $industries = array_values(array_filter($industries, function ($ind) use ($matrix) {
            return isset($matrix[(string) $ind['id_icb2']]);
        }));

        wp_send_json_success([
            'event_times' => $event_times,
            'industries'  => $industries,
            'matrix'      => $matrix,
            'field_label' => $field_label,
        ]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function build_field_options(array $allowed_fields) {
        $options    = [];
        $all_fields = self::get_all_fields();

        foreach ($all_fields as $table_key => $table_def) {
            foreach ($table_def['fields'] as $col => $col_label) {
                $key = $table_key . '__' . $col;
                if (!empty($allowed_fields) && !in_array($key, $allowed_fields, true)) {
                    continue;
                }
                $options[$key] = '[' . $table_def['label'] . '] ' . $col_label;
            }
        }

        return $options;
    }

    private function build_css_vars(array $s) {
        return implode('; ', [
            '--lcnim-row-bg: '             . esc_attr($s['row_bg']              ?? '#ffffff'),
            '--lcnim-row-alt-bg: '         . esc_attr($s['row_alt_bg']          ?? '#f4f6f9'),
            '--lcnim-row-height: '         . ((int) ($s['row_height'] ?? 36))     . 'px',
            '--lcnim-row-sep-color: '      . esc_attr($s['row_separator_color']  ?? '#e2e6ea'),
            '--lcnim-row-sep-width: '      . ((int) ($s['row_separator_width'] ?? 1)) . 'px',
            '--lcnim-header-bg: '          . esc_attr($s['header_bg']            ?? '#1a2332'),
            '--lcnim-header-height: '      . ((int) ($s['header_height'] ?? 44)) . 'px',
            '--lcnim-font-size: '          . ((int) ($s['font_size'] ?? 13))     . 'px',
        ]);
    }
}
