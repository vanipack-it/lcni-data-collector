<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('lcni_get_market_timezone')) {
    function lcni_get_market_timezone() {
        $default_timezone = 'Asia/Ho_Chi_Minh';
        $timezone_name = apply_filters('lcni_market_timezone', $default_timezone);

        if (!is_string($timezone_name) || trim($timezone_name) === '') {
            $timezone_name = $default_timezone;
        }

        try {
            return new DateTimeZone($timezone_name);
        } catch (Exception $e) {
            return new DateTimeZone($default_timezone);
        }
    }
}

if (!function_exists('lcni_is_trading_time')) {
    function lcni_is_trading_time(?DateTimeImmutable $now = null) {
        $timezone = lcni_get_market_timezone();
        $current_time = $now instanceof DateTimeImmutable ? $now->setTimezone($timezone) : new DateTimeImmutable('now', $timezone);

        $day_of_week = (int) $current_time->format('N');
        if ($day_of_week >= 6) {
            return false;
        }

        $day_start = $current_time->setTime(0, 0, 0);
        $current_ts = $current_time->getTimestamp();

        $morning_start = $day_start->setTime(9, 0, 0)->getTimestamp();
        $morning_end = $day_start->setTime(11, 30, 0)->getTimestamp();
        $afternoon_start = $day_start->setTime(13, 0, 0)->getTimestamp();
        $afternoon_end = $day_start->setTime(14, 45, 0)->getTimestamp();

        $is_morning_session = $current_ts >= $morning_start && $current_ts <= $morning_end;
        $is_afternoon_session = $current_ts >= $afternoon_start && $current_ts <= $afternoon_end;

        return $is_morning_session || $is_afternoon_session;
    }
}

if (!function_exists('lcni_get_next_trading_time')) {
    function lcni_get_next_trading_time(?DateTimeImmutable $now = null) {
        $timezone = lcni_get_market_timezone();
        $current_time = $now instanceof DateTimeImmutable ? $now->setTimezone($timezone) : new DateTimeImmutable('now', $timezone);

        $candidate_day = $current_time;

        while (true) {
            $day_of_week = (int) $candidate_day->format('N');

            if ($day_of_week >= 6) {
                $candidate_day = $candidate_day->modify('tomorrow')->setTime(9, 0, 0);
                continue;
            }

            $morning_start = $candidate_day->setTime(9, 0, 0);
            $morning_end = $candidate_day->setTime(11, 30, 0);
            $afternoon_start = $candidate_day->setTime(13, 0, 0);
            $afternoon_end = $candidate_day->setTime(14, 45, 0);

            if ($current_time < $morning_start && $candidate_day->format('Y-m-d') === $current_time->format('Y-m-d')) {
                return $morning_start;
            }

            if ($current_time >= $morning_start && $current_time <= $morning_end) {
                return $current_time;
            }

            if ($current_time > $morning_end && $current_time < $afternoon_start && $candidate_day->format('Y-m-d') === $current_time->format('Y-m-d')) {
                return $afternoon_start;
            }

            if ($current_time >= $afternoon_start && $current_time <= $afternoon_end) {
                return $current_time;
            }

            $candidate_day = $candidate_day->modify('tomorrow')->setTime(9, 0, 0);
            $current_time = $candidate_day;
        }
    }
}
