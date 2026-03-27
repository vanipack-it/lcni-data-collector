<?php
/**
 * LCNI Screenshot Module
 *
 * Tính năng chụp màn hình chia sẻ:
 * - Nút float chỉ xuất hiện frontend với user có quyền can_screenshot
 * - Capture vùng chọn bằng html2canvas
 * - Editor ảnh: crop, text, emoji, vẽ tay (kiểu Zalo)
 * - Tự động chèn watermark logo + text với hiệu ứng adaptive opacity
 * - Admin tuỳ chỉnh: vị trí, kích thước logo, text watermark
 *
 * Enqueue: chỉ frontend, chỉ khi user có quyền
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LCNI_Screenshot_Module {

    const OPTION_KEY    = 'lcni_screenshot_settings';
    const ADMIN_PAGE    = 'lcni-member-settings';
    const ADMIN_TAB     = 'screenshot';

    private static bool $booted = false;

    public static function boot(): void {
        if ( self::$booted ) return;
        self::$booted = true;

        add_action( 'wp_enqueue_scripts',  [ __CLASS__, 'maybe_enqueue' ], 25 );
        add_action( 'wp_footer',           [ __CLASS__, 'maybe_inject_root' ], 100 );

        // Admin
        add_action( 'admin_menu',          [ __CLASS__, 'register_admin_tab' ], 20 );
        add_action( 'admin_init',          [ __CLASS__, 'handle_admin_save' ] );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'admin_enqueue' ] );

        // REST: trả về config watermark cho JS
        add_action( 'rest_api_init',       [ __CLASS__, 'register_rest' ] );
    }

    // =========================================================================
    // PERMISSION CHECK
    // =========================================================================

    /**
     * Kiểm tra user hiện tại có quyền screenshot không.
     * Ưu tiên: admin luôn có quyền. Sau đó check meta user.
     * Nếu dùng SaaS package → check permission 'can_screenshot' trong package.
     */
    public static function current_user_can(): bool {
        if ( ! is_user_logged_in() ) return false;

        // Mặc định: tất cả user đã đăng nhập đều có quyền chụp màn hình
        return true;

        // phpcs:ignore -- code dưới giữ lại để dùng sau nếu cần phân quyền
        if ( current_user_can( 'manage_options' ) ) return true;
        $user_id = get_current_user_id();
        $meta = get_user_meta( $user_id, 'lcni_can_screenshot', true );
        if ( $meta === '1' || $meta === 1 || $meta === true ) return true;

        // SaaS package permission
        if ( class_exists( 'SaasService' ) ) {
            global $lcni_saas_service;
            if ( $lcni_saas_service instanceof SaasService ) {
                $pkg = $lcni_saas_service->get_current_user_package_info();
                if ( $pkg && ! empty( $pkg['permissions'] ) ) {
                    foreach ( (array) $pkg['permissions'] as $perm ) {
                        if (
                            ( $perm['module_key'] ?? '' ) === 'screenshot'
                            && ! empty( $perm['can_view'] )
                        ) {
                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }

    // =========================================================================
    // FRONTEND ENQUEUE
    // =========================================================================

    public static function maybe_enqueue(): void {
        if ( is_admin() ) return;
        if ( ! self::current_user_can() ) return;

        $base_path = defined( 'LCNI_PATH' ) ? LCNI_PATH : trailingslashit( dirname( dirname( dirname( __FILE__ ) ) ) );
        $base_url  = defined( 'LCNI_URL'  ) ? LCNI_URL  : trailingslashit( plugin_dir_url( dirname( dirname( dirname( __FILE__ ) ) ) ) );

        $js_file  = $base_path . 'assets/js/lcni-screenshot.js';
        $css_file = $base_path . 'assets/css/lcni-screenshot.css';
        $ver_js   = file_exists( $js_file )  ? (string) filemtime( $js_file )  : '1.0.0';
        $ver_css  = file_exists( $css_file ) ? (string) filemtime( $css_file ) : '1.0.0';

        // html2canvas cho region crop, dom-to-image-more cho full page
        wp_enqueue_script(
            'html2canvas',
            'https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js',
            [],
            '1.4.1',
            true
        );

        wp_enqueue_script(
            'dom-to-image-more',
            'https://cdnjs.cloudflare.com/ajax/libs/dom-to-image-more/3.4.0/dom-to-image-more.min.js',
            [], '3.4.0', true
        );
        wp_enqueue_style(  'lcni-screenshot', $base_url . 'assets/css/lcni-screenshot.css', [], $ver_css );
        wp_enqueue_script( 'lcni-screenshot', $base_url . 'assets/js/lcni-screenshot.js',  [ 'html2canvas', 'dom-to-image-more' ], $ver_js, true );

        $settings = self::get_settings();

        wp_localize_script( 'lcni-screenshot', 'lcniScreenshotCfg', [
            'restBase'        => esc_url( rest_url( 'lcni/v1/screenshot' ) ),
            'nonce'           => wp_create_nonce( 'wp_rest' ),
            'siteLogoUrl'     => self::get_logo_url(),
            'siteName'        => get_bloginfo( 'name' ),
            'watermarkPos'    => $settings['watermark_pos']    ?? 'bottom-right',
            'watermarkScale'  => (float) ( $settings['watermark_scale']  ?? 0.18 ),
            'watermarkOpacity'=> (float) ( $settings['watermark_opacity'] ?? 0.85 ),
            'watermarkText'   => $settings['watermark_text']   ?? '',
            'watermarkTextPos'=> $settings['watermark_text_pos'] ?? 'below-logo',
            'watermarkTextSize'=> (int) ( $settings['watermark_text_size'] ?? 14 ),
            'btnPosition'     => $settings['btn_position']     ?? 'bottom-right',
            // Frame defaults
            'frameBgColor'    => $settings['frame_bg_color']   ?? '#1a1a2e',
            'frameThin'       => (int) ( $settings['frame_thin']   ?? 12 ),
            'frameSize'       => (int) ( $settings['frame_size']   ?? 64 ),
            'frameRadius'     => (int) ( $settings['frame_radius'] ?? 12 ),
            'frameShowLogo'   => ! empty( $settings['frame_show_logo'] ),
            'frameShowText'   => ! empty( $settings['frame_show_text'] ),
            'frameTextColor'  => $settings['frame_text_color'] ?? '#ffffff',
            'frameTextSize'   => (int) ( $settings['frame_text_size'] ?? 28 ),
        ] );
    }

    public static function maybe_inject_root(): void {
        if ( is_admin() ) return;
        if ( ! self::current_user_can() ) return;
        echo '<div id="lcni-screenshot-root"></div>' . "\n";
    }

    // =========================================================================
    // LOGO URL
    // =========================================================================

    public static function get_logo_url(): string {
        // Custom logo từ settings nếu có
        $settings = self::get_settings();
        if ( ! empty( $settings['custom_logo_url'] ) ) {
            return esc_url( $settings['custom_logo_url'] );
        }
        // WordPress custom logo
        $logo_id = (int) get_theme_mod( 'custom_logo' );
        if ( $logo_id > 0 ) {
            $src = wp_get_attachment_image_src( $logo_id, 'full' );
            if ( $src ) return esc_url( $src[0] );
        }
        // Fallback: site icon
        $icon_id = (int) get_option( 'site_icon' );
        if ( $icon_id > 0 ) {
            $src = wp_get_attachment_image_src( $icon_id, 'thumbnail' );
            if ( $src ) return esc_url( $src[0] );
        }
        return '';
    }

    // =========================================================================
    // SETTINGS
    // =========================================================================

    public static function get_settings(): array {
        $saved = get_option( self::OPTION_KEY, [] );
        if ( ! is_array( $saved ) ) $saved = [];

        return array_merge( [
            'watermark_pos'       => 'bottom-right',
            'watermark_scale'     => 0.18,
            'watermark_opacity'   => 0.85,
            'watermark_text'      => '',
            'watermark_text_pos'  => 'below-logo',
            'watermark_text_size' => 14,
            'watermark_text_color'=> '',    // rỗng = auto (adaptive)
            'custom_logo_url'     => '',
            'btn_position'        => 'bottom-right',
            'allow_all_users'     => true,   // mặc định cho phép tất cả user đã đăng nhập
            'allowed_packages'    => [],    // package IDs được phép (rỗng = chỉ dùng user_meta)
            // Frame border defaults
            'frame_bg_color'      => '#1a1a2e',
            'frame_thin'          => 12,    // viền mỏng T/L/R
            'frame_size'          => 64,    // bar dưới
            'frame_radius'        => 12,
            'frame_show_logo'     => true,
            'frame_show_text'     => true,
            'frame_text_color'    => '#ffffff',
            'frame_text_size'     => 28,
        ], $saved );
    }

    // =========================================================================
    // REST API
    // =========================================================================

    public static function register_rest(): void {
        register_rest_route( 'lcni/v1', '/screenshot/config', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ __CLASS__, 'rest_get_config' ],
            'permission_callback' => [ __CLASS__, 'rest_permission' ],
        ] );
    }

    public static function rest_permission(): bool {
        return self::current_user_can();
    }

    public static function rest_get_config( WP_REST_Request $req ): WP_REST_Response {
        $settings = self::get_settings();
        return new WP_REST_Response( [
            'logo_url'         => self::get_logo_url(),
            'watermark_pos'    => $settings['watermark_pos'],
            'watermark_scale'  => (float) $settings['watermark_scale'],
            'watermark_opacity'=> (float) $settings['watermark_opacity'],
            'watermark_text'   => $settings['watermark_text'],
            'watermark_text_pos'=> $settings['watermark_text_pos'],
            'watermark_text_size'=> (int) $settings['watermark_text_size'],
            'watermark_text_color'=> $settings['watermark_text_color'],
        ], 200 );
    }

    // =========================================================================
    // ADMIN
    // =========================================================================

    public static function register_admin_tab(): void {
        // Tab được render trong MemberSettingsPage nếu có hook,
        // hoặc tự render như submenu riêng
        add_submenu_page(
            'lcni-settings',
            'Chụp màn hình',
            'Chụp màn hình',
            'manage_options',
            'lcni-screenshot-settings',
            [ __CLASS__, 'render_admin_page' ]
        );
    }

    public static function admin_enqueue( string $hook ): void {
        if ( strpos( $hook, 'lcni-screenshot-settings' ) === false ) return;
        wp_enqueue_media(); // Cho media uploader chọn logo
    }

    public static function handle_admin_save(): void {
        if ( ! isset( $_POST['lcni_screenshot_save'] ) ) return;
        if ( ! current_user_can( 'manage_options' ) ) return;
        if ( ! check_admin_referer( 'lcni_screenshot_settings' ) ) return;

        $s = self::get_settings();

        $s['watermark_pos']        = sanitize_key( $_POST['watermark_pos'] ?? 'bottom-right' );
        $s['watermark_scale']      = max( 0.05, min( 0.5, (float) ( $_POST['watermark_scale'] ?? 0.18 ) ) );
        $s['watermark_opacity']    = max( 0.1, min( 1.0, (float) ( $_POST['watermark_opacity'] ?? 0.85 ) ) );
        $s['watermark_text']       = sanitize_text_field( wp_unslash( $_POST['watermark_text'] ?? '' ) );
        $s['watermark_text_pos']   = sanitize_key( $_POST['watermark_text_pos'] ?? 'below-logo' );
        $s['watermark_text_size']  = max( 8, min( 48, (int) ( $_POST['watermark_text_size'] ?? 14 ) ) );
        $s['watermark_text_color'] = sanitize_hex_color( $_POST['watermark_text_color'] ?? '' ) ?: '';
        $s['custom_logo_url']      = esc_url_raw( wp_unslash( $_POST['custom_logo_url'] ?? '' ) );
        $s['btn_position']         = sanitize_key( $_POST['btn_position'] ?? 'bottom-right' );
        $s['allow_all_users']      = ! empty( $_POST['allow_all_users'] );

        // Frame border
        $s['frame_bg_color']   = sanitize_hex_color( $_POST['frame_bg_color'] ?? '#1a1a2e' ) ?: '#1a1a2e';
        $s['frame_thin']       = max( 2,  min( 60,  (int) ( $_POST['frame_thin']  ?? 12 ) ) );
        $s['frame_size']       = max( 20, min( 220, (int) ( $_POST['frame_size']  ?? 64 ) ) );
        $s['frame_radius']     = max( 0,  min( 80,  (int) ( $_POST['frame_radius'] ?? 12 ) ) );
        $s['frame_show_logo']  = ! empty( $_POST['frame_show_logo'] );
        $s['frame_show_text']  = ! empty( $_POST['frame_show_text'] );
        $s['frame_text_color'] = sanitize_hex_color( $_POST['frame_text_color'] ?? '#ffffff' ) ?: '#ffffff';
        $s['frame_text_size']  = max( 8, min( 80, (int) ( $_POST['frame_text_size'] ?? 28 ) ) );

        update_option( self::OPTION_KEY, $s );

        wp_safe_redirect( add_query_arg( 'lcni_saved', '1', admin_url( 'admin.php?page=lcni-screenshot-settings' ) ) );
        exit;
    }

    public static function render_admin_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) return;
        $s = self::get_settings();

        $pos_options = [
            'top-left'      => 'Trên trái',
            'top-right'     => 'Trên phải',
            'bottom-left'   => 'Dưới trái',
            'bottom-right'  => 'Dưới phải',
            'center'        => 'Giữa',
        ];
        $text_pos_options = [
            'above-logo'  => 'Trên logo',
            'below-logo'  => 'Dưới logo',
            'left-logo'   => 'Bên trái logo',
            'right-logo'  => 'Bên phải logo',
            'standalone'  => 'Độc lập (không gần logo)',
        ];
        $saved = isset( $_GET['lcni_saved'] );
        ?>
<div class="wrap lcni-screenshot-admin">
    <h1 style="display:flex;align-items:center;gap:10px;">
        <span style="font-size:22px;">📸</span> Cài đặt Chụp màn hình &amp; Chia sẻ
    </h1>
    <?php if ( $saved ): ?>
    <div class="notice notice-success is-dismissible"><p>✅ Đã lưu cài đặt.</p></div>
    <?php endif; ?>

    <form method="post" action="">
        <?php wp_nonce_field( 'lcni_screenshot_settings' ); ?>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;max-width:960px;margin-top:20px;">

            <!-- CỘT TRÁI: Watermark logo -->
            <div class="lcni-ss-card">
                <div class="lcni-ss-card-title">🖼️ Watermark Logo</div>

                <table class="form-table lcni-ss-table">
                    <tr>
                        <th>Logo tùy chỉnh</th>
                        <td>
                            <div style="display:flex;gap:8px;align-items:center;">
                                <input type="text" name="custom_logo_url" id="lcni-logo-url"
                                    value="<?php echo esc_attr( $s['custom_logo_url'] ); ?>"
                                    style="flex:1;min-width:0;" class="regular-text">
                                <button type="button" class="button" id="lcni-logo-pick">Chọn ảnh</button>
                            </div>
                            <?php if ( $s['custom_logo_url'] ): ?>
                            <img src="<?php echo esc_url( $s['custom_logo_url'] ); ?>"
                                style="max-height:40px;margin-top:6px;border-radius:4px;border:1px solid #e5e7eb;">
                            <?php endif; ?>
                            <p class="description">Để trống → dùng logo WordPress (Appearance → Customize → Logo).</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Vị trí watermark</th>
                        <td>
                            <select name="watermark_pos">
                                <?php foreach ( $pos_options as $k => $v ): ?>
                                <option value="<?php echo esc_attr($k); ?>" <?php selected($s['watermark_pos'], $k); ?>>
                                    <?php echo esc_html($v); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th>Kích thước logo</th>
                        <td>
                            <input type="range" name="watermark_scale" min="0.05" max="0.5" step="0.01"
                                value="<?php echo esc_attr( $s['watermark_scale'] ); ?>"
                                oninput="document.getElementById('wm-scale-val').textContent=Math.round(this.value*100)+'%'">
                            <span id="wm-scale-val"><?php echo round($s['watermark_scale']*100); ?>%</span>
                            <p class="description">Tỷ lệ so với chiều rộng ảnh (5%–50%).</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Opacity logo</th>
                        <td>
                            <input type="range" name="watermark_opacity" min="0.1" max="1" step="0.05"
                                value="<?php echo esc_attr( $s['watermark_opacity'] ); ?>"
                                oninput="document.getElementById('wm-op-val').textContent=Math.round(this.value*100)+'%'">
                            <span id="wm-op-val"><?php echo round($s['watermark_opacity']*100); ?>%</span>
                            <p class="description">Adaptive: JS tự tăng opacity khi nền ảnh trùng màu logo.</p>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- CỘT PHẢI: Watermark text -->
            <div class="lcni-ss-card">
                <div class="lcni-ss-card-title">✍️ Watermark Text</div>

                <table class="form-table lcni-ss-table">
                    <tr>
                        <th>Nội dung text</th>
                        <td>
                            <input type="text" name="watermark_text"
                                value="<?php echo esc_attr( $s['watermark_text'] ); ?>"
                                class="regular-text"
                                placeholder="VD: niinsight.com | Phân tích chứng khoán">
                            <p class="description">Hỗ trợ {site_name}, {date}, {url}. Để trống = không hiện text.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Vị trí text</th>
                        <td>
                            <select name="watermark_text_pos">
                                <?php foreach ( $text_pos_options as $k => $v ): ?>
                                <option value="<?php echo esc_attr($k); ?>" <?php selected($s['watermark_text_pos'], $k); ?>>
                                    <?php echo esc_html($v); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th>Cỡ chữ text (px)</th>
                        <td>
                            <input type="number" name="watermark_text_size" min="8" max="48"
                                value="<?php echo esc_attr( $s['watermark_text_size'] ); ?>"
                                style="width:80px;">
                        </td>
                    </tr>
                    <tr>
                        <th>Màu chữ</th>
                        <td>
                            <input type="color" name="watermark_text_color"
                                value="<?php echo esc_attr( $s['watermark_text_color'] ?: '#ffffff' ); ?>">
                            <label style="margin-left:8px;">
                                <input type="checkbox" id="wm-text-auto"
                                    <?php checked( $s['watermark_text_color'] === '' ); ?>
                                    onchange="document.getElementsByName('watermark_text_color')[0].disabled=this.checked;document.getElementsByName('watermark_text_color')[0].value=this.checked?'':'#ffffff'">
                                Auto (adaptive theo nền ảnh)
                            </label>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- HÀNG DƯỚI: Nút float + Phân quyền -->
            <div class="lcni-ss-card">
                <div class="lcni-ss-card-title">🎯 Nút chụp màn hình</div>
                <table class="form-table lcni-ss-table">
                    <tr>
                        <th>Vị trí nút float</th>
                        <td>
                            <select name="btn_position">
                                <?php foreach ( $pos_options as $k => $v ): ?>
                                <option value="<?php echo esc_attr($k); ?>" <?php selected($s['btn_position'], $k); ?>>
                                    <?php echo esc_html($v); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="lcni-ss-card">
                <div class="lcni-ss-card-title">🔐 Phân quyền user</div>
                <p style="font-size:13px;color:#6b7280;margin:0 0 12px;">
                    Bật quyền screenshot cho từng user thủ công qua
                    <strong>Users → Edit User → LCNI → Cho phép chụp màn hình</strong>.
                    Hoặc bật trong gói SaaS bằng cách thêm permission <code>screenshot</code>.
                </p>
                <?php
                // Hiện 5 user gần đây có quyền
                $users_with_cap = get_users( [
                    'meta_key'   => 'lcni_can_screenshot',
                    'meta_value' => '1',
                    'number'     => 10,
                ] );
                if ( $users_with_cap ):
                ?>
                <div style="font-size:12px;color:#374151;">
                    <strong>Users đang có quyền (meta):</strong>
                    <?php foreach ($users_with_cap as $u): ?>
                    <span style="display:inline-flex;align-items:center;gap:4px;background:#eff6ff;border:1px solid #bfdbfe;padding:2px 8px;border-radius:4px;margin:2px;">
                        <?php echo esc_html($u->display_name); ?> <small>(#<?php echo $u->ID; ?>)</small>
                    </span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

        </div><!-- end grid 2-col -->

        <!-- CARD FRAME: full width -->
        <div class="lcni-ss-card" style="max-width:960px;margin-top:0;">
            <div class="lcni-ss-card-title">🖼️ Viền ngoài ảnh (Frame Border)</div>
            <p style="font-size:13px;color:#6b7280;margin:0 0 14px;">
                Khi click nút <strong>🖼 Viền</strong> trong editor, panel sẽ mở với giá trị mặc định từ đây.
                Logo + text xuất hiện trong vùng viền phía dưới ảnh.
            </p>
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:20px;">

                <table class="form-table lcni-ss-table">
                    <tr>
                        <th>Màu nền viền</th>
                        <td><input type="color" name="frame_bg_color"
                            value="<?php echo esc_attr( $s['frame_bg_color'] ?? '#1a1a2e' ); ?>"></td>
                    </tr>
                    <tr>
                        <th>Viền T/L/R (px)</th>
                        <td>
                            <input type="range" name="frame_thin" min="2" max="60" step="2"
                                value="<?php echo esc_attr( (string) ( $s['frame_thin'] ?? 12 ) ); ?>"
                                oninput="document.getElementById('fr-thin-val').textContent=this.value+'px'">
                            <span id="fr-thin-val"><?php echo esc_html( (string) ( $s['frame_thin'] ?? 12 ) ); ?>px</span>
                        </td>
                    </tr>
                    <tr>
                        <th>Bar dưới (px)</th>
                        <td>
                            <input type="range" name="frame_size" min="20" max="220" step="4"
                                value="<?php echo esc_attr( (string) ( $s['frame_size'] ?? 64 ) ); ?>"
                                oninput="document.getElementById('fr-size-val').textContent=this.value+'px'">
                            <span id="fr-size-val"><?php echo esc_html( (string) ( $s['frame_size'] ?? 64 ) ); ?>px</span>
                        </td>
                    </tr>
                    <tr>
                        <th>Bo góc (px)</th>
                        <td>
                            <input type="range" name="frame_radius" min="0" max="80" step="2"
                                value="<?php echo esc_attr( (string) ( $s['frame_radius'] ?? 12 ) ); ?>"
                                oninput="document.getElementById('fr-radius-val').textContent=this.value+'px'">
                            <span id="fr-radius-val"><?php echo esc_html( (string) ( $s['frame_radius'] ?? 12 ) ); ?>px</span>
                        </td>
                    </tr>
                </table>

                <table class="form-table lcni-ss-table">
                    <tr>
                        <th>Hiển thị logo</th>
                        <td><label>
                            <input type="checkbox" name="frame_show_logo" value="1"
                                <?php checked( ! empty( $s['frame_show_logo'] ) ); ?>>
                            Chèn logo vào vùng viền dưới
                        </label></td>
                    </tr>
                    <tr>
                        <th>Hiển thị text</th>
                        <td><label>
                            <input type="checkbox" name="frame_show_text" value="1"
                                <?php checked( ! empty( $s['frame_show_text'] ) ); ?>>
                            Chèn text watermark vào viền
                        </label></td>
                    </tr>
                    <tr>
                        <th>Màu chữ viền</th>
                        <td><input type="color" name="frame_text_color"
                            value="<?php echo esc_attr( $s['frame_text_color'] ?? '#ffffff' ); ?>"></td>
                    </tr>
                    <tr>
                        <th>Cỡ chữ (px)</th>
                        <td>
                            <input type="range" name="frame_text_size" min="8" max="80" step="2"
                                value="<?php echo esc_attr( (string) ( $s['frame_text_size'] ?? 28 ) ); ?>"
                                oninput="document.getElementById('fr-tsize-val').textContent=this.value+'px'">
                            <span id="fr-tsize-val"><?php echo esc_html( (string) ( $s['frame_text_size'] ?? 28 ) ); ?>px</span>
                        </td>
                    </tr>
                </table>

                <div style="background:#f9fafb;border-radius:8px;padding:12px;font-size:12px;color:#6b7280;line-height:1.7;">
                    <strong style="color:#374151;display:block;margin-bottom:6px;">Cách dùng:</strong>
                    <ol style="margin:0;padding-left:16px;">
                        <li>Chụp màn hình → Editor mở ra</li>
                        <li>Click nút <strong>🖼</strong> trên toolbar</li>
                        <li>Điều chỉnh hoặc giữ mặc định</li>
                        <li>Click <strong>✅ Áp dụng viền</strong></li>
                        <li>Export ảnh với viền hoàn chỉnh</li>
                    </ol>
                    <div style="margin-top:10px;padding:8px;background:#eff6ff;border-radius:6px;border:1px solid #bfdbfe;">
                        <strong style="color:#1d4ed8;">Preview:</strong> Nền
                        <span style="display:inline-block;width:18px;height:18px;border-radius:3px;border:1px solid #ccc;vertical-align:middle;background:<?php echo esc_attr( $s['frame_bg_color'] ?? '#1a1a2e' ); ?>"></span>
                        · Dày <?php echo (int)($s['frame_size']??48); ?>px · Bo <?php echo (int)($s['frame_radius']??10); ?>px
                    </div>
                </div>

            </div>
        </div>

        <p class="submit" style="margin-top:20px;">
            <input type="submit" name="lcni_screenshot_save" class="button button-primary button-large"
                value="💾 Lưu cài đặt">
        </p>
    </form>
</div>

<style>
.lcni-screenshot-admin .lcni-ss-card {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    padding: 20px;
    box-shadow: 0 1px 3px rgba(0,0,0,.05);
}
.lcni-screenshot-admin .lcni-ss-card-title {
    font-size: 14px;
    font-weight: 700;
    color: #111827;
    margin-bottom: 14px;
    padding-bottom: 10px;
    border-bottom: 1px solid #f3f4f6;
}
.lcni-screenshot-admin .lcni-ss-table th {
    font-size: 13px;
    font-weight: 600;
    padding: 8px 10px 8px 0;
    width: 140px;
    vertical-align: top;
}
.lcni-screenshot-admin .lcni-ss-table td { padding: 6px 0; }
.lcni-screenshot-admin .lcni-ss-table .description { font-size: 11px; color: #6b7280; margin: 3px 0 0; }
</style>

<script>
jQuery(function($){
    // Media uploader cho logo
    $('#lcni-logo-pick').on('click', function(){
        var frame = wp.media({ title: 'Chọn Logo', button: { text: 'Dùng ảnh này' }, multiple: false });
        frame.on('select', function(){
            var att = frame.state().get('selection').first().toJSON();
            $('#lcni-logo-url').val(att.url);
        });
        frame.open();
    });
});
</script>
        <?php
    }
}

// Boot khi plugin loaded
add_action( 'plugins_loaded', [ 'LCNI_Screenshot_Module', 'boot' ], 20 );

// Hook vào User edit để thêm field "Cho phép chụp màn hình"
add_action( 'show_user_profile',   'lcni_screenshot_user_field' );
add_action( 'edit_user_profile',   'lcni_screenshot_user_field' );
add_action( 'personal_options_update',  'lcni_screenshot_user_field_save' );
add_action( 'edit_user_profile_update', 'lcni_screenshot_user_field_save' );

function lcni_screenshot_user_field( WP_User $user ): void {
    if ( ! current_user_can( 'manage_options' ) ) return;
    $val = get_user_meta( $user->ID, 'lcni_can_screenshot', true );
    ?>
    <h3>LCNI — Quyền chụp màn hình</h3>
    <table class="form-table">
        <tr>
            <th>Cho phép chụp màn hình</th>
            <td>
                <label>
                    <input type="checkbox" name="lcni_can_screenshot" value="1" <?php checked( $val, '1' ); ?>>
                    Hiện nút chụp màn hình chia sẻ cho user này
                </label>
            </td>
        </tr>
    </table>
    <?php
}

function lcni_screenshot_user_field_save( int $user_id ): void {
    if ( ! current_user_can( 'manage_options' ) ) return;
    update_user_meta( $user_id, 'lcni_can_screenshot', isset( $_POST['lcni_can_screenshot'] ) ? '1' : '0' );
}
