<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_Industry_Metrics_Upgrade {

    const CRON_HOOK = 'lcni_compute_industry_metrics_extra';
    const BATCH_SIZE = 500;

    public static function init() {
        if ( class_exists('LCNI_Compute_Control') && ! LCNI_Compute_Control::is_enabled('lcni_compute_industry_metrics') ) {
            return;
        }
        add_filter('cron_schedules', [__CLASS__, 'register_cron_schedule']);
        add_action('init', [__CLASS__, 'ensure_cron_scheduled']);
        add_action(self::CRON_HOOK, [__CLASS__, 'handle_cron']);
        add_action('plugins_loaded', 'lcni_add_missing_columns');
    }

    public static function register_cron_schedule($schedules) {
        if (!isset($schedules['lcni_every_five_minutes'])) {
            $schedules['lcni_every_five_minutes'] = [
                'interval' => 5 * MINUTE_IN_SECONDS,
                'display' => 'Every 5 Minutes (LCNI Industry Metrics)',
            ];
        }

        return $schedules;
    }

    public static function ensure_cron_scheduled() {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(current_time('timestamp') + (2 * MINUTE_IN_SECONDS), 'lcni_every_five_minutes', self::CRON_HOOK);
        }
    }

    public static function handle_cron() {
        try {
            lcni_add_missing_columns();
            $start_event_time = lcni_backfill_missing_metrics();
            $ids = self::get_missing_row_ids(self::BATCH_SIZE, $start_event_time);

            if (empty($ids)) {
                return;
            }

            lcni_compute_momentum_delta_batch($ids);
            lcni_compute_trend_state_vi_batch($ids);
            lcni_compute_industry_rank_batch($ids);
        } catch (Throwable $e) {
            error_log('[LCNI] Industry metrics extra cron failed: ' . $e->getMessage());
        }
    }

    public static function add_missing_columns() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'lcni_industry_metrics';

        self::ensure_index_exists($table_name, 'idx_industry_time', 'event_time,timeframe,id_icb2');
        self::ensure_index_exists($table_name, 'idx_score', 'industry_score_raw');

        if (!self::column_exists($table_name, 'industry_rank')) {
            $sql = "ALTER TABLE {$table_name} ADD COLUMN industry_rank INT DEFAULT 0 AFTER industry_score_raw";
            $wpdb->query($sql);
            if (!empty($wpdb->last_error)) {
                error_log('[LCNI] Failed adding column industry_rank: ' . $wpdb->last_error);
            }
        }

        if (!self::column_exists($table_name, 'momentum_delta')) {
            $sql = "ALTER TABLE {$table_name} ADD COLUMN momentum_delta DECIMAL(16,8) DEFAULT 0 AFTER momentum";
            $wpdb->query($sql);
            if (!empty($wpdb->last_error)) {
                error_log('[LCNI] Failed adding column momentum_delta: ' . $wpdb->last_error);
            }
        }

        if (!self::column_exists($table_name, 'trend_state_vi')) {
            $sql = "ALTER TABLE {$table_name} ADD COLUMN trend_state_vi VARCHAR(50) DEFAULT 'Trung tính' AFTER industry_rank";
            $wpdb->query($sql);
            if (!empty($wpdb->last_error)) {
                error_log('[LCNI] Failed adding column trend_state_vi: ' . $wpdb->last_error);
            }
        }

        if (!self::index_exists($table_name, 'uniq_event_industry_tf')) {
            $sql = "ALTER TABLE {$table_name} ADD UNIQUE KEY uniq_event_industry_tf (event_time,timeframe,id_icb2)";
            $wpdb->query($sql);
            if (!empty($wpdb->last_error)) {
                error_log('[LCNI] Failed adding unique key uniq_event_industry_tf: ' . $wpdb->last_error);
            }
        }
    }

    public static function compute_momentum_delta_batch($ids) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'lcni_industry_metrics';
        foreach (self::sanitize_ids($ids) as $id) {
            $row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT id, event_time, timeframe, id_icb2, momentum
                     FROM {$table_name}
                     WHERE id = %d",
                    $id
                ),
                ARRAY_A
            );

            if (empty($row)) {
                continue;
            }

            $previous_momentum = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT momentum
                     FROM {$table_name}
                     WHERE id_icb2 = %d
                       AND timeframe = %s
                       AND event_time < %s
                     ORDER BY event_time DESC
                     LIMIT 1",
                    (int) $row['id_icb2'],
                    (string) $row['timeframe'],
                    (string) $row['event_time']
                )
            );

            $momentum_today = (float) $row['momentum'];
            $momentum_prev = $previous_momentum !== null ? (float) $previous_momentum : 0.0;
            $momentum_delta = $previous_momentum !== null ? ($momentum_today - $momentum_prev) : 0.0;

            $wpdb->update(
                $table_name,
                ['momentum_delta' => $momentum_delta],
                ['id' => (int) $row['id']],
                ['%f'],
                ['%d']
            );

            if (!empty($wpdb->last_error)) {
                error_log('[LCNI] Failed updating momentum_delta for id ' . (int) $row['id'] . ': ' . $wpdb->last_error);
            }
        }
    }

    public static function compute_trend_state_vi_batch($ids) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'lcni_industry_metrics';

        foreach (self::sanitize_ids($ids) as $id) {
            $row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT id, momentum, relative_strength
                     FROM {$table_name}
                     WHERE id = %d",
                    $id
                ),
                ARRAY_A
            );

            if (empty($row)) {
                continue;
            }

            $momentum = (float) $row['momentum'];
            $relative_strength = (float) $row['relative_strength'];

            if ($momentum > 0 && $relative_strength > 0) {
                $trend_state = 'Ngành dẫn dắt';
            } elseif ($momentum > 0 && $relative_strength <= 0) {
                $trend_state = 'Ngành đang cải thiện';
            } elseif ($momentum <= 0 && $relative_strength > 0) {
                $trend_state = 'Ngành suy yếu';
            } else {
                $trend_state = 'Ngành tụt hậu';
            }

            $wpdb->update(
                $table_name,
                ['trend_state_vi' => $trend_state],
                ['id' => (int) $row['id']],
                ['%s'],
                ['%d']
            );

            if (!empty($wpdb->last_error)) {
                error_log('[LCNI] Failed updating trend_state_vi for id ' . (int) $row['id'] . ': ' . $wpdb->last_error);
            }
        }
    }

    public static function compute_industry_rank_batch($ids) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'lcni_industry_metrics';

        foreach (self::sanitize_ids($ids) as $id) {
            $row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT id, event_time, timeframe, industry_score_raw
                     FROM {$table_name}
                     WHERE id = %d",
                    $id
                ),
                ARRAY_A
            );

            if (empty($row)) {
                continue;
            }

            $higher_score_count = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(1)
                     FROM {$table_name}
                     WHERE event_time = %s
                       AND timeframe = %s
                       AND industry_score_raw > %f",
                    (string) $row['event_time'],
                    (string) $row['timeframe'],
                    (float) $row['industry_score_raw']
                )
            );

            $rank = $higher_score_count + 1;

            $wpdb->update(
                $table_name,
                ['industry_rank' => $rank],
                ['id' => (int) $row['id']],
                ['%d'],
                ['%d']
            );

            if (!empty($wpdb->last_error)) {
                error_log('[LCNI] Failed updating industry_rank for id ' . (int) $row['id'] . ': ' . $wpdb->last_error);
            }
        }
    }

    public static function backfill_missing_metrics() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'lcni_industry_metrics';

        $event_time = $wpdb->get_var(
            "SELECT MIN(event_time)
             FROM {$table_name}
             WHERE momentum_delta = 0"
        );

        return $event_time ? (string) $event_time : null;
    }

    private static function get_missing_row_ids($limit, $start_event_time = null) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'lcni_industry_metrics';
        $limit = max(1, (int) $limit);

        if ($start_event_time !== null && $start_event_time !== '') {
            $query = $wpdb->prepare(
                "SELECT id
                 FROM {$table_name}
                 WHERE event_time >= %s
                   AND (
                       momentum_delta = 0
                       OR industry_rank = 0
                       OR trend_state_vi = %s
                   )
                 ORDER BY event_time ASC, id ASC
                 LIMIT %d",
                (string) $start_event_time,
                'Trung tính',
                $limit
            );
        } else {
            $query = $wpdb->prepare(
                "SELECT id
                 FROM {$table_name}
                 WHERE
                     momentum_delta = 0
                     OR industry_rank = 0
                     OR trend_state_vi = %s
                 ORDER BY event_time ASC, id ASC
                 LIMIT %d",
                'Trung tính',
                $limit
            );
        }

        $ids = $wpdb->get_col($query);

        return self::sanitize_ids($ids);
    }

    private static function ensure_index_exists($table_name, $index_name, $index_columns_sql) {
        global $wpdb;

        if (self::index_exists($table_name, $index_name)) {
            return;
        }

        $sql = sprintf(
            'ALTER TABLE %s ADD INDEX %s (%s)',
            $table_name,
            $index_name,
            $index_columns_sql
        );

        $wpdb->query($sql);
        if (!empty($wpdb->last_error)) {
            error_log('[LCNI] Failed adding index ' . $index_name . ': ' . $wpdb->last_error);
        }
    }

    private static function column_exists($table_name, $column_name) {
        global $wpdb;

        $exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(1)
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = %s
                   AND COLUMN_NAME = %s",
                $table_name,
                $column_name
            )
        );

        return (int) $exists > 0;
    }

    private static function index_exists($table_name, $index_name) {
        global $wpdb;

        $result = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(1)
                 FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = %s
                   AND INDEX_NAME = %s",
                $table_name,
                $index_name
            )
        );

        return (int) $result > 0;
    }

    private static function sanitize_ids($ids) {
        $ids = array_map('intval', (array) $ids);

        return array_values(array_filter($ids, function ($id) {
            return $id > 0;
        }));
    }
}

function lcni_add_missing_columns() {
    LCNI_Industry_Metrics_Upgrade::add_missing_columns();
}

function lcni_compute_momentum_delta_batch($ids = []) {
    LCNI_Industry_Metrics_Upgrade::compute_momentum_delta_batch($ids);
}

function lcni_compute_trend_state_vi_batch($ids = []) {
    LCNI_Industry_Metrics_Upgrade::compute_trend_state_vi_batch($ids);
}

function lcni_compute_industry_rank_batch($ids = []) {
    LCNI_Industry_Metrics_Upgrade::compute_industry_rank_batch($ids);
}

function lcni_backfill_missing_metrics() {
    return LCNI_Industry_Metrics_Upgrade::backfill_missing_metrics();
}

LCNI_Industry_Metrics_Upgrade::init();
