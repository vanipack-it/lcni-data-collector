<?php
/**
 * Custom Index Admin Page
 * Trang quản lý chỉ số riêng trong WP Admin.
 * Menu: LCNI Data → Chỉ số tùy chỉnh
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class LCNI_Custom_Index_Admin {

    private LCNI_Custom_Index_Repository $repo;
    private LCNI_Custom_Index_Calculator $calc;

    public function __construct(
        LCNI_Custom_Index_Repository $repo,
        LCNI_Custom_Index_Calculator $calc
    ) {
        $this->repo = $repo;
        $this->calc = $calc;
        // Hooks được đăng ký bởi loader, không tự hook ở đây
    }

    public function register_menu(): void {
        add_submenu_page(
            'lcni-settings',
            'Chỉ số tùy chỉnh',
            'Chỉ số tùy chỉnh',
            'manage_options',
            'lcni_custom_index_page',
            [ $this, 'render_page' ]
        );
    }

    // =========================================================================
    // POST handler
    // =========================================================================

    public function handle_post(): void {
        if (
            ! isset( $_POST['lcni_ci_action'] ) ||
            ! current_user_can( 'manage_options' ) ||
            ! check_admin_referer( 'lcni_custom_index_action' )
        ) return;

        $action = sanitize_key( $_POST['lcni_ci_action'] );

        if ( $action === 'create' || $action === 'update' ) {
            $data = [
                'name'               => sanitize_text_field( wp_unslash( $_POST['name']         ?? '' ) ),
                'description'        => sanitize_textarea_field( wp_unslash( $_POST['description']  ?? '' ) ),
                'exchange'           => sanitize_text_field( wp_unslash( $_POST['exchange']      ?? '' ) ),
                'id_icb2'            => absint( $_POST['id_icb2'] ?? 0 ),
                'symbol_scope'       => sanitize_text_field( wp_unslash( $_POST['symbol_scope']  ?? 'all' ) ),
                'scope_watchlist_id' => absint( $_POST['scope_watchlist_id'] ?? 0 ),
                'scope_custom_list'  => sanitize_textarea_field( wp_unslash( $_POST['scope_custom_list'] ?? '' ) ),
                'is_active'          => ! empty( $_POST['is_active'] ) ? 1 : 0,
            ];

            if ( $action === 'create' ) {
                $id = $this->repo->create( $data );
                wp_redirect( admin_url( 'admin.php?page=lcni_custom_index_page&created=' . $id ) );
            } else {
                $id = absint( $_POST['index_id'] ?? 0 );
                $this->repo->update( $id, $data );
                wp_redirect( admin_url( 'admin.php?page=lcni_custom_index_page&updated=' . $id ) );
            }
            exit;
        }

        if ( $action === 'delete' ) {
            $id = absint( $_POST['index_id'] ?? 0 );
            $this->repo->delete( $id );
            wp_redirect( admin_url( 'admin.php?page=lcni_custom_index_page&deleted=1' ) );
            exit;
        }

        if ( $action === 'backfill' ) {
            $id    = absint( $_POST['index_id']  ?? 0 );
            $tf    = sanitize_text_field( $_POST['timeframe'] ?? '1D' );
            $reset = ! empty( $_POST['reset_first'] );
            if ( $reset ) $this->repo->reset_ohlc( $id );
            $index   = $this->repo->find( $id );
            $created = $index ? $this->calc->backfill( $index, $tf ) : 0;
            wp_redirect( admin_url( "admin.php?page=lcni_custom_index_page&backfilled={$created}&index_id={$id}" ) );
            exit;
        }
    }

    // =========================================================================
    // RENDER
    // =========================================================================

    public function render_page(): void {
        // Safety check
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Bạn không có quyền truy cập trang này.' );
        }

        $edit_id = absint( $_GET['edit'] ?? 0 );
        $editing = $edit_id > 0 ? $this->repo->find( $edit_id ) : null;

        // ICB2 list for dropdown
        global $wpdb;
        $icb2_list = $wpdb->get_results(
            "SELECT id_icb2, name_icb2 FROM {$wpdb->prefix}lcni_icb2 ORDER BY name_icb2 ASC",
            ARRAY_A
        ) ?: [];

        // Watchlists of current admin
        $wl_table  = $wpdb->prefix . 'lcni_watchlists';
        $watchlists = $wpdb->get_results(
            $wpdb->prepare( "SELECT id, name FROM {$wl_table} WHERE user_id = %d ORDER BY id ASC", get_current_user_id() ),
            ARRAY_A
        ) ?: [];

        $indexes = $this->repo->all();

        echo '<div class="wrap">';
        echo '<h1>Chỉ số tùy chỉnh (Value-Weighted)</h1>';

        $this->render_notices();

        echo '<div style="display:grid;grid-template-columns:400px 1fr;gap:24px;align-items:start">';

        // ── LEFT: Form ────────────────────────────────────────────────────────
        echo '<div>';
        $is_edit = $editing !== null;
        $action_val = $is_edit ? 'update' : 'create';
        $saved_scope = sanitize_text_field( (string)( $editing['symbol_scope'] ?? 'all' ) );
        echo '<h2>' . ( $is_edit ? 'Chỉnh sửa: ' . esc_html( $editing['name'] ) : 'Tạo chỉ số mới' ) . '</h2>';
        echo '<form method="post">';
        wp_nonce_field( 'lcni_custom_index_action' );
        echo '<input type="hidden" name="lcni_ci_action" value="' . esc_attr( $action_val ) . '">';
        if ( $is_edit ) {
            echo '<input type="hidden" name="index_id" value="' . esc_attr( $editing['id'] ) . '">';
        }

        $this->field_text( 'Tên chỉ số', 'name', $editing['name'] ?? '', true );
        $this->field_textarea( 'Mô tả', 'description', $editing['description'] ?? '' );

        // Exchange filter
        $exchanges = [ '' => 'Tất cả sàn', 'HOSE' => 'HOSE', 'HNX' => 'HNX', 'UPCOM' => 'UPCOM' ];
        echo '<p><label><strong>Sàn giao dịch</strong><br>';
        echo '<select name="exchange" class="regular-text">';
        foreach ( $exchanges as $val => $label ) {
            $sel = ( ( $editing['exchange'] ?? '' ) === $val ) ? ' selected' : '';
            echo '<option value="' . esc_attr( $val ) . '"' . $sel . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select></label></p>';

        // ICB2 filter
        echo '<p><label><strong>Ngành ICB2</strong><br>';
        echo '<select name="id_icb2" class="regular-text">';
        echo '<option value="0">Tất cả ngành</option>';
        foreach ( $icb2_list as $icb ) {
            $sel = ( absint( $editing['id_icb2'] ?? 0 ) === (int) $icb['id_icb2'] ) ? ' selected' : '';
            echo '<option value="' . esc_attr( $icb['id_icb2'] ) . '"' . $sel . '>' . esc_html( $icb['name_icb2'] ) . '</option>';
        }
        echo '</select></label></p>';

        // Symbol scope
        echo '<p><strong>Phạm vi mã</strong></p>';
        echo '<div style="display:flex;flex-direction:column;gap:6px;margin-bottom:12px">';

        foreach ( [
            'all'       => 'Toàn thị trường (theo sàn/ngành trên)',
            'watchlist' => 'Theo watchlist của tôi',
            'custom'    => 'Nhập mã thủ công',
        ] as $val => $label ) {
            $chk = $saved_scope === $val ? ' checked' : '';
            echo '<label><input type="radio" name="symbol_scope" value="' . esc_attr( $val ) . '"'
                . $chk . ' onchange="lcniCiToggleScope(\'' . esc_js( $val ) . '\')"> ' . esc_html( $label ) . '</label>';

            if ( $val === 'watchlist' ) {
                $display = $saved_scope === 'watchlist' ? '' : 'display:none';
                echo '<div id="lci-scope-wl" style="margin-left:20px;' . $display . '">';
                echo '<select name="scope_watchlist_id">';
                echo '<option value="0">— Chọn watchlist —</option>';
                foreach ( $watchlists as $wl ) {
                    $sel = ( absint( $editing['scope_watchlist_id'] ?? 0 ) === (int) $wl['id'] ) ? ' selected' : '';
                    echo '<option value="' . esc_attr( $wl['id'] ) . '"' . $sel . '>' . esc_html( $wl['name'] ) . '</option>';
                }
                echo '</select></div>';
            }

            if ( $val === 'custom' ) {
                $display = $saved_scope === 'custom' ? '' : 'display:none';
                $saved_custom = esc_textarea( (string)( $editing['scope_custom_list'] ?? '' ) );
                echo '<div id="lci-scope-custom" style="margin-left:20px;' . $display . '">';
                echo '<textarea name="scope_custom_list" rows="3" class="large-text" placeholder="HPG,VNM,FPT,ACB">' . $saved_custom . '</textarea>';
                echo '<p class="description">Phân cách bằng dấu phẩy.</p>';
                echo '</div>';
            }
        }
        echo '</div>';

        echo '<p><label><input type="checkbox" name="is_active" value="1"'
            . ( ! isset( $editing ) || ! empty( $editing['is_active'] ) ? ' checked' : '' )
            . '> Active</label></p>';

        submit_button( $is_edit ? 'Cập nhật' : 'Tạo chỉ số' );
        echo '</form>';

        // Backfill form (chỉ hiện khi edit)
        if ( $is_edit ) {
            echo '<hr>';
            echo '<h3>Tính lại lịch sử OHLC</h3>';
            $count = $this->repo->get_ohlc_count( (int) $editing['id'] );
            echo '<p>Đã có <strong>' . number_format( $count ) . '</strong> phiên 1D.</p>';
            echo '<form method="post" onsubmit="return confirm(\'Xác nhận tính lại? Quá trình có thể mất vài phút.\')">';
            wp_nonce_field( 'lcni_custom_index_action' );
            echo '<input type="hidden" name="lcni_ci_action" value="backfill">';
            echo '<input type="hidden" name="index_id" value="' . esc_attr( $editing['id'] ) . '">';
            echo '<input type="hidden" name="timeframe" value="1D">';
            echo '<p><label><input type="checkbox" name="reset_first" value="1"> Xóa dữ liệu cũ trước khi tính lại</label></p>';
            submit_button( '▶ Backfill toàn bộ lịch sử 1D', 'secondary' );
            echo '</form>';
        }
        echo '</div>';

        // ── RIGHT: Table ──────────────────────────────────────────────────────
        echo '<div>';
        echo '<h2>Danh sách chỉ số (' . count( $indexes ) . ')</h2>';
        if ( empty( $indexes ) ) {
            echo '<p>Chưa có chỉ số nào. Tạo chỉ số đầu tiên ở bên trái.</p>';
        } else {
            echo '<table class="widefat striped">';
            echo '<thead><tr><th>ID</th><th>Tên</th><th>Sàn</th><th>Scope</th><th>Phiên 1D</th><th>Giá trị mới nhất</th><th>Active</th><th></th></tr></thead>';
            echo '<tbody>';
            foreach ( $indexes as $idx ) {
                $iid    = (int) $idx['id'];
                $count  = $this->repo->get_ohlc_count( $iid );
                $latest = $this->calc->get_latest_candle( $iid, '1D' );
                $close  = $latest ? number_format( (float) $latest['close_value'], 2 ) : '—';
                $scope_labels = [ 'all' => 'Toàn TT', 'watchlist' => 'Watchlist', 'custom' => 'Tuỳ chọn' ];
                $scope_badge  = $scope_labels[ $idx['symbol_scope'] ] ?? $idx['symbol_scope'];

                echo '<tr>';
                echo '<td>' . esc_html( $iid ) . '</td>';
                echo '<td><strong>' . esc_html( $idx['name'] ) . '</strong>';
                if ( $idx['description'] ) echo '<br><small>' . esc_html( mb_substr( $idx['description'], 0, 60 ) ) . '</small>';
                echo '</td>';
                echo '<td>' . esc_html( $idx['exchange'] ?: 'Tất cả' ) . '</td>';
                echo '<td><span style="font-size:11px;padding:2px 7px;border-radius:10px;background:#0073aa;color:#fff">' . esc_html( $scope_badge ) . '</span></td>';
                echo '<td>' . number_format( $count ) . '</td>';
                echo '<td><strong>' . esc_html( $close ) . '</strong></td>';
                echo '<td>' . ( $idx['is_active'] ? '✅' : '—' ) . '</td>';
                echo '<td style="white-space:nowrap">';
                echo '<a href="' . esc_url( admin_url( 'admin.php?page=lcni_custom_index_page&edit=' . $iid ) ) . '" class="button button-small">Sửa</a> ';
                echo '<form method="post" style="display:inline" onsubmit="return confirm(\'Xóa chỉ số này và toàn bộ OHLC?\')">';
                wp_nonce_field( 'lcni_custom_index_action' );
                echo '<input type="hidden" name="lcni_ci_action" value="delete">';
                echo '<input type="hidden" name="index_id" value="' . esc_attr( $iid ) . '">';
                echo '<button class="button button-small" style="color:#a00">Xóa</button>';
                echo '</form>';
                echo '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';
        echo '</div>'; // grid

        $this->render_js();
        echo '</div>'; // wrap
    }

    private function render_notices(): void {
        if ( isset( $_GET['created'] ) ) echo '<div class="notice notice-success"><p>✅ Đã tạo chỉ số #' . absint( $_GET['created'] ) . '.</p></div>';
        if ( isset( $_GET['updated'] ) ) echo '<div class="notice notice-success"><p>✅ Đã cập nhật.</p></div>';
        if ( isset( $_GET['deleted'] ) ) echo '<div class="notice notice-success"><p>✅ Đã xóa.</p></div>';
        if ( isset( $_GET['backfilled'] ) ) {
            $n = absint( $_GET['backfilled'] );
            echo '<div class="notice notice-success"><p>✅ Backfill xong: <strong>' . number_format( $n ) . '</strong> phiên.</p></div>';
        }
    }

    private function field_text( string $label, string $name, string $value, bool $required = false ): void {
        $req = $required ? ' required' : '';
        echo '<p><label><strong>' . esc_html( $label ) . '</strong><br>';
        echo '<input type="text" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" class="regular-text"' . $req . '></label></p>';
    }

    private function field_textarea( string $label, string $name, string $value ): void {
        echo '<p><label><strong>' . esc_html( $label ) . '</strong><br>';
        echo '<textarea name="' . esc_attr( $name ) . '" rows="2" class="large-text">' . esc_textarea( $value ) . '</textarea></label></p>';
    }

    private function render_js(): void {
        ?>
<script>
function lcniCiToggleScope(val) {
    document.getElementById('lci-scope-wl').style.display     = val === 'watchlist' ? '' : 'none';
    document.getElementById('lci-scope-custom').style.display = val === 'custom'    ? '' : 'none';
}
</script>
        <?php
    }
}
