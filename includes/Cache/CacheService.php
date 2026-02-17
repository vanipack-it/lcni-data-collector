<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_CacheService {

    private $group;
    private $default_ttl;

    public function __construct($group = 'lcni_rest', $default_ttl = 60) {
        $this->group = $group;
        $this->default_ttl = (int) $default_ttl;
    }

    public function remember($key, callable $callback, $ttl = null) {
        $cache_key = $this->buildCacheKey($key);
        $cache_hit = false;
        $value = wp_cache_get($cache_key, $this->group, false, $cache_hit);

        if ($cache_hit) {
            return $value;
        }

        $transient = get_transient($cache_key);
        if ($transient !== false) {
            wp_cache_set($cache_key, $transient, $this->group, $this->default_ttl);

            return $transient;
        }

        $value = $callback();
        $resolved_ttl = $ttl !== null ? max(1, (int) $ttl) : $this->default_ttl;

        wp_cache_set($cache_key, $value, $this->group, $resolved_ttl);
        set_transient($cache_key, $value, $resolved_ttl);

        return $value;
    }

    private function buildCacheKey($key) {
        return 'lcni:' . md5((string) $key);
    }
}
