<?php
/**
 * Custom Index Calculator
 *
 * Phương pháp: Value-Weighted (Liquidity-Weighted)
 *
 *   index_t = ( Σ close_i × value_traded_i ) / ( Σ value_traded_i )  ×  scale
 *
 * Trong đó:
 *   close_i        — giá đóng cửa mã i (nghìn đồng)
 *   value_traded_i — close_i × volume_i (nghìn đồng · cổ phiếu, tỷ lệ với GTGD)
 *   scale          — hệ số để index ≈ 1000 tại phiên gốc (giống VNIndex)
 *
 * Công thức thực tế:
 *   weighted_price_t = Σ(close_i × value_i) / Σ(value_i)   — giá bình quân
 *   index_t          = weighted_price_t / base_weighted_price × 100
 *
 * OHLC được tính theo cùng công thức cho open/high/low/close.
 * High/Low dùng high_price/low_price thay vì close_price.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class LCNI_Custom_Index_Calculator {

    private wpdb $wpdb;

    public function __construct( wpdb $wpdb ) {
        $this->wpdb = $wpdb;
    }

    // =========================================================================
    // PUBLIC: Tính và lưu toàn bộ lịch sử cho một chỉ số
    // =========================================================================

    /**
     * Backfill toàn bộ lịch sử OHLC cho chỉ số.
     *
     * @param  array  $index  Row từ lcni_custom_index
     * @param  string $timeframe  VD: '1D'
     * @param  int    $limit  Số phiên tối đa (0 = không giới hạn)
     * @return int    Số phiên đã upsert
     */
    public function backfill( array $index, string $timeframe = '1D', int $limit = 0 ): int {
        $index_id = (int) $index['id'];
        $rows     = $this->compute_all_sessions( $index, $timeframe, $limit );

        if ( empty( $rows ) ) return 0;

        // Xác định base (phiên đầu tiên — index = 100.00)
        $base_row = reset( $rows );
        $this->ensure_base( $index_id, $base_row );

        // Reload base_value sau khi lưu
        $index = $this->reload_index( $index_id );

        return $this->upsert_rows( $index, $rows );
    }

    /**
     * Tính và upsert một phiên cụ thể (dùng cho cron hàng ngày).
     *
     * @param  array  $index
     * @param  string $timeframe
     * @param  int    $event_time  Unix timestamp của phiên cần tính
     * @return bool
     */
    public function compute_session( array $index, string $timeframe, int $event_time ): bool {
        $row = $this->compute_one_session( $index, $timeframe, $event_time );
        if ( ! $row ) return false;

        $this->ensure_base( (int) $index['id'], $row );
        $index = $this->reload_index( (int) $index['id'] );

        return $this->upsert_rows( $index, [ $row ] ) > 0;
    }

    // =========================================================================
    // CORE SQL: Tính giá trị chỉ số từ lcni_ohlc
    // =========================================================================

    /**
     * Tính toàn bộ phiên — trả về array rows thô chưa scale.
     * Mỗi row: [ event_time, open_wp, high_wp, low_wp, close_wp,
     *            total_value_traded, so_ma, so_tang, so_giam ]
     */
    private function compute_all_sessions( array $index, string $timeframe, int $limit ): array {
        [ $join_sql, $where_parts, $params ] = $this->build_filter_sql( $index, $timeframe );

        $limit_sql = $limit > 0 ? $this->wpdb->prepare( 'LIMIT %d', $limit ) : '';

        $sql = "
            SELECT
                o.event_time,
                -- Weighted prices (chưa chia base)
                SUM( o.open_price  * o.value_traded ) / NULLIF( SUM( o.value_traded ), 0 )
                    AS open_wp,
                SUM( o.high_price  * o.value_traded ) / NULLIF( SUM( o.value_traded ), 0 )
                    AS high_wp,
                SUM( o.low_price   * o.value_traded ) / NULLIF( SUM( o.value_traded ), 0 )
                    AS low_wp,
                SUM( o.close_price * o.value_traded ) / NULLIF( SUM( o.value_traded ), 0 )
                    AS close_wp,
                -- Tổng giá trị giao dịch (nghìn đồng)
                SUM( o.value_traded )                  AS total_value_traded,
                -- Breadth
                COUNT(*)                               AS so_ma,
                SUM( CASE WHEN COALESCE(o.pct_t_1, 0) > 0 THEN 1 ELSE 0 END ) AS so_tang,
                SUM( CASE WHEN COALESCE(o.pct_t_1, 0) < 0 THEN 1 ELSE 0 END ) AS so_giam
            FROM {$this->wpdb->prefix}lcni_ohlc o
            {$join_sql}
            WHERE " . implode( ' AND ', $where_parts ) . "
                AND o.value_traded > 0
            GROUP BY o.event_time
            ORDER BY o.event_time ASC
            {$limit_sql}";

        $prepared = empty( $params )
            ? $sql
            : $this->wpdb->prepare( $sql, $params );

        return (array) $this->wpdb->get_results( $prepared, ARRAY_A );
    }

    /**
     * Tính một phiên đơn lẻ.
     */
    private function compute_one_session( array $index, string $timeframe, int $event_time ): ?array {
        [ $join_sql, $where_parts, $params ] = $this->build_filter_sql( $index, $timeframe );

        $where_parts[] = 'o.event_time = %d';
        $params[]      = $event_time;

        $sql = "
            SELECT
                o.event_time,
                SUM( o.open_price  * o.value_traded ) / NULLIF( SUM( o.value_traded ), 0 ) AS open_wp,
                SUM( o.high_price  * o.value_traded ) / NULLIF( SUM( o.value_traded ), 0 ) AS high_wp,
                SUM( o.low_price   * o.value_traded ) / NULLIF( SUM( o.value_traded ), 0 ) AS low_wp,
                SUM( o.close_price * o.value_traded ) / NULLIF( SUM( o.value_traded ), 0 ) AS close_wp,
                SUM( o.value_traded ) AS total_value_traded,
                COUNT(*) AS so_ma,
                SUM( CASE WHEN COALESCE(o.pct_t_1, 0) > 0 THEN 1 ELSE 0 END ) AS so_tang,
                SUM( CASE WHEN COALESCE(o.pct_t_1, 0) < 0 THEN 1 ELSE 0 END ) AS so_giam
            FROM {$this->wpdb->prefix}lcni_ohlc o
            {$join_sql}
            WHERE " . implode( ' AND ', $where_parts ) . "
                AND o.value_traded > 0
            GROUP BY o.event_time
            LIMIT 1";

        $row = $this->wpdb->get_row( $this->wpdb->prepare( $sql, $params ), ARRAY_A );
        return $row ?: null;
    }

    // =========================================================================
    // UPSERT: Lưu vào lcni_custom_index_ohlc
    // =========================================================================

    /**
     * Upsert array rows vào bảng OHLC, scale theo base_value.
     */
    private function upsert_rows( array $index, array $rows ): int {
        $ohlc_table = LCNI_Custom_Index_DB::ohlc_table();
        $index_id   = (int) $index['id'];
        $base_value = (float) ( $index['base_value'] ?? 0 );
        $timeframe  = $rows[0]['timeframe'] ?? '1D'; // được set bởi compute

        if ( $base_value <= 0 ) {
            error_log( '[LCNI CustomIndex] base_value = 0 for index #' . $index_id . ' — run backfill first.' );
            return 0;
        }

        $count = 0;
        foreach ( $rows as $row ) {
            $scale      = (float) ( $row['close_wp'] ?? 0 ) > 0
                          ? 100.0 / $base_value
                          : 0;

            $close_val  = round( (float) ( $row['close_wp'] ?? 0 ) * $scale, 4 );
            $open_val   = round( (float) ( $row['open_wp']  ?? 0 ) * $scale, 4 );
            $high_val   = round( (float) ( $row['high_wp']  ?? 0 ) * $scale, 4 );
            $low_val    = round( (float) ( $row['low_wp']   ?? 0 ) * $scale, 4 );

            if ( $close_val <= 0 ) continue;

            $result = $this->wpdb->query(
                $this->wpdb->prepare(
                    "INSERT INTO {$ohlc_table}
                        (index_id, timeframe, event_time,
                         open_value, high_value, low_value, close_value,
                         total_value_traded, so_ma, so_tang, so_giam)
                     VALUES (%d, %s, %d, %f, %f, %f, %f, %f, %d, %d, %d)
                     ON DUPLICATE KEY UPDATE
                         open_value         = VALUES(open_value),
                         high_value         = VALUES(high_value),
                         low_value          = VALUES(low_value),
                         close_value        = VALUES(close_value),
                         total_value_traded = VALUES(total_value_traded),
                         so_ma              = VALUES(so_ma),
                         so_tang            = VALUES(so_tang),
                         so_giam            = VALUES(so_giam),
                         updated_at         = NOW()",
                    $index_id,
                    $timeframe,
                    (int) $row['event_time'],
                    $open_val, $high_val, $low_val, $close_val,
                    (float) ( $row['total_value_traded'] ?? 0 ),
                    (int)   ( $row['so_ma']   ?? 0 ),
                    (int)   ( $row['so_tang'] ?? 0 ),
                    (int)   ( $row['so_giam'] ?? 0 )
                )
            );
            if ( $result !== false ) $count++;
        }
        return $count;
    }

    // =========================================================================
    // BASE VALUE: Phiên đầu tiên = 100.00
    // =========================================================================

    /**
     * Lưu base_value vào lcni_custom_index nếu chưa có.
     * base_value = close_wp của phiên đầu tiên
     * → index tại phiên đó = 100.00
     */
    private function ensure_base( int $index_id, array $first_row ): void {
        $idx_table = LCNI_Custom_Index_DB::index_table();

        $existing = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT base_value FROM {$idx_table} WHERE id = %d", $index_id
            )
        );

        if ( $existing !== null && (float) $existing > 0 ) return;

        $close_wp = (float) ( $first_row['close_wp'] ?? 0 );
        if ( $close_wp <= 0 ) return;

        $this->wpdb->update(
            $idx_table,
            [
                'base_event_time' => (int) $first_row['event_time'],
                'base_value'      => $close_wp,
            ],
            [ 'id' => $index_id ],
            [ '%d', '%f' ],
            [ '%d' ]
        );
    }

    private function reload_index( int $index_id ): array {
        $idx_table = LCNI_Custom_Index_DB::index_table();
        return (array) $this->wpdb->get_row(
            $this->wpdb->prepare( "SELECT * FROM {$idx_table} WHERE id = %d", $index_id ),
            ARRAY_A
        );
    }

    // =========================================================================
    // FILTER BUILDER: Lọc symbol theo cấu hình chỉ số
    // =========================================================================

    /**
     * Build JOIN + WHERE cho filter symbol.
     * Returns [ $join_sql, $where_parts[], $params[] ]
     */
    private function build_filter_sql( array $index, string $timeframe ): array {
        $mapping = $this->wpdb->prefix . 'lcni_sym_icb_market';
        $icb2    = $this->wpdb->prefix . 'lcni_icb2';
        $wl_sym  = $this->wpdb->prefix . 'lcni_watchlist_symbols';

        $joins        = [];
        $where_parts  = [
            "o.timeframe   = %s",
            "o.symbol_type = 'STOCK'",
        ];
        $params = [ strtoupper( $timeframe ) ];

        $scope      = sanitize_text_field( (string) ( $index['symbol_scope'] ?? 'all' ) );
        $exchange   = sanitize_text_field( (string) ( $index['exchange']     ?? '' ) );
        $id_icb2    = absint( $index['id_icb2'] ?? 0 );
        $wl_id      = absint( $index['scope_watchlist_id'] ?? 0 );
        $custom_raw = sanitize_textarea_field( (string) ( $index['scope_custom_list'] ?? '' ) );

        // JOIN mapping nếu cần lọc theo sàn hoặc ngành
        $need_mapping = $exchange !== '' || $id_icb2 > 0;
        if ( $need_mapping ) {
            $joins[] = "INNER JOIN {$mapping} m ON m.symbol = o.symbol";
        }

        if ( $exchange !== '' ) {
            $where_parts[] = 'm.exchange = %s';
            $params[]      = strtoupper( $exchange );
        }

        if ( $id_icb2 > 0 ) {
            $where_parts[] = 'm.id_icb2 = %d';
            $params[]      = $id_icb2;
        }

        // Lọc theo scope
        if ( $scope === 'custom' && $custom_raw !== '' ) {
            $symbols = array_values( array_filter( array_map(
                static fn( $s ) => strtoupper( trim( $s ) ),
                explode( ',', $custom_raw )
            ) ) );
            if ( ! empty( $symbols ) ) {
                $phs           = implode( ',', array_fill( 0, count( $symbols ), '%s' ) );
                $where_parts[] = "o.symbol IN ({$phs})";
                array_push( $params, ...$symbols );
            }
        } elseif ( $scope === 'watchlist' && $wl_id > 0 ) {
            $where_parts[] = "o.symbol IN (SELECT symbol FROM {$wl_sym} WHERE watchlist_id = %d)";
            $params[]      = $wl_id;
        }

        $join_sql = empty( $joins ) ? '' : implode( "\n            ", $joins );
        return [ $join_sql, $where_parts, $params ];
    }

    // =========================================================================
    // READ: Lấy OHLC để hiển thị
    // =========================================================================

    /**
     * Lấy nến OHLC của chỉ số từ DB (đã scale).
     */
    public function get_candles( int $index_id, string $timeframe, int $limit = 200 ): array {
        $ohlc_table = LCNI_Custom_Index_DB::ohlc_table();
        $limit = max( 1, min( 2000, $limit ) );

        return (array) $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT event_time,
                        open_value  AS open,
                        high_value  AS high,
                        low_value   AS low,
                        close_value AS close,
                        total_value_traded AS value,
                        so_ma, so_tang, so_giam
                 FROM {$ohlc_table}
                 WHERE index_id = %d AND timeframe = %s
                 ORDER BY event_time DESC
                 LIMIT %d",
                $index_id, strtoupper( $timeframe ), $limit
            ),
            ARRAY_A
        );
    }

    /**
     * Lấy candle mới nhất (dùng để tính pct change).
     */
    public function get_latest_candle( int $index_id, string $timeframe ): ?array {
        $ohlc_table = LCNI_Custom_Index_DB::ohlc_table();
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$ohlc_table}
                 WHERE index_id = %d AND timeframe = %s
                 ORDER BY event_time DESC LIMIT 1",
                $index_id, strtoupper( $timeframe )
            ),
            ARRAY_A
        );
        return $row ?: null;
    }
}
