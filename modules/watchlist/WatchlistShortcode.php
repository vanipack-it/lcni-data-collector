<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_WatchlistShortcode {

    const OPTION_KEY = 'lcni_watchlist_settings';
    const VERSION = '1.0.0';

    private $service;

    public function __construct(LCNI_WatchlistService $service) {
        $this->service = $service;

        add_action('init', [$this, 'register_shortcodes']);
        add_action('wp_enqueue_scripts', [$this, 'register_assets']);
        add_action('admin_menu', [$this, 'register_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
    }

    public function register_shortcodes() {
        add_shortcode('lcni_watchlist', [$this, 'render_watchlist']);
        add_shortcode('lcni_watchlist_add', [$this, 'render_add_button']);
    }

    public function register_assets() {
        $js = LCNI_PATH . 'modules/watchlist/assets/js/watchlist.js';
        $css = LCNI_PATH . 'modules/watchlist/assets/css/watchlist.css';
        $version = file_exists($js) ? (string) filemtime($js) : self::VERSION;
        $css_version = file_exists($css) ? (string) filemtime($css) : self::VERSION;

        wp_register_script('lcni-watchlist', LCNI_URL . 'modules/watchlist/assets/js/watchlist.js', [], $version, true);
        wp_register_style('lcni-watchlist', LCNI_URL . 'modules/watchlist/assets/css/watchlist.css', [], $css_version);
    }

    public function render_watchlist() {
        $this->enqueue_watchlist_assets();

        return '<div class="lcni-watchlist" data-lcni-watchlist></div>';
    }

    public function render_add_button($atts = []) {
        $atts = shortcode_atts(['symbol' => ''], $atts, 'lcni_watchlist_add');
        $symbol = strtoupper(sanitize_text_field((string) $atts['symbol']));

        if ($symbol === '') {
            return '';
        }

        $this->enqueue_watchlist_assets();

        $settings = $this->get_settings();
        $button_styles = isset($settings['add_button']) && is_array($settings['add_button']) ? $settings['add_button'] : [];
        $icon_class = isset($button_styles['icon']) ? sanitize_text_field($button_styles['icon']) : 'fa-solid fa-heart-circle-plus';

        $style = sprintf(
            'background:%s;color:%s;font-size:%dpx;',
            esc_attr($button_styles['background'] ?? '#dc2626'),
            esc_attr($button_styles['text_color'] ?? '#ffffff'),
            (int) ($button_styles['font_size'] ?? 14)
        );

        return sprintf(
            '<button type="button" class="lcni-watchlist-add" data-lcni-watchlist-add data-symbol="%1$s" style="%2$s"><i class="%3$s" aria-hidden="true"></i></button>',
            esc_attr($symbol),
            esc_attr($style),
            esc_attr($icon_class)
        );
    }

    public function register_admin_menu() {
        add_submenu_page('lcni-settings', 'Watchlist Settings', 'Watchlist Settings', 'manage_options', 'lcni-watchlist-settings', [$this, 'render_admin_page']);
    }

    public function register_settings() {
        register_setting('lcni_watchlist_group', self::OPTION_KEY, ['sanitize_callback' => [$this, 'sanitize_settings']]);
    }

    public function sanitize_settings($input) {
        $all_columns = $this->service->get_all_columns();
        $allowed_columns = isset($input['allowed_columns']) && is_array($input['allowed_columns'])
            ? array_values(array_intersect($all_columns, array_map('sanitize_key', $input['allowed_columns'])))
            : ['symbol', 'close_price', 'pct_t_1', 'volume', 'exchange'];

        return [
            'allowed_columns' => $allowed_columns,
            'styles' => [
                'font' => sanitize_text_field($input['styles']['font'] ?? 'inherit'),
                'text_color' => sanitize_hex_color($input['styles']['text_color'] ?? '#111827') ?: '#111827',
                'background' => sanitize_hex_color($input['styles']['background'] ?? '#ffffff') ?: '#ffffff',
                'border' => sanitize_text_field($input['styles']['border'] ?? '1px solid #e5e7eb'),
                'border_radius' => max(0, min(24, (int) ($input['styles']['border_radius'] ?? 8))),
            ],
            'add_button' => [
                'icon' => sanitize_text_field($input['add_button']['icon'] ?? 'fa-solid fa-heart-circle-plus'),
                'background' => sanitize_hex_color($input['add_button']['background'] ?? '#dc2626') ?: '#dc2626',
                'text_color' => sanitize_hex_color($input['add_button']['text_color'] ?? '#ffffff') ?: '#ffffff',
                'font_size' => max(10, min(24, (int) ($input['add_button']['font_size'] ?? 14))),
            ],
        ];
    }

    public function render_admin_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings = $this->get_settings();
        $all_columns = $this->service->get_all_columns();
        ?>
        <div class="wrap">
            <h1>LCNI Watchlist Settings</h1>
            <form method="post" action="options.php">
                <?php settings_fields('lcni_watchlist_group'); ?>
                <h2>Cột hiển thị frontend</h2>
                <?php foreach ($all_columns as $column): ?>
                    <label style="display:inline-block;min-width:180px;">
                        <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[allowed_columns][]" value="<?php echo esc_attr($column); ?>" <?php checked(in_array($column, $settings['allowed_columns'], true)); ?>>
                        <?php echo esc_html($column); ?>
                    </label>
                <?php endforeach; ?>

                <h2>Style config</h2>
                <p><input type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[styles][font]" value="<?php echo esc_attr($settings['styles']['font']); ?>" placeholder="Font family"></p>
                <p><label>Text color <input type="color" name="<?php echo esc_attr(self::OPTION_KEY); ?>[styles][text_color]" value="<?php echo esc_attr($settings['styles']['text_color']); ?>"></label></p>
                <p><label>Background <input type="color" name="<?php echo esc_attr(self::OPTION_KEY); ?>[styles][background]" value="<?php echo esc_attr($settings['styles']['background']); ?>"></label></p>
                <p><label>Border <input type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[styles][border]" value="<?php echo esc_attr($settings['styles']['border']); ?>"></label></p>
                <p><label>Border radius <input type="number" min="0" max="24" name="<?php echo esc_attr(self::OPTION_KEY); ?>[styles][border_radius]" value="<?php echo esc_attr((string) $settings['styles']['border_radius']); ?>"></label></p>

                <h2>Nút add to watchlist</h2>
                <p><label>FontAwesome icon <input type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[add_button][icon]" value="<?php echo esc_attr($settings['add_button']['icon']); ?>"></label></p>
                <p><label>Background <input type="color" name="<?php echo esc_attr(self::OPTION_KEY); ?>[add_button][background]" value="<?php echo esc_attr($settings['add_button']['background']); ?>"></label></p>
                <p><label>Text color <input type="color" name="<?php echo esc_attr(self::OPTION_KEY); ?>[add_button][text_color]" value="<?php echo esc_attr($settings['add_button']['text_color']); ?>"></label></p>
                <p><label>Font size <input type="number" min="10" max="24" name="<?php echo esc_attr(self::OPTION_KEY); ?>[add_button][font_size]" value="<?php echo esc_attr((string) $settings['add_button']['font_size']); ?>"></label></p>

                <?php submit_button('Save Watchlist Settings'); ?>
            </form>
        </div>
        <?php
    }


    private function enqueue_watchlist_assets() {
        wp_enqueue_script('lcni-watchlist');
        wp_enqueue_style('lcni-watchlist');

        wp_localize_script('lcni-watchlist', 'lcniWatchlistConfig', [
            'restBase' => esc_url_raw(rest_url('lcni/v1/watchlist')),
            'settingsOption' => $this->get_settings(),
            'nonce' => wp_create_nonce('wp_rest'),
            'isLoggedIn' => is_user_logged_in(),
            'loginUrl' => esc_url_raw(wp_login_url(get_permalink() ?: home_url('/'))),
            'stockApiBase' => esc_url_raw(rest_url('lcni/v1/stock/')),
        ]);
    }

    private function get_settings() {
        $saved = get_option(self::OPTION_KEY, []);
        $defaults = [
            'allowed_columns' => ['symbol', 'close_price', 'pct_t_1', 'volume', 'exchange'],
            'styles' => [
                'font' => 'inherit',
                'text_color' => '#111827',
                'background' => '#ffffff',
                'border' => '1px solid #e5e7eb',
                'border_radius' => 8,
            ],
            'add_button' => [
                'icon' => 'fa-solid fa-heart-circle-plus',
                'background' => '#dc2626',
                'text_color' => '#ffffff',
                'font_size' => 14,
            ],
        ];

        return wp_parse_args($saved, $defaults);
    }
}
