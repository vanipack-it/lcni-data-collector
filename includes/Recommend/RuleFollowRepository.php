<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * RuleFollowRepository
 *
 * CRUD cho bảng lcni_recommend_rule_follow.
 * Mỗi user có thể follow nhiều rule để nhận thông báo email
 * khi có signal mới thuộc rule đó.
 */
class RuleFollowRepository {

    private $wpdb;
    private $table;         // lcni_recommend_rule_follow
    private $rule_table;    // lcni_recommend_rule

    public function __construct( wpdb $wpdb ) {
        $this->wpdb       = $wpdb;
        $this->table      = $wpdb->prefix . 'lcni_recommend_rule_follow';
        $this->rule_table = $wpdb->prefix . 'lcni_recommend_rule';
    }

    // =========================================================================
    // FOLLOW / UNFOLLOW
    // =========================================================================

    /**
     * Follow một rule.
     * Dùng INSERT IGNORE để idempotent (gọi nhiều lần không lỗi).
     */
    /**
     * @param bool  $notify_email
     * @param bool  $notify_browser     — Web push notification
     * @param int   $dynamic_watchlist_id — 0 = không tạo dynamic watchlist
     */
    public function follow(
        int  $user_id,
        int  $rule_id,
        bool $notify_email         = true,
        bool $notify_browser       = false,
        int  $dynamic_watchlist_id = 0
    ): bool {
        if ( $user_id <= 0 || $rule_id <= 0 ) return false;

        $data = [
            'notify_email'         => $notify_email ? 1 : 0,
            'notify_browser'       => $notify_browser ? 1 : 0,
            'dynamic_watchlist_id' => $dynamic_watchlist_id > 0 ? $dynamic_watchlist_id : null,
        ];

        $existing = $this->get_follow( $user_id, $rule_id );
        if ( $existing ) {
            $this->wpdb->update(
                $this->table,
                $data,
                [ 'user_id' => $user_id, 'rule_id' => $rule_id ],
                [ '%d', '%d', '%s' ], [ '%d', '%d' ]
            );
            return true;
        }

        $result = $this->wpdb->insert(
            $this->table,
            array_merge( [ 'user_id' => $user_id, 'rule_id' => $rule_id ], $data ),
            [ '%d', '%d', '%d', '%d', '%s' ]
        );
        return $result !== false;
    }

    /**
     * Unfollow một rule.
     */
    public function unfollow( int $user_id, int $rule_id ): bool {
        if ( $user_id <= 0 || $rule_id <= 0 ) return false;

        $deleted = $this->wpdb->delete(
            $this->table,
            [ 'user_id' => $user_id, 'rule_id' => $rule_id ],
            [ '%d', '%d' ]
        );
        return $deleted !== false;
    }

    /**
     * Toggle follow: nếu đang follow → unfollow, ngược lại → follow.
     * Trả về trạng thái mới: true = đang follow, false = không follow.
     */
    public function toggle( int $user_id, int $rule_id ): bool {
        if ( $this->is_following( $user_id, $rule_id ) ) {
            $this->unfollow( $user_id, $rule_id );
            return false;
        }
        $this->follow( $user_id, $rule_id );
        return true;
    }

    // =========================================================================
    // QUERY
    // =========================================================================

    /**
     * Lấy một follow record cụ thể.
     */
    public function get_follow( int $user_id, int $rule_id ): ?array {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE user_id = %d AND rule_id = %d",
                $user_id, $rule_id
            ),
            ARRAY_A
        );
        return $row ?: null;
    }

    /**
     * Kiểm tra user có đang follow rule không.
     */
    public function is_following( int $user_id, int $rule_id ): bool {
        return $this->get_follow( $user_id, $rule_id ) !== null;
    }

    /**
     * Lấy tất cả rule_ids user đang follow.
     *
     * @return int[]
     */
    public function get_followed_rule_ids( int $user_id ): array {
        $rows = $this->wpdb->get_col(
            $this->wpdb->prepare(
                "SELECT rule_id FROM {$this->table} WHERE user_id = %d",
                $user_id
            )
        );
        return array_map( 'intval', $rows ?: [] );
    }

    /**
     * Lấy danh sách rules kèm trạng thái follow của user.
     * Dùng cho UI shortcode.
     *
     * @return array[]  Mỗi item: rule fields + 'is_following' (bool) + 'notify_email' (bool)
     */
    public function get_rules_with_follow_status( int $user_id ): array {
        $sql = $this->wpdb->prepare(
            "SELECT r.*,
                    CASE WHEN f.id IS NOT NULL THEN 1 ELSE 0 END AS is_following,
                    COALESCE(f.notify_email, 1)    AS notify_email,
                    COALESCE(f.notify_browser, 0)  AS notify_browser,
                    f.dynamic_watchlist_id         AS dynamic_watchlist_id,
                    f.created_at                   AS followed_at
             FROM {$this->rule_table} r
             LEFT JOIN {$this->table} f
                    ON f.rule_id = r.id AND f.user_id = %d
             WHERE r.is_active = 1
             ORDER BY r.id ASC",
            $user_id
        );
        return $this->wpdb->get_results( $sql, ARRAY_A ) ?: [];
    }

    /**
     * Lấy danh sách user follow rule + muốn nhận browser push (notify_browser = 1).
     * @return array[]  [['user_id' => int], ...]
     */
    public function get_browser_subscribers_for_rule( int $rule_id ): array {
        $sql = $this->wpdb->prepare(
            "SELECT f.user_id
             FROM {$this->table} f
             WHERE f.rule_id = %d
               AND f.notify_browser = 1",
            $rule_id
        );
        return $this->wpdb->get_results( $sql, ARRAY_A ) ?: [];
    }

    /**
     * Lấy danh sách (user_id, dynamic_watchlist_id) của rule.
     * Dùng khi có signal mới → sync symbol vào watchlist.
     * @return array[]  [['user_id' => int, 'dynamic_watchlist_id' => int], ...]
     */
    public function get_dynamic_watchlist_subscribers_for_rule( int $rule_id ): array {
        $sql = $this->wpdb->prepare(
            "SELECT f.user_id, f.dynamic_watchlist_id
             FROM {$this->table} f
             WHERE f.rule_id = %d
               AND f.dynamic_watchlist_id IS NOT NULL
               AND f.dynamic_watchlist_id > 0",
            $rule_id
        );
        return $this->wpdb->get_results( $sql, ARRAY_A ) ?: [];
    }

    /**
     * Lấy danh sách user đang follow một rule VÀ muốn nhận email.
     * Dùng bởi Notifier khi có signal mới.
     *
     * @return array[]  [['user_id' => int, 'user_email' => string, 'display_name' => string], ...]
     */
    public function get_email_subscribers_for_rule( int $rule_id ): array {
        $sql = $this->wpdb->prepare(
            "SELECT f.user_id, u.user_email, u.display_name
             FROM {$this->table} f
             INNER JOIN {$this->wpdb->users} u ON u.ID = f.user_id
             WHERE f.rule_id = %d
               AND f.notify_email = 1
               AND u.user_email != ''",
            $rule_id
        );
        return $this->wpdb->get_results( $sql, ARRAY_A ) ?: [];
    }

    /**
     * Đếm số người đang follow một rule.
     */
    public function count_followers( int $rule_id ): int {
        return (int) $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table} WHERE rule_id = %d",
                $rule_id
            )
        );
    }
}
