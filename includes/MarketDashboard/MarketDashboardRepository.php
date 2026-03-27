<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * MarketDashboardRepository
 *
 * Truy vấn 6 bảng thống kê và tổng hợp thành một snapshot
 * market_context hoàn chỉnh. Snapshot này:
 *   1) Hiển thị trên Market Dashboard shortcode
 *   2) Ghi vào bảng wp_lcni_market_context để Recommend Rule engine
 *      có thể JOIN và lọc theo điều kiện thị trường
 *
 * Các field được thiết kế extensible: mỗi group có thể thêm field mới
 * mà không phá vỡ schema cũ (LONGTEXT JSON + ALTER TABLE khi cần index).
 */
class LCNI_MarketDashboardRepository {

    /** @var wpdb */
    private $wpdb;

    // ─── Table names ─────────────────────────────────────────────────────────
    private $tbl_ohlc;
    private $tbl_thong_ke_tt;
    private $tbl_thong_ke_nganh;
    private $tbl_thong_ke_nganh_toan;
    private $tbl_industry_return;
    private $tbl_industry_index;
    private $tbl_industry_metrics;
    private $tbl_icb2;
    private $tbl_context;
    private $tbl_context_latest;

    // Cache kết quả SHOW COLUMNS (tránh gọi lại nhiều lần trong 1 request)
    private $col_cache = [];

    public function __construct( ?wpdb $wpdb = null ) {
        if ( $wpdb !== null ) {
            $this->wpdb = $wpdb;
        } else {
            global $wpdb;
            $this->wpdb = $wpdb;
        }
        $p = $this->wpdb->prefix;

        $this->tbl_ohlc                = $p . 'lcni_ohlc';
        $this->tbl_thong_ke_tt         = $p . 'lcni_thong_ke_thi_truong';
        $this->tbl_thong_ke_nganh      = $p . 'lcni_thong_ke_nganh_icb_2';
        $this->tbl_thong_ke_nganh_toan = $p . 'lcni_thong_ke_nganh_icb_2_toan_thi_truong';
        $this->tbl_industry_return     = $p . 'lcni_industry_return';
        $this->tbl_industry_index      = $p . 'lcni_industry_index';
        $this->tbl_industry_metrics    = $p . 'lcni_industry_metrics';
        $this->tbl_icb2                = $p . 'lcni_icb2';
        $this->tbl_context             = $p . 'lcni_market_context';
        $this->tbl_context_latest      = $p . 'lcni_market_context_latest';
    }

    // =========================================================================
    // PUBLIC: Lấy snapshot đầy đủ
    // =========================================================================

    /**
     * Trả về toàn bộ market context snapshot.
     * Nếu $use_cache = true → đọc từ bảng lcni_market_context trước.
     *
     * @param string $timeframe  '1D' | '1W' | '1M'
     * @param int    $event_time 0 = tự lấy latest
     * @param bool   $use_cache  true = ưu tiên đọc cache DB
     * @return array
     */
    public function get_snapshot( string $timeframe = '1D', int $event_time = 0, bool $use_cache = true ): array {
        $timeframe = strtoupper( $timeframe );

        if ( $use_cache ) {
            // Redis cache — nhanh hơn query DB context table
            $redis_key = 'market_snapshot:' . $timeframe . ':' . (int) $event_time;
            $redis_hit = LCNI_RedisCache::get( $redis_key, LCNI_RedisCache::GRP_MARKET_STATS );
            if ( ! empty( $redis_hit ) ) {
                return $redis_hit;
            }

            $cached = $this->read_context_from_db( $timeframe, $event_time );
            if ( ! empty( $cached ) ) {
                LCNI_RedisCache::set( $redis_key, $cached, LCNI_RedisCache::GRP_MARKET_STATS );
                return $cached;
            }
        }

        $et = $event_time > 0 ? $event_time : $this->get_latest_event_time( $timeframe );
        if ( $et <= 0 ) {
            return [];
        }

        // Suppress wpdb errors for entire snapshot computation to prevent
        // unexpected output during plugin activation or when tables are empty
        $prev = $this->wpdb->suppress_errors( true );
        $snapshot = $this->build_snapshot( $timeframe, $et );
        $this->save_context_to_db( $snapshot );
        $this->wpdb->suppress_errors( $prev );

        // Ghi vào Redis sau khi build xong
        $redis_key = 'market_snapshot:' . $timeframe . ':' . $et;
        LCNI_RedisCache::set( $redis_key, $snapshot, LCNI_RedisCache::GRP_MARKET_STATS );

        return $snapshot;
    }

    /**
     * Lấy danh sách event_time có dữ liệu (cho dropdown lịch sử).
     */
    public function get_available_event_times( string $timeframe = '1D', int $limit = 60 ): array {
        return $this->wpdb->get_col(
            $this->wpdb->prepare(
                "SELECT DISTINCT event_time FROM {$this->tbl_thong_ke_tt}
                 WHERE timeframe = %s ORDER BY event_time DESC LIMIT %d",
                $timeframe, max( 1, min( 200, $limit ) )
            )
        ) ?: [];
    }

    // =========================================================================
    // COLUMN DETECTION — phòng thủ trước cột chưa migrate
    // =========================================================================

