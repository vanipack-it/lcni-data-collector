<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_API {

    private static $last_request_error = '';

    public static function get_candles($symbol, $timeframe = '1D') {
        $api_key = get_option('lcni_api_key');
        $api_secret = get_option('lcni_api_secret');

        if (empty($api_key) || empty($api_secret) || empty($symbol)) {
            return false;
        }

        $urls = [
            add_query_arg(
                [
                    'symbol' => strtoupper($symbol),
                    'tf' => $timeframe,
                ],
                'https://api.dnse.com.vn/candles'
            ),
            add_query_arg(
                [
                    'symbol' => strtoupper($symbol),
                    'tf' => $timeframe,
                ],
                'https://api.dnse.com.vn/dnse/candles'
            ),
        ];

        $response = self::request_with_fallback($urls, $api_key, $api_secret);

        if ($response === false) {
            LCNI_DB::log_change('api_error', sprintf('Failed to fetch candles for %s.', $symbol), ['error' => self::$last_request_error]);

            return false;
        }

        return $response;
    }

    public static function get_security_definitions() {
        $api_key = get_option('lcni_api_key');
        $api_secret = get_option('lcni_api_secret');

        if (empty($api_key) || empty($api_secret)) {
            return false;
        }

        $response = self::request_with_fallback(
            [
                'https://api.dnse.com.vn/secdef',
                'https://api.dnse.com.vn/dnse/secdef',
            ],
            $api_key,
            $api_secret
        );

        if ($response === false) {
            LCNI_DB::log_change('api_error', 'Failed to fetch security definitions.');

            return false;
        }

        return $response;
    }

    public static function test_connection($api_key, $api_secret) {
        if (empty($api_key) || empty($api_secret)) {
            return new WP_Error('missing_credentials', 'Vui lòng nhập đầy đủ API Key và API Secret.');
        }

        $response = self::request_with_fallback(
            [
                'https://api.dnse.com.vn/secdef',
                'https://api.dnse.com.vn/dnse/secdef',
            ],
            $api_key,
            $api_secret
        );

        if ($response === false) {
            return new WP_Error('connection_failed', 'Không thể kết nối DNSE API hoặc thông tin xác thực không hợp lệ. ' . self::$last_request_error);
        }

        if (!is_array($response)) {
            return new WP_Error('invalid_response', 'DNSE API trả về dữ liệu không hợp lệ.');
        }

        return true;
    }


    private static function request_with_fallback($urls, $api_key, $api_secret) {
        self::$last_request_error = '';

        foreach ($urls as $url) {
            $response = self::request('GET', $url, $api_key, $api_secret);
            if ($response !== false) {
                return $response;
            }
        }

        if (self::$last_request_error === '') {
            self::$last_request_error = 'No endpoint responded successfully.';
        }

        return false;
    }

    private static function request($method, $url, $api_key, $api_secret) {
        $timestamp = (string) round(microtime(true) * 1000);
        $signature = self::build_signature($method, $url, $timestamp, '', $api_secret);

        $args = [
            'method' => $method,
            'headers' => [
                'X-API-KEY' => $api_key,
                'X-API-SECRET' => $api_secret,
                'X-TIMESTAMP' => $timestamp,
                'X-SIGNATURE' => $signature,
                'Accept' => 'application/json',
            ],
            'timeout' => 20,
        ];

        $response = wp_remote_request($url, $args);

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
                sprintf('DNSE API returned HTTP %d for %s', $status_code, $url),
                [
                    'status' => $status_code,
                    'body' => $decoded ?: $body,
                ]
            );

            return false;
        }

        if (json_last_error() !== JSON_ERROR_NONE) {
            self::$last_request_error = 'Invalid JSON response from ' . $url;
            LCNI_DB::log_change('api_error', 'DNSE API returned invalid JSON.', ['body' => $body]);

            return false;
        }

        self::$last_request_error = '';

        return $decoded;
    }

    private static function build_signature($method, $url, $timestamp, $body, $api_secret) {
        $path = wp_parse_url($url, PHP_URL_PATH);
        $query = wp_parse_url($url, PHP_URL_QUERY);
        $resource = $path ? $path : '/';

        if (!empty($query)) {
            $resource .= '?' . $query;
        }

        $payload_hash = hash('sha256', $body);
        $raw_signature = strtoupper($method) . "\n" . $resource . "\n" . $timestamp . "\n" . $payload_hash;

        return hash_hmac('sha256', $raw_signature, $api_secret);
    }
}
