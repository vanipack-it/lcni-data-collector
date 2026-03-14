<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * LCNI_DnseTradingAdminPage
 *
 * Trang quản trị DNSE Trading trong wp-admin.
 * Menu: wp-admin → LCNi → DNSE Trading
 *
 * Chức năng:
 *   1. Tab "Tổng quan"   — danh sách users đã kết nối, trạng thái token
 *   2. Tab "Cài đặt"     — cấu hình module (cho phép/chặn theo gói)
 *   3. Tab "Log"         — log sync lỗi gần đây
 */
class LCNI_DnseTradingAdminPage {

    /** @var LCNI_DnseTradingRepository */
    private $repo;

    public function __construct( LCNI_DnseTradingRepository $repo ) {
        $this->repo = $repo;
        // Không cần admin_menu — render trực tiếp qua tab lcni-settings&tab=dnse_trading
    }

    /**
     * Entry point gọi từ class-lcni-settings.php:
     *   LCNI_DnseTradingAdminPage::render_settings_inline();
     */
    public static function render_settings_inline(): void {
        $repo = new LCNI_DnseTradingRepository();
        $page = new self( $repo );
        $page->render_page();
    }

    /**
     * URL đến tab này trong lcni-settings
     */
    private function page_url( string $extra = '' ): string {
        $base = admin_url( 'admin.php?page=lcni-settings&tab=dnse_trading' );
        return $extra !== '' ? $base . '&' . $extra : $base;
    }

