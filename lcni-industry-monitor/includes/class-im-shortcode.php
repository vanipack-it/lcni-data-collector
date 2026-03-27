<?php
/**
 * LCNI_IM_Shortcode
 * Xử lý [lcni_industry_monitor id="N"] cho từng monitor instance.
 * Mode icb  → dùng LCNI_Industry_Data (query lcni_industry_*)
 * Mode symbol → dùng LCNI_IM_Symbol_Data (query lcni_ohlc)
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class LCNI_IM_Shortcode {

    public function register_hooks() {
        // Ghi đè shortcode cũ để hỗ trợ thuộc tính id=
        add_shortcode( 'lcni_industry_monitor',         [ $this, 'render' ] );
        add_shortcode( 'lcni_industry_monitor_compact', [ $this, 'render_compact' ] );
        add_action( 'wp_ajax_lcni_im_data',        [ $this, 'ajax_data' ] );
        add_action( 'wp_ajax_nopriv_lcni_im_data', [ $this, 'ajax_data' ] );
        add_action( 'wp_enqueue_scripts',          [ $this, 'register_assets' ] );
    }

    public function register_assets() {
        wp_register_style(
            'lcni-industry-monitor',
            LCNI_INDUSTRY_MONITOR_URL . 'public/css/industry-monitor.css',
            [ 'lcni-ui-table' ],
            LCNI_INDUSTRY_MONITOR_VERSION
        );
        wp_register_script(
            'lcni-industry-monitor',
            LCNI_INDUSTRY_MONITOR_URL . 'public/js/industry-monitor.js',
            [ 'lcni-main-js' ],
            LCNI_INDUSTRY_MONITOR_VERSION,
            true
        );
    }

    public function render_compact( $atts ) {
        $atts = is_array( $atts ) ? $atts : [];
        $atts['compact'] = '1';
        return $this->render( $atts );
    }

    // ── Main render ──────────────────────────────────────────────────────────

    public function render( $atts ) {
        $atts = shortcode_atts( [
            'id'        => 0,      // monitor instance ID
            'timeframe' => '1D',
            'metric'    => '',
            'id_icb2'   => '',
            'symbols'   => '',     // symbol mode: danh sách mã cách nhau dấu phẩy
            'session'   => '',
            'compact'   => '',
        ], is_array( $atts ) ? $atts : [], 'lcni_industry_monitor' );

        $monitor_id = absint( $atts['id'] );

        // Nếu có id → load từ DB, ngược lại dùng global settings (backward compat)
        if ( $monitor_id > 0 ) {
            $monitor = LCNI_IM_Monitor_DB::find( $monitor_id );
            if ( ! $monitor ) {
                return '<p style="color:red">[lcni_industry_monitor] Monitor #' . $monitor_id . ' không tồn tại.</p>';
            }
            $mode = $monitor['mode'];
            $cfg  = $monitor['config'];
        } else {
            $mode = 'icb';
            $cfg  = LCNI_IM_Monitor_DB::default_config();
        }

        wp_enqueue_style( 'lcni-industry-monitor' );
        wp_enqueue_script( 'lcni-industry-monitor' );

        return $mode === 'symbol'
            ? $this->render_symbol_mode( $atts, $cfg, $monitor_id )
            : $this->render_icb_mode( $atts, $cfg, $monitor_id );
    }

    // ── ICB mode ─────────────────────────────────────────────────────────────

    private function render_icb_mode( array $atts, array $cfg, int $monitor_id ) {
        $data          = new LCNI_Industry_Data();
        $metric_labels = LCNI_Industry_Settings::get_metric_labels();
        $all_metrics   = $data->get_supported_metrics();
        $enabled       = array_values( array_intersect( $all_metrics, (array) ( $cfg['enabled_metrics'] ?? $all_metrics ) ) );
        if ( empty( $enabled ) ) $enabled = $all_metrics;

        $metric_options = [];
        foreach ( $enabled as $k ) {
            $metric_options[] = [ 'key' => $k, 'label' => $metric_labels[ $k ] ?? $k ];
        }

        $requested_metric  = sanitize_key( $atts['metric'] );
        $default_metric    = in_array( $requested_metric, $enabled, true ) ? $requested_metric : ( $metric_options[0]['key'] ?? 'money_flow_share' );
        $default_tf        = strtoupper( trim( $atts['timeframe'] ) ) ?: '1D';
        $id_icb2_list      = $this->parse_ids( $atts['id_icb2'] );
        $session_limit     = absint( $atts['session'] ) ?: (int) ( $cfg['default_session_limit'] ?? 30 );
        $session_limit     = max( 1, min( 200, $session_limit ) );
        $is_compact        = ! empty( $id_icb2_list ) || $atts['session'] || $atts['compact'];
        $container_id      = 'lcni-im-' . wp_generate_password( 8, false, false );
        $nonce             = wp_create_nonce( 'lcni_im_data_nonce' );
        $initial_payload   = $this->cached_payload_icb( $data, $default_metric, $default_tf, $session_limit, $id_icb2_list );

        $config = [
            'ajaxUrl'             => admin_url( 'admin-ajax.php' ),
            'ajaxAction'          => 'lcni_im_data',
            'nonce'               => $nonce,
            'monitorId'           => $monitor_id,
            'mode'                => 'icb',
            'defaultMetric'       => $default_metric,
            'defaultTimeframe'    => $default_tf,
            'defaultSessionLimit' => $session_limit,
            'filterBaseUrl'       => $cfg['industry_filter_url'] ?? '',
            'filterParamKey'      => 'name_icb2',
            'rowHoverEnabled'     => ! empty( $cfg['row_hover_enabled'] ),
            'eventTimeColumnWidth'=> (int) ( $cfg['event_time_col_width'] ?? 140 ),
            'cellRules'           => array_values( (array) ( $cfg['cell_rules'] ?? [] ) ),
            'rowGradientRules'    => array_values( (array) ( $cfg['row_gradient_rules'] ?? [] ) ),
            'initialPayload'      => $initial_payload,
            'idIcb2'              => $id_icb2_list,
            'showFullTableButton' => $is_compact,
            'fullTableUrl'        => (string) ( $cfg['compact_full_table_url'] ?? '' ),
        ];

        return $this->render_html( $container_id, $config, $metric_options, $cfg );
    }

    // ── Symbol mode ──────────────────────────────────────────────────────────

    private function render_symbol_mode( array $atts, array $cfg, int $monitor_id ) {
        $data       = new LCNI_IM_Symbol_Data();
        $ohlc_cols  = LCNI_IM_Monitor_DB::get_ohlc_numeric_columns();
        $enabled    = (array) ( $cfg['ohlc_columns'] ?? [] );
        if ( empty( $enabled ) ) $enabled = array_keys( $ohlc_cols );

        $metric_options = [];
        foreach ( $enabled as $k ) {
            if ( isset( $ohlc_cols[ $k ] ) ) {
                $metric_options[] = [ 'key' => $k, 'label' => $k ];
            }
        }
        if ( empty( $metric_options ) ) {
            return '<p style="color:orange">[lcni_industry_monitor] Monitor #' . $monitor_id . ': chưa chọn cột OHLC nào.</p>';
        }

        $requested_metric  = sanitize_key( $atts['metric'] );
        $default_metric    = in_array( $requested_metric, $enabled, true ) ? $requested_metric : $metric_options[0]['key'];
        $default_tf        = '1D';
        $symbols           = $this->parse_symbols( $atts['symbols'] );
        $session_limit     = absint( $atts['session'] ) ?: (int) ( $cfg['default_session_limit'] ?? 30 );
        $session_limit     = max( 1, min( 200, $session_limit ) );
        $is_compact        = ! empty( $symbols ) || $atts['session'] || $atts['compact'];
        $container_id      = 'lcni-im-' . wp_generate_password( 8, false, false );
        $nonce             = wp_create_nonce( 'lcni_im_data_nonce' );
        $initial_payload   = $this->cached_payload_symbol( $data, $default_metric, $default_tf, $session_limit, $symbols );

        $config = [
            'ajaxUrl'             => admin_url( 'admin-ajax.php' ),
            'ajaxAction'          => 'lcni_im_data',
            'nonce'               => $nonce,
            'monitorId'           => $monitor_id,
            'mode'                => 'symbol',
            'defaultMetric'       => $default_metric,
            'defaultTimeframe'    => $default_tf,
            'defaultSessionLimit' => $session_limit,
            'filterBaseUrl'       => $cfg['symbol_filter_url'] ?? '',
            'filterParamKey'      => 'symbol',
            'rowHoverEnabled'     => ! empty( $cfg['row_hover_enabled'] ),
            'eventTimeColumnWidth'=> (int) ( $cfg['event_time_col_width'] ?? 140 ),
            'cellRules'           => array_values( (array) ( $cfg['cell_rules'] ?? [] ) ),
            'rowGradientRules'    => array_values( (array) ( $cfg['row_gradient_rules'] ?? [] ) ),
            'initialPayload'      => $initial_payload,
            'symbols'             => $symbols,
            'showFullTableButton' => $is_compact,
            'fullTableUrl'        => (string) ( $cfg['compact_full_table_url'] ?? '' ),
        ];

        return $this->render_html( $container_id, $config, $metric_options, $cfg );
    }

    // ── HTML output ──────────────────────────────────────────────────────────

    private function render_html( string $container_id, array $config, array $metric_options, array $cfg ) {
        wp_add_inline_script(
            'lcni-industry-monitor',
            'window.LCNIIndustryMonitors=window.LCNIIndustryMonitors||{};window.LCNIIndustryMonitors[' . wp_json_encode( $container_id ) . ']=' . wp_json_encode( $config ) . ';',
            'before'
        );

        // Template truyền qua $settings để giữ tương thích với industry-table.php
        $settings = array_merge( LCNI_Industry_Settings::get_settings(), [
            'event_time_col_width'  => $cfg['event_time_col_width']  ?? 140,
            'row_hover_enabled'     => $cfg['row_hover_enabled']     ?? 1,
            'industry_filter_url'   => $cfg['industry_filter_url']   ?? '',
            'compact_full_table_url'=> $cfg['compact_full_table_url'] ?? '',
            'default_session_limit' => $cfg['default_session_limit'] ?? 30,
            'cell_rules'            => $cfg['cell_rules']            ?? [],
            'row_gradient_rules'    => $cfg['row_gradient_rules']    ?? [],
        ] );

        ob_start();
        include LCNI_INDUSTRY_MONITOR_PATH . 'templates/industry-table.php';
        return ob_get_clean() ?: '';
    }

    // ── AJAX ────────────────────────────────────────────────────────────────

    public function ajax_data() {
        $nonce = sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) );
        if ( ! wp_verify_nonce( $nonce, 'lcni_im_data_nonce' ) ) {
            wp_send_json_error( [ 'message' => 'Invalid nonce.' ], 403 );
        }

        $monitor_id = absint( $_POST['monitorId'] ?? 0 );
        $mode       = 'icb';
        $cfg        = LCNI_IM_Monitor_DB::default_config();

        if ( $monitor_id > 0 ) {
            $monitor = LCNI_IM_Monitor_DB::find( $monitor_id );
            if ( ! $monitor ) {
                wp_send_json_error( [ 'message' => 'Monitor not found.' ], 404 );
            }
            $mode = $monitor['mode'];
            $cfg  = $monitor['config'];
        }

        $metric  = sanitize_key( wp_unslash( $_POST['metric']    ?? 'money_flow_share' ) );
        $tf      = strtoupper( sanitize_text_field( wp_unslash( $_POST['timeframe'] ?? '1D' ) ) ) ?: '1D';
        $limit   = max( 1, min( 200, absint( $_POST['limit'] ?? ( $cfg['default_session_limit'] ?? 30 ) ) ) );

        if ( $mode === 'symbol' ) {
            $symbols   = $this->parse_symbols( wp_unslash( $_POST['symbols'] ?? '' ) );
            $ohlc_cols = array_keys( LCNI_IM_Monitor_DB::get_ohlc_numeric_columns() );
            $enabled   = (array) ( $cfg['ohlc_columns'] ?? $ohlc_cols );
            if ( ! in_array( $metric, $enabled, true ) ) {
                wp_send_json_error( [ 'message' => 'Unsupported column.' ], 400 );
            }
            $data    = new LCNI_IM_Symbol_Data();
            $payload = $this->cached_payload_symbol( $data, $metric, $tf, $limit, $symbols );
        } else {
            // Nếu có sync_symbols từ filter/watchlist → convert sang id_icb2
            $sync_symbols = $this->parse_symbols( wp_unslash( $_POST['sync_symbols'] ?? '' ) );
            if ( ! empty( $sync_symbols ) ) {
                $id_icb2 = $this->symbols_to_id_icb2( $sync_symbols );
            } else {
                $id_icb2 = $this->parse_ids( wp_unslash( $_POST['id_icb2'] ?? '' ) );
            }
            $data    = new LCNI_Industry_Data();
            $allowed = array_values( array_intersect(
                $data->get_supported_metrics(),
                (array) ( $cfg['enabled_metrics'] ?? $data->get_supported_metrics() )
            ) );
            if ( ! in_array( $metric, $allowed, true ) ) {
                wp_send_json_error( [ 'message' => 'Unsupported metric.' ], 400 );
            }
            $payload = $this->cached_payload_icb( $data, $metric, $tf, $limit, $id_icb2 );
        }

        wp_send_json_success( $payload );
    }

    // ── Payload helpers ──────────────────────────────────────────────────────

    private function cached_payload_icb( LCNI_Industry_Data $data, string $metric, string $tf, int $limit, array $id_icb2 ) {
        $key    = 'lcni_im_icb_' . md5( "$metric|$tf|$limit|" . implode( ',', $id_icb2 ) );
        $cached = get_transient( $key );
        if ( is_array( $cached ) ) return $cached;

        $columns = $data->get_event_times( $tf, $limit, $metric, $id_icb2 );
        if ( empty( $columns ) ) {
            $tf      = $data->resolve_timeframe( $metric, $tf );
            $columns = $data->get_event_times( $tf, $limit, $metric, $id_icb2 );
        }
        $rows    = $data->get_metric_rows( $metric, $tf, $columns, $id_icb2 );
        $payload = [
            'columns'    => array_map( [ $data, 'format_event_time' ], $columns ),
            'rawColumns' => $columns,
            'rows'       => $rows,
        ];
        set_transient( $key, $payload, 60 );
        return $payload;
    }

    private function cached_payload_symbol( LCNI_IM_Symbol_Data $data, string $metric, string $tf, int $limit, array $symbols ) {
        $key    = 'lcni_im_sym_' . md5( "$metric|$tf|$limit|" . implode( ',', $symbols ) );
        $cached = get_transient( $key );
        if ( is_array( $cached ) ) return $cached;

        $columns = $data->get_event_times( $tf, $limit, $metric, $symbols );
        $rows    = $data->get_metric_rows( $metric, $tf, $columns, $symbols );
        $payload = [
            'columns'    => array_map( [ $data, 'format_event_time' ], $columns ),
            'rawColumns' => $columns,
            'rows'       => $rows,
        ];
        set_transient( $key, $payload, 60 );
        return $payload;
    }

    // ── Parsers ──────────────────────────────────────────────────────────────

    private function parse_ids( $raw ) {
        return array_values( array_filter( array_map( 'absint', explode( ',', (string) $raw ) ) ) );
    }

    private function parse_symbols( $raw ) {
        return array_values( array_filter( array_map(
            function( $s ) { return strtoupper( sanitize_text_field( trim( $s ) ) ); },
            explode( ',', (string) $raw )
        ) ) );
    }

    /**
     * Lookup id_icb2 từ danh sách symbols qua bảng lcni_sym_icb_market + lcni_symbols.
     * Trả về mảng id_icb2 duy nhất (đã dedup) tương ứng với các symbols.
     *
     * @param  string[] $symbols
     * @return int[]
     */
    private function symbols_to_id_icb2( array $symbols ) : array {
        global $wpdb;
        if ( empty( $symbols ) ) return [];

        $placeholders = implode( ',', array_fill( 0, count( $symbols ), '%s' ) );

        // Ưu tiên bảng lcni_sym_icb_market (mapping tùy chỉnh), fallback lcni_symbols
        $map_table = $wpdb->prefix . 'lcni_sym_icb_market';
        $sym_table = $wpdb->prefix . 'lcni_symbols';

        $rows = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT COALESCE(m.id_icb2, s.id_icb2)
                 FROM {$sym_table} s
                 LEFT JOIN {$map_table} m ON m.symbol = s.symbol
                 WHERE s.symbol IN ({$placeholders})
                   AND COALESCE(m.id_icb2, s.id_icb2) IS NOT NULL
                   AND COALESCE(m.id_icb2, s.id_icb2) > 0",
                ...$symbols
            )
        );

        return array_values( array_filter( array_map( 'absint', (array) $rows ) ) );
    }
}
