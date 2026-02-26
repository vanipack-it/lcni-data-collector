## 2026-02-26 22:55 (v2.2.6)
- Nâng version plugin lên `2.2.6` và cập nhật log thay đổi cho bản vá đồng bộ snapshot OHLC Latest.
- Fix cơ chế auto sync `wp_lcni_ohlc_latest`: cron/watchdog sẽ tự chạy lại không chỉ khi dữ liệu stale mà cả khi phát hiện snapshot bị thiếu row so với số cặp `(symbol, timeframe)` trong bảng gốc `wp_lcni_ohlc`.
- Fix manual sync trong tab **Update Data**: trước khi refresh sẽ kiểm tra health snapshot, nếu bảng latest đang trống/thiếu row thì chủ động `TRUNCATE` và nạp lại toàn bộ để phục hồi dữ liệu.
- Bổ sung cơ chế retry cho manual refresh: nếu lần `REPLACE INTO` đầu thất bại, hệ thống sẽ tự làm sạch bảng latest và chạy lại một lần để tăng khả năng tự phục hồi.
- Bổ sung hàm health check snapshot để chuẩn hóa kiểm tra `expected_rows`, `actual_rows`, `missing_rows` và tái sử dụng cho cả runtime manager lẫn DB layer.

## 2026-02-26 19:05 (v2.2.5)
- Nâng version plugin lên `2.2.5` và cập nhật log kèm mốc ngày giờ cụ thể để dễ theo dõi thời điểm cập nhật.
- Sửa logic đồng bộ snapshot OHLC để bảng `wp_lcni_ohlc_latest` chỉ giữ 1 dòng mới nhất cho mỗi cặp `(symbol, timeframe)` bằng 1 SQL duy nhất chạy qua `$wpdb->query($sql)` với `INNER JOIN` vào `MAX(event_time)`.
- Đồng bộ stored procedure refresh snapshot theo cùng logic `JOIN + MAX(event_time)` để cron/manual sync luôn lấy đúng bản ghi mới nhất theo từng `(symbol, timeframe)`.
- Bổ sung kiểm tra/đảm bảo ràng buộc `UNIQUE KEY uniq_symbol_tf (symbol, timeframe)` trên bảng snapshot để `REPLACE INTO` hoạt động đúng theo yêu cầu.
- Bổ sung kiểm tra/đảm bảo index hiệu năng `idx_symbol_tf_time (symbol, timeframe, event_time)` trên bảng gốc `wp_lcni_ohlc` để tối ưu truy vấn lấy bản ghi mới nhất.

## 2026-02-26 18:23 (v2.2.4)
- Nâng version plugin lên `2.2.4` và cập nhật log có mốc ngày giờ cụ thể để dễ nắm bắt thời điểm thay đổi.
- Fix Frontend Settings → Filter → Style: `Row height` sau khi nhập số và lưu đã áp dụng thực tế ra bảng filter frontend.
- Bổ sung thông báo kết quả filter đặt cùng hàng với `btn_filter_apply` trên desktop và hiển thị dưới topbar trên mobile, theo mẫu: `Bộ lọc có {number} cổ phiếu thỏa mãn các tiêu chí ...`.
- Fix Frontend Settings → Watchlist → Column → Selected order: sau khi kéo-thả lưu, thứ tự header và các cột value ở tbody luôn đồng bộ khi auto refresh, không còn lệch gây nhầm lẫn.
- Fix Frontend Settings → Filter → Filter Criteria → Selected order: đảm bảo thứ tự tiêu chí kéo-thả được thực thi đúng ra filter panel frontend.

## 2026-02-26 18:10 (v2.2.3)
- Nâng version plugin lên `2.2.3` và cập nhật log với mốc ngày giờ chi tiết để dễ nắm bắt thời điểm thay đổi.
- Fix logic đồng bộ `wp_lcni_ohlc_latest`: lấy nến mới nhất theo từng cặp `symbol + timeframe` (không còn gộp theo `symbol`) để dữ liệu latest đúng khi filter theo ngày gần nhất.
- Chuẩn hóa khóa chính bảng latest thành `PRIMARY KEY (symbol, timeframe)` để `REPLACE INTO` hoạt động đúng theo từng khung thời gian.
- Bổ sung/đảm bảo index bắt buộc `idx_symbol_tf_time (symbol, timeframe, event_time)` trên bảng gốc `wp_lcni_ohlc` để tăng tốc truy vấn lấy bản ghi mới nhất.

