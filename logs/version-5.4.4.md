# LCNI Data Collector - Version 5.4.4

## Changes
- Nâng version plugin lên `5.4.4`.
- Cập nhật `LCNi Recommend -> Tạo Rule`: bỏ cơ chế kéo thả để tạo `Entry Conditions`.
- Gộp 2 khối `Entry Conditions JSON` + `Table source (1)` thành một khối duy nhất tên `Điều kiện kích hoạt`.
- Thêm builder rule dạng bảng cho phép tạo nhiều dòng điều kiện, mặc định có sẵn 1 dòng và có nút `+ Thêm rule`.
- Mỗi dòng rule gồm 3 cột:
  - `Field`: danh sách field từ các bảng `lcni_ohlc`, `lcni_symbols`, `lcni_icb2`, `lcni_sym_icb_market`, `lcni_symbol_tongquan`.
  - `Điều kiện`: hỗ trợ `=`, `>`, `<`, `contains`, `not_contains`.
  - `Giá trị so sánh`: input text để nhập giá trị cần so sánh.
- Cập nhật `RuleRepository` để hỗ trợ JSON điều kiện mới dạng `rules[]`, kết hợp tất cả rule bằng logic `AND` khi lọc candidate symbol.
