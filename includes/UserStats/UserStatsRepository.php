<?php
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'LCNI_UserStatsRepository' ) ) :
class LCNI_UserStatsRepository {

    private $db;
    private $t_users;
    private $t_usermeta;
    private $t_packages;
    private $t_user_packages;
    private $t_follow;
    private $t_rules;
    private $t_user_rules;
    private $t_user_signals;
    private $t_user_perf;
    private $t_activity;

    public function __construct( wpdb $db ) {
        $this->db              = $db;
        $this->t_users         = $db->users;
        $this->t_usermeta      = $db->usermeta;
        $this->t_packages      = $db->prefix . 'lcni_saas_packages';
        $this->t_user_packages = $db->prefix . 'lcni_user_packages';
        $this->t_follow        = $db->prefix . 'lcni_recommend_rule_follow';
        $this->t_rules         = $db->prefix . 'lcni_recommend_rule';
        $this->t_user_rules    = $db->prefix . 'lcni_user_rules';
        $this->t_user_signals  = $db->prefix . 'lcni_user_signals';
        $this->t_user_perf     = $db->prefix . 'lcni_user_performance';
        $this->t_activity      = $db->prefix . 'lcni_user_activity';
    }

    // ===== OVERVIEW =====
    public function get_overview(): array {
        return [
            'total_users' => (int) $this->db->get_var("SELECT COUNT(*) FROM {$this->t_users}"),
            'upgraded'    => (int) $this->db->get_var("SELECT COUNT(DISTINCT user_id) FROM {$this->t_user_packages}"),
            'following'   => (int) $this->db->get_var("SELECT COUNT(DISTINCT user_id) FROM {$this->t_follow}"),
            'auto_users'  => (int) $this->db->get_var("SELECT COUNT(DISTINCT user_id) FROM {$this->t_user_rules} WHERE status='active'"),
            'new_7d'      => (int) $this->db->get_var("SELECT COUNT(*) FROM {$this->t_users} WHERE user_registered >= DATE_SUB(NOW(),INTERVAL 7 DAY)"),
            'new_30d'     => (int) $this->db->get_var("SELECT COUNT(*) FROM {$this->t_users} WHERE user_registered >= DATE_SUB(NOW(),INTERVAL 30 DAY)"),
            'total_pnl'   => (float) $this->db->get_var("SELECT COALESCE(SUM(total_pnl_vnd),0) FROM {$this->t_user_perf}"),
        ];
    }

    // ===== PACKAGES =====
    public function get_package_stats(): array {
        return $this->db->get_results(
            "SELECT p.id, p.package_name, p.color,
                    COUNT(up.user_id) AS user_count,
                    SUM(up.expires_at IS NOT NULL AND up.expires_at > NOW()
                        AND up.expires_at < DATE_ADD(NOW(),INTERVAL 7 DAY)) AS expiring_soon,
                    SUM(up.expires_at IS NOT NULL AND up.expires_at < NOW()) AS expired
             FROM {$this->t_packages} p
             LEFT JOIN {$this->t_user_packages} up ON up.package_id=p.id
             WHERE p.is_active=1
             GROUP BY p.id ORDER BY user_count DESC",
            ARRAY_A
        ) ?: [];
    }

    public function get_packages_for_filter(): array {
        return $this->db->get_results(
            "SELECT id, package_name, color FROM {$this->t_packages} WHERE is_active=1 ORDER BY package_name",
            ARRAY_A
        ) ?: [];
    }

    // ===== RULE STATS =====
    public function get_rule_stats(): array {
        return $this->db->get_results(
            "SELECT r.id, r.name AS rule_name, r.is_active,
                    COUNT(DISTINCT f.user_id)         AS follow_count,
                    SUM(COALESCE(f.notify_email,0))   AS email_notify_count,
                    SUM(COALESCE(f.notify_browser,0)) AS push_notify_count,
                    COUNT(DISTINCT ur.user_id)        AS auto_count,
                    SUM(COALESCE(ur.auto_order,0))    AS real_order_count,
                    COALESCE(SUM(p.total_pnl_vnd),0) AS total_pnl,
                    COALESCE(AVG(CASE WHEN p.total_trades>0 THEN p.total_pnl_vnd END),0) AS avg_pnl_per_user
             FROM {$this->t_rules} r
             LEFT JOIN {$this->t_follow}     f  ON f.rule_id=r.id
             LEFT JOIN {$this->t_user_rules} ur ON ur.rule_id=r.id AND ur.status='active'
             LEFT JOIN {$this->t_user_perf}  p  ON p.user_rule_id=ur.id
             GROUP BY r.id ORDER BY follow_count DESC",
            ARRAY_A
        ) ?: [];
    }

