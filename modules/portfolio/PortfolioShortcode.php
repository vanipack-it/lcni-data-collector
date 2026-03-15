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
        add_shortcode('lcni_portfolio',              [$this, 'render']);
        add_shortcode('lcni_add_transaction',        [$this, 'render_add_tx_inline']);
        add_shortcode('lcni_add_transaction_float',  [$this, 'render_add_tx_float']);
    }

    public function register_assets() {
        $js_path    = LCNI_PATH . 'modules/portfolio/assets/js/portfolio.js';
        $css_path   = LCNI_PATH . 'modules/portfolio/assets/css/portfolio.css';
        $widget_js  = LCNI_PATH . 'modules/portfolio/assets/js/lcni-add-tx-widget.js';
        $widget_css = LCNI_PATH . 'modules/portfolio/assets/css/lcni-add-tx-widget.css';

        $ctrl_js    = LCNI_PATH . 'modules/portfolio/assets/js/lcni-transaction-controller.js';
        $ctrl_css   = LCNI_PATH . 'modules/portfolio/assets/css/lcni-transaction-modal.css';
        $svc_js     = LCNI_PATH . 'modules/portfolio/assets/js/lcni-order-service.js';
        $dnse_svc_js = LCNI_PATH . 'modules/portfolio/assets/js/services/DNSEOrderService.js';

        $js_ver     = file_exists($js_path)    ? (string) filemtime($js_path)    : self::VERSION;
        $css_ver    = file_exists($css_path)   ? (string) filemtime($css_path)   : self::VERSION;
        $wjs_ver    = file_exists($widget_js)  ? (string) filemtime($widget_js)  : self::VERSION;
        $wcss_ver   = file_exists($widget_css) ? (string) filemtime($widget_css) : self::VERSION;
        $cjs_ver    = file_exists($ctrl_js)    ? (string) filemtime($ctrl_js)    : self::VERSION;
        $ccss_ver   = file_exists($ctrl_css)   ? (string) filemtime($ctrl_css)   : self::VERSION;
        $svc_ver    = file_exists($svc_js)     ? (string) filemtime($svc_js)     : self::VERSION;
        $dnse_svc_ver = file_exists($dnse_svc_js) ? (string) filemtime($dnse_svc_js) : self::VERSION;

        // DNSE Order Service — dedicated DNSE API client (loads before lcni-order-service)
        wp_register_script('lcni-dnse-order-service',     LCNI_URL . 'modules/portfolio/assets/js/services/DNSEOrderService.js',   [], $dnse_svc_ver, true);

        // Order service — unified pipeline (depends on dnse-order-service)
        wp_register_script('lcni-order-service',          LCNI_URL . 'modules/portfolio/assets/js/lcni-order-service.js',          ['lcni-dnse-order-service'], $svc_ver, true);

        // Unified transaction controller — must load BEFORE widget & portfolio scripts
        wp_register_script('lcni-transaction-controller', LCNI_URL . 'modules/portfolio/assets/js/lcni-transaction-controller.js', ['lcni-order-service', 'lcni-dnse-order-service'], $cjs_ver, true);
        wp_register_style('lcni-transaction-modal',       LCNI_URL . 'modules/portfolio/assets/css/lcni-transaction-modal.css',    ['lcni-ui-table'], $ccss_ver);

        wp_register_script('lcni-portfolio',        LCNI_URL . 'modules/portfolio/assets/js/portfolio.js',             ['jquery', 'lcni-order-service', 'lcni-transaction-controller'], $js_ver,  true);
        wp_register_style('lcni-portfolio',          LCNI_URL . 'modules/portfolio/assets/css/portfolio.css',           ['lcni-ui-table', 'lcni-transaction-modal'], $css_ver);
        wp_register_script('lcni-add-tx-widget',    LCNI_URL . 'modules/portfolio/assets/js/lcni-add-tx-widget.js',    ['lcni-transaction-controller'],             $wjs_ver, true);
        wp_register_style('lcni-add-tx-widget',     LCNI_URL . 'modules/portfolio/assets/css/lcni-add-tx-widget.css',  ['lcni-ui-table', 'lcni-transaction-modal'], $wcss_ver);
    }

    public function render($atts = []) {
        if (!is_user_logged_in()) {
            return '<p class="lcni-pf-notice">Vui lòng <a href="' . esc_url(wp_login_url(get_permalink())) . '">đăng nhập</a> để xem danh mục.</p>';
        }

        wp_enqueue_script('lcni-dnse-order-service');
        wp_enqueue_script('lcni-dnse-order-service');
        wp_enqueue_script('lcni-order-service');
        wp_enqueue_script('lcni-transaction-controller');
        wp_enqueue_style('lcni-transaction-modal');
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

        $tx_config = [
            'restUrl'    => $rest_url,
            'nonce'      => $nonce,
            'portfolios' => $portfolios,
            'activeId'   => $active_id,
        ];
        wp_localize_script('lcni-transaction-controller', 'lcniTxControllerConfig', $tx_config);
        // Note: lcni-add-tx-widget not enqueued here; its config is set in enqueue_add_tx_widget()

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
                    <div class="lcni-pf-table-wrap lcni-table-wrapper">
                        <table class="lcni-pf-table lcni-table" id="lcni-pf-holdings-table">
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
                <div class="lcni-pf-table-wrap lcni-table-wrapper">
                    <table class="lcni-pf-table lcni-table" id="lcni-pf-tx-table">
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
                            <!-- Buying power hint — shown only when DNSE connected + buy tx + price filled -->
                            <div id="lcni-pf-buying-power" class="lcni-pf-buying-power" style="display:none;"></div>
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
                                    <option value="MP">MP — Thị trường (khớp lệnh liên tục)</option>
                                    <option value="PM">PM — Thị trường (phiên chiều)</option>
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

    /* =========================================================
     * Helper: enqueue widget assets + localize config
     * ======================================================= */
    private function enqueue_add_tx_widget( int $active_id, array $portfolios ) {
        // Load portfolio script + modal CSS so lcniOpenPortfolioTxModal is available
        // even when [lcni_portfolio] shortcode is not on the same page.
        wp_enqueue_script('lcni-dnse-order-service');
        wp_enqueue_script('lcni-order-service');
        wp_enqueue_script('lcni-transaction-controller');
        wp_enqueue_style('lcni-transaction-modal');
        wp_enqueue_script('lcni-portfolio');
        wp_enqueue_style('lcni-portfolio');
        wp_enqueue_script('lcni-add-tx-widget');
        wp_enqueue_style('lcni-add-tx-widget');

        static $localized = false;
        if ( ! $localized ) {
            $localized = true;

            // Resolve DNSE accounts for this user
            $dnse_accounts = [];
            if ( class_exists( 'LCNI_DnseTradingRepository' ) ) {
                try {
                    $dnse_repo  = new LCNI_DnseTradingRepository();
                    $dnse_accts = $dnse_repo->get_accounts( get_current_user_id() );
                    foreach ( $dnse_accts as $a ) {
                        $dnse_accounts[] = [
                            'id'    => $a['account_no'],
                            'label' => ( $a['account_type_name'] ?: ( $a['account_type'] === 'margin' ? 'Margin' : 'Thường' ) )
                                       . ' (' . $a['account_no'] . ')',
                            'type'  => $a['account_type'],
                        ];
                    }
                } catch ( Throwable $e ) {}
            }

            $rest_url = esc_url_raw( rest_url('lcni/v1') );
            $nonce    = wp_create_nonce('wp_rest');

            $config = [
                'restUrl'      => $rest_url,
                'nonce'        => $nonce,
                'portfolios'   => $portfolios,
                'activeId'     => $active_id,
                'dnseAccounts' => $dnse_accounts,
                'dnseOrderUrl' => rest_url('lcni/v1/dnse/order'),
            ];

            // Localize lcniPortfolioConfig so portfolio.js initialises correctly
            // (needed when portfolio.js loads without the [lcni_portfolio] shortcode)
            wp_localize_script('lcni-portfolio',              'lcniPortfolioConfig',    $config);
            wp_localize_script('lcni-transaction-controller', 'lcniTxControllerConfig', $config);
            wp_localize_script('lcni-add-tx-widget',          'lcniAddTxConfig',        $config);
        }
    }

    /* =========================================================
     * [lcni_add_transaction] — form nhúng inline vào page
     * ======================================================= */
    public function render_add_tx_inline( $atts = [] ) {
        if ( ! is_user_logged_in() ) {
            return '<p class="lcni-pf-notice">Vui lòng <a href="' . esc_url( wp_login_url( get_permalink() ) ) . '">đăng nhập</a> để thêm giao dịch.</p>';
        }

        $user_id    = get_current_user_id();
        $portfolios = $this->service->get_portfolios($user_id);

        if ( empty($portfolios) ) {
            $this->service->create_portfolio( $user_id, 'Danh mục của tôi' );
            $portfolios = $this->service->get_portfolios($user_id);
        }

        $active_id = (int) ( $portfolios[0]['id'] ?? 0 );
        foreach ( $portfolios as $p ) {
            if ( $p['is_default'] ) { $active_id = (int) $p['id']; break; }
        }

        $this->enqueue_add_tx_widget( $active_id, $portfolios );

        return '<div data-lcni-add-tx-inline="1" class="lcni-atx-shortcode-wrap"></div>';
    }

    /* =========================================================
     * [lcni_add_transaction_float] — nút thả trôi
     *
     * Atts:
     *   edge      = right | left    (default: right)
     *   offset    = CSS top value   (default: 50%)
     *   label     = text nút        (default: ＋ GD)
     *   collapsed = true | false    (default: true)
     * ======================================================= */
    public function render_add_tx_float( $atts = [] ) {
        if ( ! is_user_logged_in() ) return '';

        $atts = shortcode_atts([
            'edge'      => 'right',
            'offset'    => '50%',
            'label'     => '＋ GD',
            'collapsed' => 'true',
        ], $atts, 'lcni_add_transaction_float');

        $user_id    = get_current_user_id();
        $portfolios = $this->service->get_portfolios($user_id);

        if ( empty($portfolios) ) {
            $this->service->create_portfolio( $user_id, 'Danh mục của tôi' );
            $portfolios = $this->service->get_portfolios($user_id);
        }

        $active_id = (int) ( $portfolios[0]['id'] ?? 0 );
        foreach ( $portfolios as $p ) {
            if ( $p['is_default'] ) { $active_id = (int) $p['id']; break; }
        }

        $this->enqueue_add_tx_widget( $active_id, $portfolios );

        return sprintf(
            '<span data-lcni-add-tx-float="1" data-edge="%s" data-offset="%s" data-label="%s" data-collapsed="%s" style="display:none;" aria-hidden="true"></span>',
            esc_attr( sanitize_key( $atts['edge'] ) ),
            esc_attr( $atts['offset'] ),
            esc_attr( $atts['label'] ),
            esc_attr( $atts['collapsed'] )
        );
    }
}
