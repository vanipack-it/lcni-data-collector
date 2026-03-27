<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * UserRuleNotifier
 *
 * Gửi email thông báo cho các sự kiện phát sinh trong quá trình
 * thực thi User Rule (tạo signal, đóng signal, lệnh DNSE, v.v.)
 *
 * Tất cả loại email có thể tùy chỉnh template qua:
 *   WP Admin → LCNi → Thông báo → tab "User Rule"
 *
 * Loại sự kiện:
 *   ur_signal_opened   — UserRule nhận được signal mới (paper hoặc real)
 *   ur_signal_closed   — Signal đóng (SL / TP / max_hold)
 *   ur_order_placed    — Đặt lệnh DNSE thành công
 *   ur_order_failed    — Đặt lệnh DNSE thất bại
 *   ur_dnse_token_expired — Token DNSE hết hạn, cần gia hạn
 *   ur_max_symbols     — Đạt giới hạn số mã, bỏ qua signal
 */
if ( ! class_exists( 'UserRuleNotifier' ) ) :
class UserRuleNotifier {

    const OPTION_KEY = 'lcni_user_rule_notifications';

    // =========================================================================
    // PUBLIC API
    // =========================================================================

    /**
     * Gửi email cho 1 sự kiện.
     * @param string $type       Loại sự kiện (ur_signal_opened, ur_signal_closed…)
     * @param int    $user_id    ID user nhận email
     * @param array  $vars       Biến thay thế {{var}} trong template
     */
    public static function send( string $type, int $user_id, array $vars = [] ): bool {
        $tmpl = self::get_type_settings( $type );
        if ( empty( $tmpl['enabled'] ) ) return false;

        $user = get_userdata( $user_id );
        if ( ! $user || ! $user->user_email ) return false;

        // Dùng global settings của LCNINotificationManager (from_name, from_email, logo, color…)
        $global = class_exists( 'LCNINotificationManager' )
            ? LCNINotificationManager::get_settings()
            : [];

        $site_name = get_bloginfo( 'name' );
        $site_url  = home_url( '/' );

        // Merge base vars — caller vars ưu tiên, base vars là fallback
        $vars = array_merge( [
            'site_name'  => $site_name,
            'site_url'   => $site_url,
            'user_name'  => $user->display_name ?: $user->user_login,
            'user_email' => $user->user_email,
        ], $vars );

        $subject = self::replace_vars( (string) ( $tmpl['subject'] ?? '' ), $vars );
        $heading = self::replace_vars( (string) ( $tmpl['heading'] ?? '' ), $vars );
        $body    = self::replace_vars( (string) ( $tmpl['body']    ?? '' ), $vars );
        $extra   = self::replace_vars( (string) ( $tmpl['extra']   ?? '' ), $vars );

        // Build HTML — dùng LCNINotificationManager::build_html nếu có (kế thừa logo, màu, footer)
        // Nếu không có thì dùng build_html nội bộ
        if ( class_exists( 'LCNINotificationManager' ) ) {
            $html = LCNINotificationManager::build_html(
                $heading,
                $body,
                $extra,
                $vars,
                (string) ( $global['logo_url']      ?? '' ),
                (string) ( $global['footer_text']   ?? '' ),
                (string) ( $global['primary_color'] ?? '#1e40af' )
            );
        } else {
            $html = self::build_html( $site_name, $heading, $body, $extra, $site_url );
        }

        // Set content-type dùng named method (có thể remove chính xác, không leak)
        add_filter( 'wp_mail_content_type', [ __CLASS__, '_html_content_type' ] );

        // Set from_name / from_email nếu admin đã cấu hình (giống LCNINotificationManager)
        $from_name  = ! empty( $global['from_name'] )  ? $global['from_name']  : '';
        $from_email = ! empty( $global['from_email'] ) ? $global['from_email'] : '';
        if ( $from_name !== '' ) {
            add_filter( 'wp_mail_from_name', [ __CLASS__, '_override_from_name' ], 5 );
            self::$_from_name = $from_name;
        }
        if ( $from_email !== '' ) {
            add_filter( 'wp_mail_from', [ __CLASS__, '_override_from_email' ], 5 );
            self::$_from_email = $from_email;
        }

        $sent = wp_mail( $user->user_email, $subject, $html );

        // Remove chỉ callback của chúng ta — không ảnh hưởng WP Mail SMTP
        remove_filter( 'wp_mail_content_type', [ __CLASS__, '_html_content_type' ] );
        remove_filter( 'wp_mail_from_name', [ __CLASS__, '_override_from_name' ], 5 );
        remove_filter( 'wp_mail_from',      [ __CLASS__, '_override_from_email' ], 5 );

        return $sent;
    }