    public function render_page(): void {
        $tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'overview';
        $allowed_tabs = [ 'overview', 'settings', 'log' ];
        if ( ! in_array( $tab, $allowed_tabs, true ) ) {
            $tab = 'overview';
        }
        ?>
        <div class="wrap lcni-dnse-admin">
            <h1>🔗 DNSE Trading Module</h1>

            <nav class="nav-tab-wrapper">
                <a href="<?php echo esc_url( $this->page_url( 'tab=overview' ) ); ?>"
                   class="nav-tab <?php echo $tab === 'overview' ? 'nav-tab-active' : ''; ?>">
                    Tổng quan
                </a>
                <a href="<?php echo esc_url( $this->page_url( 'tab=settings' ) ); ?>"
                   class="nav-tab <?php echo $tab === 'settings' ? 'nav-tab-active' : ''; ?>">
                    Cài đặt
                </a>
                <a href="<?php echo esc_url( $this->page_url( 'tab=log' ) ); ?>"
                   class="nav-tab <?php echo $tab === 'log' ? 'nav-tab-active' : ''; ?>">
                    Log
                </a>
            </nav>

            <div style="margin-top:20px">
            <?php
            if ( $tab === 'settings' ) {
                $this->render_settings_tab();
            } elseif ( $tab === 'log' ) {
                $this->render_log_tab();
            } else {
                $this->render_overview_tab();
            }
            ?>
            </div>
        </div>

        <style>
            .lcni-dnse-admin h2 { margin-top:24px; font-size:16px; }
            .lcni-dnse-admin .lcni-badge { display:inline-block; padding:2px 8px; border-radius:20px; font-size:11px; font-weight:600; }
            .lcni-dnse-admin .lcni-badge-green  { background:#ecf9f1; color:#065f46; border:1px solid #6ee7b7; }
            .lcni-dnse-admin .lcni-badge-amber  { background:#fff8e5; color:#92400e; border:1px solid #fcd34d; }
            .lcni-dnse-admin .lcni-badge-gray   { background:#f3f4f6; color:#374151; border:1px solid #d1d5db; }
            .lcni-dnse-admin .lcni-badge-red    { background:#fff1f0; color:#991b1b; border:1px solid #fca5a5; }
            .lcni-dnse-admin .lcni-stat-grid    { display:flex; gap:16px; flex-wrap:wrap; margin-bottom:20px; }
            .lcni-dnse-admin .lcni-stat-card    { background:#fff; border:1px solid #c3c4c7; border-radius:8px; padding:14px 18px; min-width:120px; }
            .lcni-dnse-admin .lcni-stat-value   { font-size:24px; font-weight:700; color:#1d2327; display:block; }
            .lcni-dnse-admin .lcni-stat-label   { font-size:12px; color:#646970; }
        </style>
        <?php
    }

    // ── Tab 1: Overview ───────────────────────────────────────────────────────

    private function render_overview_tab(): void {
        global $wpdb;
        $tbl  = $wpdb->prefix . 'lcni_dnse_credentials';
        $now  = time();

        $prev = $wpdb->suppress_errors( true );
        $rows = $wpdb->get_results(
            "SELECT c.*, u.display_name, u.user_email
             FROM {$tbl} c
             LEFT JOIN {$wpdb->users} u ON u.ID = c.user_id
             ORDER BY c.connected_at DESC
             LIMIT 100",
            ARRAY_A
        ) ?: [];
        $wpdb->suppress_errors( $prev );

        $total   = count( $rows );
        $active  = 0;
        $trading = 0;
        foreach ( $rows as $r ) {
            if ( (int) $r['jwt_expires_at'] > $now )      $active++;
            if ( (int) $r['trading_expires_at'] > $now )  $trading++;
        }

        // Stats cards
        echo '<div class="lcni-stat-grid">';
        $this->stat_card( $total,   'Tổng số kết nối' );
        $this->stat_card( $active,  'Token còn hạn' );
        $this->stat_card( $trading, 'Trading token OK' );
        echo '</div>';

        if ( empty( $rows ) ) {
            echo '<p>Chưa có user nào kết nối DNSE. Shortcode <code>[lcni_dnse_trading]</code> hiện trên trang frontend để user đăng nhập.</p>';
            return;
        }

        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>
            <th>User</th>
            <th>Tài khoản DNSE</th>
            <th>JWT Token</th>
            <th>Trading Token</th>
            <th>Kết nối lúc</th>
            <th>Sync lần cuối</th>
            <th>Thao tác</th>
        </tr></thead><tbody>';

        foreach ( $rows as $r ) {
            $user_id     = (int) $r['user_id'];
            $jwt_ok      = (int) $r['jwt_expires_at'] > $now;
            $trading_ok  = (int) $r['trading_expires_at'] > $now;
            $jwt_exp     = $r['jwt_expires_at'] > 0
                ? esc_html( date( 'd/m H:i', (int) $r['jwt_expires_at'] ) )
                : '—';
            $trading_exp = $r['trading_expires_at'] > 0
                ? esc_html( date( 'd/m H:i', (int) $r['trading_expires_at'] ) )
                : '—';

            printf(
                '<tr>
                    <td><strong>%s</strong><br><small>%s</small></td>
                    <td><code>%s</code></td>
                    <td>%s<br><small>Hết: %s</small></td>
                    <td>%s<br><small>Hết: %s</small></td>
                    <td>%s</td>
                    <td>%s</td>
                    <td>
                        <a href="%s" class="button button-small button-secondary"
                           onclick="return confirm(\'Xóa token của user này?\')">Revoke</a>
                    </td>
                </tr>',
                esc_html( $r['display_name'] ?? 'User #' . $user_id ),
                esc_html( $r['user_email'] ?? '' ),
                esc_html( $r['dnse_account_no'] ?: '—' ),
                $jwt_ok
                    ? '<span class="lcni-badge lcni-badge-green">✓ Hợp lệ</span>'
                    : '<span class="lcni-badge lcni-badge-red">✗ Hết hạn</span>',
                $jwt_exp,
                $trading_ok
                    ? '<span class="lcni-badge lcni-badge-green">✓ Active</span>'
                    : '<span class="lcni-badge lcni-badge-amber">Chờ OTP</span>',
                $trading_exp,
                esc_html( $r['connected_at'] ?: '—' ),
                esc_html( $r['last_sync_at'] ?: '—' ),
                esc_url( $this->page_url( sprintf(
                    'action=revoke&user_id=%d&_wpnonce=%s',
                    $user_id,
                    wp_create_nonce( 'lcni_dnse_revoke_' . $user_id )
                ) ) )
            );
        }

        echo '</tbody></table>';

        // Handle revoke action
        $this->handle_admin_actions();
    }

    private function handle_admin_actions(): void {
        if ( empty( $_GET['action'] ) || $_GET['action'] !== 'revoke' ) return;
        $user_id = (int) ( $_GET['user_id'] ?? 0 );
        if ( $user_id <= 0 ) return;
        if ( ! check_admin_referer( 'lcni_dnse_revoke_' . $user_id ) ) return;

        $repo = new LCNI_DnseTradingRepository();
        $repo->revoke_credentials( $user_id );

        echo '<div class="notice notice-success is-dismissible"><p>Đã revoke token của user #' . esc_html( (string) $user_id ) . '.</p></div>';
    }

    // ── Tab 2: Settings ───────────────────────────────────────────────────────

    private function render_settings_tab(): void {
        if ( isset( $_POST['lcni_dnse_settings_save'] ) ) {
            check_admin_referer( 'lcni_dnse_settings_save' );
            $settings = [
                'auto_sync_enabled'     => ! empty( $_POST['auto_sync_enabled'] ),
                'sync_interval_minutes' => max( 5, min( 60, (int) ( $_POST['sync_interval_minutes'] ?? 30 ) ) ),
                'positions_cache_ttl'   => max( 1, min( 60, (int) ( $_POST['positions_cache_ttl'] ?? 5 ) ) ),
            ];
            update_option( 'lcni_dnse_trading_settings', $settings );
            echo '<div class="notice notice-success is-dismissible"><p>Đã lưu cài đặt.</p></div>';
        }

        $s = get_option( 'lcni_dnse_trading_settings', [
            'auto_sync_enabled'     => true,
            'sync_interval_minutes' => 30,
            'positions_cache_ttl'   => 5,
        ] );
        ?>
        <form method="post" style="max-width:600px">
            <?php wp_nonce_field( 'lcni_dnse_settings_save' ); ?>
            <input type="hidden" name="lcni_dnse_settings_save" value="1">

            <table class="form-table">
                <tr>
                    <th>Auto Sync</th>
                    <td>
                        <label>
                            <input type="checkbox" name="auto_sync_enabled" value="1"
                                <?php checked( $s['auto_sync_enabled'] ); ?>>
                            Tự động sync balance/positions/orders theo cron
                        </label>
                        <p class="description">Nếu tắt, user phải bấm "Đồng bộ" thủ công trên frontend.</p>
                    </td>
                </tr>
                <tr>
                    <th>Chu kỳ sync (phút)</th>
                    <td>
                        <input type="number" name="sync_interval_minutes" min="5" max="60"
                               value="<?php echo (int) $s['sync_interval_minutes']; ?>" class="small-text">
                        <p class="description">Mỗi X phút sync 1 lần cho tất cả user đang kết nối. Tối thiểu 5 phút.</p>
                    </td>
                </tr>
                <tr>
                    <th>Cache positions (phút)</th>
                    <td>
                        <input type="number" name="positions_cache_ttl" min="1" max="60"
                               value="<?php echo (int) $s['positions_cache_ttl']; ?>" class="small-text">
                        <p class="description">Thời gian cache danh mục. Sau thời gian này, lần xem tiếp theo sẽ trigger sync.</p>
                    </td>
                </tr>
            </table>

            <h2>Shortcode</h2>
            <p>Thêm shortcode sau vào bất kỳ page/post nào:</p>
            <table class="form-table">
                <tr>
                    <th>Dashboard đầy đủ</th>
                    <td><code>[lcni_dnse_trading]</code></td>
                </tr>
                <tr>
                    <th>Mở tab danh mục</th>
                    <td><code>[lcni_dnse_trading tab="portfolio"]</code></td>
                </tr>
                <tr>
                    <th>Mở tab sổ lệnh</th>
                    <td><code>[lcni_dnse_trading tab="orders"]</code></td>
                </tr>
                <tr>
                    <th>Layout compact</th>
                    <td><code>[lcni_dnse_trading compact="yes"]</code></td>
                </tr>
            </table>

            <h2>REST API Endpoints</h2>
            <table class="widefat" style="margin-bottom:20px">
                <thead><tr><th>Method</th><th>Endpoint</th><th>Mô tả</th></tr></thead>
                <tbody>
                    <?php
                    $endpoints = [
                        ['POST', '/wp-json/lcni/v1/dnse/connect',     'Đăng nhập DNSE, lưu JWT token'],
                        ['POST', '/wp-json/lcni/v1/dnse/request-otp', 'Gửi Email OTP'],
                        ['POST', '/wp-json/lcni/v1/dnse/verify-otp',  'Xác thực OTP, lưu trading token'],
                        ['POST', '/wp-json/lcni/v1/dnse/disconnect',  'Xóa tất cả tokens'],
                        ['GET',  '/wp-json/lcni/v1/dnse/status',      'Trạng thái kết nối hiện tại'],
                        ['GET',  '/wp-json/lcni/v1/dnse/dashboard',   'Đọc cache dashboard'],
                        ['POST', '/wp-json/lcni/v1/dnse/sync',        'Trigger sync từ DNSE về DB'],
                    ];
                    foreach ( $endpoints as [$method, $path, $desc] ) {
                        printf(
                            '<tr><td><strong>%s</strong></td><td><code>%s</code></td><td>%s</td></tr>',
                            esc_html( $method ), esc_html( $path ), esc_html( $desc )
                        );
                    }
                    ?>
                </tbody>
            </table>

            <?php submit_button( 'Lưu cài đặt' ); ?>
        </form>
        <?php
    }

    // ── Tab 3: Log ────────────────────────────────────────────────────────────

    private function render_log_tab(): void {
        global $wpdb;
        $tbl  = $wpdb->prefix . 'lcni_dnse_credentials';

        $prev = $wpdb->suppress_errors( true );

        // Thống kê sync
        $stats = $wpdb->get_row(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN jwt_expires_at > UNIX_TIMESTAMP() THEN 1 ELSE 0 END) AS active,
                    MAX(last_sync_at) AS last_sync,
                    MIN(connected_at) AS first_connect
             FROM {$tbl}",
            ARRAY_A
        );

        $pos_tbl = $wpdb->prefix . 'lcni_dnse_positions';
        $pos_count = $wpdb->get_var( "SELECT COUNT(*) FROM {$pos_tbl}" ) ?: 0;

        $ord_tbl = $wpdb->prefix . 'lcni_dnse_orders';
        $ord_count = $wpdb->get_var( "SELECT COUNT(*) FROM {$ord_tbl}" ) ?: 0;

        $wpdb->suppress_errors( $prev );

        echo '<div class="lcni-stat-grid">';
        $this->stat_card( $stats['total'] ?? 0,  'Tổng credentials' );
        $this->stat_card( $stats['active'] ?? 0, 'JWT còn hạn' );
        $this->stat_card( (int) $pos_count,       'Positions cached' );
        $this->stat_card( (int) $ord_count,       'Orders cached' );
        echo '</div>';

        echo '<table class="form-table"><tbody>';
        printf( '<tr><th>Sync gần nhất</th><td>%s</td></tr>', esc_html( $stats['last_sync'] ?? '—' ) );
        printf( '<tr><th>Kết nối đầu tiên</th><td>%s</td></tr>', esc_html( $stats['first_connect'] ?? '—' ) );
        echo '</tbody></table>';

        echo '<h2>Kiểm tra kết nối DNSE API</h2>';
        echo '<p>Dùng lệnh cURL để test:</p>';
        echo '<pre style="background:#1e1e1e;color:#d4d4d4;padding:12px;border-radius:6px;overflow-x:auto;font-size:12px">';
        echo esc_html(
            "# 1. Login lấy JWT (domain mới 2025)\n" .
            "curl -X POST https://api.dnse.com.vn/user-service/api/auth \\\n" .
            "  -H 'Content-Type: application/json' \\\n" .
            "  -d '{\"username\":\"TK_DNSE\",\"password\":\"MAT_KHAU\"}'\n\n" .
            "# 2. Hoặc domain cũ\n" .
            "curl -X POST https://services.entrade.com.vn/dnse-auth-service/login \\\n" .
            "  -H 'Content-Type: application/json' \\\n" .
            "  -d '{\"username\":\"TK_DNSE\",\"password\":\"MAT_KHAU\"}'\n\n" .
            "# 3. Lấy sub-accounts\n" .
            "curl https://api.dnse.com.vn/order-service/accounts \\\n" .
            "  -H 'Authorization: Bearer JWT_TOKEN_HERE'"
        );
        echo '</pre>';
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function stat_card( $value, string $label ): void {
        printf(
            '<div class="lcni-stat-card"><span class="lcni-stat-value">%s</span><span class="lcni-stat-label">%s</span></div>',
            esc_html( (string) $value ),
            esc_html( $label )
        );
    }
}
