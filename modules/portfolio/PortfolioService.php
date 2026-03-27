<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_Portfolio_Service {

    private $repo;

    public function __construct(LCNI_Portfolio_Repository $repo) {
        $this->repo = $repo;
    }

    // =========================================================
    // Portfolio CRUD
    // =========================================================

    public function get_portfolios($user_id) {
        return $this->repo->get_portfolios((int) $user_id);
    }

    public function create_portfolio($user_id, $name, $description = '') {
        if (trim($name) === '') {
            $name = 'Danh mục ' . gmdate('d/m/Y');
        }
        return $this->repo->create_portfolio((int) $user_id, $name, $description);
    }

    public function update_portfolio($portfolio_id, $user_id, $name, $description = '') {
        $this->repo->update_portfolio((int) $portfolio_id, (int) $user_id, $name, $description);
    }

    public function delete_portfolio($portfolio_id, $user_id) {
        $portfolio = $this->repo->get_portfolio((int) $portfolio_id, (int) $user_id);
        if (!$portfolio) {
            return new WP_Error('not_found', 'Danh mục không tồn tại.');
        }
        $this->repo->delete_portfolio((int) $portfolio_id, (int) $user_id);
        return true;
    }

    public function set_default($portfolio_id, $user_id) {
        $this->repo->set_default_portfolio((int) $portfolio_id, (int) $user_id);
    }

    // =========================================================
    // Transaction CRUD
    // =========================================================

    public function add_transaction($portfolio_id, $user_id, $data) {
        $portfolio = $this->repo->get_portfolio((int) $portfolio_id, (int) $user_id);
        if (!$portfolio) {
            return new WP_Error('not_found', 'Danh mục không tồn tại.');
        }
        $errors = $this->validate_transaction($data);
        if (!empty($errors)) {
            return new WP_Error('validation', implode(' ', $errors));
        }
        $tx_id = $this->repo->add_transaction((int) $portfolio_id, (int) $user_id, $data);
        $this->save_today_snapshot((int) $portfolio_id, (int) $user_id);
        return $tx_id;
    }

    public function update_transaction($tx_id, $user_id, $data) {
        $tx = $this->repo->get_transaction((int) $tx_id, (int) $user_id);
        if (!$tx) {
            return new WP_Error('not_found', 'Giao dịch không tồn tại.');
        }
        $errors = $this->validate_transaction($data);
        if (!empty($errors)) {
            return new WP_Error('validation', implode(' ', $errors));
        }
        $this->repo->update_transaction((int) $tx_id, (int) $user_id, $data);
        $this->save_today_snapshot((int) $tx['portfolio_id'], (int) $user_id);
        return true;
    }

    public function delete_transaction($tx_id, $user_id) {
        $tx = $this->repo->get_transaction((int) $tx_id, (int) $user_id);
        if (!$tx) {
            return new WP_Error('not_found', 'Giao dịch không tồn tại.');
        }
        $this->repo->delete_transaction((int) $tx_id, (int) $user_id);
        $this->save_today_snapshot((int) $tx['portfolio_id'], (int) $user_id);
        return true;
    }

    // =========================================================
    // P&L Engine — Bình quân gia quyền (BQGQ)
    // =========================================================

    /**
     * Tính toàn bộ P&L cho 1 portfolio.
     * Returns: [
     *   'holdings'       => [...],   // Các mã đang nắm giữ
     *   'summary'        => [...],   // Tổng danh mục
     *   'transactions'   => [...],   // Lịch sử giao dịch (enriched)
     *   'allocation'     => [...],   // Tỷ trọng %
     * ]
     */
    public function get_portfolio_data($portfolio_id, $user_id) {
        $portfolio = $this->repo->get_portfolio((int) $portfolio_id, (int) $user_id);
        if (!$portfolio) {
            return null;
        }

        $transactions = $this->repo->get_transactions((int) $portfolio_id, (int) $user_id);
        $holdings     = $this->calculate_holdings($transactions);
        $prices       = $this->get_current_prices(array_keys($holdings));
        $enriched     = $this->enrich_holdings($holdings, $prices);
        $summary      = $this->calculate_summary($enriched, $transactions);
        $allocation   = $this->calculate_allocation($enriched, $summary['total_market_value']);

        return [
            'portfolio'    => $portfolio,
            'holdings'     => array_values($enriched),
            'summary'      => $summary,
            'allocation'   => $allocation,
            'transactions' => $this->enrich_transactions($transactions, $holdings),
        ];
    }

    /**
     * Bình quân gia quyền:
     * Giá vốn TB = (Tổng tiền mua còn lại) / (Tổng khối lượng còn lại)
     */
    private function calculate_holdings($transactions) {
        $holdings = [];

        foreach ($transactions as $tx) {
            $symbol   = strtoupper($tx['symbol']);
            $type     = $tx['type'];
            $qty      = (float) $tx['quantity'];
            // DB/API stores prices in thousands (21.5 = 21,500 VNĐ) — normalize to full VNĐ
            $price    = (float) $tx['price'] * 1000;
            $fee      = (float) $tx['fee'];
            $tax      = (float) $tx['tax'];
            $date     = $tx['trade_date'];

            if (!isset($holdings[$symbol])) {
                $holdings[$symbol] = [
                    'symbol'           => $symbol,
                    'quantity'         => 0.0,
                    'avg_cost'         => 0.0,
                    'total_cost_basis' => 0.0,  // Tổng tiền vốn hiện tại (đã bình quân)
                    'realized_pnl'     => 0.0,  // Lãi/lỗ đã chốt
                    'total_fee'        => 0.0,
                    'first_trade_date' => $date,
                    'last_trade_date'  => $date,
                ];
            }

            $h = &$holdings[$symbol];
            $h['last_trade_date'] = $date;

            if ($type === 'buy') {
                $cost_this_trade    = $qty * $price + $fee;
                $new_quantity       = $h['quantity'] + $qty;
                $new_cost_basis     = $h['total_cost_basis'] + $cost_this_trade;

                $h['quantity']         = $new_quantity;
                $h['total_cost_basis'] = $new_cost_basis;
                $h['avg_cost']         = $new_quantity > 0 ? $new_cost_basis / $new_quantity : 0.0;
                $h['total_fee']       += $fee;

            } elseif ($type === 'sell') {
                if ($h['quantity'] <= 0) continue;

                $sell_value         = $qty * $price - $fee - $tax;
                $cost_of_sold       = $h['avg_cost'] * $qty;
                $realized           = $sell_value - $cost_of_sold;

                $h['realized_pnl']    += $realized;
                $h['quantity']         = max(0, $h['quantity'] - $qty);
                $h['total_cost_basis'] = $h['avg_cost'] * $h['quantity'];
                $h['total_fee']       += $fee + $tax;

            } elseif ($type === 'dividend') {
                // Cổ tức tính vào realized PnL
                $h['realized_pnl'] += $qty * $price;

            } elseif ($type === 'fee') {
                $h['realized_pnl'] -= $price; // 'price' field dùng để lưu số tiền phí
                $h['total_fee']    += $price;
            }
        }

        // Loại bỏ mã đã bán hết (quantity = 0) nhưng vẫn giữ để hiện lịch sử
        return $holdings;
    }

    private function enrich_holdings($holdings, $prices) {
        $enriched = [];
        foreach ($holdings as $symbol => $h) {
            $current_price    = (float) ($prices[$symbol] ?? 0);
            $qty              = (float) $h['quantity'];
            $market_value     = $qty * $current_price;
            $unrealized_pnl   = $qty > 0 ? $market_value - $h['total_cost_basis'] : 0.0;
            $unrealized_pct   = $h['total_cost_basis'] > 0
                ? ($unrealized_pnl / $h['total_cost_basis']) * 100
                : 0.0;
            $total_pnl        = $h['realized_pnl'] + $unrealized_pnl;

            $enriched[$symbol] = array_merge($h, [
                'current_price'  => $current_price,
                'market_value'   => $market_value,
                'unrealized_pnl' => $unrealized_pnl,
                'unrealized_pct' => round($unrealized_pct, 2),
                'total_pnl'      => $total_pnl,
                'is_holding'     => $qty > 0,
            ]);
        }
        return $enriched;
    }

    private function calculate_summary($enriched, $transactions) {
        $total_market_value  = 0.0;
        $total_cost_basis    = 0.0;
        $total_realized      = 0.0;
        $total_unrealized    = 0.0;
        $total_invested      = 0.0; // Tổng tiền đã bỏ ra (buy)

        foreach ($enriched as $h) {
            if ($h['is_holding']) {
                $total_market_value += $h['market_value'];
                $total_cost_basis   += $h['total_cost_basis'];
                $total_unrealized   += $h['unrealized_pnl'];
            }
            $total_realized += $h['realized_pnl'];
        }

        foreach ($transactions as $tx) {
            if ($tx['type'] === 'buy') {
                // price ở dạng DB (21.5) → nhân 1000 để ra VNĐ đầy đủ
                $total_invested += (float) $tx['quantity'] * (float) $tx['price'] * 1000 + (float) $tx['fee'];
            }
        }

        $total_pnl     = $total_realized + $total_unrealized;
        $total_pnl_pct = $total_cost_basis > 0
            ? ($total_unrealized / $total_cost_basis) * 100
            : 0.0;

        return [
            'total_market_value'  => round($total_market_value, 0),
            'total_cost_basis'    => round($total_cost_basis, 0),
            'total_invested'      => round($total_invested, 0),
            'total_realized_pnl'  => round($total_realized, 0),
            'total_unrealized_pnl'=> round($total_unrealized, 0),
            'total_pnl'           => round($total_pnl, 0),
            'total_pnl_pct'       => round($total_pnl_pct, 2),
            'holding_count'       => count(array_filter($enriched, fn($h) => $h['is_holding'])),
            'tx_count'            => count($transactions),
        ];
    }

    private function calculate_allocation($enriched, $total_market_value) {
        $alloc = [];
        foreach ($enriched as $symbol => $h) {
            if (!$h['is_holding'] || $total_market_value <= 0) continue;
            $alloc[] = [
                'symbol' => $symbol,
                'value'  => round($h['market_value'], 0),
                'pct'    => round(($h['market_value'] / $total_market_value) * 100, 2),
            ];
        }
        usort($alloc, fn($a, $b) => $b['value'] <=> $a['value']);
        return $alloc;
    }

    private function enrich_transactions($transactions, $holdings) {
        $result = [];
        foreach (array_reverse($transactions) as $tx) {
            $symbol    = strtoupper($tx['symbol']);
            $avg_cost  = (float) ($holdings[$symbol]['avg_cost'] ?? 0);
            // avg_cost đã ở dạng VNĐ đầy đủ (sau normalize), giữ nguyên
            // price trong tx vẫn ở dạng DB — convert sang VNĐ đầy đủ cho JS
            $price_vnd = (float) $tx['price'] * 1000;
            $result[]  = array_merge($tx, [
                'price'            => $price_vnd,
                'avg_cost_at_time' => $avg_cost,
                'total_value'      => (float) $tx['quantity'] * $price_vnd,
            ]);
        }
        return $result;
    }

    // =========================================================
    // Equity Curve (Snapshots)
    // =========================================================

    public function get_equity_curve($portfolio_id, $user_id, $limit = 90) {
        $portfolio = $this->repo->get_portfolio((int) $portfolio_id, (int) $user_id);
        if (!$portfolio) return [];

        $rows = $this->repo->get_snapshots((int) $portfolio_id, $limit);
        return array_reverse($rows); // chronological order
    }

    public function save_today_snapshot($portfolio_id, $user_id) {
        $data = $this->get_portfolio_data($portfolio_id, $user_id);
        if (!$data) return;

        $s = $data['summary'];
        $this->repo->upsert_snapshot(
            $portfolio_id,
            current_time('Y-m-d'),
            $s['total_market_value'],
            $s['total_cost_basis'],
            $s['total_pnl'],
            $s['total_pnl_pct']
        );
    }

    // =========================================================
    // Price lookup — dùng bảng lcni_ohlc_latest
    // =========================================================

    private function get_current_prices($symbols) {
        if (empty($symbols)) return [];

        global $wpdb;
        $latest_table = $wpdb->prefix . 'lcni_ohlc_latest';

        // Check table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '{$latest_table}'") !== $latest_table) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($symbols), '%s'));
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT symbol, close_price FROM {$latest_table}
                 WHERE symbol IN ({$placeholders}) AND timeframe = '1D'",
                ...$symbols
            ), ARRAY_A
        ) ?: [];

        // DB stores prices in thousands (e.g. 21.5 = 21,500 VNĐ) — convert to full VNĐ
        $prices = [];
        foreach ($rows as $row) {
            $prices[strtoupper($row['symbol'])] = (float) $row['close_price'] * 1000;
        }
        return $prices;
    }

    // =========================================================
    // Validation
    // =========================================================

    private function validate_transaction($data) {
        $errors = [];
        if (empty($data['symbol'])) $errors[] = 'Thiếu mã chứng khoán.';
        if (empty($data['trade_date'])) $errors[] = 'Thiếu ngày giao dịch.';
        if (empty($data['type']) || !in_array($data['type'], ['buy','sell','dividend','fee'], true)) {
            $errors[] = 'Loại giao dịch không hợp lệ.';
        }
        if (!isset($data['quantity']) || (float) $data['quantity'] <= 0) {
            $errors[] = 'Khối lượng phải lớn hơn 0.';
        }
        if (!isset($data['price']) || (float) $data['price'] < 0) {
            $errors[] = 'Giá không hợp lệ.';
        }
        return $errors;
    }

    // =========================================================
    // DNSE Integration
    // =========================================================

    /**
     * Lấy data cho portfolio DNSE/combined.
     * - source='dnse'     → dùng live positions từ lcni_dnse_positions
     * - source='combined' → gộp transactions của nhiều portfolio
     * - source='manual'   → giữ nguyên logic cũ
     */
    public function get_portfolio_data_with_dnse( int $portfolio_id, int $user_id ): ?array {
        $portfolio = $this->repo->get_portfolio( $portfolio_id, $user_id );
        if ( ! $portfolio ) return null;

        $source = $portfolio['source'] ?? 'manual';

        if ( $source === 'dnse' ) {
            return $this->get_dnse_live_data( $portfolio, $user_id );
        }

        if ( $source === 'combined' ) {
            return $this->get_combined_data( $portfolio, $user_id );
        }

        // Manual: giữ nguyên logic cũ
        return $this->get_portfolio_data( $portfolio_id, $user_id );
    }

    /**
     * Data realtime từ lcni_dnse_positions (không qua transactions).
     * Hiển thị đúng vị thế hiện tại từ DNSE.
     */
    private function get_dnse_live_data( array $portfolio, int $user_id ): array {
        global $wpdb;

        $account_no    = $portfolio['dnse_account_no'] ?? '';
        $pos_table     = $wpdb->prefix . 'lcni_dnse_positions';
        $orders_table  = $wpdb->prefix . 'lcni_dnse_orders';

        // Positions hiện tại
        $positions = $wpdb->get_results( $wpdb->prepare(
            "SELECT symbol, quantity, avg_price, current_price, market_value,
                    unrealized_pnl, unrealized_pnl_pct, synced_at
             FROM {$pos_table}
             WHERE user_id = %d AND account_no = %s AND quantity > 0
             ORDER BY market_value DESC",
            $user_id, $account_no
        ), ARRAY_A ) ?: [];

        // Lịch sử lệnh đã khớp (transactions ảo)
        $orders = $wpdb->get_results( $wpdb->prepare(
            "SELECT symbol, side, price, filled_quantity AS quantity, order_date, dnse_order_id, status
             FROM {$orders_table}
             WHERE user_id = %d AND account_no = %s AND filled_quantity > 0
             ORDER BY order_date DESC LIMIT 200",
            $user_id, $account_no
        ), ARRAY_A ) ?: [];

        // Tính summary từ positions
        $total_market_value = 0;
        $total_cost_basis   = 0;
        $holdings = [];

        foreach ( $positions as $pos ) {
            $market_val  = (float) $pos['market_value'];
            $avg_price   = (float) $pos['avg_price'];
            $qty         = (float) $pos['quantity'];
            $cost        = $avg_price * $qty * 1000; // avg_price từ DNSE là giá nghìn đồng

            $total_market_value += $market_val;
            $total_cost_basis   += $cost;

            $holdings[] = [
                'symbol'           => $pos['symbol'],
                'quantity'         => $qty,
                'avg_cost'         => $avg_price * 1000,
                'market_price'     => (float) $pos['current_price'] * 1000,
                'market_value'     => $market_val,
                'unrealized_pnl'   => (float) $pos['unrealized_pnl'],
                'unrealized_pnl_pct' => (float) $pos['unrealized_pnl_pct'],
                'total_cost_basis' => $cost,
                'realized_pnl'     => 0,
                'source'           => 'dnse',
                'synced_at'        => $pos['synced_at'],
            ];
        }

        $total_pnl     = $total_market_value - $total_cost_basis;
        $total_pnl_pct = $total_cost_basis > 0 ? ( $total_pnl / $total_cost_basis ) * 100 : 0;

        // Map orders thành transactions ảo
        $transactions = array_map( function( $o ) {
            return [
                'id'          => 0,
                'symbol'      => $o['symbol'],
                'type'        => $o['side'] === 'sell' ? 'sell' : 'buy',
                'trade_date'  => $o['order_date'],
                'quantity'    => (float) $o['quantity'],
                'price'       => (float) $o['price'],
                'fee'         => 0,
                'tax'         => 0,
                'note'        => 'DNSE #' . $o['dnse_order_id'],
                'source'      => 'dnse',
                'dnse_order_id' => $o['dnse_order_id'],
            ];
        }, $orders );

        return [
            'portfolio'    => array_merge( $portfolio, [ 'source' => 'dnse' ] ),
            'holdings'     => $holdings,
            'summary'      => [
                'total_market_value'  => $total_market_value,
                'total_cost_basis'    => $total_cost_basis,
                'total_unrealized_pnl'=> $total_pnl,
                'total_realized_pnl'  => 0,
                'total_pnl'           => $total_pnl,
                'total_pnl_pct'       => round( $total_pnl_pct, 2 ),
                'holding_count'       => count( $holdings ),
                'source'              => 'dnse',
            ],
            'allocation'   => $this->calculate_allocation( $holdings, $total_market_value ),
            'transactions' => $transactions,
        ];
    }

    /**
     * Gộp data của nhiều portfolios (manual + dnse).
     * dnse_combined_ids = "1,2,3" (portfolio IDs cần gộp)
     */
    private function get_combined_data( array $portfolio, int $user_id ): array {
        $ids_raw = array_filter( array_map( 'intval',
            explode( ',', $portfolio['dnse_combined_ids'] ?? '' )
        ) );

        if ( empty( $ids_raw ) ) {
            return $this->get_portfolio_data( (int) $portfolio['id'], $user_id );
        }

        $all_holdings     = [];
        $total_market     = 0;
        $total_cost       = 0;
        $total_unrealized = 0;
        $total_realized   = 0;
        $all_transactions = [];

        foreach ( $ids_raw as $sub_id ) {
            $sub = $this->get_portfolio_data_with_dnse( $sub_id, $user_id );
            if ( ! $sub ) continue;

            // Gộp holdings: nếu trùng symbol → cộng dồn
            foreach ( $sub['holdings'] as $h ) {
                $sym = $h['symbol'];
                if ( isset( $all_holdings[ $sym ] ) ) {
                    $ex = &$all_holdings[ $sym ];
                    // Bình quân gia quyền lại
                    $new_qty   = $ex['quantity'] + $h['quantity'];
                    $new_cost  = $ex['total_cost_basis'] + $h['total_cost_basis'];
                    $ex['quantity']         = $new_qty;
                    $ex['total_cost_basis'] = $new_cost;
                    $ex['avg_cost']         = $new_qty > 0 ? $new_cost / $new_qty : 0;
                    $ex['market_value']    += $h['market_value'];
                    $ex['unrealized_pnl']  += $h['unrealized_pnl'];
                    $ex['realized_pnl']    += $h['realized_pnl'];
                } else {
                    $all_holdings[ $sym ] = $h;
                }
            }

            $s = $sub['summary'];
            $total_market     += (float) $s['total_market_value'];
            $total_cost       += (float) $s['total_cost_basis'];
            $total_unrealized += (float) $s['total_unrealized_pnl'];
            $total_realized   += (float) $s['total_realized_pnl'];

            // Gộp transactions, đánh dấu source portfolio
            foreach ( $sub['transactions'] as $tx ) {
                $tx['_source_portfolio'] = $sub['portfolio']['name'];
                $all_transactions[] = $tx;
            }
        }

        // Sort transactions theo date DESC
        usort( $all_transactions, fn($a, $b) =>
            strcmp( $b['trade_date'] ?? '', $a['trade_date'] ?? '' )
        );

        $total_pnl     = $total_market - $total_cost;
        $total_pnl_pct = $total_cost > 0 ? ( $total_pnl / $total_cost ) * 100 : 0;

        return [
            'portfolio'    => array_merge( $portfolio, [ 'source' => 'combined' ] ),
            'holdings'     => array_values( $all_holdings ),
            'summary'      => [
                'total_market_value'   => $total_market,
                'total_cost_basis'     => $total_cost,
                'total_unrealized_pnl' => $total_unrealized,
                'total_realized_pnl'   => $total_realized,
                'total_pnl'            => $total_pnl,
                'total_pnl_pct'        => round( $total_pnl_pct, 2 ),
                'holding_count'        => count( $all_holdings ),
                'source'               => 'combined',
            ],
            'allocation'   => $this->calculate_allocation( array_values( $all_holdings ), $total_market ),
            'transactions' => $all_transactions,
        ];
    }

    /**
     * Đồng bộ lệnh DNSE đã khớp vào portfolio transactions.
     * Gọi sau mỗi lần sync DNSE (DnseTradingService::sync_all).
     */
    public function sync_dnse_orders_to_portfolio( int $user_id, string $account_no, string $account_type_name = '' ): int {
        global $wpdb;

        // Lấy hoặc tạo portfolio DNSE
        $portfolio_id = $this->repo->get_or_create_dnse_portfolio( $user_id, $account_no, $account_type_name );
        if ( ! $portfolio_id ) return 0;

        // Lấy lệnh đã khớp từ DNSE
        $orders_table = $wpdb->prefix . 'lcni_dnse_orders';
        $orders = $wpdb->get_results( $wpdb->prepare(
            "SELECT symbol, side, price, filled_quantity, order_date, dnse_order_id, status
             FROM {$orders_table}
             WHERE user_id = %d AND account_no = %s
               AND filled_quantity > 0
               AND status IN ('filled','matched','FILLED','MATCHED')
             ORDER BY order_date ASC",
            $user_id, $account_no
        ), ARRAY_A ) ?: [];

        $synced = 0;
        foreach ( $orders as $order ) {
            $ok = $this->repo->upsert_dnse_transaction( $portfolio_id, $user_id, [
                'symbol'          => $order['symbol'],
                'side'            => $order['side'],
                'price'           => (float) $order['price'],
                'filled_quantity' => (float) $order['filled_quantity'],
                'quantity'        => (float) $order['filled_quantity'],
                'order_date'      => $order['order_date'],
                'dnse_order_id'   => $order['dnse_order_id'],
            ] );
            if ( $ok ) $synced++;
        }

        return $synced;
    }

}
