<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_Heatmap_Admin {

    private $watchlist_service;

    public function __construct(LCNI_WatchlistService $watchlist_service) {
        $this->watchlist_service = $watchlist_service;
        add_action('admin_menu', [$this, 'add_admin_page']);
        add_action('admin_post_lcni_save_heatmap_settings', [$this, 'handle_save']);
    }

    public function add_admin_page() {
        add_submenu_page(
            'lcni-settings',
            'Heatmap Filter',
            'Heatmap Filter',
            'manage_options',
            'lcni-heatmap',
            [$this, 'render_page']
        );
    }

    public function handle_save() {
        if (!current_user_can('manage_options')) {
            wp_die('Không có quyền.');
        }
        check_admin_referer('lcni_heatmap_save');

        // ── Save cell definitions ────────────────────────────────────────────
        $raw_cells = isset($_POST['heatmap_cells']) && is_array($_POST['heatmap_cells'])
            ? $_POST['heatmap_cells']
            : [];

        $all_columns = $this->watchlist_service->get_all_columns();
        $cells = [];
        foreach ($raw_cells as $cell) {
            $col = sanitize_key($cell['column'] ?? '');
            if ($col === '' || !in_array($col, $all_columns, true)) {
                continue;
            }
            $operator = in_array($cell['operator'] ?? '', ['=', '!=', '>', '>=', '<', '<=', 'between', 'contains', 'not_contains', 'in'], true)
                ? $cell['operator'] : '!=';

            $cells[] = [
                'column'     => $col,
                'label'      => sanitize_text_field($cell['label'] ?? $col),
                'color'      => sanitize_hex_color($cell['color']      ?? '#dc2626') ?: '#dc2626',
                'text_color' => sanitize_hex_color($cell['text_color'] ?? '#ffffff') ?: '#ffffff',
                'operator'   => $operator,
                'value'      => sanitize_text_field($cell['value']  ?? ''),
                'value2'     => sanitize_text_field($cell['value2'] ?? ''),
            ];
        }
        update_option('lcni_heatmap_cells', $cells);

        // ── Save display settings ────────────────────────────────────────────
        $ds = isset($_POST['heatmap_display']) && is_array($_POST['heatmap_display'])
            ? $_POST['heatmap_display']
            : [];

        update_option('lcni_heatmap_display_settings', [
            'label_font_size'  => max(10, min(32, (int) ($ds['label_font_size']  ?? 13))),
            'count_font_size'  => max(16, min(80, (int) ($ds['count_font_size']  ?? 42))),
            'symbol_font_size' => max(9,  min(24, (int) ($ds['symbol_font_size'] ?? 13))),
            'gap'              => max(2, min(20, (int) ($ds['gap']          ?? 6))),
            'border_radius'    => max(0, min(24, (int) ($ds['border_radius'] ?? 8))),
        ]);

        wp_safe_redirect(add_query_arg([
            'page'    => 'lcni-heatmap',
            'updated' => '1',
        ], admin_url('admin.php')));
        exit;
    }

    public function render_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $all_columns   = $this->watchlist_service->get_all_columns();
        $column_labels = $this->watchlist_service->get_column_labels($all_columns);
        $cells         = get_option('lcni_heatmap_cells', []);
        $cells         = is_array($cells) ? $cells : [];
        $ds            = get_option('lcni_heatmap_display_settings', []);
        $ds            = is_array($ds) ? $ds : [];

        // display settings defaults
        $label_font_size  = max(10, min(32, (int) ($ds['label_font_size']  ?? 13)));
        $count_font_size  = max(16, min(80, (int) ($ds['count_font_size']  ?? 42)));
        $symbol_font_size = max(9,  min(24, (int) ($ds['symbol_font_size'] ?? 13)));
        $gap              = max(2, min(20, (int) ($ds['gap']           ?? 6)));
        $border_radius    = max(0, min(24, (int) ($ds['border_radius'] ?? 8)));

        $operators = ['=' => '=', '!=' => '!=', '>' => '>', '>=' => '>=', '<' => '<', '<=' => '<=', 'between' => 'between', 'contains' => 'contains (chứa)', 'not_contains' => 'not contains (không chứa)', 'in' => 'in (danh sách)'];
        $updated   = isset($_GET['updated']);
        ?>
        <div class="wrap">
            <h1>Heatmap Filter — Cài đặt ô</h1>
            <?php if ($updated) : ?>
                <div class="notice notice-success is-dismissible"><p>Đã lưu thành công.</p></div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="lcni-heatmap-form">
                <?php wp_nonce_field('lcni_heatmap_save'); ?>
                <input type="hidden" name="action" value="lcni_save_heatmap_settings">

                <!-- ── Display Settings ──────────────────────────────────── -->
                <h2>Cài đặt hiển thị chung</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th>Cỡ chữ Label ô</th>
                        <td><input type="number" name="heatmap_display[label_font_size]" value="<?php echo esc_attr($label_font_size); ?>" min="10" max="32" style="width:80px"> px</td>
                    </tr>
                    <tr>
                        <th>Cỡ chữ số lượng (số lớn)</th>
                        <td><input type="number" name="heatmap_display[count_font_size]" value="<?php echo esc_attr($count_font_size); ?>" min="16" max="80" style="width:80px"> px</td>
                    </tr>
                    <tr>
                        <th>Cỡ chữ mã cổ phiếu</th>
                        <td><input type="number" name="heatmap_display[symbol_font_size]" value="<?php echo esc_attr($symbol_font_size); ?>" min="9" max="24" style="width:80px"> px</td>
                    </tr>
                    <tr>
                        <th>Khoảng cách giữa ô (gap)</th>
                        <td><input type="number" name="heatmap_display[gap]" value="<?php echo esc_attr($gap); ?>" min="2" max="20" style="width:80px"> px</td>
                    </tr>
                    <tr>
                        <th>Bo góc ô (border-radius)</th>
                        <td><input type="number" name="heatmap_display[border_radius]" value="<?php echo esc_attr($border_radius); ?>" min="0" max="24" style="width:80px"> px</td>
                    </tr>
                </table>

                <!-- ── Cell Definitions ──────────────────────────────────── -->
                <h2 style="margin-top:32px">Các ô Heatmap</h2>
                <p class="description">
                    Mỗi ô hiện thị số mã thỏa mãn điều kiện lọc của cột được chọn.<br>
                    Kích thước ô tự động tỉ lệ với số mã — ô nhiều mã sẽ lớn hơn.<br>
                    Shortcode: <code>[lcni_heatmap]</code>
                </p>

                <div id="lcni-heatmap-cells">
                    <?php foreach ($cells as $i => $cell) : ?>
                        <?php $this->render_cell_row($i, $cell, $all_columns, $column_labels, $operators); ?>
                    <?php endforeach; ?>
                </div>

                <button type="button" id="lcni-heatmap-add-cell" class="button" style="margin-top:12px">
                    + Thêm ô
                </button>

                <p class="submit" style="margin-top:24px">
                    <?php submit_button('Lưu cài đặt', 'primary', 'submit', false); ?>
                </p>
            </form>
        </div>

        <!-- ── Template row (hidden) ────────────────────────────────────── -->
        <template id="lcni-heatmap-cell-template">
            <?php $this->render_cell_row('__IDX__', [], $all_columns, $column_labels, $operators); ?>
        </template>

        <script>
        (function () {
            let idx = <?php echo (int) count($cells); ?>;

            const container = document.getElementById('lcni-heatmap-cells');
            const tpl       = document.getElementById('lcni-heatmap-cell-template');
            const addBtn    = document.getElementById('lcni-heatmap-add-cell');

            function addRow() {
                const html = tpl.innerHTML.replace(/__IDX__/g, idx++);
                const wrap = document.createElement('div');
                wrap.innerHTML = html;
                container.appendChild(wrap.firstElementChild);
                bindRow(container.lastElementChild);
            }

            function bindRow(row) {
                const opSel = row.querySelector('[data-op-select]');
                const v2Row = row.querySelector('[data-value2-row]');
                if (opSel && v2Row) {
                    opSel.addEventListener('change', () => {
                        v2Row.style.display = opSel.value === 'between' ? '' : 'none';
                    });
                    v2Row.style.display = opSel.value === 'between' ? '' : 'none';
                }
                const removeBtn = row.querySelector('[data-remove-cell]');
                if (removeBtn) {
                    removeBtn.addEventListener('click', () => row.remove());
                }
            }

            // bind existing rows
            container.querySelectorAll('.lcni-heatmap-cell-row').forEach(bindRow);

            addBtn.addEventListener('click', addRow);
        })();
        </script>
        <?php
    }

    // ─── private helper ──────────────────────────────────────────────────────

    private function render_cell_row($idx, array $cell, array $all_columns, array $column_labels, array $operators) {
        $sel_col    = sanitize_key($cell['column']   ?? '');
        $label      = esc_attr($cell['label']      ?? '');
        $color      = esc_attr(sanitize_hex_color($cell['color']      ?? '#dc2626') ?: '#dc2626');
        $text_color = esc_attr(sanitize_hex_color($cell['text_color'] ?? '#ffffff') ?: '#ffffff');
        $sel_op     = $cell['operator'] ?? '!=';
        $val        = esc_attr($cell['value']  ?? '');
        $val2       = esc_attr($cell['value2'] ?? '');
        $n          = esc_attr((string) $idx);
        $v2_style   = $sel_op === 'between' ? '' : 'display:none';
        ?>
        <div class="lcni-heatmap-cell-row" style="border:1px solid #ddd;padding:14px 16px;margin-bottom:10px;border-radius:6px;background:#fafafa;display:flex;flex-wrap:wrap;gap:12px;align-items:center;">
            <div>
                <label style="display:block;font-size:11px;margin-bottom:3px;font-weight:600">Cột (điều kiện)</label>
                <select name="heatmap_cells[<?php echo $n; ?>][column]" style="min-width:160px">
                    <?php foreach ($all_columns as $col) : ?>
                        <option value="<?php echo esc_attr($col); ?>" <?php selected($col, $sel_col); ?>>
                            <?php echo esc_html($column_labels[$col] ?? $col); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label style="display:block;font-size:11px;margin-bottom:3px;font-weight:600">Toán tử</label>
                <select name="heatmap_cells[<?php echo $n; ?>][operator]" data-op-select>
                    <?php foreach ($operators as $op_val => $op_label) : ?>
                        <option value="<?php echo esc_attr($op_val); ?>" <?php selected($op_val, $sel_op); ?>>
                            <?php echo esc_html($op_label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label style="display:block;font-size:11px;margin-bottom:3px;font-weight:600">Giá trị</label>
                <input type="text" name="heatmap_cells[<?php echo $n; ?>][value]" value="<?php echo $val; ?>" style="width:100px" placeholder="vd: 1">
            </div>
            <div data-value2-row style="<?php echo esc_attr($v2_style); ?>">
                <label style="display:block;font-size:11px;margin-bottom:3px;font-weight:600">Giá trị 2 (between)</label>
                <input type="text" name="heatmap_cells[<?php echo $n; ?>][value2]" value="<?php echo $val2; ?>" style="width:100px" placeholder="Đến">
            </div>
            <div>
                <label style="display:block;font-size:11px;margin-bottom:3px;font-weight:600">Label ô</label>
                <input type="text" name="heatmap_cells[<?php echo $n; ?>][label]" value="<?php echo $label; ?>" style="width:140px" placeholder="vd: Tăng giá kèm Vol">
            </div>
            <div>
                <label style="display:block;font-size:11px;margin-bottom:3px;font-weight:600">Màu nền</label>
                <input type="color" name="heatmap_cells[<?php echo $n; ?>][color]" value="<?php echo $color; ?>">
            </div>
            <div>
                <label style="display:block;font-size:11px;margin-bottom:3px;font-weight:600">Màu chữ</label>
                <input type="color" name="heatmap_cells[<?php echo $n; ?>][text_color]" value="<?php echo $text_color; ?>">
            </div>
            <div style="margin-left:auto;align-self:flex-end">
                <button type="button" class="button button-small" data-remove-cell style="color:#cc0000">✕ Xóa</button>
            </div>
        </div>
        <?php
    }
}
