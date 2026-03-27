<?php
if (! defined('ABSPATH')) { exit; }

$settings       = isset($settings)       && is_array($settings)       ? $settings       : LCNI_Industry_Settings::get_settings();
$metric_options = isset($metric_options) && is_array($metric_options) ? $metric_options : array();
$container_id   = isset($container_id)   ? (string) $container_id     : ('lcni-im-' . wp_generate_password(8, false, false));

// Global table config
$gtc             = class_exists('LCNI_Table_Config') ? LCNI_Table_Config::get_config() : [];
$g_row_height    = ! empty($gtc['row_height'])        ? (int) $gtc['row_height']        : 36;
$g_header_height = ! empty($gtc['header_height'])     ? (int) $gtc['header_height']     : 42;
$g_row_bg        = ! empty($gtc['row_bg'])            ? esc_attr($gtc['row_bg'])        : '#ffffff';
$g_header_bg     = ! empty($gtc['header_bg'])         ? esc_attr($gtc['header_bg'])     : '#f3f4f6';
$g_divider_color = ! empty($gtc['row_divider_color']) ? esc_attr($gtc['row_divider_color']) : '#e5e7eb';
$g_divider_width = isset($gtc['row_divider_width'])   ? (int) $gtc['row_divider_width'] : 1;
$g_font_size     = ! empty($gtc['row_font_size'])     ? (int) $gtc['row_font_size']     : 13;
$g_max_height    = ! empty($gtc['max_height'])        ? (int) $gtc['max_height']        : 70;
$g_header_color  = ! empty($gtc['header_color'])      ? esc_attr($gtc['header_color'])  : '#111827';
$g_row_color     = ! empty($gtc['row_color'])         ? esc_attr($gtc['row_color'])     : '#111827';

$css_vars = sprintf(
    '--lcni-table-value-bg:%1$s;--lcni-row-bg:%1$s;' .
    '--lcni-row-divider-color:%2$s;--lcni-row-border-color:%2$s;' .
    '--lcni-row-divider-width:%3$dpx;--lcni-row-border-width:%3$dpx;' .
    '--lcni-table-header-bg:%4$s;--lcni-header-bg:%4$s;' .
    '--lcni-table-header-height:%5$dpx;--lcni-header-height:%5$dpx;' .
    '--lcni-table-value-size:%6$dpx;--lcni-row-font-size:%6$dpx;' .
    '--lcni-table-max-height:%7$dvh;--lcni-table-height:%7$dvh;' .
    '--lcni-table-row-height:%8$dpx;' .
    '--lcni-event-time-col-width:%9$dpx;' .
    '--lcni-table-header-color:%10$s;--lcni-header-color:%10$s;' .
    '--lcni-table-value-color:%11$s;--lcni-row-color:%11$s;',
    $g_row_bg, $g_divider_color, $g_divider_width,
    $g_header_bg, $g_header_height, $g_font_size,
    $g_max_height, $g_row_height,
    (int) $settings['event_time_col_width'],
    $g_header_color, $g_row_color
);
?>
<div class="lcni-industry-monitor"
     id="<?php echo esc_attr($container_id); ?>"
     data-monitor-id="<?php echo esc_attr($container_id); ?>"
     data-row-hover-enabled="<?php echo ! empty($settings['row_hover_enabled']) ? '1' : '0'; ?>"
     style="<?php echo esc_attr($css_vars); ?>">

    <!-- ── Toolbar: metric chips + session chips ── -->
    <div class="lcni-im-toolbar">

        <!-- Metric chips — render từ PHP, JS xử lý click + active state -->
        <div class="lcni-im-metric-chips" aria-label="Chỉ số">
            <?php foreach ($metric_options as $opt) : ?>
                <button type="button" class="lcni-im-mchip"
                        data-value="<?php echo esc_attr($opt['key']); ?>">
                    <?php echo esc_html($opt['label']); ?>
                </button>
            <?php endforeach; ?>
        </div>

    </div>
    <input type="hidden" class="lcni-industry-metric" value="" />

    <!-- ── Bảng dữ liệu ── -->
    <div class="lcni-industry-monitor__table-wrap lcni-table-wrapper lcni-table-scroll">
        <table class="lcni-industry-monitor__table lcni-table has-sticky-header">
            <thead>
                <tr class="lcni-industry-header-row">
                    <th class="lcni-industry-monitor__sticky-industry is-sticky-col">
                        <!-- Cột 1: tên ngành/symbol — header rỗng, label nằm trong toolbar -->
                    </th>
                </tr>
            </thead>
            <tbody class="lcni-industry-body"></tbody>
        </table>
    </div>

    <div class="lcni-industry-monitor__full-link-wrap" hidden>
        <a class="lcni-industry-monitor__full-link" href="#" target="_blank" rel="noopener noreferrer">
            <?php esc_html_e('Xem đầy đủ bảng ngành', 'lcni-industry-monitor'); ?>
        </a>
    </div>
</div>
