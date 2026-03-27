<?php
/**
 * LCNI_InboxDispatcher
 * Hook vào các events hiện có → ghi notification vào inbox.
 *
 * USER notifications:
 *   - recommend_signal  : Tín hiệu mới từ hệ thống Recommend (lcni_signal_created)
 *   - auto_rule_signal  : Auto Rule kích hoạt lệnh (lcni_auto_rule_triggered)
 *   - follow_rule       : Xác nhận theo dõi chiến lược (lcni_rule_followed)
 *   - new_signal        : Tín hiệu cá nhân UserRule (lcni_user_rule_signal)
 *   - upgrade_prompt    : Gợi ý nâng cấp gói (lcni_upgrade_prompt)
 *   - marketing         : Chương trình ưu đãi (lcni_marketing_campaign / MarketingService hook)
 *   - admin_broadcast   : Broadcast thủ công từ admin (lcni_admin_broadcast)
 *
 * ADMIN notifications:
 *   - admin_new_user     : User mới đăng ký (user_register)
 *   - admin_user_upgrade : User được gán gói mới (lcni_package_assigned)
 *   - admin_user_expiring: User sắp hết hạn — Cron hàng ngày
 *   - admin_rule_follow  : User theo dõi rule (lcni_rule_followed)
 *   - admin_auto_rule    : User bật Auto Rule (lcni_auto_rule_enabled)
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class LCNI_InboxDispatcher {

    public function register_hooks() {
        // ── USER HOOKS ────────────────────────────────────────────────────────

        // Recommend signal mới (lcni_signal_created từ SignalRepository)
        add_action( 'lcni_signal_created',          [ $this, 'on_recommend_signal' ],  20, 5 );

        // UserRule signal cá nhân
        add_action( 'lcni_new_signal_notification',  [ $this, 'on_new_signal' ],        10, 2 );
        add_action( 'lcni_user_rule_signal',         [ $this, 'on_user_rule_signal' ],  10, 2 );

        // Auto Rule kích hoạt lệnh DNSE/paper
        add_action( 'lcni_auto_rule_triggered',      [ $this, 'on_auto_rule_triggered'], 10, 3 );

        // Follow rule
        add_action( 'lcni_rule_followed',            [ $this, 'on_follow_rule' ],       10, 2 );

        // Gợi ý nâng cấp gói (fire từ permission check)
        add_action( 'lcni_upgrade_prompt',           [ $this, 'on_upgrade_prompt' ],    10, 2 );

        // Marketing campaign
        add_action( 'lcni_marketing_campaign_sent',  [ $this, 'on_marketing_campaign'], 10, 2 );
        add_action( 'lcni_marketing_share_upgraded', [ $this, 'on_marketing_upgraded'], 10, 5 );

        // Admin broadcast thủ công
        add_action( 'lcni_admin_broadcast',          [ $this, 'on_admin_broadcast' ],   10, 1 );

        // ── ADMIN HOOKS ───────────────────────────────────────────────────────

        // User mới đăng ký
        add_action( 'user_register',                 [ $this, 'admin_on_new_user' ],    20, 1 );

        // User được gán gói (do admin hoặc MarketingService)
        add_action( 'lcni_package_assigned',         [ $this, 'admin_on_user_upgrade'], 10, 3 );

        // User bật Auto Rule
        add_action( 'lcni_auto_rule_enabled',        [ $this, 'admin_on_auto_rule' ],   10, 3 );

        // User theo dõi rule → cũng thông báo admin
        add_action( 'lcni_rule_followed',            [ $this, 'admin_on_rule_follow' ], 10, 2 );

        // ── CRON ──────────────────────────────────────────────────────────────
        add_action( 'lcni_daily_cron',               [ $this, 'cleanup' ] );
        add_action( 'lcni_daily_cron',               [ $this, 'admin_check_expiring_users' ] );
        add_action( 'lcni_inbox_cleanup',            [ $this, 'cleanup' ] );
        if ( ! wp_next_scheduled( 'lcni_inbox_cleanup' ) ) {
            wp_schedule_event( time(), 'daily', 'lcni_inbox_cleanup' );
        }
    }

    // =========================================================================
    // USER HANDLERS
    // =========================================================================

    /**
     * Recommend signal mới — gửi đến tất cả followers của rule.
     */
    public function on_recommend_signal( $signal_id, $rule_id, $symbol, $entry_price, $entry_time ) {
        $signal_id   = (int) $signal_id;
        $rule_id     = (int) $rule_id;
        $symbol      = sanitize_text_field( (string) $symbol );
        $entry_price = (float) $entry_price;

        if ( $rule_id <= 0 || ! $symbol ) return;

        global $wpdb;
        $tbl = $wpdb->prefix . 'lcni_recommend_rule_follow';
        $followers = $wpdb->get_results(
            $wpdb->prepare( "SELECT user_id FROM {$tbl} WHERE rule_id = %d", $rule_id ),
            ARRAY_A
        );
        if ( empty( $followers ) ) return;

        $rule_tbl  = $wpdb->prefix . 'lcni_recommend_rule';
        $rule_name = $wpdb->get_var( $wpdb->prepare( "SELECT name FROM {$rule_tbl} WHERE id = %d", $rule_id ) );
        $rule_name = $rule_name ?: "Rule #{$rule_id}";

        $cfg     = LCNI_InboxDB::get_admin_config();
        $url     = $cfg['inbox_page_url'] ?? home_url('/');
        $price_f = $entry_price > 0 ? number_format( $entry_price * 1000, 0, ',', '.' ) . ' đ' : '';
        $body    = "Chiến lược <strong>{$rule_name}</strong> vừa phát tín hiệu mua cho <strong>{$symbol}</strong>"
                   . ( $price_f ? " tại giá <strong>{$price_f}</strong>" : '' ) . '.';

        foreach ( $followers as $row ) {
            $uid = (int) $row['user_id'];
            if ( ! $uid ) continue;
            LCNI_InboxDB::insert( [
                'user_id' => $uid,
                'type'    => 'recommend_signal',
                'title'   => "🎯 Tín hiệu Recommend: {$symbol}",
                'body'    => $body,
                'url'     => $url,
                'meta'    => [ 'signal_id' => $signal_id, 'rule_id' => $rule_id, 'symbol' => $symbol ],
            ] );
        }
    }

    /**
     * UserRule signal thông thường (lcni_new_signal_notification).
     */
    public function on_new_signal( $user_id, $signal_data ) {
        $user_id = absint( $user_id );
        if ( ! $user_id ) return;
        $symbol    = sanitize_text_field( $signal_data['symbol']    ?? '' );
        $rule_name = sanitize_text_field( $signal_data['rule_name'] ?? '' );
        $price     = $signal_data['price'] ?? '';
        $cfg       = LCNI_InboxDB::get_admin_config();

        LCNI_InboxDB::insert( [
            'user_id' => $user_id,
            'type'    => 'new_signal',
            'title'   => "📈 Tín hiệu mới: {$symbol}",
            'body'    => "Rule <strong>{$rule_name}</strong> vừa phát tín hiệu cho <strong>{$symbol}</strong>"
                         . ( $price ? " tại giá <strong>{$price}</strong>" : '' ) . '.',
            'url'     => $cfg['inbox_page_url'] ?? home_url('/'),
            'meta'    => $signal_data,
        ] );
    }

    /**
     * UserRule signal cá nhân (lcni_user_rule_signal).
     */
    public function on_user_rule_signal( $user_id, $data ) {
        $user_id = absint( $user_id );
        if ( ! $user_id ) return;
        $symbol    = sanitize_text_field( $data['symbol'] ?? '' );
        $rule_name = sanitize_text_field( $data['rule_name'] ?? '' );

        LCNI_InboxDB::insert( [
            'user_id' => $user_id,
            'type'    => 'new_signal',
            'title'   => "📊 Tín hiệu cá nhân: {$symbol}",
            'body'    => "Rule cá nhân <strong>{$rule_name}</strong> phát tín hiệu cho <strong>{$symbol}</strong>.",
            'url'     => '',
            'meta'    => $data,
        ] );
    }

    /**
     * Auto Rule kích hoạt lệnh thực tế (DNSE hoặc paper).
     * Hook: lcni_auto_rule_triggered( $user_id, $symbol, $data )
     */
    public function on_auto_rule_triggered( $user_id, $symbol, $data ) {
        $user_id = absint( $user_id );
        if ( ! $user_id ) return;
        $symbol    = sanitize_text_field( (string) $symbol );
        $rule_name = sanitize_text_field( $data['rule_name'] ?? '' );
        $is_paper  = ! empty( $data['is_paper'] );
        $price_f   = isset( $data['price'] ) ? number_format( (float)$data['price'] * 1000, 0, ',', '.' ) . ' đ' : '';
        $shares    = isset( $data['shares'] ) ? number_format( (int)$data['shares'] ) : '';
        $trade_lbl = $is_paper ? 'Paper Trade' : 'Lệnh thật';

        $body = "Auto Rule <strong>{$rule_name}</strong> đã đặt <strong>{$trade_lbl}</strong> mua"
              . ( $shares   ? " <strong>{$shares} cp</strong>"         : '' )
              . " <strong>{$symbol}</strong>"
              . ( $price_f  ? " tại giá <strong>{$price_f}</strong>"   : '' ) . '.';

        $cfg = LCNI_InboxDB::get_admin_config();
        LCNI_InboxDB::insert( [
            'user_id' => $user_id,
            'type'    => 'auto_rule_signal',
            'title'   => "🤖 Auto Rule: {$symbol}" . ( $is_paper ? ' (Paper)' : '' ),
            'body'    => $body,
            'url'     => $cfg['inbox_page_url'] ?? home_url('/'),
            'meta'    => (array) $data,
        ] );
    }

    /**
     * Xác nhận theo dõi chiến lược.
     */
    public function on_follow_rule( $user_id, $rule_data ) {
        $user_id   = absint( $user_id );
        if ( ! $user_id ) return;
        $rule_name = sanitize_text_field( $rule_data['rule_name'] ?? '' );

        LCNI_InboxDB::insert( [
            'user_id' => $user_id,
            'type'    => 'follow_rule',
            'title'   => '🔔 Đang theo dõi chiến lược',
            'body'    => "Bạn đã theo dõi chiến lược <strong>{$rule_name}</strong>. Hệ thống sẽ thông báo khi có tín hiệu mới.",
            'url'     => '',
            'meta'    => $rule_data,
        ] );
    }

    /**
     * Gợi ý nâng cấp gói khi user cố truy cập tính năng cao hơn.
     * Hook: lcni_upgrade_prompt( $user_id, $module_key )
     */
    public function on_upgrade_prompt( $user_id, $module_key ) {
        $user_id    = absint( $user_id );
        if ( ! $user_id ) return;
        $module_key = sanitize_key( (string) $module_key );

        // Chỉ gửi 1 lần/ngày/module để tránh spam
        $throttle_key = "lcni_upgrade_prompt_{$user_id}_{$module_key}";
        if ( get_transient( $throttle_key ) ) return;
        set_transient( $throttle_key, 1, DAY_IN_SECONDS );

        $module_labels = [
            'recommend-follow' => 'Theo dõi chiến lược',
            'auto-order'       => 'Auto Order',
            'user-rule'        => 'Chiến lược cá nhân',
            'portfolio'        => 'Quản lý danh mục',
            'heatmap'          => 'Bản đồ nhiệt',
        ];
        $module_label = $module_labels[ $module_key ] ?? $module_key;

        $cfg = LCNI_InboxDB::get_admin_config();
        LCNI_InboxDB::insert( [
            'user_id' => $user_id,
            'type'    => 'upgrade_prompt',
            'title'   => '⭐ Nâng cấp để dùng tính năng này',
            'body'    => "Tính năng <strong>{$module_label}</strong> yêu cầu gói cao hơn. Nâng cấp ngay để trải nghiệm đầy đủ!",
            'url'     => $cfg['inbox_page_url'] ?? home_url('/'),
            'meta'    => [ 'module_key' => $module_key ],
        ] );
    }

    /**
     * Thông báo marketing campaign đến user cụ thể.
     * Hook: lcni_marketing_campaign_sent( $user_id, $campaign_data )
     */
    public function on_marketing_campaign( $user_id, $campaign_data ) {
        $user_id = absint( $user_id );
        if ( ! $user_id ) return;
        $title   = sanitize_text_field( $campaign_data['title'] ?? 'Ưu đãi mới dành cho bạn' );
        $body    = wp_kses_post( $campaign_data['body']  ?? '' );
        $url     = esc_url_raw( $campaign_data['url']   ?? '' );

        LCNI_InboxDB::insert( [
            'user_id' => $user_id,
            'type'    => 'marketing',
            'title'   => "🎁 {$title}",
            'body'    => $body,
            'url'     => $url,
            'meta'    => $campaign_data,
        ] );
    }

    /**
     * Thông báo sau khi user dùng marketing share để nâng cấp thành công.
     * Hook: lcni_marketing_share_upgraded( $user_id, $campaign, $share_id, $package_id, $expires_at )
     */
    public function on_marketing_upgraded( $user_id, $campaign, $share_id, $package_id, $expires_at ) {
        $user_id     = absint( $user_id );
        if ( ! $user_id ) return;
        $pkg_name    = '';
        if ( $package_id ) {
            global $wpdb;
            $pkg_table = $wpdb->prefix . 'lcni_saas_packages';
            $pkg_name  = (string) $wpdb->get_var( $wpdb->prepare( "SELECT package_name FROM {$pkg_table} WHERE id=%d", $package_id ) );
        }
        $expires_fmt = $expires_at ? wp_date( 'd/m/Y', strtotime( $expires_at ) ) : 'vĩnh viễn';
        $body = 'Chúc mừng! Bạn đã được nâng cấp lên gói'
              . ( $pkg_name ? " <strong>{$pkg_name}</strong>" : '' )
              . " thông qua chương trình chia sẻ. Hạn sử dụng: <strong>{$expires_fmt}</strong>.";

        $cfg = LCNI_InboxDB::get_admin_config();
        LCNI_InboxDB::insert( [
            'user_id' => $user_id,
            'type'    => 'marketing',
            'title'   => '🎉 Nâng cấp thành công qua chương trình ưu đãi',
            'body'    => $body,
            'url'     => $cfg['inbox_page_url'] ?? home_url('/'),
            'meta'    => [ 'campaign' => $campaign, 'share_id' => $share_id, 'package_id' => $package_id ],
        ] );
    }

    /**
     * Broadcast thủ công từ admin đến tất cả users.
     */
    public function on_admin_broadcast( $data ) {
        $title = sanitize_text_field( $data['title'] ?? 'Thông báo từ admin' );
        $body  = wp_kses_post( $data['body']  ?? '' );
        $url   = esc_url_raw( $data['url']   ?? '' );

        LCNI_InboxDB::insert( [
            'user_id' => 0, // 0 = broadcast
            'type'    => 'admin_broadcast',
            'title'   => $title,
            'body'    => $body,
            'url'     => $url,
            'meta'    => [],
        ] );
    }

    // =========================================================================
    // ADMIN HANDLERS
    // =========================================================================

    /**
     * User mới đăng ký — thông báo cho admin.
     */
    public function admin_on_new_user( $user_id ) {
        $user_id = (int) $user_id;
        if ( ! $user_id ) return;

        // Bỏ qua nếu chính admin tạo user (có lcni_pkg_nonce)
        if ( ! empty( $_POST['lcni_pkg_nonce'] ) ) return;

        $user     = get_userdata( $user_id );
        $username = $user ? ( $user->display_name ?: $user->user_login ) : "User #{$user_id}";
        $email    = $user ? $user->user_email : '';
        $admin_url = admin_url( "user-edit.php?user_id={$user_id}" );

        LCNI_InboxDB::insert( [
            'user_id' => 0, // sẽ override bởi _insert_to_admins
            'type'    => 'admin_new_user',
            'title'   => "👤 Người dùng mới: {$username}",
            'body'    => "User <strong>{$username}</strong>"
                       . ( $email ? " (<em>{$email}</em>)" : '' )
                       . ' vừa đăng ký tài khoản.',
            'url'     => $admin_url,
            'meta'    => [ 'user_id' => $user_id, 'email' => $email ],
        ] );
    }

    /**
     * User được gán gói mới — thông báo cho admin.
     * Hook: lcni_package_assigned( $user_id, $package_id, $expires_at )
     */
    public function admin_on_user_upgrade( $user_id, $package_id, $expires_at ) {
        $user_id    = (int) $user_id;
        $package_id = (int) $package_id;
        if ( ! $user_id ) return;

        $user     = get_userdata( $user_id );
        $username = $user ? ( $user->display_name ?: $user->user_login ) : "User #{$user_id}";

        $pkg_name = '';
        if ( $package_id ) {
            global $wpdb;
            $pkg_table = $wpdb->prefix . 'lcni_saas_packages';
            $pkg_name  = (string) $wpdb->get_var( $wpdb->prepare( "SELECT package_name FROM {$pkg_table} WHERE id=%d", $package_id ) );
        }
        $expires_fmt = $expires_at ? wp_date( 'd/m/Y', strtotime( (string) $expires_at ) ) : 'vĩnh viễn';
        $admin_url   = admin_url( "user-edit.php?user_id={$user_id}" );

        LCNI_InboxDB::insert( [
            'user_id' => 0,
            'type'    => 'admin_user_upgrade',
            'title'   => "🚀 User nâng cấp: {$username}",
            'body'    => "User <strong>{$username}</strong> đã được gán gói"
                       . ( $pkg_name ? " <strong>{$pkg_name}</strong>" : '' )
                       . ". Hạn dùng: <strong>{$expires_fmt}</strong>.",
            'url'     => $admin_url,
            'meta'    => [ 'user_id' => $user_id, 'package_id' => $package_id, 'expires_at' => $expires_at ],
        ] );
    }

    /**
     * User bật Auto Rule — thông báo cho admin.
     * Hook: lcni_auto_rule_enabled( $user_id, $rule_id, $rule_name )
     */
    public function admin_on_auto_rule( $user_id, $rule_id, $rule_name ) {
        $user_id   = (int) $user_id;
        $rule_name = sanitize_text_field( (string) $rule_name );
        if ( ! $user_id ) return;

        $user     = get_userdata( $user_id );
        $username = $user ? ( $user->display_name ?: $user->user_login ) : "User #{$user_id}";
        $admin_url = admin_url( "user-edit.php?user_id={$user_id}" );

        LCNI_InboxDB::insert( [
            'user_id' => 0,
            'type'    => 'admin_auto_rule',
            'title'   => "🤖 User bật Auto Rule: {$username}",
            'body'    => "User <strong>{$username}</strong> đã bật Auto Rule <strong>{$rule_name}</strong> (Rule ID #{$rule_id}).",
            'url'     => $admin_url,
            'meta'    => [ 'user_id' => $user_id, 'rule_id' => $rule_id, 'rule_name' => $rule_name ],
        ] );
    }

    /**
     * User theo dõi rule — thông báo cho admin.
     */
    public function admin_on_rule_follow( $user_id, $rule_data ) {
        $user_id   = (int) $user_id;
        $rule_name = sanitize_text_field( $rule_data['rule_name'] ?? '' );
        $rule_id   = (int) ( $rule_data['rule_id'] ?? 0 );
        if ( ! $user_id ) return;

        $user     = get_userdata( $user_id );
        $username = $user ? ( $user->display_name ?: $user->user_login ) : "User #{$user_id}";
        $admin_url = admin_url( "user-edit.php?user_id={$user_id}" );

        LCNI_InboxDB::insert( [
            'user_id' => 0,
            'type'    => 'admin_rule_follow',
            'title'   => "🔔 User theo dõi chiến lược",
            'body'    => "User <strong>{$username}</strong> vừa theo dõi chiến lược <strong>{$rule_name}</strong>.",
            'url'     => $admin_url,
            'meta'    => [ 'user_id' => $user_id, 'rule_id' => $rule_id, 'rule_name' => $rule_name ],
        ] );
    }

    /**
     * Cron hàng ngày: tìm users sắp hết hạn gói (trong 7 ngày), thông báo admin.
     */
    public function admin_check_expiring_users() {
        global $wpdb;
        $up  = $wpdb->prefix . 'lcni_user_packages';
        $pk  = $wpdb->prefix . 'lcni_saas_packages';

        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$up}'" ) !== $up ) return;

        // Users hết hạn trong vòng 7 ngày, chưa hết hạn
        $rows = $wpdb->get_results( "
            SELECT up.user_id, up.expires_at, p.package_name
            FROM {$up} up
            LEFT JOIN {$pk} p ON p.id = up.package_id
            WHERE up.role_slug = ''
              AND up.expires_at IS NOT NULL
              AND up.expires_at > NOW()
              AND up.expires_at <= DATE_ADD(NOW(), INTERVAL 7 DAY)
        ", ARRAY_A );

        if ( empty( $rows ) ) return;

        // Chỉ gửi 1 lần/ngày — dùng transient để check
        $today = current_time( 'Y-m-d' );
        $sent  = (array) get_transient( 'lcni_admin_expiring_notified_' . $today );

        foreach ( $rows as $row ) {
            $uid = (int) $row['user_id'];
            if ( in_array( $uid, $sent, true ) ) continue;

            $user     = get_userdata( $uid );
            $username = $user ? ( $user->display_name ?: $user->user_login ) : "User #{$uid}";
            $pkg_name = sanitize_text_field( $row['package_name'] ?? '' );
            $expires  = wp_date( 'd/m/Y', strtotime( $row['expires_at'] ) );
            $days_left = (int) ceil( ( strtotime( $row['expires_at'] ) - time() ) / DAY_IN_SECONDS );

            LCNI_InboxDB::insert( [
                'user_id' => 0,
                'type'    => 'admin_user_expiring',
                'title'   => "⏳ User sắp hết hạn: {$username}",
                'body'    => "Gói <strong>{$pkg_name}</strong> của user <strong>{$username}</strong> "
                           . "sẽ hết hạn vào <strong>{$expires}</strong> ({$days_left} ngày nữa).",
                'url'     => admin_url( "user-edit.php?user_id={$uid}" ),
                'meta'    => [ 'user_id' => $uid, 'expires_at' => $row['expires_at'], 'package_name' => $pkg_name ],
            ] );

            $sent[] = $uid;
        }

        set_transient( 'lcni_admin_expiring_notified_' . $today, $sent, DAY_IN_SECONDS );
    }

    // =========================================================================
    // STATIC HELPER
    // =========================================================================

    /**
     * Gọi trực tiếp từ code khác để push inbox.
     */
    public static function push( int $user_id, string $type, string $title, string $body, string $url = '', array $meta = [] ) {
        return LCNI_InboxDB::insert( compact( 'user_id', 'type', 'title', 'body', 'url', 'meta' ) );
    }

    public function cleanup() {
        $cfg  = LCNI_InboxDB::get_admin_config();
        $days = (int) ( $cfg['retention_days'] ?? 90 );
        LCNI_InboxDB::delete_old( $days );
    }
}