    // ===== USERS FOLLOWING A RULE =====
    public function get_rule_followers( int $rule_id ): array {
        return $this->db->get_results( $this->db->prepare(
            "SELECT u.ID AS user_id, u.display_name, u.user_email,
                    f.notify_email, f.notify_browser, f.created_at AS followed_at,
                    pkg.package_name, pkg.color AS package_color,
                    ur.id AS user_rule_id, ur.is_paper, ur.auto_order, ur.status AS ur_status,
                    COALESCE(p.total_pnl_vnd,0) AS pnl,
                    COALESCE(p.winrate,0) AS winrate,
                    COALESCE(p.total_trades,0) AS total_trades
             FROM {$this->t_follow} f
             JOIN {$this->t_users} u ON u.ID=f.user_id
             LEFT JOIN {$this->t_user_packages} up  ON up.user_id=u.ID
             LEFT JOIN {$this->t_packages}      pkg ON pkg.id=up.package_id
             LEFT JOIN {$this->t_user_rules}    ur  ON ur.user_id=u.ID AND ur.rule_id=f.rule_id
             LEFT JOIN {$this->t_user_perf}     p   ON p.user_rule_id=ur.id
             WHERE f.rule_id=%d ORDER BY f.created_at DESC",
            $rule_id
        ), ARRAY_A ) ?: [];
    }

    // ===== USER LIST =====
    public function get_user_list( int $page = 1, int $per_page = 30, array $filters = [] ): array {
        $offset      = ( $page - 1 ) * $per_page;
        $where_parts = [ '1=1' ];
        $params      = [];

        if ( ! empty($filters['package_id']) ) {
            $where_parts[] = 'up.package_id=%d';
            $params[]      = (int) $filters['package_id'];
        }
        if ( ! empty($filters['search']) ) {
            $like = '%' . $this->db->esc_like($filters['search']) . '%';
            $where_parts[] = '(u.user_login LIKE %s OR u.user_email LIKE %s OR u.display_name LIKE %s)';
            $params[] = $like; $params[] = $like; $params[] = $like;
        }
        if ( ! empty($filters['has_follow']) ) $where_parts[] = 'COALESCE(fc.follow_count,0)>0';
        if ( ! empty($filters['has_rule'])   ) $where_parts[] = 'COALESCE(ur_cnt.cnt,0)>0';

        $act_join = $this->activity_table_exists()
            ? "LEFT JOIN (SELECT user_id, MAX(created_at) AS last_active,
                          COUNT(DISTINCT session_date) AS active_days
                   FROM {$this->t_activity} GROUP BY user_id) act ON act.user_id=u.ID"
            : '';
        $act_cols = $this->activity_table_exists()
            ? 'act.last_active, COALESCE(act.active_days,0) AS active_days,'
            : 'NULL AS last_active, 0 AS active_days,';

