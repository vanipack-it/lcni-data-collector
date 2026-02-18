<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_UserMembershipService {

    const META_TIER = 'lcni_membership_tier';

    public function register($email, $password, $display_name, $tier = 'free') {
        if (!is_email($email)) {
            return new WP_Error('invalid_email', 'Email không hợp lệ.', ['status' => 400]);
        }

        if (email_exists($email)) {
            return new WP_Error('email_exists', 'Email đã tồn tại.', ['status' => 409]);
        }

        $user_id = wp_create_user($email, $password, $email);
        if (is_wp_error($user_id)) {
            return $user_id;
        }

        wp_update_user([
            'ID' => $user_id,
            'display_name' => sanitize_text_field($display_name ?: $email),
        ]);

        update_user_meta($user_id, self::META_TIER, $tier === 'premium' ? 'premium' : 'free');

        return $this->get_user_profile($user_id);
    }

    public function login($email, $password, $remember = true) {
        $creds = [
            'user_login' => sanitize_text_field($email),
            'user_password' => (string) $password,
            'remember' => (bool) $remember,
        ];

        $user = wp_signon($creds, is_ssl());
        if (is_wp_error($user)) {
            return new WP_Error('invalid_login', 'Sai email hoặc mật khẩu.', ['status' => 401]);
        }

        return $this->get_user_profile($user->ID);
    }

    public function get_user_profile($user_id) {
        $user = get_userdata($user_id);
        if (!$user) {
            return new WP_Error('user_not_found', 'Không tìm thấy user.', ['status' => 404]);
        }

        return [
            'id' => (int) $user->ID,
            'email' => $user->user_email,
            'display_name' => $user->display_name,
            'tier' => $this->get_tier($user_id),
        ];
    }

    public function get_tier($user_id) {
        $tier = get_user_meta($user_id, self::META_TIER, true);

        return $tier === 'premium' ? 'premium' : 'free';
    }

    public function set_tier($user_id, $tier) {
        $normalized = $tier === 'premium' ? 'premium' : 'free';
        update_user_meta($user_id, self::META_TIER, $normalized);

        return $normalized;
    }
}
