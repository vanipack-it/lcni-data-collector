<?php

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * LCNINotificationManager  v1.0
 *
 * Quản lý tất cả email notifications của plugin LCNI.
 * Mỗi loại thông báo có template riêng có thể tùy chỉnh trong admin.
 *
 * Types:
 *   register_success  — Chào mừng sau khi đăng ký
 *   follow_rule       — Xác nhận follow rule
 *   new_signal        — Tín hiệu mới
 *
 * Template variables: {{site_name}}, {{site_url}}, {{user_name}}, {{user_email}},
 *   {{rule_name}}, {{symbol}}, {{price}}, {{signal_date}}, {{signals_url}},
 *   {{unsubscribe_url}}, {{signal_card}}
 */
if ( ! class_exists( 'LCNINotificationManager' ) ) :
class LCNINotificationManager {

    const OPTION_KEY = 'lcni_notification_settings';

    // =========================================================================
    // DEFAULTS
    // =========================================================================

    public static function get_defaults(): array {
        return [
            // Global settings
            'from_name'   => '',
            'from_email'  => '',
            'logo_url'    => '',
            'footer_text' => '',
            'primary_color' => '#1e40af',

            // Loại 1: Đăng ký thành công
            'register_success' => [
                'enabled' => true,
                'subject' => '[{{site_name}}] 🎉 Chào mừng bạn đến với {{site_name}}!',
                'heading' => 'Chào mừng {{user_name}}!',
                'body'    => "<p>Tài khoản của bạn đã được tạo thành công.</p>\n<p>Bạn có thể bắt đầu theo dõi các tín hiệu giao dịch và nhận thông báo khi có cơ hội phù hợp.</p>\n<p><a href=\"{{site_url}}\" class=\"btn-primary\">Khám phá ngay →</a></p>",
                'extra'   => '',
            ],

            // Loại 2: Follow rule thành công
            'follow_rule' => [
                'enabled' => true,
                'subject' => '[{{site_name}}] ✅ Đang theo dõi rule "{{rule_name}}"',
                'heading' => '🔔 Theo dõi thành công',
                'body'    => "<p>Bạn đã bắt đầu theo dõi rule <strong>{{rule_name}}</strong>.</p>\n<p>Hệ thống sẽ thông báo khi có tín hiệu mới từ rule này qua các kênh bạn đã chọn.</p>",
                'extra'   => '',
            ],

            // Loại 3: Có signal mới
            'new_signal' => [
                'enabled' => true,
                'subject' => '[{{site_name}}] 📈 Tín hiệu mới: {{symbol}} — Rule "{{rule_name}}"',
                'heading' => '📈 Tín hiệu mới xuất hiện',
                'body'    => "<p>Hệ thống vừa phát hiện tín hiệu mới thuộc rule bạn đang theo dõi:</p>\n{{signal_card}}\n<p><a href=\"{{signals_url}}\" class=\"btn-primary\">Xem tín hiệu ngay →</a></p>",
                'extra'   => '',
            ],

            // ── Upgrade Request ──────────────────────────────────────────────

            // Loại 4: User gửi yêu cầu nâng cấp thành công
            'upgrade_submitted' => [
                'enabled' => true,
                'subject' => '[{{site_name}}] Đã nhận yêu cầu nâng cấp gói #{{request_id}}',
                'heading' => '📬 Yêu cầu của bạn đã được ghi nhận',
                'body'    => "<p>Xin chào <strong>{{user_name}}</strong>,</p>\n<p>Chúng tôi đã nhận được yêu cầu nâng cấp gói <strong>#{{request_id}}</strong> của bạn. Đội ngũ hỗ trợ sẽ xem xét và liên hệ với bạn sớm nhất có thể.</p>\n<p><strong>Gói hiện tại:</strong> {{from_package}}<br><strong>Gói muốn nâng cấp:</strong> {{to_package}}<br><strong>Công ty CK:</strong> {{broker_company}}<br><strong>ID CK:</strong> {{broker_id}}</p>",
                'extra'   => '',
            ],

            // Loại 5: Admin thông báo có yêu cầu mới (gửi cho admin)
            'upgrade_admin_notify' => [
                'enabled' => true,
                'subject' => '[{{site_name}}] Yêu cầu nâng cấp mới #{{request_id}} — {{user_name}}',
                'heading' => '🔔 Có yêu cầu nâng cấp gói mới',
                'body'    => "<p>Có yêu cầu nâng cấp gói mới cần xét duyệt:</p>\n<p><strong>Mã yêu cầu:</strong> #{{request_id}}<br><strong>Họ tên:</strong> {{user_name}}<br><strong>SĐT:</strong> {{phone}}<br><strong>Email:</strong> {{user_email}}<br><strong>Công ty CK:</strong> {{broker_company}}<br><strong>ID CK:</strong> {{broker_id}}<br><strong>Gói hiện tại:</strong> {{from_package}}<br><strong>Nâng lên:</strong> {{to_package}}</p>\n<p><a href=\"{{review_url}}\" class=\"btn-primary\">➜ Xem & Duyệt yêu cầu</a></p>",
                'extra'   => '',
            ],

            // Loại 6: Admin đã liên hệ user
            'upgrade_contacted' => [
                'enabled' => true,
                'subject' => '[{{site_name}}] Yêu cầu #{{request_id}} – Chúng tôi đã liên hệ',
                'heading' => '📞 Đội hỗ trợ đã liên hệ với bạn',
                'body'    => "<p>Xin chào <strong>{{user_name}}</strong>,</p>\n<p>Đội ngũ hỗ trợ đã xem xét yêu cầu <strong>#{{request_id}}</strong> và đã liên hệ (hoặc sẽ liên hệ trong thời gian sớm nhất) qua số <strong>{{phone}}</strong> hoặc email.</p>\n<p>Vui lòng kiểm tra điện thoại / email và phản hồi để hoàn tất quy trình xác minh.</p>",
                'extra'   => '',
            ],

            // Loại 7: Admin duyệt — nâng cấp thành công
            'upgrade_approved' => [
                'enabled' => true,
                'subject' => '[{{site_name}}] 🎉 Gói của bạn đã được nâng cấp! (#{{request_id}})',
                'heading' => '🎉 Chúc mừng — Gói đã được nâng cấp!',
                'body'    => "<p>Xin chào <strong>{{user_name}}</strong>,</p>\n<p>Yêu cầu nâng cấp <strong>#{{request_id}}</strong> đã được <strong style=\"color:#16a34a\">phê duyệt</strong>. Tài khoản của bạn đã được nâng lên gói <strong>{{to_package}}</strong>.</p>\n<p>Đăng nhập lại để trải nghiệm đầy đủ các tính năng mới.</p>\n<p><a href=\"{{site_url}}\" class=\"btn-primary\">Đăng nhập ngay →</a></p>\n{{admin_note_block}}",
                'extra'   => '',
            ],

            // Loại 8: Admin từ chối yêu cầu
            'upgrade_rejected' => [
                'enabled' => true,
                'subject' => '[{{site_name}}] Yêu cầu #{{request_id}} chưa được phê duyệt',
                'heading' => '❌ Yêu cầu nâng cấp chưa được duyệt',
                'body'    => "<p>Xin chào <strong>{{user_name}}</strong>,</p>\n<p>Rất tiếc, yêu cầu nâng cấp <strong>#{{request_id}}</strong> chưa được phê duyệt lần này.</p>\n{{admin_note_block}}\n<p>Nếu có thắc mắc, vui lòng liên hệ đội ngũ hỗ trợ để được hướng dẫn thêm.</p>",
                'extra'   => '',
            ],
        ];
    }

