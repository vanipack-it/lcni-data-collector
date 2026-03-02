<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_Member_Auth_Shortcodes {

    private $service;

    public function __construct(LCNI_SaaS_Service $service) {
        $this->service = $service;
        add_shortcode('lcni_member_login', [$this, 'render_login']);
        add_shortcode('lcni_member_register', [$this, 'render_register']);
        add_action('init', [$this, 'handle_register_submit']);
    }

    public function render_login($atts = []) {
        if (!$this->service->can('member-login', 'view')) {
            return '<p>Bạn không có quyền truy cập module Login.</p>';
        }

        $settings = get_option('lcni_member_login_settings', []);
        $style = $this->container_style($settings);
        $quote = $this->random_quote($settings);

        ob_start();
        echo '<div class="lcni-member-form lcni-member-login" style="' . esc_attr($style) . '">';
        if ($quote !== '') {
            echo '<blockquote>' . esc_html($quote) . '</blockquote>';
        }
        wp_login_form([
            'remember' => !empty($settings['remember_me']),
            'redirect' => !empty($settings['redirect_url']) ? esc_url($settings['redirect_url']) : '',
            'label_username' => $settings['label_username'] ?? __('Username'),
            'label_password' => $settings['label_password'] ?? __('Password'),
            'label_log_in' => $settings['label_button'] ?? __('Log In'),
        ]);
        echo '</div>';

        return ob_get_clean();
    }

    public function render_register($atts = []) {
        if (!$this->service->can('member-register', 'view')) {
            return '<p>Bạn không có quyền truy cập module Register.</p>';
        }

        $settings = get_option('lcni_member_register_settings', []);
        $style = $this->container_style($settings);
        $quote = $this->random_quote($settings);
        $error = isset($_GET['lcni_register_error']) ? sanitize_text_field(wp_unslash($_GET['lcni_register_error'])) : '';

        ob_start();
        echo '<form method="post" class="lcni-member-form lcni-member-register" style="' . esc_attr($style) . '">';
        wp_nonce_field('lcni_member_register_action', 'lcni_member_register_nonce');
        echo '<input type="hidden" name="lcni_member_register_submit" value="1">';
        if ($quote !== '') {
            echo '<blockquote>' . esc_html($quote) . '</blockquote>';
        }
        if ($error !== '') {
            echo '<p style="color:#b91c1c;">' . esc_html($error) . '</p>';
        }
        echo '<p><label>' . esc_html($settings['label_username'] ?? 'Username') . '</label><input type="text" name="username" required></p>';
        echo '<p><label>' . esc_html($settings['label_email'] ?? 'Email') . '</label><input type="email" name="email" required></p>';
        echo '<p><label>' . esc_html($settings['label_password'] ?? 'Password') . '</label><input type="password" name="password" required></p>';
        echo '<p><button type="submit" style="' . esc_attr($settings['button_style'] ?? '') . '">' . esc_html($settings['label_button'] ?? 'Register') . '</button></p>';
        echo '</form>';

        return ob_get_clean();
    }

    public function handle_register_submit() {
        if (empty($_POST['lcni_member_register_submit'])) {
            return;
        }

        if (empty($_POST['lcni_member_register_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['lcni_member_register_nonce'])), 'lcni_member_register_action')) {
            return;
        }

        $username = isset($_POST['username']) ? sanitize_user(wp_unslash($_POST['username'])) : '';
        $email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
        $password = isset($_POST['password']) ? (string) wp_unslash($_POST['password']) : '';

        $error_message = '';
        if ($username === '' || !validate_username($username) || username_exists($username)) {
            $error_message = 'Username không hợp lệ hoặc đã tồn tại.';
        } elseif (!is_email($email) || email_exists($email)) {
            $error_message = 'Email không hợp lệ hoặc đã tồn tại.';
        } elseif (strlen($password) < 6) {
            $error_message = 'Password tối thiểu 6 ký tự.';
        }

        if ($error_message !== '') {
            wp_safe_redirect(add_query_arg('lcni_register_error', rawurlencode($error_message), wp_get_referer()));
            exit;
        }

        $settings = get_option('lcni_member_register_settings', []);
        $role = !empty($settings['default_role']) ? sanitize_key($settings['default_role']) : 'subscriber';

        $user_id = wp_create_user($username, $password, $email);
        if (is_wp_error($user_id)) {
            wp_safe_redirect(add_query_arg('lcni_register_error', rawurlencode($user_id->get_error_message()), wp_get_referer()));
            exit;
        }

        wp_update_user(['ID' => $user_id, 'role' => $role]);

        if (!empty($settings['auto_login'])) {
            wp_set_current_user($user_id);
            wp_set_auth_cookie($user_id, true);
        }

        $redirect = !empty($settings['redirect_url']) ? $settings['redirect_url'] : wp_get_referer();
        wp_safe_redirect($redirect ?: home_url('/'));
        exit;
    }

    private function random_quote($settings) {
        $quotes = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string) ($settings['quote_list'] ?? '')))));
        if (empty($quotes)) {
            return '';
        }

        return $quotes[array_rand($quotes)];
    }

    private function container_style($settings) {
        return sprintf('font-family:%s;color:%s;background:%s;border:%s;border-radius:%dpx;padding:16px;',
            esc_attr($settings['font'] ?? 'inherit'),
            esc_attr($settings['text_color'] ?? '#1f2937'),
            esc_attr($settings['background'] ?? '#ffffff'),
            esc_attr($settings['border'] ?? '1px solid #d1d5db'),
            absint($settings['border_radius'] ?? 8)
        );
    }
}
