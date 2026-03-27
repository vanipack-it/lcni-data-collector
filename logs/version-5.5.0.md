# LCNI Data Collector v5.5.0

## Added
- Frontend Settings có thêm tab con **Recommend Signal** trong nhóm Frontend Setting, ngang hàng các tab LCNi Signals / Stock Overview / Watchlist.
- Tab Recommend Signal có 2 khu vực cấu hình:
  - **Columns**: chọn cột hiển thị cho shortcode `[lcni_signals]` từ 3 bảng `wp_lcni_recommend_rule`, `wp_lcni_recommend_signal`, `wp_lcni_ohlc_latest` (mapping theo key `rule__`, `signal__`, `ohlc__`).
  - **Style**: cấu hình style table tương tự Watchlist (font, text/background, border, border radius, font size, header/value colors, row divider, row hover, sticky column, sticky header).
- Hỗ trợ kéo thả sắp xếp thứ tự cột trong tab Columns và đồng bộ giá trị `column_order` khi lưu.

## Changed
- Shortcode `[lcni_signals]` đọc cấu hình mới từ option `lcni_frontend_settings_recommend_signal` để render danh sách cột theo thứ tự admin đã chọn.
- `SignalRepository::list_signals()` hỗ trợ chọn cột động theo nguồn dữ liệu signal/rule/ohlc_latest.

## Defaults
- Font: `inherit`
- Border: `1px solid #e5e7eb`
- Border radius: `8`
- Header label font size: `14px`
- Row font size: `14px`
- Row divider width: `1px`
- Header row height: `30px`
- Sticky column: `signal__symbol`
- Sticky header row: disabled
