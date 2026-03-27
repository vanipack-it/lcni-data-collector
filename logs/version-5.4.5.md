# LCNI Data Collector - Version 5.4.5

## Changes
- Nâng version plugin lên `5.4.5`.
- Thêm template Chart Builder mới `Mini Line Charts (Sparkline)` trong danh sách template admin.
- Template mới hỗ trợ mapping theo chuẩn `xAxis / yAxis / series[]` để tương thích đầy đủ với UI Axis & Series mapping.
- Frontend render dạng matrix sparkline (nhiều ô mini line chart trong cùng một bảng), phù hợp theo dõi đồng thời nhiều mã cổ phiếu và nhóm ngành.
- Bổ sung `dataZoom` dạng `slider` + `inside` trên toàn bộ cell để hỗ trợ zoom theo trục thời gian.

## Hướng dẫn sử dụng template Mini Line Charts (Sparkline)
1. Vào **LCNI → Frontend Setting → Chart Builder**.
2. Chọn template **Mini Line Charts (Sparkline)**.
3. Kéo thả mapping:
   - **X Axis**: trường chiều ngang của ma trận (ví dụ: `symbol` hoặc `weekday`).
   - **Y Axis**: trường chiều dọc của ma trận (ví dụ: `icb2` hoặc `session`).
   - **Series 1**: trường giá trị line (ví dụ: `close`, `price`, `%change`).
4. Đảm bảo data source có trường thời gian (`event_time`/`date`) để sparkline thể hiện chuỗi theo thời gian trong từng ô.
5. Bấm **Preview** để xem ma trận sparkline.
6. Ở frontend, dùng thanh **zoom slider** phía dưới để thu/phóng đồng thời toàn bộ các line chart trong bảng.

## Gợi ý dữ liệu cho mục tiêu theo dõi nhiều cổ phiếu/nhóm ngành
- X Axis: `symbol`
- Y Axis: `icb2`
- Series 1: `close` hoặc `%change`
- Filter: `market` hoặc `exchange` để thu hẹp phạm vi theo sàn/nhóm.
