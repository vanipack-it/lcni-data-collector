# LCNI Data Collector - Version 5.4.2a

## Changes
- Nâng version plugin lên `5.4.2a`.
- Cập nhật giao diện tạo rule trong `LCNi Recommend`:
  - Bỏ cơ chế kéo-thả trong Entry Conditions.
  - Gộp `Entry Conditions JSON` và `Table source (1)` thành một cột `Entry Conditions`.
  - Tạo điều kiện theo kiểu rule builder tương tự Cell Color: mỗi rule gồm `table`, `field`, `operator`, `value`.
  - Hỗ trợ thêm nhiều rule và tự động ghép các rule theo logic `AND`.
- Cập nhật `RuleRepository` để parse và truy vấn theo operator (`=`, `!=`, `>`, `>=`, `<`, `<=`, `contains`, `not_contains`) cho format mới.
- Giữ tương thích ngược với format điều kiện cũ.
