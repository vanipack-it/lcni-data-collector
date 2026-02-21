# Development Log



## 2026-02-21
- Release `2.0.0`: refactor module Filter theo hướng SaaS, giữ nguyên hành vi người dùng nhưng tách rõ responsibility giữa shortcode/render, service và repository.
- Tối ưu enqueue assets chỉ khi cần: chỉ nạp assets Filter (JS/CSS) + Watchlist dependency + Font Awesome 6 CDN khi trang chứa shortcode `[lcni_stock_filter]` hoặc đang ở stock detail context.
- Chuẩn hóa render wrapper frontend thành `<div class="lcni-app">...` và cập nhật CSS filter sang scoped selectors `.lcni-app ...` để tránh xung đột style global.
- Refactor endpoint `POST /wp-json/lcni/v1/filter/list` chỉ trả về payload tối giản `{ rows, total }`; frontend cập nhật bảng theo `tbody.innerHTML` thay vì render lại toàn bộ table.
- Cập nhật JS filter theo event delegation cho click row (`tr[data-symbol]`, bỏ qua click button) và luồng add watchlist qua AJAX realtime (đổi trạng thái icon, không reload trang).
- Củng cố data layer: REST handler không query trực tiếp, mọi SQL vẫn tập trung trong `repositories/SnapshotRepository.php`.

## 2026-02-20
- Watchlist: bổ sung cấu hình cột mặc định tách riêng desktop/mobile trong Admin Frontend Setting; user setting lưu JSON trong `user_meta`, có cache `localStorage` + sync AJAX để render nhanh không reload.
- Watchlist mobile UX: bật scroll ngang mượt, sticky cột `symbol`, giữ layout ổn định trên màn hình nhỏ, panel setting mặc định ẩn và chỉ mở khi click nút ⚙ (event delegation).
- Stock detail router: tăng độ ổn định rewrite `stock/([^/]+)`, set đồng thời query vars `symbol` + `lcni_stock_symbol`, chuẩn hóa flags query để tránh trạng thái 404/empty content và ưu tiên load đúng page template admin đã chọn.
- Stock chart: chuyển options vào panel setting ẩn mặc định, lưu cấu hình per-user (`user_meta`) + cache localStorage, đồng bộ qua REST AJAX có verify nonce.

- Refactor Watchlist frontend: panel chọn cột dạng dropdown chỉ mở khi click icon setting, lưu cấu hình cột theo `user_meta` và giữ nguyên sau reload.
- Nâng cấp UI bảng Watchlist: thêm hover row, con trỏ pointer và click row để điều hướng sang URL động `/stock/{symbol}`.
- Mở rộng nguồn cột Watchlist cho admin từ toàn bộ cột khả dụng trong `wp_lcni_ohlc_latest`, `wp_lcni_symbol_tongquan`, `wp_lcni_sym_icb_market`; user chỉ chọn trong danh sách admin cho phép.
- Di chuyển cấu hình Watchlist vào tab con **LCNI Data → Frontend Setting → Watchlist**, đồng thời bổ sung lựa chọn page template cho Stock Detail.
- Thêm dynamic stock route: rewrite `stock/{symbol}`, query vars `symbol` + `lcni_stock_symbol`, template loader render page được admin chọn và tự bind symbol vào shortcodes `lcni_stock_overview`, `lcni_stock_chart`, `lcni_stock_signals`.
- Bổ sung tài liệu thiết lập nhanh trong `docs_watchlist_stock_detail_setup.md`.

## 2026-02-19
- Tối ưu module LCNi Signals: chuyển truy vấn lấy dữ liệu mới nhất từ `wp_lcni_ohlc` sang `wp_lcni_ohlc_latest` để tránh `ORDER BY event_time DESC LIMIT 1` trên bảng lịch sử lớn, giúp giảm tải DB và tăng tốc độ tải trang.

