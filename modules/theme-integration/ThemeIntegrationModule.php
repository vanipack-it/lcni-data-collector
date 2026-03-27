<?php
/**
 * LCNI Theme Integration Module
 *
 * Kết nối plugin lcni-data-collector với Stock Dashboard Theme v2.3+.
 * Chỉ được khởi tạo khi theme stock-dashboard-theme đang active.
 *
 * Chức năng:
 *  1. Lọc module sidebar theo gói SaaS của user (stock_dashboard_modules filter)
 *  2. Gate content: redirect nếu truy cập tab không có quyền (wp action)
 *  3. Inject CSS package badge lên avatar toggle (wp_head)
 *  4. Render badge HTML trong user dropdown header (sd_user_dropdown_header_after)
 *
 * @package LCNI_Data_Collector
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LCNI_Theme_Integration_Module {

    /**
     * Map: theme module slug → plugin module key + capability
     * Cho phép một theme-slug map sang nhiều plugin module keys (dùng array).
     */
    const MODULE_MAP = [
        // theme slug          => plugin module key
        'overview'            => 'overview',
        'overview-indices'    => 'overview',
        'overview-watchlist'  => 'watchlist',
        'portfolio'           => 'portfolio',
        'stock-detail'        => 'chart',
        'watchlist'           => 'watchlist',
        'filter'              => 'filter',
        'signals'             => 'signals',
        'chart'               => 'chart',
        'integrations'        => null,   // null = luôn hiện (không check permission)
        'settings'            => null,
    ];

    /** @var LCNI_SaaS_Service */
    private $service;

    public function __construct( LCNI_SaaS_Service $service ) {
        $this->service = $service;

        // ── Phase 1: Pure filter/hook, không sửa theme ──────────────────────

        // 1. Lọc modules hiển thị trong sidebar theo gói user
        add_filter( 'stock_dashboard_modules', [ $this, 'filter_modules_by_package' ] );

        // 2. Gate: redirect nếu truy cập tab không có quyền
        add_action( 'wp', [ $this, 'gate_dashboard_content' ] );

        // 3. CSS badge trên avatar toggle (luôn visible, không cần mở dropdown)
        add_action( 'wp_head', [ $this, 'inject_package_badge_css' ] );

        // 3b. Fallback: inject avatar JS qua wp_footer với priority thấp
        // Chạy kể cả khi không detect được dashboard_context
        add_action( 'wp_footer', [ $this, 'inject_avatar_fallback_script' ], 99 );

        // 3c. Bell notification button (bên cạnh avatar)
        // bell inject handled inside inject_avatar_fallback_script
        // Thêm bell qua PHP hook trong topbar (chuẩn nhất)
        add_action( 'sd_topbar_right_before_user', [ $this, 'render_bell_html' ] );

        // ── Phase 2: Cần theme thêm do_action (xem topbar.php + bottom-bar.php) ──

        // 4. Badge HTML đầy đủ trong dropdown header
        add_action( 'sd_user_dropdown_header_after', [ $this, 'render_dropdown_badge' ] );
    }

    // =========================================================================
    // 1. Filter modules by package
    // =========================================================================

    /**
     * Nhận mảng modules từ theme, loại bỏ module user không có quyền truy cập.
     * Admin (manage_options) luôn thấy tất cả.
     *
     * @param array $modules
     * @return array
     */
    public function filter_modules_by_package( $modules ) {
        if ( ! is_array( $modules ) ) {
            return $modules;
        }

        // Admin thấy tất cả
        if ( current_user_can( 'manage_options' ) ) {
            return $modules;
        }

        $filtered = [];
        foreach ( $modules as $slug => $module ) {
            $module_slug = is_string( $slug ) ? $slug : ( $module['id'] ?? $module['slug'] ?? '' );
            $module_slug = sanitize_key( $module_slug );

            if ( $this->can_access_theme_module( $module_slug ) ) {
                // Lọc children nếu có
                if ( ! empty( $module['children'] ) && is_array( $module['children'] ) ) {
                    $filtered_children = [];
                    foreach ( $module['children'] as $child_slug => $child ) {
                        $child_slug = is_string( $child_slug ) ? $child_slug : ( $child['id'] ?? $child['slug'] ?? '' );
                        $child_slug = sanitize_key( $child_slug );
                        if ( $this->can_access_theme_module( $child_slug ) ) {
                            $filtered_children[ $child_slug ] = $child;
                        }
                    }
                    $module['children'] = $filtered_children;
                }
                $filtered[ $module_slug ] = $module;
            }
        }

        return $filtered;
    }

    /**
     * Kiểm tra xem user có quyền xem theme module không.
     *
     * @param string $theme_slug
     * @return bool
     */
    private function can_access_theme_module( $theme_slug ) {
        if ( ! array_key_exists( $theme_slug, self::MODULE_MAP ) ) {
            // Slug không có trong map → hiển thị mặc định (có thể là module ngoài)
            return true;
        }

        $plugin_module = self::MODULE_MAP[ $theme_slug ];

        // null = luôn hiện (không cần quyền)
        if ( $plugin_module === null ) {
            return true;
        }

        // Guest không có quyền gì ngoài module null
        if ( ! is_user_logged_in() ) {
            return false;
        }

        return $this->service->can( $plugin_module, 'view' );
    }

    // =========================================================================
    // 2. Gate dashboard content
    // =========================================================================

    /**
     * Chạy ở hook 'wp' — sau khi WP query đã resolve.
     * Nếu user đang ở một tab không có quyền → redirect sang upgrade page.
     */
    public function gate_dashboard_content() {
        if ( ! function_exists( 'stock_dashboard_theme_is_dashboard_context' ) ) {
            return;
        }

        if ( ! stock_dashboard_theme_is_dashboard_context() ) {
            return;
        }

        // Admin bypass
        if ( current_user_can( 'manage_options' ) ) {
            return;
        }

        $current_tab = function_exists( 'stock_dashboard_theme_get_current_tab' )
            ? stock_dashboard_theme_get_current_tab()
            : 'overview';

        if ( $this->can_access_theme_module( $current_tab ) ) {
            return;
        }

        // Redirect về trang upgrade hoặc trang dashboard overview
        $upgrade_url = get_option( 'lcni_saas_upgrade_url', '' );
        if ( empty( $upgrade_url ) ) {
            // Fallback: redirect về tab overview của dashboard
            $upgrade_url = function_exists( 'stock_dashboard_theme_get_dashboard_url' )
                ? stock_dashboard_theme_get_dashboard_url()
                : home_url( '/' );
        }

        wp_safe_redirect( esc_url_raw( $upgrade_url ) );
        exit;
    }

    // =========================================================================
    // 3. CSS package badge (Phase 1 — không cần sửa theme)
    // =========================================================================

    /**
     * Inject CSS variables + badge styles vào wp_head.
     * Badge xuất hiện dưới dạng ::after pseudo-element trên .sd-user-dropdown__toggle.
     * Plugin thêm data-lcni-pkg attribute vào toggle bằng JS snippet nhỏ inline.
     */
    public function inject_package_badge_css() {
        if ( ! function_exists( 'stock_dashboard_theme_is_dashboard_context' ) ) {
            return;
        }

        if ( ! stock_dashboard_theme_is_dashboard_context() ) {
            return;
        }

        if ( ! is_user_logged_in() ) {
            return;
        }

        $pkg = $this->get_current_user_badge_data();

        $color      = esc_attr( $pkg['color'] );
        $is_expired = $pkg['is_expired'] ? 'true' : 'false';
        $badge_icon = $pkg['badge_icon'] ?? '';
        $label      = $pkg['label'];

        // Xác định nội dung hiển thị: ưu tiên icon, fallback label text
        $has_icon    = ! empty( $badge_icon );
        $is_dashicon = $has_icon && strpos( $badge_icon, 'dashicons' ) !== false;
        $is_emoji    = $has_icon && ! $is_dashicon && ! preg_match( '/^[a-z][\w\- ]*[a-z]$/i', $badge_icon );

        // Tạo inner HTML của badge span
        if ( $has_icon ) {
            if ( $is_dashicon ) {
                $badge_inner_js = json_encode( '<span class="dashicons ' . esc_attr( $badge_icon ) . '" style="font-size:11px;width:11px;height:11px;line-height:1;display:flex;"></span>' );
            } else {
                // FA class hoặc emoji
                $badge_inner_js = json_encode( '<i class="' . esc_attr( $badge_icon ) . '" style="font-size:10px;line-height:1;"></i>' );
            }
        } else {
            // Chỉ có label text
            $badge_inner_js = json_encode( esc_html( $label ) );
        }

        // ── Avatar cho nút toggle user ────────────────────────────────────────
        $avatar_html_js = $this->build_toggle_avatar_js( $pkg['color'] );

        // ── Lời chào theo giờ trên topbar desktop ────────────────────────────
        $greeting_js = $this->build_greeting_js();
        ?>
        <style id="lcni-pkg-badge-css">
        /* ── LCNI Package Badge ─────────────────────────────────── */
        :root {
            --lcni-pkg-color: <?php echo $color; ?>;
            --lcni-pkg-expired-color: #9ca3af;
        }

        .sd-user-dropdown__toggle {
            position: relative;
        }

        /* Badge span được inject bằng JS — căn giữa phía trên icon */
        .lcni-toggle-badge {
            position: absolute;
            top: -7px;
            left: 50%;
            transform: translateX(-50%);
            min-width: 16px;
            height: 16px;
            background: var(--lcni-pkg-color);
            border-radius: 99px;
            border: 2px solid var(--sd-color-sidebar, #1d2327);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0 3px;
            pointer-events: none;
            z-index: 10;
            box-sizing: border-box;
            line-height: 1;
        }
        .lcni-toggle-badge--text {
            font-size: 8px;
            font-weight: 800;
            color: #fff;
            letter-spacing: .04em;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            min-width: 20px;
            padding: 0 4px;
        }
        .lcni-toggle-badge--icon {
            min-width: 16px;
            padding: 0 2px;
        }
        .lcni-toggle-badge--icon .dashicons,
        .lcni-toggle-badge--icon i {
            color: #fff;
        }
        .lcni-toggle-badge--expired {
            background: var(--lcni-pkg-expired-color) !important;
        }
        /* Mobile bottom bar — căn giữa phía trên icon */
        .sd-user-dropdown--bottom-bar .lcni-toggle-badge {
            top: -7px;
            left: 50%;
            transform: translateX(-50%);
            right: auto;
        }

        /* Lời chào trên topbar */
        .lcni-topbar-greeting {
            font-size: 12px;
            color: rgba(255,255,255,0.65);
            margin-right: 8px;
            white-space: nowrap;
            font-weight: 400;
            line-height: 1;
            vertical-align: middle;
        }
        .lcni-topbar-greeting strong {
            color: rgba(255,255,255,0.9);
            font-weight: 600;
        }
        /* Ẩn trên màn hình nhỏ */
        @media (max-width: 640px) {
            .lcni-topbar-greeting { display: none; }
        }

        /* ── LCNI User Avatar trong toggle button ───────────────────── */
        .lcni-user-avatar {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            overflow: hidden;
            flex-shrink: 0;
            box-sizing: border-box;
            vertical-align: middle;
        }
        .lcni-user-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 50%;
            display: block;
        }
        /* Kích thước trong toggle topbar */
        .sd-topbar .lcni-user-avatar {
            width: 28px;
            height: 28px;
        }
        /* Kích thước trong toggle bottom-bar */
        .sd-bottom-bar .lcni-user-avatar {
            width: 26px;
            height: 26px;
        }
        </style>
        <script id="lcni-pkg-badge-js">
        (function() {
            var badgeInner = <?php echo $badge_inner_js; ?>;
            var isIcon     = <?php echo $has_icon ? 'true' : 'false'; ?>;
            var expired    = <?php echo $is_expired; ?>;
            var avatarHtml  = <?php echo $avatar_html_js; ?>;
            var greetingHtml = <?php echo $greeting_js; ?>;

            function applyBadge() {
                var toggles = document.querySelectorAll('.sd-user-dropdown__toggle');
                toggles.forEach(function(el) {
                    // Không thêm badge trùng
                    if (el.querySelector('.lcni-toggle-badge')) return;

                    var span = document.createElement('span');
                    span.className = 'lcni-toggle-badge' +
                        (isIcon   ? ' lcni-toggle-badge--icon' : ' lcni-toggle-badge--text') +
                        (expired  ? ' lcni-toggle-badge--expired' : '');
                    span.innerHTML = badgeInner;

                    // Đảm bảo toggle có position: relative
                    var pos = window.getComputedStyle(el).position;
                    if (pos === 'static') el.style.position = 'relative';

                    el.appendChild(span);
                });
            }

            /**
             * Thay thế dashicons-admin-users trong nút toggle bằng avatar user.
             *
             * Dùng replaceChild() thay vì display:none để tránh bị reset
             * khi <details> open/close trên một số browser.
             * Tìm icon bằng Array.from(el.children) — đáng tin hơn :scope selector
             * khi el là <summary> element.
             */
            function applyAvatar() {
                // avatarHtml có thể là null (PHP json_encode null = "null" string)
                if (!avatarHtml || avatarHtml === 'null') return;

                // Selector ưu tiên: toggle trong topbar/bottom-bar
                // Fallback: tất cả toggle (phòng trường hợp theme đổi class wrapper)
                var toggles = document.querySelectorAll(
                    '.sd-topbar .sd-user-dropdown__toggle, ' +
                    '.sd-bottom-bar .sd-user-dropdown__toggle'
                );

                // Fallback nếu không tìm thấy với selector hẹp
                if (!toggles.length) {
                    // Lọc thủ công: chỉ lấy toggle KHÔNG nằm trong .sd-user-dropdown__menu
                    var all = document.querySelectorAll('.sd-user-dropdown__toggle');
                    var filtered = [];
                    all.forEach(function(el) {
                        if (!el.closest('.sd-user-dropdown__menu')) {
                            filtered.push(el);
                        }
                    });
                    toggles = filtered;
                }

                if (!toggles.length) return;

                toggles.forEach(function(el) {
                    // Bottom-bar: PHP lcni_get_user_avatar() đã render .lcni-avatar → skip
                    if (el.closest('.sd-bottom-bar')) return;
                    // Bỏ qua nếu đã inject avatar rồi
                    if (el.querySelector('.lcni-user-avatar, .lcni-avatar')) return;

                    // Tìm dashicons-admin-users là con TRỰC TIẾP
                    var icon = null;
                    var kids = Array.prototype.slice.call(el.children);
                    for (var i = 0; i < kids.length; i++) {
                        if (kids[i].classList && kids[i].classList.contains('dashicons-admin-users')) {
                            icon = kids[i];
                            break;
                        }
                    }

                    // Build avatar element
                    var tmp = document.createElement('span');
                    tmp.innerHTML = avatarHtml;
                    var avatarEl = tmp.firstElementChild;
                    if (!avatarEl) return;

                    if (icon) {
                        // replaceChild — xóa hẳn khỏi DOM, không dùng display:none
                        el.replaceChild(avatarEl, icon);
                    } else {
                        // Không có dashicons (có thể theme đã dùng get_avatar)
                        // → thay thế img/avatar hiện có nếu có, hoặc prepend
                        var existingImg = el.querySelector('img');
                        if (existingImg && existingImg.parentElement === el) {
                            el.replaceChild(avatarEl, existingImg);
                        } else {
                            el.insertBefore(avatarEl, el.firstChild);
                        }
                    }
                });
            }

            /**
             * Inject lời chào bên trái avatar trong .sd-topbar-right (desktop only).
             * CSS đã ẩn .lcni-topbar-greeting trên ≤640px.
             */
            function applyGreeting() {
                if (!greetingHtml) return;

                // Chỉ inject vào topbar (không phải bottom-bar)
                var topbarRight = document.querySelector('.sd-topbar .sd-topbar-right, .sd-topbar-right');
                if (!topbarRight) return;

                // Không inject trùng
                if (topbarRight.querySelector('.lcni-topbar-greeting')) return;

                // Tìm toggle trong topbar-right để chèn greeting ngay trước nó
                var toggle = topbarRight.querySelector('.sd-user-dropdown__toggle');
                var wrapper = document.createElement('span');
                wrapper.innerHTML = greetingHtml;
                var greetingEl = wrapper.firstElementChild;
                if (!greetingEl) return;

                if (toggle) {
                    topbarRight.insertBefore(greetingEl, toggle);
                } else {
                    topbarRight.insertBefore(greetingEl, topbarRight.firstChild);
                }
            }

            function init() {
                applyGreeting();
                applyAvatar();
                applyBadge();
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', init);
            } else {
                init();
            }
        })();
        </script>
        <?php
    }

    // =========================================================================
    // 4. Dropdown badge HTML (Phase 2 — requires do_action in topbar.php)
    // =========================================================================

    /**
     * Render badge HTML đầy đủ bên dưới tên user trong dropdown.
     * Được gọi bởi do_action('sd_user_dropdown_header_after') trong topbar.php.
     */
    public function render_dropdown_badge() {
        if ( ! is_user_logged_in() ) {
            return;
        }

        $pkg = $this->get_current_user_badge_data();

        $color      = esc_attr( $pkg['color'] );
        $label      = esc_html( $pkg['label'] ?: $pkg['name'] );
        $icon       = $pkg['badge_icon'] ?? '';
        $is_expired = $pkg['is_expired'];
        $expires    = $pkg['expires_label'];
        $has_pkg    = ! empty( $pkg['name'] );

        if ( ! $has_pkg ) {
            return;
        }

        $badge_bg    = $is_expired ? '#fee2e2' : 'rgba(' . $this->hex_to_rgb( $pkg['color'] ) . ',0.12)';
        $badge_color = $is_expired ? '#991b1b' : $pkg['color'];
        ?>
        <div class="lcni-dropdown-badge" style="
            display:flex;align-items:center;gap:6px;
            margin-top:6px;padding:5px 10px;
            background:<?php echo esc_attr( $badge_bg ); ?>;
            border-radius:20px;width:fit-content;
            border:1px solid <?php echo esc_attr( $is_expired ? '#fca5a5' : 'rgba(' . $this->hex_to_rgb( $pkg['color'] ) . ',.25)' ); ?>;
        ">
            <?php if ( ! empty( $icon ) ) : ?>
                <?php if ( strpos( $icon, 'dashicons' ) !== false ) : ?>
                    <span class="dashicons <?php echo esc_attr( $icon ); ?>" style="font-size:13px;width:13px;height:13px;color:<?php echo esc_attr( $badge_color ); ?>;"></span>
                <?php else : ?>
                    <i class="<?php echo esc_attr( $icon ); ?>" style="font-size:11px;color:<?php echo esc_attr( $badge_color ); ?>;"></i>
                <?php endif; ?>
            <?php else : ?>
                <span style="width:7px;height:7px;border-radius:50%;background:<?php echo esc_attr( $badge_color ); ?>;flex-shrink:0;"></span>
            <?php endif; ?>
            <span style="font-size:11px;font-weight:700;color:<?php echo esc_attr( $badge_color ); ?>;letter-spacing:.04em;line-height:1;">
                <?php echo $label; ?>
            </span>
            <?php if ( $is_expired ) : ?>
                <span style="font-size:10px;color:#ef4444;font-weight:600;">⚠ Hết hạn</span>
            <?php elseif ( $expires ) : ?>
                <span style="font-size:10px;color:<?php echo esc_attr( $badge_color ); ?>;opacity:.7;"><?php echo esc_html( $expires ); ?></span>
            <?php endif; ?>
        </div>
        <?php
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Lấy thông tin badge của user hiện tại.
     *
     * @return array{name:string,label:string,color:string,badge_icon:string,is_expired:bool,expires_label:string}
     */
    private function get_current_user_badge_data() {
        $default = [
            'name'        => '',
            'label'       => '',
            'color'       => '#9ca3af',
            'badge_icon'  => '',
            'is_expired'  => false,
            'expires_label' => '',
        ];

        if ( ! is_user_logged_in() ) {
            return $default;
        }

        $pkg_info = $this->service->get_current_user_package_info();

        if ( empty( $pkg_info ) ) {
            return $default;
        }

        $is_expired = ! empty( $pkg_info['is_expired'] );
        $expires_label = '';
        if ( ! empty( $pkg_info['expires_at'] ) ) {
            $expires_ts = strtotime( $pkg_info['expires_at'] );
            if ( $expires_ts > 0 ) {
                $diff_days = (int) ceil( ( $expires_ts - time() ) / DAY_IN_SECONDS );
                if ( $is_expired ) {
                    $expires_label = 'Hết hạn';
                } elseif ( $diff_days <= 7 ) {
                    $expires_label = 'còn ' . $diff_days . 'ngày';
                } elseif ( $diff_days <= 30 ) {
                    $expires_label = 'còn ' . $diff_days . 'ngày';
                }
            }
        }

        $badge_label = ! empty( $pkg_info['badge_label'] ) ? $pkg_info['badge_label'] : '';
        if ( $badge_label === '' && ! empty( $pkg_info['package_name'] ) ) {
            // Auto-generate: lấy từ đầu tên gói (tối đa 5 ký tự viết hoa)
            $auto = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', $pkg_info['package_name'] ) );
            $badge_label = substr( $auto, 0, 5 );
        }

        return [
            'name'          => $pkg_info['package_name'] ?? '',
            'label'         => $badge_label,
            'color'         => ! empty( $pkg_info['color'] ) ? $pkg_info['color'] : '#2563eb',
            'badge_icon'    => $pkg_info['badge_icon'] ?? '',
            'is_expired'    => $is_expired,
            'expires_label' => $expires_label,
        ];
    }


    /**
     * Build HTML avatar cho nút toggle, encode thành JSON string để inject vào JS.
     *
     * Thứ tự ưu tiên:
     *  1. Ảnh Google (meta lcni_google_avatar) — render <img>
     *  2. Initials từ display_name              — render <span> tròn inline
     *
     * Không dùng Gravatar ở đây vì cần HTTP request async — toggle phải hiện ngay.
     *
     * @param string $border_color Màu viền hex từ gói SaaS.
     * @return string JSON-encoded HTML string để nhúng vào JS.
     */

    /**
     * Build lời chào theo giờ hiện tại, encode thành JSON string cho JS.
     *
     * Giờ server (giờ VN nếu WP timezone đúng):
     *  05:00–11:59 → Chào buổi sáng
     *  12:00–17:59 → Chào buổi chiều
     *  18:00–04:59 → Chào buổi tối
     *
     * Tên dùng: display_name, fallback user_login.
     *
     * @return string JSON-encoded HTML string hoặc "null".
     */
    private function build_greeting_js() {
        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return 'null';
        }

        $user = get_userdata( $user_id );
        if ( ! $user ) {
            return 'null';
        }

        // Lấy giờ theo timezone WordPress
        $hour = (int) current_time( 'G' ); // 0-23

        if ( $hour >= 5 && $hour < 12 ) {
            $salutation = 'Chào buổi sáng';
        } elseif ( $hour >= 12 && $hour < 18 ) {
            $salutation = 'Chào buổi chiều';
        } else {
            $salutation = 'Chào buổi tối';
        }

        $name = ! empty( $user->display_name ) ? $user->display_name : $user->user_login;
        $name = esc_html( $name );

        $html = sprintf(
            '<span class="lcni-topbar-greeting">%s, <strong>%s</strong></span>',
            esc_html( $salutation ),
            $name
        );

        return wp_json_encode( $html );
    }

    private function build_toggle_avatar_js( $border_color ) {
        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return 'null';
        }

        $user         = get_userdata( $user_id );
        $border_color = sanitize_hex_color( $border_color ) ?: '#2563eb';
        $size         = 28; // px — đủ nhìn rõ trong cả topbar lẫn bottom-bar
        $border_px    = 2;

        // ── 1. Google avatar ─────────────────────────────────────────────────
        $google_url = get_user_meta( $user_id, 'lcni_google_avatar', true );
        if ( $google_url && filter_var( $google_url, FILTER_VALIDATE_URL ) ) {
            $html = sprintf(
                '<span class="lcni-user-avatar" style="width:%1$dpx;height:%1$dpx;border:%2$dpx solid %3$s;">'
                . '<img src="%4$s" alt="%5$s" width="%1$d" height="%1$d" loading="lazy">'
                . '</span>',
                $size,
                $border_px,
                esc_attr( $border_color ),
                esc_url( $google_url ),
                esc_attr( $user ? $user->display_name : '' )
            );
            return wp_json_encode( $html );
        }

        // ── 2. Initials fallback ─────────────────────────────────────────────
        $display_name = $user ? trim( $user->display_name ) : '';
        if ( empty( $display_name ) ) {
            return 'null';
        }

        $words = preg_split( '/\s+/u', $display_name, -1, PREG_SPLIT_NO_EMPTY );
        if ( count( $words ) >= 2 ) {
            $initials = mb_strtoupper( mb_substr( $words[0], 0, 1, 'UTF-8' ), 'UTF-8' )
                      . mb_strtoupper( mb_substr( $words[ count($words)-1 ], 0, 1, 'UTF-8' ), 'UTF-8' );
        } else {
            $initials = mb_strtoupper( mb_substr( $words[0], 0, 2, 'UTF-8' ), 'UTF-8' );
        }

        // Tính màu nền nhạt 12% opacity
        $hex = ltrim( $border_color, '#' );
        if ( strlen( $hex ) === 3 ) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }
        $r = hexdec( substr($hex,0,2) );
        $g = hexdec( substr($hex,2,2) );
        $b = hexdec( substr($hex,4,2) );
        $bg = "rgba({$r},{$g},{$b},0.12)";

        $font_size = max( 9, (int) round( $size * 0.38 ) );

        $html = sprintf(
            '<span class="lcni-user-avatar" '
            . 'style="width:%1$dpx;height:%1$dpx;border:%2$dpx solid %3$s;'
            . 'background:%4$s;color:%3$s;'
            . 'font-size:%5$dpx;font-weight:700;font-family:inherit;'
            . 'letter-spacing:0.04em;user-select:none;" '
            . 'aria-label="%6$s" title="%6$s">'
            . '%6$s'
            . '</span>',
            $size,
            $border_px,
            esc_attr( $border_color ),
            esc_attr( $bg ),
            $font_size,
            esc_html( $initials )
        );

        return wp_json_encode( $html );
    }

    /**
     * Convert hex color sang RGB tuple string "R,G,B" để dùng trong rgba().
     *
     * @param string $hex
     * @return string
     */
    // =========================================================================
    // Fallback avatar script — chạy qua wp_footer, không cần dashboard_context
    // =========================================================================

    /**
     * Inject script thay icon user bằng avatar — chạy qua wp_footer priority 99.
     * Không phụ thuộc stock_dashboard_theme_is_dashboard_context(), đảm bảo
     * luôn chạy trên mọi trang có .sd-topbar hoặc .sd-bottom-bar.
     */
    /**
     * Render bell button HTML trực tiếp vào topbar qua PHP hook.
     * Đây là cách chuẩn nhất — không cần JS injection.
     */
    public function render_bell_html(): void {
        if ( ! is_user_logged_in() ) return;
        if ( ! class_exists( 'LCNI_InboxDB' ) ) return;
        ?>
        <div id="lcni-bell-wrap" style="position:relative;display:inline-flex;align-items:center;margin-right:4px;">
            <button id="lcni-bell-btn"
                    title="Thông báo"
                    aria-label="Thông báo"
                    style="position:relative;display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;padding:0;border:none;border-radius:50%;background:transparent;cursor:pointer;color:inherit;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
                    <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
                </svg>
                <span id="lcni-bell-badge"
                      style="display:none;position:absolute;top:0;right:0;min-width:16px;height:16px;padding:0 3px;border-radius:8px;background:#ef4444;color:#fff;font-size:9px;font-weight:700;line-height:16px;text-align:center;box-shadow:0 0 0 2px rgba(0,0,0,.25);pointer-events:none;box-sizing:border-box;">0</span>
            </button>
            <div id="lcni-bell-dropdown"
                 style="display:none;position:absolute;top:calc(100% + 6px);right:0;z-index:999999;width:320px;max-width:calc(100vw - 16px);background:#fff;border:1px solid #e5e7eb;border-radius:10px;box-shadow:0 8px 32px rgba(0,0,0,.18);overflow:hidden;"></div>
        </div>
        <?php
    }

    public function inject_bell_script(): void {
        if ( ! is_user_logged_in() ) return;
        if ( ! class_exists( 'LCNI_InboxDB' ) ) return;

        $rest_base = esc_url( rest_url( 'lcni/v1/inbox' ) );
        $nonce     = wp_create_nonce( 'wp_rest' );
        $inbox_url = esc_url( LCNI_InboxDB::get_admin_config()['inbox_page_url'] ?? home_url('/') );
        ?>
        <script id="lcni-bell-inject">
        (function(){
            var CFG = {
                restBase: <?php echo wp_json_encode( $rest_base ); ?>,
                nonce:    <?php echo wp_json_encode( $nonce ); ?>,
                inboxUrl: <?php echo wp_json_encode( $inbox_url ); ?>,
                poll:     60000
            };

            // ── Tạo bell element ─────────────────────────────────────────────
            function createBell() {
                var w = document.createElement('div');
                w.id = 'lcni-bell-wrap';
                w.style.cssText = 'position:relative;display:inline-flex;align-items:center;margin-right:6px;';
                w.innerHTML =
                    '<button id="lcni-bell-btn" style="position:relative;display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;padding:0;border:none;border-radius:50%;background:transparent;cursor:pointer;color:inherit;">' +
                        '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
                            '<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>' +
                            '<path d="M13.73 21a2 2 0 0 1-3.46 0"/>' +
                        '</svg>' +
                        '<span id="lcni-bell-badge" style="display:none;position:absolute;top:1px;right:1px;min-width:18px;height:18px;padding:0 4px;border-radius:999px;background:#ef4444;color:#fff;font-size:10px;font-weight:700;line-height:18px;text-align:center;box-shadow:0 0 0 2px #fff;pointer-events:none;box-sizing:border-box;">0</span>' +
                    '</button>' +
                    '<div id="lcni-bell-dropdown" style="display:none;position:absolute;top:calc(100% + 8px);right:0;z-index:99999;width:340px;max-width:calc(100vw - 16px);background:#fff;border:1px solid #e5e7eb;border-radius:12px;box-shadow:0 12px 40px rgba(0,0,0,.16);overflow:hidden;"></div>';
                return w;
            }

            // ── Inject vào topbar ────────────────────────────────────────────
            // Chiến lược: tìm chính xác toggle button chứa lcni-avatar rồi inject bell trước nó
            // Xác định vùng topbar để không inject nhầm vào content
            function getTopbarScope() {
                var scopes = [
                    document.querySelector('.sd-topbar'),
                    document.querySelector('[class*="topbar"]:not([class*="content"])'),
                    document.querySelector('header'),
                    document.querySelector('#masthead'),
                    document.querySelector('#site-header'),
                ];
                for (var i = 0; i < scopes.length; i++) {
                    if (scopes[i]) return scopes[i];
                }
                return null;
            }

            function findToggle() {
                var scope = getTopbarScope();
                if (!scope) return null;

                // Ưu tiên: .sd-user-dropdown__toggle trong topbar scope
                var toggle = scope.querySelector('.sd-user-dropdown__toggle');
                if (toggle) return toggle;

                // Tìm lcni-avatar CHỈ trong topbar scope
                var avatar = scope.querySelector('.lcni-avatar, .lcni-user-avatar');
                if (avatar) {
                    var el = avatar.parentElement;
                    while (el && el !== scope) {
                        if (el.tagName === 'BUTTON' || el.tagName === 'A' ||
                            (el.className && (String(el.className).indexOf('toggle') >= 0 ||
                             String(el.className).indexOf('dropdown') >= 0))) {
                            return el;
                        }
                        el = el.parentElement;
                    }
                    return avatar.parentElement;
                }

                // Fallback: greeting
                var greeting = scope.querySelector('.lcni-topbar-greeting');
                if (greeting) return greeting;

                return null;
            }

            function injectBell() {
                if (document.getElementById('lcni-bell-btn')) return;

                var toggle = findToggle();
                if (!toggle) {
                    console.log('[LCNI Bell] toggle not found, retrying...');
                    return;
                }

                var bell = createBell();
                var parent = toggle.parentElement;
                if (!parent) return;

                parent.insertBefore(bell, toggle);
                bindBell();
                console.log('[LCNI Bell] injected before:', toggle.tagName, toggle.className.substring(0, 60));
            }

            // ── Badge ────────────────────────────────────────────────────────
            var _unread = 0;
            function setBadge(n) {
                _unread = Math.max(0, n);
                var b = document.getElementById('lcni-bell-badge');
                if (!b) return;
                b.style.display = _unread ? 'block' : 'none';
                b.textContent   = _unread > 99 ? '99+' : String(_unread);
            }

            function fetchCount() {
                fetch(CFG.restBase + '/count', {
                    credentials: 'same-origin',
                    headers: { 'X-WP-Nonce': CFG.nonce }
                }).then(function(r){ return r.json(); }).then(function(d){
                    setBadge(d.unread_count || 0);
                }).catch(function(){});
            }

            // ── Dropdown ─────────────────────────────────────────────────────
            var _open = false;

            function renderItem(item) {
                var url = CFG.inboxUrl + (CFG.inboxUrl.indexOf('?') >= 0 ? '&' : '?') + 'notif_id=' + item.id;
                var bg  = item.is_read ? '#fff' : '#eff6ff';
                var fw  = item.is_read ? '500' : '700';
                return '<a href="' + url + '" data-id="' + item.id + '"'
                    + ' style="display:flex;align-items:flex-start;gap:10px;padding:11px 16px;text-decoration:none;color:inherit;border-bottom:1px solid #f9fafb;background:' + bg + ';">'
                    + '<div style="flex:1;min-width:0;">'
                        + '<div style="font-size:13px;font-weight:' + fw + ';color:#111827;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + escH(item.title) + '</div>'
                        + '<div style="font-size:11px;color:#9ca3af;margin-top:2px;">' + escH(item.time_ago) + '</div>'
                    + '</div>'
                    + (item.is_read ? '' : '<div style="width:8px;height:8px;border-radius:50%;background:#3b82f6;flex-shrink:0;margin-top:4px;"></div>')
                    + '</a>';
            }

            function openDropdown() {
                var drop = document.getElementById('lcni-bell-dropdown');
                if (!drop) return;
                _open = true;
                drop.style.display = 'block';
                drop.innerHTML = '<div style="display:flex;align-items:center;justify-content:space-between;padding:13px 16px 11px;border-bottom:1px solid #f3f4f6;">'
                    + '<span style="font-weight:700;font-size:14px;">🔔 Thông báo</span>'
                    + '<button id="lcni-drop-mark-all" style="border:none;background:none;color:#3b82f6;font-size:12px;font-weight:600;cursor:pointer;">✓ Đọc hết</button>'
                    + '</div>'
                    + '<div id="lcni-drop-list" style="max-height:360px;overflow-y:auto;"><div style="text-align:center;padding:20px;color:#9ca3af;">Đang tải...</div></div>'
                    + '<div style="padding:10px 16px;border-top:1px solid #f3f4f6;text-align:center;"><a href="' + escH(CFG.inboxUrl) + '" style="font-size:13px;color:#3b82f6;font-weight:600;text-decoration:none;">Xem tất cả →</a></div>';

                fetch(CFG.restBase + '?per_page=8', {
                    credentials: 'same-origin',
                    headers: { 'X-WP-Nonce': CFG.nonce }
                }).then(function(r){ return r.json(); }).then(function(d){
                    var list = document.getElementById('lcni-drop-list');
                    if (!list) return;
                    var items = d.items || [];
                    setBadge(d.unread_count || 0);
                    list.innerHTML = items.length
                        ? items.map(renderItem).join('')
                        : '<div style="text-align:center;padding:24px;color:#9ca3af;">Không có thông báo.</div>';

                    list.addEventListener('click', function(e) {
                        var a = e.target.closest('[data-id]');
                        if (!a) return;
                        fetch(CFG.restBase + '/mark-read', { method:'POST', credentials:'same-origin',
                            headers:{'X-WP-Nonce':CFG.nonce,'Content-Type':'application/json'},
                            body: JSON.stringify({ ids: [parseInt(a.dataset.id,10)] })
                        }).then(function(r){ return r.json(); }).then(function(r){ setBadge(r.unread_count||0); });
                    });
                });

                var markAll = document.getElementById('lcni-drop-mark-all');
                if (markAll) markAll.addEventListener('click', function(){
                    fetch(CFG.restBase + '/mark-read', { method:'POST', credentials:'same-origin',
                        headers:{'X-WP-Nonce':CFG.nonce,'Content-Type':'application/json'},
                        body: JSON.stringify({ ids: 'all' })
                    }).then(function(r){ return r.json(); }).then(function(r){ setBadge(0); });
                });
            }

            function closeDropdown() {
                var drop = document.getElementById('lcni-bell-dropdown');
                if (drop) drop.style.display = 'none';
                _open = false;
            }

            function bindBell() {
                var btn = document.getElementById('lcni-bell-btn');
                if (!btn || btn._lcniBound) return;
                btn._lcniBound = true;
                btn.addEventListener('click', function(e){
                    e.stopPropagation();
                    _open ? closeDropdown() : openDropdown();
                });
                document.addEventListener('click', function(e){
                    var w = document.getElementById('lcni-bell-wrap');
                    if (w && !w.contains(e.target)) closeDropdown();
                });
                document.addEventListener('keydown', function(e){
                    if (e.key === 'Escape') closeDropdown();
                });
            }

            function escH(s) {
                return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
            }

            // ── Polling ──────────────────────────────────────────────────────
            function startPoll() {
                fetchCount();
                var t = setInterval(fetchCount, CFG.poll);
                document.addEventListener('visibilitychange', function(){
                    if (document.hidden) { clearInterval(t); }
                    else { fetchCount(); t = setInterval(fetchCount, CFG.poll); }
                });
            }

            // ── Boot ─────────────────────────────────────────────────────────
            function boot() {
                // Debug: log topbar structure để diagnose
                setTimeout(function() {
                    var topbars = document.querySelectorAll('[class*="topbar"],[class*="header"] nav,[class*="header"] .right');
                    if (topbars.length) {
                        console.log('[LCNI Bell] topbar candidates:', Array.prototype.map.call(topbars, function(el){ return el.className; }));
                    }
                    var anchor = findAnchorEl();
                    console.log('[LCNI Bell] anchor found:', anchor ? anchor.className : 'NULL');
                }, 200);

                injectBell();
                setTimeout(injectBell, 300);
                setTimeout(injectBell, 800);
                setTimeout(injectBell, 2000);
                startPoll();
                // MutationObserver: catch topbar nếu theme render sau DOMContentLoaded
                if (window.MutationObserver) {
                    var obs = new MutationObserver(function() {
                        if (!document.getElementById('lcni-bell-btn')) {
                            injectBell();
                        } else {
                            obs.disconnect();
                        }
                    });
                    obs.observe(document.body, { childList: true, subtree: true });
                    setTimeout(function(){ obs.disconnect(); }, 5000);
                }
            }

            if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
            else boot();
        })();
        </script>
        <?php
    }

    public function inject_avatar_fallback_script(): void {
        if ( ! is_user_logged_in() ) {
            return;
        }

        $pkg          = $this->get_current_user_badge_data();
        $avatar_html  = $this->build_toggle_avatar_js( $pkg['color'] );

        // Không có avatar → không cần inject
        if ( $avatar_html === 'null' ) {
            return;
        }
        ?>
        <script id="lcni-avatar-fallback-js">
        (function() {
            var avatarHtml = <?php echo $avatar_html; ?>;
            if (!avatarHtml || avatarHtml === 'null') return;

            function doInject() {
                // Kiểm tra trang có topbar/bottom-bar không — nếu không thì bỏ qua
                if (!document.querySelector('.sd-topbar, .sd-bottom-bar')) return;

                // Nhắm đúng toggle trong topbar và bottom-bar (không phải trong menu)
                var toggles = document.querySelectorAll(
                    '.sd-topbar .sd-user-dropdown__toggle, ' +
                    '.sd-bottom-bar .sd-user-dropdown__toggle'
                );

                // Fallback rộng hơn nếu không tìm thấy
                if (!toggles.length) {
                    var all = document.querySelectorAll('.sd-user-dropdown__toggle');
                    var arr = [];
                    all.forEach(function(el) {
                        if (!el.closest('.sd-user-dropdown__menu')) arr.push(el);
                    });
                    toggles = arr;
                }

                toggles.forEach(function(el) {
                    // Bottom-bar: PHP lcni_get_user_avatar() đã render .lcni-avatar → skip
                    if (el.closest('.sd-bottom-bar')) return;
                    // Đã inject rồi → bỏ qua
                    if (el.querySelector('.lcni-user-avatar, .lcni-avatar')) return;

                    // Tìm dashicons-admin-users là con trực tiếp
                    var icon = null;
                    Array.prototype.forEach.call(el.children, function(child) {
                        if (!icon && child.classList && child.classList.contains('dashicons-admin-users')) {
                            icon = child;
                        }
                    });

                    var tmp = document.createElement('span');
                    tmp.innerHTML = avatarHtml;
                    var avatarEl = tmp.firstElementChild;
                    if (!avatarEl) return;

                    if (icon) {
                        el.replaceChild(avatarEl, icon);
                    } else {
                        // Không có dashicons — thay img nếu có, hoặc prepend
                        var existingImg = null;
                        Array.prototype.forEach.call(el.children, function(child) {
                            if (!existingImg && child.tagName === 'IMG') existingImg = child;
                        });
                        if (existingImg) {
                            el.replaceChild(avatarEl, existingImg);
                        } else {
                            el.insertBefore(avatarEl, el.firstChild);
                        }
                    }
                });
            }

            // ── Inject bell ngay trước toggle (cùng selector với avatar) ──
            // Bell đã được render bởi PHP (render_bell_html) — chỉ cần bind events
            function bindBellIfRendered() {
                var btn = document.getElementById('lcni-bell-btn');
                if (!btn || btn._bellBound) return;
                btn._bellBound = true;

                var RBASE  = <?php echo wp_json_encode( esc_url( rest_url( 'lcni/v1/inbox' ) ) ); ?>;
                var RNONCE = <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>;
                var IURL   = <?php echo wp_json_encode( class_exists('LCNI_InboxDB') ? esc_url( LCNI_InboxDB::get_admin_config()['inbox_page_url'] ?? home_url('/') ) : esc_url( home_url('/') ) ); ?>;
                var _open  = false;

                function setBadge(n) {
                    var b = document.getElementById('lcni-bell-badge');
                    if (!b) return;
                    b.style.display = n > 0 ? 'block' : 'none';
                    b.textContent = n > 99 ? '99+' : String(n);
                }
                function fetchCount() {
                    fetch(RBASE + '/count', {credentials:'same-origin', headers:{'X-WP-Nonce':RNONCE}})
                        .then(function(r){return r.json();}).then(function(d){setBadge(d.unread_count||0);}).catch(function(){});
                }
                function escH(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

                function renderDrop(items, unread) {
                    setBadge(unread);
                    var drop = document.getElementById('lcni-bell-dropdown');
                    if (!drop) return;
                    var html = '<div style="display:flex;align-items:center;justify-content:space-between;padding:12px 14px 10px;border-bottom:1px solid #f3f4f6;">'
                        + '<span style="font-weight:700;font-size:13px;">🔔 Thông báo</span>'
                        + '<button id="lcni-mark-all" style="border:none;background:none;color:#3b82f6;font-size:11px;font-weight:600;cursor:pointer;padding:2px 6px;">✓ Đọc hết</button>'
                        + '</div><div style="max-height:320px;overflow-y:auto;">';
                    if (!items.length) {
                        html += '<div style="text-align:center;padding:20px;color:#9ca3af;font-size:13px;">Không có thông báo.</div>';
                    } else {
                        items.forEach(function(item) {
                            var u = IURL + (IURL.indexOf('?')>=0?'&':'?') + 'notif_id=' + item.id;
                            html += '<a href="' + escH(u) + '" data-id="' + item.id + '" style="display:block;padding:10px 14px;text-decoration:none;color:inherit;border-bottom:1px solid #f9fafb;background:' + (item.is_read?'#fff':'#eff6ff') + ';">'
                                + '<div style="font-size:12px;font-weight:' + (item.is_read?'500':'700') + ';color:#111827;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + escH(item.title) + '</div>'
                                + '<div style="font-size:10px;color:#9ca3af;margin-top:2px;">' + escH(item.time_ago) + '</div>'
                                + '</a>';
                        });
                    }
                    html += '</div><div style="padding:8px 14px;border-top:1px solid #f3f4f6;text-align:center;"><a href="' + escH(IURL) + '" style="font-size:12px;color:#3b82f6;font-weight:600;text-decoration:none;">Xem tất cả →</a></div>';
                    drop.innerHTML = html;
                    var ma = document.getElementById('lcni-mark-all');
                    if (ma) ma.addEventListener('click', function(){
                        fetch(RBASE+'/mark-read',{method:'POST',credentials:'same-origin',headers:{'X-WP-Nonce':RNONCE,'Content-Type':'application/json'},body:JSON.stringify({ids:'all'})})
                            .then(function(r){return r.json();}).then(function(){setBadge(0);});
                    });
                    drop.addEventListener('click', function(e){
                        var a = e.target.closest('[data-id]');
                        if (!a) return;
                        fetch(RBASE+'/mark-read',{method:'POST',credentials:'same-origin',headers:{'X-WP-Nonce':RNONCE,'Content-Type':'application/json'},body:JSON.stringify({ids:[parseInt(a.dataset.id,10)]})})
                            .then(function(r){return r.json();}).then(function(d){setBadge(d.unread_count||0);});
                    });
                }

                btn.addEventListener('click', function(e){
                    e.stopPropagation();
                    var drop = document.getElementById('lcni-bell-dropdown');
                    if (!drop) return;
                    _open = !_open;
                    if (_open) {
                        drop.style.display = 'block';
                        drop.innerHTML = '<div style="text-align:center;padding:16px;color:#9ca3af;font-size:12px;">Đang tải...</div>';
                        fetch(RBASE+'?per_page=8',{credentials:'same-origin',headers:{'X-WP-Nonce':RNONCE}})
                            .then(function(r){return r.json();})
                            .then(function(d){renderDrop(d.items||[],d.unread_count||0);});
                    } else { drop.style.display = 'none'; }
                });
                document.addEventListener('click', function(e){
                    var w = document.getElementById('lcni-bell-wrap');
                    if (_open && w && !w.contains(e.target)){ _open=false; var d=document.getElementById('lcni-bell-dropdown'); if(d) d.style.display='none'; }
                });
                document.addEventListener('keydown', function(e){ if(e.key==='Escape'&&_open){ _open=false; var d=document.getElementById('lcni-bell-dropdown'); if(d) d.style.display='none'; } });

                fetchCount();
                setInterval(fetchCount, 60000);
                document.addEventListener('visibilitychange', function(){ if(!document.hidden) fetchCount(); });
            }
            // Chạy ngay (wp_footer = DOM đã sẵn sàng) + retry 1 lần sau 500ms
            // đề phòng theme render toggle sau DOMContentLoaded
            doInject();
            setTimeout(doInject, 500);
            bindBellIfRendered();
            setTimeout(bindBellIfRendered, 300);
        })();
        </script>
        <?php
    }

        private function hex_to_rgb( $hex ) {
        $hex = ltrim( $hex, '#' );
        if ( strlen( $hex ) === 3 ) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if ( strlen( $hex ) !== 6 ) {
            return '37,99,235';
        }
        $r = hexdec( substr( $hex, 0, 2 ) );
        $g = hexdec( substr( $hex, 2, 2 ) );
        $b = hexdec( substr( $hex, 4, 2 ) );
        return "{$r},{$g},{$b}";
    }
}
