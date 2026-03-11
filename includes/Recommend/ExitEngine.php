<?php

if (!defined('ABSPATH')) {
    exit;
}

class ExitEngine {

    public function should_exit($signal, $rule, $current_price, $r_multiple, $holding_days) {
        $entry_price = (float) ($signal['entry_price'] ?? 0);
        $max_loss_pct = abs((float) ($rule['max_loss_pct'] ?? ($rule['initial_sl_pct'] ?? 8)));
        $max_loss_cut_price = $entry_price > 0 ? $entry_price * (1 - ($max_loss_pct / 100)) : 0;

        if ((float) $current_price <= (float) $signal['initial_sl']) {
            return true;
        }

        if ($max_loss_cut_price > 0 && (float) $current_price <= $max_loss_cut_price) {
            return true;
        }

        if ((float) $r_multiple >= (float) $rule['exit_at_r']) {
            return true;
        }

        if ((int) $holding_days >= (int) $rule['max_hold_days']) {
            return true;
        }

        return false;
    }
}
