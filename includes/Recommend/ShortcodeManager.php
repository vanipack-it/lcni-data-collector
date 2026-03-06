<?php

if (!defined('ABSPATH')) {
    exit;
}

class ShortcodeManager {
    private const VIETNAMESE_COLUMN_LABELS = [
        'signal__symbol' => 'Mã CP',
        'rule__name' => 'Tên chiến lược',
        'signal__entry_price' => 'Giá mua',
        'signal__status' => 'Trạng thái',
        'signal__entry_time' => 'Thời điểm mua',
        'signal__current_price' => 'Giá hiện tại',
        'signal__initial_sl' => 'Cắt lỗ ban đầu',
        'signal__risk_per_share' => 'Rủi ro / cổ phiếu',
        'signal__r_multiple' => 'Bội số R',
        'signal__position_state' => 'Tình trạng vị thế',
        'signal__exit_price' => 'Giá bán',
        'signal__exit_time' => 'Thời điểm bán',
        'signal__final_r' => 'R cuối cùng',
        'signal__holding_days' => 'Số ngày nắm giữ',
    ];

    private $signal_repository;
    private $performance_calculator;
    private $position_engine;

    public function __construct(SignalRepository $signal_repository, PerformanceCalculator $performance_calculator, PositionEngine $position_engine) {
        $this->signal_repository = $signal_repository;
        $this->performance_calculator = $performance_calculator;
        $this->position_engine = $position_engine;

        add_action('init', [$this, 'register_shortcodes']);
    }

    public function register_shortcodes() {
        add_shortcode('lcni_signals', [$this, 'render_signals']);
        add_shortcode('lcni_performance', [$this, 'render_performance']);
        add_shortcode('lcni_signal', [$this, 'render_signal_card']);
    }

    public function render_signals($atts = []) {
        $atts = shortcode_atts(['rule_id' => 0, 'status' => '', 'limit' => 200, 'symbol' => ''], $atts, 'lcni_signals');
        $frontend_settings = $this->get_recommend_signal_frontend_settings();
        $columns = (array) ($frontend_settings['column_order'] ?? []);

        $rows = $this->signal_repository->list_signals([
            'rule_id' => $atts['rule_id'],
            'status' => $atts['status'],
            'limit' => $atts['limit'],
            'symbol' => $atts['symbol'],
            'selected_columns' => $columns,
        ]);

        $catalog = $this->signal_repository->get_recommend_column_catalog();
        $styles = (array) ($frontend_settings['styles'] ?? []);
        $stock_detail_base_url = $this->resolve_stock_detail_base_url();
        $value_background = (string) ($styles['value_background'] ?? '#ffffff');
        $value_text_color = (string) ($styles['value_text_color'] ?? '#111827');
        $row_hover_background = (string) ($styles['row_hover_bg'] ?? '#f3f4f6');
        $sticky_column = (string) ($styles['sticky_column'] ?? 'signal__symbol');
        $sticky_header_enabled = !empty($styles['sticky_header']);
        $wrapper_style = sprintf('font-family:%s;color:%s;background:%s;border:%s;border-radius:%dpx;overflow:auto;',
            esc_attr((string) ($styles['font'] ?? 'inherit')),
            esc_attr((string) ($styles['text_color'] ?? '#111827')),
            esc_attr((string) ($styles['background'] ?? '#ffffff')),
            esc_attr((string) ($styles['border'] ?? '1px solid #e5e7eb')),
            (int) ($styles['border_radius'] ?? 8)
        );
        if ($sticky_header_enabled) {
            $wrapper_style .= 'max-height:min(70vh,720px);overscroll-behavior:contain;';
        }

        ob_start();
        echo '<div class="lcni-recommend-signals-table" style="' . $wrapper_style . '">';
        echo '<table style="width:100%;border-collapse:separate;border-spacing:0;font-size:' . (int) ($styles['row_font_size'] ?? 14) . 'px;">';
        $head_row_style = 'height:' . (int) ($styles['head_height'] ?? 30) . 'px;background:' . esc_attr((string) ($styles['header_background'] ?? '#ffffff')) . ';color:' . esc_attr((string) ($styles['header_text_color'] ?? '#111827')) . ';';
        echo '<thead><tr style="' . $head_row_style . '">';
        foreach ($columns as $column) {
            $label = $this->resolve_column_label($column, $catalog);
            $th_style = 'text-align:left;padding:8px;border-bottom:' . (int) ($styles['row_divider_width'] ?? 1) . 'px solid ' . esc_attr((string) ($styles['row_divider_color'] ?? '#e5e7eb')) . ';font-size:' . (int) ($styles['header_font_size'] ?? 14) . 'px;background:' . esc_attr((string) ($styles['header_background'] ?? '#ffffff')) . ';';
            if ($sticky_header_enabled) {
                $th_style .= 'position:sticky;top:0;z-index:20;';
            }
            if ($sticky_column === $column) {
                $th_style .= 'position:sticky;left:0;z-index:' . ($sticky_header_enabled ? '25' : '5') . ';';
            }
            echo '<th style="' . $th_style . '">' . esc_html($label) . '</th>';
        }
        echo '</tr></thead><tbody>';

        foreach ($rows as $row) {
            echo '<tr style="background:' . esc_attr($value_background) . ';color:' . esc_attr($value_text_color) . ';" onmouseover="this.style.background=\'' . esc_attr($row_hover_background) . '\'" onmouseout="this.style.background=\'' . esc_attr($value_background) . '\'">';
            foreach ($columns as $column) {
                $value = isset($row[$column]) ? $row[$column] : '';
                $raw_value = $value;
                $value = $this->format_signal_value($column, $value);
                $cell_style = $this->resolve_recommend_signal_cell_style($column, $raw_value, $styles);
                $cell_style_attr = 'padding:8px;border-bottom:' . (int) ($styles['row_divider_width'] ?? 1) . 'px solid ' . esc_attr((string) ($styles['row_divider_color'] ?? '#e5e7eb')) . ';';
                if ($cell_style['background'] !== '') {
                    $cell_style_attr .= 'background:' . esc_attr($cell_style['background']) . ';';
                }
                if ($cell_style['color'] !== '') {
                    $cell_style_attr .= 'color:' . esc_attr($cell_style['color']) . ';';
                }
                if ($sticky_column === $column) {
                    $cell_bg = $cell_style['background'] !== '' ? (string) $cell_style['background'] : $value_background;
                    $cell_style_attr .= 'position:sticky;left:0;z-index:3;background:' . esc_attr($cell_bg) . ';';
                }

                if ($column === 'signal__symbol') {
                    $symbol = strtoupper(sanitize_text_field((string) $raw_value));
                    $detail_url = $this->build_stock_detail_url($stock_detail_base_url, $symbol);
                    if ($detail_url !== '') {
                        $value = '<a href="' . esc_url($detail_url) . '">' . esc_html((string) $value) . '</a>';
                        echo '<td style="' . $cell_style_attr . '">' . $value . '</td>';
                        continue;
                    }
                }

                echo '<td style="' . $cell_style_attr . '">' . esc_html((string) $value) . '</td>';
            }
            echo '</tr>';
        }

        echo '</tbody></table></div>';

        return ob_get_clean();
    }

