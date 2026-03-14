<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_Portfolio_Repository {

    private $wpdb;

    // Table names
    public $portfolios_table;
    public $transactions_table;
    public $snapshots_table;

    public function __construct() {
        global $wpdb;
        $this->wpdb              = $wpdb;
        $this->portfolios_table  = $wpdb->prefix . 'lcni_portfolios';
        $this->transactions_table = $wpdb->prefix . 'lcni_portfolio_transactions';
        $this->snapshots_table   = $wpdb->prefix . 'lcni_portfolio_snapshots';
    }

    // =========================================================
    // Schema
    // =========================================================

    public static function maybe_create_tables() {
        if ( ! get_transient('lcni_portfolio_schema_v1') ) {
            self::create_tables();
            set_transient('lcni_portfolio_schema_v1', 1, 10 * MINUTE_IN_SECONDS);
        }
        // Migration: thêm cột mới nếu chưa có
        global $wpdb;
        $p = $wpdb->prefix . 'lcni_portfolios';
        $t = $wpdb->prefix . 'lcni_portfolio_transactions';
        $cols_p = $wpdb->get_col("SHOW COLUMNS FROM {$p}", 0);
        if ( is_array($cols_p) ) {
            if ( ! in_array('source', $cols_p) ) {
                $wpdb->query("ALTER TABLE {$p} ADD COLUMN source ENUM('manual','dnse','combined') NOT NULL DEFAULT 'manual' AFTER is_default");
            }
            if ( ! in_array('dnse_account_no', $cols_p) ) {
                $wpdb->query("ALTER TABLE {$p} ADD COLUMN dnse_account_no VARCHAR(30) NOT NULL DEFAULT '' AFTER source");
            }
            if ( ! in_array('dnse_combined_ids', $cols_p) ) {
                $wpdb->query("ALTER TABLE {$p} ADD COLUMN dnse_combined_ids TEXT NOT NULL DEFAULT '' AFTER dnse_account_no");
            }
        }
        $cols_t = $wpdb->get_col("SHOW COLUMNS FROM {$t}", 0);
        if ( is_array($cols_t) ) {
            if ( ! in_array('dnse_order_id', $cols_t) ) {
                $wpdb->query("ALTER TABLE {$t} ADD COLUMN dnse_order_id VARCHAR(50) NOT NULL DEFAULT '' AFTER note");
            }
            if ( ! in_array('source', $cols_t) ) {
                $wpdb->query("ALTER TABLE {$t} ADD COLUMN source ENUM('manual','dnse') NOT NULL DEFAULT 'manual' AFTER dnse_order_id");
            }
        }
    }

    public static function create_tables() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();

        $portfolios = $wpdb->prefix . 'lcni_portfolios';
        $transactions = $wpdb->prefix . 'lcni_portfolio_transactions';
        $snapshots = $wpdb->prefix . 'lcni_portfolio_snapshots';

        dbDelta("CREATE TABLE {$portfolios} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(200) NOT NULL DEFAULT 'Danh mục của tôi',
            description TEXT NOT NULL DEFAULT '',
            is_default TINYINT(1) NOT NULL DEFAULT 0,
            source ENUM('manual','dnse','combined') NOT NULL DEFAULT 'manual',
            dnse_account_no VARCHAR(30) NOT NULL DEFAULT '',
            dnse_combined_ids TEXT NOT NULL DEFAULT '',
            PRIMARY KEY (id),
            KEY idx_user (user_id),
            KEY idx_source (source)
        ) {$charset};");

        dbDelta("CREATE TABLE {$transactions} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            portfolio_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            symbol VARCHAR(20) NOT NULL,
            type ENUM('buy','sell','dividend','fee') NOT NULL DEFAULT 'buy',
            trade_date DATE NOT NULL,
            quantity DECIMAL(15,2) NOT NULL DEFAULT 0,
            price DECIMAL(15,2) NOT NULL DEFAULT 0,
            fee DECIMAL(15,2) NOT NULL DEFAULT 0,
            tax DECIMAL(15,2) NOT NULL DEFAULT 0,
            note VARCHAR(500) NOT NULL DEFAULT '',
            dnse_order_id VARCHAR(50) NOT NULL DEFAULT '',
            source ENUM('manual','dnse') NOT NULL DEFAULT 'manual',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_portfolio (portfolio_id),
            KEY idx_user_symbol (user_id, symbol),
            KEY idx_trade_date (trade_date)
        ) {$charset};");

        dbDelta("CREATE TABLE {$snapshots} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            portfolio_id BIGINT UNSIGNED NOT NULL,
            snapshot_date DATE NOT NULL,
            total_value DECIMAL(20,2) NOT NULL DEFAULT 0,
            cash_invested DECIMAL(20,2) NOT NULL DEFAULT 0,
            pnl_total DECIMAL(20,2) NOT NULL DEFAULT 0,
            pnl_pct DECIMAL(10,4) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_portfolio_date (portfolio_id, snapshot_date),
            KEY idx_portfolio (portfolio_id)
        ) {$charset};");
    }

    // =========================================================
    // Portfolios
    // =========================================================

    public function get_portfolios($user_id) {
        return $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->portfolios_table} WHERE user_id = %d ORDER BY is_default DESC, id ASC",
                (int) $user_id
            ), ARRAY_A
        ) ?: [];
    }

    public function get_portfolio($portfolio_id, $user_id) {
        return $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->portfolios_table} WHERE id = %d AND user_id = %d",
                (int) $portfolio_id, (int) $user_id
            ), ARRAY_A
        );
    }

    public function create_portfolio($user_id, $name, $description = '') {
        // First portfolio is default
        $count = (int) $this->wpdb->get_var(
            $this->wpdb->prepare("SELECT COUNT(*) FROM {$this->portfolios_table} WHERE user_id = %d", (int) $user_id)
        );
        $this->wpdb->insert($this->portfolios_table, [
            'user_id'     => (int) $user_id,
            'name'        => sanitize_text_field($name),
            'description' => sanitize_textarea_field($description),
            'is_default'  => $count === 0 ? 1 : 0,
        ], ['%d', '%s', '%s', '%d']);
        return (int) $this->wpdb->insert_id;
    }

    public function update_portfolio($portfolio_id, $user_id, $name, $description = '') {
        $this->wpdb->update(
            $this->portfolios_table,
            ['name' => sanitize_text_field($name), 'description' => sanitize_textarea_field($description)],
            ['id' => (int) $portfolio_id, 'user_id' => (int) $user_id],
            ['%s', '%s'], ['%d', '%d']
        );
    }

    public function delete_portfolio($portfolio_id, $user_id) {
        // Delete transactions first
        $this->wpdb->delete($this->transactions_table, ['portfolio_id' => (int) $portfolio_id], ['%d']);
        $this->wpdb->delete($this->snapshots_table, ['portfolio_id' => (int) $portfolio_id], ['%d']);
        $this->wpdb->delete($this->portfolios_table, ['id' => (int) $portfolio_id, 'user_id' => (int) $user_id], ['%d', '%d']);
    }

    public function set_default_portfolio($portfolio_id, $user_id) {
        $this->wpdb->update($this->portfolios_table, ['is_default' => 0], ['user_id' => (int) $user_id], ['%d'], ['%d']);
        $this->wpdb->update($this->portfolios_table, ['is_default' => 1], ['id' => (int) $portfolio_id, 'user_id' => (int) $user_id], ['%d'], ['%d', '%d']);
    }

    // =========================================================
    // Transactions
    // =========================================================

    public function get_transactions($portfolio_id, $user_id, $symbol = '') {
        $where = $this->wpdb->prepare("portfolio_id = %d AND user_id = %d", (int) $portfolio_id, (int) $user_id);
        if ($symbol !== '') {
            $where .= $this->wpdb->prepare(" AND symbol = %s", strtoupper($symbol));
        }
        return $this->wpdb->get_results(
            "SELECT * FROM {$this->transactions_table} WHERE {$where} ORDER BY trade_date ASC, id ASC",
            ARRAY_A
        ) ?: [];
    }

    public function get_transaction($tx_id, $user_id) {
        return $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->transactions_table} WHERE id = %d AND user_id = %d",
                (int) $tx_id, (int) $user_id
            ), ARRAY_A
        );
    }

    public function add_transaction($portfolio_id, $user_id, $data) {
        $this->wpdb->insert($this->transactions_table, [
            'portfolio_id' => (int) $portfolio_id,
            'user_id'      => (int) $user_id,
            'symbol'       => strtoupper(sanitize_text_field($data['symbol'])),
            'type'         => in_array($data['type'], ['buy','sell','dividend','fee'], true) ? $data['type'] : 'buy',
            'trade_date'   => sanitize_text_field($data['trade_date']),
            'quantity'     => (float) $data['quantity'],
            'price'        => (float) $data['price'],
            'fee'          => (float) ($data['fee'] ?? 0),
            'tax'          => (float) ($data['tax'] ?? 0),
            'note'         => sanitize_text_field($data['note'] ?? ''),
        ], ['%d','%d','%s','%s','%s','%f','%f','%f','%f','%s']);
        return (int) $this->wpdb->insert_id;
    }

    public function update_transaction($tx_id, $user_id, $data) {
        $this->wpdb->update(
            $this->transactions_table,
            [
                'symbol'     => strtoupper(sanitize_text_field($data['symbol'])),
                'type'       => in_array($data['type'], ['buy','sell','dividend','fee'], true) ? $data['type'] : 'buy',
                'trade_date' => sanitize_text_field($data['trade_date']),
                'quantity'   => (float) $data['quantity'],
                'price'      => (float) $data['price'],
                'fee'        => (float) ($data['fee'] ?? 0),
                'tax'        => (float) ($data['tax'] ?? 0),
                'note'       => sanitize_text_field($data['note'] ?? ''),
            ],
            ['id' => (int) $tx_id, 'user_id' => (int) $user_id],
            ['%s','%s','%s','%f','%f','%f','%f','%s'],
            ['%d','%d']
        );
    }

    public function delete_transaction($tx_id, $user_id) {
        $this->wpdb->delete($this->transactions_table, ['id' => (int) $tx_id, 'user_id' => (int) $user_id], ['%d','%d']);
    }

    // =========================================================
    // Snapshots
    // =========================================================

    public function upsert_snapshot($portfolio_id, $date, $total_value, $cash_invested, $pnl_total, $pnl_pct) {
        $this->wpdb->query($this->wpdb->prepare(
            "INSERT INTO {$this->snapshots_table}
                (portfolio_id, snapshot_date, total_value, cash_invested, pnl_total, pnl_pct)
             VALUES (%d, %s, %f, %f, %f, %f)
             ON DUPLICATE KEY UPDATE
                total_value = VALUES(total_value),
                cash_invested = VALUES(cash_invested),
                pnl_total = VALUES(pnl_total),
                pnl_pct = VALUES(pnl_pct)",
            (int) $portfolio_id, $date, $total_value, $cash_invested, $pnl_total, $pnl_pct
        ));
    }

    public function get_snapshots($portfolio_id, $limit = 90) {
        return $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT snapshot_date, total_value, cash_invested, pnl_total, pnl_pct
                 FROM {$this->snapshots_table}
                 WHERE portfolio_id = %d
                 ORDER BY snapshot_date DESC
                 LIMIT %d",
                (int) $portfolio_id, (int) $limit
            ), ARRAY_A
        ) ?: [];
    }

    // =========================================================
    // DNSE Integration
    // =========================================================

    /**
     * Lấy hoặc tạo portfolio DNSE cho account_no.
     * Mỗi account DNSE = 1 portfolio riêng (source='dnse').
     */
    public function get_or_create_dnse_portfolio( int $user_id, string $account_no, string $account_type_name = '' ): int {
        global $wpdb;
        $table = $this->portfolios_table;

        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE user_id = %d AND source = 'dnse' AND dnse_account_no = %s LIMIT 1",
            $user_id, $account_no
        ) );

        if ( $existing ) return (int) $existing;

        $name = $account_type_name
            ? "DNSE — {$account_type_name} ({$account_no})"
            : "DNSE — {$account_no}";

        // Đếm portfolio hiện tại
        $count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE user_id = %d", $user_id
        ) );

        $wpdb->insert( $table, [
            'user_id'         => $user_id,
            'name'            => $name,
            'description'     => 'Danh mục đồng bộ tự động từ DNSE.',
            'is_default'      => $count === 0 ? 1 : 0,
            'source'          => 'dnse',
            'dnse_account_no' => $account_no,
        ] );

        return (int) $wpdb->insert_id;
    }

    /**
     * Upsert transaction từ lệnh DNSE đã khớp.
     * Idempotent theo dnse_order_id.
     */
    public function upsert_dnse_transaction( int $portfolio_id, int $user_id, array $order ): bool {
        global $wpdb;
        $table = $this->transactions_table;

        $dnse_id = (string) ( $order['dnse_order_id'] ?? '' );
        if ( $dnse_id === '' ) return false;

        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE user_id = %d AND dnse_order_id = %s LIMIT 1",
            $user_id, $dnse_id
        ) );

        $data = [
            'portfolio_id'  => $portfolio_id,
            'user_id'       => $user_id,
            'symbol'        => strtoupper( (string) ( $order['symbol'] ?? '' ) ),
            'type'          => $order['side'] === 'sell' ? 'sell' : 'buy',
            'trade_date'    => $order['order_date'] ?? current_time('Y-m-d'),
            'quantity'      => (float) ( $order['filled_quantity'] ?? $order['quantity'] ?? 0 ),
            'price'         => (float) ( $order['price'] ?? 0 ),
            'fee'           => 0.0,
            'tax'           => 0.0,
            'note'          => 'Đồng bộ từ DNSE. Lệnh #' . $dnse_id,
            'dnse_order_id' => $dnse_id,
            'source'        => 'dnse',
        ];

        if ( $existing ) {
            // Cập nhật filled_quantity nếu lệnh vừa khớp thêm
            $wpdb->update( $table, [
                'quantity' => $data['quantity'],
                'price'    => $data['price'],
            ], [ 'id' => (int) $existing ], [ '%f', '%f' ], [ '%d' ] );
            return true;
        }

        return $wpdb->insert( $table, $data ) !== false;
    }

    /**
     * Xóa tất cả transactions nguồn DNSE của một portfolio
     * (dùng khi reset sync).
     */
    public function clear_dnse_transactions( int $portfolio_id, int $user_id ): void {
        $this->wpdb->delete(
            $this->transactions_table,
            [ 'portfolio_id' => $portfolio_id, 'user_id' => $user_id, 'source' => 'dnse' ],
            [ '%d', '%d', '%s' ]
        );
    }

    /**
     * Lấy danh sách portfolios kèm thêm meta DNSE.
     */
    public function get_portfolios_with_meta( int $user_id ): array {
        return $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT id, name, description, is_default, source, dnse_account_no, dnse_combined_ids
                 FROM {$this->portfolios_table}
                 WHERE user_id = %d ORDER BY is_default DESC, source ASC, id ASC",
                $user_id
            ), ARRAY_A
        ) ?: [];
    }

    /**
     * Update combined_ids (danh sách portfolio_id gộp).
     */
    public function update_combined_ids( int $portfolio_id, int $user_id, array $ids ): void {
        $this->wpdb->update(
            $this->portfolios_table,
            [ 'dnse_combined_ids' => implode(',', array_map('intval', $ids)) ],
            [ 'id' => $portfolio_id, 'user_id' => $user_id ],
            [ '%s' ], [ '%d', '%d' ]
        );
    }

}
