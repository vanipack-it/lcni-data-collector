# LCNI Data Collector - Version 5.4.2

## Changes
- Nâng version plugin lên `5.4.2`.
- Sửa lỗi tại `LCNi Recommend -> Rule`: sau khi nhập thông số và bấm **Lưu**, hệ thống kiểm tra kết quả lưu và hiển thị notice thành công/thất bại để tránh trạng thái "lưu nhưng không thấy danh sách".
- Cập nhật `Entry Conditions JSON` trong `LCNi Recommend`:
  - Khi kéo-thả cột text vào khung điều kiện, hệ thống hiển thị danh sách value distinct.
  - Mỗi value có checkbox ở đầu để chọn làm điều kiện.
  - Ví dụ: cột `tang_gia_kem_vol` có thể chọn value `Tăng giá kèm Vol`.
- Bổ sung hỗ trợ lưu điều kiện text dạng mảng trong `RuleRepository` và truy vấn candidate bằng `IN (...)`.
- Giữ nguyên các chức năng khác.
