<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LCNI_Upgrade_Request_Service {

    private LCNI_Upgrade_Request_Repository $repo;
    private LCNI_SaaS_Service               $saas;

    public function __construct(
        LCNI_Upgrade_Request_Repository $repo,
        LCNI_SaaS_Service $saas
    ) {
        $this->repo = $repo;
        $this->saas = $saas;
    }

    // ─── Submit ──────────────────────────────────────────────────────────────

    public function submit( array $data ) {
        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return new WP_Error( 'not_logged_in', 'Bạn cần đăng nhập.' );
        }

        $full_name = sanitize_text_field( $data['full_name'] ?? '' );
        $phone     = sanitize_text_field( $data['phone']     ?? '' );
        $email     = sanitize_email(      $data['email']     ?? '' );
        $to_pkg    = (int) ( $data['to_package_id'] ?? 0 );
        $flow      = in_array( $data['flow'] ?? '', ['broker','payment'], true ) ? $data['flow'] : 'broker';

        if ( ! $full_name || ! $phone || ! is_email( $email ) ) {
            return new WP_Error( 'invalid_data', 'Vui lòng điền đầy đủ Họ tên, SĐT và Email hợp lệ.' );
        }
        if ( $to_pkg <= 0 ) {
            return new WP_Error( 'invalid_package', 'Gói nâng cấp không hợp lệ.' );
        }
        if ( $this->repo->has_pending( $user_id ) ) {
            return new WP_Error( 'already_pending', 'Bạn đã có yêu cầu đang chờ xử lý.' );
        }

        $pkg_info    = $this->saas->get_current_user_package_info();
        $from_pkg_id = $pkg_info ? (int) $pkg_info['package_id'] : 0;

        // Tính giá theo flow=payment
        $duration_months = 0;
        $payment_amount  = 0;
        if ( $flow === 'payment' ) {
            $duration_months = (int) ( $data['duration_months'] ?? 0 );
            if ( ! in_array( $duration_months, [1,3,6,12], true ) ) {
                return new WP_Error( 'invalid_duration', 'Thời hạn không hợp lệ.' );
            }
            $payment_amount = $this->get_price( $to_pkg, $duration_months );
        }

        $id = $this->repo->create( [
            'user_id'           => $user_id,
            'full_name'         => $full_name,
            'phone'             => $phone,
            'email'             => $email,
            'broker_company'    => sanitize_text_field( $data['broker_company'] ?? '' ),
            'broker_id'         => sanitize_text_field( $data['broker_id']      ?? '' ),
            'flow'              => $flow,
            'duration_months'   => $duration_months,
            'payment_amount'    => $payment_amount,
            'payment_proof_url' => esc_url_raw( $data['payment_proof_url'] ?? '' ),
            'from_package_id'   => $from_pkg_id,
            'to_package_id'     => $to_pkg,
        ] );

        if ( ! $id ) {
            return new WP_Error( 'db_error', 'Không thể lưu yêu cầu.' );
        }

        $request = $this->get_with_package_names( $id );
        LCNINotificationManager::send_upgrade( 'upgrade_submitted', $email, $request );
        $admin_to = get_option( 'lcni_upgrade_request_admin_email', '' ) ?: get_option( 'admin_email' );
        LCNINotificationManager::send_upgrade( 'upgrade_admin_notify', $admin_to, $request );

        return $id;
    }

    // ─── Upload proof (AJAX) ─────────────────────────────────────────────────

    public function upload_proof( int $request_id ): array {
        if ( ! function_exists('wp_handle_upload') ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        if ( empty( $_FILES['proof_file'] ) ) {
            return [ 'ok' => false, 'message' => 'Không tìm thấy file.' ];
        }
        $allowed = [ 'jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp', 'pdf' => 'application/pdf' ];
        $result  = wp_handle_upload( $_FILES['proof_file'], [ 'test_form' => false, 'mimes' => $allowed ] );
        if ( isset( $result['error'] ) ) {
            return [ 'ok' => false, 'message' => $result['error'] ];
        }
        $url = $result['url'] ?? '';
        if ( $request_id > 0 ) {
            $this->repo->update_proof_url( $request_id, $url );
        }
        return [ 'ok' => true, 'url' => $url ];
    }

    // ─── Admin update ─────────────────────────────────────────────────────────

    public function admin_update( int $request_id, string $action, string $note = '' ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            return new WP_Error( 'forbidden', 'Không có quyền.' );
        }
        $request = $this->get_with_package_names( $request_id );
        if ( ! $request ) {
            return new WP_Error( 'not_found', 'Không tìm thấy yêu cầu.' );
        }
        $admin_id = get_current_user_id();

        switch ( $action ) {
            case 'contacted':
                $this->repo->update_review( $request_id, 'contacted', 'contacted', $note, $admin_id );
                $request = $this->get_with_package_names( $request_id );
                LCNINotificationManager::send_upgrade( 'upgrade_contacted', $request['email'], $request, $note );
                break;
            case 'approved':
                $this->do_upgrade_package( $request );
                $this->repo->update_review( $request_id, 'done', 'approved', $note, $admin_id );
                $request = $this->get_with_package_names( $request_id );
                LCNINotificationManager::send_upgrade( 'upgrade_approved', $request['email'], $request, $note );
                break;
            case 'rejected':
                $this->repo->update_review( $request_id, 'done', 'rejected', $note, $admin_id );
                $request = $this->get_with_package_names( $request_id );
                LCNINotificationManager::send_upgrade( 'upgrade_rejected', $request['email'], $request, $note );
                break;
            default:
                return new WP_Error( 'invalid_action', 'Hành động không hợp lệ.' );
        }
        return true;
    }

    // ─── Auto upgrade ─────────────────────────────────────────────────────────

    private function do_upgrade_package( array $request ): void {
        $user_id   = (int) $request['user_id'];
        $to_pkg_id = (int) $request['to_package_id'];
        if ( ! $user_id || ! $to_pkg_id ) return;

        global $wpdb;
        $table = $wpdb->prefix . 'lcni_user_packages';
        // Xóa tất cả dòng cũ
        $wpdb->delete( $table, [ 'user_id' => $user_id ], [ '%d' ] );

        // Tính expires_at từ duration_months
        $duration = (int) ( $request['duration_months'] ?? 0 );
        $expires_at = null;
        if ( $duration > 0 ) {
            $expires_at = date( 'Y-m-d H:i:s', strtotime( "+{$duration} months" ) );
        }

        $this->saas->assign_package(
            $user_id,
            '',
            $to_pkg_id,
            $expires_at,
            'Auto-nâng cấp từ yêu cầu #' . $request['id']
        );
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /** Lấy giá gói theo package_id + duration_months từ option admin */
    public function get_price( int $pkg_id, int $months ): float {
        $prices = get_option( 'lcni_package_prices', [] );
        return (float) ( $prices[ $pkg_id ][ $months ] ?? 0 );
    }

    /** Lấy toàn bộ bảng giá: [pkg_id][months] = price */
    public static function get_all_prices(): array {
        return (array) get_option( 'lcni_package_prices', [] );
    }

    public function get_payment_info(): array {
        return [
            'qr_url'      => get_option( 'lcni_payment_qr_url',      '' ),
            'bank_name'   => get_option( 'lcni_payment_bank_name',    '' ),
            'account_no'  => get_option( 'lcni_payment_account_no',   '' ),
            'account_name'=> get_option( 'lcni_payment_account_name', '' ),
        ];
    }

    private function get_with_package_names( int $id ): ?array {
        global $wpdb;
        $r = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT r.*, p1.package_name AS from_package_name, p2.package_name AS to_package_name
                 FROM {$wpdb->prefix}lcni_upgrade_requests r
                 LEFT JOIN {$wpdb->prefix}lcni_saas_packages p1 ON p1.id = r.from_package_id
                 LEFT JOIN {$wpdb->prefix}lcni_saas_packages p2 ON p2.id = r.to_package_id
                 WHERE r.id = %d", $id ),
            ARRAY_A
        );
        return $r ?: null;
    }
}
