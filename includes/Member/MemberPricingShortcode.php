<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Shortcode [lcni_pricing_table]
 *
 * Atts:
 *   title="..."              — Tiêu đề section (default: "Chọn gói phù hợp với bạn")
 *   subtitle="..."           — Mô tả nhỏ phía dưới title
 *   highlight="..."          — package_key của gói muốn nổi bật (mặc định gói đầu tiên)
 *   register_url="..."       — Override URL nút đăng ký (mặc định lấy từ settings)
 *   login_url="..."          — Override URL nút đăng nhập
 *   button_text="..."        — Label nút CTA (default: "Bắt đầu ngay")
 *   highlight_button_text="..."  — Label nút CTA của gói nổi bật (default: "Dùng thử miễn phí")
 *   show_login_link="yes|no" — Hiển thị link "Đã có tài khoản?" (default: yes)
 *   columns="2|3|4"          — Số cột (default: tự động theo số gói)
 */
class LCNI_Member_Pricing_Shortcode {

    private $service;

    public function __construct( LCNI_SaaS_Service $service ) {
        $this->service = $service;
        add_shortcode( 'lcni_pricing_table',  [ $this, 'render' ] );
        add_shortcode( 'lcni_upgrade_cta',    [ $this, 'render_upgrade_cta' ] );
    }

