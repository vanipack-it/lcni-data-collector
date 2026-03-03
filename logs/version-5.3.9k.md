# LCNI Data Collector - Version 5.3.9k

## Changes
- Nâng version plugin lên `5.3.9k`.
- `[lcni_member_login]`: căn trái đồng bộ label, căn giữa đồng bộ input theo cùng trục; bổ sung hỗ trợ nhận `lcni_redirect_to` để login xong quay lại trang watchlist.
- `[lcni_member_register]`: căn trái đồng bộ label, căn giữa đồng bộ input theo cùng trục; bổ sung hỗ trợ nhận `lcni_redirect_to` để register xong quay lại trang watchlist.
- Quote: căn giữa theo cả trục dọc/ngang, tự động xuống dòng (`white-space` + `word-break`) thay vì dồn lệch về top.
- Fix hiệu ứng blur Quote bằng cách bổ sung cả `-webkit-backdrop-filter` và `backdrop-filter`.
- Watchlist `[lcni_watchlist]`: thêm tùy chọn guest mode ở phần settings:
  - `link` (mặc định): hiển thị link "Đăng nhập để xem watchlist".
  - `page`: tự động mở page Login/Register đã chọn khi khách chưa đăng nhập.
- Sau khi khách login/register thành công từ page được mở bởi watchlist, hệ thống redirect quay lại trang chứa `[lcni_watchlist]`.