    // Named callbacks cho filter — có thể remove chính xác
    public static function _html_content_type(): string { return 'text/html'; }
    public static $_from_name  = '';
    public static $_from_email = '';
    public static function _override_from_name( string $n ): string  { return self::$_from_name  !== '' ? self::$_from_name  : $n; }
    public static function _override_from_email( string $e ): string { return self::$_from_email !== '' ? self::$_from_email : $e; }

    // =========================================================================
    // SETTINGS
    // =========================================================================

    public static function get_defaults(): array {
        return [
            // Loại 1: Signal mới được mirror vào UserRule
            'ur_signal_opened' => [
                'enabled' => true,
                'subject' => '[{{site_name}}] 📈 Signal mới: {{symbol}} — Chiến lược "{{rule_name}}"',
                'heading' => '📈 Signal mới được áp dụng',
                'body'    => "<p>Xin chào <strong>{{user_name}}</strong>,</p>\n"
                           . "<p>Chiến lược <strong>{{rule_name}}</strong> vừa phát hiện tín hiệu mới cho mã <strong>{{symbol}}</strong>.</p>\n"
                           . "<table style=\"border:1px solid #e5e7eb;border-radius:8px;padding:12px 16px;background:#f9fafb;\">\n"
                           . "  <tr><td style=\"color:#6b7280;padding:3px 12px 3px 0\">Mã</td><td><strong>{{symbol}}</strong></td></tr>\n"
                           . "  <tr><td style=\"color:#6b7280;padding:3px 12px 3px 0\">Giá vào</td><td><strong>{{entry_price}}</strong></td></tr>\n"
                           . "  <tr><td style=\"color:#6b7280;padding:3px 12px 3px 0\">Stoploss</td><td>{{initial_sl}}</td></tr>\n"
                           . "  <tr><td style=\"color:#6b7280;padding:3px 12px 3px 0\">Khối lượng</td><td>{{shares}} cổ phiếu</td></tr>\n"
                           . "  <tr><td style=\"color:#6b7280;padding:3px 12px 3px 0\">Vốn phân bổ</td><td>{{allocated_capital}}</td></tr>\n"
                           . "  <tr><td style=\"color:#6b7280;padding:3px 12px 3px 0\">Loại</td><td>{{trade_type}}</td></tr>\n"
                           . "</table>",
                'extra'   => '',
            ],
            // Loại 2: Signal đóng
            'ur_signal_closed' => [
                'enabled' => true,
                'subject' => '[{{site_name}}] 🔔 Đóng vị thế {{symbol}} — {{exit_reason}}',
                'heading' => '🔔 Vị thế đã được đóng',
                'body'    => "<p>Xin chào <strong>{{user_name}}</strong>,</p>\n"
                           . "<p>Chiến lược <strong>{{rule_name}}</strong> vừa đóng vị thế <strong>{{symbol}}</strong>.</p>\n"
                           . "<table style=\"border:1px solid #e5e7eb;border-radius:8px;padding:12px 16px;background:#f9fafb;\">\n"
                           . "  <tr><td style=\"color:#6b7280;padding:3px 12px 3px 0\">Mã</td><td><strong>{{symbol}}</strong></td></tr>\n"
                           . "  <tr><td style=\"color:#6b7280;padding:3px 12px 3px 0\">Giá vào</td><td>{{entry_price}}</td></tr>\n"
                           . "  <tr><td style=\"color:#6b7280;padding:3px 12px 3px 0\">Giá ra</td><td>{{exit_price}}</td></tr>\n"
                           . "  <tr><td style=\"color:#6b7280;padding:3px 12px 3px 0\">R-Multiple</td><td><strong {{pnl_color}}>{{final_r}}R</strong></td></tr>\n"
                           . "  <tr><td style=\"color:#6b7280;padding:3px 12px 3px 0\">P&amp;L</td><td><strong {{pnl_color}}>{{pnl_vnd}}</strong></td></tr>\n"
                           . "  <tr><td style=\"color:#6b7280;padding:3px 12px 3px 0\">Lý do</td><td>{{exit_reason_label}}</td></tr>\n"
                           . "  <tr><td style=\"color:#6b7280;padding:3px 12px 3px 0\">Số ngày nắm</td><td>{{holding_days}} ngày</td></tr>\n"
                           . "</table>",
                'extra'   => '',
            ],
            // Loại 3: Đặt lệnh DNSE thành công
            'ur_order_placed' => [
                'enabled' => true,
                'subject' => '[{{site_name}}] ✅ Đặt lệnh DNSE thành công: {{symbol}}',
                'heading' => '✅ Lệnh đã được đặt',
                'body'    => "<p>Xin chào <strong>{{user_name}}</strong>,</p>\n"
                           . "<p>Lệnh mua cho tín hiệu từ chiến lược <strong>{{rule_name}}</strong> đã được gửi lên DNSE.</p>\n"
                           . "<table style=\"border:1px solid #e5e7eb;border-radius:8px;padding:12px 16px;background:#f9fafb;\">\n"
                           . "  <tr><td style=\"color:#6b7280;padding:3px 12px 3px 0\">Mã</td><td><strong>{{symbol}}</strong></td></tr>\n"
                           . "  <tr><td style=\"color:#6b7280;padding:3px 12px 3px 0\">Giá đặt</td><td>{{entry_price}}</td></tr>\n"
                           . "  <tr><td style=\"color:#6b7280;padding:3px 12px 3px 0\">Khối lượng</td><td>{{shares}} cổ phiếu</td></tr>\n"
                           . "  <tr><td style=\"color:#6b7280;padding:3px 12px 3px 0\">Số lệnh DNSE</td><td>{{dnse_order_id}}</td></tr>\n"
                           . "  <tr><td style=\"color:#6b7280;padding:3px 12px 3px 0\">Tài khoản</td><td>{{account_no}}</td></tr>\n"
                           . "</table>",
                'extra'   => '',
            ],
            // Loại 4: Đặt lệnh DNSE thất bại
            'ur_order_failed' => [
                'enabled' => true,
                'subject' => '[{{site_name}}] ⚠️ Đặt lệnh DNSE thất bại: {{symbol}}',
                'heading' => '⚠️ Lệnh không được thực thi',
                'body'    => "<p>Xin chào <strong>{{user_name}}</strong>,</p>\n"
                           . "<p>Hệ thống không thể đặt lệnh cho tín hiệu <strong>{{symbol}}</strong> từ chiến lược <strong>{{rule_name}}</strong>.</p>\n"
                           . "<p><strong>Lý do:</strong> {{error_message}}</p>\n"
                           . "<p>Vui lòng đăng nhập vào hệ thống để kiểm tra và đặt lệnh thủ công nếu cần.</p>",
                'extra'   => '',
            ],
            // Loại 5: Token DNSE hết hạn
            'ur_dnse_token_expired' => [
                'enabled' => true,
                'subject' => '[{{site_name}}] 🔑 Token DNSE hết hạn — Cần gia hạn',
                'heading' => '🔑 Phiên giao dịch DNSE đã hết hạn',
                'body'    => "<p>Xin chào <strong>{{user_name}}</strong>,</p>\n"
                           . "<p>Phiên giao dịch DNSE của bạn đã hết hạn. Hệ thống không thể tự động đặt lệnh cho tín hiệu <strong>{{symbol}}</strong> từ chiến lược <strong>{{rule_name}}</strong>.</p>\n"
                           . "<p>Vui lòng đăng nhập lại vào DNSE để gia hạn phiên giao dịch và nhận OTP mới.</p>\n"
                           . "<p><a href=\"{{site_url}}\" style=\"display:inline-block;padding:10px 24px;background:#2563eb;color:#fff;text-decoration:none;border-radius:6px;font-weight:600;\">Gia hạn ngay →</a></p>",
                'extra'   => '',
            ],
            // Loại 6: Đạt giới hạn số mã
            'ur_max_symbols' => [
                'enabled' => false, // Tắt mặc định — tránh spam
                'subject' => '[{{site_name}}] ℹ️ Bỏ qua tín hiệu {{symbol}} — Đạt giới hạn mã',
                'heading' => 'ℹ️ Signal bị bỏ qua',
                'body'    => "<p>Xin chào <strong>{{user_name}}</strong>,</p>\n"
                           . "<p>Chiến lược <strong>{{rule_name}}</strong> vừa phát hiện tín hiệu <strong>{{symbol}}</strong> nhưng đã bị bỏ qua vì bạn đang nắm giữ tối đa <strong>{{max_symbols}}</strong> mã.</p>\n"
                           . "<p>Đóng một vị thế đang mở để nhận tín hiệu tiếp theo.</p>",
                'extra'   => '',
            ],
        ];
    }