    public function render( $atts = [] ) {
        $atts = shortcode_atts( [
            'title'                  => 'Chọn gói phù hợp với bạn',
            'subtitle'               => 'Truy cập dữ liệu thị trường chứng khoán chuyên sâu',
            'highlight'              => '',
            'register_url'           => '',
            'login_url'              => '',
            'button_text'            => 'Bắt đầu ngay',
            'highlight_button_text'  => 'Dùng thử miễn phí',
            'show_login_link'        => 'yes',
            'columns'                => '',
        ], $atts, 'lcni_pricing_table' );

        $packages = $this->service->get_package_options();
        $packages = array_filter( $packages, fn($p) => ! empty( $p['is_active'] ) );
        $packages = array_values( $packages );

        if ( empty( $packages ) ) {
            return '<p style="color:#6b7280;font-size:14px;">Chưa có gói dịch vụ nào được cấu hình.</p>';
        }

        // Build permissions map: package_id → [module_key → caps]
        $modules_meta   = $this->service->get_module_list();
        $perm_map       = [];
        foreach ( $packages as $pkg ) {
            $perms = $this->service->get_permissions( (int) $pkg['id'] );
            $perm_map[ $pkg['id'] ] = [];
            foreach ( $perms as $p ) {
                $perm_map[ $pkg['id'] ][ $p['module_key'] ] = $p;
            }
        }

        // Collect all modules that appear in at least one package
        $all_modules = [];
        foreach ( $perm_map as $pkg_perms ) {
            foreach ( $pkg_perms as $mk => $p ) {
                if ( ! isset( $all_modules[$mk] ) ) {
                    $meta = $modules_meta[$mk] ?? null;
                    $all_modules[$mk] = is_array($meta) ? ($meta['label'] ?? $mk) : (is_string($meta) ? $meta : $mk);
                }
            }
        }

        // Determine highlight package
        $highlight_key = $atts['highlight'];
        if ( $highlight_key === '' && ! empty( $packages ) ) {
            // Auto: chọn gói ở giữa
            $mid = (int) floor( count($packages) / 2 );
            $highlight_key = $packages[$mid]['package_key'] ?? $packages[0]['package_key'];
        }

        // URLs
        $register_url = $atts['register_url'] ?: $this->get_register_url();
        $login_url    = $atts['login_url']    ?: $this->get_login_url();

        // Columns
        $col_count = (int) $atts['columns'];
        if ( $col_count < 1 ) {
            $col_count = min( count($packages), 4 );
        }

        // Grid style — dùng trực tiếp thay vì CSS custom property
        // (WordPress có thể escape dấu : trong inline style attr, làm CSS var bị hỏng)
        $grid_cols_css = 'grid-template-columns:repeat(' . $col_count . ',1fr);';

        $uid = 'lcni-pt-' . substr( md5( serialize($atts) . count($packages) ), 0, 6 );

        ob_start();
        $this->render_styles( $uid, $col_count );
        ?>
        <div class="lcni-pt-wrap" id="<?php echo esc_attr($uid); ?>">

            <?php if ( $atts['title'] !== '' ) : ?>
            <div class="lcni-pt-header">
                <h2 class="lcni-pt-title"><?php echo esc_html( $atts['title'] ); ?></h2>
                <?php if ( $atts['subtitle'] !== '' ) : ?>
                <p class="lcni-pt-subtitle"><?php echo esc_html( $atts['subtitle'] ); ?></p>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <div class="lcni-pt-grid" style="<?php echo esc_attr( $grid_cols_css ); ?>">
                <?php foreach ( $packages as $i => $pkg ) :
                    $is_highlight = ( $pkg['package_key'] === $highlight_key );
                    $pkg_color    = ! empty($pkg['color']) ? $pkg['color'] : '#2563eb';
                    $pkg_perms    = $perm_map[ $pkg['id'] ] ?? [];
                    $btn_text     = $is_highlight ? $atts['highlight_button_text'] : $atts['button_text'];
                ?>
                <div class="lcni-pt-card <?php echo $is_highlight ? 'lcni-pt-card--highlight' : ''; ?>"
                     style="--pkg-color:<?php echo esc_attr($pkg_color); ?>;"
                     data-aos-delay="<?php echo $i * 80; ?>">

                    <?php if ( $is_highlight ) : ?>
                    <div class="lcni-pt-badge">⭐ Phổ biến nhất</div>
                    <?php endif; ?>

                    <!-- Card header -->
                    <div class="lcni-pt-card-header">
                        <div class="lcni-pt-pkg-dot"></div>
                        <div class="lcni-pt-pkg-name"><?php echo esc_html( $pkg['package_name'] ); ?></div>
                        <?php if ( ! empty($pkg['description']) ) : ?>
                        <div class="lcni-pt-pkg-desc"><?php echo esc_html( $pkg['description'] ); ?></div>
                        <?php endif; ?>
                    </div>

                    <!-- Feature list -->
                    <ul class="lcni-pt-features">
                        <?php if ( empty($pkg_perms) ) : ?>
                        <li class="lcni-pt-feature lcni-pt-feature--none">
                            <span class="lcni-pt-feat-icon">—</span>
                            <span>Chưa cấu hình tính năng</span>
                        </li>
                        <?php else :
                            // Nhóm theo group
                            $grouped = [];
                            foreach ( $pkg_perms as $mk => $p ) {
                                $meta  = $modules_meta[$mk] ?? null;
                                $label = is_array($meta) ? ($meta['label'] ?? $mk) : (is_string($meta) ? $meta : $mk);
                                $group = is_array($meta) ? ($meta['group'] ?? 'Khác') : 'Khác';
                                $caps  = [];
                                if ( ! empty($p['can_view']) )     $caps[] = 'Xem';
                                if ( ! empty($p['can_filter']) )   $caps[] = 'Lọc';
                                if ( ! empty($p['can_export']) )   $caps[] = 'Xuất';
                                if ( ! empty($p['can_realtime']) ) $caps[] = 'Realtime';
                                if ( empty($caps) ) continue;
                                $grouped[$group][] = ['label' => $label, 'caps' => $caps, 'key' => $mk];
                            }
                            foreach ( $grouped as $group_name => $items ) :
                        ?>
                            <li class="lcni-pt-feature-group"><?php echo esc_html($group_name); ?></li>
                            <?php foreach ( $items as $feat ) : ?>
                            <li class="lcni-pt-feature">
                                <span class="lcni-pt-feat-icon">✓</span>
                                <span class="lcni-pt-feat-label"><?php echo esc_html($feat['label']); ?></span>
                                <span class="lcni-pt-feat-caps">
                                    <?php foreach ($feat['caps'] as $cap) : ?>
                                    <span class="lcni-pt-cap"><?php echo esc_html($cap); ?></span>
                                    <?php endforeach; ?>
                                </span>
                            </li>
                            <?php endforeach; ?>
                        <?php endforeach; endif; ?>
                    </ul>

                    <!-- CTA Button -->
                    <div class="lcni-pt-cta">
                        <?php
                        $cta_url = $register_url ?: '#';
                        // Nếu user đã đăng nhập → link sang upgrade page
                        if ( is_user_logged_in() ) {
                            $upgrade = (string) get_option('lcni_saas_upgrade_url', '');
                            $cta_url = $upgrade ?: '#';
                        }
                        ?>
                        <a href="<?php echo esc_url($cta_url); ?>"
                           class="lcni-pt-btn <?php echo $is_highlight ? 'lcni-pt-btn--primary' : 'lcni-pt-btn--outline'; ?>"
                           data-package-key="<?php echo esc_attr($pkg['package_key']); ?>"
                           data-package-id="<?php echo esc_attr($pkg['id']); ?>">
                            <?php echo esc_html($btn_text); ?>
                            <svg class="lcni-pt-btn-arrow" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M10.293 3.293a1 1 0 011.414 0l6 6a1 1 0 010 1.414l-6 6a1 1 0 01-1.414-1.414L14.586 11H3a1 1 0 110-2h11.586l-4.293-4.293a1 1 0 010-1.414z" clip-rule="evenodd"/>
                            </svg>
                        </a>
                    </div>

                </div>
                <?php endforeach; ?>
            </div>

            <?php if ( $atts['show_login_link'] === 'yes' && $login_url && ! is_user_logged_in() ) : ?>
            <div class="lcni-pt-login-hint">
                Đã có tài khoản?
                <a href="<?php echo esc_url($login_url); ?>">Đăng nhập tại đây</a>
            </div>
            <?php endif; ?>

        </div>

        <script>
        (function(){
            var el = document.getElementById(<?php echo wp_json_encode($uid); ?>);
            if (!el) return;
            // Intersection Observer để animate khi scroll vào
            if ('IntersectionObserver' in window) {
                var cards = el.querySelectorAll('.lcni-pt-card');
                var io = new IntersectionObserver(function(entries){
                    entries.forEach(function(e){
                        if (e.isIntersecting) {
                            var delay = parseInt(e.target.getAttribute('data-aos-delay') || 0);
                            setTimeout(function(){ e.target.classList.add('lcni-pt-card--visible'); }, delay);
                            io.unobserve(e.target);
                        }
                    });
                }, { threshold: 0.1 });
                cards.forEach(function(c){ io.observe(c); });
            } else {
                el.querySelectorAll('.lcni-pt-card').forEach(function(c){
                    c.classList.add('lcni-pt-card--visible');
                });
            }
        })();
        </script>
        <?php
        return ob_get_clean();
    }

