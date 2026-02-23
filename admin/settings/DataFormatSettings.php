<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_Data_Format_Settings {

    const OPTION_KEY = 'lcni_data_format_settings';
    const SETTINGS_GROUP = 'lcni_data_format_settings_group';
    const PAGE_SLUG = 'lcni-data-format-settings';

    const MODULE_SCOPE_KEYS = [
        'dashboard',
        'stock_detail',
        'screener',
        'watchlist',
        'market_overview',
    ];

    const MODULE_SCOPE_LABELS = [
        'dashboard' => 'Dashboard',
        'stock_detail' => 'Stock Detail',
        'screener' => 'Screener',
        'watchlist' => 'Watchlist',
        'market_overview' => 'Market Overview',
    ];

    const MULTIPLY_100_FIELDS = [
        'pct_t_1',
        'pct_t_3',
        'pct_1w',
        'pct_1m',
        'pct_3m',
        'pct_6m',
        'pct_1y',
        'gia_sv_ma10',
        'gia_sv_ma20',
        'gia_sv_ma50',
        'gia_sv_ma100',
        'gia_sv_ma200',
        'vol_sv_vol_ma10',
        'vol_sv_vol_ma20',
    ];

    const ALREADY_PERCENT_FIELDS = [
        'eps_1y_pct',
        'dt_1y_pct',
        'bien_ln_gop',
        'bien_ln_rong',
        'roe',
        'co_tuc_pct',
        'so_huu_nn_pct',
        'tang_truong_dt_quy_gan_nhat',
        'tang_truong_dt_quy_gan_nhi',
        'tang_truong_ln_quy_gan_nhat',
        'tang_truong_ln_quy_gan_nhi',
    ];

    public function __construct() {
        add_action('admin_init', [$this, 'register_settings']);
    }

    public static function get_defaults() {
        return [
            'use_intl' => true,
            'locale' => 'vi-VN',
            'compact_numbers' => true,
            'compact_threshold' => 1000,
            'decimals' => [
                'price' => 2,
                'percent' => 2,
                'rsi' => 1,
                'macd' => 2,
                'pe' => 2,
                'pb' => 2,
                'rs' => 1,
                'volume' => 1,
            ],
            'percent_normalization' => [
                'multiply_100_fields' => self::MULTIPLY_100_FIELDS,
                'already_percent_fields' => self::ALREADY_PERCENT_FIELDS,
            ],
            'module_scope' => array_fill_keys(self::MODULE_SCOPE_KEYS, true),
        ];
    }

    public static function get_settings() {
        $saved = get_option(self::OPTION_KEY, []);

        return self::sanitize_settings($saved);
    }

    public static function get_multiply_100_fields() {
        return self::MULTIPLY_100_FIELDS;
    }

    public static function get_already_percent_fields() {
        return self::ALREADY_PERCENT_FIELDS;
    }

    public static function get_module_scope_labels() {
        return self::MODULE_SCOPE_LABELS;
    }

    public function register_settings() {
        register_setting(
            self::SETTINGS_GROUP,
            self::OPTION_KEY,
            [
                'type' => 'array',
                'default' => self::get_defaults(),
                'sanitize_callback' => [self::class, 'sanitize_settings'],
            ]
        );
    }

    private static function sanitize_field_selection($value, $allowed_fields) {
        if (!is_array($value)) {
            return [];
        }

        $allowed_map = array_fill_keys($allowed_fields, true);
        $sanitized = [];

        foreach ($value as $field) {
            $normalized_field = sanitize_key((string) $field);
            if ($normalized_field !== '' && isset($allowed_map[$normalized_field])) {
                $sanitized[] = $normalized_field;
            }
        }

        return array_values(array_unique($sanitized));
    }

    public static function sanitize_settings($value) {
        $defaults = self::get_defaults();
        $input = is_array($value) ? $value : [];

        $sanitized = [
            'use_intl' => !empty($input['use_intl']),
            'locale' => in_array($input['locale'] ?? '', ['vi-VN', 'en-US'], true) ? $input['locale'] : $defaults['locale'],
            'compact_numbers' => !empty($input['compact_numbers']),
            'compact_threshold' => max(0, absint($input['compact_threshold'] ?? $defaults['compact_threshold'])),
            'decimals' => $defaults['decimals'],
            'percent_normalization' => [
                'multiply_100_fields' => $defaults['percent_normalization']['multiply_100_fields'],
                'already_percent_fields' => $defaults['percent_normalization']['already_percent_fields'],
            ],
            'module_scope' => $defaults['module_scope'],
        ];

        $decimals = isset($input['decimals']) && is_array($input['decimals']) ? $input['decimals'] : [];

        foreach ($defaults['decimals'] as $type => $default_value) {
            $raw = isset($decimals[$type]) ? $decimals[$type] : $default_value;
            $sanitized['decimals'][$type] = min(8, max(0, absint($raw)));
        }

        $normalization_input = isset($input['percent_normalization']) && is_array($input['percent_normalization'])
            ? $input['percent_normalization']
            : [];

        $sanitized['percent_normalization']['multiply_100_fields'] = self::sanitize_field_selection(
            $normalization_input['multiply_100_fields'] ?? [],
            self::MULTIPLY_100_FIELDS
        );

        $sanitized['percent_normalization']['already_percent_fields'] = self::sanitize_field_selection(
            $normalization_input['already_percent_fields'] ?? [],
            self::ALREADY_PERCENT_FIELDS
        );

        $module_scope_input = isset($input['module_scope']) && is_array($input['module_scope'])
            ? $input['module_scope']
            : [];

        foreach (self::MODULE_SCOPE_KEYS as $module_key) {
            $sanitized['module_scope'][$module_key] = !empty($module_scope_input[$module_key]);
        }

        return $sanitized;
    }

    public function render_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings = self::get_settings();
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Data Format', 'lcni'); ?></h1>
            <form method="post" action="options.php">
                <?php settings_fields(self::SETTINGS_GROUP); ?>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php echo esc_html__('Use Intl.NumberFormat', 'lcni'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[use_intl]" value="1" <?php checked(!empty($settings['use_intl'])); ?> />
                                <?php echo esc_html__('Enable Intl-based number formatter', 'lcni'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Locale', 'lcni'); ?></th>
                        <td>
                            <select name="<?php echo esc_attr(self::OPTION_KEY); ?>[locale]">
                                <option value="vi-VN" <?php selected($settings['locale'], 'vi-VN'); ?>>vi-VN</option>
                                <option value="en-US" <?php selected($settings['locale'], 'en-US'); ?>>en-US</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Use compact numbers (K/M/B)', 'lcni'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[compact_numbers]" value="1" <?php checked(!empty($settings['compact_numbers'])); ?> />
                                <?php echo esc_html__('Compact large numeric values', 'lcni'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Compact threshold', 'lcni'); ?></th>
                        <td>
                            <input type="number" min="0" step="1" name="<?php echo esc_attr(self::OPTION_KEY); ?>[compact_threshold]" value="<?php echo esc_attr((string) $settings['compact_threshold']); ?>" />
                        </td>
                    </tr>
                </table>

                <?php submit_button(__('Save Data Format Settings', 'lcni')); ?>
            </form>
        </div>
        <?php
    }
}