    // =========================================================================
    // SETTINGS ACCESSORS
    // =========================================================================

    public static function get_settings(): array {
        $saved    = get_option( self::OPTION_KEY, [] );
        if ( ! is_array( $saved ) ) $saved = [];
        $defaults = self::get_defaults();

        // Deep merge for nested type arrays
        foreach ( [ 'register_success', 'follow_rule', 'new_signal',
                    'upgrade_submitted', 'upgrade_admin_notify', 'upgrade_contacted',
                    'upgrade_approved', 'upgrade_rejected' ] as $type ) {
            if ( isset( $saved[ $type ] ) && is_array( $saved[ $type ] ) ) {
                $saved[ $type ] = array_merge( $defaults[ $type ], $saved[ $type ] );
            }
        }
        return array_merge( $defaults, $saved );
    }

    public static function get_type_settings( string $type ): array {
        $all      = self::get_settings();
        $defaults = self::get_defaults();
        $def_type = $defaults[ $type ] ?? [ 'enabled' => false, 'subject' => '', 'heading' => '', 'body' => '', 'extra' => '' ];
        $saved    = $all[ $type ] ?? [];
        return is_array( $saved ) ? array_merge( $def_type, $saved ) : $def_type;
    }

    // =========================================================================
    // SEND EMAIL
    // =========================================================================

