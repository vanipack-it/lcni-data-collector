# LCNI Data Collector - Version 5.4.4.1

## Changes
- Nâng version plugin lên `5.4.4.1`.
- Bổ sung trường `Ngày áp dụng` (`apply_from_date`) cho Rule Recommend, cho phép admin áp dụng rule về quá khứ.
- Khi tạo/cập nhật rule có `Ngày áp dụng`, hệ thống quét dữ liệu lịch sử từ ngày chọn đến hiện tại và đưa mã thỏa điều kiện vào bảng `lcni_recommend_signal`, sau đó cập nhật bảng `lcni_recommend_performance`.
- Bổ sung trường `Lịch quét hàng ngày` (`scan_time`) để chọn giờ quét tự động cho từng rule.
- Chuyển cron Recommend sang chạy mỗi phút, đến giờ cấu hình từng rule hệ thống tự động quét dữ liệu trong ngày và cập nhật `last_scan_at`.
- Bổ sung bảng log `lcni_recommend_rule_log` để lưu lịch sử thay đổi rule và lịch sử quét (create/update/history scan/cron scan).
- Thêm tab `Lịch sử thay đổi` trong trang admin `LCNi Recommend` để theo dõi các thay đổi và hoạt động quét.
- Cập nhật migration đảm bảo các cột mới (`apply_from_date`, `scan_time`, `last_scan_at`) và bảng log được tạo tự động trên site đang chạy.
