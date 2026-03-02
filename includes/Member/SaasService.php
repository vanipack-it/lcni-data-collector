<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_SaaS_Service {

    private $repo;

    public function __construct(LCNI_SaaS_Repository $repo) {
        $this->repo = $repo;
    }

    public function get_package_options() {
        return $this->repo->get_packages();
    }

    public function create_package($name) {
        $name = sanitize_text_field((string) $name);
        $key = sanitize_title($name);
        if ($name === '' || $key === '') {
            return 0;
        }

        return $this->repo->create_package($name, $key);
    }

    public function save_permissions($package_id, $permissions) {
        $clean = [];
        foreach ((array) $permissions as $permission) {
            $clean[] = [
                'module_key' => sanitize_key($permission['module_key']),
                'table_name' => sanitize_text_field($permission['table_name']),
                'column_name' => sanitize_text_field($permission['column_name']),
                'can_view' => !empty($permission['can_view']) ? 1 : 0,
                'can_export' => !empty($permission['can_export']) ? 1 : 0,
                'can_filter' => !empty($permission['can_filter']) ? 1 : 0,
                'can_realtime' => !empty($permission['can_realtime']) ? 1 : 0,
            ];
        }

        $this->repo->update_permissions((int) $package_id, $clean);
    }

    public function assign_package($user_id, $role_slug, $package_id) {
        $this->repo->assign_package((int) $user_id, sanitize_key($role_slug), (int) $package_id);
    }

    public function can($module_key, $capability, $user_id = 0) {
        $user_id = $user_id ? (int) $user_id : get_current_user_id();
        $user = $user_id ? get_userdata($user_id) : false;
        $role_slug = $user && !empty($user->roles[0]) ? sanitize_key($user->roles[0]) : 'guest';
        $package_id = $this->repo->get_package_for_user($user_id, $role_slug);

        if (!$package_id) {
            return true;
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
