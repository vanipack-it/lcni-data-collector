<?php
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'LCNI_UserProfilePage' ) ) :
class LCNI_UserProfilePage {

    private $repo;

    public function __construct( LCNI_UserStatsRepository $repo ) {
        $this->repo = $repo;
        add_action( 'admin_menu',                        [ $this, 'register_menu' ] );
        add_action( 'wp_ajax_lcni_user_activity_data',   [ $this, 'ajax_activity' ] );
    }

    public function register_menu(): void {
        add_submenu_page( null, 'Chân dung User', 'Chân dung User',
            'manage_options', 'lcni-user-profile', [ $this, 'render' ] );
    }

    // =========================================================================
    // AJAX — Activity heatmap + daily + events
    // =========================================================================
    public function ajax_activity(): void {
        check_ajax_referer('lcni_user_stats','_nonce');
        if ( ! current_user_can('manage_options') ) wp_send_json_error();

        $user_id = absint($_POST['user_id'] ?? 0);
        $days    = min(365, max(7, absint($_POST['days'] ?? 90)));

        wp_send_json_success([
            'heatmap' => $this->repo->get_activity_heatmap($user_id, $days),
            'daily'   => $this->repo->get_activity_daily($user_id, $days),
            'events'  => $this->repo->get_activity_events($user_id, 50),
            'has_tracking' => $this->repo->activity_table_exists(),
        ]);
    }

