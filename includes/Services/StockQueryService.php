<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_StockQueryService {

    private $repository;
    private $indicator_service;
    private $access_control;
    private $cache;

    public function __construct(
        LCNI_Data_StockRepository $repository,
        LCNI_IndicatorService $indicator_service,
        LCNI_AccessControl $access_control,
        LCNI_CacheService $cache
    ) {
        $this->repository = $repository;
        $this->indicator_service = $indicator_service;
        $this->access_control = $access_control;
        $this->cache = $cache;
    }

    public function getStockDetail($symbol) {
        $normalized_symbol = $this->normalizeSymbol($symbol);
        if ($normalized_symbol === '') {
            return null;
        }

        $package = $this->access_control->resolvePackage();

        return $this->cache->remember(
            'stock_detail:' . $normalized_symbol . ':' . $package,
            function () use ($normalized_symbol, $package) {
                $row = $this->repository->getLatestBySymbol($normalized_symbol);
                if (!$row) {
                    return null;
                }

                $allowed_ma = $this->access_control->getIndicatorWhitelist($package);

                return [
                    'symbol' => $row['symbol'],
                    'price' => (float) $row['close_price'],
                    'change' => $row['pct_t_1'] !== null ? (float) $row['pct_t_1'] : null,
                    'volume' => (int) $row['volume'],
                    'ohlc' => [[
                        'time' => (int) $row['event_time'],
                        'open' => (float) $row['open_price'],
                        'high' => (float) $row['high_price'],
                        'low' => (float) $row['low_price'],
                        'close' => (float) $row['close_price'],
                    ]],
                    'indicators' => $this->indicator_service->buildIndicators($row, $allowed_ma),
                    'signals' => $this->indicator_service->buildSignals($row),
                    'package' => $package,
                ];
            }
        );
    }

    public function getStockDetailPage($symbol) {
        $normalized_symbol = $this->normalizeSymbol($symbol);
        if ($normalized_symbol === '') {
            return null;
        }

        return $this->cache->remember(
            'stock_detail_page:' . $normalized_symbol,
            function () use ($normalized_symbol) {
                return $this->repository->getDetailPageBySymbol($normalized_symbol);
            },
            120
        );
    }

    public function getStockHistory($symbol, $limit) {
        $normalized_symbol = $this->normalizeSymbol($symbol);
        if ($normalized_symbol === '') {
            return [];
        }

        $package = $this->access_control->resolvePackage();
        $requested_limit = max(1, (int) $limit);
        $effective_limit = min($requested_limit, $this->access_control->getHistoryLimit($package));

        $items = $this->cache->remember(
            'stock_history:' . $normalized_symbol . ':' . $effective_limit,
            function () use ($normalized_symbol, $effective_limit) {
                return $this->repository->getHistoryBySymbol($normalized_symbol, $effective_limit);
            }
        );

        return [
            'symbol' => $normalized_symbol,
            'limit' => $effective_limit,
            'package' => $package,
            'items' => $items,
        ];
    }

    public function getStocks($page, $per_page) {
        $safe_page = max(1, (int) $page);
        $safe_per_page = max(1, min(100, (int) $per_page));

        $payload = $this->cache->remember(
            'stocks:' . $safe_page . ':' . $safe_per_page,
            function () use ($safe_page, $safe_per_page) {
                return $this->repository->getStocks($safe_page, $safe_per_page);
            }
        );

        return [
            'pagination' => [
                'page' => $safe_page,
                'per_page' => $safe_per_page,
                'total' => (int) $payload['total'],
                'total_pages' => (int) ceil(((int) $payload['total']) / $safe_per_page),
            ],
            'items' => $payload['items'],
        ];
    }

    public function getCandles($symbol, $limit = 200, $timeframe = 'D') {
        $normalized_symbol = $this->normalizeSymbol($symbol);
        if ($normalized_symbol === '') {
            return [];
        }

        $safe_limit = max(1, min(500, (int) $limit));
        $normalized_timeframe = $this->normalizeTimeframe($timeframe);

        return $this->cache->remember(
            'candles:' . $normalized_symbol . ':' . $normalized_timeframe . ':' . $safe_limit,
            function () use ($normalized_symbol, $safe_limit, $normalized_timeframe) {
                $rows = $this->repository->getCandlesBySymbol($normalized_symbol, $safe_limit, $normalized_timeframe);

                if (empty($rows)) {
                    return [];
                }

                return array_map(
                    static function ($row) {
                        $open = (float) $row->open_price;
                        $close = (float) $row->close_price;
                        $macd = $row->macd !== null ? (float) $row->macd : null;
                        $macd_signal = $row->macd_signal !== null ? (float) $row->macd_signal : null;
                        $time = strtotime((string) $row->trading_date);

                        return [
                            'time' => $time !== false ? (int) $time : 0,
                            'timestamp' => isset($row->event_time) ? (int) $row->event_time : null,
                            'open' => $open,
                            'high' => (float) $row->high_price,
                            'low' => (float) $row->low_price,
                            'close' => $close,
                            'volume' => isset($row->volume) ? (float) $row->volume : 0.0,
                            'macd' => $macd,
                            'macd_signal' => $macd_signal,
                            'macd_histogram' => ($macd !== null && $macd_signal !== null) ? ($macd - $macd_signal) : null,
                            'rsi' => $row->rsi !== null ? (float) $row->rsi : null,
                            'trend' => $close >= $open ? 'up' : 'down',
                        ];
                    },
                    $rows
                );
            }
        );
    }


    public function getChartData($symbol, $range = '1D') {
        $normalized_symbol = $this->normalizeSymbol($symbol);
        if ($normalized_symbol === '') {
            return [
                'success' => false,
                'message' => 'NO_DATA',
            ];
        }

        $normalized_range = strtoupper(sanitize_text_field((string) $range));
        $limit = $this->resolveChartRangeLimit($normalized_range);

        $rows = $this->repository->getChartDataBySymbol($normalized_symbol, $limit);
        if (empty($rows)) {
            return [
                'success' => false,
                'message' => 'NO_DATA',
            ];
        }

        $items = [];
        foreach ($rows as $row) {
            $time = isset($row['time']) ? (int) $row['time'] : 0;
            $open = isset($row['open']) ? (float) $row['open'] : null;
            $high = isset($row['high']) ? (float) $row['high'] : null;
            $low = isset($row['low']) ? (float) $row['low'] : null;
            $close = isset($row['close']) ? (float) $row['close'] : null;
            $volume = isset($row['volume']) ? (int) $row['volume'] : 0;

            if ($time <= 0 || $open === null || $high === null || $low === null || $close === null) {
                continue;
            }

            $items[] = [
                'time' => $time,
                'open' => $open,
                'high' => $high,
                'low' => $low,
                'close' => $close,
                'volume' => $volume,
            ];
        }

        if (empty($items)) {
            return [
                'success' => false,
                'message' => 'NO_DATA',
            ];
        }

        return $items;
    }

    private function resolveChartRangeLimit($range) {
        $map = [
            '1D' => 1,
            '5D' => 5,
            '1M' => 30,
            '3M' => 90,
            '6M' => 180,
            '1Y' => 365,
            '5Y' => 1825,
            'MAX' => 2000,
        ];

        return isset($map[$range]) ? $map[$range] : 200;
    }

    private function normalizeTimeframe($timeframe) {
        $normalized_timeframe = strtoupper(sanitize_text_field((string) $timeframe));

        if ($normalized_timeframe === 'D' || $normalized_timeframe === '1D') {
            return '1D';
        }

        return '1D';
    }

    private function normalizeSymbol($symbol) {
        $normalized_symbol = strtoupper(sanitize_text_field((string) $symbol));
        if ($normalized_symbol === '') {
            return '';
        }

        if (preg_match('/^[A-Z0-9._-]{1,15}$/', $normalized_symbol) !== 1) {
            return '';
        }

        return $normalized_symbol;
    }
}
