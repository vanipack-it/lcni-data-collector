<?php
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'LCNI_UserStatsAdminPage' ) ) :
class LCNI_UserStatsAdminPage {

    private $repo;

    public function __construct( LCNI_UserStatsRepository $repo ) {
        $this->repo = $repo;
        add_action( 'admin_menu',                     [ $this, 'register_menu' ] );
        add_action( 'wp_ajax_lcni_user_tooltip',      [ $this, 'ajax_tooltip'  ] );
        add_action( 'wp_ajax_lcni_rule_followers',    [ $this, 'ajax_rule_followers' ] );
        add_action( 'admin_enqueue_scripts',          [ $this, 'enqueue'       ] );
    }

    public function register_menu(): void {
        add_submenu_page(
            'lcni-settings', 'Thống kê User', '📊 Thống kê User',
            'manage_options', 'lcni-user-stats', [ $this, 'render' ]
        );
    }

    public function enqueue( $hook ): void {
        if ( ! $hook || strpos((string)$hook, 'lcni-user-stats') === false ) return;
        wp_enqueue_script('chartjs','https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js',[],null,true);
    }

    // =========================================================================
    // AJAX: Tooltip user
    // =========================================================================
    public function ajax_tooltip(): void {
        check_ajax_referer('lcni_user_stats','_nonce');
        if ( ! current_user_can('manage_options') ) wp_send_json_error();

        $user_id = absint($_POST['user_id'] ?? 0);
        $user    = $user_id ? get_userdata($user_id) : null;
        if ( ! $user ) wp_send_json_error();

        $detail     = $this->repo->get_user_detail($user_id);
        $days_active= max(0,(int)floor((time()-strtotime($user->user_registered))/DAY_IN_SECONDS));
        wp_send_json_success(['html' => $this->build_tooltip($user,$detail,$days_active)]);
    }