    private function get_recommend_signal_frontend_settings() {
        $saved = get_option('lcni_frontend_settings_recommend_signal', []);
        if (!is_array($saved)) {
            $saved = [];
        }

        $columns = isset($saved['column_order']) && is_array($saved['column_order']) ? array_values(array_map('sanitize_key', $saved['column_order'])) : [];
        if (empty($columns)) {
            $columns = ['signal__symbol', 'rule__name', 'signal__entry_price', 'signal__current_price', 'signal__r_multiple', 'signal__position_state', 'signal__status'];
        }

        $styles = isset($saved['styles']) && is_array($saved['styles']) ? $saved['styles'] : [];

        return [
            'column_order' => $columns,
            'styles' => [
                'font' => sanitize_text_field($styles['font'] ?? 'inherit'),
                'text_color' => sanitize_hex_color($styles['text_color'] ?? '#111827') ?: '#111827',
                'background' => sanitize_hex_color($styles['background'] ?? '#ffffff') ?: '#ffffff',
                'border' => sanitize_text_field($styles['border'] ?? '1px solid #e5e7eb'),
                'border_radius' => max(0, min(24, (int) ($styles['border_radius'] ?? 8))),
                'header_font_size' => max(10, min(30, (int) ($styles['header_font_size'] ?? 14))),
                'row_font_size' => max(10, min(30, (int) ($styles['row_font_size'] ?? 14))),
                'header_background' => sanitize_hex_color($styles['header_background'] ?? '#ffffff') ?: '#ffffff',
                'header_text_color' => sanitize_hex_color($styles['header_text_color'] ?? '#111827') ?: '#111827',
                'value_background' => sanitize_hex_color($styles['value_background'] ?? '#ffffff') ?: '#ffffff',
                'value_text_color' => sanitize_hex_color($styles['value_text_color'] ?? '#111827') ?: '#111827',
                'row_divider_color' => sanitize_hex_color($styles['row_divider_color'] ?? '#e5e7eb') ?: '#e5e7eb',
                'row_divider_width' => max(1, min(6, (int) ($styles['row_divider_width'] ?? 1))),
                'head_height' => max(24, min(120, (int) ($styles['head_height'] ?? 30))),
                'cell_color_rules' => $this->sanitize_cell_color_rules($styles['cell_color_rules'] ?? [], $columns),
            ],
        ];
    }

