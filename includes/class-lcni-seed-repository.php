<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_SeedRepository {

    public static function get_table_name() {
        global $wpdb;

        return $wpdb->prefix . 'lcni_seed_tasks';
    }

    public static function reset_tasks() {
        global $wpdb;

        $table = self::get_table_name();
        $wpdb->query("TRUNCATE TABLE {$table}");
    }

    public static function create_seed_tasks($symbols, $timeframes) {
        global $wpdb;

        $table = self::get_table_name();
        $created = 0;

        foreach ($symbols as $symbol) {
            foreach ($timeframes as $timeframe) {
                $result = $wpdb->query(
                    $wpdb->prepare(
                        "INSERT INTO {$table} (symbol, timeframe, status, last_to_time, created_at, updated_at)
                        VALUES (%s, %s, 'pending', %d, NOW(), NOW())
                        ON DUPLICATE KEY UPDATE
                            status = VALUES(status),
                            last_to_time = VALUES(last_to_time),
                            updated_at = NOW()",
                        strtoupper((string) $symbol),
                        strtoupper((string) $timeframe),
                        time()
                    )
                );

                if ($result !== false) {
                    $created++;
                }
            }
        }

        return $created;
    }

    public static function get_next_task() {
        global $wpdb;

        $table = self::get_table_name();

        $task = $wpdb->get_row(
            "SELECT * FROM {$table} WHERE status IN ('pending', 'running') ORDER BY FIELD(status, 'running', 'pending'), updated_at ASC, id ASC LIMIT 1",
            ARRAY_A
        );

        if (empty($task)) {
            return null;
        }

        $wpdb->update(
            $table,
            [
                'status' => 'running',
                'updated_at' => current_time('mysql', 1),
            ],
            ['id' => (int) $task['id']],
            ['%s', '%s'],
            ['%d']
        );

        $task['status'] = 'running';

        return $task;
    }

    public static function update_progress($task_id, $last_to_time) {
        global $wpdb;

        $table = self::get_table_name();

        return $wpdb->update(
            $table,
            [
                'status' => 'pending',
                'last_to_time' => max(1, (int) $last_to_time),
                'updated_at' => current_time('mysql', 1),
            ],
            ['id' => (int) $task_id],
            ['%s', '%d', '%s'],
            ['%d']
        );
    }

    public static function mark_done($task_id) {
        global $wpdb;

        $table = self::get_table_name();

        return $wpdb->update(
            $table,
            [
                'status' => 'done',
                'updated_at' => current_time('mysql', 1),
            ],
            ['id' => (int) $task_id],
            ['%s', '%s'],
            ['%d']
        );
    }

    public static function get_dashboard_stats() {
        global $wpdb;

        $table = self::get_table_name();

        return [
            'total' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}"),
            'done' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'done'"),
            'running' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'running'"),
            'pending' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'pending'"),
        ];
    }

    public static function get_recent_tasks($limit = 20) {
        global $wpdb;

        $table = self::get_table_name();

        return $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$table} ORDER BY FIELD(status, 'running', 'pending', 'done'), updated_at DESC, id DESC LIMIT %d", max(1, (int) $limit)),
            ARRAY_A
        );
    }
}
