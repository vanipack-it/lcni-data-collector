<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * UserRuleShortcode
 *
 * [lcni_user_rule]
 *
 * Hiển thị:
 *   - Dashboard: danh sách các UserRule đang áp dụng
 *   - Detail view: signals + equity curve của 1 UserRule (param ?ur_id=X)
 *   - Setup wizard: tạo mới (param ?ur_new=1)
 */
class UserRuleShortcode {

    private UserRuleRepository $repo;
    private ?LCNI_SaaS_Service $saas;

    public function __construct( UserRuleRepository $repo, ?LCNI_SaaS_Service $saas = null ) {
        $this->repo = $repo;
        $this->saas = $saas;
        add_shortcode( 'lcni_user_rule', [ $this, 'render' ] );
    }

    public function render( $atts = [] ): string {
        if ( ! is_user_logged_in() ) {
            return $this->render_login_gate();
        }

        // Kiểm tra quyền gói SaaS — module 'user-rule'
        // Logic: PHẢI có service VÀ PHẢI có quyền → mới được vào
        // Nếu service null (chưa load) → chặn để an toàn
        $saas_service = $this->saas;
        if ( ! $saas_service ) {
            global $lcni_saas_service;
            $saas_service = $lcni_saas_service ?? null;
        }

        $has_permission = false;
        if ( $saas_service ) {
            $has_permission = $saas_service->can( 'user-rule', 'view' );
        }

        if ( ! $has_permission ) {
            $upgrade_url = get_option( 'lcni_saas_upgrade_url', home_url('/') );
            return '<div style="padding:24px 28px;background:#fefce8;border:1px solid #fde68a;border-radius:10px;font-size:14px;color:#92400e;max-width:560px">
                <strong>🔒 Tính năng này yêu cầu nâng cấp gói.</strong><br><br>
                Chiến lược tự động / Paper Trading chỉ dành cho thành viên có gói phù hợp.<br><br>
                <a href="' . esc_url($upgrade_url) . '" style="display:inline-block;padding:9px 20px;background:#2563eb;color:#fff;border-radius:6px;text-decoration:none;font-size:13px;font-weight:600">
                    Nâng cấp gói →
                </a>
            </div>';
        }

        $user_id  = get_current_user_id();
        $rest_url = esc_url_raw( rest_url('lcni/v1') );
        $nonce    = wp_create_nonce('wp_rest');
        $ur_id          = absint( $_GET['ur_id'] ?? 0 );
        $ur_new         = ! empty( $_GET['ur_new'] );
        $preset_rule_id = absint( $_GET['rule_id'] ?? 0 ); // Pre-select từ [lcni_rule_follow]

        // Lấy danh sách system rules để chọn
        global $wpdb;
        $sys_rules = $wpdb->get_results(
            "SELECT id, name, timeframe, risk_reward FROM {$wpdb->prefix}lcni_recommend_rule WHERE is_active=1 ORDER BY id ASC",
            ARRAY_A
        ) ?: [];

        // Lấy user rules
        $user_rules    = $this->repo->list_user_rules( $user_id );
        $applied_rule_ids = array_column( $user_rules, 'rule_id' );

        // Danh sách watchlists để chọn
        $watchlists = [];
        $wl_table = $wpdb->prefix . 'lcni_watchlists';
        if ( $wpdb->get_var( $wpdb->prepare('SHOW TABLES LIKE %s', $wl_table) ) ) {
            $watchlists = $wpdb->get_results(
                $wpdb->prepare("SELECT id, name FROM {$wl_table} WHERE user_id=%d ORDER BY id ASC", $user_id),
                ARRAY_A
            ) ?: [];
        }

        // DNSE accounts của user
        $dnse_accounts = [];
        $dnse_table = $wpdb->prefix . 'lcni_dnse_accounts';
        if ( $wpdb->get_var( $wpdb->prepare('SHOW TABLES LIKE %s', $dnse_table) ) ) {
            $dnse_accounts = $wpdb->get_results(
                $wpdb->prepare("SELECT account_no, account_type FROM {$dnse_table} WHERE user_id=%d", $user_id),
                ARRAY_A
            ) ?: [];
        }

        // Fallback: nếu lcni_dnse_accounts chưa sync, đọc từ sub_accounts_json trong credentials
        if ( empty( $dnse_accounts ) ) {
            $cred_table = $wpdb->prefix . 'lcni_dnse_credentials';
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $cred_table ) ) ) {
                $cred_row = $wpdb->get_row(
                    $wpdb->prepare( "SELECT sub_accounts_json, dnse_account_no FROM {$cred_table} WHERE user_id=%d LIMIT 1", $user_id ),
                    ARRAY_A
                );
                if ( $cred_row ) {
                    $subs_raw = json_decode( (string)( $cred_row['sub_accounts_json'] ?? '' ), true ) ?: [];
                    foreach ( $subs_raw as $sub ) {
                        $acct_no = (string)( $sub['investorAccountNo'] ?? $sub['accountNo'] ?? $sub['id'] ?? '' );
                        if ( $acct_no === '' ) continue;
                        $type_raw  = strtolower( (string)( $sub['type'] ?? $sub['accountType'] ?? 'spot' ) );
                        $acct_type = ( strpos( $type_raw, 'margin' ) !== false || strpos( $type_raw, 'mr' ) !== false )
                            ? 'margin' : 'spot';
                        $dnse_accounts[] = [ 'account_no' => $acct_no, 'account_type' => $acct_type ];
                    }
                    if ( empty( $dnse_accounts ) && ! empty( $cred_row['dnse_account_no'] ) ) {
                        $dnse_accounts[] = [ 'account_no' => $cred_row['dnse_account_no'], 'account_type' => 'spot' ];
                    }
                }
            }
        }

        ob_start();
        ?>
        <div class="lcni-ur-app" id="lcni-ur-app"
             data-rest="<?php echo esc_attr($rest_url); ?>"
             data-nonce="<?php echo esc_attr($nonce); ?>"
             data-page="<?php echo esc_attr(get_permalink()); ?>"
             data-preset-rule="<?php echo esc_attr( (string) $preset_rule_id ); ?>">

            <!-- Header -->
            <div class="lcni-ur-header">
                <h2 class="lcni-ur-title">🤖 Áp dụng Chiến lược tự động</h2>
                <?php if ( ! $ur_new && ! $ur_id ): ?>
                <a href="<?php echo esc_url(add_query_arg('ur_new','1')); ?>"
                   class="lcni-btn lcni-btn-btn_ur_new lcni-ur-btn"><?php echo LCNI_Button_Style_Config::build_button_content('btn_ur_new', '＋ Áp dụng chiến lược mới'); ?></a>
                <?php endif; ?>
                <?php if ( $ur_id || $ur_new ): ?>
                <a href="<?php echo esc_url(get_permalink()); ?>"
                   class="lcni-ur-btn lcni-ur-btn--ghost">← Quay lại</a>
                <?php endif; ?>
            </div>

            <?php if ( $ur_new ): ?>
                <?php $this->render_wizard( $sys_rules, $applied_rule_ids, $dnse_accounts, $watchlists ); ?>
            <?php elseif ( $ur_id ): ?>
                <?php
                $rule = $this->repo->get_user_rule( $ur_id, $user_id );
                if ( $rule ) $this->render_detail( $rule );
                else echo '<p class="lcni-ur-empty">Không tìm thấy chiến lược này.</p>';
                ?>
            <?php else: ?>
                <?php $this->render_dashboard( $user_rules ); ?>
            <?php endif; ?>

            <div id="lcni-ur-toast" class="lcni-ur-toast" aria-live="polite"></div>
        </div>

        <?php $this->render_styles(); ?>
        <?php $this->render_scripts( $sys_rules, $user_rules ); ?>
        <?php
        return ob_get_clean();
    }

    // =========================================================================
    // SETUP WIZARD
    // =========================================================================

    private function render_wizard( array $sys_rules, array $applied_ids, array $dnse_accounts, array $watchlists = [] ): void {
        $today = date('Y-m-d');
        ?>
        <div class="lcni-ur-wizard" id="lcni-ur-wizard">
            <div class="lcni-ur-steps">
                <div class="lcni-ur-step lcni-ur-step--active" data-step="1">1. Chọn chiến lược</div>
                <div class="lcni-ur-step" data-step="2">2. Cấu hình vốn</div>
                <div class="lcni-ur-step" data-step="3">3. Điều kiện</div>
                <div class="lcni-ur-step" data-step="4">4. Xác nhận</div>
            </div>

            <!-- Step 1: Chọn chiến lược -->
            <div class="lcni-ur-wizard-pane lcni-ur-wizard-pane--active" data-pane="1">
                <h3>Chọn chiến lược muốn áp dụng</h3>
                <div class="lcni-ur-rule-grid" id="lcni-ur-rule-grid">
                    <?php foreach ( $sys_rules as $r ):
                        $applied = in_array( (int)$r['id'], (array)$applied_ids, true );
                    ?>
                    <div class="lcni-ur-rule-card <?php echo $applied ? 'lcni-ur-rule-card--applied' : ''; ?>"
                         data-rule-id="<?php echo (int)$r['id']; ?>"
                         data-rule-name="<?php echo esc_attr($r['name']); ?>"
                         <?php echo $applied ? 'title="Bạn đã áp dụng chiến lược này"' : ''; ?>>
                        <div class="lcni-ur-rule-name"><?php echo esc_html($r['name']); ?></div>
                        <div class="lcni-ur-rule-meta">
                            <span><?php echo esc_html(strtoupper($r['timeframe'])); ?></span>
                            <?php if ($r['risk_reward']): ?>
                            <span>R:R <?php echo number_format((float)$r['risk_reward'],1); ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if ($applied): ?>
                        <div class="lcni-ur-applied-badge">✅ Đã áp dụng</div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                    <?php if ( empty($sys_rules) ): ?>
                    <p class="lcni-ur-empty">Chưa có chiến lược nào được kích hoạt.</p>
                    <?php endif; ?>
                </div>
                <input type="hidden" id="lcni-ur-selected-rule-id" value="">
                <div class="lcni-ur-wizard-nav">
                    <button type="button" class="lcni-btn lcni-btn-btn_ur_next lcni-ur-btn" onclick="lcniUrNextStep(2)"><?php echo LCNI_Button_Style_Config::build_button_content('btn_ur_next', 'Tiếp theo →'); ?></button>
                </div>
            </div>

            <!-- Step 2: Cấu hình vốn -->
            <div class="lcni-ur-wizard-pane" data-pane="2">
                <h3>Cấu hình vốn đầu tư</h3>

                <!-- Paper / Real toggle -->
                <div class="lcni-ur-mode-toggle">
                    <label class="lcni-ur-mode-card lcni-ur-mode-card--active" id="lcni-mode-paper">
                        <input type="radio" name="lcni_ur_mode" value="paper" checked onchange="lcniUrSetMode('paper')">
                        <div class="lcni-ur-mode-icon">📄</div>
                        <div class="lcni-ur-mode-label">Vốn ảo (Paper Trading)</div>
                        <div class="lcni-ur-mode-desc">Mô phỏng giao dịch, không cần tài khoản chứng khoán</div>
                    </label>
                    <?php if ( ! empty($dnse_accounts) ): ?>
                    <label class="lcni-ur-mode-card" id="lcni-mode-real">
                        <input type="radio" name="lcni_ur_mode" value="real" onchange="lcniUrSetMode('real')">
                        <div class="lcni-ur-mode-icon">💼</div>
                        <div class="lcni-ur-mode-label">Tài khoản thật (DNSE)</div>
                        <div class="lcni-ur-mode-desc">Kết nối tài khoản DNSE, đặt lệnh tự động</div>
                    </label>
                    <?php endif; ?>
                </div>

                <div class="lcni-ur-form-row">
                    <label class="lcni-ur-label">
                        Vốn đầu tư (VNĐ)
                        <small id="lcni-paper-hint" class="lcni-ur-hint">Nhập số vốn ảo muốn mô phỏng</small>
                        <small id="lcni-real-hint" class="lcni-ur-hint" style="display:none">Số vốn thực tế muốn phân bổ cho chiến lược này</small>
                    </label>
                    <input type="number" id="lcni-ur-capital" class="lcni-ur-input"
                           placeholder="Ví dụ: 100000000" min="0" step="1000000" value="100000000">
                    <span class="lcni-ur-capital-display" id="lcni-ur-capital-display">100,000,000 đ</span>
                </div>

                <div class="lcni-ur-form-row" id="lcni-real-account-row" style="display:none">
                    <label class="lcni-ur-label">Tài khoản DNSE</label>
                    <select id="lcni-ur-account" class="lcni-ur-select">
                        <?php foreach ($dnse_accounts as $acc): ?>
                        <option value="<?php echo esc_attr($acc['account_no']); ?>">
                            <?php echo esc_html($acc['account_no']); ?> (<?php echo esc_html($acc['account_type']); ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="lcni-ur-form-row" id="lcni-auto-order-row" style="display:none">
                    <label class="lcni-ur-label" style="flex-direction:row;align-items:center;gap:10px;cursor:pointer;">
                        <input type="checkbox" id="lcni-ur-auto-order" style="width:18px;height:18px;">
                        <span>Tự động đặt lệnh DNSE khi có signal mới</span>
                    </label>
                    <p class="lcni-ur-hint" style="margin-top:4px;color:#d97706;">⚠ Tính năng này sẽ tự động đặt lệnh mua. Đảm bảo bạn đã kiểm tra cẩn thận trước khi bật.Hãy thực hiện kết nối DNSE trước 9h00 sáng hàng ngày</p>
                </div>

                <div class="lcni-ur-wizard-nav">
                    <button type="button" class="lcni-ur-btn lcni-ur-btn--ghost" onclick="lcniUrNextStep(1)">← Quay lại</button>
                    <button type="button" class="lcni-btn lcni-btn-btn_ur_next lcni-ur-btn" onclick="lcniUrNextStep(3)"><?php echo LCNI_Button_Style_Config::build_button_content('btn_ur_next', 'Tiếp theo →'); ?></button>
                </div>
            </div>

            <!-- Step 3: Điều kiện -->
            <div class="lcni-ur-wizard-pane" data-pane="3">
                <h3>Điều kiện áp dụng</h3>

                <div class="lcni-ur-form-row">
                    <label class="lcni-ur-label">
                        Ngày bắt đầu áp dụng
                        <small class="lcni-ur-hint">Chỉ nhận áp dụng từ ngày này trở đi. Không thể chọn ngày trong quá khứ.</small>
                    </label>
                    <input type="date" id="lcni-ur-start-date" class="lcni-ur-input"
                           value="<?php echo $today; ?>" min="<?php echo $today; ?>">
                </div>

                <div class="lcni-ur-form-row">
                    <label class="lcni-ur-label">
                        % Vốn mỗi lệnh
                        <small class="lcni-ur-hint">Phần trăm vốn phân bổ cho mỗi lệnh. Ví dụ: 2% vốn 100tr → 2tr/lệnh</small>
                    </label>
                    <div style="display:flex;align-items:center;gap:12px;">
                        <input type="range" id="lcni-ur-risk-range" min="0.5" max="20" step="0.5" value="2"
                               style="flex:1;" oninput="document.getElementById('lcni-ur-risk-pct').value=this.value;lcniUrUpdateRiskCalc()">
                        <input type="number" id="lcni-ur-risk-pct" class="lcni-ur-input"
                               style="width:80px;" min="0.5" max="20" step="0.5" value="2"
                               oninput="document.getElementById('lcni-ur-risk-range').value=this.value;lcniUrUpdateRiskCalc()">
                        <span>%</span>
                    </div>
                    <div class="lcni-ur-risk-calc" id="lcni-ur-risk-calc">
                        → Phân bổ ~<strong id="lcni-alloc-display">2,000,000 đ</strong>/lệnh
                        (~<strong id="lcni-shares-display">?</strong> cổ phiếu)
                    </div>
                </div>

                <div class="lcni-ur-form-row">
                    <label class="lcni-ur-label">
                        Tối đa số mã cùng lúc
                        <small class="lcni-ur-hint">Không vào thêm lệnh khi đang giữ đủ số mã này</small>
                    </label>
                    <input type="number" id="lcni-ur-max-symbols" class="lcni-ur-input"
                           min="1" max="30" value="5" style="width:100px;">
                </div>

                <div class="lcni-ur-form-row">
                    <label class="lcni-ur-label">
                        Phạm vi áp dụng
                        <small class="lcni-ur-hint">Chỉ nhận signal từ những mã trong phạm vi này</small>
                    </label>
                    <div class="lcni-ur-scope-group" id="lcni-ur-scope-group">
                        <label class="lcni-ur-scope-card lcni-ur-scope-card--active" data-scope="all">
                            <input type="radio" name="lcni_ur_scope" value="all" checked onchange="lcniUrOnScopeChange('all')">
                            <span class="lcni-ur-scope-icon">🌐</span>
                            <span class="lcni-ur-scope-label">Toàn thị trường</span>
                        </label>
                        <label class="lcni-ur-scope-card" data-scope="watchlist">
                            <input type="radio" name="lcni_ur_scope" value="watchlist" onchange="lcniUrOnScopeChange('watchlist')">
                            <span class="lcni-ur-scope-icon">📋</span>
                            <span class="lcni-ur-scope-label">Watchlist của tôi</span>
                        </label>
                        <label class="lcni-ur-scope-card" data-scope="custom">
                            <input type="radio" name="lcni_ur_scope" value="custom" onchange="lcniUrOnScopeChange('custom')">
                            <span class="lcni-ur-scope-icon">✏️</span>
                            <span class="lcni-ur-scope-label">Mã cụ thể</span>
                        </label>
                    </div>

                    <!-- Watchlist picker (ẩn mặc định) -->
                    <div id="lcni-ur-watchlist-picker" style="display:none;margin-top:10px;">
                        <select id="lcni-ur-watchlist-id" class="lcni-ur-select">
                            <option value="">— Chọn Watchlist —</option>
                            <?php foreach ($watchlists as $wl): ?>
                            <option value="<?php echo (int)$wl['id']; ?>"><?php echo esc_html($wl['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (empty($watchlists)): ?>
                        <p class="lcni-ur-hint" style="color:#d97706;margin-top:6px;">⚠ Bạn chưa có watchlist nào. Hãy tạo watchlist trước.</p>
                        <?php endif; ?>
                    </div>

                    <!-- Custom symbols input (ẩn mặc định) -->
                    <div id="lcni-ur-custom-symbols-wrap" style="display:none;margin-top:10px;">
                        <input type="text" id="lcni-ur-custom-symbols" class="lcni-ur-input"
                               placeholder="Ví dụ: VHM, HPG, FPT, MBB"
                               style="text-transform:uppercase;">
                        <small class="lcni-ur-hint" style="display:block;margin-top:4px;">Nhập mã cổ phiếu cách nhau dấu phẩy. Chỉ nhận signal từ các mã này.</small>
                    </div>
                </div>

                <div class="lcni-ur-wizard-nav">
                    <button type="button" class="lcni-ur-btn lcni-ur-btn--ghost" onclick="lcniUrNextStep(2)">← Quay lại</button>
                    <button type="button" class="lcni-btn lcni-btn-btn_ur_next lcni-ur-btn" onclick="lcniUrNextStep(4)"><?php echo LCNI_Button_Style_Config::build_button_content('btn_ur_next', 'Xem lại →'); ?></button>
                </div>
            </div>

            <!-- Step 4: Xác nhận -->
            <div class="lcni-ur-wizard-pane" data-pane="4">
                <h3>Xác nhận cấu hình</h3>
                <div class="lcni-ur-summary" id="lcni-ur-summary">
                    <!-- filled by JS -->
                </div>
                <div class="lcni-ur-warning">
                    <strong>⚠ Lưu ý quan trọng:</strong>
                    <ul>
                        <li>Hệ thống <strong>chỉ nhận áp dụng chiến lược từ ngày đã chọn</strong>, không áp dụng lùi về quá khứ.</li>
                        <li>Đường cong vốn sẽ được tính từ ngày bắt đầu áp dụng.</li>
                        <li>Paper Trading là mô phỏng, lãi/lỗ không phải tiền thật.</li>
                    </ul>
                </div>
                <div class="lcni-ur-wizard-nav">
                    <button type="button" class="lcni-ur-btn lcni-ur-btn--ghost" onclick="lcniUrNextStep(3)">← Quay lại</button>
                    <button type="button" class="lcni-btn lcni-btn-btn_ur_submit lcni-ur-btn" id="lcni-ur-submit-btn" onclick="lcniUrSubmit()"><?php echo LCNI_Button_Style_Config::build_button_content('btn_ur_submit', '🚀 Bắt đầu áp dụng'); ?></button>
                </div>
            </div>
        </div>
        <?php
    }

    // =========================================================================
    // DASHBOARD
    // =========================================================================

    private function render_dashboard( array $user_rules ): void {
        if ( empty($user_rules) ): ?>
        <div class="lcni-ur-empty-state">
            <div class="lcni-ur-empty-icon">🤖</div>
            <h3>Chưa áp dụng chiến lược nào</h3>
            <p>Chọn một chiến lược và cấu hình vốn để hệ thống tự động theo dõi và tính P&amp;L cho bạn.</p>
            <a href="<?php echo esc_url(add_query_arg('ur_new','1')); ?>"
               class="lcni-btn lcni-btn-btn_ur_new lcni-ur-btn"><?php echo LCNI_Button_Style_Config::build_button_content('btn_ur_new', '＋ Áp dụng Rule đầu tiên'); ?></a>
        </div>
        <?php return; endif; ?>

        <div class="lcni-ur-dashboard-grid">
        <?php foreach ( $user_rules as $ur ):
            $perf       = $ur;
            $is_paper   = (int)$ur['is_paper'];
            $status_cls = $ur['status'] === 'active' ? 'lcni-ur-status--active' : 'lcni-ur-status--paused';
            $pnl        = (float)($ur['total_pnl_vnd'] ?? 0);
            $pnl_cls    = $pnl >= 0 ? 'lcni-ur-pos' : 'lcni-ur-neg';
            $total_r    = (float)($ur['total_r'] ?? 0);
            $winrate    = isset($ur['winrate']) ? round((float)$ur['winrate'] * 100, 1) : null;
        ?>
        <div class="lcni-ur-card" data-ur-id="<?php echo (int)$ur['id']; ?>">
            <div class="lcni-ur-card-header">
                <div>
                    <div class="lcni-ur-card-title">
                        <?php echo esc_html($ur['rule_name']); ?>
                        <span class="lcni-ur-badge <?php echo $is_paper ? 'lcni-ur-badge--paper' : 'lcni-ur-badge--real'; ?>">
                            <?php echo $is_paper ? '📄 Ảo' : '💼 Thật'; ?>
                        </span>
                    </div>
                    <div class="lcni-ur-card-meta">
                        <?php echo esc_html(strtoupper($ur['timeframe'])); ?> &bull;
                        Vốn: <?php echo number_format((float)$ur['capital']); ?> đ &bull;
                        Từ: <?php echo esc_html($ur['start_date']); ?>
                    </div>
                </div>
                <span class="lcni-ur-status <?php echo $status_cls; ?>">
                    <?php echo $ur['status'] === 'active' ? '● Đang chạy' : '⏸ Tạm dừng'; ?>
                </span>
            </div>

            <!-- Stats -->
            <div class="lcni-ur-stats-grid">
                <div class="lcni-ur-stat">
                    <div class="lcni-ur-stat-label">Vốn hiện tại</div>
                    <div class="lcni-ur-stat-value <?php echo $pnl_cls; ?>">
                        <?php echo number_format((float)($ur['current_capital'] ?? $ur['capital'])); ?> đ
                    </div>
                </div>
                <div class="lcni-ur-stat">
                    <div class="lcni-ur-stat-label">P&L</div>
                    <div class="lcni-ur-stat-value <?php echo $pnl_cls; ?>">
                        <?php echo ($pnl >= 0 ? '+' : '') . number_format($pnl); ?> đ
                    </div>
                </div>
                <div class="lcni-ur-stat">
                    <div class="lcni-ur-stat-label">Tổng R</div>
                    <div class="lcni-ur-stat-value <?php echo $total_r >= 0 ? 'lcni-ur-pos' : 'lcni-ur-neg'; ?>">
                        <?php echo ($total_r >= 0 ? '+' : '') . number_format($total_r, 2); ?>R
                    </div>
                </div>
                <div class="lcni-ur-stat">
                    <div class="lcni-ur-stat-label">Số lệnh / Winrate</div>
                    <div class="lcni-ur-stat-value">
                        <?php echo (int)($ur['total_trades'] ?? 0); ?> lệnh
                        <?php if ($winrate !== null): ?>/ <?php echo $winrate; ?>%<?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Actions -->
            <div class="lcni-ur-card-actions">
                <a href="<?php echo esc_url(add_query_arg('ur_id', $ur['id'])); ?>"
                   class="lcni-ur-btn lcni-ur-btn--sm">📊 Chi tiết</a>
                <button type="button" class="lcni-ur-btn lcni-ur-btn--sm lcni-ur-btn--ghost lcni-ur-pause-btn"
                        data-ur-id="<?php echo (int)$ur['id']; ?>">
                    <?php echo $ur['status'] === 'active' ? '⏸ Tạm dừng' : '▶ Tiếp tục'; ?>
                </button>
                <button type="button" class="lcni-ur-btn lcni-ur-btn--sm lcni-ur-btn--danger lcni-ur-delete-btn"
                        data-ur-id="<?php echo (int)$ur['id']; ?>" data-rule-name="<?php echo esc_attr($ur['rule_name']); ?>">
                    🗑
                </button>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
        <?php
    }

    // =========================================================================
    // DETAIL VIEW
    // =========================================================================

    private function render_detail( array $rule ): void {
        $perf     = $this->repo->get_performance( (int)$rule['id'] );
        $pnl      = (float)($perf['total_pnl_vnd'] ?? 0);
        $pnl_cls  = $pnl >= 0 ? 'lcni-ur-pos' : 'lcni-ur-neg';
        $is_paper = (int)$rule['is_paper'];
        ?>
        <div class="lcni-ur-detail">
            <!-- Summary header -->
            <div class="lcni-ur-detail-header">
                <div>
                    <h3 class="lcni-ur-detail-title">
                        <?php echo esc_html($rule['rule_name']); ?>
                        <span class="lcni-ur-badge <?php echo $is_paper ? 'lcni-ur-badge--paper' : 'lcni-ur-badge--real'; ?>">
                            <?php echo $is_paper ? '📄 Paper' : '💼 Real'; ?>
                        </span>
                    </h3>
                    <div class="lcni-ur-detail-meta">
                        Vốn ban đầu: <strong><?php echo number_format((float)$rule['capital']); ?> đ</strong> &bull;
                        Risk/lệnh: <strong><?php echo (float)$rule['risk_per_trade']; ?>%</strong> &bull;
                        Max <?php echo (int)$rule['max_symbols']; ?> mã &bull;
                        Từ: <?php echo esc_html($rule['start_date']); ?>
                    </div>
                </div>
                <div class="lcni-ur-detail-actions">
                    <button type="button" class="lcni-ur-btn lcni-ur-btn--ghost lcni-ur-pause-btn"
                            data-ur-id="<?php echo (int)$rule['id']; ?>">
                        <?php echo $rule['status'] === 'active' ? '⏸ Tạm dừng' : '▶ Tiếp tục'; ?>
                    </button>
                </div>
            </div>

            <!-- Performance row -->
            <div class="lcni-ur-perf-row">
                <?php
                $stats = [
                    ['Vốn hiện tại', number_format((float)($perf['current_capital'] ?? $rule['capital'])) . ' đ', $pnl_cls],
                    ['P&L', ($pnl >= 0 ? '+' : '') . number_format($pnl) . ' đ', $pnl_cls],
                    ['Tổng R', (($r = (float)($perf['total_r'] ?? 0)) >= 0 ? '+' : '') . number_format($r, 2) . 'R', $r >= 0 ? 'lcni-ur-pos' : 'lcni-ur-neg'],
                    ['Số lệnh', (int)($perf['total_trades'] ?? 0), ''],
                    ['Winrate', isset($perf['winrate']) ? number_format((float)$perf['winrate'] * 100, 1) . '%' : '—', ''],
                    ['Max DD', '-' . number_format((float)($perf['max_drawdown_vnd'] ?? 0)) . ' đ', 'lcni-ur-neg'],
                ];
                foreach ($stats as [$label, $value, $cls]): ?>
                <div class="lcni-ur-perf-card">
                    <div class="lcni-ur-perf-label"><?php echo esc_html($label); ?></div>
                    <div class="lcni-ur-perf-value <?php echo esc_attr($cls); ?>"><?php echo esc_html($value); ?></div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Equity curve -->
            <div class="lcni-ur-chart-section">
                <h4 class="lcni-ur-section-title">
                    📈 Đường cong vốn
                    <span class="lcni-ur-chart-toggle">
                        <button type="button" class="lcni-ur-chart-mode-btn lcni-ur-chart-mode-btn--active" data-mode="vnd">VNĐ</button>
                        <button type="button" class="lcni-ur-chart-mode-btn" data-mode="r">R-multiple</button>
                    </span>
                </h4>
                <div class="lcni-ur-chart-wrap">
                    <canvas id="lcni-ur-equity-chart" height="200"></canvas>
                    <div class="lcni-ur-chart-loading" id="lcni-ur-chart-loading">Đang tải...</div>
                </div>
            </div>

            <!-- Signals table -->
            <div class="lcni-ur-signals-section">
                <h4 class="lcni-ur-section-title">
                    📋 Danh sách lệnh
                    <div class="lcni-ur-sig-filter">
                        <button type="button" class="lcni-ur-sig-tab lcni-ur-sig-tab--active" data-filter="open">Đang mở</button>
                        <button type="button" class="lcni-ur-sig-tab" data-filter="closed">Đã đóng</button>
                        <button type="button" class="lcni-ur-sig-tab" data-filter="">Tất cả</button>
                    </div>
                </h4>
                <div class="lcni-ur-signals-wrap" id="lcni-ur-signals-wrap">
                    <div class="lcni-ur-loading">Đang tải...</div>
                </div>
            </div>
        </div>

        <script>
        window._lcniUrDetailId = <?php echo (int)$rule['id']; ?>;
        window._lcniUrCapital  = <?php echo (float)$rule['capital']; ?>;
        </script>
        <?php
    }

    // =========================================================================
    // STYLES
    // =========================================================================

    private function render_styles(): void {
        $fa_url  = 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css';
        $btn_css = LCNI_Button_Style_Config::get_inline_css();
        echo '<link rel="stylesheet" href="' . esc_url( $fa_url ) . '">' . "\n";
        if ( $btn_css !== '' ) {
            echo '<style id="lcni-ur-btn-style">' . $btn_css . '</style>' . "\n";
        }
        echo '<style>
.lcni-ur-app{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;max-width:1000px;margin:0 auto;padding:0 0 48px}
.lcni-ur-notice{padding:24px;background:#f9fafb;border-radius:8px;text-align:center;color:#6b7280;font-size:14px}
.lcni-ur-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;flex-wrap:wrap;gap:12px}
.lcni-ur-title{margin:0;font-size:20px;font-weight:700;color:#111827}

/* Buttons — base structure only; primary colors/sizes from LCNI_Button_Style_Config */
.lcni-ur-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 18px;border-radius:8px;font-size:14px;font-weight:600;text-decoration:none;cursor:pointer;border:none;transition:all .15s}
.lcni-ur-btn--ghost{background:#f3f4f6;color:#374151;border:1px solid #e5e7eb}
.lcni-ur-btn--ghost:hover{background:#e5e7eb}
.lcni-ur-btn--danger{background:#fef2f2;color:#dc2626;border:1px solid #fecaca}
.lcni-ur-btn--danger:hover{background:#fee2e2}
.lcni-ur-btn--sm{padding:5px 12px;font-size:12px}
.lcni-ur-btn:disabled{opacity:.5;cursor:not-allowed}

/* Wizard */
.lcni-ur-steps{display:flex;gap:0;margin-bottom:28px;border-bottom:2px solid #e5e7eb}
.lcni-ur-step{padding:10px 18px;font-size:13px;font-weight:600;color:#9ca3af;cursor:default;border-bottom:2px solid transparent;margin-bottom:-2px}
.lcni-ur-step--active{color:#2563eb;border-bottom-color:#2563eb}
.lcni-ur-step--done{color:#16a34a}
.lcni-ur-wizard-pane{display:none}
.lcni-ur-wizard-pane--active{display:block}
.lcni-ur-wizard-pane h3{font-size:17px;font-weight:700;margin:0 0 20px;color:#111827}
.lcni-ur-wizard-nav{display:flex;justify-content:flex-end;gap:10px;margin-top:28px;padding-top:20px;border-top:1px solid #e5e7eb}

/* Rule grid */
.lcni-ur-rule-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:12px;margin-bottom:20px}
.lcni-ur-rule-card{border:2px solid #e5e7eb;border-radius:10px;padding:16px;cursor:pointer;transition:all .15s;position:relative}
.lcni-ur-rule-card:hover{border-color:#2563eb;background:#f0f7ff}
.lcni-ur-rule-card--selected{border-color:#2563eb;background:#eff6ff}
.lcni-ur-rule-card--applied{opacity:.5;cursor:not-allowed}
.lcni-ur-rule-name{font-weight:700;font-size:14px;color:#111827;margin-bottom:6px}
.lcni-ur-rule-meta{display:flex;gap:8px;font-size:12px;color:#6b7280}
.lcni-ur-applied-badge{position:absolute;top:8px;right:8px;font-size:11px;background:#dcfce7;color:#166534;padding:2px 8px;border-radius:10px}

/* Mode toggle */
.lcni-ur-mode-toggle{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:20px}
.lcni-ur-mode-card{border:2px solid #e5e7eb;border-radius:10px;padding:16px;cursor:pointer;transition:all .15s;display:block}
.lcni-ur-mode-card:has(input:checked){border-color:#2563eb;background:#eff6ff}
.lcni-ur-mode-card input{display:none}
.lcni-ur-mode-icon{font-size:28px;margin-bottom:8px}
.lcni-ur-mode-label{font-weight:700;font-size:14px;color:#111827;margin-bottom:4px}
.lcni-ur-mode-desc{font-size:12px;color:#6b7280;line-height:1.4}

/* Form */
.lcni-ur-form-row{margin-bottom:20px}
.lcni-ur-label{display:flex;flex-direction:column;gap:4px;font-size:13px;font-weight:600;color:#374151;margin-bottom:8px}
.lcni-ur-input{width:100%;padding:8px 12px;border:1px solid #d1d5db;border-radius:7px;font-size:14px;box-sizing:border-box}
.lcni-ur-select{width:100%;padding:8px 12px;border:1px solid #d1d5db;border-radius:7px;font-size:14px}
.lcni-ur-hint{font-weight:400;color:#6b7280;font-size:12px}
.lcni-ur-capital-display{display:block;margin-top:6px;font-size:16px;font-weight:700;color:#2563eb}
.lcni-ur-risk-calc{margin-top:8px;font-size:13px;color:#374151;background:#f0fdf4;padding:8px 12px;border-radius:6px}
.lcni-ur-warning{background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:14px 16px;font-size:13px;color:#92400e;margin-top:16px}
.lcni-ur-warning ul{margin:6px 0 0 16px;padding:0}
.lcni-ur-warning li{margin-bottom:4px}

/* Summary */
.lcni-ur-summary{background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;padding:16px 20px;margin-bottom:16px}
.lcni-ur-summary-row{display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid #f3f4f6;font-size:14px}
.lcni-ur-summary-row:last-child{border-bottom:0}
.lcni-ur-summary-label{color:#6b7280}
.lcni-ur-summary-value{font-weight:600;color:#111827}

/* Dashboard */
.lcni-ur-dashboard-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:16px}
.lcni-ur-card{background:#fff;border:2px solid #e5e7eb;border-radius:12px;padding:16px;transition:box-shadow .2s}
.lcni-ur-card:hover{box-shadow:0 4px 16px rgba(0,0,0,.08)}
.lcni-ur-card-header{display:flex;justify-content:space-between;align-items:flex-start;gap:10px;margin-bottom:14px}
.lcni-ur-card-title{font-size:15px;font-weight:700;color:#111827;display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.lcni-ur-card-meta{font-size:12px;color:#9ca3af;margin-top:4px}
.lcni-ur-badge{font-size:11px;padding:2px 8px;border-radius:10px;font-weight:600}
.lcni-ur-badge--paper{background:#e0f2fe;color:#0369a1}
.lcni-ur-badge--real{background:#dcfce7;color:#166534}
.lcni-ur-status{font-size:12px;font-weight:600;white-space:nowrap}
.lcni-ur-status--active{color:#16a34a}
.lcni-ur-status--paused{color:#d97706}
.lcni-ur-stats-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:14px}
.lcni-ur-stat{background:#f9fafb;border-radius:6px;padding:8px 10px}
.lcni-ur-stat-label{font-size:10px;color:#9ca3af;margin-bottom:2px}
.lcni-ur-stat-value{font-size:14px;font-weight:700;color:#111827}
.lcni-ur-card-actions{display:flex;gap:6px;flex-wrap:wrap}
.lcni-ur-pos{color:#16a34a!important}
.lcni-ur-neg{color:#dc2626!important}

/* Empty state */
.lcni-ur-empty-state{text-align:center;padding:48px 24px;background:#f9fafb;border-radius:12px}
.lcni-ur-empty-icon{font-size:48px;margin-bottom:12px}
.lcni-ur-empty-state h3{margin:0 0 8px;font-size:18px;font-weight:700;color:#111827}
.lcni-ur-empty-state p{color:#6b7280;font-size:14px;margin:0 0 20px}

/* Detail */
.lcni-ur-detail-header{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;margin-bottom:20px;flex-wrap:wrap}
.lcni-ur-detail-title{margin:0 0 6px;font-size:18px;font-weight:700;color:#111827;display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.lcni-ur-detail-meta{font-size:13px;color:#6b7280}
.lcni-ur-detail-actions{display:flex;gap:8px;flex-shrink:0}
.lcni-ur-perf-row{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:10px;margin-bottom:24px}
.lcni-ur-perf-card{background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:12px 14px}
.lcni-ur-perf-label{font-size:11px;color:#9ca3af;margin-bottom:4px}
.lcni-ur-perf-value{font-size:15px;font-weight:700;color:#111827}
.lcni-ur-section-title{font-size:15px;font-weight:700;color:#111827;margin:0 0 14px;display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap}
.lcni-ur-chart-section,.lcni-ur-signals-section{margin-bottom:28px}
.lcni-ur-chart-wrap{position:relative;background:#f9fafb;border-radius:8px;padding:12px;min-height:220px}
.lcni-ur-chart-loading{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-size:13px;color:#9ca3af}
.lcni-ur-chart-mode-btn{padding:4px 12px;border:1px solid #e5e7eb;border-radius:6px;background:#fff;font-size:12px;cursor:pointer}
.lcni-ur-chart-mode-btn--active{background:#2563eb;color:#fff;border-color:#2563eb}
.lcni-ur-chart-toggle{display:flex;gap:4px}
.lcni-ur-sig-filter{display:flex;gap:4px}
.lcni-ur-sig-tab{padding:4px 12px;border:1px solid #e5e7eb;border-radius:6px;background:#fff;font-size:12px;cursor:pointer}
.lcni-ur-sig-tab--active{background:#2563eb;color:#fff;border-color:#2563eb}
.lcni-ur-signals-table{width:100%;border-collapse:collapse;font-size:13px}
.lcni-ur-signals-table th{padding:8px 10px;background:#f9fafb;font-weight:600;color:#374151;text-align:left;border-bottom:1px solid #e5e7eb;white-space:nowrap}
.lcni-ur-signals-table td{padding:8px 10px;border-bottom:1px solid #f3f4f6;white-space:nowrap}
.lcni-ur-signals-table tr:last-child td{border-bottom:0}
.lcni-ur-signals-table tr:hover td{background:#f9fafb}
.lcni-ur-sig-sym{font-weight:700;color:#1d4ed8}
.lcni-ur-loading{padding:32px;text-align:center;color:#9ca3af;font-size:14px}
.lcni-ur-empty{color:#9ca3af;font-size:13px;padding:24px;text-align:center}

/* Toast */
.lcni-ur-toast{position:fixed;bottom:24px;right:24px;padding:12px 20px;border-radius:8px;font-size:14px;font-weight:500;background:#111827;color:#fff;z-index:99999;opacity:0;transform:translateY(8px);transition:opacity .25s,transform .25s;pointer-events:none;max-width:340px}
.lcni-ur-toast--show{opacity:1;transform:translateY(0)}
.lcni-ur-toast--success{background:#166534}
.lcni-ur-toast--error{background:#991b1b}

/* Scope selector */
.lcni-ur-scope-group{display:flex;gap:10px;flex-wrap:wrap}
.lcni-ur-scope-card{display:flex;align-items:center;gap:8px;padding:10px 16px;border:2px solid #e5e7eb;border-radius:9px;cursor:pointer;font-size:13px;font-weight:600;color:#374151;transition:all .15s;background:#fff;user-select:none}
.lcni-ur-scope-card:hover{border-color:#93c5fd;color:#2563eb}
.lcni-ur-scope-card--active{border-color:#2563eb;background:#eff6ff;color:#2563eb}
.lcni-ur-scope-card input{display:none}
.lcni-ur-scope-icon{font-size:16px;line-height:1}
.lcni-ur-scope-label{white-space:nowrap}
@media(max-width:640px){
    .lcni-ur-scope-group{flex-direction:column}
    .lcni-ur-mode-toggle{grid-template-columns:1fr}
    .lcni-ur-dashboard-grid,.lcni-ur-perf-row{grid-template-columns:1fr}
    .lcni-ur-stats-grid{grid-template-columns:1fr 1fr}
}
</style>';
    }

    // =========================================================================
    // SCRIPTS
    // =========================================================================

    private function render_scripts( array $sys_rules, array $user_rules ): void {
        $rules_map = [];
        foreach ( $sys_rules as $r ) {
            $rules_map[$r['id']] = [ 'name' => $r['name'], 'timeframe' => $r['timeframe'] ];
        }
        ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script>
(function(){
"use strict";

var APP    = document.getElementById("lcni-ur-app");
if (!APP) return;
var REST   = APP.dataset.rest  || "";
var NONCE  = APP.dataset.nonce || "";
var PAGE   = APP.dataset.page  || "";
var toast  = document.getElementById("lcni-ur-toast");
var RULES  = <?php echo wp_json_encode($rules_map); ?>;

/* ── Toast ────────────────────────────────────────────────── */
function showToast(msg, type) {
    if (!toast) return;
    toast.innerHTML = msg;
    toast.className = "lcni-ur-toast lcni-ur-toast--" + (type || "success");
    requestAnimationFrame(function(){ toast.classList.add("lcni-ur-toast--show"); });
    setTimeout(function(){ toast.classList.remove("lcni-ur-toast--show"); }, 3500);
}

/* ── API ──────────────────────────────────────────────────── */
function api(method, path, body) {
    var opts = { method: method, headers: { "Content-Type": "application/json", "X-WP-Nonce": NONCE }, credentials: "same-origin" };
    if (body) opts.body = JSON.stringify(body);
    return fetch(REST + path, opts).then(function(r) {
        return r.text().then(function(text) {
            try {
                var json = JSON.parse(text);
                // HTTP 4xx/5xx nhưng body là JSON → trả về như bình thường (có success:false + message)
                if (!r.ok && json.message) return { success: false, message: json.message };
                if (!r.ok) return { success: false, message: "Lỗi " + r.status + ": " + r.statusText };
                return json;
            } catch(e) {
                // Body không phải JSON (HTML error page)
                return { success: false, message: "Lỗi kết nối (" + r.status + "). Vui lòng thử lại." };
            }
        });
    });
}

/* ── Format ───────────────────────────────────────────────── */
function fmtVnd(n) { return Number(n).toLocaleString("vi-VN") + " đ"; }
function fmtPct(n) { return Number(n).toFixed(1) + "%"; }
function fmtR(n)   { return (n >= 0 ? "+" : "") + Number(n).toFixed(2) + "R"; }
function fmtDate(ts) { if (!ts) return "—"; return new Date(parseInt(ts)*1000).toLocaleDateString("vi-VN"); }

/* ══════════════════════════════════════════════════════════
   WIZARD
   ══════════════════════════════════════════════════════════ */
var wizardStep = 1;

window.lcniUrSelectRule = function(card) {
    if (!card || card.classList.contains("lcni-ur-rule-card--applied")) return;
    document.querySelectorAll(".lcni-ur-rule-card--selected").forEach(function(c){ c.classList.remove("lcni-ur-rule-card--selected"); });
    card.classList.add("lcni-ur-rule-card--selected");
    document.getElementById("lcni-ur-selected-rule-id").value = card.dataset.ruleId || "";
};

window.lcniUrSetMode = function(mode) {
    var isPaper = mode === "paper";
    var realRow = document.getElementById("lcni-real-account-row");
    var autoRow = document.getElementById("lcni-auto-order-row");
    var paperCard = document.getElementById("lcni-mode-paper");
    var realCard  = document.getElementById("lcni-mode-real");
    if (realRow) realRow.style.display = isPaper ? "none" : "";
    if (autoRow) autoRow.style.display = isPaper ? "none" : "";
    if (paperCard) paperCard.classList.toggle("lcni-ur-mode-card--active", isPaper);
    if (realCard)  realCard.classList.toggle("lcni-ur-mode-card--active", !isPaper);
    document.getElementById("lcni-paper-hint").style.display = isPaper ? "" : "none";
    document.getElementById("lcni-real-hint").style.display  = isPaper ? "none" : "";
};

window.lcniUrOnScopeChange = function(scope) {
    // Update card active state
    document.querySelectorAll('.lcni-ur-scope-card').forEach(function(c) {
        c.classList.toggle('lcni-ur-scope-card--active', c.dataset.scope === scope);
    });
    // Show/hide sub-inputs
    var wlPicker = document.getElementById('lcni-ur-watchlist-picker');
    var custWrap = document.getElementById('lcni-ur-custom-symbols-wrap');
    if (wlPicker) wlPicker.style.display = scope === 'watchlist' ? '' : 'none';
    if (custWrap) custWrap.style.display = scope === 'custom' ? '' : 'none';
    // Auto uppercase custom symbols
    var custInput = document.getElementById('lcni-ur-custom-symbols');
    if (custInput && scope === 'custom') {
        custInput.addEventListener('input', function() {
            var pos = this.selectionStart;
            this.value = this.value.toUpperCase();
            this.setSelectionRange(pos, pos);
        }, { once: true });
    }
};

window.lcniUrUpdateRiskCalc = function() {
    var capital = parseFloat(document.getElementById("lcni-ur-capital").value || 0);
    var risk    = parseFloat(document.getElementById("lcni-ur-risk-pct").value || 2);
    var alloc   = capital * risk / 100;
    var allocEl = document.getElementById("lcni-alloc-display");
    if (allocEl) allocEl.textContent = Number(alloc).toLocaleString("vi-VN") + " đ";
};

// Event delegation cho rule grid — closest() để xử lý click vào element con
var ruleGrid = document.getElementById("lcni-ur-rule-grid");
if (ruleGrid) {
    ruleGrid.addEventListener("click", function(e) {
        var card = e.target.closest(".lcni-ur-rule-card");
        if (card && !card.classList.contains("lcni-ur-rule-card--applied")) {
            window.lcniUrSelectRule(card);
        }
    });
}

// Capital display update
var capEl = document.getElementById("lcni-ur-capital");
var capDisp = document.getElementById("lcni-ur-capital-display");
if (capEl && capDisp) {
    capEl.addEventListener("input", function() {
        capDisp.textContent = Number(this.value).toLocaleString("vi-VN") + " đ";
        lcniUrUpdateRiskCalc();
    });
}

window.lcniUrNextStep = function(step, skipValidation) {
    // Validation — bỏ qua khi preset auto-jump (skipValidation=true)
    if (!skipValidation) {
        if (step === 2 && wizardStep === 1) {
            var ruleId = document.getElementById("lcni-ur-selected-rule-id").value;
            if (!ruleId) { showToast("Vui lòng chọn một Rule.", "error"); return; }
        }
        if (step === 4) {
        var capital = parseFloat((document.getElementById("lcni-ur-capital") || {}).value || 0);
        if (capital <= 0) { showToast("Vui lòng nhập vốn đầu tư.", "error"); return; }
        // Build summary
        var ruleId   = document.getElementById("lcni-ur-selected-rule-id").value;
        var isPaper  = (document.querySelector("input[name=lcni_ur_mode]:checked") || {}).value !== "real";
        var risk     = parseFloat(document.getElementById("lcni-ur-risk-pct").value || 2);
        var maxSym   = parseInt(document.getElementById("lcni-ur-max-symbols").value || 5);
        var start    = document.getElementById("lcni-ur-start-date").value;
        var ruleName = (RULES[ruleId] || {}).name || "Rule #" + ruleId;
        var alloc    = capital * risk / 100;
        var rows = [
            ["Rule", ruleName],
            ["Loại", isPaper ? "📄 Paper Trading (ảo)" : "💼 Tài khoản thật"],
            ["Vốn", Number(capital).toLocaleString("vi-VN") + " đ"],
            ["% mỗi lệnh", risk + "% → " + Number(alloc).toLocaleString("vi-VN") + " đ/lệnh"],
            ["Tối đa mã", maxSym + " mã cùng lúc"],
            ["Bắt đầu", start + " (chỉ signal từ ngày này)"],
        ];
        // Scope
        var scopeVal = (document.querySelector("input[name=lcni_ur_scope]:checked") || {}).value || "all";
        var scopeLabel = { all: "🌐 Toàn thị trường", watchlist: "📋 Watchlist", custom: "✏️ Mã cụ thể" }[scopeVal] || "Toàn thị trường";
        if (scopeVal === "watchlist") {
            var wlSel = document.getElementById("lcni-ur-watchlist-id");
            var wlName = wlSel && wlSel.selectedIndex >= 0 ? (wlSel.options[wlSel.selectedIndex].text || "") : "";
            if (wlName && wlName !== "— Chọn Watchlist —") scopeLabel += ": " + wlName;
        } else if (scopeVal === "custom") {
            var custSyms = (document.getElementById("lcni-ur-custom-symbols") || {}).value || "";
            if (custSyms) scopeLabel += ": " + custSyms;
        }
        rows.push(["Phạm vi áp dụng", scopeLabel]);
        var s = document.getElementById("lcni-ur-summary");
        if (s) s.innerHTML = rows.map(function(r){ return "<div class='lcni-ur-summary-row'><span class='lcni-ur-summary-label'>" + r[0] + "</span><span class='lcni-ur-summary-value'>" + r[1] + "</span></div>"; }).join("");
        } // end if step===4
    } // end !skipValidation

    // Switch pane
    document.querySelectorAll(".lcni-ur-wizard-pane").forEach(function(p){ p.classList.remove("lcni-ur-wizard-pane--active"); });
    var target = document.querySelector(".lcni-ur-wizard-pane[data-pane='" + step + "']");
    if (target) target.classList.add("lcni-ur-wizard-pane--active");

    // Update step indicator
    document.querySelectorAll(".lcni-ur-step").forEach(function(el){
        var s = parseInt(el.dataset.step);
        el.classList.remove("lcni-ur-step--active","lcni-ur-step--done");
        if (s === step) el.classList.add("lcni-ur-step--active");
        else if (s < step) el.classList.add("lcni-ur-step--done");
    });
    wizardStep = step;
};

// Preset rule từ URL ?rule_id=X
// Đặt SAU lcniUrSelectRule và lcniUrNextStep để đảm bảo cả 2 đã được định nghĩa
(function() {
    var appEl = document.querySelector("[data-preset-rule]");
    if (!appEl) return;
    var presetId = String(appEl.dataset.presetRule || "");
    if (!presetId || presetId === "0") return;
    var card = document.querySelector('.lcni-ur-rule-card[data-rule-id="' + presetId + '"]');
    if (!card || card.classList.contains("lcni-ur-rule-card--applied")) return;
    window.lcniUrSelectRule(card);
    window.lcniUrNextStep(2, true); // preset auto-jump, bypass validation
})();

window.lcniUrSubmit = function() {
    var ruleId   = document.getElementById("lcni-ur-selected-rule-id").value;
    var isPaper  = (document.querySelector("input[name=lcni_ur_mode]:checked") || {}).value !== "real";
    var capital  = parseFloat((document.getElementById("lcni-ur-capital") || {}).value || 0);
    var risk     = parseFloat(document.getElementById("lcni-ur-risk-pct").value || 2);
    var maxSym   = parseInt(document.getElementById("lcni-ur-max-symbols").value || 5);
    var start    = document.getElementById("lcni-ur-start-date").value;
    var account  = !isPaper ? ((document.getElementById("lcni-ur-account") || {}).value || "") : "";
    var autoOrd  = !isPaper && ((document.getElementById("lcni-ur-auto-order") || {}).checked || false);
    var scope    = (document.querySelector("input[name=lcni_ur_scope]:checked") || {}).value || "all";
    var wlId     = scope === "watchlist" ? parseInt((document.getElementById("lcni-ur-watchlist-id") || {}).value || 0) : 0;
    var custSyms = scope === "custom" ? ((document.getElementById("lcni-ur-custom-symbols") || {}).value || "").trim() : "";

    // Validate scope
    if (scope === "watchlist" && !wlId) { showToast("Vui lòng chọn Watchlist.", "error"); return; }
    if (scope === "custom" && !custSyms) { showToast("Vui lòng nhập ít nhất 1 mã cổ phiếu.", "error"); return; }
    // Normalize custom symbols: uppercase, remove spaces, dedupe
    if (custSyms) {
        custSyms = custSyms.split(",").map(function(s){ return s.trim().toUpperCase(); }).filter(Boolean).join(",");
    }

    var btn = document.getElementById("lcni-ur-submit-btn");
    if (btn) { btn.disabled = true; btn.textContent = "⏳ Đang tạo..."; }

    api("POST", "/user-rules", {
        rule_id: parseInt(ruleId), is_paper: isPaper, capital: capital,
        risk_per_trade: risk, max_symbols: maxSym, start_date: start,
        account_id: account, auto_order: autoOrd,
        symbol_scope: scope, watchlist_id: wlId, custom_symbols: custSyms,
    }).then(function(res) {
        if (res.success) {
            showToast("✅ Đã bắt đầu áp dụng Rule!", "success");
            setTimeout(function(){ window.location.href = PAGE; }, 1200);
        } else {
            showToast("❌ " + (res.message || "Lỗi tạo UserRule."), "error");
            if (btn) { btn.disabled = false; btn.textContent = "🚀 Bắt đầu áp dụng"; }
        }
    }).catch(function() {
        showToast("❌ Lỗi kết nối.", "error");
        if (btn) { btn.disabled = false; btn.textContent = "🚀 Bắt đầu áp dụng"; }
    });
};

/* ══════════════════════════════════════════════════════════
   DASHBOARD ACTIONS
   ══════════════════════════════════════════════════════════ */
document.addEventListener("click", function(e) {
    // Pause/resume
    var pauseBtn = e.target.closest(".lcni-ur-pause-btn");
    if (pauseBtn) {
        var urId = pauseBtn.dataset.urId;
        api("PUT", "/user-rules/" + urId + "/pause").then(function(res) {
            if (res.success) {
                showToast("✅ Đã cập nhật trạng thái.", "success");
                setTimeout(function(){ location.reload(); }, 800);
            }
        });
        return;
    }
    // Delete
    var delBtn = e.target.closest(".lcni-ur-delete-btn");
    if (delBtn) {
        var name = delBtn.dataset.ruleName || "Rule này";
        if (!confirm("Xác nhận xoá " + name + "?\nToàn bộ signals và lịch sử sẽ bị xoá.")) return;
        api("DELETE", "/user-rules/" + delBtn.dataset.urId).then(function(res) {
            if (res.success) {
                showToast("🗑 Đã xoá.", "success");
                setTimeout(function(){ location.reload(); }, 800);
            }
        });
        return;
    }
    // Chart mode toggle
    var modeBtn = e.target.closest(".lcni-ur-chart-mode-btn");
    if (modeBtn) {
        document.querySelectorAll(".lcni-ur-chart-mode-btn").forEach(function(b){ b.classList.remove("lcni-ur-chart-mode-btn--active"); });
        modeBtn.classList.add("lcni-ur-chart-mode-btn--active");
        if (window._lcniUrChart && window._lcniUrEquityData) {
            renderEquityChart(window._lcniUrEquityData, modeBtn.dataset.mode);
        }
        return;
    }
    // Signal filter tab
    var sigTab = e.target.closest(".lcni-ur-sig-tab");
    if (sigTab) {
        document.querySelectorAll(".lcni-ur-sig-tab").forEach(function(t){ t.classList.remove("lcni-ur-sig-tab--active"); });
        sigTab.classList.add("lcni-ur-sig-tab--active");
        if (window._lcniUrDetailId) loadSignals(window._lcniUrDetailId, sigTab.dataset.filter || "");
        return;
    }
});

/* ══════════════════════════════════════════════════════════
   DETAIL: Equity Chart
   ══════════════════════════════════════════════════════════ */
function renderEquityChart(points, mode) {
    mode = mode || "vnd";
    var canvas = document.getElementById("lcni-ur-equity-chart");
    var loader = document.getElementById("lcni-ur-chart-loading");
    if (!canvas) return;
    if (loader) loader.style.display = "none";

    var labels = points.map(function(p){ return p.date || ""; });
    var data   = points.map(function(p){ return mode === "vnd" ? p.cumulative_vnd : p.cumulative_r; });
    var initialCap = window._lcniUrCapital || 0;
    if (mode === "vnd") data = [initialCap].concat(data.map(function(v){ return initialCap + v; }));
    else data = [0].concat(data);
    labels = ["Bắt đầu"].concat(labels);

    var lastVal = data[data.length - 1];
    var lineColor = lastVal >= (mode === "vnd" ? (window._lcniUrCapital || 0) : 0) ? "#16a34a" : "#dc2626";

    if (window._lcniUrChart) window._lcniUrChart.destroy();
    window._lcniUrChart = new Chart(canvas, {
        type: "line",
        data: {
            labels: labels,
            datasets: [{
                data: data, borderColor: lineColor, borderWidth: 2, fill: true,
                backgroundColor: lineColor + "18", tension: 0.3, pointRadius: data.length > 50 ? 0 : 3,
                pointHoverRadius: 4,
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false, animation: false,
            plugins: { legend: { display: false }, tooltip: {
                callbacks: {
                    label: function(ctx) {
                        var v = ctx.parsed.y;
                        return mode === "vnd" ? Number(v).toLocaleString("vi-VN") + " đ" : (v >= 0 ? "+" : "") + v.toFixed(2) + "R";
                    }
                }
            }},
            scales: {
                y: { grid: { color: "rgba(0,0,0,.05)" }, ticks: { font: { size: 11 } } },
                x: { grid: { display: false }, ticks: { maxTicksLimit: 8, font: { size: 11 } } }
            }
        }
    });
}

/* ══════════════════════════════════════════════════════════
   DETAIL: Signals table
   ══════════════════════════════════════════════════════════ */
function loadSignals(urId, status) {
    var wrap = document.getElementById("lcni-ur-signals-wrap");
    if (!wrap) return;
    wrap.innerHTML = "<div class='lcni-ur-loading'>Đang tải...</div>";
    var qs = status ? "?status=" + encodeURIComponent(status) : "";
    api("GET", "/user-rules/" + urId + "/signals" + qs).then(function(res) {
        if (!res.success || !res.signals.length) {
            wrap.innerHTML = "<p class='lcni-ur-empty'>Chưa có lệnh nào" + (status ? " (bộ lọc: " + status + ")" : "") + ".</p>";
            return;
        }
        var rows = res.signals.map(function(s) {
            var ep   = parseFloat(s.entry_price || 0);
            var xp   = parseFloat(s.exit_price  || 0);
            var r    = parseFloat(s.r_multiple || s.final_r || 0);
            var pnl  = parseFloat(s.pnl_vnd || 0);
            var rCls = r >= 0 ? "lcni-ur-pos" : "lcni-ur-neg";
            var pCls = pnl >= 0 ? "lcni-ur-pos" : "lcni-ur-neg";
            var epFmt = ep < 1000 && ep > 0 ? (ep * 1000).toFixed(0) : ep.toFixed(0);
            var xpFmt = xp > 0 ? (xp < 1000 ? (xp*1000).toFixed(0) : xp.toFixed(0)) : "—";
            var statusTag = s.status === "open"
                ? "<span style='color:#2563eb'>● Đang mở</span>"
                : "<span style='color:#6b7280'>● Đã đóng</span>";
            return "<tr>"
                + "<td><span class='lcni-ur-sig-sym'>" + esc(s.symbol) + "</span></td>"
                + "<td>" + fmtDate(s.entry_time) + "</td>"
                + "<td>" + epFmt + "</td>"
                + "<td>" + xpFmt + "</td>"
                + "<td>" + (s.shares || 0) + "</td>"
                + "<td class='" + rCls + "'>" + fmtR(r) + "</td>"
                + "<td class='" + pCls + "'>" + (pnl ? (pnl >= 0 ? "+" : "") + Number(pnl).toLocaleString("vi-VN") + "đ" : "—") + "</td>"
                + "<td>" + statusTag + "</td>"
                + "<td>" + esc(s.exit_reason || "—") + "</td>"
                + "</tr>";
        }).join("");
        wrap.innerHTML = "<div style='overflow-x:auto'><table class='lcni-ur-signals-table'>"
            + "<thead><tr><th>Mã</th><th>Ngày vào</th><th>Giá vào</th><th>Giá ra</th>"
            + "<th>Số cổ</th><th>R</th><th>P&L (đ)</th><th>Trạng thái</th><th>Lý do thoát</th></tr></thead>"
            + "<tbody>" + rows + "</tbody></table></div>";
    }).catch(function() {
        wrap.innerHTML = "<p class='lcni-ur-empty'>Lỗi tải dữ liệu.</p>";
    });
}

/* ── Escape ─────────────────────────────────────────────── */
function esc(v) { return String(v == null ? "" : v).replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;"); }

/* ── Init detail page ──────────────────────────────────── */
if (window._lcniUrDetailId) {
    var urId = window._lcniUrDetailId;
    // Load equity
    api("GET", "/user-rules/" + urId + "/equity").then(function(res) {
        if (res.success && res.points && res.points.length) {
            window._lcniUrEquityData = res.points;
            renderEquityChart(res.points, "vnd");
        } else {
            var loader = document.getElementById("lcni-ur-chart-loading");
            if (loader) loader.textContent = "Chưa có dữ liệu (signal chưa đóng)";
        }
    });
    // Load signals
    loadSignals(urId, "open");
}

})();
</script>
        <?php
    }
    private function render_login_gate(): string {
        $current_url = ( is_ssl() ? 'https' : 'http' ) . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
        $redirect_to = esc_url_raw( $current_url );

        if ( shortcode_exists( 'lcni_member_login' ) ) {
            $prev = $_GET['lcni_redirect_to'] ?? null;
            $_GET['lcni_redirect_to'] = $redirect_to;
            $login_html = do_shortcode( '[lcni_member_login]' );
            if ( $prev === null ) unset( $_GET['lcni_redirect_to'] );
            else $_GET['lcni_redirect_to'] = $prev;
            return '<div class="lcni-ur-login-gate">' . $login_html . '</div>';
        }

        $login_url = get_option('lcni_central_login_url', '');
        if ( ! $login_url ) {
            $ls  = get_option('lcni_member_login_settings', []);
            $pid = absint($ls['login_page_id'] ?? 0);
            if ($pid) $login_url = get_permalink($pid);
        }
        $login_href = $login_url
            ? add_query_arg('lcni_redirect_to', rawurlencode($redirect_to), $login_url)
            : wp_login_url($redirect_to);

        return '<div class="lcni-ur-login-gate">
            <div class="lcni-ur-empty-state">
                <div class="lcni-ur-empty-icon">🔒</div>
                <h3>Đăng nhập để sử dụng tính năng này</h3>
                <p>Auto Apply Rule / Paper Trading yêu cầu tài khoản.</p>
                <a href="' . esc_url($login_href) . '" class="lcni-ur-btn lcni-ur-btn--primary">🔑 Đăng nhập</a>
            </div>
        </div>';
    }

}