    public static function get_settings(): array {
        $saved    = get_option( self::OPTION_KEY, [] );
        if ( ! is_array( $saved ) ) $saved = [];
        $defaults = self::get_defaults();
        $types    = array_keys( $defaults );
        foreach ( $types as $type ) {
            if ( isset( $saved[$type] ) && is_array( $saved[$type] ) ) {
                $saved[$type] = array_merge( $defaults[$type], $saved[$type] );
            }
        }
        return array_merge( $defaults, $saved );
    }

    public static function get_type_settings( string $type ): array {
        $all      = self::get_settings();
        $defaults = self::get_defaults();
        $tmpl     = $all[$type] ?? $defaults[$type] ?? [];
        // Merge với defaults của type đó
        return array_merge( $defaults[$type] ?? [], $tmpl );
    }

    public static function save_settings( array $data ): void {
        $defaults = self::get_defaults();
        $clean    = [];
        foreach ( array_keys( $defaults ) as $type ) {
            if ( ! isset( $data[$type] ) || ! is_array( $data[$type] ) ) continue;
            $clean[$type] = [
                'enabled' => ! empty( $data[$type]['enabled'] ),
                'subject' => wp_kses_post( wp_unslash( $data[$type]['subject'] ?? '' ) ),
                'heading' => wp_kses_post( wp_unslash( $data[$type]['heading'] ?? '' ) ),
                'body'    => wp_kses_post( wp_unslash( $data[$type]['body']    ?? '' ) ),
                'extra'   => wp_kses_post( wp_unslash( $data[$type]['extra']   ?? '' ) ),
            ];
        }
        update_option( self::OPTION_KEY, $clean );
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    public static function replace_vars( $template, array $vars ): string {
        $template = (string)($template ?? '');
        foreach ( $vars as $k => $v ) {
            $template = str_replace( '{{' . $k . '}}', (string)($v ?? ''), (string)$template );
        }
        return $template;
    }

    private static function build_html( string $site_name, string $heading, string $body, string $extra, string $site_url ): string {
        $year = gmdate('Y');
        return <<<HTML
<!DOCTYPE html><html lang="vi"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:32px 0;">
<tr><td align="center">
<table width="560" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 4px 16px rgba(0,0,0,.08);max-width:560px;width:100%;">
<tr><td style="background:#1e40af;padding:24px 32px;">
  <p style="margin:0;color:#fff;font-size:20px;font-weight:700;">📊 {$site_name}</p>
  <p style="margin:4px 0 0;color:#bfdbfe;font-size:13px;">Thông báo chiến lược</p>
</td></tr>
<tr><td style="padding:28px 32px;">
  <h2 style="margin:0 0 16px;color:#111827;font-size:18px;">{$heading}</h2>
  <div style="color:#374151;font-size:14px;line-height:1.7;">{$body}</div>
  {$extra}
  <p style="margin:20px 0 0;color:#9ca3af;font-size:12px;line-height:1.6;">Đây là email tự động từ hệ thống. Không phải lời khuyên đầu tư.</p>
</td></tr>
<tr><td style="background:#f9fafb;padding:14px 32px;border-top:1px solid #e5e7eb;">
  <p style="margin:0;color:#9ca3af;font-size:12px;text-align:center;">{$site_name} &bull; {$year}</p>
</td></tr>
</table></td></tr></table></body></html>
HTML;
    }

    // =========================================================================
    // LABEL HELPERS
    // =========================================================================

    public static function exit_reason_label( string $reason ): string {
        $map = [
            'stop_loss'  => '❌ Stoploss',
            'max_loss'   => '❌ Max Loss',
            'take_profit'=> '✅ Take Profit',
            'max_hold'   => '⏰ Hết thời gian nắm giữ',
            'manual'     => '🖐 Đóng thủ công',
        ];
        return $map[$reason] ?? ucfirst( str_replace( '_', ' ', (string)($reason ?? '') ) );
    }

    public static function fmt_price( float $price_thousands ): string {
        return number_format( $price_thousands * 1000, 0, ',', '.' ) . ' đ';
    }

    public static function fmt_vnd( float $vnd ): string {
        $sign = $vnd >= 0 ? '+' : '';
        return $sign . number_format( $vnd, 0, ',', '.' ) . ' đ';
    }
}
endif; // class_exists UserRuleNotifier
