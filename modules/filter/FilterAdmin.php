<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_FilterAdmin {

    public static function sanitize_columns($columns) {
        $service = new LCNI_WatchlistService(new LCNI_WatchlistRepository());
        $all = $service->get_all_columns();
        $columns = is_array($columns) ? array_map('sanitize_key', $columns) : [];

        return array_values(array_filter($columns, static function ($column) use ($all) {
            return in_array($column, $all, true);
        }));
    }

    public static function sanitize_column_order($columns) {
        return self::sanitize_columns($columns);
    }

    public static function sanitize_style($input) {
        $input = is_array($input) ? $input : [];

        $rules = $input['conditional_value_colors'] ?? '[]';
        if (is_array($rules)) {
            $rules = wp_json_encode($rules);
        }

        return [
            'inherit_style' => !empty($input['inherit_style']),
            'enable_hide_button' => !empty($input['enable_hide_button']),
            'font_size' => max(10, min(24, (int) ($input['font_size'] ?? 13))),
            'text_color' => sanitize_hex_color((string) ($input['text_color'] ?? '')) ?: '',
            'background_color' => sanitize_hex_color((string) ($input['background_color'] ?? '')) ?: '',
            'border_color' => sanitize_hex_color((string) ($input['border_color'] ?? '')) ?: '',
            'border_width' => is_numeric($input['border_width'] ?? '') ? max(0, min(6, (int) $input['border_width'])) : '',
            'border_radius' => is_numeric($input['border_radius'] ?? '') ? max(0, min(30, (int) $input['border_radius'])) : '',
            'header_label_font_size' => is_numeric($input['header_label_font_size'] ?? '') ? max(10, min(30, (int) $input['header_label_font_size'])) : '',
            'row_font_size' => is_numeric($input['row_font_size'] ?? '') ? max(10, min(30, (int) $input['row_font_size'])) : '',
            'row_height' => max(24, min(64, (int) ($input['row_height'] ?? 36))),
            'saved_filter_label' => sanitize_text_field((string) ($input['saved_filter_label'] ?? 'Saved filters')),
            'panel_label_font_size' => is_numeric($input['panel_label_font_size'] ?? '') ? max(10, min(30, (int) $input['panel_label_font_size'])) : '',
            'panel_value_font_size' => is_numeric($input['panel_value_font_size'] ?? '') ? max(10, min(30, (int) $input['panel_value_font_size'])) : '',
            'panel_label_color' => sanitize_hex_color((string) ($input['panel_label_color'] ?? '')) ?: '',
            'panel_value_color' => sanitize_hex_color((string) ($input['panel_value_color'] ?? '')) ?: '',
            'table_header_font_size' => is_numeric($input['table_header_font_size'] ?? '') ? max(10, min(30, (int) $input['table_header_font_size'])) : '',
            'table_header_text_color' => sanitize_hex_color((string) ($input['table_header_text_color'] ?? '')) ?: '',
            'table_header_background' => sanitize_hex_color((string) ($input['table_header_background'] ?? '')) ?: '',
            'table_value_font_size' => is_numeric($input['table_value_font_size'] ?? '') ? max(10, min(30, (int) $input['table_value_font_size'])) : '',
            'table_value_text_color' => sanitize_hex_color((string) ($input['table_value_text_color'] ?? '')) ?: '',
            'table_value_background' => sanitize_hex_color((string) ($input['table_value_background'] ?? '')) ?: '',
            'table_row_divider_color' => sanitize_hex_color((string) ($input['table_row_divider_color'] ?? '')) ?: '',
            'table_row_divider_width' => is_numeric($input['table_row_divider_width'] ?? '') ? max(0, min(6, (int) $input['table_row_divider_width'])) : '',
            'sticky_column_count' => is_numeric($input['sticky_column_count'] ?? '') ? max(0, min(5, (int) $input['sticky_column_count'])) : 1,
            'sticky_header_rows' => is_numeric($input['sticky_header_rows'] ?? '') ? max(0, min(2, (int) $input['sticky_header_rows'])) : 1,
            'table_header_row_height' => is_numeric($input['table_header_row_height'] ?? '') ? max(28, min(80, (int) $input['table_header_row_height'])) : 42,
            'table_scroll_speed' => is_numeric($input['table_scroll_speed'] ?? '') ? max(1, min(5, (int) $input['table_scroll_speed'])) : 1,
            'row_hover_background' => sanitize_hex_color((string) ($input['row_hover_background'] ?? '')) ?: '',
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


    public static function get_saved_filters_by_user($user_id) {
        global $wpdb;
        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return [];
        }

        $table = $wpdb->prefix . 'lcni_saved_filters';
        $sql = $wpdb->prepare("SELECT id, filter_name FROM {$table} WHERE user_id = %d ORDER BY id DESC", $user_id);
        $rows = $wpdb->get_results($sql, ARRAY_A);

        return is_array($rows) ? $rows : [];
    }

    public static function render_filter_form($tab_id) {
        $service = new LCNI_WatchlistService(new LCNI_WatchlistRepository());
        $all_columns = $service->get_all_columns();
        $criteria = self::sanitize_columns(get_option('lcni_filter_criteria_columns', []));
        $table_columns = self::sanitize_columns(get_option('lcni_filter_table_columns', []));
        $style = self::sanitize_style(get_option('lcni_filter_style_config', get_option('lcni_filter_style', [])));
        $default_filter_values = (string) get_option('lcni_filter_default_values', '');
        $admin_saved_filters = self::get_saved_filters_by_user(get_current_user_id());
        $default_admin_saved_filter_id = absint(get_option('lcni_filter_default_admin_saved_filter_id', 0));
        ?>
        <div id="<?php echo esc_attr($tab_id); ?>" class="lcni-sub-tab-content">
            <div class="lcni-sub-tab-nav" id="lcni-filter-sub-tabs">
                <button type="button" data-filter-sub-tab="criteria">Filter Criteria</button>
                <button type="button" data-filter-sub-tab="table_columns">Table Columns</button>
                <button type="button" data-filter-sub-tab="style">Style</button>
                <button type="button" data-filter-sub-tab="default_values">Default Values</button>
                <button type="button" data-filter-sub-tab="default_criteria">Filter Criteria Default</button>
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
                <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=lcni-settings')); ?>" class="lcni-front-form" data-lcni-table-column-form>
                    <?php wp_nonce_field('lcni_admin_actions', 'lcni_action_nonce'); ?>
                    <input type="hidden" name="lcni_admin_action" value="save_frontend_settings">
                    <input type="hidden" name="lcni_frontend_module" value="filter">
                    <input type="hidden" name="lcni_filter_section" value="table_columns">
                    <input type="hidden" name="lcni_redirect_tab" value="<?php echo esc_attr($tab_id); ?>">
                    <input type="hidden" name="lcni_filter_table_column_order" value="<?php echo esc_attr(implode(',', (array) $table_columns)); ?>" data-selected-order>
                    <h3>Table Columns</h3>
                    <div style="display:grid;grid-template-columns:80% 20%;gap:12px;align-items:start;">
                        <div>
                            <p><strong>Available fields</strong></p>
                            <div class="lcni-front-grid">
                                <?php foreach ($all_columns as $column) : ?>
                                    <label><input type="checkbox" name="lcni_filter_table_columns[]" data-column-checkbox value="<?php echo esc_attr($column); ?>" <?php checked(in_array($column, $table_columns, true)); ?>> <?php echo esc_html($column); ?></label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div>
                            <p><strong>Selected order</strong></p>
                            <ol data-selected-list style="margin:0;padding-left:18px;max-height:320px;overflow:auto;">
                                <?php foreach ((array) $table_columns as $column) : ?>
                                    <li draggable="true" data-selected-column="<?php echo esc_attr($column); ?>" style="cursor:move;padding:4px 0;"><?php echo esc_html($column); ?></li>
                                <?php endforeach; ?>
                            </ol>
                            <p class="description">Kéo thả để đổi thứ tự cột hiển thị frontend.</p>
                        </div>
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
                    <p><label><input type="checkbox" name="lcni_filter_style_config[enable_hide_button]" value="1" <?php checked(!empty($style['enable_hide_button'])); ?>> Enable panel hide button</label></p>
                    <p><label>Saved filter label <input type="text" name="lcni_filter_style_config[saved_filter_label]" value="<?php echo esc_attr((string) ($style['saved_filter_label'] ?? 'Saved filters')); ?>"></label></p>
                    <p><label>Font size <input type="number" name="lcni_filter_style_config[font_size]" value="<?php echo esc_attr((string) $style['font_size']); ?>"></label></p>
                    <p><label>Text color <input type="color" name="lcni_filter_style_config[text_color]" value="<?php echo esc_attr((string) $style['text_color']); ?>"></label></p>
                    <p><label>Background color <input type="color" name="lcni_filter_style_config[background_color]" value="<?php echo esc_attr((string) $style['background_color']); ?>"></label></p>
                    <p><label>Border color <input type="color" name="lcni_filter_style_config[border_color]" value="<?php echo esc_attr((string) ($style['border_color'] ?? '#e5e7eb')); ?>"></label></p>
                    <p><label>Border width <input type="number" name="lcni_filter_style_config[border_width]" value="<?php echo esc_attr((string) ($style['border_width'] ?? 1)); ?>"></label></p>
                    <p><label>Border radius <input type="number" name="lcni_filter_style_config[border_radius]" value="<?php echo esc_attr((string) ($style['border_radius'] ?? 8)); ?>"></label></p>
                    <p><label>Header label font size <input type="number" name="lcni_filter_style_config[header_label_font_size]" value="<?php echo esc_attr((string) ($style['header_label_font_size'] ?? 12)); ?>"></label></p>
                    <p><label>Row font size <input type="number" name="lcni_filter_style_config[row_font_size]" value="<?php echo esc_attr((string) ($style['row_font_size'] ?? 13)); ?>"></label></p>
                    <p><label>Row height <input type="number" name="lcni_filter_style_config[row_height]" value="<?php echo esc_attr((string) $style['row_height']); ?>"></label></p>
                    <p><label>Panel label font size <input type="number" name="lcni_filter_style_config[panel_label_font_size]" value="<?php echo esc_attr((string) ($style['panel_label_font_size'] ?? 13)); ?>"></label></p>
                    <p><label>Panel label color <input type="color" name="lcni_filter_style_config[panel_label_color]" value="<?php echo esc_attr((string) ($style['panel_label_color'] ?? '#111827')); ?>"></label></p>
                    <p><label>Panel value font size <input type="number" name="lcni_filter_style_config[panel_value_font_size]" value="<?php echo esc_attr((string) ($style['panel_value_font_size'] ?? 13)); ?>"></label></p>
                    <p><label>Panel value color <input type="color" name="lcni_filter_style_config[panel_value_color]" value="<?php echo esc_attr((string) ($style['panel_value_color'] ?? '#374151')); ?>"></label></p>
                    <p><label>Table header font size <input type="number" name="lcni_filter_style_config[table_header_font_size]" value="<?php echo esc_attr((string) ($style['table_header_font_size'] ?? 12)); ?>"></label></p>
                    <p><label>Table header text color <input type="color" name="lcni_filter_style_config[table_header_text_color]" value="<?php echo esc_attr((string) ($style['table_header_text_color'] ?? '#111827')); ?>"></label></p>
                    <p><label>Table header background <input type="color" name="lcni_filter_style_config[table_header_background]" value="<?php echo esc_attr((string) ($style['table_header_background'] ?? '#f3f4f6')); ?>"></label></p>
                    <p><label>Table value font size <input type="number" name="lcni_filter_style_config[table_value_font_size]" value="<?php echo esc_attr((string) ($style['table_value_font_size'] ?? 13)); ?>"></label></p>
                    <p><label>Table value text color <input type="color" name="lcni_filter_style_config[table_value_text_color]" value="<?php echo esc_attr((string) ($style['table_value_text_color'] ?? '#111827')); ?>"></label></p>
                    <p><label>Table value background <input type="color" name="lcni_filter_style_config[table_value_background]" value="<?php echo esc_attr((string) ($style['table_value_background'] ?? '#ffffff')); ?>"></label></p>
                    <p><label>Row divider color <input type="color" name="lcni_filter_style_config[table_row_divider_color]" value="<?php echo esc_attr((string) ($style['table_row_divider_color'] ?? '#e5e7eb')); ?>"></label></p>
                    <p><label>Row divider width <input type="number" name="lcni_filter_style_config[table_row_divider_width]" value="<?php echo esc_attr((string) ($style['table_row_divider_width'] ?? 1)); ?>"></label></p>
                    <p><label>Sticky column count <input type="number" name="lcni_filter_style_config[sticky_column_count]" value="<?php echo esc_attr((string) ($style['sticky_column_count'] ?? 1)); ?>"></label></p>
                    <p><label>Sticky header rows <input type="number" name="lcni_filter_style_config[sticky_header_rows]" value="<?php echo esc_attr((string) ($style['sticky_header_rows'] ?? 1)); ?>"></label></p>
                    <p><label>Table header row height <input type="number" name="lcni_filter_style_config[table_header_row_height]" value="<?php echo esc_attr((string) ($style['table_header_row_height'] ?? 42)); ?>"></label></p>
                    <p><label>Table horizontal scroll speed <input type="number" min="1" max="5" name="lcni_filter_style_config[table_scroll_speed]" value="<?php echo esc_attr((string) ($style['table_scroll_speed'] ?? 1)); ?>"></label></p>
                    <p><label>Row hover background <input type="color" name="lcni_filter_style_config[row_hover_background]" value="<?php echo esc_attr((string) ($style['row_hover_background'] ?? '#eef2ff')); ?>"></label></p>
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

            <div data-filter-sub-pane="default_criteria" style="display:none">
                <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=lcni-settings')); ?>" class="lcni-front-form">
                    <?php wp_nonce_field('lcni_admin_actions', 'lcni_action_nonce'); ?>
                    <input type="hidden" name="lcni_admin_action" value="save_frontend_settings">
                    <input type="hidden" name="lcni_frontend_module" value="filter">
                    <input type="hidden" name="lcni_filter_section" value="default_criteria">
                    <input type="hidden" name="lcni_redirect_tab" value="<?php echo esc_attr($tab_id); ?>">
                    <h3>Default filter from admin saved filter</h3>
                    <p>Chọn bộ lọc đã lưu của admin để làm mặc định cho khách chưa đăng nhập.</p>
                    <p>
                        <select name="lcni_filter_default_admin_saved_filter_id">
                            <option value="0">-- None --</option>
                            <?php foreach ($admin_saved_filters as $filter) : ?>
                                <option value="<?php echo esc_attr((string) absint($filter['id'] ?? 0)); ?>" <?php selected(absint($filter['id'] ?? 0), $default_admin_saved_filter_id); ?>><?php echo esc_html((string) ($filter['filter_name'] ?? '')); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </p>
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

                document.querySelectorAll('[data-lcni-table-column-form]').forEach((form) => {
                    const selectedList = form.querySelector('[data-selected-list]');
                    const hidden = form.querySelector('[data-selected-order]');
                    if (!selectedList || !hidden) {
                        return;
                    }

                    const stickyColumnCount = Math.max(0, Number(<?php echo wp_json_encode((int) ($style['sticky_column_count'] ?? 1)); ?>) || 0);
                    const syncHidden = () => {
                        hidden.value = Array.from(selectedList.querySelectorAll('[data-selected-column]')).map((node) => node.getAttribute('data-selected-column') || '').filter(Boolean).join(',');
                    };

                    const isLockedIndex = (index) => index < stickyColumnCount;

                    const renderSelected = () => {
                        const checked = Array.from(form.querySelectorAll('[data-column-checkbox]:checked')).map((node) => node.value);
                        const existing = Array.from(selectedList.querySelectorAll('[data-selected-column]')).map((node) => node.getAttribute('data-selected-column') || '');
                        const next = existing.filter((col) => checked.includes(col));
                        checked.forEach((col) => { if (!next.includes(col)) next.push(col); });
                        selectedList.innerHTML = next.map((col, index) => {
                            const locked = isLockedIndex(index);
                            return `<li draggable="${locked ? 'false' : 'true'}" data-selected-column="${col}" data-sticky-locked="${locked ? '1' : '0'}" style="cursor:${locked ? 'not-allowed' : 'move'};padding:4px 0;">${col}${locked ? ' (sticky)' : ''}</li>`;
                        }).join('');
                        bindSortable();
                        syncHidden();
                    };

                    const bindSortable = () => {
                        let dragging = null;
                        selectedList.querySelectorAll('[data-selected-column]').forEach((item, itemIndex) => {
                            const itemLocked = item.getAttribute('data-sticky-locked') === '1' || isLockedIndex(itemIndex);
                            item.addEventListener('dragstart', (event) => {
                                if (itemLocked) {
                                    event.preventDefault();
                                    dragging = null;
                                    return;
                                }
                                dragging = item;
                                item.style.opacity = '0.5';
                            });
                            item.addEventListener('dragend', () => { if (dragging) dragging.style.opacity = ''; dragging = null; syncHidden(); });
                            item.addEventListener('dragover', (event) => event.preventDefault());
                            item.addEventListener('drop', (event) => {
                                event.preventDefault();
                                const targetLocked = item.getAttribute('data-sticky-locked') === '1';
                                if (!dragging || dragging === item || targetLocked) return;
                                const rect = item.getBoundingClientRect();
                                const after = (event.clientY - rect.top) > rect.height / 2;
                                if (after) {
                                    item.after(dragging);
                                } else {
                                    item.before(dragging);
                                }
                                syncHidden();
                            });
                        });
                    };

                    form.querySelectorAll('[data-column-checkbox]').forEach((checkbox) => checkbox.addEventListener('change', renderSelected));
                    bindSortable();
                    syncHidden();
                });

                show('criteria');
            })();
        </script>
        <?php
    }
}
