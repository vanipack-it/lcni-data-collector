<?php
/**
 * LCNI_IM_Admin
 * Trang quản lý các Monitor Instance (list/create/edit/delete).
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class LCNI_IM_Admin {

    public function register_hooks() {
        add_action( 'admin_menu',  [ $this, 'add_menu' ] );
        add_action( 'admin_init',  [ $this, 'on_admin_init' ] );
    }

    public function on_admin_init() {
        LCNI_IM_Monitor_DB::ensure_table();
        $this->handle_post();
    }

    public function add_menu() {
        // Menu chính: Quản lý monitors
        add_submenu_page(
            'lcni-settings',
            'Industry Monitor',
            'Industry Monitor',
            'manage_options',
            'lcni-industry-monitor',
            [ $this, 'render_page' ]
        );

        // Submenu: Cài đặt chung
        add_submenu_page(
            'lcni-settings',
            'IM Cài đặt chung',
            'IM Cài đặt chung',
            'manage_options',
            'lcni-im-settings',
            [ $this, 'render_global_settings_page' ]
        );
    }

    public function render_global_settings_page() {
        $s = new LCNI_Industry_Settings();
        $s->render_page();
    }

    // ── POST handler ─────────────────────────────────────────────────────────

    public function handle_post() {
        if ( empty( $_POST['lcni_im_action'] ) ) return;
        if ( ! current_user_can( 'manage_options' ) ) return;
        if ( ! check_admin_referer( 'lcni_im_save' ) ) return;

        $action = sanitize_key( $_POST['lcni_im_action'] );
        $id     = absint( $_POST['lcni_im_id'] ?? 0 );
        $name   = sanitize_text_field( wp_unslash( $_POST['lcni_im_name'] ?? '' ) );
        $mode   = sanitize_key( $_POST['lcni_im_mode'] ?? 'icb' );
        $config = $this->sanitize_config( $_POST, $mode );

        if ( $action === 'create' && $name !== '' ) {
            LCNI_IM_Monitor_DB::insert( $name, $mode, $config );
            wp_safe_redirect( add_query_arg( [ 'page' => 'lcni-industry-monitor', 'saved' => '1' ], admin_url( 'admin.php' ) ) );
            exit;
        }

        if ( $action === 'update' && $id > 0 ) {
            LCNI_IM_Monitor_DB::update( $id, $name, $mode, $config );
            wp_safe_redirect( add_query_arg( [ 'page' => 'lcni-industry-monitor', 'saved' => '1' ], admin_url( 'admin.php' ) ) );
            exit;
        }

        if ( $action === 'delete' && $id > 0 ) {
            LCNI_IM_Monitor_DB::delete( $id );
            wp_safe_redirect( add_query_arg( [ 'page' => 'lcni-industry-monitor', 'deleted' => '1' ], admin_url( 'admin.php' ) ) );
            exit;
        }
    }

    // ── Sanitize ─────────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function sanitize_config( array $post, string $mode ) {
        $global_settings    = LCNI_Industry_Settings::get_settings();
        $metric_labels      = LCNI_Industry_Settings::get_metric_labels();
        $all_icb_metrics    = array_keys( $metric_labels );
        $all_ohlc_cols      = array_keys( LCNI_IM_Monitor_DB::get_ohlc_numeric_columns() );

        // Enabled metrics / ohlc columns
        if ( $mode === 'icb' ) {
            $raw_metrics = isset( $post['lcni_im_enabled_metrics'] ) ? (array) $post['lcni_im_enabled_metrics'] : [];
            $enabled     = array_values( array_intersect( $all_icb_metrics, array_map( 'sanitize_key', $raw_metrics ) ) );
            if ( empty( $enabled ) ) $enabled = $all_icb_metrics;
        } else {
            $raw_cols    = isset( $post['lcni_im_ohlc_columns'] ) ? (array) $post['lcni_im_ohlc_columns'] : [];
            $enabled     = array_values( array_intersect( $all_ohlc_cols, array_map( 'sanitize_key', $raw_cols ) ) );
        }

        // Cell rules
        $cell_rules     = $this->sanitize_cell_rules(
            $post['lcni_im_cell_rules'] ?? [],
            $mode === 'icb' ? $all_icb_metrics : $all_ohlc_cols
        );
        // Row gradient rules
        $gradient_rules = $this->sanitize_gradient_rules(
            $post['lcni_im_gradient_rules'] ?? [],
            $mode === 'icb' ? $all_icb_metrics : $all_ohlc_cols
        );

        return [
            'enabled_metrics'        => $mode === 'icb' ? $enabled : ( $global_settings['enabled_metrics'] ?? [] ),
            'ohlc_columns'           => $mode === 'symbol' ? $enabled : [],
            'event_time_col_width'   => max( 72, min( 360,  absint( $post['lcni_im_event_time_col_width']  ?? 140 ) ) ),
            'dropdown_height'        => max( 28, min( 80,   absint( $post['lcni_im_dropdown_height']        ?? 36  ) ) ),
            'dropdown_width'         => max( 80, min( 720,  absint( $post['lcni_im_dropdown_width']         ?? 280 ) ) ),
            'dropdown_border_color'  => sanitize_hex_color( $post['lcni_im_dropdown_border_color'] ?? '#d0d0d0' ) ?: '#d0d0d0',
            'dropdown_border_width'  => max( 0, min( 8, absint( $post['lcni_im_dropdown_border_width'] ?? 1 ) ) ),
            'row_hover_enabled'      => ! empty( $post['lcni_im_row_hover_enabled'] ) ? 1 : 0,
            'industry_filter_url'    => esc_url_raw( wp_unslash( $post['lcni_im_industry_filter_url'] ?? home_url('/') ) ),
            'symbol_filter_url'      => esc_url_raw( wp_unslash( $post['lcni_im_symbol_filter_url']   ?? home_url('/') ) ),
            'compact_full_table_url' => esc_url_raw( wp_unslash( $post['lcni_im_compact_full_table_url'] ?? home_url('/') ) ),
            'default_session_limit'  => max( 1, min( 200, absint( $post['lcni_im_default_session_limit'] ?? 30 ) ) ),
            'cell_rules'             => $cell_rules,
            'row_gradient_rules'     => $gradient_rules,
        ];
    }

    /** @param mixed $raw */
    private function sanitize_cell_rules( $raw, array $allowed_fields ) {
        $result = [];
        foreach ( (array) $raw as $rule ) {
            $rule  = (array) $rule;
            $field = sanitize_key( $rule['field'] ?? '' );
            $op    = (string) ( $rule['operator'] ?? '>' );
            if ( ! in_array( $field, $allowed_fields, true ) ) continue;
            if ( ! in_array( $op, [ '>', '=', '<' ], true ) ) continue;
            $v = $rule['value'] ?? null;
            if ( ! is_numeric( $v ) ) continue;
            $result[] = [
                'field'      => $field,
                'operator'   => $op,
                'value'      => (float) $v,
                'bg_color'   => sanitize_hex_color( $rule['bg_color']   ?? '#ffffff' ) ?: '#ffffff',
                'text_color' => sanitize_hex_color( $rule['text_color'] ?? '#111111' ) ?: '#111111',
            ];
        }
        return $result;
    }

    /** @param mixed $raw */
    private function sanitize_gradient_rules( $raw, array $allowed_fields ) {
        $result = [];
        foreach ( (array) $raw as $rule ) {
            $rule  = (array) $rule;
            $field = sanitize_key( $rule['field'] ?? '' );
            if ( ! in_array( $field, $allowed_fields, true ) ) continue;
            $result[] = [
                'field'           => $field,
                'color_negative'  => sanitize_hex_color( $rule['color_negative']  ?? '#f87171' ) ?: '#f87171',
                'color_neutral'   => sanitize_hex_color( $rule['color_neutral']   ?? '#f3f4f6' ) ?: '#f3f4f6',
                'color_positive'  => sanitize_hex_color( $rule['color_positive']  ?? '#4ade80' ) ?: '#4ade80',
            ];
        }
        return $result;
    }

    // ── Render ────────────────────────────────────────────────────────────────

    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;

        $action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : 'list';
        $id     = absint( $_GET['id'] ?? 0 );

        if ( $action === 'edit' && $id > 0 ) {
            $monitor = LCNI_IM_Monitor_DB::find( $id );
            if ( $monitor ) {
                $this->render_form( $monitor );
                return;
            }
        }

        if ( $action === 'new' ) {
            $this->render_form( null );
            return;
        }

        $this->render_list();
    }

    // ── List ──────────────────────────────────────────────────────────────────

    private function render_list() {
        $monitors = LCNI_IM_Monitor_DB::get_all();
        $saved    = isset( $_GET['saved'] );
        $deleted  = isset( $_GET['deleted'] );
        $new_url  = admin_url( 'admin.php?page=lcni-industry-monitor&action=new' );
        ?>
        <div class="wrap lcni-im-wrap">
            <h1 class="lcni-im-page-title">
                📊 Industry Monitor
                <a href="<?php echo esc_url( $new_url ); ?>" class="page-title-action lcni-im-btn-create">+ Tạo monitor mới</a>
                <a href="<?php echo esc_url( admin_url('admin.php?page=lcni-im-settings') ); ?>" class="page-title-action" style="background:#6b7280!important;border-color:#6b7280!important;color:#fff!important;border-radius:6px!important;margin-left:4px">⚙️ Cài đặt chung</a>
            </h1>

            <?php if ( $saved ): ?>
                <div class="notice notice-success is-dismissible"><p>✅ Đã lưu.</p></div>
            <?php elseif ( $deleted ): ?>
                <div class="notice notice-info is-dismissible"><p>🗑️ Đã xoá.</p></div>
            <?php endif; ?>

            <?php if ( empty( $monitors ) ): ?>
                <div class="lcni-im-empty">
                    <div class="lcni-im-empty-icon">📈</div>
                    <p>Chưa có monitor nào. <a href="<?php echo esc_url( $new_url ); ?>">Tạo monitor đầu tiên</a></p>
                </div>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped lcni-im-list-table">
                    <thead>
                        <tr>
                            <th style="width:40px">#</th>
                            <th>Tên</th>
                            <th style="width:100px">Chế độ</th>
                            <th style="width:280px">Shortcode</th>
                            <th style="width:160px">Ngày tạo</th>
                            <th style="width:160px">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $monitors as $m ):
                            $edit_url   = admin_url( 'admin.php?page=lcni-industry-monitor&action=edit&id=' . (int) $m['id'] );
                            $delete_url = wp_nonce_url(
                                admin_url( 'admin.php?page=lcni-industry-monitor' ),
                                'lcni_im_delete_' . $m['id']
                            );
                            $mode_label = $m['mode'] === 'symbol' ? '🏷 Symbol' : '🏭 ICB';
                            $shortcode  = '[lcni_industry_monitor id="' . (int) $m['id'] . '"]';
                        ?>
                        <tr>
                            <td><?php echo (int) $m['id']; ?></td>
                            <td><strong><?php echo esc_html( $m['name'] ); ?></strong></td>
                            <td><span class="lcni-im-mode-badge lcni-im-mode-<?php echo esc_attr( $m['mode'] ); ?>"><?php echo esc_html( $mode_label ); ?></span></td>
                            <td><code class="lcni-im-shortcode" onclick="lcniImCopy(this)" title="Click để sao chép"><?php echo esc_html( $shortcode ); ?></code></td>
                            <td><?php echo esc_html( $m['created_at'] ); ?></td>
                            <td>
                                <a href="<?php echo esc_url( $edit_url ); ?>" class="button button-small">✏️ Sửa</a>
                                <form method="post" style="display:inline" onsubmit="return confirm('Xác nhận xoá?')">
                                    <?php wp_nonce_field( 'lcni_im_save' ); ?>
                                    <input type="hidden" name="lcni_im_action" value="delete">
                                    <input type="hidden" name="lcni_im_id" value="<?php echo (int) $m['id']; ?>">
                                    <button type="submit" class="button button-small" style="color:#dc2626">🗑 Xoá</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <style>
        .lcni-im-wrap { max-width: 1100px; }
        .lcni-im-page-title { display:flex; align-items:center; gap:12px; }
        .lcni-im-btn-create { background:#2563eb!important; color:#fff!important; border-color:#2563eb!important; border-radius:6px!important; }
        .lcni-im-empty { text-align:center; padding:60px 20px; background:#f9fafb; border:2px dashed #e5e7eb; border-radius:10px; margin-top:20px; }
        .lcni-im-empty-icon { font-size:48px; margin-bottom:12px; }
        .lcni-im-list-table td { vertical-align:middle; }
        .lcni-im-mode-badge { display:inline-flex; align-items:center; gap:4px; padding:3px 10px; border-radius:20px; font-size:12px; font-weight:600; }
        .lcni-im-mode-icb    { background:#dbeafe; color:#1d4ed8; }
        .lcni-im-mode-symbol { background:#dcfce7; color:#15803d; }
        .lcni-im-shortcode { cursor:pointer; background:#f1f5f9; padding:4px 8px; border-radius:4px; font-size:12px; }
        .lcni-im-shortcode:hover { background:#e2e8f0; }
        </style>
        <script>
        function lcniImCopy(el) {
            navigator.clipboard?.writeText(el.textContent).then(() => {
                const orig = el.title;
                el.title = '✅ Đã sao chép!';
                setTimeout(() => { el.title = orig; }, 1500);
            });
        }
        </script>
        <?php
    }

    // ── Form (create + edit) ──────────────────────────────────────────────────

    private function render_form( ?array $monitor ) {
        $is_edit      = $monitor !== null;
        $id           = $is_edit ? (int) $monitor['id'] : 0;
        $name         = $is_edit ? $monitor['name'] : '';
        $mode         = $is_edit ? $monitor['mode'] : 'icb';
        $cfg          = $is_edit ? $monitor['config'] : LCNI_IM_Monitor_DB::default_config();

        $metric_labels  = LCNI_Industry_Settings::get_metric_labels();
        $ohlc_cols      = LCNI_IM_Monitor_DB::get_ohlc_numeric_columns();
        $enabled_icb    = (array) ( $cfg['enabled_metrics'] ?? array_keys( $metric_labels ) );
        $enabled_ohlc   = (array) ( $cfg['ohlc_columns']    ?? [] );
        $cell_rules     = (array) ( $cfg['cell_rules']       ?? [] );
        $gradient_rules = (array) ( $cfg['row_gradient_rules'] ?? [] );

        $back_url = admin_url( 'admin.php?page=lcni-industry-monitor' );
        $title    = $is_edit ? '✏️ Sửa monitor: ' . esc_html( $name ) : '➕ Tạo monitor mới';
        ?>
        <div class="wrap lcni-im-wrap">
            <h1><?php echo $title; ?> <a href="<?php echo esc_url( $back_url ); ?>" class="button" style="margin-left:8px">← Danh sách</a></h1>

            <form method="post" id="lcni-im-form">
                <?php wp_nonce_field( 'lcni_im_save' ); ?>
                <input type="hidden" name="lcni_im_action" value="<?php echo $is_edit ? 'update' : 'create'; ?>">
                <?php if ( $is_edit ): ?>
                <input type="hidden" name="lcni_im_id" value="<?php echo $id; ?>">
                <?php endif; ?>

                <div class="lcni-im-form-grid">

                    <!-- ── Card: Thông tin cơ bản ── -->
                    <div class="lcni-im-card" style="grid-column:1/-1">
                        <div class="lcni-im-card-title">⚙️ Thông tin cơ bản</div>
                        <table class="form-table">
                            <tr>
                                <th><label>Tên monitor</label></th>
                                <td><input type="text" name="lcni_im_name" value="<?php echo esc_attr( $name ); ?>" class="regular-text" required placeholder="VD: Ngành 1D, Cổ phiếu Blue-chip..."></td>
                            </tr>
                            <tr>
                                <th><label>Chế độ cột 1</label></th>
                                <td>
                                    <label style="margin-right:16px">
                                        <input type="radio" name="lcni_im_mode" value="icb" <?php checked( $mode, 'icb' ); ?> id="lcni-im-mode-icb"> 🏭 <strong>ICB</strong> — Tên ngành (lcni_industry_*)
                                    </label>
                                    <label>
                                        <input type="radio" name="lcni_im_mode" value="symbol" <?php checked( $mode, 'symbol' ); ?> id="lcni-im-mode-symbol"> 🏷 <strong>Symbol</strong> — Mã cổ phiếu (lcni_ohlc)
                                    </label>
                                    <p class="description">ICB: dropdown chọn từ các metrics ngành. Symbol: dropdown chọn cột numeric từ lcni_ohlc.</p>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <!-- ── Card: Cột hiển thị (ICB) ── -->
                    <div class="lcni-im-card lcni-im-mode-section" data-mode="icb">
                        <div class="lcni-im-card-title">📊 Cột hiển thị (ICB metrics)</div>
                        <p class="description">Chọn các metric ngành sẽ có trong dropdown frontend.</p>
                        <div style="columns:2;gap:12px">
                            <?php foreach ( $metric_labels as $key => $label ): ?>
                            <label style="display:block;margin-bottom:5px">
                                <input type="checkbox" name="lcni_im_enabled_metrics[]" value="<?php echo esc_attr( $key ); ?>"
                                    <?php checked( in_array( $key, $enabled_icb, true ) ); ?>>
                                <?php echo esc_html( $label ); ?>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- ── Card: Cột hiển thị (Symbol) ── -->
                    <div class="lcni-im-card lcni-im-mode-section" data-mode="symbol">
                        <div class="lcni-im-card-title">🏷 Cột hiển thị (OHLC columns)</div>
                        <p class="description">Chọn các cột numeric từ <code>lcni_ohlc</code> sẽ có trong dropdown frontend.</p>
                        <div style="columns:3;gap:10px;max-height:320px;overflow:auto">
                            <?php foreach ( $ohlc_cols as $col_key => $col_label ): ?>
                            <label style="display:block;margin-bottom:4px;font-size:12px">
                                <input type="checkbox" name="lcni_im_ohlc_columns[]" value="<?php echo esc_attr( $col_key ); ?>"
                                    <?php checked( in_array( $col_key, $enabled_ohlc, true ) ); ?>>
                                <code><?php echo esc_html( $col_label ); ?></code>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- ── Card: Cài đặt bảng ── -->
                    <div class="lcni-im-card">
                        <div class="lcni-im-card-title">🎨 Cài đặt bảng</div>
                        <table class="form-table">
                            <tr><th>Event time col width (px)</th><td><input type="number" min="72" max="360" name="lcni_im_event_time_col_width" value="<?php echo esc_attr( (string) ( $cfg['event_time_col_width'] ?? 140 ) ); ?>"></td></tr>
                            <tr><th>Dropdown height (px)</th><td><input type="number" min="28" max="80" name="lcni_im_dropdown_height" value="<?php echo esc_attr( (string) ( $cfg['dropdown_height'] ?? 36 ) ); ?>"></td></tr>
                            <tr><th>Dropdown width (px)</th><td><input type="number" min="80" max="720" name="lcni_im_dropdown_width" value="<?php echo esc_attr( (string) ( $cfg['dropdown_width'] ?? 280 ) ); ?>"></td></tr>
                            <tr><th>Dropdown border color</th><td><input type="color" name="lcni_im_dropdown_border_color" value="<?php echo esc_attr( $cfg['dropdown_border_color'] ?? '#d0d0d0' ); ?>"></td></tr>
                            <tr><th>Dropdown border width (px)</th><td><input type="number" min="0" max="8" name="lcni_im_dropdown_border_width" value="<?php echo esc_attr( (string) ( $cfg['dropdown_border_width'] ?? 1 ) ); ?>"></td></tr>
                            <tr><th>Row hover effect</th><td><label><input type="checkbox" name="lcni_im_row_hover_enabled" value="1" <?php checked( ! empty( $cfg['row_hover_enabled'] ) ); ?>> Bật hover</label></td></tr>
                            <tr><th>Default sessions</th><td><input type="number" min="1" max="200" name="lcni_im_default_session_limit" value="<?php echo esc_attr( (string) ( $cfg['default_session_limit'] ?? 30 ) ); ?>"></td></tr>
                        </table>
                    </div>

                    <!-- ── Card: URL ── -->
                    <div class="lcni-im-card">
                        <div class="lcni-im-card-title">🔗 URL liên kết</div>
                        <table class="form-table">
                            <tr class="lcni-im-mode-section" data-mode="icb">
                                <th>Industry filter URL</th>
                                <td><input type="url" class="regular-text" name="lcni_im_industry_filter_url" value="<?php echo esc_attr( $cfg['industry_filter_url'] ?? home_url('/') ); ?>">
                                <p class="description">Click ngành → append <code>?apply_filter=1&name_icb2={Tên ngành}</code></p></td>
                            </tr>
                            <tr class="lcni-im-mode-section" data-mode="symbol">
                                <th>Symbol filter URL</th>
                                <td><input type="url" class="regular-text" name="lcni_im_symbol_filter_url" value="<?php echo esc_attr( $cfg['symbol_filter_url'] ?? home_url('/') ); ?>">
                                <p class="description">Click mã → append <code>?apply_filter=1&symbol={MÃ}</code></p></td>
                            </tr>
                            <tr>
                                <th>Compact full table URL</th>
                                <td><input type="url" class="regular-text" name="lcni_im_compact_full_table_url" value="<?php echo esc_attr( $cfg['compact_full_table_url'] ?? home_url('/') ); ?>"></td>
                            </tr>
                        </table>
                    </div>

                    <!-- ── Card: Cell rules ── -->
                    <div class="lcni-im-card" style="grid-column:1/-1">
                        <div class="lcni-im-card-title">🎨 Cell rules (tô màu ô)</div>
                        <?php $this->render_cell_rules_table( $cell_rules, $metric_labels, $ohlc_cols, $mode ); ?>
                    </div>

                    <!-- ── Card: Gradient rules ── -->
                    <div class="lcni-im-card" style="grid-column:1/-1">
                        <div class="lcni-im-card-title">🌈 Row gradient rules</div>
                        <?php $this->render_gradient_rules_table( $gradient_rules, $metric_labels, $ohlc_cols, $mode ); ?>
                    </div>

                </div><!-- .lcni-im-form-grid -->

                <p class="submit" style="margin-top:20px">
                    <button type="submit" class="button button-primary button-large">💾 <?php echo $is_edit ? 'Lưu thay đổi' : 'Tạo monitor'; ?></button>
                    <a href="<?php echo esc_url( $back_url ); ?>" class="button button-large" style="margin-left:8px">Huỷ</a>
                </p>
            </form>
        </div>

        <?php $this->render_form_styles_scripts( $mode, $metric_labels, $ohlc_cols ); ?>
        <?php
    }

    private function render_cell_rules_table( array $rules, array $metric_labels, array $ohlc_cols, string $mode ) {
        ?>
        <table class="widefat" id="lcni-im-cell-rules-table" style="max-width:900px">
            <thead><tr>
                <th>Field</th><th>Operator</th><th>Value</th>
                <th>Background</th><th>Text color</th><th></th>
            </tr></thead>
            <tbody id="lcni-im-cell-rules-body">
                <?php foreach ( $rules as $i => $rule ): ?>
                    <?php $this->render_cell_rule_row( $i, $rule, $metric_labels, $ohlc_cols, $mode ); ?>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p><button type="button" class="button" id="lcni-im-add-cell-rule">+ Thêm rule</button></p>
        <template id="lcni-im-cell-rule-tpl">
            <?php $this->render_cell_rule_row( '__I__', [], $metric_labels, $ohlc_cols, $mode ); ?>
        </template>
        <?php
    }

    private function render_cell_rule_row( $i, array $rule, array $metric_labels, array $ohlc_cols, string $mode ) {
        $fields    = $mode === 'symbol' ? $ohlc_cols : $metric_labels;
        $sel_field = $rule['field']      ?? '';
        $sel_op    = $rule['operator']   ?? '>';
        $val       = $rule['value']      ?? '';
        $bg        = $rule['bg_color']   ?? '#ffffff';
        $tc        = $rule['text_color'] ?? '#111111';
        $n         = "lcni_im_cell_rules[{$i}]";
        ?>
        <tr>
            <td>
                <select name="<?php echo esc_attr( $n ); ?>[field]">
                    <option value="">-- chọn --</option>
                    <?php foreach ( $fields as $k => $l ): ?>
                    <option value="<?php echo esc_attr( $k ); ?>" <?php selected( $sel_field, $k ); ?>><?php echo esc_html( $l ); ?></option>
                    <?php endforeach; ?>
                </select>
            </td>
            <td>
                <select name="<?php echo esc_attr( $n ); ?>[operator]">
                    <?php foreach ( ['>' => '>', '=' => '=', '<' => '<'] as $o => $l ): ?>
                    <option value="<?php echo esc_attr( $o ); ?>" <?php selected( $sel_op, $o ); ?>><?php echo esc_html( $l ); ?></option>
                    <?php endforeach; ?>
                </select>
            </td>
            <td><input type="number" step="any" name="<?php echo esc_attr( $n ); ?>[value]" value="<?php echo esc_attr( (string) $val ); ?>" style="width:80px"></td>
            <td><input type="color" name="<?php echo esc_attr( $n ); ?>[bg_color]" value="<?php echo esc_attr( $bg ); ?>"></td>
            <td><input type="color" name="<?php echo esc_attr( $n ); ?>[text_color]" value="<?php echo esc_attr( $tc ); ?>"></td>
            <td><button type="button" class="button button-small lcni-im-del-row">✕</button></td>
        </tr>
        <?php
    }

    private function render_gradient_rules_table( array $rules, array $metric_labels, array $ohlc_cols, string $mode ) {
        ?>
        <table class="widefat" id="lcni-im-grad-rules-table" style="max-width:900px">
            <thead><tr>
                <th>Field</th><th>Màu âm</th><th>Màu neutral</th><th>Màu dương</th><th></th>
            </tr></thead>
            <tbody id="lcni-im-grad-rules-body">
                <?php foreach ( $rules as $i => $rule ): ?>
                    <?php $this->render_gradient_rule_row( $i, $rule, $metric_labels, $ohlc_cols, $mode ); ?>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p><button type="button" class="button" id="lcni-im-add-grad-rule">+ Thêm gradient rule</button></p>
        <template id="lcni-im-grad-rule-tpl">
            <?php $this->render_gradient_rule_row( '__I__', [], $metric_labels, $ohlc_cols, $mode ); ?>
        </template>
        <?php
    }

    private function render_gradient_rule_row( $i, array $rule, array $metric_labels, array $ohlc_cols, string $mode ) {
        $fields = $mode === 'symbol' ? $ohlc_cols : $metric_labels;
        $n      = "lcni_im_gradient_rules[{$i}]";
        ?>
        <tr>
            <td>
                <select name="<?php echo esc_attr( $n ); ?>[field]">
                    <option value="">-- chọn --</option>
                    <?php foreach ( $fields as $k => $l ): ?>
                    <option value="<?php echo esc_attr( $k ); ?>" <?php selected( $rule['field'] ?? '', $k ); ?>><?php echo esc_html( $l ); ?></option>
                    <?php endforeach; ?>
                </select>
            </td>
            <td><input type="color" name="<?php echo esc_attr( $n ); ?>[color_negative]" value="<?php echo esc_attr( $rule['color_negative'] ?? '#f87171' ); ?>"></td>
            <td><input type="color" name="<?php echo esc_attr( $n ); ?>[color_neutral]"  value="<?php echo esc_attr( $rule['color_neutral']  ?? '#f3f4f6' ); ?>"></td>
            <td><input type="color" name="<?php echo esc_attr( $n ); ?>[color_positive]" value="<?php echo esc_attr( $rule['color_positive'] ?? '#4ade80' ); ?>"></td>
            <td><button type="button" class="button button-small lcni-im-del-row">✕</button></td>
        </tr>
        <?php
    }

    private function render_form_styles_scripts( string $mode, array $metric_labels, array $ohlc_cols ) {
        $metric_json = wp_json_encode( array_keys( $metric_labels ) );
        $ohlc_json   = wp_json_encode( array_keys( $ohlc_cols ) );
        ?>
        <style>
        .lcni-im-form-grid { display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-top:16px; }
        .lcni-im-card { background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:18px 20px; }
        .lcni-im-card-title { font-size:14px; font-weight:700; color:#111827; margin-bottom:14px; padding-bottom:10px; border-bottom:1px solid #f3f4f6; }
        .lcni-im-card .form-table th { font-size:13px; font-weight:600; padding:8px 10px 8px 0; width:200px; }
        .lcni-im-card .form-table td { padding:6px 0; }
        .lcni-im-mode-section { transition:opacity .2s; }
        .lcni-im-mode-section.lcni-im-hidden { display:none; }
        @media (max-width:900px) { .lcni-im-form-grid { grid-template-columns:1fr; } }
        </style>
        <script>
        (function(){
            const icbMetrics = <?php echo $metric_json; ?>;
            const ohlcCols   = <?php echo $ohlc_json; ?>;

            function updateMode(mode) {
                document.querySelectorAll('.lcni-im-mode-section').forEach(el => {
                    const elMode = el.dataset.mode;
                    if (!elMode) return;
                    el.classList.toggle('lcni-im-hidden', elMode !== mode);
                });
                // Update field selects trong cell/gradient rules
                updateRuleSelects(mode);
            }

            function buildOptions(fields, selectedVal) {
                return '<option value="">-- chọn --</option>' +
                    fields.map(f => `<option value="${f}"${f===selectedVal?' selected':''}>${f}</option>`).join('');
            }

            function updateRuleSelects(mode) {
                const fields = mode === 'symbol' ? ohlcCols : icbMetrics;
                document.querySelectorAll('select[name*="[field]"]').forEach(sel => {
                    const cur = sel.value;
                    sel.innerHTML = buildOptions(fields, cur);
                });
            }

            // Init
            const modeInputs = document.querySelectorAll('input[name="lcni_im_mode"]');
            modeInputs.forEach(inp => {
                inp.addEventListener('change', () => updateMode(inp.value));
            });
            const initMode = document.querySelector('input[name="lcni_im_mode"]:checked')?.value || 'icb';
            updateMode(initMode);

            // Add/remove rule rows
            function addRow(tbodyId, templateId, reindexName) {
                const tbody = document.getElementById(tbodyId);
                const tpl   = document.getElementById(templateId);
                if (!tbody || !tpl) return;
                const idx = tbody.querySelectorAll('tr').length;
                // Must wrap in <table><tbody> so browser parses <tr> correctly
                const wrap = document.createElement('table');
                wrap.innerHTML = '<tbody>' + tpl.innerHTML.replace(/__I__/g, idx) + '</tbody>';
                const tr = wrap.querySelector('tr');
                if (tr) tbody.appendChild(tr);
                // Update field options to current mode
                const mode = document.querySelector('input[name="lcni_im_mode"]:checked')?.value || 'icb';
                const fields = mode === 'symbol' ? ohlcCols : icbMetrics;
                tr?.querySelectorAll('select[name*="[field]"]').forEach(sel => {
                    sel.innerHTML = buildOptions(fields, '');
                });
            }

            document.getElementById('lcni-im-add-cell-rule')?.addEventListener('click',
                () => addRow('lcni-im-cell-rules-body', 'lcni-im-cell-rule-tpl', 'lcni_im_cell_rules'));
            document.getElementById('lcni-im-add-grad-rule')?.addEventListener('click',
                () => addRow('lcni-im-grad-rules-body', 'lcni-im-grad-rule-tpl', 'lcni_im_gradient_rules'));

            document.addEventListener('click', e => {
                if (e.target.classList.contains('lcni-im-del-row')) {
                    e.target.closest('tr')?.remove();
                }
            });
        })();
        </script>
        <?php
    }
}
