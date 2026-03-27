<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Trang quản trị: LCNi Member → Yêu cầu nâng cấp
 * Menu slug: lcni-upgrade-requests
 */
class LCNI_Upgrade_Request_Admin_Page {

    private LCNI_Upgrade_Request_Service    $service;
    private LCNI_Upgrade_Request_Repository $repo;

    public function __construct(
        LCNI_Upgrade_Request_Service    $service,
        LCNI_Upgrade_Request_Repository $repo
    ) {
        $this->service = $service;
        $this->repo    = $repo;

        add_action( 'admin_post_lcni_upgrade_review', [ $this, 'handle_review_post' ] );
        add_action( 'admin_post_lcni_save_broker_companies',  [ $this, 'handle_save_broker_companies' ] );
        add_action( 'admin_post_lcni_save_payment_settings',  [ $this, 'handle_save_payment_settings' ] );
        add_action( 'admin_post_lcni_save_package_prices',    [ $this, 'handle_save_package_prices' ] );
    }

    // ─── Handle POST ─────────────────────────────────────────────────────────

    public function handle_review_post(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Forbidden' );
        }
        check_admin_referer( 'lcni_upgrade_review_nonce' );

        $request_id = (int) ( $_POST['request_id'] ?? 0 );
        $action     = sanitize_key( $_POST['review_action'] ?? '' );
        $note       = sanitize_textarea_field( $_POST['admin_note'] ?? '' );

        if ( $request_id && $action ) {
            $result = $this->service->admin_update( $request_id, $action, $note );
        }