    // =========================================================================
    // RENDER
    // =========================================================================
    public function render(): void {
        $user_id = absint($_GET['uid'] ?? 0);
        if ( ! $user_id ) { echo '<div class="wrap"><p>Thiếu user ID.</p></div>'; return; }
        $user = get_userdata($user_id);
        if ( ! $user ) { echo '<div class="wrap"><p>User không tồn tại.</p></div>'; return; }

        $detail     = $this->repo->get_user_detail($user_id);
        $pkg        = $detail['package'];
        $follows    = $detail['follows'];
        $user_rules = $detail['user_rules'];
        $activity   = $detail['activity'];

        $days_active = max(0,(int)floor((time()-strtotime($user->user_registered))/DAY_IN_SECONDS));
        $total_pnl   = array_sum(array_column($user_rules,'total_pnl_vnd'));
        $total_tr    = array_sum(array_column($user_rules,'total_trades'));
        $win_tr      = array_sum(array_column($user_rules,'win_trades'));
        $total_r     = array_sum(array_column($user_rules,'total_r'));
        $max_dd      = ! empty($user_rules) ? max(array_column($user_rules,'max_drawdown_pct')) : 0;
        $winrate     = $total_tr > 0 ? round($win_tr/$total_tr*100,1) : 0;
        $segment     = $this->get_segment($user_rules,$follows,$days_active,$activity);
        $nonce       = wp_create_nonce('lcni_user_stats');

        $this->render_styles();
        ?>
        <div class="wrap lcni-profile-wrap">
            <p style="margin-bottom:12px">
                <a href="<?php echo esc_url(admin_url('admin.php?page=lcni-user-stats')); ?>" style="color:#6b7280;text-decoration:none">← Thống kê User</a>
            </p>

            <!-- HEADER -->
            <div class="lcni-profile-header">
                <div class="lcni-profile-avatar">
                    <?php echo mb_strtoupper(mb_substr($user->display_name?:$user->user_login,0,2)); ?>
                </div>
                <div class="lcni-profile-info">
                    <h1><?php echo esc_html($user->display_name?:$user->user_login); ?></h1>
                    <div class="lcni-profile-meta">
                        <span>✉️ <?php echo esc_html($user->user_email); ?></span>
                        <span>📅 Đăng ký <?php echo date_i18n('d/m/Y',strtotime($user->user_registered)); ?></span>
                        <span>⏱ <?php echo $days_active; ?> ngày là thành viên</span>
                        <?php if ($activity && $activity['last_active']): ?>
                        <span>🟢 Active lần cuối:
                            <?php $ago=(int)floor((time()-strtotime($activity['last_active']))/DAY_IN_SECONDS);
                            echo $ago===0?'hôm nay':($ago===1?'hôm qua':$ago.' ngày trước'); ?>
                        </span>
                        <span>📅 Tổng <?php echo (int)$activity['active_days']; ?> ngày active</span>
                        <?php endif; ?>
                        <?php if ($pkg): ?>
                        <span class="lcni-prof-badge" style="background:<?php echo esc_attr($pkg['color']??'#2563eb'); ?>">
                            <?php echo esc_html($pkg['package_name']); ?>
                            <?php if ($pkg['expires_at']) echo ' · HH '.date_i18n('d/m/Y',strtotime($pkg['expires_at'])); ?>
                        </span>
                        <?php else: ?>
                        <span class="lcni-prof-badge" style="background:#9ca3af">Free</span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="lcni-segment">
                    <div class="lcni-segment__icon"><?php echo $segment['icon']; ?></div>
                    <div>
                        <div class="lcni-segment__name"><?php echo esc_html($segment['name']); ?></div>
                        <div class="lcni-segment__desc"><?php echo esc_html($segment['desc']); ?></div>
                    </div>
                </div>
            </div>

            <!-- KPI CARDS -->
            <div class="lcni-prof-cards">
                <?php
                $pnl_c = $total_pnl>=0?'#16a34a':'#dc2626';
                $this->kpi('P&L',($total_pnl>=0?'+':'').number_format($total_pnl/1000000,2).'tr',$pnl_c);
                $this->kpi('Win Rate',$total_tr>0?$winrate.'%':'—',$winrate>=60?'#16a34a':'#d97706');
                $this->kpi('Total R',($total_r>=0?'+':'').number_format($total_r,2).'R',$total_r>=0?'#16a34a':'#dc2626');
                $this->kpi('Trades',$total_tr?:0,'#2563eb');
                $this->kpi('Max DD',$max_dd>0?'-'.number_format($max_dd,1).'%':'—','#dc2626');
                $this->kpi('Vị thế mở',(int)$detail['open_signals'],'#7c3aed');
                $this->kpi('Follow',count($follows),'#0891b2');
                $this->kpi('Auto Rule',count($user_rules),'#059669');
                ?>
            </div>

            <div class="lcni-prof-grid">

                <!-- CỘT TRÁI -->
                <div>
                    <!-- Follow rules -->
                    <div class="lcni-prof-box">
                        <h3>👁 Đang theo dõi (<?php echo count($follows); ?>)</h3>
                        <?php if (empty($follows)): ?>
                        <p class="lcni-muted-txt">Chưa follow chiến lược nào.</p>
                        <?php else: ?>
                        <table class="lcni-prof-table">
                            <thead><tr><th>Chiến lược</th><th>Follow từ</th><th>Email</th><th>Push</th></tr></thead>
                            <tbody>
                            <?php foreach ($follows as $f): ?>
                            <tr>
                                <td><strong><?php echo esc_html((string)($f['rule_name']??'')); ?></strong></td>
                                <td style="font-size:12px;color:#6b7280"><?php echo date_i18n('d/m/Y',strtotime($f['followed_at'])); ?></td>
                                <td><?php echo $f['notify_email']?'✅':'—'; ?></td>
                                <td><?php echo $f['notify_browser']?'🔔':'—'; ?></td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php endif; ?>
                    </div>

                    <!-- Auto rules -->
                    <div class="lcni-prof-box">
                        <h3>🤖 Chiến lược tự động (<?php echo count($user_rules); ?>)</h3>
                        <?php if (empty($user_rules)): ?>
                        <p class="lcni-muted-txt">Chưa áp dụng chiến lược tự động.</p>
                        <?php else: ?>
                        <?php foreach ($user_rules as $ur):
                            $up = (float)$ur['total_pnl_vnd']; $ur_r=(float)$ur['total_r'];
                            $pc = $up>=0?'#16a34a':'#dc2626'; $rc=$ur_r>=0?'#16a34a':'#dc2626';
                        ?>
                        <div class="lcni-ur-card">
                            <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:6px;margin-bottom:6px">
                                <strong><?php echo esc_html((string)($ur['rule_name']??'')); ?></strong>
                                <div style="display:flex;gap:4px;flex-wrap:wrap">
                                    <span class="lcni-badge-sm" style="background:<?php echo $ur['is_paper']?'#f3f4f6':'#dbeafe'; ?>;color:<?php echo $ur['is_paper']?'#6b7280':'#1d4ed8'; ?>">
                                        <?php echo $ur['is_paper']?'📄 Paper':'💰 Real'; ?>
                                    </span>
                                    <?php if ($ur['auto_order']) echo '<span class="lcni-badge-sm" style="background:#ede9fe;color:#7c3aed">⚡ Auto</span>'; ?>
                                    <span class="lcni-badge-sm" style="background:<?php echo $ur['status']==='active'?'#dcfce7':'#fee2e2'; ?>;color:<?php echo $ur['status']==='active'?'#15803d':'#991b1b'; ?>">
                                        <?php echo $ur['status']==='active'?'● Active':'○ Paused'; ?>
                                    </span>
                                </div>
                            </div>
                            <div style="display:flex;gap:14px;font-size:12px;color:#6b7280;flex-wrap:wrap;margin-bottom:8px">
                                <span>Vốn: <strong><?php echo number_format((float)$ur['capital']/1000000,1); ?>tr</strong></span>
                                <span>Risk: <strong><?php echo $ur['risk_per_trade']; ?>%</strong></span>
                                <span>Từ: <?php echo date_i18n('d/m/Y',strtotime($ur['created_at'])); ?></span>
                                <?php if ($ur['account_id']) echo '<span>DNSE: <code>'.esc_html($ur['account_id']).'</code></span>'; ?>
                            </div>
                            <?php if ($ur['total_trades']>0): ?>
                            <div class="lcni-ur-perf">
                                <div class="lcni-ur-stat"><span style="color:<?php echo $pc; ?>"><?php echo ($up>=0?'+':'').number_format($up/1000000,2); ?>tr</span><span>P&L</span></div>
                                <div class="lcni-ur-stat"><span><?php echo number_format((float)$ur['winrate']*100,1); ?>%</span><span>Win</span></div>
                                <div class="lcni-ur-stat"><span style="color:<?php echo $rc; ?>"><?php echo ($ur_r>=0?'+':'').number_format($ur_r,2); ?>R</span><span>Total R</span></div>
                                <div class="lcni-ur-stat"><span><?php echo (int)$ur['total_trades']; ?></span><span>Trades</span></div>
                                <div class="lcni-ur-stat"><span style="color:#dc2626">-<?php echo number_format((float)$ur['max_drawdown_pct'],1); ?>%</span><span>Max DD</span></div>
                                <div class="lcni-ur-stat"><span><?php echo number_format((float)$ur['current_capital']/1000000,1); ?>tr</span><span>Vốn HT</span></div>
                            </div>
                            <?php else: ?>
                            <p class="lcni-muted-txt" style="margin:0;font-size:12px">Chưa có giao dịch.</p>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- CỘT PHẢI -->
                <div>
                    <!-- Heatmap -->
                    <div class="lcni-prof-box">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
                            <h3 style="margin:0">🕐 Thời gian hoạt động</h3>
                            <select id="lcni-days-sel" style="padding:4px 8px;border:1px solid #d1d5db;border-radius:5px;font-size:12px">
                                <option value="30">30 ngày</option>
                                <option value="90" selected>90 ngày</option>
                                <option value="180">6 tháng</option>
                                <option value="365">1 năm</option>
                            </select>
                        </div>
                        <div id="lcni-heatmap-container">
                            <p style="color:#9ca3af;font-size:13px">⏳ Đang tải...</p>
                        </div>
                        <div id="lcni-peak-info" style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px"></div>
                    </div>

                    <!-- Daily chart -->
                    <div class="lcni-prof-box">
                        <h3>📅 Hoạt động hàng ngày</h3>
                        <canvas id="lcni-daily-chart" height="110"></canvas>
                    </div>

                    <!-- Chân dung -->
                    <div class="lcni-prof-box">
                        <h3>🪞 Chân dung khách hàng</h3>
                        <div class="lcni-portrait-grid">
                            <?php foreach ($segment['traits'] as $t): ?>
                            <div class="lcni-trait">
                                <span><?php echo $t['icon']; ?></span>
                                <div>
                                    <div style="font-size:11px;color:#9ca3af"><?php echo esc_html($t['label']); ?></div>
                                    <div style="font-size:13px;font-weight:600;color:#111827"><?php echo esc_html($t['value']); ?></div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php if (!empty($segment['recs'])): ?>
                        <div style="margin-top:14px;border-top:1px solid #f3f4f6;padding-top:12px">
                            <div style="font-size:12px;font-weight:700;color:#374151;margin-bottom:6px">💡 Gợi ý hành động</div>
                            <?php foreach ($segment['recs'] as $rec): ?>
                            <div style="font-size:12px;color:#6b7280;padding:3px 0">→ <?php echo esc_html($rec); ?></div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Event log -->
                    <div class="lcni-prof-box">
                        <h3>📋 Nhật ký hoạt động</h3>
                        <div id="lcni-event-log"><p style="color:#9ca3af;font-size:13px">⏳ Đang tải...</p></div>
                    </div>
                </div>

            </div>
        </div>
        <?php $this->render_scripts($user_id, $nonce); ?>
        <?php
    }