    /**
     * Kiểm tra cột có tồn tại trong bảng không.
     * Kết quả được cache trong request để tránh gọi SHOW COLUMNS nhiều lần.
     */
    private function has_col( string $table, string $col ): bool {
        $key = $table . '.' . $col;
        if ( ! isset( $this->col_cache[ $key ] ) ) {
            // Suppress wpdb errors to prevent unexpected output during plugin activation
            $prev = $this->wpdb->suppress_errors( true );
            $result = $this->wpdb->get_var(
                $this->wpdb->prepare( "SHOW COLUMNS FROM `{$table}` LIKE %s", $col )
            );
            $this->wpdb->suppress_errors( $prev );
            $this->col_cache[ $key ] = (bool) $result;
        }
        return $this->col_cache[ $key ];
    }

    // =========================================================================

    private function build_snapshot( string $timeframe, int $et ): array {
        $breadth   = $this->get_market_breadth( $timeframe, $et );
        $sentiment = $this->get_market_sentiment( $timeframe, $et );
        $flow      = $this->get_market_flow( $timeframe, $et );
        $rotation  = $this->get_sector_rotation( $timeframe, $et );
        $trend     = $this->compute_trend_summary( $breadth, $sentiment, $flow, $rotation );

        return [
            // ── Meta ─────────────────────────────────────────────────────────
            'event_time'      => $et,
            'timeframe'       => $timeframe,
            'computed_at'     => current_time( 'timestamp' ),

            // ── 4 nhóm tín hiệu ──────────────────────────────────────────────
            'breadth'         => $breadth,
            'sentiment'       => $sentiment,
            'flow'            => $flow,
            'rotation'        => $rotation,

            // ── Nhận định tổng hợp (dùng cho Rule) ───────────────────────────
            'market_trend'    => $trend,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // NHÓM 1: Market Breadth (từ thong_ke_thi_truong)
    // ─────────────────────────────────────────────────────────────────────────

    private function get_market_breadth( string $tf, int $et ): array {
        // Tổng hợp toàn thị trường (HOSE + HNX + UPCOM gộp)
        // pct_so_ma_tren_* được lưu dạng 0–1 (ratio) từ v2.3.0a trở đi
        // Dùng SUM(tang) / SUM(total) thay vì AVG() để weighted average đúng
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT
                    SUM(so_ma_tang_gia) AS tang,
                    SUM(so_ma_giam_gia) AS giam,
                    SUM(so_ma_tang_gia) + SUM(so_ma_giam_gia) AS total_ma,
                    SUM(pct_so_ma_tren_ma20  * (so_ma_tang_gia + so_ma_giam_gia))
                        / NULLIF(SUM(so_ma_tang_gia + so_ma_giam_gia), 0) AS pct_ma20,
                    SUM(pct_so_ma_tren_ma50  * (so_ma_tang_gia + so_ma_giam_gia))
                        / NULLIF(SUM(so_ma_tang_gia + so_ma_giam_gia), 0) AS pct_ma50,
                    SUM(pct_so_ma_tren_ma100 * (so_ma_tang_gia + so_ma_giam_gia))
                        / NULLIF(SUM(so_ma_tang_gia + so_ma_giam_gia), 0) AS pct_ma100,
                    SUM(so_pha_nen)          AS pha_nen,
                    SUM(so_tang_gia_kem_vol) AS tang_vol,
                    SUM(tong_value_traded)   AS tong_value
                 FROM {$this->tbl_thong_ke_tt}
                 WHERE timeframe = %s AND event_time = %d",
                $tf, $et
            ), ARRAY_A
        ) ?: [];

        $tang  = (int) ( $row['tang'] ?? 0 );
        $giam  = (int) ( $row['giam'] ?? 0 );
        $total = $tang + $giam;

        $ad_ratio = $total > 0 ? round( $tang / $total * 100, 1 ) : null;

        // pct_ma* là 0–1 ratio từ DB, nhân 100 để ra %
        $pct_ma20  = round( (float) ( $row['pct_ma20']  ?? 0 ) * 100, 1 );
        $pct_ma50  = round( (float) ( $row['pct_ma50']  ?? 0 ) * 100, 1 );
        $pct_ma100 = round( (float) ( $row['pct_ma100'] ?? 0 ) * 100, 1 );

        $ma_score = ( $pct_ma20 > 50 ? 1 : 0 )
                  + ( $pct_ma50 > 50 ? 1 : 0 )
                  + ( $pct_ma100 > 50 ? 1 : 0 );

        $breadth_label = $this->classify_breadth( $ad_ratio, $pct_ma50 );

        return [
            'ad_ratio'          => $ad_ratio,
            'advance_count'     => $tang,
            'decline_count'     => $giam,
            'pct_above_ma20'    => $pct_ma20,
            'pct_above_ma50'    => $pct_ma50,
            'pct_above_ma100'   => $pct_ma100,
            'ma_trend_score'    => $ma_score,
            'breakout_count'    => (int) ( $row['pha_nen']   ?? 0 ),
            'advance_vol_count' => (int) ( $row['tang_vol']  ?? 0 ),
            'total_value'       => (float) ( $row['tong_value'] ?? 0 ),
            'label'             => $breadth_label,
        ];
    }