## 2026-02-26 17:35 (v2.2.2)
- Nâng version plugin lên `2.2.2` và cập nhật log với mốc ngày giờ cụ thể để dễ nắm bắt thời điểm thay đổi.
- Fix Frontend Settings → Filter → Filter Criteria: đảm bảo thứ tự field kéo-thả được lưu và thực thi ổn định ra frontend theo option `lcni_filter_criteria_columns`/`lcni_filter_criteria_column_order`.
- Fix bổ sung tab con `Filter Page` trong Frontend Settings → Filter để chọn page liên kết chứa module filter (thay cho slug mặc định `sug-filter`), tương tự cơ chế chọn stock detail page ở Watchlist.
- Fix nút thêm vào watchlist trong filter panel: khi click sẽ mở popup chọn watchlist có sẵn, nếu chưa có thì cho tạo watchlist mới trước khi thêm mã.
- Fix mobile UX: khi click `btn_filter_apply` hệ thống tự động ẩn filter panel để không che bảng kết quả sau lọc.

## 2026-02-26 15:30 (v2.2.1)
- Nâng version plugin lên `2.2.1` và cập nhật log có mốc thời gian ngày giờ cụ thể để dễ theo dõi.
- Điều chỉnh hành vi click bảng Watchlist/Filter: chỉ mở link chi tiết `...?symbol={SYMBOL}` khi click đúng cell `symbol`; click các cell khác không còn mở trang chi tiết.
- Bổ sung deep-link filter theo cell value cho Watchlist/Filter/Overview/Signals: khi click cell/field có nằm trong Filter Criteria sẽ mở `.../sug-filter/?apply_filter=1&{field}={value}`.
- Frontend Filter hỗ trợ auto-apply từ query `apply_filter=1`: tự nạp giá trị vào panel filter và chạy kết quả ngay, không cần bấm `Apply Filter` thủ công.
- Bổ sung cấu hình URL filter link cho frontend qua option `lcni_filter_link_page` (mặc định `sug-filter`) để dùng làm đích điều hướng cho các deep-link filter.

## 2026-02-26 14:59 (v2.2.0)
- Nâng version plugin lên `2.2.0` và ghi log kèm mốc thời gian cập nhật chi tiết theo định dạng `YYYY-MM-DD HH:MM` để dễ theo dõi.
- Frontend Filter panel bổ sung nút `Thêm vào Watchlist` (`btn_filter_add_watchlist_bulk`): sau khi bấm `btn_filter_apply`, user có thể thêm toàn bộ mã trong kết quả vào watchlist đã có hoặc nhập tên để tạo watchlist mới ngay trong popup.
- Bổ sung REST endpoint `POST /wp-json/lcni/v1/watchlist/add-symbols` để thêm hàng loạt symbol vào watchlist, trả về thống kê `requested_count`, `added_count`, `duplicate_count` nhằm giúp frontend hiển thị kết quả chính xác.
- Frontend Filter panel bổ sung nút `Xuất Excel` (`btn_filter_export_excel`) để xuất kết quả filter hiện tại ra file với tên mặc định `LCNi_Filter_{ten-bo-loc}.xlsx`; tên bộ lọc được chuẩn hóa `lowercase`, bỏ dấu tiếng Việt và nối bằng dấu gạch ngang.

## 2026-02-26 14:36 (v2.1.9)
- Nâng version plugin lên `2.1.9` và cập nhật change log kèm mốc ngày giờ cụ thể để tiện theo dõi.
- Filter Panel bổ sung thêm dropdown `LCNi Filter Template` (bộ lọc do Admin tạo), hiển thị cạnh dropdown `Saved Filter` (bộ lọc của user); tab Frontend Settings → Filter `Filter Criteria Default` được đồng bộ thành nguồn template mặc định cho dropdown này.
- Bổ sung tùy chỉnh style hiệu ứng dropdown trong Frontend Settings → Filter → Style Config cho cả `Saved Filter` và `LCNi Filter Template` (background/text/border + label).
- Tối ưu lần truy cập đầu tiên: hệ thống tự động tải bảng kết quả ngay theo bộ lọc mặc định, không cần bấm `Apply Filter` thủ công.
- Với user đã đăng nhập: ưu tiên bộ lọc mặc định của user; nếu chưa có thì tự fallback sang bộ lọc gần nhất user đã xem; các thao tác sau đó vẫn giữ cơ chế Apply như hiện tại.

