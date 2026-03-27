# LCNI Data Collector - Version 5.3.9c

- Thời gian: 2026-03-02 12:00

## Module mới
- Member Login shortcode module.
- Member Register shortcode module.
- SaaS package permission module (User/Role -> Package -> Permission).
- Permission middleware chặn shortcode và REST truy cập trái phép.

## Shortcodes
- `[lcni_member_login]`
- `[lcni_member_register]`

## Settings tabs
- Frontend Settings → Member/Login
- Frontend Settings → Member/Register
- Frontend Settings → Member/Gói SaaS

## Database tables mới
- `wp_lcni_saas_packages`
- `wp_lcni_saas_permissions`
- `wp_lcni_user_packages`