## 2026-02-18
- Bổ sung module shortcodes `lcni_stock_overview` và `lcni_stock_overview_query` để hiển thị bộ chỉ số cơ bản từ `wp_lcni_symbol_tongquan` (kèm Sàn + ICB2), có nút setting góc phải cho phép user tùy chỉnh field hiển thị và lưu cá nhân theo `user_meta`.
- Thêm REST endpoint `GET /wp-json/lcni/v1/stock-overview?symbol=XXX` và `GET/POST /wp-json/lcni/v1/stock-overview/settings` để phục vụ module overview + lưu cấu hình cá nhân.
- Nâng cấp đồng bộ symbol không reload trang giữa chart/overview: click link cổ phiếu dùng `pushState`, gọi lại API và cập nhật đồng thời các module trên cùng page; có lưu lịch sử thay đổi symbol trong session.
- Tăng phiên bản plugin lên `1.6` để dễ theo dõi release.
- Bổ sung cột `rs_recommend_status` vào bảng `wp_lcni_ohlc` (schema tạo mới + cơ chế tự thêm cột cho hệ thống đang chạy).
- Thêm migration/backfill `rs_recommend_status` để cập nhật dữ liệu cho các bản ghi cũ và tự gắn cờ phiên bản migration `v1`.
- Chuẩn hóa công thức mapping theo cặp `rs_exchange_status` + `rs_exchange_recommend` với xử lý chống sai lệch khoảng trắng, tránh lỗi do chuỗi rỗng/định dạng không đồng nhất; mặc định trả về `Theo dõi`.
- Tích hợp tính lại `rs_recommend_status` ngay sau bước rebuild `rs_exchange_status`/`rs_exchange_recommend` để các dòng mới phát sinh luôn có giá trị đúng.
- Ghi lịch sử thay đổi qua `lcni_change_logs` cho luồng backfill `rs_recommend_status`.

## 2026-02-17
- Nâng cấp dữ liệu endpoint chi tiết/candles để hỗ trợ chart nâng cao: bổ sung full `ohlc`, `volume`, `macd`, `macd_signal`, `macd_histogram`, `rsi` trên từng mốc thời gian.
- Mở rộng payload `stock detail page` với `ohlc_history`, `volume_values`, `macd_values` (giữ tương thích ngược với `price_history`, `ma_values`, `rsi_values`).
- Nâng cấp script `assets/js/lcni-chart.js`: thêm lựa chọn chuyển đổi `Line`/`Candlestick`, thêm panel `Volume`, `MACD`, `RSI` có thể bật/tắt trực tiếp trên chart.

## 2026-02-14
- Điều tra lỗi import CSV trả về `updated 0 / total N` dù file có dữ liệu.
- Bổ sung cơ chế `LCNI_DB::ensure_tables_exist()` để tự động tạo bảng nếu plugin chưa chạy activation hoặc bị thiếu bảng.
- Gọi `ensure_tables_exist()` trước các luồng chính: import CSV, sync security definitions, sync OHLC.
- Sửa parser header CSV để loại bỏ BOM UTF-8 ở đầu cột (tránh trường hợp cột `symbol` không được nhận diện).
- Kỳ vọng sau sửa: import CSV sẽ tạo/đảm bảo bảng `lcni_symbols` tồn tại và upsert dữ liệu thành công.

## 2026-02-15
- Cập nhật trang **Saved Data** theo dạng tab, mỗi tab hiển thị một bảng riêng (Symbols, Market, ICB2, Symbol-Market-ICB, OHLC + Indicators).
- Bổ sung bảng mới `lcni_sym_icb_market` (symbol, market_id, id_icb2) để liên kết dữ liệu symbol với market và ngành ICB2.
- Mở rộng bảng `lcni_symbols` thêm cột `id_icb2` để lưu ngành theo symbol và đồng bộ sang bảng liên kết mới.
- Mở rộng bảng `lcni_ohlc` thêm các cột chỉ báo: nhóm % thay đổi theo kỳ, MA, High/Low theo kỳ, Volume MA, tỷ lệ giá/volume so với MA, MACD, MACD Signal, RSI.
- Bổ sung luồng tính toán lại indicator theo từng symbol/timeframe ngay sau khi đồng bộ dữ liệu OHLC; chỉ tính trên các phiên có dữ liệu thực tế nên tự động bỏ qua ngày nghỉ/lễ.
- Sửa lỗi các cột indicator mở rộng bị `NULL` sau khi seed/update: chuyển việc tính toán indicator sang ngay trong `upsert_ohlc_rows()` để mọi luồng ghi OHLC (sync thường + seed queue) đều tự động tính lại theo công thức.
- Bổ sung `rebuild_missing_ohlc_indicators()` để tự dò các mã/timeframe mà bản ghi mới nhất còn thiếu chỉ báo và tự tính bù.
- Bổ sung cột `trading_index` cho `lcni_ohlc` và tự động đánh số giao dịch liên tục theo từng `symbol` (tăng dần theo `event_time`).
- Bổ sung cột `xay_nen` và cập nhật logic gán nhãn `'xây nền'` theo bộ điều kiện RSI, độ lệch MA, thanh khoản và biên độ biến động giá.
- Cập nhật script SQL MySQL 8 để tính đồng thời `trading_index` và `xay_nen` khi rebuild indicator.
- Bổ sung migration `backfill_ohlc_trading_index_and_xay_nen` để tự quét các series còn thiếu `trading_index`/`xay_nen` và tính bù toàn bộ indicator.
- Điều chỉnh nhãn `xay_nen` để phân biệt rõ trạng thái: `chưa đủ dữ liệu` (chưa đủ phiên tính công thức), `không xây nền` (đã tính nhưng không thỏa điều kiện), `xây nền` (thỏa điều kiện).
- Cập nhật script SQL MySQL 8 cùng chuẩn phân loại `xay_nen` mới để khi rebuild bằng SQL không còn hiển thị `NULL` mơ hồ.

