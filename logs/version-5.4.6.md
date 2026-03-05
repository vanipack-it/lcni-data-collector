# LCNI Data Collector - Version 5.4.6

## Changes
- Nâng version plugin lên `5.4.6`.
- Đổi nhãn tab lịch sử thay đổi trong Recommend admin thành tab chuẩn `Logs` để đồng bộ giao diện.
- Nâng cấp quét thủ công cho Rule: hỗ trợ chọn khoảng ngày quét (`Từ ngày` / `Đến ngày`) để scan dữ liệu lịch sử (phục vụ mô phỏng/backtest thủ công theo rule).
- Cập nhật engine scan theo khoảng thời gian: khi quét theo cửa sổ ngày sẽ lấy các bản ghi theo `event_time` trong window thay vì chỉ lấy bản ghi latest của từng mã.
- Bổ sung tùy chọn logic kết hợp rule trong Entry Conditions: hỗ trợ `AND` hoặc `OR`.
- Bổ sung ô tìm kiếm nhanh field cho từng dòng điều kiện kích hoạt để chọn cột nhanh hơn khi danh sách field dài.
- Tab `Signals` trong Recommend admin bổ sung thêm cột `Entry Date` và `Số ngày nắm giữ` để theo dõi vòng đời tín hiệu dễ hơn.
- Đồng bộ label LCNi Signals với cấu hình Column Label toàn cục trong Admin (frontend có thể ưu tiên nhãn đã cấu hình).
- Bổ sung tài liệu shortcode cho module Recommend trong `SHORTCODES.md`.
