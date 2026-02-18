<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_IndicatorService {

    public function buildIndicators(array $row, array $allowed_ma_keys) {
        $ma = [];

        foreach ($allowed_ma_keys as $ma_key) {
            if (array_key_exists($ma_key, $row) && $row[$ma_key] !== null) {
                $ma[$ma_key] = (float) $row[$ma_key];
            }
        }

        return [
            'ma' => $ma,
            'rsi' => $row['rsi'] !== null ? (float) $row['rsi'] : null,
        ];
    }

    public function buildSignals(array $row) {
        return array_filter([
            'xay_nen' => $row['xay_nen'] ?? null,
            'pha_nen' => $row['pha_nen'] ?? null,
            'tang_gia_kem_vol' => $row['tang_gia_kem_vol'] ?? null,
            'smart_money' => $row['smart_money'] ?? null,
            'rs_exchange_status' => $row['rs_exchange_status'] ?? null,
            'rs_exchange_recommend' => $row['rs_exchange_recommend'] ?? null,
        ], static function ($value) {
            return $value !== null && $value !== '';
        });
    }
}
