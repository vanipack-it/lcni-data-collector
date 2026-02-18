<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_SignalNotificationService {

    const META_PREFS = 'lcni_signal_notification_preferences';

    private $wpdb;
    private $table;

    public function __construct($wpdb) {
        $this->wpdb = $wpdb;
        $this->table = $wpdb->prefix . 'lcni_signal_notifications';
    }

    public function get_preferences($user_id) {
        $defaults = [
            'channels' => ['in_app'],
            'signals' => ['xay_nen', 'pha_nen', 'tang_gia_kem_vol', 'smart_money', 'rs_exchange_recommend'],
            'auto_add_watchlist' => false,
        ];

        $saved = get_user_meta($user_id, self::META_PREFS, true);
        if (!is_array($saved)) {
            return $defaults;
        }

        return [
            'channels' => $this->sanitize_channels($saved['channels'] ?? $defaults['channels']),
            'signals' => $this->sanitize_signals($saved['signals'] ?? $defaults['signals']),
            'auto_add_watchlist' => !empty($saved['auto_add_watchlist']),
        ];
    }

    public function save_preferences($user_id, $payload) {
        $prefs = [
            'channels' => $this->sanitize_channels($payload['channels'] ?? []),
            'signals' => $this->sanitize_signals($payload['signals'] ?? []),
            'auto_add_watchlist' => !empty($payload['auto_add_watchlist']),
        ];

        if (empty($prefs['channels'])) {
            $prefs['channels'] = ['in_app'];
        }

        if (empty($prefs['signals'])) {
            $prefs['signals'] = ['xay_nen'];
        }

        update_user_meta($user_id, self::META_PREFS, $prefs);

        return $prefs;
    }

    public function list_notifications($user_id, $limit = 30) {
        $limit = max(1, min(100, (int) $limit));
        $sql = $this->wpdb->prepare(
            "SELECT id, symbol, signal_code, channel, title, message, is_read, created_at
             FROM {$this->table}
             WHERE user_id = %d
             ORDER BY created_at DESC
             LIMIT %d",
            $user_id,
            $limit
        );

        return $this->wpdb->get_results($sql, ARRAY_A);
    }

    public function mark_read($user_id, $notification_id) {
        return $this->wpdb->query(
            $this->wpdb->prepare(
                "UPDATE {$this->table}
                SET is_read = 1, read_at = NOW()
                WHERE user_id = %d AND id = %d",
                $user_id,
                $notification_id
            )
        );
    }

    public function unread_count($user_id) {
        return (int) $this->wpdb->get_var($this->wpdb->prepare("SELECT COUNT(*) FROM {$this->table} WHERE user_id = %d AND is_read = 0", $user_id));
    }

    private function sanitize_channels($channels) {
        $allowed = ['email', 'browser', 'in_app'];

        return array_values(array_intersect($allowed, array_map('sanitize_key', (array) $channels)));
    }

    private function sanitize_signals($signals) {
        $allowed = ['xay_nen', 'pha_nen', 'tang_gia_kem_vol', 'smart_money', 'rs_exchange_status', 'rs_exchange_recommend'];

        return array_values(array_intersect($allowed, array_map('sanitize_key', (array) $signals)));
    }
}
