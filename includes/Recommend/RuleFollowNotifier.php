<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * RuleFollowNotifier
 *
 * Gửi email thông báo đến user khi có signal mới thuộc rule họ đang follow.
 *
 * Được gọi từ SignalRepository::create_signal() sau khi INSERT thành công.
 *
 * Email chứa:
 *   - Tên rule
 *   - Mã chứng khoán (symbol)
 *   - Giá vào lệnh đề xuất
 *   - Ngày signal
 *   - Link xem chi tiết signal
 */
class RuleFollowNotifier {

    /** @var RuleFollowRepository */
    private $follow_repo;

    /** @var RuleRepository */
    private $rule_repo;

    public function __construct( RuleFollowRepository $follow_repo, RuleRepository $rule_repo ) {
        $this->follow_repo = $follow_repo;
        $this->rule_repo   = $rule_repo;
    }

    // =========================================================================
    // PUBLIC API
    // =========================================================================

    /**
     * Gửi thông báo khi có signal mới được tạo.
     * Gọi ngay sau khi INSERT signal thành công.
     *
     * @param int   $signal_id   ID signal vừa tạo
     * @param array $rule        Rule data (id, name, ...)
     * @param string $symbol     Mã CK (uppercase)
     * @param float  $entry_price Giá vào DB format (nghìn VNĐ)
     * @param int    $entry_time  Unix timestamp của nến
     */
    public function on_new_signal(
        int    $signal_id,
        array  $rule,
        string $symbol,
        float  $entry_price,
        int    $entry_time
    ): void {
        $rule_id = (int) ( $rule['id'] ?? 0 );
        if ( $rule_id <= 0 || $signal_id <= 0 ) return;

        // Lấy danh sách subscribers của rule này
        $subscribers = $this->follow_repo->get_email_subscribers_for_rule( $rule_id );
        if ( empty( $subscribers ) ) return;

        $rule_name    = sanitize_text_field( (string) ( $rule['name'] ?? "Rule #{$rule_id}" ) );
        $price_vnd    = $entry_price * 1000;  // DB format → full VNĐ
        $signal_date  = $entry_time > 0
            ? date_i18n( 'd/m/Y', $entry_time )
            : date_i18n( 'd/m/Y' );

        // Link xem signal (trang có shortcode [lcni_recommend_signals])
        $signals_url  = $this->get_signals_page_url();

        foreach ( $subscribers as $sub ) {
            $this->send_email(
                (string) $sub['user_email'],
                (string) $sub['display_name'],
                $rule_name,
                $symbol,
                $price_vnd,
                $signal_date,
                $signals_url,
                $signal_id
            );
        }
    }

    // =========================================================================
    // EMAIL BUILDER
    // =========================================================================

    private function send_email(
        string $to_email,
        string $to_name,
        string $rule_name,
        string $symbol,
        float  $price_vnd,
        string $signal_date,
        string $signals_url,
        int    $signal_id
    ): void {
        if ( ! is_email( $to_email ) ) return;

        $site_name    = get_bloginfo( 'name' );
        $price_fmt    = number_format( $price_vnd, 0, ',', '.' ) . ' đ';
        $subject      = sprintf(
            '[%s] 📈 Tín hiệu mới: %s — Rule "%s"',
            $site_name, $symbol, $rule_name
        );

        $unsubscribe_url = add_query_arg( [
            'lcni_unfollow_rule' => $signal_id,
            '_nonce'             => wp_create_nonce( 'lcni_unfollow_' . $to_email ),
        ], home_url( '/' ) );

        $html = $this->build_html(
            $to_name, $rule_name, $symbol,
            $price_fmt, $signal_date, $signals_url,
            $site_name, $unsubscribe_url
        );

        // WordPress wp_mail với Content-Type HTML
        add_filter( 'wp_mail_content_type', [ $this, '_html_content_type' ] );
        wp_mail( $to_email, $subject, $html );
        remove_filter( 'wp_mail_content_type', [ $this, '_html_content_type' ] );
    }

    public function _html_content_type(): string {
        return 'text/html';
    }

