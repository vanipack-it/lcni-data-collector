<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_Portfolio_Admin_Page {

    private $service;

    public function __construct(LCNI_Portfolio_Service $service) {
        $this->service = $service;
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_head', [$this, 'admin_styles']);
    }

    public function add_menu() {
        add_submenu_page(
            'lcni-settings',
            'Portfolio Users',
            '📊 Portfolio Users',
            'manage_options',
            'lcni-portfolio-admin',
            [$this, 'render_page']
        );
    }

    public function admin_styles() {
        $screen = get_current_screen();
        if (!$screen || strpos($screen->id, 'lcni-portfolio-admin') === false) return;
        echo '<style>
        .lcni-pa-wrap { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; max-width: 1200px; }
        .lcni-pa-table { width:100%; border-collapse:collapse; background:#fff; border-radius:8px; overflow:hidden; box-shadow:0 1px 4px rgba(0,0,0,.08); }
        .lcni-pa-table th { background:#f3f4f6; color:#374151; font-size:12px; font-weight:600; text-transform:uppercase; padding:10px 14px; text-align:left; }
        .lcni-pa-table td { padding:10px 14px; font-size:13px; border-top:1px solid #f3f4f6; color:#1f2937; }
        .lcni-pa-table tr:hover td { background:#fafafa; }
        .lcni-badge { display:inline-block; padding:2px 8px; border-radius:12px; font-size:11px; font-weight:600; }
        .lcni-badge-green { background:#dcfce7; color:#166534; }
        .lcni-badge-red { background:#fee2e2; color:#991b1b; }
        .lcni-badge-gray { background:#f3f4f6; color:#6b7280; }
        </style>';
    }

    public function render_page() {
        global $wpdb;
        $portfolios_table  = $wpdb->prefix . 'lcni_portfolios';
        $transactions_table = $wpdb->prefix . 'lcni_portfolio_transactions';

        // Check tables exist
        if ($wpdb->get_var("SHOW TABLES LIKE '{$portfolios_table}'") !== $portfolios_table) {
            echo '<div class="wrap"><p>Bảng portfolio chưa được tạo. Deactivate & reactivate plugin.</p></div>';
            return;
        }

        $rows = $wpdb->get_results(
            "SELECT p.*, u.display_name, u.user_email,
                    COUNT(t.id) AS tx_count
             FROM {$portfolios_table} p
             LEFT JOIN {$wpdb->users} u ON u.ID = p.user_id
             LEFT JOIN {$transactions_table} t ON t.portfolio_id = p.id
             GROUP BY p.id
             ORDER BY p.user_id ASC, p.id ASC",
            ARRAY_A
        ) ?: [];

        ?>
        <div class="wrap lcni-pa-wrap">
            <h1>📊 Danh mục đầu tư của User</h1>
            <p style="color:#6b7280;">Xem toàn bộ portfolio các thành viên đã tạo.</p>

            <?php if (empty($rows)): ?>
                <p style="padding:20px;background:#fff;border-radius:8px;">Chưa có portfolio nào được tạo.</p>
            <?php else: ?>
            <table class="lcni-pa-table">
                <thead>
                    <tr>
                        <th>User</th>
                        <th>Tên danh mục</th>
                        <th>Số giao dịch</th>
                        <th>Mặc định</th>
                        <th>Ngày tạo</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html($row['display_name'] ?? '—'); ?></strong><br>
                            <span style="color:#9ca3af;font-size:11px;"><?php echo esc_html($row['user_email'] ?? ''); ?></span>
                        </td>
                        <td><?php echo esc_html($row['name']); ?></td>
                        <td><?php echo (int) $row['tx_count']; ?> giao dịch</td>
                        <td>
                            <?php if ($row['is_default']): ?>
                                <span class="lcni-badge lcni-badge-green">Mặc định</span>
                            <?php else: ?>
                                <span class="lcni-badge lcni-badge-gray">—</span>
                            <?php endif; ?>
                        </td>
                        <td style="color:#9ca3af;"><?php echo esc_html(date_i18n('d/m/Y', strtotime($row['created_at']))); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
        <?php
    }
}
