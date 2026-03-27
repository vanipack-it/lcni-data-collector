<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * RuleFollowNotifier  v2.0
 *
 * Xử lý 3 loại thông báo khi có signal mới:
 *   1. Email — gửi qua wp_mail()
 *   2. Browser Push Notification — VAPID + Web Push Protocol (RFC 8030)
 *   3. Dynamic Watchlist Sync — tự add symbol vào watchlist được liên kết
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
     * Gọi SAU KHI tất cả signals của 1 rule đã được INSERT xong trong 1 lần scan.
     * Gộp nhiều symbols vào 1 email digest + 1 inbox notification cho mỗi follower.
     *
     * @param array $rule        Rule data array
     * @param array $new_signals [ ['signal_id'=>int, 'symbol'=>str, 'entry_price'=>float, 'entry_time'=>int], ... ]
     */
    public function on_new_signals_batch( array $rule, array $new_signals ): void {
        $rule_id = (int) ( $rule['id'] ?? 0 );
        if ( $rule_id <= 0 || empty( $new_signals ) ) return;

        $rule_name   = sanitize_text_field( (string) ( $rule['name'] ?? "Rule #{$rule_id}" ) );
        $signals_url = $this->get_signals_page_url();
        $signal_date = date_i18n( 'd/m/Y' );

        // Danh sách symbols + giá
        $symbols_data = [];
        foreach ( $new_signals as $s ) {
            $symbols_data[] = [
                'symbol'      => strtoupper( sanitize_text_field( (string) $s['symbol'] ) ),
                'price'       => number_format( (float) $s['entry_price'] * 1000, 0, ',', '.' ) . ' đ',
                'entry_time'  => (int) $s['entry_time'],
                'signal_id'   => (int) $s['signal_id'],
            ];
        }
        $symbol_list = array_column( $symbols_data, 'symbol' );
        $symbol_str  = implode( ', ', $symbol_list );
        $count       = count( $symbol_list );

        // 1. Email digest — queue, tránh gửi đồng loạt
        $email_subs = $this->follow_repo->get_email_subscribers_for_rule( $rule_id );
        if ( ! empty( $email_subs ) ) {
            // Email đầu tiên gửi ngay, còn lại queue 45s mỗi người
            $first = array_shift( $email_subs );
            $this->send_digest_email( $first, $rule_name, $symbols_data, $signals_url, $signal_date );

            foreach ( $email_subs as $idx => $sub ) {
                $delay = ( $idx + 1 ) * 45;
                wp_schedule_single_event(
                    time() + $delay,
                    'lcni_send_queued_digest_notification',
                    [ $sub, $rule_name, $symbols_data, $signals_url, $signal_date ]
                );
            }
        }

        // 2. Inbox notification — 1 notification tổng hợp cho mỗi follower
        global $wpdb;
        $tbl       = $wpdb->prefix . 'lcni_recommend_rule_follow';
        $followers = $wpdb->get_results(
            $wpdb->prepare( "SELECT user_id FROM {$tbl} WHERE rule_id = %d", $rule_id ),
            ARRAY_A
        );

        if ( ! empty( $followers ) ) {
            $cfg  = LCNI_InboxDB::get_admin_config();
            $url  = $cfg['inbox_page_url'] ?? home_url('/');

            if ( $count === 1 ) {
                $s     = $symbols_data[0];
                $title = "🎯 Tín hiệu Recommend: {$s['symbol']}";
                $body  = "Chiến lược <strong>{$rule_name}</strong> vừa phát tín hiệu mua cho <strong>{$s['symbol']}</strong>"
                       . " tại giá <strong>{$s['price']}</strong>.";
            } else {
                $title = "🎯 Tín hiệu Recommend: {$count} mã mới từ {$rule_name}";
                $body  = "Chiến lược <strong>{$rule_name}</strong> vừa phát <strong>{$count} tín hiệu</strong>: "
                       . '<strong>' . esc_html( $symbol_str ) . '</strong>.';
            }

            foreach ( $followers as $row ) {
                $uid = (int) $row['user_id'];
                if ( ! $uid ) continue;
                LCNI_InboxDB::insert( [
                    'user_id' => $uid,
                    'type'    => 'recommend_signal',
                    'title'   => $title,
                    'body'    => $body,
                    'url'     => $url,
                    'meta'    => [
                        'rule_id'      => $rule_id,
                        'rule_name'    => $rule_name,
                        'symbol_count' => $count,
                        'symbols'      => $symbol_list,
                    ],
                ] );
            }
        }

        // 3. Browser push (giữ nguyên — 1 lần per rule)
        $browser_subs = $this->follow_repo->get_browser_subscribers_for_rule( $rule_id );
        if ( ! empty( $browser_subs ) ) {
            $user_ids = array_column( $browser_subs, 'user_id' );
            $push_body = $count > 1
                ? "{$count} tín hiệu mới: {$symbol_str}"
                : ( $symbol_list[0] . ' — ' . $symbols_data[0]['price'] );
            $this->send_browser_push( $user_ids, $rule_name, $push_body, $signals_url );
        }

        // 4. Dynamic watchlist sync
        $wl_subs = $this->follow_repo->get_dynamic_watchlist_subscribers_for_rule( $rule_id );
        if ( ! empty( $wl_subs ) ) {
            foreach ( $symbol_list as $sym ) {
                $this->sync_symbol_to_watchlists( $wl_subs, $sym );
            }
        }
    }

    /**
     * Gọi ngay sau khi INSERT signal thành công — GIỮ LẠI để tương thích
     * với các code path khác (manual scan từ admin, v.v.)
     * Chỉ gọi trực tiếp khi scan 1 signal đơn lẻ, không phải batch.
     *
     * @deprecated Với cron batch scan, dùng on_new_signals_batch() thay thế.
     */
    public function on_new_signal(
        int    $signal_id,
        array  $rule,
        string $symbol,
        float  $entry_price,
        int    $entry_time
    ): void {
        // Chuyển về batch với 1 phần tử
        $this->on_new_signals_batch( $rule, [ [
            'signal_id'   => $signal_id,
            'symbol'      => $symbol,
            'entry_price' => $entry_price,
            'entry_time'  => $entry_time,
        ] ] );
    }

    // =========================================================================
    // QUEUED EMAIL HANDLER (WP Cron)
    // =========================================================================

    /**
     * Xử lý 1 digest email đã được queue.
     * Hook: lcni_send_queued_digest_notification
     */
    public static function handle_queued_digest_notification(
        array  $sub,
        string $rule_name,
        array  $symbols_data,
        string $signals_url,
        string $signal_date
    ): void {
        $instance = self::get_instance();
        if ( $instance ) {
            $instance->send_digest_email( $sub, $rule_name, $symbols_data, $signals_url, $signal_date );
        }
    }

    /** Singleton getter — dùng cho static queued handlers */
    private static ?self $instance = null;
    public static function set_instance( self $inst ): void { self::$instance = $inst; }
    private static function get_instance(): ?self { return self::$instance; }

    /**
     * Gửi 1 email digest tổng hợp nhiều symbols cho 1 subscriber.
     */
    private function send_digest_email(
        array  $sub,
        string $rule_name,
        array  $symbols_data,
        string $signals_url,
        string $signal_date
    ): void {
        $to_email = (string) ( $sub['user_email'] ?? '' );
        $to_name  = (string) ( $sub['display_name'] ?? '' );
        if ( ! is_email( $to_email ) ) return;

        $count      = count( $symbols_data );
        $site_name  = get_bloginfo( 'name' );
        $symbol_str = implode( ', ', array_column( $symbols_data, 'symbol' ) );

        if ( $count === 1 ) {
            $subject = sprintf( '[%s] 📈 Tín hiệu mới: %s — Chiến lược "%s"',
                $site_name, $symbols_data[0]['symbol'], $rule_name );
        } else {
            $subject = sprintf( '[%s] 📈 %d tín hiệu mới từ chiến lược "%s": %s',
                $site_name, $count, $rule_name, $symbol_str );
        }

        $greeting   = $to_name ? "Xin chào <strong>" . esc_html($to_name) . "</strong>," : "Xin chào,";
        $signals_btn = $signals_url
            ? '<a href="' . esc_url($signals_url) . '" style="display:inline-block;padding:10px 24px;background:#2563eb;color:#fff;text-decoration:none;border-radius:6px;font-weight:600;font-size:14px;">Xem tín hiệu ngay →</a>'
            : '';

        // Build rows bảng symbols
        $rows_html = '';
        foreach ( $symbols_data as $s ) {
            $rows_html .= '<tr>'
                . '<td style="padding:10px 16px;font-weight:700;color:#1e40af;font-size:15px;">' . esc_html( $s['symbol'] ) . '</td>'
                . '<td style="padding:10px 16px;color:#374151;font-size:14px;">' . esc_html( $s['price'] ) . '</td>'
                . '<td style="padding:10px 16px;color:#6b7280;font-size:13px;">' . esc_html( $signal_date ) . '</td>'
                . '</tr>';
        }

        $intro = $count > 1
            ? "Hệ thống vừa phát hiện <strong>{$count} tín hiệu mới</strong> thuộc chiến lược bạn đang theo dõi:"
            : "Hệ thống vừa phát hiện <strong>tín hiệu mới</strong> thuộc chiến lược bạn đang theo dõi:";

        $html = <<<HTML
<!DOCTYPE html><html lang="vi"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:32px 0;">
<tr><td align="center">
<table width="560" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 4px 16px rgba(0,0,0,.08);max-width:560px;width:100%;">
<tr><td style="background:#1e40af;padding:24px 32px;">
  <p style="margin:0;color:#fff;font-size:20px;font-weight:700;">📈 {$site_name}</p>
  <p style="margin:4px 0 0;color:#bfdbfe;font-size:13px;">Thông báo tín hiệu mới</p>
</td></tr>
<tr><td style="padding:28px 32px;">
  <p style="margin:0 0 16px;color:#374151;font-size:15px;">{$greeting}</p>
  <p style="margin:0 0 20px;color:#374151;font-size:15px;">{$intro}</p>
  <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;margin-bottom:24px;overflow:hidden;">
    <div style="padding:14px 16px 6px;background:#dbeafe;">
      <span style="color:#1e40af;font-size:13px;font-weight:700;">📋 Chiến lược: {$rule_name}</span>
    </div>
    <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">
      <thead>
        <tr style="background:#e0f2fe;">
          <th style="padding:8px 16px;text-align:left;font-size:12px;color:#0c4a6e;font-weight:600;text-transform:uppercase;">Mã CK</th>
          <th style="padding:8px 16px;text-align:left;font-size:12px;color:#0c4a6e;font-weight:600;text-transform:uppercase;">Giá vào</th>
          <th style="padding:8px 16px;text-align:left;font-size:12px;color:#0c4a6e;font-weight:600;text-transform:uppercase;">Ngày</th>
        </tr>
      </thead>
      <tbody>{$rows_html}</tbody>
    </table>
  </div>
  <div style="text-align:center;margin-top:8px;">{$signals_btn}</div>
</td></tr>
<tr><td style="padding:16px 32px;background:#f8fafc;border-top:1px solid #e5e7eb;">
  <p style="margin:0;color:#9ca3af;font-size:11px;text-align:center;">
    Email này được gửi vì bạn đang theo dõi chiến lược "{$rule_name}" tại {$site_name}.
  </p>
</td></tr>
</table>
</td></tr></table>
</body></html>
HTML;

        add_filter( 'wp_mail_content_type', [ $this, '_html_content_type' ] );
        wp_mail( $to_email, $subject, $html );
        remove_filter( 'wp_mail_content_type', [ $this, '_html_content_type' ] );
    }

    /**
     * Xử lý 1 email đã được queue (legacy — 1 symbol).
     * Hook: lcni_send_queued_notification
     */
    public static function handle_queued_notification( array $item ): void {
        if ( empty( $item['email'] ) ) return;
        LCNINotificationManager::send( 'new_signal', $item['email'], [
            'user_name'       => $item['name']        ?? '',
            'user_email'      => $item['email'],
            'rule_name'       => $item['rule_name']   ?? '',
            'symbol'          => $item['symbol']      ?? '',
            'price'           => $item['price']       ?? '',
            'signal_date'     => $item['date']        ?? '',
            'signal_card'     => $item['card']        ?? '',
            'signals_url'     => $item['signals_url'] ?? home_url('/'),
            'unsubscribe_url' => $item['unsub_url']   ?? home_url('/'),
        ] );
    }

        // =========================================================================
    // 1. EMAIL
    // =========================================================================

    /**
     * N1 NOTE: hàm này không được gọi — on_new_signal() dùng LCNINotificationManager::send() trực tiếp.
     * @deprecated Giữ lại để tham khảo, sẽ xoá trong phiên bản kế tiếp.
     */
    private function send_email(
        string $to_email, string $to_name, string $rule_name,
        string $symbol, float $price_vnd, string $signal_date,
        string $signals_url, int $signal_id
    ): void {
        if ( ! is_email( $to_email ) ) return;

        $site_name       = get_bloginfo( 'name' );
        $price_fmt       = number_format( $price_vnd, 0, ',', '.' ) . ' đ';
        $subject         = sprintf( '[%s] 📈 Tín hiệu mới: %s — Rule "%s"', $site_name, $symbol, $rule_name );
        $unsubscribe_url = add_query_arg([
            'lcni_unfollow_rule' => $signal_id,
            '_nonce'             => wp_create_nonce( 'lcni_unfollow_' . $to_email ),
        ], home_url( '/' ) );

        $html = $this->build_email_html(
            $to_name, $rule_name, $symbol, $price_fmt, $signal_date,
            $signals_url, $site_name, $unsubscribe_url
        );

        add_filter( 'wp_mail_content_type', [ $this, '_html_content_type' ] );
        wp_mail( $to_email, $subject, $html );
        remove_filter( 'wp_mail_content_type', [ $this, '_html_content_type' ] );
    }

    public function _html_content_type(): string { return 'text/html'; }

    private function build_email_html(
        string $name, string $rule_name, string $symbol,
        string $price_fmt, string $date, string $signals_url,
        string $site_name, string $unsubscribe_url
    ): string {
        $greeting    = $name ? "Xin chào <strong>" . esc_html($name) . "</strong>," : "Xin chào,";
        $signals_btn = $signals_url
            ? '<a href="' . esc_url($signals_url) . '" style="display:inline-block;padding:10px 24px;background:#2563eb;color:#fff;text-decoration:none;border-radius:6px;font-weight:600;font-size:14px;">Xem tín hiệu ngay →</a>'
            : '';

        return <<<HTML
<!DOCTYPE html><html lang="vi"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:32px 0;">
<tr><td align="center">
<table width="560" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 4px 16px rgba(0,0,0,.08);max-width:560px;width:100%;">
<tr><td style="background:#1e40af;padding:24px 32px;">
  <p style="margin:0;color:#fff;font-size:20px;font-weight:700;">📈 {$site_name}</p>
  <p style="margin:4px 0 0;color:#bfdbfe;font-size:13px;">Thông báo tín hiệu mới</p>
</td></tr>
<tr><td style="padding:28px 32px;">
  <p style="margin:0 0 16px;color:#374151;font-size:15px;">{$greeting}</p>
  <p style="margin:0 0 20px;color:#374151;font-size:15px;">Hệ thống vừa phát hiện <strong>tín hiệu mới</strong> thuộc rule bạn đang theo dõi:</p>
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;margin-bottom:24px;">
    <tr><td style="padding:20px 24px;">
      <span style="color:#6b7280;font-size:12px;display:block;margin-bottom:2px;">Rule theo dõi</span>
      <strong style="color:#1e40af;font-size:15px;">{$rule_name}</strong>
      <table width="100%" cellpadding="0" cellspacing="0" style="margin-top:12px;border-top:1px solid #dbeafe;">
        <tr>
          <td width="50%" style="padding-top:10px;"><span style="color:#6b7280;font-size:12px;display:block;">Mã chứng khoán</span><strong style="color:#111827;font-size:22px;">{$symbol}</strong></td>
          <td width="50%" style="padding-top:10px;"><span style="color:#6b7280;font-size:12px;display:block;">Giá vào đề xuất</span><strong style="color:#16a34a;font-size:18px;">{$price_fmt}</strong></td>
        </tr>
        <tr><td colspan="2" style="padding-top:8px;"><span style="color:#6b7280;font-size:12px;display:block;">Ngày tín hiệu</span><span style="color:#374151;font-size:14px;">{$date}</span></td></tr>
      </table>
    </td></tr>
  </table>
  <p style="margin:0 0 24px;text-align:center;">{$signals_btn}</p>
  <p style="margin:0;color:#6b7280;font-size:13px;line-height:1.6;">Đây là thông báo tự động. Tín hiệu chỉ mang tính tham khảo, không phải lời khuyên đầu tư.</p>
</td></tr>
<tr><td style="background:#f9fafb;padding:16px 32px;border-top:1px solid #e5e7eb;">
  <p style="margin:0;color:#9ca3af;font-size:12px;text-align:center;">{$site_name} &bull; <a href="{$unsubscribe_url}" style="color:#9ca3af;">Bỏ theo dõi rule này</a></p>
</td></tr>
</table></td></tr></table></body></html>
HTML;
    }

    // =========================================================================
    // 2. BROWSER PUSH NOTIFICATION (Web Push Protocol / VAPID)
    // =========================================================================

    /**
     * Gửi Web Push đến tất cả subscriptions của $user_ids.
     * Không throw — fail silently, log error.
     */
    private function send_browser_push( array $user_ids, string $rule_name, string $symbol_text, string $url ): void {
        if ( empty( $user_ids ) ) return;

        $vapid_pub  = (string) get_option('lcni_vapid_public_key', '');
        $vapid_priv = (string) get_option('lcni_vapid_private_key', '');
        if ( $vapid_pub === '' || $vapid_priv === '' ) return; // VAPID chưa setup

        global $wpdb;
        $table = $wpdb->prefix . 'lcni_push_subscriptions';

        $placeholders = implode(',', array_fill(0, count($user_ids), '%d'));
        $subs = $wpdb->get_results(
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            call_user_func_array(
                [ $wpdb, 'prepare' ],
                array_merge(
                    [ "SELECT endpoint, p256dh, auth FROM {$table} WHERE user_id IN ({$placeholders})" ],
                    array_values( $user_ids )
                )
            ),
            ARRAY_A
        );
        if ( empty( $subs ) ) return;

        $payload = wp_json_encode( [
            'title' => '📈 Tín hiệu mới: ' . $symbol_text,
            'body'  => 'Chiến lược "' . $rule_name . '" vừa phát hiện tín hiệu. Nhấn để xem chi tiết.',
            'icon'  => '/favicon.ico',
            'url'   => $url ?: home_url( '/' ),
            'tag'   => 'lcni-signal-' . md5( $symbol_text ) . '-' . time(),
        ], JSON_UNESCAPED_UNICODE );

        foreach ( $subs as $sub ) {
            $this->send_single_push(
                (string) $sub['endpoint'],
                (string) $sub['p256dh'],
                (string) $sub['auth'],
                (string) $payload,
                $vapid_pub,
                $vapid_priv
            );
        }
    }

    /**
     * Gửi một Web Push request đến endpoint.
     *
     * Dùng simplified push (không encrypt payload nếu thiếu lib) —
     * gửi request POST với Authorization: VAPID header.
     *
     * Để mã hóa payload đầy đủ (RFC 8291 aesgcm/aes128gcm),
     * cần cài Minishlink/WebPush hoặc tương đương.
     * Ở đây implement VAPID JWT header + plain payload (works với nhiều browsers
     * nhưng không bảo mật end-to-end — dùng cho MVP).
     */
    private function send_single_push(
        string $endpoint, string $p256dh, string $auth,
        string $payload, string $vapid_pub, string $vapid_priv
    ): void {
        if ( $endpoint === '' ) return;

        try {
            // Build VAPID JWT
            $jwt = $this->build_vapid_jwt( $endpoint, $vapid_pub, $vapid_priv );
            if ( ! $jwt ) return;

            $auth_header = 'vapid t=' . $jwt . ',k=' . $vapid_pub;

            $response = wp_remote_post( $endpoint, [
                'timeout' => 10,
                'headers' => [
                    'Authorization'  => $auth_header,
                    'Content-Type'   => 'application/octet-stream',
                    'Content-Length' => strlen( $payload ),
                    'TTL'            => '86400',
                ],
                'body' => $payload,
            ] );

            if ( is_wp_error( $response ) ) {
                error_log( '[LCNI Push] WP_Error: ' . $response->get_error_message() );
            }
        } catch ( \Throwable $e ) {
            error_log( '[LCNI Push] Exception: ' . $e->getMessage() );
        }
    }

    /**
     * Build VAPID JWT (ES256).
     * Requires openssl with prime256v1 support.
     */
    private function build_vapid_jwt( string $endpoint, string $vapid_pub, string $vapid_priv ): ?string {
        if ( ! function_exists('openssl_sign') ) return null;

        $parts = parse_url( $endpoint );
        $audience = ( $parts['scheme'] ?? 'https' ) . '://' . ( $parts['host'] ?? '' );

        $header  = $this->b64url( json_encode(['typ' => 'JWT', 'alg' => 'ES256']) );
        $payload = $this->b64url( json_encode([
            'aud' => $audience,
            'exp' => time() + 43200, // 12h
            'sub' => 'mailto:' . get_option('admin_email'),
        ]) );

        $signing_input = $header . '.' . $payload;

        // Load private key from base64url-encoded raw bytes
        $priv_raw = $this->b64url_decode( $vapid_priv );
        $pub_raw  = $this->b64url_decode( $vapid_pub );

        // Reconstruct EC key from raw bytes using DER encoding
        $priv_key = $this->load_ec_private_key( $priv_raw, $pub_raw );
        if ( ! $priv_key ) return null;

        $signature = '';
        if ( ! openssl_sign( $signing_input, $signature, $priv_key, OPENSSL_ALGO_SHA256 ) ) return null;

        // Convert DER signature to raw r||s (64 bytes)
        $sig_raw = $this->der_to_raw_sig( $signature );
        if ( ! $sig_raw ) return null;

        return $signing_input . '.' . $this->b64url( $sig_raw );
    }

    /** Load EC private key from raw 32-byte values via DER */
    /** @return resource|OpenSSLAsymmetricKey|false */
    private function load_ec_private_key( string $priv_raw, string $pub_raw ) {
        if ( strlen($priv_raw) !== 32 ) return null;

        // SEC1 ECPrivateKey DER for P-256
        // Sequence { version=1, privateKey=32bytes, [0] OID P-256, [1] publicKey }
        $oid_p256  = "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07";
        $priv_str  = "\x04\x20" . $priv_raw; // OCTET STRING
        $oid_wrap  = "\xa0\x0a" . $oid_p256;

        if ( strlen($pub_raw) === 65 ) {
            $pub_bits  = "\x03\x42\x00" . $pub_raw; // BIT STRING
            $pub_wrap  = "\xa1\x44" . $pub_bits;
        } else {
            $pub_wrap  = '';
        }

        $seq_inner = "\x02\x01\x01" . $priv_str . $oid_wrap . $pub_wrap;
        $der = "\x30" . $this->der_length( $seq_inner ) . $seq_inner;

        $pem = "-----BEGIN EC PRIVATE KEY-----\n"
             . chunk_split( base64_encode($der), 64, "\n" )
             . "-----END EC PRIVATE KEY-----\n";

        return openssl_pkey_get_private( $pem );
    }

    /** Convert DER signature (from openssl_sign) to raw 64-byte r||s */
    private function der_to_raw_sig( string $der ): ?string {
        $offset = 0;
        if ( ord($der[$offset++]) !== 0x30 ) return null;
        $offset++; // skip length
        if ( ord($der[$offset++]) !== 0x02 ) return null;
        $r_len = ord($der[$offset++]);
        $r = substr($der, $offset, $r_len); $offset += $r_len;
        if ( ord($der[$offset++]) !== 0x02 ) return null;
        $s_len = ord($der[$offset++]);
        $s = substr($der, $offset, $s_len);

        // Pad/trim to 32 bytes
        $r = str_pad( ltrim($r, "\x00"), 32, "\x00", STR_PAD_LEFT );
        $s = str_pad( ltrim($s, "\x00"), 32, "\x00", STR_PAD_LEFT );
        return substr($r, -32) . substr($s, -32);
    }

    private function der_length( string $data ): string {
        $len = strlen($data);
        if ( $len < 128 ) return chr($len);
        if ( $len < 256 ) return "\x81" . chr($len);
        return "\x82" . chr($len >> 8) . chr($len & 0xFF);
    }

    private function b64url( string $data ): string {
        return rtrim( strtr( base64_encode($data), '+/', '-_' ), '=' );
    }

    private function b64url_decode( string $data ): string {
        return base64_decode( strtr( $data, '-_', '+/' ) . str_repeat('=', (4 - strlen($data) % 4) % 4) );
    }

    // =========================================================================
    // 3. DYNAMIC WATCHLIST SYNC
    // =========================================================================

    /**
     * Add symbol vào tất cả watchlists được liên kết với rule.
     */
    private function sync_symbol_to_watchlists( array $wl_subs, string $symbol ): void {
        if ( ! class_exists('LCNI_WatchlistService') ) return;
        global $lcni_watchlist_service;
        if ( ! ( $lcni_watchlist_service instanceof LCNI_WatchlistService ) ) return;

        foreach ( $wl_subs as $sub ) {
            $user_id    = (int) $sub['user_id'];
            $watchlist_id = (int) $sub['dynamic_watchlist_id'];
            if ( $user_id <= 0 || $watchlist_id <= 0 ) continue;

            // add_symbol idempotent — không thêm trùng
            $lcni_watchlist_service->add_symbol( $user_id, $symbol, $watchlist_id );
        }
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    private function get_signals_page_url(): string {
        global $wpdb;
        $page = $wpdb->get_var(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_status = 'publish' AND post_type = 'page'
               AND post_content LIKE '%lcni_recommend_signals%'
             LIMIT 1"
        );
        return $page ? (string) get_permalink( (int) $page ) : home_url('/');
    }
}