        $where = implode(' AND ', $where_parts);
        $sql = "
            SELECT u.ID AS user_id, u.user_login, u.user_email, u.display_name, u.user_registered,
                   pkg.package_name, pkg.color AS package_color, up.expires_at, up.created_at AS upgraded_at,
                   {$act_cols}
                   COALESCE(fc.follow_count,0)   AS follow_count,
                   COALESCE(fc.follow_names,'')  AS follow_names,
                   COALESCE(ur_cnt.cnt,0)        AS rule_count,
                   COALESCE(ur_cnt.active_cnt,0) AS active_rule_count,
                   COALESCE(ur_cnt.rule_names,'')AS rule_names,
                   COALESCE(p.total_trades,0)    AS total_trades,
                   COALESCE(p.win_trades,0)      AS win_trades,
                   COALESCE(p.total_pnl_vnd,0)  AS total_pnl_vnd,
                   COALESCE(p.winrate,0)         AS winrate,
                   COALESCE(p.total_r,0)         AS total_r
            FROM {$this->t_users} u
            LEFT JOIN {$this->t_user_packages} up  ON up.user_id=u.ID
            LEFT JOIN {$this->t_packages}      pkg ON pkg.id=up.package_id
            LEFT JOIN (
                SELECT f.user_id, COUNT(*) AS follow_count,
                       GROUP_CONCAT(r.name ORDER BY f.created_at SEPARATOR ', ') AS follow_names
                FROM {$this->t_follow} f JOIN {$this->t_rules} r ON r.id=f.rule_id
                GROUP BY f.user_id
            ) fc ON fc.user_id=u.ID
            LEFT JOIN (
                SELECT ur.user_id, COUNT(*) AS cnt, SUM(ur.status='active') AS active_cnt,
                       GROUP_CONCAT(r.name ORDER BY ur.created_at SEPARATOR ', ') AS rule_names
                FROM {$this->t_user_rules} ur JOIN {$this->t_rules} r ON r.id=ur.rule_id
                GROUP BY ur.user_id
            ) ur_cnt ON ur_cnt.user_id=u.ID
            LEFT JOIN (
                SELECT ur.user_id, SUM(p.total_trades) AS total_trades,
                       SUM(p.win_trades) AS win_trades, SUM(p.total_pnl_vnd) AS total_pnl_vnd,
                       AVG(p.winrate) AS winrate, SUM(p.total_r) AS total_r
                FROM {$this->t_user_rules} ur JOIN {$this->t_user_perf} p ON p.user_rule_id=ur.id
                GROUP BY ur.user_id
            ) p ON p.user_id=u.ID
            {$act_join}
            WHERE {$where}
            ORDER BY u.user_registered DESC
            LIMIT %d OFFSET %d";