    private function resolve_column_label($column, $catalog) {
        $column = sanitize_key((string) $column);
        if ($column === '') {
            return '';
        }

        $global_labels = get_option('lcni_column_labels', []);
        if (is_array($global_labels) && isset($global_labels[$column])) {
            $label = sanitize_text_field((string) $global_labels[$column]);
            if ($label !== '') {
                return $label;
            }
        }

        if (isset(self::VIETNAMESE_COLUMN_LABELS[$column])) {
            return self::VIETNAMESE_COLUMN_LABELS[$column];
        }

        $fallback = isset($catalog[$column]['column']) ? (string) $catalog[$column]['column'] : $column;
        return ucwords(str_replace('_', ' ', $fallback));
    }

    private function resolve_stock_detail_base_url() {
        $stock_page_slug = sanitize_title((string) get_option('lcni_watchlist_stock_page', ''));
        if ($stock_page_slug === '') {
            $stock_page_id = absint(get_option('lcni_frontend_stock_detail_page', 0));
            if ($stock_page_id > 0) {
                $stock_page_slug = sanitize_title((string) get_post_field('post_name', $stock_page_id));
            }
        }
        if ($stock_page_slug === '') {
            $stock_page_slug = 'chi-tiet-co-phieu';
        }

        return home_url('/' . $stock_page_slug . '/');
    }

    private function build_stock_detail_url($base_url, $symbol) {
        if ($base_url === '' || $symbol === '' || preg_match('/^[A-Z0-9._-]{1,20}$/', $symbol) !== 1) {
            return '';
        }

        return add_query_arg('symbol', $symbol, $base_url);
    }

    private function format_signal_value($column, $value) {
        if ($value === null || $value === '') {
            return '';
        }

        $field = strpos((string) $column, '__') !== false ? substr((string) $column, strpos((string) $column, '__') + 2) : (string) $column;
        if (($field === 'entry_time' || $field === 'exit_time') && is_numeric($value)) {
            $format_settings = LCNI_Data_Format_Settings::get_settings();
            $event_time_format = (string) ($format_settings['date_formats']['event_time'] ?? 'DD-MM-YYYY');
            if ($event_time_format === 'number') {
                return number_format((float) $value, 2, '.', ',');
            }

            $timestamp = (int) $value;
            if ($timestamp > 0) {
                return wp_date('d-m-Y', $timestamp);
            }
        }

        if (is_numeric($value)) {
            $numeric = (float) $value;
            return (abs($numeric) >= 1000 || floor($numeric) != $numeric) ? number_format($numeric, 2, '.', ',') : (string) ((int) $numeric);
        }

        return (string) $value;
    }

    private function sanitize_cell_color_rules($rules, $columns) {
        if (!is_array($rules)) {
            return [];
        }

        $allowed_operators = ['=', '!=', '>', '>=', '<', '<=', 'contains', 'not_contains'];
        $allowed_columns = array_values(array_map('sanitize_key', is_array($columns) ? $columns : []));
        $sanitized = [];

        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                continue;
            }

            $column = sanitize_key((string) ($rule['column'] ?? ''));
            $operator = sanitize_text_field((string) ($rule['operator'] ?? ''));
            $value = trim(sanitize_text_field((string) ($rule['value'] ?? '')));
            $bg_color = sanitize_hex_color((string) ($rule['bg_color'] ?? ''));
            $text_color = sanitize_hex_color((string) ($rule['text_color'] ?? ''));

            if ($column === '' || !in_array($column, $allowed_columns, true) || !in_array($operator, $allowed_operators, true) || $value === '') {
                continue;
            }

            if (!$bg_color && !$text_color) {
                continue;
            }

