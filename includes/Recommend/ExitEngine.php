<?php

if (!defined('ABSPATH')) {
    exit;
}

class ExitEngine {

    public function should_exit($signal, $rule, $current_price, $r_multiple, $holding_days) {
        if ((float) $current_price <= (float) $signal['initial_sl']) {
            return true;
        }

        if ((float) $r_multiple >= (float) $rule['exit_at_r']) {
            return true;
        }

        if ((int) $holding_days > (int) $rule['max_hold_days']) {
            return true;
        }

        return false;
    }
}
