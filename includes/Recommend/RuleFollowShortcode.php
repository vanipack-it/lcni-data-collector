<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * RuleFollowShortcode
 *
 * Shortcode [lcni_rule_follow] — Trang theo dõi rule cho user.
 *
 * Hiển thị danh sách tất cả active rules.
 * Mỗi rule: tên, mô tả, số người follow, nút Follow/Bỏ follow,
 * checkbox "Nhận email khi có tín hiệu mới".
 *
 * Yêu cầu: user đã đăng nhập.
 *
 * Dùng: [lcni_rule_follow]
 * Tùy chọn:
 *   show_description="1"   — hiện mô tả rule (mặc định 1)
 *   show_stats="1"         — hiện winrate, risk/reward (mặc định 1)
 */
class RuleFollowShortcode {

    /** @var RuleFollowRepository */
    private $follow_repo;

    public function __construct( RuleFollowRepository $follow_repo ) {
        $this->follow_repo = $follow_repo;
        add_shortcode( 'lcni_rule_follow', [ $this, 'render' ] );
    }

    public function render( $atts = [] ): string {
        $atts = shortcode_atts( [
            'show_description' => '1',
            'show_stats'       => '1',
        ], $atts, 'lcni_rule_follow' );

        if ( ! is_user_logged_in() ) {
            return '<p class="lcni-rf-notice">Vui lòng <a href="' . esc_url( wp_login_url( get_permalink() ) ) . '">đăng nhập</a> để theo dõi tín hiệu.</p>';
        }

        $user_id  = get_current_user_id();
        $rules    = $this->follow_repo->get_rules_with_follow_status( $user_id );
        $rest_url = esc_url_raw( rest_url( 'lcni/v1' ) );
        $nonce    = wp_create_nonce( 'wp_rest' );

        $show_desc  = $atts['show_description'] === '1';
        $show_stats = $atts['show_stats'] === '1';

        ob_start();
        ?>
        <div class="lcni-rf-wrap" id="lcni-rf-app"
             data-rest="<?php echo esc_attr( $rest_url ); ?>"
             data-nonce="<?php echo esc_attr( $nonce ); ?>">

            <div class="lcni-rf-header">
                <h2 class="lcni-rf-title">🔔 Theo dõi tín hiệu</h2>
                <p class="lcni-rf-subtitle">
                    Chọn các rule muốn theo dõi. Khi có tín hiệu mới, hệ thống sẽ gửi thông báo email đến bạn.
                </p>
            </div>

            <?php if ( empty( $rules ) ): ?>
                <p class="lcni-rf-empty">Chưa có rule nào được kích hoạt.</p>
            <?php else: ?>

            <div class="lcni-rf-grid">
            <?php foreach ( $rules as $rule ):
                $rule_id      = (int) $rule['id'];
                $is_following = ! empty( $rule['is_following'] );
                $notify_email = ! empty( $rule['notify_email'] );
                $followers    = (int) $this->follow_repo->count_followers( $rule_id );
                $winrate      = isset( $rule['winrate'] ) ? round( (float) $rule['winrate'] * 100, 1 ) : null;
                $rr           = isset( $rule['risk_reward'] ) ? (float) $rule['risk_reward'] : null;
            ?>
            <div class="lcni-rf-card <?php echo $is_following ? 'lcni-rf-card--following' : ''; ?>"
                 data-rule-id="<?php echo $rule_id; ?>">

                <!-- Card header -->
                <div class="lcni-rf-card-top">
                    <div class="lcni-rf-card-info">
                        <h3 class="lcni-rf-rule-name"><?php echo esc_html( $rule['name'] ); ?></h3>
                        <span class="lcni-rf-timeframe"><?php echo esc_html( strtoupper( $rule['timeframe'] ?? '1D' ) ); ?></span>
                    </div>
                    <div class="lcni-rf-card-actions">
                        <button type="button"
                                class="lcni-rf-follow-btn <?php echo $is_following ? 'lcni-rf-follow-btn--active' : ''; ?>"
                                data-rule-id="<?php echo $rule_id; ?>"
                                data-following="<?php echo $is_following ? '1' : '0'; ?>">
                            <?php echo $is_following ? '✅ Đang theo dõi' : '🔔 Theo dõi'; ?>
                        </button>
                    </div>
                </div>

                <?php if ( $show_desc && ! empty( $rule['description'] ) ): ?>
                <p class="lcni-rf-description"><?php echo esc_html( $rule['description'] ); ?></p>
                <?php endif; ?>

                <!-- Stats row -->
                <?php if ( $show_stats ): ?>
                <div class="lcni-rf-stats">
                    <?php if ( $rr !== null ): ?>
                    <span class="lcni-rf-stat">
                        <span class="lcni-rf-stat-label">R:R</span>
                        <span class="lcni-rf-stat-value"><?php echo number_format( $rr, 1 ); ?></span>
                    </span>
                    <?php endif; ?>
                    <?php if ( $winrate !== null ): ?>
                    <span class="lcni-rf-stat">
                        <span class="lcni-rf-stat-label">Winrate</span>
                        <span class="lcni-rf-stat-value"><?php echo $winrate; ?>%</span>
                    </span>
                    <?php endif; ?>
                    <span class="lcni-rf-stat">
                        <span class="lcni-rf-stat-label">Người theo dõi</span>
                        <span class="lcni-rf-stat-value lcni-rf-follower-count"
                              data-rule-id="<?php echo $rule_id; ?>"><?php echo $followers; ?></span>
                    </span>
                </div>
                <?php endif; ?>

                <!-- Email toggle — chỉ hiện khi đang following -->
                <div class="lcni-rf-email-toggle <?php echo $is_following ? '' : 'lcni-rf-email-toggle--hidden'; ?>"
                     data-rule-id="<?php echo $rule_id; ?>">
                    <label class="lcni-rf-email-label">
                        <input type="checkbox"
                               class="lcni-rf-email-check"
                               data-rule-id="<?php echo $rule_id; ?>"
                               <?php echo $notify_email ? 'checked' : ''; ?>>
                        <span>📧 Nhận email khi có tín hiệu mới</span>
                    </label>
                </div>

            </div>
            <?php endforeach; ?>
            </div>

            <?php endif; ?>

            <!-- Toast container -->
            <div id="lcni-rf-toast" class="lcni-rf-toast" aria-live="polite"></div>
        </div>

        <?php $this->render_styles(); ?>
        <?php $this->render_script(); ?>
        <?php
        return ob_get_clean();
    }

