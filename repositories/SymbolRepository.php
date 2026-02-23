<?php

if (!defined('ABSPATH')) {
    exit;
}

class SymbolRepository {

    private $wpdb;
    private $table_symbol;

    public function __construct() {
        global $wpdb;

        $this->wpdb = $wpdb;
        $this->table_symbol = $wpdb->prefix . 'lcni_symbol_tongquan';
    }

    public function getAllSymbols() {
        return $this->wpdb->get_col(
            "SELECT DISTINCT symbol
            FROM {$this->table_symbol}
            WHERE symbol IS NOT NULL"
        );
    }

    public function isValid($symbol) {
        $symbol = strtoupper(trim((string) $symbol));

        return (bool) $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(*)
                FROM {$this->table_symbol}
                WHERE symbol = %s",
                $symbol
            )
        );
    }
}
