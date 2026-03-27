<?php
/**
 * LCNI_InboxDB
 * DB schema + CRUD cho in-app notification inbox.
 *
 * Tables:
 *  lcni_inbox        — mỗi notification gửi đến user
 *  lcni_inbox_prefs  — user preference: loại nào muốn nhận
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class LCNI_InboxDB {

    const VER_OPT       = 'lcni_inbox_db_ver';
    const SCHEMA_VER    = 3;

    // Notification types — admin bật/tắt từng loại
    // Types dành cho user thường
    const TYPES = [
        'new_signal'       => '📈 Tín hiệu mới (UserRule)',
        'recommend_signal' => '🎯 Tín hiệu Recommend',
        'auto_rule_signal' => '🤖 Auto Rule kích hoạt',
        'follow_rule'      => '🔔 Theo dõi chiến lược',
        'upgrade_prompt'   => '⭐ Gợi ý nâng cấp gói',
        'marketing'        => '🎁 Chương trình ưu đãi',
        'price_alert'      => '💰 Cảnh báo giá',
        'system'           => '⚙️ Hệ thống',
        'news'             => '📰 Tin tức / Công bố',
        'market_summary'   => '📊 Tóm tắt thị trường',
        'portfolio_alert'  => '💼 Cảnh báo danh mục',
        'admin_broadcast'  => '📢 Thông báo từ admin',
    ];

    // Types chỉ gửi cho admin (không hiện trong prefs user thường)
    const ADMIN_TYPES = [
        'admin_new_user'     => '👤 User mới đăng ký',
        'admin_user_upgrade' => '🚀 User nâng cấp gói',
        'admin_user_expiring'=> '⏳ User sắp hết hạn',
        'admin_rule_follow'  => '🔔 User theo dõi chiến lược',
        'admin_auto_rule'    => '🤖 User áp dụng Auto Rule',
    ];

    public static function table_inbox() {
        global $wpdb;
        return $wpdb->prefix . 'lcni_inbox';
    }

    public static function table_prefs() {
        global $wpdb;
        return $wpdb->prefix . 'lcni_inbox_prefs';
    }

    // ── Schema ────────────────────────────────────────────────────────────────

    public static function ensure_tables() {
        if ( (int) get_option( self::VER_OPT, 0 ) >= self::SCHEMA_VER ) return;

        global $wpdb;
        $col = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $ti = self::table_inbox();
        dbDelta( "CREATE TABLE {$ti} (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id     BIGINT UNSIGNED NOT NULL DEFAULT 0,
            type        VARCHAR(40)  NOT NULL DEFAULT 'system',
            title       VARCHAR(255) NOT NULL DEFAULT '',
            body        TEXT         NOT NULL,
            url         VARCHAR(512) NOT NULL DEFAULT '',
            meta        TEXT         NOT NULL DEFAULT '',
            is_read     TINYINT(1)   NOT NULL DEFAULT 0,
            created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_user_read (user_id, is_read),
            KEY idx_user_created (user_id, created_at)
        ) {$col};" );

        $tp = self::table_prefs();
        dbDelta( "CREATE TABLE {$tp} (
            user_id     BIGINT UNSIGNED NOT NULL,
            type        VARCHAR(40)  NOT NULL,
            enabled     TINYINT(1)   NOT NULL DEFAULT 1,
            PRIMARY KEY (user_id, type)
        ) {$col};" );

        update_option( self::VER_OPT, self::SCHEMA_VER );
    }

    // ── Write ─────────────────────────────────────────────────────────────────

    /**
     * Ghi 1 notification vào inbox.
     * Nếu $user_id = 0 → broadcast tất cả users có pref = enabled.
     * @param array $args { user_id, type, title, body, url, meta }
     * @return int|false insert_id hoặc false
     */
    public static function insert( array $args ) {
        global $wpdb;

        $user_id = absint( $args['user_id'] ?? 0 );
        $type    = sanitize_key( $args['type'] ?? 'system' );

        // Admin types — chỉ gửi đến admins, không qua prefs
        if ( isset( self::ADMIN_TYPES[ $type ] ) ) {
            return self::_insert_to_admins( $args, $type );
        }

        // Kiểm tra admin có bật type này không
        $admin_types = self::get_admin_enabled_types();
        if ( ! in_array( $type, $admin_types, true ) ) return false;

        // Nếu broadcast → ghi cho tất cả users đã đăng ký (tối đa 1000)
        if ( $user_id === 0 ) {
            $cfg   = self::get_admin_config();
            $max   = (int) ( $cfg['max_per_user'] ?? 200 );
            $users = get_users( [ 'fields' => 'ID', 'number' => 1000, 'role__not_in' => [] ] );
            foreach ( $users as $uid ) {
                $args['user_id'] = (int) $uid;
                // Kiểm tra user pref trước khi ghi
                if ( self::user_wants( (int) $uid, $type ) ) {
                    self::_do_insert( $args );
                }
            }
            return true;
        }

        // Kiểm tra user pref
        if ( ! self::user_wants( $user_id, $type ) ) return false;

        return self::_do_insert( $args );
    }

    private static function _do_insert( array $args ) {
        global $wpdb;
        $wpdb->insert( self::table_inbox(), [
            'user_id'    => absint( $args['user_id'] ?? 0 ),
            'type'       => sanitize_key( $args['type'] ?? 'system' ),
            'title'      => sanitize_text_field( $args['title'] ?? '' ),
            'body'       => wp_kses_post( $args['body'] ?? '' ),
            'url'        => esc_url_raw( $args['url'] ?? '' ),
            'meta'       => wp_json_encode( is_array( $args['meta'] ?? null ) ? $args['meta'] : [] ),
            'is_read'    => 0,
            'created_at' => current_time( 'mysql' ),
        ], [ '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s' ] );
        return $wpdb->insert_id ?: false;
    }

    // ── Read ──────────────────────────────────────────────────────────────────

    /**
     * @return array<int,array>
     */
    public static function get_list( int $user_id, int $limit = 20, int $offset = 0, $filter = 'all', string $type = '' ) {
        global $wpdb;
        $t   = self::table_inbox();
        $where = 'WHERE user_id = %d';
        $vals  = [ $user_id ];
        if ( $filter === 'unread' ) { $where .= ' AND is_read = 0'; }
        if ( $filter === 'read'   ) { $where .= ' AND is_read = 1'; }
        // Lọc theo type — chỉ cho phép user types (loại trừ admin types)
        if ( $type && isset( self::TYPES[ $type ] ) ) {
            $where  .= ' AND type = %s';
            $vals[]  = $type;
        } elseif ( ! $type ) {
            // Mặc định: không hiện admin types cho user thường
            $admin_keys = array_keys( self::ADMIN_TYPES );
            $ph         = implode( ',', array_fill( 0, count( $admin_keys ), '%s' ) );
            $where     .= " AND type NOT IN ({$ph})";
            $vals       = array_merge( $vals, $admin_keys );
        }
        $vals[] = $limit;
        $vals[] = $offset;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        $sql = "SELECT * FROM {$t} {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d";
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $vals ), ARRAY_A );
        return is_array( $rows ) ? $rows : [];
    }

    public static function get_unread_count( int $user_id ) {
        global $wpdb;
        $t = self::table_inbox();
        return (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE user_id = %d AND is_read = 0", $user_id )
        );
    }

    public static function get_single( int $id, int $user_id ) {
        global $wpdb;
        $t = self::table_inbox();
        return $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d AND user_id = %d", $id, $user_id ),
            ARRAY_A
        );
    }

    // ── Mark read ─────────────────────────────────────────────────────────────

    public static function mark_read( int $user_id, $ids = 'all' ) {
        global $wpdb;
        $t = self::table_inbox();
        if ( $ids === 'all' ) {
            $wpdb->update( $t, [ 'is_read' => 1 ], [ 'user_id' => $user_id, 'is_read' => 0 ], [ '%d' ], [ '%d', '%d' ] );
        } elseif ( is_array( $ids ) && ! empty( $ids ) ) {
            $ids_int  = array_map( 'absint', $ids );
            $ph       = implode( ',', array_fill( 0, count( $ids_int ), '%d' ) );
            $args     = array_merge( $ids_int, [ $user_id ] );
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET is_read=1 WHERE id IN ({$ph}) AND user_id=%d", $args ) );
        }
    }

    public static function delete_old( int $days = 90 ) {
        global $wpdb;
        $t = self::table_inbox();
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$t} WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days
        ) );
    }

    // ── Admin config ──────────────────────────────────────────────────────────

    const ADMIN_OPT = 'lcni_inbox_admin_cfg';

    public static function get_admin_config() {
        $saved = get_option( self::ADMIN_OPT, [] );
        if ( ! is_array( $saved ) ) $saved = [];
        $defaults = [
            'enabled_types'  => array_keys( self::TYPES ),
            'retention_days' => 90,
            'max_per_user'   => 200,
            'inbox_page_url' => home_url('/'),
        ];
        return array_merge( $defaults, $saved );
    }

    public static function save_admin_config( array $cfg ) {
        update_option( self::ADMIN_OPT, $cfg );
    }

    public static function get_admin_enabled_types() {
        return (array) ( self::get_admin_config()['enabled_types'] ?? array_keys( self::TYPES ) );
    }

    // ── User prefs ────────────────────────────────────────────────────────────

    /** true nếu user muốn nhận type này */
    public static function user_wants( int $user_id, string $type ) {
        global $wpdb;
        $t   = self::table_prefs();
        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT enabled FROM {$t} WHERE user_id=%d AND type=%s", $user_id, $type ),
            ARRAY_A
        );
        // Nếu chưa có pref → mặc định bật
        return $row ? (bool) $row['enabled'] : true;
    }

    /** @return array<string,bool> type => enabled */
    public static function get_user_prefs( int $user_id ) {
        global $wpdb;
        $t    = self::table_prefs();
        $rows = $wpdb->get_results(
            $wpdb->prepare( "SELECT type, enabled FROM {$t} WHERE user_id=%d", $user_id ),
            ARRAY_A
        );
        $prefs = [];
        if ( is_array( $rows ) ) {
            foreach ( $rows as $r ) $prefs[ $r['type'] ] = (bool) $r['enabled'];
        }
        // Fill defaults
        foreach ( self::get_admin_enabled_types() as $type ) {
            if ( ! array_key_exists( $type, $prefs ) ) $prefs[ $type ] = true;
        }
        return $prefs;
    }

    public static function save_user_prefs( int $user_id, array $prefs ) {
        global $wpdb;
        $t       = self::table_prefs();
        $allowed = self::get_admin_enabled_types();
        foreach ( $allowed as $type ) {
            $wpdb->replace( $t, [
                'user_id' => $user_id,
                'type'    => $type,
                'enabled' => empty( $prefs[ $type ] ) ? 0 : 1,
            ], [ '%d', '%s', '%d' ] );
        }
    }

    // ── Admin types ───────────────────────────────────────────────────────────

    /**
     * Gửi notification admin-type đến tất cả users có cap manage_options.
     * Không cần qua prefs (admin luôn nhận).
     */
    private static function _insert_to_admins( array $args, string $type ) {
        $admins = get_users( [
            'role__in' => [ 'administrator' ],
            'fields'   => 'ID',
            'number'   => 50,
        ] );
        $inserted = 0;
        foreach ( $admins as $uid ) {
            $args['user_id'] = (int) $uid;
            $args['type']    = $type;
            if ( self::_do_insert( $args ) ) $inserted++;
        }
        return $inserted > 0 ? $inserted : false;
    }

    /**
     * Trả về label cho bất kỳ type nào (cả user lẫn admin).
     */
    public static function get_type_label( string $type ): string {
        return self::TYPES[ $type ] ?? self::ADMIN_TYPES[ $type ] ?? $type;
    }
}
