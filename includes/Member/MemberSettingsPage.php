<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_Member_Settings_Page {

    private $service;

    public function __construct(LCNI_SaaS_Service $service) {
        $this->service = $service;
        add_action('admin_menu',           [$this, 'add_menu']);
        add_action('admin_init',           [$this, 'register_settings']);
        add_action('admin_head',           [$this, 'admin_styles']);
        add_action('admin_post_lcni_saas', [$this, 'handle_saas_post']);
        add_action('wp_ajax_lcni_user_search', [$this, 'ajax_user_search']);
    }

    public function add_menu() {
        add_submenu_page('lcni-settings', 'Member', 'Member', 'manage_options', 'lcni-member-settings', [$this, 'render']);
    }

    public function admin_styles() {
        $screen = get_current_screen();
        if (!$screen || strpos($screen->id, 'lcni-member-settings') === false) {
            return;
        }
        echo '<style>
        /* === LCNI SaaS Admin UI === */
        .lcni-saas-wrap { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
        .lcni-saas-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 16px; }
        .lcni-saas-grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; margin-top: 16px; }
        .lcni-saas-card {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 1px 4px rgba(0,0,0,.06);
        }
        .lcni-saas-card h3 {
            margin: 0 0 16px;
            font-size: 14px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .05em;
            color: #6b7280;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .lcni-saas-card h3 .dashicons { font-size: 16px; width: 16px; height: 16px; }
        .lcni-field { margin-bottom: 12px; }
        .lcni-field label { display: block; font-size: 12px; font-weight: 600; color: #374151; margin-bottom: 4px; }
        .lcni-field input[type=text],
        .lcni-field input[type=number],
        .lcni-field input[type=date],
        .lcni-field textarea,
        .lcni-field select {
            width: 100%;
            padding: 8px 10px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 13px;
            color: #111827;
            background: #f9fafb;
            box-sizing: border-box;
            transition: border-color .2s, box-shadow .2s;
        }
        .lcni-field input:focus, .lcni-field select:focus, .lcni-field textarea:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37,99,235,.12);
            outline: none;
            background: #fff;
        }
        .lcni-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            border: none;
            transition: opacity .15s, transform .1s;
        }
        .lcni-btn:hover { opacity: .88; transform: translateY(-1px); }
        .lcni-btn:active { transform: translateY(0); }
        .lcni-btn-primary { background: #2563eb; color: #fff; }
        .lcni-btn-danger  { background: #dc2626; color: #fff; }
        .lcni-btn-ghost   { background: #f3f4f6; color: #374151; border: 1px solid #d1d5db; }
        .lcni-btn-sm      { padding: 5px 10px; font-size: 12px; }

        /* Package cards */
        .lcni-pkg-list { display: flex; flex-direction: column; gap: 10px; }
        .lcni-pkg-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 14px;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            background: #fafafa;
            transition: box-shadow .15s;
        }
        .lcni-pkg-item:hover { box-shadow: 0 2px 8px rgba(0,0,0,.08); }
        .lcni-pkg-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
        .lcni-pkg-name { font-weight: 600; font-size: 13px; color: #111827; flex: 1; }
        .lcni-pkg-desc { font-size: 12px; color: #6b7280; }
        .lcni-pkg-badge {
            display: inline-flex;
            align-items: center;
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            background: #e0e7ff;
            color: #3730a3;
        }
        .lcni-pkg-badge.inactive { background: #fee2e2; color: #991b1b; }

        /* Permission matrix */
        .lcni-perm-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .lcni-perm-table th {
            text-align: left;
            padding: 8px 10px;
            background: #f3f4f6;
            border-bottom: 1px solid #e5e7eb;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: .05em;
            color: #6b7280;
        }
        .lcni-perm-table td { padding: 8px 10px; border-bottom: 1px solid #f3f4f6; vertical-align: middle; }
        .lcni-perm-table tr:last-child td { border-bottom: none; }
        .lcni-perm-table input[type=checkbox] { width: 16px; height: 16px; accent-color: #2563eb; cursor: pointer; }
        .lcni-perm-check { text-align: center; }

        /* Assignments table */
        .lcni-assign-table { width: 100%; border-collapse: collapse; font-size: 13px; margin-top: 8px; }
        .lcni-assign-table th {
            text-align: left;
            padding: 8px 10px;
            background: #f8fafc;
            border-bottom: 2px solid #e5e7eb;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: .04em;
            color: #6b7280;
            font-weight: 700;
        }
        .lcni-assign-table td { padding: 9px 10px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
        .lcni-assign-table tr:hover td { background: #f8fafc; }
        .lcni-assign-table tr:last-child td { border-bottom: none; }
        .lcni-color-swatch { width: 10px; height: 10px; border-radius: 50%; display: inline-block; margin-right: 6px; vertical-align: middle; }
        .lcni-expiry { font-size: 11px; color: #6b7280; }
        .lcni-expiry.expired { color: #dc2626; font-weight: 600; }

        /* Tabs */
        .lcni-saas-tabs { display: flex; gap: 4px; border-bottom: 2px solid #e5e7eb; margin-bottom: 20px; }
        .lcni-saas-tab {
            padding: 8px 16px;
            font-size: 13px;
            font-weight: 600;
            color: #6b7280;
            cursor: pointer;
            border-bottom: 2px solid transparent;
            margin-bottom: -2px;
            transition: color .15s, border-color .15s;
            text-decoration: none;
        }
        .lcni-saas-tab:hover { color: #2563eb; }
        .lcni-saas-tab.active { color: #2563eb; border-bottom-color: #2563eb; }

        .lcni-notice { padding: 10px 14px; border-radius: 8px; font-size: 13px; margin-bottom: 16px; }
        .lcni-notice-success { background: #ecfdf5; color: #065f46; border: 1px solid #6ee7b7; }
        .lcni-notice-error   { background: #fef2f2; color: #7f1d1d; border: 1px solid #fca5a5; }
        .lcni-section-title { font-size: 13px; font-weight: 700; color: #374151; margin: 0 0 12px; padding: 0; border: none; }
        .lcni-shortcode-box {
            background: #1e293b;
            color: #7dd3fc;
            font-family: monospace;
            font-size: 13px;
            padding: 10px 14px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-top: 6px;
        }
        .lcni-copy-btn {
            background: #334155;
            color: #e2e8f0;
            border: none;
            border-radius: 6px;
            padding: 4px 10px;
            font-size: 11px;
            cursor: pointer;
            white-space: nowrap;
        }
        .lcni-copy-btn:hover { background: #475569; }
        </style>';
        echo <<<'LCNI_JS'
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            var iconInput = document.querySelector('input[name="package_badge_icon"]');
            var preview   = document.getElementById('lcni-badge-icon-preview');
            if (!iconInput || !preview) return;
            iconInput.addEventListener('input', function() {
                var val = this.value.trim();
                if (!val) { preview.innerHTML = ''; return; }
                if (val.indexOf('dashicons') !== -1) {
                    preview.innerHTML = '<span class="dashicons ' + val + '" style="font-size:18px;width:18px;height:18px;color:#374151;"></span>';
                } else {
                    preview.innerHTML = '<i class="' + val + '" style="color:#374151;"></i>';
                }
            });
        });
        </script>
LCNI_JS;
    }

    public function ajax_user_search() {
        check_ajax_referer('lcni_user_search', '_nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error([], 403);
        }

        $q = isset($_GET['q']) ? sanitize_text_field(wp_unslash($_GET['q'])) : '';
        if (strlen($q) < 2) {
            wp_send_json_success([]);
        }

        $users = get_users([
            'search'         => '*' . $q . '*',
            'search_columns' => ['user_login', 'user_email', 'display_name'],
            'number'         => 20,
            'orderby'        => 'display_name',
            'fields'         => ['ID', 'display_name', 'user_email', 'user_login'],
        ]);

        $result = [];
        foreach ($users as $u) {
            $user_obj = get_userdata($u->ID);
            $role     = $user_obj && !empty($user_obj->roles) ? implode(', ', $user_obj->roles) : '—';
            $result[] = [
                'ID'           => $u->ID,
                'display_name' => $u->display_name ?: $u->user_login,
                'user_email'   => $u->user_email,
                'role'         => $role,
            ];
        }

        wp_send_json_success($result);
    }

    public function register_settings() {
        register_setting('lcni_member_settings', 'lcni_member_login_settings',   ['type' => 'array', 'sanitize_callback' => [$this, 'sanitize_login_settings'],   'default' => []]);
        register_setting('lcni_member_settings', 'lcni_member_register_settings', ['type' => 'array', 'sanitize_callback' => [$this, 'sanitize_register_settings'], 'default' => []]);
        register_setting('lcni_member_settings', 'lcni_member_profile_settings',  ['type' => 'array', 'sanitize_callback' => [$this, 'sanitize_profile_settings'],  'default' => []]);
        register_setting('lcni_member_settings', 'lcni_member_quote_settings',    ['type' => 'array', 'sanitize_callback' => [$this, 'sanitize_quote_settings'],    'default' => []]);
        // Google OAuth
        register_setting('lcni_member_settings', 'lcni_google_client_id', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ]);
    }

    /**
     * Xử lý tất cả form POST của tab SaaS (tạo/sửa/xoá gói, gán/thu hồi, phân quyền).
     * Hook: admin_post_lcni_saas
     */
    public function handle_saas_post() {
        check_admin_referer('lcni_member_saas_action');

        $redirect_base = admin_url('admin.php?page=lcni-member-settings&tab=saas');
        $saas_tab = isset($_POST['_saas_tab']) ? sanitize_key(wp_unslash($_POST['_saas_tab'])) : 'packages';

        // Save default packages per role
        if (!empty($_POST['lcni_member_save_default_packages'])) {
            $pkgs = isset($_POST['lcni_saas_default_packages']) ? (array) wp_unslash($_POST['lcni_saas_default_packages']) : [];
            $durs = isset($_POST['lcni_saas_default_durations']) ? (array) wp_unslash($_POST['lcni_saas_default_durations']) : [];
            $clean_pkgs = [];
            $clean_durs = [];
            foreach ($pkgs as $role => $pkg_id) {
                // Bảo toàn key '*' (fallback mọi role), sanitize_key sẽ strip nó thành ''
                $role_key = ($role === '*') ? '*' : sanitize_key($role);
                if ($role_key === '') continue;
                $clean_pkgs[$role_key] = absint($pkg_id);
            }
            foreach ($durs as $role => $days) {
                $role_key = ($role === '*') ? '*' : sanitize_key($role);
                if ($role_key === '') continue;
                $clean_durs[$role_key] = absint($days);
            }
            update_option('lcni_saas_default_packages',  $clean_pkgs);
            update_option('lcni_saas_default_durations', $clean_durs);
            wp_safe_redirect(add_query_arg(['saas_tab' => 'packages', 'lcni_saved' => 'default_packages'], $redirect_base) . '#lcni-default-packages');
            exit;
        }

        // Save central URLs
        if (!empty($_POST['lcni_member_save_central_urls'])) {
            update_option('lcni_central_login_url',    esc_url_raw(wp_unslash($_POST['lcni_central_login_url']    ?? '')));
            update_option('lcni_central_register_url', esc_url_raw(wp_unslash($_POST['lcni_central_register_url'] ?? '')));
            update_option('lcni_saas_upgrade_url',     esc_url_raw(wp_unslash($_POST['lcni_saas_upgrade_url']     ?? '')));
            wp_safe_redirect(add_query_arg('saas_tab', 'packages', $redirect_base));
            exit;
        }

        // Create package
        if (!empty($_POST['lcni_member_create_package'])) {
            $this->service->create_package(
                isset($_POST['package_name'])        ? wp_unslash($_POST['package_name'])        : '',
                isset($_POST['package_description']) ? wp_unslash($_POST['package_description']) : '',
                isset($_POST['package_color'])       ? sanitize_hex_color(wp_unslash($_POST['package_color'])) : '#2563eb',
                isset($_POST['package_badge_icon'])  ? wp_unslash($_POST['package_badge_icon'])  : '',
                isset($_POST['package_badge_label']) ? wp_unslash($_POST['package_badge_label']) : ''
            );
            wp_safe_redirect(add_query_arg('saas_tab', 'packages', $redirect_base));
            exit;
        }

        // Update package
        if (!empty($_POST['lcni_member_update_package'])) {
            $this->service->update_package(
                absint($_POST['edit_package_id'] ?? 0),
                isset($_POST['package_name'])        ? wp_unslash($_POST['package_name'])        : '',
                isset($_POST['package_description']) ? wp_unslash($_POST['package_description']) : '',
                isset($_POST['package_color'])       ? sanitize_hex_color(wp_unslash($_POST['package_color'])) : '#2563eb',
                !empty($_POST['package_is_active']) ? 1 : 0,
                isset($_POST['package_badge_icon'])  ? wp_unslash($_POST['package_badge_icon'])  : '',
                isset($_POST['package_badge_label']) ? wp_unslash($_POST['package_badge_label']) : ''
            );
            wp_safe_redirect(add_query_arg('saas_tab', 'packages', $redirect_base));
            exit;
        }

        // Delete package
        if (!empty($_POST['lcni_member_delete_package'])) {
            $this->service->delete_package(absint($_POST['delete_package_id'] ?? 0));
            wp_safe_redirect(add_query_arg('saas_tab', 'packages', $redirect_base));
            exit;
        }

        // Assign package
        if (!empty($_POST['lcni_member_assign_package'])) {
            $expires_raw = isset($_POST['expires_at']) ? sanitize_text_field(wp_unslash($_POST['expires_at'])) : '';
            $this->service->assign_package(
                absint($_POST['user_id']    ?? 0),
                isset($_POST['role_slug'])  ? sanitize_key(wp_unslash($_POST['role_slug']))  : '',
                absint($_POST['package_id'] ?? 0),
                $expires_raw !== '' ? $expires_raw : null,
                isset($_POST['assign_note']) ? sanitize_text_field(wp_unslash($_POST['assign_note'])) : ''
            );
            wp_safe_redirect(add_query_arg('saas_tab', 'assign', $redirect_base));
            exit;
        }

        // Revoke package
        if (!empty($_POST['lcni_member_revoke_package'])) {
            $this->service->revoke_package(
                absint($_POST['revoke_user_id']  ?? 0),
                isset($_POST['revoke_role_slug']) ? sanitize_key(wp_unslash($_POST['revoke_role_slug'])) : ''
            );
            wp_safe_redirect(add_query_arg('saas_tab', 'assign', $redirect_base));
            exit;
        }

        // Save permissions
        if (!empty($_POST['lcni_member_save_permissions'])) {
            $package_id  = absint($_POST['permission_package_id'] ?? 0);
            $permissions = isset($_POST['permissions']) ? (array) wp_unslash($_POST['permissions']) : [];
            $this->service->save_permissions($package_id, $permissions);
            wp_safe_redirect(add_query_arg(['saas_tab' => 'permissions', 'perm_pkg' => $package_id], $redirect_base));
            exit;
        }

        wp_safe_redirect(add_query_arg('saas_tab', $saas_tab, $redirect_base));
        exit;
    }

    // =================== Sanitizers ===================

    public function sanitize_login_settings($input) {
        return $this->sanitize_common_settings($input, ['remember_me'], 'lcni_member_login_bg_image_file');
    }

    public function sanitize_register_settings($input) {
        $sanitized = $this->sanitize_common_settings($input, ['auto_login'], 'lcni_member_register_bg_image_file');
        $sanitized['default_role'] = sanitize_key($input['default_role'] ?? 'subscriber');
        return $sanitized;
    }

    public function sanitize_profile_settings($input) {
        $sanitized = $this->sanitize_common_settings($input, [], 'lcni_member_profile_bg_image_file', 'lcni_member_profile_settings');
        foreach (['label_user_login','label_email','label_first_name','label_last_name','label_nickname','label_display_name','label_pass1','label_pass2','password_hint'] as $k) {
            $sanitized[$k] = sanitize_text_field($input[$k] ?? '');
        }
        $sanitized['input_focus_border_color'] = sanitize_hex_color($input['input_focus_border_color'] ?? '#2563eb');
        $sanitized['input_focus_shadow']       = !empty($input['input_focus_shadow']) ? 1 : 0;
        return $sanitized;
    }

    public function sanitize_quote_settings($input) {
        $input   = is_array($input) ? $input : [];
        $current = get_option('lcni_member_quote_settings', []);
        if (is_array($current)) { $input = wp_parse_args($input, $current); }
        $quote_csv_url = $this->handle_uploaded_file('lcni_member_quote_csv_file', ['csv' => 'text/csv', 'txt' => 'text/plain'], (string) ($input['quote_csv_url'] ?? ''));
        return [
            'width'          => max(200, absint($input['width'] ?? 500)),
            'height'         => max(60,  absint($input['height'] ?? 120)),
            'border_radius'  => absint($input['border_radius'] ?? 12),
            'background'     => sanitize_hex_color($input['background'] ?? '#f8fafc'),
            'background_blur'=> absint($input['background_blur'] ?? 0),
            'border_color'   => sanitize_hex_color($input['border_color'] ?? '#d1d5db'),
            'text_color'     => sanitize_hex_color($input['text_color'] ?? '#334155'),
            'font_size'      => max(10, absint($input['font_size'] ?? 16)),
            'font_family'    => sanitize_text_field($input['font_family'] ?? 'inherit'),
            'text_align'     => in_array(($input['text_align'] ?? 'left'), ['left','center','right'], true) ? $input['text_align'] : 'left',
            'effect'         => in_array(($input['effect'] ?? 'normal'), ['normal','italic','bold','uppercase','shadow'], true) ? $input['effect'] : 'normal',
            'preview_text'   => sanitize_text_field($input['preview_text'] ?? 'Market quote preview'),
            'quote_list'     => sanitize_textarea_field($input['quote_list'] ?? ''),
            'quote_csv_url'  => esc_url_raw($quote_csv_url),
        ];
    }

    private function sanitize_common_settings($input, $bool_keys, $background_upload_field, $option_key = '') {
        $input = is_array($input) ? $input : [];
        if ($option_key === '') {
            $option_key = in_array('remember_me', $bool_keys, true) ? 'lcni_member_login_settings' : 'lcni_member_register_settings';
        }
        $current = get_option($option_key, []);
        if (is_array($current)) { $input = wp_parse_args($input, $current); }
        $background_image = $this->handle_uploaded_file($background_upload_field, ['jpg|jpeg|jpe' => 'image/jpeg','gif' => 'image/gif','png' => 'image/png','webp' => 'image/webp'], (string) ($input['background_image'] ?? ''));
        $sanitized = [
            'font'                   => sanitize_text_field($input['font'] ?? ''),
            'text_color'             => sanitize_hex_color($input['text_color']             ?? '#1f2937'),
            'background'             => sanitize_hex_color($input['background']             ?? '#ffffff'),
            'background_image'       => esc_url_raw($background_image),
            'border_color'           => sanitize_hex_color($input['border_color']           ?? '#d1d5db'),
            'border_radius'          => absint($input['border_radius'] ?? 8),
            'form_box_background'    => sanitize_hex_color($input['form_box_background']    ?? '#ffffff'),
            'form_box_border_color'  => sanitize_hex_color($input['form_box_border_color']  ?? '#d1d5db'),
            'form_box_border_radius' => absint($input['form_box_border_radius'] ?? 10),
            'input_height'           => max(32,  absint($input['input_height'] ?? 40)),
            'input_width'            => max(120, absint($input['input_width']  ?? 320)),
            'input_bg'               => sanitize_hex_color($input['input_bg']               ?? '#ffffff'),
            'input_border_color'     => sanitize_hex_color($input['input_border_color']     ?? '#d1d5db'),
            'input_text_color'       => sanitize_hex_color($input['input_text_color']       ?? '#111827'),
            'button_height'          => max(30,  absint($input['button_height'] ?? 42)),
            'button_width'           => max(100, absint($input['button_width']  ?? 180)),
            'button_bg'              => sanitize_hex_color($input['button_bg']              ?? '#2563eb'),
            'button_border_color'    => sanitize_hex_color($input['button_border_color']    ?? '#1d4ed8'),
            'button_text_color'      => sanitize_hex_color($input['button_text_color']      ?? '#ffffff'),
            'button_icon_class'      => sanitize_text_field($input['button_icon_class']     ?? 'fa-solid fa-right-to-bracket'),
            'redirect_url'           => esc_url_raw($input['redirect_url']                  ?? ''),
            'label_username'         => sanitize_text_field($input['label_username']        ?? 'Username'),
            'label_email'            => sanitize_text_field($input['label_email']           ?? 'Email'),
            'label_password'         => sanitize_text_field($input['label_password']        ?? 'Password'),
            'label_button'           => sanitize_text_field($input['label_button']          ?? 'Submit'),
            'register_button_label'  => sanitize_text_field($input['register_button_label'] ?? 'Đăng ký'),
            'register_page_id'       => absint($input['register_page_id'] ?? 0),
        ];
        foreach ($bool_keys as $key) { $sanitized[$key] = !empty($input[$key]) ? 1 : 0; }
        return $sanitized;
    }

    private function handle_uploaded_file($field_name, $mimes, $fallback_url) {
        if (empty($_FILES[$field_name]['tmp_name'])) { return (string) $fallback_url; }
        if (!function_exists('wp_handle_upload')) { require_once ABSPATH . 'wp-admin/includes/file.php'; }
        $uploaded = wp_handle_upload($_FILES[$field_name], ['test_form' => false, 'mimes' => $mimes]);
        return is_array($uploaded) && !empty($uploaded['url']) ? (string) $uploaded['url'] : (string) $fallback_url;
    }

    // =================== Render ===================

    public function render() {
        $tab      = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'login';
        $packages = $this->service->get_package_options();
        ?>
        <div class="wrap lcni-saas-wrap">
            <h1 style="font-size:20px;font-weight:700;margin-bottom:4px;">⚙️ LCNI Member Settings</h1>
            <p style="color:#6b7280;margin-top:0;margin-bottom:16px;font-size:13px;">Quản lý giao diện form, gói SaaS và phân quyền thành viên.</p>

            <h2 class="nav-tab-wrapper" style="border-bottom:2px solid #e5e7eb;margin-bottom:20px;">
                <?php foreach ([
                    'login'    => '🔑 Login',
                    'register' => '📝 Register',
                    'profile'  => '👤 Profile',
                    'quote'    => '💬 Quote',
                    'saas'     => '🎁 Gói SaaS',
                    'google'   => '🔵 Google Login',
                ] as $slug => $label): ?>
                <a href="<?php echo esc_url(admin_url('admin.php?page=lcni-member-settings&tab=' . $slug)); ?>"
                   class="nav-tab <?php echo $tab === $slug ? 'nav-tab-active' : ''; ?>">
                   <?php echo esc_html($label); ?>
                </a>
                <?php endforeach; ?>
            </h2>

            <?php if ($tab === 'saas'): ?>
                <?php $this->render_saas_tab($packages); ?>
            <?php elseif ($tab === 'quote'): ?>
                <?php $this->render_quote_tab(); ?>
            <?php elseif ($tab === 'google'): ?>
                <?php $this->render_google_tab(); ?>
            <?php else: ?>
                <?php $this->render_form_tab($tab); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    // =================== SaaS Tab ===================

    private function render_saas_tab($packages) {
        $saas_tab    = isset($_GET['saas_tab']) ? sanitize_key(wp_unslash($_GET['saas_tab'])) : 'packages';
        $assignments = $this->service->get_all_user_assignments();
        $packages    = $this->service->get_packages(); // re-fetch sau migration
        $edit_pkg_id = isset($_GET['edit_pkg']) ? absint($_GET['edit_pkg']) : 0;
        $edit_pkg    = $edit_pkg_id ? $this->service->get_package_by_id($edit_pkg_id) : null;

        // Permissions tab state
        $perm_pkg_id  = isset($_GET['perm_pkg']) ? absint($_GET['perm_pkg']) : ($packages[0]['id'] ?? 0);
        $perm_pkg     = $perm_pkg_id ? $this->service->get_package_by_id($perm_pkg_id) : null;
        $perm_current = $perm_pkg_id ? $this->service->get_permissions($perm_pkg_id) : [];
        $perm_map     = [];
        foreach ($perm_current as $p) { $perm_map[$p['module_key']] = $p; }

        $modules = $this->service->get_module_list();
        ?>

        <!-- Central URL config -->
        <div class="lcni-saas-card" style="margin-bottom:20px;">
            <h3><span class="dashicons dashicons-admin-links"></span> URL tập trung (dùng cho thông báo thiếu quyền)</h3>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('lcni_member_saas_action'); ?>
                <input type="hidden" name="action" value="lcni_saas">
                <input type="hidden" name="_saas_tab" value="packages">
                <input type="hidden" name="lcni_member_save_central_urls" value="1">
                <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;">
                    <div class="lcni-field">
                        <label>🔑 URL trang Đăng nhập</label>
                        <input type="text" name="lcni_central_login_url"
                               value="<?php echo esc_attr(get_option('lcni_central_login_url', '')); ?>"
                               placeholder="https://...">
                        <p style="font-size:11px;color:#9ca3af;margin:4px 0 0;">Hiển thị nút Login khi user chưa đăng nhập</p>
                    </div>
                    <div class="lcni-field">
                        <label>📝 URL trang Đăng ký</label>
                        <input type="text" name="lcni_central_register_url"
                               value="<?php echo esc_attr(get_option('lcni_central_register_url', '')); ?>"
                               placeholder="https://...">
                        <p style="font-size:11px;color:#9ca3af;margin:4px 0 0;">Hiển thị nút Đăng ký khi user chưa đăng nhập</p>
                    </div>
                    <div class="lcni-field">
                        <label>⭐ URL trang Nâng cấp gói</label>
                        <input type="text" name="lcni_saas_upgrade_url"
                               value="<?php echo esc_attr(get_option('lcni_saas_upgrade_url', '')); ?>"
                               placeholder="https://...">
                        <p style="font-size:11px;color:#9ca3af;margin:4px 0 0;">Hiển thị nút Nâng cấp / Gia hạn khi user thiếu quyền</p>
                    </div>
                </div>
                <button class="lcni-btn lcni-btn-primary" style="margin-top:8px;" type="submit">💾 Lưu URL</button>
            </form>
        </div>

        <!-- Default package config -->
        <?php
        $all_roles       = wp_roles()->get_names();
        $default_pkgs    = get_option('lcni_saas_default_packages',  []);
        $default_durs    = get_option('lcni_saas_default_durations',  []);
        ?>
        <div class="lcni-saas-card" style="margin-bottom:20px;" id="lcni-default-packages">
            <h3><span class="dashicons dashicons-welcome-add-page"></span> Gói mặc định khi đăng ký</h3>
            <?php if (isset($_GET['lcni_saved']) && $_GET['lcni_saved'] === 'default_packages'): ?>
            <div style="padding:8px 12px;background:#ecfdf5;border:1px solid #6ee7b7;border-radius:8px;color:#065f46;font-size:13px;margin-bottom:12px;">✅ Đã lưu cấu hình gói mặc định.</div>
            <?php endif; ?>
            <p style="font-size:13px;color:#6b7280;margin:0 0 14px;">
                User mới đăng ký (qua bất kỳ kênh nào) sẽ được gán gói này tự động. Để trống = không gán gói.
            </p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('lcni_member_saas_action'); ?>
                <input type="hidden" name="action" value="lcni_saas">
                <input type="hidden" name="_saas_tab" value="packages">
                <input type="hidden" name="lcni_member_save_default_packages" value="1">
                <table class="lcni-perm-table">
                    <thead>
                        <tr>
                            <th style="width:160px;">Role WordPress</th>
                            <th>Gói SaaS mặc định</th>
                            <th style="width:160px;">Thời hạn (ngày)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- Dòng fallback cho mọi role -->
                        <tr style="background:#fefce8;">
                            <td>
                                <strong>* Mọi role</strong>
                                <div style="font-size:11px;color:#9ca3af;">Fallback nếu role không có cấu hình riêng</div>
                            </td>
                            <td>
                                <select name="lcni_saas_default_packages[*]" style="width:100%;">
                                    <option value="0">— Không gán —</option>
                                    <?php foreach ($packages as $pkg): ?>
                                    <option value="<?php echo esc_attr($pkg['id']); ?>"
                                        <?php selected(absint($default_pkgs['*'] ?? 0), (int) $pkg['id']); ?>>
                                        <?php echo esc_html($pkg['package_name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <input type="number" min="0" name="lcni_saas_default_durations[*]"
                                       value="<?php echo esc_attr($default_durs['*'] ?? 0); ?>"
                                       style="width:100%;">
                                <div style="font-size:11px;color:#9ca3af;">0 = vĩnh viễn</div>
                            </td>
                        </tr>
                        <?php foreach ($all_roles as $role_slug => $role_name): ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html($role_name); ?></strong>
                                <div style="font-size:11px;color:#9ca3af;font-family:monospace;"><?php echo esc_html($role_slug); ?></div>
                            </td>
                            <td>
                                <select name="lcni_saas_default_packages[<?php echo esc_attr($role_slug); ?>]" style="width:100%;">
                                    <option value="0">— Dùng theo * fallback —</option>
                                    <?php foreach ($packages as $pkg): ?>
                                    <option value="<?php echo esc_attr($pkg['id']); ?>"
                                        <?php selected(absint($default_pkgs[$role_slug] ?? 0), (int) $pkg['id']); ?>>
                                        <?php echo esc_html($pkg['package_name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <input type="number" min="0"
                                       name="lcni_saas_default_durations[<?php echo esc_attr($role_slug); ?>]"
                                       value="<?php echo esc_attr($default_durs[$role_slug] ?? 0); ?>"
                                       style="width:100%;">
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <button class="lcni-btn lcni-btn-primary" style="margin-top:12px;" type="submit">💾 Lưu cấu hình mặc định</button>
            </form>
        </div>

        <!-- Shortcode reference -->
        <div class="lcni-saas-card" style="margin-bottom:20px;background:#f0f9ff;border-color:#bae6fd;">
            <h3 style="color:#0369a1;margin-bottom:12px;">📋 Shortcodes để nhúng vào Frontend</h3>
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:10px;">
                <?php foreach ([
                    '[lcni_member_login]'   => 'Form đăng nhập',
                    '[lcni_member_register]'=> 'Form đăng ký',
                    '[lcni_member_profile]' => 'Trang hồ sơ',
                    '[lcni_member_package]' => 'Thông tin gói hiện tại',
                ] as $sc => $desc): ?>
                <div>
                    <div style="font-size:11px;color:#0369a1;font-weight:600;margin-bottom:4px;"><?php echo esc_html($desc); ?></div>
                    <div class="lcni-shortcode-box">
                        <code><?php echo esc_html($sc); ?></code>
                        <button class="lcni-copy-btn" onclick="navigator.clipboard.writeText('<?php echo esc_js($sc); ?>');this.textContent='✓ Copied'">Copy</button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Sub-tabs -->
        <div style="display:flex;gap:4px;margin-bottom:20px;border-bottom:2px solid #e5e7eb;">
            <?php foreach (['packages' => '📦 Quản lý gói', 'assign' => '👥 Gán thành viên', 'permissions' => '🔐 Phân quyền'] as $slug => $label): ?>
            <a href="<?php echo esc_url(admin_url('admin.php?page=lcni-member-settings&tab=saas&saas_tab=' . $slug)); ?>"
               style="padding:8px 16px;font-size:13px;font-weight:600;text-decoration:none;border-bottom:2px solid transparent;margin-bottom:-2px;color:<?php echo $saas_tab === $slug ? '#2563eb' : '#6b7280'; ?>;border-bottom-color:<?php echo $saas_tab === $slug ? '#2563eb' : 'transparent'; ?>">
               <?php echo esc_html($label); ?>
            </a>
            <?php endforeach; ?>
        </div>

        <?php if ($saas_tab === 'packages'): ?>
        <!-- ===== PACKAGES ===== -->
        <div class="lcni-saas-grid" style="grid-template-columns: 1fr 1.4fr;">

            <!-- Create / Edit form -->
            <div class="lcni-saas-card">
                <h3><span class="dashicons <?php echo $edit_pkg ? 'dashicons-edit' : 'dashicons-plus-alt'; ?>"></span>
                    <?php echo $edit_pkg ? 'Chỉnh sửa gói' : 'Tạo gói mới'; ?>
                </h3>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('lcni_member_saas_action'); ?>
                    <input type="hidden" name="action" value="lcni_saas">
                    <input type="hidden" name="_saas_tab" value="packages">
                    <?php if ($edit_pkg): ?>
                        <input type="hidden" name="edit_package_id" value="<?php echo esc_attr($edit_pkg['id']); ?>">
                    <?php endif; ?>
                    <div class="lcni-field">
                        <label>Tên gói <span style="color:#dc2626">*</span></label>
                        <input type="text" name="package_name" required value="<?php echo esc_attr($edit_pkg['package_name'] ?? ''); ?>" placeholder="VD: Gói Premium">
                    </div>
                    <div class="lcni-field">
                        <label>Mô tả</label>
                        <textarea name="package_description" rows="2" placeholder="Mô tả ngắn về gói..."><?php echo esc_textarea($edit_pkg['description'] ?? ''); ?></textarea>
                    </div>
                    <div class="lcni-field" style="display:flex;align-items:center;gap:10px;">
                        <div style="flex:1">
                            <label>Màu nhãn</label>
                            <input type="color" name="package_color" value="<?php echo esc_attr($edit_pkg['color'] ?? '#2563eb'); ?>" style="height:38px;padding:2px 4px;">
                        </div>
                        <?php if ($edit_pkg): ?>
                        <div style="flex:1">
                            <label>Trạng thái</label>
                            <select name="package_is_active">
                                <option value="1" <?php selected($edit_pkg['is_active'] ?? 1, 1); ?>>Hoạt động</option>
                                <option value="0" <?php selected($edit_pkg['is_active'] ?? 1, 0); ?>>Tạm khoá</option>
                            </select>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="lcni-field">
                        <label>Icon badge <span style="color:#6b7280;font-weight:400;">(Dashicons hoặc FA class, VD: dashicons-star-filled hoặc fa-solid fa-crown)</span></label>
                        <div style="display:flex;gap:8px;align-items:center;">
                            <input type="text" name="package_badge_icon" value="<?php echo esc_attr($edit_pkg['badge_icon'] ?? ''); ?>" placeholder="dashicons-star-filled" style="flex:1;">
                            <div id="lcni-badge-icon-preview" style="width:32px;height:32px;display:flex;align-items:center;justify-content:center;background:#f3f4f6;border-radius:6px;border:1px solid #d1d5db;">
                                <?php
                                $prev_icon = $edit_pkg['badge_icon'] ?? '';
                                if (!empty($prev_icon)) {
                                    if (strpos($prev_icon, 'dashicons') !== false) {
                                        echo '<span class="dashicons ' . esc_attr($prev_icon) . '" style="font-size:18px;width:18px;height:18px;color:#374151;"></span>';
                                    } else {
                                        echo '<i class="' . esc_attr($prev_icon) . '" style="color:#374151;"></i>';
                                    }
                                }
                                ?>
                            </div>
                        </div>
                        <p style="font-size:11px;color:#9ca3af;margin:4px 0 0;">Hiển thị trong user dropdown menu trên dashboard. Để trống = dùng dot màu mặc định.</p>
                    </div>
                    <div class="lcni-field">
                        <label>Nhãn badge ngắn <span style="color:#6b7280;font-weight:400;">(tối đa 10 ký tự, VD: PRO, BASIC, VIP)</span></label>
                        <input type="text" name="package_badge_label" value="<?php echo esc_attr($edit_pkg['badge_label'] ?? ''); ?>" placeholder="PRO" maxlength="10" style="max-width:160px;">
                    </div>
                    <div style="display:flex;gap:8px;margin-top:4px;">
                        <?php if ($edit_pkg): ?>
                            <button class="lcni-btn lcni-btn-primary" name="lcni_member_update_package" value="1">💾 Lưu thay đổi</button>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=lcni-member-settings&tab=saas')); ?>" class="lcni-btn lcni-btn-ghost">Huỷ</a>
                        <?php else: ?>
                            <button class="lcni-btn lcni-btn-primary" name="lcni_member_create_package" value="1">➕ Tạo gói</button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- Package list -->
            <div class="lcni-saas-card">
                <h3><span class="dashicons dashicons-list-view"></span> Danh sách gói (<?php echo count($packages); ?>)</h3>
                <?php if (empty($packages)): ?>
                    <p style="color:#9ca3af;font-size:13px;text-align:center;padding:20px 0;">Chưa có gói nào. Tạo gói đầu tiên!</p>
                <?php else: ?>
                <div class="lcni-pkg-list">
                    <?php foreach ($packages as $pkg): ?>
                    <div class="lcni-pkg-item">
                        <div class="lcni-pkg-dot" style="background:<?php echo esc_attr($pkg['color']); ?>;"></div>
                        <div style="flex:1;min-width:0;">
                            <div class="lcni-pkg-name"><?php echo esc_html($pkg['package_name']); ?></div>
                            <?php if (!empty($pkg['description'])): ?>
                            <div class="lcni-pkg-desc"><?php echo esc_html($pkg['description']); ?></div>
                            <?php endif; ?>
                            <code style="font-size:11px;color:#9ca3af;"><?php echo esc_html($pkg['package_key']); ?></code>
                        </div>
                        <span class="lcni-pkg-badge <?php echo $pkg['is_active'] ? '' : 'inactive'; ?>">
                            <?php echo $pkg['is_active'] ? 'Active' : 'Khoá'; ?>
                        </span>
                        <div style="display:flex;gap:6px;">
                            <a href="<?php echo esc_url(admin_url('admin.php?page=lcni-member-settings&tab=saas&edit_pkg=' . $pkg['id'])); ?>" class="lcni-btn lcni-btn-ghost lcni-btn-sm">✏️ Sửa</a>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=lcni-member-settings&tab=saas&saas_tab=permissions&perm_pkg=' . $pkg['id'])); ?>" class="lcni-btn lcni-btn-ghost lcni-btn-sm">🔐 Quyền</a>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:0;" onsubmit="return confirm('Xoá gói này? Tất cả phân quyền và gán gói sẽ bị xoá.')">
                                <?php wp_nonce_field('lcni_member_saas_action'); ?>
                                <input type="hidden" name="action" value="lcni_saas">
                                <input type="hidden" name="_saas_tab" value="packages">
                                <input type="hidden" name="delete_package_id" value="<?php echo esc_attr($pkg['id']); ?>">
                                <button class="lcni-btn lcni-btn-danger lcni-btn-sm" name="lcni_member_delete_package" value="1">🗑️</button>
                            </form>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <?php elseif ($saas_tab === 'assign'): ?>
        <!-- ===== ASSIGN ===== -->
        <div class="lcni-saas-grid" style="grid-template-columns: 1fr 1.6fr;">
            <div class="lcni-saas-card">
                <h3><span class="dashicons dashicons-admin-users"></span> Gán gói cho thành viên</h3>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="lcni-assign-form">
                    <?php wp_nonce_field('lcni_member_saas_action'); ?>
                    <input type="hidden" name="action" value="lcni_saas">
                    <input type="hidden" name="_saas_tab" value="assign">
                    <input type="hidden" name="user_id" id="lcni-assign-user-id" value="0">

                    <!-- User search -->
                    <div class="lcni-field" style="position:relative;">
                        <label>👤 Thành viên <small style="color:#9ca3af">(tìm theo tên / email)</small></label>
                        <input type="text" id="lcni-user-search-input" autocomplete="off"
                               placeholder="Gõ tên hoặc email..."
                               style="width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:8px;font-size:13px;box-sizing:border-box;">
                        <div id="lcni-user-search-results" style="display:none;position:absolute;top:100%;left:0;right:0;background:#fff;border:1px solid #e5e7eb;border-radius:8px;box-shadow:0 4px 16px rgba(0,0,0,.1);z-index:999;max-height:220px;overflow-y:auto;margin-top:2px;"></div>
                    </div>

                    <!-- Selected user preview -->
                    <div id="lcni-selected-user" style="display:none;padding:8px 12px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;font-size:13px;margin-bottom:8px;display:flex;align-items:center;gap:8px;">
                        <span>👤</span>
                        <span id="lcni-selected-user-label" style="flex:1;font-weight:600;color:#1e40af;"></span>
                        <button type="button" id="lcni-clear-user" style="background:none;border:none;color:#6b7280;cursor:pointer;font-size:16px;padding:0;line-height:1;">×</button>
                    </div>

                    <!-- Role fallback -->
                    <div class="lcni-field">
                        <label>Role <small style="color:#9ca3af">(chỉ dùng khi không chọn user cụ thể)</small></label>
                        <select name="role_slug" id="lcni-assign-role" style="width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:8px;font-size:13px;box-sizing:border-box;background:#f9fafb;">
                            <option value="">— Không áp dụng theo Role —</option>
                            <?php foreach (wp_roles()->get_names() as $slug => $name): ?>
                            <option value="<?php echo esc_attr($slug); ?>"><?php echo esc_html($name); ?> (<?php echo esc_html($slug); ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="lcni-field">
                        <label>Gói SaaS</label>
                        <select name="package_id" style="width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:8px;font-size:13px;box-sizing:border-box;background:#f9fafb;">
                            <?php foreach ($packages as $pkg): ?>
                            <option value="<?php echo esc_attr($pkg['id']); ?>">
                                <?php echo esc_html($pkg['package_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="lcni-field">
                        <label>Hạn sử dụng <small style="color:#9ca3af">(bỏ trống = vĩnh viễn)</small></label>
                        <input type="date" name="expires_at" style="width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:8px;font-size:13px;box-sizing:border-box;background:#f9fafb;">
                    </div>
                    <div class="lcni-field">
                        <label>Ghi chú</label>
                        <input type="text" name="assign_note" placeholder="VD: Thanh toán tháng 3/2026" style="width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:8px;font-size:13px;box-sizing:border-box;background:#f9fafb;">
                    </div>
                    <button class="lcni-btn lcni-btn-primary" name="lcni_member_assign_package" value="1">✅ Gán gói</button>
                </form>
            </div>

            <!-- Assignments list -->
            <div class="lcni-saas-card" style="overflow-x:auto;">
                <h3><span class="dashicons dashicons-groups"></span> Danh sách gán gói (<?php echo count($assignments); ?>)</h3>
                <?php if (empty($assignments)): ?>
                    <p style="color:#9ca3af;font-size:13px;text-align:center;padding:20px 0;">Chưa có gán gói nào.</p>
                <?php else: ?>
                <table class="lcni-assign-table">
                    <thead>
                        <tr>
                            <th>Thành viên / Role</th>
                            <th>Gói</th>
                            <th>Hết hạn</th>
                            <th>Ghi chú</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($assignments as $row):
                            $is_expired = !empty($row['expires_at']) && strtotime($row['expires_at']) < time();
                        ?>
                        <tr>
                            <td><strong><?php echo esc_html($row['label']); ?></strong></td>
                            <td>
                                <span class="lcni-color-swatch" style="background:<?php echo esc_attr($row['color'] ?? '#2563eb'); ?>"></span>
                                <?php echo esc_html($row['package_name'] ?? '—'); ?>
                            </td>
                            <td class="lcni-expiry <?php echo $is_expired ? 'expired' : ''; ?>">
                                <?php echo $row['expires_at'] ? esc_html(date('d/m/Y', strtotime($row['expires_at']))) . ($is_expired ? ' ⚠️' : '') : '∞ Vĩnh viễn'; ?>
                            </td>
                            <td style="color:#6b7280;font-size:12px;"><?php echo esc_html($row['note'] ?? ''); ?></td>
                            <td>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:0;" onsubmit="return confirm('Thu hồi gói này?')">
                                    <?php wp_nonce_field('lcni_member_saas_action'); ?>
                                    <input type="hidden" name="action" value="lcni_saas">
                                    <input type="hidden" name="_saas_tab" value="assign">
                                    <input type="hidden" name="revoke_user_id"  value="<?php echo esc_attr($row['user_id']); ?>">
                                    <input type="hidden" name="revoke_role_slug" value="<?php echo esc_attr($row['role_slug']); ?>">
                                    <button class="lcni-btn lcni-btn-danger lcni-btn-sm" name="lcni_member_revoke_package" value="1">Thu hồi</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>

        <?php elseif ($saas_tab === 'permissions'): ?>
        <!-- ===== PERMISSIONS ===== -->
        <?php if (empty($packages)): ?>
            <div class="lcni-notice lcni-notice-error">Chưa có gói nào. Vui lòng tạo gói trước.</div>
        <?php else: ?>

        <!-- Package selector -->
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:20px;">
            <?php foreach ($packages as $pkg): ?>
            <a href="<?php echo esc_url(admin_url('admin.php?page=lcni-member-settings&tab=saas&saas_tab=permissions&perm_pkg=' . $pkg['id'])); ?>"
               style="display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border-radius:8px;font-size:13px;font-weight:600;text-decoration:none;border:2px solid <?php echo $perm_pkg_id == $pkg['id'] ? esc_attr($pkg['color']) : '#e5e7eb'; ?>;background:<?php echo $perm_pkg_id == $pkg['id'] ? esc_attr($pkg['color']) : '#fff'; ?>;color:<?php echo $perm_pkg_id == $pkg['id'] ? '#fff' : '#374151'; ?>;">
               <span style="width:8px;height:8px;border-radius:50%;background:<?php echo $perm_pkg_id == $pkg['id'] ? 'rgba(255,255,255,.6)' : esc_attr($pkg['color']); ?>;"></span>
               <?php echo esc_html($pkg['package_name']); ?>
            </a>
            <?php endforeach; ?>
        </div>

        <?php if ($perm_pkg): ?>
        <div class="lcni-saas-card">
            <h3>
                <span style="width:12px;height:12px;border-radius:50%;background:<?php echo esc_attr($perm_pkg['color']); ?>;display:inline-block;"></span>
                Phân quyền: <?php echo esc_html($perm_pkg['package_name']); ?>
                <small style="font-weight:400;text-transform:none;font-size:12px;color:#9ca3af;"><?php echo esc_html($perm_pkg['description'] ?? ''); ?></small>
            </h3>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('lcni_member_saas_action'); ?>
                <input type="hidden" name="action" value="lcni_saas">
                <input type="hidden" name="_saas_tab" value="permissions">
                <input type="hidden" name="permission_package_id" value="<?php echo esc_attr($perm_pkg_id); ?>">
                <table class="lcni-perm-table">
                    <thead>
                        <tr>
                            <th style="width:220px;">Module</th>
                            <th class="lcni-perm-check">👁 View</th>
                            <th class="lcni-perm-check">📤 Export</th>
                            <th class="lcni-perm-check">🔍 Filter</th>
                            <th class="lcni-perm-check">⚡ Realtime</th>
                            <th class="lcni-perm-check">📈 Trade</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $caps_map = ['can_view' => 'view', 'can_export' => 'export', 'can_filter' => 'filter', 'can_realtime' => 'realtime', 'can_trade' => 'trade'];
                        $last_group = '';
                        foreach ($modules as $module_key => $module_meta):
                            // Hỗ trợ cả format cũ (string) và mới (array)
                            if (is_string($module_meta)) {
                                $module_meta = ['label' => $module_meta, 'group' => '', 'caps' => ['view','export','filter','realtime']];
                            }
                            $module_label = $module_meta['label'] ?? $module_key;
                            $module_group = $module_meta['group'] ?? '';
                            $allowed_caps = $module_meta['caps'] ?? ['view','export','filter','realtime'];
                            $p = $perm_map[$module_key] ?? [];

                            // Header group
                            if ($module_group !== '' && $module_group !== $last_group):
                                $last_group = $module_group;
                        ?>
                        <tr>
                            <td colspan="5" style="background:#f8fafc;padding:6px 10px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#94a3b8;border-top:2px solid #e2e8f0;">
                                <?php echo esc_html($module_group); ?>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <tr>
                            <td>
                                <strong style="font-size:13px;"><?php echo esc_html($module_label); ?></strong>
                                <input type="hidden" name="permissions[<?php echo esc_attr($module_key); ?>][module_key]" value="<?php echo esc_attr($module_key); ?>">
                                <input type="hidden" name="permissions[<?php echo esc_attr($module_key); ?>][table_name]"  value="*">
                                <input type="hidden" name="permissions[<?php echo esc_attr($module_key); ?>][column_name]" value="*">
                                <div style="font-size:11px;color:#9ca3af;font-family:monospace;"><?php echo esc_html($module_key); ?></div>
                            </td>
                            <?php foreach ($caps_map as $cap_field => $cap_slug): ?>
                            <td class="lcni-perm-check">
                                <?php if (in_array($cap_slug, $allowed_caps, true)): ?>
                                <input type="checkbox"
                                       name="permissions[<?php echo esc_attr($module_key); ?>][<?php echo esc_attr($cap_field); ?>]"
                                       value="1"
                                       <?php checked(!empty($p[$cap_field])); ?>>
                                <?php else: ?>
                                <span style="color:#e5e7eb;font-size:16px;">—</span>
                                <?php endif; ?>
                            </td>
                            <?php endforeach; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <div style="margin-top:16px;display:flex;align-items:center;gap:12px;">
                    <button class="lcni-btn lcni-btn-primary" name="lcni_member_save_permissions" value="1">💾 Lưu phân quyền</button>
                    <span style="font-size:12px;color:#6b7280;">Module không được tick = bị chặn khi gói này được áp dụng. Dấu — = cap không áp dụng cho module này.</span>
                </div>
            </form>
        </div>
        <?php endif; ?>
        <?php endif; ?>
        <?php endif; ?>

        <script>
        (function () {
            var searchInput  = document.getElementById('lcni-user-search-input');
            var resultsBox   = document.getElementById('lcni-user-search-results');
            var userIdInput  = document.getElementById('lcni-assign-user-id');
            var selectedBox  = document.getElementById('lcni-selected-user');
            var selectedLabel= document.getElementById('lcni-selected-user-label');
            var clearBtn     = document.getElementById('lcni-clear-user');
            var roleSelect   = document.getElementById('lcni-assign-role');

            if (!searchInput) return;

            var timer = null;
            var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
            var nonce   = <?php echo wp_json_encode(wp_create_nonce('lcni_user_search')); ?>;

            function clearSelection() {
                userIdInput.value = '0';
                searchInput.value = '';
                selectedBox.style.display = 'none';
                searchInput.style.display = '';
                if (roleSelect) roleSelect.disabled = false;
            }

            function selectUser(id, label) {
                userIdInput.value = id;
                selectedLabel.textContent = label;
                selectedBox.style.display = 'flex';
                searchInput.style.display = 'none';
                resultsBox.style.display  = 'none';
                if (roleSelect) {
                    roleSelect.value    = '';
                    roleSelect.disabled = true;
                }
            }

            clearBtn.addEventListener('click', clearSelection);

            searchInput.addEventListener('input', function () {
                var q = this.value.trim();
                clearTimeout(timer);
                if (q.length < 2) { resultsBox.style.display = 'none'; return; }
                timer = setTimeout(function () {
                    fetch(ajaxUrl + '?action=lcni_user_search&q=' + encodeURIComponent(q) + '&_nonce=' + nonce)
                        .then(function (r) { return r.json(); })
                        .then(function (data) {
                            resultsBox.innerHTML = '';
                            if (!data.success || !data.data.length) {
                                resultsBox.innerHTML = '<div style="padding:10px 14px;color:#9ca3af;font-size:13px;">Không tìm thấy user.</div>';
                                resultsBox.style.display = 'block';
                                return;
                            }
                            data.data.forEach(function (u) {
                                var item = document.createElement('div');
                                item.style.cssText = 'padding:9px 14px;cursor:pointer;font-size:13px;display:flex;align-items:center;gap:10px;border-bottom:1px solid #f3f4f6;';
                                item.innerHTML = '<span style="width:28px;height:28px;border-radius:50%;background:#e0e7ff;display:flex;align-items:center;justify-content:center;font-size:13px;flex-shrink:0;">👤</span>'
                                    + '<span><strong>' + u.display_name + '</strong><br><span style="color:#6b7280;font-size:11px;">' + u.user_email + ' · ID:' + u.ID + ' · ' + u.role + '</span></span>';
                                item.addEventListener('mouseenter', function () { this.style.background = '#f0f9ff'; });
                                item.addEventListener('mouseleave', function () { this.style.background = ''; });
                                item.addEventListener('click', function () {
                                    selectUser(u.ID, u.display_name + ' (' + u.user_email + ')');
                                });
                                resultsBox.appendChild(item);
                            });
                            resultsBox.style.display = 'block';
                        })
                        .catch(function () { resultsBox.style.display = 'none'; });
                }, 300);
            });

            document.addEventListener('click', function (e) {
                if (!searchInput.contains(e.target) && !resultsBox.contains(e.target)) {
                    resultsBox.style.display = 'none';
                }
            });
        })();
        </script>
        <?php
    }

    // =================== Google OAuth Tab ===================

    private function render_google_tab() {
        $client_id = get_option( 'lcni_google_client_id', '' );
        ?>
        <form method="post" action="options.php">
            <?php settings_fields( 'lcni_member_settings' ); ?>
            <div class="lcni-saas-grid" style="grid-template-columns:1fr 1fr;">

                <div class="lcni-saas-card">
                    <h3><span class="dashicons dashicons-google"></span> Cấu hình Google OAuth</h3>

                    <div class="lcni-field">
                        <label>Google Client ID</label>
                        <input type="text"
                               name="lcni_google_client_id"
                               value="<?php echo esc_attr( $client_id ); ?>"
                               placeholder="xxxxxxxxxxxx-xxxxxx.apps.googleusercontent.com"
                               style="width:100%;font-family:monospace;font-size:12px;">
                        <p style="font-size:12px;color:#6b7280;margin-top:6px;">
                            Lấy từ <a href="https://console.cloud.google.com/apis/credentials" target="_blank">Google Cloud Console</a>
                            → Credentials → OAuth 2.0 Client ID (loại <em>Web application</em>).
                        </p>
                    </div>

                    <div class="lcni-field" style="background:#f0fdf4;border:1px solid #86efac;border-radius:8px;padding:12px;">
                        <p style="margin:0 0 6px;font-weight:600;font-size:13px;color:#15803d;">✅ Authorized JavaScript origins</p>
                        <p style="margin:0;font-size:12px;color:#166534;">Thêm domain của bạn vào Google Cloud Console:</p>
                        <code style="display:block;margin-top:6px;font-size:12px;background:#dcfce7;padding:6px 10px;border-radius:6px;">
                            <?php echo esc_html( home_url() ); ?>
                        </code>
                    </div>

                    <?php submit_button( '💾 Lưu cấu hình Google' ); ?>
                </div>

                <div class="lcni-saas-card">
                    <h3><span class="dashicons dashicons-info"></span> Hướng dẫn tích hợp</h3>
                    <ol style="font-size:13px;line-height:1.8;color:#374151;padding-left:18px;margin:0;">
                        <li>Vào <strong>Google Cloud Console</strong> → tạo project (nếu chưa có).</li>
                        <li>Vào <strong>APIs &amp; Services → Credentials</strong> → <em>Create Credentials → OAuth 2.0 Client ID</em>.</li>
                        <li>Chọn loại <strong>Web application</strong>, đặt tên tùy ý.</li>
                        <li>Thêm URL site vào <strong>Authorized JavaScript origins</strong>:<br>
                            <code style="font-size:11px;"><?php echo esc_html( home_url() ); ?></code></li>
                        <li>Copy <strong>Client ID</strong> (dạng <code>xxx.apps.googleusercontent.com</code>) và dán vào ô bên trái.</li>
                        <li>Lưu cài đặt. Nút <em>"Đăng nhập với Google"</em> sẽ tự hiện trên form login.</li>
                    </ol>

                    <div style="margin-top:16px;padding:10px 14px;background:#fef9c3;border:1px solid #fde047;border-radius:8px;">
                        <p style="margin:0;font-size:12px;color:#713f12;">
                            <strong>⚠️ Lưu ý:</strong> Đảm bảo site dùng HTTPS. Google OAuth không hoạt động trên HTTP ở môi trường production.
                        </p>
                    </div>

                    <?php if ( $client_id !== '' ): ?>
                    <div style="margin-top:16px;padding:10px 14px;background:#f0fdf4;border:1px solid #86efac;border-radius:8px;">
                        <p style="margin:0;font-size:12px;color:#15803d;">
                            <strong>✅ Đã cấu hình.</strong> Nút Google đang hiển thị trên form <code>[lcni_member_login]</code>.
                        </p>
                    </div>
                    <?php else: ?>
                    <div style="margin-top:16px;padding:10px 14px;background:#fff7ed;border:1px solid #fed7aa;border-radius:8px;">
                        <p style="margin:0;font-size:12px;color:#9a3412;">
                            <strong>⏳ Chưa cấu hình.</strong> Nhập Client ID để bật tính năng đăng nhập Google.
                        </p>
                    </div>
                    <?php endif; ?>
                </div>

            </div>
        </form>
        <?php
    }

    // =================== Quote Tab ===================

    private function render_quote_tab() {
        $quote_settings = get_option('lcni_member_quote_settings', []);
        ?>
        <form method="post" action="options.php" enctype="multipart/form-data">
            <?php settings_fields('lcni_member_settings'); ?>
            <div class="lcni-saas-grid" style="grid-template-columns:1fr 1fr;">
                <div class="lcni-saas-card">
                    <h3><span class="dashicons dashicons-format-quote"></span> Kích thước & Màu sắc</h3>
                    <div class="lcni-field"><label>Chiều rộng (px)</label><input type="number" name="lcni_member_quote_settings[width]" value="<?php echo esc_attr($quote_settings['width'] ?? 500); ?>"></div>
                    <div class="lcni-field"><label>Chiều cao (px)</label><input type="number" name="lcni_member_quote_settings[height]" value="<?php echo esc_attr($quote_settings['height'] ?? 120); ?>"></div>
                    <div class="lcni-field"><label>Border radius (px)</label><input type="number" name="lcni_member_quote_settings[border_radius]" value="<?php echo esc_attr($quote_settings['border_radius'] ?? 12); ?>"></div>
                    <div class="lcni-field"><label>Blur nền (px)</label><input type="number" name="lcni_member_quote_settings[background_blur]" value="<?php echo esc_attr($quote_settings['background_blur'] ?? 0); ?>"></div>
                    <div class="lcni-field"><label>Màu nền</label><input type="color" name="lcni_member_quote_settings[background]" value="<?php echo esc_attr($quote_settings['background'] ?? '#f8fafc'); ?>"></div>
                    <div class="lcni-field"><label>Màu viền</label><input type="color" name="lcni_member_quote_settings[border_color]" value="<?php echo esc_attr($quote_settings['border_color'] ?? '#d1d5db'); ?>"></div>
                    <div class="lcni-field"><label>Màu chữ</label><input type="color" name="lcni_member_quote_settings[text_color]" value="<?php echo esc_attr($quote_settings['text_color'] ?? '#334155'); ?>"></div>
                    <div class="lcni-field"><label>Cỡ chữ (px)</label><input type="number" name="lcni_member_quote_settings[font_size]" value="<?php echo esc_attr($quote_settings['font_size'] ?? 16); ?>"></div>
                    <div class="lcni-field"><label>Font family</label><input type="text" name="lcni_member_quote_settings[font_family]" value="<?php echo esc_attr($quote_settings['font_family'] ?? 'inherit'); ?>" placeholder="inherit, serif, ..."></div>
                    <div class="lcni-field"><label>Căn lề</label><select name="lcni_member_quote_settings[text_align]"><option value="left" <?php selected(($quote_settings['text_align'] ?? 'left'), 'left'); ?>>Left</option><option value="center" <?php selected(($quote_settings['text_align'] ?? 'left'), 'center'); ?>>Center</option><option value="right" <?php selected(($quote_settings['text_align'] ?? 'left'), 'right'); ?>>Right</option></select></div>
                    <div class="lcni-field"><label>Hiệu ứng chữ</label><select name="lcni_member_quote_settings[effect]"><option value="normal" <?php selected(($quote_settings['effect'] ?? 'normal'), 'normal'); ?>>Normal</option><option value="italic" <?php selected(($quote_settings['effect'] ?? 'normal'), 'italic'); ?>>Italic</option><option value="bold" <?php selected(($quote_settings['effect'] ?? 'normal'), 'bold'); ?>>Bold</option><option value="uppercase" <?php selected(($quote_settings['effect'] ?? 'normal'), 'uppercase'); ?>>Uppercase</option><option value="shadow" <?php selected(($quote_settings['effect'] ?? 'normal'), 'shadow'); ?>>Shadow</option></select></div>
                </div>
                <div class="lcni-saas-card">
                    <h3><span class="dashicons dashicons-visibility"></span> Nội dung & Preview</h3>
                    <div class="lcni-field">
                        <label>Preview quote (1 dòng)</label>
                        <input class="regular-text" id="lcni-quote-preview-input" name="lcni_member_quote_settings[preview_text]" value="<?php echo esc_attr($quote_settings['preview_text'] ?? 'Market quote preview'); ?>">
                        <div id="lcni-quote-preview" style="margin-top:10px;padding:14px;border:1px dashed #cbd5e1;border-radius:8px;min-height:50px;display:flex;align-items:center;"><?php echo esc_html($quote_settings['preview_text'] ?? 'Market quote preview'); ?></div>
                    </div>
                    <div class="lcni-field">
                        <label>Danh sách quotes (mỗi dòng 1 câu)</label>
                        <textarea name="lcni_member_quote_settings[quote_list]" class="large-text" rows="6"><?php echo esc_textarea($quote_settings['quote_list'] ?? ''); ?></textarea>
                    </div>
                    <div class="lcni-field">
                        <label>Upload CSV file (mỗi dòng 1 câu)</label>
                        <input type="file" name="lcni_member_quote_csv_file" accept=".csv,.txt">
                        <input type="hidden" name="lcni_member_quote_settings[quote_csv_url]" value="<?php echo esc_attr($quote_settings['quote_csv_url'] ?? ''); ?>">
                        <?php if (!empty($quote_settings['quote_csv_url'])): ?>
                        <p style="font-size:12px;color:#6b7280;margin-top:4px;">Đang dùng: <code><?php echo esc_html($quote_settings['quote_csv_url']); ?></code></p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php submit_button('💾 Lưu cài đặt Quote'); ?>
        </form>
        <script>
        document.addEventListener('DOMContentLoaded', function () {
            var preview   = document.getElementById('lcni-quote-preview');
            var textInput = document.getElementById('lcni-quote-preview-input');
            var fontInput = document.querySelector('input[name="lcni_member_quote_settings[font_family]"]');
            var alignInput  = document.querySelector('select[name="lcni_member_quote_settings[text_align]"]');
            var effectInput = document.querySelector('select[name="lcni_member_quote_settings[effect]"]');
            var colorInput  = document.querySelector('input[name="lcni_member_quote_settings[text_color]"]');
            if (!preview || !textInput) return;
            var applyStyle = function () {
                var effect = effectInput.value;
                preview.textContent   = textInput.value || 'Market quote preview';
                preview.style.fontFamily    = fontInput.value || 'inherit';
                preview.style.textAlign     = alignInput.value || 'left';
                preview.style.color         = colorInput.value || '#334155';
                preview.style.fontStyle     = effect === 'italic'    ? 'italic'    : 'normal';
                preview.style.fontWeight    = effect === 'bold'      ? '700'       : '400';
                preview.style.textTransform = effect === 'uppercase' ? 'uppercase' : 'none';
                preview.style.textShadow    = effect === 'shadow'    ? '1px 1px 2px rgba(15,23,42,0.35)' : 'none';
            };
            [textInput, fontInput, alignInput, effectInput, colorInput].forEach(function (n) {
                n.addEventListener('input', applyStyle);
                n.addEventListener('change', applyStyle);
            });
            applyStyle();
        });
        </script>
        <?php
    }

    // =================== Form Tab (Login / Register / Profile) ===================

    private function render_form_tab($tab) {
        $login    = get_option('lcni_member_login_settings',    []);
        $register = get_option('lcni_member_register_settings', []);
        $profile  = get_option('lcni_member_profile_settings',  []);
        $key      = $tab === 'login' ? 'lcni_member_login_settings' : ($tab === 'register' ? 'lcni_member_register_settings' : 'lcni_member_profile_settings');
        $v        = $tab === 'login' ? $login : ($tab === 'register' ? $register : $profile);
        ?>
        <form method="post" action="options.php" enctype="multipart/form-data">
            <?php settings_fields('lcni_member_settings'); ?>
            <div class="lcni-saas-grid" style="grid-template-columns:1fr 1fr;">
                <div class="lcni-saas-card">
                    <h3><span class="dashicons dashicons-art"></span> Giao diện & Màu sắc</h3>
                    <div class="lcni-field"><label>Font</label><input type="text" name="<?php echo esc_attr($key); ?>[font]" value="<?php echo esc_attr($v['font'] ?? 'inherit'); ?>" placeholder="inherit, sans-serif, ..."></div>
                    <div class="lcni-field"><label>Màu chữ tổng</label><input type="color" name="<?php echo esc_attr($key); ?>[text_color]" value="<?php echo esc_attr($v['text_color'] ?? '#1f2937'); ?>"></div>
                    <div class="lcni-field"><label>Màu nền trang</label><input type="color" name="<?php echo esc_attr($key); ?>[background]" value="<?php echo esc_attr($v['background'] ?? '#ffffff'); ?>"></div>
                    <div class="lcni-field">
                        <label>Ảnh nền</label>
                        <input type="file" name="<?php echo $tab === 'login' ? 'lcni_member_login_bg_image_file' : ($tab === 'register' ? 'lcni_member_register_bg_image_file' : 'lcni_member_profile_bg_image_file'); ?>" accept="image/*">
                        <input type="hidden" name="<?php echo esc_attr($key); ?>[background_image]" value="<?php echo esc_attr($v['background_image'] ?? ''); ?>">
                        <?php if (!empty($v['background_image'])): ?><p style="font-size:12px;color:#6b7280;margin-top:4px;">Đang dùng: <code><?php echo esc_html($v['background_image']); ?></code></p><?php endif; ?>
                    </div>
                    <div class="lcni-field"><label>Màu viền ngoài</label><input type="color" name="<?php echo esc_attr($key); ?>[border_color]" value="<?php echo esc_attr($v['border_color'] ?? '#d1d5db'); ?>"></div>
                    <div class="lcni-field"><label>Border radius ngoài (px)</label><input type="number" name="<?php echo esc_attr($key); ?>[border_radius]" value="<?php echo esc_attr($v['border_radius'] ?? 8); ?>"></div>
                    <div class="lcni-field"><label>Màu nền form box</label><input type="color" name="<?php echo esc_attr($key); ?>[form_box_background]" value="<?php echo esc_attr($v['form_box_background'] ?? '#ffffff'); ?>"></div>
                    <div class="lcni-field"><label>Màu viền form box</label><input type="color" name="<?php echo esc_attr($key); ?>[form_box_border_color]" value="<?php echo esc_attr($v['form_box_border_color'] ?? '#d1d5db'); ?>"></div>
                    <div class="lcni-field"><label>Border radius form box (px)</label><input type="number" name="<?php echo esc_attr($key); ?>[form_box_border_radius]" value="<?php echo esc_attr($v['form_box_border_radius'] ?? 10); ?>"></div>
                </div>
                <div class="lcni-saas-card">
                    <h3><span class="dashicons dashicons-forms"></span> Input, Button & Label</h3>
                    <div class="lcni-field"><label>Input height (px)</label><input type="number" name="<?php echo esc_attr($key); ?>[input_height]" value="<?php echo esc_attr($v['input_height'] ?? 40); ?>"></div>
                    <div class="lcni-field"><label>Input width (px)</label><input type="number" name="<?php echo esc_attr($key); ?>[input_width]" value="<?php echo esc_attr($v['input_width'] ?? 320); ?>"></div>
                    <div class="lcni-field"><label>Màu nền input</label><input type="color" name="<?php echo esc_attr($key); ?>[input_bg]" value="<?php echo esc_attr($v['input_bg'] ?? '#ffffff'); ?>"></div>
                    <div class="lcni-field"><label>Màu viền input</label><input type="color" name="<?php echo esc_attr($key); ?>[input_border_color]" value="<?php echo esc_attr($v['input_border_color'] ?? '#d1d5db'); ?>"></div>
                    <div class="lcni-field"><label>Màu chữ input</label><input type="color" name="<?php echo esc_attr($key); ?>[input_text_color]" value="<?php echo esc_attr($v['input_text_color'] ?? '#111827'); ?>"></div>
                    <?php if ($tab === 'profile'): ?>
                    <div class="lcni-field"><label>Màu viền input khi focus</label><input type="color" name="<?php echo esc_attr($key); ?>[input_focus_border_color]" value="<?php echo esc_attr($v['input_focus_border_color'] ?? '#2563eb'); ?>"></div>
                    <div class="lcni-field"><label>Focus shadow</label><label><input type="checkbox" name="<?php echo esc_attr($key); ?>[input_focus_shadow]" value="1" <?php checked(!empty($v['input_focus_shadow'])); ?>> Bật glow khi focus</label></div>
                    <?php endif; ?>
                    <div class="lcni-field"><label>Button height (px)</label><input type="number" name="<?php echo esc_attr($key); ?>[button_height]" value="<?php echo esc_attr($v['button_height'] ?? 42); ?>"></div>
                    <div class="lcni-field"><label>Button width (px)</label><input type="number" name="<?php echo esc_attr($key); ?>[button_width]" value="<?php echo esc_attr($v['button_width'] ?? 180); ?>"></div>
                    <div class="lcni-field"><label>Màu nền button</label><input type="color" name="<?php echo esc_attr($key); ?>[button_bg]" value="<?php echo esc_attr($v['button_bg'] ?? '#2563eb'); ?>"></div>
                    <div class="lcni-field"><label>Màu viền button</label><input type="color" name="<?php echo esc_attr($key); ?>[button_border_color]" value="<?php echo esc_attr($v['button_border_color'] ?? '#1d4ed8'); ?>"></div>
                    <div class="lcni-field"><label>Màu chữ button</label><input type="color" name="<?php echo esc_attr($key); ?>[button_text_color]" value="<?php echo esc_attr($v['button_text_color'] ?? '#ffffff'); ?>"></div>
                    <div class="lcni-field"><label>Icon class button</label><input type="text" name="<?php echo esc_attr($key); ?>[button_icon_class]" value="<?php echo esc_attr($v['button_icon_class'] ?? 'fa-solid fa-right-to-bracket'); ?>" placeholder="fa-solid fa-arrow-right"></div>
                    <div class="lcni-field"><label>Label username</label><input type="text" name="<?php echo esc_attr($key); ?>[label_username]" value="<?php echo esc_attr($v['label_username'] ?? 'Username'); ?>"></div>
                    <?php if ($tab === 'register'): ?>
                    <div class="lcni-field"><label>Label email</label><input type="text" name="<?php echo esc_attr($key); ?>[label_email]" value="<?php echo esc_attr($v['label_email'] ?? 'Email'); ?>"></div>
                    <?php endif; ?>
                    <div class="lcni-field"><label>Label password</label><input type="text" name="<?php echo esc_attr($key); ?>[label_password]" value="<?php echo esc_attr($v['label_password'] ?? 'Password'); ?>"></div>
                    <div class="lcni-field"><label>Label button submit</label><input type="text" name="<?php echo esc_attr($key); ?>[label_button]" value="<?php echo esc_attr($v['label_button'] ?? 'Submit'); ?>"></div>
                    <?php if ($tab === 'login'): ?>
                    <div class="lcni-field"><label>Label button đăng ký</label><input type="text" name="<?php echo esc_attr($key); ?>[register_button_label]" value="<?php echo esc_attr($v['register_button_label'] ?? 'Đăng ký'); ?>"></div>
                    <div class="lcni-field">
                        <label>Trang đăng ký</label>
                        <?php wp_dropdown_pages(['name' => $key.'[register_page_id]', 'selected' => absint($v['register_page_id'] ?? 0), 'show_option_none' => '-- Chọn trang --', 'option_none_value' => '0']); ?>
                    </div>
                    <div class="lcni-field"><label>Remember me</label><label><input type="checkbox" name="<?php echo esc_attr($key); ?>[remember_me]" value="1" <?php checked(!empty($v['remember_me'])); ?>> Hiển thị "Ghi nhớ đăng nhập"</label></div>
                    <?php endif; ?>
                    <?php if ($tab === 'register'): ?>
                    <div class="lcni-field"><label>Role mặc định</label><input type="text" name="<?php echo esc_attr($key); ?>[default_role]" value="<?php echo esc_attr($v['default_role'] ?? 'subscriber'); ?>"></div>
                    <div class="lcni-field"><label>Auto login sau đăng ký</label><label><input type="checkbox" name="<?php echo esc_attr($key); ?>[auto_login]" value="1" <?php checked(!empty($v['auto_login'])); ?>> Bật</label></div>
                    <?php endif; ?>
                    <div class="lcni-field"><label>Redirect URL sau <?php echo esc_html($tab); ?></label><input type="text" name="<?php echo esc_attr($key); ?>[redirect_url]" value="<?php echo esc_attr($v['redirect_url'] ?? ''); ?>" placeholder="https://..."></div>
                </div>
            </div>
            <?php submit_button('💾 Lưu cài đặt ' . ucfirst($tab)); ?>
        </form>
        <?php
    }
}
