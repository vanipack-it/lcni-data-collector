<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_SaaS_Repository {

    const TABLE_PACKAGES      = 'lcni_saas_packages';
    const TABLE_PERMISSIONS   = 'lcni_saas_permissions';
    const TABLE_USER_PACKAGES = 'lcni_user_packages';

    // =========================================================
    // Schema
    // =========================================================

    public static function migrate_schema(): void {
        global $wpdb;
        $tbl = $wpdb->prefix . self::TABLE_PERMISSIONS;
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$tbl}'" ) !== $tbl ) return;
        $cols = $wpdb->get_col( "SHOW COLUMNS FROM {$tbl}", 0 );
        if ( ! in_array( 'can_trade', (array) $cols ) ) {
            $wpdb->query( "ALTER TABLE {$tbl} ADD COLUMN can_trade TINYINT(1) NOT NULL DEFAULT 0 AFTER can_realtime" );
        }
    }

    public static function maybe_create_tables() {
        // Always run column migrations (fast SHOW COLUMNS check) - never skip these
        self::maybe_migrate_columns();

        // Only run expensive dbDelta once per 10 minutes (guarded by transient)
        // Bump to v6 to force re-run on existing installs with stale v5 transient
        if (get_transient('lcni_member_saas_schema_v6')) {
            return;
        }
        delete_transient('lcni_member_saas_schema_v5'); // clear old transient
        delete_transient('lcni_member_saas_schema_v4');
        self::create_tables();
        set_transient('lcni_member_saas_schema_v6', 1, 10 * MINUTE_IN_SECONDS);
    }

    /**
     * Chạy ALTER TABLE migrations riêng — LUÔN LUÔN được gọi, không phụ thuộc transient.
     * Đảm bảo các cột mới luôn tồn tại dù transient cũ đã cache skip.
     */
    /**
     * Public alias để gọi từ bên ngoài (VD: MemberSettingsPage render).
     * Chỉ chạy ALTER TABLE nếu thiếu cột — không dbDelta, không output.
     */
    public static function run_column_migrations() {
        self::maybe_migrate_columns();
    }

    private static function maybe_migrate_columns() {
        global $wpdb;
        $packages      = $wpdb->prefix . self::TABLE_PACKAGES;
        $user_packages = $wpdb->prefix . self::TABLE_USER_PACKAGES;

        // Bảng chưa tồn tại thì bỏ qua (sẽ được tạo bởi create_tables)
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$packages}'" ) !== $packages ) {
            return;
        }

        $existing_pkg_cols  = self::get_columns( $packages );
        $existing_user_cols = self::get_columns( $user_packages );

        if ( ! in_array( 'description', $existing_pkg_cols, true ) ) {
            $wpdb->query( "ALTER TABLE {$packages} ADD COLUMN description TEXT NOT NULL DEFAULT ''" );
        }
        if ( ! in_array( 'color', $existing_pkg_cols, true ) ) {
            $wpdb->query( "ALTER TABLE {$packages} ADD COLUMN color VARCHAR(20) NOT NULL DEFAULT '#2563eb'" );
        }
        if ( ! in_array( 'package_key', $existing_pkg_cols, true ) ) {
            $wpdb->query( "ALTER TABLE {$packages} ADD COLUMN package_key VARCHAR(80) NOT NULL DEFAULT ''" );
        }
        if ( ! in_array( 'is_active', $existing_pkg_cols, true ) ) {
            $wpdb->query( "ALTER TABLE {$packages} ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1" );
        }
        if ( ! in_array( 'badge_icon', $existing_pkg_cols, true ) ) {
            $wpdb->query( "ALTER TABLE {$packages} ADD COLUMN badge_icon VARCHAR(120) NOT NULL DEFAULT ''" );
        }
        if ( ! in_array( 'badge_label', $existing_pkg_cols, true ) ) {
            $wpdb->query( "ALTER TABLE {$packages} ADD COLUMN badge_label VARCHAR(40) NOT NULL DEFAULT ''" );
        }
        if ( ! in_array( 'expires_at', $existing_user_cols, true ) ) {
            $wpdb->query( "ALTER TABLE {$user_packages} ADD COLUMN expires_at DATETIME NULL DEFAULT NULL" );
        }
        if ( ! in_array( 'note', $existing_user_cols, true ) ) {
            $wpdb->query( "ALTER TABLE {$user_packages} ADD COLUMN note VARCHAR(255) NOT NULL DEFAULT ''" );
        }
    }

    public static function create_tables() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset       = $wpdb->get_charset_collate();
        $packages      = $wpdb->prefix . self::TABLE_PACKAGES;
        $permissions   = $wpdb->prefix . self::TABLE_PERMISSIONS;
        $user_packages = $wpdb->prefix . self::TABLE_USER_PACKAGES;

        // ob_start để chặn bất kỳ output nào từ dbDelta (tránh "unexpected output" khi activation)
        ob_start();

        dbDelta( "CREATE TABLE {$packages} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            package_key VARCHAR(80) NOT NULL,
            package_name VARCHAR(120) NOT NULL,
            description TEXT NOT NULL DEFAULT '',
            color VARCHAR(20) NOT NULL DEFAULT '#2563eb',
            badge_icon VARCHAR(120) NOT NULL DEFAULT '',
            badge_label VARCHAR(40) NOT NULL DEFAULT '',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_package_key (package_key)
        ) {$charset};" );

        dbDelta( "CREATE TABLE {$permissions} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            package_id BIGINT UNSIGNED NOT NULL,
            module_key VARCHAR(80) NOT NULL,
            table_name VARCHAR(120) NOT NULL DEFAULT '*',
            column_name VARCHAR(120) NOT NULL DEFAULT '*',
            can_view TINYINT(1) NOT NULL DEFAULT 0,
            can_export TINYINT(1) NOT NULL DEFAULT 0,
            can_filter TINYINT(1) NOT NULL DEFAULT 0,
            can_realtime TINYINT(1) NOT NULL DEFAULT 0,
            can_trade TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_package_module (package_id, module_key)
        ) {$charset};" );

        dbDelta( "CREATE TABLE {$user_packages} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            role_slug VARCHAR(80) NOT NULL DEFAULT '',
            package_id BIGINT UNSIGNED NOT NULL,
            expires_at DATETIME NULL DEFAULT NULL,
            note VARCHAR(255) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_user_role (user_id, role_slug),
            KEY idx_package (package_id)
        ) {$charset};" );

        ob_end_clean();

        // Column migrations are handled in maybe_migrate_columns() which always runs
    }

    private static function get_columns($table) {
        global $wpdb;
        $cols = $wpdb->get_col("SHOW COLUMNS FROM {$table}");
        return is_array($cols) ? $cols : [];
    }

    // =========================================================
    // Packages
    // =========================================================

    public function get_packages() {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_PACKAGES;
        return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY package_name ASC", ARRAY_A ) ?: [];
    }

    public function get_package_by_id( $id ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_PACKAGES;
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), ARRAY_A );
    }

    public function create_package( $name, $key, $description = '', $color = '#2563eb', $badge_icon = '', $badge_label = '' ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_PACKAGES;
        $wpdb->insert( $table, [
            'package_name' => $name,
            'package_key'  => $key,
            'description'  => $description,
            'color'        => $color,
            'badge_icon'   => $badge_icon,
            'badge_label'  => $badge_label,
            'is_active'    => 1,
        ], [ '%s', '%s', '%s', '%s', '%s', '%s', '%d' ] );
        return (int) $wpdb->insert_id;
    }

    public function update_package( $id, $name, $description = '', $color = '#2563eb', $is_active = 1, $badge_icon = '', $badge_label = '' ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_PACKAGES;
        $wpdb->update( $table, [
            'package_name' => $name,
            'description'  => $description,
            'color'        => $color,
            'badge_icon'   => $badge_icon,
            'badge_label'  => $badge_label,
            'is_active'    => (int) $is_active,
        ], [ 'id' => (int) $id ], [ '%s', '%s', '%s', '%s', '%s', '%d' ], [ '%d' ] );
    }

    public function delete_package( $id ) {
        global $wpdb;
        $id = (int) $id;
        $wpdb->delete( $wpdb->prefix . self::TABLE_PERMISSIONS,   [ 'package_id' => $id ], [ '%d' ] );
        $wpdb->delete( $wpdb->prefix . self::TABLE_USER_PACKAGES, [ 'package_id' => $id ], [ '%d' ] );
        $wpdb->delete( $wpdb->prefix . self::TABLE_PACKAGES,      [ 'id'         => $id ], [ '%d' ] );
    }

    // =========================================================
    // Permissions
    // =========================================================

    public function update_permissions( $package_id, $permissions ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_PERMISSIONS;
        $wpdb->delete( $table, [ 'package_id' => $package_id ], [ '%d' ] );

        foreach ( $permissions as $permission ) {
            if ( empty( trim( (string) ( $permission['module_key'] ?? '' ) ) ) ) {
                continue;
            }
            $wpdb->insert( $table, [
                'package_id'   => $package_id,
                'module_key'   => $permission['module_key'],
                'table_name'   => $permission['table_name']  ?? '*',
                'column_name'  => $permission['column_name'] ?? '*',
                'can_view'     => ! empty( $permission['can_view'] )     ? 1 : 0,
                'can_export'   => ! empty( $permission['can_export'] )   ? 1 : 0,
                'can_filter'   => ! empty( $permission['can_filter'] )   ? 1 : 0,
                'can_realtime' => ! empty( $permission['can_realtime'] ) ? 1 : 0,
                'can_trade'    => ! empty( $permission['can_trade'] )    ? 1 : 0,
            ], [ '%d', '%s', '%s', '%s', '%d', '%d', '%d', '%d' ] );
        }
    }

    public function get_permissions_by_package_id( $package_id ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_PERMISSIONS;
        return $wpdb->get_results(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE package_id = %d", (int) $package_id ),
            ARRAY_A
        ) ?: [];
    }

    // =========================================================
    // User ↔ Package
    // =========================================================

    public function assign_package( $user_id, $role_slug, $package_id, $expires_at = null, $note = '' ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_USER_PACKAGES;

        // Guard: bảng chưa tồn tại thì bỏ qua thay vì crash
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
            return;
        }

        $expires_value = null;
        if ( $expires_at !== null && $expires_at !== '' ) {
            $ts = strtotime( $expires_at );
            if ( $ts !== false ) {
                $expires_value = date( 'Y-m-d H:i:s', $ts );
            }
        }

        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE user_id = %d AND role_slug = %s LIMIT 1",
            (int) $user_id,
            (string) $role_slug
        ) );

        if ( $existing ) {
            if ( $expires_value === null ) {
                $wpdb->query( $wpdb->prepare(
                    "UPDATE {$table} SET package_id = %d, expires_at = NULL, note = %s WHERE id = %d",
                    (int) $package_id,
                    sanitize_text_field( $note ),
                    (int) $existing
                ) );
            } else {
                $wpdb->query( $wpdb->prepare(
                    "UPDATE {$table} SET package_id = %d, expires_at = %s, note = %s WHERE id = %d",
                    (int) $package_id,
                    $expires_value,
                    sanitize_text_field( $note ),
                    (int) $existing
                ) );
            }
        } else {
            if ( $expires_value === null ) {
                $wpdb->query( $wpdb->prepare(
                    "INSERT INTO {$table} (user_id, role_slug, package_id, expires_at, note) VALUES (%d, %s, %d, NULL, %s)",
                    (int) $user_id,
                    (string) $role_slug,
                    (int) $package_id,
                    sanitize_text_field( $note )
                ) );
            } else {
                $wpdb->query( $wpdb->prepare(
                    "INSERT INTO {$table} (user_id, role_slug, package_id, expires_at, note) VALUES (%d, %s, %d, %s, %s)",
                    (int) $user_id,
                    (string) $role_slug,
                    (int) $package_id,
                    $expires_value,
                    sanitize_text_field( $note )
                ) );
            }
        }
    }

    public function revoke_package( $user_id, $role_slug ) {
        global $wpdb;
        $wpdb->delete(
            $wpdb->prefix . self::TABLE_USER_PACKAGES,
            [ 'user_id' => (int) $user_id, 'role_slug' => (string) $role_slug ],
            [ '%d', '%s' ]
        );
    }

    public function get_package_for_user( $user_id, $role_slug ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_USER_PACKAGES;
        $now   = current_time( 'mysql' );

        // Ưu tiên 1: gán trực tiếp user_id + role_slug = '' (admin gán thủ công)
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT package_id, expires_at FROM {$table}
             WHERE user_id = %d AND role_slug = '' LIMIT 1",
            (int) $user_id
        ), ARRAY_A );

        // Ưu tiên 2: gán trực tiếp user_id + role_slug cụ thể
        if ( ! $row && $role_slug !== '' ) {
            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT package_id, expires_at FROM {$table}
                 WHERE user_id = %d AND role_slug = %s LIMIT 1",
                (int) $user_id, (string) $role_slug
            ), ARRAY_A );
        }

        // Ưu tiên 3: gán theo role (user_id = 0)
        if ( ! $row && $role_slug !== '' ) {
            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT package_id, expires_at FROM {$table}
                 WHERE user_id = 0 AND role_slug = %s LIMIT 1",
                (string) $role_slug
            ), ARRAY_A );
        }

        if ( ! $row ) {
            return 0;
        }

        // Kiểm tra hết hạn
        if ( ! empty( $row['expires_at'] ) && $row['expires_at'] < $now ) {
            return 0;
        }

        return (int) $row['package_id'];
    }

    public function get_user_package_row( $user_id, $role_slug ) {
        global $wpdb;
        $up = $wpdb->prefix . self::TABLE_USER_PACKAGES;
        $pk = $wpdb->prefix . self::TABLE_PACKAGES;

        // Ưu tiên 1: gán trực tiếp vào user (role_slug = '') — do admin gán
        // Dùng SELECT * để tương thích DB cũ chưa có cột badge_icon/badge_label
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT up.*, p.package_name, p.package_key, p.description, p.color
             FROM {$up} up
             LEFT JOIN {$pk} p ON p.id = up.package_id
             WHERE up.user_id = %d AND up.role_slug = '' LIMIT 1",
            (int) $user_id
        ), ARRAY_A );
        if ( $row ) {
            // Bổ sung badge fields nếu DB cũ chưa có (migration chạy async)
            $row = $this->fill_badge_fields( $row, (int)( $row['package_id'] ?? 0 ) );
        }

        if ( $row ) {
            return $row;
        }

        // Ưu tiên 2: gán theo role_slug cụ thể của user
        if ( $role_slug !== '' ) {
            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT up.*, p.package_name, p.package_key, p.description, p.color
                 FROM {$up} up
                 LEFT JOIN {$pk} p ON p.id = up.package_id
                 WHERE up.user_id = %d AND up.role_slug = %s LIMIT 1",
                (int) $user_id, (string) $role_slug
            ), ARRAY_A );

            if ( $row ) {
                $row = $this->fill_badge_fields( $row, (int)( $row['package_id'] ?? 0 ) );
                return $row;
            }
        }

        // Ưu tiên 3: fallback gán theo role (user_id = 0)
        if ( $role_slug !== '' ) {
            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT up.*, p.package_name, p.package_key, p.description, p.color
                 FROM {$up} up
                 LEFT JOIN {$pk} p ON p.id = up.package_id
                 WHERE up.user_id = 0 AND up.role_slug = %s LIMIT 1",
                (string) $role_slug
            ), ARRAY_A );
            if ( $row ) {
                $row = $this->fill_badge_fields( $row, (int)( $row['package_id'] ?? 0 ) );
            }
        }

        return $row ?: null;
    }

    /**
     * Bổ sung badge_icon và badge_label vào $row nếu thiếu.
     * Dùng SHOW COLUMNS để kiểm tra trước — tránh fatal "Unknown column" trên DB cũ.
     *
     * @param array $row
     * @param int   $package_id
     * @return array
     */
    private function fill_badge_fields( array $row, $package_id ) {
        $package_id = (int) $package_id;
        if ( isset( $row['badge_icon'] ) && isset( $row['badge_label'] ) ) {
            return $row; // DB mới đã có cột, không cần làm gì
        }

        $row['badge_icon']  = '';
        $row['badge_label'] = '';

        if ( $package_id <= 0 ) {
            return $row;
        }

        global $wpdb;
        $pk   = $wpdb->prefix . self::TABLE_PACKAGES;
        $cols = self::get_columns( $pk );

        if ( in_array( 'badge_icon', $cols, true ) ) {
            $extra = $wpdb->get_row( $wpdb->prepare(
                "SELECT badge_icon, badge_label FROM {$pk} WHERE id = %d LIMIT 1",
                $package_id
            ), ARRAY_A );
            if ( $extra ) {
                $row['badge_icon']  = $extra['badge_icon']  ?? '';
                $row['badge_label'] = $extra['badge_label'] ?? '';
            }
        }

        return $row;
    }

    public function get_all_user_assignments() {
        global $wpdb;
        $up = $wpdb->prefix . self::TABLE_USER_PACKAGES;
        $pk = $wpdb->prefix . self::TABLE_PACKAGES;

        return $wpdb->get_results(
            "SELECT up.*, p.package_name, p.color,
                    CASE WHEN up.user_id > 0
                         THEN u.user_login
                         ELSE CONCAT('[Role] ', up.role_slug)
                    END AS label
             FROM {$up} up
             LEFT JOIN {$pk} p ON p.id = up.package_id
             LEFT JOIN {$wpdb->users} u ON u.ID = up.user_id
             ORDER BY up.updated_at DESC
             LIMIT 200",
            ARRAY_A
        ) ?: [];
    }
}
