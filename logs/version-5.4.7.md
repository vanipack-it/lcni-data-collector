# LCNI Data Collector - Version 5.4.7

## Changes
- Nâng version plugin lên `5.4.7`.
- Recommend Admin / Điều kiện kích hoạt: chuyển logic kết hợp điều kiện từ chế độ global (`match` chung cho toàn bộ rules) sang chế độ theo từng dòng (`join_with_next`), cho phép kết hợp AND/OR linh hoạt giữa các row.
- Recommend Admin / Điều kiện kích hoạt: thêm cột chọn `AND/OR` ngay trên từng dòng để xác định cách nối với dòng kế tiếp.
- Recommend Admin / Điều kiện kích hoạt: thay cách chọn Field sang ô tìm kiếm trực tiếp bằng `datalist` (gõ ký tự để lọc danh sách và chọn nhanh field).
- RuleRepository: hỗ trợ parse/lưu/read điều kiện mới theo từng row với `join_with_next` và vẫn tương thích dữ liệu cũ dùng `match` global.
