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
        $shared_table = self::sanitize_button_entry(array_merge(self::get_default_button_entry('__shared_table'), self::extract_shared_config($input, '__shared_table', 'btn_watchlist_remove_symbol', [])));
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
            $allow_outside_override = in_array($button_key, self::get_outside_override_button_keys(), true);

            if ($allow_outside_override) {
                $is_private_color = in_array($button_key, self::get_private_color_button_keys(), true);
                if ($is_private_color) {
                    // Dùng màu default của button này; chỉ override nếu admin đã chỉnh raw
                    $merged['background_color']       = isset($raw['background_color'])       ? $merged['background_color']       : $defaults['background_color'];
                    $merged['text_color']             = isset($raw['text_color'])             ? $merged['text_color']             : $defaults['text_color'];
                    $merged['hover_background_color'] = isset($raw['hover_background_color']) ? $merged['hover_background_color'] : $defaults['hover_background_color'];
                    $merged['hover_text_color']       = isset($raw['hover_text_color'])       ? $merged['hover_text_color']       : $defaults['hover_text_color'];
                } else {
                    $merged['background_color'] = isset($raw['background_color']) ? $merged['background_color'] : $shared_all['background_color'];
                    $merged['text_color'] = isset($raw['text_color']) ? $merged['text_color'] : $shared_all['text_color'];
                    $merged['hover_background_color'] = isset($raw['hover_background_color']) ? $merged['hover_background_color'] : $shared_all['hover_background_color'];
                    $merged['hover_text_color'] = isset($raw['hover_text_color']) ? $merged['hover_text_color'] : $shared_all['hover_text_color'];
                }
            } else {
                $merged['background_color'] = $shared_all['background_color'];
                $merged['text_color'] = $shared_all['text_color'];
                $merged['hover_background_color'] = $shared_all['hover_background_color'];
                $merged['hover_text_color'] = $shared_all['hover_text_color'];
            }

            if (in_array($button_key, self::get_table_button_keys(), true)) {
                $merged['height'] = $shared_table['height'];
                $merged['font_size'] = $shared_table['font_size'];
                $merged['padding_left_right'] = $shared_table['padding_left_right'];
                $merged['text_color'] = $shared_table['text_color'];
                $merged['hover_text_color'] = $shared_table['hover_text_color'];
            } else {
                if ($allow_outside_override) {
                    $is_private_color = in_array($button_key, self::get_private_color_button_keys(), true);
                    if ($is_private_color) {
                        // Dùng kích thước default riêng; chỉ override nếu admin đã chỉnh raw
                        $merged['height']             = isset($raw['height'])             ? $merged['height']             : $defaults['height'];
                        $merged['font_size']          = isset($raw['font_size'])          ? $merged['font_size']          : $defaults['font_size'];
                        $merged['padding_left_right'] = isset($raw['padding_left_right']) ? $merged['padding_left_right'] : $defaults['padding_left_right'];
                    } else {
                        $merged['height'] = isset($raw['height']) ? $merged['height'] : $shared_outside['height'];
                        $merged['font_size'] = isset($raw['font_size']) ? $merged['font_size'] : $shared_outside['font_size'];
                        $merged['padding_left_right'] = isset($raw['padding_left_right']) ? $merged['padding_left_right'] : $shared_outside['padding_left_right'];
                    }
                } else {
                    $merged['height'] = $shared_outside['height'];
                    $merged['font_size'] = $shared_outside['font_size'];
                    $merged['padding_left_right'] = $shared_outside['padding_left_right'];
                }
            }

            $sanitized[$button_key] = self::sanitize_button_entry($merged);
        }

        return $sanitized;
    }

    public static function get_table_button_keys() {
        return [
            'btn_add_filter_row',
            'btn_watchlist_remove_symbol',
            'btn_watchlist_remove_symbol_row',
            'btn_recommend_signal_add',
            'btn_signal_follow_add',
            'btn_rule_follow_add',
        ];
    }

    public static function get_outside_override_button_keys() {
        return [
            'btn_filter_watchlist_login',
            'btn_filter_watchlist_register',
            'btn_filter_watchlist_close',
            'btn_popup_confirm',
            'btn_popup_close',
            // lcni_rule_follow
            'btn_rf_follow',
            'btn_rf_following',
            'btn_rf_signal',
            'btn_rf_performance',
            // lcni_signals_rule / lcni_performance_v2 (Rule Action Bar)
            'btn_rab_follow',
            'btn_rab_following',
            'btn_rab_auto',
            // lcni_user_rule
            'btn_ur_next',
            'btn_ur_submit',
            'btn_ur_new',
        ];
    }

    /**
     * Các nút outside có màu riêng theo design — không kế thừa shared_all khi chưa có raw override.
     * Admin vẫn override được, nhưng mặc định dùng màu từ get_default_button_entry() của key đó.
     */
    public static function get_private_color_button_keys() {
        return [
            'btn_rf_follow',
            'btn_rf_following',
            'btn_rf_signal',
            'btn_rf_performance',
            'btn_rab_follow',
            'btn_rab_following',
            'btn_rab_auto',
            'btn_ur_next',
            'btn_ur_submit',
            'btn_ur_new',
        ];
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

    /**
     * Trả về CSS string để nhúng trực tiếp vào <style> tag trong shortcode output.
     * Dùng khi shortcode render sau wp_head — wp_add_inline_style() quá muộn.
     */
    public static function get_inline_css(): string {
        return self::build_dynamic_css(self::get_config());
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
            'btn_filter_add_watchlist_bulk' => ['Filter: Add Result to Watchlist', 'filter'],
            'btn_filter_watchlist_login' => ['Filter: Watchlist Login', 'filter'],
            'btn_filter_watchlist_register' => ['Filter: Watchlist Register', 'filter'],
            'btn_filter_watchlist_close' => ['Filter: Watchlist Close', 'filter'],
            'btn_filter_export_excel' => ['Filter: Export Excel', 'filter'],
            'btn_add_filter_row' => ['Filter: Add Watchlist', 'filter'],
            'btn_recommend_signal_add' => ['Recommend Signal: Add Watchlist', 'filter'],
            'btn_signal_follow_add' => ['Signal Follow: Add Watchlist', 'filter'],
            'btn_rule_follow_add' => ['Rule Follow: Add Watchlist', 'filter'],
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
            // lcni_rule_follow buttons (ngoài bảng)
            'btn_rf_follow'       => ['Rule Follow: Nút Theo dõi', 'recommend'],
            'btn_rf_following'    => ['Rule Follow: Nút Đang theo dõi (active)', 'recommend'],
            'btn_rf_signal'       => ['Rule Follow: Nút Tín hiệu (icon)', 'recommend'],
            'btn_rf_performance'  => ['Rule Follow: Nút Hiệu suất (icon)', 'recommend'],
            // lcni_signals_rule / lcni_performance_v2 Rule Action Bar buttons
            'btn_rab_follow'      => ['Rule Action Bar: Nút Theo dõi', 'recommend'],
            'btn_rab_following'   => ['Rule Action Bar: Nút Đang theo dõi', 'recommend'],
            'btn_rab_auto'        => ['Rule Action Bar: Nút Tự động', 'recommend'],
            // lcni_user_rule
            'btn_ur_next'         => ['User Rule: Nút Tiếp theo (wizard)', 'recommend'],
            'btn_ur_submit'       => ['User Rule: Nút Bắt đầu áp dụng (submit)', 'recommend'],
            'btn_ur_new'          => ['User Rule: Nút Áp dụng chiến lược mới', 'recommend'],
        ];

        foreach ($defaults as $key => $meta) {
            LCNI_Button_Registry::register($key, $meta[0], $meta[1]);
        }
    }

    private static function sanitize_button_entry(array $button) {
        return [
            'background_color'       => sanitize_hex_color((string) ($button['background_color'] ?? '#2563eb')) ?: '#2563eb',
            'text_color'             => sanitize_hex_color((string) ($button['text_color'] ?? '#ffffff')) ?: '#ffffff',
            'hover_background_color' => sanitize_hex_color((string) ($button['hover_background_color'] ?? '#1d4ed8')) ?: '#1d4ed8',
            'hover_text_color'       => sanitize_hex_color((string) ($button['hover_text_color'] ?? '#ffffff')) ?: '#ffffff',
            'border'                 => sanitize_text_field((string) ($button['border'] ?? '0')),
            'height'                 => self::sanitize_css_size($button['height'] ?? '', ''),
            'border_radius'          => self::sanitize_css_size($button['border_radius'] ?? '8px', '8px'),
            'padding_left_right'     => self::sanitize_css_size($button['padding_left_right'] ?? '', ''),
            'font_size'              => self::sanitize_css_size($button['font_size'] ?? '', ''),
            'icon_class'             => sanitize_text_field((string) ($button['icon_class'] ?? 'fa-solid fa-circle')),
            'icon_position'          => in_array(($button['icon_position'] ?? 'left'), ['left', 'right'], true) ? $button['icon_position'] : 'left',
            'label_text'             => sanitize_text_field((string) ($button['label_text'] ?? '')),
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
            'background_color'       => '#2563eb',
            'text_color'             => '#ffffff',
            'hover_background_color' => '#1d4ed8',
            'hover_text_color'       => '#ffffff',
            'border'                 => '0',
            'height'                 => '36px',
            'border_radius'          => '8px',
            'padding_left_right'     => '12px',
            'font_size'              => '14px',
            'icon_class'             => 'fa-solid fa-circle',
            'icon_position'          => 'left',
            'label_text'             => '',
        ];

        // __shared_table: nút bên trong bảng — kích thước đồng nhất, admin tuỳ chỉnh tự do
        if ($button_key === '__shared_table') {
            $shared['height']             = '28px';
            $shared['font_size']          = '12px';
            $shared['padding_left_right'] = '6px';
            $shared['text_color']         = '#2563eb';
            $shared['hover_text_color']   = '#1d4ed8';
        }

        // Table add-to-watchlist buttons: chỉ override icon/label/radius — kích thước do __shared_table
        if (in_array($button_key, ['btn_add_filter_row', 'btn_recommend_signal_add', 'btn_signal_follow_add', 'btn_rule_follow_add'], true)) {
            $shared['icon_class']        = 'fa-solid fa-heart-circle-plus';
            $shared['label_text']        = '';
            $shared['border_radius']     = '6px';
        }

        if (in_array($button_key, ['btn_overview_setting', 'btn_chart_setting', 'btn_signals_setting', 'btn_watchlist_setting', 'btn_filter_setting'], true)) {
            $shared['icon_class']        = 'fa-solid fa-gear';
            $shared['label_text']        = '';
            $shared['height']            = '32px';
            $shared['padding_left_right'] = '10px';
            $shared['font_size']         = '13px';
        }

        // lcni_rule_follow: nút Follow (ngoài bảng)
        if ( $button_key === 'btn_rf_follow' ) {
            $shared['label_text']            = 'Theo dõi';
            $shared['icon_class']            = 'fa-solid fa-bell';
            $shared['icon_position']         = 'left';
            $shared['border']                = '1.5px solid #2563eb';
            $shared['border_radius']         = '20px';
            $shared['height']                = '32px';
            $shared['font_size']             = '12px';
            $shared['padding_left_right']    = '12px';
            $shared['background_color']      = '#ffffff';
            $shared['text_color']            = '#2563eb';
            $shared['hover_background_color']= '#eff6ff';
            $shared['hover_text_color']      = '#1d4ed8';
        }

        // lcni_rule_follow: nút Đang theo dõi (active state)
        if ( $button_key === 'btn_rf_following' ) {
            $shared['label_text']            = 'Đang theo dõi';
            $shared['icon_class']            = 'fa-solid fa-circle-check';
            $shared['icon_position']         = 'left';
            $shared['border']                = '1.5px solid #2563eb';
            $shared['border_radius']         = '20px';
            $shared['height']                = '32px';
            $shared['font_size']             = '12px';
            $shared['padding_left_right']    = '12px';
            $shared['background_color']      = '#2563eb';
            $shared['text_color']            = '#ffffff';
            $shared['hover_background_color']= '#1d4ed8';
            $shared['hover_text_color']      = '#ffffff';
        }

        // lcni_rule_follow: nút Icon Tín hiệu
        if ( $button_key === 'btn_rf_signal' ) {
            $shared['label_text']            = '';
            $shared['icon_class']            = 'fa-solid fa-chart-bar';
            $shared['icon_position']         = 'left';
            $shared['border']                = '0';
            $shared['border_radius']         = '8px';
            $shared['height']                = '30px';
            $shared['font_size']             = '14px';
            $shared['padding_left_right']    = '6px';
            $shared['background_color']      = '#f3f4f6';
            $shared['text_color']            = '#374151';
            $shared['hover_background_color']= '#dbeafe';
            $shared['hover_text_color']      = '#1d4ed8';
        }

        // lcni_rule_follow: nút Icon Hiệu suất
        if ( $button_key === 'btn_rf_performance' ) {
            $shared['label_text']            = '';
            $shared['icon_class']            = 'fa-solid fa-chart-line';
            $shared['icon_position']         = 'left';
            $shared['border']                = '0';
            $shared['border_radius']         = '8px';
            $shared['height']                = '30px';
            $shared['font_size']             = '14px';
            $shared['padding_left_right']    = '6px';
            $shared['background_color']      = '#f3f4f6';
            $shared['text_color']            = '#374151';
            $shared['hover_background_color']= '#dbeafe';
            $shared['hover_text_color']      = '#1d4ed8';
        }

        // Rule Action Bar: nút Theo dõi
        if ( $button_key === 'btn_rab_follow' ) {
            $shared['label_text']            = 'Theo dõi';
            $shared['icon_class']            = 'fa-solid fa-bell';
            $shared['icon_position']         = 'left';
            $shared['border']                = '1.5px solid #2563eb';
            $shared['border_radius']         = '20px';
            $shared['height']                = '34px';
            $shared['font_size']             = '13px';
            $shared['padding_left_right']    = '16px';
            $shared['background_color']      = '#ffffff';
            $shared['text_color']            = '#2563eb';
            $shared['hover_background_color']= '#eff6ff';
            $shared['hover_text_color']      = '#1d4ed8';
        }

        // Rule Action Bar: nút Đang theo dõi (active state)
        if ( $button_key === 'btn_rab_following' ) {
            $shared['label_text']            = 'Đang theo dõi';
            $shared['icon_class']            = 'fa-solid fa-circle-check';
            $shared['icon_position']         = 'left';
            $shared['border']                = '1.5px solid #2563eb';
            $shared['border_radius']         = '20px';
            $shared['height']                = '34px';
            $shared['font_size']             = '13px';
            $shared['padding_left_right']    = '16px';
            $shared['background_color']      = '#2563eb';
            $shared['text_color']            = '#ffffff';
            $shared['hover_background_color']= '#1d4ed8';
            $shared['hover_text_color']      = '#ffffff';
        }

        // Rule Action Bar: nút Tự động
        if ( $button_key === 'btn_rab_auto' ) {
            $shared['label_text']            = 'Tự động';
            $shared['icon_class']            = 'fa-solid fa-gear';
            $shared['icon_position']         = 'left';
            $shared['border']                = '1.5px solid #6b7280';
            $shared['border_radius']         = '20px';
            $shared['height']                = '34px';
            $shared['font_size']             = '13px';
            $shared['padding_left_right']    = '16px';
            $shared['background_color']      = '#ffffff';
            $shared['text_color']            = '#374151';
            $shared['hover_background_color']= '#f9fafb';
            $shared['hover_text_color']      = '#111827';
        }

        // lcni_user_rule: nút Tiếp theo (wizard steps)
        if ( $button_key === 'btn_ur_next' ) {
            $shared['label_text']            = 'Tiếp theo';
            $shared['icon_class']            = 'fa-solid fa-arrow-right';
            $shared['icon_position']         = 'right';
            $shared['border']                = '0';
            $shared['border_radius']         = '8px';
            $shared['height']                = '38px';
            $shared['font_size']             = '14px';
            $shared['padding_left_right']    = '18px';
            $shared['background_color']      = '#2563eb';
            $shared['text_color']            = '#ffffff';
            $shared['hover_background_color']= '#1d4ed8';
            $shared['hover_text_color']      = '#ffffff';
        }

        // lcni_user_rule: nút Bắt đầu áp dụng (submit)
        if ( $button_key === 'btn_ur_submit' ) {
            $shared['label_text']            = 'Bắt đầu áp dụng';
            $shared['icon_class']            = 'fa-solid fa-rocket';
            $shared['icon_position']         = 'left';
            $shared['border']                = '0';
            $shared['border_radius']         = '8px';
            $shared['height']                = '38px';
            $shared['font_size']             = '14px';
            $shared['padding_left_right']    = '18px';
            $shared['background_color']      = '#2563eb';
            $shared['text_color']            = '#ffffff';
            $shared['hover_background_color']= '#1d4ed8';
            $shared['hover_text_color']      = '#ffffff';
        }

        // lcni_user_rule: nút Áp dụng chiến lược mới
        if ( $button_key === 'btn_ur_new' ) {
            $shared['label_text']            = 'Áp dụng chiến lược mới';
            $shared['icon_class']            = 'fa-solid fa-plus';
            $shared['icon_position']         = 'left';
            $shared['border']                = '0';
            $shared['border_radius']         = '8px';
            $shared['height']                = '38px';
            $shared['font_size']             = '14px';
            $shared['padding_left_right']    = '18px';
            $shared['background_color']      = '#2563eb';
            $shared['text_color']            = '#ffffff';
            $shared['hover_background_color']= '#1d4ed8';
            $shared['hover_text_color']      = '#ffffff';
        }

        return $shared;
    }

    private static function build_dynamic_css(array $config) {
        // Base button reset
        $css  = '.lcni-btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;text-decoration:none;border:0;cursor:pointer;}' . "\n";

        // Ajax spin → check-circle animation shared by all table add-buttons
        $css .= '@keyframes lcni-btn-spin{to{transform:rotate(360deg)}}' . "\n";
        $css .= '.lcni-btn.is-loading .lcni-btn-icon,.lcni-btn.is-loading i:first-child{animation:lcni-btn-spin .6s linear infinite;display:inline-block;}' . "\n";
        $css .= '.lcni-btn.is-done{pointer-events:none;}' . "\n";

        foreach ($config as $key => $button) {
            if (strpos((string) $key, '__shared_') === 0 || !is_array($button)) {
                continue;
            }

            $class        = '.lcni-btn-' . sanitize_html_class((string) $key);
            $is_table_btn = in_array((string) $key, self::get_table_button_keys(), true);

            // Build CSS props — only include non-empty values (no forced min)
            $height      = esc_attr((string) ($button['height'] ?? ''));
            $font_size   = esc_attr((string) ($button['font_size'] ?? ''));
            $padding     = esc_attr((string) ($button['padding_left_right'] ?? ''));
            $radius      = esc_attr((string) ($button['border_radius'] ?? ''));
            $border      = esc_attr((string) ($button['border'] ?? '0'));

            $props = '';
            if ($height !== '')    $props .= "height:{$height};";
            if ($radius !== '')    $props .= "border-radius:{$radius};";
            if ($padding !== '')   $props .= "padding:0 {$padding};";
            if ($font_size !== '') $props .= "font-size:{$font_size};";

            if ($is_table_btn) {
                $color       = esc_attr((string) ($button['text_color'] ?? ''));
                $hover_color = esc_attr((string) ($button['hover_text_color'] ?? ''));
                $border_css  = $border !== '0' ? "border:{$border};" : '';

                $css .= "{$class}{background:transparent;min-width:0;min-height:0;{$props}" . ($color !== '' ? "color:{$color};" : '') . "}\n";
                if ($border_css) $css .= "{$class}{{$border_css}}\n";
                $css .= "{$class}:hover,{$class}:focus{background:transparent;" . ($hover_color !== '' ? "color:{$hover_color};" : '') . "}\n";
                $css .= "{$class}.is-done,{$class}.is-done:hover{color:#16a34a;}\n";
            } else {
                $bg          = esc_attr((string) ($button['background_color'] ?? ''));
                $color       = esc_attr((string) ($button['text_color'] ?? ''));
                $hover_bg    = esc_attr((string) ($button['hover_background_color'] ?? ''));
                $hover_color = esc_attr((string) ($button['hover_text_color'] ?? ''));
                $border_css  = $border !== '0' ? "border:{$border};" : '';

                $css .= "{$class}{" . ($bg !== '' ? "background:{$bg};" : '') . ($color !== '' ? "color:{$color};" : '') . "{$props}}\n";
                if ($border_css) $css .= "{$class}{{$border_css}}\n";
                $css .= "{$class}:hover,{$class}:focus{" . ($hover_bg !== '' ? "background:{$hover_bg};" : '') . ($hover_color !== '' ? "color:{$hover_color};" : '') . "}\n";
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
