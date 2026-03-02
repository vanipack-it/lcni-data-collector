<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_Member_Settings_Page {

    private $service;

    public function __construct(LCNI_SaaS_Service $service) {
        $this->service = $service;
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_init', [$this, 'register_settings']);
    }

    public function add_menu() {
        add_submenu_page('lcni-settings', 'Member', 'Member', 'manage_options', 'lcni-member-settings', [$this, 'render']);
    }

    public function register_settings() {
        register_setting('lcni_member_settings', 'lcni_member_login_settings', ['type' => 'array', 'sanitize_callback' => [$this, 'sanitize_login_settings'], 'default' => []]);
        register_setting('lcni_member_settings', 'lcni_member_register_settings', ['type' => 'array', 'sanitize_callback' => [$this, 'sanitize_register_settings'], 'default' => []]);
        register_setting('lcni_member_settings', 'lcni_member_quote_settings', ['type' => 'array', 'sanitize_callback' => [$this, 'sanitize_quote_settings'], 'default' => []]);

        if (!empty($_POST['lcni_member_create_package'])) {
            check_admin_referer('lcni_member_saas_action');
            $this->service->create_package(isset($_POST['package_name']) ? wp_unslash($_POST['package_name']) : '');
        }

        if (!empty($_POST['lcni_member_assign_package'])) {
            check_admin_referer('lcni_member_saas_action');
            $this->service->assign_package(
                isset($_POST['user_id']) ? absint($_POST['user_id']) : 0,
                isset($_POST['role_slug']) ? sanitize_key(wp_unslash($_POST['role_slug'])) : '',
                isset($_POST['package_id']) ? absint($_POST['package_id']) : 0
            );
        }

        if (!empty($_POST['lcni_member_save_permissions'])) {
            check_admin_referer('lcni_member_saas_action');
            $package_id = isset($_POST['permission_package_id']) ? absint($_POST['permission_package_id']) : 0;
            $permissions = isset($_POST['permissions']) ? (array) wp_unslash($_POST['permissions']) : [];
            $this->service->save_permissions($package_id, $permissions);
        }
    }

    public function sanitize_login_settings($input) {
        return $this->sanitize_common_settings($input, ['remember_me']);
    }

    public function sanitize_register_settings($input) {
        $sanitized = $this->sanitize_common_settings($input, ['auto_login']);
        $sanitized['default_role'] = sanitize_key($input['default_role'] ?? 'subscriber');
        return $sanitized;
    }

    public function sanitize_quote_settings($input) {
        $input = is_array($input) ? $input : [];

        return [
            'width' => max(200, absint($input['width'] ?? 500)),
            'height' => max(60, absint($input['height'] ?? 120)),
            'border_radius' => absint($input['border_radius'] ?? 12),
            'background' => sanitize_hex_color($input['background'] ?? '#f8fafc'),
            'background_blur' => absint($input['background_blur'] ?? 0),
            'border_color' => sanitize_hex_color($input['border_color'] ?? '#d1d5db'),
            'text_color' => sanitize_hex_color($input['text_color'] ?? '#334155'),
            'font_size' => max(10, absint($input['font_size'] ?? 16)),
            'quote_list' => sanitize_textarea_field($input['quote_list'] ?? ''),
            'quote_csv_url' => esc_url_raw($input['quote_csv_url'] ?? ''),
        ];
    }

    private function sanitize_common_settings($input, $bool_keys) {
        $input = is_array($input) ? $input : [];
        $sanitized = [
            'font' => sanitize_text_field($input['font'] ?? ''),
            'text_color' => sanitize_hex_color($input['text_color'] ?? '#1f2937'),
            'background' => sanitize_hex_color($input['background'] ?? '#ffffff'),
            'background_image' => esc_url_raw($input['background_image'] ?? ''),
            'border_color' => sanitize_hex_color($input['border_color'] ?? '#d1d5db'),
            'border_radius' => absint($input['border_radius'] ?? 8),
            'input_height' => max(32, absint($input['input_height'] ?? 40)),
            'input_width' => max(120, absint($input['input_width'] ?? 320)),
            'input_bg' => sanitize_hex_color($input['input_bg'] ?? '#ffffff'),
            'input_border_color' => sanitize_hex_color($input['input_border_color'] ?? '#d1d5db'),
            'input_text_color' => sanitize_hex_color($input['input_text_color'] ?? '#111827'),
            'button_height' => max(30, absint($input['button_height'] ?? 42)),
            'button_width' => max(100, absint($input['button_width'] ?? 180)),
            'button_bg' => sanitize_hex_color($input['button_bg'] ?? '#2563eb'),
            'button_border_color' => sanitize_hex_color($input['button_border_color'] ?? '#1d4ed8'),
            'button_text_color' => sanitize_hex_color($input['button_text_color'] ?? '#ffffff'),
            'button_icon_class' => sanitize_text_field($input['button_icon_class'] ?? 'fa-solid fa-right-to-bracket'),
            'redirect_url' => esc_url_raw($input['redirect_url'] ?? ''),
            'label_username' => sanitize_text_field($input['label_username'] ?? 'Username'),
            'label_email' => sanitize_text_field($input['label_email'] ?? 'Email'),
            'label_password' => sanitize_text_field($input['label_password'] ?? 'Password'),
            'label_button' => sanitize_text_field($input['label_button'] ?? 'Submit'),
        ];

        foreach ($bool_keys as $key) {
            $sanitized[$key] = !empty($input[$key]) ? 1 : 0;
        }

        return $sanitized;
    }

    public function render() {
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'login';
        $login = get_option('lcni_member_login_settings', []);
        $register = get_option('lcni_member_register_settings', []);
        $quote_settings = get_option('lcni_member_quote_settings', []);
        $packages = $this->service->get_package_options();
        ?>
        <div class="wrap">
            <h1>LCNI Data → Frontend Setting → Member</h1>
            <h2 class="nav-tab-wrapper">
                <a href="<?php echo esc_url(admin_url('admin.php?page=lcni-member-settings&tab=login')); ?>" class="nav-tab <?php echo $tab === 'login' ? 'nav-tab-active' : ''; ?>">Login</a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=lcni-member-settings&tab=register')); ?>" class="nav-tab <?php echo $tab === 'register' ? 'nav-tab-active' : ''; ?>">Register</a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=lcni-member-settings&tab=quote')); ?>" class="nav-tab <?php echo $tab === 'quote' ? 'nav-tab-active' : ''; ?>">Quote</a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=lcni-member-settings&tab=saas')); ?>" class="nav-tab <?php echo $tab === 'saas' ? 'nav-tab-active' : ''; ?>">Gói SaaS</a>
            </h2>
            <?php if ($tab === 'login' || $tab === 'register') : ?>
                <form method="post" action="options.php">
                    <?php settings_fields('lcni_member_settings'); ?>
                    <?php $key = $tab === 'login' ? 'lcni_member_login_settings' : 'lcni_member_register_settings'; $v = $tab === 'login' ? $login : $register; ?>
                    <table class="form-table">
                        <tr><th>Font</th><td><input name="<?php echo esc_attr($key); ?>[font]" value="<?php echo esc_attr($v['font'] ?? 'inherit'); ?>" class="regular-text"></td></tr>
                        <tr><th>Text color</th><td><input type="color" name="<?php echo esc_attr($key); ?>[text_color]" value="<?php echo esc_attr($v['text_color'] ?? '#1f2937'); ?>"></td></tr>
                        <tr><th>Background</th><td><input type="color" name="<?php echo esc_attr($key); ?>[background]" value="<?php echo esc_attr($v['background'] ?? '#ffffff'); ?>"></td></tr>
                        <tr><th>Background image URL</th><td><input name="<?php echo esc_attr($key); ?>[background_image]" value="<?php echo esc_attr($v['background_image'] ?? ''); ?>" class="regular-text"></td></tr>
                        <tr><th>Border color</th><td><input type="color" name="<?php echo esc_attr($key); ?>[border_color]" value="<?php echo esc_attr($v['border_color'] ?? '#d1d5db'); ?>"></td></tr>
                        <tr><th>Border radius</th><td><input type="number" name="<?php echo esc_attr($key); ?>[border_radius]" value="<?php echo esc_attr($v['border_radius'] ?? 8); ?>"> px</td></tr>
                        <tr><th>Input height</th><td><input type="number" name="<?php echo esc_attr($key); ?>[input_height]" value="<?php echo esc_attr($v['input_height'] ?? 40); ?>"> px</td></tr>
                        <tr><th>Input width</th><td><input type="number" name="<?php echo esc_attr($key); ?>[input_width]" value="<?php echo esc_attr($v['input_width'] ?? 320); ?>"> px</td></tr>
                        <tr><th>Input background</th><td><input type="color" name="<?php echo esc_attr($key); ?>[input_bg]" value="<?php echo esc_attr($v['input_bg'] ?? '#ffffff'); ?>"></td></tr>
                        <tr><th>Input border color</th><td><input type="color" name="<?php echo esc_attr($key); ?>[input_border_color]" value="<?php echo esc_attr($v['input_border_color'] ?? '#d1d5db'); ?>"></td></tr>
                        <tr><th>Input text color</th><td><input type="color" name="<?php echo esc_attr($key); ?>[input_text_color]" value="<?php echo esc_attr($v['input_text_color'] ?? '#111827'); ?>"></td></tr>
                        <tr><th>Button height</th><td><input type="number" name="<?php echo esc_attr($key); ?>[button_height]" value="<?php echo esc_attr($v['button_height'] ?? 42); ?>"> px</td></tr>
                        <tr><th>Button width</th><td><input type="number" name="<?php echo esc_attr($key); ?>[button_width]" value="<?php echo esc_attr($v['button_width'] ?? 180); ?>"> px</td></tr>
                        <tr><th>Button background</th><td><input type="color" name="<?php echo esc_attr($key); ?>[button_bg]" value="<?php echo esc_attr($v['button_bg'] ?? '#2563eb'); ?>"></td></tr>
                        <tr><th>Button border color</th><td><input type="color" name="<?php echo esc_attr($key); ?>[button_border_color]" value="<?php echo esc_attr($v['button_border_color'] ?? '#1d4ed8'); ?>"></td></tr>
                        <tr><th>Button text color</th><td><input type="color" name="<?php echo esc_attr($key); ?>[button_text_color]" value="<?php echo esc_attr($v['button_text_color'] ?? '#ffffff'); ?>"></td></tr>
                        <tr><th>Submit icon class</th><td><input name="<?php echo esc_attr($key); ?>[button_icon_class]" value="<?php echo esc_attr($v['button_icon_class'] ?? 'fa-solid fa-right-to-bracket'); ?>" class="regular-text"><p class="description">Ví dụ: fa-solid fa-arrow-right</p></td></tr>
                        <tr><th>Redirect URL</th><td><input name="<?php echo esc_attr($key); ?>[redirect_url]" value="<?php echo esc_attr($v['redirect_url'] ?? ''); ?>" class="regular-text"></td></tr>
                        <tr><th>Label username</th><td><input name="<?php echo esc_attr($key); ?>[label_username]" value="<?php echo esc_attr($v['label_username'] ?? 'Username'); ?>"></td></tr>
                        <?php if ($tab === 'register') : ?><tr><th>Label email</th><td><input name="<?php echo esc_attr($key); ?>[label_email]" value="<?php echo esc_attr($v['label_email'] ?? 'Email'); ?>"></td></tr><?php endif; ?>
                        <tr><th>Label password</th><td><input name="<?php echo esc_attr($key); ?>[label_password]" value="<?php echo esc_attr($v['label_password'] ?? 'Password'); ?>"></td></tr>
                        <tr><th>Label button</th><td><input name="<?php echo esc_attr($key); ?>[label_button]" value="<?php echo esc_attr($v['label_button'] ?? 'Submit'); ?>"></td></tr>
                        <?php if ($tab === 'login') : ?><tr><th>Remember me</th><td><label><input type="checkbox" name="<?php echo esc_attr($key); ?>[remember_me]" value="1" <?php checked(!empty($v['remember_me'])); ?>> Bật</label></td></tr><?php endif; ?>
                        <?php if ($tab === 'register') : ?>
                            <tr><th>Role mặc định</th><td><input name="<?php echo esc_attr($key); ?>[default_role]" value="<?php echo esc_attr($v['default_role'] ?? 'subscriber'); ?>"></td></tr>
                            <tr><th>Auto login</th><td><label><input type="checkbox" name="<?php echo esc_attr($key); ?>[auto_login]" value="1" <?php checked(!empty($v['auto_login'])); ?>> Bật</label></td></tr>
                        <?php endif; ?>
                    </table>
                    <?php submit_button('Lưu'); ?>
                </form>
            <?php elseif ($tab === 'quote') : ?>
                <form method="post" action="options.php">
                    <?php settings_fields('lcni_member_settings'); ?>
                    <table class="form-table">
                        <tr><th>Quote width</th><td><input type="number" name="lcni_member_quote_settings[width]" value="<?php echo esc_attr($quote_settings['width'] ?? 500); ?>"> px</td></tr>
                        <tr><th>Quote height</th><td><input type="number" name="lcni_member_quote_settings[height]" value="<?php echo esc_attr($quote_settings['height'] ?? 120); ?>"> px</td></tr>
                        <tr><th>Border radius</th><td><input type="number" name="lcni_member_quote_settings[border_radius]" value="<?php echo esc_attr($quote_settings['border_radius'] ?? 12); ?>"> px</td></tr>
                        <tr><th>Background color</th><td><input type="color" name="lcni_member_quote_settings[background]" value="<?php echo esc_attr($quote_settings['background'] ?? '#f8fafc'); ?>"></td></tr>
                        <tr><th>Blur nền (px)</th><td><input type="number" name="lcni_member_quote_settings[background_blur]" value="<?php echo esc_attr($quote_settings['background_blur'] ?? 0); ?>"></td></tr>
                        <tr><th>Border color</th><td><input type="color" name="lcni_member_quote_settings[border_color]" value="<?php echo esc_attr($quote_settings['border_color'] ?? '#d1d5db'); ?>"></td></tr>
                        <tr><th>Text color</th><td><input type="color" name="lcni_member_quote_settings[text_color]" value="<?php echo esc_attr($quote_settings['text_color'] ?? '#334155'); ?>"></td></tr>
                        <tr><th>Font size</th><td><input type="number" name="lcni_member_quote_settings[font_size]" value="<?php echo esc_attr($quote_settings['font_size'] ?? 16); ?>"> px</td></tr>
                        <tr><th>Quote list (1 dòng 1 quote)</th><td><textarea name="lcni_member_quote_settings[quote_list]" class="large-text" rows="5"><?php echo esc_textarea($quote_settings['quote_list'] ?? ''); ?></textarea></td></tr>
                        <tr><th>CSV URL (mỗi dòng 1 quote)</th><td><input name="lcni_member_quote_settings[quote_csv_url]" value="<?php echo esc_attr($quote_settings['quote_csv_url'] ?? ''); ?>" class="regular-text"></td></tr>
                    </table>
                    <?php submit_button('Lưu'); ?>
                </form>
            <?php else : ?>
                <h3>Tạo gói</h3>
                <form method="post"><?php wp_nonce_field('lcni_member_saas_action'); ?><input name="package_name" placeholder="Tên gói"><button class="button button-primary" name="lcni_member_create_package" value="1">Tạo</button></form>
                <h3>Gán gói</h3>
                <form method="post"><?php wp_nonce_field('lcni_member_saas_action'); ?><input name="user_id" type="number" placeholder="User ID"><input name="role_slug" placeholder="role (hoặc để trống)"><select name="package_id"><?php foreach ($packages as $package) { echo '<option value="' . esc_attr($package['id']) . '">' . esc_html($package['package_name']) . '</option>'; } ?></select><button class="button" name="lcni_member_assign_package" value="1">Gán</button></form>
                <h3>Phân quyền</h3>
                <form method="post"><?php wp_nonce_field('lcni_member_saas_action'); ?><select name="permission_package_id"><?php foreach ($packages as $package) { echo '<option value="' . esc_attr($package['id']) . '">' . esc_html($package['package_name']) . '</option>'; } ?></select>
                    <p>Module keys ví dụ: chart, filter, watchlist, member-login, member-register</p>
                    <?php for ($i = 0; $i < 5; $i++) : ?>
                        <p><input name="permissions[<?php echo esc_attr($i); ?>][module_key]" placeholder="module_key"> <input name="permissions[<?php echo esc_attr($i); ?>][table_name]" placeholder="table"> <input name="permissions[<?php echo esc_attr($i); ?>][column_name]" placeholder="column"> <label><input type="checkbox" name="permissions[<?php echo esc_attr($i); ?>][can_view]" value="1">view</label> <label><input type="checkbox" name="permissions[<?php echo esc_attr($i); ?>][can_export]" value="1">export</label> <label><input type="checkbox" name="permissions[<?php echo esc_attr($i); ?>][can_filter]" value="1">filter</label> <label><input type="checkbox" name="permissions[<?php echo esc_attr($i); ?>][can_realtime]" value="1">realtime</label></p>
                    <?php endfor; ?>
                    <button class="button button-primary" name="lcni_member_save_permissions" value="1">Lưu quyền</button>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }
}
