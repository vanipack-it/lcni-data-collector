<?php

if (!defined('ABSPATH')) {
    exit;
}

class ExitEngine {

    // Các lý do thoát lệnh — dùng làm hằng số
    const REASON_STOP_LOSS   = 'stop_loss';   // giá chạm initial_sl
    const REASON_MAX_LOSS    = 'max_loss';    // giá chạm max_loss_cut
    const REASON_TAKE_PROFIT = 'take_profit'; // r_multiple >= exit_at_r
    const REASON_MAX_HOLD    = 'max_hold';    // giữ quá max_hold_days
    const REASON_NONE        = '';            // chưa cần thoát

    /**
     * Trả về lý do thoát lệnh (string) hoặc '' nếu chưa cần thoát.
     * Thứ tự ưu tiên: stop_loss > max_loss > take_profit > max_hold
     */
    public function get_exit_reason( $signal, $rule, $current_price, $r_multiple, $holding_days ): string {
        $entry_price        = (float) ( $signal['entry_price'] ?? 0 );
        $max_loss_pct       = abs( (float) ( $rule['max_loss_pct'] ?? ( $rule['initial_sl_pct'] ?? 8 ) ) );
        $max_loss_cut_price = $entry_price > 0 ? $entry_price * ( 1 - $max_loss_pct / 100 ) : 0;

        if ( (float) $current_price <= (float) $signal['initial_sl'] ) {
            return self::REASON_STOP_LOSS;
        }

        if ( $max_loss_cut_price > 0 && (float) $current_price <= $max_loss_cut_price ) {
            return self::REASON_MAX_LOSS;
        }

        if ( (float) $r_multiple >= (float) $rule['exit_at_r'] ) {
            return self::REASON_TAKE_PROFIT;
        }

        if ( (int) $holding_days >= (int) $rule['max_hold_days'] ) {
            return self::REASON_MAX_HOLD;
        }

        return self::REASON_NONE;
    }

    /**
     * Backward-compatible: trả về bool để không phá code cũ nếu có.
     */
    public function should_exit( $signal, $rule, $current_price, $r_multiple, $holding_days ): bool {
        return $this->get_exit_reason( $signal, $rule, $current_price, $r_multiple, $holding_days ) !== self::REASON_NONE;
    }

    /**
     * Tính final_r có cap theo exit_reason.
     * stop_loss / max_loss → cap tối đa -1.0R (đúng nguyên tắc R-multiple)
     * take_profit / max_hold → dùng r_multiple thực tế
     */
    public static function compute_final_r( float $r_multiple, string $exit_reason ): float {
        if ( $exit_reason === self::REASON_STOP_LOSS || $exit_reason === self::REASON_MAX_LOSS ) {
            return max( $r_multiple, -1.0 );
        }

        return $r_multiple;
    }

    /**
     * Nhãn tiếng Việt cho exit_reason.
     */
    public static function reason_label( string $reason ): string {
        $map = [
            self::REASON_STOP_LOSS   => 'Cắt lỗ (SL)',
            self::REASON_MAX_LOSS    => 'Cắt lỗ tối đa',
            self::REASON_TAKE_PROFIT => 'Chốt lời',
            self::REASON_MAX_HOLD    => 'Hết thời gian',
        ];

        return $map[ $reason ] ?? '—';
    }
}
