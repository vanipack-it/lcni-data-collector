<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * [lcni_upgrade_request]
 *
 * Attrs:
 *   flow="both|broker|payment"
 *   to_package_id="0"
 *   show_status="1"
 *   title="Nâng cấp gói"
 *   button_text="Tạo yêu cầu nâng cấp"
 */
class LCNI_Upgrade_Request_Shortcode {

    private LCNI_Upgrade_Request_Service    $service;
    private LCNI_Upgrade_Request_Repository $repo;
    private LCNI_SaaS_Service               $saas;

    public function __construct(
        LCNI_Upgrade_Request_Service    $service,
        LCNI_Upgrade_Request_Repository $repo,
        LCNI_SaaS_Service               $saas
    ) {
        $this->service = $service;
        $this->repo    = $repo;
        $this->saas    = $saas;
        add_shortcode( 'lcni_upgrade_request', [ $this, 'render' ] );
        add_action( 'wp_ajax_lcni_submit_upgrade_request', [ $this, 'handle_ajax' ] );
        add_action( 'wp_ajax_lcni_upload_proof',           [ $this, 'handle_upload_proof' ] );
    }

    // ─── AJAX handlers ───────────────────────────────────────────────────────

    public function handle_ajax(): void {
        check_ajax_referer( 'lcni_upgrade_request_nonce', '_nonce' );
        $result = $this->service->submit( [
            'full_name'         => sanitize_text_field( $_POST['full_name']         ?? '' ),
            'phone'             => sanitize_text_field( $_POST['phone']             ?? '' ),
            'email'             => sanitize_email(      $_POST['email']             ?? '' ),
            'broker_company'    => sanitize_text_field( $_POST['broker_company']    ?? '' ),
            'broker_id'         => sanitize_text_field( $_POST['broker_id']         ?? '' ),
            'flow'              => sanitize_key(        $_POST['flow']              ?? 'broker' ),
            'duration_months'   => (int)               ($_POST['duration_months']   ?? 0),
            'payment_proof_url' => esc_url_raw(         $_POST['payment_proof_url'] ?? '' ),
            'to_package_id'     => (int)               ($_POST['to_package_id']     ?? 0),
        ] );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }
        wp_send_json_success( [ 'message' => 'Yêu cầu đã được gửi! Chúng tôi sẽ liên hệ sớm nhất có thể.' ] );
    }

    public function handle_upload_proof(): void {
        check_ajax_referer( 'lcni_upgrade_request_nonce', '_nonce' );
        $result = $this->service->upload_proof( (int)($_POST['request_id'] ?? 0) );
        if ( $result['ok'] ) {
            wp_send_json_success( [ 'url' => $result['url'] ] );
        } else {
            wp_send_json_error( [ 'message' => $result['message'] ] );
        }
    }

    // ─── Render ──────────────────────────────────────────────────────────────

    public function render( $atts ): string {
        $atts = shortcode_atts( [
            'flow'          => 'both',
            'to_package_id' => '0',
            'show_status'   => '1',
            'title'         => 'Nâng cấp gói',
            'button_text'   => '+ Tạo yêu cầu nâng cấp',
        ], $atts, 'lcni_upgrade_request' );

        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return '<div class="lcni-ur-notice--warn">Vui lòng <a href="' . esc_url( wp_login_url( get_permalink() ) ) . '">đăng nhập</a> để gửi yêu cầu nâng cấp.</div>';
        }

        $user          = wp_get_current_user();
        $username      = $user->user_login;
        $allowed_flow  = in_array( $atts['flow'], ['both','broker','payment'] ) ? $atts['flow'] : 'both';
        $to_package_id = (int) $atts['to_package_id'];

        // Lấy gói hiện tại của user để loại khỏi danh sách
        $current_pkg   = $this->saas->get_current_user_package_info();
        $current_pkg_id = $current_pkg ? (int)$current_pkg['package_id'] : 0;

        // Danh sách gói (bỏ gói hiện tại)
        $all_packages = array_values( array_filter(
            $this->saas->get_packages(),
            function($p) use ($current_pkg_id) {
                return ! empty($p['is_active']) && (int)$p['id'] !== $current_pkg_id;
            }
        ) );

        $broker_list  = array_filter( array_map( 'trim', explode( "\n", get_option( 'lcni_broker_companies', '' ) ) ) );
        $prices       = LCNI_Upgrade_Request_Service::get_all_prices();
        $payment_info = $this->service->get_payment_info();
        $durations    = array( 1 => '1 tháng', 3 => '3 tháng', 6 => '6 tháng', 12 => '1 năm' );

        // Lịch sử
        $requests = $this->repo->get_by_user( $user_id );

        // Prices JSON cho JS
        $prices_json = array();
        foreach ( $all_packages as $pkg ) {
            $pid = (int)$pkg['id'];
            $prices_json[$pid] = array();
            foreach ( array(1,3,6,12) as $m ) {
                $prices_json[$pid][$m] = (float)( isset($prices[$pid][$m]) ? $prices[$pid][$m] : 0 );
            }
        }

        ob_start();
        $this->render_styles();
        ?>
        <div class="lcni-ur-wrap">

            <!-- Header: title + nút mở modal -->
            <div class="lcni-ur-topbar">
                <h3 class="lcni-ur-title"><?php echo esc_html($atts['title']); ?></h3>
                <button class="lcni-ur-open-btn" id="lcni-ur-open-modal">
                    <?php echo esc_html($atts['button_text']); ?>
                </button>
            </div>

            <!-- Bảng lịch sử -->
            <?php $this->render_history_table( $requests ); ?>

            <!-- MODAL -->
            <div class="lcni-ur-modal-overlay" id="lcni-ur-modal" style="display:none">
                <div class="lcni-ur-modal-box">
                    <button class="lcni-ur-modal-close" id="lcni-ur-close-modal">✕</button>
                    <h3 class="lcni-ur-modal-title"><?php echo esc_html($atts['title']); ?></h3>

                    <?php if ( $allowed_flow === 'both' ): ?>
                    <div class="lcni-ur-flow-tabs" id="lcni-ur-flow-tabs">
                        <button class="lcni-ur-flow-tab active" data-flow="broker">
                            🏦 Gán ID Cộng tác viên hoặc Broker
                            <span class="lcni-ur-flow-tab-sub">Chỉ cần có tài khoản chứng khoán</span>
                        </button>
                        <button class="lcni-ur-flow-tab" data-flow="payment">
                            💳 Trả phí trực tiếp
                            <span class="lcni-ur-flow-tab-sub">Thông qua chuyển khoản ngân hàng</span>
                        </button>
                    </div>
                    <?php endif; ?>

                    <!-- LUỒNG 1: BROKER -->
                    <?php if ( in_array($allowed_flow, ['both','broker']) ): ?>
                    <div class="lcni-ur-panel" id="lcni-panel-broker" <?php echo $allowed_flow === 'payment' ? 'style="display:none"' : ''; ?>>
                        <form id="lcni-form-broker" novalidate>
                            <?php wp_nonce_field( 'lcni_upgrade_request_nonce', '_nonce' ); ?>
                            <input type="hidden" name="action" value="lcni_submit_upgrade_request">
                            <input type="hidden" name="flow"   value="broker">
                            <div class="lcni-ur-grid">
                                <div class="lcni-ur-field">
                                    <label>Họ và tên <span class="req">*</span></label>
                                    <input type="text" name="full_name" value="<?php echo esc_attr($user->display_name); ?>" required>
                                </div>
                                <div class="lcni-ur-field">
                                    <label>Số điện thoại <span class="req">*</span></label>
                                    <input type="tel" name="phone" placeholder="0901234567" required>
                                </div>
                                <div class="lcni-ur-field">
                                    <label>Email <span class="req">*</span></label>
                                    <input type="email" name="email" value="<?php echo esc_attr($user->user_email); ?>" required>
                                </div>
                                <div class="lcni-ur-field">
                                    <label>Công ty chứng khoán</label>
                                    <?php if ( ! empty($broker_list) ): ?>
                                        <select name="broker_company">
                                            <option value="">— Chọn công ty Chứng khoán —</option>
                                            <?php foreach ($broker_list as $b): ?>
                                                <option value="<?php echo esc_attr($b); ?>"><?php echo esc_html($b); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php else: ?>
                                        <input type="text" name="broker_company" placeholder="Tên công ty chứng khoán">
                                    <?php endif; ?>
                                </div>
                                <div class="lcni-ur-field">
                                    <label>Tài khoản CK</label>
                                    <input type="text" name="broker_id" placeholder="Ví dụ: VCSC123456">
                                    <span class="hint">Mã tài khoản được cấp tại công ty CK liên kết</span>
                                </div>
                                <?php if ( $to_package_id > 0 ): ?>
                                    <input type="hidden" name="to_package_id" value="<?php echo $to_package_id; ?>">
                                <?php else: ?>
                                <div class="lcni-ur-field lcni-ur-field--full">
                                    <label>Gói muốn nâng cấp <span class="req">*</span></label>
                                    <select name="to_package_id" required>
                                        <option value="">— Chọn gói —</option>
                                        <?php foreach ($all_packages as $pkg): ?>
                                            <option value="<?php echo (int)$pkg['id']; ?>"><?php echo esc_html($pkg['package_name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <?php endif; ?>
                            </div>
                            <div class="lcni-ur-actions">
                                <button type="submit" class="lcni-ur-btn">
                                    <span class="btn-text">Gửi yêu cầu</span>
                                    <span class="btn-loading" style="display:none">⏳ Đang gửi...</span>
                                </button>
                            </div>
                            <div class="lcni-ur-msg" style="display:none"></div>
                        </form>
                    </div>
                    <?php endif; ?>

                    <!-- LUỒNG 2: PAYMENT -->
                    <?php if ( in_array($allowed_flow, ['both','payment']) ): ?>
                    <div class="lcni-ur-panel" id="lcni-panel-payment" <?php echo $allowed_flow !== 'payment' ? 'style="display:none"' : ''; ?>>

                        <!-- Bước 1 -->
                        <div id="pay-step-1">
                            <div class="lcni-pay-steps">
                                <div class="lcni-pay-step-item active"><span class="sn">1</span><span class="sl">Thông tin</span></div>
                                <div class="lcni-pay-step-line"></div>
                                <div class="lcni-pay-step-item"><span class="sn">2</span><span class="sl">Thanh toán</span></div>
                                <div class="lcni-pay-step-line"></div>
                                <div class="lcni-pay-step-item"><span class="sn">3</span><span class="sl">Hoàn tất</span></div>
                            </div>
                            <div class="lcni-ur-grid" style="margin-top:16px">
                                <div class="lcni-ur-field">
                                    <label>Họ và tên <span class="req">*</span></label>
                                    <input type="text" id="p-name" value="<?php echo esc_attr($user->display_name); ?>">
                                </div>
                                <div class="lcni-ur-field">
                                    <label>Số điện thoại <span class="req">*</span></label>
                                    <input type="tel" id="p-phone" placeholder="0901234567">
                                </div>
                                <div class="lcni-ur-field">
                                    <label>Email <span class="req">*</span></label>
                                    <input type="email" id="p-email" value="<?php echo esc_attr($user->user_email); ?>">
                                </div>
                                <div class="lcni-ur-field">
                                    <label>Gói muốn nâng cấp <span class="req">*</span></label>
                                    <?php if ( $to_package_id > 0 ):
                                        $found_pkg = null;
                                        foreach ($all_packages as $pk) { if ((int)$pk['id'] === $to_package_id) { $found_pkg = $pk; break; } }
                                    ?>
                                        <input type="hidden" id="p-pkg" value="<?php echo $to_package_id; ?>">
                                        <input type="text" value="<?php echo esc_attr($found_pkg ? $found_pkg['package_name'] : ''); ?>" disabled style="background:#f9fafb">
                                    <?php else: ?>
                                        <select id="p-pkg">
                                            <option value="">— Chọn gói —</option>
                                            <?php foreach ($all_packages as $pkg): ?>
                                                <option value="<?php echo (int)$pkg['id']; ?>"
                                                        data-prices="<?php echo esc_attr(json_encode(isset($prices_json[(int)$pkg['id']]) ? $prices_json[(int)$pkg['id']] : array())); ?>">
                                                    <?php echo esc_html($pkg['package_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php endif; ?>
                                </div>
                                <div class="lcni-ur-field lcni-ur-field--full">
                                    <label>Thời hạn <span class="req">*</span></label>
                                    <div class="lcni-dur-grid" id="dur-grid">
                                        <?php foreach ($durations as $months => $label): ?>
                                        <label class="lcni-dur-card" data-months="<?php echo $months; ?>">
                                            <input type="radio" name="dur" value="<?php echo $months; ?>">
                                            <div class="dur-label"><?php echo $label; ?></div>
                                            <div class="dur-price" data-months="<?php echo $months; ?>">—</div>
                                        </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="lcni-ur-actions">
                                <button class="lcni-ur-btn" id="pay-next-1">Tiếp theo →</button>
                            </div>
                            <div id="pay-msg-1" class="lcni-ur-msg" style="display:none"></div>
                        </div>

                        <!-- Bước 2 -->
                        <div id="pay-step-2" style="display:none">
                            <div class="lcni-pay-steps">
                                <div class="lcni-pay-step-item done"><span class="sn">✓</span><span class="sl">Thông tin</span></div>
                                <div class="lcni-pay-step-line done"></div>
                                <div class="lcni-pay-step-item active"><span class="sn">2</span><span class="sl">Thanh toán</span></div>
                                <div class="lcni-pay-step-line"></div>
                                <div class="lcni-pay-step-item"><span class="sn">3</span><span class="sl">Hoàn tất</span></div>
                            </div>
                            <div class="lcni-pay-layout">
                                <div class="lcni-pay-qr-col">
                                    <?php if (!empty($payment_info['qr_url'])): ?>
                                        <img src="<?php echo esc_url($payment_info['qr_url']); ?>" class="lcni-qr-img" alt="QR">
                                    <?php else: ?>
                                        <div class="lcni-qr-placeholder">📷 QR Code<br><small>Chưa cấu hình</small></div>
                                    <?php endif; ?>
                                    <div class="lcni-bank-info">
                                        <?php if (!empty($payment_info['bank_name'])): ?><div class="bank-row"><span>Ngân hàng</span><strong><?php echo esc_html($payment_info['bank_name']); ?></strong></div><?php endif; ?>
                                        <?php if (!empty($payment_info['account_no'])): ?><div class="bank-row"><span>Số TK</span><strong><?php echo esc_html($payment_info['account_no']); ?></strong></div><?php endif; ?>
                                        <?php if (!empty($payment_info['account_name'])): ?><div class="bank-row"><span>Chủ TK</span><strong><?php echo esc_html($payment_info['account_name']); ?></strong></div><?php endif; ?>
                                    </div>
                                </div>
                                <div class="lcni-pay-info-col">
                                    <div class="lcni-amount-card">
                                        <div class="a-label">Số tiền</div>
                                        <div class="a-value" id="pay-amount">—</div>
                                        <div class="a-label" style="margin-top:12px">Nội dung chuyển khoản</div>
                                        <div class="a-content" id="pay-content">—</div>
                                        <button type="button" class="a-copy" id="pay-copy">📋 Sao chép</button>
                                    </div>
                                    <div class="lcni-upload-box">
                                        <div class="upload-label">📎 Đính kèm ảnh chuyển khoản</div>
                                        <div class="upload-area" id="upload-area">
                                            <input type="file" id="proof-file" accept="image/*,application/pdf" style="display:none">
                                            <div id="upload-prompt" onclick="document.getElementById('proof-file').click()" style="cursor:pointer;padding:12px 0">
                                                <div style="font-size:24px">📷</div>
                                                <div style="font-size:12px;color:#6b7280">Click để chọn ảnh (JPG/PNG/PDF, max 5MB)</div>
                                            </div>
                                            <div id="upload-prog" style="display:none">
                                                <div class="prog-bar"><div class="prog-fill" id="prog-fill"></div></div>
                                                <div class="prog-label" id="prog-label">Đang tải lên...</div>
                                            </div>
                                            <div id="upload-ok" style="display:none;text-align:center">
                                                <div style="color:#16a34a;font-size:13px;font-weight:600">✅ Tải lên thành công</div>
                                                <img id="proof-preview" src="" style="max-width:100px;max-height:70px;border-radius:6px;margin-top:6px;border:1px solid #e5e7eb">
                                                <button type="button" id="remove-proof" style="display:block;font-size:11px;color:#dc2626;background:none;border:none;cursor:pointer;margin:4px auto 0">✕ Xóa</button>
                                            </div>
                                        </div>
                                        <input type="hidden" id="proof-url" value="">
                                    </div>
                                </div>
                            </div>
                            <div class="lcni-ur-actions" style="gap:10px">
                                <button class="lcni-ur-btn lcni-ur-btn--ghost" id="pay-back-1">← Quay lại</button>
                                <button class="lcni-ur-btn" id="pay-submit">
                                    <span class="btn-text">Gửi yêu cầu</span>
                                    <span class="btn-loading" style="display:none">⏳ Đang gửi...</span>
                                </button>
                            </div>
                            <div id="pay-msg-2" class="lcni-ur-msg" style="display:none"></div>
                        </div>

                        <!-- Bước 3 -->
                        <div id="pay-step-3" style="display:none;text-align:center;padding:32px 0">
                            <div style="font-size:48px">🎉</div>
                            <h3 style="margin:12px 0 6px;color:#111827">Yêu cầu đã được gửi!</h3>
                            <p style="color:#6b7280;font-size:14px">Admin sẽ xác nhận và nâng cấp tài khoản sớm nhất có thể.</p>
                        </div>
                    </div>
                    <?php endif; ?>
                </div><!-- /.modal-box -->
            </div><!-- /.modal-overlay -->

            <!-- MODAL TIẾN TRÌNH -->
            <div class="lcni-ur-modal-overlay" id="lcni-progress-modal" style="display:none">
                <div class="lcni-ur-modal-box" style="max-width:480px">
                    <button class="lcni-ur-modal-close" id="lcni-close-progress">✕</button>
                    <h3 class="lcni-ur-modal-title">Tiến trình yêu cầu</h3>
                    <div id="lcni-progress-content"></div>
                </div>
            </div>
        </div>

        <script>
        (function(){
            var AJAX  = '<?php echo esc_url(admin_url('admin-ajax.php')); ?>';
            var NONCE = '<?php echo wp_create_nonce('lcni_upgrade_request_nonce'); ?>';
            var UNAME = '<?php echo esc_js($username); ?>';
            var PRICES= <?php echo json_encode($prices_json); ?>;
            var proofUrl = '';
            var curDur   = 0;
            var curPkgId = <?php echo $to_package_id > 0 ? $to_package_id : 0; ?>;
            var curPkgName = '';

            // ── Open/close modal ──
            function openModal(id) { document.getElementById(id).style.display = 'flex'; document.body.style.overflow = 'hidden'; }
            function closeModal(id) { document.getElementById(id).style.display = 'none'; document.body.style.overflow = ''; }

            var openBtn = document.getElementById('lcni-ur-open-modal');
            if (openBtn) openBtn.addEventListener('click', function(){ openModal('lcni-ur-modal'); });
            var closeBtn = document.getElementById('lcni-ur-close-modal');
            if (closeBtn) closeBtn.addEventListener('click', function(){ closeModal('lcni-ur-modal'); });
            document.getElementById('lcni-ur-modal').addEventListener('click', function(e){ if(e.target===this) closeModal('lcni-ur-modal'); });

            // ── Flow tabs ──
            document.querySelectorAll('.lcni-ur-flow-tab').forEach(function(btn){
                btn.addEventListener('click', function(){
                    document.querySelectorAll('.lcni-ur-flow-tab').forEach(function(b){ b.classList.remove('active'); });
                    btn.classList.add('active');
                    var f = btn.dataset.flow;
                    var bp = document.getElementById('lcni-panel-broker');
                    var pp = document.getElementById('lcni-panel-payment');
                    if(bp) bp.style.display = f==='broker' ? '' : 'none';
                    if(pp) pp.style.display = f==='payment' ? '' : 'none';
                });
            });

            // ── Form 1 submit ──
            var f1 = document.getElementById('lcni-form-broker');
            if (f1) f1.addEventListener('submit', function(e){
                e.preventDefault();
                submitAjax(f1, f1.querySelector('.btn-text'), f1.querySelector('.btn-loading'), f1.querySelector('.lcni-ur-msg'), function(){
                    closeModal('lcni-ur-modal'); setTimeout(function(){ location.reload(); }, 800);
                });
            });

            // ── Prices update ──
            function fmt(n){ return n > 0 ? n.toLocaleString('vi-VN') + ' đ' : 'Liên hệ'; }
            function updatePrices(){
                if (!curPkgId) return;
                var pp = PRICES[curPkgId] || {};
                document.querySelectorAll('#dur-grid .dur-price').forEach(function(el){
                    el.textContent = fmt(pp[parseInt(el.dataset.months)] || 0);
                });
            }
            var pkgSel = document.getElementById('p-pkg');
            if (pkgSel && pkgSel.tagName === 'SELECT') {
                pkgSel.addEventListener('change', function(){
                    curPkgId = parseInt(this.value) || 0;
                    var opt = this.options[this.selectedIndex];
                    curPkgName = opt ? opt.text : '';
                    updatePrices();
                });
            } else if (pkgSel) {
                // hidden input — find pkg name from packages
                <?php foreach($all_packages as $pk): ?>
                if (curPkgId === <?php echo (int)$pk['id']; ?>) curPkgName = <?php echo json_encode($pk['package_name']); ?>;
                <?php endforeach; ?>
                updatePrices();
            }

            // Duration select
            document.querySelectorAll('#dur-grid input[type=radio]').forEach(function(r){
                r.addEventListener('change', function(){
                    curDur = parseInt(this.value);
                    document.querySelectorAll('#dur-grid .lcni-dur-card').forEach(function(c){ c.classList.remove('selected'); });
                    this.closest('.lcni-dur-card').classList.add('selected');
                });
            });

            // ── Step 1 → 2 ──
            var next1 = document.getElementById('pay-next-1');
            if (next1) next1.addEventListener('click', function(){
                var msg = document.getElementById('pay-msg-1');
                msg.style.display = 'none';
                if (!document.getElementById('p-name').value.trim()) { showMsg(msg, 'Vui lòng nhập họ tên.'); return; }
                if (!document.getElementById('p-phone').value.trim()) { showMsg(msg, 'Vui lòng nhập SĐT.'); return; }
                if (!document.getElementById('p-email').value.trim()) { showMsg(msg, 'Vui lòng nhập email.'); return; }
                var pkEl = document.getElementById('p-pkg');
                if (!curPkgId && pkEl && pkEl.tagName==='SELECT') curPkgId = parseInt(pkEl.value)||0;
                if (!curPkgId) { showMsg(msg, 'Vui lòng chọn gói.'); return; }
                if (!curDur) { showMsg(msg, 'Vui lòng chọn thời hạn.'); return; }
                if (!curPkgName && pkEl && pkEl.tagName==='SELECT') { curPkgName = pkEl.options[pkEl.selectedIndex] ? pkEl.options[pkEl.selectedIndex].text : ''; }
                var amount = (PRICES[curPkgId]||{})[curDur] || 0;
                document.getElementById('pay-amount').textContent = fmt(amount);
                var raw = 'Nang cap ' + UNAME + ' len ' + curPkgName;
                var content = raw.normalize('NFD').replace(/[\u0300-\u036f]/g,'').replace(/đ/gi,'d').replace(/[^a-zA-Z0-9 ]/g,'').trim();
                document.getElementById('pay-content').textContent = content;
                document.getElementById('pay-step-1').style.display = 'none';
                document.getElementById('pay-step-2').style.display = '';
            });

            // Copy
            var copyBtn = document.getElementById('pay-copy');
            if (copyBtn) copyBtn.addEventListener('click', function(){
                var t = document.getElementById('pay-content').textContent;
                if (navigator.clipboard) navigator.clipboard.writeText(t).then(function(){ copyBtn.textContent = '✅ Đã sao chép'; setTimeout(function(){ copyBtn.textContent = '📋 Sao chép'; }, 2000); });
            });

            // Back
            var back1 = document.getElementById('pay-back-1');
            if (back1) back1.addEventListener('click', function(){
                document.getElementById('pay-step-2').style.display = 'none';
                document.getElementById('pay-step-1').style.display = '';
            });

            // Upload proof
            var fileInput = document.getElementById('proof-file');
            if (fileInput) fileInput.addEventListener('change', function(){
                var file = fileInput.files[0];
                if (!file) return;
                if (file.size > 5*1024*1024) { alert('File quá lớn (tối đa 5MB)'); return; }
                document.getElementById('upload-prompt').style.display = 'none';
                document.getElementById('upload-prog').style.display = '';
                document.getElementById('upload-ok').style.display = 'none';
                var fill = document.getElementById('prog-fill');
                var pct = 0;
                var timer = setInterval(function(){ pct = Math.min(pct+15, 80); fill.style.width = pct + '%'; }, 100);
                var fd = new FormData();
                fd.append('action', 'lcni_upload_proof');
                fd.append('_nonce', NONCE);
                fd.append('request_id', '0');
                fd.append('proof_file', file);
                fetch(AJAX, { method:'POST', body:fd, credentials:'same-origin' })
                    .then(function(r){ return r.json(); })
                    .then(function(d){
                        clearInterval(timer); fill.style.width = '100%';
                        setTimeout(function(){
                            document.getElementById('upload-prog').style.display = 'none';
                            if (d.success) {
                                proofUrl = d.data.url;
                                document.getElementById('proof-url').value = proofUrl;
                                document.getElementById('proof-preview').src = proofUrl;
                                document.getElementById('upload-ok').style.display = '';
                            } else {
                                document.getElementById('upload-prompt').style.display = '';
                                alert(d.data.message || 'Upload lỗi.');
                            }
                        }, 300);
                    });
            });

            var removeProof = document.getElementById('remove-proof');
            if (removeProof) removeProof.addEventListener('click', function(){
                proofUrl = ''; document.getElementById('proof-url').value = '';
                document.getElementById('upload-ok').style.display = 'none';
                document.getElementById('upload-prompt').style.display = '';
                fileInput.value = '';
            });

            // ── Pay submit ──
            var paySubmit = document.getElementById('pay-submit');
            if (paySubmit) paySubmit.addEventListener('click', function(){
                var msg = document.getElementById('pay-msg-2');
                var fd = new FormData();
                fd.append('action', 'lcni_submit_upgrade_request');
                fd.append('_nonce', NONCE);
                fd.append('flow', 'payment');
                fd.append('full_name', document.getElementById('p-name').value);
                fd.append('phone', document.getElementById('p-phone').value);
                fd.append('email', document.getElementById('p-email').value);
                fd.append('to_package_id', curPkgId);
                fd.append('duration_months', curDur);
                fd.append('payment_proof_url', proofUrl);
                paySubmit.querySelector('.btn-text').style.display = 'none';
                paySubmit.querySelector('.btn-loading').style.display = 'inline';
                paySubmit.disabled = true;
                fetch(AJAX, { method:'POST', body:fd, credentials:'same-origin' })
                    .then(function(r){ return r.json(); })
                    .then(function(d){
                        if (d.success) {
                            document.getElementById('pay-step-2').style.display = 'none';
                            document.getElementById('pay-step-3').style.display = '';
                            setTimeout(function(){ closeModal('lcni-ur-modal'); location.reload(); }, 2500);
                        } else {
                            showMsg(msg, d.data.message || 'Có lỗi xảy ra.');
                            paySubmit.querySelector('.btn-text').style.display = 'inline';
                            paySubmit.querySelector('.btn-loading').style.display = 'none';
                            paySubmit.disabled = false;
                        }
                    });
            });

            // ── Progress modal ──
            document.querySelectorAll('.lcni-view-progress').forEach(function(btn){
                btn.addEventListener('click', function(){
                    document.getElementById('lcni-progress-content').innerHTML = btn.dataset.html;
                    openModal('lcni-progress-modal');
                });
            });
            var closeProgress = document.getElementById('lcni-close-progress');
            if (closeProgress) closeProgress.addEventListener('click', function(){ closeModal('lcni-progress-modal'); });
            document.getElementById('lcni-progress-modal').addEventListener('click', function(e){ if(e.target===this) closeModal('lcni-progress-modal'); });

            // ── Helpers ──
            function showMsg(el, txt) { el.className = 'lcni-ur-msg lcni-ur-msg--error'; el.textContent = txt; el.style.display = 'block'; }

            function submitAjax(form, btnText, btnLoad, msgEl, onSuccess) {
                btnText.style.display = 'none'; btnLoad.style.display = 'inline';
                msgEl.style.display = 'none';
                fetch(AJAX, { method:'POST', body: new FormData(form), credentials:'same-origin' })
                    .then(function(r){ return r.json(); })
                    .then(function(d){
                        if (d.success) {
                            msgEl.className = 'lcni-ur-msg lcni-ur-msg--success';
                            msgEl.textContent = d.data.message; msgEl.style.display = 'block';
                            if (onSuccess) onSuccess();
                        } else {
                            showMsg(msgEl, d.data.message || 'Có lỗi xảy ra.');
                            btnText.style.display = 'inline'; btnLoad.style.display = 'none';
                        }
                    });
            }
        })();
        </script>
        <?php
        return ob_get_clean();
    }

    // ─── History table ────────────────────────────────────────────────────────

    private function render_history_table( array $requests ): void {
        $status_cfg = array(
            'pending'   => array('Đang chờ',   '#f59e0b','#fffbeb'),
            'contacted' => array('Đang xử lý', '#3b82f6','#eff6ff'),
            'approved'  => array('Thành công', '#16a34a','#f0fdf4'),
            'rejected'  => array('Từ chối',    '#dc2626','#fef2f2'),
        );
        $flow_lbl = array('broker'=>'🏦 Liên kết CK','payment'=>'💳 Trả phí');
        $dur_lbl  = array(1=>'1 tháng',3=>'3 tháng',6=>'6 tháng',12=>'1 năm');
        $step_labels = array('submitted'=>'Gửi yêu cầu','contacted'=>'Liên hệ hỗ trợ','done'=>'Hoàn thành');
        ?>
        <div class="lcni-ur-history">
            <h4 class="lcni-ur-history-title">📋 Lịch sử yêu cầu</h4>
            <?php if ( empty($requests) ): ?>
                <div class="lcni-ur-empty">Chưa có yêu cầu nào. Nhấn nút bên trên để tạo yêu cầu nâng cấp.</div>
            <?php else: ?>
            <table class="lcni-ur-hist-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Ngày gửi</th>
                        <th>Gói</th>
                        <th>Luồng</th>
                        <th>Thời hạn</th>
                        <th>Trạng thái</th>
                        <th>Tiến trình</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($requests as $r):
                    $cfg = isset($status_cfg[$r['status']]) ? $status_cfg[$r['status']] : array('—','#6b7280','#f9fafb');
                    $dur = (int)$r['duration_months'];
                    $dur_text = ($dur > 0 && isset($dur_lbl[$dur])) ? $dur_lbl[$dur] : '—';
                    // Build progress HTML for modal
                    $steps = array_keys($step_labels);
                    $cur_idx = array_search($r['step'], $steps);
                    ob_start();
                    ?>
                    <div style="padding:8px 0">
                        <div style="display:flex;align-items:center;margin-bottom:12px">
                            <?php foreach ($step_labels as $key => $lbl):
                                $idx = array_search($key, $steps);
                                $done = $idx <= $cur_idx;
                                if ($idx === $cur_idx && in_array($r['status'], array('approved','rejected'))) {
                                    $cls = $r['status'];
                                } else {
                                    $cls = $done ? 'done' : 'pending';
                                }
                                $dot_color = '#d1d5db'; $dot_text = '#9ca3af'; $dot_bg = '#fff';
                                if ($cls==='done')     { $dot_color='#2563eb'; $dot_text='#fff'; $dot_bg='#2563eb'; }
                                if ($cls==='approved') { $dot_color='#16a34a'; $dot_text='#fff'; $dot_bg='#16a34a'; }
                                if ($cls==='rejected') { $dot_color='#dc2626'; $dot_text='#fff'; $dot_bg='#dc2626'; }
                                $icon = ($cls==='approved'||$cls==='done') ? '✓' : ($cls==='rejected' ? '✕' : ($idx+1));
                            ?>
                            <div style="display:flex;flex-direction:column;align-items:center;min-width:80px">
                                <div style="width:30px;height:30px;border-radius:50%;background:<?php echo $dot_bg; ?>;border:2px solid <?php echo $dot_color; ?>;display:flex;align-items:center;justify-content:center;color:<?php echo $dot_text; ?>;font-size:13px;font-weight:700"><?php echo $icon; ?></div>
                                <div style="font-size:11px;color:<?php echo ($cls==='done'||$cls==='approved') ? '#2563eb' : ($cls==='rejected' ? '#dc2626' : '#9ca3af'); ?>;margin-top:4px;text-align:center"><?php echo esc_html($lbl); ?></div>
                            </div>
                            <?php if ($idx < count($steps)-1): ?>
                            <div style="flex:1;height:2px;background:<?php echo ($idx < $cur_idx) ? '#2563eb' : '#e5e7eb'; ?>;margin-bottom:18px"></div>
                            <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                        <?php if (!empty($r['admin_note'])): ?>
                        <div style="padding:10px 12px;background:#f9fafb;border-radius:6px;font-size:13px;color:#374151;border-left:3px solid #d1d5db">
                            💬 <em><?php echo esc_html($r['admin_note']); ?></em>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($r['payment_proof_url']) && $r['flow']==='payment'): ?>
                        <div style="margin-top:10px;font-size:13px">
                            📎 <a href="<?php echo esc_url($r['payment_proof_url']); ?>" target="_blank">Xem ảnh chuyển khoản</a>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php
                    $progress_html = esc_attr(ob_get_clean());
                ?>
                    <tr>
                        <td>#<?php echo (int)$r['id']; ?></td>
                        <td><?php echo esc_html(date('d/m/Y H:i', strtotime($r['created_at']))); ?></td>
                        <td><?php echo esc_html($r['to_package_name'] ?? '—'); ?></td>
                        <td><?php echo esc_html(isset($flow_lbl[$r['flow']]) ? $flow_lbl[$r['flow']] : '—'); ?></td>
                        <td><?php echo esc_html($dur_text); ?></td>
                        <td>
                            <span class="lcni-ur-status-badge" style="color:<?php echo $cfg[0]; ?>;background:<?php echo $cfg[2]; ?>;border-color:<?php echo $cfg[1]; ?>">
                                <?php echo esc_html($cfg[0]); ?>
                            </span>
                        </td>
                        <td>
                            <button class="lcni-view-progress lcni-ur-btn-sm" data-html="<?php echo $progress_html; ?>">
                                Xem tiến trình
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
        <?php
    }

    // ─── Styles ──────────────────────────────────────────────────────────────

    private function render_styles(): void {
        static $p = false; if ($p) return; $p = true; ?>
        <style>
        .lcni-ur-wrap{font-family:inherit;max-width:800px}
        .lcni-ur-topbar{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:10px}
        .lcni-ur-title{font-size:17px;font-weight:700;margin:0;color:#111827}
        .lcni-ur-open-btn{background:#2563eb;color:#fff;border:none;border-radius:7px;padding:10px 20px;font-size:14px;font-weight:600;cursor:pointer;transition:background .15s}
        .lcni-ur-open-btn:hover{background:#1d4ed8}
        .lcni-ur-notice--warn{background:#fffbeb;border:1px solid #fde68a;color:#92400e;padding:10px 14px;border-radius:6px;font-size:13px}

        /* History table */
        .lcni-ur-history{margin-bottom:24px}
        .lcni-ur-history-title{font-size:14px;font-weight:700;margin:0 0 10px;color:#374151}
        .lcni-ur-empty{padding:20px;background:#f9fafb;border:1px dashed #d1d5db;border-radius:8px;font-size:13px;color:#9ca3af;text-align:center}
        .lcni-ur-hist-table{width:100%;border-collapse:collapse;font-size:13px;background:#fff;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden}
        .lcni-ur-hist-table th{background:#f9fafb;padding:9px 12px;text-align:left;font-weight:600;color:#374151;border-bottom:1px solid #e5e7eb;white-space:nowrap}
        .lcni-ur-hist-table td{padding:9px 12px;border-bottom:1px solid #f3f4f6;vertical-align:middle}
        .lcni-ur-hist-table tr:last-child td{border-bottom:none}
        .lcni-ur-hist-table tr:hover td{background:#f8fafc}
        .lcni-ur-status-badge{padding:2px 9px;border-radius:20px;font-size:11px;font-weight:600;border:1px solid}
        .lcni-ur-btn-sm{background:#f3f4f6;border:1px solid #d1d5db;border-radius:5px;padding:4px 10px;font-size:12px;cursor:pointer;color:#374151;white-space:nowrap}
        .lcni-ur-btn-sm:hover{background:#e5e7eb}

        /* Modal */
        .lcni-ur-modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:99999;display:flex;align-items:center;justify-content:center;padding:16px}
        .lcni-ur-modal-box{background:#fff;border-radius:12px;padding:28px;max-width:640px;width:100%;max-height:90vh;overflow-y:auto;position:relative}
        .lcni-ur-modal-close{position:absolute;top:14px;right:16px;background:none;border:none;font-size:18px;cursor:pointer;color:#6b7280;line-height:1}
        .lcni-ur-modal-close:hover{color:#111827}
        .lcni-ur-modal-title{font-size:16px;font-weight:700;margin:0 0 16px;color:#111827}
        @media(max-width:600px){
            .lcni-ur-modal-overlay{padding:0;align-items:flex-start;}
            .lcni-ur-modal-box{
                position:fixed;
                top:env(safe-area-inset-top,0px);
                bottom:env(safe-area-inset-bottom,0px);
                left:0;
                right:0;
                height:auto;
                max-height:none;
                border-radius:0;
                width:100%;
                max-width:100%;
                padding:16px 16px 20px;
                box-sizing:border-box;
                overflow-y:auto;
                -webkit-overflow-scrolling:touch;
                overscroll-behavior:contain;
            }
        }

        /* Flow tabs */
        .lcni-ur-flow-tabs{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:18px}
        .lcni-ur-flow-tab{display:flex;flex-direction:column;align-items:center;gap:3px;padding:12px;background:#fff;border:2px solid #e5e7eb;border-radius:9px;cursor:pointer;font-size:13px;font-weight:600;color:#374151;transition:all .15s}
        .lcni-ur-flow-tab:hover{border-color:#93c5fd;color:#2563eb}
        .lcni-ur-flow-tab.active{border-color:#2563eb;color:#2563eb;background:#eff6ff}
        .lcni-ur-flow-tab-sub{font-size:11px;font-weight:400;color:#9ca3af}
        .lcni-ur-flow-tab.active .lcni-ur-flow-tab-sub{color:#93c5fd}

        /* Form */
        .lcni-ur-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
        .lcni-ur-field{display:flex;flex-direction:column;gap:4px}
        .lcni-ur-field--full{grid-column:1/-1}
        .lcni-ur-field label{font-size:12px;font-weight:600;color:#374151}
        .req{color:#dc2626}
        .hint{font-size:11px;color:#9ca3af}
        .lcni-ur-field input,.lcni-ur-field select{border:1px solid #d1d5db;border-radius:6px;padding:7px 9px;font-size:13px;width:100%;box-sizing:border-box;outline:none;transition:border-color .15s}
        .lcni-ur-field input:focus,.lcni-ur-field select:focus{border-color:#2563eb;box-shadow:0 0 0 2px #dbeafe}
        .lcni-ur-actions{margin-top:16px;display:flex;gap:8px}
        .lcni-ur-btn{background:#2563eb;color:#fff;border:none;border-radius:7px;padding:9px 20px;font-size:13px;font-weight:600;cursor:pointer;transition:background .15s}
        .lcni-ur-btn:hover{background:#1d4ed8}
        .lcni-ur-btn:disabled{background:#93c5fd;cursor:not-allowed}
        .lcni-ur-btn--ghost{background:#f3f4f6;color:#374151;border:1px solid #d1d5db}
        .lcni-ur-btn--ghost:hover{background:#e5e7eb}
        .lcni-ur-msg{margin-top:10px;padding:9px 12px;border-radius:6px;font-size:13px}
        .lcni-ur-msg--success{background:#f0fdf4;color:#15803d;border:1px solid #bbf7d0}
        .lcni-ur-msg--error{background:#fef2f2;color:#dc2626;border:1px solid #fecaca}

        /* Pay steps */
        .lcni-pay-steps{display:flex;align-items:center;margin-bottom:16px}
        .lcni-pay-step-item{display:flex;flex-direction:column;align-items:center;gap:4px}
        .lcni-pay-step-item .sn{width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;border:2px solid #d1d5db;color:#9ca3af;background:#fff}
        .lcni-pay-step-item.active .sn{background:#2563eb;border-color:#2563eb;color:#fff}
        .lcni-pay-step-item.done .sn{background:#e0e7ff;border-color:#818cf8;color:#4f46e5}
        .lcni-pay-step-item .sl{font-size:11px;color:#9ca3af;white-space:nowrap}
        .lcni-pay-step-item.active .sl{color:#2563eb;font-weight:600}
        .lcni-pay-step-line{flex:1;height:2px;background:#e5e7eb;margin-bottom:18px}
        .lcni-pay-step-line.done{background:#818cf8}

        /* Duration */
        .lcni-dur-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:8px}
        .lcni-dur-card{display:flex;flex-direction:column;align-items:center;padding:9px 6px;border:2px solid #e5e7eb;border-radius:8px;cursor:pointer;transition:all .15s;background:#fff}
        .lcni-dur-card input{display:none}
        .lcni-dur-card:hover{border-color:#93c5fd}
        .lcni-dur-card.selected{border-color:#2563eb;background:#eff6ff}
        .dur-label{font-size:12px;font-weight:600;color:#374151}
        .dur-price{font-size:11px;color:#2563eb;font-weight:700;margin-top:2px}

        /* Payment layout */
        .lcni-pay-layout{display:grid;grid-template-columns:160px 1fr;gap:16px;align-items:start;margin-top:16px}
        .lcni-qr-img{width:150px;height:150px;object-fit:contain;border:1px solid #e5e7eb;border-radius:8px}
        .lcni-qr-placeholder{width:150px;height:150px;background:#f3f4f6;border:1px dashed #d1d5db;border-radius:8px;display:flex;flex-direction:column;align-items:center;justify-content:center;color:#9ca3af;font-size:12px;text-align:center}
        .lcni-bank-info{margin-top:8px}
        .bank-row{display:flex;justify-content:space-between;font-size:11px;padding:3px 0;border-bottom:1px solid #f3f4f6;gap:6px}
        .bank-row span{color:#6b7280}
        .lcni-amount-card{background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:12px}
        .a-label{font-size:10px;color:#6b7280;text-transform:uppercase;letter-spacing:.5px}
        .a-value{font-size:22px;font-weight:800;color:#1d4ed8;margin-top:3px}
        .a-content{font-size:13px;font-weight:600;color:#1e40af;background:#fff;border:1px solid #bfdbfe;border-radius:5px;padding:7px 9px;margin:5px 0;word-break:break-all}
        .a-copy{background:none;border:1px solid #93c5fd;color:#2563eb;border-radius:5px;padding:3px 9px;font-size:11px;cursor:pointer}
        .lcni-upload-box{margin-top:12px}
        .upload-label{font-size:12px;font-weight:600;color:#374151;margin-bottom:6px}
        .upload-area{border:2px dashed #d1d5db;border-radius:8px;padding:12px;text-align:center;min-height:70px;display:flex;align-items:center;justify-content:center;transition:border-color .15s}
        .upload-area:hover{border-color:#93c5fd}
        .prog-bar{height:5px;background:#e5e7eb;border-radius:3px;overflow:hidden;margin:6px 0}
        .prog-fill{height:100%;background:#2563eb;width:0;transition:width .1s}
        .prog-label{font-size:11px;color:#6b7280}
        @media(max-width:600px){.lcni-ur-grid,.lcni-ur-flow-tabs{grid-template-columns:1fr}.lcni-pay-layout{grid-template-columns:1fr}.lcni-dur-grid{grid-template-columns:repeat(2,1fr)}.lcni-ur-actions{flex-wrap:wrap}.lcni-ur-btn,.lcni-ur-btn--ghost{width:100%;justify-content:center}}
        </style>
        <?php
    }
}
