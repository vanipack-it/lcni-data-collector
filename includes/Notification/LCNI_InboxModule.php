<?php
/**
 * LCNI_InboxModule
 * - Shortcode [lcni_inbox] — trang chi tiết / danh sách
 * - Admin settings page
 * - Inject bell vào topbar
 * - Enqueue assets
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class LCNI_InboxModule {

    public function register_hooks() {
        LCNI_InboxDB::ensure_tables();

        add_shortcode( 'lcni_inbox', [ $this, 'render_shortcode' ] );
        add_action( 'wp_enqueue_scripts',  [ $this, 'enqueue_assets' ] );
        add_action( 'wp_footer',           [ $this, 'inject_bell' ], 5 );
        add_action( 'admin_menu',          [ $this, 'admin_menu' ] );
        add_action( 'admin_init',          [ $this, 'admin_save' ] );
        add_action( 'admin_init',          [ $this, 'admin_broadcast_send' ] );
    }

    // ── Assets ────────────────────────────────────────────────────────────────

    public function enqueue_assets() {
        if ( ! is_user_logged_in() ) return;

        $base = LCNI_URL . 'assets/';
        $path = LCNI_PATH . 'assets/';

        wp_enqueue_style(
            'lcni-inbox',
            $base . 'css/lcni-inbox.css',
            [],
            file_exists( $path . 'css/lcni-inbox.css' ) ? (string) filemtime( $path . 'css/lcni-inbox.css' ) : '1'
        );
        wp_enqueue_script(
            'lcni-inbox',
            $base . 'js/lcni-inbox.js',
            [],
            file_exists( $path . 'js/lcni-inbox.js' ) ? (string) filemtime( $path . 'js/lcni-inbox.js' ) : '1',
            true
        );
        wp_localize_script( 'lcni-inbox', 'lcniInboxCfg', [
            'restBase'    => esc_url( rest_url( 'lcni/v1/inbox' ) ),
            'nonce'       => wp_create_nonce( 'wp_rest' ),
            'inboxUrl'    => esc_url( LCNI_InboxDB::get_admin_config()['inbox_page_url'] ?? home_url('/') ),
            'isLoggedIn'  => true,
            'pollInterval'=> 60, // giây
        ] );
    }

    // ── Bell inject ───────────────────────────────────────────────────────────

    public function inject_bell() {
        if ( ! is_user_logged_in() ) return;
        if ( ! wp_script_is( 'lcni-inbox', 'enqueued' ) ) return;

        $js = <<<INBOXJS
(function(){
var SEL='.sd-topbar .sd-topbar-right,.sd-topbar-right,.site-header .header-right,.header__right,.navbar-right,.top-bar-right';
function injectBell(){
    if(document.getElementById('lcni-bell-btn')) return;
    var el=document.querySelector(SEL);
    if(!el) return;
    var w=document.createElement('div');
    w.id='lcni-bell-wrap';
    w.innerHTML='<button id="lcni-bell-btn" class="lcni-bell-btn" aria-label="Thong bao">'
        +'<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
        +'<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>'
        +'<path d="M13.73 21a2 2 0 0 1-3.46 0"/>'
        +'</svg>'
        +'<span id="lcni-bell-badge" class="lcni-bell-badge" hidden>0</span>'
        +'</button>'
        +'<div id="lcni-bell-dropdown" class="lcni-bell-dropdown" hidden></div>';
    var t=el.querySelector('.sd-user-dropdown__toggle,.user-toggle');
    t ? el.insertBefore(w,t) : el.appendChild(w);
}
if(document.readyState==='loading'){ document.addEventListener('DOMContentLoaded',injectBell); }
else{ injectBell(); setTimeout(injectBell,400); setTimeout(injectBell,1200); }
})();
INBOXJS;
        wp_add_inline_script( 'lcni-inbox', $js );
    }

    public function render_shortcode( $atts ) {
        if ( ! is_user_logged_in() ) {
            return '<p class="lcni-inbox-login">Vui lòng <a href="' . esc_url( wp_login_url( get_permalink() ) ) . '">đăng nhập</a> để xem thông báo.</p>';
        }

        $atts = shortcode_atts( [ 'view' => 'list' ], $atts, 'lcni_inbox' );
        $uid  = get_current_user_id();
        $id   = absint( $_GET['notif_id'] ?? 0 );

        ob_start();
        if ( $id > 0 ) {
            // Chi tiết 1 notification
            $row = LCNI_InboxDB::get_single( $id, $uid );
            if ( $row ) {
                // Auto mark read
                if ( ! $row['is_read'] ) LCNI_InboxDB::mark_read( $uid, [ $id ] );
                ?>
                <div class="lcni-inbox-detail">
                    <div class="lcni-inbox-detail__back">
                        <a href="<?php echo esc_url( remove_query_arg( 'notif_id' ) ); ?>">← Quay lại</a>
                    </div>
                    <div class="lcni-inbox-detail__card">
                        <div class="lcni-inbox-detail__meta">
                            <span class="lcni-inbox-type-badge lcni-inbox-type-<?php echo esc_attr( $row['type'] ); ?>">
                                <?php echo esc_html( LCNI_InboxDB::TYPES[ $row['type'] ] ?? $row['type'] ); ?>
                            </span>
                            <span class="lcni-inbox-detail__time"><?php echo esc_html( $row['created_at'] ); ?></span>
                        </div>
                        <h2 class="lcni-inbox-detail__title"><?php echo esc_html( $row['title'] ); ?></h2>
                        <div class="lcni-inbox-detail__body"><?php echo wp_kses_post( $row['body'] ); ?></div>
                        <?php if ( $row['url'] ): ?>
                        <a href="<?php echo esc_url( $row['url'] ); ?>" class="lcni-inbox-detail__link">Xem chi tiết →</a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php
            } else {
                echo '<p>Không tìm thấy thông báo.</p>';
            }
        } else {
            // Danh sách
            ?>
            <div class="lcni-inbox-page" id="lcni-inbox-page">
                <div class="lcni-inbox-page__header">
                    <h2>🔔 Thông báo của bạn</h2>
                    <div class="lcni-inbox-page__actions">
                        <button class="lcni-btn-mark-all-read" id="lcni-inbox-mark-all">✓ Đánh dấu tất cả đã đọc</button>
                        <button class="lcni-btn-prefs-toggle" id="lcni-inbox-prefs-toggle">⚙️ Tùy chọn</button>
                    </div>
                </div>

                <!-- Prefs panel -->
                <div class="lcni-inbox-prefs-panel" id="lcni-inbox-prefs" hidden>
                    <h4>Chọn loại thông báo muốn nhận</h4>
                    <div id="lcni-inbox-prefs-list" class="lcni-inbox-prefs-list">
                        <em>Đang tải...</em>
                    </div>
                    <button class="lcni-btn-save-prefs" id="lcni-inbox-save-prefs">💾 Lưu tùy chọn</button>
                </div>

                <!-- Filter tabs -->
                <div class="lcni-inbox-filter-tabs">
                    <button class="lcni-inbox-tab active" data-filter="all">Tất cả</button>
                    <button class="lcni-inbox-tab" data-filter="unread">Chưa đọc</button>
                    <button class="lcni-inbox-tab" data-filter="read">Đã đọc</button>
                    <span class="lcni-inbox-tab-sep">|</span>
                    <button class="lcni-inbox-tab lcni-inbox-tab--type" data-type="recommend_signal">🎯 Tín hiệu</button>
                    <button class="lcni-inbox-tab lcni-inbox-tab--type" data-type="auto_rule_signal">🤖 Auto Rule</button>
                    <button class="lcni-inbox-tab lcni-inbox-tab--type" data-type="marketing">🎁 Ưu đãi</button>
                    <button class="lcni-inbox-tab lcni-inbox-tab--type" data-type="upgrade_prompt">⭐ Nâng cấp</button>
                </div>

                <!-- List -->
                <div id="lcni-inbox-list" class="lcni-inbox-list">
                    <div class="lcni-inbox-loading">Đang tải...</div>
                </div>

                <div class="lcni-inbox-pagination">
                    <button id="lcni-inbox-load-more" class="lcni-inbox-load-more" hidden>Tải thêm</button>
                </div>
            </div>

            <script>
            // lcni-inbox.js load ở footer → dùng lcniInboxAutoInit thay DOMContentLoaded
            window.lcniInboxPageId = 'lcni-inbox-page';
            </script>
            <?php
        }
        return ob_get_clean();
    }

    // ── Admin menu ────────────────────────────────────────────────────────────

    public function admin_menu() {
        add_submenu_page(
            'lcni-settings',
            '🔔 Inbox Notification',
            '🔔 Inbox',
            'manage_options',
            'lcni-inbox-settings',
            [ $this, 'admin_page' ]
        );
    }

    public function admin_save() {
        if ( ! isset( $_POST['lcni_inbox_save'] ) ) return;
        if ( ! current_user_can( 'manage_options' ) ) return;
        if ( ! check_admin_referer( 'lcni_inbox_admin' ) ) return;

        $enabled = isset( $_POST['inbox_enabled_types'] ) ? (array) $_POST['inbox_enabled_types'] : [];
        $enabled = array_values( array_intersect( array_keys( LCNI_InboxDB::TYPES ), array_map( 'sanitize_key', $enabled ) ) );

        $cfg = [
            'enabled_types'  => $enabled,
            'retention_days' => max( 7, min( 365, absint( $_POST['inbox_retention_days'] ?? 90 ) ) ),
            'max_per_user'   => max( 10, min( 1000, absint( $_POST['inbox_max_per_user']  ?? 200 ) ) ),
            'inbox_page_url' => esc_url_raw( wp_unslash( $_POST['inbox_page_url'] ?? home_url('/') ) ),
        ];

        LCNI_InboxDB::save_admin_config( $cfg );
        wp_safe_redirect( add_query_arg( 'saved', '1', admin_url( 'admin.php?page=lcni-inbox-settings' ) ) );
        exit;
    }

    public function admin_broadcast_send() {
        if ( ! isset( $_POST['lcni_inbox_broadcast'] ) ) return;
        if ( ! current_user_can( 'manage_options' ) ) return;
        if ( ! check_admin_referer( 'lcni_inbox_broadcast' ) ) return;

        do_action( 'lcni_admin_broadcast', [
            'title' => sanitize_text_field( wp_unslash( $_POST['bc_title'] ?? 'Thông báo' ) ),
            'body'  => wp_kses_post( wp_unslash( $_POST['bc_body'] ?? '' ) ),
            'url'   => esc_url_raw( wp_unslash( $_POST['bc_url'] ?? '' ) ),
        ] );

        wp_safe_redirect( add_query_arg( 'broadcast', '1', admin_url( 'admin.php?page=lcni-inbox-settings' ) ) );
        exit;
    }

    // ── Admin page ────────────────────────────────────────────────────────────

    public function admin_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        $cfg = LCNI_InboxDB::get_admin_config();
        $uid = get_current_user_id();

        // Đếm thông báo admin chưa đọc
        $admin_unread = LCNI_InboxDB::get_unread_count( $uid );
        ?>
        <div class="wrap">
            <h1>🔔 Inbox Notification Settings</h1>

            <?php if ( isset( $_GET['saved'] ) ): ?>
                <div class="notice notice-success is-dismissible"><p>✅ Đã lưu.</p></div>
            <?php elseif ( isset( $_GET['broadcast'] ) ): ?>
                <div class="notice notice-success is-dismissible"><p>📢 Đã gửi broadcast.</p></div>
            <?php endif; ?>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;max-width:1000px;margin-top:16px">

                <!-- Cài đặt loại thông báo -->
                <div style="background:#fff;padding:20px;border:1px solid #e5e7eb;border-radius:10px">
                    <h3 style="margin-top:0">⚙️ Cài đặt loại thông báo</h3>
                    <form method="post">
                        <?php wp_nonce_field('lcni_inbox_admin'); ?>

                        <p><strong>Loại thông báo bật cho user:</strong></p>
                        <?php foreach ( LCNI_InboxDB::TYPES as $key => $label ): ?>
                        <label style="display:block;margin-bottom:6px">
                            <input type="checkbox" name="inbox_enabled_types[]" value="<?php echo esc_attr($key); ?>"
                                <?php checked( in_array( $key, $cfg['enabled_types'], true ) ); ?>>
                            <?php echo esc_html($label); ?>
                        </label>
                        <?php endforeach; ?>

                        <hr>
                        <table class="form-table">
                            <tr>
                                <th>Giữ thông báo (ngày)</th>
                                <td><input type="number" name="inbox_retention_days" min="7" max="365"
                                    value="<?php echo esc_attr((string)$cfg['retention_days']); ?>"></td>
                            </tr>
                            <tr>
                                <th>Tối đa / user</th>
                                <td><input type="number" name="inbox_max_per_user" min="10" max="1000"
                                    value="<?php echo esc_attr((string)$cfg['max_per_user']); ?>"></td>
                            </tr>
                            <tr>
                                <th>URL trang Inbox</th>
                                <td><input type="url" name="inbox_page_url" class="regular-text"
                                    value="<?php echo esc_attr($cfg['inbox_page_url']); ?>">
                                    <p class="description">URL trang có <code>[lcni_inbox]</code></p></td>
                            </tr>
                        </table>

                        <input type="submit" name="lcni_inbox_save" class="button button-primary" value="💾 Lưu cài đặt">
                    </form>
                </div>

                <!-- Broadcast -->
                <div style="background:#fff;padding:20px;border:1px solid #e5e7eb;border-radius:10px">
                    <h3 style="margin-top:0">📢 Gửi broadcast đến tất cả user</h3>
                    <form method="post">
                        <?php wp_nonce_field('lcni_inbox_broadcast'); ?>
                        <table class="form-table">
                            <tr>
                                <th>Tiêu đề</th>
                                <td><input type="text" name="bc_title" class="regular-text" required placeholder="Tiêu đề thông báo"></td>
                            </tr>
                            <tr>
                                <th>Nội dung</th>
                                <td><textarea name="bc_body" rows="4" class="large-text" placeholder="Nội dung HTML..."></textarea></td>
                            </tr>
                            <tr>
                                <th>URL (tùy chọn)</th>
                                <td><input type="url" name="bc_url" class="regular-text" placeholder="https://..."></td>
                            </tr>
                        </table>
                        <input type="submit" name="lcni_inbox_broadcast" class="button button-primary"
                            value="📢 Gửi ngay"
                            onclick="return confirm('Gửi broadcast đến TẤT CẢ user?')">
                    </form>

                    <hr style="margin-top:24px">
                    <h4>Shortcode</h4>
                    <p>Nhúng trang inbox: <code>[lcni_inbox]</code></p>
                    <p>REST API base: <code><?php echo esc_html( rest_url('lcni/v1/inbox') ); ?></code></p>
                </div>

            </div>

            <!-- Admin Inbox -->
            <div style="margin-top:32px;background:#fff;padding:20px;border:1px solid #e5e7eb;border-radius:10px;max-width:1000px">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
                    <h3 style="margin:0">
                        📥 Inbox Admin
                        <?php if ( $admin_unread > 0 ): ?>
                            <span style="background:#ef4444;color:#fff;font-size:12px;padding:2px 8px;border-radius:12px;margin-left:8px"><?php echo (int) $admin_unread; ?> chưa đọc</span>
                        <?php endif; ?>
                    </h3>
                    <button id="lcni-admin-mark-all" class="button button-secondary" style="font-size:12px">✓ Đánh dấu tất cả đã đọc</button>
                </div>
                <p style="color:#6b7280;font-size:13px;margin-top:0">
                    Tự động nhận: <?php echo implode(', ', array_map( 'esc_html', LCNI_InboxDB::ADMIN_TYPES ) ); ?>
                </p>

                <!-- Filter tabs -->
                <div style="display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap">
                    <?php
                    $admin_types_keys = array_keys( LCNI_InboxDB::ADMIN_TYPES );
                    $filter_type      = isset( $_GET['admin_inbox_type'] ) ? sanitize_key( $_GET['admin_inbox_type'] ) : '';
                    $base_url         = admin_url( 'admin.php?page=lcni-inbox-settings' );
                    ?>
                    <a href="<?php echo esc_url( $base_url ); ?>" class="button button-<?php echo $filter_type === '' ? 'primary' : 'secondary'; ?>" style="font-size:12px">Tất cả</a>
                    <?php foreach ( LCNI_InboxDB::ADMIN_TYPES as $atype => $alabel ): ?>
                    <a href="<?php echo esc_url( add_query_arg( 'admin_inbox_type', $atype, $base_url ) ); ?>"
                       class="button button-<?php echo $filter_type === $atype ? 'primary' : 'secondary'; ?>"
                       style="font-size:12px"><?php echo esc_html( $alabel ); ?></a>
                    <?php endforeach; ?>
                </div>

                <!-- Admin inbox list -->
                <?php
                global $wpdb;
                $t     = LCNI_InboxDB::table_inbox();
                $per   = 15;
                $paged = max( 1, (int)( isset( $_GET['admin_inbox_page'] ) ? $_GET['admin_inbox_page'] : 1 ) );
                $off   = ( $paged - 1 ) * $per;

                if ( $filter_type && isset( LCNI_InboxDB::ADMIN_TYPES[ $filter_type ] ) ) {
                    $count_sql = $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE user_id=%d AND type=%s", $uid, $filter_type );
                    $list_sql  = $wpdb->prepare( "SELECT * FROM {$t} WHERE user_id=%d AND type=%s ORDER BY created_at DESC LIMIT %d OFFSET %d", $uid, $filter_type, $per, $off );
                } else {
                    $ph       = implode( ',', array_fill( 0, count( $admin_types_keys ), '%s' ) );
                    $args_c   = array_merge( [ $uid ], $admin_types_keys );
                    $args_l   = array_merge( [ $uid ], $admin_types_keys, [ $per, $off ] );
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
                    $count_sql = $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE user_id=%d AND type IN ({$ph})", $args_c );
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
                    $list_sql  = $wpdb->prepare( "SELECT * FROM {$t} WHERE user_id=%d AND type IN ({$ph}) ORDER BY created_at DESC LIMIT %d OFFSET %d", $args_l );
                }
                $total = (int) $wpdb->get_var( $count_sql );
                $rows  = $wpdb->get_results( $list_sql, ARRAY_A );
                ?>
                <table class="widefat striped" style="font-size:13px">
                    <thead>
                        <tr>
                            <th width="24">&nbsp;</th>
                            <th>Loại</th>
                            <th>Tiêu đề</th>
                            <th>Nội dung</th>
                            <th>Thời gian</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ( empty( $rows ) ): ?>
                        <tr><td colspan="5" style="text-align:center;padding:16px;color:#9ca3af">Chưa có thông báo admin nào.</td></tr>
                    <?php else: ?>
                        <?php foreach ( $rows as $row ): ?>
                        <tr style="<?php echo ! $row['is_read'] ? 'background:#eff6ff;' : ''; ?>">
                            <td><?php echo $row['is_read'] ? '' : '<span style="color:#2563eb;font-size:10px">●</span>'; ?></td>
                            <td><span style="font-size:11px;white-space:nowrap"><?php echo esc_html( LCNI_InboxDB::get_type_label( $row['type'] ) ); ?></span></td>
                            <td style="font-weight:<?php echo $row['is_read'] ? '400' : '600'; ?>">
                                <?php if ( $row['url'] ): ?>
                                    <a href="<?php echo esc_url( $row['url'] ); ?>"><?php echo esc_html( $row['title'] ); ?></a>
                                <?php else: ?>
                                    <?php echo esc_html( $row['title'] ); ?>
                                <?php endif; ?>
                            </td>
                            <td style="color:#374151"><?php echo wp_kses_post( $row['body'] ); ?></td>
                            <td style="white-space:nowrap;color:#6b7280;font-size:11px"><?php echo esc_html( $row['created_at'] ); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>

                <?php if ( $total > $per ): ?>
                <div style="margin-top:12px;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                    <?php
                    $pages = (int) ceil( $total / $per );
                    for ( $i = 1; $i <= $pages; $i++ ) {
                        $url = add_query_arg( [ 'admin_inbox_type' => $filter_type ?: false, 'admin_inbox_page' => $i ], $base_url );
                        printf(
                            '<a href="%s" class="button button-%s" style="font-size:12px">%d</a>',
                            esc_url( $url ),
                            $i === $paged ? 'primary' : 'secondary',
                            $i
                        );
                    }
                    ?>
                    <span style="color:#6b7280;font-size:12px">Tổng: <?php echo (int) $total; ?> thông báo</span>
                </div>
                <?php endif; ?>

                <script>
                document.getElementById('lcni-admin-mark-all')?.addEventListener('click', function() {
                    fetch(<?php echo wp_json_encode( rest_url('lcni/v1/inbox/mark-read') ); ?>, {
                        method: 'POST',
                        headers: { 'X-WP-Nonce': <?php echo wp_json_encode( wp_create_nonce('wp_rest') ); ?>, 'Content-Type': 'application/json' },
                        body: JSON.stringify({ ids: 'all' })
                    }).then(() => location.reload());
                });
                </script>
            </div>

        </div>
        <?php
    }
}
