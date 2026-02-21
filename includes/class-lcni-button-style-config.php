<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_Button_Style_Config {
    const OPTION_KEY = 'lcni_button_style_config';

    public static function get_button_keys() {
        return [
            'btn_filter_open' => 'Filter: Open',
            'btn_apply_filter' => 'Filter: Apply',
            'btn_save_filter' => 'Filter: Save Columns',
            'btn_add_filter_row' => 'Filter: Add Watchlist',
            'btn_filter_setting' => 'Filter: Settings',
            'btn_stock_view' => 'Stock Detail: View',
            'btn_watchlist_add' => 'Watchlist: Add/Remove Symbol',
            'btn_watchlist_save' => 'Watchlist: Save',
            'btn_watchlist_setting' => 'Watchlist: Settings',
            'btn_watchlist_add_symbol' => 'Watchlist: Add Symbol',
        ];
    }

    public static function sanitize_config($input) {
        $input = is_array($input) ? $input : [];
        $sanitized = [];

        foreach (array_keys(self::get_button_keys()) as $button_key) {
            $sanitized[$button_key] = self::sanitize_button_entry(isset($input[$button_key]) && is_array($input[$button_key]) ? $input[$button_key] : []);
        }

        return $sanitized;
    }

    public static function get_config() {
        $saved = get_option(self::OPTION_KEY, []);
        return self::sanitize_config(is_array($saved) ? $saved : []);
    }

    public static function get_button($button_key) {
        $config = self::get_config();
        return $config[$button_key] ?? self::sanitize_button_entry([]);
    }

    public static function enqueue_frontend_assets($style_handle) {
        wp_register_style('lcni-fa', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css', [], '6.5.2');
        wp_enqueue_style('lcni-fa');

        $css = self::build_dynamic_css(self::get_config());
        if ($css !== '') {
            wp_add_inline_style($style_handle, $css);
        }
    }

    public static function build_button_content($button_key, $fallback_label = '') {
        $button = self::get_button($button_key);
        $icon_class = trim((string) ($button['icon_class'] ?? ''));
        $label = (string) ($button['label_text'] !== '' ? $button['label_text'] : $fallback_label);
        $icon_html = $icon_class !== '' ? '<i class="' . esc_attr($icon_class) . '" aria-hidden="true"></i>' : '';
        $label_html = '<span>' . esc_html($label) . '</span>';

        if (($button['icon_position'] ?? 'left') === 'right') {
            return $label_html . $icon_html;
        }

        return $icon_html . $label_html;
    }

    private static function sanitize_button_entry(array $button) {
        return [
            'background_color' => sanitize_hex_color((string) ($button['background_color'] ?? '#2563eb')) ?: '#2563eb',
            'text_color' => sanitize_hex_color((string) ($button['text_color'] ?? '#ffffff')) ?: '#ffffff',
            'hover_background_color' => sanitize_hex_color((string) ($button['hover_background_color'] ?? '#1d4ed8')) ?: '#1d4ed8',
            'hover_text_color' => sanitize_hex_color((string) ($button['hover_text_color'] ?? '#ffffff')) ?: '#ffffff',
            'height' => self::sanitize_css_size($button['height'] ?? '36px', '36px'),
            'border_radius' => self::sanitize_css_size($button['border_radius'] ?? '8px', '8px'),
            'padding_left_right' => self::sanitize_css_size($button['padding_left_right'] ?? '12px', '12px'),
            'font_size' => self::sanitize_css_size($button['font_size'] ?? '14px', '14px'),
            'icon_class' => sanitize_text_field((string) ($button['icon_class'] ?? 'fa-solid fa-circle')),
            'icon_position' => in_array(($button['icon_position'] ?? 'left'), ['left', 'right'], true) ? $button['icon_position'] : 'left',
            'label_text' => sanitize_text_field((string) ($button['label_text'] ?? '')),
        ];
    }

    private static function sanitize_css_size($value, $default) {
        $value = trim((string) $value);
        if (preg_match('/^\d+(\.\d+)?(px|em|rem|%)$/', $value)) {
            return $value;
        }

        return $default;
    }

    private static function build_dynamic_css(array $config) {
        $css = '.lcni-btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;text-decoration:none;border:0;cursor:pointer;}' . "\n";

        foreach ($config as $key => $button) {
            $class = '.lcni-btn-' . sanitize_html_class((string) $key);
            $css .= sprintf(
                "%s{background:%s;color:%s;height:%s;border-radius:%s;padding:0 %s;font-size:%s;}\n",
                $class,
                esc_attr($button['background_color']),
                esc_attr($button['text_color']),
                esc_attr($button['height']),
                esc_attr($button['border_radius']),
                esc_attr($button['padding_left_right']),
                esc_attr($button['font_size'])
            );
            $css .= sprintf(
                "%s:hover,%s:focus{background:%s;color:%s;}\n",
                $class,
                $class,
                esc_attr($button['hover_background_color']),
                esc_attr($button['hover_text_color'])
            );
        }

        return $css;
    }
}