        $base = admin_url( 'admin.php?page=lcni-member-settings&tab=upgrades' );
        $redirect = $request_id ? add_query_arg( 'detail', $request_id, $base ) : $base;
        if ( isset( $result ) && is_wp_error( $result ) ) {
            $redirect = add_query_arg( 'lcni_error', urlencode( $result->get_error_message() ), $redirect );
        } else {
            $redirect = add_query_arg( 'lcni_saved', '1', $redirect );
        }
        wp_safe_redirect( $redirect );
        exit;
    }

    // ─── Render (được gọi từ MemberSettingsPage::render_upgrades_tab) ────────

    public function render(): void {
        $detail_id = (int) ( $_GET['detail'] ?? 0 );

        $this->render_styles();

        if ( $detail_id ) {
            $this->render_detail( $detail_id );
        } else {
            $this->render_list();
        }
    }

    // ─── List view ───────────────────────────────────────────────────────────

    private function render_list(): void {
        $status_filter = sanitize_key( $_GET['status'] ?? '' );
        $requests      = $this->repo->get_all( $status_filter );

        $status_tabs = [
            ''          => 'Tất cả',
            'pending'   => 'Đang chờ',
            'contacted' => 'Đang xử lý',
            'approved'  => 'Đã duyệt',
            'rejected'  => 'Từ chối',
        ];
        $status_colors = [
            'pending'   => '#f59e0b',
            'contacted' => '#3b82f6',
            'approved'  => '#16a34a',
            'rejected'  => '#dc2626',
        ];
        $step_labels = [
            'submitted' => '① Gửi yêu cầu',
            'contacted' => '② Liên hệ',
            'done'      => '③ Hoàn thành',
        ];
        ?>
        <h1 class="wp-heading-inline">📋 Yêu cầu nâng cấp gói</h1>
        <hr class="wp-header-end">

        <?php if ( ! empty( $_GET['lcni_saved'] ) ): ?>
            <div class="notice notice-success is-dismissible"><p>✅ Đã cập nhật thành công.</p></div>
        <?php endif; ?>
        <?php if ( ! empty( $_GET['lcni_error'] ) ): ?>
            <div class="notice notice-error is-dismissible"><p>❌ <?php echo esc_html( urldecode( $_GET['lcni_error'] ) ); ?></p></div>
        <?php endif; ?>

        <!-- Status tabs -->
        <div class="lcni-ur-admin-tabs">
            <?php foreach ( $status_tabs as $key => $label ):
                $url     = admin_url( 'admin.php?page=lcni-member-settings&tab=upgrades' . ( $key ? '&status=' . $key : '' ) );
                $active  = $status_filter === $key;
                $count   = count( array_filter( $this->repo->get_all( $key ), fn($r) => true ) );
                if ( $key !== '' ) {
                    $all = $this->repo->get_all($key);
                    $count = count($all);
                } else {
                    $count = count( $this->repo->get_all('') );
                }
                ?>
                <a href="<?php echo esc_url($url); ?>"
                   class="lcni-ur-admin-tab <?php echo $active ? 'active' : ''; ?>">
                    <?php echo esc_html($label); ?>
                    <span class="lcni-ur-admin-tab-count"><?php echo $count; ?></span>
                </a>
            <?php endforeach; ?>
        </div>

        <table class="lcni-ur-admin-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>User</th>
                    <th>Họ tên</th>
                    <th>SĐT / Email</th>
                    <th>Công ty CK</th>
                    <th>ID CK</th>
                    <th>Luồng</th>
                    <th>Thời hạn / Số tiền</th>
                    <th>Gói hiện tại → Nâng lên</th>
                    <th>Bước</th>
                    <th>Trạng thái</th>
                    <th>Ngày gửi</th>
                    <th>Thao tác</th>
                </tr>
            </thead>
            <tbody>
            <?php if ( empty($requests) ): ?>
                <tr><td colspan="13" style="text-align:center;color:#6b7280;padding:24px">Không có yêu cầu nào.</td></tr>
            <?php else: foreach ( $requests as $r ):
                $color = $status_colors[ $r['status'] ] ?? '#6b7280';
                $from  = esc_html( $r['from_package_name'] ?? '—' );
                $to    = esc_html( $r['to_package_name']   ?? '—' );
                $step  = esc_html( $step_labels[ $r['step'] ] ?? $r['step'] );
                $detail_url = admin_url( 'admin.php?page=lcni-member-settings&tab=upgrades&detail=' . $r['id'] );
                ?>
                <tr>
                    <td><strong><?php echo (int)$r['id']; ?></strong></td>
                    <td><?php echo esc_html( $r['user_display_name'] ?? '—' ); ?></td>
                    <td><?php echo esc_html( $r['full_name'] ); ?></td>
                    <td>
                        <?php echo esc_html( $r['phone'] ); ?><br>
                        <small><?php echo esc_html( $r['email'] ); ?></small>
                    </td>
                    <td><?php echo esc_html( $r['broker_company'] ?: '—' ); ?></td>
                    <td><?php echo esc_html( $r['broker_id'] ?: '—' ); ?></td>
                    <td style="font-size:12px">
                        <?php echo $r['flow'] === 'payment' ? '<span style="color:#7c3aed;font-weight:600">💳 Trả phí</span>' : '<span style="color:#0369a1">🏦 Broker</span>'; ?>
                    </td>
                    <td style="font-size:12px">
                        <?php
                        if ($r['flow']==='payment') {
                            $dur = (int)$r['duration_months'];
                            $dur_map = array(1=>'1 tháng',3=>'3 tháng',6=>'6 tháng',12=>'1 năm');
                            echo $dur > 0 ? esc_html(isset($dur_map[$dur]) ? $dur_map[$dur] : $dur.' tháng') : '&mdash;';
                            if ((float)$r['payment_amount'] > 0) {
                                echo '<br><small style="color:#16a34a">'.number_format((float)$r['payment_amount'],0,',','.').' đ</small>';
                            }
                        } else {
                            echo '&mdash;';
                        }
                        ?>
                    </td>
                    <td>
                        <span style="color:#6b7280"><?php echo $from; ?></span>
                        <span style="color:#9ca3af"> → </span>
                        <strong style="color:#2563eb"><?php echo $to; ?></strong>
                    </td>
                    <td style="font-size:12px"><?php echo $step; ?></td>
                    <td>
                        <span class="lcni-ur-admin-badge" style="color:<?php echo $color; ?>;border-color:<?php echo $color; ?>">
                            <?php echo esc_html( $r['status'] ); ?>
                        </span>
                    </td>
                    <td style="font-size:12px;color:#6b7280"><?php echo esc_html( date('d/m/Y H:i', strtotime($r['created_at'])) ); ?></td>
                    <td>
                        <a href="<?php echo esc_url($detail_url); ?>" class="button button-small">Xem & Duyệt</a>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
        <?php
    }

    // ─── Detail view ─────────────────────────────────────────────────────────

    private function render_detail( int $id ): void {
        $r = $this->repo->get_by_id( $id );
        if ( ! $r ) {
            echo '<div class="notice notice-error"><p>Không tìm thấy yêu cầu.</p></div>';
            return;
        }

        $list_url = admin_url('admin.php?page=lcni-member-settings&tab=upgrades');
        $status_colors = [
            'pending'   => '#f59e0b',
            'contacted' => '#3b82f6',
            'approved'  => '#16a34a',
            'rejected'  => '#dc2626',
        ];
        $color = $status_colors[ $r['status'] ] ?? '#6b7280';
        $is_done = in_array( $r['status'], ['approved','rejected'], true );

        ?>
        <a href="<?php echo esc_url($list_url); ?>" class="lcni-ur-back">← Danh sách</a>
        <h1>Yêu cầu nâng cấp #<?php echo (int)$r['id']; ?>
            <span class="lcni-ur-admin-badge" style="color:<?php echo $color; ?>;border-color:<?php echo $color; ?>;font-size:13px;margin-left:10px">
                <?php echo esc_html($r['status']); ?>
            </span>
        </h1>

        <?php if ( ! empty( $_GET['lcni_saved'] ) ): ?>
            <div class="notice notice-success is-dismissible"><p>✅ Đã cập nhật thành công.</p></div>
        <?php endif; ?>

        <div class="lcni-ur-detail-grid">
            <!-- Info card -->
            <div class="lcni-ur-detail-card">
                <h3>Thông tin người dùng</h3>
                <table class="lcni-ur-info-table">
                    <tr><th>Họ và tên</th><td><?php echo esc_html($r['full_name']); ?></td></tr>
                    <tr><th>Số điện thoại</th><td><?php echo esc_html($r['phone']); ?></td></tr>
                    <tr><th>Email</th><td><?php echo esc_html($r['email']); ?></td></tr>
                    <tr><th>Công ty CK</th><td><?php echo esc_html($r['broker_company'] ?: '—'); ?></td></tr>
                    <tr><th>ID tại công ty CK</th><td><?php echo esc_html($r['broker_id'] ?: '—'); ?></td></tr>
                    <tr><th>WordPress User</th><td>
                        <?php
                        $u = get_userdata( (int)$r['user_id'] );
                        echo $u ? esc_html($u->display_name) . ' <small>(' . esc_html($u->user_email) . ')</small>' : '—';
                        ?>
                    </td></tr>
                    <tr><th>Gói hiện tại</th><td><?php echo esc_html($r['from_package_name'] ?? '—'); ?></td></tr>
                    <tr><th>Gói muốn nâng cấp</th><td><strong style="color:#2563eb"><?php echo esc_html($r['to_package_name'] ?? '—'); ?></strong></td></tr>
                    <tr><th>Ngày gửi</th><td><?php echo esc_html(date('d/m/Y H:i', strtotime($r['created_at']))); ?></td></tr>
                    <?php if ($r['reviewed_at']): ?>
                        <tr><th>Ngày duyệt</th><td><?php echo esc_html(date('d/m/Y H:i', strtotime($r['reviewed_at']))); ?></td></tr>
                    <?php endif; ?>
                    <?php if ($r['admin_note']): ?>
                        <tr><th>Ghi chú</th><td><?php echo esc_html($r['admin_note']); ?></td></tr>
                    <?php endif; ?>
                </table>
            </div>

            <!-- Progress card -->
            <div class="lcni-ur-detail-card">
                <h3>Tiến trình xử lý</h3>
                <?php $this->render_admin_steps($r); ?>

                <?php if ( ! $is_done ): ?>
                    <h3 style="margin-top:24px">Thao tác</h3>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('lcni_upgrade_review_nonce'); ?>
                        <input type="hidden" name="action" value="lcni_upgrade_review">
                        <input type="hidden" name="request_id" value="<?php echo (int)$r['id']; ?>">

                        <div style="margin-bottom:12px">
                            <label style="display:block;font-weight:600;margin-bottom:5px;font-size:13px">Ghi chú cho user (sẽ ghi vào email)</label>
                            <textarea name="admin_note" rows="3" style="width:100%;border:1px solid #d1d5db;border-radius:6px;padding:8px;font-size:13px"><?php echo esc_textarea($r['admin_note']); ?></textarea>
                        </div>

                        <div class="lcni-ur-action-btns">
                            <?php if ($r['status'] === 'pending'): ?>
                                <button type="submit" name="review_action" value="contacted" class="button button-secondary">
                                    📞 Đánh dấu Đã liên hệ
                                </button>
                            <?php endif; ?>
                            <button type="submit" name="review_action" value="approved"
                                    class="button button-primary"
                                    onclick="return confirm('Xác nhận DUYỆT và tự động nâng cấp gói cho user?')">
                                ✅ Duyệt & Nâng cấp gói
                            </button>
                            <button type="submit" name="review_action" value="rejected"
                                    class="button"
                                    style="background:#fef2f2;color:#dc2626;border-color:#fca5a5"
                                    onclick="return confirm('Xác nhận TỪ CHỐI yêu cầu này?')">
                                ❌ Từ chối
                            </button>
                        </div>
                    </form>
                <?php else: ?>
                    <div class="lcni-ur-done-banner" style="background:<?php echo $r['status']==='approved' ? '#f0fdf4' : '#fef2f2'; ?>;border-color:<?php echo $r['status']==='approved' ? '#bbf7d0' : '#fecaca'; ?>">
                        <?php if($r['status']==='approved'): ?>
                            ✅ Yêu cầu đã được <strong>phê duyệt</strong>. Gói của user đã được nâng cấp tự động.
                        <?php else: ?>
                            ❌ Yêu cầu đã bị <strong>từ chối</strong>.
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    private function render_admin_steps( array $r ): void {
        $steps = [
            'submitted' => [ 'Gửi yêu cầu',    '①' ],
            'contacted' => [ 'Liên hệ hỗ trợ', '②' ],
            'done'      => [ 'Hoàn thành',      '③' ],
        ];
        $keys    = array_keys($steps);
        $cur_idx = array_search($r['step'], $keys);
        ?>
        <div class="lcni-ur-admin-steps">
            <?php foreach ($steps as $key => [$label, $num]):
                $idx  = array_search($key, $keys);
                $done = $idx <= $cur_idx;
                if ($idx === $cur_idx && in_array($r['status'], ['approved','rejected'], true)) {
                    $cls = $r['status'] === 'approved' ? 'approved' : 'rejected';
                } else {
                    $cls = $done ? 'done' : 'pending';
                }
                ?>
                <div class="lcni-ur-astep lcni-ur-astep--<?php echo $cls; ?>">
                    <div class="lcni-ur-astep-dot">
                        <?php if ($cls==='approved') echo '✓';
                        elseif ($cls==='rejected') echo '✕';
                        elseif ($done) echo '✓';
                        else echo $num; ?>
                    </div>
                    <div class="lcni-ur-astep-label"><?php echo esc_html($label); ?></div>
                </div>
                <?php if ($idx < count($steps)-1): ?>
                    <div class="lcni-ur-astep-line lcni-ur-astep-line--<?php echo $idx < $cur_idx ? 'done' : 'pending'; ?>"></div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
        <?php
    }

    // ─── Broker Companies Settings ───────────────────────────────────────────

    public function handle_save_broker_companies(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden' );
        check_admin_referer( 'lcni_save_broker_companies' );
        $raw = sanitize_textarea_field( wp_unslash( $_POST['broker_companies'] ?? '' ) );
        update_option( 'lcni_broker_companies', $raw );
        wp_safe_redirect( add_query_arg( [ 'page' => 'lcni-member-settings', 'tab' => 'upgrades', 'lcni_saved' => '1' ], admin_url('admin.php') ) );
        exit;
    }

    public function render_broker_settings(): void {
        $current = get_option( 'lcni_broker_companies', '' );
        ?>
        <div class="lcni-ur-broker-box">
            <h3>⚙️ Danh sách công ty chứng khoán</h3>
            <p style="color:#6b7280;font-size:13px;margin:0 0 12px">Mỗi dòng một tên công ty. Danh sách này sẽ hiển thị trong dropdown cho user khi đăng ký nâng cấp.</p>
            <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>">
                <?php wp_nonce_field('lcni_save_broker_companies'); ?>
                <input type="hidden" name="action" value="lcni_save_broker_companies">
                <textarea name="broker_companies" rows="8" style="width:100%;max-width:500px;border:1px solid #d1d5db;border-radius:6px;padding:10px;font-size:13px;font-family:monospace"><?php echo esc_textarea( $current ); ?></textarea>
                <br>
                <button type="submit" class="button button-primary" style="margin-top:8px">💾 Lưu danh sách</button>
            </form>
            <p style="font-size:12px;color:#9ca3af;margin-top:8px">Ví dụ:<br>VNDirect<br>SSI Securities<br>VCSC<br>Mirae Asset</p>
        </div>
        <?php
    }

    public function handle_save_payment_settings(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden' );
        check_admin_referer( 'lcni_save_payment_settings' );
        update_option( 'lcni_payment_qr_url',       esc_url_raw(         wp_unslash( $_POST['qr_url']       ?? '' ) ) );
        update_option( 'lcni_payment_bank_name',    sanitize_text_field( wp_unslash( $_POST['bank_name']    ?? '' ) ) );
        update_option( 'lcni_payment_account_no',   sanitize_text_field( wp_unslash( $_POST['account_no']   ?? '' ) ) );
        update_option( 'lcni_payment_account_name', sanitize_text_field( wp_unslash( $_POST['account_name'] ?? '' ) ) );

        // Upload QR image nếu có
        if ( ! empty( $_FILES['qr_file']['name'] ) ) {
            if ( ! function_exists('wp_handle_upload') ) require_once ABSPATH . 'wp-admin/includes/file.php';
            $up = wp_handle_upload( $_FILES['qr_file'], [ 'test_form' => false, 'mimes' => ['jpg|jpeg|jpe'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif'] ] );
            if ( ! empty( $up['url'] ) ) update_option( 'lcni_payment_qr_url', $up['url'] );
        }
        wp_safe_redirect( add_query_arg( [ 'page'=>'lcni-member-settings','tab'=>'upgrades','lcni_saved'=>'1' ], admin_url('admin.php') ) );
        exit;
    }

    public function handle_save_package_prices(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden' );
        check_admin_referer( 'lcni_save_package_prices' );
        $raw    = (array) wp_unslash( $_POST['prices'] ?? [] );
        $clean  = [];
        foreach ( $raw as $pkg_id => $durations ) {
            $pid = (int) $pkg_id;
            if ( $pid <= 0 ) continue;
            foreach ( [1,3,6,12] as $m ) {
                $clean[$pid][$m] = max( 0, (float) ( $durations[$m] ?? 0 ) );
            }
        }
        update_option( 'lcni_package_prices', $clean );
        wp_safe_redirect( add_query_arg( [ 'page'=>'lcni-member-settings','tab'=>'upgrades','lcni_saved'=>'1' ], admin_url('admin.php') ) );
        exit;
    }

    public function render_payment_settings(): void {
        global $lcni_saas_service;
        $packages = $lcni_saas_service ? $lcni_saas_service->get_packages() : [];
        $prices   = LCNI_Upgrade_Request_Service::get_all_prices();
        $qr_url   = get_option('lcni_payment_qr_url','');
        $durations = [1=>'1 tháng',3=>'3 tháng',6=>'6 tháng',12=>'1 năm'];
        ?>
        <!-- QR / Tài khoản -->
        <div class="lcni-ur-broker-box" style="max-width:700px;margin-bottom:20px">
            <h3>💳 Cấu hình thanh toán (Luồng 2 – Trả phí)</h3>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                <?php wp_nonce_field('lcni_save_payment_settings'); ?>
                <input type="hidden" name="action" value="lcni_save_payment_settings">
                <table class="form-table" style="margin:0">
                    <tr>
                        <th style="width:160px;font-size:13px">Mã QR thanh toán</th>
                        <td>
                            <?php if($qr_url): ?><img src="<?php echo esc_url($qr_url); ?>" style="width:100px;border:1px solid #e5e7eb;border-radius:6px;margin-bottom:6px;display:block"><?php endif; ?>
                            <input type="file" name="qr_file" accept="image/*" style="font-size:13px">
                            <div style="font-size:11px;color:#9ca3af;margin-top:4px">Hoặc nhập URL:</div>
                            <input type="url" name="qr_url" value="<?php echo esc_attr($qr_url); ?>" style="width:100%;max-width:400px;margin-top:4px;border:1px solid #d1d5db;border-radius:5px;padding:6px 8px;font-size:13px">
                        </td>
                    </tr>
                    <tr>
                        <th style="font-size:13px">Ngân hàng</th>
                        <td><input type="text" name="bank_name" value="<?php echo esc_attr(get_option('lcni_payment_bank_name','')); ?>" style="width:300px;border:1px solid #d1d5db;border-radius:5px;padding:6px 8px;font-size:13px" placeholder="VD: Vietcombank"></td>
                    </tr>
                    <tr>
                        <th style="font-size:13px">Số tài khoản</th>
                        <td><input type="text" name="account_no" value="<?php echo esc_attr(get_option('lcni_payment_account_no','')); ?>" style="width:300px;border:1px solid #d1d5db;border-radius:5px;padding:6px 8px;font-size:13px"></td>
                    </tr>
                    <tr>
                        <th style="font-size:13px">Tên chủ tài khoản</th>
                        <td><input type="text" name="account_name" value="<?php echo esc_attr(get_option('lcni_payment_account_name','')); ?>" style="width:300px;border:1px solid #d1d5db;border-radius:5px;padding:6px 8px;font-size:13px"></td>
                    </tr>
                </table>
                <button type="submit" class="button button-primary" style="margin-top:10px">💾 Lưu thông tin thanh toán</button>
            </form>
        </div>

        <!-- Bảng giá -->
        <div class="lcni-ur-broker-box" style="max-width:700px;margin-bottom:24px">
            <h3>💰 Bảng giá theo gói & thời hạn</h3>
            <p style="color:#6b7280;font-size:13px;margin:0 0 12px">Nhập 0 = Liên hệ / Miễn phí. Đơn vị: VNĐ.</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('lcni_save_package_prices'); ?>
                <input type="hidden" name="action" value="lcni_save_package_prices">
                <table style="border-collapse:collapse;font-size:13px;width:100%">
                    <thead>
                        <tr style="background:#f9fafb">
                            <th style="padding:8px 10px;border:1px solid #e5e7eb;text-align:left">Gói</th>
                            <?php foreach($durations as $m => $lbl): ?>
                            <th style="padding:8px 10px;border:1px solid #e5e7eb;text-align:center"><?php echo $lbl; ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach($packages as $pkg):
                        $pid = (int)$pkg['id'];
                    ?>
                        <tr>
                            <td style="padding:8px 10px;border:1px solid #e5e7eb;font-weight:600"><?php echo esc_html($pkg['package_name']); ?></td>
                            <?php foreach([1,3,6,12] as $m): ?>
                            <td style="padding:6px 8px;border:1px solid #e5e7eb">
                                <input type="number" min="0" step="1000" name="prices[<?php echo $pid; ?>][<?php echo $m; ?>]"
                                       value="<?php echo (int)($prices[$pid][$m] ?? 0); ?>"
                                       style="width:110px;border:1px solid #d1d5db;border-radius:4px;padding:5px 7px;font-size:13px;text-align:right">
                            </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <button type="submit" class="button button-primary" style="margin-top:10px">💾 Lưu bảng giá</button>
            </form>
        </div>
        <?php
    }

    // ─── Admin styles ────────────────────────────────────────────────────────

    private function render_styles(): void {
        ?>
        <style>
        .lcni-ur-admin { max-width: 1200px; }
        .lcni-ur-back { display: inline-block; margin-bottom: 10px; color: #2563eb; text-decoration: none; font-size: 13px; }
        .lcni-ur-back:hover { text-decoration: underline; }

        /* Tabs */
        .lcni-ur-admin-tabs { display: flex; gap: 4px; margin: 16px 0; border-bottom: 2px solid #e5e7eb; padding-bottom: 0; }
        .lcni-ur-admin-tab { padding: 8px 14px; text-decoration: none; color: #6b7280; font-size: 13px; font-weight: 500; border-bottom: 2px solid transparent; margin-bottom: -2px; transition: color .15s; }
        .lcni-ur-admin-tab:hover { color: #2563eb; }
        .lcni-ur-admin-tab.active { color: #2563eb; border-bottom-color: #2563eb; font-weight: 600; }
        .lcni-ur-admin-tab-count { background: #f3f4f6; color: #6b7280; border-radius: 10px; padding: 1px 7px; font-size: 11px; margin-left: 4px; }
        .lcni-ur-admin-tab.active .lcni-ur-admin-tab-count { background: #dbeafe; color: #1d4ed8; }

        /* Table */
        .lcni-ur-admin-table { width: 100%; border-collapse: collapse; background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden; font-size: 13px; }
        .lcni-ur-admin-table th { background: #f9fafb; padding: 10px 12px; text-align: left; font-weight: 600; color: #374151; border-bottom: 1px solid #e5e7eb; }
        .lcni-ur-admin-table td { padding: 10px 12px; border-bottom: 1px solid #f3f4f6; vertical-align: middle; }
        .lcni-ur-admin-table tr:last-child td { border-bottom: none; }
        .lcni-ur-admin-table tr:hover td { background: #f8fafc; }
        .lcni-ur-admin-badge { padding: 2px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; border: 1px solid; }

        /* Detail */
        .lcni-ur-detail-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 16px; }
        .lcni-ur-detail-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 20px; }
        .lcni-ur-detail-card h3 { font-size: 14px; font-weight: 700; margin: 0 0 14px; color: #111827; border-bottom: 1px solid #f3f4f6; padding-bottom: 8px; }
        .lcni-ur-info-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .lcni-ur-info-table th { width: 40%; padding: 7px 0; color: #6b7280; font-weight: 500; text-align: left; vertical-align: top; }
        .lcni-ur-info-table td { padding: 7px 0; color: #111827; }
        .lcni-ur-action-btns { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 4px; }
        .lcni-ur-done-banner { padding: 14px 16px; border-radius: 8px; border: 1px solid; font-size: 13px; margin-top: 8px; }

        /* Admin steps */
        .lcni-ur-admin-steps { display: flex; align-items: center; margin: 8px 0 16px; }
        .lcni-ur-astep { display: flex; flex-direction: column; align-items: center; gap: 4px; min-width: 90px; }
        .lcni-ur-astep-dot { width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 700; border: 2px solid #d1d5db; color: #9ca3af; background: #fff; }
        .lcni-ur-astep--done .lcni-ur-astep-dot     { background: #2563eb; border-color: #2563eb; color: #fff; }
        .lcni-ur-astep--approved .lcni-ur-astep-dot { background: #16a34a; border-color: #16a34a; color: #fff; }
        .lcni-ur-astep--rejected .lcni-ur-astep-dot { background: #dc2626; border-color: #dc2626; color: #fff; }
        .lcni-ur-astep-label { font-size: 11px; color: #6b7280; text-align: center; }
        .lcni-ur-astep--done .lcni-ur-astep-label     { color: #2563eb; font-weight: 600; }
        .lcni-ur-astep--approved .lcni-ur-astep-label { color: #16a34a; font-weight: 600; }
        .lcni-ur-astep--rejected .lcni-ur-astep-label { color: #dc2626; font-weight: 600; }
        .lcni-ur-astep-line { flex: 1; height: 2px; background: #e5e7eb; margin-bottom: 18px; }
        .lcni-ur-astep-line--done { background: #2563eb; }

        .lcni-ur-broker-box { background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:20px 24px;margin-bottom:24px;max-width:600px; }
        .lcni-ur-broker-box h3 { font-size:14px;font-weight:700;margin:0 0 8px;color:#111827; }
        @media (max-width: 900px) {
            .lcni-ur-detail-grid { grid-template-columns: 1fr; }
        }
        </style>
        <?php
    }
}
