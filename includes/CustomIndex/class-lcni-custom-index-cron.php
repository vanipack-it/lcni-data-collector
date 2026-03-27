<?php
/**
 * Custom Index Cron Service
 * Chạy sau khi DailyCronService cập nhật ohlc mới nhất.
 * Hook vào action lcni_ohlc_updated (fire sau upsert_ohlc_rows)
 * hoặc chạy độc lập qua WP Cron.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class LCNI_Custom_Index_Cron {

    const CRON_HOOK = 'lcni_custom_index_daily_cron';

    private LCNI_Custom_Index_Repository  $repo;
    private LCNI_Custom_Index_Calculator  $calc;

    public function __construct(
        LCNI_Custom_Index_Repository $repo,
        LCNI_Custom_Index_Calculator $calc
    ) {
        $this->repo = $repo;
        $this->calc = $calc;
    }

    public function register_hooks(): void {
        add_action( self::CRON_HOOK, [ $this, 'run' ] );
    }

    public static function schedule(): void {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            // Chạy lúc 18:30 mỗi ngày (sau khi HOSE đóng cửa)
            $tz   = wp_timezone();
            $next = new DateTimeImmutable( 'today 18:30:00', $tz );
            if ( $next->getTimestamp() < time() ) {
                $next = $next->modify( '+1 day' );
            }
            wp_schedule_event( $next->getTimestamp(), 'daily', self::CRON_HOOK );
        }
    }

    public static function unschedule(): void {
        $ts = wp_next_scheduled( self::CRON_HOOK );
        if ( $ts ) wp_unschedule_event( $ts, self::CRON_HOOK );
    }

    /**
     * Cron chính: tính phiên hôm nay cho tất cả active indexes.
     */
    public function run(): void {
        $indexes   = $this->repo->get_active();
        $timeframes = [ '1D' ]; // mở rộng thêm 1W/1M nếu cần

        foreach ( $indexes as $index ) {
            foreach ( $timeframes as $tf ) {
                $latest_et = $this->get_latest_ohlc_event_time( $tf );
                if ( $latest_et <= 0 ) continue;

                $this->calc->compute_session( $index, $tf, $latest_et );

                error_log( sprintf(
                    '[LCNI CustomIndex] Updated index #%d "%s" tf=%s event_time=%d',
                    (int) $index['id'], $index['name'], $tf, $latest_et
                ) );
            }
        }
    }

    /**
     * Lấy event_time mới nhất trong lcni_ohlc cho timeframe.
     */
    private function get_latest_ohlc_event_time( string $timeframe ): int {
        global $wpdb;
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT MAX(event_time) FROM {$wpdb->prefix}lcni_ohlc
                 WHERE timeframe = %s AND symbol_type = 'STOCK' AND value_traded > 0",
                strtoupper( $timeframe )
            )
        );
    }
}
