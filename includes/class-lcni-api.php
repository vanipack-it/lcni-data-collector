<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_API {

    public static function get_candles($symbol, $timeframe = '1D') {
        $api_key = get_option('lcni_api_key');
        $api_secret = get_option('lcni_api_secret');

        if (empty($api_key) || empty($api_secret) || empty($symbol)) {
            return false;
        }

        $url = add_query_arg(
            [
                'symbol' => strtoupper($symbol),
                'tf' => $timeframe,
            ],
            'https://api.dnse.com.vn/candles'
        );

        $response = self::request('GET', $url, $api_key, $api_secret);

        if ($response === false) {
            LCNI_DB::log_change('api_error', sprintf('Failed to fetch candles for %s.', $symbol));

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
            return new WP_Error('connection_failed', 'Không thể kết nối DNSE API hoặc thông tin xác thực không hợp lệ.');
        }

        if (!is_array($response)) {
            return new WP_Error('invalid_response', 'DNSE API trả về dữ liệu không hợp lệ.');
        }

        return true;
    }


    private static function request_with_fallback($urls, $api_key, $api_secret) {
        foreach ($urls as $url) {
            $response = self::request('GET', $url, $api_key, $api_secret);
            if ($response !== false) {
                return $response;
            }
        }

        return false;
    }

    private static function request($method, $url, $api_key, $api_secret) {
        $args = [
            'method' => $method,
            'headers' => [
                'X-API-KEY' => $api_key,
                'X-API-SECRET' => $api_secret,
                'Accept' => 'application/json',
            ],
            'timeout' => 20,
        ];

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            LCNI_DB::log_change('api_error', 'HTTP request error.', $response->get_error_messages());

            return false;
        }

        $status_code = (int) wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);

        if ($status_code < 200 || $status_code >= 300) {
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
            LCNI_DB::log_change('api_error', 'DNSE API returned invalid JSON.', ['body' => $body]);

            return false;
        }

        return $decoded;
    }
}
