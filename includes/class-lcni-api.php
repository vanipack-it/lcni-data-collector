<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_API {

    const SECDEF_URL = 'https://openapi.dnse.com.vn/price/secdef';
    const SECDEF_FALLBACK_URL = 'https://openapi.dnse.com.vn/price/secdefs';

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
        $api_key = trim((string) get_option('lcni_api_key', ''));
        $api_secret = trim((string) get_option('lcni_api_secret', ''));

        $configured_url = trim((string) get_option('lcni_secdef_url', self::SECDEF_URL));
        $candidates = self::build_secdef_candidates($configured_url);

        $headers = [];
        if ($api_key !== '') {
            $headers['X-API-KEY'] = $api_key;
        }

        if ($api_secret !== '') {
            $headers['X-API-SECRET'] = $api_secret;
        }

        $attempt_errors = [];

        foreach ($candidates as $url) {
            $payload = self::request_json($url, $headers);
            if (is_array($payload)) {
                return $payload;
            }

            if (self::$last_request_error !== '') {
                $attempt_errors[] = self::$last_request_error;
            }
        }

        if (!empty($attempt_errors)) {
            self::$last_request_error = implode(' | ', array_unique($attempt_errors));
        }

        return false;
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

    private static function request_json($url, $headers = []) {
        self::$last_request_error = '';

        $request_headers = array_merge(
            [
                'Accept' => 'application/json',
            ],
            is_array($headers) ? $headers : []
        );

        $response = wp_remote_get(
            $url,
            [
                'headers' => $request_headers,
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

    private static function normalize_secdef_url($url) {
        $url = trim((string) $url);

        if ($url === '') {
            return '';
        }

        return str_ireplace('/:symbol', '', $url);
    }

    private static function build_secdef_candidates($configured_url) {
        $configured_url = trim((string) $configured_url);

        $candidates = [
            self::normalize_secdef_url($configured_url),
            self::normalize_secdef_url(self::SECDEF_URL),
            self::normalize_secdef_url(self::SECDEF_FALLBACK_URL),
        ];

        $candidates = array_values(
            array_filter(
                array_unique($candidates),
                static function ($url) {
                    return is_string($url) && trim($url) !== '';
                }
            )
        );

        return !empty($candidates) ? $candidates : [self::SECDEF_URL, self::SECDEF_FALLBACK_URL];
    }
}
