<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_Button_Registry {
    private static $buttons = [];

    public static function register($key, $label, $module) {
        $key = sanitize_key((string) $key);
        $label = sanitize_text_field((string) $label);
        $module = sanitize_text_field((string) $module);

        if ($key === '' || $label === '') {
            return;
        }

        self::$buttons[$key] = [
            'label' => $label,
            'module' => $module,
        ];
    }

    public static function getAll() {
        return self::$buttons;
    }
}
