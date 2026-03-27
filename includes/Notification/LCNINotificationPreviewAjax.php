<?php

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * LCNINotificationPreviewAjax
 * Render preview HTML cho từng loại email thông báo (dùng trong admin iframe).
 */
class LCNINotificationPreviewAjax {

    public function __construct() {
        add_action( 'wp_ajax_lcni_notif_preview', [ $this, 'render_preview' ] );
    }

    public function render_preview(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );
        check_ajax_referer( 'lcni_notif_preview', '_nonce' );

        $type  = sanitize_key( $_GET['lcni_notif_preview'] ?? '' );
        $valid = [ 'register_success', 'follow_rule', 'new_signal' ];
        if ( ! in_array( $type, $valid, true ) ) wp_die( 'Invalid type', 400 );

        // Sample vars for preview
        $signal_card = LCNINotificationManager::build_signal_card(
            'RADA Breakout', 'VNM', '75.500 đ', date_i18n( 'd/m/Y' )
        );
        $vars = [
            'user_name'       => 'Nguyễn Văn A',
            'user_email'      => 'user@example.com',
            'rule_name'       => 'RADA Breakout',
            'symbol'          => 'VNM',
            'price'           => '75.500 đ',
            'signal_date'     => date_i18n( 'd/m/Y' ),
            'signal_card'     => $signal_card,
            'signals_url'     => home_url( '/' ),
            'unsubscribe_url' => home_url( '/' ),
        ];

        $tmpl    = LCNINotificationManager::get_type_settings( $type );
        $settings = LCNINotificationManager::get_settings();

        $vars['site_name'] = get_bloginfo( 'name' );
        $vars['site_url']  = home_url( '/' );

        $heading = LCNINotificationManager::replace_vars( (string) ( $tmpl['heading'] ?? '' ), $vars );
        $body    = LCNINotificationManager::replace_vars( (string) ( $tmpl['body']    ?? '' ), $vars );
        $extra   = LCNINotificationManager::replace_vars( (string) ( $tmpl['extra']   ?? '' ), $vars );

        // Build preview HTML using reflection to call private method
        // Instead: replicate the wrapping inline
        $logo_url     = (string) ( $settings['logo_url']     ?? '' );
        $footer_text  = (string) ( $settings['footer_text']  ?? '' );
        $primary      = (string) ( $settings['primary_color'] ?? '#1e40af' );
        $site_name    = esc_html( get_bloginfo('name') );
        $site_url     = esc_url( home_url('/') );

        $logo_html = $logo_url
            ? '<a href="' . $site_url . '" style="text-decoration:none;"><img src="' . esc_url($logo_url) . '" alt="' . $site_name . '" style="max-height:44px;max-width:180px;border:0;"></a>'
            : '<span style="font-size:20px;font-weight:700;color:#fff;">' . $site_name . '</span>';

        $footer = $footer_text
            ? wp_kses_post($footer_text)
            : $site_name . ' &bull; <a href="' . $site_url . '" style="color:#9ca3af;">' . $site_url . '</a>';

        $extra_html = $extra
            ? '<div style="margin-top:20px;padding-top:20px;border-top:1px solid #f3f4f6;">' . wp_kses_post($extra) . '</div>'
            : '';

        header( 'Content-Type: text/html; charset=UTF-8' );
        echo '<!DOCTYPE html><html lang="vi"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<style>
  body{margin:0;padding:0;background:#f3f4f6;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;}
  a{color:' . esc_attr($primary) . ';}
  a.btn-primary{display:inline-block;padding:11px 26px;background:' . esc_attr($primary) . ';color:#fff!important;text-decoration:none!important;border-radius:7px;font-weight:600;font-size:14px;}
  p{margin:0 0 14px;color:#374151;font-size:15px;line-height:1.65;}
</style>
</head>
<body>
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:24px 12px;">
<tr><td align="center">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 4px 16px rgba(0,0,0,.08);max-width:560px;">
  <tr><td style="background:' . esc_attr($primary) . ';padding:20px 28px;">' . $logo_html . '</td></tr>
  <tr><td style="padding:24px 28px 0;">
    <h2 style="margin:0 0 16px;font-size:20px;font-weight:700;color:#111827;">' . esc_html($heading) . '</h2>
  </td></tr>
  <tr><td style="padding:0 28px 24px;color:#374151;font-size:15px;line-height:1.65;">
    ' . wp_kses_post($body) . '
    ' . $extra_html . '
    <p style="margin-top:20px;font-size:11px;color:#9ca3af;border-top:1px solid #f3f4f6;padding-top:14px;">Đây là thông báo tự động từ hệ thống. Thông tin chỉ mang tính tham khảo.</p>
  </td></tr>
  <tr><td style="background:#f9fafb;padding:12px 28px;border-top:1px solid #e5e7eb;text-align:center;font-size:12px;color:#9ca3af;">' . $footer . '</td></tr>
</table>
</td></tr>
</table>
<div style="text-align:center;padding:8px;font-size:11px;color:#9ca3af;background:#e9ecef;">
  🔍 Preview — dữ liệu mẫu, không phải email thật
</div>
</body></html>';
        exit;
    }
}
