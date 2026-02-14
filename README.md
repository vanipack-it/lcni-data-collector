# LCNI Data Collector

Plugin WordPress để đồng bộ danh sách mã (security definitions) và dữ liệu nến OHLC từ hệ thống Entrade/DNSE.

## Cấu hình API khuyến nghị

Trong trang **LCNI Settings**, mục **Security Definition URL** nên dùng một trong hai endpoint sau:

1. `https://openapi.dnse.com.vn/price/secdef` (mặc định)
2. `https://services.entrade.com.vn/chart-api/v2/securities` (fallback)

> Lưu ý: endpoint cũ dạng `https://services.entrade.com.vn/open-api/market/v2/securities?...` có thể trả về HTTP 404.

## Đồng bộ dữ liệu

- Nút **Chạy đồng bộ ngay** sẽ:
  - Đồng bộ danh sách mã trước.
  - Sau đó đồng bộ OHLC theo batch.
- Cron sẽ chạy theo chế độ incremental để chỉ lấy nến mới.
