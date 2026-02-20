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
