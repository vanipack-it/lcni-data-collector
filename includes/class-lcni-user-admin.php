<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_User_Admin {
    public function __construct() {
        add_action('user_new_form', [$this, 'render_user_package_field']);
        add_action('show_user_profile', [$this, 'render_user_package_field']);
        add_action('edit_user_profile', [$this, 'render_user_package_field']);

        add_action('user_register', [$this, 'save_user_package']);
        add_action('personal_options_update', [$this, 'save_user_package']);
        add_action('edit_user_profile_update', [$this, 'save_user_package']);

        add_action('user_register', [$this, 'send_new_account_email'], 20);
        add_action('after_password_reset', [$this, 'send_password_updated_email'], 10, 2);
    }

    public function render_user_package_field($user) {
        if (!current_user_can('create_users') && !current_user_can('edit_users')) {
            return;
        }

        $user_id = (is_object($user) && isset($user->ID)) ? (int) $user->ID : 0;
        $package = $user_id > 0 ? (string) get_user_meta($user_id, 'lcni_user_package', true) : '';
        $package = in_array($package, ['free', 'premium'], true) ? $package : 'free';
        ?>
        <h2>LCNI Membership</h2>
        <table class="form-table" role="presentation">
            <tr>
                <th><label for="lcni_user_package">Gói thành viên</label></th>
                <td>
                    <select name="lcni_user_package" id="lcni_user_package">
                        <option value="free" <?php selected($package, 'free'); ?>>Free</option>
                        <option value="premium" <?php selected($package, 'premium'); ?>>Premium</option>
                    </select>
                    <p class="description">Mặc định user mới sẽ là gói Free.</p>
                </td>
            </tr>
        </table>
        <?php
    }

    public function save_user_package($user_id) {
        if (!current_user_can('edit_user', $user_id) && !current_user_can('create_users')) {
            return;
        }

        if (!isset($_POST['lcni_user_package'])) {
            return;
        }

        $package = sanitize_key(wp_unslash($_POST['lcni_user_package']));
        if (!in_array($package, ['free', 'premium'], true)) {
            $package = 'free';
        }

        update_user_meta($user_id, 'lcni_user_package', $package);
    }

    public function send_new_account_email($user_id) {
        $user = get_userdata($user_id);
        if (!$user || empty($user->user_email)) {
            return;
        }

        $subject = sprintf('[%s] Tài khoản của bạn đã được tạo', wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES));
        $message = sprintf(
            "Xin chào %s,\n\nTài khoản của bạn đã được tạo thành công trên %s.\nBạn có thể đăng nhập tại: %s\n\nNếu bạn chưa đặt mật khẩu, vui lòng dùng tính năng quên mật khẩu để thiết lập mật khẩu mới.",
            $user->display_name ?: $user->user_login,
            home_url('/'),
            wp_login_url()
        );

        wp_mail($user->user_email, $subject, $message);
    }

    public function send_password_updated_email($user) {
        if (!$user instanceof WP_User || empty($user->user_email)) {
            return;
        }

        $subject = sprintf('[%s] Mật khẩu đã được cập nhật', wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES));
        $message = sprintf(
            "Xin chào %s,\n\nMật khẩu tài khoản của bạn vừa được cập nhật thành công.\nNếu bạn không thực hiện thay đổi này, vui lòng liên hệ quản trị viên ngay.",
            $user->display_name ?: $user->user_login
        );

        wp_mail($user->user_email, $subject, $message);
    }
}
