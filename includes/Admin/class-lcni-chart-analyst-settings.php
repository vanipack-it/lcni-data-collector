<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_Chart_Analyst_Settings {

    public static function get_default_config() {
        return [
            'templates' => [
                'momentum' => [
                    'label' => 'Momentum Template',
                    'indicators' => ['ma20', 'ma50', 'rs_1w_by_exchange', 'rs_1m_by_exchange'],
                ],
                'trend' => [
                    'label' => 'Trend Template',
                    'indicators' => ['ma50', 'ma200', 'rs_3m_by_exchange'],
                ],
                'swing' => [
                    'label' => 'Swing Template',
                    'indicators' => ['rsi', 'macd'],
                ],
            ],
            'default_template' => [
                'stock_detail' => 'momentum',
                'dashboard' => 'trend',
                'watchlist' => 'swing',
            ],
        ];
    }

    public static function get_allowed_indicators() {
        return [
            'ma20',
            'ma50',
            'ma100',
            'ma200',
            'rsi',
            'macd',
            'rs_1w_by_exchange',
            'rs_1m_by_exchange',
            'rs_3m_by_exchange',
        ];
    }

    public static function sanitize_config($value) {
        $default = self::get_default_config();
        $allowedIndicators = self::get_allowed_indicators();
        $allowedContexts = ['stock_detail', 'dashboard', 'watchlist'];

        if (!is_array($value)) {
            $value = [];
        }

        $templates = [];
        $rawTemplates = isset($value['templates']) && is_array($value['templates']) ? $value['templates'] : [];

        foreach ($default['templates'] as $key => $templateDefault) {
            $templatePayload = isset($rawTemplates[$key]) && is_array($rawTemplates[$key]) ? $rawTemplates[$key] : [];
            $label = sanitize_text_field((string) ($templatePayload['label'] ?? $templateDefault['label']));
            if ($label === '') {
                $label = $templateDefault['label'];
            }

            $indicatorsRaw = isset($templatePayload['indicators']) && is_array($templatePayload['indicators'])
                ? array_map('sanitize_key', $templatePayload['indicators'])
                : $templateDefault['indicators'];
            $indicators = array_values(array_intersect($allowedIndicators, $indicatorsRaw));
            if (empty($indicators)) {
                $indicators = $templateDefault['indicators'];
            }

            $templates[$key] = [
                'label' => $label,
                'indicators' => $indicators,
            ];
        }

        $defaultTemplate = [];
        $rawDefaultTemplate = isset($value['default_template']) && is_array($value['default_template']) ? $value['default_template'] : [];
        $allowedTemplateKeys = array_keys($templates);

        foreach ($allowedContexts as $context) {
            $templateKey = sanitize_key((string) ($rawDefaultTemplate[$context] ?? $default['default_template'][$context]));
            if (!in_array($templateKey, $allowedTemplateKeys, true)) {
                $templateKey = $default['default_template'][$context];
            }

            $defaultTemplate[$context] = $templateKey;
        }

        return [
            'templates' => $templates,
            'default_template' => $defaultTemplate,
        ];
    }
}
