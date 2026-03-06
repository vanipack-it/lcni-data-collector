<?php

if (!defined('ABSPATH')) {
    exit;
}

class ShortcodeManager {
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
        $atts = shortcode_atts(['rule_id' => 0, 'status' => '', 'limit' => 20, 'symbol' => ''], $atts, 'lcni_signals');
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
        $wrapper_style = sprintf('font-family:%s;color:%s;background:%s;border:%s;border-radius:%dpx;overflow:auto;',
            esc_attr((string) ($styles['font'] ?? 'inherit')),
            esc_attr((string) ($styles['text_color'] ?? '#111827')),
            esc_attr((string) ($styles['background'] ?? '#ffffff')),
            esc_attr((string) ($styles['border'] ?? '1px solid #e5e7eb')),
            (int) ($styles['border_radius'] ?? 8)
        );

        ob_start();
        echo '<div class="lcni-recommend-signals-table" style="' . $wrapper_style . '">';
        echo '<table style="width:100%;border-collapse:collapse;font-size:' . (int) ($styles['row_font_size'] ?? 14) . 'px;">';
        echo '<thead><tr style="height:' . (int) ($styles['head_height'] ?? 30) . 'px;background:' . esc_attr((string) ($styles['header_background'] ?? '#ffffff')) . ';color:' . esc_attr((string) ($styles['header_text_color'] ?? '#111827')) . ';">';
        foreach ($columns as $column) {
            $label = isset($catalog[$column]) ? (ucwords(str_replace('_', ' ', (string) $catalog[$column]['column']))) : $column;
            echo '<th style="text-align:left;padding:8px;border-bottom:' . (int) ($styles['row_divider_width'] ?? 1) . 'px solid ' . esc_attr((string) ($styles['row_divider_color'] ?? '#e5e7eb')) . ';font-size:' . (int) ($styles['header_font_size'] ?? 14) . 'px;">' . esc_html($label) . '</th>';
        }
        echo '</tr></thead><tbody>';

        foreach ($rows as $row) {
            echo '<tr style="background:' . esc_attr((string) ($styles['value_background'] ?? '#ffffff')) . ';color:' . esc_attr((string) ($styles['value_text_color'] ?? '#111827')) . ';">';
            foreach ($columns as $column) {
                $value = isset($row[$column]) ? $row[$column] : '';
                if (is_numeric($value)) {
                    $value = (float) $value;
                    $value = (abs($value) >= 1000 || floor($value) != $value) ? number_format($value, 2, '.', ',') : (string) ((int) $value);
                }
                echo '<td style="padding:8px;border-bottom:' . (int) ($styles['row_divider_width'] ?? 1) . 'px solid ' . esc_attr((string) ($styles['row_divider_color'] ?? '#e5e7eb')) . ';">' . esc_html((string) $value) . '</td>';
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
            ],
        ];
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
