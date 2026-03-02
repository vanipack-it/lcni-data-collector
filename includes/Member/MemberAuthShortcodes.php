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
        add_action('init', [$this, 'handle_login_submit']);
    }

    public function render_login($atts = []) {
        if (!$this->service->can('member-login', 'view')) {
            return '<p>Bạn không có quyền truy cập module Login.</p>';
        }

        $settings = get_option('lcni_member_login_settings', []);
        $quote = $this->random_quote();
        $error = isset($_GET['lcni_login_error']) ? sanitize_text_field(wp_unslash($_GET['lcni_login_error'])) : '';

        ob_start();
        echo '<form method="post" class="lcni-member-form lcni-member-login" style="' . esc_attr($this->container_style($settings)) . '">';
        wp_nonce_field('lcni_member_login_action', 'lcni_member_login_nonce');
        echo '<input type="hidden" name="lcni_member_login_submit" value="1">';
        if ($quote !== '') {
            echo '<blockquote style="' . esc_attr($this->quote_style()) . '">' . esc_html($quote) . '</blockquote>';
        }
        if ($error !== '') {
            echo '<p style="color:#b91c1c;">' . esc_html($error) . '</p>';
        }

        $username_label = $this->setting_text($settings, 'label_username', 'Username');
        $password_label = $this->setting_text($settings, 'label_password', 'Password');
        $button_label = $this->setting_text($settings, 'label_button', 'Submit');
        $button_icon = !empty($settings['button_icon_class']) ? '<i class="' . esc_attr($settings['button_icon_class']) . '"></i> ' : '';

        echo '<p><label>' . esc_html($username_label) . '</label><input style="' . esc_attr($this->input_style($settings)) . '" type="text" name="log" required></p>';
        echo '<p><label>' . esc_html($password_label) . '</label><input style="' . esc_attr($this->input_style($settings)) . '" type="password" name="pwd" required></p>';
        if (!empty($settings['remember_me'])) {
            echo '<p><label><input type="checkbox" name="rememberme" value="1"> ' . esc_html__('Remember Me') . '</label></p>';
        }
        echo '<p><button type="submit" style="' . esc_attr($this->button_style($settings)) . '">' . wp_kses_post($button_icon) . esc_html($button_label) . '</button></p>';
        echo '</form>';

        return ob_get_clean();
    }

    public function render_register($atts = []) {
        if (!$this->service->can('member-register', 'view')) {
            return '<p>Bạn không có quyền truy cập module Register.</p>';
        }

        $settings = get_option('lcni_member_register_settings', []);
        $quote = $this->random_quote();
        $error = isset($_GET['lcni_register_error']) ? sanitize_text_field(wp_unslash($_GET['lcni_register_error'])) : '';

        ob_start();
        echo '<form method="post" class="lcni-member-form lcni-member-register" style="' . esc_attr($this->container_style($settings)) . '">';
        wp_nonce_field('lcni_member_register_action', 'lcni_member_register_nonce');
        echo '<input type="hidden" name="lcni_member_register_submit" value="1">';
        if ($quote !== '') {
            echo '<blockquote style="' . esc_attr($this->quote_style()) . '">' . esc_html($quote) . '</blockquote>';
        }
        if ($error !== '') {
            echo '<p style="color:#b91c1c;">' . esc_html($error) . '</p>';
        }

        $username_label = $this->setting_text($settings, 'label_username', 'Username');
        $email_label = $this->setting_text($settings, 'label_email', 'Email');
        $password_label = $this->setting_text($settings, 'label_password', 'Password');
        $button_label = $this->setting_text($settings, 'label_button', 'Submit');
        $button_icon = !empty($settings['button_icon_class']) ? '<i class="' . esc_attr($settings['button_icon_class']) . '"></i> ' : '';

        echo '<p><label>' . esc_html($username_label) . '</label><input style="' . esc_attr($this->input_style($settings)) . '" type="text" name="username" required></p>';
        echo '<p><label>' . esc_html($email_label) . '</label><input style="' . esc_attr($this->input_style($settings)) . '" type="email" name="email" required></p>';
        echo '<p><label>' . esc_html($password_label) . '</label><input style="' . esc_attr($this->input_style($settings)) . '" type="password" name="password" required></p>';
        echo '<p><button type="submit" style="' . esc_attr($this->button_style($settings)) . '">' . wp_kses_post($button_icon) . esc_html($button_label) . '</button></p>';
        echo '</form>';

        return ob_get_clean();
    }

    public function handle_login_submit() {
        if (empty($_POST['lcni_member_login_submit'])) {
            return;
        }

        if (empty($_POST['lcni_member_login_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['lcni_member_login_nonce'])), 'lcni_member_login_action')) {
            return;
        }

        $credentials = [
            'user_login' => isset($_POST['log']) ? sanitize_user(wp_unslash($_POST['log'])) : '',
            'user_password' => isset($_POST['pwd']) ? (string) wp_unslash($_POST['pwd']) : '',
            'remember' => !empty($_POST['rememberme']),
        ];

        $user = wp_signon($credentials, is_ssl());
        $referer = wp_get_referer() ?: home_url('/');

        if (is_wp_error($user)) {
            wp_safe_redirect(add_query_arg('lcni_login_error', rawurlencode($user->get_error_message()), $referer));
            exit;
        }

        $settings = get_option('lcni_member_login_settings', []);
        $redirect = !empty($settings['redirect_url']) ? $settings['redirect_url'] : $referer;
        wp_safe_redirect($redirect ?: home_url('/'));
        exit;
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

    private function random_quote() {
        $quote_settings = get_option('lcni_member_quote_settings', []);
        $quotes = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string) ($quote_settings['quote_list'] ?? '')))));

        if (!empty($quote_settings['quote_csv_url'])) {
            $response = wp_remote_get(esc_url_raw($quote_settings['quote_csv_url']), ['timeout' => 4]);
            if (!is_wp_error($response)) {
                $content = wp_remote_retrieve_body($response);
                if (is_string($content) && $content !== '') {
                    $rows = preg_split('/\r\n|\r|\n/', $content);
                    foreach ($rows as $row) {
                        $row = trim((string) $row);
                        if ($row !== '') {
                            $quotes[] = $row;
                        }
                    }
                }
            }
        }

        if (empty($quotes)) {
            return '';
        }

        return $quotes[array_rand($quotes)];
    }

    private function setting_text($settings, $key, $default) {
        if (array_key_exists($key, $settings) && $settings[$key] !== '') {
            return (string) $settings[$key];
        }

        return $default;
    }

    private function quote_style() {
        $settings = get_option('lcni_member_quote_settings', []);
        $blur = absint($settings['background_blur'] ?? 0);
        $blur_style = $blur > 0 ? 'backdrop-filter:blur(' . $blur . 'px);' : '';

        return sprintf(
            'width:%dpx;min-height:%dpx;margin:0 auto 16px auto;padding:12px;border-radius:%dpx;border:1px solid %s;background:%s;color:%s;font-size:%dpx;%s',
            max(200, absint($settings['width'] ?? 500)),
            max(60, absint($settings['height'] ?? 120)),
            absint($settings['border_radius'] ?? 12),
            esc_attr($settings['border_color'] ?? '#d1d5db'),
            esc_attr($settings['background'] ?? '#f8fafc'),
            esc_attr($settings['text_color'] ?? '#334155'),
            max(10, absint($settings['font_size'] ?? 16)),
            $blur_style
        );
    }

    private function input_style($settings) {
        return sprintf(
            'height:%dpx;width:%dpx;max-width:100%%;background:%s;border:1px solid %s;color:%s;border-radius:6px;padding:0 10px;',
            max(32, absint($settings['input_height'] ?? 40)),
            max(120, absint($settings['input_width'] ?? 320)),
            esc_attr($settings['input_bg'] ?? '#ffffff'),
            esc_attr($settings['input_border_color'] ?? '#d1d5db'),
            esc_attr($settings['input_text_color'] ?? '#111827')
        );
    }

    private function button_style($settings) {
        return sprintf(
            'height:%dpx;width:%dpx;max-width:100%%;background:%s;border:1px solid %s;color:%s;border-radius:6px;display:inline-flex;align-items:center;justify-content:center;gap:8px;cursor:pointer;',
            max(30, absint($settings['button_height'] ?? 42)),
            max(100, absint($settings['button_width'] ?? 180)),
            esc_attr($settings['button_bg'] ?? '#2563eb'),
            esc_attr($settings['button_border_color'] ?? '#1d4ed8'),
            esc_attr($settings['button_text_color'] ?? '#ffffff')
        );
    }

    private function container_style($settings) {
        $background_color = esc_attr($settings['background'] ?? '#ffffff');
        $background_image = !empty($settings['background_image'])
            ? 'background-image:url(' . esc_url($settings['background_image']) . ');background-size:cover;background-position:center;'
            : '';

        return sprintf(
            'font-family:%s;color:%s;background:%s;%sborder:1px solid %s;border-radius:%dpx;padding:20px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:10px;',
            esc_attr($settings['font'] ?? 'inherit'),
            esc_attr($settings['text_color'] ?? '#1f2937'),
            $background_color,
            $background_image,
            esc_attr($settings['border_color'] ?? '#d1d5db'),
            absint($settings['border_radius'] ?? 8)
        );
    }
}
