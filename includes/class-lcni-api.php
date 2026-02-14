<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_API {

    public static function get_candles($symbol, $timeframe = '1D') {
        $api_key = get_option('lcni_api_key');
        $api_secret = get_option('lcni_api_secret');

        $url = add_query_arg(
            [
                'symbol' => $symbol,
                'tf' => $timeframe,
            ],
            'https://api.dnse.com.vn/candles'
        );

        $response = wp_remote_get(
            $url,
            [
                'headers' => [
                    'X-API-KEY' => $api_key,
                    'X-API-SECRET' => $api_secret,
                ],
                'timeout' => 20,
            ]
        );

        if (is_wp_error($response)) {
            LCNI_DB::log_change('api_error', 'Failed to fetch candles.', $response->get_error_messages());

            return false;
        }

        return json_decode(wp_remote_retrieve_body($response), true);
    }
}
