<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_Button_Style_Config {
    const OPTION_KEY = 'lcni_button_style_config';

    public static function get_button_keys() {
        self::register_default_buttons();
        $registered = LCNI_Button_Registry::getAll();
        $result = [];
        foreach ($registered as $key => $meta) {
            $result[$key] = $meta['label'] ?? $key;
        }

        return $result;
    }

    public static function sanitize_config($input) {
        $input = is_array($input) ? $input : [];
        $sanitized = [];
        $shared_all = self::sanitize_button_entry(array_merge(self::get_default_button_entry('shared'), self::extract_shared_config($input, '__shared_all', null, [])));
        $shared_table = self::sanitize_button_entry(array_merge(self::get_default_button_entry('shared'), self::extract_shared_config($input, '__shared_table', 'btn_watchlist_remove_symbol', [])));
        $shared_outside = self::sanitize_button_entry(array_merge(self::get_default_button_entry('shared'), self::extract_shared_config($input, '__shared_outside', 'btn_filter_open', [])));

        $sanitized['__shared_all'] = [
            'background_color' => $shared_all['background_color'],
            'text_color' => $shared_all['text_color'],
            'hover_background_color' => $shared_all['hover_background_color'],
            'hover_text_color' => $shared_all['hover_text_color'],
        ];

        $sanitized['__shared_table'] = [
            'height' => $shared_table['height'],
            'font_size' => $shared_table['font_size'],
            'padding_left_right' => $shared_table['padding_left_right'],
            'text_color' => $shared_table['text_color'],
            'hover_text_color' => $shared_table['hover_text_color'],
        ];

        $sanitized['__shared_outside'] = [
            'height' => $shared_outside['height'],
            'font_size' => $shared_outside['font_size'],
            'padding_left_right' => $shared_outside['padding_left_right'],
        ];

        foreach (array_keys(self::get_button_keys()) as $button_key) {
            $defaults = self::get_default_button_entry($button_key);
            $raw = isset($input[$button_key]) && is_array($input[$button_key]) ? $input[$button_key] : [];
            $merged = array_merge($defaults, $raw);

            $merged['background_color'] = $shared_all['background_color'];
            $merged['text_color'] = $shared_all['text_color'];
            $merged['hover_background_color'] = $shared_all['hover_background_color'];
            $merged['hover_text_color'] = $shared_all['hover_text_color'];

            if (in_array($button_key, self::get_table_button_keys(), true)) {
                $merged['height'] = $shared_table['height'];
                $merged['font_size'] = $shared_table['font_size'];
                $merged['padding_left_right'] = $shared_table['padding_left_right'];
                $merged['text_color'] = $shared_table['text_color'];
                $merged['hover_text_color'] = $shared_table['hover_text_color'];
            } else {
                $merged['height'] = $shared_outside['height'];
                $merged['font_size'] = $shared_outside['font_size'];
                $merged['padding_left_right'] = $shared_outside['padding_left_right'];
            }

            $sanitized[$button_key] = self::sanitize_button_entry($merged);
        }

        return $sanitized;
    }

    public static function get_table_button_keys() {
        return ['btn_add_filter_row', 'btn_watchlist_remove_symbol', 'btn_watchlist_remove_symbol_row'];
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

    private static function register_default_buttons() {
        $defaults = [
            'btn_filter_open' => ['Filter: Open', 'filter'],
            'btn_apply_filter' => ['Filter: Apply', 'filter'],
            'btn_save_filter' => ['Filter: Save', 'filter'],
            'btn_filter_reload' => ['Filter: Reload', 'filter'],
            'btn_filter_delete' => ['Filter: Delete', 'filter'],
            'btn_filter_save' => ['Filter: Save Current', 'filter'],
            'btn_filter_apply' => ['Filter: Apply', 'filter'],
            'btn_filter_clear' => ['Filter: Clear', 'filter'],
            'btn_add_filter_row' => ['Filter: Add Watchlist', 'filter'],
            'btn_filter_setting' => ['Filter: Settings', 'filter'],
            'btn_filter_hide' => ['Filter: Hide Panel', 'filter'],
            'btn_set_default_filter' => ['Filter: Set Default', 'filter'],
            'btn_stock_view' => ['Stock Detail: View', 'chart'],
            'btn_watchlist_add' => ['Watchlist: Add Symbol', 'watchlist'],
            'btn_watchlist_remove_symbol' => ['Watchlist: Remove Symbol', 'watchlist'],
            'btn_watchlist_remove_symbol_row' => ['Watchlist: Remove Symbol (Row)', 'watchlist'],
            'btn_watchlist_save' => ['Watchlist: Save', 'watchlist'],
            'btn_watchlist_new' => ['Watchlist: New', 'watchlist'],
            'btn_watchlist_delete' => ['Watchlist: Delete', 'watchlist'],
            'btn_watchlist_setting' => ['Watchlist: Settings', 'watchlist'],
            'btn_watchlist_add_symbol' => ['Watchlist: Add Symbol', 'watchlist'],
            'btn_overview_setting' => ['Overview: Settings', 'overview'],
            'btn_chart_setting' => ['Chart: Settings', 'chart'],
            'btn_signals_setting' => ['Signals: Settings', 'signal'],
            'btn_overview_save' => ['Overview: Save', 'overview'],
            'btn_signal_save' => ['LCNI Signal: Save', 'signal'],
            'btn_chart_save' => ['Chart: Save', 'chart'],
            'btn_popup_confirm' => ['Popup: Confirm', 'watchlist'],
            'btn_popup_close' => ['Popup: Close', 'watchlist'],
        ];

        foreach ($defaults as $key => $meta) {
            LCNI_Button_Registry::register($key, $meta[0], $meta[1]);
        }
    }

    private static function sanitize_button_entry(array $button) {
        return [
            'background_color' => sanitize_hex_color((string) ($button['background_color'] ?? '#2563eb')) ?: '#2563eb',
            'text_color' => sanitize_hex_color((string) ($button['text_color'] ?? '#ffffff')) ?: '#ffffff',
            'hover_background_color' => sanitize_hex_color((string) ($button['hover_background_color'] ?? '#1d4ed8')) ?: '#1d4ed8',
            'hover_text_color' => sanitize_hex_color((string) ($button['hover_text_color'] ?? '#ffffff')) ?: '#ffffff',
            'border' => sanitize_text_field((string) ($button['border'] ?? '0')),
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
        if (preg_match('/^\d+(\.\d+)?$/', $value)) {
            return $value . 'px';
        }
        if (preg_match('/^\d+(\.\d+)?(px|em|rem|%)$/', $value)) {
            return $value;
        }

        return $default;
    }

    private static function get_default_button_entry($button_key) {
        $shared = [
            'background_color' => '#2563eb',
            'text_color' => '#ffffff',
            'hover_background_color' => '#1d4ed8',
            'hover_text_color' => '#ffffff',
            'border' => '0',
            'height' => '36px',
            'border_radius' => '8px',
            'padding_left_right' => '12px',
            'font_size' => '14px',
            'icon_class' => 'fa-solid fa-circle',
            'icon_position' => 'left',
            'label_text' => '',
        ];

        if ($button_key === '__shared_table') {
            $shared['text_color'] = '#2563eb';
            $shared['hover_text_color'] = '#1d4ed8';
        }

        if (in_array($button_key, ['btn_overview_setting', 'btn_chart_setting', 'btn_signals_setting', 'btn_watchlist_setting', 'btn_filter_setting'], true)) {
            $shared['icon_class'] = 'fa-solid fa-gear';
            $shared['label_text'] = '';
            $shared['height'] = '32px';
            $shared['padding_left_right'] = '10px';
            $shared['font_size'] = '13px';
        }

        return $shared;
    }

    private static function build_dynamic_css(array $config) {
        $css = '.lcni-btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;text-decoration:none;border:0;cursor:pointer;}' . "\n";

        foreach ($config as $key => $button) {
            if (strpos((string) $key, '__shared_') === 0 || !is_array($button)) {
                continue;
            }

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
            $css .= sprintf("%s{border:%s;}\n", $class, esc_attr((string) ($button['border'] ?? '0')));
            $css .= sprintf(
                "%s:hover,%s:focus{background:%s;color:%s;}\n",
                $class,
                $class,
                esc_attr($button['hover_background_color']),
                esc_attr($button['hover_text_color'])
            );

            if (in_array((string) $key, self::get_table_button_keys(), true)) {
                $css .= sprintf("%s,%s:hover,%s:focus{background:transparent!important;}\n", $class, $class, $class);
            }
        }

        return $css;
    }

    private static function extract_shared_config(array $input, $shared_key, $fallback_button_key = null, array $field_map = []) {
        if (isset($input[$shared_key]) && is_array($input[$shared_key])) {
            return $input[$shared_key];
        }

        if ($fallback_button_key !== null && isset($input[$fallback_button_key]) && is_array($input[$fallback_button_key])) {
            $fallback = $input[$fallback_button_key];
            $result = [];
            foreach ($field_map as $from => $to) {
                if (isset($fallback[$from])) {
                    $result[$to] = $fallback[$from];
                }
            }
            if (empty($result)) {
                return $fallback;
            }

            return $result;
        }

        return [];
    }
}
