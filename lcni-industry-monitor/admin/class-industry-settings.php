<?php

if (! defined('ABSPATH')) {
    exit;
}

class LCNI_Industry_Settings
{
    const OPTION_KEY = 'lcni_industry_monitor_settings';

    /** @return array<string,mixed> */
    public static function get_settings()
    {
        $defaults = self::get_defaults();
        $saved = get_option(self::OPTION_KEY, array());
        if (! is_array($saved)) {
            $saved = array();
        }

        $settings = wp_parse_args($saved, $defaults);
        $settings['enabled_metrics'] = array_values(array_intersect(array_keys(self::get_metric_labels()), (array) $settings['enabled_metrics']));

        if (empty($settings['enabled_metrics'])) {
            $settings['enabled_metrics'] = $defaults['enabled_metrics'];
        }

        return $settings;
    }

    /** @return array<string,mixed> */
    public static function get_defaults()
    {
        return array(
            'enabled_metrics' => array_keys(self::get_metric_labels()),
            'row_bg_color' => '#ffffff',
            'row_border_color' => '#e2e2e2',
            'row_border_width' => 1,
            'table_border_color' => '#d6d6d6',
            'table_border_width' => 1,
            'row_height' => 40,
            'header_bg_color' => '#f7f7f7',
            'header_height' => 44,
            'row_font_size' => 14,
            'event_time_col_width' => 140,
            'row_hover_enabled' => 1,
            'industry_filter_url' => home_url('/'),
            'default_session_limit' => 30,
            'cell_rules' => array(),
        );
    }

    /** @return array<string,string> */
    public static function get_metric_labels()
    {
        $labels = array(
            'money_flow_share' => __('Money Flow Share', 'lcni-industry-monitor'),
            'momentum' => __('Momentum', 'lcni-industry-monitor'),
            'relative_strength' => __('Relative Strength', 'lcni-industry-monitor'),
            'breadth' => __('Breadth', 'lcni-industry-monitor'),
            'industry_index' => __('Industry Index', 'lcni-industry-monitor'),
            'industry_return' => __('Industry Return', 'lcni-industry-monitor'),
            'industry_volume' => __('Industry Volume', 'lcni-industry-monitor'),
        );

        return apply_filters('lcni_industry_monitor_metric_labels', $labels);
    }

