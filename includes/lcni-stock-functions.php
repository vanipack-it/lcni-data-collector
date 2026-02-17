<?php

if (!defined('ABSPATH')) {
    exit;
}

function lcni_get_tradingview_symbol($symbol) {
    $stock = LCNI_StockRepository::get_stock($symbol);
    $exchange = strtoupper(trim((string) ($stock['exchange'] ?? '')));
    $normalized_symbol = strtoupper(sanitize_text_field((string) $symbol));

    $exchange_aliases = [
        'HSX' => 'HOSE',
        'HOSE' => 'HOSE',
        'HNX' => 'HNX',
        'UPCOM' => 'UPCOM',
    ];

    $tradingview_exchange = 'HOSE';
    if (isset($exchange_aliases[$exchange])) {
        $tradingview_exchange = $exchange_aliases[$exchange];
    }

    return $tradingview_exchange . ':' . $normalized_symbol;
}

function lcni_get_stock($symbol) {
    $stock = LCNI_StockRepository::get_stock($symbol);
    if (empty($stock)) {
        return null;
    }

    $stock['tradingview_symbol'] = lcni_get_tradingview_symbol($stock['symbol']);
    $stock['tradingview_embed_url'] = sprintf(
        'https://www.tradingview.com/chart/?symbol=%s',
        rawurlencode($stock['tradingview_symbol'])
    );

    return $stock;
}

function lcni_get_stock_history($symbol, $limit = 120, $timeframe = '1D') {
    return LCNI_StockRepository::get_stock_history($symbol, $limit, $timeframe);
}
