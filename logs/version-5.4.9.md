# LCNI Data Collector - Version 5.4.9

## Changes
- Nâng version plugin lên `5.4.9`.
- Bổ sung migration mở rộng schema cho 3 bảng ngành (chỉ `ALTER TABLE ... ADD COLUMN`):
  - `wp_lcni_industry_return`: `industry_volume`, `value_ma20`, `money_flow_ratio`.
  - `wp_lcni_industry_index`: `index_ma50`, `industry_trend`.
  - `wp_lcni_industry_metrics`: `leader_stock_ratio`, `updown_ratio`, `industry_phase`.
- Bổ sung pipeline batch SQL 8 bước để tính toán dữ liệu mở rộng ngành theo thứ tự:
  1. update `industry_volume`
  2. update `value_ma20`
  3. update `money_flow_ratio`
  4. update `index_ma50`
  5. update `industry_trend`
  6. update `leader_stock_ratio`
  7. update `updown_ratio`
  8. update `industry_phase`
- Mở rộng nguồn dữ liệu cho Chart Builder với các bảng Industry/Recommend/Thống kê thị trường để hỗ trợ kéo-thả cột tạo biểu đồ.
- Mở rộng nguồn bảng cho Rule Builder (module Recommend) để có thêm cột từ Industry/Recommend/Thống kê thị trường trong quá trình cấu hình rule.
- Mở rộng danh sách cột trong mục Global Column Label để chuẩn hóa nhãn cho các bảng Industry/Recommend/Thống kê thị trường.