    public function register_hooks()
    {
        add_action('admin_menu', array($this, 'register_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_filter('lcni_industry_monitor_metric_labels', array($this, 'extend_metric_labels_from_data'));
    }

    /**
     * @param array<string,string> $labels
     * @return array<string,string>
     */
    public function extend_metric_labels_from_data($labels)
    {
        $data = new LCNI_Industry_Data();
        foreach ($data->get_supported_metrics() as $metric_key) {
            if (isset($labels[$metric_key])) {
                continue;
            }
            $labels[$metric_key] = ucwords(str_replace('_', ' ', (string) $metric_key));
        }

        return $labels;
    }

    public function register_menu()
    {
        add_submenu_page(
            'lcni-settings',
            __('LCNI Industry Monitor', 'lcni-industry-monitor'),
            __('Industry Monitor', 'lcni-industry-monitor'),
            'manage_options',
            'lcni-industry-monitor',
            array($this, 'render_page')
        );
    }

    public function register_settings()
    {
        register_setting(
            'lcni_industry_monitor_settings_group',
            self::OPTION_KEY,
            array($this, 'sanitize_settings')
        );
    }

    /** @param mixed $input */
    public function sanitize_settings($input)
    {
        $defaults = self::get_defaults();
        $input = is_array($input) ? $input : array();

        $enabled_metrics = array_values(array_intersect(array_keys(self::get_metric_labels()), (array) ($input['enabled_metrics'] ?? array())));
        if (empty($enabled_metrics)) {
            $enabled_metrics = $defaults['enabled_metrics'];
        }

        return array(
            'enabled_metrics' => $enabled_metrics,
            'row_bg_color' => sanitize_hex_color($input['row_bg_color'] ?? $defaults['row_bg_color']) ?: $defaults['row_bg_color'],
            'row_border_color' => sanitize_hex_color($input['row_border_color'] ?? $defaults['row_border_color']) ?: $defaults['row_border_color'],
            'row_border_width' => max(0, min(8, absint($input['row_border_width'] ?? $defaults['row_border_width']))),
            'table_border_color' => sanitize_hex_color($input['table_border_color'] ?? $defaults['table_border_color']) ?: $defaults['table_border_color'],
            'table_border_width' => max(0, min(8, absint($input['table_border_width'] ?? $defaults['table_border_width']))),
            'row_height' => max(24, min(120, absint($input['row_height'] ?? $defaults['row_height']))),
            'header_bg_color' => sanitize_hex_color($input['header_bg_color'] ?? $defaults['header_bg_color']) ?: $defaults['header_bg_color'],
            'header_height' => max(24, min(120, absint($input['header_height'] ?? $defaults['header_height']))),
            'row_font_size' => max(10, min(24, absint($input['row_font_size'] ?? $defaults['row_font_size']))),
            'event_time_col_width' => max(72, min(360, absint($input['event_time_col_width'] ?? $defaults['event_time_col_width']))),
            'row_hover_enabled' => ! empty($input['row_hover_enabled']) ? 1 : 0,
            'industry_filter_url' => esc_url_raw($input['industry_filter_url'] ?? $defaults['industry_filter_url']),
            'default_session_limit' => max(1, min(200, absint($input['default_session_limit'] ?? $defaults['default_session_limit']))),
            'cell_rules' => $this->sanitize_cell_rules($input['cell_rules'] ?? array(), $enabled_metrics),
        );
    }

    /**
     * @param mixed $rules
     * @param string[] $allowed_fields
     * @return array<int,array{field:string,operator:string,value:float,bg_color:string,text_color:string}>
     */
    private function sanitize_cell_rules($rules, $allowed_fields)
    {
        $rules = is_array($rules) ? array_values($rules) : array();
        $normalized = array();

        foreach ($rules as $rule) {
            if (! is_array($rule)) {
                continue;
            }

            $field = sanitize_key($rule['field'] ?? '');
            $operator = (string) ($rule['operator'] ?? '=');
            if (! in_array($field, $allowed_fields, true) || ! in_array($operator, array('>', '=', '<'), true)) {
                continue;
            }

            $value = is_numeric($rule['value'] ?? null) ? (float) $rule['value'] : null;
            if ($value === null) {
                continue;
            }

            $bg_color = sanitize_hex_color($rule['bg_color'] ?? '#ffffff') ?: '#ffffff';
            $text_color = sanitize_hex_color($rule['text_color'] ?? '#111111') ?: '#111111';

            $normalized[] = array(
                'field' => $field,
                'operator' => $operator,
                'value' => $value,
                'bg_color' => $bg_color,
                'text_color' => $text_color,
            );
        }

        return $normalized;
    }

    public function render_page()
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $settings = self::get_settings();
        $metric_labels = self::get_metric_labels();
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('LCNI Industry Monitor', 'lcni-industry-monitor'); ?></h1>
            <p><?php echo esc_html__('Use shortcode [lcni_industry_monitor] to display monitor table.', 'lcni-industry-monitor'); ?></p>

            <form method="post" action="options.php">
                <?php settings_fields('lcni_industry_monitor_settings_group'); ?>

                <h2><?php echo esc_html__('Display Columns', 'lcni-industry-monitor'); ?></h2>
                <p><?php echo esc_html__('Choose metrics (columns) available on frontend selector.', 'lcni-industry-monitor'); ?></p>
                <?php foreach ($metric_labels as $metric_key => $label) : ?>
                    <label style="display:block;margin-bottom:6px;">
                        <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[enabled_metrics][]" value="<?php echo esc_attr($metric_key); ?>" <?php checked(in_array($metric_key, (array) $settings['enabled_metrics'], true)); ?> />
                        <?php echo esc_html($label); ?>
                    </label>
                <?php endforeach; ?>

                <h2><?php echo esc_html__('Table Styles', 'lcni-industry-monitor'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr><th><label><?php echo esc_html__('Row background', 'lcni-industry-monitor'); ?></label></th><td><input type="color" name="<?php echo esc_attr(self::OPTION_KEY); ?>[row_bg_color]" value="<?php echo esc_attr($settings['row_bg_color']); ?>" /></td></tr>
                    <tr><th><label><?php echo esc_html__('Row divider color', 'lcni-industry-monitor'); ?></label></th><td><input type="color" name="<?php echo esc_attr(self::OPTION_KEY); ?>[row_border_color]" value="<?php echo esc_attr($settings['row_border_color']); ?>" /></td></tr>
                    <tr><th><label><?php echo esc_html__('Row divider thickness (px)', 'lcni-industry-monitor'); ?></label></th><td><input type="number" min="0" max="8" name="<?php echo esc_attr(self::OPTION_KEY); ?>[row_border_width]" value="<?php echo esc_attr((string) $settings['row_border_width']); ?>" /></td></tr>
                    <tr><th><label><?php echo esc_html__('Frame border color', 'lcni-industry-monitor'); ?></label></th><td><input type="color" name="<?php echo esc_attr(self::OPTION_KEY); ?>[table_border_color]" value="<?php echo esc_attr($settings['table_border_color']); ?>" /></td></tr>
                    <tr><th><label><?php echo esc_html__('Frame border thickness (px)', 'lcni-industry-monitor'); ?></label></th><td><input type="number" min="0" max="8" name="<?php echo esc_attr(self::OPTION_KEY); ?>[table_border_width]" value="<?php echo esc_attr((string) $settings['table_border_width']); ?>" /></td></tr>
                    <tr><th><label><?php echo esc_html__('Row height (px)', 'lcni-industry-monitor'); ?></label></th><td><input type="number" min="24" max="120" name="<?php echo esc_attr(self::OPTION_KEY); ?>[row_height]" value="<?php echo esc_attr((string) $settings['row_height']); ?>" /></td></tr>
                    <tr><th><label><?php echo esc_html__('Row font size (px)', 'lcni-industry-monitor'); ?></label></th><td><input type="number" min="10" max="24" name="<?php echo esc_attr(self::OPTION_KEY); ?>[row_font_size]" value="<?php echo esc_attr((string) $settings['row_font_size']); ?>" /></td></tr>
                    <tr><th><label><?php echo esc_html__('Header background', 'lcni-industry-monitor'); ?></label></th><td><input type="color" name="<?php echo esc_attr(self::OPTION_KEY); ?>[header_bg_color]" value="<?php echo esc_attr($settings['header_bg_color']); ?>" /></td></tr>
                    <tr><th><label><?php echo esc_html__('Header height (px)', 'lcni-industry-monitor'); ?></label></th><td><input type="number" min="24" max="120" name="<?php echo esc_attr(self::OPTION_KEY); ?>[header_height]" value="<?php echo esc_attr((string) $settings['header_height']); ?>" /></td></tr>
                    <tr><th><label><?php echo esc_html__('Event time column width (px)', 'lcni-industry-monitor'); ?></label></th><td><input type="number" min="72" max="360" name="<?php echo esc_attr(self::OPTION_KEY); ?>[event_time_col_width]" value="<?php echo esc_attr((string) $settings['event_time_col_width']); ?>" /></td></tr>
                    <tr><th><label><?php echo esc_html__('Enable row hover effect', 'lcni-industry-monitor'); ?></label></th><td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[row_hover_enabled]" value="1" <?php checked(! empty($settings['row_hover_enabled'])); ?> /> <?php echo esc_html__('Enable hover + pointer on row', 'lcni-industry-monitor'); ?></label></td></tr>
                    <tr><th><label><?php echo esc_html__('Industry filter base URL', 'lcni-industry-monitor'); ?></label></th><td><input type="url" class="regular-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[industry_filter_url]" value="<?php echo esc_attr((string) $settings['industry_filter_url']); ?>" /><p class="description"><?php echo esc_html__('On row click, system appends ?apply_filter=1&name_icb2={Industry}.', 'lcni-industry-monitor'); ?></p></td></tr>
                    <tr><th><label><?php echo esc_html__('Default sessions to display', 'lcni-industry-monitor'); ?></label></th><td><input type="number" min="1" max="200" name="<?php echo esc_attr(self::OPTION_KEY); ?>[default_session_limit]" value="<?php echo esc_attr((string) $settings['default_session_limit']); ?>" /></td></tr>
                </table>

                <h2><?php echo esc_html__('Cell rules', 'lcni-industry-monitor'); ?></h2>
                <p><?php echo esc_html__('Apply conditional styles on metric cells.', 'lcni-industry-monitor'); ?></p>
                <table class="widefat" id="lcni-cell-rules-table" style="max-width:1000px;">
                    <thead>
                        <tr>
                            <th><?php echo esc_html__('Field', 'lcni-industry-monitor'); ?></th>
                            <th><?php echo esc_html__('Operator', 'lcni-industry-monitor'); ?></th>
                            <th><?php echo esc_html__('Compare value', 'lcni-industry-monitor'); ?></th>
                            <th><?php echo esc_html__('Background color', 'lcni-industry-monitor'); ?></th>
                            <th><?php echo esc_html__('Text color', 'lcni-industry-monitor'); ?></th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="lcni-cell-rules-body">
                        <?php foreach ((array) ($settings['cell_rules'] ?? array()) as $index => $rule) : ?>
                            <?php $this->render_rule_row((int) $index, (array) $rule, $metric_labels); ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p><button type="button" class="button" id="lcni-add-cell-rule"><?php echo esc_html__('+ Add rule', 'lcni-industry-monitor'); ?></button></p>

                <template id="lcni-cell-rule-template">
                    <?php $this->render_rule_row('__INDEX__', array('field' => '', 'operator' => '>', 'value' => '', 'bg_color' => '#ffffff', 'text_color' => '#111111'), $metric_labels); ?>
                </template>

                <?php submit_button(); ?>
            </form>
        </div>
        <script>
            (function () {
                var body = document.getElementById('lcni-cell-rules-body');
                var addBtn = document.getElementById('lcni-add-cell-rule');
                var template = document.getElementById('lcni-cell-rule-template');
                if (!body || !addBtn || !template) return;

                function bindRemoveActions() {
                    body.querySelectorAll('.lcni-remove-rule').forEach(function (btn) {
                        btn.onclick = function () {
                            var row = btn.closest('tr');
                            if (row) row.remove();
                        };
                    });
                }

                addBtn.addEventListener('click', function () {
                    var index = body.querySelectorAll('tr').length;
                    var html = template.innerHTML.replace(/__INDEX__/g, String(index));
                    body.insertAdjacentHTML('beforeend', html);
                    bindRemoveActions();
                });

                bindRemoveActions();
            })();
        </script>
        <?php
    }

    /**
     * @param int|string $index
     * @param array<string,mixed> $rule
     * @param array<string,string> $metric_labels
     */
    private function render_rule_row($index, $rule, $metric_labels)
    {
        ?>
        <tr>
            <td>
                <select name="<?php echo esc_attr(self::OPTION_KEY); ?>[cell_rules][<?php echo esc_attr((string) $index); ?>][field]">
                    <option value=""><?php echo esc_html__('Select field', 'lcni-industry-monitor'); ?></option>
                    <?php foreach ($metric_labels as $metric_key => $label) : ?>
                        <option value="<?php echo esc_attr($metric_key); ?>" <?php selected((string) ($rule['field'] ?? ''), $metric_key); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </td>
            <td>
                <select name="<?php echo esc_attr(self::OPTION_KEY); ?>[cell_rules][<?php echo esc_attr((string) $index); ?>][operator]">
                    <option value=">" <?php selected((string) ($rule['operator'] ?? ''), '>'); ?>>&gt;</option>
                    <option value="=" <?php selected((string) ($rule['operator'] ?? ''), '='); ?>>=</option>
                    <option value="<" <?php selected((string) ($rule['operator'] ?? ''), '<'); ?>>&lt;</option>
                </select>
            </td>
            <td><input type="number" step="any" name="<?php echo esc_attr(self::OPTION_KEY); ?>[cell_rules][<?php echo esc_attr((string) $index); ?>][value]" value="<?php echo esc_attr((string) ($rule['value'] ?? '')); ?>" /></td>
            <td><input type="color" name="<?php echo esc_attr(self::OPTION_KEY); ?>[cell_rules][<?php echo esc_attr((string) $index); ?>][bg_color]" value="<?php echo esc_attr((string) ($rule['bg_color'] ?? '#ffffff')); ?>" /></td>
            <td><input type="color" name="<?php echo esc_attr(self::OPTION_KEY); ?>[cell_rules][<?php echo esc_attr((string) $index); ?>][text_color]" value="<?php echo esc_attr((string) ($rule['text_color'] ?? '#111111')); ?>" /></td>
            <td><button type="button" class="button-link-delete lcni-remove-rule">×</button></td>
        </tr>
        <?php
    }
}
