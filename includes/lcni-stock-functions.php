<?php

if (!defined('ABSPATH')) {
    exit;
}

function lcni_get_chart_symbol($symbol) {
    $stock = LCNI_StockRepository::get_stock($symbol);
    $exchange = strtoupper(trim((string) ($stock['exchange'] ?? '')));
    $normalized_symbol = strtoupper(sanitize_text_field((string) $symbol));

    $exchange_aliases = [
        'HSX' => 'HOSE',
        'HOSE' => 'HOSE',
        'HNX' => 'HNX',
        'UPCOM' => 'UPCOM',
    ];

    $chart_exchange = 'HOSE';
    if (isset($exchange_aliases[$exchange])) {
        $chart_exchange = $exchange_aliases[$exchange];
    }

    return $chart_exchange . ':' . $normalized_symbol;
}

function lcni_get_stock($symbol) {
    $stock = LCNI_StockRepository::get_stock($symbol);
    if (empty($stock)) {
        return null;
    }

    $normalized_symbol = strtoupper(sanitize_text_field((string) $stock['symbol']));

    $stock['chart_library'] = 'echarts';
    $stock['chart_symbol'] = lcni_get_chart_symbol($normalized_symbol);
    $stock['chart_data_endpoint'] = esc_url_raw(
        rest_url(sprintf('lcni/v1/candles?symbol=%s', rawurlencode($normalized_symbol)))
    );

    return $stock;
}

function lcni_get_stock_history($symbol, $limit = 120, $timeframe = '1D') {
    return LCNI_StockRepository::get_stock_history($symbol, $limit, $timeframe);
}

function lcni_get_lightweight_chart_series($symbol, $limit = 120, $timeframe = '1D') {
    $history = LCNI_StockRepository::get_stock_history($symbol, $limit, $timeframe);
    if (empty($history)) {
        return [];
    }

    $series = [];
    foreach (array_reverse($history) as $candle) {
        $series[] = [
            'time' => (int) ($candle['event_time'] ?? 0),
            'open' => (float) ($candle['open_price'] ?? 0),
            'high' => (float) ($candle['high_price'] ?? 0),
            'low' => (float) ($candle['low_price'] ?? 0),
            'close' => (float) ($candle['close_price'] ?? 0),
        ];
    }

    return $series;
}

function lcni_get_watchlist_add_button($symbol) {
    $symbol = strtoupper(sanitize_text_field((string) $symbol));
    if ($symbol === '') {
        return '';
    }

    return do_shortcode(sprintf('[lcni_watchlist_add symbol="%s"]', esc_attr($symbol)));
}

function lcni_render_symbol($symbol) {
    $normalized = strtoupper(sanitize_text_field((string) $symbol));
    if ($normalized === '') {
        return '';
    }

    $base_html = sprintf('<span class="lcni-symbol">%s</span>', esc_html($normalized));

    return (string) apply_filters('lcni_render_symbol', $base_html, $normalized);
}