            $sanitized[] = [
                'column' => $column,
                'operator' => $operator,
                'value' => $value,
                'bg_color' => $bg_color,
                'text_color' => $text_color,
            ];
        }

        return array_slice($sanitized, 0, 100);
    }

    private function resolve_recommend_signal_cell_style($column, $value, $styles) {
        $rules = isset($styles['cell_color_rules']) && is_array($styles['cell_color_rules']) ? $styles['cell_color_rules'] : [];

        foreach ($rules as $rule) {
            if (!is_array($rule) || (string) ($rule['column'] ?? '') !== (string) $column) {
                continue;
            }

            if (!$this->matches_cell_color_rule($value, (string) ($rule['operator'] ?? ''), $rule['value'] ?? null)) {
                continue;
            }

            return [
                'background' => (string) ($rule['bg_color'] ?? ''),
                'color' => (string) ($rule['text_color'] ?? ''),
            ];
        }

        return ['background' => '', 'color' => ''];
    }

    private function matches_cell_color_rule($actual_value, $operator, $expected_value) {
        $actual = is_scalar($actual_value) ? trim((string) $actual_value) : '';
        $expected = is_scalar($expected_value) ? trim((string) $expected_value) : '';

        $actual_number = is_numeric($actual) ? (float) $actual : null;
        $expected_number = is_numeric($expected) ? (float) $expected : null;
        $numeric_compare = $actual_number !== null && $expected_number !== null;

        if ($operator === '>') {
            return $numeric_compare && $actual_number > $expected_number;
        }
        if ($operator === '>=') {
            return $numeric_compare && $actual_number >= $expected_number;
        }
        if ($operator === '<') {
            return $numeric_compare && $actual_number < $expected_number;
        }
        if ($operator === '<=') {
            return $numeric_compare && $actual_number <= $expected_number;
        }
        if ($operator === '=') {
            return $numeric_compare ? $actual_number === $expected_number : strcasecmp($actual, $expected) === 0;
        }
        if ($operator === '!=') {
            return $numeric_compare ? $actual_number !== $expected_number : strcasecmp($actual, $expected) !== 0;
        }
        if ($operator === 'contains') {
            return $expected !== '' && stripos($actual, $expected) !== false;
        }
        if ($operator === 'not_contains') {
            return $expected !== '' && stripos($actual, $expected) === false;
        }

        return false;
    }

    public function render_performance($atts = []) {
        $atts = shortcode_atts(['rule_id' => 0], $atts, 'lcni_performance');
        $rows = $this->performance_calculator->list_performance((int) $atts['rule_id']);

        ob_start();
        echo '<table><thead><tr><th>Rule</th><th>Total</th><th>Win</th><th>Lose</th><th>Winrate</th><th>Avg R</th><th>Expectancy</th><th>Max R</th><th>Min R</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            echo '<tr>';
            echo '<td>' . esc_html((string) ($row['rule_name'] ?: ('Rule #' . $row['rule_id']))) . '</td>';
            echo '<td>' . esc_html((string) $row['total_trades']) . '</td>';
            echo '<td>' . esc_html((string) $row['win_trades']) . '</td>';
            echo '<td>' . esc_html((string) $row['lose_trades']) . '</td>';
            echo '<td>' . esc_html(number_format((float) $row['winrate'] * 100, 2)) . '%</td>';
            echo '<td>' . esc_html(number_format((float) $row['avg_r'], 2)) . '</td>';
            echo '<td>' . esc_html(number_format((float) $row['expectancy'], 2)) . '</td>';
            echo '<td>' . esc_html(number_format((float) $row['max_r'], 2)) . '</td>';
            echo '<td>' . esc_html(number_format((float) $row['min_r'], 2)) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';

        return ob_get_clean();
    }

    public function render_signal_card($atts = []) {
        $atts = shortcode_atts(['symbol' => ''], $atts, 'lcni_signal');
        $symbol = strtoupper(sanitize_text_field((string) $atts['symbol']));
        if ($symbol === '') {
            return '';
        }

        $signal = $this->signal_repository->find_open_signal_by_symbol($symbol);
        if (!$signal) {
            return '<p>Không có signal open cho mã này.</p>';
        }

        $action = $this->position_engine->action_for_state((string) $signal['position_state']);

        ob_start();
        echo '<div>';
        echo '<p><strong>Rule Name:</strong> ' . esc_html((string) $signal['rule_name']) . '</p>';
        echo '<p><strong>Entry price:</strong> ' . esc_html((string) $signal['entry_price']) . '</p>';
        echo '<p><strong>Current price:</strong> ' . esc_html((string) $signal['current_price']) . '</p>';
        echo '<p><strong>R multiple:</strong> ' . esc_html(number_format((float) $signal['r_multiple'], 2)) . '</p>';
        echo '<p><strong>Position state:</strong> ' . esc_html((string) $signal['position_state']) . '</p>';
        echo '<p><strong>Action:</strong> ' . esc_html($action) . '</p>';
        echo '</div>';

        return ob_get_clean();
    }
}
