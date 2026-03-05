<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_Recommend_Module {
    private $daily_cron_service;

    public function __construct() {
        global $wpdb;

        $rule_repository = new RuleRepository($wpdb);
        $signal_repository = new SignalRepository($wpdb);
        $position_engine = new PositionEngine();
        $exit_engine = new ExitEngine();
        $performance_calculator = new PerformanceCalculator($wpdb);

        $this->daily_cron_service = new DailyCronService(
            $rule_repository,
            $signal_repository,
            $position_engine,
            $exit_engine,
            $performance_calculator,
            $wpdb
        );

        new ShortcodeManager($signal_repository, $performance_calculator, $position_engine);
        if (is_admin()) {
            new LCNI_Recommend_Admin_Page($rule_repository, $signal_repository, $performance_calculator, $this->daily_cron_service);
        }

        add_action(DailyCronService::CRON_HOOK, [$this, 'run_daily_cron']);
    }

    public static function activate() {
        LCNI_Recommend_DB::create_tables();
        self::ensure_cron();
    }

    public static function ensure_infrastructure() {
        LCNI_Recommend_DB::ensure_tables_exist();
        self::ensure_cron();
    }

    public static function ensure_cron() {
        if (!wp_next_scheduled(DailyCronService::CRON_HOOK)) {
            $timezone = wp_timezone();
            $next_run = new DateTimeImmutable('now', $timezone);
            wp_schedule_event($next_run->getTimestamp() + MINUTE_IN_SECONDS, 'lcni_every_minute', DailyCronService::CRON_HOOK);
        }
    }

    public static function deactivate() {
        $timestamp = wp_next_scheduled(DailyCronService::CRON_HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, DailyCronService::CRON_HOOK);
        }
    }

    public function run_daily_cron() {
        $this->daily_cron_service->run_daily();
    }
}
