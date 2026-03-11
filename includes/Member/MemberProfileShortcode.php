<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_Member_Profile_Shortcode {

    private $service;

    public function __construct(LCNI_SaaS_Service $service) {
        $this->service = $service;
        add_shortcode('lcni_member_profile', [$this, 'render']);
        add_action('init', [$this, 'handle_submit']);
    }

    public function render($atts = []) {
        if (!$this->service->can('member-profile', 'view')) {
            return '<p>Bạn không có quyền truy cập module Profile.</p>';
        }

        if (!is_user_logged_in()) {
            return '<p>Vui lòng đăng nhập để quản lý hồ sơ.</p>';
        }

        $user = wp_get_current_user();
        if (!$user || !$user->exists()) {
            return '<p>Không tìm thấy tài khoản hiện tại.</p>';
        }

        $status = isset($_GET['lcni_profile_status']) ? sanitize_key(wp_unslash($_GET['lcni_profile_status'])) : '';
        $error = isset($_GET['lcni_profile_error']) ? sanitize_text_field(wp_unslash($_GET['lcni_profile_error'])) : '';

        $display_names = array_values(array_unique(array_filter([
            $user->display_name,
            $user->nickname,
            $user->first_name,
            $user->last_name,
            trim($user->first_name . ' ' . $user->last_name),
            $user->user_login,
        ])));

        ob_start();
        echo '<div class="lcni-member-profile-admin">';
        echo '<style>' . esc_html($this->admin_style()) . '</style>';
        echo '<div class="wrap">';
        echo '<h2>Profile</h2>';
        echo '<p class="description">Shortcode: <code>[lcni_member_profile]</code></p>';

        if ($status === 'success') {
            echo '<div class="notice notice-success"><p>Cập nhật hồ sơ thành công.</p></div>';
        }

        if ($error !== '') {
            echo '<div class="notice notice-error"><p>' . esc_html($error) . '</p></div>';
        }

        echo '<form method="post">';
        wp_nonce_field('lcni_member_profile_action', 'lcni_member_profile_nonce');
        echo '<input type="hidden" name="lcni_member_profile_submit" value="1">';
        echo '<table class="form-table" role="presentation"><tbody>';

        echo '<tr><th><label>Tên đăng nhập</label></th><td><input type="text" class="regular-text" value="' . esc_attr($user->user_login) . '" readonly></td></tr>';
        echo '<tr><th><label for="lcni-profile-email">Email</label></th><td><input id="lcni-profile-email" name="email" type="email" class="regular-text" value="' . esc_attr($user->user_email) . '" required></td></tr>';
        echo '<tr><th><label for="lcni-profile-first-name">Tên</label></th><td><input id="lcni-profile-first-name" name="first_name" type="text" class="regular-text" value="' . esc_attr($user->first_name) . '"></td></tr>';
        echo '<tr><th><label for="lcni-profile-last-name">Họ</label></th><td><input id="lcni-profile-last-name" name="last_name" type="text" class="regular-text" value="' . esc_attr($user->last_name) . '"></td></tr>';
        echo '<tr><th><label for="lcni-profile-nickname">Nickname</label></th><td><input id="lcni-profile-nickname" name="nickname" type="text" class="regular-text" value="' . esc_attr($user->nickname) . '"></td></tr>';

        echo '<tr><th><label for="lcni-profile-display-name">Tên hiển thị công khai</label></th><td><select id="lcni-profile-display-name" name="display_name">';
        foreach ($display_names as $display_name) {
            echo '<option value="' . esc_attr($display_name) . '" ' . selected($user->display_name, $display_name, false) . '>' . esc_html($display_name) . '</option>';
        }
        echo '</select></td></tr>';

        echo '<tr><th><label for="lcni-profile-pass-1">Mật khẩu mới</label></th><td><input id="lcni-profile-pass-1" name="pass1" type="password" class="regular-text" autocomplete="new-password"><p class="description">Để trống nếu không đổi mật khẩu.</p></td></tr>';
        echo '<tr><th><label for="lcni-profile-pass-2">Nhập lại mật khẩu</label></th><td><input id="lcni-profile-pass-2" name="pass2" type="password" class="regular-text" autocomplete="new-password"></td></tr>';

        echo '</tbody></table>';
        submit_button('Cập nhật hồ sơ');
        echo '</form>';
        echo '</div>';
        echo '</div>';

        return ob_get_clean();
    }

    public function handle_submit() {
        if (empty($_POST['lcni_member_profile_submit'])) {
            return;
        }

        if (!is_user_logged_in()) {
            return;
        }

        if (!isset($_POST['lcni_member_profile_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['lcni_member_profile_nonce'])), 'lcni_member_profile_action')) {
            return;
        }

        $user = wp_get_current_user();
        if (!$user || !$user->exists()) {
            return;
        }

        $email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
        $first_name = isset($_POST['first_name']) ? sanitize_text_field(wp_unslash($_POST['first_name'])) : '';
        $last_name = isset($_POST['last_name']) ? sanitize_text_field(wp_unslash($_POST['last_name'])) : '';
        $nickname = isset($_POST['nickname']) ? sanitize_text_field(wp_unslash($_POST['nickname'])) : '';
        $display_name = isset($_POST['display_name']) ? sanitize_text_field(wp_unslash($_POST['display_name'])) : '';
        $pass1 = isset($_POST['pass1']) ? (string) wp_unslash($_POST['pass1']) : '';
        $pass2 = isset($_POST['pass2']) ? (string) wp_unslash($_POST['pass2']) : '';

        $error_message = '';

        if (!is_email($email)) {
            $error_message = 'Email không hợp lệ.';
        } elseif (email_exists($email) && strtolower($email) !== strtolower($user->user_email)) {
            $error_message = 'Email đã được sử dụng bởi tài khoản khác.';
        } elseif ($pass1 !== '' && strlen($pass1) < 6) {
            $error_message = 'Mật khẩu mới tối thiểu 6 ký tự.';
        } elseif ($pass1 !== $pass2) {
            $error_message = 'Mật khẩu nhập lại không khớp.';
        }

        $redirect_url = wp_get_referer() ?: home_url('/');

        if ($error_message !== '') {
            wp_safe_redirect(add_query_arg('lcni_profile_error', rawurlencode($error_message), $redirect_url));
            exit;
        }

        $update_args = [
            'ID' => $user->ID,
            'user_email' => $email,
            'first_name' => $first_name,
            'last_name' => $last_name,
            'display_name' => $display_name,
        ];

        if ($pass1 !== '') {
            $update_args['user_pass'] = $pass1;
        }

        $result = wp_update_user($update_args);
        if (is_wp_error($result)) {
            wp_safe_redirect(add_query_arg('lcni_profile_error', rawurlencode($result->get_error_message()), $redirect_url));
            exit;
        }

        update_user_meta($user->ID, 'nickname', $nickname);

        if ($pass1 !== '') {
            wp_set_auth_cookie($user->ID, true);
            wp_set_current_user($user->ID);
        }

        wp_safe_redirect(add_query_arg('lcni_profile_status', 'success', $redirect_url));
        exit;
    }

    private function admin_style() {
        return '.lcni-member-profile-admin .wrap{max-width:860px;margin:20px auto;padding:20px;border:1px solid #ccd0d4;background:#fff;box-sizing:border-box;}'
            . '.lcni-member-profile-admin h2{margin-top:0;}'
            . '.lcni-member-profile-admin .notice{padding:8px 12px;margin:10px 0;border-left:4px solid #72aee6;background:#fff;}'
            . '.lcni-member-profile-admin .notice-success{border-left-color:#00a32a;}'
            . '.lcni-member-profile-admin .notice-error{border-left-color:#d63638;}'
            . '.lcni-member-profile-admin .form-table th{width:220px;}';
    }
}
