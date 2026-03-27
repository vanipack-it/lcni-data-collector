<?php

if (!defined('ABSPATH')) {
    exit;
}

if ( class_exists('LCNI_Member_Pricing_Shortcode') ) {
    return;
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
        // Load defaults from admin settings (Admin → Member → Pricing Table)
        $db = wp_parse_args( get_option('lcni_pricing_table_settings', []), [
            'title'              => 'Chọn gói phù hợp với bạn',
            'subtitle'           => 'Công cụ hỗ trợ nhà đầu tư chứng khoán cá nhân',
            'highlight'          => '',
            'button_text'        => 'Bắt đầu ngay',
            'highlight_btn_text' => 'Dùng thử miễn phí',
            'login_hint_text'    => 'Đã có tài khoản? Đăng nhập tại đây',
            'show_login_link'    => 'yes',
        ]);

        // Shortcode atts override DB settings (for inline customisation)
        $atts = shortcode_atts( [
            'title'                  => $db['title'],
            'subtitle'               => $db['subtitle'],
            'highlight'              => $db['highlight'],
            'register_url'           => '',
            'login_url'              => '',
            'button_text'            => $db['button_text'],
            'highlight_button_text'  => $db['highlight_btn_text'],
            'show_login_link'        => $db['show_login_link'],
            'columns'                => '',
            'debug'                  => '',
        ], $atts, 'lcni_pricing_table' );

        $packages = $this->service->get_package_options();
        $packages = array_filter( $packages, fn($p) => ! empty( $p['is_active'] ) );
        $packages = array_values( $packages );

        if ( empty( $packages ) ) {
            return '<p style="color:#6b7280;font-size:14px;">Chưa có gói dịch vụ nào được cấu hình.</p>';
        }

        // Sắp xếp gói theo số lượng permission (ít → nhiều tính năng)
        usort( $packages, function($a, $b) {
            $pa = $this->service->get_permissions( (int)$a['id'] );
            $pb = $this->service->get_permissions( (int)$b['id'] );
            $ca = count( array_filter($pa, fn($p) => !empty($p['can_view'])) );
            $cb = count( array_filter($pb, fn($p) => !empty($p['can_view'])) );
            return $ca <=> $cb;
        });

        // Load bảng giá từ admin cấu hình
        $pkg_prices    = (array) get_option( 'lcni_package_prices', [] );
        $price_durations = [1=>'tháng',3=>'quý',6=>'6 tháng',12=>'năm'];

        // Gói mặc định (không cần upgrade form) — lấy từ * fallback
        $default_pkgs  = (array) get_option( 'lcni_saas_default_packages', [] );
        $default_pkg_id = (int) ( $default_pkgs['*'] ?? 0 );

        // URL trang có shortcode [lcni_upgrade_request]
        $upgrade_request_url = (string) get_option( 'lcni_upgrade_request_page_url', '' );

        // Admin debug mode: [lcni_pricing_table debug="1"]
        $debug = ( ! empty($atts['debug']) && current_user_can('manage_options') );

        $modules_meta = $this->service->get_module_list();
        $perm_map = [];
        foreach ( $packages as $pkg ) {
            $perms = $this->service->get_permissions( (int) $pkg['id'] );
            $perm_map[ $pkg['id'] ] = [];
            foreach ( $perms as $p ) {
                // Lưu tất cả rows vào map; get_caps sẽ filter theo can_view
                $perm_map[ $pkg['id'] ][ $p['module_key'] ] = $p;
            }
        }

        // Collect all_modules only from entries where at least can_view=1
        // (tránh hiển thị module mà cả 2 gói đều không có quyền)

        $all_modules = [];
        foreach ( $packages as $pkg ) {
            foreach ( $perm_map[ $pkg['id'] ] as $mk => $p ) {
                // Chỉ thêm module vào danh sách khi ít nhất 1 gói có can_view=1
                if ( empty($p['can_view']) ) continue;
                if ( isset( $all_modules[$mk] ) ) continue;
                $meta  = $modules_meta[$mk] ?? null;
                $label = is_array($meta) ? ($meta['label'] ?? $mk) : (is_string($meta) ? $meta : $mk);
                $group = is_array($meta) ? ($meta['group'] ?? 'Khác') : 'Khác';
                $all_modules[$mk] = ['label' => $label, 'group' => $group];
            }
        }

        $grouped_modules = [];
        foreach ( $all_modules as $mk => $info ) {
            $grouped_modules[ $info['group'] ][] = ['mk' => $mk, 'label' => $info['label']];
        }

        $get_caps = function( $pkg_id, $mk ) use ( $perm_map ) {
            $p = $perm_map[$pkg_id][$mk] ?? null;
            // Row không tồn tại → không có quyền
            if ( $p === null ) return null;
            $caps = [];
            if ( ! empty($p['can_view']) )     $caps[] = 'Xem';
            if ( ! empty($p['can_filter']) )   $caps[] = 'Lọc';
            if ( ! empty($p['can_export']) )   $caps[] = 'Xuất';
            if ( ! empty($p['can_realtime']) ) $caps[] = 'RT';
            // Row tồn tại nhưng tất cả caps = 0 → coi như không có quyền
            if ( empty($caps) && empty($p['can_view']) ) return null;
            // Nếu có ít nhất can_view = 1 thì coi như có quyền (kể cả không có cap khác)
            if ( empty($p['can_view']) ) return null;
            return $caps;
        };

        $highlight_key = $atts['highlight'];
        if ( $highlight_key === '' && ! empty($packages) ) {
            $mid = (int) floor( count($packages) / 2 );
            $highlight_key = $packages[$mid]['package_key'] ?? $packages[0]['package_key'];
        }

        $n_pkg        = count($packages);
        $col_count    = (int) $atts['columns'] ?: min($n_pkg, 4);
        $register_url = $atts['register_url'] ?: $this->get_register_url();
        $login_url    = $atts['login_url']    ?: $this->get_login_url();
        $login_hint   = $db['login_hint_text'];
        $uid          = 'lcni-pt-' . substr( md5( $n_pkg . $highlight_key ), 0, 6 );

        ob_start();

        // ── Admin debug output ────────────────────────────────────────────
        if ( $debug ) {
            echo '<div style="background:#1e293b;color:#94a3b8;font-size:12px;padding:16px;border-radius:8px;margin-bottom:20px;font-family:monospace;overflow-x:auto;">';
            echo '<strong style="color:#f1f5f9;">📊 Pricing Table Debug</strong><br><br>';
            foreach ( $packages as $pkg ) {
                $edit_url = admin_url( 'admin.php?page=lcni-member-settings&tab=saas&saas_tab=permissions&perm_pkg=' . $pkg['id'] );
                echo '<strong style="color:#fbbf24;">' . esc_html($pkg['package_name']) . '</strong>';
                echo ' (id=' . $pkg['id'] . ', key=' . esc_html($pkg['package_key']) . ')';
                echo ' <a href="' . esc_url($edit_url) . '" style="color:#60a5fa;font-size:11px;" target="_blank">Sửa quyền ↗</a><br>';
                $perms = $perm_map[ $pkg['id'] ] ?? [];
                if ( empty($perms) ) {
                    echo '&nbsp;&nbsp;<em style="color:#ef4444;">Không có quyền nào!</em><br>';
                } else {
                    foreach ( $perms as $mk => $p ) {
                        $caps_str = implode(',', array_filter([
                            !empty($p['can_view'])     ? 'view'     : '',
                            !empty($p['can_filter'])   ? 'filter'   : '',
                            !empty($p['can_export'])   ? 'export'   : '',
                            !empty($p['can_realtime']) ? 'realtime' : '',
                        ]));
                        echo '&nbsp;&nbsp;' . esc_html($mk) . ': <span style="color:#4ade80;">' . esc_html($caps_str) . '</span><br>';
                    }
                }
                echo '<br>';
            }
            echo '</div>';
        }
        // ──────────────────────────────────────────────────────────────────

        // Build prices JSON cho JS switcher
        $all_prices_json = array();
        foreach ( $packages as $pkg ) {
            $pid = (int)$pkg['id'];
            $all_prices_json[$pid] = array();
            foreach ( [1,3,6,12] as $m ) {
                $all_prices_json[$pid][$m] = (float)( $pkg_prices[$pid][$m] ?? 0 );
            }
        }
        // Tính % tiết kiệm so với giá 1 tháng * số tháng
        $savings_json = array();
        foreach ( $packages as $pkg ) {
            $pid = (int)$pkg['id'];
            $base = (float)( $pkg_prices[$pid][1] ?? 0 );
            $savings_json[$pid] = array();
            foreach ( [3,6,12] as $m ) {
                $v = (float)( $pkg_prices[$pid][$m] ?? 0 );
                if ( $base > 0 && $v > 0 ) {
                    $full = $base * $m;
                    $pct  = round( ($full - $v) / $full * 100 );
                    $savings_json[$pid][$m] = $pct > 0 ? $pct : 0;
                } else {
                    $savings_json[$pid][$m] = 0;
                }
            }
        }

        $this->render_styles( $uid, $col_count );
        ?>
        <div class="lcni-pt-wrap" id="<?php echo esc_attr($uid); ?>">

            <?php if ( $atts['title'] !== '' ) : ?>
            <div class="lcni-pt-header-inside">
                <div class="lcni-pt-title-text"><?php echo esc_html( $atts['title'] ); ?></div>
                <?php if ( $atts['subtitle'] !== '' ) : ?>
                <div class="lcni-pt-subtitle-text"><?php echo esc_html( $atts['subtitle'] ); ?></div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Duration switcher -->
            <?php
            // Chỉ hiện switcher nếu có ít nhất 1 gói có giá
            $has_any_price = false;
            foreach ($packages as $pkg) {
                $pid = (int)$pkg['id'];
                foreach ([1,3,6,12] as $m) {
                    if ((float)($pkg_prices[$pid][$m] ?? 0) > 0) { $has_any_price = true; break 2; }
                }
            }
            ?>
            <?php if ($has_any_price): ?>
            <div class="lcni-pt-dur-switcher" id="<?php echo esc_attr($uid); ?>-switcher">
                <?php
                $dur_opts = [1=>'1 tháng', 3=>'3 tháng', 6=>'6 tháng', 12=>'1 năm'];
                foreach ($dur_opts as $m => $lbl):
                    // Tính % tiết kiệm tốt nhất trong tất cả gói cho duration này
                    $best_save = 0;
                    foreach ($packages as $pkg) {
                        $pid = (int)$pkg['id'];
                        $s = $savings_json[$pid][$m] ?? 0;
                        if ($s > $best_save) $best_save = $s;
                    }
                ?>
                <button class="lcni-pt-dur-btn <?php echo $m === 1 ? 'active' : ''; ?>"
                        data-months="<?php echo $m; ?>">
                    <?php echo esc_html($lbl); ?>
                    <?php if ($best_save > 0): ?>
                    <span class="lcni-pt-dur-save">-<?php echo $best_save; ?>%</span>
                    <?php endif; ?>
                </button>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div class="lcni-pt-matrix">

                <!-- Header: package names + CTA -->
                <div class="lcni-pt-matrix-header">
                    <div class="lcni-pt-label-col" style="visibility:hidden;">&nbsp;</div>
                    <?php foreach ( $packages as $i => $pkg ) :
                        $is_hi    = ( $pkg['package_key'] === $highlight_key );
                        $color    = ! empty($pkg['color']) ? $pkg['color'] : '#2563eb';
                        $btn_text = $is_hi ? $atts['highlight_button_text'] : $atts['button_text'];
                        $cta_url  = $register_url ?: '#'; // fallback only — overridden below per pkg
                    ?>
                    <div class="lcni-pt-pkg-col <?php echo $is_hi ? 'lcni-pt-pkg-col--hi' : ''; ?>"
                         style="--pkg-color:<?php echo esc_attr($color); ?>;"
                         data-aos-delay="<?php echo $i * 80; ?>">
                        <?php if ($is_hi) : ?>
                        <div class="lcni-pt-badge">&#11088; Ph&#7893; bi&#7871;n nh&#7845;t</div>
                        <?php endif; ?>
                        <div class="lcni-pt-pkg-col-inner">
                            <div class="lcni-pt-pkg-dot"></div>
                            <div class="lcni-pt-pkg-name"><?php echo esc_html($pkg['package_name']); ?></div>
                            <?php if (!empty($pkg['description'])) : ?>
                            <div class="lcni-pt-pkg-desc"><?php echo esc_html($pkg['description']); ?></div>
                            <?php endif; ?>

                            <?php
                            // Hiển thị giá gói — dynamic theo switcher
                            $pid = (int)$pkg['id'];
                            $pkg_price_data = $pkg_prices[$pid] ?? [];
                            $has_any_pkg_price = false;
                            foreach ([1,3,6,12] as $dm) {
                                if ((float)($pkg_price_data[$dm] ?? 0) > 0) { $has_any_pkg_price = true; break; }
                            }
                            $price_1m = (float)($pkg_price_data[1] ?? 0);
                            ?>
                            <?php if ($has_any_pkg_price): ?>
                            <div class="lcni-pt-price-block lcni-pt-price-dynamic"
                                 data-pkg="<?php echo $pid; ?>"
                                 data-prices="<?php echo esc_attr(json_encode($all_prices_json[$pid] ?? [])); ?>"
                                 data-savings="<?php echo esc_attr(json_encode($savings_json[$pid] ?? [])); ?>">
                                <span class="lcni-pt-price-from">Từ</span>
                                <span class="lcni-pt-price-val"><?php echo $price_1m > 0 ? number_format($price_1m,0,',','.') . 'đ' : 'Liên hệ'; ?></span>
                                <span class="lcni-pt-price-per">/tháng</span>
                                <span class="lcni-pt-price-save" style="display:none"></span>
                            </div>
                            <?php elseif ( (int)$pkg['id'] === $default_pkg_id ): ?>
                            <div class="lcni-pt-price-block">
                                <span class="lcni-pt-price-free">Miễn phí</span>
                            </div>
                            <?php endif; ?>

                            <?php
                            // CTA URL: gói mặc định → register/upgrade URL thường
                            //          gói trả phí → upgrade request page?to_package_id=X
                            $is_default_pkg = ( (int)$pkg['id'] === $default_pkg_id );
                            if ( $is_default_pkg || empty($upgrade_request_url) ) {
                                // Gói mặc định hoặc chưa cấu hình trang upgrade request
                                $cta_url = $register_url ?: '#';
                                if ( is_user_logged_in() ) {
                                    $upgrade = (string) get_option('lcni_saas_upgrade_url','');
                                    $cta_url = $upgrade ?: '#';
                                }
                            } else {
                                // Gói trả phí → link thẳng đến form nâng cấp với pkg pre-select
                                $cta_url = add_query_arg('to_package_id', $pid, $upgrade_request_url);
                                if ( ! is_user_logged_in() ) {
                                    $cta_url = add_query_arg('redirect_to', urlencode($cta_url), $register_url ?: wp_login_url($cta_url));
                                }
                            }
                            ?>
                            <a href="<?php echo esc_url($cta_url); ?>"
                               class="lcni-pt-btn lcni-pt-cta-btn <?php echo $is_hi ? 'lcni-pt-btn--primary' : 'lcni-pt-btn--outline'; ?>"
                               data-package-key="<?php echo esc_attr($pkg['package_key']); ?>"
                               data-package-id="<?php echo $pid; ?>"
                               data-base-url="<?php echo esc_attr(add_query_arg('to_package_id',$pid,$upgrade_request_url ?: '')); ?>"
                               data-is-upgrade="<?php echo (!$is_default_pkg && !empty($upgrade_request_url)) ? '1' : '0'; ?>">
                                <?php echo esc_html($btn_text); ?>
                                <svg viewBox="0 0 20 20" fill="currentColor" width="14" height="14" style="flex-shrink:0;">
                                    <path fill-rule="evenodd" d="M10.293 3.293a1 1 0 011.414 0l6 6a1 1 0 010 1.414l-6 6a1 1 0 01-1.414-1.414L14.586 11H3a1 1 0 110-2h11.586l-4.293-4.293a1 1 0 010-1.414z" clip-rule="evenodd"/>
                                </svg>
                            </a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Feature rows -->
                <?php foreach ( $grouped_modules as $group_name => $items ) : ?>
                <div class="lcni-pt-matrix-group-row">
                    <div class="lcni-pt-label-col lcni-pt-group-label"><?php echo esc_html( strtoupper($group_name) ); ?></div>
                    <?php foreach ( $packages as $pkg ) : ?>
                    <div class="lcni-pt-data-col lcni-pt-group-sep <?php echo ($pkg['package_key']===$highlight_key)?'lcni-pt-col--hi':''; ?>"></div>
                    <?php endforeach; ?>
                </div>

                <?php foreach ( $items as $feat ) :
                    $mk = $feat['mk'];
                    $any = false;
                    foreach ( $packages as $pkg ) {
                        if ( $get_caps($pkg['id'], $mk) !== null ) { $any = true; break; }
                    }
                    if (!$any) continue;
                ?>
                <div class="lcni-pt-matrix-row">
                    <div class="lcni-pt-label-col lcni-pt-feat-name"><?php echo esc_html($feat['label']); ?></div>
                    <?php foreach ( $packages as $pkg ) :
                        $is_hi = ($pkg['package_key'] === $highlight_key);
                        $caps  = $get_caps($pkg['id'], $mk);
                        $has   = $caps !== null;
                    ?>
                    <div class="lcni-pt-data-col <?php echo $is_hi ? 'lcni-pt-col--hi' : ''; ?>">
                        <div class="lcni-pt-data-col-inner">
                        <?php if ($has) : ?>
                            <span class="lcni-pt-check <?php echo $is_hi ? 'lcni-pt-check--hi' : ''; ?>">&#10003;</span>
                            <?php if (!empty($caps)) : ?>
                            <span class="lcni-pt-caps">
                                <?php foreach ($caps as $c) : ?>
                                <span class="lcni-pt-cap <?php echo $is_hi ? 'lcni-pt-cap--hi' : ''; ?>"><?php echo esc_html($c); ?></span>
                                <?php endforeach; ?>
                            </span>
                            <?php endif; ?>
                        <?php else : ?>
                            <span class="lcni-pt-dash">&#8212;</span>
                        <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>

                <?php endforeach; // groups ?>

                <?php if ( empty($grouped_modules) ) : ?>
                <div class="lcni-pt-matrix-row">
                    <div class="lcni-pt-label-col lcni-pt-feat-name" style="color:var(--pt-muted);font-style:italic;">Chưa cấu hình tính năng</div>
                    <?php foreach ($packages as $pkg) : ?>
                    <div class="lcni-pt-data-col <?php echo ($pkg['package_key']===$highlight_key)?'lcni-pt-col--hi':''; ?>">
                        <div class="lcni-pt-data-col-inner"><span class="lcni-pt-dash">&#8212;</span></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

            </div><!-- /matrix -->

            <!-- ══ MOBILE CARD SLIDER ══════════════════════════════════════ -->
            <div class="lcni-pt-mobile-slider" id="<?php echo esc_attr($uid); ?>-mobile">
                <div class="lcni-pt-mobile-track" id="<?php echo esc_attr($uid); ?>-track">
                <?php foreach ( $packages as $i => $pkg ) :
                    $is_hi  = ( $pkg['package_key'] === $highlight_key );
                    $color  = ! empty($pkg['color']) ? $pkg['color'] : '#2563eb';
                    $pid    = (int)$pkg['id'];
                    $is_default_pkg = ( $pid === $default_pkg_id );
                    $pkg_price_data = $pkg_prices[$pid] ?? [];
                    $has_any_pkg_price = false;
                    foreach ([1,3,6,12] as $dm) {
                        if ((float)($pkg_price_data[$dm] ?? 0) > 0) { $has_any_pkg_price = true; break; }
                    }
                    $price_1m = (float)($pkg_price_data[1] ?? 0);
                    // CTA URL same logic as matrix
                    if ( $is_default_pkg || empty($upgrade_request_url) ) {
                        $mob_cta_url = $register_url ?: '#';
                        if ( is_user_logged_in() ) {
                            $upgrade = (string) get_option('lcni_saas_upgrade_url','');
                            $mob_cta_url = $upgrade ?: '#';
                        }
                    } else {
                        $mob_cta_url = add_query_arg('to_package_id', $pid, $upgrade_request_url);
                        if ( ! is_user_logged_in() ) {
                            $mob_cta_url = add_query_arg('redirect_to', urlencode($mob_cta_url), $register_url ?: wp_login_url($mob_cta_url));
                        }
                    }
                    $btn_text = $is_hi ? $atts['highlight_button_text'] : $atts['button_text'];
                ?>
                <div class="lcni-pt-mobile-card <?php echo $is_hi ? 'lcni-pt-mobile-card--hi' : ''; ?>"
                     style="--pkg-color:<?php echo esc_attr($color); ?>;"
                     data-card-index="<?php echo $i; ?>">
                    <div class="lcni-pt-mobile-card-top"></div>
                    <div class="lcni-pt-mobile-card-body">
                        <?php if ($is_hi) : ?>
                        <div class="lcni-pt-mobile-badge">&#11088; Phổ biến nhất</div>
                        <?php endif; ?>
                        <div class="lcni-pt-mobile-dot"></div>
                        <div class="lcni-pt-mobile-name"><?php echo esc_html($pkg['package_name']); ?></div>
                        <?php if (!empty($pkg['description'])) : ?>
                        <div class="lcni-pt-mobile-desc"><?php echo esc_html($pkg['description']); ?></div>
                        <?php endif; ?>

                        <!-- Price (dynamic) -->
                        <?php if ($has_any_pkg_price) : ?>
                        <div class="lcni-pt-mobile-price lcni-pt-price-dynamic"
                             data-pkg="<?php echo $pid; ?>"
                             data-prices="<?php echo esc_attr(json_encode($all_prices_json[$pid] ?? [])); ?>"
                             data-savings="<?php echo esc_attr(json_encode($savings_json[$pid] ?? [])); ?>">
                            <span class="lcni-pt-price-from">Từ</span>
                            <span class="lcni-pt-price-val"><?php echo $price_1m > 0 ? number_format($price_1m,0,',','.') . 'đ' : 'Liên hệ'; ?></span>
                            <span class="lcni-pt-price-per">/tháng</span>
                            <span class="lcni-pt-price-save" style="display:none"></span>
                        </div>
                        <?php elseif ($is_default_pkg) : ?>
                        <div class="lcni-pt-mobile-price">
                            <span class="lcni-pt-price-free">Miễn phí</span>
                        </div>
                        <?php endif; ?>

                        <!-- Features list -->
                        <ul class="lcni-pt-mobile-features">
                        <?php foreach ( $grouped_modules as $group_name => $items ) :
                            foreach ( $items as $feat ) :
                                $mk   = $feat['mk'];
                                $caps = $get_caps($pkg['id'], $mk);
                                if ($caps === null) continue;
                        ?>
                            <li>
                                <span class="lcni-pt-mobile-feat-check">✓</span>
                                <span><?php echo esc_html($feat['label']); ?></span>
                                <?php if (!empty($caps)) : ?>
                                <span class="lcni-pt-mobile-caps">
                                    <?php foreach ($caps as $c) : ?>
                                    <span class="lcni-pt-mobile-cap"><?php echo esc_html($c); ?></span>
                                    <?php endforeach; ?>
                                </span>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; endforeach; ?>
                        </ul>

                        <!-- CTA -->
                        <a href="<?php echo esc_url($mob_cta_url); ?>"
                           class="lcni-pt-btn <?php echo $is_hi ? 'lcni-pt-btn--primary' : 'lcni-pt-btn--outline'; ?>"
                           data-package-id="<?php echo $pid; ?>"
                           data-base-url="<?php echo esc_attr(add_query_arg('to_package_id',$pid,$upgrade_request_url ?: '')); ?>"
                           data-is-upgrade="<?php echo (!$is_default_pkg && !empty($upgrade_request_url)) ? '1' : '0'; ?>">
                            <?php echo esc_html($btn_text); ?>
                            <svg viewBox="0 0 20 20" fill="currentColor" width="14" height="14" style="flex-shrink:0;">
                                <path fill-rule="evenodd" d="M10.293 3.293a1 1 0 011.414 0l6 6a1 1 0 010 1.414l-6 6a1 1 0 01-1.414-1.414L14.586 11H3a1 1 0 110-2h11.586l-4.293-4.293a1 1 0 010-1.414z" clip-rule="evenodd"/>
                            </svg>
                        </a>
                    </div>
                </div>
                <?php endforeach; ?>
                </div><!-- /track -->

                <!-- Dot indicators -->
                <div class="lcni-pt-mobile-dots" id="<?php echo esc_attr($uid); ?>-dots">
                    <?php foreach ($packages as $i => $pkg) : ?>
                    <div class="lcni-pt-mobile-dot-ind <?php echo $i === 0 ? 'active' : ''; ?>"
                         data-dot-index="<?php echo $i; ?>"></div>
                    <?php endforeach; ?>
                </div>
            </div><!-- /mobile-slider -->

            <?php if ( $atts['show_login_link'] === 'yes' && $login_url && ! is_user_logged_in() ) : ?>
            <div class="lcni-pt-login-hint">
                <?php echo esc_html($login_hint); ?> <a href="<?php echo esc_url($login_url); ?>">&#8594;</a>
            </div>
            <?php endif; ?>

            <?php if ( current_user_can('manage_options') ) : ?>
            <div class="lcni-pt-admin-bar">
                <?php foreach ($packages as $pkg) :
                    $edit_url = admin_url('admin.php?page=lcni-member-settings&tab=saas&saas_tab=permissions&perm_pkg='.$pkg['id']);
                ?>
                <a href="<?php echo esc_url($edit_url); ?>" class="lcni-pt-admin-link" target="_blank">
                    &#9998; Quyền: <?php echo esc_html($pkg['package_name']); ?>
                </a>
                <?php endforeach; ?>
                <a href="<?php echo esc_url(add_query_arg(['debug'=>1])); ?>" class="lcni-pt-admin-link lcni-pt-admin-link--debug">
                    &#128269; Debug
                </a>
            </div>
            <?php endif; ?>

        </div>

        <script>
        (function(){
            var el = document.getElementById(<?php echo wp_json_encode($uid); ?>);
            if (!el) return;
            if ('IntersectionObserver' in window) {
                var cols = el.querySelectorAll('.lcni-pt-pkg-col');
                var io = new IntersectionObserver(function(entries){
                    entries.forEach(function(e){
                        if (e.isIntersecting) {
                            var d = parseInt(e.target.getAttribute('data-aos-delay') || 0);
                            setTimeout(function(){ e.target.classList.add('lcni-pt-pkg-col--visible'); }, d);
                            io.unobserve(e.target);
                        }
                    });
                }, { threshold: 0.08 });
                cols.forEach(function(c){ io.observe(c); });
            } else {
                el.querySelectorAll('.lcni-pt-pkg-col').forEach(function(c){ c.classList.add('lcni-pt-pkg-col--visible'); });
            }

            // ── Duration switcher ──────────────────────────────────────────
            var switcher = document.getElementById(<?php echo wp_json_encode($uid . '-switcher'); ?>);
            if (!switcher) return;

            var curMonths = 1;

            function fmt(n) {
                return n > 0 ? n.toLocaleString('vi-VN') + 'đ' : 'Liên hệ';
            }
            function durLabel(m) {
                var map = {1:'tháng',3:'quý',6:'6 tháng',12:'năm'};
                return map[m] || 'tháng';
            }

            function updatePrices(months) {
                curMonths = months;
                el.querySelectorAll('.lcni-pt-price-dynamic').forEach(function(block){
                    var prices  = JSON.parse(block.getAttribute('data-prices') || '{}');
                    var savings = JSON.parse(block.getAttribute('data-savings') || '{}');
                    var price   = prices[months] || 0;
                    var save    = savings[months] || 0;

                    var valEl  = block.querySelector('.lcni-pt-price-val');
                    var perEl  = block.querySelector('.lcni-pt-price-per');
                    var saveEl = block.querySelector('.lcni-pt-price-save');

                    if (valEl) valEl.textContent = fmt(price);
                    if (perEl) perEl.textContent = '/' + durLabel(months);
                    if (saveEl) {
                        if (save > 0 && months > 1) {
                            saveEl.textContent = 'Tiết kiệm ' + save + '%';
                            saveEl.style.display = 'inline-block';
                        } else {
                            saveEl.style.display = 'none';
                        }
                    }
                    // Ẩn "Từ" nếu không có giá
                    var fromEl = block.querySelector('.lcni-pt-price-from');
                    if (fromEl) fromEl.style.display = price > 0 ? 'inline' : 'none';
                });

                // Cập nhật URL CTA buttons kèm duration_months
                el.querySelectorAll('.lcni-pt-cta-btn').forEach(function(btn){
                    if (btn.getAttribute('data-is-upgrade') !== '1') return;
                    var base = btn.getAttribute('data-base-url') || '';
                    if (!base) return;
                    var sep = base.indexOf('?') !== -1 ? '&' : '?';
                    btn.href = base + (months > 1 ? sep + 'duration_months=' + months : '');
                });
            }

            // Bind switcher buttons
            switcher.querySelectorAll('.lcni-pt-dur-btn').forEach(function(btn){
                btn.addEventListener('click', function(){
                    switcher.querySelectorAll('.lcni-pt-dur-btn').forEach(function(b){ b.classList.remove('active'); });
                    btn.classList.add('active');
                    updatePrices(parseInt(btn.getAttribute('data-months')));
                });
            });

            // ── Mobile card slider ─────────────────────────────────────────
            var mobileTrack = document.getElementById(<?php echo wp_json_encode($uid . '-track'); ?>);
            var mobileDots  = document.getElementById(<?php echo wp_json_encode($uid . '-dots'); ?>);

            if (mobileTrack && mobileDots) {
                var dotEls = mobileDots.querySelectorAll('.lcni-pt-mobile-dot-ind');
                var cards  = mobileTrack.querySelectorAll('.lcni-pt-mobile-card');
                var nCards = cards.length;

                // Tìm index card nổi bật để scroll đến lúc init
                var hiIdx = 0;
                cards.forEach(function(c, i) {
                    if (c.classList.contains('lcni-pt-mobile-card--hi')) hiIdx = i;
                });

                function setActiveDot(idx) {
                    dotEls.forEach(function(d, i) {
                        d.classList.toggle('active', i === idx);
                    });
                }

                // Scroll đến card theo index — dùng scrollIntoView với inline:center
                // để browser tự tính toán đúng vị trí, không cần tính thủ công offsetLeft
                function scrollToCard(idx, smooth) {
                    var card = cards[idx];
                    if (!card) return;
                    card.scrollIntoView({
                        behavior: smooth ? 'smooth' : 'instant',
                        block: 'nearest',
                        inline: 'center'
                    });
                    setActiveDot(idx);
                }

                // Dot click → scroll
                dotEls.forEach(function(dot) {
                    dot.addEventListener('click', function() {
                        scrollToCard(parseInt(this.getAttribute('data-dot-index')), true);
                    });
                });

                // Cập nhật dot khi user tự vuốt — dùng scroll event throttled
                // IntersectionObserver không đáng tin khi track có padding lớn
                var scrollTimer = null;
                mobileTrack.addEventListener('scroll', function() {
                    if (scrollTimer) return;
                    scrollTimer = setTimeout(function() {
                        scrollTimer = null;
                        // Tìm card có center gần nhất với center của track
                        var trackCenter = mobileTrack.scrollLeft + mobileTrack.clientWidth / 2;
                        var closest = 0, minDist = Infinity;
                        cards.forEach(function(c, i) {
                            var cardCenter = c.offsetLeft + c.offsetWidth / 2;
                            var dist = Math.abs(cardCenter - trackCenter);
                            if (dist < minDist) { minDist = dist; closest = i; }
                        });
                        setActiveDot(closest);
                    }, 80);
                }, { passive: true });

                // Init: scroll đến card highlight — instant, không animate
                // Dùng requestAnimationFrame để chắc chắn layout đã xong
                requestAnimationFrame(function() {
                    scrollToCard(hiIdx, false);
                });

                // Update CTA URLs on mobile when duration changes
                function updateMobileCtas(months) {
                    mobileTrack.querySelectorAll('[data-is-upgrade="1"]').forEach(function(btn) {
                        var base = btn.getAttribute('data-base-url') || '';
                        if (!base) return;
                        var sep = base.indexOf('?') !== -1 ? '&' : '?';
                        btn.href = base + (months > 1 ? sep + 'duration_months=' + months : '');
                    });
                }

                // Hook vào duration switcher
                if (switcher) {
                    switcher.querySelectorAll('.lcni-pt-dur-btn').forEach(function(btn) {
                        btn.addEventListener('click', function() {
                            updateMobileCtas(parseInt(this.getAttribute('data-months')));
                        });
                    });
                }
            }

            // Init
            updatePrices(1);
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
/* ── LCNI Pricing Table — Matrix Layout ───────────────────── */
/* Header outside dark box — avoids theme quote CSS */
.lcni-pt-header-inside {
    text-align:center;
    margin-bottom:40px;
    padding:0 8px;
    position:relative;
    z-index:2;
}
.lcni-pt-title-text {
    font-size:clamp(24px,4vw,36px) !important;
    font-weight:800 !important;
    color:#e6edf3 !important;
    letter-spacing:-.02em;
    margin:0 0 12px !important;
    line-height:1.2;
    display:block !important;
    text-shadow:none !important;
    opacity:1 !important;
    visibility:visible !important;
    filter:none !important;
}
.lcni-pt-title-text::before { content:none !important; }
.lcni-pt-title-text::after {
    content:"" !important;display:block;width:48px;height:3px;
    background:#e8b84b;margin:12px auto 0;border-radius:2px;
}
.lcni-pt-subtitle-text {
    color:#8b949e !important;
    font-size:15px;margin:0;line-height:1.6;display:block !important;
    opacity:1 !important;
    visibility:visible !important;
    filter:none !important;
}
.lcni-pt-subtitle-text::before { content:none !important; }

.lcni-pt-wrap {
    --pt-bg:      #0d1117;
    --pt-surface: #161b22;
    --pt-border:  rgba(255,255,255,.08);
    --pt-text:    #e6edf3;
    --pt-muted:   #8b949e;
    --pt-gold:    #e8b84b;
    --pt-gold-2:  #f5d27a;
    --pt-radius:  16px;
    font-family: "DM Sans","Segoe UI",system-ui,sans-serif;
    background: var(--pt-bg);
    padding: 56px 32px 64px;
    border-radius: 24px;
    position: relative;
    overflow: visible;
}
.lcni-pt-wrap::before {
    content:"";
    position:absolute;inset:0;pointer-events:none;
    border-radius:24px;
    background:
        radial-gradient(ellipse 60% 40% at 20% 10%,rgba(232,184,75,.06) 0%,transparent 60%),
        radial-gradient(ellipse 50% 50% at 80% 90%,rgba(37,99,235,.06) 0%,transparent 60%);
}

/* Header */
.lcni-pt-header {
    text-align:center;margin-bottom:48px;position:relative;
    overflow:visible;
    z-index:1;
}
.lcni-pt-title {
    font-size:clamp(24px,4vw,36px);font-weight:800;color:var(--pt-text);
    letter-spacing:-.02em;margin:0 0 12px;line-height:1.2;
    overflow:visible;
    white-space:normal;
    word-break:break-word;
    padding-left:0.15em; /* compensate for italic/quote overhang */
}
.lcni-pt-title::after {
    content:"";display:block;width:48px;height:3px;
    background:var(--pt-gold);margin:12px auto 0;border-radius:2px;
}
.lcni-pt-subtitle { color:var(--pt-muted);font-size:15px;margin:0;line-height:1.6; }

/* ══ MATRIX ══════════════════════════════════════════════════ */
.lcni-pt-matrix {
    max-width:1100px;
    margin:0 auto;
    overflow-x:auto;
    -webkit-overflow-scrolling:touch;
    /* CSS table layout: columns perfectly aligned across all rows */
    display:table;
    width:100%;
    border-spacing:0;
}

/* Every row = table-row */
.lcni-pt-matrix-header,
.lcni-pt-matrix-group-row,
.lcni-pt-matrix-row {
    display:table-row;
}
.lcni-pt-matrix-header {
    /* room for badge overflowing above */
}

/* Label column (left, fixed width) */
.lcni-pt-label-col {
    display:table-cell;
    width:220px;
    min-width:160px;
    padding:10px 16px 10px 0;
    vertical-align:middle;
    white-space:normal;
}
@media(max-width:640px){ .lcni-pt-label-col{ width:130px;min-width:100px; } }

/* Package header + data columns (equal width, auto) */
.lcni-pt-pkg-col,
.lcni-pt-data-col {
    display:table-cell;
    text-align:center;
    vertical-align:middle;
}

/* ── Package header column ── */
.lcni-pt-pkg-col {
    padding:24px 16px;
    background:var(--pt-surface);
    border:1px solid var(--pt-border);
    border-bottom:none;
    border-radius:var(--pt-radius) var(--pt-radius) 0 0;
    position:relative;
    opacity:0;
    transform:translateY(20px);
    transition:opacity .5s ease, transform .5s ease;
    min-width:140px;
}
/* Inner flex container inside table-cell */
.lcni-pt-pkg-col-inner {
    display:flex;
    flex-direction:column;
    align-items:center;
    gap:6px;
}
.lcni-pt-pkg-col::before {
    content:"";
    position:absolute;top:0;left:0;right:0;
    height:4px;
    background:var(--pkg-color,#2563eb);
    border-radius:var(--pt-radius) var(--pt-radius) 0 0;
}
.lcni-pt-pkg-col--visible { opacity:1;transform:translateY(0); }
.lcni-pt-pkg-col--hi {
    background:linear-gradient(155deg,#1a2236 0%,#131d32 100%);
    border-color:var(--pt-gold);
    box-shadow:0 0 0 1px rgba(232,184,75,.2), 0 8px 32px rgba(232,184,75,.1);
}
.lcni-pt-pkg-col--hi::before {
    background:linear-gradient(90deg,var(--pt-gold),var(--pt-gold-2));
    height:4px;
}

/* Badge */
.lcni-pt-badge {
    position:absolute;top:0;right:16px;transform:translateY(-100%);
    background:linear-gradient(135deg,var(--pt-gold),var(--pt-gold-2));
    color:#0d1117;font-size:10px;font-weight:800;
    letter-spacing:.06em;text-transform:uppercase;
    padding:4px 10px;border-radius:6px 6px 0 0;
    white-space:nowrap;
}

/* Package dot + name */
.lcni-pt-pkg-dot {
    width:10px;height:10px;border-radius:50%;
    background:var(--pkg-color,#2563eb);
    box-shadow:0 0 10px var(--pkg-color,#2563eb);
    margin-bottom:4px;
}
.lcni-pt-pkg-col--hi .lcni-pt-pkg-dot {
    background:var(--pt-gold);
    box-shadow:0 0 14px rgba(232,184,75,.5);
}
.lcni-pt-pkg-name {
    font-size:17px;font-weight:800;color:var(--pt-text);
    letter-spacing:-.01em;line-height:1.2;
}
.lcni-pt-pkg-col--hi .lcni-pt-pkg-name { color:var(--pt-gold-2); }
.lcni-pt-pkg-desc {
    font-size:12px;color:var(--pt-muted);line-height:1.4;
    margin-bottom:4px;
}
.lcni-pt-price-block {
    display:flex;align-items:baseline;gap:4px;margin:8px 0 4px;flex-wrap:wrap;justify-content:center;
}
.lcni-pt-price-from { font-size:11px;color:var(--pt-muted); }
.lcni-pt-price-val  { font-size:20px;font-weight:800;color:var(--pt-gold); }
.lcni-pt-price-per  { font-size:11px;color:var(--pt-muted); }
.lcni-pt-price-free { font-size:14px;font-weight:700;color:#4ade80;letter-spacing:.3px; }
.lcni-pt-price-save {
    display:inline-block;background:#16a34a;color:#fff;font-size:10px;font-weight:700;
    padding:2px 7px;border-radius:20px;letter-spacing:.3px;vertical-align:middle;
    animation: lcni-pop .25s ease;
}
@keyframes lcni-pop { 0%{transform:scale(.8);opacity:0} 100%{transform:scale(1);opacity:1} }

/* Duration switcher */
.lcni-pt-dur-switcher {
    display:flex;justify-content:center;gap:8px;margin:12px 0 20px;flex-wrap:wrap;
}
.lcni-pt-dur-btn {
    display:flex;align-items:center;gap:5px;padding:7px 18px;
    background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.14);
    border-radius:30px;cursor:pointer;font-size:13px;font-weight:500;
    color:var(--pt-muted);transition:all .2s;white-space:nowrap;
}
.lcni-pt-dur-btn:hover { border-color:rgba(255,255,255,.35);color:#fff; }
.lcni-pt-dur-btn.active {
    background:var(--pt-gold);border-color:var(--pt-gold);color:#1a1a2e;font-weight:700;
}
.lcni-pt-dur-save {
    background:#16a34a;color:#fff;font-size:10px;font-weight:700;
    padding:1px 6px;border-radius:10px;
}
.lcni-pt-dur-btn.active .lcni-pt-dur-save { background:rgba(0,0,0,.2); }

/* ── Data cells ── */
.lcni-pt-data-col {
    padding:9px 16px;   /* match pkg-col horizontal padding */
    background:var(--pt-surface);
    border-left:1px solid var(--pt-border);
    border-right:1px solid var(--pt-border);
}
.lcni-pt-data-col-inner {
    display:flex;
    align-items:center;
    justify-content:center;
    gap:4px;
    flex-wrap:wrap;
}
.lcni-pt-col--hi {
    background:linear-gradient(180deg,#131d32 0%,#0f1829 100%);
    border-left-color:rgba(232,184,75,.15);
    border-right-color:rgba(232,184,75,.15);
}

/* Alternating row shading */
.lcni-pt-matrix-row:nth-child(even) .lcni-pt-data-col {
    background:rgba(255,255,255,.025);
}
.lcni-pt-matrix-row:nth-child(even) .lcni-pt-col--hi {
    background:rgba(232,184,75,.03);
}
.lcni-pt-matrix-row:last-child .lcni-pt-data-col {
    border-bottom:1px solid var(--pt-border);
    border-radius:0 0 0 0;
}
.lcni-pt-matrix-row:last-child .lcni-pt-col--hi {
    border-bottom-color:rgba(232,184,75,.2);
}

/* Group heading row */
.lcni-pt-matrix-group-row .lcni-pt-data-col.lcni-pt-group-sep {
    background:rgba(255,255,255,.02);
    border-bottom:1px solid rgba(255,255,255,.05);
    height:32px;
    padding:0;
}
.lcni-pt-matrix-group-row .lcni-pt-col--hi.lcni-pt-group-sep {
    background:rgba(232,184,75,.03);
    border-bottom-color:rgba(232,184,75,.08);
}

/* Labels */
.lcni-pt-group-label {
    font-size:10px;font-weight:700;text-transform:uppercase;
    letter-spacing:.1em;color:var(--pt-muted);
    padding-top:18px;padding-bottom:4px;
    align-items:flex-end;
}
.lcni-pt-feat-name {
    font-size:13px;color:#c9d1d9;
    padding-right:12px;
    line-height:1.35;
}

/* Cell content */
.lcni-pt-check {
    font-size:14px;font-weight:700;color:var(--pt-muted);
}
.lcni-pt-check--hi { color:var(--pt-gold); }
.lcni-pt-dash { font-size:14px;color:rgba(255,255,255,.15); }

.lcni-pt-caps { display:flex;gap:3px;flex-wrap:wrap;justify-content:center; }
.lcni-pt-cap {
    font-size:9px;font-weight:700;text-transform:uppercase;
    padding:2px 5px;border-radius:4px;
    background:rgba(255,255,255,.06);
    color:var(--pt-muted);
    border:1px solid rgba(255,255,255,.08);
}
.lcni-pt-cap--hi {
    background:rgba(232,184,75,.1);
    color:var(--pt-gold);
    border-color:rgba(232,184,75,.2);
}

/* CTA button (inside header col) */
.lcni-pt-btn {
    display:inline-flex;align-items:center;justify-content:center;
    gap:6px;width:100%;margin-top:12px;
    padding:11px 14px;border-radius:10px;
    font-size:13px;font-weight:700;letter-spacing:.01em;
    text-decoration:none;
    transition:transform .2s, box-shadow .2s, background .2s;
}
.lcni-pt-btn--outline {
    background:transparent;
    border:1.5px solid rgba(255,255,255,.15);
    color:var(--pt-text);
}
.lcni-pt-btn--outline:hover {
    background:rgba(255,255,255,.06);
    border-color:rgba(255,255,255,.3);
    transform:translateY(-1px);
}
.lcni-pt-btn--primary {
    background:linear-gradient(135deg,var(--pt-gold) 0%,var(--pt-gold-2) 100%);
    border:none;color:#0d1117;
    box-shadow:0 4px 18px rgba(232,184,75,.25);
    position:relative;overflow:hidden;
}
.lcni-pt-btn--primary::before {
    content:"";position:absolute;inset:0;
    background:linear-gradient(135deg,rgba(255,255,255,.15),transparent);
    opacity:0;transition:opacity .2s;
}
.lcni-pt-btn--primary:hover {
    transform:translateY(-2px);
    box-shadow:0 8px 28px rgba(232,184,75,.4);
    color:#0d1117;
}
.lcni-pt-btn--primary:hover::before { opacity:1; }

/* Login hint */
.lcni-pt-login-hint {
    text-align:center;margin-top:32px;
    font-size:13px;color:var(--pt-muted);
}

/* Admin quick-edit bar */
.lcni-pt-admin-bar {
    display:flex;gap:8px;flex-wrap:wrap;justify-content:center;
    margin-top:16px;padding-top:16px;
    border-top:1px dashed rgba(255,255,255,.1);
}
.lcni-pt-admin-link {
    font-size:11px;padding:4px 10px;border-radius:5px;
    background:rgba(255,255,255,.06);color:var(--pt-muted);
    text-decoration:none;border:1px solid rgba(255,255,255,.1);
    transition:background .15s;
}
.lcni-pt-admin-link:hover { background:rgba(255,255,255,.12);color:var(--pt-text); }
.lcni-pt-admin-link--debug { color:#60a5fa; }
.lcni-pt-login-hint a {
    color:var(--pt-gold);text-decoration:none;font-weight:600;
}
.lcni-pt-login-hint a:hover { text-decoration:underline; }

/* ══ MOBILE CARD SLIDER (≤ 760px) ══════════════════════════════ */
@media(max-width:760px){
    /* Ẩn matrix table trên mobile */
    .lcni-pt-matrix { display:none !important; }

    /* Slider container */
    .lcni-pt-mobile-slider {
        display:block;
        position:relative;
        /* KHÔNG dùng overflow:hidden ở đây — để card peek hai bên hiện ra */
    }

    /* Track: mỗi card chiếm đúng 100% width viewport của track,
       padding hai bên = khoảng peek, scroll-snap căn CENTER */
    .lcni-pt-mobile-track {
        display:flex;
        overflow-x:scroll;
        scroll-snap-type:x mandatory;
        -webkit-overflow-scrolling:touch;
        overscroll-behavior-x:contain;
        /* padding = (track width - card width) / 2 để snap căn giữa.
           Card width = 82vw, peek mỗi bên = 9vw → padding = 9vw */
        padding:8px 9vw 16px;
        gap:12px;
        scrollbar-width:none;
        /* Tắt pointer-events khi đang animate để tránh conflict */
        touch-action:pan-x pinch-zoom;
        /* Không dùng will-change ở đây — gây jank trên low-end */
    }
    .lcni-pt-mobile-track::-webkit-scrollbar { display:none; }

    /* Mỗi card: chiếm 82vw, snap center */
    .lcni-pt-mobile-card {
        flex:0 0 82vw;
        max-width:340px;
        scroll-snap-align:center;
        scroll-snap-stop:always;   /* QUAN TRỌNG: chặn skip card khi vuốt nhanh */
        background:var(--pt-surface);
        border:1px solid var(--pt-border);
        border-radius:16px;
        overflow:hidden;
        position:relative;
    }
    .lcni-pt-mobile-card--hi {
        background:linear-gradient(155deg,#1a2236 0%,#131d32 100%);
        border-color:var(--pt-gold);
        box-shadow:0 0 0 1px rgba(232,184,75,.2), 0 8px 24px rgba(232,184,75,.12);
    }
    .lcni-pt-mobile-card-top {
        height:4px;
        background:var(--pkg-color,#2563eb);
    }
    .lcni-pt-mobile-card--hi .lcni-pt-mobile-card-top {
        background:linear-gradient(90deg,var(--pt-gold),var(--pt-gold-2));
    }
    .lcni-pt-mobile-badge {
        display:inline-block;
        background:linear-gradient(135deg,var(--pt-gold),var(--pt-gold-2));
        color:#0d1117;font-size:9px;font-weight:800;
        letter-spacing:.06em;text-transform:uppercase;
        padding:3px 10px;border-radius:0 0 8px 8px;
        margin-bottom:4px;
    }
    .lcni-pt-mobile-card-body {
        padding:16px 18px 20px;
    }
    .lcni-pt-mobile-dot {
        width:8px;height:8px;border-radius:50%;
        background:var(--pkg-color,#2563eb);
        box-shadow:0 0 8px var(--pkg-color,#2563eb);
        display:inline-block;margin-bottom:8px;
    }
    .lcni-pt-mobile-card--hi .lcni-pt-mobile-dot {
        background:var(--pt-gold);
        box-shadow:0 0 12px rgba(232,184,75,.5);
    }
    .lcni-pt-mobile-name {
        font-size:18px;font-weight:800;color:var(--pt-text);margin:0 0 4px;
    }
    .lcni-pt-mobile-card--hi .lcni-pt-mobile-name { color:var(--pt-gold-2); }
    .lcni-pt-mobile-desc {
        font-size:12px;color:var(--pt-muted);margin:0 0 12px;line-height:1.4;
    }
    .lcni-pt-mobile-price {
        display:flex;align-items:baseline;gap:4px;flex-wrap:wrap;margin-bottom:14px;
    }
    .lcni-pt-mobile-features {
        list-style:none;margin:0 0 16px;padding:0;
        display:flex;flex-direction:column;gap:7px;
        border-top:1px solid rgba(255,255,255,.06);
        padding-top:14px;
    }
    .lcni-pt-mobile-features li {
        display:flex;align-items:center;gap:8px;
        font-size:12px;color:#c9d1d9;
    }
    .lcni-pt-mobile-feat-check { color:var(--pt-gold);font-weight:700;flex-shrink:0; }
    .lcni-pt-mobile-feat-dash  { color:rgba(255,255,255,.2);flex-shrink:0; }
    .lcni-pt-mobile-caps {
        display:flex;gap:3px;flex-wrap:wrap;margin-left:auto;
    }
    .lcni-pt-mobile-cap {
        font-size:9px;font-weight:700;text-transform:uppercase;
        padding:1px 5px;border-radius:4px;
        background:rgba(255,255,255,.06);color:var(--pt-muted);
        border:1px solid rgba(255,255,255,.08);
    }
    .lcni-pt-mobile-card--hi .lcni-pt-mobile-cap {
        background:rgba(232,184,75,.1);color:var(--pt-gold);
        border-color:rgba(232,184,75,.2);
    }

    /* Dots indicator */
    .lcni-pt-mobile-dots {
        display:flex;justify-content:center;gap:6px;margin-top:4px;padding-bottom:4px;
    }
    .lcni-pt-mobile-dot-ind {
        width:6px;height:6px;border-radius:50%;
        background:rgba(255,255,255,.2);
        transition:all .25s;
        cursor:pointer;
    }
    .lcni-pt-mobile-dot-ind.active {
        width:18px;border-radius:3px;
        background:var(--pt-gold);
    }

    .lcni-pt-wrap { padding:40px 16px 48px; }
}
@media(min-width:761px){
    .lcni-pt-mobile-slider { display:none !important; }
}
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