## 2026-02-26 14:03 (v2.1.8)
- Nâng version plugin lên `2.1.8` và ghi log mốc thời gian cập nhật cụ thể (YYYY-MM-DD HH:MM) để tiện theo dõi.
- Đổi tab `Change Logs` thành `Report` và tách thành 2 tab con: `Change Logs` + `Report System`.
- Bổ sung `Report System` hiển thị: số tác vụ chạy ngầm + % tổng quan, danh sách tác vụ nền đã hoàn thành (thời gian bắt đầu/kết thúc), symbol cập nhật gần nhất kèm thời gian, tổng số symbol đã cập nhật trong ngày hiện tại, và tiến độ tác vụ tự động tính toán `Rule rebuild` khi có dữ liệu mới.

## 2026-02-26 14:45 (v2.1.7)
- Nâng version plugin lên `2.1.7` và cập nhật log kèm mốc ngày giờ cụ thể để tiện theo dõi.
- Frontend Settings → Filter Criteria: chuyển sang layout 2 cột tỷ lệ 80:20; cột trái chọn field bằng checkbox, cột phải hiển thị field đã chọn và hỗ trợ kéo-thả để lưu thứ tự điều kiện filter hiển thị ở frontend.
- Frontend Filter panel: bổ sung nút `Clear` (key button `btn_filter_clear`) để xóa nhanh toàn bộ điều kiện đã chọn; nút này có thể cấu hình trong Frontend Settings → Style Config → Button.
- Frontend Filter: nút `btn_filter_apply` hiển thị realtime số mã đủ điều kiện ngay trên label button; bảng kết quả chỉ tải khi click `Apply Filter` (không auto load khi mở trang).
- Đảm bảo `btn_filter_apply` luôn hiển thị đầy đủ cả icon + text trên desktop và mobile.

## 2026-02-26 13:19 (v2.1.6)
- Nâng version plugin lên `2.1.6` và cập nhật log kèm thời điểm ngày giờ cụ thể để dễ theo dõi.
- Fix pipeline đồng bộ `wp_lcni_ohlc_latest`: kiểm tra stale theo **thời điểm chạy sync gần nhất** (runtime timestamp) thay vì `MAX(event_time)` của dữ liệu, tránh trường hợp `event_time` nằm tương lai làm cron/watchdog bỏ qua refresh và khiến dữ liệu latest bị đứng.
- Bổ sung change log cho mỗi lần chạy OHLC latest snapshot (manual/wp_cron/watchdog): ghi rõ nguồn chạy, số row ảnh hưởng, `started_at`, `ended_at`, trạng thái thành công/thất bại và lỗi (nếu có) để dễ audit thời điểm cập nhật.
- Fix Frontend Setting → Style Config → Cell Color: đọc danh sách rule từ option global `lcni_global_cell_color_rules` khi render màn hình admin, đảm bảo hiển thị đầy đủ nhiều rule trên cùng 1 field (ví dụ `pct_t_1 > 0`, `< 0`, `= 0`) thay vì phụ thuộc dữ liệu cũ theo watchlist settings.

