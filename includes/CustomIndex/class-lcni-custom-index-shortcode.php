<?php
/**
 * Custom Index Shortcode
 *
 * Hiển thị chart candlestick cho chỉ số tùy chỉnh, dùng ECharts.
 * Dữ liệu lấy từ REST API /lcni/v1/custom-indexes/{id}/candles
 *
 * Shortcodes:
 *   [lcni_custom_index id="1" height="360" timeframe="1D" limit="200" show_breadth="1"]
 *   [lcni_custom_index_list]   — danh sách tất cả chỉ số dạng cards
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class LCNI_Custom_Index_Shortcode {

    private LCNI_Custom_Index_Repository $repo;
    private LCNI_Custom_Index_Calculator $calc;

    public function __construct(
        LCNI_Custom_Index_Repository $repo,
        LCNI_Custom_Index_Calculator $calc
    ) {
        $this->repo = $repo;
        $this->calc = $calc;

        add_action( 'init',               [ $this, 'register' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'register_assets' ] );
    }

    public function register(): void {
        add_shortcode( 'lcni_custom_index',      [ $this, 'render_chart' ] );
        add_shortcode( 'lcni_custom_index_list', [ $this, 'render_list' ] );
    }

    public function register_assets(): void {
        // ECharts từ CDN
        wp_register_script(
            'lcni-echarts',
            'https://cdn.jsdelivr.net/npm/echarts@5/dist/echarts.min.js',
            [], '5.x', true
        );
        // Inline JS của module này (được enqueue khi shortcode render)
        $ci_ver = defined('LCNI_VERSION') ? LCNI_VERSION : '1.0';
        wp_register_script(
            'lcni-custom-index-chart',
            LCNI_URL . 'includes/CustomIndex/custom-index-chart.js',
            [ 'lcni-echarts' ], $ci_ver, true
        );
        wp_register_style(
            'lcni-custom-index-chart',
            LCNI_URL . 'includes/CustomIndex/custom-index-chart.css',
            [], $ci_ver
        );
    }

    // =========================================================================
    // [lcni_custom_index id="N" ...]
    // =========================================================================

    public function render_chart( $atts = [] ): string {
        $atts = shortcode_atts( [
            'id'           => 0,
            'height'       => 360,
            'timeframe'    => '1D',
            'limit'        => 200,
            'show_breadth' => 1,       // hiện panel số mã tăng/giảm
            'show_volume'  => 1,       // hiện panel value_traded
            'title'        => '',      // override tên chỉ số
        ], $atts, 'lcni_custom_index' );

        $id = absint( $atts['id'] );
        if ( $id <= 0 ) return '<p style="color:red">[lcni_custom_index] Thiếu thuộc tính id.</p>';

        $index = $this->repo->find( $id );
        if ( ! $index ) return '<p style="color:red">[lcni_custom_index] Không tìm thấy chỉ số #' . $id . '.</p>';

        $height       = max( 240, min( 1200, (int) $atts['height'] ) );
        $tf           = strtoupper( sanitize_text_field( $atts['timeframe'] ) );
        $limit        = max( 10, min( 2000, (int) $atts['limit'] ) );
        $show_breadth = ! empty( $atts['show_breadth'] );
        $show_volume  = ! empty( $atts['show_volume'] );
        $title        = sanitize_text_field( $atts['title'] ) ?: $index['name'];

        // Lấy latest để hiện headline
        $latest = $this->calc->get_latest_candle( $id, $tf );
        $latest_close = $latest ? round( (float) $latest['close_value'], 2 ) : null;

        $api_url = rest_url( "lcni/v1/custom-indexes/{$id}/candles" );
        $uid     = 'lcni-ci-' . $id . '-' . wp_rand( 1000, 9999 );

        $config = wp_json_encode( [
            'api_url'      => $api_url,
            'nonce'        => wp_create_nonce( 'wp_rest' ),
            'index_id'     => $id,
            'timeframe'    => $tf,
            'limit'        => $limit,
            'show_breadth' => $show_breadth,
            'show_volume'  => $show_volume,
            'title'        => $title,
        ] );

        wp_enqueue_script( 'lcni-custom-index-chart' );
        wp_enqueue_style( 'lcni-custom-index-chart' );

        ob_start();
        ?>
<div class="lcni-ci-wrap" id="<?php echo esc_attr( $uid ); ?>"
     data-config="<?php echo esc_attr( $config ); ?>">

    <div class="lcni-ci-header">
        <span class="lcni-ci-name"><?php echo esc_html( $title ); ?></span>
        <?php if ( $latest_close !== null ): ?>
        <span class="lcni-ci-value"><?php echo number_format( $latest_close, 2 ); ?></span>
        <span class="lcni-ci-pct" id="<?php echo esc_attr( $uid ); ?>-pct">—</span>
        <?php endif; ?>
        <span class="lcni-ci-tf-group">
            <?php foreach ( [ '1D', '1W', '1M' ] as $t ): ?>
            <button class="lcni-ci-tf-btn<?php echo $t === $tf ? ' active' : ''; ?>"
                    data-tf="<?php echo esc_attr( $t ); ?>"
                    onclick="lcniCiSetTf('<?php echo esc_attr( $uid ); ?>','<?php echo esc_attr( $t ); ?>')">
                <?php echo esc_html( $t ); ?>
            </button>
            <?php endforeach; ?>
        </span>
    </div>


    <!-- Chart chính -->
    <div class="lcni-ci-chart" id="<?php echo esc_attr( $uid ); ?>-main"
         style="height:<?php echo esc_attr( (string) $height ); ?>px"></div>

    <?php if ( $show_volume ): ?>
    <!-- Panel value traded -->
    <div class="lcni-ci-chart lcni-ci-chart--sub" id="<?php echo esc_attr( $uid ); ?>-vol"
         style="height:80px"></div>
    <?php endif; ?>

    <?php if ( $show_breadth ): ?>
    <!-- Panel breadth: số mã tăng/giảm -->
    <div class="lcni-ci-breadth" id="<?php echo esc_attr( $uid ); ?>-breadth">
        <span>Tăng: <strong id="<?php echo esc_attr( $uid ); ?>-so-tang">—</strong></span>
        <span>Giảm: <strong id="<?php echo esc_attr( $uid ); ?>-so-giam">—</strong></span>
        <span>Tổng mã: <strong id="<?php echo esc_attr( $uid ); ?>-so-ma">—</strong></span>
    </div>
    <?php endif; ?>

    <div class="lcni-ci-loader" id="<?php echo esc_attr( $uid ); ?>-loader">Đang tải...</div>
    <div class="lcni-ci-error" id="<?php echo esc_attr( $uid ); ?>-error" style="display:none"></div>

    <!-- Chu thich cong thuc tinh chi so -->
    <details class="lcni-ci-formula">
        <summary class="lcni-ci-formula__toggle">&#128208; C&aacute;ch t&iacute;nh &amp; &yacute; ngh&#297;a ch&#7881; s&#7889;</summary>
        <div class="lcni-ci-formula__body">

            <div class="lcni-ci-formula__block">
                <div class="lcni-ci-formula__section-title">Ph&#432;&#417;ng ph&aacute;p: Value-Weighted (Liquidity-Weighted)</div>
                <div class="lcni-ci-formula__desc">
                    M&#7895;i m&atilde; &#273;&#432;&#7907;c tr&#7885;ng s&#7889; h&oacute;a theo <strong>gi&aacute; tr&#7883; giao d&#7883;ch</strong>
                    (GTGD&nbsp;=&nbsp;gi&aacute; &#273;&oacute;ng c&#7917;a&nbsp;&times;&nbsp;kh&#7889;i l&#432;&#7907;ng), t&#432;&#417;ng t&#7921; c&aacute;ch VNIndex t&iacute;nh theo v&#7889;n h&oacute;a &mdash;
                    nh&#432;ng ph&#7843;n &aacute;nh tr&#7921;c ti&#7871;p <em>d&ograve;ng ti&#7873;n th&#7921;c t&#7871;</em> thay v&igrave; quy m&ocirc; ni&ecirc;m y&#7871;t.
                </div>
            </div>

            <div class="lcni-ci-formula__block">
                <div class="lcni-ci-formula__section-title">C&ocirc;ng th&#7913;c</div>
                <div class="lcni-ci-formula__formula">
                    <div class="lcni-ci-formula__line">
                        <span class="lcni-ci-formula__label">Gi&aacute; b&igrave;nh qu&acirc;n phi&ecirc;n t</span>
                        <span class="lcni-ci-formula__eq">P<sub>t</sub>&nbsp;=&nbsp;&Sigma;(close<sub>i</sub>&nbsp;&times;&nbsp;V<sub>i</sub>)&nbsp;/&nbsp;&Sigma;(V<sub>i</sub>)</span>
                    </div>
                    <div class="lcni-ci-formula__line">
                        <span class="lcni-ci-formula__label">Gi&aacute; tr&#7883; ch&#7881; s&#7889;</span>
                        <span class="lcni-ci-formula__eq">Index<sub>t</sub>&nbsp;=&nbsp;P<sub>t</sub>&nbsp;/&nbsp;P<sub>base</sub>&nbsp;&times;&nbsp;100</span>
                    </div>
                    <div class="lcni-ci-formula__line">
                        <span class="lcni-ci-formula__label">OHLC</span>
                        <span class="lcni-ci-formula__eq">D&ugrave;ng open/high/low/close t&#432;&#417;ng &#7913;ng thay cho close<sub>i</sub></span>
                    </div>
                </div>
                <div class="lcni-ci-formula__legend">
                    <span><b>close<sub>i</sub></b>&nbsp;&mdash; gi&aacute; &#273;&oacute;ng c&#7917;a m&atilde;&nbsp;i</span>
                    <span><b>V<sub>i</sub></b>&nbsp;&mdash; GTGD m&atilde;&nbsp;i&nbsp;=&nbsp;close<sub>i</sub>&nbsp;&times;&nbsp;kh&#7889;i l&#432;&#7907;ng<sub>i</sub></span>
                    <span><b>P<sub>base</sub></b>&nbsp;&mdash; gi&aacute; b&igrave;nh qu&acirc;n phi&ecirc;n g&#7889;c (index&nbsp;=&nbsp;100 t&#7841;i phi&ecirc;n &#273;&oacute;)</span>
                </div>
            </div>

            <div class="lcni-ci-formula__block">
                <div class="lcni-ci-formula__section-title">L&#7907;i &iacute;ch so v&#7899;i b&igrave;nh qu&acirc;n &#273;&#417;n gi&#7843;n</div>
                <div class="lcni-ci-formula__benefits">
                    <div class="lcni-ci-formula__benefit">
                        <span class="lcni-ci-formula__benefit-icon">&#128167;</span>
                        <div>
                            <strong>Ph&#7843;n &aacute;nh d&ograve;ng ti&#7873;n th&#7921;c t&#7871;</strong>
                            <span>M&atilde; &#273;&#432;&#7907;c giao d&#7883;ch nhi&#7873;u (thanh kho&#7843;n cao) c&oacute; &#7843;nh h&#432;&#7903;ng l&#7899;n h&#417;n &mdash; &#273;&uacute;ng v&#7899;i c&aacute;ch th&#7883; tr&#432;&#7901;ng th&#7921;c s&#7921; v&#7853;n &#273;&#7897;ng.</span>
                        </div>
                    </div>
                    <div class="lcni-ci-formula__benefit">
                        <span class="lcni-ci-formula__benefit-icon">&#128737;&#65039;</span>
                        <div>
                            <strong>Ch&#7889;ng m&eacute;o b&#7903;i m&atilde; thanh kho&#7843;n th&#7845;p</strong>
                            <span>M&atilde; kh&#7899;p l&#7879;nh &iacute;t, gi&aacute; bi&#7871;n &#273;&#7897;ng b&#7845;t th&#432;&#7901;ng, kh&ocirc;ng k&eacute;o l&#7879;ch ch&#7881; s&#7889; &mdash; kh&#7855;c ph&#7909;c nh&#432;&#7907;c &#273;i&#7875;m c&#7911;a b&igrave;nh qu&acirc;n &#273;&#417;n gi&#7843;n.</span>
                        </div>
                    </div>
                    <div class="lcni-ci-formula__benefit">
                        <span class="lcni-ci-formula__benefit-icon">&#128202;</span>
                        <div>
                            <strong>N&#7871;n OHLC &#273;&#7847;y &#273;&#7911;</strong>
                            <span>Open/High/Low/Close &#273;&#7873;u t&iacute;nh theo c&ugrave;ng c&ocirc;ng th&#7913;c &mdash; &#273;&#7885;c &#273;&#432;&#7907;c n&#7871;n, v&#7869; &#273;&#432;&#7907;c MA/RSI nh&#432; ch&#7881; s&#7889; g&#7889;c.</span>
                        </div>
                    </div>
                    <div class="lcni-ci-formula__benefit">
                        <span class="lcni-ci-formula__benefit-icon">&#127919;</span>
                        <div>
                            <strong>T&#7921; &#273;&#7883;nh ngh&#297;a r&#7893; c&#7893; phi&#7871;u</strong>
                            <span>L&#7885;c theo s&agrave;n, ng&agrave;nh ICB, danh s&aacute;ch t&ugrave;y ch&#7881;nh ho&#7863;c watchlist &mdash; theo d&otilde;i s&#7913;c m&#7841;nh nh&oacute;m c&#7893; phi&#7871;u quan t&acirc;m.</span>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </details>
</div>
        <?php
        return ob_get_clean();
    }

    // =========================================================================
    // [lcni_custom_index_list]
    // =========================================================================

    public function render_list( $atts = [] ): string {
        $atts = shortcode_atts( [
            'timeframe' => '1D',
            'columns'   => 3,
        ], $atts, 'lcni_custom_index_list' );

        $indexes = $this->repo->get_active();
        if ( empty( $indexes ) ) {
            return '<p>Chưa có chỉ số nào được kích hoạt.</p>';
        }

        $tf   = strtoupper( sanitize_text_field( $atts['timeframe'] ) );
        $cols = max( 1, min( 4, (int) $atts['columns'] ) );

        wp_enqueue_style( 'lcni-custom-index-chart' );

        ob_start();
        echo '<div class="lcni-ci-list" style="display:grid;grid-template-columns:repeat(' . esc_attr( (string) $cols ) . ',1fr);gap:16px">';

        foreach ( $indexes as $idx ) {
            $id     = (int) $idx['id'];
            $latest = $this->calc->get_latest_candle( $id, $tf );
            $close  = $latest ? round( (float) $latest['close_value'], 2 ) : null;

            // Tính % thay đổi nếu có 2 phiên
            $pct = null;
            if ( $latest ) {
                $prev = $this->wpdb_prev_candle( $id, $tf, (int) $latest['event_time'] );
                if ( $prev && (float) $prev['close_value'] > 0 ) {
                    $pct = ( (float) $latest['close_value'] - (float) $prev['close_value'] )
                           / (float) $prev['close_value'] * 100;
                }
            }

            $pct_html = '';
            if ( $pct !== null ) {
                $color    = $pct >= 0 ? '#3fb950' : '#f85149';
                $sign     = $pct >= 0 ? '+' : '';
                $pct_html = '<span style="color:' . $color . ';font-size:13px">' . $sign . number_format( $pct, 2 ) . '%</span>';
            }

            echo '<a class="lcni-ci-card" href="' . esc_url( add_query_arg( 'ci', $id, get_permalink() ) ) . '">';
            echo '<div class="lcni-ci-card__name">' . esc_html( $idx['name'] ) . '</div>';
            if ( $close !== null ) {
                echo '<div class="lcni-ci-card__value">' . number_format( $close, 2 ) . ' ' . $pct_html . '</div>';
            } else {
                echo '<div class="lcni-ci-card__value" style="color:#888">Chưa có dữ liệu</div>';
            }
            echo '<div class="lcni-ci-card__meta">';
            if ( $idx['exchange'] ) echo '<span>' . esc_html( $idx['exchange'] ) . '</span> ';
            $so_ma = $latest ? (int) $latest['so_ma'] : 0;
            if ( $so_ma > 0 ) echo '<span>' . number_format( $so_ma ) . ' mã</span>';
            echo '</div>';
            echo '</a>';
        }

        echo '</div>';
        return ob_get_clean();
    }

    private function wpdb_prev_candle( int $index_id, string $tf, int $before_et ): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT close_value, event_time, so_ma, so_tang, so_giam
                 FROM " . LCNI_Custom_Index_DB::ohlc_table() . "
                 WHERE index_id = %d AND timeframe = %s AND event_time < %d
                 ORDER BY event_time DESC LIMIT 1",
                $index_id, strtoupper( $tf ), $before_et
            ),
            ARRAY_A
        );
        return $row ?: null;
    }
}