## 2026-02-16

- Bổ sung cột `pha_nen` cho `lcni_ohlc`, tính theo điều kiện phiên trước có `nen_type` thuộc `Nền vừa/Nền chặt` kết hợp `%T-1` và `vol_sv_vol_ma20`; áp dụng cho cả dữ liệu đã có và dữ liệu mới cập nhật.
- Bổ sung migration/backfill `pha_nen` và mở rộng luồng rebuild để tự tính lại đầy đủ các cột `xay_nen`, `xay_nen_count_30`, `nen_type`, `pha_nen` khi thiếu dữ liệu.
- Bổ sung cơ chế đảm bảo index `idx_symbol_index(symbol, trading_index)` cho bảng `lcni_ohlc` để tăng tốc truy vấn join theo symbol + trading_index.
- Nâng cấp tab **OHLC Data + Indicators**: cho phép chọn cột hiển thị bằng checkbox và lọc nhanh danh sách cột bằng thao tác nhập từ khóa rồi nhấn Enter.
- Bổ sung tab **Rule Setting** trong Saved Data để cấu hình tham số tính `xay_nen/xay_nen_count_30/nen_type/pha_nen`; khi lưu tham số mới hệ thống tự động tính lại toàn bộ series.
- Cập nhật script `sql_ohlc_indicators_mysql8.sql` để thêm `pha_nen` và phản ánh công thức tính phá nền trong luồng rebuild SQL.
- Sửa lỗi rebuild `trading_index` đang đánh số theo toàn bộ `symbol` (bỏ qua `timeframe`), dẫn đến sai chỉ số khi một mã có nhiều khung thời gian; đã cập nhật rebuild theo cặp `symbol + timeframe`.
- Tăng độ bền luồng tự vá `rebuild_missing_ohlc_indicators()` bằng cách bổ sung điều kiện kiểm tra thiếu cho các cột `xay_nen`, `xay_nen_count_30`, `nen_type` để tự tính bù khi còn `NULL`.
- Nâng phiên bản migration backfill `xay_nen_count_30/nen_type` lên `v2`, đồng thời rebuild lại `trading_index` theo đúng `timeframe` trong quá trình backfill.
- Chuyển **Rule Setting** thành cụm tab con trong menu **LCNI Data > Saved Data** với 4 nhóm công thức: `xay_nen`, `xay_nen_count_30`, `nen_type`, `pha_nen`; mỗi tab có nút **Lưu & thực thi** riêng để thao tác độc lập.
- Nâng cấp xử lý lưu rule theo hướng cập nhật từng phần (partial update) và hỗ trợ chế độ thực thi lại ngay cả khi giá trị không đổi, tránh ghi đè ngoài ý muốn khi chỉnh theo từng tab con.
- Cải thiện tab **OHLC Data + Indicators**: hiển thị đúng cột theo checkbox ngay khi tải trang, thêm bộ nút chọn nhanh (chọn tất cả/bỏ chọn/chọn cột rule/reset lọc), đổi lọc cột sang realtime khi gõ để thao tác nhanh hơn với nhiều cột.
- Giảm font bảng OHLC để tăng mật độ hiển thị cột trên cùng màn hình.
# 2026-02-16
- Chuyển luồng **Lưu & thực thi Rule** sang chạy nền theo batch (cron), có trạng thái tiến trình realtime trên tab Rule Setting để tránh quá tải/sập admin khi rebuild toàn bộ dữ liệu.
- Bổ sung cột `smart_money` trong `wp_lcni_ohlc`; giá trị trả về `Smart Money` khi đồng thời thỏa `pha_nen = Phá nền`, `tang_gia_kem_vol = Tăng giá kèm Vol` và `xep_hang` thuộc `A++, A+, A, B+` từ bảng `wp_lcni_symbol_tongquan`.