## 2026-02-26 08:07 (v2.1.5)
- Nâng version plugin lên `2.1.5` và cập nhật log kèm mốc ngày giờ cụ thể để dễ theo dõi.
- Fix Frontend Setting → LCNi Signals: mở danh sách field theo toàn bộ cột hiện có của bảng `wp_lcni_ohlc_latest` (thay vì cố định 9 field), admin có thể chọn đầy đủ cột và kéo-thả thứ tự hiển thị theo layout 80:20.
- Fix Frontend Setting → Stock Overview: bổ sung layout chọn field 80:20, cột phải hiển thị field đã chọn và hỗ trợ kéo-thả để lưu thứ tự hiển thị frontend.
- Đồng bộ frontend Signals để đọc `field_labels` từ admin config và render thứ tự box theo danh sách đã chọn/sắp xếp, đảm bảo di chuyển đồng thời cả label + value.
- Fix watchlist mobile: nhóm nút `btn_watchlist_add`, `btn_watchlist_new`, `btn_watchlist_delete`, `btn_watchlist_setting` hiển thị trên cùng 1 hàng full-width; khi thiếu không gian sẽ tự ẩn text và giữ icon.

## 2026-02-26 07:22 (v2.1.4)
- Nâng version plugin lên `2.1.4` và ghi log kèm mốc ngày giờ cập nhật cụ thể để dễ theo dõi.
- Frontend Setting -> Data Format: bổ sung tùy chỉnh định dạng `event_time` (`DD-MM-YYYY` hoặc `number`), mặc định hiển thị ngày để các module frontend dùng đồng bộ.
- Fix Frontend Setting -> Watchlist/Filter -> Column -> Selected order: chuẩn hóa thứ tự cột theo đúng danh sách kéo thả (label và value di chuyển cùng nhau), đồng thời khóa cột sticky để không thể kéo đổi vị trí.
- Fix Frontend Setting -> Watchlist -> Style Config -> Cell Color: mở rộng sanitize operator cho nhiều rule (`contains`, `not_contains`) để các rule sau lưu không bị rơi về mặc định.
- Fix Frontend Setting -> Watchlist -> Style Config -> Cell to Cell Color: giữ dữ liệu rule ở pipeline settings dùng chung để frontend modules nhận và thực thi đúng.

## 2026-02-26 02:53 (v2.1.3)
- Nâng version plugin lên `2.1.3` và ghi rõ mốc ngày giờ cập nhật để dễ theo dõi.
- Fix lỗi `trading_index` bị nhảy cóc khi import/upsert dữ liệu `wp_lcni_ohlc`: gom toàn bộ luồng rebuild về 1 pipeline `symbol + timeframe`, luôn đánh lại `trading_index` từ `1..n` theo `event_time` (cũ -> mới) trước khi tính indicator.
- Chuẩn hóa công thức tính `pct_t_1`, `pct_t_3`, `pct_1w`, `pct_1m`, `pct_3m`, `pct_6m`, `pct_1y`, `ma10/20/50/100/200`, `vol_ma10/20`, `macd`, `macd_signal`, `rsi` theo chuỗi `trading_index` để tránh lệch do ngày nghỉ/thứ 7/chủ nhật.
- Cập nhật script `sql_ohlc_indicators_mysql8.sql` theo cùng nguyên tắc `symbol + timeframe` và số phiên giao dịch (1w=5, 1m=21, 3m=63, 6m=126, 1y=252).

## 2026-02-26 11:20 (v2.1.2)
- Nâng version plugin lên `2.1.2` và ghi rõ mốc ngày giờ cập nhật để dễ theo dõi.
- Fix Frontend Setting → Style Config → Cell Color: lưu rules đồng thời vào option global `lcni_global_cell_color_rules` để các module frontend (đặc biệt Filter/Watchlist) thực thi ngay.
- Fix Frontend Setting → Style Config → Cell to Cell Color: chuẩn hóa hỗ trợ toán tử `>=`, `<=`, `!=` ở Watchlist service để rule đã lưu không bị loại bỏ khi render frontend.
- Bổ sung Frontend Setting → Watchlist → Columns layout 80:20, cột phải hiển thị danh sách field đã chọn + kéo thả thứ tự; lưu thứ tự để frontend Watchlist render cột theo thứ tự từ trái sang phải.

## 2026-02-26 02:07 (v2.1.1)
- Nâng version plugin lên `2.1.1` và ghi log mốc thời gian cập nhật cụ thể.
- Bổ sung cột `hanh_vi_gia_1w` cho `wp_lcni_ohlc` trong schema tạo mới + cơ chế tự thêm cột trên hệ thống đang chạy.
- Thêm migration `lcni_ohlc_hanh_vi_gia_1w_backfilled_v1` để backfill dữ liệu cũ theo công thức so sánh giá/khối lượng với 5 phiên trước.
- Đảm bảo pipeline `rebuild_ohlc_indicators` tự tính `hanh_vi_gia_1w` cho dữ liệu mới insert/update về sau.

