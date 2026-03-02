<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_SaaS_Repository {

    const TABLE_PACKAGES = 'lcni_saas_packages';
    const TABLE_PERMISSIONS = 'lcni_saas_permissions';
    const TABLE_USER_PACKAGES = 'lcni_user_packages';

    public static function maybe_create_tables() {
        if (get_transient('lcni_member_saas_schema_ready')) {
            return;
        }

        self::create_tables();
        set_transient('lcni_member_saas_schema_ready', 1, 10 * MINUTE_IN_SECONDS);
    }

    public static function create_tables() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $packages = $wpdb->prefix . self::TABLE_PACKAGES;
        $permissions = $wpdb->prefix . self::TABLE_PERMISSIONS;
        $user_packages = $wpdb->prefix . self::TABLE_USER_PACKAGES;

        dbDelta("CREATE TABLE {$packages} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            package_key VARCHAR(80) NOT NULL,
            package_name VARCHAR(120) NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_package_key (package_key)
        ) {$charset};");

        dbDelta("CREATE TABLE {$permissions} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            package_id BIGINT UNSIGNED NOT NULL,
            module_key VARCHAR(80) NOT NULL,
            table_name VARCHAR(120) NOT NULL DEFAULT '*',
            column_name VARCHAR(120) NOT NULL DEFAULT '*',
            can_view TINYINT(1) NOT NULL DEFAULT 0,
            can_export TINYINT(1) NOT NULL DEFAULT 0,
            can_filter TINYINT(1) NOT NULL DEFAULT 0,
            can_realtime TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_package_module (package_id, module_key)
        ) {$charset};");

        dbDelta("CREATE TABLE {$user_packages} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            role_slug VARCHAR(80) NOT NULL DEFAULT '',
            package_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_user_role (user_id, role_slug),
            KEY idx_package (package_id)
        ) {$charset};");
    }

    public function get_packages() {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_PACKAGES;
        return $wpdb->get_results("SELECT * FROM {$table} ORDER BY package_name ASC", ARRAY_A);
    }

    public function create_package($name, $key) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_PACKAGES;
        $wpdb->insert($table, [
            'package_name' => $name,
            'package_key' => $key,
            'is_active' => 1,
        ], ['%s', '%s', '%d']);

        return (int) $wpdb->insert_id;
    }

    public function update_permissions($package_id, $permissions) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_PERMISSIONS;
        $wpdb->delete($table, ['package_id' => $package_id], ['%d']);

        foreach ($permissions as $permission) {
            $wpdb->insert($table, [
                'package_id' => $package_id,
                'module_key' => $permission['module_key'],
                'table_name' => $permission['table_name'],
                'column_name' => $permission['column_name'],
                'can_view' => !empty($permission['can_view']) ? 1 : 0,
                'can_export' => !empty($permission['can_export']) ? 1 : 0,
                'can_filter' => !empty($permission['can_filter']) ? 1 : 0,
                'can_realtime' => !empty($permission['can_realtime']) ? 1 : 0,
            ], ['%d', '%s', '%s', '%s', '%d', '%d', '%d', '%d']);
        }
    }

    public function assign_package($user_id, $role_slug, $package_id) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_USER_PACKAGES;
        $wpdb->replace($table, [
            'user_id' => (int) $user_id,
            'role_slug' => (string) $role_slug,
            'package_id' => (int) $package_id,
        ], ['%d', '%s', '%d']);
    }

    public function get_package_for_user($user_id, $role_slug) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_USER_PACKAGES;
        $package_id = $wpdb->get_var($wpdb->prepare(
            "SELECT package_id FROM {$table} WHERE user_id = %d AND role_slug = %s LIMIT 1",
            (int) $user_id,
            (string) $role_slug
        ));

        if ($package_id) {
            return (int) $package_id;
        }

        if ($role_slug !== '') {
            $package_id = $wpdb->get_var($wpdb->prepare(
                "SELECT package_id FROM {$table} WHERE user_id = 0 AND role_slug = %s LIMIT 1",
                (string) $role_slug
            ));
        }

        return $package_id ? (int) $package_id : 0;
    }

    public function get_permissions_by_package_id($package_id) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_PERMISSIONS;
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE package_id = %d", (int) $package_id), ARRAY_A);
    }
}
