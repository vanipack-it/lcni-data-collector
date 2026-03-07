<?php
if (! defined('ABSPATH')) {
    exit;
}
?>
<div class="lcni-industry-monitor" data-default-timeframe="<?php echo esc_attr('1D'); ?>">
    <div class="lcni-industry-monitor__controls">
        <label for="lcni-industry-metric"><?php esc_html_e('Metric', 'lcni-industry-monitor'); ?></label>
        <select id="lcni-industry-metric" class="lcni-industry-monitor__metric">
            <?php foreach ($metrics as $metric_key) : ?>
                <option value="<?php echo esc_attr($metric_key); ?>"><?php echo esc_html($metric_key); ?></option>
            <?php endforeach; ?>
        </select>

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
