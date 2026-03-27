<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_Update_Data_Page {

    const PAGE_SLUG = 'lcni-update-data';
    const ACTION_HANDLE = 'lcni_update_data_action';
    const NONCE_ACTION = 'lcni_update_data_nonce_action';

    public function __construct() {
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_post_' . self::ACTION_HANDLE, [$this, 'handle_actions']);
    }

    public function register_menu() {
        add_submenu_page(
            'lcni-settings',
            'Update Data',
            'Update Data',
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

        $control_action = isset($_POST['lcni_update_action']) ? sanitize_key(wp_unslash($_POST['lcni_update_action'])) : '';
        $message = '';
        $type = 'success';

        if ($control_action === 'run_snapshot_now') {
            $status = LCNI_OHLC_Latest_Manager::trigger_manual_sync();
            $message = empty($status['error']) ? 'Snapshot sync completed successfully.' : 'Snapshot sync failed: ' . (string) $status['error'];
            $type = empty($status['error']) ? 'success' : 'error';
        } elseif ($control_action === 'reset_cron') {
            LCNI_OHLC_Latest_Manager::reset_cron();
            $message = 'WP-Cron schedules have been reset.';
        } elseif ($control_action === 'recreate_mysql_event') {
            LCNI_OHLC_Latest_Manager::recreate_mysql_event();
            $message = 'MySQL event/procedure infrastructure has been recreated.';
        } elseif ($control_action === 'force_full_rebuild') {
            $status = LCNI_OHLC_Latest_Manager::force_full_rebuild();
            $message = empty($status['error']) ? 'Full snapshot rebuild completed successfully.' : 'Full snapshot rebuild failed: ' . (string) $status['error'];
            $type = empty($status['error']) ? 'success' : 'error';
        } else {
            $message = 'Unknown action.';
            $type = 'error';
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

        $engine_status = LCNI_OHLC_Latest_Manager::get_engine_status();
        $snapshot_stats = LCNI_OHLC_Latest_Manager::get_snapshot_stats();

        $notice = isset($_GET['lcni_notice']) ? sanitize_text_field(wp_unslash($_GET['lcni_notice'])) : '';
        $notice_type = isset($_GET['lcni_notice_type']) ? sanitize_key(wp_unslash($_GET['lcni_notice_type'])) : 'success';
        $notice_css = $notice_type === 'error' ? 'notice notice-error' : 'notice notice-success';

        $event_scheduler = $engine_status['event_scheduler'] ?? ['enabled' => false, 'value' => 'unknown'];
        $next_run_timestamp = (int) ($engine_status['next_run_timestamp'] ?? 0);
        $time_remaining = $next_run_timestamp > 0 ? human_time_diff(current_time('timestamp'), $next_run_timestamp) : '-';

        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Update Data', 'lcni-data-collector'); ?></h1>

            <?php if ($notice !== '') : ?>
                <div class="<?php echo esc_attr($notice_css); ?> is-dismissible"><p><?php echo esc_html($notice); ?></p></div>
            <?php endif; ?>

            <?php if (empty($event_scheduler['enabled'])) : ?>
                <div class="notice notice-warning"><p><?php echo esc_html__('MySQL Event Scheduler is OFF. Background MySQL event execution may not run.', 'lcni-data-collector'); ?></p></div>
            <?php endif; ?>

            <h2><?php echo esc_html__('Engine Status', 'lcni-data-collector'); ?></h2>
            <table class="widefat striped" style="max-width: 900px;">
                <tbody>
                <tr><th><?php echo esc_html__('MySQL Event Scheduler', 'lcni-data-collector'); ?></th><td><?php echo esc_html(strtoupper((string) ($event_scheduler['value'] ?? 'unknown'))); ?></td></tr>
                <tr><th><?php echo esc_html__('WP-Cron Status', 'lcni-data-collector'); ?></th><td><?php echo esc_html(!empty($engine_status['wp_cron_scheduled']) ? 'Scheduled' : 'Not Scheduled'); ?></td></tr>
                <tr><th><?php echo esc_html__('Last Snapshot Time', 'lcni-data-collector'); ?></th><td><?php echo esc_html((string) ($engine_status['last_snapshot_time'] ?: '-')); ?></td></tr>
                <tr><th><?php echo esc_html__('Last Run Time', 'lcni-data-collector'); ?></th><td><?php echo esc_html((string) (($snapshot_stats['last_run'] ?? '') ?: '-')); ?></td></tr>
                <tr><th><?php echo esc_html__('Next Scheduled Run', 'lcni-data-collector'); ?></th><td><?php echo esc_html((string) ($engine_status['next_run'] ?: '-')); ?></td></tr>
                <tr><th><?php echo esc_html__('Time Remaining', 'lcni-data-collector'); ?></th><td><?php echo esc_html($time_remaining === '-' ? '-' : $time_remaining); ?></td></tr>
                <tr><th><?php echo esc_html__('Interval (minutes)', 'lcni-data-collector'); ?></th><td><?php echo esc_html((string) ($engine_status['interval_minutes'] ?? 0)); ?></td></tr>
                </tbody>
            </table>

            <h2 style="margin-top: 24px;"><?php echo esc_html__('Snapshot Statistics', 'lcni-data-collector'); ?></h2>
            <table class="widefat striped" style="max-width: 900px;">
                <tbody>
                <tr><th><?php echo esc_html__('Total symbols updated', 'lcni-data-collector'); ?></th><td><?php echo esc_html((string) ((int) ($snapshot_stats['total_symbols'] ?? 0))); ?></td></tr>
                <tr><th><?php echo esc_html__('Rows affected', 'lcni-data-collector'); ?></th><td><?php echo esc_html((string) ((int) ($snapshot_stats['rows_affected'] ?? 0))); ?></td></tr>
                <tr><th><?php echo esc_html__('Update duration (seconds)', 'lcni-data-collector'); ?></th><td><?php echo esc_html((string) ((int) ($snapshot_stats['duration_seconds'] ?? 0))); ?></td></tr>
                <tr><th><?php echo esc_html__('Market Up count', 'lcni-data-collector'); ?></th><td><span style="color:#2e7d32;font-weight:600;"><?php echo esc_html((string) ((int) ($snapshot_stats['market_stats']['up'] ?? 0))); ?></span></td></tr>
                <tr><th><?php echo esc_html__('Market Down count', 'lcni-data-collector'); ?></th><td><span style="color:#c62828;font-weight:600;"><?php echo esc_html((string) ((int) ($snapshot_stats['market_stats']['down'] ?? 0))); ?></span></td></tr>
                <tr><th><?php echo esc_html__('Market Flat count', 'lcni-data-collector'); ?></th><td><span style="color:#616161;font-weight:600;"><?php echo esc_html((string) ((int) ($snapshot_stats['market_stats']['flat'] ?? 0))); ?></span></td></tr>
                </tbody>
            </table>

            <h2 style="margin-top: 24px;"><?php echo esc_html__('Manual Controls', 'lcni-data-collector'); ?></h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field(self::NONCE_ACTION); ?>
                <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_HANDLE); ?>">
                <p>
                    <button type="submit" class="button button-primary" name="lcni_update_action" value="run_snapshot_now"><?php echo esc_html__('Run Snapshot Now', 'lcni-data-collector'); ?></button>
                    <button type="submit" class="button" name="lcni_update_action" value="reset_cron"><?php echo esc_html__('Reset Cron', 'lcni-data-collector'); ?></button>
                    <button type="submit" class="button" name="lcni_update_action" value="recreate_mysql_event"><?php echo esc_html__('Recreate MySQL Event', 'lcni-data-collector'); ?></button>
                    <button type="submit" class="button" name="lcni_update_action" value="force_full_rebuild"><?php echo esc_html__('Force Full Rebuild', 'lcni-data-collector'); ?></button>
                </p>
            </form>
        </div>
        <?php
    }
}
