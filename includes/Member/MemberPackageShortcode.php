<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Shortcode [lcni_member_package]
 *
 * Atts:
 *   show_permissions="yes|no"  — Hiển thị danh sách quyền (default: yes)
 *   show_expiry="yes|no"       — Hiển thị hạn dùng (default: yes)
 *   no_package_text="..."      — Text khi user chưa có gói
 *   guest_text="..."           — Text khi chưa đăng nhập
 *   expired_text="..."         — Text khi gói đã hết hạn
 */
class LCNI_Member_Package_Shortcode {

    private $service;

    public function __construct(LCNI_SaaS_Service $service) {
        $this->service = $service;
        add_shortcode('lcni_member_package', [$this, 'render']);
    }

    public function render($atts = []) {
        $atts = shortcode_atts([
            'show_permissions' => 'yes',
            'show_expiry'      => 'yes',
            'no_package_text'  => 'Bạn chưa được gán gói dịch vụ.',
            'guest_text'       => 'Vui lòng đăng nhập để xem thông tin gói.',
            'expired_text'     => 'Gói dịch vụ của bạn đã hết hạn.',
        ], $atts, 'lcni_member_package');

        if (!is_user_logged_in()) {
            return $this->wrap('<p class="lcni-pkg-sc-notice lcni-pkg-sc-guest">'
                . esc_html($atts['guest_text']) . '</p>');
        }

        $info = $this->service->get_current_user_package_info();

        if (!$info) {
            return $this->wrap('<p class="lcni-pkg-sc-notice lcni-pkg-sc-empty">'
                . esc_html($atts['no_package_text']) . '</p>');
        }

        if ($info['is_expired']) {
            return $this->wrap('<p class="lcni-pkg-sc-notice lcni-pkg-sc-expired">'
                . esc_html($atts['expired_text']) . '</p>');
        }

        $color   = esc_attr($info['color']);
        $name    = esc_html($info['package_name']);
        $desc    = esc_html($info['description']);
        $expires = $info['expires_at']
            ? date_i18n(get_option('date_format'), strtotime($info['expires_at']))
            : 'Vĩnh viễn';

        // Build permissions list
        $perm_html = '';
        if ($atts['show_permissions'] === 'yes' && !empty($info['permissions'])) {
            $module_labels = LCNI_SaaS_Service::MODULES;
            $caps = [
                'can_view'     => ['👁', 'Xem'],
                'can_filter'   => ['🔍', 'Lọc'],
                'can_export'   => ['📤', 'Xuất'],
                'can_realtime' => ['⚡', 'Realtime'],
            ];

            $rows = '';
            foreach ($info['permissions'] as $p) {
                $module_meta  = $module_labels[$p['module_key']] ?? null;
                // Hỗ trợ format cũ (string) lẫn mới (array có key 'label')
                if (is_array($module_meta)) {
                    $module_label = $module_meta['label'] ?? $p['module_key'];
                } elseif (is_string($module_meta) && $module_meta !== '') {
                    $module_label = $module_meta;
                } else {
                    $module_label = $p['module_key'];
                }
                $cap_badges = '';
                foreach ($caps as $cap_key => [$icon, $label]) {
                    if (!empty($p[$cap_key])) {
                        $cap_badges .= '<span class="lcni-pkg-sc-cap">' . $icon . ' ' . esc_html($label) . '</span>';
                    }
                }
                if ($cap_badges !== '') {
                    $rows .= '<div class="lcni-pkg-sc-perm-row">'
                           . '<span class="lcni-pkg-sc-module">' . esc_html($module_label) . '</span>'
                           . '<span class="lcni-pkg-sc-caps">' . $cap_badges . '</span>'
                           . '</div>';
                }
            }

            if ($rows !== '') {
                $perm_html = '<div class="lcni-pkg-sc-perms">'
                           . '<div class="lcni-pkg-sc-perms-title">Quyền truy cập</div>'
                           . $rows
                           . '</div>';
            }
        }

        $expiry_html = '';
        if ($atts['show_expiry'] === 'yes') {
            $expiry_html = '<div class="lcni-pkg-sc-expiry">⏰ Hạn dùng: <strong>' . esc_html($expires) . '</strong></div>';
        }

        $note_html = '';
        if (!empty($info['note'])) {
            $note_html = '<div class="lcni-pkg-sc-note">📌 ' . esc_html($info['note']) . '</div>';
        }

        $html = <<<HTML
<div class="lcni-pkg-sc-card" style="--pkg-color:{$color};">
    <div class="lcni-pkg-sc-header">
        <div class="lcni-pkg-sc-dot"></div>
        <div>
            <div class="lcni-pkg-sc-name">{$name}</div>
            <div class="lcni-pkg-sc-desc">{$desc}</div>
        </div>
    </div>
    {$expiry_html}
    {$note_html}
    {$perm_html}
</div>
HTML;

        return $this->wrap($html);
    }

