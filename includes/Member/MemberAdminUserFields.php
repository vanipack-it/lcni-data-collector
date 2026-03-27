<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Thêm field "Gói SaaS" vào form tạo/edit user trong WordPress Admin.
 * Xử lý gán gói mặc định khi user mới đăng ký (thay thế hook user_register trong MemberAuthShortcodes).
 */
class LCNI_Member_Admin_User_Fields {

    private $service;

    public function __construct( LCNI_SaaS_Service $service ) {
        $this->service = $service;

        // Thêm field vào form tạo user mới (user-new.php)
        add_action( 'user_new_form',           [ $this, 'render_new_user_fields' ] );

        // Thêm field vào form edit user (user-edit.php / profile.php)
        add_action( 'edit_user_profile',        [ $this, 'render_edit_user_fields' ] );
        add_action( 'show_user_profile',        [ $this, 'render_edit_user_fields' ] );

        // Lưu khi tạo user mới (admin) + auto-assign frontend
        add_action( 'user_register',            [ $this, 'save_on_register' ], 10 );

        // Lưu khi EDIT user (không chạy khi tạo mới)
        // Chỉ hook edit_user_profile_update — fire khi admin sửa user khác
        // personal_options_update — fire khi user sửa profile của chính mình
        // Cả 2 đều KHÔNG fire trên user-new.php
        add_action( 'edit_user_profile_update', [ $this, 'save_on_edit' ] );
        add_action( 'personal_options_update',  [ $this, 'save_on_edit' ] );

        // CSS cho admin
        add_action( 'admin_head-user-new.php',  [ $this, 'admin_styles' ] );
        add_action( 'admin_head-user-edit.php', [ $this, 'admin_styles' ] );
        add_action( 'admin_head-profile.php',   [ $this, 'admin_styles' ] );
    }

    // =========================================================
    // Styles
    // =========================================================