## 2026-02-18
- Bổ sung module LCNi Signals với shortcodes mới `lcni_stock_signals` và `lcni_stock_signals_query` để hiển thị các trường custom (`xay_nen`, `xay_nen_count_30`, `nen_type`, `pha_nen`, `tang_gia_kem_vol`, `smart_money`, `rs_exchange_status`, `rs_exchange_recommend`, `rs_recommend_status`) tại `event_time` gần nhất có dữ liệu.
- Thêm REST endpoint `GET /wp-json/lcni/v1/stock-signals?symbol=XXX` phục vụ module signals.
- Bổ sung shared asset `lcni-stock-sync.js` để đồng bộ đổi symbol giữa chart/overview/signals và sửa lỗi đồng bộ khi đặt nhiều shortcode chart/module trên cùng page (hỗ trợ query param động thay vì cố định `symbol`).
- Tạo tài liệu `SHORTCODES.md` liệt kê toàn bộ shortcode và cách dùng.
- Tăng phiên bản plugin lên `1.7`.

## 2026-02-20
- Refactor module Watchlist lưu danh sách symbol theo `user_meta` key `lcni_watchlist_symbols` (JSON array), bỏ phụ thuộc ghi/xóa vào bảng watchlist cũ.
- Bổ sung shortcode mới `[lcni_watchlist_add_form]` và `[lcni_watchlist_add_button]` (giữ alias tương thích `[lcni_watchlist_add]`), hỗ trợ add/remove realtime qua AJAX + nonce.
- Nâng cấp frontend watchlist: thay icon add trong bảng thành icon delete, xóa dòng realtime không reload, dùng event delegation và đồng bộ trạng thái toàn cục qua `window.lcniWatchlistStore`.
- Bổ sung JS events đồng bộ module: `lcniSymbolAdded`, `lcniSymbolRemoved`, `lcniWatchlistSymbolsChanged`.
- Mở rộng cấu hình admin tại Frontend Settings → Watchlist cho phép đặt label từng column (`column_labels`) và frontend render header theo label cấu hình (fallback key mặc định).
- Cập nhật tài liệu `SHORTCODES.md` cho toàn bộ shortcode watchlist mới.
- Refactor Watchlist row navigation để redirect theo slug page template cấu hình ở Frontend Setting (`/{page-slug}/?symbol={symbol}`), lưu thêm option slug `lcni_watchlist_stock_page` và encode symbol phía client trước khi chuyển trang.
- Cập nhật UX Watchlist column selector: panel mặc định ẩn, mở/đóng bằng nút settings, click outside để tự đóng; panel dùng `position: absolute` nên không đẩy layout bảng.
- Fix đồng bộ zoom/scroll cho lightweight-charts bằng `subscribeVisibleLogicalRangeChange` với cờ `isSyncingRange`, đồng bộ visible range giữa chart chính và toàn bộ chart phụ (volume/macd/rsi/rs).
- Bổ sung cấu hình tiêu đề module frontend (`overview_title`, `chart_title`, `signal_title`) trong Frontend Setting; frontend render theo title cấu hình, fallback về tiêu đề mặc định khi chưa khai báo.