    /**
     * @param string $type     Loại thông báo: register_success | follow_rule | new_signal
     * @param string $to_email Email người nhận
     * @param array  $vars     Biến thay thế {{key}} trong template
     */
    public static function send( string $type, string $to_email, array $vars = [] ): bool {
        if ( ! is_email( $to_email ) ) return false;

        $tmpl = self::get_type_settings( $type );
        if ( empty( $tmpl['enabled'] ) ) return false;

        $global = self::get_settings();

        // Default vars
        $vars = array_merge( [
            'site_name'        => get_bloginfo( 'name' ),
            'site_url'         => home_url( '/' ),
            'signals_url'      => home_url( '/' ),
            'unsubscribe_url'  => home_url( '/' ),
            'user_name'        => '',
            'user_email'       => $to_email,
            'rule_name'        => '',
            'symbol'           => '',
            'price'            => '',
            'signal_date'      => '',
            'signal_card'      => '',
            // upgrade vars
            'request_id'       => '',
            'phone'            => '',
            'broker_company'   => '',
            'broker_id'        => '',
            'from_package'     => '',
            'to_package'       => '',
            'review_url'       => '',
            'admin_note_block' => '',
        ], $vars );

        $subject = self::replace_vars( (string) ( $tmpl['subject'] ?? '' ), $vars );
        $heading = self::replace_vars( (string) ( $tmpl['heading'] ?? '' ), $vars );
        $body    = self::replace_vars( (string) ( $tmpl['body']    ?? '' ), $vars );
        $extra   = self::replace_vars( (string) ( $tmpl['extra']   ?? '' ), $vars );

        $html = self::build_html(
            $heading,
            $body,
            $extra,
            $vars,
            (string) ( $global['logo_url']     ?? '' ),
            (string) ( $global['footer_text']  ?? '' ),
            (string) ( $global['primary_color'] ?? '#1e40af' )
        );

        // Chỉ set content-type — KHÔNG override wp_mail_from/wp_mail_from_name
        // vì remove_all_filters sẽ phá WP Mail SMTP / FluentSMTP hooks.
        // WP Mail SMTP tự quản lý from email qua cấu hình riêng của nó.
        add_filter( 'wp_mail_content_type', [ __CLASS__, 'html_content_type' ] );

        // Chỉ set from nếu admin đã cấu hình LCNI override (không rỗng)
        // và dùng named callback để có thể remove chính xác sau đó
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

        $ok = wp_mail( $to_email, $subject, $html );

        // Remove chỉ callback của chúng ta — không ảnh hưởng WP Mail SMTP
        remove_filter( 'wp_mail_content_type', [ __CLASS__, 'html_content_type' ] );
        remove_filter( 'wp_mail_from_name',    [ __CLASS__, '_override_from_name' ],  5 );
        remove_filter( 'wp_mail_from',         [ __CLASS__, '_override_from_email' ], 5 );

        return $ok;
    }

