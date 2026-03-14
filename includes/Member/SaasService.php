<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_SaaS_Service {

    private $repo;

    /**
     * Registry đầy đủ của tất cả module trong plugin.
     * Khi phát triển module mới, thêm vào đây.
     *
     * Format: 'module_key' => ['label' => '...', 'group' => '...', 'caps' => ['view','export','filter','realtime']]
     * caps: danh sách capability có nghĩa với module này (bỏ qua cap không liên quan trong UI)
     */
    const MODULES = [
        // ── Thị trường chung (Industry / Market) ────────────────
        'industry'         => ['label' => 'Ngành (Industry Dashboard)', 'group' => 'Thị trường',  'caps' => ['view', 'export']],
        'industry-monitor' => ['label' => 'Industry Monitor',           'group' => 'Thị trường',  'caps' => ['view']],

        // ── Chart ──────────────────────────────────────────────
        'chart'         => ['label' => 'Stock Chart',        'group' => 'Chart',     'caps' => ['view', 'realtime']],
        'chart-builder' => ['label' => 'Chart Builder',      'group' => 'Chart',     'caps' => ['view']],
        'overview'      => ['label' => 'Stock Overview',     'group' => 'Chart',     'caps' => ['view', 'export']],

        // ── Filter / Screener ───────────────────────────────────
        'filter'        => ['label' => 'Bộ lọc (Filter)',   'group' => 'Filter',    'caps' => ['view', 'filter', 'export']],
        'screener'      => ['label' => 'Screener API',       'group' => 'Filter',    'caps' => ['view', 'filter']],

        // ── Signals ─────────────────────────────────────────────
        'signals'       => ['label' => 'Stock Signals',      'group' => 'Signals',   'caps' => ['view', 'export', 'realtime']],

        // ── Watchlist ───────────────────────────────────────────
        'watchlist'     => ['label' => 'Watchlist',          'group' => 'Watchlist', 'caps' => ['view', 'export']],

        // ── Portfolio ───────────────────────────────────────────
        'portfolio'     => ['label' => 'Danh mục đầu tư',   'group' => 'Portfolio', 'caps' => ['view', 'export']],

        // ── Recommend ───────────────────────────────────────────
        // Mỗi rule recommend là 1 module riêng (key = 'recommend-{rule_slug}')
        // Các key dưới đây là module cố định; module theo rule được sinh động qua get_recommend_modules()
        'recommend-signals'     => ['label' => 'Recommend: Tín hiệu',       'group' => 'Recommend', 'caps' => ['view', 'export']],
        'recommend-performance' => ['label' => 'Recommend: Hiệu suất',      'group' => 'Recommend', 'caps' => ['view', 'export']],
        'recommend-equity'      => ['label' => 'Recommend: Equity Curve',   'group' => 'Recommend', 'caps' => ['view']],

        // ── Heatmap ─────────────────────────────────────────────
        'heatmap'          => ['label' => 'Heatmap thị trường',      'group' => 'Thị trường',  'caps' => ['view']],

        // ── Market Dashboard ────────────────────────────────────
        'market-dashboard' => ['label' => 'Market Dashboard',        'group' => 'Thị trường',  'caps' => ['view']],
        'market-chart'     => ['label' => 'Market Chart (Biểu đồ)', 'group' => 'Thị trường',  'caps' => ['view']],

        // ── DNSE Trading ────────────────────────────────────────
        'dnse-trading'    => ['label' => 'DNSE Trading (Đặt lệnh)',  'group' => 'Trading', 'caps' => ['view', 'trade']],

        // ── Member ──────────────────────────────────────────────
        'member-login'    => ['label' => 'Form Đăng nhập',  'group' => 'Member',    'caps' => ['view']],
        'member-register' => ['label' => 'Form Đăng ký',    'group' => 'Member',    'caps' => ['view']],
        'member-profile'  => ['label' => 'Hồ sơ thành viên','group' => 'Member',    'caps' => ['view']],
    ];

    public function __construct(LCNI_SaaS_Repository $repo) {
        $this->repo = $repo;
    }

    public function get_module_list() {
        $modules = self::MODULES;

        // Tự động phát hiện recommend rules từ DB và thêm vào danh sách
        $dynamic = $this->get_recommend_modules();
        foreach ($dynamic as $key => $meta) {
            if (!isset($modules[$key])) {
                $modules[$key] = $meta;
            }
        }

        return $modules;
    }

    /**
     * Sinh module key động từ các rule recommend trong DB.
     * Mỗi rule = 1 module 'recommend-rule-{slug}' độc lập để phân quyền riêng.
     */
    public function get_recommend_modules() {
        global $wpdb;
        $modules = [];

        // Đọc rule từ bảng lcni_recommend_rules nếu tồn tại
        $table = $wpdb->prefix . 'lcni_recommend_rules';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table) {
            $rules = $wpdb->get_results("SELECT rule_key, rule_name FROM {$table} WHERE is_active = 1 ORDER BY rule_name ASC", ARRAY_A);
            foreach ((array) $rules as $rule) {
                $key = 'recommend-rule-' . sanitize_key($rule['rule_key']);
                $modules[$key] = [
                    'label' => 'Recommend Rule: ' . $rule['rule_name'],
                    'group' => 'Recommend Rules',
                    'caps'  => ['view', 'export'],
                ];
            }
        }

        return $modules;
    }

    public function get_package_options() {
        return $this->repo->get_packages();
    }

    public function get_packages() {
        return $this->repo->get_packages();
    }

    public function get_package_by_id($id) {
        return $this->repo->get_package_by_id((int) $id);
    }

    public function create_package($name, $description = '', $color = '#2563eb', $badge_icon = '', $badge_label = '') {
        $name = sanitize_text_field((string) $name);
        $key  = sanitize_title($name);
        if ($name === '' || $key === '') {
            return 0;
        }
        return $this->repo->create_package($name, $key, $description, $color, sanitize_text_field($badge_icon), sanitize_text_field($badge_label));
    }

    public function update_package($id, $name, $description = '', $color = '#2563eb', $is_active = 1, $badge_icon = '', $badge_label = '') {
        $name = sanitize_text_field((string) $name);
        if ($name === '') {
            return;
        }
        $this->repo->update_package((int) $id, $name, $description, $color, $is_active, sanitize_text_field($badge_icon), sanitize_text_field($badge_label));
    }

    public function delete_package($id) {
        $this->repo->delete_package((int) $id);
    }

    public function save_permissions($package_id, $permissions) {
        $clean = [];
        foreach ((array) $permissions as $permission) {
            $module_key = sanitize_key($permission['module_key'] ?? '');
            if ($module_key === '') {
                continue;
            }
            $clean[] = [
                'module_key'   => $module_key,
                'table_name'   => sanitize_text_field($permission['table_name']  ?? '*'),
                'column_name'  => sanitize_text_field($permission['column_name'] ?? '*'),
                'can_view'     => !empty($permission['can_view'])     ? 1 : 0,
                'can_export'   => !empty($permission['can_export'])   ? 1 : 0,
                'can_filter'   => !empty($permission['can_filter'])   ? 1 : 0,
                'can_realtime' => !empty($permission['can_realtime']) ? 1 : 0,
            ];
        }
        $this->repo->update_permissions((int) $package_id, $clean);
    }

    public function get_permissions($package_id) {
        return $this->repo->get_permissions_by_package_id((int) $package_id);
    }

    public function assign_package($user_id, $role_slug, $package_id, $expires_at = null, $note = '') {
        $this->repo->assign_package((int) $user_id, sanitize_key($role_slug), (int) $package_id, $expires_at, $note);
    }

    public function revoke_package($user_id, $role_slug) {
        $this->repo->revoke_package((int) $user_id, sanitize_key($role_slug));
    }

    public function get_all_user_assignments() {
        return $this->repo->get_all_user_assignments();
    }

    public function get_current_user_package_info() {
        $user_id = get_current_user_id();
        if (!$user_id) {
            return null;
        }
        $user      = get_userdata($user_id);
        $role_slug = $user && !empty($user->roles[0]) ? sanitize_key($user->roles[0]) : '';
        $row       = $this->repo->get_user_package_row($user_id, $role_slug);

        if (!$row) {
            return null;
        }

        $expired     = !empty($row['expires_at']) && strtotime($row['expires_at']) < time();
        $permissions = $this->repo->get_permissions_by_package_id((int) $row['package_id']);

        return [
            'package_id'   => $row['package_id'],
            'package_name' => $row['package_name'] ?? '',
            'description'  => $row['description']  ?? '',
            'color'        => $row['color']         ?? '#2563eb',
            'badge_icon'   => $row['badge_icon']    ?? '',
            'badge_label'  => $row['badge_label']   ?? '',
            'expires_at'   => $row['expires_at']    ?? null,
            'note'         => $row['note']           ?? '',
            'is_expired'   => $expired,
            'permissions'  => $permissions,
        ];
    }

    public function can($module_key, $capability, $user_id = 0) {
        $user_id = $user_id ? (int) $user_id : get_current_user_id();

        // Các trang login/register luôn hiển thị cho khách chưa đăng nhập
        if (in_array($module_key, ['member-login', 'member-register'], true) && !$user_id) {
            return true;
        }

        // Chưa đăng nhập → từ chối tất cả module khác
        if (!$user_id) {
            return false;
        }

        // Admin (manage_options) luôn được phép
        if (user_can($user_id, 'manage_options')) {
            return true;
        }

        $user      = get_userdata($user_id);
        $role_slug = $user && !empty($user->roles[0]) ? sanitize_key($user->roles[0]) : '';

        $package_id = $this->repo->get_package_for_user($user_id, $role_slug);

        // Đã đăng nhập nhưng chưa được gán gói → từ chối
        if (!$package_id) {
            return false;
        }

        // Gói hết hạn → từ chối
        $pkg_info = $this->get_current_user_package_info();
        if ($pkg_info && !empty($pkg_info['is_expired'])) {
            return false;
        }

        $permissions = $this->repo->get_permissions_by_package_id($package_id);
        foreach ($permissions as $permission) {
            if ($permission['module_key'] !== $module_key) {
                continue;
            }
            if (!empty($permission['can_' . $capability])) {
                return true;
            }
        }

        return false;
    }
}
