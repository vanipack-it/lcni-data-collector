<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_Industry_Data_Page {

    const PAGE_SLUG = 'lcni-industry-data';
    const ACTION_HANDLE = 'lcni_industry_data_action';
    const NONCE_ACTION = 'lcni_industry_data_nonce_action';

    public function __construct() {
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_post_' . self::ACTION_HANDLE, [$this, 'handle_actions']);
    }

    public function register_menu() {
        add_submenu_page(
            'lcni-settings',
            'Industry Data',
            'Industry Data',
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'render_page']
        );
    }

    public function handle_actions() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Unauthorized request.', 'lcni-data-collector'));
        }

        check_admin_referer(self::NONCE_ACTION);

        $control_action = isset($_POST['lcni_industry_action']) ? sanitize_key(wp_unslash($_POST['lcni_industry_action'])) : '';
        $message = '';
        $type = 'success';

        if ($control_action === 'rebuild_industry_tables') {
            $rows = LCNI_DB::rebuild_industry_analysis_snapshot(['1D'], true);

            if ($rows > 0) {
                update_option('lcni_industry_analysis_backfilled_v1', 'yes');
                $message = sprintf('Đã rebuild dữ liệu ngành thành công. Số phiên xử lý: %d.', (int) $rows);
            } else {
                $type = 'error';
                $message = 'Không rebuild được dữ liệu ngành. Hãy kiểm tra bảng OHLC, mapping ngành hoặc dữ liệu nguồn đầu vào.';
            }
        } else {
            $type = 'error';
            $message = 'Unknown action.';
        }

        $redirect = add_query_arg(
            [
                'page' => self::PAGE_SLUG,
                'lcni_notice' => rawurlencode($message),
                'lcni_notice_type' => $type,
            ],
            admin_url('admin.php')
        );

        wp_safe_redirect($redirect);
        exit;
    }

    public function render_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $stats = LCNI_DB::get_industry_analysis_table_stats();

        $notice = isset($_GET['lcni_notice']) ? sanitize_text_field(wp_unslash($_GET['lcni_notice'])) : '';
        $notice_type = isset($_GET['lcni_notice_type']) ? sanitize_key(wp_unslash($_GET['lcni_notice_type'])) : 'success';
        $notice_css = $notice_type === 'error' ? 'notice notice-error' : 'notice notice-success';

        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Industry Data', 'lcni-data-collector'); ?></h1>

            <?php if ($notice !== '') : ?>
                <div class="<?php echo esc_attr($notice_css); ?> is-dismissible"><p><?php echo esc_html($notice); ?></p></div>
            <?php endif; ?>

            <p><?php echo esc_html__('Trang này giúp kiểm tra nhanh tình trạng dữ liệu ngành và chạy rebuild thủ công khi bảng dữ liệu đang trống.', 'lcni-data-collector'); ?></p>

            <h2><?php echo esc_html__('Industry Materialized Tables', 'lcni-data-collector'); ?></h2>
            <table class="widefat striped" style="max-width: 980px;">
                <thead>
                <tr>
                    <th><?php echo esc_html__('Table', 'lcni-data-collector'); ?></th>
                    <th><?php echo esc_html__('Exists', 'lcni-data-collector'); ?></th>
                    <th><?php echo esc_html__('Rows', 'lcni-data-collector'); ?></th>
                    <th><?php echo esc_html__('Latest event_time', 'lcni-data-collector'); ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($stats as $item) : ?>
                    <tr>
                        <td><code><?php echo esc_html((string) ($item['table'] ?? '')); ?></code></td>
                        <td><?php echo esc_html(!empty($item['exists']) ? 'Yes' : 'No'); ?></td>
                        <td><?php echo esc_html((string) ((int) ($item['rows'] ?? 0))); ?></td>
                        <td><?php echo esc_html((string) ((int) ($item['latest_event_time'] ?? 0))); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <h2 style="margin-top: 24px;"><?php echo esc_html__('Manual Controls', 'lcni-data-collector'); ?></h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field(self::NONCE_ACTION); ?>
                <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_HANDLE); ?>">
                <p>
                    <button type="submit" class="button button-primary" name="lcni_industry_action" value="rebuild_industry_tables">
                        <?php echo esc_html__('Rebuild Industry Tables', 'lcni-data-collector'); ?>
                    </button>
                </p>
            </form>
        </div>
        <?php
    }
}
