<?php

if (!defined('ABSPATH')) {
    exit;
}

class ShortcodeManager {
    private const VIETNAMESE_COLUMN_LABELS = [
        'signal__symbol' => 'Mã CP',
        'rule__name' => 'Tên chiến lược',
        'signal__entry_price' => 'Giá mua',
        'signal__status' => 'Trạng thái',
        'signal__entry_time' => 'Thời điểm mua',
        'signal__current_price' => 'Giá hiện tại',
        'signal__initial_sl' => 'Cắt lỗ ban đầu',
        'signal__risk_per_share' => 'Rủi ro / cổ phiếu',
        'signal__r_multiple' => 'Bội số R',
        'signal__position_state' => 'Tình trạng vị thế',
        'signal__exit_price' => 'Giá bán',
        'signal__exit_time' => 'Thời điểm bán',
        'signal__final_r' => 'R cuối cùng',
        'signal__holding_days' => 'Số ngày nắm giữ',
        'signal__exit_reason' => 'Lý do thoát',
        'market__exchange' => 'Sàn',
        'icb2__name_icb2' => 'Ngành ICB2',
        'signal__npl_current' => 'NPL hiện tại (%)',
        'signal__npl_closed' => 'NPL đã chốt (%)',
    ];

    private $signal_repository;
    private $performance_calculator;
    private $position_engine;
    private static $signals_assets_printed = false;

    public function __construct(SignalRepository $signal_repository, PerformanceCalculator $performance_calculator, PositionEngine $position_engine) {
        $this->signal_repository = $signal_repository;
        $this->performance_calculator = $performance_calculator;
        $this->position_engine = $position_engine;

        add_action('init', [$this, 'register_shortcodes']);
        add_action('wp_ajax_lcni_public_equity_curve', [$this, 'ajax_public_equity_curve']);
        add_action('wp_ajax_nopriv_lcni_public_equity_curve', [$this, 'ajax_public_equity_curve']);
    }

    public function register_shortcodes() {
        add_shortcode('lcni_signals', [$this, 'render_signals']);
        add_shortcode('lcni_signals_rule', [$this, 'render_signals_rule']);
        add_shortcode('lcni_performance', [$this, 'render_performance']);
        add_shortcode('lcni_performance_v2', [$this, 'render_performance_v2']);
        add_shortcode('lcni_equity_curve', [$this, 'render_equity_curve']);
        add_shortcode('lcni_signal', [$this, 'render_signal_card']);
    }

