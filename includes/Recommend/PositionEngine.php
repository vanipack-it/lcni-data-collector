<?php

if (!defined('ABSPATH')) {
    exit;
}

class PositionEngine {

    public function calculate_r_multiple($entry_price, $initial_sl, $current_price) {
        $risk = (float) $entry_price - (float) $initial_sl;
        if ($risk <= 0) {
            return 0.0;
        }

        return ((float) $current_price - (float) $entry_price) / $risk;
    }

    public function resolve_state($r_multiple, $add_at_r, $exit_at_r) {
        $r_multiple = (float) $r_multiple;

        if ($r_multiple < 0) {
            return 'CUT_ZONE';
        }

        if ($r_multiple < 1) {
            return 'EARLY';
        }

        if ($r_multiple < (float) $add_at_r) {
            return 'HOLD';
        }

        if ($r_multiple < (float) $exit_at_r) {
            return 'ADD_ZONE';
        }

        return 'TAKE_PROFIT_ZONE';
    }

    public function action_for_state($state) {
        $map = [
            'CUT_ZONE' => 'Cắt',
            'EARLY' => 'Theo dõi',
            'HOLD' => 'Nắm giữ',
            'ADD_ZONE' => 'Gia tăng',
            'TAKE_PROFIT_ZONE' => 'Chốt từng phần',
        ];

        return $map[$state] ?? 'Theo dõi';
    }
}