    // =========================================================================
    // SEGMENT
    // =========================================================================
    private function get_segment(array $rules, array $follows, int $days_active, ?array $activity): array {
        $has_real  = !empty(array_filter($rules, function($r){ return !(int)$r['is_paper']; }));
        $has_auto  = !empty(array_filter($rules, function($r){ return (int)$r['auto_order']; }));
        $has_paper = !empty(array_filter($rules, function($r){ return (int)$r['is_paper']; }));
        $fc        = count($follows);
        $rc        = count($rules);
        $total_tr  = array_sum(array_column($rules,'total_trades'));
        $total_pnl = array_sum(array_column($rules,'total_pnl_vnd'));
        $win_tr    = array_sum(array_column($rules,'win_trades'));
        $wr        = $total_tr>0?$win_tr/$total_tr:0;
        $total_r   = array_sum(array_column($rules,'total_r'));
        $active_days=(int)($activity['active_days']??0);

        if ($has_real && $has_auto && $total_pnl>0)        { $icon='🏆'; $name='Expert Trader';  $desc='Giao dịch thật, tự động, sinh lời'; }
        elseif ($has_real && $total_tr>0)                  { $icon='💼'; $name='Active Trader';   $desc='Đang giao dịch tài khoản thật'; }
        elseif ($has_paper && $total_tr>=10)               { $icon='🧪'; $name='Paper Trader';    $desc='Đang học và thử nghiệm tài khoản ảo'; }
        elseif ($fc>=3 && $rc===0)                         { $icon='👀'; $name='Observer';        $desc='Theo dõi nhiều chiến lược, chưa áp dụng'; }
        elseif ($fc>0||$rc>0)                              { $icon='🌱'; $name='Explorer';        $desc='Đang khám phá hệ thống'; }
        elseif ($days_active>7 && $active_days>0)          { $icon='💤'; $name='Inactive Trader'; $desc='Đăng nhập nhưng chưa tương tác'; }
        else                                               { $icon='😴'; $name='Inactive';        $desc='Chưa tương tác với hệ thống'; }

        $freq = $days_active>0 && $active_days>0 ? round($active_days/$days_active*100,0) : 0;

        $traits = [
            ['icon'=>'📅','label'=>'Thành viên','value'=>$days_active.' ngày'],
            ['icon'=>'🔄','label'=>'Tần suất hoạt động','value'=>$active_days>0?$freq.'% số ngày':'Chưa có dữ liệu'],
            ['icon'=>'👁','label'=>'Đang follow','value'=>$fc.' chiến lược'],
            ['icon'=>'🤖','label'=>'Auto rule','value'=>$rc.' chiến lược'],
            ['icon'=>'📊','label'=>'Tổng giao dịch','value'=>$total_tr>0?$total_tr.' lệnh':'Chưa có'],
            ['icon'=>'🎯','label'=>'Win rate','value'=>$total_tr>0?round($wr*100,1).'%':'N/A'],
            ['icon'=>'💰','label'=>'P&L','value'=>$total_tr>0?(($total_pnl>=0?'+':'').number_format($total_pnl/1000000,2).' triệu đ'):'N/A'],
            ['icon'=>'⚡','label'=>'Auto order','value'=>$has_auto?'✅ Đã kích hoạt':'❌ Chưa bật'],
        ];

        $recs = [];
        if ($fc>0&&$rc===0) $recs[]='Đang follow nhưng chưa áp dụng Auto Rule — upsell paper trade';
        if ($has_paper&&!$has_real) $recs[]='Paper trader — có thể sẵn sàng chuyển sang giao dịch thật';
        if ($has_real&&!$has_auto) $recs[]='Giao dịch thật nhưng chưa bật Auto Order — giới thiệu DNSE';
        if ($wr<0.4&&$total_tr>=10) $recs[]='Win rate thấp — gợi ý điều chỉnh risk% hoặc đổi chiến lược';
        if ($days_active>30&&$fc===0&&$rc===0) $recs[]='Lâu không tương tác — cần email re-engagement';
        if ($freq>0&&$freq<20) $recs[]='Tần suất thấp ('.($freq).'%) — cân nhắc push notification';

        return compact('icon','name','desc','traits','recs');
    }

