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
        return $this->sanitize_common_settings($input, ['remember_me'], 'lcni_member_login_bg_image_file');
    }

    public function sanitize_register_settings($input) {
        $sanitized = $this->sanitize_common_settings($input, ['auto_login'], 'lcni_member_register_bg_image_file');
        $sanitized['default_role'] = sanitize_key($input['default_role'] ?? 'subscriber');
        return $sanitized;
    }

    public function sanitize_quote_settings($input) {
        $input = is_array($input) ? $input : [];
        $current = get_option('lcni_member_quote_settings', []);
        if (is_array($current)) {
            $input = wp_parse_args($input, $current);
        }

        $quote_csv_url = $this->handle_uploaded_file(
            'lcni_member_quote_csv_file',
            ['csv' => 'text/csv', 'txt' => 'text/plain'],
            (string) ($input['quote_csv_url'] ?? '')
        );

        return [
            'width' => max(200, absint($input['width'] ?? 500)),
            'height' => max(60, absint($input['height'] ?? 120)),
            'border_radius' => absint($input['border_radius'] ?? 12),
            'background' => sanitize_hex_color($input['background'] ?? '#f8fafc'),
            'background_blur' => absint($input['background_blur'] ?? 0),
            'border_color' => sanitize_hex_color($input['border_color'] ?? '#d1d5db'),
            'text_color' => sanitize_hex_color($input['text_color'] ?? '#334155'),
            'font_size' => max(10, absint($input['font_size'] ?? 16)),
            'font_family' => sanitize_text_field($input['font_family'] ?? 'inherit'),
            'text_align' => in_array(($input['text_align'] ?? 'left'), ['left', 'center', 'right'], true) ? $input['text_align'] : 'left',
            'effect' => in_array(($input['effect'] ?? 'normal'), ['normal', 'italic', 'bold', 'uppercase', 'shadow'], true) ? $input['effect'] : 'normal',
            'preview_text' => sanitize_text_field($input['preview_text'] ?? 'Market quote preview'),
            'quote_list' => sanitize_textarea_field($input['quote_list'] ?? ''),
            'quote_csv_url' => esc_url_raw($quote_csv_url),
        ];
    }

    private function sanitize_common_settings($input, $bool_keys, $background_upload_field) {
        $input = is_array($input) ? $input : [];
        $option_key = in_array('remember_me', $bool_keys, true)
            ? 'lcni_member_login_settings'
            : 'lcni_member_register_settings';
        $current = get_option($option_key, []);
        if (is_array($current)) {
            $input = wp_parse_args($input, $current);
        }

        $background_image = $this->handle_uploaded_file(
            $background_upload_field,
            [
                'jpg|jpeg|jpe' => 'image/jpeg',
                'gif' => 'image/gif',
                'png' => 'image/png',
                'webp' => 'image/webp',
            ],
            (string) ($input['background_image'] ?? '')
        );

        $sanitized = [
            'font' => sanitize_text_field($input['font'] ?? ''),
            'text_color' => sanitize_hex_color($input['text_color'] ?? '#1f2937'),
            'background' => sanitize_hex_color($input['background'] ?? '#ffffff'),
            'background_image' => esc_url_raw($background_image),
            'border_color' => sanitize_hex_color($input['border_color'] ?? '#d1d5db'),
            'border_radius' => absint($input['border_radius'] ?? 8),
            'form_box_background' => sanitize_hex_color($input['form_box_background'] ?? '#ffffff'),
            'form_box_border_color' => sanitize_hex_color($input['form_box_border_color'] ?? '#d1d5db'),
            'form_box_border_radius' => absint($input['form_box_border_radius'] ?? 10),
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
            'register_button_label' => sanitize_text_field($input['register_button_label'] ?? 'Đăng ký'),
            'register_page_id' => absint($input['register_page_id'] ?? 0),
        ];

        foreach ($bool_keys as $key) {
            $sanitized[$key] = !empty($input[$key]) ? 1 : 0;
        }

        return $sanitized;
    }

    private function handle_uploaded_file($field_name, $mimes, $fallback_url) {
        if (empty($_FILES[$field_name]['tmp_name'])) {
            return (string) $fallback_url;
        }

        if (!function_exists('wp_handle_upload')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $uploaded = wp_handle_upload($_FILES[$field_name], [
            'test_form' => false,
            'mimes' => $mimes,
        ]);

        if (is_array($uploaded) && !empty($uploaded['url'])) {
            return (string) $uploaded['url'];
        }

        return (string) $fallback_url;
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
                <form method="post" action="options.php" enctype="multipart/form-data">
                    <?php settings_fields('lcni_member_settings'); ?>
                    <?php $key = $tab === 'login' ? 'lcni_member_login_settings' : 'lcni_member_register_settings'; $v = $tab === 'login' ? $login : $register; ?>
                    <table class="form-table">
                        <tr><th>Font</th><td><input name="<?php echo esc_attr($key); ?>[font]" value="<?php echo esc_attr($v['font'] ?? 'inherit'); ?>" class="regular-text"></td></tr>
                        <tr><th>Text color</th><td><input type="color" name="<?php echo esc_attr($key); ?>[text_color]" value="<?php echo esc_attr($v['text_color'] ?? '#1f2937'); ?>"></td></tr>
                        <tr><th>Background</th><td><input type="color" name="<?php echo esc_attr($key); ?>[background]" value="<?php echo esc_attr($v['background'] ?? '#ffffff'); ?>"></td></tr>
                        <tr>
                            <th>Background image</th>
                            <td>
                                <input type="file" name="<?php echo $tab === 'login' ? 'lcni_member_login_bg_image_file' : 'lcni_member_register_bg_image_file'; ?>" accept="image/*">
                                <input type="hidden" name="<?php echo esc_attr($key); ?>[background_image]" value="<?php echo esc_attr($v['background_image'] ?? ''); ?>">
                                <?php if (!empty($v['background_image'])) : ?>
                                    <p class="description">Đang dùng: <code><?php echo esc_html($v['background_image']); ?></code></p>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr><th>Border color</th><td><input type="color" name="<?php echo esc_attr($key); ?>[border_color]" value="<?php echo esc_attr($v['border_color'] ?? '#d1d5db'); ?>"></td></tr>
                        <tr><th>Border radius</th><td><input type="number" name="<?php echo esc_attr($key); ?>[border_radius]" value="<?php echo esc_attr($v['border_radius'] ?? 8); ?>"> px</td></tr>
                        <tr><th>Form box background</th><td><input type="color" name="<?php echo esc_attr($key); ?>[form_box_background]" value="<?php echo esc_attr($v['form_box_background'] ?? '#ffffff'); ?>"></td></tr>
                        <tr><th>Form box border color</th><td><input type="color" name="<?php echo esc_attr($key); ?>[form_box_border_color]" value="<?php echo esc_attr($v['form_box_border_color'] ?? '#d1d5db'); ?>"></td></tr>
                        <tr><th>Form box border radius</th><td><input type="number" name="<?php echo esc_attr($key); ?>[form_box_border_radius]" value="<?php echo esc_attr($v['form_box_border_radius'] ?? 10); ?>"> px</td></tr>
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
                        <?php if ($tab === 'login') : ?>
                            <tr><th>Label button đăng ký</th><td><input name="<?php echo esc_attr($key); ?>[register_button_label]" value="<?php echo esc_attr($v['register_button_label'] ?? 'Đăng ký'); ?>"></td></tr>
                            <tr>
                                <th>Trang mở khi bấm Đăng ký</th>
                                <td>
                                    <?php
                                    wp_dropdown_pages([
                                        'name' => $key . '[register_page_id]',
                                        'selected' => absint($v['register_page_id'] ?? 0),
                                        'show_option_none' => '-- Chọn trang --',
                                        'option_none_value' => '0',
                                    ]);
                                    ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                        <?php if ($tab === 'login') : ?><tr><th>Remember me</th><td><label><input type="checkbox" name="<?php echo esc_attr($key); ?>[remember_me]" value="1" <?php checked(!empty($v['remember_me'])); ?>> Bật</label></td></tr><?php endif; ?>
                        <?php if ($tab === 'register') : ?>
                            <tr><th>Role mặc định</th><td><input name="<?php echo esc_attr($key); ?>[default_role]" value="<?php echo esc_attr($v['default_role'] ?? 'subscriber'); ?>"></td></tr>
                            <tr><th>Auto login</th><td><label><input type="checkbox" name="<?php echo esc_attr($key); ?>[auto_login]" value="1" <?php checked(!empty($v['auto_login'])); ?>> Bật</label></td></tr>
                        <?php endif; ?>
                    </table>
                    <?php submit_button('Lưu'); ?>
                </form>
            <?php elseif ($tab === 'quote') : ?>
                <form method="post" action="options.php" enctype="multipart/form-data">
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
                        <tr><th>Font family</th><td><input class="regular-text" name="lcni_member_quote_settings[font_family]" value="<?php echo esc_attr($quote_settings['font_family'] ?? 'inherit'); ?>"></td></tr>
                        <tr><th>Căn lề</th><td><select name="lcni_member_quote_settings[text_align]"><option value="left" <?php selected(($quote_settings['text_align'] ?? 'left'), 'left'); ?>>Left</option><option value="center" <?php selected(($quote_settings['text_align'] ?? 'left'), 'center'); ?>>Center</option><option value="right" <?php selected(($quote_settings['text_align'] ?? 'left'), 'right'); ?>>Right</option></select></td></tr>
                        <tr><th>Hiệu ứng chữ</th><td><select name="lcni_member_quote_settings[effect]"><option value="normal" <?php selected(($quote_settings['effect'] ?? 'normal'), 'normal'); ?>>Normal</option><option value="italic" <?php selected(($quote_settings['effect'] ?? 'normal'), 'italic'); ?>>Italic</option><option value="bold" <?php selected(($quote_settings['effect'] ?? 'normal'), 'bold'); ?>>Bold</option><option value="uppercase" <?php selected(($quote_settings['effect'] ?? 'normal'), 'uppercase'); ?>>Uppercase</option><option value="shadow" <?php selected(($quote_settings['effect'] ?? 'normal'), 'shadow'); ?>>Shadow</option></select></td></tr>
                        <tr><th>Preview quote (1 dòng)</th><td><input class="regular-text" id="lcni-quote-preview-input" name="lcni_member_quote_settings[preview_text]" value="<?php echo esc_attr($quote_settings['preview_text'] ?? 'Market quote preview'); ?>"><p id="lcni-quote-preview" style="margin-top:8px;padding:10px;border:1px dashed #cbd5e1;"><?php echo esc_html($quote_settings['preview_text'] ?? 'Market quote preview'); ?></p></td></tr>
                        <tr><th>Quote list (1 dòng 1 quote)</th><td><textarea name="lcni_member_quote_settings[quote_list]" class="large-text" rows="5"><?php echo esc_textarea($quote_settings['quote_list'] ?? ''); ?></textarea></td></tr>
                        <tr>
                            <th>CSV file (mỗi dòng 1 quote)</th>
                            <td>
                                <input type="file" name="lcni_member_quote_csv_file" accept=".csv,.txt,text/csv,text/plain">
                                <input type="hidden" name="lcni_member_quote_settings[quote_csv_url]" value="<?php echo esc_attr($quote_settings['quote_csv_url'] ?? ''); ?>">
                                <?php if (!empty($quote_settings['quote_csv_url'])) : ?>
                                    <p class="description">Đang dùng: <code><?php echo esc_html($quote_settings['quote_csv_url']); ?></code></p>
                                <?php endif; ?>
                            </td>
                        </tr>
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

            <?php if ($tab === 'quote') : ?>
                <script>
                    document.addEventListener('DOMContentLoaded', function () {
                        var preview = document.getElementById('lcni-quote-preview');
                        var textInput = document.getElementById('lcni-quote-preview-input');
                        var fontInput = document.querySelector('input[name="lcni_member_quote_settings[font_family]"]');
                        var alignInput = document.querySelector('select[name="lcni_member_quote_settings[text_align]"]');
                        var effectInput = document.querySelector('select[name="lcni_member_quote_settings[effect]"]');
                        var colorInput = document.querySelector('input[name="lcni_member_quote_settings[text_color]"]');

                        if (!preview || !textInput || !fontInput || !alignInput || !effectInput || !colorInput) {
                            return;
                        }

                        var applyStyle = function () {
                            var effect = effectInput.value;
                            preview.textContent = textInput.value || 'Market quote preview';
                            preview.style.fontFamily = fontInput.value || 'inherit';
                            preview.style.textAlign = alignInput.value || 'left';
                            preview.style.color = colorInput.value || '#334155';
                            preview.style.fontStyle = effect === 'italic' ? 'italic' : 'normal';
                            preview.style.fontWeight = effect === 'bold' ? '700' : '400';
                            preview.style.textTransform = effect === 'uppercase' ? 'uppercase' : 'none';
                            preview.style.textShadow = effect === 'shadow' ? '1px 1px 2px rgba(15,23,42,0.35)' : 'none';
                        };

                        [textInput, fontInput, alignInput, effectInput, colorInput].forEach(function (node) {
                            node.addEventListener('input', applyStyle);
                            node.addEventListener('change', applyStyle);
                        });

                        applyStyle();
                    });
                </script>
            <?php endif; ?>
        </div>
        <?php
    }
}
