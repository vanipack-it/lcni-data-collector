<?php

if (!defined('ABSPATH')) {
    exit;
}

class CacheService {
    private $group;

    public function __construct(string $group = 'lcni') {
        $this->group = sanitize_key($group);
    }

    public function get(string $key) {
        $normalized = $this->normalizeKey($key);

        if (function_exists('wp_cache_get')) {
            $value = wp_cache_get($normalized, $this->group);
            if ($value !== false) {
                return $value;
            }
        }

        return get_transient($this->transientKey($normalized));
    }

    public function set(string $key, $value, int $ttl = 60) {
        $normalized = $this->normalizeKey($key);
        $ttl = max(1, $ttl);

        if (function_exists('wp_cache_set')) {
            wp_cache_set($normalized, $value, $this->group, $ttl);
        }

        set_transient($this->transientKey($normalized), $value, $ttl);

        return true;
    }

    public function delete(string $key) {
        $normalized = $this->normalizeKey($key);

        if (function_exists('wp_cache_delete')) {
            wp_cache_delete($normalized, $this->group);
        }

        return delete_transient($this->transientKey($normalized));
    }

    private function normalizeKey(string $key): string {
        return sanitize_key($key);
    }

    private function transientKey(string $key): string {
        return $this->group . '_' . $key;
    }
}