    public function render_signals($atts = []) {
        $atts = shortcode_atts(['rule_id' => 0, 'status' => '', 'limit' => 200, 'symbol' => ''], $atts, 'lcni_signals');
        $frontend_settings = $this->get_recommend_signal_frontend_settings();
        $columns = (array) ($frontend_settings['column_order'] ?? []);

        $rows = $this->signal_repository->list_signals([
            'rule_id' => $atts['rule_id'],
            'status' => $atts['status'],
            'limit' => $atts['limit'],
            'symbol' => $atts['symbol'],
            'selected_columns' => $columns,
        ]);

        $catalog = $this->signal_repository->get_recommend_column_catalog();
        $styles = (array) ($frontend_settings['styles'] ?? []);
        $stock_detail_base_url = $this->resolve_stock_detail_base_url();
        $watchlist_rest_base = esc_url_raw(rest_url('lcni/v1/watchlist'));
        $filter_login_page_id = absint(get_option('lcni_filter_login_page_id', 0));
        $filter_register_page_id = absint(get_option('lcni_filter_register_page_id', 0));
        $login_url = $filter_login_page_id > 0 ? get_permalink($filter_login_page_id) : '';
        $register_url = $filter_register_page_id > 0 ? get_permalink($filter_register_page_id) : '';
        if (!is_string($login_url) || $login_url === '') {
            $login_url = wp_login_url(get_permalink() ?: home_url('/'));
        }
        if (!is_string($register_url) || $register_url === '') {
            $register_url = function_exists('wp_registration_url') ? wp_registration_url() : wp_login_url();
        }
        $value_background = (string) ($styles['value_background'] ?? '#ffffff');
        $value_text_color = (string) ($styles['value_text_color'] ?? '#111827');
        $row_hover_background = (string) ($styles['row_hover_bg'] ?? '#f3f4f6');
        $sticky_column = (string) ($styles['sticky_column'] ?? 'signal__symbol');
        $sticky_header_enabled = !empty($styles['sticky_header']);
        $filter_button_color = (string) ($styles['filter_button_color'] ?? '#374151');
        $filter_button_background = (string) ($styles['filter_button_background'] ?? '#ffffff');
        $filter_button_height = (int) ($styles['filter_button_height'] ?? 28);
        $filter_button_font_size = (int) ($styles['filter_button_font_size'] ?? 14);
        $filter_button_icon = $this->sanitize_icon_class((string) ($styles['filter_button_icon'] ?? 'fa-solid fa-filter'), 'fa-solid fa-filter');
        $watchlist_button_color = (string) ($styles['watchlist_button_color'] ?? '#dc2626');
        $watchlist_button_active_color = (string) ($styles['watchlist_button_active_color'] ?? '#16a34a');
        $watchlist_button_height = (int) ($styles['watchlist_button_height'] ?? 28);
        $watchlist_button_font_size = (int) ($styles['watchlist_button_font_size'] ?? 15);
        $watchlist_button_icon = $this->sanitize_icon_class((string) ($styles['watchlist_button_icon'] ?? 'fa-solid fa-heart'), 'fa-solid fa-heart');
        $watchlist_button_active_icon = $this->sanitize_icon_class((string) ($styles['watchlist_button_active_icon'] ?? 'fa-solid fa-check'), 'fa-solid fa-check');
        $filter_panel_button_height = (int) ($styles['filter_panel_button_height'] ?? 32);
        $filter_panel_button_font_size = (int) ($styles['filter_panel_button_font_size'] ?? 14);
        $filter_apply_button_color = (string) ($styles['filter_apply_button_color'] ?? '#ffffff');
        $filter_apply_button_background = (string) ($styles['filter_apply_button_background'] ?? '#2563eb');
        $filter_apply_button_hover_background = (string) ($styles['filter_apply_button_hover_background'] ?? '#1d4ed8');
        $filter_apply_button_icon = $this->sanitize_icon_class((string) ($styles['filter_apply_button_icon'] ?? 'fa-solid fa-check'), 'fa-solid fa-check');
        $filter_apply_button_label = sanitize_text_field((string) ($styles['filter_apply_button_label'] ?? 'Apply'));
        $filter_clear_button_color = (string) ($styles['filter_clear_button_color'] ?? '#111827');
        $filter_clear_button_background = (string) ($styles['filter_clear_button_background'] ?? '#e5e7eb');
        $filter_clear_button_hover_background = (string) ($styles['filter_clear_button_hover_background'] ?? '#d1d5db');
        $filter_clear_button_icon = $this->sanitize_icon_class((string) ($styles['filter_clear_button_icon'] ?? 'fa-solid fa-eraser'), 'fa-solid fa-eraser');
        $filter_clear_button_label = sanitize_text_field((string) ($styles['filter_clear_button_label'] ?? 'Clear'));
        $filter_panel_button_border = sanitize_text_field((string) ($styles['filter_panel_button_border'] ?? '1px solid #9ca3af'));
        $filter_panel_button_border_radius = (int) ($styles['filter_panel_button_border_radius'] ?? 6);
        $table_max_height = (int) ($styles['table_max_height'] ?? 560);
        $wrapper_style = sprintf('font-family:%s;color:%s;background:%s;border:%s;border-radius:%dpx;overflow:visible;max-width:100%%;position:relative;isolation:isolate;--lcni-signals-filter-btn-color:%s;--lcni-signals-filter-btn-bg:%s;--lcni-signals-watchlist-btn-color:%s;--lcni-signals-watchlist-btn-active-color:%s;--lcni-signals-filter-btn-height:%dpx;--lcni-signals-filter-btn-font-size:%dpx;--lcni-signals-watchlist-btn-height:%dpx;--lcni-signals-watchlist-btn-font-size:%dpx;--lcni-signals-panel-btn-height:%dpx;--lcni-signals-panel-btn-font-size:%dpx;--lcni-signals-apply-btn-bg:%s;--lcni-signals-apply-btn-color:%s;--lcni-signals-apply-btn-hover-bg:%s;--lcni-signals-clear-btn-bg:%s;--lcni-signals-clear-btn-color:%s;--lcni-signals-clear-btn-hover-bg:%s;--lcni-signals-panel-btn-border:%s;--lcni-signals-panel-btn-radius:%dpx;',
            esc_attr((string) ($styles['font'] ?? 'inherit')),
            esc_attr((string) ($styles['text_color'] ?? '#111827')),
            esc_attr((string) ($styles['background'] ?? '#ffffff')),
            esc_attr((string) ($styles['border'] ?? '1px solid #e5e7eb')),
            (int) ($styles['border_radius'] ?? 8),
            esc_attr($filter_button_color),
            esc_attr($filter_button_background),
            esc_attr($watchlist_button_color),
            esc_attr($watchlist_button_active_color),
            $filter_button_height,
            $filter_button_font_size,
            $watchlist_button_height,
            $watchlist_button_font_size,
            $filter_panel_button_height,
            $filter_panel_button_font_size,
            esc_attr($filter_apply_button_background),
            esc_attr($filter_apply_button_color),
            esc_attr($filter_apply_button_hover_background),
            esc_attr($filter_clear_button_background),
            esc_attr($filter_clear_button_color),
            esc_attr($filter_clear_button_hover_background),
            esc_attr($filter_panel_button_border),
            $filter_panel_button_border_radius
        );

        $button_configs = [
            'btn_filter_watchlist_login' => LCNI_Button_Style_Config::get_button('btn_filter_watchlist_login'),
            'btn_filter_watchlist_register' => LCNI_Button_Style_Config::get_button('btn_filter_watchlist_register'),
            'btn_popup_confirm' => LCNI_Button_Style_Config::get_button('btn_popup_confirm'),
            'btn_popup_close' => LCNI_Button_Style_Config::get_button('btn_popup_close'),
        ];

        ob_start();
        echo '<div class="lcni-recommend-signals-table" data-lcni-signals-table data-watchlist-rest-base="' . esc_attr($watchlist_rest_base) . '" data-login-url="' . esc_url($login_url) . '" data-register-url="' . esc_url($register_url) . '" data-is-logged-in="' . (is_user_logged_in() ? '1' : '0') . '" data-rest-nonce="' . esc_attr(wp_create_nonce('wp_rest')) . '" data-watchlist-icon="' . esc_attr($watchlist_button_icon) . '" data-watchlist-active-icon="' . esc_attr($watchlist_button_active_icon) . '" data-filter-apply-icon="' . esc_attr($filter_apply_button_icon) . '" data-filter-clear-icon="' . esc_attr($filter_clear_button_icon) . '" data-filter-apply-label="' . esc_attr($filter_apply_button_label) . '" data-filter-clear-label="' . esc_attr($filter_clear_button_label) . '" data-button-config="' . esc_attr(wp_json_encode($button_configs)) . '" style="' . $wrapper_style . '">';
        // lcni-table-wrapper: scroll container cho sticky header + sticky column + mobile scroll
        $table_wrapper_style = 'width:100%;overflow-x:auto;overflow-y:auto;max-height:' . $table_max_height . 'px;-webkit-overflow-scrolling:touch;overscroll-behavior:contain;position:relative;';
        echo '<div class="lcni-table-wrapper" style="' . $table_wrapper_style . '">';
        echo '<table class="lcni-table" style="width:100%;border-collapse:separate;border-spacing:0;font-size:' . (int) ($styles['row_font_size'] ?? 14) . 'px;">';
        $head_row_style = 'height:' . (int) ($styles['head_height'] ?? 30) . 'px;background:' . esc_attr((string) ($styles['header_background'] ?? '#ffffff')) . ';color:' . esc_attr((string) ($styles['header_text_color'] ?? '#111827')) . ';';
        echo '<thead><tr style="' . $head_row_style . '">';
        foreach ($columns as $column) {
            $label = $this->resolve_column_label($column, $catalog);
            $th_style = 'text-align:left;padding:8px;border-bottom:' . (int) ($styles['row_divider_width'] ?? 1) . 'px solid ' . esc_attr((string) ($styles['row_divider_color'] ?? '#e5e7eb')) . ';font-size:' . (int) ($styles['header_font_size'] ?? 14) . 'px;background:' . esc_attr((string) ($styles['header_background'] ?? '#ffffff')) . ';white-space:nowrap;line-height:1.2;';
            if ($sticky_header_enabled) {
                $th_style .= 'position:sticky;top:0;z-index:20;';
            }
            if ($sticky_column === $column) {
                $th_style .= 'position:sticky;left:0;z-index:' . ($sticky_header_enabled ? '25' : '5') . ';';
            }
            echo '<th style="' . $th_style . '" data-lcni-field="' . esc_attr($column) . '"><span>' . esc_html($label) . '</span><button type="button" class="lcni-signals-filter-btn" data-lcni-filter-btn aria-label="Lọc nhanh"><i class="' . esc_attr($filter_button_icon) . '" aria-hidden="true"></i></button></th>';
        }
        echo '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $row_symbol = strtoupper(sanitize_text_field((string) ($row['signal__symbol'] ?? '')));
            $row_url = $this->build_stock_detail_url($stock_detail_base_url, $row_symbol);
            echo '<tr class="lcni-signal-row" data-lcni-row-symbol="' . esc_attr($row_symbol) . '" data-lcni-row-url="' . esc_url($row_url) . '" style="background:' . esc_attr($value_background) . ';color:' . esc_attr($value_text_color) . ';">';
            foreach ($columns as $column) {
                $value = isset($row[$column]) ? $row[$column] : '';
                $raw_value = $value;
                $value = $this->format_signal_value($column, $value);
                $cell_style = $this->resolve_recommend_signal_cell_style($column, $raw_value, $styles);
                $cell_style_attr = 'padding:8px;border-bottom:' . (int) ($styles['row_divider_width'] ?? 1) . 'px solid ' . esc_attr((string) ($styles['row_divider_color'] ?? '#e5e7eb')) . ';white-space:nowrap;line-height:1.2;';
                if ($cell_style['background'] !== '') {
                    $cell_style_attr .= 'background:' . esc_attr($cell_style['background']) . ';';
                }
                if ($cell_style['color'] !== '') {
                    $cell_style_attr .= 'color:' . esc_attr($cell_style['color']) . ';';
                }
                if ($sticky_column === $column) {
                    $cell_bg = $cell_style['background'] !== '' ? (string) $cell_style['background'] : $value_background;
                    $cell_style_attr .= 'position:sticky;left:0;z-index:15;background:' . esc_attr($cell_bg) . ';';
                }

                if ($column === 'signal__symbol') {
                    $symbol = strtoupper(sanitize_text_field((string) $raw_value));
                    $detail_url = $this->build_stock_detail_url($stock_detail_base_url, $symbol);
                    $watchlist_btn = '<button type="button" class="lcni-signals-watchlist-btn" data-lcni-watchlist-add data-symbol="' . esc_attr($symbol) . '" aria-label="Thêm vào watchlist"><i class="' . esc_attr($watchlist_button_icon) . '" aria-hidden="true"></i></button>';
                    if ($detail_url !== '') {
                        $value = '<a href="' . esc_url($detail_url) . '">' . esc_html((string) $value) . '</a>';
                        echo '<td data-lcni-field="' . esc_attr($column) . '" data-lcni-value="' . esc_attr((string) $raw_value) . '" style="' . $cell_style_attr . '"><span class="lcni-signals-symbol-cell">' . $value . $watchlist_btn . '</span></td>';
                        continue;
                    }
                    echo '<td data-lcni-field="' . esc_attr($column) . '" data-lcni-value="' . esc_attr((string) $raw_value) . '" style="' . $cell_style_attr . '"><span class="lcni-signals-symbol-cell">' . esc_html((string) $value) . $watchlist_btn . '</span></td>';
                    continue;
                }

                echo '<td data-lcni-field="' . esc_attr($column) . '" data-lcni-value="' . esc_attr((string) $raw_value) . '" style="' . $cell_style_attr . '">' . esc_html((string) $value) . '</td>';
            }
            echo '</tr>';
        }

        echo '</tbody></table></div></div>';  // close table, lcni-table-wrapper, lcni-recommend-signals-table
        echo $this->render_signals_table_assets();

