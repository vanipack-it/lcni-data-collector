<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_API {

    private static $last_request_error = '';

    public static function get_candles($symbol, $resolution = '1D', $days = 365) {
        if (empty($symbol)) {
            self::$last_request_error = 'Symbol is required.';

            return false;
        }

        $days = max(1, (int) $days);
        $to = time();
        $from = max(0, $to - ($days * DAY_IN_SECONDS));

        return self::get_candles_by_range($symbol, $resolution, $from, $to);
    }

    public static function get_candles_by_range($symbol, $resolution, $from, $to) {
        if (empty($symbol)) {
            self::$last_request_error = 'Symbol is required.';

            return false;
        }

        $normalized_resolution = self::normalize_resolution($resolution);

        $from = (int) $from;
        $to = (int) $to;

        if ($from <= 0 || $to <= 0 || $from >= $to) {
            self::$last_request_error = sprintf('Invalid range from=%d to=%d', $from, $to);

            return false;
        }

        $url = add_query_arg(
            [
                'symbol' => strtoupper($symbol),
                'resolution' => $normalized_resolution,
                'from' => $from,
                'to' => $to,
            ],
            'https://services.entrade.com.vn/chart-api/v2/ohlcs'
        );

        return self::request_json($url);
    }

    public static function get_security_definitions() {
        return self::request_json('https://api.dnse.com.vn/secdef');
    }

    public static function test_connection() {
        $response = self::get_candles('VNINDEX', '1D', 5);

        if ($response === false) {
            return new WP_Error('connection_failed', 'Không thể kết nối chart-api. ' . self::$last_request_error);
        }

        if (!is_array($response) || !isset($response['t']) || !is_array($response['t'])) {
            return new WP_Error('invalid_response', 'chart-api trả về dữ liệu không hợp lệ.');
        }

        return true;
    }

    private static function request_json($url) {
        self::$last_request_error = '';

        $response = wp_remote_get(
            $url,
            [
                'headers' => [
                    'Accept' => 'application/json',
                ],
                'timeout' => 20,
            ]
        );

        if (is_wp_error($response)) {
            self::$last_request_error = 'HTTP request error: ' . implode('; ', $response->get_error_messages());
            LCNI_DB::log_change('api_error', 'HTTP request error.', $response->get_error_messages());

            return false;
        }

        $status_code = (int) wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);

        if ($status_code < 200 || $status_code >= 300) {
            self::$last_request_error = sprintf('HTTP %d from %s', $status_code, $url);
            LCNI_DB::log_change(
                'api_error',
                sprintf('API returned HTTP %d for %s', $status_code, $url),
                [
                    'status' => $status_code,
                    'body' => $decoded ?: $body,
                ]
            );

            return false;
        }

        if (json_last_error() !== JSON_ERROR_NONE) {
            self::$last_request_error = 'Invalid JSON response from ' . $url;
            LCNI_DB::log_change('api_error', 'API returned invalid JSON.', ['body' => $body]);

            return false;
        }

        return $decoded;
    }

    private static function normalize_resolution($resolution) {
        $raw = strtoupper(trim((string) $resolution));
        $map = [
            'D' => '1D',
            '1D' => '1D',
            'W' => '1W',
            '1W' => '1W',
            'M' => '1M',
            '1M' => '1M',
            '1H' => '60',
            'H1' => '60',
        ];

        if (isset($map[$raw])) {
            return $map[$raw];
        }

        if (preg_match('/^\d+$/', $raw)) {
            return $raw;
        }

        if (preg_match('/^(\d+)MIN$/', $raw, $matches)) {
            return $matches[1];
        }

        return '1D';
    }
}