## 2026-02-26 09:30 (v2.1.0)
- Nâng version plugin lên `2.1.0` và cập nhật log có mốc ngày giờ cụ thể để tiện theo dõi thay đổi.
- Bổ sung cột `rsi_status` và `hanh_vi_gia` cho `wp_lcni_ohlc` trong schema tạo bảng mới + cơ chế tự thêm cột cho hệ thống đang chạy.
- Thêm migration `lcni_ohlc_rsi_status_hanh_vi_gia_backfilled_v1` để tính lại dữ liệu các dòng cũ: cập nhật `rsi_status` theo ngưỡng RSI (Tham lam/Sợ hãi/Quá mua/Quá bán) và `hanh_vi_gia` theo so sánh giá + khối lượng với phiên trước.
- Đảm bảo dữ liệu dòng mới luôn có `rsi_status` + `hanh_vi_gia` ngay trong pipeline `rebuild_ohlc_indicators`.
- Bổ sung index `idx_symbol_trading (symbol, trading_index)` nếu chưa có để tối ưu các truy vấn/join theo symbol + trading_index.
- Fix thực thi Frontend Setting → Style Config: module Filter nhận và áp dụng `global cell color rules` + `cell-to-cell rules`; mở rộng toán tử hỗ trợ `>=`, `<=`, `!=` cho cả rule lưu và rule render.
- Cải thiện đồng bộ thứ tự cột Filter: đổi `tableSettingsStorageKey` theo hash cấu hình cột để frontend tự nhận thứ tự mới sau khi admin lưu, không bị giữ cache session cũ.
- Đồng bộ Watchlist để dùng thêm global value color rules cùng bộ rule hiện có.

## 2026-02-25 22:43 (v2.0.9)
- Frontend Filter: chỉnh hover row rõ ràng, header về normal weight, căn phải cho ô số và căn trái cho ô text.
- Frontend Watchlist: căn phải cho ô số, căn trái cho ô text, header về normal weight.
- Frontend Settings > Filter > Table Columns: bổ sung bố cục 80:20, cột phải hiển thị danh sách cột đã chọn và hỗ trợ kéo-thả để lưu thứ tự hiển thị ra frontend.
- Frontend Settings > Watchlist > Default Columns: bổ sung bố cục 80:20 và kéo-thả thứ tự cột desktop để đồng bộ hiển thị frontend.
- Fix lưu Style Config > Cell Color: giữ/persist đúng rules khi save và đồng bộ lại cho filter/watchlist.
- Bổ sung Style Config > Cell to Cell Color: 9 cột rule, mặc định 5 dòng, có nút thêm rule và áp dụng ra frontend table (Filter/Watchlist).

## 2026-02-25 10:30
- Release v2.0.8.
- Gom cấu hình Style Config vào Frontend Setting theo nhóm Button/Form/Cell Color: chuẩn hóa nhóm nút trong bảng vs ngoài bảng (đồng bộ màu nền/text/hover), gom Saved filters + Watchlist dropdown/input style về Form, và gom rule màu theo value (7 cột) dùng chung cho Watchlist/Filter/Stock Overview/LCNI Signals.
- Bổ sung cấu hình rule màu toàn cục hỗ trợ 5 dòng mặc định + nút thêm dòng, điều kiện `=`, `>`, `<`, `contains`, `not_contains`, icon fontawesome và vị trí icon trái/phải.
- Cập nhật responsive Watchlist mobile: giữ hiển thị cả text + icon cho button, và ép Watchlist dropdown + Symbol input hiển thị 1 dòng tỷ lệ 50%:50%.

## 2.0.4
- Refactor chart runtime qua abstraction `LCNIChartEngine` và thêm engine ECharts có fallback Lightweight qua `window.lcniChartEngineType`.
- Mở rộng cấu hình style cho filter/watchlist popup-input, thêm lưu `lcni_filter_style_config`.
- Cập nhật filter/watchlist table cho sticky + hover + table-layout auto + sort client-side + filter hide toggle.
- Nâng cấp watchlist shortcode runtime để có input thêm mã trực tiếp, validate uppercase/trim và toast rõ ràng.

