# LCNI AmiBroker Explore

File `lcni_explore_compute.afl` dùng để Explore và export CSV các cột indicator/signal để sync vào bảng `wp_lcni_ohlc`.

## Ghi chú nhanh
- `symbol,timeframe,event_time,open/high/low/close,volume,value_traded` vẫn là raw nguồn chuẩn từ WP/DNSE.
- Script này tính các cột dẫn xuất (MA/RSI/MACD/Pattern/Signal/Window metrics).
- `market_id` có cột output riêng để map với `wp_lcni_marketid`.
- Phần `rs_*_by_exchange` đang để placeholder `Null` để bạn thay bằng công thức RS theo market universe thực tế trong AmiBroker.

## Cách chạy
1. Mở AmiBroker > Analysis > New Formula.
2. Dán file `lcni_explore_compute.afl`.
3. Chọn universe cần chạy (daily).
4. Run `Explore` và export CSV.
5. Import CSV vào WP theo mapping cột tương ứng.

## Lưu ý
- Công thức trong script là bản vận hành thực tế để offload compute; có thể khác nhẹ so với PHP hiện tại.
- Nên chạy song song 3-5 phiên để đối soát trước khi tắt compute trên WP.
