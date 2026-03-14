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
        </style>
        <script id="lcni-pkg-badge-js">
        (function() {
            var badgeInner = <?php echo $badge_inner_js; ?>;
            var isIcon     = <?php echo $has_icon ? 'true' : 'false'; ?>;
            var expired    = <?php echo $is_expired; ?>;

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

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', applyBadge);
            } else {
                applyBadge();
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
     * Convert hex color sang RGB tuple string "R,G,B" để dùng trong rgba().
     *
     * @param string $hex
     * @return string
     */
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
