<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_FilterAdmin {

    public static function sanitize_columns($columns) {
        $service = new LCNI_WatchlistService(new LCNI_WatchlistRepository());
        $all = $service->get_all_columns();
        $columns = is_array($columns) ? array_map('sanitize_key', $columns) : [];

        return array_values(array_intersect($all, $columns));
    }

    public static function sanitize_style($input) {
        $input = is_array($input) ? $input : [];

        $rules = $input['conditional_value_colors'] ?? '[]';
        if (is_array($rules)) {
            $rules = wp_json_encode($rules);
        }

        return [
            'inherit_style' => !empty($input['inherit_style']),
            'font_size' => max(10, min(24, (int) ($input['font_size'] ?? 13))),
            'text_color' => sanitize_hex_color((string) ($input['text_color'] ?? '')) ?: '',
            'background_color' => sanitize_hex_color((string) ($input['background_color'] ?? '')) ?: '',
            'border_color' => sanitize_hex_color((string) ($input['border_color'] ?? '')) ?: '',
            'border_width' => is_numeric($input['border_width'] ?? '') ? max(0, min(6, (int) $input['border_width'])) : '',
            'border_radius' => is_numeric($input['border_radius'] ?? '') ? max(0, min(30, (int) $input['border_radius'])) : '',
            'header_label_font_size' => is_numeric($input['header_label_font_size'] ?? '') ? max(10, min(30, (int) $input['header_label_font_size'])) : '',
            'row_font_size' => is_numeric($input['row_font_size'] ?? '') ? max(10, min(30, (int) $input['row_font_size'])) : '',
            'row_height' => max(24, min(64, (int) ($input['row_height'] ?? 36))),
            'conditional_value_colors' => is_string($rules) ? $rules : '[]',
        ];
    }

    public static function sanitize_default_filter_values($json) {
        if (!is_string($json)) {
            return '';
        }

        $json = trim(wp_unslash($json));
        if ($json === '') {
            return '';
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return '';
        }

        $encoded = wp_json_encode($decoded);

        return is_string($encoded) ? $encoded : '{"filters":[]}';
    }

    public static function render_filter_form($tab_id) {
        $service = new LCNI_WatchlistService(new LCNI_WatchlistRepository());
        $all_columns = $service->get_all_columns();
        $criteria = self::sanitize_columns(get_option('lcni_filter_criteria_columns', []));
        $table_columns = self::sanitize_columns(get_option('lcni_filter_table_columns', []));
        $style = self::sanitize_style(get_option('lcni_filter_style_config', get_option('lcni_filter_style', [])));
        $default_filter_values = (string) get_option('lcni_filter_default_values', '');
        ?>
        <div id="<?php echo esc_attr($tab_id); ?>" class="lcni-sub-tab-content">
            <div class="lcni-sub-tab-nav" id="lcni-filter-sub-tabs">
                <button type="button" data-filter-sub-tab="criteria">Filter Criteria</button>
                <button type="button" data-filter-sub-tab="table_columns">Table Columns</button>
                <button type="button" data-filter-sub-tab="style">Style</button>
                <button type="button" data-filter-sub-tab="default_values">Default Values</button>
            </div>

            <div data-filter-sub-pane="criteria">
                <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=lcni-settings')); ?>" class="lcni-front-form">
                    <?php wp_nonce_field('lcni_admin_actions', 'lcni_action_nonce'); ?>
                    <input type="hidden" name="lcni_admin_action" value="save_frontend_settings">
                    <input type="hidden" name="lcni_frontend_module" value="filter">
                    <input type="hidden" name="lcni_filter_section" value="criteria">
                    <input type="hidden" name="lcni_redirect_tab" value="<?php echo esc_attr($tab_id); ?>">
                    <h3>Filter Criteria</h3>
                    <div class="lcni-front-grid">
                        <?php foreach ($all_columns as $column) : ?>
                            <label><input type="checkbox" name="lcni_filter_criteria_columns[]" value="<?php echo esc_attr($column); ?>" <?php checked(in_array($column, $criteria, true)); ?>> <?php echo esc_html($column); ?></label>
                        <?php endforeach; ?>
                    </div>
                    <?php submit_button('Save'); ?>
                </form>
            </div>

            <div data-filter-sub-pane="table_columns" style="display:none">
                <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=lcni-settings')); ?>" class="lcni-front-form">
                    <?php wp_nonce_field('lcni_admin_actions', 'lcni_action_nonce'); ?>
                    <input type="hidden" name="lcni_admin_action" value="save_frontend_settings">
                    <input type="hidden" name="lcni_frontend_module" value="filter">
                    <input type="hidden" name="lcni_filter_section" value="table_columns">
                    <input type="hidden" name="lcni_redirect_tab" value="<?php echo esc_attr($tab_id); ?>">
                    <h3>Table Columns</h3>
                    <div class="lcni-front-grid">
                        <?php foreach ($all_columns as $column) : ?>
                            <label><input type="checkbox" name="lcni_filter_table_columns[]" value="<?php echo esc_attr($column); ?>" <?php checked(in_array($column, $table_columns, true)); ?>> <?php echo esc_html($column); ?></label>
                        <?php endforeach; ?>
                    </div>
                    <?php submit_button('Save'); ?>
                </form>
            </div>

            <div data-filter-sub-pane="style" style="display:none">
                <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=lcni-settings')); ?>" class="lcni-front-form">
                    <?php wp_nonce_field('lcni_admin_actions', 'lcni_action_nonce'); ?>
                    <input type="hidden" name="lcni_admin_action" value="save_frontend_settings">
                    <input type="hidden" name="lcni_frontend_module" value="filter">
                    <input type="hidden" name="lcni_filter_section" value="style">
                    <input type="hidden" name="lcni_redirect_tab" value="<?php echo esc_attr($tab_id); ?>">
                    <h3>Style</h3>
                    <p><label><input type="checkbox" name="lcni_filter_style_config[inherit_style]" value="1" <?php checked(!empty($style['inherit_style'])); ?>> Inherit global style</label></p>
                    <p><label>Font size <input type="number" name="lcni_filter_style_config[font_size]" value="<?php echo esc_attr((string) $style['font_size']); ?>"></label></p>
                    <p><label>Text color <input type="color" name="lcni_filter_style_config[text_color]" value="<?php echo esc_attr((string) $style['text_color']); ?>"></label></p>
                    <p><label>Background color <input type="color" name="lcni_filter_style_config[background_color]" value="<?php echo esc_attr((string) $style['background_color']); ?>"></label></p>
                    <p><label>Border color <input type="color" name="lcni_filter_style_config[border_color]" value="<?php echo esc_attr((string) ($style['border_color'] ?? '#e5e7eb')); ?>"></label></p>
                    <p><label>Border width <input type="number" name="lcni_filter_style_config[border_width]" value="<?php echo esc_attr((string) ($style['border_width'] ?? 1)); ?>"></label></p>
                    <p><label>Border radius <input type="number" name="lcni_filter_style_config[border_radius]" value="<?php echo esc_attr((string) ($style['border_radius'] ?? 8)); ?>"></label></p>
                    <p><label>Header label font size <input type="number" name="lcni_filter_style_config[header_label_font_size]" value="<?php echo esc_attr((string) ($style['header_label_font_size'] ?? 12)); ?>"></label></p>
                    <p><label>Row font size <input type="number" name="lcni_filter_style_config[row_font_size]" value="<?php echo esc_attr((string) ($style['row_font_size'] ?? 13)); ?>"></label></p>
                    <p><label>Row height <input type="number" name="lcni_filter_style_config[row_height]" value="<?php echo esc_attr((string) $style['row_height']); ?>"></label></p>
                    <p><label>Conditional value colors JSON <textarea name="lcni_filter_style_config[conditional_value_colors]" rows="5" class="large-text code"><?php echo esc_textarea((string) ($style['conditional_value_colors'] ?? '[]')); ?></textarea></label></p>
                    <?php submit_button('Save'); ?>
                </form>
            </div>

            <div data-filter-sub-pane="default_values" style="display:none">
                <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=lcni-settings')); ?>" class="lcni-front-form">
                    <?php wp_nonce_field('lcni_admin_actions', 'lcni_action_nonce'); ?>
                    <input type="hidden" name="lcni_admin_action" value="save_frontend_settings">
                    <input type="hidden" name="lcni_frontend_module" value="filter">
                    <input type="hidden" name="lcni_filter_section" value="default_values">
                    <input type="hidden" name="lcni_redirect_tab" value="<?php echo esc_attr($tab_id); ?>">
                    <h3>Default filter values (JSON)</h3>
                    <p><textarea name="lcni_filter_default_values" rows="8" class="large-text code" placeholder='{"exchange":["HOSE"],"volume":[100000,5000000]}'><?php echo esc_textarea($default_filter_values); ?></textarea></p>
                    <?php submit_button('Save'); ?>
                </form>
            </div>
        </div>
        <script>
            (function () {
                const root = document.getElementById('lcni-filter-sub-tabs');
                if (!root) return;
                const buttons = root.querySelectorAll('[data-filter-sub-tab]');
                const panes = document.querySelectorAll('[data-filter-sub-pane]');
                const show = (name) => {
                    buttons.forEach((btn) => btn.classList.toggle('active', btn.getAttribute('data-filter-sub-tab') === name));
                    panes.forEach((pane) => { pane.style.display = pane.getAttribute('data-filter-sub-pane') === name ? '' : 'none'; });
                };
                buttons.forEach((btn) => btn.addEventListener('click', () => show(btn.getAttribute('data-filter-sub-tab'))));
                show('criteria');
            })();
        </script>
        <?php
    }
}