    private function classify_breadth( ?float $ad_ratio, float $pct_ma50 ): string {
        if ( $ad_ratio === null ) return 'Không có dữ liệu';
        if ( $ad_ratio >= 65 && $pct_ma50 >= 60 ) return 'Rất mạnh';
        if ( $ad_ratio >= 55 && $pct_ma50 >= 50 ) return 'Mạnh';
        if ( $ad_ratio >= 45 ) return 'Trung tính';
        if ( $ad_ratio >= 35 ) return 'Yếu';
        return 'Rất yếu';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // NHÓM 2: Tâm lý thị trường (RSI status + Smart Money)
    // ─────────────────────────────────────────────────────────────────────────

    private function get_market_sentiment( string $tf, int $et ): array {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT
                    SUM(so_rsi_tham_lam)  AS tham_lam,
                    SUM(so_rsi_so_hai)    AS so_hai,
                    SUM(so_rsi_qua_mua)   AS qua_mua,
                    SUM(so_rsi_qua_ban)   AS qua_ban,
                    SUM(so_smart_money)   AS smart_money,
                    SUM(so_ma_tang_gia) + SUM(so_ma_giam_gia) AS tong_ma
                 FROM {$this->tbl_thong_ke_tt}
                 WHERE timeframe = %s AND event_time = %d",
                $tf, $et
            ), ARRAY_A
        ) ?: [];

        $tong    = max( 1, (int) ( $row['tong_ma'] ?? 1 ) );
        $tl      = (int) ( $row['tham_lam']   ?? 0 );
        $sh      = (int) ( $row['so_hai']     ?? 0 );
        $qm      = (int) ( $row['qua_mua']    ?? 0 );
        $qb      = (int) ( $row['qua_ban']    ?? 0 );
        $sm      = (int) ( $row['smart_money'] ?? 0 );

        $pct_tl  = round( $tl / $tong * 100, 1 );
        $pct_sh  = round( $sh / $tong * 100, 1 );
        $pct_qm  = round( $qm / $tong * 100, 1 );
        $pct_qb  = round( $qb / $tong * 100, 1 );
        $pct_sm  = round( $sm / $tong * 100, 1 );

        // Fear & Greed Index (0-100): tổng hợp RSI + Smart Money
        // 100 = cực kỳ tham lam, 0 = cực kỳ sợ hãi
        $fg_index = $this->compute_fear_greed( $pct_tl, $pct_sh, $pct_qm, $pct_qb, $pct_sm );
        $fg_label = $this->classify_fear_greed( $fg_index );

