<?php
if (! defined('ABSPATH')) {
    exit;
}

$settings = isset($settings) && is_array($settings) ? $settings : LCNI_Industry_Settings::get_settings();
$metric_options = isset($metric_options) && is_array($metric_options) ? $metric_options : array();
$container_id = isset($container_id) ? (string) $container_id : ('lcni-industry-monitor-' . wp_generate_password(8, false, false));

$css_vars = sprintf(
    '--lcni-row-bg:%1$s;--lcni-row-border-color:%2$s;--lcni-row-border-width:%3$dpx;--lcni-table-border-color:%4$s;--lcni-table-border-width:%5$dpx;--lcni-header-bg:%6$s;--lcni-header-height:%7$dpx;--lcni-row-font-size:%8$dpx;--lcni-table-height:%9$dvh;--lcni-event-time-col-width:%10$dpx;--lcni-dropdown-height:%11$dpx;--lcni-dropdown-width:%12$dpx;--lcni-dropdown-border-color:%13$s;--lcni-dropdown-border-width:%14$dpx;',
    esc_attr($settings['row_bg_color']),
    esc_attr($settings['row_border_color']),
    (int) $settings['row_border_width'],
    esc_attr($settings['table_border_color']),
    (int) $settings['table_border_width'],
    esc_attr($settings['header_bg_color']),
    (int) $settings['header_height'],
    (int) $settings['row_font_size'],
    (int) $settings['table_height'],
    (int) $settings['event_time_col_width'],
    (int) $settings['dropdown_height'],
    (int) $settings['dropdown_width'],
    esc_attr($settings['dropdown_border_color']),
    (int) $settings['dropdown_border_width']
);
?>
<div
    class="lcni-industry-monitor"
    id="<?php echo esc_attr($container_id); ?>"
    data-monitor-id="<?php echo esc_attr($container_id); ?>"
    data-row-hover-enabled="<?php echo ! empty($settings['row_hover_enabled']) ? '1' : '0'; ?>"
    style="<?php echo esc_attr($css_vars); ?>"
>
    <div class="lcni-industry-monitor__table-wrap lcni-table-wrapper">
        <table class="lcni-industry-monitor__table lcni-table">
            <thead>
                <tr class="lcni-industry-header-row">
                    <th class="lcni-industry-monitor__sticky-industry">
                        <div class="lcni-industry-monitor__industry-head">
                            <div class="lcni-industry-monitor__metric-dropdown">
                                <button type="button" class="lcni-industry-monitor__metric-toggle"><?php esc_html_e('Vui lòng chọn Giá trị thống kê', 'lcni-industry-monitor'); ?></button>
                                <div class="lcni-industry-monitor__metric-menu" hidden>
                                    <input class="lcni-industry-monitor__metric-search" type="search" placeholder="<?php esc_attr_e('Type to find metric...', 'lcni-industry-monitor'); ?>" autocomplete="off" />
                                    <div class="lcni-industry-monitor__metric-options">
                                        <?php foreach ($metric_options as $metric) : ?>
                                            <button type="button" class="lcni-industry-monitor__metric-option" data-value="<?php echo esc_attr($metric['key']); ?>"><?php echo esc_html($metric['label']); ?></button>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                            <input type="hidden" class="lcni-industry-metric" value="" />
                        </div>
                    </th>
                </tr>
            </thead>
            <tbody class="lcni-industry-body"></tbody>
        </table>
    </div>
    <div class="lcni-industry-monitor__full-link-wrap" hidden>
        <a class="lcni-industry-monitor__full-link" href="#" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Xem đầy đủ bảng ngành', 'lcni-industry-monitor'); ?></a>
    </div>
</div>