    private function render_styles( $uid, $col_count ) {
        static $base_printed = false;
        $base = '';
        if ( ! $base_printed ) {
            $base_printed = true;
            $base = $this->base_css();
        }
        echo $base;
        // Per-instance column override
        echo '<style>
        #' . esc_attr($uid) . ' .lcni-pt-grid { --col-count:' . $col_count . '; }
        </style>';
    }

    private function base_css() {
        return '<style>
/* ── LCNI Pricing Table ───────────────────────────────────── */
.lcni-pt-wrap {
    --pt-bg:       #0d1117;
    --pt-surface:  #161b22;
    --pt-border:   rgba(255,255,255,.08);
    --pt-text:     #e6edf3;
    --pt-muted:    #8b949e;
    --pt-gold:     #e8b84b;
    --pt-gold-2:   #f5d27a;
    --pt-radius:   18px;
    font-family: "DM Sans", "Segoe UI", system-ui, sans-serif;
    background: var(--pt-bg);
    padding: 56px 24px 64px;
    position: relative;
    overflow: hidden;
    border-radius: 24px;
}
/* Background mesh */
.lcni-pt-wrap::before {
    content: "";
    position: absolute; inset: 0;
    background:
        radial-gradient(ellipse 60% 40% at 20% 10%, rgba(232,184,75,.06) 0%, transparent 60%),
        radial-gradient(ellipse 50% 50% at 80% 90%, rgba(37,99,235,.06) 0%, transparent 60%);
    pointer-events: none;
}

/* Header */
.lcni-pt-header { text-align: center; margin-bottom: 48px; position: relative; }
.lcni-pt-title {
    font-size: clamp(24px, 4vw, 36px);
    font-weight: 800;
    color: var(--pt-text);
    letter-spacing: -.02em;
    margin: 0 0 12px;
    line-height: 1.2;
}
.lcni-pt-title::after {
    content: "";
    display: block;
    width: 48px; height: 3px;
    background: var(--pt-gold);
    margin: 12px auto 0;
    border-radius: 2px;
}
.lcni-pt-subtitle {
    color: var(--pt-muted);
    font-size: 15px;
    margin: 0;
    line-height: 1.6;
}

/* Grid */
.lcni-pt-grid {
    display: grid;
    grid-template-columns: repeat(var(--col-count, 3), 1fr);
    gap: 20px;
    position: relative;
    max-width: 1100px;
    margin: 0 auto;
}
@media (max-width: 900px) {
    .lcni-pt-grid { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 560px) {
    .lcni-pt-grid { grid-template-columns: 1fr; }
}

/* Card */
.lcni-pt-card {
    background: var(--pt-surface);
    border: 1px solid var(--pt-border);
    border-radius: var(--pt-radius);
    padding: 28px 24px 24px;
    display: flex;
    flex-direction: column;
    position: relative;
    opacity: 0;
    transform: translateY(28px);
    transition: opacity .5s ease, transform .5s ease, box-shadow .3s ease, border-color .3s ease;
    overflow: hidden;
}
.lcni-pt-card::before {
    content: "";
    position: absolute; top: 0; left: 0; right: 0;
    height: 3px;
    background: var(--pkg-color, #2563eb);
    border-radius: var(--pt-radius) var(--pt-radius) 0 0;
    opacity: .7;
}
.lcni-pt-card--visible {
    opacity: 1;
    transform: translateY(0);
}
.lcni-pt-card:hover {
    border-color: rgba(255,255,255,.16);
    box-shadow: 0 20px 48px rgba(0,0,0,.4), 0 0 0 1px rgba(255,255,255,.06);
    transform: translateY(-4px);
}
.lcni-pt-card--visible:hover { transform: translateY(-4px); }

/* Highlight card */
.lcni-pt-card--highlight {
    background: linear-gradient(155deg, #1a2236 0%, #131d32 100%);
    border-color: var(--pt-gold);
    box-shadow: 0 8px 32px rgba(232,184,75,.12), 0 0 0 1px rgba(232,184,75,.2);
}
.lcni-pt-card--highlight::before {
    background: linear-gradient(90deg, var(--pt-gold), var(--pt-gold-2));
    opacity: 1;
    height: 4px;
}
.lcni-pt-card--highlight:hover {
    box-shadow: 0 24px 56px rgba(232,184,75,.18), 0 0 0 1px rgba(232,184,75,.3);
    border-color: var(--pt-gold-2);
    transform: translateY(-6px);
}
.lcni-pt-card--highlight.lcni-pt-card--visible { transform: translateY(-4px); }
.lcni-pt-card--highlight.lcni-pt-card--visible:hover { transform: translateY(-8px); }

/* Badge */
.lcni-pt-badge {
    position: absolute;
    top: -1px; right: 20px;
    background: linear-gradient(135deg, var(--pt-gold), var(--pt-gold-2));
    color: #0d1117;
    font-size: 11px;
    font-weight: 800;
    letter-spacing: .06em;
    text-transform: uppercase;
    padding: 4px 10px;
    border-radius: 0 0 8px 8px;
}

/* Card header */
.lcni-pt-card-header {
    margin-bottom: 24px;
    padding-bottom: 20px;
    border-bottom: 1px solid var(--pt-border);
}
.lcni-pt-pkg-dot {
    width: 12px; height: 12px;
    border-radius: 50%;
    background: var(--pkg-color, #2563eb);
    box-shadow: 0 0 12px var(--pkg-color, #2563eb);
    margin-bottom: 10px;
}
.lcni-pt-card--highlight .lcni-pt-pkg-dot {
    background: var(--pt-gold);
    box-shadow: 0 0 16px rgba(232,184,75,.5);
}
.lcni-pt-pkg-name {
    font-size: 20px;
    font-weight: 800;
    color: var(--pt-text);
    letter-spacing: -.01em;
    line-height: 1.2;
    margin-bottom: 4px;
}
.lcni-pt-card--highlight .lcni-pt-pkg-name { color: var(--pt-gold-2); }
.lcni-pt-pkg-desc {
    font-size: 13px;
    color: var(--pt-muted);
    line-height: 1.5;
}

/* Features */
.lcni-pt-features {
    list-style: none;
    margin: 0 0 24px;
    padding: 0;
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 2px;
}
.lcni-pt-feature-group {
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .1em;
    color: var(--pt-muted);
    padding: 10px 0 4px;
    margin-top: 4px;
}
.lcni-pt-feature-group:first-child { padding-top: 0; margin-top: 0; }
.lcni-pt-feature {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    padding: 5px 0;
    font-size: 13px;
    color: #c9d1d9;
    line-height: 1.4;
}
.lcni-pt-feature--none { color: var(--pt-muted); }
.lcni-pt-feat-icon {
    flex-shrink: 0;
    width: 18px;
    text-align: center;
    font-size: 13px;
    color: var(--pkg-color, #2563eb);
    font-weight: 700;
    margin-top: 1px;
}
.lcni-pt-card--highlight .lcni-pt-feat-icon { color: var(--pt-gold); }
.lcni-pt-feat-label { flex: 1; }
.lcni-pt-feat-caps {
    display: flex;
    gap: 3px;
    flex-wrap: wrap;
    flex-shrink: 0;
}
.lcni-pt-cap {
    font-size: 9px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .04em;
    padding: 2px 5px;
    border-radius: 4px;
    background: rgba(255,255,255,.06);
    color: var(--pt-muted);
    border: 1px solid rgba(255,255,255,.08);
}
.lcni-pt-card--highlight .lcni-pt-cap {
    background: rgba(232,184,75,.1);
    color: var(--pt-gold);
    border-color: rgba(232,184,75,.2);
}

/* CTA */
.lcni-pt-cta { margin-top: auto; }
.lcni-pt-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    width: 100%;
    padding: 13px 20px;
    border-radius: 12px;
    font-size: 14px;
    font-weight: 700;
    letter-spacing: .01em;
    text-decoration: none;
    transition: transform .2s ease, box-shadow .2s ease, background .2s ease;
    position: relative;
    overflow: hidden;
}
.lcni-pt-btn-arrow {
    width: 16px; height: 16px;
    transition: transform .2s ease;
    flex-shrink: 0;
}
.lcni-pt-btn:hover .lcni-pt-btn-arrow { transform: translateX(3px); }

.lcni-pt-btn--outline {
    background: transparent;
    border: 1.5px solid rgba(255,255,255,.15);
    color: var(--pt-text);
}
.lcni-pt-btn--outline:hover {
    background: rgba(255,255,255,.06);
    border-color: rgba(255,255,255,.3);
    transform: translateY(-1px);
}

.lcni-pt-btn--primary {
    background: linear-gradient(135deg, var(--pt-gold) 0%, var(--pt-gold-2) 100%);
    border: none;
    color: #0d1117;
    box-shadow: 0 4px 20px rgba(232,184,75,.25);
}
.lcni-pt-btn--primary::before {
    content: "";
    position: absolute; inset: 0;
    background: linear-gradient(135deg, rgba(255,255,255,.15), transparent);
    opacity: 0;
    transition: opacity .2s;
}
.lcni-pt-btn--primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 28px rgba(232,184,75,.4);
}
.lcni-pt-btn--primary:hover::before { opacity: 1; }

/* Pulse ring on highlight btn */
.lcni-pt-card--highlight .lcni-pt-btn--primary::after {
    content: "";
    position: absolute;
    inset: -3px;
    border-radius: 14px;
    border: 2px solid var(--pt-gold);
    animation: lcni-pt-pulse 2.5s ease-in-out infinite;
    opacity: 0;
}
@keyframes lcni-pt-pulse {
    0%   { transform: scale(1);    opacity: .6; }
    70%  { transform: scale(1.04); opacity: 0; }
    100% { transform: scale(1.04); opacity: 0; }
}

/* Login hint */
.lcni-pt-login-hint {
    text-align: center;
    margin-top: 32px;
    font-size: 13px;
    color: var(--pt-muted);
    position: relative;
}
.lcni-pt-login-hint a {
    color: var(--pt-gold);
    text-decoration: none;
    font-weight: 600;
}
.lcni-pt-login-hint a:hover { text-decoration: underline; }
</style>';
    }

    private function get_login_url() {
        $settings = get_option( 'lcni_member_login_settings', [] );
        $page_id  = absint( $settings['login_page_id'] ?? 0 );
        if ( $page_id > 0 ) {
            return (string) get_permalink( $page_id );
        }
        return (string) get_option( 'lcni_central_login_url', '' );
    }

    private function get_register_url() {
        $settings = get_option( 'lcni_member_login_settings', [] );
        $page_id  = absint( $settings['register_page_id'] ?? 0 );
        if ( $page_id > 0 ) {
            return (string) get_permalink( $page_id );
        }
        return (string) get_option( 'lcni_central_register_url', '' );
    }

    // =========================================================================
    // [lcni_upgrade_cta] — Banner nâng cấp gói dành cho user đã đăng nhập
    //
    // Atts:
    //   title="..."          — Tiêu đề (default: "Nâng cấp lên Premium")
    //   subtitle="..."       — Mô tả
    //   button_text="..."    — Label nút (default: "Nâng cấp ngay")
    //   upgrade_url="..."    — Override URL (default: lcni_saas_upgrade_url từ settings)
    //   package_key="..."    — Highlight gói cụ thể (default: gói cao nhất)
    //   show_features="yes"  — Hiện danh sách tính năng của gói target (default: yes)
    //   style="banner|card"  — Kiểu hiển thị (default: banner)
    // =========================================================================

    public function render_upgrade_cta( $atts = [] ) {
        $atts = shortcode_atts( [
            'title'         => 'Nâng cấp lên Premium',
            'subtitle'      => 'Mở khoá toàn bộ tính năng phân tích chứng khoán chuyên sâu',
            'button_text'   => 'Nâng cấp ngay',
            'upgrade_url'   => '',
            'package_key'   => '',
            'show_features' => 'yes',
            'style'         => 'banner',
        ], $atts, 'lcni_upgrade_cta' );

        // Chỉ hiện cho user đã đăng nhập, ẩn với admin
        if ( ! is_user_logged_in() ) return '';
        if ( current_user_can( 'manage_options' ) ) return '';

        // Nếu user đã có gói active rồi thì không hiện
        $current_pkg = $this->service->get_current_user_package_info();
        if ( ! empty( $current_pkg ) && empty( $current_pkg['is_expired'] ) ) return '';

        // Lấy URL upgrade
        $upgrade_url = $atts['upgrade_url'] ?: (string) get_option( 'lcni_saas_upgrade_url', '' );
        if ( $upgrade_url === '' ) {
            $upgrade_url = $this->get_register_url();
        }
        if ( $upgrade_url === '' ) $upgrade_url = '#';

        // Lấy gói target để hiện features
        $target_pkg   = null;
        $target_perms = [];
        if ( $atts['show_features'] === 'yes' ) {
            $packages = array_values( array_filter(
                $this->service->get_package_options(),
                fn($p) => ! empty( $p['is_active'] )
            ) );
            if ( ! empty( $packages ) ) {
                // Tìm theo package_key nếu có, không thì lấy gói cuối (cao nhất)
                if ( $atts['package_key'] !== '' ) {
                    foreach ( $packages as $p ) {
                        if ( $p['package_key'] === $atts['package_key'] ) {
                            $target_pkg = $p;
                            break;
                        }
                    }
                }
                if ( ! $target_pkg ) {
                    $target_pkg = end( $packages );
                }
                if ( $target_pkg ) {
                    $perms = $this->service->get_permissions( (int) $target_pkg['id'] );
                    $modules_meta = $this->service->get_module_list();
                    foreach ( $perms as $p ) {
                        if ( empty( $p['can_view'] ) ) continue;
                        $mk    = $p['module_key'];
                        $meta  = $modules_meta[$mk] ?? null;
                        $label = is_array($meta) ? ($meta['label'] ?? $mk) : (is_string($meta) ? $meta : $mk);
                        $caps  = [];
                        if ( ! empty($p['can_view']) )     $caps[] = 'Xem';
                        if ( ! empty($p['can_filter']) )   $caps[] = 'Lọc';
                        if ( ! empty($p['can_export']) )   $caps[] = 'Xuất';
                        if ( ! empty($p['can_realtime']) ) $caps[] = 'Realtime';
                        $target_perms[] = [ 'label' => $label, 'caps' => $caps ];
                    }
                }
            }
        }

        $pkg_color = ! empty( $target_pkg['color'] ) ? $target_pkg['color'] : '#e8b84b';
        $is_banner = $atts['style'] !== 'card';

        ob_start();
        ?>
        <div class="lcni-ugcta lcni-ugcta--<?php echo esc_attr( $atts['style'] ); ?>"
             style="--ugcta-color:<?php echo esc_attr( $pkg_color ); ?>;">
            <?php $this->render_upgrade_cta_styles(); ?>

            <div class="lcni-ugcta-inner">
                <div class="lcni-ugcta-content">
                    <div class="lcni-ugcta-glow"></div>
                    <div class="lcni-ugcta-icon">⭐</div>
                    <div class="lcni-ugcta-text">
                        <p class="lcni-ugcta-title"><?php echo esc_html( $atts['title'] ); ?></p>
                        <p class="lcni-ugcta-subtitle"><?php echo esc_html( $atts['subtitle'] ); ?></p>
                    </div>
                    <?php if ( ! empty( $target_perms ) && $is_banner ) : ?>
                    <ul class="lcni-ugcta-features">
                        <?php foreach ( array_slice( $target_perms, 0, 6 ) as $feat ) : ?>
                        <li>
                            <span class="lcni-ugcta-check">✓</span>
                            <?php echo esc_html( $feat['label'] ); ?>
                            <?php if ( ! empty( $feat['caps'] ) ) : ?>
                                <span class="lcni-ugcta-caps"><?php echo esc_html( implode(' · ', $feat['caps'] ) ); ?></span>
                            <?php endif; ?>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                </div>
                <div class="lcni-ugcta-action">
                    <?php if ( ! empty( $target_perms ) && ! $is_banner ) : ?>
                    <ul class="lcni-ugcta-features lcni-ugcta-features--card">
                        <?php foreach ( array_slice( $target_perms, 0, 5 ) as $feat ) : ?>
                        <li><span class="lcni-ugcta-check">✓</span><?php echo esc_html( $feat['label'] ); ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                    <a href="<?php echo esc_url( $upgrade_url ); ?>"
                       class="lcni-ugcta-btn"
                       <?php if ( $target_pkg ) : ?>data-package-key="<?php echo esc_attr( $target_pkg['package_key'] ); ?>"<?php endif; ?>>
                        <?php echo esc_html( $atts['button_text'] ); ?>
                        <svg viewBox="0 0 20 20" fill="currentColor" width="16" height="16">
                            <path fill-rule="evenodd" d="M10.293 3.293a1 1 0 011.414 0l6 6a1 1 0 010 1.414l-6 6a1 1 0 01-1.414-1.414L14.586 11H3a1 1 0 110-2h11.586l-4.293-4.293a1 1 0 010-1.414z" clip-rule="evenodd"/>
                        </svg>
                    </a>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    private function render_upgrade_cta_styles() {
        static $printed = false;
        if ( $printed ) return;
        $printed = true;
        ?>
        <style>
        .lcni-ugcta {
            font-family: "DM Sans", "Segoe UI", system-ui, sans-serif;
            position: relative;
            border-radius: 16px;
            overflow: hidden;
            margin: 16px 0;
        }
        .lcni-ugcta--banner {
            background: linear-gradient(135deg, #0d1117 0%, #131d32 100%);
            border: 1px solid rgba(232,184,75,.3);
            box-shadow: 0 4px 24px rgba(232,184,75,.08);
        }
        .lcni-ugcta--card {
            background: #161b22;
            border: 1px solid rgba(255,255,255,.08);
            max-width: 360px;
        }
        .lcni-ugcta-inner {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 24px;
            padding: 24px 28px;
            position: relative;
            z-index: 1;
        }
        .lcni-ugcta--card .lcni-ugcta-inner {
            flex-direction: column;
            align-items: flex-start;
        }
        .lcni-ugcta-glow {
            position: absolute;
            top: -40px; left: -40px;
            width: 160px; height: 160px;
            background: radial-gradient(circle, rgba(232,184,75,.15) 0%, transparent 70%);
            pointer-events: none;
        }
        .lcni-ugcta-content {
            display: flex;
            align-items: center;
            gap: 16px;
            flex: 1;
            min-width: 0;
        }
        .lcni-ugcta--card .lcni-ugcta-content {
            flex-direction: column;
            align-items: flex-start;
        }
        .lcni-ugcta-icon {
            font-size: 28px;
            flex-shrink: 0;
            line-height: 1;
        }
        .lcni-ugcta-text { min-width: 0; }
        .lcni-ugcta-title {
            font-size: 16px;
            font-weight: 800;
            color: #e8b84b;
            margin: 0 0 4px;
            line-height: 1.3;
        }
        .lcni-ugcta-subtitle {
            font-size: 13px;
            color: #8b949e;
            margin: 0;
            line-height: 1.5;
        }
        .lcni-ugcta-features {
            list-style: none;
            margin: 0 0 0 8px;
            padding: 0;
            display: flex;
            flex-direction: column;
            gap: 4px;
            flex-shrink: 0;
        }
        .lcni-ugcta-features--card { margin: 8px 0 12px; }
        .lcni-ugcta-features li {
            font-size: 12px;
            color: #c9d1d9;
            display: flex;
            align-items: center;
            gap: 6px;
            white-space: nowrap;
        }
        .lcni-ugcta-check {
            color: #e8b84b;
            font-weight: 700;
            font-size: 11px;
            flex-shrink: 0;
        }
        .lcni-ugcta-caps {
            font-size: 10px;
            color: #6b7280;
            margin-left: 2px;
        }
        .lcni-ugcta-action { flex-shrink: 0; }
        .lcni-ugcta--card .lcni-ugcta-action { width: 100%; }
        .lcni-ugcta-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 11px 22px;
            background: linear-gradient(135deg, #e8b84b, #f5d27a);
            color: #0d1117;
            font-size: 14px;
            font-weight: 700;
            border-radius: 10px;
            text-decoration: none;
            white-space: nowrap;
            transition: transform .2s ease, box-shadow .2s ease;
            box-shadow: 0 4px 16px rgba(232,184,75,.25);
        }
        .lcni-ugcta--card .lcni-ugcta-btn { width: 100%; justify-content: center; }
        .lcni-ugcta-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(232,184,75,.4);
            color: #0d1117;
            text-decoration: none;
        }
        @media (max-width: 640px) {
            .lcni-ugcta--banner .lcni-ugcta-inner { flex-direction: column; align-items: flex-start; }
            .lcni-ugcta--banner .lcni-ugcta-features { display: none; }
            .lcni-ugcta--banner .lcni-ugcta-btn { width: 100%; justify-content: center; }
        }
        </style>
        <?php
    }
}
