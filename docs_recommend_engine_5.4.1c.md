# Recommend Engine 5.4.1c

## Thay đổi chính

1. **Version bump**
   - Nâng version plugin lên `5.4.1c`.

2. **Recommend Rules Builder (3 cột)**
   - Cập nhật giao diện `LCNi Recommend -> Rules` theo layout 3 cột:
     - Cột trái: thông tin Rule (`Name`, `Timeframe`, `Description recommend`, `Initial SL %`, `Risk Reward`, `Add at R`, `Exit at R`, `Max Hold Days`).
     - Cột giữa: `Entry Conditions JSON` + danh sách cột đã kéo vào rule.
     - Cột phải: `Table source (1)` và `Column of table (2)`.
   - Hỗ trợ kéo-thả cột từ bảng nguồn vào vùng điều kiện.

3. **Nguồn bảng cho Rule Builder**
   - Cho phép chọn các bảng:
     - `wp_lcni_ohlc`
     - `wp_lcni_symbol_tong_quan`
     - `wp_lcni_icb2`
     - `wp_lcni_marketid`
   - Hệ thống đọc danh sách cột trực tiếp từ DB.

4. **Giá trị điều kiện theo kiểu dữ liệu**
   - Với cột kiểu số: nhập **min/max**.
   - Với cột text: dùng **checkbox + value**.
   - Tự động đồng bộ thành JSON tương thích rule engine hiện tại.

5. **Schema Rule**
   - Thêm cột `description` cho bảng `wp_lcni_recommend_rule`.
   - Tự động bổ sung cột bằng `ALTER TABLE` nếu môi trường cũ chưa có.

6. **OHLC Latest Snapshot settings**
   - Bỏ input UI `Chu kỳ Event (phút)` theo yêu cầu.
   - Khi lưu cấu hình, hệ thống giữ nguyên interval hiện có; chỉ cập nhật `enabled` và `refresh_times`.