    private function build_html(
        string $name,
        string $rule_name,
        string $symbol,
        string $price_fmt,
        string $date,
        string $signals_url,
        string $site_name,
        string $unsubscribe_url
    ): string {
        $greeting = $name ? "Xin chào <strong>" . esc_html( $name ) . "</strong>," : "Xin chào,";
        $signals_btn = $signals_url
            ? '<a href="' . esc_url( $signals_url ) . '" style="display:inline-block;padding:10px 24px;background:#2563eb;color:#fff;text-decoration:none;border-radius:6px;font-weight:600;font-size:14px;">Xem tín hiệu ngay →</a>'
            : '';

        return <<<HTML
<!DOCTYPE html>
<html lang="vi">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:32px 0;">
  <tr><td align="center">
    <table width="560" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 4px 16px rgba(0,0,0,.08);max-width:560px;width:100%;">

      <!-- Header -->
      <tr><td style="background:#1e40af;padding:24px 32px;">
        <p style="margin:0;color:#fff;font-size:20px;font-weight:700;">📈 {$site_name}</p>
        <p style="margin:4px 0 0;color:#bfdbfe;font-size:13px;">Thông báo tín hiệu mới</p>
      </td></tr>

      <!-- Body -->
      <tr><td style="padding:28px 32px;">
        <p style="margin:0 0 16px;color:#374151;font-size:15px;">{$greeting}</p>
        <p style="margin:0 0 20px;color:#374151;font-size:15px;">
          Hệ thống vừa phát hiện <strong>tín hiệu mới</strong> thuộc rule bạn đang theo dõi:
        </p>

        <!-- Signal card -->
        <table width="100%" cellpadding="0" cellspacing="0"
               style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;margin-bottom:24px;">
          <tr><td style="padding:20px 24px;">
            <table width="100%" cellpadding="0" cellspacing="0">
              <tr>
                <td style="padding:6px 0;">
                  <span style="color:#6b7280;font-size:12px;display:block;margin-bottom:2px;">Rule theo dõi</span>
                  <strong style="color:#1e40af;font-size:15px;">{$rule_name}</strong>
                </td>
              </tr>
              <tr>
                <td style="padding:6px 0;border-top:1px solid #dbeafe;">
                  <table width="100%" cellpadding="0" cellspacing="0">
                    <tr>
                      <td width="50%" style="padding-top:8px;">
                        <span style="color:#6b7280;font-size:12px;display:block;">Mã chứng khoán</span>
                        <strong style="color:#111827;font-size:22px;letter-spacing:1px;">{$symbol}</strong>
                      </td>
                      <td width="50%" style="padding-top:8px;">
                        <span style="color:#6b7280;font-size:12px;display:block;">Giá vào đề xuất</span>
                        <strong style="color:#16a34a;font-size:18px;">{$price_fmt}</strong>
                      </td>
                    </tr>
                    <tr>
                      <td colspan="2" style="padding-top:8px;">
                        <span style="color:#6b7280;font-size:12px;display:block;">Ngày tín hiệu</span>
                        <span style="color:#374151;font-size:14px;">{$date}</span>
                      </td>
                    </tr>
                  </table>
                </td>
              </tr>
            </table>
          </td></tr>
        </table>

        <!-- CTA -->
        <p style="margin:0 0 24px;text-align:center;">{$signals_btn}</p>

        <p style="margin:0;color:#6b7280;font-size:13px;line-height:1.6;">
          Đây là thông báo tự động từ hệ thống. Tín hiệu chỉ mang tính tham khảo,
          không phải lời khuyên đầu tư. Bạn chịu trách nhiệm với quyết định của mình.
        </p>
      </td></tr>

      <!-- Footer -->
      <tr><td style="background:#f9fafb;padding:16px 32px;border-top:1px solid #e5e7eb;">
        <p style="margin:0;color:#9ca3af;font-size:12px;text-align:center;">
          {$site_name} &bull;
          <a href="{$unsubscribe_url}" style="color:#9ca3af;">Bỏ theo dõi rule này</a>
        </p>
      </td></tr>

    </table>
  </td></tr>
</table>
</body>
</html>
HTML;
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Tìm URL trang có shortcode [lcni_recommend_signals].
     * Fallback về home_url nếu không tìm được.
     */
    private function get_signals_page_url(): string {
        // Tìm page có shortcode lcni_recommend_signals
        global $wpdb;
        $page = $wpdb->get_var(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_status = 'publish'
               AND post_type = 'page'
               AND post_content LIKE '%lcni_recommend_signals%'
             LIMIT 1"
        );
        if ( $page ) {
            return (string) get_permalink( (int) $page );
        }
        return home_url( '/' );
    }
}
