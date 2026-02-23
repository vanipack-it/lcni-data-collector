<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_API {

    const BASE_URL = 'https://services.entrade.com.vn';
    const SECDEF_ENDPOINT = '/open-api/market/v2/securities';
    const SECDEF_URL = self::BASE_URL . self::SECDEF_ENDPOINT;

    private static $last_request_error = '';
    private static $supported_symbol_map = null;

    public static function get_candles($symbol, $resolution = '1D', $days = 365) {
        if (empty($symbol)) {
            self::$last_request_error = 'Symbol is required.';

            return false;
        }

        $days = max(1, (int) $days);
        $to = current_time('timestamp');
        $from = max(0, $to - ($days * DAY_IN_SECONDS));

        return self::get_candles_by_range($symbol, $resolution, $from, $to);
    }

    public static function get_candles_by_range($symbol, $resolution, $from, $to) {
        $normalized_symbol = strtoupper(trim((string) $symbol));

        if ($normalized_symbol === '') {
            self::$last_request_error = 'Symbol is required.';

            return false;
        }

        if (!self::is_valid_symbol_format($normalized_symbol)) {
            self::$last_request_error = sprintf('Symbol format invalid for Entrade API: %s', $normalized_symbol);
            LCNI_DB::log_change('api_error', self::$last_request_error);

            return new WP_Error('invalid_symbol', 'Symbol format invalid for Entrade API');
        }

        if (!self::is_symbol_supported_for_entrade($normalized_symbol)) {
            self::$last_request_error = sprintf('Unsupported symbol for Entrade chart-api: %s', $normalized_symbol);
            LCNI_DB::log_change('api_error', self::$last_request_error, ['symbol' => $normalized_symbol]);

            return new WP_Error('unsupported_symbol', 'Symbol is not supported by Entrade chart-api');
        }

        $normalized_resolution = self::normalize_resolution($resolution);

        $from = (int) $from;
        $to = (int) $to;

        // Entrade chart-api expects UNIX timestamps in milliseconds.
        if ($from < 1000000000000) {
            $from *= 1000;
        }

        if ($to < 1000000000000) {
            $to *= 1000;
        }

        if ($from <= 0 || $to <= 0 || $from >= $to) {
            self::$last_request_error = sprintf('Invalid range from=%d to=%d', $from, $to);

            return false;
        }

        $url = add_query_arg(
            [
                'symbol' => $normalized_symbol,
                'resolution' => $normalized_resolution,
                'from' => $from,
                'to' => $to,
            ],
            'https://services.entrade.com.vn/chart-api/v2/ohlcs'
        );

        return self::request_json($url);
    }

    public static function get_security_definitions() {
        $url = self::BASE_URL . self::SECDEF_ENDPOINT;

        $query_args = [
            'page' => 1,
            'size' => 200,
        ];

        return self::request_json(
            add_query_arg($query_args, $url),
            [],
            'GET'
        );
    }

    public static function get_last_request_error() {
        return self::$last_request_error;
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

    private static function request_json($url, $headers = [], $method = 'GET', $body = null) {
        self::$last_request_error = '';

        $request_headers = array_merge(
            [
                'Accept' => 'application/json',
            ],
            is_array($headers) ? $headers : []
        );

        $request_args = [
            'headers' => $request_headers,
            'timeout' => 20,
            'method' => strtoupper((string) $method),
        ];

        if ($request_args['method'] !== 'GET' && $body !== null) {
            $request_args['body'] = wp_json_encode($body);
        }

        $response = wp_remote_request($url, $request_args);

        if (is_wp_error($response)) {
            self::$last_request_error = 'HTTP request error: ' . implode('; ', $response->get_error_messages());
            LCNI_DB::log_change('api_error', 'HTTP request error.', $response->get_error_messages());

            return false;
        }

        $status_code = (int) wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($status_code !== 200) {
            self::$last_request_error = sprintf('HTTP %d from %s', $status_code, $url);
            LCNI_DB::log_change(
                'api_error',
                sprintf('API returned HTTP %d for %s', $status_code, $url),
                [
                    'status' => $status_code,
                    'body' => $body,
                ]
            );

            return false;
        }

        if ($body === '') {
            self::$last_request_error = 'Empty response body from ' . $url;
            LCNI_DB::log_change('api_error', 'API returned empty body.', ['url' => $url]);

            return false;
        }

        $decoded = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            self::$last_request_error = 'Invalid JSON response from ' . $url;
            LCNI_DB::log_change('api_error', 'API returned invalid JSON.', ['body' => $body]);

            return false;
        }

        if (empty($decoded)) {
            self::$last_request_error = 'Empty decoded payload from ' . $url;
            LCNI_DB::log_change('api_error', 'API returned empty payload.', ['url' => $url]);

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

    private static function is_valid_symbol_format($symbol) {
        if (preg_match('/^[A-Z]{2,5}$/', $symbol)) {
            return true;
        }

        return in_array($symbol, ['VNINDEX', 'HNXINDEX', 'UPCOMINDEX'], true);
    }

    private static function is_symbol_supported_for_entrade($symbol) {
        if (in_array($symbol, ['VNINDEX', 'HNXINDEX', 'UPCOMINDEX'], true)) {
            return true;
        }

        $symbol_map = self::get_supported_symbol_map();
        if (!is_array($symbol_map) || empty($symbol_map)) {
            self::$last_request_error = 'Unable to validate supported symbols for Entrade chart-api. Fallback to symbol format validation.';
            LCNI_DB::log_change('api_error', self::$last_request_error, ['symbol' => $symbol]);

            return (bool) preg_match('/^[A-Z]{2,5}$/', $symbol);
        }

        return isset($symbol_map[$symbol]);
    }

    private static function get_supported_symbol_map() {
        if (is_array(self::$supported_symbol_map)) {
            return self::$supported_symbol_map;
        }

        $cache_key = 'lcni_entrade_supported_symbols';
        $cached = get_transient($cache_key);
        if (is_array($cached) && !empty($cached)) {
            self::$supported_symbol_map = $cached;

            return self::$supported_symbol_map;
        }

        $definitions = self::get_security_definitions();
        if (!is_array($definitions) || empty($definitions['data']) || !is_array($definitions['data'])) {
            self::$supported_symbol_map = [];

            return self::$supported_symbol_map;
        }

        $map = [];
        foreach ($definitions['data'] as $row) {
            if (empty($row['symbol'])) {
                continue;
            }

            $normalized = strtoupper(trim((string) $row['symbol']));
            if ($normalized === '') {
                continue;
            }

            $map[$normalized] = true;
        }

        self::$supported_symbol_map = $map;
        set_transient($cache_key, $map, HOUR_IN_SECONDS);

        return self::$supported_symbol_map;
    }

}