    // =========================================================================
    // AJAX: Danh sách user follow 1 rule
    // =========================================================================
    public function ajax_rule_followers(): void {
        check_ajax_referer('lcni_user_stats','_nonce');
        if ( ! current_user_can('manage_options') ) wp_send_json_error();

        $rule_id   = absint($_POST['rule_id'] ?? 0);
        $rule_name = sanitize_text_field($_POST['rule_name'] ?? '');
        $followers = $this->repo->get_rule_followers($rule_id);

        ob_start();
        ?>
        <div class="lcni-modal-inner">
            <h2 style="margin:0 0 4px">👁 <?php echo esc_html($rule_name); ?></h2>
            <p style="color:#6b7280;margin:0 0 16px;font-size:13px"><?php echo count($followers); ?> người dùng liên quan</p>
            <table class="lcni-stats-table">
                <thead>
                    <tr>
                        <th>User</th><th>Gói</th><th>Follow từ</th>
                        <th>📧</th><th>🔔</th>
                        <th>Auto Rule</th><th>Loại</th>
                        <th>Trades</th><th>Win%</th><th>P&L</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($followers as $f):
                    $pnl   = (float)$f['pnl'];
                    $pnl_c = $pnl>=0?'#16a34a':'#dc2626';
                    $wr    = (float)$f['winrate'];
                ?>
                <tr>
                    <td>
                        <div style="display:flex;align-items:center;gap:8px">
                            <div class="lcni-user-avatar" style="width:28px;height:28px;font-size:11px">
                                <?php echo esc_html(mb_strtoupper(mb_substr($f['display_name']?:$f['user_email'],0,2))); ?>
                            </div>
                            <div>
                                <div style="font-weight:600;font-size:13px"><?php echo esc_html($f['display_name']); ?></div>
                                <div style="font-size:11px;color:#9ca3af"><?php echo esc_html($f['user_email']); ?></div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <?php if ($f['package_name']): ?>
                        <span style="background:<?php echo esc_attr($f['package_color']??'#6b7280'); ?>;color:#fff;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:600">
                            <?php echo esc_html($f['package_name']); ?>
                        </span>
                        <?php else: echo '<span style="color:#9ca3af;font-size:12px">Free</span>'; endif; ?>
                    </td>
                    <td style="font-size:12px;color:#6b7280"><?php echo $f['followed_at'] ? date_i18n('d/m/Y',strtotime($f['followed_at'])) : '—'; ?></td>
                    <td><?php echo $f['notify_email']?'✅':'—'; ?></td>
                    <td><?php echo $f['notify_browser']?'🔔':'—'; ?></td>
                    <td>
                        <?php if ($f['user_rule_id']): ?>
                        <span style="background:#dcfce7;color:#15803d;padding:2px 8px;border-radius:12px;font-size:11px;font-weight:600">✅ Có</span>
                        <?php else: echo '<span style="color:#9ca3af;font-size:12px">—</span>'; endif; ?>
                    </td>
                    <td style="font-size:11px">
                        <?php if ($f['user_rule_id']) {
                            echo $f['is_paper'] ? '<span style="color:#6b7280">📄 Paper</span>' : '<span style="color:#2563eb">💰 Real</span>';
                            if ($f['auto_order']) echo ' <span style="color:#7c3aed">⚡</span>';
                        } else { echo '—'; } ?>
                    </td>
                    <td><?php echo $f['total_trades']>0?(int)$f['total_trades']:'—'; ?></td>
                    <td style="color:<?php echo $wr>=0.6?'#16a34a':($wr>=0.4?'#d97706':'#dc2626'); ?>;font-weight:600">
                        <?php echo $f['total_trades']>0?number_format($wr*100,1).'%':'—'; ?>
                    </td>
                    <td style="color:<?php echo $pnl_c; ?>;font-weight:600;white-space:nowrap">
                        <?php echo $f['total_trades']>0?(($pnl>=0?'+':'').number_format($pnl/1000000,2).'tr'):'—'; ?>
                    </td>
                    <td>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=lcni-user-profile&uid='.(int)$f['user_id'])); ?>"
                           target="_blank" style="font-size:12px;color:#2563eb">Xem →</a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($followers)): ?>
                <tr><td colspan="11" style="text-align:center;color:#9ca3af;padding:20px">Chưa có user nào.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
        wp_send_json_success(['html' => ob_get_clean()]);
    }

    // =========================================================================
    // MAIN RENDER
    // =========================================================================
    public function render(): void {
        $overview   = $this->repo->get_overview();
        $pkg_stats  = $this->repo->get_package_stats();
        $rule_stats = $this->repo->get_rule_stats();
        $trend      = $this->repo->get_registration_trend(30);
        $pkg_filter_list = $this->repo->get_packages_for_filter();

        $page       = max(1,(int)($_GET['paged']??1));
        $per_page   = 30;
        $search     = sanitize_text_field($_GET['search']??'');
        $pkg_filter = absint($_GET['pkg']??0);
        $type_filter= sanitize_key($_GET['type']??'');  // follow | rule | all

        $filters = array_filter([
            'search'     => $search ?: null,
            'package_id' => $pkg_filter ?: null,
            'has_follow' => $type_filter==='follow' ? true : null,
            'has_rule'   => $type_filter==='rule'   ? true : null,
        ]);

        $total_rows  = $this->repo->get_user_list_count($filters);
        $total_pages = max(1,(int)ceil($total_rows/$per_page));
        $users       = $this->repo->get_user_list($page,$per_page,$filters);
        $nonce       = wp_create_nonce('lcni_user_stats');
        $profile_base= admin_url('admin.php?page=lcni-user-profile&uid=');

        $this->render_styles();
        ?>
        <div class="wrap lcni-stats-wrap">
            <h1 style="display:flex;align-items:center;gap:10px;margin-bottom:20px">
                📊 Thống kê User
                <span style="font-size:13px;font-weight:400;color:#6b7280">Member · Follow · Auto Rule · Activity</span>
            </h1>

            <!-- OVERVIEW CARDS -->
            <div class="lcni-stats-cards">
                <?php $this->card('Tổng user',$overview['total_users'],'👥','#2563eb'); ?>
                <?php $this->card('Đã nâng cấp',$overview['upgraded'],'⭐','#7c3aed',
                    $overview['total_users']>0 ? round($overview['upgraded']/$overview['total_users']*100,1).'%' : ''); ?>
                <?php $this->card('Follow rule',$overview['following'],'👁','#0891b2'); ?>
                <?php $this->card('Auto rule',$overview['auto_users'],'🤖','#059669'); ?>
                <?php $this->card('Mới 7 ngày',$overview['new_7d'],'🆕','#d97706'); ?>
                <?php $this->card('Mới 30 ngày',$overview['new_30d'],'📅','#6366f1'); ?>
                <?php
                $pnl = $overview['total_pnl'];
                $this->card('Tổng P&L',($pnl>=0?'+':'').number_format($pnl/1000000,1).'tr đ','💰',$pnl>=0?'#16a34a':'#dc2626');
                ?>
            </div>

            <!-- GRID: CHART + GÓI -->
            <div class="lcni-stats-grid2">
                <div class="lcni-stats-box">
                    <h3>📅 Đăng ký 30 ngày qua</h3>
                    <canvas id="lcni-reg-chart" height="120"></canvas>
                </div>
                <div class="lcni-stats-box">
                    <h3>📦 Phân bổ theo gói</h3>
                    <canvas id="lcni-pkg-chart" height="90"></canvas>
                    <table class="lcni-stats-table" style="margin-top:10px">
                        <thead><tr><th>Gói</th><th>Users</th><th style="color:#d97706">Sắp HH</th><th style="color:#dc2626">Đã HH</th></tr></thead>
                        <tbody>
                        <?php foreach ($pkg_stats as $p): ?>
                        <tr>
                            <td><span class="lcni-pkg-dot" style="background:<?php echo esc_attr($p['color']??'#6b7280'); ?>"></span><?php echo esc_html($p['package_name']); ?></td>
                            <td><strong><?php echo (int)$p['user_count']; ?></strong></td>
                            <td><?php echo $p['expiring_soon']>0?'<span class="lcni-warn">'.(int)$p['expiring_soon'].'</span>':'—'; ?></td>
                            <td><?php echo $p['expired']>0?'<span class="lcni-err">'.(int)$p['expired'].'</span>':'—'; ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- THỐNG KÊ RULE (click tên rule → danh sách user) -->
            <div class="lcni-stats-box" style="margin-bottom:20px">
                <h3>🎯 Thống kê theo Chiến lược
                    <span style="font-size:12px;font-weight:400;color:#6b7280">— Click số để xem danh sách user</span>
                </h3>
                <table class="lcni-stats-table">
                    <thead>
                        <tr>
                            <th>Chiến lược</th><th>Trạng thái</th>
                            <th>👁 Follow</th><th>📧 Email</th><th>🔔 Push</th>
                            <th>🤖 Auto</th><th>⚡ Lệnh thật</th>
                            <th>💰 Tổng P&L</th><th>📊 TB P&L/user</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rule_stats as $r):
                        $r_pnl = (float)$r['total_pnl'];
                        $r_avg = (float)$r['avg_pnl_per_user'];
                    ?>
                    <tr>
                        <td><strong><?php echo esc_html($r['rule_name']); ?></strong></td>
                        <td><?php echo $r['is_active']?'<span class="lcni-ok">Active</span>':'<span class="lcni-muted">Tắt</span>'; ?></td>
                        <td>
                            <?php if ((int)$r['follow_count']>0): ?>
                            <button class="lcni-rule-btn lcni-follow-count"
                                    data-rule-id="<?php echo (int)$r['id']; ?>"
                                    data-rule-name="<?php echo esc_attr($r['rule_name']); ?>"
                                    data-nonce="<?php echo esc_attr($nonce); ?>">
                                <strong><?php echo (int)$r['follow_count']; ?></strong>
                            </button>
                            <?php else: echo '—'; endif; ?>
                        </td>
                        <td><?php echo (int)$r['email_notify_count']; ?></td>
                        <td><?php echo (int)$r['push_notify_count']; ?></td>
                        <td>
                            <?php if ((int)$r['auto_count']>0): ?>
                            <strong class="lcni-ok"><?php echo (int)$r['auto_count']; ?></strong>
                            <?php else: echo '—'; endif; ?>
                        </td>
                        <td><?php echo (int)$r['real_order_count']>0?(int)$r['real_order_count']:'—'; ?></td>
                        <td style="color:<?php echo $r_pnl>=0?'#16a34a':'#dc2626'; ?>;font-weight:600">
                            <?php echo ($r_pnl>=0?'+':'').number_format($r_pnl/1000000,2); ?>tr
                        </td>
                        <td style="color:<?php echo $r_avg>=0?'#16a34a':'#dc2626'; ?>">
                            <?php echo ($r_avg>=0?'+':'').number_format($r_avg/1000000,2); ?>tr
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- BẢNG USER -->
            <div class="lcni-stats-box">
                <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:14px">
                    <h3 style="margin:0">👤 Danh sách User
                        <span style="font-size:13px;font-weight:400;color:#6b7280">(<?php echo $total_rows; ?>)</span>
                    </h3>
                    <form method="get" style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
                        <input type="hidden" name="page" value="lcni-user-stats">
                        <input type="search" name="search" value="<?php echo esc_attr($search); ?>"
                               placeholder="Tên / email..." style="padding:5px 9px;border:1px solid #d1d5db;border-radius:5px;font-size:13px;width:200px">
                        <select name="pkg" style="padding:5px 8px;border:1px solid #d1d5db;border-radius:5px;font-size:13px">
                            <option value="">— Tất cả gói —</option>
                            <?php foreach ($pkg_filter_list as $p): ?>
                            <option value="<?php echo $p['id']; ?>" <?php selected($pkg_filter,$p['id']); ?>>
                                <?php echo esc_html($p['package_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <select name="type" style="padding:5px 8px;border:1px solid #d1d5db;border-radius:5px;font-size:13px">
                            <option value="">— Tất cả —</option>
                            <option value="follow" <?php selected($type_filter,'follow'); ?>>Đang follow</option>
                            <option value="rule"   <?php selected($type_filter,'rule');   ?>>Có Auto Rule</option>
                        </select>
                        <button type="submit" class="button">🔍 Lọc</button>
                        <?php if ($search||$pkg_filter||$type_filter): ?>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=lcni-user-stats')); ?>" class="button">✕</a>
                        <?php endif; ?>
                    </form>
                </div>

                <table class="lcni-stats-table lcni-users-table">
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Gói</th>
                            <th>Đăng ký</th>
                            <th>Lần cuối active</th>
                            <th>Ngày active</th>
                            <th>👁 Follow</th>
                            <th>🤖 Auto Rule</th>
                            <th>Trades</th>
                            <th>Win%</th>
                            <th>P&L</th>
                            <th>Total R</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($users as $u):
                        $days_since_reg = max(0,(int)floor((time()-strtotime($u['user_registered']))/DAY_IN_SECONDS));
                        $pnl   = (float)$u['total_pnl_vnd'];
                        $pnl_c = $pnl>=0?'#16a34a':'#dc2626';
                        $r_c   = (float)$u['total_r']>=0?'#16a34a':'#dc2626';
                        $wr    = (float)$u['winrate'];
                        $wr_c  = $wr>=0.6?'#16a34a':($wr>=0.4?'#d97706':'#dc2626');
                        $expire= $u['expires_at'] ? strtotime($u['expires_at']) : 0;
                        $exp_cls = '';
                        if ($expire) {
                            $diff = (int)floor(($expire-time())/DAY_IN_SECONDS);
                            if ($diff<0) $exp_cls='lcni-err'; elseif ($diff<=7) $exp_cls='lcni-warn';
                        }
                        $last_active = $u['last_active'] ?? null;
                        $active_days = (int)($u['active_days'] ?? 0);
                        $days_ago_active = $last_active ? (int)floor((time()-strtotime($last_active))/DAY_IN_SECONDS) : null;
                    ?>
                    <tr class="lcni-user-row"
                        data-user-id="<?php echo (int)$u['user_id']; ?>"
                        data-nonce="<?php echo esc_attr($nonce); ?>">
                        <td>
                            <div style="display:flex;align-items:center;gap:8px">
                                <div class="lcni-user-avatar">
                                    <?php echo esc_html(mb_strtoupper(mb_substr($u['display_name']?:$u['user_login'],0,2))); ?>
                                </div>
                                <div>
                                    <div class="lcni-user-name"><?php echo esc_html($u['display_name']?:$u['user_login']); ?></div>
                                    <div class="lcni-user-email"><?php echo esc_html($u['user_email']); ?></div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <?php if ($u['package_name']): ?>
                            <span class="lcni-pkg-badge <?php echo $exp_cls; ?>"
                                  style="background:<?php echo esc_attr($u['package_color']??'#6b7280'); ?>">
                                <?php echo esc_html($u['package_name']); ?>
                            </span>
                            <?php if ($u['expires_at']): ?>
                            <div class="lcni-expire <?php echo $exp_cls; ?>">
                                <?php
                                $diff = (int)floor((strtotime($u['expires_at'])-time())/DAY_IN_SECONDS);
                                echo $diff<0 ? 'HH '.abs($diff).'ng' : ($diff===0?'Hôm nay':'Còn '.$diff.'ng');
                                ?>
                            </div>
                            <?php endif; ?>
                            <?php else: ?>
                            <span class="lcni-pkg-badge" style="background:#9ca3af">Free</span>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:12px;color:#6b7280;white-space:nowrap">
                            <?php echo date_i18n('d/m/Y',strtotime($u['user_registered'])); ?>
                        </td>
                        <td>
                            <?php if ($last_active): ?>
                            <span class="lcni-active-badge <?php echo $days_ago_active===0?'today':($days_ago_active<=7?'recent':'old'); ?>">
                                <?php echo $days_ago_active===0?'Hôm nay':($days_ago_active===1?'Hôm qua':$days_ago_active.'ng trước'); ?>
                            </span>
                            <?php else: ?>
                            <span style="color:#d1d5db;font-size:12px">Chưa theo dõi</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($active_days>0): ?>
                            <span style="background:#f0fdf4;color:#15803d;padding:2px 8px;border-radius:20px;font-size:12px;font-weight:600">
                                <?php echo $active_days; ?>ng
                            </span>
                            <?php else: echo '—'; endif; ?>
                        </td>
                        <td>
                            <?php if ((int)$u['follow_count']>0): ?>
                            <div>
                                <span class="lcni-num-badge blue"><?php echo (int)$u['follow_count']; ?></span>
                                <?php if ($u['follow_names']): ?>
                                <div style="font-size:10px;color:#9ca3af;max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;margin-top:2px"
                                     title="<?php echo esc_attr((string)($u['follow_names']??'')); ?>">
                                    <?php echo esc_html(mb_strimwidth((string)($u['follow_names']??''),0,40,'…')); ?>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php else: echo '<span style="color:#d1d5db">—</span>'; endif; ?>
                        </td>
                        <td>
                            <?php if ((int)$u['active_rule_count']>0): ?>
                            <div>
                                <span class="lcni-num-badge green"><?php echo (int)$u['active_rule_count']; ?></span>
                                <?php if ($u['rule_names']): ?>
                                <div style="font-size:10px;color:#9ca3af;max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;margin-top:2px"
                                     title="<?php echo esc_attr((string)($u['rule_names']??'')); ?>">
                                    <?php echo esc_html(mb_strimwidth((string)($u['rule_names']??''),0,40,'…')); ?>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php else: echo '<span style="color:#d1d5db">—</span>'; endif; ?>
                        </td>
                        <td><?php echo (int)$u['total_trades']>0?(int)$u['total_trades']:'—'; ?></td>
                        <td style="font-weight:600;color:<?php echo $wr_c; ?>">
                            <?php echo (int)$u['total_trades']>0?number_format($wr*100,1).'%':'—'; ?>
                        </td>
                        <td style="color:<?php echo $pnl_c; ?>;font-weight:600;white-space:nowrap">
                            <?php echo (int)$u['total_trades']>0?(($pnl>=0?'+':'').number_format($pnl/1000000,2).'tr'):'—'; ?>
                        </td>
                        <td style="color:<?php echo $r_c; ?>">
                            <?php if ((int)$u['total_trades']>0) {
                                $r=(float)$u['total_r'];
                                echo ($r>=0?'+':'').number_format($r,2).'R';
                            } else { echo '—'; } ?>
                        </td>
                        <td>
                            <a href="<?php echo esc_url($profile_base.(int)$u['user_id']); ?>"
                               title="Xem chân dung" style="font-size:16px;text-decoration:none">🪞</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($users)): ?>
                    <tr><td colspan="12" style="text-align:center;color:#9ca3af;padding:24px">Không có dữ liệu.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>

                <!-- PAGINATION -->
                <?php if ($total_pages>1): ?>
                <div class="lcni-pagination">
                    <?php
                    $base = add_query_arg(array_filter(['page'=>'lcni-user-stats','search'=>$search?:null,'pkg'=>$pkg_filter?:null,'type'=>$type_filter?:null]),admin_url('admin.php'));
                    for ($i=1;$i<=min($total_pages,15);$i++):
                        $url=add_query_arg('paged',$i,$base);
                    ?>
                    <a href="<?php echo esc_url($url); ?>" class="lcni-page-btn <?php echo $i===$page?'current':''; ?>"><?php echo $i; ?></a>
                    <?php endfor; if ($total_pages>15): ?>
                    <span style="color:#6b7280;padding:0 8px">… <?php echo $total_pages; ?> trang</span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Tooltip container -->
        <div id="lcni-user-tooltip" class="lcni-tooltip-wrap" style="display:none"></div>

        <!-- Modal container (rule followers) -->
        <div id="lcni-modal-overlay" style="display:none">
            <div id="lcni-modal-box">
                <button id="lcni-modal-close">✕</button>
                <div id="lcni-modal-content"></div>
            </div>
        </div>

        <?php $this->render_scripts($trend,$pkg_stats,$nonce); ?>
        <?php
    }

    // =========================================================================
    // BUILD TOOLTIP HTML
    // =========================================================================
    private function build_tooltip( WP_User $user, array $d, int $days_active ): string {
        $pkg        = $d['package'];
        $follows    = $d['follows'];
        $rules      = $d['user_rules'];
        $activity   = $d['activity'];
        $total_pnl  = array_sum(array_column($rules,'total_pnl_vnd'));
        $total_tr   = array_sum(array_column($rules,'total_trades'));
        $win_tr     = array_sum(array_column($rules,'win_trades'));
        $total_r    = array_sum(array_column($rules,'total_r'));
        $wr         = $total_tr>0 ? round($win_tr/$total_tr*100,1) : 0;
        $pnl_c      = $total_pnl>=0?'#16a34a':'#dc2626';

        ob_start(); ?>
        <div class="lcni-tt">
            <div class="lcni-tt__head">
                <div class="lcni-tt__avatar"><?php echo mb_strtoupper(mb_substr($user->display_name?:$user->user_login,0,2)); ?></div>
                <div>
                    <div class="lcni-tt__name"><?php echo esc_html($user->display_name?:$user->user_login); ?></div>
                    <div class="lcni-tt__email"><?php echo esc_html($user->user_email); ?></div>
                </div>
            </div>
            <div class="lcni-tt__meta">
                <?php if ($pkg): ?>
                <span class="lcni-tt__badge" style="background:<?php echo esc_attr($pkg['color']??'#2563eb'); ?>">
                    <?php echo esc_html($pkg['package_name']); ?>
                </span>
                <?php endif; ?>
                <span class="lcni-tt__chip">🕐 <?php echo $days_active; ?> ngày</span>
                <?php if ($activity && $activity['last_active']): ?>
                <span class="lcni-tt__chip">
                    🟢 Active <?php
                    $ago = (int)floor((time()-strtotime($activity['last_active']))/DAY_IN_SECONDS);
                    echo $ago===0?'hôm nay':($ago===1?'hôm qua':$ago.'ng trước');
                    ?>
                </span>
                <span class="lcni-tt__chip">📅 <?php echo (int)$activity['active_days']; ?> ngày active</span>
                <?php endif; ?>
                <?php if ($d['open_signals']>0): ?>
                <span class="lcni-tt__chip">📈 <?php echo $d['open_signals']; ?> vị thế</span>
                <?php endif; ?>
            </div>
            <?php if ($total_tr>0): ?>
            <div class="lcni-tt__perf">
                <div class="lcni-tt__stat"><span class="lcni-tt__stat-val" style="color:<?php echo $pnl_c; ?>"><?php echo ($total_pnl>=0?'+':'').number_format($total_pnl/1000000,2); ?>tr</span><span class="lcni-tt__stat-lbl">P&L</span></div>
                <div class="lcni-tt__stat"><span class="lcni-tt__stat-val"><?php echo $wr; ?>%</span><span class="lcni-tt__stat-lbl">Win</span></div>
                <div class="lcni-tt__stat"><span class="lcni-tt__stat-val" style="color:<?php echo $total_r>=0?'#16a34a':'#dc2626'; ?>"><?php echo ($total_r>=0?'+':'').number_format($total_r,2); ?>R</span><span class="lcni-tt__stat-lbl">Total R</span></div>
                <div class="lcni-tt__stat"><span class="lcni-tt__stat-val"><?php echo $total_tr; ?></span><span class="lcni-tt__stat-lbl">Trades</span></div>
            </div>
            <?php endif; ?>
            <?php if (!empty($follows)): ?>
            <div class="lcni-tt__section">
                <div class="lcni-tt__sec-title">👁 Follow (<?php echo count($follows); ?>)</div>
                <?php foreach (array_slice($follows,0,4) as $f): ?>
                <div class="lcni-tt__row">
                    <span><?php echo esc_html($f['rule_name']); ?></span>
                    <span><?php echo $f['notify_email']?'📧 ':' '; echo $f['notify_browser']?'🔔':''; ?></span>
                </div>
                <?php endforeach;
                if (count($follows)>4) echo '<div class="lcni-tt__more">+' . (count($follows)-4) . ' chiến lược</div>'; ?>
            </div>
            <?php endif; ?>
            <?php if (!empty($rules)): ?>
            <div class="lcni-tt__section">
                <div class="lcni-tt__sec-title">🤖 Auto Rule (<?php echo count($rules); ?>)</div>
                <?php foreach (array_slice($rules,0,3) as $ur):
                    $up = (float)$ur['total_pnl_vnd'];
                ?>
                <div class="lcni-tt__row">
                    <span><?php echo esc_html($ur['rule_name']); ?>
                        <?php echo $ur['is_paper']?'<em style="color:#9ca3af;font-size:10px">paper</em>':'<em style="color:#3b82f6;font-size:10px">real</em>'; ?>
                        <?php if ($ur['auto_order']) echo '<em style="color:#7c3aed;font-size:10px">⚡</em>'; ?>
                    </span>
                    <?php if ($ur['total_trades']>0): ?>
                    <span style="color:<?php echo $up>=0?'#16a34a':'#dc2626'; ?>;font-size:12px;font-weight:600">
                        <?php echo ($up>=0?'+':'').number_format($up/1000000,2); ?>tr
                    </span>
                    <?php endif; ?>
                </div>
                <?php endforeach;
                if (count($rules)>3) echo '<div class="lcni-tt__more">+' . (count($rules)-3) . ' chiến lược</div>'; ?>
            </div>
            <?php endif; ?>
            <?php if ($pkg && $pkg['expires_at']): ?>
            <div class="lcni-tt__footer">HH gói: <?php echo date_i18n('d/m/Y',strtotime($pkg['expires_at'])); ?></div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    // =========================================================================
    // HELPERS
    // =========================================================================
    private function card(string $label,$value,string $icon,string $color,string $sub=''): void { ?>
        <div class="lcni-stats-card">
            <div class="lcni-stats-card__icon" style="background:<?php echo $color; ?>18;color:<?php echo $color; ?>"><?php echo $icon; ?></div>
            <div>
                <div class="lcni-stats-card__val"><?php echo is_numeric($value)?number_format((int)$value):esc_html($value); ?></div>
                <div class="lcni-stats-card__lbl"><?php echo esc_html($label); ?></div>
                <?php if ($sub) echo '<div class="lcni-stats-card__sub">'.esc_html($sub).'</div>'; ?>
            </div>
        </div>
    <?php }

    // =========================================================================
    // SCRIPTS
    // =========================================================================
    private function render_scripts(array $trend,array $pkg_stats,string $nonce): void {
        $reg_labels=[]; $reg_data=[];
        $tmap=array_column($trend,'cnt','reg_date');
        for ($i=29;$i>=0;$i--) {
            $d=date('Y-m-d',strtotime("-{$i} days"));
            $reg_labels[]=date('d/m',strtotime($d));
            $reg_data[]=(int)($tmap[$d]??0);
        }
        $pkg_labels=array_column($pkg_stats,'package_name');
        $pkg_data=array_map(function($p){ return (int)$p['user_count']; },$pkg_stats);
        $pkg_colors=array_column($pkg_stats,'color');
        ?>
        <script>
        (function(){
            // Charts
            var regCtx = document.getElementById('lcni-reg-chart');
            if (regCtx) new Chart(regCtx,{type:'bar',data:{labels:<?php echo wp_json_encode($reg_labels);?>,datasets:[{label:'Đăng ký',data:<?php echo wp_json_encode($reg_data);?>,backgroundColor:'rgba(37,99,235,.7)',borderRadius:4}]},options:{responsive:true,plugins:{legend:{display:false}},scales:{x:{ticks:{font:{size:10},maxRotation:45}},y:{beginAtZero:true,ticks:{stepSize:1}}}}});

            var pkgCtx = document.getElementById('lcni-pkg-chart');
            if (pkgCtx) new Chart(pkgCtx,{type:'doughnut',data:{labels:<?php echo wp_json_encode($pkg_labels);?>,datasets:[{data:<?php echo wp_json_encode($pkg_data);?>,backgroundColor:<?php echo wp_json_encode($pkg_colors);?>,borderWidth:2,borderColor:'#fff'}]},options:{responsive:true,cutout:'65%',plugins:{legend:{position:'right',labels:{font:{size:11},boxWidth:12}}}}});

            var AJAX  = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
            var cache = {};
            var timer = null;

            // ── Tooltip on hover ──
            var tooltip = document.getElementById('lcni-user-tooltip');
            document.querySelectorAll('.lcni-user-row').forEach(function(row){
                row.addEventListener('mouseenter',function(){
                    var uid=row.dataset.userId, nonce=row.dataset.nonce;
                    timer = setTimeout(function(){
                        if (cache[uid]){showTip(row,cache[uid]);return;}
                        tooltip.innerHTML='<div style="padding:14px;color:#9ca3af;font-size:13px">⏳ Đang tải...</div>';
                        posTip(row); tooltip.style.display='block';
                        fetch(AJAX,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
                            body:new URLSearchParams({action:'lcni_user_tooltip',user_id:uid,_nonce:nonce})})
                        .then(r=>r.json()).then(d=>{if(d.success){cache[uid]=d.data.html;showTip(row,cache[uid]);}});
                    },300);
                });
                row.addEventListener('mouseleave',function(){
                    clearTimeout(timer);
                    setTimeout(function(){if(!tooltip.matches(':hover'))tooltip.style.display='none';},200);
                });
            });
            tooltip.addEventListener('mouseleave',function(){tooltip.style.display='none';});
            function showTip(row,html){tooltip.innerHTML=html;posTip(row);tooltip.style.display='block';}
            function posTip(row){
                var r=row.getBoundingClientRect(),sy=window.scrollY,sx=window.scrollX;
                var top=r.top+sy, left=r.right+sx+10;
                if(left+340>window.innerWidth+sx) left=r.left+sx-350;
                tooltip.style.top=top+'px'; tooltip.style.left=left+'px';
            }

            // ── Rule followers modal ──
            var overlay=document.getElementById('lcni-modal-overlay');
            var mbox   =document.getElementById('lcni-modal-box');
            var mcont  =document.getElementById('lcni-modal-content');
            document.querySelectorAll('.lcni-follow-count').forEach(function(btn){
                btn.addEventListener('click',function(){
                    var rid=btn.dataset.ruleId, rname=btn.dataset.ruleName, nonce=btn.dataset.nonce;
                    mcont.innerHTML='<div style="padding:24px;text-align:center;color:#9ca3af">⏳ Đang tải...</div>';
                    overlay.style.display='flex';
                    fetch(AJAX,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
                        body:new URLSearchParams({action:'lcni_rule_followers',rule_id:rid,rule_name:rname,_nonce:nonce})})
                    .then(r=>r.json()).then(d=>{if(d.success)mcont.innerHTML=d.data.html;});
                });
            });
            document.getElementById('lcni-modal-close').onclick=function(){overlay.style.display='none';};
            overlay.addEventListener('click',function(e){if(e.target===overlay)overlay.style.display='none';});
        })();
        </script>
        <?php
    }

    // =========================================================================
    // STYLES
    // =========================================================================
    private function render_styles(): void { ?>
        <style>
        .lcni-stats-wrap{max-width:1500px}
        .lcni-stats-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:12px;margin-bottom:20px}
        .lcni-stats-card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:14px;display:flex;gap:12px;align-items:center}
        .lcni-stats-card__icon{width:40px;height:40px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0}
        .lcni-stats-card__val{font-size:22px;font-weight:800;color:#111827;line-height:1.1}
        .lcni-stats-card__lbl{font-size:11px;color:#6b7280;margin-top:2px}
        .lcni-stats-card__sub{font-size:11px;color:#9ca3af}
        .lcni-stats-grid2{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px}
        @media(max-width:900px){.lcni-stats-grid2{grid-template-columns:1fr}}
        .lcni-stats-box{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:18px 20px}
        .lcni-stats-box h3{margin:0 0 14px;font-size:14px;font-weight:700;color:#111827}
        .lcni-stats-table{width:100%;border-collapse:collapse;font-size:13px}
        .lcni-stats-table th{background:#f9fafb;padding:8px 10px;text-align:left;font-weight:600;color:#374151;border-bottom:2px solid #e5e7eb;white-space:nowrap}
        .lcni-stats-table td{padding:8px 10px;border-bottom:1px solid #f3f4f6;vertical-align:middle}
        .lcni-stats-table tr:hover td{background:#f8fafc}
        .lcni-user-row{cursor:default}
        .lcni-user-avatar{width:32px;height:32px;border-radius:50%;background:#2563eb;color:#fff;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;flex-shrink:0}
        .lcni-user-name{font-size:13px;font-weight:600;color:#111827}
        .lcni-user-email{font-size:11px;color:#9ca3af}
        .lcni-pkg-badge{display:inline-block;padding:2px 8px;border-radius:20px;color:#fff;font-size:11px;font-weight:600}
        .lcni-expire{font-size:10px;color:#9ca3af;margin-top:2px}
        .lcni-num-badge{display:inline-flex;align-items:center;justify-content:center;min-width:22px;height:22px;border-radius:11px;font-size:12px;font-weight:700;padding:0 6px}
        .lcni-num-badge.blue{background:#dbeafe;color:#1d4ed8}
        .lcni-num-badge.green{background:#dcfce7;color:#15803d}
        .lcni-pkg-dot{display:inline-block;width:10px;height:10px;border-radius:50%;margin-right:6px;vertical-align:middle}
        .lcni-active-badge{display:inline-block;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:600}
        .lcni-active-badge.today{background:#dcfce7;color:#15803d}
        .lcni-active-badge.recent{background:#dbeafe;color:#1d4ed8}
        .lcni-active-badge.old{background:#f3f4f6;color:#6b7280}
        .lcni-ok{color:#16a34a;font-weight:600}
        .lcni-muted{color:#9ca3af}
        .lcni-warn{color:#d97706!important;font-weight:600}
        .lcni-err{color:#dc2626!important;font-weight:600}
        .lcni-pagination{display:flex;gap:4px;margin-top:14px;flex-wrap:wrap;align-items:center}
        .lcni-page-btn{display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:6px;border:1px solid #e5e7eb;font-size:13px;color:#374151;text-decoration:none;background:#fff}
        .lcni-page-btn.current{background:#2563eb;border-color:#2563eb;color:#fff}
        .lcni-rule-btn{background:none;border:none;cursor:pointer;color:#2563eb;font-size:14px;text-decoration:underline;padding:0}
        .lcni-rule-btn:hover{color:#1d4ed8}
        /* Tooltip */
        .lcni-tooltip-wrap{position:fixed;z-index:99999;width:320px;background:#1e293b;border-radius:12px;box-shadow:0 8px 32px rgba(0,0,0,.35);overflow:hidden;pointer-events:auto}
        .lcni-tt{color:#e2e8f0;font-size:13px}
        .lcni-tt__head{display:flex;align-items:center;gap:12px;padding:14px 16px;border-bottom:1px solid rgba(255,255,255,.08)}
        .lcni-tt__avatar{width:40px;height:40px;border-radius:50%;background:#3b82f6;color:#fff;display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:700;flex-shrink:0}
        .lcni-tt__name{font-weight:700;color:#f1f5f9;font-size:14px}
        .lcni-tt__email{font-size:11px;color:#94a3b8;margin-top:2px}
        .lcni-tt__meta{display:flex;gap:5px;flex-wrap:wrap;padding:9px 16px;border-bottom:1px solid rgba(255,255,255,.06)}
        .lcni-tt__badge{padding:2px 10px;border-radius:20px;color:#fff;font-size:11px;font-weight:600}
        .lcni-tt__chip{background:rgba(255,255,255,.08);padding:2px 8px;border-radius:20px;font-size:11px;color:#cbd5e1}
        .lcni-tt__perf{display:grid;grid-template-columns:repeat(4,1fr);border-bottom:1px solid rgba(255,255,255,.06)}
        .lcni-tt__stat{text-align:center;padding:10px 4px}
        .lcni-tt__stat-val{display:block;font-size:14px;font-weight:800}
        .lcni-tt__stat-lbl{display:block;font-size:10px;color:#94a3b8;margin-top:2px}
        .lcni-tt__section{padding:9px 16px;border-bottom:1px solid rgba(255,255,255,.06)}
        .lcni-tt__sec-title{font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px}
        .lcni-tt__row{display:flex;justify-content:space-between;align-items:center;padding:3px 0;font-size:12px}
        .lcni-tt__more{font-size:11px;color:#64748b;margin-top:4px}
        .lcni-tt__footer{padding:8px 16px;font-size:11px;color:#64748b;text-align:center}
        /* Modal */
        #lcni-modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:100000;display:none;align-items:center;justify-content:center;padding:20px}
        #lcni-modal-box{background:#fff;border-radius:12px;max-width:900px;width:100%;max-height:85vh;overflow-y:auto;position:relative;padding:24px}
        #lcni-modal-close{position:absolute;top:14px;right:16px;background:none;border:none;font-size:18px;cursor:pointer;color:#6b7280}
        .lcni-modal-inner h2{color:#111827}
        </style>
    <?php }
}
endif;