## 2026-02-20
- Cập nhật `lcni-chart.js` để luôn `fitContent()` sau mỗi lần `setData()`, sau đó áp dụng `setVisibleLogicalRange()` theo cấu hình `default_visible_bars`; đồng thời reset viewport khi đổi symbol và bổ sung cờ bật/tắt sync zoom/scroll theo `subscribeVisibleLogicalRangeChange` với `isSyncingRange` tránh loop.
- Mở rộng Frontend Settings → Stock Chart thêm tùy chọn admin `chart_sync_enabled` (bật/tắt đồng bộ zoom/scroll giữa các panel).
- Nâng cấp Frontend Settings → Watchlist: thêm nút “Thêm rule” cho phần màu theo điều kiện (mặc định hiển thị 5 dòng), thêm cấu hình kích thước nút `[lcni_watchlist_add_button]` và thêm cấu hình style/icon cho nút submit của `[lcni_watchlist_add_form]`.
- Cập nhật shortcode Watchlist + assets để áp dụng style admin cho nút add/add-form, thêm hiệu ứng AJAX spinner → check-circle khi add thành công từ form, và chỉnh kích thước nút add để đồng nhất hiển thị.
- Tạo module mới **LCNI Filter** (`modules/filter`) với shortcode `[lcni_stock_filter]`, data mode `all_symbols`, panel điều kiện lọc realtime (debounce + AJAX), pagination, cập nhật riêng `tbody` không reload trang và tích hợp nút `lcni_watchlist_add_button` trên từng dòng.
- Mở rộng REST data provider để hỗ trợ truy vấn toàn bộ symbol + filter động từ frontend qua endpoint `POST /wp-json/lcni/v1/filter/list`.
- Tái sử dụng kiến trúc bảng Watchlist (column config, column labels, style config, conditional color rules) cho Filter; thêm cài đặt admin Frontend Settings → Filter với options `lcni_filter_allowed_columns` và `lcni_filter_default_conditions`.

## 2026-02-21
- Refactor module Filter theo kiến trúc Repository + Service: thêm `SnapshotRepository` để gom toàn bộ SQL filter, thêm `FilterService` cho validate/request mapping/output formatting, và cập nhật REST handler chỉ còn gọi service.
- Bổ sung `CacheService` dùng `wp_cache_*` với fallback transient, tích hợp cache 60 giây cho payload filter và cache 5 phút cho danh sách distinct values + symbol list.
- Tối ưu query filter theo phase 2: hỗ trợ `LIMIT/OFFSET` tùy chọn, chỉ SELECT các cột hiển thị, và JOIN động theo cột/filter đang dùng để giảm join dư thừa; thêm ghi chú TODO cho bước EXPLAIN trên production.

## 2026-02-21 (v2.0.1)
- Bổ sung sticky header cho bảng Watchlist + Filter bằng wrapper `.lcni-table-wrapper` và class bảng `.lcni-table`, giữ nguyên layout/shortcode cũ, không dùng JS clone header.
- Mở rộng endpoint Filter và Watchlist với chế độ `mode=refresh` chỉ trả về `tbody` rows; frontend auto refresh mỗi 15 giây bằng `setInterval` và cập nhật `tbody.innerHTML` không reload trang.
- Điều chỉnh luồng Filter frontend: bỏ gọi AJAX theo `onchange/input`, chỉ gọi một lần khi click nút `.btn-apply-filter`.
- Thêm cấu hình admin `lcni_filter_default_values` (JSON) trong Frontend Settings → Filter để pre-check checkbox/range và auto apply ngay khi load trang.
- Thêm Frontend Settings → Style Config → Button Style (`button_background_color`, `button_text_color`, `button_height`, `button_border_radius`, `button_icon_class`), render dynamic CSS `.lcni-btn` từ option và áp dụng cho các nút Filter/Watchlist.
- Nâng version plugin lên `2.0.1`.

## 2026-02-21 (v2.0.1.1)
- Refactor Frontend Setting → Style Config thành **Button Settings** chi tiết theo từng key button, lưu tập trung vào option `lcni_button_style_config` theo cấu trúc mảng con cho từng button (`background_color`, `text_color`, `hover_*`, `height`, `border_radius`, `padding_left_right`, `font_size`, `icon_class`, `icon_position`, `label_text`).
- Bổ sung class quản lý cấu hình `LCNI_Button_Style_Config` để sanitize dữ liệu, build dynamic CSS theo class `.lcni-btn` + `.lcni-btn-{button_key}`, render icon/label động và tái sử dụng cho nhiều module.
- Cập nhật frontend Filter/Watchlist/Stock Query Form để dùng class riêng từng button (ví dụ `lcni-btn-btn_apply_filter`, `lcni-btn-btn_watchlist_add`, `lcni-btn-btn_stock_view`) và render icon FontAwesome từ config thay vì hardcode icon HTML.
- Plugin enqueue FontAwesome 6 riêng bằng handle `lcni-fa`, không phụ thuộc/remove asset FontAwesome của theme.
- Giữ nguyên shortcode, layout tổng thể và hành vi nghiệp vụ hiện có; chỉ bổ sung lớp cấu hình style + icon cho button theo yêu cầu.
- Nâng version plugin lên `2.0.1.1`.