    // =========================================================================
    // HELPERS
    // =========================================================================
    private function kpi(string $label,$value,string $color): void { ?>
        <div class="lcni-prof-kpi">
            <div style="font-size:20px;font-weight:800;color:<?php echo esc_attr($color); ?>;line-height:1.1">
                <?php echo is_numeric($value)?number_format((float)$value,0):esc_html($value); ?>
            </div>
            <div style="font-size:11px;color:#6b7280;margin-top:3px"><?php echo esc_html($label); ?></div>
        </div>
    <?php }

    // =========================================================================
    // SCRIPTS — heatmap + daily chart + event log
    // =========================================================================
    private function render_scripts(int $user_id, string $nonce): void { ?>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
        <script>
        (function(){
            var UID   = <?php echo $user_id; ?>;
            var NONCE = <?php echo wp_json_encode($nonce); ?>;
            var AJAX  = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
            var DAYS  = ['','T2','T3','T4','T5','T6','T7','CN'];
            var DNAMES= {1:'Thứ 2',2:'Thứ 3',3:'Thứ 4',4:'Thứ 5',5:'Thứ 6',6:'Thứ 7',7:'Chủ nhật'};
            var ELABELS = {
                login:'🔑 Đăng nhập', logout:'🚪 Đăng xuất',
                page_view:'👁 Xem trang', signal_view:'📈 Xem tín hiệu',
                rule_follow:'➕ Follow', rule_unfollow:'➖ Unfollow',
                rule_apply:'🤖 Áp dụng Auto Rule'
            };
            var dailyChart = null;

            function load(days) {
                document.getElementById('lcni-heatmap-container').innerHTML = '<p style="color:#9ca3af;font-size:13px">⏳ Đang tải...</p>';
                fetch(AJAX,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
                    body: new URLSearchParams({action:'lcni_user_activity_data',user_id:UID,days:days,_nonce:NONCE})})
                .then(r=>r.json()).then(d=>{
                    if (!d.success) return;
                    var dd = d.data;
                    if (!dd.has_tracking) {
                        document.getElementById('lcni-heatmap-container').innerHTML =
                            '<div style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:12px 16px;font-size:13px;color:#92400e">' +
                            '⚠️ Module theo dõi hoạt động chưa được bật. Cài đặt plugin và dữ liệu sẽ được ghi từ đây trở đi.</div>';
                        return;
                    }
                    renderHeatmap(dd.heatmap);
                    renderPeakInfo(dd.heatmap);
                    renderDaily(dd.daily, days);
                    renderEventLog(dd.events);
                });
            }

            function renderHeatmap(rows) {
                var wrap = document.getElementById('lcni-heatmap-container');
                if (!rows || !rows.length) {
                    wrap.innerHTML = '<p style="color:#9ca3af;font-size:13px">Chưa có dữ liệu hoạt động trong khoảng này.</p>';
                    return;
                }
                var matrix = {}, max = 0;
                rows.forEach(r=>{ var k=r.day_of_week+'_'+r.hour_of_day; matrix[k]=parseInt(r.cnt); if(matrix[k]>max)max=matrix[k]; });

                var html = '<div class="lcni-hm"><div class="lcni-hm-hdr"><span></span>';
                for (var h=0;h<24;h++) html += '<span>'+h+'</span>';
                html += '</div>';
                for (var dow=1;dow<=7;dow++) {
                    html += '<div class="lcni-hm-row"><span class="lcni-hm-day">'+DAYS[dow]+'</span>';
                    for (var hr=0;hr<24;hr++) {
                        var cnt=matrix[dow+'_'+hr]||0, pct=max>0?cnt/max:0;
                        var bg=pct===0?'#f3f4f6':pct<.25?'#bfdbfe':pct<.5?'#3b82f6':pct<.75?'#1d4ed8':'#1e3a8a';
                        var tt=cnt>0?DAYS[dow]+' '+hr+':00 – '+cnt+' sự kiện':'';
                        html += '<span class="lcni-hm-cell" style="background:'+bg+'" title="'+tt+'"></span>';
                    }
                    html += '</div>';
                }
                html += '</div>';
                wrap.innerHTML = html;
            }

            function renderPeakInfo(rows) {
                var wrap = document.getElementById('lcni-peak-info');
                if (!rows||!rows.length){wrap.innerHTML='';return;}
                var ht={},dt={};
                rows.forEach(r=>{ ht[r.hour_of_day]=(ht[r.hour_of_day]||0)+parseInt(r.cnt); dt[r.day_of_week]=(dt[r.day_of_week]||0)+parseInt(r.cnt); });
                var ph=Object.keys(ht).sort((a,b)=>ht[b]-ht[a])[0];
                var pd=Object.keys(dt).sort((a,b)=>dt[b]-dt[a])[0];
                wrap.innerHTML =
                    '<span class="lcni-chip">⏰ Giờ cao điểm: <strong>'+ph+':00–'+(+ph+1)+':00</strong></span>' +
                    '<span class="lcni-chip">📅 Ngày active nhất: <strong>'+(DNAMES[pd]||'N/A')+'</strong></span>';
            }

            function renderDaily(rows, days) {
                var map={};
                rows.forEach(r=>map[r.session_date]=parseInt(r.cnt));
                var labels=[],data=[];
                for (var i=days-1;i>=0;i--) {
                    var d=new Date(); d.setDate(d.getDate()-i);
                    var k=d.toISOString().slice(0,10);
                    labels.push((d.getMonth()+1)+'/'+d.getDate()); data.push(map[k]||0);
                }
                var ctx=document.getElementById('lcni-daily-chart');
                if (!ctx) return;
                if (dailyChart) dailyChart.destroy();
                dailyChart = new Chart(ctx,{type:'bar',data:{labels:labels,datasets:[{label:'Sự kiện',data:data,backgroundColor:'rgba(37,99,235,.7)',borderRadius:3}]},options:{responsive:true,plugins:{legend:{display:false}},scales:{x:{ticks:{font:{size:10},maxTicksLimit:days>60?12:20}},y:{beginAtZero:true,ticks:{stepSize:1}}}}});
            }

            function renderEventLog(events) {
                var wrap = document.getElementById('lcni-event-log');
                if (!events||!events.length) { wrap.innerHTML='<p style="color:#9ca3af;font-size:13px">Chưa có nhật ký.</p>'; return; }
                var html='<table class="lcni-prof-table"><thead><tr><th>Sự kiện</th><th>Chi tiết</th><th>Ngày</th><th>Giờ</th></tr></thead><tbody>';
                events.forEach(e=>{
                    html+='<tr><td>'+(ELABELS[e.event_type]||e.event_type)+'</td>'
                        +'<td style="color:#6b7280;font-size:12px">'+(e.event_meta||'—')+'</td>'
                        +'<td style="font-size:12px">'+e.session_date+'</td>'
                        +'<td style="font-size:12px">'+e.hour_of_day+':00</td></tr>';
                });
                html+='</tbody></table>';
                wrap.innerHTML=html;
            }

            document.getElementById('lcni-days-sel').addEventListener('change',function(){ load(parseInt(this.value)); });
            load(90);
        })();
        </script>
    <?php }

    // =========================================================================
    // STYLES
    // =========================================================================
    private function render_styles(): void { ?>
        <style>
        .lcni-profile-wrap{max-width:1300px}
        .lcni-profile-header{display:flex;align-items:flex-start;gap:18px;background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:18px 22px;margin-bottom:14px;flex-wrap:wrap}
        .lcni-profile-avatar{width:60px;height:60px;border-radius:50%;background:#2563eb;color:#fff;font-size:20px;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0}
        .lcni-profile-info{flex:1;min-width:0}
        .lcni-profile-info h1{margin:0 0 8px;font-size:20px;font-weight:800;color:#111827}
        .lcni-profile-meta{display:flex;flex-wrap:wrap;gap:10px;font-size:13px;color:#6b7280}
        .lcni-prof-badge{padding:3px 10px;border-radius:20px;color:#fff;font-size:12px;font-weight:600}
        .lcni-segment{display:flex;gap:10px;align-items:center;background:#f0f9ff;border:1px solid #bae6fd;border-radius:10px;padding:12px 16px}
        .lcni-segment__icon{font-size:26px}
        .lcni-segment__name{font-weight:700;color:#0c4a6e;font-size:14px}
        .lcni-segment__desc{font-size:12px;color:#0369a1;margin-top:1px}
        .lcni-prof-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(110px,1fr));gap:10px;margin-bottom:14px}
        .lcni-prof-kpi{background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:12px;text-align:center}
        .lcni-prof-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
        @media(max-width:1000px){.lcni-prof-grid{grid-template-columns:1fr}}
        .lcni-prof-box{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:16px 18px;margin-bottom:14px}
        .lcni-prof-box h3{margin:0 0 12px;font-size:14px;font-weight:700;color:#111827}
        .lcni-prof-table{width:100%;border-collapse:collapse;font-size:13px}
        .lcni-prof-table th{background:#f9fafb;padding:7px 9px;text-align:left;font-weight:600;color:#374151;border-bottom:2px solid #e5e7eb}
        .lcni-prof-table td{padding:7px 9px;border-bottom:1px solid #f3f4f6;vertical-align:middle}
        .lcni-ur-card{border:1px solid #e5e7eb;border-radius:8px;padding:12px;margin-bottom:10px}
        .lcni-badge-sm{padding:2px 8px;border-radius:12px;font-size:11px;font-weight:600}
        .lcni-ur-perf{display:grid;grid-template-columns:repeat(6,1fr);border-top:1px solid #f3f4f6;padding-top:8px;margin-top:6px}
        .lcni-ur-stat{text-align:center;padding:5px 2px}
        .lcni-ur-stat span:first-child{display:block;font-size:14px;font-weight:700}
        .lcni-ur-stat span:last-child{display:block;font-size:10px;color:#9ca3af;margin-top:1px}
        .lcni-muted-txt{color:#9ca3af;font-size:13px;margin:0}
        .lcni-portrait-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
        .lcni-trait{display:flex;gap:8px;align-items:flex-start}
        .lcni-chip{background:#f3f4f6;padding:4px 10px;border-radius:20px;font-size:12px;color:#374151}
        code{font-size:11px;background:#f3f4f6;padding:1px 5px;border-radius:3px}
        /* Heatmap */
        .lcni-hm{display:flex;flex-direction:column;gap:2px}
        .lcni-hm-hdr,.lcni-hm-row{display:flex;gap:2px;align-items:center}
        .lcni-hm-hdr span{width:16px;height:12px;font-size:9px;text-align:center;color:#9ca3af;flex-shrink:0}
        .lcni-hm-hdr span:first-child{width:22px}
        .lcni-hm-day{width:22px;font-size:11px;color:#6b7280;text-align:right;flex-shrink:0}
        .lcni-hm-cell{width:16px;height:16px;border-radius:2px;flex-shrink:0;cursor:default}
        </style>
    <?php }
}
endif;