    private function wrap($inner) {
        static $style_printed = false;
        $style = '';
        if (!$style_printed) {
            $style_printed = true;
            $style = '<style>
.lcni-pkg-sc-wrap { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
.lcni-pkg-sc-notice { padding: 12px 16px; border-radius: 10px; font-size: 14px; color: #374151; background: #f3f4f6; border: 1px solid #e5e7eb; }
.lcni-pkg-sc-guest   { background: #eff6ff; color: #1e40af; border-color: #bfdbfe; }
.lcni-pkg-sc-empty   { background: #fafafa; color: #6b7280; }
.lcni-pkg-sc-expired { background: #fff7ed; color: #9a3412; border-color: #fed7aa; }

.lcni-pkg-sc-card {
    background: #fff;
    border: 2px solid var(--pkg-color, #2563eb);
    border-radius: 14px;
    padding: 20px;
    box-shadow: 0 2px 12px rgba(0,0,0,.07);
    max-width: 480px;
}
.lcni-pkg-sc-header {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 12px;
}
.lcni-pkg-sc-dot {
    width: 14px;
    height: 14px;
    border-radius: 50%;
    background: var(--pkg-color, #2563eb);
    flex-shrink: 0;
    box-shadow: 0 0 0 3px color-mix(in srgb, var(--pkg-color, #2563eb) 20%, transparent);
}
.lcni-pkg-sc-name {
    font-size: 16px;
    font-weight: 700;
    color: #111827;
    line-height: 1.2;
}
.lcni-pkg-sc-desc {
    font-size: 13px;
    color: #6b7280;
    margin-top: 2px;
}
.lcni-pkg-sc-expiry {
    font-size: 13px;
    color: #374151;
    padding: 8px 12px;
    background: #f9fafb;
    border-radius: 8px;
    margin-bottom: 8px;
}
.lcni-pkg-sc-note {
    font-size: 12px;
    color: #6b7280;
    margin-bottom: 12px;
}
.lcni-pkg-sc-perms { margin-top: 14px; border-top: 1px solid #f3f4f6; padding-top: 14px; }
.lcni-pkg-sc-perms-title {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .06em;
    color: #9ca3af;
    margin-bottom: 10px;
}
.lcni-pkg-sc-perm-row {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 6px 0;
    border-bottom: 1px solid #f9fafb;
    flex-wrap: wrap;
}
.lcni-pkg-sc-perm-row:last-child { border-bottom: none; }
.lcni-pkg-sc-module {
    font-size: 13px;
    font-weight: 600;
    color: #374151;
    min-width: 100px;
}
.lcni-pkg-sc-caps { display: flex; gap: 6px; flex-wrap: wrap; }
.lcni-pkg-sc-cap {
    display: inline-flex;
    align-items: center;
    gap: 3px;
    padding: 2px 8px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 600;
    background: color-mix(in srgb, var(--pkg-color, #2563eb) 12%, #fff);
    color: var(--pkg-color, #2563eb);
    border: 1px solid color-mix(in srgb, var(--pkg-color, #2563eb) 25%, transparent);
}
</style>';
        }
        return $style . '<div class="lcni-pkg-sc-wrap">' . $inner . '</div>';
    }
}