        return ob_get_clean();
    }

    private function render_signals_table_assets() {
        if (self::$signals_assets_printed) {
            return '';
        }
        self::$signals_assets_printed = true;

        return <<<'HTML'
<style>
.lcni-signals-filter-btn{margin-left:6px;border:0;background:var(--lcni-signals-filter-btn-bg,#ffffff);cursor:pointer;color:var(--lcni-signals-filter-btn-color,#374151);padding:0 8px;height:var(--lcni-signals-filter-btn-height,28px);min-width:var(--lcni-signals-filter-btn-height,28px);border-radius:6px;font-size:var(--lcni-signals-filter-btn-font-size,14px);display:inline-flex;align-items:center;justify-content:center}
.lcni-signals-filter-pop{position:fixed;z-index:999999;background:#fff;border:1px solid #d1d5db;border-radius:8px;padding:10px;min-width:240px;max-width:360px;max-height:min(65vh,420px);overflow:auto;box-shadow:0 10px 30px rgba(17,24,39,.2)}
.lcni-signals-filter-pop-title{display:block;font-size:15px}
.lcni-signals-filter-search{width:100%;box-sizing:border-box;height:34px;padding:0 10px;margin:8px 0;border:1px solid #d1d5db;border-radius:6px}
.lcni-signals-filter-hint{font-size:12px;color:#6b7280;margin:0 0 6px}
.lcni-signals-filter-pop [data-lcni-values]{display:grid;gap:4px;max-height:240px;overflow:auto;margin:8px 0}
.lcni-signals-filter-actions{display:flex;gap:8px;justify-content:flex-end}
.lcni-signals-filter-actions .lcni-btn{height:var(--lcni-signals-panel-btn-height,32px);font-size:var(--lcni-signals-panel-btn-font-size,14px);padding:0 12px;display:inline-flex;align-items:center;gap:6px;border:var(--lcni-signals-panel-btn-border,1px solid #9ca3af);border-radius:var(--lcni-signals-panel-btn-radius,6px)}
.lcni-signals-filter-actions [data-lcni-clear]{background:var(--lcni-signals-clear-btn-bg,#e5e7eb);color:var(--lcni-signals-clear-btn-color,#111827)}
.lcni-signals-filter-actions [data-lcni-clear]:hover{background:var(--lcni-signals-clear-btn-hover-bg,#d1d5db)}
.lcni-signals-filter-actions [data-lcni-apply]{background:var(--lcni-signals-apply-btn-bg,#2563eb);color:var(--lcni-signals-apply-btn-color,#ffffff)}
.lcni-signals-filter-actions [data-lcni-apply]:hover{background:var(--lcni-signals-apply-btn-hover-bg,#1d4ed8)}
.lcni-signals-symbol-cell{display:flex;gap:8px;align-items:center}
.lcni-signals-watchlist-btn{border:0;background:transparent;cursor:pointer;color:var(--lcni-signals-watchlist-btn-color,#dc2626);padding:0;height:var(--lcni-signals-watchlist-btn-height,28px);min-width:var(--lcni-signals-watchlist-btn-height,28px);display:inline-flex;align-items:center;justify-content:center;font-size:var(--lcni-signals-watchlist-btn-font-size,15px)}
.lcni-signals-watchlist-btn.is-active{color:var(--lcni-signals-watchlist-btn-active-color,#16a34a)}
.lcni-signals-modal{position:fixed;inset:0;background:rgba(17,24,39,.45);display:flex;align-items:center;justify-content:center;z-index:999999}
.lcni-signals-modal-card{background:#fff;border-radius:10px;padding:14px;width:min(92vw,420px)}
.lcni-signals-modal-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:10px}
</style>
<script>
(function(){
  if(window.__lcniSignalsTableInit) return;
  window.__lcniSignalsTableInit = true;
  const esc=(v)=>String(v==null?'':v).replace(/[&<>"']/g,(m)=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));
  const getButtonConfig=(host,key)=>{try{const cfg=JSON.parse(host.dataset.buttonConfig||'{}');return cfg&&typeof cfg==='object'?(cfg[key]||{}):{}}catch(err){return {}}};
  const renderButtonContent=(host,key,fallbackLabel)=>{const cfg=getButtonConfig(host,key);const icon=String(cfg.icon_class||'').trim();const label=String((cfg.label_text||'')).trim()||String(fallbackLabel||'');const pos=String(cfg.icon_position||'left')==='right'?'right':'left';const iconHtml=icon?'<i class="'+esc(icon)+'" aria-hidden="true"></i>':'';const textHtml=label?'<span>'+esc(label)+'</span>':'';if(icon&&pos==='right')return textHtml+iconHtml;return iconHtml+textHtml;};
  const toast=(m)=>{const n=document.createElement('div');n.className='lcni-watchlist-toast';n.textContent=m;document.body.appendChild(n);setTimeout(()=>n.remove(),2400)};
  const closeModal=()=>{const n=document.querySelector('.lcni-signals-modal');if(n)n.remove()};
  const showModal=(html)=>{closeModal();const n=document.createElement('div');n.className='lcni-signals-modal';n.innerHTML='<div class="lcni-signals-modal-card">'+html+'</div>';n.addEventListener('click',(e)=>{if(e.target===n||e.target.closest('[data-lcni-close]'))closeModal()});document.body.appendChild(n)};
  const watchlistApi=(host,path,opt)=>fetch((host.dataset.watchlistRestBase||'').replace(/\/$/,'')+path,{method:(opt&&opt.method)||'GET',headers:{'Content-Type':'application/json','X-WP-Nonce':host.dataset.restNonce||''},credentials:'same-origin',body:opt&&opt.body?JSON.stringify(opt.body):undefined}).then(async(r)=>{const p=await r.json().catch(()=>({}));if(!r.ok)throw p;return p&&typeof p==='object'&&Object.prototype.hasOwnProperty.call(p,'data')?p.data:p});
  const setButtonState=(btn)=>{const icon=btn.querySelector('i');if(!icon)return;icon.className=btn.classList.contains('is-active')?(btn.closest('[data-lcni-signals-table]')?.dataset.watchlistActiveIcon||'fa-solid fa-check'):(btn.closest('[data-lcni-signals-table]')?.dataset.watchlistIcon||'fa-solid fa-heart')};
  const isNumericValues=(values)=>values.length>0&&values.every((v)=>{const n=Number(String(v).replace(/,/g,''));return Number.isFinite(n);});

  const openWatchlist=(host,symbol,btn)=>{
    if(host.dataset.isLoggedIn!=='1'){
      showModal('<h3>Vui lòng đăng nhập hoặc đăng ký để thêm vào watchlist</h3><div class="lcni-signals-modal-actions"><a class="lcni-btn lcni-btn-btn_filter_watchlist_login" href="'+esc(host.dataset.loginUrl||'#')+'">'+renderButtonContent(host,'btn_filter_watchlist_login','Login')+'</a><a class="lcni-btn lcni-btn-btn_filter_watchlist_register" href="'+esc(host.dataset.registerUrl||host.dataset.loginUrl||'#')+'">'+renderButtonContent(host,'btn_filter_watchlist_register','Register')+'</a><button type="button" class="lcni-btn lcni-btn-btn_popup_close" data-lcni-close>'+renderButtonContent(host,'btn_popup_close','Close')+'</button></div>');
      return;
    }

    watchlistApi(host,'/list?device=desktop').then((data)=>{
      const lists=Array.isArray(data.watchlists)?data.watchlists:[];
      const active=Number(data.active_watchlist_id||0);

      if(!lists.length){
        showModal('<h3>Tạo watchlist mới cho '+esc(symbol)+'</h3><form data-lcni-create><input type="text" name="name" placeholder="Tên watchlist" required><div class="lcni-signals-modal-actions"><button class="lcni-btn lcni-btn-btn_popup_confirm" type="submit">'+renderButtonContent(host,'btn_popup_confirm','+ New')+'</button><button class="lcni-btn lcni-btn-btn_popup_close" type="button" data-lcni-close>'+renderButtonContent(host,'btn_popup_close','Close')+'</button></div></form>');
        const form=document.querySelector('[data-lcni-create]');
        if(form) form.addEventListener('submit',(e)=>{e.preventDefault();const name=String((new FormData(form)).get('name')||'').trim();if(!name)return;watchlistApi(host,'/create',{method:'POST',body:{name}}).then((c)=>{const id=Number(c.id||0);if(!id)throw new Error('Không thể tạo watchlist');return watchlistApi(host,'/add-symbol',{method:'POST',body:{symbol,watchlist_id:id}})}).then(()=>{btn.classList.add('is-active');setButtonState(btn);closeModal();toast('Đã thêm mã '+symbol+' vào watchlist')}).catch((err)=>toast((err&&err.message)||'Không thể thêm vào watchlist'));},{once:true});
        return;
      }

      showModal('<h3>Chọn watchlist cho '+esc(symbol)+'</h3><form data-lcni-pick><div class="lcni-filter-watchlist-options">'+lists.map((w)=>'<label><input type="radio" name="watchlist_id" value="'+Number(w.id||0)+'" '+(Number(w.id||0)===active?'checked':'')+'> '+esc(w.name||'')+'</label>').join('')+'</div><div class="lcni-signals-modal-actions"><button class="lcni-btn lcni-btn-btn_popup_confirm" type="submit">'+renderButtonContent(host,'btn_popup_confirm','Confirm')+'</button><button class="lcni-btn lcni-btn-btn_popup_close" type="button" data-lcni-close>'+renderButtonContent(host,'btn_popup_close','Close')+'</button></div></form>');
      const form=document.querySelector('[data-lcni-pick]');
      if(form) form.addEventListener('submit',(e)=>{e.preventDefault();const sel=form.querySelector('input[name="watchlist_id"]:checked');const id=Number(sel?sel.value:0);if(!id)return;watchlistApi(host,'/add-symbol',{method:'POST',body:{symbol,watchlist_id:id}}).then(()=>{btn.classList.add('is-active');setButtonState(btn);closeModal();toast('Đã thêm mã '+symbol+' vào watchlist')}).catch((err)=>toast((err&&err.message)||'Không thể thêm vào watchlist'));},{once:true});
    }).catch((err)=>toast((err&&err.message)||'Không thể tải watchlist'));
  };

  const applyFilters=(host)=>{
    const active=host.__lcniFilters||{};
    host.querySelectorAll('tbody tr').forEach((row)=>{
      let ok=true;
      Object.keys(active).forEach((field)=>{if(!ok)return;const values=active[field]||[];if(!values.length)return;const cell=row.querySelector('td[data-lcni-field="'+field+'"]');const v=String(cell?cell.dataset.lcniValue||'':'');if(!values.includes(v))ok=false;});
      row.style.display=ok?'':'none';
    });
  };

  const closeAllPopups=()=>document.querySelectorAll('.lcni-signals-filter-pop').forEach((n)=>n.remove());

  document.addEventListener('click',(e)=>{
    const watchBtn=e.target.closest('[data-lcni-watchlist-add]');
    if(watchBtn){const host=watchBtn.closest('[data-lcni-signals-table]');if(!host)return;e.preventDefault();e.stopPropagation();openWatchlist(host,String(watchBtn.dataset.symbol||'').toUpperCase(),watchBtn);return;}

    const filterBtn=e.target.closest('[data-lcni-filter-btn]');
    if(filterBtn){
      e.preventDefault();e.stopPropagation();
      const host=filterBtn.closest('[data-lcni-signals-table]');if(!host)return;
      const th=filterBtn.closest('th[data-lcni-field]');const field=th?th.dataset.lcniField:'';if(!field)return;
      const values=[...new Set(Array.from(host.querySelectorAll('tbody td[data-lcni-field="'+field+'"]')).map((n)=>String(n.dataset.lcniValue||'')).filter(Boolean))].sort((a,b)=>{const na=Number(a);const nb=Number(b);if(Number.isFinite(na)&&Number.isFinite(nb))return na-nb;return String(a).localeCompare(String(b),'vi');});
      closeAllPopups();
      const pop=document.createElement('div');
      pop.className='lcni-signals-filter-pop';
      const selected=((host.__lcniFilters||{})[field]||values).slice();
      const selectedSet=new Set(selected);
      const numericMode=isNumericValues(values);
      const title=esc((th.querySelector('span')||{}).textContent||field);
      const applyIcon=esc(host.dataset.filterApplyIcon||'fa-solid fa-check');
      const clearIcon=esc(host.dataset.filterClearIcon||'fa-solid fa-eraser');
      const applyLabel=esc(host.dataset.filterApplyLabel||'Apply');
      const clearLabel=esc(host.dataset.filterClearLabel||'Clear');
      pop.innerHTML='<strong class="lcni-signals-filter-pop-title">Lọc '+title+'</strong><p class="lcni-signals-filter-hint">Kiểu lọc: '+(numericMode?'Số':'Text')+'</p><input type="search" class="lcni-signals-filter-search" placeholder="Tìm nhanh giá trị..." data-lcni-filter-search><div data-lcni-values></div><div class="lcni-signals-filter-actions"><button type="button" class="lcni-btn" data-lcni-clear><i class="'+clearIcon+'" aria-hidden="true"></i><span>'+clearLabel+'</span></button><button type="button" class="lcni-btn" data-lcni-apply><i class="'+applyIcon+'" aria-hidden="true"></i><span>'+applyLabel+'</span></button></div>';
      document.body.appendChild(pop);
      const valuesWrap=pop.querySelector('[data-lcni-values]');
      const searchInput=pop.querySelector('[data-lcni-filter-search]');
      const renderValues=(keyword)=>{
        const q=String(keyword||'').trim().toLowerCase();
        const filtered=values.filter((v)=>!q||String(v).toLowerCase().includes(q));
        valuesWrap.innerHTML=filtered.map((v)=>'<label><input type="checkbox" value="'+esc(v)+'" '+(selectedSet.has(v)?'checked':'')+'> '+esc(v)+'</label>').join('') || '<em>Không có giá trị phù hợp</em>';
      };
      renderValues('');
      if(searchInput){searchInput.addEventListener('input',()=>renderValues(searchInput.value));}
      const rect=filterBtn.getBoundingClientRect();
      pop.style.top=(rect.bottom+6)+'px';
      pop.style.left=Math.max(8,Math.min(window.innerWidth-pop.offsetWidth-8,rect.left))+'px';
      pop.addEventListener('click',(ev)=>{
        if(ev.target.closest('[data-lcni-clear]')){selectedSet.clear();host.__lcniFilters=host.__lcniFilters||{};delete host.__lcniFilters[field];applyFilters(host);pop.remove();return;}
        if(ev.target.closest('[data-lcni-apply]')){const selectedValues=Array.from(pop.querySelectorAll('input[type="checkbox"]:checked')).map((n)=>n.value);host.__lcniFilters=host.__lcniFilters||{};if(!selectedValues.length||selectedValues.length===values.length)delete host.__lcniFilters[field];else host.__lcniFilters[field]=selectedValues;applyFilters(host);pop.remove();}
      });
      return;
    }

    if(!e.target.closest('.lcni-signals-filter-pop')) closeAllPopups();

    // Row click: only navigate when clicking directly on the symbol link <a>
    // Price cells (signal__entry_price, signal__current_price, signal__exit_price)
    // are handled by LCNITransactionController's delegated click listener.
    // Other row areas do nothing (no full-row navigation).
    const symbolLink=e.target.closest('td[data-lcni-field="signal__symbol"] a');
    if(symbolLink) return; // let <a href> navigate naturally — no need to intercept
  });
})();
</script>
HTML;
    }



    public function render_signals_rule($atts = []) {
        $atts = shortcode_atts(['rule_id' => 0, 'status' => '', 'limit' => 200, 'symbol' => ''], $atts, 'lcni_signals_rule');
        $atts['rule_id'] = (int) $atts['rule_id'];
        if ($atts['rule_id'] <= 0) {
            return '';
        }

        return $this->render_signals($atts);
    }

    private function get_recommend_signal_frontend_settings() {
        $saved = get_option('lcni_frontend_settings_recommend_signal', []);
        if (!is_array($saved)) {
            $saved = [];
        }

        $columns = isset($saved['column_order']) && is_array($saved['column_order']) ? array_values(array_map('sanitize_key', $saved['column_order'])) : [];
        if (empty($columns)) {
            $columns = ['signal__symbol', 'rule__name', 'signal__entry_price', 'signal__current_price', 'signal__r_multiple', 'signal__position_state', 'signal__status'];
        }

        $styles = isset($saved['styles']) && is_array($saved['styles']) ? $saved['styles'] : [];

        return [
            'column_order' => $columns,
            'styles' => [
                'font' => sanitize_text_field($styles['font'] ?? 'inherit'),
                'text_color' => sanitize_hex_color($styles['text_color'] ?? '#111827') ?: '#111827',
                'background' => sanitize_hex_color($styles['background'] ?? '#ffffff') ?: '#ffffff',
                'border' => sanitize_text_field($styles['border'] ?? '1px solid #e5e7eb'),
                'border_radius' => max(0, min(24, (int) ($styles['border_radius'] ?? 8))),
                'header_font_size' => max(10, min(30, (int) ($styles['header_font_size'] ?? 14))),
                'row_font_size' => max(10, min(30, (int) ($styles['row_font_size'] ?? 14))),
                'header_background' => sanitize_hex_color($styles['header_background'] ?? '#ffffff') ?: '#ffffff',
                'header_text_color' => sanitize_hex_color($styles['header_text_color'] ?? '#111827') ?: '#111827',
                'value_background' => sanitize_hex_color($styles['value_background'] ?? '#ffffff') ?: '#ffffff',
                'value_text_color' => sanitize_hex_color($styles['value_text_color'] ?? '#111827') ?: '#111827',
                'row_divider_color' => sanitize_hex_color($styles['row_divider_color'] ?? '#e5e7eb') ?: '#e5e7eb',
                'row_divider_width' => max(1, min(6, (int) ($styles['row_divider_width'] ?? 1))),
                'head_height' => max(24, min(120, (int) ($styles['head_height'] ?? 30))),
                'sticky_column' => in_array(sanitize_key((string) ($styles['sticky_column'] ?? 'signal__symbol')), $columns, true)
                    ? sanitize_key((string) ($styles['sticky_column'] ?? 'signal__symbol'))
                    : ($columns[0] ?? 'signal__symbol'),
                'sticky_header' => !empty($styles['sticky_header']) ? 1 : 0,
                'filter_button_color' => sanitize_hex_color($styles['filter_button_color'] ?? '#374151') ?: '#374151',
                'filter_button_background' => sanitize_hex_color($styles['filter_button_background'] ?? '#ffffff') ?: '#ffffff',
                'filter_button_height' => max(20, min(60, (int) ($styles['filter_button_height'] ?? 28))),
                'filter_button_font_size' => max(10, min(24, (int) ($styles['filter_button_font_size'] ?? 14))),
                'filter_button_icon' => sanitize_text_field((string) ($styles['filter_button_icon'] ?? 'fa-solid fa-filter')),
                'watchlist_button_color' => sanitize_hex_color($styles['watchlist_button_color'] ?? '#dc2626') ?: '#dc2626',
                'watchlist_button_active_color' => sanitize_hex_color($styles['watchlist_button_active_color'] ?? '#16a34a') ?: '#16a34a',
                'watchlist_button_height' => max(20, min(60, (int) ($styles['watchlist_button_height'] ?? 28))),
                'watchlist_button_font_size' => max(10, min(24, (int) ($styles['watchlist_button_font_size'] ?? 15))),
                'watchlist_button_icon' => sanitize_text_field((string) ($styles['watchlist_button_icon'] ?? 'fa-solid fa-heart')),
                'watchlist_button_active_icon' => sanitize_text_field((string) ($styles['watchlist_button_active_icon'] ?? 'fa-solid fa-check')),
                'filter_apply_button_color' => sanitize_hex_color($styles['filter_apply_button_color'] ?? '#ffffff') ?: '#ffffff',
                'filter_apply_button_background' => sanitize_hex_color($styles['filter_apply_button_background'] ?? '#2563eb') ?: '#2563eb',
                'filter_apply_button_hover_background' => sanitize_hex_color($styles['filter_apply_button_hover_background'] ?? '#1d4ed8') ?: '#1d4ed8',
                'filter_apply_button_icon' => sanitize_text_field((string) ($styles['filter_apply_button_icon'] ?? 'fa-solid fa-check')),
                'filter_apply_button_label' => sanitize_text_field((string) ($styles['filter_apply_button_label'] ?? 'Apply')),
                'filter_clear_button_color' => sanitize_hex_color($styles['filter_clear_button_color'] ?? '#111827') ?: '#111827',
                'filter_clear_button_background' => sanitize_hex_color($styles['filter_clear_button_background'] ?? '#e5e7eb') ?: '#e5e7eb',
                'filter_clear_button_hover_background' => sanitize_hex_color($styles['filter_clear_button_hover_background'] ?? '#d1d5db') ?: '#d1d5db',
                'filter_clear_button_icon' => sanitize_text_field((string) ($styles['filter_clear_button_icon'] ?? 'fa-solid fa-eraser')),
                'filter_clear_button_label' => sanitize_text_field((string) ($styles['filter_clear_button_label'] ?? 'Clear')),
                'filter_panel_button_height' => max(24, min(64, (int) ($styles['filter_panel_button_height'] ?? 32))),
                'filter_panel_button_font_size' => max(10, min(24, (int) ($styles['filter_panel_button_font_size'] ?? 14))),
                'filter_panel_button_border' => sanitize_text_field((string) ($styles['filter_panel_button_border'] ?? '1px solid #9ca3af')),
                'filter_panel_button_border_radius' => max(0, min(24, (int) ($styles['filter_panel_button_border_radius'] ?? 6))),
                'table_max_height' => max(240, min(1600, (int) ($styles['table_max_height'] ?? 560))),
                'cell_color_rules' => $this->sanitize_cell_color_rules($styles['cell_color_rules'] ?? [], $columns),
            ],
        ];
    }

    private function resolve_column_label($column, $catalog) {
        $column = sanitize_key((string) $column);
        if ($column === '') {
            return '';
        }

        $global_labels = get_option('lcni_column_labels', []);
        if (is_array($global_labels) && isset($global_labels[$column])) {
            $label = sanitize_text_field((string) $global_labels[$column]);
            if ($label !== '') {
                return $label;
            }
        }

        if (isset(self::VIETNAMESE_COLUMN_LABELS[$column])) {
            return self::VIETNAMESE_COLUMN_LABELS[$column];
        }

        $fallback = isset($catalog[$column]['column']) ? (string) $catalog[$column]['column'] : $column;
        return ucwords(str_replace('_', ' ', $fallback));
    }

    private function resolve_stock_detail_base_url() {
        $stock_page_slug = sanitize_title((string) get_option('lcni_watchlist_stock_page', ''));
        if ($stock_page_slug === '') {
            $stock_page_id = absint(get_option('lcni_frontend_stock_detail_page', 0));
            if ($stock_page_id > 0) {
                $stock_page_slug = sanitize_title((string) get_post_field('post_name', $stock_page_id));
            }
        }
        if ($stock_page_slug === '') {
            $stock_page_slug = 'chi-tiet-co-phieu';
        }

        return home_url('/' . $stock_page_slug . '/');
    }

    private function build_stock_detail_url($base_url, $symbol) {
        if ($base_url === '' || $symbol === '' || preg_match('/^[A-Z0-9._-]{1,20}$/', $symbol) !== 1) {
            return '';
        }

        return add_query_arg('symbol', $symbol, $base_url);
    }

    private function format_signal_value($column, $value) {
        if ($value === null || $value === '') {
            return '';
        }

        $field = strpos((string) $column, '__') !== false ? substr((string) $column, strpos((string) $column, '__') + 2) : (string) $column;
        if (($field === 'entry_time' || $field === 'exit_time') && is_numeric($value)) {
            $format_settings = LCNI_Data_Format_Settings::get_settings();
            $event_time_format = (string) ($format_settings['date_formats']['event_time'] ?? 'DD-MM-YYYY');
            if ($event_time_format === 'number') {
                return number_format((float) $value, 2, '.', ',');
            }

            $timestamp = (int) $value;
            if ($timestamp > 0) {
                return wp_date('d-m-Y', $timestamp);
            }
        }

        if (($field === 'npl_current' || $field === 'npl_closed') && is_numeric($value)) {
            return number_format((float) $value, 2, '.', ',') . '%';
        }

        if (is_numeric($value)) {
            $numeric = (float) $value;
            return (abs($numeric) >= 1000 || floor($numeric) != $numeric) ? number_format($numeric, 2, '.', ',') : (string) ((int) $numeric);
        }

        return (string) $value;
    }

    private function sanitize_icon_class($icon_class, $fallback) {
        $icon = preg_replace('/[^a-zA-Z0-9\-\s]/', '', (string) $icon_class);
        $icon = trim((string) $icon);

        if ($icon === '') {
            $icon = trim((string) $fallback);
        }

        if ($icon === '') {
            $icon = 'fa-solid fa-circle';
        }

        return $icon;
    }

    private function sanitize_cell_color_rules($rules, $columns) {
        if (!is_array($rules)) {
            return [];
        }

        $allowed_operators = ['=', '!=', '>', '>=', '<', '<=', 'contains', 'not_contains'];
        $allowed_columns = array_values(array_map('sanitize_key', is_array($columns) ? $columns : []));
        $sanitized = [];

        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                continue;
            }

            $column = sanitize_key((string) ($rule['column'] ?? ''));
            $operator = sanitize_text_field((string) ($rule['operator'] ?? ''));
            $value = trim(sanitize_text_field((string) ($rule['value'] ?? '')));
            $bg_color = sanitize_hex_color((string) ($rule['bg_color'] ?? ''));
            $text_color = sanitize_hex_color((string) ($rule['text_color'] ?? ''));

            if ($column === '' || !in_array($column, $allowed_columns, true) || !in_array($operator, $allowed_operators, true) || $value === '') {
                continue;
            }

            if (!$bg_color && !$text_color) {
                continue;
            }

            $sanitized[] = [
                'column' => $column,
                'operator' => $operator,
                'value' => $value,
                'bg_color' => $bg_color,
                'text_color' => $text_color,
            ];
        }

        return array_slice($sanitized, 0, 100);
    }

    private function resolve_recommend_signal_cell_style($column, $value, $styles) {
        $rules = isset($styles['cell_color_rules']) && is_array($styles['cell_color_rules']) ? $styles['cell_color_rules'] : [];

        foreach ($rules as $rule) {
            if (!is_array($rule) || (string) ($rule['column'] ?? '') !== (string) $column) {
                continue;
            }

            if (!$this->matches_cell_color_rule($value, (string) ($rule['operator'] ?? ''), $rule['value'] ?? null)) {
                continue;
            }

            return [
                'background' => (string) ($rule['bg_color'] ?? ''),
                'color' => (string) ($rule['text_color'] ?? ''),
            ];
        }

        return ['background' => '', 'color' => ''];
    }

    private function matches_cell_color_rule($actual_value, $operator, $expected_value) {
        $actual = is_scalar($actual_value) ? trim((string) $actual_value) : '';
        $expected = is_scalar($expected_value) ? trim((string) $expected_value) : '';

        $actual_number = is_numeric($actual) ? (float) $actual : null;
        $expected_number = is_numeric($expected) ? (float) $expected : null;
        $numeric_compare = $actual_number !== null && $expected_number !== null;

        if ($operator === '>') {
            return $numeric_compare && $actual_number > $expected_number;
        }
        if ($operator === '>=') {
            return $numeric_compare && $actual_number >= $expected_number;
        }
        if ($operator === '<') {
            return $numeric_compare && $actual_number < $expected_number;
        }
        if ($operator === '<=') {
            return $numeric_compare && $actual_number <= $expected_number;
        }
        if ($operator === '=') {
            return $numeric_compare ? $actual_number === $expected_number : strcasecmp($actual, $expected) === 0;
        }
        if ($operator === '!=') {
            return $numeric_compare ? $actual_number !== $expected_number : strcasecmp($actual, $expected) !== 0;
        }
        if ($operator === 'contains') {
            return $expected !== '' && stripos($actual, $expected) !== false;
        }
        if ($operator === 'not_contains') {
            return $expected !== '' && stripos($actual, $expected) === false;
        }

        return false;
    }

    public function render_performance($atts = []) {
        $atts = shortcode_atts(['rule_id' => 0], $atts, 'lcni_performance');
        $rows = $this->performance_calculator->list_performance((int) $atts['rule_id']);

        ob_start();
        echo '<div class="lcni-table-wrapper"><table class="lcni-table" style="width:100%;"><thead><tr><th>Rule</th><th>Total</th><th>Win</th><th>Lose</th><th>Winrate</th><th>Avg R</th><th>Expectancy</th><th>Max R</th><th>Min R</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            echo '<tr>';
            echo '<td>' . esc_html((string) ($row['rule_name'] ?: ('Rule #' . $row['rule_id']))) . '</td>';
            echo '<td>' . esc_html((string) $row['total_trades']) . '</td>';
            echo '<td>' . esc_html((string) $row['win_trades']) . '</td>';
            echo '<td>' . esc_html((string) $row['lose_trades']) . '</td>';
            echo '<td>' . esc_html(number_format((float) $row['winrate'] * 100, 2)) . '%</td>';
            echo '<td>' . esc_html(number_format((float) $row['avg_r'], 2)) . '</td>';
            echo '<td>' . esc_html(number_format((float) $row['expectancy'], 2)) . '</td>';
            echo '<td>' . esc_html(number_format((float) $row['max_r'], 2)) . '</td>';
            echo '<td>' . esc_html(number_format((float) $row['min_r'], 2)) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>'; // close table + lcni-table-wrapper

        return ob_get_clean();
    }

    /**
     * [lcni_performance_v2] — Extended performance table with new metrics.
     * Attributes:
     *   rule_id    (int, default 0 = all rules)
     *   show_chart (bool "1"|"0", default "1")
     */
    public function render_performance_v2( $atts = [] ) {
        $atts = shortcode_atts(
            [ 'rule_id' => 0, 'show_chart' => '1' ],
            $atts,
            'lcni_performance_v2'
        );

        $rule_id_filter = (int) $atts['rule_id'];
        $rows           = $this->performance_calculator->list_performance( $rule_id_filter );
        $show_chart     = ( $atts['show_chart'] !== '0' );
        $ajax_url       = esc_url_raw( admin_url( 'admin-ajax.php' ) );
        $nonce          = wp_create_nonce( 'lcni_public_equity_curve' );
        $uid            = 'lcni-pv2-' . ( $rule_id_filter > 0 ? $rule_id_filter : 'all' );

        ob_start(); ?>
        <div class="lcni-pv2" id="<?php echo esc_attr( $uid ); ?>">
        <style>
        #<?php echo esc_attr( $uid ); ?>{width:100%;box-sizing:border-box;font-family:inherit;font-size:14px;}

        /* ── Card mỗi rule ── */
        .lcni-pv2-card{border:1px solid #e5e7eb;border-radius:10px;overflow:hidden;margin-bottom:20px;background:#fff;}

        /* ── Header card: tên + điểm ── */
        .lcni-pv2-header{display:flex;align-items:center;justify-content:space-between;padding:12px 16px;background:#f8fafc;border-bottom:1px solid #e5e7eb;gap:12px;flex-wrap:wrap;}
        .lcni-pv2-rulename{font-size:16px;font-weight:700;color:#111827;}
        .lcni-pv2-score-wrap{display:flex;align-items:center;gap:8px;}
        .lcni-pv2-score-num{font-size:22px;font-weight:800;}
        .lcni-pv2-score-num.good{color:#16a34a;}
        .lcni-pv2-score-num.neutral{color:#d97706;}
        .lcni-pv2-score-num.weak{color:#dc2626;}
        .lcni-pv2-badge{font-size:12px;padding:3px 9px;border-radius:5px;color:#fff;font-weight:700;}
        .lcni-pv2-badge.good{background:#16a34a;}
        .lcni-pv2-badge.neutral{background:#d97706;}
        .lcni-pv2-badge.weak{background:#dc2626;}

        /* ── Grid metrics 2 hàng × 4 cột ── */
        .lcni-pv2-grid{display:grid;grid-template-columns:repeat(4,1fr);border-bottom:1px solid #e5e7eb;}
        .lcni-pv2-cell{padding:10px 14px;border-right:1px solid #e5e7eb;border-bottom:1px solid #e5e7eb;}
        .lcni-pv2-cell:nth-child(4n){border-right:0;}
        .lcni-pv2-cell:nth-child(n+5){border-bottom:0;}
        .lcni-pv2-cell-label{font-size:11px;color:#6b7280;margin-bottom:3px;}
        .lcni-pv2-cell-value{font-size:15px;font-weight:600;color:#111827;}
        .lcni-pv2-cell-value.green{color:#16a34a;}
        .lcni-pv2-cell-value.red{color:#dc2626;}
        .lcni-pv2-cell-sub{font-size:11px;color:#9ca3af;margin-top:1px;}

        /* ── Stat cards trên chart ── */
        .lcni-pv2-stats{display:flex;gap:10px;flex-wrap:wrap;padding:12px 16px;background:#f8fafc;border-bottom:1px solid #e5e7eb;}
        .lcni-pv2-stat{flex:1 1 110px;background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:8px 12px;line-height:1.4;}
        .lcni-pv2-stat strong{display:block;font-size:16px;font-weight:700;}
        .lcni-pv2-stat span{font-size:11px;color:#6b7280;}

        /* ── Chart ── */
        .lcni-pv2-chart-wrap{padding:14px 16px;}
        .lcni-pv2-chart-title{font-size:13px;font-weight:600;color:#374151;margin:0 0 10px;}
        .lcni-pv2-chart-el{width:100%;height:300px;}

        @media(max-width:600px){
            .lcni-pv2-grid{grid-template-columns:repeat(2,1fr);}
            .lcni-pv2-cell:nth-child(4n){border-right:1px solid #e5e7eb;}
            .lcni-pv2-cell:nth-child(2n){border-right:0;}
            .lcni-pv2-cell:nth-child(n+5){border-bottom:1px solid #e5e7eb;}
            .lcni-pv2-cell:last-child,.lcni-pv2-cell:nth-last-child(2){border-bottom:0;}
        }
        </style>

        <?php foreach ( $rows as $row ) :
            $score    = PerformanceCalculator::compute_score( $row );
            $badge    = PerformanceCalculator::score_badge( $score );
            $vi_badge = [ 'good' => 'Tốt', 'neutral' => 'Trung bình', 'weak' => 'Kém' ];
            $kelly    = (float) ( $row['kelly_pct']    ?? 0 );
            $half_k   = $kelly / 2;
            $rid      = (int)   ( $row['rule_id']      ?? 0 );
            $rname    = (string)( $row['rule_name']    ?: ( 'Chiến lược #' . $rid ) );
            $chart_id = $uid . '-c-' . $rid;
        ?>

        <div class="lcni-pv2-card">

            <?php /* Header: tên + điểm */ ?>
            <div class="lcni-pv2-header">
                <span class="lcni-pv2-rulename"><?php echo esc_html( $rname ); ?></span>
                <div class="lcni-pv2-score-wrap">
                    <span class="lcni-pv2-score-num <?php echo esc_attr( $badge ); ?>"><?php echo esc_html( (string) $score ); ?>/100</span>
                    <span class="lcni-pv2-badge <?php echo esc_attr( $badge ); ?>"><?php echo esc_html( $vi_badge[$badge] ?? '' ); ?></span>
                </div>
            </div>

            <?php /* Grid 2 hàng × 4 cột */ ?>
            <div class="lcni-pv2-grid">

                <div class="lcni-pv2-cell">
                    <div class="lcni-pv2-cell-label">Tổng lệnh / Thắng / Thua</div>
                    <div class="lcni-pv2-cell-value">
                        <?php echo esc_html( (string)( $row['total_trades'] ?? 0 ) ); ?>
                        &nbsp;·&nbsp;<span style="color:#16a34a"><?php echo esc_html( (string)( $row['win_trades'] ?? 0 ) ); ?></span>
                        &nbsp;·&nbsp;<span style="color:#dc2626"><?php echo esc_html( (string)( $row['lose_trades'] ?? 0 ) ); ?></span>
                    </div>
                </div>

                <div class="lcni-pv2-cell">
                    <div class="lcni-pv2-cell-label">Tỷ lệ thắng</div>
                    <div class="lcni-pv2-cell-value"><?php echo esc_html( number_format( (float)( $row['winrate'] ?? 0 ) * 100, 2 ) ); ?>%</div>
                </div>

                <div class="lcni-pv2-cell">
                    <div class="lcni-pv2-cell-label">R kỳ vọng (Expectancy)</div>
                    <div class="lcni-pv2-cell-value"><?php echo esc_html( number_format( (float)( $row['expectancy'] ?? 0 ), 4 ) ); ?></div>
                </div>

                <div class="lcni-pv2-cell">
                    <div class="lcni-pv2-cell-label">Avg R trung bình</div>
                    <div class="lcni-pv2-cell-value"><?php echo esc_html( number_format( (float)( $row['avg_r'] ?? 0 ), 4 ) ); ?></div>
                </div>

                <div class="lcni-pv2-cell">
                    <div class="lcni-pv2-cell-label">R thắng TB / R thua TB</div>
                    <div class="lcni-pv2-cell-value">
                        <span class="green"><?php echo esc_html( number_format( (float)( $row['avg_win_r'] ?? 0 ), 4 ) ); ?>R</span>
                        &nbsp;/&nbsp;
                        <span class="red"><?php echo esc_html( number_format( (float)( $row['avg_loss_r'] ?? 0 ), 4 ) ); ?>R</span>
                    </div>
                </div>

                <div class="lcni-pv2-cell">
                    <div class="lcni-pv2-cell-label">Hệ số lợi nhuận (Profit Factor)</div>
                    <div class="lcni-pv2-cell-value"><?php echo esc_html( number_format( (float)( $row['profit_factor'] ?? 0 ), 4 ) ); ?></div>
                </div>

                <div class="lcni-pv2-cell">
                    <div class="lcni-pv2-cell-label">Kelly % (Half-Kelly)</div>
                    <div class="lcni-pv2-cell-value"><?php echo esc_html( number_format( $kelly * 100, 2 ) ); ?>%</div>
                    <div class="lcni-pv2-cell-sub">½K: <?php echo esc_html( number_format( $half_k * 100, 2 ) ); ?>%</div>
                </div>

                <div class="lcni-pv2-cell">
                    <div class="lcni-pv2-cell-label">Nắm giữ TB · R cao nhất · R thấp nhất</div>
                    <div class="lcni-pv2-cell-value" style="font-size:13px;">
                        <?php echo esc_html( number_format( (float)( $row['avg_hold_days'] ?? 0 ), 1 ) ); ?> ngày
                        &nbsp;·&nbsp;<span class="green"><?php echo esc_html( number_format( (float)( $row['max_r'] ?? 0 ), 2 ) ); ?>R</span>
                        &nbsp;·&nbsp;<span class="red"><?php echo esc_html( number_format( (float)( $row['min_r'] ?? 0 ), 2 ) ); ?>R</span>
                    </div>
                </div>

            </div><!-- /.lcni-pv2-grid -->

            <?php
            // Breakdown exit_reason
            $breakdown = $this->performance_calculator->get_exit_reason_breakdown( $rid );
            $total_closed = array_sum( $breakdown );
            if ( $total_closed > 0 ) :
                $bd_items = [
                    ExitEngine::REASON_TAKE_PROFIT => [ 'label' => 'Chốt lời',       'color' => '#16a34a' ],
                    ExitEngine::REASON_MAX_HOLD    => [ 'label' => 'Hết thời gian',   'color' => '#2563eb' ],
                    ExitEngine::REASON_STOP_LOSS   => [ 'label' => 'Cắt lỗ (SL)',    'color' => '#dc2626' ],
                    ExitEngine::REASON_MAX_LOSS    => [ 'label' => 'Cắt lỗ tối đa',  'color' => '#b91c1c' ],
                    'unknown'                      => [ 'label' => 'Không rõ',        'color' => '#9ca3af' ],
                ];
            ?>
            <div style="padding:10px 14px;border-bottom:1px solid #e5e7eb;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                <span style="font-size:11px;color:#6b7280;font-weight:600;margin-right:4px;">Lý do thoát lệnh:</span>
                <?php foreach ( $bd_items as $reason => $meta ) :
                    $cnt = (int)( $breakdown[$reason] ?? 0 );
                    if ( $cnt <= 0 ) continue;
                    $pct = round( $cnt / $total_closed * 100, 1 );
                ?>
                <span style="display:inline-flex;align-items:center;gap:4px;background:<?php echo esc_attr($meta['color']); ?>18;border:1px solid <?php echo esc_attr($meta['color']); ?>44;border-radius:5px;padding:3px 9px;font-size:12px;">
                    <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:<?php echo esc_attr($meta['color']); ?>;"></span>
                    <strong style="color:<?php echo esc_attr($meta['color']); ?>"><?php echo esc_html($cnt); ?></strong>
                    <span style="color:#374151"><?php echo esc_html($meta['label']); ?> (<?php echo esc_html($pct); ?>%)</span>
                </span>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php if ( $show_chart ) : ?>

            <div class="lcni-pv2-stats" id="<?php echo esc_attr( $chart_id ); ?>-stats">
                <em style="color:#9ca3af;font-size:13px;">Đang tải đường cong vốn…</em>
            </div>

            <div class="lcni-pv2-chart-wrap">
                <div class="lcni-pv2-chart-title">📈 Đường cong vốn — <?php echo esc_html( $rname ); ?></div>
                <div class="lcni-pv2-chart-el" id="<?php echo esc_attr( $chart_id ); ?>-el"></div>
            </div>

            <?php endif; ?>

        </div><!-- /.lcni-pv2-card -->

        <?php endforeach; ?>

        <?php if ( $show_chart ) : ?>
        <script>
        (function(){
            var ajaxUrl = <?php echo wp_json_encode( $ajax_url ); ?>;
            var nonce   = <?php echo wp_json_encode( $nonce ); ?>;
            var rows    = <?php echo wp_json_encode( array_map( function( $r ) {
                return [ 'rule_id' => (int)( $r['rule_id'] ?? 0 ), 'rule_name' => (string)( $r['rule_name'] ?? '' ) ];
            }, $rows ) ); ?>;
            var uid     = <?php echo wp_json_encode( $uid ); ?>;

            if(!window.__lcniCharts) window.__lcniCharts = {};

            function stat(label, value, color){
                return '<div class="lcni-pv2-stat"><strong style="color:'+(color||'#111827')+'">'+value+'</strong><span>'+label+'</span></div>';
            }

            function renderChart(ruleId, points){
                var chartId = uid+'-c-'+ruleId;
                var statsEl = document.getElementById(chartId+'-stats');
                var chartEl = document.getElementById(chartId+'-el');
                if(!statsEl || !chartEl) return;

                if(!points || !points.length){
                    statsEl.innerHTML = '<em style="color:#9ca3af;font-size:13px;">Chưa có lệnh đã đóng.</em>';
                    chartEl.style.display = 'none';
                    return;
                }

                var cumVals  = points.map(function(p){ return p.cumulative_r; });
                var tradeRs  = points.map(function(p){ return p.trade_r; });
                var dates    = points.map(function(p,i){ return p.date || ''; });
                var xLabels  = points.map(function(p,i){ return i+1; }); // số thứ tự lệnh
                var final    = cumVals[cumVals.length-1];
                var peak=0, maxDD=0;
                for(var i=0;i<cumVals.length;i++){
                    if(cumVals[i]>peak) peak=cumVals[i];
                    var dd=peak-cumVals[i]; if(dd>maxDD) maxDD=dd;
                }
                var wins  = tradeRs.filter(function(r){ return r>=0; }).length;
                var total = tradeRs.length;

                statsEl.innerHTML =
                    stat('Tổng R', (final>=0?'+':'')+final.toFixed(2)+'R', final>=0?'#16a34a':'#dc2626') +
                    stat('Số lệnh đã đóng', total) +
                    stat('Drawdown tối đa', '-'+maxDD.toFixed(2)+'R', '#dc2626') +
                    stat('Tỷ lệ thắng', (total>0?(wins/total*100).toFixed(1):0)+'%');

                if(window.__lcniCharts[chartId]) window.__lcniCharts[chartId].dispose();
                var chart = window.echarts.init(chartEl);
                window.__lcniCharts[chartId] = chart;

                // Tính ngày đầu và ngày cuối để hiện trên trục X
                var dateFirst = dates[0] || '';
                var dateLast  = dates[dates.length-1] || '';

                chart.setOption({
                    tooltip:{
                        trigger:'axis',
                        formatter:function(params){
                            var i=params[0].dataIndex; var p=points[i];
                            var reasonColor = p.exit_reason==='stop_loss'||p.exit_reason==='max_loss' ? '#dc2626'
                                            : p.exit_reason==='take_profit' ? '#16a34a' : '#6b7280';
                            return '<b>Lệnh #'+(i+1)+'</b><br/>'+
                                   'Mã: <b>'+p.symbol+'</b><br/>'+
                                   'Mua: '+(p.entry_date||'—')+' · Bán: '+(p.date||'—')+'<br/>'+
                                   'Nắm giữ: '+(p.holding_days||'—')+' ngày<br/>'+
                                   'Lý do thoát: <span style="color:'+reasonColor+';font-weight:600">'+(p.exit_label||'—')+'</span><br/>'+
                                   'Lệnh này: '+(p.trade_r>=0?'+':'')+p.trade_r.toFixed(2)+'R<br/>'+
                                   'Cộng dồn: '+(p.cumulative_r>=0?'+':'')+p.cumulative_r.toFixed(2)+'R';
                        }
                    },
                    grid:{left:'55px',right:'16px',bottom:'52px',top:'20px'},
                    xAxis:{
                        type:'category',
                        data:xLabels,
                        name: dateFirst&&dateLast ? dateFirst+' → '+dateLast : '',
                        nameLocation:'middle',
                        nameGap:36,
                        nameTextStyle:{fontSize:11,color:'#6b7280'},
                        axisLabel:{
                            fontSize:11,
                            formatter:function(v,i){
                                // chỉ hiện nhãn đầu, cuối, và mỗi 1/6 khoảng
                                var n=xLabels.length;
                                var step=Math.max(1,Math.floor(n/6));
                                return (v===1||v===n||v%step===0)?v:'';
                            }
                        }
                    },
                    yAxis:{type:'value',name:'R',nameTextStyle:{fontSize:11},splitLine:{lineStyle:{type:'dashed'}}},
                    legend:{data:['Vốn cộng dồn','R từng lệnh'],top:0,right:0,textStyle:{fontSize:11}},
                    series:[
                        {
                            name:'Vốn cộng dồn', type:'line', data:cumVals,
                            symbol:'circle', symbolSize:4,
                            lineStyle:{width:2,color:'#16a34a'},
                            itemStyle:{color:function(p){return p.data>=0?'#16a34a':'#dc2626';}},
                            areaStyle:{color:{type:'linear',x:0,y:0,x2:0,y2:1,
                                colorStops:[{offset:0,color:'rgba(22,163,74,0.2)'},{offset:1,color:'rgba(22,163,74,0.02)'}]}},
                            markLine:{silent:true,lineStyle:{color:'#9ca3af',type:'dashed'},
                                data:[{yAxis:0,label:{formatter:'0R',position:'insideEndTop'}}]}
                        },
                        {
                            name:'R từng lệnh', type:'bar', data:tradeRs, barMaxWidth:6,
                            itemStyle:{color:function(p){return p.data>=0?'rgba(22,163,74,0.4)':'rgba(220,38,38,0.4)';}},
                            tooltip:{show:false}
                        }
                    ],
                    dataZoom:[{type:'inside'},{type:'slider',height:18,bottom:4}]
                });

                window.addEventListener('resize', function(){ chart.resize(); });
            }

            function loadAndRender(ruleId){
                fetch(ajaxUrl+'?action=lcni_public_equity_curve&rule_id='+encodeURIComponent(ruleId)+'&nonce='+encodeURIComponent(nonce))
                    .then(function(r){ return r.json(); })
                    .then(function(resp){
                        var statsEl = document.getElementById(uid+'-c-'+ruleId+'-stats');
                        if(!resp.success){
                            var msg = (resp.data && resp.data.message) ? resp.data.message : 'Lỗi tải dữ liệu';
                            if(statsEl) statsEl.innerHTML = '<em style="color:#dc2626;font-size:13px;">⚠ '+msg+'</em>';
                            return;
                        }
                        renderChart(ruleId, resp.data.points);
                    })
                    .catch(function(err){
                        var el = document.getElementById(uid+'-c-'+ruleId+'-stats');
                        if(el) el.innerHTML = '<em style="color:#dc2626;font-size:13px;">⚠ Không kết nối được server.</em>';
                    });
            }

            function initAll(){
                rows.forEach(function(r){ loadAndRender(r.rule_id); });
            }

            if(window.echarts && typeof window.echarts.init === 'function'){
                initAll();
            } else {
                var s = document.createElement('script');
                s.src = 'https://cdn.jsdelivr.net/npm/echarts@5/dist/echarts.min.js';
                s.onload = initAll;
                document.head.appendChild(s);
            }
        })();
        </script>
        <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * [lcni_equity_curve rule_id="X"] — Standalone equity curve chart for a single rule.
     * Attributes:
     *   rule_id (int, required)
     *   height  (int, default 320) — chart height in px
     */
    public function render_equity_curve( $atts = [] ) {
        $atts    = shortcode_atts( [ 'rule_id' => 0, 'height' => 320 ], $atts, 'lcni_equity_curve' );
        $rule_id = (int) $atts['rule_id'];
        $height  = max( 200, min( 800, (int) $atts['height'] ) );

        if ( $rule_id <= 0 ) {
            return '<p><em>Vui lòng cung cấp rule_id. Ví dụ: [lcni_equity_curve rule_id="1"]</em></p>';
        }

        $points   = $this->performance_calculator->get_equity_curve( $rule_id );
        $ajax_url = esc_url_raw( admin_url( 'admin-ajax.php' ) );
        $nonce    = wp_create_nonce( 'lcni_public_equity_curve' );
        $uid      = 'lcni-ec-' . $rule_id . '-' . wp_rand( 1000, 9999 );

        if ( empty( $points ) ) {
            return '<p><em>Chưa có lệnh đã đóng cho rule này.</em></p>';
        }

        ob_start();
        ?>
        <div id="<?php echo esc_attr( $uid ); ?>" style="width:100%;height:<?php echo esc_attr( (string) $height ); ?>px;"></div>
        <div id="<?php echo esc_attr( $uid ); ?>-stats" style="display:flex;gap:10px;flex-wrap:wrap;margin-top:10px;font-size:13px;"></div>
        <script>
        (function(){
            const points = <?php echo wp_json_encode( $points ); ?>;
            const uid    = <?php echo wp_json_encode( $uid ); ?>;

            function stat(label,value,color){
                return '<div style="background:#f3f4f6;border-radius:6px;padding:5px 12px;line-height:1.5"><strong style="display:block;font-size:15px;color:'+(color||'#111827')+'">'+value+'</strong>'+label+'</div>';
            }

            function buildChart(){
                const cumVals  = points.map(function(p){return p.cumulative_r;});
                const tradeRs  = points.map(function(p){return p.trade_r;});
                const dates    = points.map(function(p){return p.date||'';});
                const xLabels  = points.map(function(p,i){return i+1;});
                const final    = cumVals[cumVals.length-1];
                const dateFirst= dates[0]||'';
                const dateLast = dates[dates.length-1]||'';
                let peak=0,maxDD=0;
                for(let i=0;i<cumVals.length;i++){if(cumVals[i]>peak)peak=cumVals[i];const dd=peak-cumVals[i];if(dd>maxDD)maxDD=dd;}
                const wins=tradeRs.filter(function(r){return r>=0;}).length;
                const total=tradeRs.length;

                document.getElementById(uid+'-stats').innerHTML=
                    stat('Tổng R',(final>=0?'+':'')+final.toFixed(2)+'R',final>=0?'#16a34a':'#dc2626')+
                    stat('Lệnh đóng',total)+
                    stat('Drawdown tối đa','-'+maxDD.toFixed(2)+'R','#dc2626')+
                    stat('Tỷ lệ thắng',(total>0?(wins/total*100).toFixed(1):0)+'%');

                const chart=window.echarts.init(document.getElementById(uid));
                chart.setOption({
                    tooltip:{trigger:'axis',formatter:function(params){
                        const i=params[0].dataIndex;const p=points[i];
                        const rc=p.exit_reason==='stop_loss'||p.exit_reason==='max_loss'?'#dc2626':p.exit_reason==='take_profit'?'#16a34a':'#6b7280';
                        return '<b>Lệnh #'+(i+1)+'</b><br/>'+
                               'Mã: <b>'+p.symbol+'</b><br/>'+
                               'Mua: '+(p.entry_date||'—')+' · Bán: '+(p.date||'—')+'<br/>'+
                               'Nắm giữ: '+(p.holding_days||'—')+' ngày<br/>'+
                               'Lý do thoát: <span style="color:'+rc+';font-weight:600">'+(p.exit_label||'—')+'</span><br/>'+
                               (p.trade_r>=0?'+':'')+p.trade_r.toFixed(2)+'R → cộng dồn: '+
                               (p.cumulative_r>=0?'+':'')+p.cumulative_r.toFixed(2)+'R';
                    }},
                    grid:{left:'55px',right:'14px',bottom:'52px',top:'20px'},
                    xAxis:{
                        type:'category',data:xLabels,
                        name:dateFirst&&dateLast?dateFirst+' → '+dateLast:'',
                        nameLocation:'middle',nameGap:36,
                        nameTextStyle:{fontSize:11,color:'#6b7280'},
                        axisLabel:{fontSize:11,formatter:function(v){
                            const n=xLabels.length;
                            const step=Math.max(1,Math.floor(n/6));
                            return (v===1||v===n||v%step===0)?v:'';
                        }}
                    },
                    yAxis:{type:'value',name:'R',splitLine:{lineStyle:{type:'dashed'}}},
                    series:[
                        {type:'line',data:cumVals,symbol:'circle',symbolSize:3,
                         lineStyle:{width:2,color:'#16a34a'},
                         itemStyle:{color:function(p){return p.data>=0?'#16a34a':'#dc2626';}},
                         areaStyle:{color:{type:'linear',x:0,y:0,x2:0,y2:1,colorStops:[{offset:0,color:'rgba(22,163,74,0.25)'},{offset:1,color:'rgba(22,163,74,0.02)'}]}},
                         markLine:{silent:true,lineStyle:{color:'#9ca3af',type:'dashed'},data:[{yAxis:0}]}},
                        {name:'R từng lệnh',type:'bar',data:tradeRs,barMaxWidth:5,
                         itemStyle:{color:function(p){return p.data>=0?'rgba(22,163,74,0.45)':'rgba(220,38,38,0.45)';}},
                         tooltip:{show:false}}
                    ],
                    dataZoom:[{type:'inside'},{type:'slider',height:18,bottom:4}]
                });
                window.addEventListener('resize',function(){chart.resize();});
            }

            if(window.echarts&&typeof window.echarts.init==='function'){buildChart();}
            else{
                const s=document.createElement('script');
                s.src='https://cdn.jsdelivr.net/npm/echarts@5/dist/echarts.min.js';
                s.onload=buildChart;
                document.head.appendChild(s);
            }
        })();
        </script>
        <?php
        return ob_get_clean();
    }

    public function ajax_public_equity_curve() {
        check_ajax_referer( 'lcni_public_equity_curve', 'nonce' );

        $rule_id = (int) ( $_GET['rule_id'] ?? 0 );
        if ( $rule_id <= 0 ) {
            wp_send_json_error( [ 'message' => 'Invalid rule_id' ], 400 );
        }

        $points = $this->performance_calculator->get_equity_curve( $rule_id );

        // Log wpdb error nếu có để debug
        if ( $this->performance_calculator->get_last_db_error() ) {
            wp_send_json_error( [ 'message' => 'DB error', 'detail' => $this->performance_calculator->get_last_db_error() ], 500 );
        }

        wp_send_json_success( [ 'points' => $points, 'count' => count( $points ) ] );
    }

    public function render_signal_card($atts = []) {
        $atts = shortcode_atts(['symbol' => ''], $atts, 'lcni_signal');
        $symbol = strtoupper(sanitize_text_field((string) $atts['symbol']));
        if ($symbol === '') {
            return '';
        }

        $signal = $this->signal_repository->find_open_signal_by_symbol($symbol);
        if (!$signal) {
            return '<p>Không có signal open cho mã này.</p>';
        }

        $action = $this->position_engine->action_for_state((string) $signal['position_state']);

        ob_start();
        echo '<div>';
        echo '<p><strong>Rule Name:</strong> ' . esc_html((string) $signal['rule_name']) . '</p>';
        echo '<p><strong>Entry price:</strong> ' . esc_html((string) $signal['entry_price']) . '</p>';
        echo '<p><strong>Current price:</strong> ' . esc_html((string) $signal['current_price']) . '</p>';
        echo '<p><strong>R multiple:</strong> ' . esc_html(number_format((float) $signal['r_multiple'], 2)) . '</p>';
        echo '<p><strong>Position state:</strong> ' . esc_html((string) $signal['position_state']) . '</p>';
        echo '<p><strong>Action:</strong> ' . esc_html($action) . '</p>';
        echo '</div>';

        return ob_get_clean();
    }
}
