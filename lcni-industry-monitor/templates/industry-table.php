<?php
if (! defined('ABSPATH')) {
    exit;
}

$settings = isset($settings) && is_array($settings) ? $settings : LCNI_Industry_Settings::get_settings();
$metric_options = isset($metric_options) && is_array($metric_options) ? $metric_options : array();

$css_vars = sprintf(
    '--lcni-row-bg:%1$s;--lcni-row-border-color:%2$s;--lcni-row-border-width:%3$dpx;--lcni-table-border-color:%4$s;--lcni-table-border-width:%5$dpx;--lcni-row-height:%6$dpx;--lcni-header-bg:%7$s;--lcni-header-height:%8$dpx;--lcni-row-font-size:%9$dpx;',
    esc_attr($settings['row_bg_color']),
    esc_attr($settings['row_border_color']),
    (int) $settings['row_border_width'],
    esc_attr($settings['table_border_color']),
    (int) $settings['table_border_width'],
    (int) $settings['row_height'],
    esc_attr($settings['header_bg_color']),
    (int) $settings['header_height'],
    (int) $settings['row_font_size']
);
?>
<div
    class="lcni-industry-monitor"
    data-default-timeframe="<?php echo esc_attr('1D'); ?>"
    data-gradient-mode="<?php echo esc_attr($settings['gradient_mode']); ?>"
    data-row-hover-enabled="<?php echo ! empty($settings['row_hover_enabled']) ? '1' : '0'; ?>"
    style="<?php echo esc_attr($css_vars); ?>"
>
    <div class="lcni-industry-monitor__controls">
        <label for="lcni-industry-metric-search"><?php esc_html_e('Metric', 'lcni-industry-monitor'); ?></label>
        <div class="lcni-industry-monitor__metric-picker">
            <input id="lcni-industry-metric-search" class="lcni-industry-monitor__metric-search" type="search" placeholder="<?php esc_attr_e('Type to find metric...', 'lcni-industry-monitor'); ?>" autocomplete="off" />
            <select id="lcni-industry-metric" class="lcni-industry-monitor__metric" size="6">
                <?php foreach ($metric_options as $metric) : ?>
                    <option value="<?php echo esc_attr($metric['key']); ?>"><?php echo esc_html($metric['label']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <label for="lcni-industry-session-limit"><?php esc_html_e('Sessions', 'lcni-industry-monitor'); ?></label>
        <input id="lcni-industry-session-limit" class="lcni-industry-monitor__session-limit" type="number" min="1" max="200" value="<?php echo esc_attr((string) (int) $settings['default_session_limit']); ?>" />

        <label for="lcni-industry-timeframe"><?php esc_html_e('Timeframe', 'lcni-industry-monitor'); ?></label>
        <select id="lcni-industry-timeframe" class="lcni-industry-monitor__timeframe">
            <option value="1D"><?php echo esc_html('1D'); ?></option>
            <option value="1W"><?php echo esc_html('1W'); ?></option>
            <option value="1M"><?php echo esc_html('1M'); ?></option>
        </select>
    </div>

    <div class="lcni-industry-monitor__table-wrap">
        <table class="lcni-industry-monitor__table">
            <thead>
                <tr id="lcni-industry-header-row">
                    <th><?php esc_html_e('Industry', 'lcni-industry-monitor'); ?></th>
                </tr>
            </thead>
            <tbody id="lcni-industry-body"></tbody>
        </table>
    </div>
</div>
