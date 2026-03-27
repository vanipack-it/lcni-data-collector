# Hướng dẫn tích hợp: Custom Index Module

## Cấu trúc file

Đặt toàn bộ thư mục `CustomIndex/` vào:

```
wp-content/plugins/lcni-data-collector/includes/CustomIndex/
```

Kết quả:

```
includes/CustomIndex/
├── class-lcni-custom-index-db.php
├── class-lcni-custom-index-calculator.php
├── class-lcni-custom-index-repository.php
├── class-lcni-custom-index-cron.php
├── class-lcni-custom-index-rest-controller.php
├── class-lcni-custom-index-admin.php
├── class-lcni-custom-index-shortcode.php
├── class-lcni-custom-index-module.php
├── custom-index-chart.js
└── custom-index-chart.css
```

\---

## Tích hợp vào lcni-data-collector.php

Thêm vào cuối file `lcni-data-collector.php`, **sau** tất cả `require\_once` hiện có:

```php
// ── Custom Index Module ───────────────────────────────────────────────────
require\_once LCNI\_PATH . 'includes/CustomIndex/class-lcni-custom-index-db.php';
require\_once LCNI\_PATH . 'includes/CustomIndex/class-lcni-custom-index-calculator.php';
require\_once LCNI\_PATH . 'includes/CustomIndex/class-lcni-custom-index-repository.php';
require\_once LCNI\_PATH . 'includes/CustomIndex/class-lcni-custom-index-cron.php';
require\_once LCNI\_PATH . 'includes/CustomIndex/class-lcni-custom-index-rest-controller.php';
require\_once LCNI\_PATH . 'includes/CustomIndex/class-lcni-custom-index-admin.php';
require\_once LCNI\_PATH . 'includes/CustomIndex/class-lcni-custom-index-shortcode.php';
require\_once LCNI\_PATH . 'includes/CustomIndex/class-lcni-custom-index-module.php';
new LCNI\_Custom\_Index\_Module();
```

\---

## Sử dụng

### 1\. Tạo chỉ số trong Admin

**WP Admin → LCNI Data → Chỉ số tùy chỉnh**

Ví dụ các chỉ số có thể tạo:

|Tên|Sàn|Ngành|Scope|
|-|-|-|-|
|HOSE Composite|HOSE|Tất cả|all|
|Bluechip 20|HOSE|Tất cả|custom: HPG,VNM,VHM,...|
|Ngân hàng|HOSE|Tài chính|all|
|Watchlist VIP|—|—|watchlist|

### 2\. Backfill lịch sử

Sau khi tạo chỉ số, click **▶ Backfill toàn bộ lịch sử 1D** trong trang edit.

* Tự động đặt base phiên đầu tiên = **100.00**
* Tất cả phiên sau được scale tương đối

### 3\. Nhúng chart vào trang WordPress

```
\[lcni\_custom\_index id="1" height="420" timeframe="1D" limit="200" show\_breadth="1" show\_volume="1"]
```

**Tham số shortcode:**

|Tham số|Mặc định|Mô tả|
|-|-|-|
|`id`|(bắt buộc)|ID chỉ số từ trang admin|
|`height`|`360`|Chiều cao chart chính (px)|
|`timeframe`|`1D`|`1D` / `1W` / `1M`|
|`limit`|`200`|Số phiên hiển thị tối đa|
|`show\_breadth`|`1`|Hiện panel số mã tăng/giảm|
|`show\_volume`|`1`|Hiện panel value traded|
|`title`|tên chỉ số|Override tiêu đề|

### 4\. Danh sách chỉ số dạng cards

```
\[lcni\_custom\_index\_list columns="3" timeframe="1D"]
```

\---

## REST API

|Method|Endpoint|Mô tả|
|-|-|-|
|GET|`/lcni/v1/custom-indexes`|Danh sách tất cả chỉ số|
|POST|`/lcni/v1/custom-indexes`|Tạo chỉ số mới (admin)|
|GET|`/lcni/v1/custom-indexes/{id}`|Chi tiết chỉ số|
|PUT|`/lcni/v1/custom-indexes/{id}`|Cập nhật (admin)|
|DELETE|`/lcni/v1/custom-indexes/{id}`|Xóa (admin)|
|GET|`/lcni/v1/custom-indexes/{id}/candles`|OHLC data|
|POST|`/lcni/v1/custom-indexes/{id}/backfill`|Trigger backfill (admin)|

**Ví dụ GET candles:**

```
GET /wp-json/lcni/v1/custom-indexes/1/candles?timeframe=1D\&limit=200
```

Response:

```json
{
  "index": { "id": 1, "name": "HOSE Composite" },
  "timeframe": "1D",
  "candles": \[
    {
      "event\_time": "1700000000",
      "open":  "98.45",
      "high":  "101.20",
      "low":   "97.80",
      "close": "100.00",
      "value": "125000000000",
      "so\_ma": "380",
      "so\_tang": "210",
      "so\_giam": "145"
    }
  ]
}
```

\---

## Công thức tính chỉ số

### Value-Weighted (Liquidity-Weighted)

```
weighted\_price(t) = Σ( close\_i × value\_traded\_i ) / Σ( value\_traded\_i )

index(t) = weighted\_price(t) / base\_weighted\_price × 100
```

Trong đó:

* `close\_i` — giá đóng cửa mã i (nghìn đồng)
* `value\_traded\_i = close\_i × volume\_i` — GTGD ước tính (nghìn đồng)
* `base\_weighted\_price` — weighted\_price tại phiên đầu tiên (index = 100)

OHLC được tính tương tự: open/high/low dùng `open\_price`/`high\_price`/`low\_price` nhân với cùng trọng số `value\_traded`.

### Ưu điểm so với Price-Weighted

* Mã thanh khoản cao (VCB, HPG, VHM) có trọng số lớn hơn → phản ánh "dòng tiền thực"
* Không bị kéo bởi mã giá cao nhưng ít giao dịch
* Không cần `listed\_volume` (chỉ cần dữ liệu OHLC có sẵn)

### Cron tự động

Cron chạy lúc **18:30 hàng ngày** (sau khi HOSE đóng cửa), tự động tính phiên mới nhất cho tất cả chỉ số active.

\---

## Lưu ý kỹ thuật

* Nếu một mã có `value\_traded = 0` trong một phiên → bị loại khỏi phép tính phiên đó (tránh sai lệch)
* Khi thay đổi bộ lọc (sàn/ngành/scope) → `base\_value` bị reset, cần backfill lại
* `base\_event\_time` được lưu vào DB → index không bị trôi khi thêm dữ liệu mới về quá khứ

