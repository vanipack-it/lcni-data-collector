<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_AccessControl {

    const PACKAGE_FREE = 'free';
    const PACKAGE_PREMIUM = 'premium';

    public function canAccessStocks() {
        return true;
    }

    public function resolvePackage() {
        if (!is_user_logged_in()) {
            return self::PACKAGE_FREE;
        }

        return $this->normalizePackage(get_user_meta(get_current_user_id(), 'lcni_user_package', true));
    }

    public function normalizePackage($package) {
        $normalized = strtolower(trim((string) $package));

        if (in_array($normalized, ['premium', 'pro'], true)) {
            return self::PACKAGE_PREMIUM;
        }

        return self::PACKAGE_FREE;
    }

    public function getHistoryLimit($package) {
        if ($package === self::PACKAGE_PREMIUM) {
            return 1000;
        }

        return 120;
    }

    public function getIndicatorWhitelist($package) {
        if ($package === self::PACKAGE_PREMIUM) {
            return ['ma10', 'ma20', 'ma50', 'ma100', 'ma200'];
        }

        return ['ma20', 'ma50'];
    }
}
