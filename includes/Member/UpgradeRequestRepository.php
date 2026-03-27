<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Repository cho bảng wp_lcni_upgrade_requests.
 *
 * flow:
 *   broker  — Luồng 1: liên kết tài khoản công ty CK
 *   payment — Luồng 2: trả phí (chọn thời hạn + upload bill)
 */
class LCNI_Upgrade_Request_Repository {

    const TABLE = 'lcni_upgrade_requests';

    public static function maybe_create_table(): void {
        global $wpdb;
        $table   = $wpdb->prefix . self::TABLE;
        $charset = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        ob_start();
        dbDelta( "CREATE TABLE {$table} (
            id                BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
            user_id           BIGINT UNSIGNED  NOT NULL DEFAULT 0,
            full_name         VARCHAR(120)     NOT NULL DEFAULT '',
            phone             VARCHAR(30)      NOT NULL DEFAULT '',
            email             VARCHAR(120)     NOT NULL DEFAULT '',
            broker_company    VARCHAR(120)     NOT NULL DEFAULT '',
            broker_id         VARCHAR(60)      NOT NULL DEFAULT '',
            flow              VARCHAR(20)      NOT NULL DEFAULT 'broker',
            duration_months   TINYINT UNSIGNED NOT NULL DEFAULT 0,
            payment_amount    DECIMAL(12,0)    NOT NULL DEFAULT 0,
            payment_proof_url VARCHAR(500)     NOT NULL DEFAULT '',
            from_package_id   BIGINT UNSIGNED  NOT NULL DEFAULT 0,
            to_package_id     BIGINT UNSIGNED  NOT NULL DEFAULT 0,
            status            VARCHAR(20)      NOT NULL DEFAULT 'pending',
            step              VARCHAR(20)      NOT NULL DEFAULT 'submitted',
            admin_note        TEXT             NOT NULL DEFAULT '',
            reviewed_by       BIGINT UNSIGNED  NOT NULL DEFAULT 0,
            reviewed_at       DATETIME         NULL DEFAULT NULL,
            created_at        DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at        DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_user_id (user_id),
            KEY idx_status  (status)
        ) {$charset};" );
        ob_end_clean();

        // Safe migrations cho bản cài cũ
        $cols = $wpdb->get_col( "SHOW COLUMNS FROM {$table}", 0 );
        $migs = [
            'broker_company'    => "ALTER TABLE {$table} ADD COLUMN broker_company VARCHAR(120) NOT NULL DEFAULT '' AFTER email",
            'broker_id'         => "ALTER TABLE {$table} ADD COLUMN broker_id VARCHAR(60) NOT NULL DEFAULT '' AFTER broker_company",
            'flow'              => "ALTER TABLE {$table} ADD COLUMN flow VARCHAR(20) NOT NULL DEFAULT 'broker' AFTER broker_id",
            'duration_months'   => "ALTER TABLE {$table} ADD COLUMN duration_months TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER flow",
            'payment_amount'    => "ALTER TABLE {$table} ADD COLUMN payment_amount DECIMAL(12,0) NOT NULL DEFAULT 0 AFTER duration_months",
            'payment_proof_url' => "ALTER TABLE {$table} ADD COLUMN payment_proof_url VARCHAR(500) NOT NULL DEFAULT '' AFTER payment_amount",
        ];
        foreach ( $migs as $col => $sql ) {
            if ( ! in_array( $col, (array) $cols, true ) ) {
                $wpdb->query( $sql );
            }
        }
    }