        $params[] = $per_page;
        $params[] = $offset;
        $rows = $this->db->get_results( $this->db->prepare($sql, ...$params), ARRAY_A ) ?: [];
        // Cast nullable string columns to avoid PHP 8.1 deprecation warnings
        foreach ( $rows as &$row ) {
            $row['follow_names']  = (string)($row['follow_names']  ?? '');
            $row['rule_names']    = (string)($row['rule_names']    ?? '');
            $row['package_name']  = (string)($row['package_name']  ?? '');
            $row['display_name']  = (string)($row['display_name']  ?? '');
            $row['last_active']   = $row['last_active'] ?? null;
        }
        unset($row);
        return $rows;
    }

    public function get_user_list_count( array $filters = [] ): int {
        $where_parts = ['1=1']; $params = [];
        if ( ! empty($filters['package_id']) ) { $where_parts[]='up.package_id=%d'; $params[]=(int)$filters['package_id']; }
        if ( ! empty($filters['search']) ) {
            $like='%'.$this->db->esc_like($filters['search']).'%';
            $where_parts[]='(u.user_login LIKE %s OR u.user_email LIKE %s OR u.display_name LIKE %s)';
            $params[]=$like; $params[]=$like; $params[]=$like;
        }
        $where = implode(' AND ',$where_parts);
        $sql = "SELECT COUNT(DISTINCT u.ID) FROM {$this->t_users} u
                LEFT JOIN {$this->t_user_packages} up ON up.user_id=u.ID WHERE {$where}";
        return (int)(empty($params) ? $this->db->get_var($sql) : $this->db->get_var($this->db->prepare($sql,...$params)));
    }

    // ===== USER DETAIL =====
    public function get_user_detail( int $user_id ): array {
        $follows = $this->db->get_results( $this->db->prepare(
            "SELECT f.rule_id, r.name AS rule_name, f.notify_email, f.notify_browser, f.created_at AS followed_at
             FROM {$this->t_follow} f JOIN {$this->t_rules} r ON r.id=f.rule_id
             WHERE f.user_id=%d ORDER BY f.created_at DESC", $user_id
        ), ARRAY_A ) ?: [];

        $user_rules = $this->db->get_results( $this->db->prepare(
            "SELECT ur.id, ur.rule_id, r.name AS rule_name, ur.is_paper, ur.capital,
                    ur.risk_per_trade, ur.max_symbols, ur.auto_order, ur.account_id,
                    ur.status, ur.created_at,
                    COALESCE(p.total_trades,0) AS total_trades, COALESCE(p.win_trades,0) AS win_trades,
                    COALESCE(p.total_pnl_vnd,0) AS total_pnl_vnd, COALESCE(p.winrate,0) AS winrate,
                    COALESCE(p.total_r,0) AS total_r, COALESCE(p.current_capital,0) AS current_capital,
                    COALESCE(p.max_drawdown_pct,0) AS max_drawdown_pct
             FROM {$this->t_user_rules} ur JOIN {$this->t_rules} r ON r.id=ur.rule_id
             LEFT JOIN {$this->t_user_perf} p ON p.user_rule_id=ur.id
             WHERE ur.user_id=%d ORDER BY ur.created_at DESC", $user_id
        ), ARRAY_A ) ?: [];

        $package = $this->db->get_row( $this->db->prepare(
            "SELECT p.package_name, p.color, up.expires_at, up.created_at AS upgraded_at
             FROM {$this->t_user_packages} up JOIN {$this->t_packages} p ON p.id=up.package_id
             WHERE up.user_id=%d LIMIT 1", $user_id
        ), ARRAY_A );

        $open_signals = (int) $this->db->get_var( $this->db->prepare(
            "SELECT COUNT(*) FROM {$this->t_user_signals} us
             JOIN {$this->t_user_rules} ur ON ur.id=us.user_rule_id
             WHERE ur.user_id=%d AND us.status='open'", $user_id
        ) );

        $activity = null;
        if ( $this->activity_table_exists() ) {
            $activity = $this->db->get_row( $this->db->prepare(
                "SELECT MAX(created_at) AS last_active,
                        COUNT(DISTINCT session_date) AS active_days,
                        COUNT(CASE WHEN event_type='login' THEN 1 END) AS login_count
                 FROM {$this->t_activity} WHERE user_id=%d", $user_id
            ), ARRAY_A );
        }

        return compact('follows','user_rules','package','open_signals','activity');
    }

    // ===== ACTIVITY =====
    public function get_activity_heatmap( int $user_id, int $days ): array {
        if ( ! $this->activity_table_exists() ) return [];
        return $this->db->get_results( $this->db->prepare(
            "SELECT hour_of_day, day_of_week, COUNT(*) AS cnt
             FROM {$this->t_activity}
             WHERE user_id=%d AND session_date >= DATE_SUB(NOW(),INTERVAL %d DAY)
               AND event_type IN ('login','page_view','signal_view')
             GROUP BY hour_of_day, day_of_week",
            $user_id, $days
        ), ARRAY_A ) ?: [];
    }

    public function get_activity_daily( int $user_id, int $days ): array {
        if ( ! $this->activity_table_exists() ) return [];
        return $this->db->get_results( $this->db->prepare(
            "SELECT session_date, COUNT(*) AS cnt
             FROM {$this->t_activity}
             WHERE user_id=%d AND session_date >= DATE_SUB(NOW(),INTERVAL %d DAY)
             GROUP BY session_date ORDER BY session_date ASC",
            $user_id, $days
        ), ARRAY_A ) ?: [];
    }

    public function get_activity_events( int $user_id, int $limit = 50 ): array {
        if ( ! $this->activity_table_exists() ) return [];
        return $this->db->get_results( $this->db->prepare(
            "SELECT event_type, event_meta, session_date, hour_of_day, created_at
             FROM {$this->t_activity} WHERE user_id=%d
             ORDER BY created_at DESC LIMIT %d",
            $user_id, $limit
        ), ARRAY_A ) ?: [];
    }

    public function activity_table_exists(): bool {
        return $this->db->get_var(
            $this->db->prepare('SHOW TABLES LIKE %s', $this->t_activity)
        ) === $this->t_activity;
    }

    public function get_registration_trend( int $days = 30 ): array {
        return $this->db->get_results( $this->db->prepare(
            "SELECT DATE(user_registered) AS reg_date, COUNT(*) AS cnt
             FROM {$this->t_users}
             WHERE user_registered >= DATE_SUB(NOW(),INTERVAL %d DAY)
             GROUP BY DATE(user_registered) ORDER BY reg_date ASC", $days
        ), ARRAY_A ) ?: [];
    }
}
endif;
