<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_Portfolio_Shortcode {

    const VERSION = '1.0.0';
    private $service;

    public function __construct(LCNI_Portfolio_Service $service) {
        $this->service = $service;
        add_action('init', [$this, 'register_shortcodes']);
        add_action('wp_enqueue_scripts', [$this, 'register_assets']);
    }

    public function register_shortcodes() {
        add_shortcode('lcni_portfolio', [$this, 'render']);
    }

    public function register_assets() {
        $js_path  = LCNI_PATH . 'modules/portfolio/assets/js/portfolio.js';
        $css_path = LCNI_PATH . 'modules/portfolio/assets/css/portfolio.css';
        $js_ver   = file_exists($js_path)  ? (string) filemtime($js_path)  : self::VERSION;
        $css_ver  = file_exists($css_path) ? (string) filemtime($css_path) : self::VERSION;

        wp_register_script('lcni-portfolio', LCNI_URL . 'modules/portfolio/assets/js/portfolio.js', ['jquery'], $js_ver, true);
        wp_register_style('lcni-portfolio',  LCNI_URL . 'modules/portfolio/assets/css/portfolio.css', [], $css_ver);
    }

    public function render($atts = []) {
        if (!is_user_logged_in()) {
            return '<p class="lcni-pf-notice">Vui lòng <a href="' . esc_url(wp_login_url(get_permalink())) . '">đăng nhập</a> để xem danh mục.</p>';
        }

        wp_enqueue_script('lcni-portfolio');
        wp_enqueue_style('lcni-portfolio');

        $user_id    = get_current_user_id();
        $portfolios = $this->service->get_portfolios($user_id);
        $nonce      = wp_create_nonce('wp_rest');
        $rest_url   = esc_url(rest_url('lcni/v1'));

        // Auto-create default portfolio if none
        if (empty($portfolios)) {
            $new_id = $this->service->create_portfolio($user_id, 'Danh mục của tôi');
            $portfolios = $this->service->get_portfolios($user_id);
        }

        $active_id = (int) ($portfolios[0]['id'] ?? 0);
        foreach ($portfolios as $p) {
            if ($p['is_default']) { $active_id = (int) $p['id']; break; }
        }

        // Lấy danh sách tài khoản DNSE nếu user đã kết nối
        $dnse_accounts = [];
        if ( class_exists( 'LCNI_DnseTradingRepository' ) ) {
            try {
                $dnse_repo   = new LCNI_DnseTradingRepository();
                $dnse_accts  = $dnse_repo->get_accounts( $user_id );
                foreach ( $dnse_accts as $a ) {
                    $dnse_accounts[] = [
                        'id'       => $a['account_no'],
                        'label'    => ( $a['account_type_name'] ?: ( $a['account_type'] === 'margin' ? 'Margin' : 'Thường' ) )
                                      . ' (' . $a['account_no'] . ')',
                        'type'     => $a['account_type'],
                    ];
                }
            } catch ( Throwable $e ) {}
        }

        wp_localize_script('lcni-portfolio', 'lcniPortfolioConfig', [
            'restUrl'      => $rest_url,
            'nonce'        => $nonce,
            'portfolios'   => $portfolios,
            'activeId'     => $active_id,
            'dnseAccounts' => $dnse_accounts,
            'dnseOrderUrl' => rest_url('lcni/v1/dnse/order'),
        ]);

        ob_start();
        ?>
        <div id="lcni-portfolio-app" class="lcni-pf-wrap">

            <!-- Header -->
            <div class="lcni-pf-header">
                <div class="lcni-pf-header-left">
                    <h2 class="lcni-pf-title">📊 Danh mục đầu tư</h2>
                    <div class="lcni-pf-tabs" id="lcni-pf-tabs">
                        <?php foreach ($portfolios as $p): ?>
                        <button class="lcni-pf-tab <?php echo $p['id'] == $active_id ? 'active' : ''; ?>"
                                data-id="<?php echo (int) $p['id']; ?>">
                            <?php echo esc_html($p['name']); ?>
                            <?php if ($p['is_default']): ?><span class="lcni-pf-default-dot"></span><?php endif; ?>
                        </button>
                        <?php endforeach; ?>
                        <button class="lcni-pf-tab lcni-pf-tab-add" id="lcni-pf-add-btn">＋</button>
                    </div>
                </div>
                <div class="lcni-pf-header-right">
                    <button class="lcni-pf-btn lcni-pf-btn-primary" id="lcni-pf-add-tx-btn">
                        ＋ Thêm giao dịch
                    </button>
                </div>
            </div>

            <!-- Summary Cards -->
            <div class="lcni-pf-summary" id="lcni-pf-summary">
                <div class="lcni-pf-card">
                    <div class="lcni-pf-card-label">Giá trị danh mục</div>
                    <div class="lcni-pf-card-value" id="pf-total-value">—</div>
                </div>
                <div class="lcni-pf-card">
                    <div class="lcni-pf-card-label">Vốn đầu tư</div>
                    <div class="lcni-pf-card-value" id="pf-cost-basis">—</div>
                </div>
                <div class="lcni-pf-card">
                    <div class="lcni-pf-card-label">Lãi/Lỗ chưa chốt</div>
                    <div class="lcni-pf-card-value" id="pf-unrealized">—</div>
                </div>
                <div class="lcni-pf-card">
                    <div class="lcni-pf-card-label">Lãi/Lỗ đã chốt</div>
                    <div class="lcni-pf-card-value" id="pf-realized">—</div>
                </div>
                <div class="lcni-pf-card">
                    <div class="lcni-pf-card-label">Tổng lãi/lỗ</div>
                    <div class="lcni-pf-card-value" id="pf-total-pnl">—</div>
                </div>
            </div>

            <!-- Main content: Holdings + Allocation -->
            <div class="lcni-pf-main">
                <!-- Holdings Table -->
                <div class="lcni-pf-section lcni-pf-holdings-wrap">
                    <div class="lcni-pf-section-header">
                        <h3>Cổ phiếu đang nắm</h3>
                        <span class="lcni-pf-count" id="pf-holding-count"></span>
                    </div>
                    <div class="lcni-pf-table-wrap">
                        <table class="lcni-pf-table" id="lcni-pf-holdings-table">
                            <thead>
                                <tr>
                                    <th>Mã CP</th>
                                    <th>Khối lượng</th>
                                    <th>Giá vốn TB</th>
                                    <th>Giá hiện tại</th>
                                    <th>Giá trị TT</th>
                                    <th>Lãi/Lỗ</th>
                                    <th>%</th>
                                </tr>
                            </thead>
                            <tbody id="lcni-pf-holdings-body">
                                <tr><td colspan="7" class="lcni-pf-loading">Đang tải...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Allocation Chart -->
                <div class="lcni-pf-section lcni-pf-alloc-wrap">
                    <div class="lcni-pf-section-header">
                        <h3>Tỷ trọng danh mục</h3>
                    </div>
                    <canvas id="lcni-pf-alloc-chart" width="260" height="260"></canvas>
                    <div id="lcni-pf-alloc-legend" class="lcni-pf-alloc-legend"></div>
                </div>
            </div>

            <!-- Equity Curve -->
            <div class="lcni-pf-section">
                <div class="lcni-pf-section-header">
                    <h3>Biến động giá trị danh mục</h3>
                    <div class="lcni-pf-period-btns">
                        <button class="lcni-pf-period active" data-limit="30">1T</button>
                        <button class="lcni-pf-period" data-limit="90">3T</button>
                        <button class="lcni-pf-period" data-limit="180">6T</button>
                        <button class="lcni-pf-period" data-limit="365">1N</button>
                    </div>
                </div>
                <div class="lcni-pf-equity-wrap">
                    <canvas id="lcni-pf-equity-chart"></canvas>
                </div>
            </div>

            <!-- Transaction History -->
            <div class="lcni-pf-section">
                <div class="lcni-pf-section-header">
                    <h3>Lịch sử giao dịch</h3>
                </div>
                <div class="lcni-pf-table-wrap">
                    <table class="lcni-pf-table" id="lcni-pf-tx-table">
                        <thead>
                            <tr>
                                <th>Ngày</th>
                                <th>Mã CP</th>
                                <th>Loại</th>
                                <th>Khối lượng</th>
                                <th>Giá</th>
                                <th>Phí</th>
                                <th>Thuế</th>
                                <th>Tổng tiền</th>
                                <th>Ghi chú</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody id="lcni-pf-tx-body">
                            <tr><td colspan="10" class="lcni-pf-loading">Đang tải...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Loading overlay -->
            <div id="lcni-pf-overlay" class="lcni-pf-overlay" style="display:none;">
                <div class="lcni-pf-spinner"></div>
            </div>
        </div>

        <!-- Modal: Add/Edit Transaction -->
        <div id="lcni-pf-tx-modal" class="lcni-pf-modal" style="display:none;">
            <div class="lcni-pf-modal-box">
                <div class="lcni-pf-modal-header">
                    <h3 id="lcni-pf-modal-title">Thêm giao dịch</h3>
                    <button class="lcni-pf-modal-close" id="lcni-pf-modal-close">✕</button>
                </div>
                <div class="lcni-pf-modal-body">
                    <input type="hidden" id="pf-tx-id" value="">
                    <div class="lcni-pf-form-row">
                        <div class="lcni-pf-form-group">
                            <label>Mã chứng khoán <span class="req">*</span></label>
                            <input type="text" id="pf-tx-symbol" placeholder="VD: VNM, HPG..." maxlength="10" style="text-transform:uppercase;">
                        </div>
                        <div class="lcni-pf-form-group">
                            <label>Loại giao dịch <span class="req">*</span></label>
                            <select id="pf-tx-type">
                                <option value="buy">🟢 Mua</option>
                                <option value="sell">🔴 Bán</option>
                                <option value="dividend">💰 Cổ tức</option>
                                <option value="fee">💸 Phí</option>
                            </select>
                        </div>
                    </div>
                    <div class="lcni-pf-form-row">
                        <div class="lcni-pf-form-group">
                            <label>Ngày giao dịch <span class="req">*</span></label>
                            <input type="date" id="pf-tx-date" value="<?php echo esc_attr(current_time('Y-m-d')); ?>">
                        </div>
                        <div class="lcni-pf-form-group">
                            <label>Khối lượng <span class="req">*</span></label>
                            <input type="number" id="pf-tx-qty" placeholder="VD: 1000" min="0" step="100">
                        </div>
                    </div>
                    <div class="lcni-pf-form-row">
                        <div class="lcni-pf-form-group">
                            <label>Giá (VNĐ) <span class="req">*</span></label>
                            <input type="number" id="pf-tx-price" placeholder="VD: 21500" min="0" step="100">
                        </div>
                        <div class="lcni-pf-form-group">
                            <label>Phí giao dịch (VNĐ)</label>
                            <input type="number" id="pf-tx-fee" placeholder="0" min="0" value="0">
                        </div>
                    </div>
                    <div class="lcni-pf-form-row">
                        <div class="lcni-pf-form-group">
                            <label>Thuế (VNĐ) <span id="pf-tax-hint" style="color:#9ca3af;font-size:11px;">0.1% khi bán</span></label>
                            <input type="number" id="pf-tx-tax" placeholder="0" min="0" value="0">
                        </div>
                        <div class="lcni-pf-form-group">
                            <label>Tổng tiền (ước tính)</label>
                            <div id="pf-tx-total" class="lcni-pf-total-preview">—</div>
                        </div>
                    </div>
                    <div class="lcni-pf-form-group">
                        <label>Ghi chú</label>
                        <input type="text" id="pf-tx-note" placeholder="VD: Mua theo sóng, chờ breakout...">
                    </div>
                    <div id="lcni-pf-tx-error" class="lcni-pf-error" style="display:none;"></div>

                    <!-- DNSE Section: chỉ hiện khi user đã kết nối DNSE -->
                    <div id="lcni-pf-dnse-order-section" style="display:none;">
                        <div class="lcni-pf-dnse-section-divider">
                            <span>⚡ Đặt lệnh thực qua DNSE</span>
                        </div>
                        <div class="lcni-pf-form-row">
                            <div class="lcni-pf-form-group">
                                <label>Tài khoản DNSE</label>
                                <select id="pf-dnse-account"></select>
                            </div>
                            <div class="lcni-pf-form-group">
                                <label>Loại lệnh</label>
                                <select id="pf-dnse-order-type">
                                    <option value="LO">LO — Giới hạn</option>
                                    <option value="ATO">ATO — Mở cửa</option>
                                    <option value="ATC">ATC — Đóng cửa</option>
                                    <option value="MP">MP — Thị trường</option>
                                </select>
                            </div>
                        </div>
                        <div class="lcni-pf-dnse-note">
                            Lệnh sẽ được gửi tới DNSE <strong>và</strong> lưu vào danh mục cùng lúc.
                            Cần Trading token còn hạn.
                        </div>
                        <label class="lcni-pf-dnse-toggle-label">
                            <input type="checkbox" id="pf-dnse-send-order" checked>
                            Gửi lệnh thực tới DNSE
                        </label>
                    </div>
                </div>
                <div class="lcni-pf-modal-footer">
                    <button class="lcni-pf-btn lcni-pf-btn-ghost" id="lcni-pf-modal-cancel">Huỷ</button>
                    <button class="lcni-pf-btn lcni-pf-btn-primary" id="lcni-pf-tx-save">💾 Lưu giao dịch</button>
                </div>
            </div>
        </div>

        <!-- Modal: Create Portfolio -->
        <div id="lcni-pf-create-modal" class="lcni-pf-modal" style="display:none;">
            <div class="lcni-pf-modal-box lcni-pf-modal-sm">
                <div class="lcni-pf-modal-header">
                    <h3>Tạo danh mục mới</h3>
                    <button class="lcni-pf-modal-close" id="lcni-pf-create-close">✕</button>
                </div>
                <div class="lcni-pf-modal-body">
                    <div class="lcni-pf-form-group">
                        <label>Tên danh mục</label>
                        <input type="text" id="pf-new-name" placeholder="VD: Danh mục dài hạn">
                    </div>
                    <div class="lcni-pf-form-group">
                        <label>Mô tả</label>
                        <input type="text" id="pf-new-desc" placeholder="Tuỳ chọn">
                    </div>
                </div>
                <div class="lcni-pf-modal-footer">
                    <button class="lcni-pf-btn lcni-pf-btn-ghost" id="lcni-pf-create-cancel">Huỷ</button>
                    <button class="lcni-pf-btn lcni-pf-btn-primary" id="lcni-pf-create-save">Tạo danh mục</button>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}