    // ── Styles ─────────────────────────────────────────────────────────────────

    private function render_styles(): void {
        echo '<style>
/* ── LCNI Rule Follow Widget ─────────────────────────────── */
.lcni-rf-wrap{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;max-width:900px;margin:0 auto;padding:0 0 40px}
.lcni-rf-header{margin-bottom:24px}
.lcni-rf-title{font-size:20px;font-weight:700;color:#111827;margin:0 0 6px}
.lcni-rf-subtitle{color:#6b7280;font-size:14px;margin:0}
.lcni-rf-notice,.lcni-rf-empty{color:#6b7280;font-size:14px;padding:24px;background:#f9fafb;border-radius:8px;text-align:center}

/* Grid */
.lcni-rf-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:16px}

/* Card */
.lcni-rf-card{background:#fff;border:2px solid #e5e7eb;border-radius:12px;padding:20px;transition:border-color .2s,box-shadow .2s;position:relative}
.lcni-rf-card:hover{border-color:#bfdbfe;box-shadow:0 4px 16px rgba(37,99,235,.08)}
.lcni-rf-card--following{border-color:#2563eb;background:#f0f7ff}
.lcni-rf-card--following:hover{border-color:#1d4ed8}

.lcni-rf-card-top{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:10px}
.lcni-rf-card-info{flex:1;min-width:0}
.lcni-rf-rule-name{margin:0 0 4px;font-size:15px;font-weight:700;color:#111827;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.lcni-rf-timeframe{display:inline-block;padding:2px 8px;background:#f3f4f6;border-radius:4px;font-size:11px;color:#6b7280;font-weight:600;letter-spacing:.5px}
.lcni-rf-description{color:#6b7280;font-size:13px;line-height:1.5;margin:0 0 12px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}

/* Follow button */
.lcni-rf-follow-btn{padding:7px 14px;border-radius:20px;border:1.5px solid #2563eb;background:#fff;color:#2563eb;font-size:13px;font-weight:600;cursor:pointer;white-space:nowrap;transition:all .15s}
.lcni-rf-follow-btn:hover{background:#eff6ff}
.lcni-rf-follow-btn--active{background:#2563eb;color:#fff;border-color:#2563eb}
.lcni-rf-follow-btn--active:hover{background:#1d4ed8;border-color:#1d4ed8}
.lcni-rf-follow-btn:disabled{opacity:.6;cursor:not-allowed}

/* Stats */
.lcni-rf-stats{display:flex;gap:16px;flex-wrap:wrap;margin:10px 0}
.lcni-rf-stat{display:flex;flex-direction:column;gap:1px}
.lcni-rf-stat-label{font-size:11px;color:#9ca3af}
.lcni-rf-stat-value{font-size:14px;font-weight:700;color:#374151}

/* Email toggle */
.lcni-rf-email-toggle{margin-top:12px;padding-top:12px;border-top:1px solid #e5e7eb}
.lcni-rf-email-toggle--hidden{display:none}
.lcni-rf-email-label{display:flex;align-items:center;gap:8px;font-size:13px;color:#374151;cursor:pointer}
.lcni-rf-email-check{width:16px;height:16px;cursor:pointer;accent-color:#2563eb}

/* Toast */
.lcni-rf-toast{position:fixed;bottom:24px;right:24px;padding:12px 20px;border-radius:8px;font-size:14px;font-weight:500;background:#111827;color:#fff;z-index:99999;opacity:0;transform:translateY(8px);transition:opacity .25s,transform .25s;pointer-events:none;max-width:320px}
.lcni-rf-toast--show{opacity:1;transform:translateY(0)}
.lcni-rf-toast--success{background:#166534}
.lcni-rf-toast--error{background:#991b1b}

@media(max-width:600px){
    .lcni-rf-grid{grid-template-columns:1fr}
    .lcni-rf-toast{left:16px;right:16px;bottom:16px}
}
</style>';
    }

    // ── JavaScript ─────────────────────────────────────────────────────────────

    private function render_script(): void {
        echo '<script>
(function(){
    "use strict";

    var app   = document.getElementById("lcni-rf-app");
    if (!app) return;

    var REST  = app.dataset.rest  || "";
    var NONCE = app.dataset.nonce || "";
    var toast = document.getElementById("lcni-rf-toast");

    /* ── Toast ──────────────────────────────────────────────── */
    function showToast(msg, type) {
        if (!toast) return;
        toast.textContent = msg;
        toast.className   = "lcni-rf-toast lcni-rf-toast--" + (type || "success");
        requestAnimationFrame(function(){
            toast.classList.add("lcni-rf-toast--show");
        });
        setTimeout(function(){
            toast.classList.remove("lcni-rf-toast--show");
        }, 3000);
    }

    /* ── API ────────────────────────────────────────────────── */
    function apiPost(path, body) {
        return fetch(REST + path, {
            method:  "POST",
            headers: { "Content-Type": "application/json", "X-WP-Nonce": NONCE },
            body:    JSON.stringify(body || {}),
        }).then(function(r){ return r.json(); });
    }

    /* ── Update follow count ─────────────────────────────────── */
    function updateCount(ruleId, count) {
        var el = app.querySelector(".lcni-rf-follower-count[data-rule-id=\"" + ruleId + "\"]");
        if (el) el.textContent = count;
    }

    /* ── Follow button click ─────────────────────────────────── */
    app.addEventListener("click", function(e) {
        var btn = e.target.closest(".lcni-rf-follow-btn");
        if (!btn) return;

        var ruleId  = btn.dataset.ruleId;
        var card    = app.querySelector(".lcni-rf-card[data-rule-id=\"" + ruleId + "\"]");
        var emailChk = card && card.querySelector(".lcni-rf-email-check");
        var notifyEmail = emailChk ? emailChk.checked : true;
        var following = btn.dataset.following === "1";

        btn.disabled = true;
        var path = following
            ? "/recommend/rules/" + ruleId + "/unfollow"
            : "/recommend/rules/" + ruleId + "/follow";

        apiPost(path, { notify_email: notifyEmail })
            .then(function(res) {
                if (!res.success) throw new Error(res.message || "Lỗi không xác định.");

                var nowFollowing = res.is_following;
                btn.dataset.following = nowFollowing ? "1" : "0";
                btn.textContent = nowFollowing ? "✅ Đang theo dõi" : "🔔 Theo dõi";
                btn.classList.toggle("lcni-rf-follow-btn--active", nowFollowing);

                if (card) {
                    card.classList.toggle("lcni-rf-card--following", nowFollowing);
                    var emailToggle = card.querySelector(".lcni-rf-email-toggle");
                    if (emailToggle) emailToggle.classList.toggle("lcni-rf-email-toggle--hidden", !nowFollowing);
                }

                updateCount(ruleId, res.follower_count || 0);
                showToast(
                    nowFollowing ? "✅ Đã theo dõi rule" : "Đã bỏ theo dõi rule",
                    "success"
                );
            })
            .catch(function(err) {
                showToast(err.message || "Lỗi kết nối.", "error");
            })
            .finally(function() {
                btn.disabled = false;
            });
    });

    /* ── Email checkbox change ───────────────────────────────── */
    app.addEventListener("change", function(e) {
        var chk = e.target.closest(".lcni-rf-email-check");
        if (!chk) return;

        var ruleId      = chk.dataset.ruleId;
        var notifyEmail = chk.checked;

        apiPost("/recommend/rules/" + ruleId + "/follow", { notify_email: notifyEmail })
            .then(function(res) {
                if (!res.success) throw new Error(res.message || "Lỗi cập nhật.");
                showToast(
                    notifyEmail ? "📧 Bật thông báo email" : "🔕 Tắt thông báo email",
                    "success"
                );
            })
            .catch(function(err) {
                chk.checked = !notifyEmail; // rollback
                showToast(err.message || "Lỗi kết nối.", "error");
            });
    });
})();
</script>';
    }
}