    public function create( array $data ): int {
        global $wpdb;
        $flow = in_array( $data['flow'] ?? '', ['broker','payment'], true ) ? $data['flow'] : 'broker';
        $wpdb->insert(
            $wpdb->prefix . self::TABLE,
            [
                'user_id'           => (int)   ( $data['user_id']           ?? 0 ),
                'full_name'         => sanitize_text_field( $data['full_name']         ?? '' ),
                'phone'             => sanitize_text_field( $data['phone']             ?? '' ),
                'email'             => sanitize_email(      $data['email']             ?? '' ),
                'broker_company'    => sanitize_text_field( $data['broker_company']    ?? '' ),
                'broker_id'         => sanitize_text_field( $data['broker_id']         ?? '' ),
                'flow'              => $flow,
                'duration_months'   => max( 0, (int) ( $data['duration_months'] ?? 0 ) ),
                'payment_amount'    => max( 0, (float) ( $data['payment_amount'] ?? 0 ) ),
                'payment_proof_url' => esc_url_raw( $data['payment_proof_url'] ?? '' ),
                'from_package_id'   => (int)   ( $data['from_package_id']   ?? 0 ),
                'to_package_id'     => (int)   ( $data['to_package_id']     ?? 0 ),
                'status'            => 'pending',
                'step'              => 'submitted',
            ],
            [ '%d','%s','%s','%s','%s','%s','%s','%d','%f','%s','%d','%d','%s','%s' ]
        );
        return (int) $wpdb->insert_id;
    }

    public function update_proof_url( int $id, string $url ): void {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . self::TABLE,
            [ 'payment_proof_url' => esc_url_raw( $url ) ],
            [ 'id' => $id ],
            [ '%s' ], [ '%d' ]
        );
    }

    public function update_review( int $id, string $step, string $status, string $note, int $admin_id ): void {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . self::TABLE,
            [
                'step'        => $step,
                'status'      => $status,
                'admin_note'  => sanitize_textarea_field( $note ),
                'reviewed_by' => $admin_id,
                'reviewed_at' => current_time( 'mysql' ),
            ],
            [ 'id' => $id ],
            [ '%s','%s','%s','%d','%s' ],
            [ '%d' ]
        );
    }

    public function get_by_id( int $id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . self::TABLE . ' WHERE id = %d', $id ),
            ARRAY_A
        );
        return $row ?: null;
    }

    public function get_by_user( int $user_id ): array {
        global $wpdb;
        return (array) $wpdb->get_results(
            $wpdb->prepare(
                'SELECT r.*, p2.package_name AS to_package_name
                 FROM ' . $wpdb->prefix . self::TABLE . ' r
                 LEFT JOIN ' . $wpdb->prefix . 'lcni_saas_packages p2 ON p2.id = r.to_package_id
                 WHERE r.user_id = %d ORDER BY r.created_at DESC',
                $user_id
            ),
            ARRAY_A
        );
    }

    public function get_all( string $status_filter = '' ): array {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $where = $status_filter ? $wpdb->prepare( 'WHERE r.status = %s', $status_filter ) : '';
        return (array) $wpdb->get_results(
            "SELECT r.*,
                    p1.package_name AS from_package_name,
                    p2.package_name AS to_package_name,
                    u.display_name  AS user_display_name
             FROM {$table} r
             LEFT JOIN {$wpdb->prefix}lcni_saas_packages p1 ON p1.id = r.from_package_id
             LEFT JOIN {$wpdb->prefix}lcni_saas_packages p2 ON p2.id = r.to_package_id
             LEFT JOIN {$wpdb->users} u ON u.ID = r.user_id
             {$where}
             ORDER BY r.created_at DESC",
            ARRAY_A
        ) ?: [];
    }

    public function has_pending( int $user_id ): bool {
        global $wpdb;
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM " . $wpdb->prefix . self::TABLE
                . " WHERE user_id = %d AND status IN ('pending','contacted')",
                $user_id
            )
        ) > 0;
    }

    /** Helper: label thời hạn */
    public static function duration_label( int $months ): string {
        if ( $months <= 0 ) return 'Vĩnh viễn';
        $map = [ 1 => '1 tháng', 3 => '3 tháng', 6 => '6 tháng', 12 => '1 năm' ];
        return $map[ $months ] ?? "{$months} tháng";
    }
}
