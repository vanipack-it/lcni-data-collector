<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_Filter_Admin_Settings {

    public static function sanitize_allowed_columns($columns) {
        $service = new LCNI_WatchlistService(new LCNI_WatchlistRepository());
        $watchlist_columns = $service->get_allowed_columns();
        $columns = is_array($columns) ? array_map('sanitize_key', $columns) : [];
        $columns = array_values(array_intersect($watchlist_columns, $columns));

        return empty($columns) ? $watchlist_columns : $columns;
    }

    public static function sanitize_default_conditions($input, $allowed_columns) {
        $decoded = json_decode((string) $input, true);
        if (!is_array($decoded)) {
            return [];
        }

        $table = new LCNI_FilterTable(new LCNI_WatchlistRepository(), new LCNI_WatchlistService(new LCNI_WatchlistRepository()));

        return $table->sanitize_filters($decoded, $allowed_columns);
    }

    public static function render_filter_form($tab_id) {
        $service = new LCNI_WatchlistService(new LCNI_WatchlistRepository());
        $watchlist_columns = $service->get_allowed_columns();
        $allowed_columns = self::sanitize_allowed_columns(get_option('lcni_filter_allowed_columns', []));
        $default_conditions = get_option('lcni_filter_default_conditions', []);
        ?>
        <div id="<?php echo esc_attr($tab_id); ?>" class="lcni-sub-tab-content">
            <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=lcni-settings')); ?>" class="lcni-front-form">
                <?php wp_nonce_field('lcni_admin_actions', 'lcni_action_nonce'); ?>
                <input type="hidden" name="lcni_admin_action" value="save_frontend_settings">
                <input type="hidden" name="lcni_frontend_module" value="filter">
                <input type="hidden" name="lcni_redirect_tab" value="<?php echo esc_attr($tab_id); ?>">

                <h3>Filterable columns selector</h3>
                <p class="description">Nguồn cột lấy từ watchlist available columns config.</p>
                <div class="lcni-front-grid">
                    <?php foreach ($watchlist_columns as $column) : ?>
                        <label><input type="checkbox" name="lcni_filter_allowed_columns[]" value="<?php echo esc_attr($column); ?>" <?php checked(in_array($column, $allowed_columns, true)); ?>> <?php echo esc_html($column); ?></label>
                    <?php endforeach; ?>
                </div>

                <h3>Default filter set by admin</h3>
                <p class="description">Lưu option <code>lcni_filter_default_conditions</code> dưới dạng JSON.</p>
                <textarea name="lcni_filter_default_conditions" rows="8" class="large-text code"><?php echo esc_textarea(wp_json_encode($default_conditions, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></textarea>
                <?php submit_button('Save'); ?>
            </form>
        </div>
        <?php
    }
}