        return [
            'fear_greed_index'   => $fg_index,      // 0-100 — dùng trong Rule
            'fear_greed_label'   => $fg_label,
            'pct_rsi_tham_lam'   => $pct_tl,        // % mã RSI≥80
            'pct_rsi_so_hai'     => $pct_sh,        // % mã RSI≤20
            'pct_rsi_qua_mua'    => $pct_qm,        // % mã RSI≥70
            'pct_rsi_qua_ban'    => $pct_qb,        // % mã RSI≤30
            'pct_smart_money'    => $pct_sm,         // % mã có Smart Money signal
            'raw_smart_money'    => $sm,
            'raw_tham_lam'       => $tl,
            'raw_so_hai'         => $sh,
        ];
    }

    private function compute_fear_greed( float $pct_tl, float $pct_sh, float $pct_qm, float $pct_qb, float $pct_sm ): float {
        // Greed components (đẩy lên): tham lam, quá mua, smart money cao
        // Fear components (kéo xuống): sợ hãi, quá bán
        $greed = min( 100, $pct_tl * 1.5 + $pct_qm * 0.5 + $pct_sm * 0.8 );
        $fear  = min( 100, $pct_sh * 1.5 + $pct_qb * 0.5 );

        $raw = 50 + ( $greed - $fear ) * 0.5;
        return round( max( 0, min( 100, $raw ) ), 1 );
    }

    private function classify_fear_greed( float $idx ): string {
        if ( $idx >= 80 ) return 'Cực kỳ tham lam';
        if ( $idx >= 65 ) return 'Tham lam';
        if ( $idx >= 55 ) return 'Trung tính tích cực';
        if ( $idx >= 45 ) return 'Trung tính';
        if ( $idx >= 35 ) return 'Trung tính tiêu cực';
        if ( $idx >= 20 ) return 'Sợ hãi';
        return 'Cực kỳ sợ hãi';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // NHÓM 3: Dòng tiền (từ industry_return + industry_metrics)
    // ─────────────────────────────────────────────────────────────────────────

    private function get_market_flow( string $tf, int $et ): array {
        // Tổng thanh khoản thị trường
        $total_value = (float) ( $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT SUM(industry_value) FROM {$this->tbl_industry_return}
                 WHERE timeframe = %s AND event_time = %d",
                $tf, $et
            )
        ) ?: 0 );

        // Phát hiện cột optional (chỉ thêm sau khi chạy upgrade batch)
        $has_phase = $this->has_col( $this->tbl_industry_metrics, 'industry_phase' );
        $has_ts    = $this->has_col( $this->tbl_industry_metrics, 'trend_state_vi' );
        $has_mfr   = $this->has_col( $this->tbl_industry_return,  'money_flow_ratio' );

        $phase_sel = $has_phase ? 'm.industry_phase'  : "NULL AS industry_phase";
        $ts_sel    = $has_ts    ? 'm.trend_state_vi'  : "'' AS trend_state_vi";
        $mfr_sel   = $has_mfr  ? 'r.money_flow_ratio' : "NULL AS money_flow_ratio";

        // Top 5 ngành hút tiền nhất
        $top_sectors = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT i.name_icb2, m.money_flow_share, m.industry_score_raw,
                        r.industry_value, {$phase_sel}, {$ts_sel}, {$mfr_sel}
                 FROM {$this->tbl_industry_metrics} m
                 LEFT JOIN {$this->tbl_icb2} i ON i.id_icb2 = m.id_icb2
                 LEFT JOIN {$this->tbl_industry_return} r
                        ON r.id_icb2 = m.id_icb2
                       AND r.event_time = m.event_time
                       AND r.timeframe = m.timeframe
                 WHERE m.timeframe = %s AND m.event_time = %d
                 ORDER BY m.money_flow_share DESC
                 LIMIT 5",
                $tf, $et
            ), ARRAY_A
        ) ?: [];

        // So sánh vs phiên trước để thấy trend thanh khoản
        $prev_et    = $this->get_prev_event_time( $tf, $et );
        $prev_value = $prev_et > 0 ? (float) ( $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT SUM(industry_value) FROM {$this->tbl_industry_return}
                 WHERE timeframe = %s AND event_time = %d",
                $tf, $prev_et
            )
        ) ?: 0 ) : 0;

        $vol_change_pct = ( $prev_value > 0 )
            ? round( ( $total_value - $prev_value ) / $prev_value * 100, 1 )
            : null;

        // Tỷ lệ ngành có dòng tiền > MA20
        if ( $has_mfr ) {
            $above_ma20_count = (int) ( $this->wpdb->get_var(
                $this->wpdb->prepare(
                    "SELECT COUNT(*) FROM {$this->tbl_industry_return}
                     WHERE timeframe = %s AND event_time = %d
                       AND COALESCE(money_flow_ratio, 0) > 1",
                    $tf, $et
                )
            ) ?: 0 );
        } else {
            // Fallback khi chưa có money_flow_ratio: đếm ngành có money_flow_share > avg
            $avg_share = (float) ( $this->wpdb->get_var(
                $this->wpdb->prepare(
                    "SELECT AVG(money_flow_share) FROM {$this->tbl_industry_metrics}
                     WHERE timeframe = %s AND event_time = %d",
                    $tf, $et
                )
            ) ?: 0 );
            $above_ma20_count = (int) ( $this->wpdb->get_var(
                $this->wpdb->prepare(
                    "SELECT COUNT(*) FROM {$this->tbl_industry_metrics}
                     WHERE timeframe = %s AND event_time = %d AND money_flow_share > %f",
                    $tf, $et, $avg_share
                )
            ) ?: 0 );
        }

        $total_sectors = (int) ( $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->tbl_industry_return}
                 WHERE timeframe = %s AND event_time = %d",
                $tf, $et
            )
        ) ?: 1 );

        $flow_breadth = round( $above_ma20_count / max( 1, $total_sectors ) * 100, 1 );

        return [
            'total_value_bn'     => round( $total_value / 1e9, 1 ),
            'value_change_pct'   => $vol_change_pct,
            'flow_breadth_pct'   => $flow_breadth,
            'top_sectors'        => array_values( array_map( function ( $s ) {
                return [
                    'name'           => $s['name_icb2'] ?? '—',
                    'flow_share_pct' => round( (float) ( $s['money_flow_share'] ?? 0 ) * 100, 1 ),
                    'phase'          => $s['industry_phase'] ?? '',
                    'trend_state'    => $s['trend_state_vi'] ?? '',
                    'flow_ratio'     => $s['money_flow_ratio'] !== null
                                        ? round( (float) $s['money_flow_ratio'], 2 )
                                        : null,
                ];
            }, $top_sectors ) ),
            'flow_breadth_score' => $flow_breadth,
            'total_market_value' => $total_value,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // NHÓM 4: Sector Rotation (từ industry_metrics + industry_index)
    // ─────────────────────────────────────────────────────────────────────────

    private function get_sector_rotation( string $tf, int $et ): array {
        $has_ts    = $this->has_col( $this->tbl_industry_metrics, 'trend_state_vi' );
        $has_rank  = $this->has_col( $this->tbl_industry_metrics, 'industry_rank' );
        $has_phase = $this->has_col( $this->tbl_industry_metrics, 'industry_phase' );
        $has_ix    = $this->has_col( $this->tbl_industry_index,   'industry_trend' );

        // ── Phân phối 4 trạng thái ───────────────────────────────────────────
        $state_map     = [];
        $leader_count  = 0;
        $improve_count = 0;
        $weak_count    = 0;
        $lag_count     = 0;

        if ( $has_ts ) {
            $state_dist = $this->wpdb->get_results(
                $this->wpdb->prepare(
                    "SELECT trend_state_vi AS state, COUNT(*) AS cnt
                     FROM {$this->tbl_industry_metrics}
                     WHERE timeframe = %s AND event_time = %d
                     GROUP BY trend_state_vi",
                    $tf, $et
                ), ARRAY_A
            ) ?: [];

            foreach ( $state_dist as $row ) {
                $state_map[ (string) $row['state'] ] = (int) $row['cnt'];
            }
            $leader_count  = $state_map['Ngành dẫn dắt']       ?? 0;
            $improve_count = $state_map['Ngành đang cải thiện'] ?? 0;
            $weak_count    = $state_map['Ngành suy yếu']        ?? 0;
            $lag_count     = $state_map['Ngành tụt hậu']        ?? 0;
        } else {
            // Fallback: phân loại tại runtime từ momentum + relative_strength
            $metrics = $this->wpdb->get_results(
                $this->wpdb->prepare(
                    "SELECT momentum, relative_strength
                     FROM {$this->tbl_industry_metrics}
                     WHERE timeframe = %s AND event_time = %d",
                    $tf, $et
                ), ARRAY_A
            ) ?: [];

            foreach ( $metrics as $m ) {
                $mo = (float) ( $m['momentum'] ?? 0 );
                $rs = (float) ( $m['relative_strength'] ?? 0 );
                if ( $mo > 0 && $rs > 0 )       { $leader_count++;  }
                elseif ( $mo > 0 && $rs <= 0 )  { $improve_count++; }
                elseif ( $mo <= 0 && $rs > 0 )  { $weak_count++;    }
                else                             { $lag_count++;     }
            }
            $state_map = [
                'Ngành dẫn dắt'        => $leader_count,
                'Ngành đang cải thiện' => $improve_count,
                'Ngành suy yếu'        => $weak_count,
                'Ngành tụt hậu'        => $lag_count,
            ];
        }

        $total_sec      = max( 1, $leader_count + $improve_count + $weak_count + $lag_count );
        $rotation_score = round( ( $leader_count * 1.0 + $improve_count * 0.5 ) / $total_sec * 100, 1 );

        // ── Phase distribution ───────────────────────────────────────────────
        $phase_map = [];
        if ( $has_phase ) {
            $phase_dist = $this->wpdb->get_results(
                $this->wpdb->prepare(
                    "SELECT industry_phase AS phase, COUNT(*) AS cnt
                     FROM {$this->tbl_industry_metrics}
                     WHERE timeframe = %s AND event_time = %d
                       AND industry_phase IS NOT NULL
                     GROUP BY industry_phase ORDER BY cnt DESC",
                    $tf, $et
                ), ARRAY_A
            ) ?: [];

            foreach ( $phase_dist as $row ) {
                $phase_map[ (string) $row['phase'] ] = (int) $row['cnt'];
            }
        }

        // ── Top leaders — SELECT động theo cột có sẵn ────────────────────────
        $rank_sel  = $has_rank  ? 'm.industry_rank'  : '0 AS industry_rank';
        $ts_sel    = $has_ts    ? 'm.trend_state_vi' : "'' AS trend_state_vi";
        $phase_sel = $has_phase ? 'm.industry_phase' : 'NULL AS industry_phase';
        $ix_sel    = $has_ix    ? 'ix.industry_trend': 'NULL AS industry_trend';

        $ix_join = $has_ix
            ? "LEFT JOIN {$this->tbl_industry_index} ix
                      ON ix.id_icb2 = m.id_icb2
                     AND ix.event_time = m.event_time
                     AND ix.timeframe = m.timeframe"
            : '';

        $leader_where = $has_ts
            ? $this->wpdb->prepare( 'AND m.trend_state_vi = %s', 'Ngành dẫn dắt' )
            : 'AND m.momentum > 0 AND m.relative_strength > 0';

        $leaders = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT i.name_icb2, m.industry_score_raw, m.momentum,
                        m.relative_strength, m.industry_rating_vi,
                        m.return_5d, m.return_10d, m.return_20d,
                        {$rank_sel}, {$ts_sel}, {$phase_sel}, {$ix_sel}
                 FROM {$this->tbl_industry_metrics} m
                 LEFT JOIN {$this->tbl_icb2} i ON i.id_icb2 = m.id_icb2
                 {$ix_join}
                 WHERE m.timeframe = %s AND m.event_time = %d {$leader_where}
                 ORDER BY m.industry_score_raw DESC
                 LIMIT 5",
                $tf, $et
            ), ARRAY_A
        ) ?: [];

        // ── % ngành xu hướng tăng ────────────────────────────────────────────
        if ( $has_ix ) {
            $trend_up = (int) ( $this->wpdb->get_var(
                $this->wpdb->prepare(
                    "SELECT COUNT(*) FROM {$this->tbl_industry_index}
                     WHERE timeframe = %s AND event_time = %d AND industry_trend = 'Xu hướng tăng'",
                    $tf, $et
                )
            ) ?: 0 );
            $total_idx = max( 1, (int) ( $this->wpdb->get_var(
                $this->wpdb->prepare(
                    "SELECT COUNT(*) FROM {$this->tbl_industry_index}
                     WHERE timeframe = %s AND event_time = %d",
                    $tf, $et
                )
            ) ?: 1 ) );
            $pct_uptrend = round( $trend_up / $total_idx * 100, 1 );
        } else {
            // Fallback: dùng momentum > 0
            $up_count  = (int) ( $this->wpdb->get_var(
                $this->wpdb->prepare(
                    "SELECT COUNT(*) FROM {$this->tbl_industry_metrics}
                     WHERE timeframe = %s AND event_time = %d AND momentum > 0",
                    $tf, $et
                )
            ) ?: 0 );
            $all_count = max( 1, (int) ( $this->wpdb->get_var(
                $this->wpdb->prepare(
                    "SELECT COUNT(*) FROM {$this->tbl_industry_metrics}
                     WHERE timeframe = %s AND event_time = %d",
                    $tf, $et
                )
            ) ?: 1 ) );
            $pct_uptrend = round( $up_count / $all_count * 100, 1 );
        }

        return [
            'rotation_score'     => $rotation_score,
            'pct_sector_uptrend' => $pct_uptrend,
            'leader_count'       => $leader_count,
            'improving_count'    => $improve_count,
            'weak_count'         => $weak_count,
            'lagging_count'      => $lag_count,
            'total_sectors'      => $total_sec,
            'state_distribution' => $state_map,
            'phase_distribution' => $phase_map,
            'top_leaders'        => array_values( array_map( function ( $s ) {
                return [
                    'name'        => $s['name_icb2']          ?? '—',
                    'rank'        => (int) ( $s['industry_rank']    ?? 0 ),
                    'score'       => round( (float) ( $s['industry_score_raw'] ?? 0 ), 2 ),
                    'phase'       => $s['industry_phase']     ?? '',
                    'trend'       => $s['industry_trend']     ?? '',
                    'trend_state' => $s['trend_state_vi']     ?? '',
                    'rating'      => $s['industry_rating_vi'] ?? '',
                    'return_5d'   => round( (float) ( $s['return_5d']  ?? 0 ) * 100, 2 ),
                    'return_10d'  => round( (float) ( $s['return_10d'] ?? 0 ) * 100, 2 ),
                    'return_20d'  => round( (float) ( $s['return_20d'] ?? 0 ) * 100, 2 ),
                    'momentum'    => round( (float) ( $s['momentum']   ?? 0 ), 4 ),
                    'rs'          => round( (float) ( $s['relative_strength'] ?? 0 ), 4 ),
                ];
            }, $leaders ) ),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TỔNG HỢP: market_trend (dùng cho Rule engine)
    // ─────────────────────────────────────────────────────────────────────────

    private function compute_trend_summary( array $b, array $s, array $f, array $r ): array {
        $ad    = $b['ad_ratio']          ?? 50;
        $ma50  = $b['pct_above_ma50']    ?? 50;
        $fg    = $s['fear_greed_index']  ?? 50;
        $rs    = $r['rotation_score']    ?? 50;
        $fb    = $f['flow_breadth_pct']  ?? 50;

        // Composite score 0-100
        $composite = round(
            $ad  * 0.25 +
            $ma50 * 0.20 +
            $fg   * 0.20 +
            $rs   * 0.20 +
            $fb   * 0.15,
            1
        );

        $phase = $this->classify_market_phase( $composite, $ad, $fg, $rs );
        $bias  = $this->classify_market_bias( $composite );

        return [
            // Dùng trong Rule engine (field: lcni_market_context.market_composite_score)
            'composite_score'   => $composite,
            'market_phase'      => $phase,
            'market_bias'       => $bias,       // 'Tích cực' | 'Trung tính' | 'Tiêu cực'
            // Component scores
            'breadth_score'     => $ad,
            'sentiment_score'   => $fg,
            'rotation_score'    => $rs,
            'flow_score'        => $fb,
            'ma_score'          => $ma50,
        ];
    }

    private function classify_market_phase( float $score, float $ad, float $fg, float $rs ): string {
        if ( $score >= 72 && $ad >= 65 && $rs >= 60 )   return 'Xu hướng tăng mạnh';
        if ( $score >= 60 )                              return 'Tích lũy - xu hướng tăng';
        if ( $score >= 50 )                              return 'Trung tính';
        if ( $score >= 38 )                              return 'Tích lũy - xu hướng giảm';
        return 'Xu hướng giảm';
    }

    private function classify_market_bias( float $score ): string {
        if ( $score >= 60 ) return 'Tích cực';
        if ( $score >= 42 ) return 'Trung tính';
        return 'Tiêu cực';
    }

    // =========================================================================
    // DB CONTEXT TABLE: save/read snapshot
    // =========================================================================

    /**
     * Ghi snapshot vào 2 bảng:
     *   1. lcni_market_context        — lịch sử đầy đủ (n rows, UNIQUE event_time+timeframe)
     *   2. lcni_market_context_latest — mới nhất (1 row per timeframe, cho Rule engine)
     */
    public function save_context_to_db( array $snapshot ): bool {
        if ( empty( $snapshot['event_time'] ) ) {
            return false;
        }

        $mt = $snapshot['market_trend'] ?? [];
        $br = $snapshot['breadth']      ?? [];
        $se = $snapshot['sentiment']    ?? [];
        $fl = $snapshot['flow']         ?? [];
        $ro = $snapshot['rotation']     ?? [];

        // Dùng 0 thay NULL để tương thích wpdb insert/update/replace hoàn toàn
        $flow_change = isset( $fl['value_change_pct'] ) ? (float) $fl['value_change_pct'] : 0.0;

        $data = [
            'event_time'                => (int)    $snapshot['event_time'],
            'timeframe'                 => (string)  $snapshot['timeframe'],
            'market_composite_score'    => (float) ( $mt['composite_score']    ?? 0 ),
            'market_phase'              => (string) ( $mt['market_phase']      ?? '' ),
            'market_bias'               => (string) ( $mt['market_bias']       ?? '' ),
            'breadth_ad_ratio'          => (float) ( $br['ad_ratio']           ?? 0 ),
            'breadth_pct_above_ma50'    => (float) ( $br['pct_above_ma50']     ?? 0 ),
            'breadth_ma_trend_score'    => (int)   ( $br['ma_trend_score']     ?? 0 ),
            'breadth_label'             => (string) ( $br['label']             ?? '' ),
            'sentiment_fear_greed'      => (float) ( $se['fear_greed_index']   ?? 0 ),
            'sentiment_label'           => (string) ( $se['fear_greed_label']  ?? '' ),
            'sentiment_pct_smart_money' => (float) ( $se['pct_smart_money']    ?? 0 ),
            'flow_breadth_score'        => (float) ( $fl['flow_breadth_score'] ?? 0 ),
            'flow_value_bn'             => (float) ( $fl['total_value_bn']     ?? 0 ),
            'flow_value_change_pct'     => $flow_change,
            'rotation_score'            => (float) ( $ro['rotation_score']     ?? 0 ),
            'rotation_pct_uptrend'      => (float) ( $ro['pct_sector_uptrend'] ?? 0 ),
            'rotation_leader_count'     => (int)   ( $ro['leader_count']       ?? 0 ),
            'snapshot_json'             => wp_json_encode( $snapshot, JSON_UNESCAPED_UNICODE ),
            'computed_at'               => current_time( 'mysql' ),
        ];

        $fmt = [ '%d','%s','%f','%s','%s','%f','%f','%d','%s','%f','%s','%f','%f','%f','%f','%f','%f','%d','%s','%s' ];

        // ── Bảng history: INSERT nếu chưa có, UPDATE nếu đã có ───────────────
        $exists = (int) $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->tbl_context} WHERE timeframe = %s AND event_time = %d",
                $data['timeframe'], $data['event_time']
            )
        );

        if ( $exists === 0 ) {
            $result = $this->wpdb->insert( $this->tbl_context, $data, $fmt );
        } else {
            $update = $data;
            unset( $update['event_time'], $update['timeframe'] );
            $update_fmt = array_slice( $fmt, 2 ); // bỏ 2 format đầu (%d %s)
            $result = $this->wpdb->update(
                $this->tbl_context, $update,
                [ 'timeframe' => $data['timeframe'], 'event_time' => $data['event_time'] ],
                $update_fmt, [ '%s', '%d' ]
            );
        }

        // ── Bảng latest: ghi nếu bảng tồn tại và event_time >= row hiện tại ──
        $prev_se = $this->wpdb->suppress_errors( true );
        $latest_exists = $this->wpdb->get_var(
            $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $this->tbl_context_latest )
        ) === $this->tbl_context_latest;
        $this->wpdb->suppress_errors( $prev_se );

        if ( $latest_exists ) {
            $cur_et = (int) ( $this->wpdb->get_var(
                $this->wpdb->prepare(
                    "SELECT event_time FROM {$this->tbl_context_latest} WHERE timeframe = %s",
                    $data['timeframe']
                )
            ) ?: 0 );

            if ( $data['event_time'] >= $cur_et ) {
                $this->wpdb->replace( $this->tbl_context_latest, $data, $fmt );
            }
        }

        return $result !== false;
    }

    /**
     * Backfill snapshot cho tất cả event_time còn thiếu trong bảng history.
     */
    public function backfill_history( string $timeframe = '1D', int $limit = 200 ): int {
        $timeframe   = strtoupper( $timeframe );
        $event_times = $this->get_available_event_times( $timeframe, $limit );
        $saved       = 0;

        // Suppress wpdb errors for entire backfill to prevent unexpected output
        $prev = $this->wpdb->suppress_errors( true );

        foreach ( $event_times as $et ) {
            $et = (int) $et;
            if ( $et <= 0 ) {
                continue;
            }
            $exists = (int) $this->wpdb->get_var(
                $this->wpdb->prepare(
                    "SELECT COUNT(*) FROM {$this->tbl_context} WHERE timeframe = %s AND event_time = %d",
                    $timeframe, $et
                )
            );
            if ( $exists > 0 ) {
                continue;
            }
            $snapshot = $this->build_snapshot( $timeframe, $et );
            if ( ! empty( $snapshot ) && $this->save_context_to_db( $snapshot ) ) {
                $saved++;
            }
        }

        $this->wpdb->suppress_errors( $prev );
        return $saved;
    }

    private function read_context_from_db( string $tf, int $et ): array {
        $resolved_et = $et > 0 ? $et : $this->get_latest_event_time( $tf );
        if ( $resolved_et <= 0 ) return [];

        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT snapshot_json, computed_at FROM {$this->tbl_context}
                 WHERE timeframe = %s AND event_time = %d",
                $tf, $resolved_et
            ), ARRAY_A
        );

        if ( empty( $row['snapshot_json'] ) ) return [];

        // Cache hợp lệ 5 phút với phiên gần nhất, 1 giờ với phiên cũ
        $is_latest   = ( $et === 0 || $et === $resolved_et );
        $max_age_sec = $is_latest ? 300 : 3600;
        $computed_ts = strtotime( $row['computed_at'] );
        if ( ( time() - $computed_ts ) > $max_age_sec ) return [];

        $decoded = json_decode( $row['snapshot_json'], true );
        return is_array( $decoded ) ? $decoded : [];
    }

    // =========================================================================
    // SCHEMA: ensure bảng lcni_market_context (history) + lcni_market_context_latest
    // =========================================================================

    public static function ensure_context_table(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $prev_suppress = $wpdb->suppress_errors( true );

        // Định nghĩa cột — dùng cho cả 2 bảng
        $col_defs = "id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_time BIGINT UNSIGNED NOT NULL,
            timeframe VARCHAR(10) NOT NULL,
            market_composite_score DECIMAL(8,2) NOT NULL DEFAULT 0,
            market_phase VARCHAR(60) NOT NULL DEFAULT '',
            market_bias VARCHAR(30) NOT NULL DEFAULT '',
            breadth_ad_ratio DECIMAL(8,2) NOT NULL DEFAULT 0,
            breadth_pct_above_ma50 DECIMAL(8,2) NOT NULL DEFAULT 0,
            breadth_ma_trend_score TINYINT NOT NULL DEFAULT 0,
            breadth_label VARCHAR(30) NOT NULL DEFAULT '',
            sentiment_fear_greed DECIMAL(8,2) NOT NULL DEFAULT 0,
            sentiment_label VARCHAR(30) NOT NULL DEFAULT '',
            sentiment_pct_smart_money DECIMAL(8,2) NOT NULL DEFAULT 0,
            flow_breadth_score DECIMAL(8,2) NOT NULL DEFAULT 0,
            flow_value_bn DECIMAL(16,2) NOT NULL DEFAULT 0,
            flow_value_change_pct DECIMAL(8,2) NOT NULL DEFAULT 0,
            rotation_score DECIMAL(8,2) NOT NULL DEFAULT 0,
            rotation_pct_uptrend DECIMAL(8,2) NOT NULL DEFAULT 0,
            rotation_leader_count SMALLINT NOT NULL DEFAULT 0,
            snapshot_json LONGTEXT,
            computed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP";

        // Bảng history: n rows per timeframe
        $history = $wpdb->prefix . 'lcni_market_context';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $history ) ) !== $history ) {
            dbDelta( "CREATE TABLE {$history} (
                {$col_defs},
                PRIMARY KEY (id),
                UNIQUE KEY uniq_event_timeframe (event_time, timeframe),
                KEY idx_timeframe_event (timeframe, event_time),
                KEY idx_market_bias (market_bias)
            ) {$charset};" );
        } else {
            self::ensure_context_columns( $wpdb, $history );
        }

        // Bảng latest: 1 row per timeframe — cho Rule engine JOIN
        $latest = $wpdb->prefix . 'lcni_market_context_latest';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $latest ) ) !== $latest ) {
            dbDelta( "CREATE TABLE {$latest} (
                {$col_defs},
                PRIMARY KEY (id),
                UNIQUE KEY uniq_timeframe (timeframe),
                KEY idx_market_bias (market_bias)
            ) {$charset};" );
        }

        $wpdb->suppress_errors( $prev_suppress );
    }

    private static function ensure_context_columns( wpdb $wpdb, string $table ): void {
        $cols = [
            'flow_value_change_pct'     => 'DECIMAL(8,2) NOT NULL DEFAULT 0',
            'rotation_pct_uptrend'      => 'DECIMAL(8,2) NOT NULL DEFAULT 0',
            'rotation_leader_count'     => 'SMALLINT NOT NULL DEFAULT 0',
            'sentiment_pct_smart_money' => 'DECIMAL(8,2) NOT NULL DEFAULT 0',
            'breadth_ma_trend_score'    => 'TINYINT NOT NULL DEFAULT 0',
        ];
        $prev = $wpdb->suppress_errors( true );
        foreach ( $cols as $col => $def ) {
            if ( ! $wpdb->get_var( "SHOW COLUMNS FROM {$table} LIKE '{$col}'" ) ) {
                $wpdb->query( "ALTER TABLE {$table} ADD COLUMN {$col} {$def}" );
            }
        }
        $wpdb->suppress_errors( $prev );
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    private function get_latest_event_time( string $tf ): int {
        return (int) ( $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT MAX(event_time) FROM {$this->tbl_thong_ke_tt} WHERE timeframe = %s",
                $tf
            )
        ) ?: 0 );
    }

    private function get_prev_event_time( string $tf, int $et ): int {
        return (int) ( $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT MAX(event_time) FROM {$this->tbl_thong_ke_tt}
                 WHERE timeframe = %s AND event_time < %d",
                $tf, $et
            )
        ) ?: 0 );
    }
}
