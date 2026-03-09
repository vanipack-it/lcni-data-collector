# LCNI Shortcodes

Tài liệu tổng hợp shortcodes của plugin `LCNI Data Collector`.

## 1) Bộ chart cổ phiếu

### `[lcni_stock_chart]`
Hiển thị chart cho **1 cổ phiếu cố định**.

Ví dụ:
```text
[lcni_stock_chart symbol="HPG" limit="250" height="480"]
```

### `[lcni_stock_chart_query]`
Hiển thị chart theo mã lấy từ query string.

Ví dụ:
```text
[lcni_stock_chart_query param="symbol" default_symbol="VNINDEX" limit="200"]
```

### `[lcni_stock_query_form]`
Form nhập mã cổ phiếu để đổi symbol ngay trên cùng trang.

Ví dụ:
```text
[lcni_stock_query_form param="symbol" placeholder="Nhập mã cổ phiếu" button_text="Xem chart"]
```

## 2) Bộ tổng quan doanh nghiệp

### `[lcni_stock_overview]`
Hiển thị overview cho **1 mã cố định**.

### `[lcni_stock_overview_query]`
Hiển thị overview theo query symbol.

## 3) Bộ thông số LCNi Signals

### `[lcni_stock_signals]`
Hiển thị các thông số LCNi custom cho **1 mã cố định**.

### `[lcni_stock_signals_query]`
Hiển thị LCNi Signals theo query param.

## 4) Watchlist

### `[lcni_watchlist]`
Render bảng watchlist theo user đăng nhập.

### `[lcni_watchlist_add_form]`
Render form thêm symbol vào watchlist (input + nút `Thêm`).

- Symbol được chuẩn hóa `trim + uppercase`.
- Không thêm trùng symbol đã có trong watchlist.
- Sau khi thêm thành công: tự clear input, phát sự kiện realtime và đồng bộ toàn bộ icon watchlist.

Ví dụ:
```text
[lcni_watchlist_add_form]
```

### `[lcni_watchlist_add_button symbol="HPG"]`
Render nút thêm/xóa watchlist dùng ở mọi nơi.

- Nếu không truyền `symbol`, shortcode sẽ fallback từ query `?symbol=` hiện tại.
- Khi thêm thành công, frontend dispatch sự kiện:

```js
window.dispatchEvent(new CustomEvent('lcniSymbolAdded', { detail: symbol }))
```

Ví dụ:
```text
[lcni_watchlist_add_button symbol="HPG"]
```

> Ghi chú tương thích ngược: shortcode cũ `[lcni_watchlist_add]` vẫn hoạt động, alias về cùng logic với `[lcni_watchlist_add_button]`.


## 5) Bộ lọc cổ phiếu (Filter module)

### `[lcni_stock_filter]`
Render bảng filter cổ phiếu của module filter.

- Dữ liệu lấy qua REST endpoint `lcni/v1/filter/list`.
- Tự động bật tính năng lưu filter/watchlist khi user đăng nhập.
- Nút mở trang chi tiết cổ phiếu dùng slug đã cấu hình trong option `lcni_watchlist_stock_page`.

Ví dụ:
```text
[lcni_stock_filter]
```

### `[lcni_filter]` (alias)
Alias của `[lcni_stock_filter]`, dùng khi muốn shortcode ngắn gọn hơn.

Ví dụ:
```text
[lcni_filter]
```


## 6) Member module

## Industry monitor module

### `[lcni_industry_monitor]`
Shortcode gốc, giữ tương thích ngược.

Ví dụ:
```text
[lcni_industry_monitor]
```

### `[lcni_industry_monitor_compact]`
Shortcode rút gọn để nhúng bài viết/dashboard.

Thuộc tính hỗ trợ:
- `id_icb2`: danh sách ID ICB2, phân tách dấu phẩy.
- `session`: số phiên gần nhất cần hiển thị.
- `metric`: metric mặc định (tuỳ chọn).
- `timeframe`: mặc định `1D`.

Ví dụ:
```text
[lcni_industry_monitor_compact id_icb2="1,2,3" session="5" metric="industry_return"]
```

### `[lcni_member_login]`
Render form đăng nhập user WordPress mặc định ở frontend.

Ví dụ:
```text
[lcni_member_login]
```

### `[lcni_member_register]`
Render form đăng ký user WordPress ở frontend.

Ví dụ:
```text
[lcni_member_register]
```

## 7) Recommend module

### `[lcni_signals]`
Render bảng danh sách tín hiệu từ Recommend Engine.

Thuộc tính hỗ trợ:
- `rule_id`: lọc theo rule cụ thể.
- `status`: lọc trạng thái (`open`/`closed`).
- `symbol`: lọc theo mã.
- `limit`: giới hạn số dòng (mặc định `20`).

Ví dụ:
```text
[lcni_signals status="open" limit="50"]
```

### `[lcni_performance]`
Render bảng hiệu suất theo rule.

Thuộc tính hỗ trợ:
- `rule_id`: chỉ hiển thị hiệu suất của một rule cụ thể (tuỳ chọn).

Ví dụ:
```text
[lcni_performance]
[lcni_performance rule_id="3"]
```

### `[lcni_signal]`
Render card tín hiệu open gần nhất của một mã.

Thuộc tính hỗ trợ:
- `symbol`: mã cổ phiếu cần xem.

Ví dụ:
```text
[lcni_signal symbol="HPG"]
```