    public function admin_styles() {
        echo '<style>
.lcni-pkg-section { margin: 20px 0; }
.lcni-pkg-section h2 { font-size: 1.3em; margin-bottom: 8px; }
.lcni-pkg-row { display: flex; align-items: flex-start; gap: 16px; flex-wrap: wrap; }
.lcni-pkg-col { flex: 1; min-width: 180px; }
.lcni-pkg-col label { display: block; font-weight: 600; font-size: 13px; margin-bottom: 4px; color: #1d2327; }
.lcni-pkg-col select,
.lcni-pkg-col input[type=date],
.lcni-pkg-col input[type=text] {
    width: 100%; padding: 6px 8px; border: 1px solid #8c8f94;
    border-radius: 4px; font-size: 13px; box-sizing: border-box;
    background: #fff;
}
.lcni-pkg-current {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 6px 12px; border-radius: 6px;
    background: #eff6ff; border: 1px solid #bfdbfe;
    color: #1e40af; font-size: 13px; font-weight: 600; margin-bottom: 10px;
}
.lcni-pkg-current .lcni-pkg-dot {
    width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0;
}
.lcni-pkg-expired { background: #fff7ed; border-color: #fed7aa; color: #9a3412; }
.lcni-pkg-none { background: #f9fafb; border-color: #e5e7eb; color: #6b7280; }
</style>';
    }

    // =========================================================
    // Render field — form tạo user mới
    // =========================================================

    public function render_new_user_fields( $operation ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $packages = $this->service->get_package_options();
        if ( empty( $packages ) ) {
            return;
        }

        // Hook user_new_form nằm BÊN TRONG <table class="form-table"> đang mở của WordPress.
        // Chỉ render <tr> rows, KHÔNG đóng/mở table mới, KHÔNG dùng <h3> ngoài <tr>.
        ?>
        <tr class="form-field">
            <th scope="row" colspan="2" style="padding-bottom:0;">
                <strong style="font-size:14px;">🎁 Gói SaaS</strong>
            </th>
        </tr>
        <tr class="form-field">
            <th scope="row"><label for="lcni_pkg_id">Gói dịch vụ</label></th>
            <td>
                <?php wp_nonce_field( 'lcni_admin_assign_package', 'lcni_pkg_nonce' ); ?>
                <div class="lcni-pkg-row">
                    <div class="lcni-pkg-col">
                        <label for="lcni_pkg_id">Chọn gói</label>
                        <select name="lcni_pkg_id" id="lcni_pkg_id">
                            <option value="0">— Không gán gói —</option>
                            <?php foreach ( $packages as $pkg ) : ?>
                            <option value="<?php echo esc_attr( $pkg['id'] ); ?>">
                                <?php echo esc_html( $pkg['package_name'] ); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="lcni-pkg-col">
                        <label for="lcni_pkg_expires">Hạn dùng</label>
                        <input type="date" name="lcni_pkg_expires" id="lcni_pkg_expires">
                        <p class="description">Để trống = vĩnh viễn</p>
                    </div>
                    <div class="lcni-pkg-col">
                        <label for="lcni_pkg_note">Ghi chú</label>
                        <input type="text" name="lcni_pkg_note" id="lcni_pkg_note"
                               placeholder="VD: Thanh toán tháng 3/2026">
                    </div>
                </div>
            </td>
        </tr>
        <?php
    }

    // =========================================================
    // Render field — form edit user
    // =========================================================

    public function render_edit_user_fields( $user ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $packages = $this->service->get_package_options();
        if ( empty( $packages ) ) {
            return;
        }

        // Lấy gói hiện tại của user
        $user_roles = $user->roles ?? [];
        $role_slug  = ! empty( $user_roles[0] ) ? $user_roles[0] : '';
        $current    = $this->get_user_package_row( $user->ID, $role_slug );

        $current_pkg_id  = $current ? (int) $current['package_id']  : 0;
        $current_expires = $current ? $current['expires_at']         : null;
        $current_note    = $current ? $current['note']               : '';
        $current_name    = $current ? $current['package_name']       : '';
        $current_color   = $current ? $current['color']              : '#94a3b8';
        $is_expired      = $current_expires && strtotime( $current_expires ) < time();
        ?>
        <h2>🎁 Gói SaaS</h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">Gói hiện tại</th>
                <td>
                    <?php if ( $current_pkg_id ) : ?>
                        <div class="lcni-pkg-current <?php echo $is_expired ? 'lcni-pkg-expired' : ''; ?>">
                            <span class="lcni-pkg-dot" style="background:<?php echo esc_attr( $current_color ); ?>;"></span>
                            <?php echo esc_html( $current_name ); ?>
                            <?php if ( $current_expires ) : ?>
                                &nbsp;·&nbsp; Hết hạn: <?php echo esc_html( date_i18n( get_option('date_format'), strtotime( $current_expires ) ) ); ?>
                                <?php if ( $is_expired ) echo ' ⚠️ Đã hết hạn'; ?>
                            <?php else : ?>
                                &nbsp;·&nbsp; Vĩnh viễn
                            <?php endif; ?>
                        </div>
                    <?php else : ?>
                        <div class="lcni-pkg-current lcni-pkg-none">Chưa có gói</div>
                    <?php endif; ?>

                    <?php wp_nonce_field( 'lcni_admin_assign_package', 'lcni_pkg_nonce' ); ?>
                    <div class="lcni-pkg-row" style="margin-top:10px;">
                        <div class="lcni-pkg-col">
                            <label for="lcni_pkg_id">Thay đổi gói</label>
                            <select name="lcni_pkg_id" id="lcni_pkg_id">
                                <option value="0">— Giữ nguyên / Không gán —</option>
                                <?php foreach ( $packages as $pkg ) : ?>
                                <option value="<?php echo esc_attr( $pkg['id'] ); ?>"
                                    <?php selected( $current_pkg_id, (int) $pkg['id'] ); ?>>
                                    <?php echo esc_html( $pkg['package_name'] ); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="lcni-pkg-col">
                            <label for="lcni_pkg_expires">Hạn dùng mới</label>
                            <input type="date" name="lcni_pkg_expires" id="lcni_pkg_expires"
                                   value="<?php echo $current_expires ? esc_attr( date('Y-m-d', strtotime($current_expires) ) ) : ''; ?>">
                            <p class="description">Để trống = vĩnh viễn</p>
                        </div>
                        <div class="lcni-pkg-col">
                            <label for="lcni_pkg_note">Ghi chú</label>
                            <input type="text" name="lcni_pkg_note" id="lcni_pkg_note"
                                   value="<?php echo esc_attr( $current_note ); ?>"
                                   placeholder="VD: Thanh toán tháng 3/2026">
                        </div>
                    </div>
                    <?php if ( $current_pkg_id ) : ?>
                    <p style="margin-top:8px;">
                        <label style="color:#dc2626;font-size:13px;">
                            <input type="checkbox" name="lcni_pkg_revoke" value="1">
                            Thu hồi gói hiện tại
                        </label>
                    </p>
                    <?php endif; ?>
                </td>
            </tr>
        </table>
        <?php
    }

    // =========================================================
    // Save — user_register: xử lý cả admin tạo lẫn frontend đăng ký
    // =========================================================

    public function save_on_register( $user_id ) {
        $user_id = (int) $user_id;
        if ( ! $user_id ) {
            return;
        }

        LCNI_SaaS_Repository::maybe_create_tables();

        // Trường hợp 1: Admin tạo user từ user-new.php — có nonce của chúng ta
        if ( ! empty( $_POST['lcni_pkg_nonce'] )
            && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lcni_pkg_nonce'] ) ), 'lcni_admin_assign_package' )
        ) {
            $pkg_id = absint( $_POST['lcni_pkg_id'] ?? 0 );
            if ( $pkg_id ) {
                $expires_raw = isset( $_POST['lcni_pkg_expires'] )
                    ? sanitize_text_field( wp_unslash( $_POST['lcni_pkg_expires'] ) ) : '';
                $note = isset( $_POST['lcni_pkg_note'] )
                    ? sanitize_text_field( wp_unslash( $_POST['lcni_pkg_note'] ) ) : 'Gán bởi admin';
                try {
                    $this->service->assign_package( $user_id, '', $pkg_id, $expires_raw ?: null, $note );
                } catch ( \Throwable $e ) {
                    $this->log_error( 'admin_create', $user_id, $e );
                }
            }
            return;
        }

        // Trường hợp 2: Frontend đăng ký — auto-assign gói mặc định
        $defaults = get_option( 'lcni_saas_default_packages', [] );
        if ( empty( $defaults ) ) {
            return;
        }
        $user = get_userdata( $user_id );
        if ( ! $user ) {
            return;
        }
        $role   = ! empty( $user->roles[0] ) ? sanitize_key( $user->roles[0] ) : 'subscriber';
        $pkg_id = absint( $defaults[ $role ] ?? $defaults['*'] ?? 0 );
        if ( ! $pkg_id ) {
            return;
        }
        $durations = get_option( 'lcni_saas_default_durations', [] );
        $days      = absint( $durations[ $role ] ?? $durations['*'] ?? 0 );
        $expires   = $days > 0 ? date( 'Y-m-d H:i:s', strtotime( "+{$days} days" ) ) : null;
        try {
            $this->service->assign_package( $user_id, '', $pkg_id, $expires, 'Gán tự động khi đăng ký' );
        } catch ( \Throwable $e ) {
            $this->log_error( 'auto_assign', $user_id, $e );
        }

        // Gửi email chào mừng đăng ký thành công
        $this->send_welcome_email( $user_id );
    }

    /**
     * Gửi email chào mừng sau khi đăng ký tài khoản.
     * Dùng LCNINotificationManager nếu có, fallback về wp_mail đơn giản.
     */
    private function send_welcome_email( int $user_id ): void {
        $user = get_userdata( $user_id );
        if ( ! $user || ! is_email( $user->user_email ) ) return;

        // Bỏ qua nếu user do admin tạo (có HTTP_REFERER chứa user-new.php)
        $referer = isset( $_SERVER['HTTP_REFERER'] ) ? (string) $_SERVER['HTTP_REFERER'] : '';
        if ( strpos( $referer, 'user-new.php' ) !== false ) return;

        if ( class_exists( 'LCNINotificationManager' ) ) {
            LCNINotificationManager::send( 'register_success', $user->user_email, [
                'user_name'  => $user->display_name ?: $user->user_login,
                'user_email' => $user->user_email,
            ] );
        }
    }

    // =========================================================
    // Save — edit user
    // =========================================================

    public function save_on_edit( $user_id ) {
        if ( ! current_user_can( 'edit_user', $user_id ) ) {
            return;
        }
        $this->save_package_fields( (int) $user_id );
    }

    // =========================================================
    // Core save logic (edit user)
    // =========================================================

    private function save_package_fields( $user_id ) {
        if ( empty( $_POST['lcni_pkg_nonce'] )
            || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lcni_pkg_nonce'] ) ), 'lcni_admin_assign_package' )
        ) {
            return;
        }

        // Thu hồi gói
        if ( ! empty( $_POST['lcni_pkg_revoke'] ) ) {
            $user      = get_userdata( $user_id );
            $role_slug = ( $user && ! empty( $user->roles[0] ) ) ? $user->roles[0] : '';
            try {
                $this->service->revoke_package( $user_id, $role_slug );
                $this->service->revoke_package( $user_id, '' );
            } catch ( \Throwable $e ) {
                $this->log_error( 'revoke', $user_id, $e );
            }
            return;
        }

        $pkg_id = absint( $_POST['lcni_pkg_id'] ?? 0 );
        if ( ! $pkg_id ) {
            return;
        }
        $expires_raw = isset( $_POST['lcni_pkg_expires'] )
            ? sanitize_text_field( wp_unslash( $_POST['lcni_pkg_expires'] ) ) : '';
        $note = isset( $_POST['lcni_pkg_note'] )
            ? sanitize_text_field( wp_unslash( $_POST['lcni_pkg_note'] ) ) : 'Gán bởi admin';
        try {
            $this->service->assign_package( $user_id, '', $pkg_id, $expires_raw ?: null, $note );
        } catch ( \Throwable $e ) {
            $this->log_error( 'assign', $user_id, $e );
        }
    }

    // =========================================================
    // Helpers
    // =========================================================

    private function get_user_package_row( $user_id, $role_slug ) {
        global $wpdb;
        $up = $wpdb->prefix . 'lcni_user_packages';
        $pk = $wpdb->prefix . 'lcni_saas_packages';

        // Kiểm tra bảng tồn tại
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$up}'" ) !== $up ) {
            return null;
        }

        // Ưu tiên: gán trực tiếp user_id (role_slug = '')
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT up.*, p.package_name, p.color, p.description
             FROM {$up} up
             LEFT JOIN {$pk} p ON p.id = up.package_id
             WHERE up.user_id = %d AND up.role_slug = '' LIMIT 1",
            $user_id
        ), ARRAY_A );

        // Fallback: gán theo role
        if ( ! $row && $role_slug !== '' ) {
            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT up.*, p.package_name, p.color, p.description
                 FROM {$up} up
                 LEFT JOIN {$pk} p ON p.id = up.package_id
                 WHERE up.user_id = 0 AND up.role_slug = %s LIMIT 1",
                $role_slug
            ), ARRAY_A );
        }

        return $row ?: null;
    }

    private function log_error( $action, $user_id, $e ) {
        if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
            error_log( sprintf(
                '[LCNI SaaS] %s failed for user %d: %s',
                $action, $user_id, $e->getMessage()
            ) );
        }
    }
}
