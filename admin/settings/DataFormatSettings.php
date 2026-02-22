<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_Data_Format_Settings {

    const OPTION_KEY = 'lcni_data_format_settings';
    const SETTINGS_GROUP = 'lcni_data_format_settings_group';
    const PAGE_SLUG = 'lcni-data-format-settings';

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
        ];
    }

    public static function get_settings() {
        $saved = get_option(self::OPTION_KEY, []);

        return self::sanitize_settings($saved);
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

    public static function sanitize_settings($value) {
        $defaults = self::get_defaults();
        $input = is_array($value) ? $value : [];

        $sanitized = [
            'use_intl' => !empty($input['use_intl']),
            'locale' => in_array($input['locale'] ?? '', ['vi-VN', 'en-US'], true) ? $input['locale'] : $defaults['locale'],
            'compact_numbers' => !empty($input['compact_numbers']),
            'compact_threshold' => max(0, absint($input['compact_threshold'] ?? $defaults['compact_threshold'])),
            'decimals' => $defaults['decimals'],
        ];

        $decimals = isset($input['decimals']) && is_array($input['decimals']) ? $input['decimals'] : [];

        foreach ($defaults['decimals'] as $type => $default_value) {
            $raw = isset($decimals[$type]) ? $decimals[$type] : $default_value;
            $sanitized['decimals'][$type] = min(8, max(0, absint($raw)));
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

                <h2><?php echo esc_html__('Decimal precision by data type', 'lcni'); ?></h2>
                <table class="form-table" role="presentation">
                    <?php foreach ($settings['decimals'] as $type => $precision) : ?>
                        <tr>
                            <th scope="row"><?php echo esc_html(ucfirst(str_replace('_', ' ', (string) $type))); ?></th>
                            <td>
                                <input
                                    type="number"
                                    min="0"
                                    max="8"
                                    step="1"
                                    name="<?php echo esc_attr(self::OPTION_KEY); ?>[decimals][<?php echo esc_attr((string) $type); ?>]"
                                    value="<?php echo esc_attr((string) $precision); ?>"
                                />
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>

                <?php submit_button(__('Save Data Format Settings', 'lcni')); ?>
            </form>
        </div>
        <?php
    }
}