    public static function html_content_type(): string { return 'text/html'; }

    /** @internal Used by named filter callbacks — safe to remove without touching SMTP plugins */
    public static $_from_name  = '';  // untyped for PHP 7.4 compat
    public static $_from_email = '';

    public static function _override_from_name( string $name ): string {
        return self::$_from_name !== '' ? self::$_from_name : $name;
    }

    public static function _override_from_email( string $email ): string {
        return self::$_from_email !== '' ? self::$_from_email : $email;
    }

    // =========================================================================
    // TEMPLATE HELPERS
    // =========================================================================

    public static function replace_vars( string $text, array $vars ): string {
        foreach ( $vars as $k => $v ) {
            $text = str_replace( '{{' . $k . '}}', (string) $v, (string) $text );
        }
        return $text;
    }

    /**
     * Gửi email thông báo liên quan đến upgrade request.
     * Tự động build vars từ $request array.
     *
     * @param string $type     upgrade_submitted|upgrade_admin_notify|upgrade_contacted|upgrade_approved|upgrade_rejected
     * @param string $to_email
     * @param array  $request  Row từ DB (có from_package_name, to_package_name, ...)
     * @param string $admin_note
     */
    public static function send_upgrade( string $type, string $to_email, array $request, string $admin_note = '' ): bool {
        $user      = get_userdata( (int) ( $request['user_id'] ?? 0 ) );
        $user_name = $user ? $user->display_name : ( $request['full_name'] ?? '' );

        $review_url = admin_url( 'admin.php?page=lcni-member-settings&tab=upgrades&detail=' . ( $request['id'] ?? '' ) );

        $note_block = $admin_note
            ? '<p style="margin-top:14px;padding:12px 16px;background:#f9fafb;border-left:3px solid #d1d5db;border-radius:4px;font-size:13px;color:#374151;"><strong>Ghi chú:</strong> ' . esc_html( $admin_note ) . '</p>'
            : '';

        $vars = [
            'request_id'       => (int) ( $request['id']               ?? 0 ),
            'user_name'        => $user_name,
            'user_email'       => $request['email']                     ?? $to_email,
            'phone'            => $request['phone']                     ?? '',
            'broker_company'   => $request['broker_company']            ?? '',
            'broker_id'        => $request['broker_id']                 ?? '',
            'from_package'     => $request['from_package_name']         ?? '—',
            'to_package'       => $request['to_package_name']           ?? '—',
            'review_url'       => $review_url,
            'admin_note_block' => $note_block,
        ];

        return self::send( $type, $to_email, $vars );
    }

    /**
     * Build signal info card HTML — injected as {{signal_card}} in new_signal body.
     */
    public static function build_signal_card( string $rule_name, string $symbol, string $price_fmt, string $date ): string {
        return '<table width="100%" cellpadding="0" cellspacing="0"
                style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;margin:16px 0;">
          <tr><td style="padding:18px 22px;">
            <p style="margin:0 0 3px;font-size:11px;color:#6b7280;text-transform:uppercase;letter-spacing:.5px;">Rule theo dõi</p>
            <p style="margin:0 0 14px;font-size:16px;font-weight:700;color:#1e40af;">' . esc_html($rule_name) . '</p>
            <table width="100%" cellpadding="0" cellspacing="0" style="border-top:1px solid #dbeafe;">
              <tr>
                <td style="padding-top:12px;width:34%">
                  <p style="margin:0 0 2px;font-size:11px;color:#6b7280;">Mã CK</p>
                  <p style="margin:0;font-size:24px;font-weight:800;color:#111827;letter-spacing:1.5px;">' . esc_html($symbol) . '</p>
                </td>
                <td style="padding-top:12px;width:33%">
                  <p style="margin:0 0 2px;font-size:11px;color:#6b7280;">Giá vào đề xuất</p>
                  <p style="margin:0;font-size:20px;font-weight:700;color:#16a34a;">' . esc_html($price_fmt) . '</p>
                </td>
                <td style="padding-top:12px;width:33%">
                  <p style="margin:0 0 2px;font-size:11px;color:#6b7280;">Ngày tín hiệu</p>
                  <p style="margin:0;font-size:14px;color:#374151;">' . esc_html($date) . '</p>
                </td>
              </tr>
            </table>
          </td></tr>
        </table>';
    }

