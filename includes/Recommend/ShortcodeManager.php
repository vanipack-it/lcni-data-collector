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
    }

    public function register_shortcodes() {
        add_shortcode('lcni_signals', [$this, 'render_signals']);
        add_shortcode('lcni_signals_rule', [$this, 'render_signals_rule']);
        add_shortcode('lcni_performance', [$this, 'render_performance']);
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
        $wrapper_style = sprintf('font-family:%s;color:%s;background:%s;border:%s;border-radius:%dpx;overflow:auto;position:relative;-webkit-overflow-scrolling:touch;',
            esc_attr((string) ($styles['font'] ?? 'inherit')),
            esc_attr((string) ($styles['text_color'] ?? '#111827')),
            esc_attr((string) ($styles['background'] ?? '#ffffff')),
            esc_attr((string) ($styles['border'] ?? '1px solid #e5e7eb')),
            (int) ($styles['border_radius'] ?? 8)
        );
        if ($sticky_header_enabled) {
            $wrapper_style .= 'max-height:min(70vh,720px);overscroll-behavior:contain;';
        }

        ob_start();
        echo '<div class="lcni-recommend-signals-table" data-lcni-signals-table data-watchlist-rest-base="' . esc_attr($watchlist_rest_base) . '" data-login-url="' . esc_url($login_url) . '" data-register-url="' . esc_url($register_url) . '" data-is-logged-in="' . (is_user_logged_in() ? '1' : '0') . '" data-rest-nonce="' . esc_attr(wp_create_nonce('wp_rest')) . '" style="' . $wrapper_style . '">';
        echo '<table style="width:100%;border-collapse:separate;border-spacing:0;font-size:' . (int) ($styles['row_font_size'] ?? 14) . 'px;">';
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
            echo '<th style="' . $th_style . '" data-lcni-field="' . esc_attr($column) . '"><span>' . esc_html($label) . '</span><button type="button" class="lcni-signals-filter-btn" data-lcni-filter-btn aria-label="Lọc nhanh"><i class="fa-solid fa-filter" aria-hidden="true"></i></button></th>';
        }
        echo '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $row_symbol = strtoupper(sanitize_text_field((string) ($row['signal__symbol'] ?? '')));
            $row_url = $this->build_stock_detail_url($stock_detail_base_url, $row_symbol);
            echo '<tr data-lcni-row-symbol="' . esc_attr($row_symbol) . '" data-lcni-row-url="' . esc_url($row_url) . '" style="background:' . esc_attr($value_background) . ';color:' . esc_attr($value_text_color) . ';" onmouseover="this.style.background=\'' . esc_attr($row_hover_background) . '\'" onmouseout="this.style.background=\'' . esc_attr($value_background) . '\'">';
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
                    $cell_style_attr .= 'position:sticky;left:0;z-index:3;background:' . esc_attr($cell_bg) . ';';
                }

                if ($column === 'signal__symbol') {
                    $symbol = strtoupper(sanitize_text_field((string) $raw_value));
                    $detail_url = $this->build_stock_detail_url($stock_detail_base_url, $symbol);
                    $watchlist_btn = '<button type="button" class="lcni-signals-watchlist-btn" data-lcni-watchlist-add data-symbol="' . esc_attr($symbol) . '" aria-label="Thêm vào watchlist"><i class="fa-solid fa-heart" aria-hidden="true"></i></button>';
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

        echo '</tbody></table></div>';
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
.lcni-signals-filter-btn{margin-left:6px;border:0;background:transparent;cursor:pointer;color:inherit;padding:2px}
.lcni-signals-filter-pop{position:fixed;z-index:999999;background:#fff;border:1px solid #d1d5db;border-radius:8px;padding:10px;min-width:220px;max-width:320px;max-height:300px;overflow:auto;box-shadow:0 10px 30px rgba(17,24,39,.2)}
.lcni-signals-filter-pop [data-lcni-values]{display:grid;gap:4px;max-height:180px;overflow:auto;margin:8px 0}
.lcni-signals-filter-actions{display:flex;gap:8px;justify-content:flex-end}
.lcni-signals-symbol-cell{display:flex;gap:8px;align-items:center}
.lcni-signals-watchlist-btn{border:0;background:transparent;cursor:pointer;color:#dc2626;padding:0}
.lcni-signals-watchlist-btn.is-active{color:#16a34a}
.lcni-signals-modal{position:fixed;inset:0;background:rgba(17,24,39,.45);display:flex;align-items:center;justify-content:center;z-index:999999}
.lcni-signals-modal-card{background:#fff;border-radius:10px;padding:14px;width:min(92vw,420px)}
.lcni-signals-modal-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:10px}
</style>
<script>
(function(){
  if(window.__lcniSignalsTableInit) return;
  window.__lcniSignalsTableInit = true;
  const esc=(v)=>String(v==null?'':v).replace(/[&<>"']/g,(m)=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));
  const toast=(m)=>{const n=document.createElement('div');n.className='lcni-watchlist-toast';n.textContent=m;document.body.appendChild(n);setTimeout(()=>n.remove(),2400)};
  const closeModal=()=>{const n=document.querySelector('.lcni-signals-modal');if(n)n.remove()};
  const showModal=(html)=>{closeModal();const n=document.createElement('div');n.className='lcni-signals-modal';n.innerHTML='<div class="lcni-signals-modal-card">'+html+'</div>';n.addEventListener('click',(e)=>{if(e.target===n||e.target.closest('[data-lcni-close]'))closeModal()});document.body.appendChild(n)};
  const watchlistApi=(host,path,opt)=>fetch((host.dataset.watchlistRestBase||'').replace(/\/$/,'')+path,{method:(opt&&opt.method)||'GET',headers:{'Content-Type':'application/json','X-WP-Nonce':host.dataset.restNonce||''},credentials:'same-origin',body:opt&&opt.body?JSON.stringify(opt.body):undefined}).then(async(r)=>{const p=await r.json().catch(()=>({}));if(!r.ok)throw p;return p&&typeof p==='object'&&Object.prototype.hasOwnProperty.call(p,'data')?p.data:p});
  const setButtonState=(btn)=>{const icon=btn.querySelector('i');if(!icon)return;icon.className=btn.classList.contains('is-active')?'fa-solid fa-check':'fa-solid fa-heart'};

  const openWatchlist=(host,symbol,btn)=>{
    if(host.dataset.isLoggedIn!=='1'){
      showModal('<h3>Vui lòng đăng nhập hoặc đăng ký để thêm vào watchlist</h3><div class="lcni-signals-modal-actions"><a class="lcni-btn" href="'+esc(host.dataset.loginUrl||'#')+'">Login</a><a class="lcni-btn" href="'+esc(host.dataset.registerUrl||host.dataset.loginUrl||'#')+'">Register</a><button type="button" class="lcni-btn" data-lcni-close>Close</button></div>');
      return;
    }

    watchlistApi(host,'/list?device=desktop').then((data)=>{
      const lists=Array.isArray(data.watchlists)?data.watchlists:[];
      const active=Number(data.active_watchlist_id||0);

      if(!lists.length){
        showModal('<h3>Tạo watchlist mới cho '+esc(symbol)+'</h3><form data-lcni-create><input type="text" name="name" placeholder="Tên watchlist" required><div class="lcni-signals-modal-actions"><button class="lcni-btn" type="submit">+ New</button><button class="lcni-btn" type="button" data-lcni-close>Close</button></div></form>');
        const form=document.querySelector('[data-lcni-create]');
        if(form) form.addEventListener('submit',(e)=>{e.preventDefault();const name=String((new FormData(form)).get('name')||'').trim();if(!name)return;watchlistApi(host,'/create',{method:'POST',body:{name}}).then((c)=>{const id=Number(c.id||0);if(!id)throw new Error('Không thể tạo watchlist');return watchlistApi(host,'/add-symbol',{method:'POST',body:{symbol,watchlist_id:id}})}).then(()=>{btn.classList.add('is-active');setButtonState(btn);closeModal();toast('Đã thêm mã '+symbol+' vào watchlist')}).catch((err)=>toast((err&&err.message)||'Không thể thêm vào watchlist'));},{once:true});
        return;
      }

      showModal('<h3>Chọn watchlist cho '+esc(symbol)+'</h3><form data-lcni-pick><div class="lcni-filter-watchlist-options">'+lists.map((w)=>'<label><input type="radio" name="watchlist_id" value="'+Number(w.id||0)+'" '+(Number(w.id||0)===active?'checked':'')+'> '+esc(w.name||'')+'</label>').join('')+'</div><div class="lcni-signals-modal-actions"><button class="lcni-btn" type="submit">Confirm</button><button class="lcni-btn" type="button" data-lcni-close>Close</button></div></form>');
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

  document.addEventListener('click',(e)=>{
    const watchBtn=e.target.closest('[data-lcni-watchlist-add]');
    if(watchBtn){const host=watchBtn.closest('[data-lcni-signals-table]');if(!host)return;e.preventDefault();e.stopPropagation();openWatchlist(host,String(watchBtn.dataset.symbol||'').toUpperCase(),watchBtn);return;}

    const filterBtn=e.target.closest('[data-lcni-filter-btn]');
    if(filterBtn){
      e.preventDefault();e.stopPropagation();
      const host=filterBtn.closest('[data-lcni-signals-table]');if(!host)return;
      const th=filterBtn.closest('th[data-lcni-field]');const field=th?th.dataset.lcniField:'';if(!field)return;
      const values=[...new Set(Array.from(host.querySelectorAll('tbody td[data-lcni-field="'+field+'"]')).map((n)=>String(n.dataset.lcniValue||'')).filter(Boolean))].sort();
      document.querySelectorAll('.lcni-signals-filter-pop').forEach((n)=>n.remove());
      const pop=document.createElement('div');
      pop.className='lcni-signals-filter-pop';
      const checked=((host.__lcniFilters||{})[field]||values);
      pop.innerHTML='<strong>Lọc '+esc((th.querySelector('span')||{}).textContent||field)+'</strong><div data-lcni-values>'+values.map((v)=>'<label><input type="checkbox" value="'+esc(v)+'" '+(checked.includes(v)?'checked':'')+'> '+esc(v)+'</label>').join('')+'</div><div class="lcni-signals-filter-actions"><button type="button" class="lcni-btn" data-lcni-clear>Clear</button><button type="button" class="lcni-btn" data-lcni-apply>Apply</button></div>';
      document.body.appendChild(pop);
      const rect=filterBtn.getBoundingClientRect();
      pop.style.top=(rect.bottom+6)+'px';
      pop.style.left=Math.max(8,Math.min(window.innerWidth-pop.offsetWidth-8,rect.left))+'px';
      pop.addEventListener('click',(ev)=>{
        if(ev.target.closest('[data-lcni-clear]')){host.__lcniFilters=host.__lcniFilters||{};delete host.__lcniFilters[field];applyFilters(host);pop.remove();return;}
        if(ev.target.closest('[data-lcni-apply]')){const selected=Array.from(pop.querySelectorAll('input[type="checkbox"]:checked')).map((n)=>n.value);host.__lcniFilters=host.__lcniFilters||{};if(!selected.length||selected.length===values.length)delete host.__lcniFilters[field];else host.__lcniFilters[field]=selected;applyFilters(host);pop.remove();}
      });
      return;
    }

    if(!e.target.closest('.lcni-signals-filter-pop')) document.querySelectorAll('.lcni-signals-filter-pop').forEach((n)=>n.remove());

    const row=e.target.closest('.lcni-recommend-signals-table tbody tr');
    if(!row||e.target.closest('a,button,i,svg,[role=button]')) return;
    const url=row.getAttribute('data-lcni-row-url')||'';
    if(url) window.location.href=url;
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
        echo '<table><thead><tr><th>Rule</th><th>Total</th><th>Win</th><th>Lose</th><th>Winrate</th><th>Avg R</th><th>Expectancy</th><th>Max R</th><th>Min R</th></tr></thead><tbody>';
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
        echo '</tbody></table>';

        return ob_get_clean();
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
