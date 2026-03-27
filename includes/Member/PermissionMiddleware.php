<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_Member_Permission_Middleware {

    private $service;

    private $shortcode_map = [
        // Industry / Market
        'lcni_industry_dashboard'       => ['module' => 'industry',         'capability' => 'view'],
        'lcni_industry_monitor'         => ['module' => 'industry-monitor', 'capability' => 'view'],
        'lcni_industry_monitor_compact' => ['module' => 'industry-monitor', 'capability' => 'view'],
        // Chart
        'lcni_stock_chart'              => ['module' => 'chart',            'capability' => 'view'],
        'lcni_stock_chart_query'        => ['module' => 'chart',            'capability' => 'view'],
        'lcni_stock_query_form'         => ['module' => 'chart',            'capability' => 'view'],
        'lcni_chart'                    => ['module' => 'chart-builder',    'capability' => 'view'],
        // Overview
        'lcni_stock_overview'           => ['module' => 'overview',         'capability' => 'view'],
        'lcni_stock_overview_query'     => ['module' => 'overview',         'capability' => 'view'],
        // Filter
        'lcni_stock_filter'             => ['module' => 'filter',           'capability' => 'filter'],
        'lcni_filter'                   => ['module' => 'filter',           'capability' => 'filter'],
        // Signals
        'lcni_stock_signals'            => ['module' => 'signals',          'capability' => 'view'],
        'lcni_stock_signals_query'      => ['module' => 'signals',          'capability' => 'view'],
        // Watchlist
        'lcni_watchlist'                => ['module' => 'watchlist',        'capability' => 'view'],
        'lcni_watchlist_add'            => ['module' => 'watchlist',        'capability' => 'view'],
        'lcni_watchlist_add_button'     => ['module' => 'watchlist',        'capability' => 'view'],
        'lcni_watchlist_add_form'       => ['module' => 'watchlist',        'capability' => 'view'],
        // Portfolio
        'lcni_portfolio'                => ['module' => 'portfolio',        'capability' => 'view'],
        // Recommend
        'lcni_signals'                  => ['module' => 'recommend-signals',     'capability' => 'view'],
        'lcni_performance'              => ['module' => 'recommend-performance', 'capability' => 'view'],
        'lcni_performance_v2'           => ['module' => 'recommend-performance', 'capability' => 'view'],
        'lcni_equity_curve'             => ['module' => 'recommend-equity',      'capability' => 'view'],
        'lcni_signal'                   => ['module' => 'recommend-signals',     'capability' => 'view'],
        'lcni_rule_follow'              => ['module' => 'recommend-follow',      'capability' => 'view'],
        'lcni_user_rule'                => ['module' => 'user-rule',             'capability' => 'view'],
        // Heatmap
        'lcni_heatmap'                  => ['module' => 'heatmap',          'capability' => 'view'],

        // DNSE Trading
        'lcni_dnse_trading'             => ['module' => 'dnse-trading',     'capability' => 'view'],

        // Market Dashboard & Chart
        'lcni_market_dashboard'         => ['module' => 'market-dashboard', 'capability' => 'view'],
        'lcni_market_chart'             => ['module' => 'market-chart',     'capability' => 'view'],

        // Member
        'lcni_member_login'             => ['module' => 'member-login',    'capability' => 'view'],
        'lcni_member_register'          => ['module' => 'member-register', 'capability' => 'view'],
        'lcni_member_profile'           => ['module' => 'member-profile',  'capability' => 'view'],
    ];

    public function __construct( LCNI_SaaS_Service $service ) {
        $this->service = $service;
        add_filter( 'pre_do_shortcode_tag',          [ $this, 'guard_shortcode' ],    10, 4 );
        add_filter( 'rest_request_before_callbacks', [ $this, 'guard_rest_request' ], 10, 3 );
    }

    public function guard_shortcode( $return, $tag, $attr, $m ) {
        if ( $tag === 'lcni_signals_rule' ) {
            $rule_slug  = ! empty( $attr['rule'] ) ? sanitize_key( $attr['rule'] ) : '';
            $module_key = $rule_slug !== '' ? 'recommend-rule-' . $rule_slug : 'recommend-signals';
            if ( $this->service->can( $module_key, 'view' ) ) {
                return $return;
            }
            return $this->render_denied_block( $module_key, 'view' );
        }

        if ( ! isset( $this->shortcode_map[ $tag ] ) ) {
            return $return;
        }

        $map = $this->shortcode_map[ $tag ];
        if ( $this->service->can( $map['module'], $map['capability'] ) ) {
            return $return;
        }

        return $this->render_denied_block( $map['module'], $map['capability'] );
    }

    public function guard_rest_request( $response, $handler, $request ) {
        $route = $request->get_route();

        $checks = [
            '/lcni/v1/industry'       => [ 'industry',  'view',   'Bạn không có quyền xem dữ liệu ngành.' ],
            '/lcni/v1/filter'         => [ 'filter',    'filter', 'Bạn không có quyền dùng bộ lọc.' ],
            '/lcni/v1/screener'       => [ 'screener',  'filter', 'Bạn không có quyền dùng screener.' ],
            '/lcni/v1/stock'          => [ 'chart',     'view',   'Bạn không có quyền xem dữ liệu cổ phiếu.' ],
            '/lcni/v1/chart'          => [ 'chart',     'view',   'Bạn không có quyền xem chart.' ],
            '/lcni/v1/stock-overview' => [ 'overview',  'view',   'Bạn không có quyền xem overview.' ],
            '/lcni/v1/watchlist'      => [ 'watchlist', 'view',   'Bạn không có quyền dùng watchlist.' ],
            '/lcni/v1/stock-signals'  => [ 'signals',        'view',   'Bạn không có quyền xem tín hiệu.' ],
            '/lcni/v1/heatmap'         => [ 'heatmap',         'view',   'Bạn không có quyền xem heatmap.' ],
            '/lcni/v1/market-dashboard'=> [ 'market-dashboard','view',   'Bạn không có quyền xem Market Dashboard.' ],
            '/lcni/v1/market-chart'    => [ 'market-chart',    'view',   'Bạn không có quyền xem Market Chart.' ],
            '/lcni/v1/dnse'            => [ 'dnse-trading',   'view',   'Bạn không có quyền dùng DNSE Trading.' ],
            '/lcni/v1/user-rules'      => [ 'user-rule',      'view',   'Bạn không có quyền sử dụng Auto Apply Rule.' ],
        ];

        // Kiểm tra riêng endpoint đặt lệnh: cần capability 'trade'
        if ( strpos( $route, '/lcni/v1/dnse/order' ) === 0 ) {
            if ( ! $this->service->can( 'dnse-trading', 'trade' ) ) {
                return new WP_Error( 'lcni_forbidden', 'Bạn không có quyền đặt lệnh giao dịch.', [
                    'status'      => 403,
                    'reason'      => is_user_logged_in() ? 'upgrade' : 'login',
                    'login_url'   => $this->get_login_url(),
                    'upgrade_url' => $this->get_upgrade_url(),
                ] );
            }
        }

        foreach ( $checks as $prefix => [ $module, $cap, $message ] ) {
            if ( strpos( $route, $prefix ) !== 0 ) {
                continue;
            }
            if ( $this->service->can( $module, $cap ) ) {
                break;
            }
            return new WP_Error( 'lcni_forbidden', $message, [
                'status'      => 403,
                'reason'      => is_user_logged_in() ? 'upgrade' : 'login',
                'login_url'   => $this->get_login_url(),
                'upgrade_url' => $this->get_upgrade_url(),
            ] );
        }

        return $response;
    }

    private function render_denied_block( $module_key, $capability ) {
        static $style_printed  = false;
        static $google_printed = false;
        $style = '';
        if ( ! $style_printed ) {
            $style_printed = true;
            $style = $this->denied_css();
        }

        $logged_in  = is_user_logged_in();
        $pkg_info   = $logged_in ? $this->service->get_current_user_package_info() : null;
        $is_expired = $pkg_info && ! empty( $pkg_info['is_expired'] );

        if ( ! $logged_in ) {
            $state = 'guest';
        } elseif ( $is_expired ) {
            $state = 'expired';
        } else {
            $state = 'upgrade';
        }

        $modules     = $this->service->get_module_list();
        $module_meta = $modules[ $module_key ] ?? null;
        if ( is_array( $module_meta ) ) {
            $module_name = $module_meta['label'] ?? $module_key;
        } elseif ( is_string( $module_meta ) && $module_meta !== '' ) {
            $module_name = $module_meta;
        } else {
            $module_name = $module_key;
        }

        $login_url        = $this->get_login_url();
        $reg_url          = $this->get_register_url();
        $upgrade_url      = $this->get_upgrade_url();
        $google_client_id = get_option( 'lcni_google_client_id', '' );
        $current_url      = ( is_ssl() ? 'https' : 'http' ) . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
        $g_redirect       = $current_url;
        $g_nonce          = wp_create_nonce( 'lcni_google_auth_nonce' );

        // Inject GIS + auth script qua wp_footer (chạy sau shortcode render xong)
        if ( $state === 'guest' && $google_client_id !== '' && ! $google_printed ) {
            $ajax_url_js = esc_js( admin_url( 'admin-ajax.php' ) );
            $nonce_js    = esc_js( $g_nonce );
            add_action( 'wp_footer', function() use ( $ajax_url_js, $nonce_js ) {
                static $footer_done = false;
                if ( $footer_done ) return;
                $footer_done = true;
                ?>
                <script>
                /* LCNI Google Auth — inject by PermissionMiddleware */
                (function() {
                    window.lcniGoogleAuth = window.lcniGoogleAuth || { ajax_url: '<?php echo $ajax_url_js; ?>', nonce: '<?php echo $nonce_js; ?>' };
                    function loadGoogleAuth() {
                        if ( window.lcniGoogleAuthLoaded ) return;
                        window.lcniGoogleAuthLoaded = true;
                        var s = document.createElement('script');
                        s.src = '<?php echo esc_js( LCNI_URL . 'assets/js/lcni-google-auth.js' ); ?>';
                        s.defer = true;
                        document.head.appendChild(s);
                    }
                    if ( window.google && window.google.accounts ) {
                        loadGoogleAuth();
                    } else {
                        var gis = document.createElement('script');
                        gis.src = 'https://accounts.google.com/gsi/client';
                        gis.async = true;
                        gis.defer = true;
                        gis.onload = loadGoogleAuth;
                        document.head.appendChild(gis);
                    }
                })();
                </script>
                <?php
            }, 99 );
        }

        ob_start();
        echo $style;
        ?>
        <div class="lcni-denied-block lcni-denied--<?php echo esc_attr( $state ); ?>">
            <div class="lcni-denied-inner">
                <div class="lcni-denied-icon">
                    <?php
                    if ( $state === 'guest' ) {
                        echo '🔒';
                    } elseif ( $state === 'expired' ) {
                        echo '⏰';
                    } else {
                        echo '⭐';
                    }
                    ?>
                </div>
                <div class="lcni-denied-content">
                    <div class="lcni-denied-title">
                        <?php
                        if ( $state === 'guest' ) {
                            echo 'Vui lòng đăng nhập để xem nội dung này';
                        } elseif ( $state === 'expired' ) {
                            echo 'Gói dịch vụ đã hết hạn';
                        } else {
                            // Lấy tên gói có module này để hiển thị cụ thể
                        $required_pkgs = $this->service->get_packages_for_module( $module_key );
                        if ( ! empty( $required_pkgs ) ) {
                            $pkg_names = array_map( static function( $p ) {
                                return ! empty( $p['badge_label'] ) ? $p['badge_label'] : $p['package_name'];
                            }, $required_pkgs );
                            // Lấy màu gói đầu tiên làm accent
                            $first_color = $required_pkgs[0]['color'] ?? '#2563eb';
                            echo 'Cần nâng cấp lên <span style="color:' . esc_attr( $first_color ) . ';font-weight:700">'
                                . esc_html( implode( ' / ', $pkg_names ) )
                                . '</span>';
                        } else {
                            echo 'Nội dung dành cho gói cao hơn';
                        }
                        }
                        ?>
                    </div>
                    <div class="lcni-denied-desc">
                        <?php if ( $state === 'guest' ) : ?>
                            <strong><?php echo esc_html( $module_name ); ?></strong> yêu cầu tài khoản để truy cập.
                        <?php elseif ( $state === 'expired' ) : ?>
                            Gói của bạn đã hết hạn. Gia hạn để tiếp tục dùng <strong><?php echo esc_html( $module_name ); ?></strong>.
                        <?php else : ?>
                            <?php
                            // Lấy tên gói user đang dùng
                            $current_pkg_name = '';
                            if ( $pkg_info && ! empty( $pkg_info['package_name'] ) ) {
                                $current_pkg_name = ! empty( $pkg_info['badge_label'] )
                                    ? $pkg_info['badge_label']
                                    : $pkg_info['package_name'];
                            }
                            ?>
                            <strong><?php echo esc_html( $module_name ); ?></strong>
                            <?php if ( $current_pkg_name !== '' ) : ?>
                                không có trong gói <strong><?php echo esc_html( $current_pkg_name ); ?></strong> của bạn.
                            <?php else : ?>
                                không nằm trong gói hiện tại của bạn.
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <div class="lcni-denied-actions">
                        <?php if ( $state === 'guest' ) : ?>
                            <?php if ( $login_url ) : ?>
                                <a href="<?php echo esc_url( $login_url ); ?>" class="lcni-denied-btn lcni-denied-btn--primary">🔑 Đăng nhập</a>
                            <?php endif; ?>
                            <?php if ( $reg_url ) : ?>
                                <a href="<?php echo esc_url( $reg_url ); ?>" class="lcni-denied-btn lcni-denied-btn--ghost">📝 Đăng ký</a>
                            <?php endif; ?>
                            <?php if ( $google_client_id !== '' ) : ?>
                                <div class="lcni-denied-google-wrap">
                                    <?php if ( ! $google_printed ) : ?>
                                        <!-- Google One Tap — tự động đăng nhập nếu Chrome có sẵn tài khoản -->
                                        <div id="g_id_onload_denied"
                                            data-client_id="<?php echo esc_attr( $google_client_id ); ?>"
                                            data-callback="lcniGoogleCallback"
                                            data-auto_prompt="true"
                                            data-itp_support="true"
                                            data-cancel_on_tap_outside="false"
                                            data-nonce="<?php echo esc_attr( $g_nonce ); ?>">
                                        </div>
                                    <?php endif; ?>
                                    <!-- Nút Google tiêu chuẩn -->
                                    <div class="g_id_signin"
                                        data-type="standard"
                                        data-shape="rectangular"
                                        data-theme="outline"
                                        data-text="signin_with"
                                        data-locale="vi"
                                        data-size="large"
                                        data-width="220">
                                    </div>
                                    <input type="hidden" id="lcni_google_redirect" value="<?php echo esc_url( $g_redirect ); ?>">
                                </div>
                                <?php $google_printed = true; ?>
                            <?php endif; ?>
                        <?php elseif ( $upgrade_url ) : ?>
                            <a href="<?php echo esc_url( $upgrade_url ); ?>" class="lcni-denied-btn lcni-denied-btn--primary">
                                <?php echo $state === 'expired' ? '🔄 Gia hạn ngay' : '⭐ Nâng cấp gói'; ?>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    private function denied_css() {
        return '<style>
.lcni-denied-block{--denied-border:#e5e7eb;--denied-bg:#f9fafb;--denied-title:#111827;--denied-desc:#6b7280;--denied-accent:#2563eb;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;margin:0;}
.lcni-denied--expired{--denied-border:#fed7aa;--denied-bg:#fff7ed;--denied-accent:#ea580c;}
.lcni-denied--upgrade{--denied-border:#c7d2fe;--denied-bg:#eef2ff;--denied-accent:#4f46e5;}
.lcni-denied-inner{display:flex;align-items:flex-start;gap:16px;padding:20px 24px;background:var(--denied-bg);border:1px solid var(--denied-border);border-left:4px solid var(--denied-accent);border-radius:10px;}
.lcni-denied-icon{font-size:28px;line-height:1;flex-shrink:0;margin-top:2px;}
.lcni-denied-content{flex:1;min-width:0;}
.lcni-denied-title{font-size:15px;font-weight:700;color:var(--denied-title);margin-bottom:4px;}
.lcni-denied-desc{font-size:13px;color:var(--denied-desc);margin-bottom:14px;line-height:1.5;}
.lcni-denied-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
.lcni-denied-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:8px;font-size:13px;font-weight:600;text-decoration:none;transition:opacity .15s,transform .1s;cursor:pointer;border:none;}
.lcni-denied-btn:hover{opacity:.85;transform:translateY(-1px);}
.lcni-denied-btn--primary{background:var(--denied-accent);color:#fff;}
.lcni-denied-btn--ghost{background:#fff;color:var(--denied-accent);border:1px solid var(--denied-accent);}
.lcni-denied-google-wrap{display:flex;align-items:center;}
.lcni-denied-google-wrap .g_id_signin iframe{border-radius:8px!important;}
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

    private function get_upgrade_url() {
        return (string) get_option( 'lcni_saas_upgrade_url', '' );
    }
}