# Development Log



## 2026-02-21 (v2.0.3)
- Fix luồng click row ở Filter theo event delegation toàn cục (`tr[data-symbol]`) và bỏ trigger khi click `.lcni-btn`, giúp hành vi đồng nhất với Watchlist.
- Nâng cấp layout bảng Filter với wrapper `.lcni-table-scroll` + `.lcni-table` để hỗ trợ scroll ngang ổn định, sticky header và sticky cột symbol mà không clone header bằng JS.
- Di chuyển cụm Saved Filter (dropdown, Reload, Save, Delete) vào trong panel Filter; bổ sung nút `Reload Filter` để nạp lại `filter_config` từ DB nhưng không auto apply.
- Bổ sung popup bắt buộc đăng nhập/đăng ký khi Save Filter hoặc Add to Watchlist từ Filter nếu user chưa đăng nhập (không còn silent fail).
- Bổ sung modal chọn watchlist khi Add từ Filter: cho phép chọn watchlist bằng radio, tạo watchlist mới khi chưa có, và gọi REST `/watchlist/add-symbol` với `watchlist_id` không reload trang.
- Mở rộng button style registry theo cơ chế auto-register cho các key mới: `btn_watchlist_new`, `btn_watchlist_delete`, `btn_filter_reload`, `btn_filter_save`, `btn_filter_delete`, `btn_filter_apply` (lưu trong `lcni_button_style_config`).
- Cập nhật nút Apply Filter hiển thị số lượng kết quả sau khi apply theo định dạng `Apply Filter (N)` dựa trên phản hồi REST `{ rows, total }`.
- Tăng version plugin lên `2.0.3`.


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

## 2026-02-22 (v2.0.6)
- Refactor shortcode `[lcni_stock_overview]` và `[lcni_stock_chart]` để chỉ render container tối giản với `data-symbol`, không render dữ liệu trực tiếp từ PHP.
- Frontend JS overview/chart chuyển sang fetch dữ liệu sau `DOMContentLoaded` qua REST tương ứng (`/wp-json/lcni/v1/stock-overview` và `/wp-json/lcni/v1/candles`) rồi render trong container.
- Bổ sung xử lý lỗi/empty response chuẩn hóa về thông báo `No data` cho cả overview và chart.
- Loại bỏ phụ thuộc router/query injection trong 2 shortcode trên: chỉ nhận symbol tường minh qua thuộc tính shortcode.
- Thêm guard chống khởi tạo JS trùng lặp (`window.__lcniOverviewInitialized`, `window.__lcniChartInitialized` + cờ `data-lcni-initialized`).
- Giữ nguyên nguyên tắc enqueue script chỉ khi shortcode xuất hiện (enqueue trong hàm render shortcode).

## 2026-02-22 (v2.0.8)
- Module `modules/chart` chuyển hoàn toàn sang Apache ECharts: bỏ dependency Lightweight Charts CDN, thêm script local `assets/vendor/echarts.min.js` + engine tách file `modules/chart/assets/lcni-echarts-engine.js`.
- Refactor `modules/chart/assets/chart.js` theo lifecycle rõ ràng: DOM scan, fetch candles giữ nguyên endpoint `/wp-json/lcni/v1/candles`, thêm `AbortController`, loading overlay, error overlay và chặn race condition khi đổi symbol nhanh.
- Tối ưu hiệu năng chart cho 0-10k daily users: giới hạn cứng tối đa 500 candles ở frontend, dùng `useDirtyRect`, `progressive`, `progressiveThreshold`, cập nhật dữ liệu bằng `setOption` + `replaceMerge` thay vì rebuild full option mỗi lần.
- Bổ sung memory-safety: kiểm tra `echarts.getInstanceByDom` trước init, dispose instance khi destroy, resize bằng `ResizeObserver` có debounce, tránh listener trùng lặp.
- Giữ nguyên shortcode `[lcni_stock_chart]`, kiến trúc PHP module, và REST API contract hiện có.
