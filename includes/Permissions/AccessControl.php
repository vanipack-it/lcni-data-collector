<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_AccessControl {

    const PACKAGE_FREE = 'free';
    const PACKAGE_PRO = 'pro';

    public function canAccessStocks() {
        return true;
    }

    public function resolvePackage() {
        if (!is_user_logged_in()) {
            return self::PACKAGE_FREE;
        }

        $package = strtolower((string) get_user_meta(get_current_user_id(), 'lcni_user_package', true));

        return $package === self::PACKAGE_PRO ? self::PACKAGE_PRO : self::PACKAGE_FREE;
    }

    public function getHistoryLimit($package) {
        if ($package === self::PACKAGE_PRO) {
            return 1000;
        }

        return 120;
    }

    public function getIndicatorWhitelist($package) {
        if ($package === self::PACKAGE_PRO) {
            return ['ma10', 'ma20', 'ma50', 'ma100', 'ma200'];
        }

        return ['ma20', 'ma50'];
    }
}
