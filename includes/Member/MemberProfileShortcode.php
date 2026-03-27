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

        $settings = get_option('lcni_member_profile_settings', []);
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

        $label_user_login = $this->setting_text($settings, 'label_user_login', 'Tên đăng nhập');
        $label_email = $this->setting_text($settings, 'label_email', 'Email');
        $label_first_name = $this->setting_text($settings, 'label_first_name', 'Tên');
        $label_last_name = $this->setting_text($settings, 'label_last_name', 'Họ');
        $label_nickname = $this->setting_text($settings, 'label_nickname', 'Nickname');
        $label_display_name = $this->setting_text($settings, 'label_display_name', 'Tên hiển thị công khai');
        $label_pass1 = $this->setting_text($settings, 'label_pass1', 'Mật khẩu mới');
        $label_pass2 = $this->setting_text($settings, 'label_pass2', 'Nhập lại mật khẩu');
        $password_hint = $this->setting_text($settings, 'password_hint', 'Để trống nếu không đổi mật khẩu.');
        $button_label = $this->setting_text($settings, 'label_button', 'Cập nhật hồ sơ');
        $button_icon = !empty($settings['button_icon_class']) ? '<i class="' . esc_attr($settings['button_icon_class']) . '"></i> ' : '';

        ob_start();
        echo '<style>' . esc_html($this->component_style($settings)) . '</style>';
        echo '<form method="post" class="lcni-member-form lcni-member-profile" style="' . esc_attr($this->container_style($settings)) . '">';
        echo '<div class="lcni-member-form-box" style="' . esc_attr($this->form_box_style($settings)) . '">';
        echo '<h2 class="lcni-member-profile-title">Profile</h2>';

        if ($status === 'success') {
            echo '<div class="lcni-member-profile-notice lcni-member-profile-notice-success"><p>Cập nhật hồ sơ thành công.</p></div>';
        }

        if ($error !== '') {
            echo '<div class="lcni-member-profile-notice lcni-member-profile-notice-error"><p>' . esc_html($error) . '</p></div>';
        }

        wp_nonce_field('lcni_member_profile_action', 'lcni_member_profile_nonce');
        echo '<input type="hidden" name="lcni_member_profile_submit" value="1">';

        echo '<p style="' . esc_attr($this->field_group_style()) . '"><label style="' . esc_attr($this->label_style($settings)) . '" for="lcni-profile-user-login">' . esc_html($label_user_login) . '</label><input id="lcni-profile-user-login" style="' . esc_attr($this->input_style($settings)) . '" type="text" value="' . esc_attr($user->user_login) . '" readonly></p>';
        echo '<p style="' . esc_attr($this->field_group_style()) . '"><label style="' . esc_attr($this->label_style($settings)) . '" for="lcni-profile-email">' . esc_html($label_email) . '</label><input id="lcni-profile-email" name="email" style="' . esc_attr($this->input_style($settings)) . '" type="email" value="' . esc_attr($user->user_email) . '" required></p>';
        echo '<p style="' . esc_attr($this->field_group_style()) . '"><label style="' . esc_attr($this->label_style($settings)) . '" for="lcni-profile-first-name">' . esc_html($label_first_name) . '</label><input id="lcni-profile-first-name" name="first_name" style="' . esc_attr($this->input_style($settings)) . '" type="text" value="' . esc_attr($user->first_name) . '"></p>';
        echo '<p style="' . esc_attr($this->field_group_style()) . '"><label style="' . esc_attr($this->label_style($settings)) . '" for="lcni-profile-last-name">' . esc_html($label_last_name) . '</label><input id="lcni-profile-last-name" name="last_name" style="' . esc_attr($this->input_style($settings)) . '" type="text" value="' . esc_attr($user->last_name) . '"></p>';
        echo '<p style="' . esc_attr($this->field_group_style()) . '"><label style="' . esc_attr($this->label_style($settings)) . '" for="lcni-profile-nickname">' . esc_html($label_nickname) . '</label><input id="lcni-profile-nickname" name="nickname" style="' . esc_attr($this->input_style($settings)) . '" type="text" value="' . esc_attr($user->nickname) . '"></p>';

        echo '<p style="' . esc_attr($this->field_group_style()) . '"><label style="' . esc_attr($this->label_style($settings)) . '" for="lcni-profile-display-name">' . esc_html($label_display_name) . '</label><select id="lcni-profile-display-name" name="display_name" style="' . esc_attr($this->input_style($settings)) . '">';
        foreach ($display_names as $display_name) {
            echo '<option value="' . esc_attr($display_name) . '" ' . selected($user->display_name, $display_name, false) . '>' . esc_html($display_name) . '</option>';
        }
        echo '</select></p>';

        echo '<p style="' . esc_attr($this->field_group_style()) . '"><label style="' . esc_attr($this->label_style($settings)) . '" for="lcni-profile-pass-1">' . esc_html($label_pass1) . '</label><input id="lcni-profile-pass-1" name="pass1" style="' . esc_attr($this->input_style($settings)) . '" type="password" autocomplete="new-password"><small class="lcni-member-profile-hint">' . esc_html($password_hint) . '</small></p>';
        echo '<p style="' . esc_attr($this->field_group_style()) . '"><label style="' . esc_attr($this->label_style($settings)) . '" for="lcni-profile-pass-2">' . esc_html($label_pass2) . '</label><input id="lcni-profile-pass-2" name="pass2" style="' . esc_attr($this->input_style($settings)) . '" type="password" autocomplete="new-password"></p>';

        echo '<button type="submit" style="' . esc_attr($this->button_style($settings)) . '">' . wp_kses_post($button_icon) . esc_html($button_label) . '</button>';
        echo '</div>';
        echo '</form>';

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

    private function setting_text($settings, $key, $default) {
        if (array_key_exists($key, $settings) && $settings[$key] !== '') {
            return (string) $settings[$key];
        }

        return $default;
    }

    private function input_style($settings) {
        return sprintf(
            'height:%dpx;width:%dpx;max-width:100%%;background:%s;border:1px solid %s;color:%s;border-radius:6px;padding:0 10px;box-sizing:border-box;display:block;text-align:left;margin:0 auto;',
            max(32, absint($settings['input_height'] ?? 40)),
            max(120, absint($settings['input_width'] ?? 320)),
            esc_attr($settings['input_bg'] ?? '#ffffff'),
            esc_attr($settings['input_border_color'] ?? '#d1d5db'),
            esc_attr($settings['input_text_color'] ?? '#111827')
        );
    }

    private function field_group_style() {
        return 'margin:0;display:flex;flex-direction:column;gap:6px;align-items:center;';
    }

    private function label_style($settings) {
        return sprintf('text-align:left;width:%dpx;max-width:100%%;', max(120, absint($settings['input_width'] ?? 320)));
    }

    private function button_style($settings) {
        return sprintf(
            'height:%dpx;width:%dpx;max-width:100%%;background:%s;border:1px solid %s;color:%s;border-radius:6px;display:inline-flex;align-items:center;justify-content:center;gap:8px;cursor:pointer;box-sizing:border-box;',
            max(30, absint($settings['button_height'] ?? 42)),
            max(120, absint($settings['button_width'] ?? 180)),
            esc_attr($settings['button_bg'] ?? '#2563eb'),
            esc_attr($settings['button_border_color'] ?? '#1d4ed8'),
            esc_attr($settings['button_text_color'] ?? '#ffffff')
        );
    }

    private function form_box_style($settings) {
        return sprintf(
            'width:100%%;max-width:520px;background:%s;border:1px solid %s;border-radius:%dpx;padding:16px;display:flex;flex-direction:column;gap:12px;box-sizing:border-box;margin:0 auto;',
            esc_attr($settings['form_box_background'] ?? '#ffffff'),
            esc_attr($settings['form_box_border_color'] ?? '#d1d5db'),
            absint($settings['form_box_border_radius'] ?? 10)
        );
    }

    private function container_style($settings) {
        $background_color = esc_attr($settings['background'] ?? '#ffffff');
        $background_image = !empty($settings['background_image'])
            ? 'background-image:url(' . esc_url($settings['background_image']) . ');background-size:cover;background-position:center;'
            : '';

        return sprintf(
            'font-family:%s;color:%s;background:%s;%sborder:1px solid %s;border-radius:%dpx;padding:20px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:10px;width:100%%;box-sizing:border-box;margin:0 auto;',
            esc_attr($settings['font'] ?? 'inherit'),
            esc_attr($settings['text_color'] ?? '#1f2937'),
            $background_color,
            $background_image,
            esc_attr($settings['border_color'] ?? '#d1d5db'),
            absint($settings['border_radius'] ?? 8)
        );
    }

    private function component_style($settings) {
        $focus_border = esc_attr($settings['input_focus_border_color'] ?? '#2563eb');
        $focus_shadow = !empty($settings['input_focus_shadow'])
            ? 'box-shadow:0 0 0 3px rgba(37,99,235,0.18);'
            : 'box-shadow:none;';

        return '.lcni-member-profile .lcni-member-profile-title{margin:0;text-align:left;}'
            . '.lcni-member-profile .lcni-member-profile-notice{padding:8px 12px;border-radius:6px;}'
            . '.lcni-member-profile .lcni-member-profile-notice-success{background:#ecfdf3;color:#166534;border:1px solid #86efac;}'
            . '.lcni-member-profile .lcni-member-profile-notice-error{background:#fef2f2;color:#991b1b;border:1px solid #fecaca;}'
            . '.lcni-member-profile .lcni-member-profile-hint{display:block;margin:2px auto 0;width:' . max(120, absint($settings['input_width'] ?? 320)) . 'px;max-width:100%;text-align:left;color:#6b7280;}'
            . '.lcni-member-profile input:focus,.lcni-member-profile select:focus{border-color:' . $focus_border . ';outline:none;' . $focus_shadow . '}';
    }
}
