<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * UserRuleNotificationAdminPage
 *
 * Tab "User Rule" trong trang Thông báo admin.
 * URL: admin.php?page=lcni-notifications&tab=user-rule
 */
if ( ! class_exists( 'UserRuleNotificationAdminPage' ) ) :
class UserRuleNotificationAdminPage {

    public function __construct() {
        add_action( 'lcni_notification_admin_tabs', [ $this, 'add_tab' ] );
        add_action( 'lcni_notification_admin_content', [ $this, 'render_content' ] );
        add_action( 'admin_post_lcni_save_user_rule_notifications', [ $this, 'handle_save' ] );
    }

    public function add_tab( string $current_tab ): void {
        $active = $current_tab === 'user-rule' ? 'nav-tab-active' : '';
        echo '<a href="' . esc_url( admin_url('admin.php?page=lcni-notifications&tab=user-rule') ) . '" '
           . 'class="nav-tab ' . $active . '">🤖 User Rule</a>';
    }

    public function render_content( string $current_tab ): void {
        if ( $current_tab !== 'user-rule' ) return;
        $this->render();
    }

    public function handle_save(): void {
        if ( ! current_user_can('manage_options') ) wp_die('Unauthorized');
        check_admin_referer('lcni_save_user_rule_notifications');
        $data = $_POST['ur_notif'] ?? [];
        UserRuleNotifier::save_settings( (array) $data );
        wp_safe_redirect( admin_url('admin.php?page=lcni-notifications&tab=user-rule&saved=1') );
        exit;
    }

    // =========================================================================
    // RENDER
    // =========================================================================

