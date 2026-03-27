<?php
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'LCNI_UserActivityTracker' ) ) :
class LCNI_UserActivityTracker {

    const TABLE   = 'lcni_user_activity';
    const VERSION = 1;

    // Không dùng typed property (PHP 7.4+) -> dùng giá trị đơn giản
    private static $installed = false;

    // =========================================================================
    // INSTALL
    // =========================================================================

    public static function install( $wpdb ) {
        $table   = $wpdb->prefix . self::TABLE;
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta( "CREATE TABLE {$table} (
            id           BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
            user_id      BIGINT UNSIGNED  NOT NULL,
            event_type   VARCHAR(30)      NOT NULL,
            event_meta   VARCHAR(255)     NOT NULL DEFAULT '',
            session_date DATE             NOT NULL,
            hour_of_day  TINYINT UNSIGNED NOT NULL DEFAULT 0,
            day_of_week  TINYINT UNSIGNED NOT NULL DEFAULT 0,
            created_at   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_user_date (user_id, session_date),
            KEY idx_event     (event_type, session_date),
            KEY idx_heatmap   (user_id, hour_of_day, day_of_week)
        ) {$charset};" );

        update_option( 'lcni_user_activity_db_version', self::VERSION );
    }

    public static function ensure( $wpdb ) {
        // Dùng transient để tránh check DB mỗi request
        if ( get_transient( 'lcni_activity_v' . self::VERSION ) ) return;
        $ver = (int) get_option( 'lcni_user_activity_db_version', 0 );
        if ( $ver >= self::VERSION ) {
            set_transient( 'lcni_activity_v' . self::VERSION, 1, DAY_IN_SECONDS );
            return;
        }
        self::install( $wpdb );
        set_transient( 'lcni_activity_v' . self::VERSION, 1, DAY_IN_SECONDS );
    }

    // =========================================================================
    // REGISTER HOOKS
    // =========================================================================

    public function register_hooks() {
        add_action( 'wp_login',  array( $this, 'on_login'  ), 10, 2 );
        add_action( 'wp_logout', array( $this, 'on_logout' ) );
        add_action( 'wp',        array( $this, 'on_page_view' ) );

        add_action( 'lcni_rule_followed',    array( $this, 'on_rule_follow'   ), 10, 2 );
        add_action( 'lcni_rule_unfollowed',  array( $this, 'on_rule_unfollow' ), 10, 2 );
        add_action( 'lcni_user_rule_created',array( $this, 'on_rule_apply'    ), 10, 2 );
        add_action( 'lcni_signal_viewed',    array( $this, 'on_signal_view'   ), 10, 2 );
    }

    // =========================================================================
    // EVENT HANDLERS — tất cả validate trước khi log
    // =========================================================================

    public function on_login( $user_login, $user ) {
        if ( ! $user || ! isset( $user->ID ) || ! $user->ID ) return;
        $this->log( (int) $user->ID, 'login', '' );
    }

    public function on_logout() {
        $user_id = (int) get_current_user_id();
        if ( $user_id > 0 ) $this->log( $user_id, 'logout', '' );
    }

    public function on_page_view() {
        if ( ! is_user_logged_in() || is_admin() ) return;

        global $post;
        if ( ! $post || empty( $post->post_content ) ) return;

        $lcni_shortcodes = array(
            'lcni_stock_filter', 'lcni_watchlist', 'lcni_performance_v2',
            'lcni_signals_rule', 'lcni_rule_follow', 'lcni_user_rule',
            'lcni_market_dashboard', 'lcni_heatmap', 'lcni_industry_dashboard',
        );

        $content = (string) $post->post_content;
        $found   = false;
        foreach ( $lcni_shortcodes as $sc ) {
            if ( has_shortcode( $content, $sc ) ) { $found = true; break; }
        }
        if ( ! $found ) return;

        $user_id = (int) get_current_user_id();
        if ( $user_id <= 0 ) return;

        $cache_k = 'lcni_pv_' . $user_id . '_' . $post->ID . '_' . gmdate('YmdH');
        if ( wp_cache_get( $cache_k ) ) return;
        wp_cache_set( $cache_k, 1, '', 3600 );

        $this->log( $user_id, 'page_view', 'pg_' . (int) $post->ID );
    }

    public function on_rule_follow( $user_id, $rule_id ) {
        $uid = (int) $user_id;
        $rid = (int) $rule_id;
        if ( $uid > 0 && $rid > 0 ) $this->log( $uid, 'rule_follow', (string) $rid );
    }

    public function on_rule_unfollow( $user_id, $rule_id ) {
        $uid = (int) $user_id;
        $rid = (int) $rule_id;
        if ( $uid > 0 && $rid > 0 ) $this->log( $uid, 'rule_unfollow', (string) $rid );
    }

    public function on_rule_apply( $user_id, $rule_id ) {
        $uid = (int) $user_id;
        $rid = (int) $rule_id;
        if ( $uid > 0 && $rid > 0 ) $this->log( $uid, 'rule_apply', (string) $rid );
    }

    public function on_signal_view( $user_id, $rule_id ) {
        $uid = (int) $user_id;
        $rid = (int) $rule_id;
        if ( $uid <= 0 || $rid <= 0 ) return;

        $cache_k = 'lcni_sv_' . $uid . '_' . $rid . '_' . gmdate('YmdH');
        if ( wp_cache_get( $cache_k ) ) return;
        wp_cache_set( $cache_k, 1, '', 3600 );
        $this->log( $uid, 'signal_view', (string) $rid );
    }

    // =========================================================================
    // CORE LOG — không dùng sanitize_key/sanitize_text_field với giá trị có thể null
    // =========================================================================

    private function log( $user_id, $event_type, $meta ) {
        $user_id    = (int) $user_id;
        $event_type = (string) $event_type;
        $meta       = (string) $meta;

        if ( $user_id <= 0 || $event_type === '' ) return;

        // Validate event_type manually thay vì sanitize_key (tránh null warning)
        $allowed = array( 'login','logout','page_view','signal_view',
                          'rule_follow','rule_unfollow','rule_apply' );
        if ( ! in_array( $event_type, $allowed, true ) ) return;

        global $wpdb;
        $now = current_datetime();

        $wpdb->insert(
            $wpdb->prefix . self::TABLE,
            array(
                'user_id'     => $user_id,
                'event_type'  => $event_type,
                'event_meta'  => substr( $meta, 0, 255 ),
                'session_date'=> $now->format( 'Y-m-d' ),
                'hour_of_day' => (int) $now->format( 'G' ),
                'day_of_week' => (int) $now->format( 'N' ),
            ),
            array( '%d', '%s', '%s', '%s', '%d', '%d' )
        );
    }

    // =========================================================================
    // STATIC HELPER
    // =========================================================================

    public static function fire_signal_view( $rule_id ) {
        $rule_id = (int) $rule_id;
        $user_id = (int) get_current_user_id();
        if ( $user_id > 0 && $rule_id > 0 ) {
            do_action( 'lcni_signal_viewed', $user_id, $rule_id );
        }
    }
}
endif; // class_exists
