# LCNI Data Collector - Version 5.3.9g

## Changes
- Fix lưu cấu hình Member Login/Register/Quote: khi chỉ lưu một phần dữ liệu, hệ thống vẫn giữ cấu hình trước đó thay vì rơi về mặc định.
- Đổi cấu hình `Background image URL` sang upload file cho tab Login/Register, lưu URL file đã upload để render frontend.
- Đổi cấu hình `CSV URL` sang upload file CSV/TXT cho tab Quote, ghi nhớ file đã upload để random quote.
- Fix Chart Builder dùng đúng Data Format ở frontend cho heatmap label/tooltip và đồng bộ đọc field dữ liệu trong chế độ `share_dataset`.
- Nâng version plugin lên `5.3.9g` và bump version asset Chart Builder để cache-busting.
