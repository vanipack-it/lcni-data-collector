<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_Color_Format_Settings {

    const OPTION_KEY = 'lcni_color_format_rules';

    public static function get_defaults() {
        return [
            'enabled' => false,
            'rules' => [],
        ];
    }

    public static function get_settings() {
        return self::sanitize_settings(get_option(self::OPTION_KEY, self::get_defaults()));
    }

    public static function sanitize_settings($input) {
        $defaults = self::get_defaults();
        $value = is_array($input) ? $input : [];
        $rules = isset($value['rules']) && is_array($value['rules']) ? $value['rules'] : [];
        $known_columns = self::get_known_columns();
        $known_map = array_fill_keys($known_columns, true);
        $normalized_rules = [];

        foreach ($rules as $index => $rule) {
            if (!is_array($rule)) {
                continue;
            }

            $column = sanitize_key((string) ($rule['column'] ?? ''));
            if ($column === '' || !isset($known_map[$column])) {
                continue;
            }

            $type = in_array($rule['type'] ?? '', ['number', 'text'], true) ? $rule['type'] : 'number';
            $allowed_operators = $type === 'number'
                ? ['<', '>', '<=', '>=', '=', 'between']
                : ['contains', 'equals', 'starts_with', 'ends_with'];
            $operator = in_array($rule['operator'] ?? '', $allowed_operators, true) ? $rule['operator'] : $allowed_operators[0];
            $style_mode = in_array($rule['style_mode'] ?? '', ['flat', 'bar', 'gradient'], true) ? $rule['style_mode'] : 'flat';

            $id = sanitize_key((string) ($rule['id'] ?? ''));
            if ($id === '') {
                $id = 'rule_' . ($index + 1);
            }

            $raw_value = isset($rule['value']) ? wp_unslash($rule['value']) : '';
            $normalized_value = $type === 'number' ? self::sanitize_numeric_value($raw_value) : sanitize_text_field((string) $raw_value);
            if ($operator === 'between') {
                $between = is_array($rule['value']) ? $rule['value'] : [];
                $min = self::sanitize_numeric_value($between[0] ?? null);
                $max = self::sanitize_numeric_value($between[1] ?? null);
                if ($min === null || $max === null) {
                    continue;
                }
                $normalized_value = [$min, $max];
            } elseif ($normalized_value === null || $normalized_value === '') {
                continue;
            }

            $background_color = sanitize_hex_color((string) ($rule['background_color'] ?? '')) ?: '#e6f4ea';
            $text_color = sanitize_hex_color((string) ($rule['text_color'] ?? '')) ?: '#1e7e34';
            $bar_color = sanitize_hex_color((string) ($rule['bar_color'] ?? '')) ?: '#28a745';
            $gradient_start = sanitize_hex_color((string) ($rule['gradient_start_color'] ?? '')) ?: '#f8d7da';
            $gradient_end = sanitize_hex_color((string) ($rule['gradient_end_color'] ?? '')) ?: '#d1e7dd';
            $gradient_min = self::sanitize_numeric_value($rule['gradient_min'] ?? null);
            $gradient_max = self::sanitize_numeric_value($rule['gradient_max'] ?? null);
            $show_overlay = !empty($rule['show_value_overlay']);

            $normalized_rules[] = [
                'id' => $id,
                'column' => $column,
                'type' => $type,
                'operator' => $operator,
                'value' => $normalized_value,
                'style_mode' => $style_mode,
                'background_color' => $background_color,
                'text_color' => $text_color,
                'bar_color' => $bar_color,
                'show_value_overlay' => $show_overlay,
                'gradient_min' => $gradient_min !== null ? $gradient_min : -10,
                'gradient_max' => $gradient_max !== null ? $gradient_max : 10,
                'gradient_start_color' => $gradient_start,
                'gradient_end_color' => $gradient_end,
            ];
        }

        return [
            'enabled' => !empty($value['enabled']),
            'rules' => array_slice($normalized_rules, 0, 500),
        ];
    }

    public static function get_known_columns() {
        $columns = [
            'symbol', 'exchange', 'pct_t_1', 'pct_t_3', 'pct_1w', 'pct_1m', 'pct_3m', 'pct_6m', 'pct_1y',
            'volume', 'vol', 'close', 'open', 'high', 'low', 'pe_ratio', 'pb_ratio', 'roe', 'eps', 'rsi_14',
            'rs_1w_by_exchange', 'rs_1m_by_exchange', 'rs_3m_by_exchange',
        ];

        if (class_exists('LCNI_WatchlistService') && class_exists('LCNI_WatchlistRepository')) {
            $service = new LCNI_WatchlistService(new LCNI_WatchlistRepository());
            $columns = array_merge($columns, $service->get_all_columns());
        }

        if (class_exists('LCNI_FilterAdmin')) {
            $columns = array_merge($columns, LCNI_FilterAdmin::available_columns());
        }

        $columns = array_values(array_unique(array_filter(array_map('sanitize_key', $columns))));

        return $columns;
    }

    public static function build_flat_css($settings) {
        $settings = self::sanitize_settings($settings);
        if (empty($settings['enabled']) || empty($settings['rules']) || !is_array($settings['rules'])) {
            return '';
        }

        $css = '';
        foreach ($settings['rules'] as $rule) {
            if (($rule['style_mode'] ?? '') !== 'flat') {
                continue;
            }

            $rule_id = sanitize_html_class((string) ($rule['id'] ?? ''));
            if ($rule_id === '') {
                continue;
            }

            $background = sanitize_hex_color((string) ($rule['background_color'] ?? ''));
            $text = sanitize_hex_color((string) ($rule['text_color'] ?? ''));
            if (!$background || !$text) {
                continue;
            }

            $css .= '.lcni-rule-' . $rule_id . '{background-color:' . $background . ';color:' . $text . ';}';
            $css .= '.lcni-rule-' . $rule_id . ' a{color:inherit;}';
        }

        return $css;
    }

    private static function sanitize_numeric_value($value) {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    public static function render_frontend_form($module, $tab_id) {
        $settings = self::get_settings();
        $columns = self::get_known_columns();
        ?>
        <div id="<?php echo esc_attr($tab_id); ?>" class="lcni-sub-tab-content">
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="lcni-front-form">
                <?php wp_nonce_field('lcni_frontend_settings_save', 'lcni_frontend_settings_nonce'); ?>
                <input type="hidden" name="action" value="lcni_save_frontend_settings">
                <input type="hidden" name="module" value="<?php echo esc_attr($module); ?>">
                <input type="hidden" name="lcni_redirect_tab" value="<?php echo esc_attr($tab_id); ?>">

                <h3>Enable Engine</h3>
                <p>
                    <label>
                        <input type="checkbox" name="lcni_color_format_rules[enabled]" value="1" <?php checked(!empty($settings['enabled'])); ?>>
                        Enable Color Format Engine
                    </label>
                </p>

                <h3>Rule Builder</h3>
                <table class="widefat striped" id="lcni-color-rule-table">
                    <thead>
                        <tr>
                            <th>Column</th><th>Type</th><th>Operator</th><th>Value</th><th>Style Mode</th><th>Style Config</th><th></th>
                        </tr>
                    </thead>
                    <tbody id="lcni-color-rule-rows"></tbody>
                </table>
                <p><button type="button" class="button" id="lcni-add-color-rule">+ Add Rule</button></p>

                <?php submit_button('Save Color Format Rules'); ?>
            </form>

            <script>
                (function () {
                    const columns = <?php echo wp_json_encode($columns); ?>;
                    const existing = <?php echo wp_json_encode($settings['rules']); ?> || [];
                    const rows = document.getElementById('lcni-color-rule-rows');
                    const addButton = document.getElementById('lcni-add-color-rule');

                    function operatorOptions(type, selected) {
                        const map = {
                            number: ['<', '>', '<=', '>=', '=', 'between'],
                            text: ['contains', 'equals', 'starts_with', 'ends_with']
                        };
                        return (map[type] || map.number).map((operator) => `<option value="${operator}" ${operator === selected ? 'selected' : ''}>${operator}</option>`).join('');
                    }

                    function renderStyleConfig(rule, index) {
                        const mode = rule.style_mode || 'flat';
                        if (mode === 'bar') {
                            return `<label>Bar Color <input type="color" name="lcni_color_format_rules[rules][${index}][bar_color]" value="${rule.bar_color || '#28a745'}"></label><br><label><input type="checkbox" name="lcni_color_format_rules[rules][${index}][show_value_overlay]" value="1" ${rule.show_value_overlay ? 'checked' : ''}> Overlay value</label>`;
                        }
                        if (mode === 'gradient') {
                            return `<label>Min <input type="number" step="any" name="lcni_color_format_rules[rules][${index}][gradient_min]" value="${rule.gradient_min ?? -10}"></label> <label>Max <input type="number" step="any" name="lcni_color_format_rules[rules][${index}][gradient_max]" value="${rule.gradient_max ?? 10}"></label><br><label>Start <input type="color" name="lcni_color_format_rules[rules][${index}][gradient_start_color]" value="${rule.gradient_start_color || '#f8d7da'}"></label> <label>End <input type="color" name="lcni_color_format_rules[rules][${index}][gradient_end_color]" value="${rule.gradient_end_color || '#d1e7dd'}"></label>`;
                        }
                        return `<label>BG <input type="color" name="lcni_color_format_rules[rules][${index}][background_color]" value="${rule.background_color || '#e6f4ea'}"></label> <label>Text <input type="color" name="lcni_color_format_rules[rules][${index}][text_color]" value="${rule.text_color || '#1e7e34'}"></label>`;
                    }

                    function renderValue(rule, index) {
                        if ((rule.operator || '=') === 'between') {
                            const range = Array.isArray(rule.value) ? rule.value : ['', ''];
                            return `<input type="number" step="any" name="lcni_color_format_rules[rules][${index}][value][]" value="${range[0] ?? ''}" placeholder="Min"> <input type="number" step="any" name="lcni_color_format_rules[rules][${index}][value][]" value="${range[1] ?? ''}" placeholder="Max">`;
                        }
                        if ((rule.type || 'number') === 'number') {
                            return `<input type="number" step="any" name="lcni_color_format_rules[rules][${index}][value]" value="${rule.value ?? ''}">`;
                        }
                        return `<input type="text" name="lcni_color_format_rules[rules][${index}][value]" value="${rule.value ?? ''}">`;
                    }

                    function renderRow(rule, index) {
                        const safe = Object.assign({ id: `rule_${Date.now()}_${index}`, column: '', type: 'number', operator: '=', value: '', style_mode: 'flat' }, rule || {});
                        const row = document.createElement('tr');
                        row.innerHTML = `<td><input type="hidden" name="lcni_color_format_rules[rules][${index}][id]" value="${safe.id}"><select name="lcni_color_format_rules[rules][${index}][column]">${columns.map((column) => `<option value="${column}" ${column === safe.column ? 'selected' : ''}>${column}</option>`).join('')}</select></td><td><select data-type name="lcni_color_format_rules[rules][${index}][type]"><option value="number" ${safe.type === 'number' ? 'selected' : ''}>number</option><option value="text" ${safe.type === 'text' ? 'selected' : ''}>text</option></select></td><td><select data-operator name="lcni_color_format_rules[rules][${index}][operator]">${operatorOptions(safe.type, safe.operator)}</select></td><td data-value>${renderValue(safe, index)}</td><td><select data-style-mode name="lcni_color_format_rules[rules][${index}][style_mode]"><option value="flat" ${safe.style_mode === 'flat' ? 'selected' : ''}>Flat color</option><option value="bar" ${safe.style_mode === 'bar' ? 'selected' : ''}>Bar visualization</option><option value="gradient" ${safe.style_mode === 'gradient' ? 'selected' : ''}>Gradient color</option></select></td><td data-style-config>${renderStyleConfig(safe, index)}</td><td><button type="button" class="button-link-delete" data-remove-rule>Remove</button></td>`;

                        const typeSelect = row.querySelector('[data-type]');
                        const operatorSelect = row.querySelector('[data-operator]');
                        const valueCell = row.querySelector('[data-value]');
                        const modeSelect = row.querySelector('[data-style-mode]');
                        const styleCell = row.querySelector('[data-style-config]');

                        typeSelect.addEventListener('change', () => {
                            operatorSelect.innerHTML = operatorOptions(typeSelect.value, operatorSelect.value);
                            safe.type = typeSelect.value;
                            safe.operator = operatorSelect.value;
                            valueCell.innerHTML = renderValue(safe, index);
                        });

                        operatorSelect.addEventListener('change', () => {
                            safe.operator = operatorSelect.value;
                            valueCell.innerHTML = renderValue(safe, index);
                        });

                        modeSelect.addEventListener('change', () => {
                            safe.style_mode = modeSelect.value;
                            styleCell.innerHTML = renderStyleConfig(safe, index);
                        });

                        row.querySelector('[data-remove-rule]').addEventListener('click', () => row.remove());
                        return row;
                    }

                    function addRow(rule) {
                        if (!rows) return;
                        const index = rows.querySelectorAll('tr').length;
                        rows.appendChild(renderRow(rule || {}, index));
                    }

                    if (addButton) {
                        addButton.addEventListener('click', () => addRow());
                    }

                    (existing.length ? existing : [{}]).forEach((rule) => addRow(rule));
                })();
            </script>
        </div>
        <?php
    }
}
