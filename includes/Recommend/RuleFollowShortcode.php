<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * RuleFollowShortcode  v2.0
 *
 * [lcni_rule_follow]
 *
 * Tab 1 — Danh sách Chiến lược: sparkline equity curve, nút Xem thống kê, Follow modal.
 * Tab 2 — Signal đang theo dõi: signals open của các rule user follow.
 */
class RuleFollowShortcode {

    private $follow_repo;

    public function __construct( RuleFollowRepository $follow_repo ) {
        $this->follow_repo = $follow_repo;
        add_shortcode( 'lcni_rule_follow', [ $this, 'render' ] );
    }

    public function render( $atts = [] ): string {
        $atts = shortcode_atts( [
            'show_description' => '1',
            'show_stats'       => '1',
            'default_tab'      => 'rules',
        ], $atts, 'lcni_rule_follow' );

        if ( ! is_user_logged_in() ) {
            return $this->render_login_gate();
        }

        $user_id     = get_current_user_id();
        $rules       = $this->follow_repo->get_rules_with_follow_status( $user_id );
        $rest_url    = esc_url_raw( rest_url( 'lcni/v1' ) );
        $ajax_url    = esc_url_raw( admin_url( 'admin-ajax.php' ) );
        $nonce       = wp_create_nonce( 'wp_rest' );
        $curve_nonce = wp_create_nonce( 'lcni_public_equity_curve' );
        $show_desc   = $atts['show_description'] === '1';
        $show_stats  = $atts['show_stats'] === '1';
        $default_tab = in_array( $atts['default_tab'], ['rules','signals'], true ) ? $atts['default_tab'] : 'rules';

        $perf_page_id  = absint( get_option( 'lcni_performance_page_id', 0 ) );
        $perf_page_url = $perf_page_id > 0 ? esc_url( get_permalink( $perf_page_id ) ) : '';

        $sig_page_id  = absint( get_option( 'lcni_signals_rule_page_id', 0 ) );
        $sig_page_url = $sig_page_id > 0 ? esc_url( get_permalink( $sig_page_id ) ) : '';

        // Enrich rules với performance data
        global $wpdb;
        if ( ! empty( $rules ) ) {
            $rids = array_map( 'intval', array_column( $rules, 'id' ) );
            $ph   = implode( ',', array_fill( 0, count( $rids ), '%d' ) );
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $perfs = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}lcni_recommend_performance WHERE rule_id IN ({$ph})",
                $rids
            ), ARRAY_A ) ?: [];
            $perf_map = array_column( $perfs, null, 'rule_id' );
            foreach ( $rules as &$rule ) { $rule['_perf'] = $perf_map[ (int)$rule['id'] ] ?? []; }
            unset( $rule );
        }

        // URL trang [lcni_user_rule] cho nút Tự động
        $ur_page_id  = absint( get_option( 'lcni_user_rule_page_id', 0 ) );
        $ur_page_url = $ur_page_id > 0 ? esc_url( get_permalink( $ur_page_id ) ) : '';

        $user_watchlists = $this->get_user_watchlists( $user_id );

        ob_start();
        ?>
        <div class="lcni-rf-wrap" id="lcni-rf-app"
             data-rest="<?php echo esc_attr( $rest_url ); ?>"
             data-ajax="<?php echo esc_attr( $ajax_url ); ?>"
             data-nonce="<?php echo esc_attr( $nonce ); ?>"
             data-curve-nonce="<?php echo esc_attr( $curve_nonce ); ?>"
             data-perf-url="<?php echo esc_attr( $perf_page_url ); ?>"
             data-default-tab="<?php echo esc_attr( $default_tab ); ?>"
             data-watchlist-rest="<?php echo esc_attr( esc_url_raw( rest_url( 'lcni/v1/watchlist' ) ) ); ?>"
             data-is-logged-in="1">

            <div class="lcni-rf-tabs">
                <button type="button" class="lcni-rf-tab <?php echo $default_tab === 'rules'   ? 'lcni-rf-tab--active' : ''; ?>" data-tab="rules">&#128203; Danh s&#225;ch Rule</button>
                <button type="button" class="lcni-rf-tab <?php echo $default_tab === 'signals' ? 'lcni-rf-tab--active' : ''; ?>" data-tab="signals">&#128225; Signal &#273;ang theo d&#245;i</button>
            </div>

            <!-- Tab: Rules -->
            <div class="lcni-rf-tab-pane <?php echo $default_tab === 'rules' ? 'lcni-rf-tab-pane--active' : ''; ?>" data-tab-pane="rules">
                <?php if ( empty( $rules ) ): ?>
                    <p class="lcni-rf-empty">Ch&#432;a c&#243; rule n&#224;o &#273;&#432;&#7907;c k&#237;ch ho&#7841;t.</p>
                <?php else: ?>
                <div class="lcni-rf-list">
                <?php foreach ( $rules as $rule ):
                    $rule_id        = (int) $rule['id'];
                    $is_following   = ! empty( $rule['is_following'] );
                    $notify_email   = ! empty( $rule['notify_email'] );
                    $notify_browser = ! empty( $rule['notify_browser'] );
                    $dyn_wl_id      = (int) ( $rule['dynamic_watchlist_id'] ?? 0 );
                    $followers      = (int) $this->follow_repo->count_followers( $rule_id );
                    $perf           = $rule['_perf'] ?? [];
                    $winrate        = isset( $perf['winrate'] )      ? round( (float) $perf['winrate'] * 100, 1 ) : null;
                    $avg_r          = isset( $perf['avg_r'] )        ? round( (float) $perf['avg_r'], 2 )         : null;
                    $expectancy     = isset( $perf['expectancy'] )   ? round( (float) $perf['expectancy'], 2 )    : null;
                    $profit_factor  = isset( $perf['profit_factor'] )? round( (float) $perf['profit_factor'], 2 ): null;
                    $total_trades   = isset( $perf['total_trades'] ) ? (int) $perf['total_trades']                : null;
                    $rr             = isset( $rule['risk_reward'] )  ? (float) $rule['risk_reward']               : null;
                    $sl_pct         = isset( $rule['initial_sl_pct'] ) ? (float) $rule['initial_sl_pct']          : null;
                    $max_loss       = isset( $rule['max_loss_pct'] ) ? (float) $rule['max_loss_pct']              : null;
                    $max_hold       = (int) ( $rule['max_hold_days'] ?? 0 );
                    // Parse entry conditions → tags
                    $ec     = json_decode( (string) ( $rule['entry_conditions'] ?? '{}' ), true );
                    $ctags  = [];
                    if ( is_array( $ec ) && ! empty( $ec['rules'] ) ) {
                        foreach ( $ec['rules'] as $c ) {
                            $f      = explode( '.', (string) ( $c['field'] ?? '' ) );
                            $col    = end( $f );
                            $ctags[] = $col . ' ' . ( $c['operator'] ?? '=' ) . ' ' . ( $c['value'] ?? '' );
                        }
                    }
                ?>
                <div class="lcni-rf-card <?php echo $is_following ? 'lcni-rf-card--following' : ''; ?>"
                     data-rule-id="<?php echo $rule_id; ?>"
                     data-notify-email="<?php echo $notify_email ? '1' : '0'; ?>"
                     data-notify-browser="<?php echo $notify_browser ? '1' : '0'; ?>"
                     data-dynamic-wl="<?php echo $dyn_wl_id; ?>">

                    <!-- Header: tên + badges + actions -->
                    <div class="lcni-rf-card-hd">
                        <div class="lcni-rf-card-hd-left">
                            <h3 class="lcni-rf-rule-name"><?php echo esc_html( $rule['name'] ); ?></h3>
                            <div class="lcni-rf-card-badges">
                                <span class="lcni-rf-badge-tf"><?php echo esc_html( strtoupper( $rule['timeframe'] ?? '1D' ) ); ?></span>
                                <?php if ( $is_following ): ?><span class="lcni-rf-badge-following">&#9989; Đang theo dõi</span><?php endif; ?>
                            </div>
                        </div>
                        <div class="lcni-rf-card-hd-right">
                            <?php if ( $sig_page_url ): ?>
                            <a class="lcni-btn lcni-btn-btn_rf_signal" href="<?php echo esc_url( $sig_page_url . '?rule_id=' . $rule_id ); ?>" title="Tín hiệu"><?php echo LCNI_Button_Style_Config::build_button_content( 'btn_rf_signal', '📋' ); ?></a>
                            <?php endif; ?>
                            <?php if ( $perf_page_url ): ?>
                            <a class="lcni-btn lcni-btn-btn_rf_performance" href="<?php echo esc_url( $perf_page_url . '?rule_id=' . $rule_id ); ?>" title="Hiệu suất"><?php echo LCNI_Button_Style_Config::build_button_content( 'btn_rf_performance', '📊' ); ?></a>
                            <?php endif; ?>
                            <?php if ( $ur_page_url ): ?>
                            <a class="lcni-btn lcni-btn-btn_rab_auto"
                               href="<?php echo esc_url( add_query_arg( [ 'ur_new' => '1', 'rule_id' => $rule_id ], $ur_page_url ) ); ?>">
                                <?php echo LCNI_Button_Style_Config::build_button_content( 'btn_rab_auto', '⚙ Tự động' ); ?>
                            </a>
                            <?php endif; ?>
                            <button type="button"
                                    class="lcni-btn lcni-btn-btn_rf_follow lcni-rf-follow-btn <?php echo $is_following ? 'lcni-btn-btn_rf_following lcni-rf-follow-btn--active' : ''; ?>"
                                    data-rule-id="<?php echo $rule_id; ?>"
                                    data-following="<?php echo $is_following ? '1' : '0'; ?>"
                                    data-rule-name="<?php echo esc_attr( $rule['name'] ); ?>">
                                <?php echo $is_following
                                    ? LCNI_Button_Style_Config::build_button_content( 'btn_rf_following', '✅ Đang theo dõi' )
                                    : LCNI_Button_Style_Config::build_button_content( 'btn_rf_follow', '🔔 Theo dõi' ); ?>
                            </button>
                        </div>
                    </div>

                    <?php if ( $show_desc && ! empty( $rule['description'] ) ): ?>
                    <p class="lcni-rf-description"><?php echo esc_html( $rule['description'] ); ?></p>
                    <?php endif; ?>

                    <!-- Body: sparkline + perf grid -->
                    <div class="lcni-rf-card-body">
                        <div class="lcni-rf-sparkline-wrap">
                            <canvas class="lcni-rf-sparkline" data-rule-id="<?php echo $rule_id; ?>"></canvas>
                            <span class="lcni-rf-sparkline-loading">&#8230;</span>
                        </div>
                        <?php if ( $show_stats && ( $winrate !== null || $avg_r !== null || $total_trades !== null ) ): ?>
                        <div class="lcni-rf-perf-grid">
                            <?php if ( $winrate !== null ): ?>
                            <div class="lcni-rf-perf-cell">
                                <span class="lcni-rf-perf-lbl">Winrate</span>
                                <span class="lcni-rf-perf-val <?php echo $winrate >= 50 ? 'lcni-rf-green' : 'lcni-rf-red'; ?>"><?php echo $winrate; ?>%</span>
                            </div>
                            <?php endif; ?>
                            <?php if ( $avg_r !== null ): ?>
                            <div class="lcni-rf-perf-cell">
                                <span class="lcni-rf-perf-lbl">Avg R</span>
                                <span class="lcni-rf-perf-val <?php echo $avg_r >= 0 ? 'lcni-rf-green' : 'lcni-rf-red'; ?>"><?php echo ( $avg_r >= 0 ? '+' : '' ) . $avg_r; ?>R</span>
                            </div>
                            <?php endif; ?>
                            <?php if ( $expectancy !== null ): ?>
                            <div class="lcni-rf-perf-cell">
                                <span class="lcni-rf-perf-lbl">Expectancy</span>
                                <span class="lcni-rf-perf-val <?php echo $expectancy >= 0 ? 'lcni-rf-green' : 'lcni-rf-red'; ?>"><?php echo ( $expectancy >= 0 ? '+' : '' ) . $expectancy; ?>R</span>
                            </div>
                            <?php endif; ?>
                            <?php if ( $profit_factor !== null && $profit_factor > 0 ): ?>
                            <div class="lcni-rf-perf-cell">
                                <span class="lcni-rf-perf-lbl">Profit Factor</span>
                                <span class="lcni-rf-perf-val <?php echo $profit_factor >= 1.5 ? 'lcni-rf-green' : ''; ?>"><?php echo $profit_factor; ?>x</span>
                            </div>
                            <?php endif; ?>
                            <?php if ( $rr !== null ): ?>
                            <div class="lcni-rf-perf-cell">
                                <span class="lcni-rf-perf-lbl">R:R</span>
                                <span class="lcni-rf-perf-val"><?php echo number_format( $rr, 1 ); ?></span>
                            </div>
                            <?php endif; ?>
                            <?php if ( $total_trades !== null ): ?>
                            <div class="lcni-rf-perf-cell">
                                <span class="lcni-rf-perf-lbl">Lệnh đóng</span>
                                <span class="lcni-rf-perf-val"><?php echo $total_trades; ?></span>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Rule params + entry conditions -->
                    <div class="lcni-rf-card-ft">
                        <div class="lcni-rf-param-row">
                            <?php if ( $sl_pct !== null ): ?><span class="lcni-rf-param-tag">SL <?php echo $sl_pct; ?>%</span><?php endif; ?>
                            <?php if ( $max_loss !== null && $max_loss != $sl_pct ): ?><span class="lcni-rf-param-tag">MaxLoss <?php echo $max_loss; ?>%</span><?php endif; ?>
                            <?php if ( $rr !== null ): ?><span class="lcni-rf-param-tag">R:R <?php echo number_format($rr,1); ?></span><?php endif; ?>
                            <?php if ( $max_hold > 0 ): ?><span class="lcni-rf-param-tag">Hold ≤<?php echo $max_hold; ?>ng</span><?php endif; ?>
                            <span class="lcni-rf-follower-wrap">&#128101; <span class="lcni-rf-follower-count" data-rule-id="<?php echo $rule_id; ?>"><?php echo $followers; ?></span></span>
                        </div>
                        <?php if ( ! empty( $ctags ) ): ?>
                        <div class="lcni-rf-cond-row">
                            <span class="lcni-rf-cond-lbl">Điều kiện:</span>
                            <?php foreach ( $ctags as $tag ): ?>
                            <span class="lcni-rf-cond-tag"><?php echo esc_html( $tag ); ?></span>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>

                </div>
                <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- Tab: Signals -->
            <div class="lcni-rf-tab-pane <?php echo $default_tab === 'signals' ? 'lcni-rf-tab-pane--active' : ''; ?>" data-tab-pane="signals">
                <div class="lcni-rf-signals-container">
                    <div class="lcni-rf-signals-loading">&#272;ang t&#7843;i signals...</div>
                </div>
            </div>

            <div id="lcni-rf-toast" class="lcni-rf-toast" aria-live="polite"></div>
        </div>

        <!-- Follow Modal -->
        <div id="lcni-rf-follow-modal" class="lcni-rf-modal-backdrop" style="display:none;" aria-modal="true" role="dialog">
            <div class="lcni-rf-modal-box">
                <div class="lcni-rf-modal-header">
                    <h3 class="lcni-rf-modal-title">&#128276; Theo d&#245;i <span id="lcni-rf-modal-rule-name"></span></h3>
                    <button type="button" class="lcni-rf-modal-close" id="lcni-rf-modal-close">&#10005;</button>
                </div>
                <div class="lcni-rf-modal-body">
                    <p class="lcni-rf-modal-subtitle">Ch&#7885;n c&#225;ch nh&#7853;n th&#244;ng b&#225;o khi c&#243; t&#237;n hi&#7879;u m&#7899;i:</p>

                    <label class="lcni-rf-option-row">
                        <input type="checkbox" id="lcni-rf-opt-email" checked>
                        <div class="lcni-rf-option-info">
                            <span class="lcni-rf-option-icon">&#128231;</span>
                            <div><strong>Th&#244;ng b&#225;o Email</strong><p>Nh&#7853;n email khi chi&#7871;n l&#432;&#7907;c t&#7841;o t&#237;n hi&#7879;u m&#7899;i.</p></div>
                        </div>
                    </label>

                    <label class="lcni-rf-option-row">
                        <input type="checkbox" id="lcni-rf-opt-browser">
                        <div class="lcni-rf-option-info">
                            <span class="lcni-rf-option-icon">&#128276;</span>
                            <div><strong>Th&#244;ng b&#225;o tr&#236;nh duy&#7879;t</strong><p>Popup ngay tr&#234;n tr&#236;nh duy&#7879;t (c&#7847;n c&#7845;p quy&#7873;n).</p></div>
                        </div>
                    </label>

                    <label class="lcni-rf-option-row">
                        <input type="checkbox" id="lcni-rf-opt-watchlist">
                        <div class="lcni-rf-option-info">
                            <span class="lcni-rf-option-icon">&#128203;</span>
                            <div><strong>T&#7841;o Watchlist &#273;&#7897;ng theo Chi&#7871;n l&#432;&#7907;c</strong><p>T&#7921; &#273;&#7897;ng th&#234;m m&#227; v&#224;o watchlist khi c&#243; t&#237;n hi&#7879;u m&#7899;i.</p></div>
                        </div>
                    </label>

                    <div id="lcni-rf-watchlist-opts" class="lcni-rf-sub-opts" style="display:none;">
                        <div class="lcni-rf-sub-opt-row">
                            <label><input type="radio" name="lcni_wl_mode" value="create" checked> T&#7841;o watchlist m&#7899;i t&#7921; &#273;&#7897;ng</label>
                            <input type="text" id="lcni-rf-wl-name" class="lcni-rf-input" placeholder="T&#234;n watchlist (&#273;&#7875; tr&#7889;ng = t&#7921; sinh)">
                        </div>
                        <?php if ( ! empty( $user_watchlists ) ): ?>
                        <div class="lcni-rf-sub-opt-row">
                            <label><input type="radio" name="lcni_wl_mode" value="existing"> Th&#234;m v&#224;o watchlist c&#243; s&#7861;n</label>
                            <select id="lcni-rf-wl-existing" class="lcni-rf-select">
                                <?php foreach ( $user_watchlists as $wl ): ?>
                                <option value="<?php echo (int) $wl['id']; ?>"><?php echo esc_html( $wl['name'] ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="lcni-rf-modal-footer">
                    <button type="button" class="lcni-rf-btn lcni-rf-btn--ghost" id="lcni-rf-modal-cancel">Hu&#7927;</button>
                    <button type="button" class="lcni-rf-btn lcni-rf-btn--primary" id="lcni-rf-modal-confirm">&#9989; X&#225;c nh&#7853;n Theo d&#245;i</button>
                </div>
            </div>
        </div>

        <?php $this->render_styles(); ?>
        <?php $this->render_script( $rules ); ?>
        <?php
        return ob_get_clean();
    }

    /**
     * Hiển thị inline login form khi user chưa đăng nhập.
     *
     * Ưu tiên:
     *  1. Shortcode [lcni_member_login] với redirect_to = trang hiện tại
     *  2. Form WordPress mặc định (wp_login_form) nếu không có shortcode
     *  3. Fallback: link đến trang login
     */
    private function render_login_gate(): string {
        // URL hiện tại để redirect về sau khi đăng nhập
        $current_url = ( is_ssl() ? 'https' : 'http' ) . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
        $redirect_to = esc_url_raw( $current_url );

        // Thử dùng shortcode [lcni_member_login] trước
        if ( shortcode_exists( 'lcni_member_login' ) ) {
            // Inject lcni_redirect_to vào $_GET để resolve_redirect_target() nhận được
            $prev = $_GET['lcni_redirect_to'] ?? null;
            $_GET['lcni_redirect_to'] = $redirect_to;

            $login_html = do_shortcode( '[lcni_member_login]' );

            // Phục hồi $_GET
            if ( $prev === null ) {
                unset( $_GET['lcni_redirect_to'] );
            } else {
                $_GET['lcni_redirect_to'] = $prev;
            }

            return '<div class="lcni-rf-login-gate">' . $login_html . '</div>';
        }

        // Fallback: WordPress default login form
        ob_start();
        ?>
        <div class="lcni-rf-login-gate lcni-rf-login-gate--wp">
            <div class="lcni-rf-login-box">
                <div class="lcni-rf-login-icon">🔒</div>
                <h3 class="lcni-rf-login-title">Đăng nhập để theo dõi tín hiệu</h3>
                <p class="lcni-rf-login-desc">Tính năng Theo dõi Chiến lược yêu cầu tài khoản. Đăng nhập để tiếp tục.</p>
                <?php
                wp_login_form( [
                    'redirect'       => $redirect_to,
                    'form_id'        => 'lcni-rf-wp-login-form',
                    'label_username' => 'Email / Tên đăng nhập',
                    'label_password' => 'Mật khẩu',
                    'label_remember' => 'Nhớ đăng nhập',
                    'label_log_in'   => '🔑 Đăng nhập',
                    'value_remember' => true,
                ] );
                ?>
                <?php
                $login_url = get_option('lcni_central_login_url', '');
                if ( ! $login_url ) {
                    $ls = get_option('lcni_member_login_settings', []);
                    $pid = absint($ls['login_page_id'] ?? 0);
                    if ($pid) $login_url = get_permalink($pid);
                }
                if ( $login_url ): ?>
                    <p style="margin-top:12px;text-align:center;font-size:13px;color:#6b7280;">
                        Hoặc <a href="<?php echo esc_url( add_query_arg('lcni_redirect_to', rawurlencode($redirect_to), $login_url) ); ?>">mở trang đăng nhập đầy đủ →</a>
                    </p>
                <?php endif; ?>
            </div>
        </div>
        <style>
        .lcni-rf-login-gate{max-width:480px;margin:24px auto;}
        .lcni-rf-login-gate--wp .lcni-rf-login-box{
            background:#fff;border:1px solid #e5e7eb;border-radius:12px;
            padding:28px 32px;text-align:center;
        }
        .lcni-rf-login-icon{font-size:40px;margin-bottom:12px;}
        .lcni-rf-login-title{margin:0 0 8px;font-size:18px;font-weight:700;color:#111827;}
        .lcni-rf-login-desc{margin:0 0 20px;font-size:14px;color:#6b7280;}
        .lcni-rf-login-gate--wp #lcni-rf-wp-login-form{text-align:left;}
        .lcni-rf-login-gate--wp #lcni-rf-wp-login-form .login-username label,
        .lcni-rf-login-gate--wp #lcni-rf-wp-login-form .login-password label{
            font-size:13px;font-weight:600;color:#374151;
        }
        .lcni-rf-login-gate--wp #lcni-rf-wp-login-form input[type=text],
        .lcni-rf-login-gate--wp #lcni-rf-wp-login-form input[type=password]{
            width:100%;padding:8px 12px;border:1px solid #d1d5db;border-radius:7px;
            font-size:14px;box-sizing:border-box;margin-top:4px;
        }
        .lcni-rf-login-gate--wp #lcni-rf-wp-login-form input[type=submit]{
            width:100%;padding:10px;background:#2563eb;color:#fff;border:none;
            border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;margin-top:12px;
        }
        .lcni-rf-login-gate--wp #lcni-rf-wp-login-form input[type=submit]:hover{background:#1d4ed8;}
        </style>
        <?php
        return ob_get_clean();
    }

    private function get_user_watchlists( int $user_id ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'lcni_watchlists';
        if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) return [];
        return $wpdb->get_results(
            $wpdb->prepare( "SELECT id, name FROM {$table} WHERE user_id = %d ORDER BY id ASC", $user_id ),
            ARRAY_A
        ) ?: [];
    }

    private function render_styles(): void {
        // Button Style Config CSS + FontAwesome — inject inline vì shortcode chạy sau wp_head.
        $fa_url  = 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css';
        $btn_css = LCNI_Button_Style_Config::get_inline_css();
        echo '<link rel="stylesheet" href="' . esc_url( $fa_url ) . '">' . "\n";
        if ( $btn_css !== '' ) {
            echo '<style id="lcni-rf-btn-style">' . $btn_css . '</style>' . "\n";
        }
        echo '<style>
.lcni-rf-wrap{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;max-width:960px;margin:0 auto;padding:0 0 40px}
.lcni-rf-notice,.lcni-rf-empty{color:#6b7280;font-size:14px;padding:24px;background:#f9fafb;border-radius:8px;text-align:center;margin:16px 0}
.lcni-rf-tabs{display:flex;border-bottom:2px solid #e5e7eb;margin-bottom:24px}
.lcni-rf-tab{padding:10px 20px;background:none;border:none;border-bottom:2px solid transparent;margin-bottom:-2px;font-size:14px;font-weight:600;color:#6b7280;cursor:pointer;transition:all .15s;white-space:nowrap}
.lcni-rf-tab:hover{color:#2563eb}
.lcni-rf-tab--active{color:#2563eb;border-bottom-color:#2563eb}
.lcni-rf-tab-pane{display:none}
.lcni-rf-tab-pane--active{display:block}
.lcni-rf-list{display:flex;flex-direction:column;gap:12px}
.lcni-rf-card{background:#fff;border:1.5px solid #e5e7eb;border-radius:14px;overflow:hidden;transition:border-color .2s,box-shadow .2s}
.lcni-rf-card:hover{border-color:#bfdbfe;box-shadow:0 4px 16px rgba(37,99,235,.08)}
.lcni-rf-card--following{border-color:#2563eb;background:#f0f7ff}
.lcni-rf-card-top{display:flex;align-items:flex-start;justify-content:space-between;gap:8px;margin-bottom:10px}
.lcni-rf-card-info{flex:1;min-width:0}
.lcni-rf-rule-name{margin:0;font-size:17px;font-weight:700;color:#111827}
.lcni-rf-description{color:#6b7280;font-size:12px;line-height:1.5;margin:0 0 10px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.lcni-rf-card-actions{display:flex;align-items:center;gap:6px;flex-shrink:0}
/* btn_rf_follow / btn_rf_following / btn_rf_signal / btn_rf_performance — structural only, colors from LCNI_Button_Style_Config */
.lcni-rf-follow-btn{white-space:nowrap;transition:all .15s;font-weight:600}
.lcni-rf-follow-btn:disabled{opacity:.5;cursor:not-allowed}
.lcni-rf-sparkline-wrap{position:relative;height:56px;margin:8px 0;background:#f9fafb;border-radius:6px;overflow:hidden;box-sizing:border-box}
.lcni-rf-sparkline{display:block;width:100% !important;height:100% !important;max-width:100%}
.lcni-rf-sparkline-loading{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-size:11px;color:#9ca3af;pointer-events:none}
.lcni-rf-stats{display:flex;gap:14px;flex-wrap:wrap;margin-top:10px;padding-top:10px;border-top:1px solid #f3f4f6}
.lcni-rf-green{color:#16a34a!important}
.lcni-rf-red{color:#dc2626!important}
.lcni-rf-signals-container{padding:4px 0}
.lcni-rf-signals-loading{padding:32px;text-align:center;color:#9ca3af;font-size:14px}
.lcni-rf-signals-table-wrap{overflow:auto;max-height:var(--lcni-table-max-height,60vh);-webkit-overflow-scrolling:touch;touch-action:pan-x pan-y;overscroll-behavior:contain;border-radius:8px;border:1px solid #e5e7eb;position:relative}
.lcni-rf-signals-table{width:100%;border-collapse:separate;border-spacing:0;font-size:var(--lcni-table-value-size,13px)}
.lcni-rf-signals-table th{height:var(--lcni-table-header-height,42px);padding:0 12px;background:var(--lcni-table-header-bg,#f9fafb);font-weight:600;color:var(--lcni-table-header-color,#374151);text-align:left;border-bottom:var(--lcni-row-divider-width,1px) solid var(--lcni-row-divider-color,#e5e7eb);white-space:nowrap}
.lcni-rf-signals-table td{height:var(--lcni-table-row-height,36px);padding:0 12px;border-bottom:var(--lcni-row-divider-width,1px) solid var(--lcni-row-divider-color,#f3f4f6);white-space:nowrap;background:var(--lcni-table-value-bg,#fff);color:var(--lcni-table-value-color,#374151)}
.lcni-rf-signals-table tr:last-child td{border-bottom:0}
.lcni-rf-signals-table tr:hover td{background:var(--lcni-row-hover-bg,#f9fafb) !important}
.lcni-rf-signals-table.has-sticky-header thead th{position:-webkit-sticky;position:sticky;top:0;z-index:20;background:var(--lcni-table-header-bg,#f9fafb);box-shadow:inset 0 -1px 0 var(--lcni-row-divider-color,#e5e7eb)}
.lcni-rf-signals-table.has-sticky-header th.is-sticky-col{z-index:25}
.lcni-rf-signals-table th.is-sticky-col,.lcni-rf-signals-table td.is-sticky-col{position:-webkit-sticky;position:sticky;left:0;z-index:15;background:var(--lcni-table-value-bg,#fff);box-shadow:2px 0 4px -1px rgba(0,0,0,.06)}
.lcni-rf-signals-table th.is-sticky-col{background:var(--lcni-table-header-bg,#f9fafb);z-index:25}
.lcni-rf-signals-table tr:hover td.is-sticky-col{background:var(--lcni-row-hover-bg,#f9fafb) !important}
.lcni-rf-price-click{cursor:pointer;color:#2563eb;font-weight:600;border-bottom:1px dashed #93c5fd;transition:color .12s}
.lcni-rf-price-click:hover{color:#1d4ed8;border-bottom-color:#2563eb}
.lcni-rf-th-hint{font-size:10px;font-weight:400;color:#9ca3af;margin-left:3px}
.lcni-rf-signal-symbol-cell{display:flex;align-items:center;gap:6px}
.lcni-rf-signal-symbol{font-weight:700;color:var(--lcni-table-value-color,#111827)}
.lcni-rf-signal-pos{color:#16a34a;font-weight:600}
.lcni-rf-signal-neg{color:#dc2626;font-weight:600}
.lcni-rf-signals-empty{padding:32px;text-align:center;color:#9ca3af;font-size:14px}
.lcni-rf-modal-backdrop{position:fixed;inset:0;background:rgba(17,24,39,.5);z-index:100000;display:flex;align-items:center;justify-content:center;padding:16px}
.lcni-rf-modal-box{background:#fff;border-radius:14px;width:100%;max-width:480px;box-shadow:0 20px 60px rgba(0,0,0,.2);overflow:hidden}
.lcni-rf-modal-header{display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid #e5e7eb;gap:12px}
.lcni-rf-modal-title{margin:0;font-size:16px;font-weight:700;color:#111827;flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.lcni-rf-modal-close{background:none;border:none;font-size:18px;cursor:pointer;color:#9ca3af;padding:4px;line-height:1;border-radius:4px}
.lcni-rf-modal-close:hover{color:#374151;background:#f3f4f6}
.lcni-rf-modal-body{padding:20px;max-height:60vh;overflow-y:auto}
.lcni-rf-modal-subtitle{font-size:13px;color:#6b7280;margin:0 0 14px}
.lcni-rf-modal-footer{display:flex;justify-content:flex-end;gap:10px;padding:12px 20px;border-top:1px solid #e5e7eb;background:#f9fafb}
.lcni-rf-option-row{display:flex;align-items:flex-start;gap:12px;padding:12px;border:1.5px solid #e5e7eb;border-radius:10px;margin-bottom:10px;cursor:pointer;transition:border-color .15s}
.lcni-rf-option-row:hover{border-color:#bfdbfe}
.lcni-rf-option-row:has(input:checked){border-color:#2563eb;background:#eff6ff}
.lcni-rf-option-row input[type="checkbox"]{margin-top:2px;width:16px;height:16px;accent-color:#2563eb;flex-shrink:0;cursor:pointer}
.lcni-rf-option-info{display:flex;gap:10px;align-items:flex-start;flex:1;pointer-events:none}
.lcni-rf-option-icon{font-size:20px;line-height:1;flex-shrink:0}
.lcni-rf-option-info strong{display:block;font-size:14px;color:#111827;margin-bottom:2px}
.lcni-rf-option-info p{font-size:12px;color:#6b7280;margin:0}
.lcni-rf-sub-opts{margin:0 0 10px 40px;padding:12px;background:#f9fafb;border-radius:8px;border:1px solid #e5e7eb}
.lcni-rf-sub-opt-row{display:flex;flex-direction:column;gap:6px;margin-bottom:10px;font-size:13px;color:#374151}
.lcni-rf-sub-opt-row:last-child{margin-bottom:0}
.lcni-rf-sub-opt-row label{display:flex;align-items:center;gap:6px;cursor:pointer}
.lcni-rf-input,.lcni-rf-select{width:100%;padding:6px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;margin-top:4px}
.lcni-rf-btn{padding:8px 18px;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;border:none;transition:all .15s}
.lcni-rf-btn--primary{background:#2563eb;color:#fff}
.lcni-rf-btn--primary:hover{background:#1d4ed8}
.lcni-rf-btn--ghost{background:#f3f4f6;color:#374151}
.lcni-rf-btn--ghost:hover{background:#e5e7eb}
.lcni-rf-btn:disabled{opacity:.5;cursor:not-allowed}
.lcni-rf-toast{position:fixed;bottom:24px;right:24px;padding:12px 20px;border-radius:8px;font-size:14px;font-weight:500;background:#111827;color:#fff;z-index:99999;opacity:0;transform:translateY(8px);transition:opacity .25s,transform .25s;pointer-events:none;max-width:320px}
.lcni-rf-toast--show{opacity:1;transform:translateY(0)}
.lcni-rf-toast--success{background:#166534}
.lcni-rf-toast--error{background:#991b1b}
@media(max-width:600px){
    .lcni-rf-modal-backdrop{align-items:center;justify-content:center;padding:16px}
    .lcni-rf-modal-box{border-radius:16px;max-width:100%;width:calc(100% - 0px);margin:0 auto;max-height:90vh;overflow-y:auto}
    .lcni-rf-modal-header{padding:14px 16px;justify-content:space-between}
    .lcni-rf-modal-title{font-size:15px;white-space:normal;text-align:left}
    .lcni-rf-modal-body{padding:16px;max-height:none}
    .lcni-rf-modal-footer{padding:12px 16px 16px;gap:8px;padding-bottom:max(16px, env(safe-area-inset-bottom, 16px))}
    .lcni-rf-option-row{padding:10px 12px;margin-bottom:8px}
    .lcni-rf-option-icon{font-size:18px}
    .lcni-rf-option-info strong{font-size:13px}
    .lcni-rf-option-info p{font-size:11px}
    .lcni-rf-modal-footer .lcni-rf-btn{flex:1;text-align:center}
    .lcni-rf-toast{left:16px;right:16px;bottom:16px}
}
/* ── Full-width card ── */
.lcni-rf-card-hd{display:flex;align-items:flex-start;justify-content:space-between;gap:10px;padding:14px 16px 8px;flex-wrap:wrap}
.lcni-rf-card-hd-left{flex:1;min-width:0}
.lcni-rf-card-hd-right{display:flex;align-items:center;gap:6px;flex-shrink:0;flex-wrap:wrap}
.lcni-rf-card-badges{display:flex;gap:6px;flex-wrap:wrap;margin-top:5px}
.lcni-rf-badge-tf{font-size:11px;font-weight:700;padding:2px 7px;border-radius:4px;background:#f3f4f6;color:#374151}
.lcni-rf-badge-following{font-size:11px;font-weight:700;padding:2px 7px;border-radius:4px;background:#dbeafe;color:#1d4ed8}
.lcni-rf-description{color:#6b7280;font-size:13px;line-height:1.5;margin:0 16px 8px}
.lcni-rf-card-body{display:flex;gap:12px;padding:0 16px 10px;align-items:flex-start;flex-wrap:wrap}
.lcni-rf-sparkline-wrap{flex:1;min-width:160px;position:relative;height:68px;background:#f9fafb;border-radius:8px;overflow:hidden}
.lcni-rf-perf-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:6px;flex:1;min-width:200px}
.lcni-rf-perf-cell{background:#f9fafb;border-radius:8px;padding:7px 10px;display:flex;flex-direction:column;gap:2px}
.lcni-rf-perf-lbl{font-size:10px;color:#9ca3af;font-weight:600;text-transform:uppercase;letter-spacing:.3px}
.lcni-rf-perf-val{font-size:14px;font-weight:700;color:#111827}
.lcni-rf-card-ft{padding:8px 16px 12px;border-top:1px solid #f3f4f6}
.lcni-rf-param-row{display:flex;flex-wrap:wrap;gap:5px;align-items:center;margin-bottom:5px}
.lcni-rf-param-tag{font-size:11px;background:#f3f4f6;color:#374151;border-radius:4px;padding:2px 7px;font-family:monospace}
.lcni-rf-follower-wrap{font-size:12px;color:#9ca3af;margin-left:4px}
.lcni-rf-cond-row{display:flex;flex-wrap:wrap;gap:5px;align-items:center}
.lcni-rf-cond-lbl{font-size:11px;color:#6b7280;font-weight:600;flex-shrink:0}
.lcni-rf-cond-tag{font-size:11px;background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;border-radius:4px;padding:2px 7px;font-family:monospace}
@media(max-width:600px){
    .lcni-rf-card-body{flex-direction:column}
    .lcni-rf-perf-grid{grid-template-columns:repeat(2,1fr)}
    .lcni-rf-card-hd-right{width:100%}
}
</style>';
    }

    private function render_script( array $rules ): void {
        $rules_json         = wp_json_encode( array_map( function( $r ) {
            return [ 'id' => (int) $r['id'], 'name' => $r['name'], 'is_following' => (bool) $r['is_following'] ];
        }, $rules ) );
        $btn_rf_json        = wp_json_encode( LCNI_Button_Style_Config::get_button( 'btn_rule_follow_add' ) );
        $btn_rf_follow_json = wp_json_encode( LCNI_Button_Style_Config::get_button( 'btn_rf_follow' ) );
        $btn_rf_follow_html = LCNI_Button_Style_Config::build_button_content( 'btn_rf_follow', '🔔 Theo dõi' );
        $btn_rf_following_html = LCNI_Button_Style_Config::build_button_content( 'btn_rf_following', '✅ Đang theo dõi' );
        ?>
<script>
(function(){
"use strict";
var app = document.getElementById("lcni-rf-app");
if (!app) return;
var REST       = app.dataset.rest || "";
var AJAX       = app.dataset.ajax || "";
var NONCE      = app.dataset.nonce || "";
var CNONCE     = app.dataset.curveNonce || "";
var PERF_URL   = app.dataset.perfUrl || "";
var RULES              = <?php echo $rules_json; ?>;
var RF_STICKY_HEADER = <?php echo class_exists('LCNI_Table_Config') && LCNI_Table_Config::sticky_header()   ? 'true' : 'false'; ?>;
var RF_STICKY_COL   = <?php echo class_exists('LCNI_Table_Config') && LCNI_Table_Config::sticky_first_col() ? 'true' : 'false'; ?>;
    var BTN_RF_CFG         = <?php echo $btn_rf_json; ?>;
var BTN_RF_FOLLOW_HTML = <?php echo wp_json_encode( $btn_rf_follow_html ); ?>;
var BTN_RF_FOLLOWING_HTML = <?php echo wp_json_encode( $btn_rf_following_html ); ?>;
var toast      = document.getElementById("lcni-rf-toast");
var modal      = document.getElementById("lcni-rf-follow-modal");
var pendingId  = 0, pendingName = "";

/* tabs */
app.querySelectorAll(".lcni-rf-tab").forEach(function(btn){
    btn.addEventListener("click", function(){
        var t = this.dataset.tab;
        app.querySelectorAll(".lcni-rf-tab").forEach(function(b){ b.classList.toggle("lcni-rf-tab--active", b.dataset.tab===t); });
        app.querySelectorAll(".lcni-rf-tab-pane").forEach(function(p){ p.classList.toggle("lcni-rf-tab-pane--active", p.dataset.tabPane===t); });
        if (t==="signals"){ signalsLoaded=false; loadSignals(); }
    });
});
if (app.dataset.defaultTab==="signals") loadSignals();

/* toast */
function showToast(msg,type){
    if(!toast)return;
    toast.textContent=msg;
    toast.className="lcni-rf-toast lcni-rf-toast--"+(type||"success");
    requestAnimationFrame(function(){ toast.classList.add("lcni-rf-toast--show"); });
    setTimeout(function(){ toast.classList.remove("lcni-rf-toast--show"); },3400);
}

/* api */
function apiPost(path,body){
    return fetch(REST+path,{method:"POST",headers:{"Content-Type":"application/json","X-WP-Nonce":NONCE},body:JSON.stringify(body||{})}).then(function(r){return r.json();});
}

/* sparkline */
function drawSparkline(canvas, pts){
    var wrap=canvas.parentElement;
    // getBoundingClientRect cho width chính xác sau layout (kể cả trong grid)
    var rect=wrap.getBoundingClientRect();
    var w=Math.round(rect.width)||wrap.offsetWidth||260, h=56, dpr=window.devicePixelRatio||1;
    if(w<10) w=260; // fallback nếu chưa visible
    canvas.width=w*dpr; canvas.height=h*dpr;
    canvas.style.width=w+"px"; canvas.style.height=h+"px";
    var ctx=canvas.getContext("2d");
    ctx.scale(dpr,dpr);
    if(!pts||pts.length<2){
        var ld=wrap.querySelector(".lcni-rf-sparkline-loading");
        if(ld) ld.textContent="Ch\u01B0a \u0111\u1EE7 d\u1EEF li\u1EC7u";
        return;
    }
    var vals=pts.map(function(p){ return parseFloat(p.cumulative_r||p.cum_r||p.cumR||0); });
    var mn=Math.min.apply(null,vals), mx=Math.max.apply(null,vals), rng=mx-mn||1;
    var pad=4, xStep=(w-pad*2)/(vals.length-1);
    var yS=function(v){ return h-pad-((v-mn)/rng)*(h-pad*2); };
    var lastV=vals[vals.length-1], lc=lastV>=0?"#16a34a":"#dc2626";
    var grad=ctx.createLinearGradient(0,0,0,h);
    grad.addColorStop(0,lastV>=0?"rgba(22,163,74,.18)":"rgba(220,38,38,.18)");
    grad.addColorStop(1,"rgba(255,255,255,0)");
    ctx.beginPath(); ctx.moveTo(pad,yS(vals[0]));
    for(var i=1;i<vals.length;i++) ctx.lineTo(pad+i*xStep,yS(vals[i]));
    ctx.lineTo(pad+(vals.length-1)*xStep,h); ctx.lineTo(pad,h); ctx.closePath();
    ctx.fillStyle=grad; ctx.fill();
    ctx.beginPath(); ctx.moveTo(pad,yS(vals[0]));
    for(var j=1;j<vals.length;j++) ctx.lineTo(pad+j*xStep,yS(vals[j]));
    ctx.strokeStyle=lc; ctx.lineWidth=1.8; ctx.lineJoin="round"; ctx.stroke();
    if(mn<0&&mx>0){
        var zy=yS(0); ctx.beginPath(); ctx.moveTo(pad,zy); ctx.lineTo(w-pad,zy);
        ctx.strokeStyle="rgba(107,114,128,.3)"; ctx.lineWidth=0.8; ctx.setLineDash([3,3]); ctx.stroke(); ctx.setLineDash([]);
    }
    var ld=wrap.querySelector(".lcni-rf-sparkline-loading");
    if(ld) ld.style.display="none";
}

function loadSparkline(canvas){
    if(!canvas.dataset.ruleId||canvas.dataset.loaded) return;
    canvas.dataset.loaded="1";
    var url=AJAX+"?action=lcni_public_equity_curve&rule_id="+encodeURIComponent(canvas.dataset.ruleId)+"&nonce="+encodeURIComponent(CNONCE);
    fetch(url).then(function(r){return r.json();}).then(function(res){
        if(res&&res.success&&res.data&&res.data.points&&res.data.points.length>1){
            canvas._pts=res.data.points;
            // double-rAF: đảm bảo grid layout ổn định trước khi đọc width
            requestAnimationFrame(function(){
                requestAnimationFrame(function(){
                    drawSparkline(canvas,canvas._pts);
                    // ResizeObserver: redraw khi container resize (responsive grid)
                    if(typeof ResizeObserver!=="undefined"&&!canvas._ro){
                        canvas._ro=new ResizeObserver(function(){
                            if(canvas._pts) drawSparkline(canvas,canvas._pts);
                        });
                        canvas._ro.observe(canvas.parentElement);
                    }
                });
            });
        } else {
            var ld=canvas.parentElement.querySelector(".lcni-rf-sparkline-loading");
            if(ld) ld.textContent="Ch\u01b0a c\u00f3 l\u1ecbch s\u1eed";
        }
    }).catch(function(){
        var ld=canvas.parentElement.querySelector(".lcni-rf-sparkline-loading");
        if(ld) ld.textContent="";
    });
}

if(typeof IntersectionObserver!=="undefined"){
    var io=new IntersectionObserver(function(entries){
        entries.forEach(function(e){ if(e.isIntersecting){ loadSparkline(e.target); io.unobserve(e.target); } });
    },{threshold:0.1});
    app.querySelectorAll(".lcni-rf-sparkline").forEach(function(c){ io.observe(c); });
} else {
    app.querySelectorAll(".lcni-rf-sparkline").forEach(loadSparkline);
}

/* follow btn click */
app.addEventListener("click",function(e){
    var btn=e.target.closest(".lcni-rf-follow-btn");
    if(!btn) return;
    var ruleId=btn.dataset.ruleId, card=app.querySelector(".lcni-rf-card[data-rule-id=\""+ruleId+"\"]");
    if(btn.dataset.following==="1"){
        btn.disabled=true;
        apiPost("/recommend/rules/"+ruleId+"/unfollow")
            .then(function(res){
                if(!res.success) throw new Error(res.message||"L\u1ED7i");
                btn.dataset.following="0"; btn.innerHTML=BTN_RF_FOLLOW_HTML; btn.classList.remove("lcni-rf-follow-btn--active","lcni-btn-btn_rf_following"); btn.classList.add("lcni-btn-btn_rf_follow");
                if(card) card.classList.remove("lcni-rf-card--following");
                updateCount(ruleId,res.follower_count||0);
                showToast("\u0110\u00E3 b\u1ECF theo d\u00F5i chi\u1EBFn l\u01B0\u1EE3c","success");
            })
            .catch(function(err){ showToast(err.message||"L\u1ED7i k\u1EBFt n\u1ED1i","error"); })
            .finally(function(){ btn.disabled=false; });
    } else {
        pendingId=ruleId; pendingName=btn.dataset.ruleName||("Chiến lược #"+ruleId);
        openModal(card);
    }
});

/* modal */
function openModal(card){
    var nameEl=document.getElementById("lcni-rf-modal-rule-name");
    if(nameEl) nameEl.textContent=pendingName;
    if(card){
        var oe=document.getElementById("lcni-rf-opt-email"), ob=document.getElementById("lcni-rf-opt-browser"), ow=document.getElementById("lcni-rf-opt-watchlist");
        if(oe) oe.checked=card.dataset.notifyEmail!=="0";
        if(ob) ob.checked=card.dataset.notifyBrowser==="1";
        if(ow) ow.checked=parseInt(card.dataset.dynamicWl||"0")>0;
        syncWlSub();
    }
    if(modal){ modal.style.display="flex"; document.body.style.overflow="hidden"; }
}
function closeModal(){ if(modal) modal.style.display="none"; document.body.style.overflow=""; pendingId=0; pendingName=""; }

if(modal) modal.addEventListener("click",function(e){ if(e.target===modal) closeModal(); });
var cBtn=document.getElementById("lcni-rf-modal-close"); if(cBtn) cBtn.addEventListener("click",closeModal);
var kBtn=document.getElementById("lcni-rf-modal-cancel"); if(kBtn) kBtn.addEventListener("click",closeModal);
document.addEventListener("keydown",function(e){ if(e.key==="Escape"&&modal&&modal.style.display!=="none") closeModal(); });

var owCb=document.getElementById("lcni-rf-opt-watchlist");
if(owCb) owCb.addEventListener("change",syncWlSub);
function syncWlSub(){ var s=document.getElementById("lcni-rf-watchlist-opts"); if(s&&owCb) s.style.display=owCb.checked?"":"none"; }

var obCb=document.getElementById("lcni-rf-opt-browser");
if(obCb) obCb.addEventListener("change",function(){
    if(this.checked){
        if(typeof Notification!=="undefined"&&Notification.permission==="default"){
            Notification.requestPermission().then(function(p){
                if(p!=="granted"){ obCb.checked=false; showToast("B\u1EA1n \u0111\u00E3 t\u1EEB ch\u1ED1i quy\u1EC1n th\u00F4ng b\u00E1o.","error"); }
                else { registerPushSubscription(); }
            });
        } else if(typeof Notification!=="undefined"&&Notification.permission==="granted"){
            registerPushSubscription();
        }
    }
});

/* Web Push subscription */
function registerPushSubscription(){
    if(!("serviceWorker" in navigator)||!("PushManager" in window)) return;
    // Fetch VAPID public key from server
    fetch(REST+"/recommend/push/vapid-key",{headers:{"X-WP-Nonce":NONCE},credentials:"same-origin"})
    .then(function(r){return r.json();})
    .then(function(res){
        if(!res||!res.publicKey) return;
        var vapidKey=urlBase64ToUint8Array(res.publicKey);
        return navigator.serviceWorker.ready.then(function(sw){
            return sw.pushManager.subscribe({
                userVisibleOnly:true,
                applicationServerKey:vapidKey
            });
        }).then(function(sub){
            var j=sub.toJSON();
            return fetch(REST+"/recommend/push/subscribe",{
                method:"POST",
                headers:{"Content-Type":"application/json","X-WP-Nonce":NONCE},
                credentials:"same-origin",
                body:JSON.stringify({
                    endpoint:j.endpoint,
                    p256dh:(j.keys&&j.keys.p256dh)||"",
                    auth:(j.keys&&j.keys.auth)||""
                })
            });
        });
    })
    .catch(function(e){ console.warn("[LCNI Push] subscribe error:",e); });
}

function urlBase64ToUint8Array(b64){
    var pad="=".repeat((4-b64.length%4)%4);
    var raw=atob(b64.replace(/-/g,"+").replace(/_/g,"/") + pad);
    var arr=new Uint8Array(raw.length);
    for(var i=0;i<raw.length;i++) arr[i]=raw.charCodeAt(i);
    return arr;
}

// Register service worker for push
if("serviceWorker" in navigator){
    navigator.serviceWorker.register("<?php echo esc_url( home_url('/lcni-sw.js') ); ?>")
        .catch(function(e){ console.warn("[LCNI SW]",e); });
}

var cfBtn=document.getElementById("lcni-rf-modal-confirm");
if(cfBtn) cfBtn.addEventListener("click",function(){
    if(!pendingId) return;
    this.disabled=true; this.textContent="Đang lưu...";
    var ne=document.getElementById("lcni-rf-opt-email"), nb_=document.getElementById("lcni-rf-opt-browser"), nw=document.getElementById("lcni-rf-opt-watchlist");
    var notifyEmail=ne?ne.checked:true, notifyBrowser=nb_?nb_.checked:false, useWl=nw&&nw.checked;
    var wlMode=(document.querySelector("input[name=\"lcni_wl_mode\"]:checked")||{}).value||"create";
    var wlName=(document.getElementById("lcni-rf-wl-name")||{}).value||"";
    var wlEx=parseInt(((document.getElementById("lcni-rf-wl-existing")||{}).value)||"0");
    var payload={notify_email:notifyEmail,notify_browser:notifyBrowser};
    if(useWl){
        if(wlMode==="existing"&&wlEx>0) payload.dynamic_watchlist_id=wlEx;
        else { payload.create_watchlist=true; payload.watchlist_name=wlName.trim()||("Signal: "+pendingName); }
    }
    var ruleId=pendingId, self=this;
    apiPost("/recommend/rules/"+ruleId+"/follow",payload)
        .then(function(res){
            if(!res.success) throw new Error(res.message||"Lỗi");
            var card=app.querySelector(".lcni-rf-card[data-rule-id=\""+ruleId+"\"]");
            var btn=app.querySelector(".lcni-rf-follow-btn[data-rule-id=\""+ruleId+"\"]");
            if(btn){ btn.dataset.following="1"; btn.innerHTML=BTN_RF_FOLLOWING_HTML; btn.classList.remove("lcni-btn-btn_rf_follow"); btn.classList.add("lcni-rf-follow-btn--active","lcni-btn-btn_rf_following"); }
            if(card){
                card.classList.add("lcni-rf-card--following");
                card.dataset.notifyEmail=notifyEmail?"1":"0";
                card.dataset.notifyBrowser=notifyBrowser?"1":"0";
                card.dataset.dynamicWl=String(res.dynamic_watchlist_id||0);
            }
            updateCount(ruleId,res.follower_count||0);
            var msgs=["✅ Đã theo dõi chiến lược"];
            if(res.dynamic_watchlist_id>0) msgs.push("📋 Watchlist đã được tạo");
            showToast(msgs.join(" · "),"success");
            if(notifyBrowser&&typeof Notification!=="undefined"&&Notification.permission==="granted")
                new Notification("🔔 Đã bật thông báo: "+pendingName);
            closeModal();
        })
        .catch(function(err){ showToast(err.message||"Lỗi kết nối","error"); })
        .finally(function(){ self.disabled=false; self.textContent="✅ Xác nhận Theo dõi"; });
});

/* follower count */
function updateCount(ruleId,count){
    var el=app.querySelector(".lcni-rf-follower-count[data-rule-id=\""+ruleId+"\"]");
    if(el) el.textContent=count;
}

/* signals tab */
var signalsLoaded=false;
function loadSignals(){
    signalsLoaded=true;
    var container=app.querySelector(".lcni-rf-signals-container");
    if(!container) return;

    // Lấy IDs từ RULES (render time) + DOM (follow trong session hiện tại)
    var domIds=[];
    app.querySelectorAll(".lcni-rf-follow-btn[data-following=\"1\"]").forEach(function(b){
        var id=parseInt(b.dataset.ruleId||"0"); if(id>0) domIds.push(id);
    });
    var ruleIds=RULES.filter(function(r){return r.is_following;}).map(function(r){return r.id;});
    var allIds=Array.from(new Set(ruleIds.concat(domIds)));

    if(!allIds.length){
        container.innerHTML="<p class=\"lcni-rf-signals-empty\">Bạn chưa theo dõi chiến lược nào. Chuyển sang tab \"Danh sách Chiến lược\" để bắt đầu.</p>";
        return;
    }
    container.innerHTML="<div class=\"lcni-rf-signals-loading\">Đang tải signals...</div>";
    fetch(REST+"/recommend/signals/open?rule_ids="+allIds.join(","),{
        headers:{"X-WP-Nonce":NONCE},
        credentials:"same-origin"
    })
    .then(function(r){
        if(!r.ok) throw new Error("HTTP "+r.status);
        return r.json();
    })
    .then(function(res){ renderSignals(container,(res&&res.signals)||[]); })
    .catch(function(err){
        container.innerHTML="<p class=\"lcni-rf-signals-empty\">Lỗi tải signals: "+(err.message||"")+"</p>";
    });
}

function renderSignals(container,signals){
    if(!signals.length){
        container.innerHTML="<p class=\"lcni-rf-signals-empty\">Hiện không có signal nào đang mở từ các rule bạn theo dõi.</p>";
        return;
    }
    var rows=signals.map(function(s){
        var r=parseFloat(s.pnl_pct||s.r_multiple||0);
        var rCls=r>=0?"lcni-rf-signal-pos":"lcni-rf-signal-neg";
        var rTxt=(r>=0?"+":"")+r.toFixed(2)+"R";
        var cur=parseFloat(s.suggested_price||s.current_price||0);
        var curTxt=cur>0?cur.toFixed(2):"—";
        // entry_price DB format → full price
        var ep=parseFloat(s.entry_price||0);
        var epFull=ep<1000&&ep>0?ep*1000:ep;
        var epTxt=epFull>0?epFull.toFixed(0):"—";
        var dt=s.entry_time?new Date(parseInt(s.entry_time)*1000).toLocaleDateString("vi-VN"):"—";
        var days=parseInt(s.holding_days||0);
        // Giá hiện tại clickable → mở [lcni_add_transaction_float]
        var curClickable=cur>0
            ?"<span class=\"lcni-rf-price-click\" data-symbol=\""+esc(s.symbol)+"\" data-price=\""+cur+"\" title=\"Click để ghi giao dịch\">"+curTxt+"</span>"
            :curTxt;
        var posStateMap={"EARLY":"MUA","HOLD":"NẮM GIỮ","ADD_ZONE":"GIA TĂNG","TAKE_PROFIT_ZONE":"CHỐT LỜI","CUT_ZONE":"RỦI RO"};
        var posState=posStateMap[s.position_state]||esc(s.position_state||"—");
        var stickyTd = RF_STICKY_COL ? "<td class=\"is-sticky-col\">" : "<td>";
        return "<tr>"
            +stickyTd+"<span class=\"lcni-rf-signal-symbol-cell\"><span class=\"lcni-rf-signal-symbol\">"+esc(s.symbol)+"</span><button type=\"button\" class=\"lcni-btn lcni-btn-btn_rule_follow_add\" data-lcni-rf-watchlist-add data-symbol=\""+esc(s.symbol)+"\" aria-label=\"Thêm vào watchlist\"><i class=\""+(BTN_RF_CFG.icon_class||'fa-solid fa-heart-circle-plus')+"\" aria-hidden=\"true\"></i></button></span></td>"
            +"<td>"+esc(s.rule_name||("Rule #"+s.rule_id))+"</td>"
            +"<td>"+dt+"</td>"
            +"<td>"+epTxt+"</td>"
            +"<td>"+curClickable+"</td>"
            +"<td class=\""+rCls+"\">"+rTxt+"</td>"
            +"<td>"+days+"d</td>"
            +"<td>"+posState+"</td>"
            +"</tr>";
    }).join("");
    var stickyTh = RF_STICKY_COL ? "<th class=\"is-sticky-col\">" : "<th>";
    container.innerHTML="<div class=\"lcni-rf-signals-table-wrap lcni-table-wrapper\"><table class=\"lcni-rf-signals-table"+(RF_STICKY_HEADER?" has-sticky-header":"")+"\">"
        +"<thead><tr>"
        +stickyTh+"Mã</th><th>Chiến lược</th><th>Ngày vào</th><th>Giá vào</th>"
        +"<th>Giá hiện tại <span class=\"lcni-rf-th-hint\">↗ click</span></th>"
        +"<th>R</th><th>Ngày</th><th>Trạng thái</th>"
        +"</tr></thead>"
        +"<tbody>"+rows+"</tbody></table></div>";

    // Touch scroll handler cho mobile
    (function(){
        var wrap = container.querySelector('.lcni-rf-signals-table-wrap');
        if(!wrap || wrap.dataset.touchBound) return;
        wrap.dataset.touchBound = '1';
        var sx=0, sy=0, sleft=0, stop=0, locked=null;
        wrap.addEventListener('touchstart', function(e){
            var t=e.touches[0]; sx=t.clientX; sy=t.clientY;
            sleft=wrap.scrollLeft; stop=wrap.scrollTop; locked=null;
        }, {passive:true});
        wrap.addEventListener('touchmove', function(e){
            if(e.touches.length!==1) return;
            var t=e.touches[0], dx=t.clientX-sx, dy=t.clientY-sy;
            if(locked===null){ if(Math.hypot(dx,dy)<8) return; locked=Math.abs(dx)>Math.abs(dy); }
            if(locked){
                e.preventDefault();
                wrap.scrollLeft = sleft - dx;
            } else {
                var atTop = wrap.scrollTop <= 0;
                var atBot = wrap.scrollTop >= wrap.scrollHeight - wrap.clientHeight;
                if((dy>0 && atTop) || (dy<0 && atBot)) return;
                e.preventDefault();
                wrap.scrollTop = stop - dy;
            }
        }, {passive:false});
        wrap.addEventListener('touchend',    function(){ locked=null; }, {passive:true});
        wrap.addEventListener('touchcancel', function(){ locked=null; }, {passive:true});
    })();

    // Sticky column offset — dùng LcniTableEngine (chuẩn hệ thống)
    (function(){
        var wrap = container.querySelector('.lcni-rf-signals-table-wrap');
        if (!wrap) return;
        var stickyCount = RF_STICKY_COL ? 1 : 0;
        if (window.LcniTableEngine && typeof window.LcniTableEngine.refresh === 'function') {
            window.LcniTableEngine.refresh(wrap, { sticky_columns: stickyCount, sticky_header: RF_STICKY_HEADER });
            window.LcniTableEngine.observe(wrap, { sticky_columns: stickyCount, sticky_header: RF_STICKY_HEADER });
        }
    })();

    // Bind click on price cells → open transaction modal
    container.querySelectorAll(".lcni-rf-price-click").forEach(function(el){
        el.addEventListener("click",function(){
            var sym=this.dataset.symbol||"";
            var price=parseFloat(this.dataset.price||0);
            // Nếu price là DB format (<1000) thì nhân 1000
            if(price>0&&price<1000) price=price*1000;
            // Try window.LCNITransactionController first (lcni-transaction-controller.js)
            if(window.LCNITransactionController&&typeof window.LCNITransactionController.openModal==="function"){
                window.LCNITransactionController.openModal({symbol:sym,price:price,type:"buy"});
            } else if(typeof window.lcniOpenPortfolioTxModal==="function"){
                window.lcniOpenPortfolioTxModal({symbol:sym,price:price,type:"buy"});
            } else {
                // Fallback: tìm và click floating button rồi pre-fill
                var floatBtn=document.querySelector("[data-lcni-add-tx-float],[data-lcni-float-add-tx]");
                if(floatBtn) floatBtn.click();
                // Pre-fill sau khi modal mở
                setTimeout(function(){
                    var symInput=document.querySelector("#lcni-tx-symbol,input[name=symbol],[data-lcni-tx-symbol]");
                    var priceInput=document.querySelector("#lcni-tx-price,input[name=price],[data-lcni-tx-price]");
                    if(symInput){ symInput.value=sym; symInput.dispatchEvent(new Event("input",{bubbles:true})); }
                    if(priceInput){ priceInput.value=price; priceInput.dispatchEvent(new Event("input",{bubbles:true})); }
                },200);
            }
        });
    });
}

function esc(v){ return String(v==null?"":v).replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;").replace(/"/g,"&quot;").replace(/'/g,"&#39;"); }

/* ── Watchlist add button in signals table ─────────────────────── */
(function(){
  var wlBase=(app.dataset.watchlistRest||'').replace(/\/$/,'');
  var nonce=app.dataset.nonce||'';

  function wlApi(path,opt){
    return fetch(wlBase+path,{method:(opt&&opt.method)||'GET',headers:{'Content-Type':'application/json','X-WP-Nonce':nonce},credentials:'same-origin',body:opt&&opt.body?JSON.stringify(opt.body):undefined})
      .then(function(r){return r.json().then(function(p){if(!r.ok)throw p;return p&&p.data!==undefined?p.data:p;});});
  }

  function wlToast(msg){
    var n=document.createElement('div');
    n.style.cssText='position:fixed;right:16px;bottom:16px;background:#111827;color:#fff;padding:10px 14px;border-radius:6px;z-index:999999;font-size:13px;';
    n.textContent=msg;document.body.appendChild(n);setTimeout(function(){n.remove();},2400);
  }

  function wlShowModal(html){
    var existing=document.getElementById('lcni-rf-wl-modal');if(existing)existing.remove();
    var m=document.createElement('div');
    m.id='lcni-rf-wl-modal';
    m.style.cssText='position:fixed;inset:0;background:rgba(17,24,39,.45);display:flex;align-items:center;justify-content:center;z-index:999999;';
    m.innerHTML='<div style="background:#fff;border-radius:10px;padding:16px;width:min(92vw,380px)">'+html+'</div>';
    m.addEventListener('click',function(e){if(e.target===m)m.remove();});
    document.body.appendChild(m);
    return m;
  }

  app.addEventListener('click',function(e){
    var btn=e.target.closest('[data-lcni-rf-watchlist-add]');
    if(!btn) return;
    e.preventDefault();e.stopPropagation();
    var symbol=String(btn.dataset.symbol||'').toUpperCase();
    if(!symbol) return;

    /* spin */
    var iconEl=btn.querySelector('i');
    var origIcon=iconEl?iconEl.className:(BTN_RF_CFG.icon_class||'fa-solid fa-heart-circle-plus');
    btn.disabled=true;btn.classList.add('is-loading');
    if(iconEl)iconEl.className='fa-solid fa-circle-notch lcni-btn-icon';

    function onSuccess(){
      btn.classList.remove('is-loading');btn.classList.add('is-done');btn.disabled=false;
      if(iconEl)iconEl.className='fa-solid fa-circle-check';
      setTimeout(function(){btn.classList.remove('is-done');if(iconEl)iconEl.className=origIcon;},1800);
    }
    function onError(){btn.classList.remove('is-loading');btn.disabled=false;if(iconEl)iconEl.className=origIcon;}

    wlApi('/list?device=desktop').then(function(data){
      var lists=Array.isArray(data.watchlists)?data.watchlists:[];
      var active=Number(data.active_watchlist_id||0);
      onError(); /* restore spinner — modal takes over */

      if(!lists.length){
        var m=wlShowModal('<h3 style="margin:0 0 10px">Tạo watchlist cho '+esc(symbol)+'</h3><form id="lcni-rf-wl-create"><input type="text" name="name" placeholder="Tên watchlist" required style="width:100%;height:34px;box-sizing:border-box;padding:0 8px;border:1px solid #d1d5db;border-radius:6px;margin-bottom:10px"><div style="display:flex;justify-content:flex-end;gap:8px"><button type="button" id="lcni-rf-wl-cancel" style="padding:0 12px;height:32px;border-radius:6px;border:1px solid #d1d5db;cursor:pointer">Hủy</button><button type="submit" style="padding:0 12px;height:32px;border-radius:6px;border:0;background:#2563eb;color:#fff;cursor:pointer">+ Tạo & Thêm</button></div></form>');
        m.querySelector('#lcni-rf-wl-cancel').addEventListener('click',function(){m.remove();});
        m.querySelector('#lcni-rf-wl-create').addEventListener('submit',function(ev){
          ev.preventDefault();
          var name=String((new FormData(this)).get('name')||'').trim();if(!name)return;
          var sb=this.querySelector('[type="submit"]');sb.disabled=true;
          wlApi('/create',{method:'POST',body:{name}})
            .then(function(c){var id=Number(c.id||0);if(!id)throw new Error('Không thể tạo watchlist');return wlApi('/add-symbol',{method:'POST',body:{symbol,watchlist_id:id}});})
            .then(function(){m.remove();wlToast('Đã thêm '+symbol+' vào watchlist');onSuccess();})
            .catch(function(err){wlToast((err&&err.message)||'Lỗi');sb.disabled=false;});
        },{once:true});
        return;
      }

      var radios=lists.map(function(w){return '<label style="display:flex;align-items:center;gap:6px;padding:4px 0"><input type="radio" name="watchlist_id" value="'+Number(w.id||0)+'" '+(Number(w.id||0)===active?'checked':'')+'>'+esc(w.name||'')+'</label>';}).join('');
      var m=wlShowModal('<h3 style="margin:0 0 10px">Chọn watchlist cho '+esc(symbol)+'</h3><form id="lcni-rf-wl-pick"><div style="max-height:200px;overflow:auto;margin-bottom:10px">'+radios+'</div><div style="display:flex;justify-content:flex-end;gap:8px"><button type="button" id="lcni-rf-wl-cancel2" style="padding:0 12px;height:32px;border-radius:6px;border:1px solid #d1d5db;cursor:pointer">Hủy</button><button type="submit" style="padding:0 12px;height:32px;border-radius:6px;border:0;background:#2563eb;color:#fff;cursor:pointer">Thêm</button></div></form>');
      m.querySelector('#lcni-rf-wl-cancel2').addEventListener('click',function(){m.remove();});
      m.querySelector('#lcni-rf-wl-pick').addEventListener('submit',function(ev){
        ev.preventDefault();
        var sel=this.querySelector('input[name="watchlist_id"]:checked');var id=Number(sel?sel.value:0);if(!id)return;
        var sb=this.querySelector('[type="submit"]');sb.disabled=true;
        wlApi('/add-symbol',{method:'POST',body:{symbol,watchlist_id:id}})
          .then(function(){m.remove();wlToast('Đã thêm '+symbol+' vào watchlist');onSuccess();})
          .catch(function(err){wlToast((err&&err.message)||'Lỗi');sb.disabled=false;});
      },{once:true});
    }).catch(function(err){wlToast((err&&err.message)||'Không thể tải watchlist');onError();});
  });
})();

})();
</script>
        <?php
    }
}
