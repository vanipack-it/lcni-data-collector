class LCNI_Settings {

    public function __construct() {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_init', [$this, 'register_settings']);
    }

    public function menu() {
        add_menu_page(
            'LCNI Settings',
            'LCNI Data',
            'manage_options',
            'lcni-settings',
            [$this, 'settings_page']
        );
    }

    public function register_settings() {
        register_setting('lcni_settings_group', 'lcni_api_key');
        register_setting('lcni_settings_group', 'lcni_api_secret');
    }

    public function settings_page() {
        ?>
        <div class="wrap">
            <h1>LCNI API Settings</h1>
            <form method="post" action="options.php">
                <?php settings_fields('lcni_settings_group'); ?>

                <table class="form-table">
                    <tr>
                        <th>API Key</th>
                        <td>
                            <input type="text" name="lcni_api_key"
                                   value="<?php echo esc_attr(get_option('lcni_api_key')); ?>"
                                   size="50">
                        </td>
                    </tr>

                    <tr>
                        <th>API Secret</th>
                        <td>
                            <input type="password" name="lcni_api_secret"
                                   value="<?php echo esc_attr(get_option('lcni_api_secret')); ?>"
                                   size="50">
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}