    // =========================================================================
    // HTML WRAPPER
    // =========================================================================

    public static function build_html(
        string $heading,
        string $body,
        string $extra,
        array  $vars,
        string $logo_url,
        string $footer_text,
        string $primary_color = '#1e40af'
    ): string {
        $site_name = esc_html( $vars['site_name'] ?? get_bloginfo('name') );
        $site_url  = esc_url(  $vars['site_url']  ?? home_url('/') );

        $logo_html = $logo_url
            ? '<a href="' . $site_url . '" style="text-decoration:none;"><img src="' . esc_url($logo_url) . '" alt="' . $site_name . '" style="max-height:44px;max-width:180px;border:0;"></a>'
            : '<a href="' . $site_url . '" style="text-decoration:none;font-size:20px;font-weight:700;color:#ffffff;">' . $site_name . '</a>';

        $footer = $footer_text
            ? wp_kses_post( $footer_text )
            : $site_name . ' &bull; <a href="' . $site_url . '" style="color:#9ca3af;">' . $site_url . '</a>';

        $extra_html = $extra
            ? '<div style="margin-top:20px;padding-top:20px;border-top:1px solid #f3f4f6;">' . wp_kses_post($extra) . '</div>'
            : '';

        return '<!DOCTYPE html><html lang="vi">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <style>
    body{margin:0;padding:0;background:#f3f4f6;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;}
    a{color:' . esc_attr($primary_color) . ';}
    a.btn-primary,a.btn-primary:visited{display:inline-block;padding:11px 26px;background:' . esc_attr($primary_color) . ';color:#fff!important;text-decoration:none!important;border-radius:7px;font-weight:600;font-size:14px;line-height:1;margin:4px 0;}
    p{margin:0 0 14px;color:#374151;font-size:15px;line-height:1.65;}
    @media(max-width:640px){.email-wrap{width:100%!important;border-radius:0!important;}}
  </style>
</head>
<body>
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:32px 16px;">
<tr><td align="center">
<table class="email-wrap" width="600" cellpadding="0" cellspacing="0"
       style="background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,.09);max-width:600px;width:100%;">

  <!-- HEADER -->
  <tr><td style="background:' . esc_attr($primary_color) . ';padding:22px 32px;">
    ' . $logo_html . '
  </td></tr>

  <!-- HEADING -->
  <tr><td style="padding:28px 32px 0;">
    <h2 style="margin:0 0 18px;font-size:21px;font-weight:700;color:#111827;">' . esc_html($heading) . '</h2>
  </td></tr>

  <!-- BODY -->
  <tr><td style="padding:0 32px 28px;font-size:15px;color:#374151;line-height:1.65;">
    ' . wp_kses_post($body) . '
    ' . $extra_html . '
    <p style="margin-top:24px;font-size:12px;color:#9ca3af;border-top:1px solid #f3f4f6;padding-top:16px;">
      Đây là thông báo tự động từ hệ thống. Thông tin chỉ mang tính tham khảo, không phải lời khuyên đầu tư.
    </p>
  </td></tr>

  <!-- FOOTER -->
  <tr><td style="background:#f9fafb;padding:14px 32px;border-top:1px solid #e5e7eb;text-align:center;font-size:12px;color:#9ca3af;">
    ' . $footer . '
  </td></tr>

</table>
</td></tr>
</table>
</body></html>';
    }
}
endif; // class_exists LCNINotificationManager