    private function render(): void {
        $settings = UserRuleNotifier::get_settings();
        $defaults = UserRuleNotifier::get_defaults();

        if ( ! empty( $_GET['saved'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>✅ Đã lưu cài đặt thông báo User Rule.</p></div>';
        }

        $type_labels = [
            'ur_signal_opened'     => '📈 Signal mới được áp dụng',
            'ur_signal_closed'     => '🔔 Signal đóng vị thế',
            'ur_order_placed'      => '✅ Đặt lệnh DNSE thành công',
            'ur_order_failed'      => '⚠️ Đặt lệnh DNSE thất bại',
            'ur_dnse_token_expired'=> '🔑 Token DNSE hết hạn',
            'ur_max_symbols'       => 'ℹ️ Bỏ qua signal (đạt giới hạn mã)',
        ];

        $var_hints = [
            'ur_signal_opened'     => '{{rule_name}} {{symbol}} {{entry_price}} {{initial_sl}} {{shares}} {{allocated_capital}} {{trade_type}} {{user_name}} {{site_name}}',
            'ur_signal_closed'     => '{{rule_name}} {{symbol}} {{entry_price}} {{exit_price}} {{final_r}} {{pnl_vnd}} {{exit_reason_label}} {{holding_days}} {{user_name}} {{site_name}}',
            'ur_order_placed'      => '{{rule_name}} {{symbol}} {{entry_price}} {{shares}} {{dnse_order_id}} {{account_no}} {{user_name}} {{site_name}}',
            'ur_order_failed'      => '{{rule_name}} {{symbol}} {{error_message}} {{user_name}} {{site_name}}',
            'ur_dnse_token_expired'=> '{{rule_name}} {{symbol}} {{site_url}} {{user_name}} {{site_name}}',
            'ur_max_symbols'       => '{{rule_name}} {{symbol}} {{max_symbols}} {{user_name}} {{site_name}}',
        ];
        ?>
        <div style="max-width:900px">
            <h2 style="margin-bottom:6px">🤖 Thông báo email — User Rule</h2>
            <p style="color:#6b7280;margin-top:0;margin-bottom:20px">
                Cấu hình email gửi cho user khi chiến lược tự động thực hiện các hành động.
            </p>

            <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>">
                <?php wp_nonce_field('lcni_save_user_rule_notifications'); ?>
                <input type="hidden" name="action" value="lcni_save_user_rule_notifications">

                <?php foreach ( $type_labels as $type => $label ):
                    $tmpl = $settings[$type] ?? $defaults[$type] ?? [];
                    $hints = $var_hints[$type] ?? '';
                ?>
                <div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:20px;margin-bottom:18px">
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px">
                        <h3 style="margin:0;font-size:15px"><?php echo esc_html($label); ?></h3>
                        <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer">
                            <input type="checkbox" name="ur_notif[<?php echo esc_attr($type); ?>][enabled]" value="1"
                                   <?php checked( ! empty($tmpl['enabled']) ); ?>>
                            Bật
                        </label>
                    </div>

                    <?php if ($hints): ?>
                    <p style="font-size:11px;color:#9ca3af;margin:0 0 10px;background:#f9fafb;padding:6px 10px;border-radius:5px;font-family:monospace">
                        Biến khả dụng: <?php echo esc_html($hints); ?>
                    </p>
                    <?php endif; ?>

                    <table style="width:100%;border-collapse:collapse">
                        <?php foreach ( ['subject'=>'Tiêu đề (Subject)', 'heading'=>'Tiêu đề trong email', 'body'=>'Nội dung email (HTML)', 'extra'=>'Nội dung phụ (tuỳ chọn)'] as $field => $field_label ): ?>
                        <tr>
                            <td style="width:180px;padding:6px 12px 6px 0;vertical-align:top;font-size:13px;color:#374151;font-weight:500">
                                <?php echo esc_html($field_label); ?>
                            </td>
                            <td style="padding:4px 0">
                                <?php if ( $field === 'body' || $field === 'extra' ): ?>
                                <textarea name="ur_notif[<?php echo esc_attr($type); ?>][<?php echo $field; ?>]"
                                          rows="<?php echo $field==='body' ? 7 : 3; ?>"
                                          style="width:100%;font-family:monospace;font-size:12px;border:1px solid #d1d5db;border-radius:5px;padding:6px 8px"
                                ><?php echo esc_textarea( $tmpl[$field] ?? '' ); ?></textarea>
                                <?php else: ?>
                                <input type="text" name="ur_notif[<?php echo esc_attr($type); ?>][<?php echo $field; ?>]"
                                       value="<?php echo esc_attr( $tmpl[$field] ?? '' ); ?>"
                                       style="width:100%;font-size:13px;border:1px solid #d1d5db;border-radius:5px;padding:6px 8px">
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </table>

                    <!-- Send test button — dùng cùng class lcni-test-email-btn với hệ thống chính -->
                    <div style="margin-top:10px;text-align:right;display:flex;align-items:center;justify-content:flex-end;gap:10px">
                        <span class="lcni-test-email-status" style="font-size:13px"></span>
                        <button type="button" class="button button-secondary lcni-test-email-btn"
                                data-nonce="<?php echo esc_attr( wp_create_nonce('lcni_send_test_notification') ); ?>"
                                data-tab="<?php echo esc_attr($type); ?>"
                                data-email="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>"
                                style="font-size:12px">
                            📨 Gửi email test
                        </button>
                    </div>
                </div>
                <?php endforeach; ?>

                <p>
                    <button type="submit" class="button button-primary">💾 Lưu cài đặt</button>
                    <a href="<?php echo esc_url( add_query_arg(['tab'=>'user-rule','reset'=>'1']) ); ?>"
                       class="button" style="margin-left:8px"
                       onclick="return confirm('Khôi phục tất cả về mặc định?')">↺ Khôi phục mặc định</a>
                </p>
            </form>
        </div>

        <script>
        // Không cần JS riêng — nút test dùng class lcni-test-email-btn
        // được xử lý bởi JS chung trong LCNINotificationAdminPage (render_preview_styles)
        // qua event delegation: document.addEventListener('click', ...) bắt class này

        <?php if ( ! empty( $_GET['reset'] ) && current_user_can( 'manage_options' ) ) :
            delete_option( UserRuleNotifier::OPTION_KEY );
        ?>
        location.href = '<?php echo esc_url( admin_url( 'admin.php?page=lcni-notifications&tab=user-rule&saved=1' ) ); ?>';
        <?php endif; ?>
        </script>
        <?php
    }
}
endif; // class_exists UserRuleNotificationAdminPage
