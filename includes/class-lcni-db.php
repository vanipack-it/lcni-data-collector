class LCNI_DB {

    public static function create_tables() {
        global $wpdb;

        $charset = $wpdb->get_charset_collate();

        $table = $wpdb->prefix . 'lcni_candles';

        $sql = "CREATE TABLE $table (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            symbol VARCHAR(20),
            timeframe VARCHAR(10),
            candle_time DATETIME,
            open FLOAT,
            high FLOAT,
            low FLOAT,
            close FLOAT,
            volume BIGINT,
            UNIQUE KEY unique_candle (symbol, timeframe, candle_time)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }
}
