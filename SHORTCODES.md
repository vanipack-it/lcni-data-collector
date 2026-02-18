# LCNI Shortcodes

Tài liệu tổng hợp shortcodes của plugin `LCNI Data Collector`.

## 1) Bộ chart cổ phiếu

### `[lcni_stock_chart]`
Hiển thị chart cho **1 cổ phiếu cố định**.

Ví dụ:
```text
[lcni_stock_chart symbol="HPG" limit="250" height="480"]
```

- `symbol`: mã cổ phiếu cố định.
- `limit`: số nến tối đa.
- `height`: chiều cao chart chính (px).

### `[lcni_stock_chart_query]`
Hiển thị chart theo mã lấy từ query string (hữu ích khi dùng cùng form/link đổi mã).

Ví dụ:
```text
[lcni_stock_chart_query param="symbol" default_symbol="VNINDEX" limit="200"]
```

- `param`: tên query param trên URL.
- `default_symbol`: mã mặc định nếu URL chưa có.

### `[lcni_stock_query_form]`
Form nhập mã cổ phiếu để đổi symbol ngay trên cùng trang.

Ví dụ:
```text
[lcni_stock_query_form param="symbol" placeholder="Nhập mã cổ phiếu" button_text="Xem chart"]
```

## 2) Bộ tổng quan doanh nghiệp

### `[lcni_stock_overview]`
Hiển thị overview cho **1 mã cố định**.

Ví dụ:
```text
[lcni_stock_overview symbol="FPT"]
```

### `[lcni_stock_overview_query]`
Hiển thị overview theo query symbol, đồng bộ với chart khi đặt chung page.

Ví dụ:
```text
[lcni_stock_overview_query param="symbol" default_symbol="FPT"]
```

## 3) Bộ thông số LCNi Signals (mới)

### `[lcni_stock_signals]`
Hiển thị các thông số LCNi custom cho **1 mã cố định**:
`xay_nen`, `xay_nen_count_30`, `nen_type`, `pha_nen`, `tang_gia_kem_vol`, `smart_money`, `rs_exchange_status`, `rs_exchange_recommend`, `rs_recommend_status`.

Ví dụ:
```text
[lcni_stock_signals symbol="SSI" version="1.7"]
```

### `[lcni_stock_signals_query]`
Hiển thị LCNi Signals theo query param, hỗ trợ đồng bộ với chart/overview khi thay đổi cổ phiếu trên cùng trang.

Ví dụ:
```text
[lcni_stock_signals_query param="symbol" default_symbol="SSI" version="1.7"]
```

- Chỉ hiển thị dữ liệu tại `event_time` mới nhất đang có dữ liệu của mã.
- Có thể phối hợp với các shortcode chart/overview để admin đặt linh hoạt nhiều module trên cùng page.

## 4) Watchlist (v1.8)

### `[lcni_watchlist]`
Hiển thị bảng watchlist của user đang đăng nhập.

Ví dụ:
```text
[lcni_watchlist]
```

Nếu chưa đăng nhập, shortcode sẽ hiển thị link đến trang đăng nhập/đăng ký.

### `[lcni_watchlist_add]`
Nút thêm nhanh mã cổ phiếu vào watchlist.

Ví dụ:
```text
[lcni_watchlist_add symbol="FPT" label="Thêm vào Watchlist"]
```

- `symbol`: mã cổ phiếu cần thêm.
- `label`: text nút.
- `class`: class CSS bổ sung.
