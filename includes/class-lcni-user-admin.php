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

        $package = isset($_POST['lcni_user_package']) ? sanitize_key(wp_unslash($_POST['lcni_user_package'])) : '';
        if (!in_array($package, ['free', 'premium'], true)) {
            $package = 'free';
        }

        update_user_meta($user_id, 'lcni_user_package', $package);
    }
}
