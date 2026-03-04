# LCNI Data Collector - Version 5.4.1a

## Changes
- Nâng version plugin lên `5.4.1a`.
- Bổ sung template chart mới trong Chart Builder:
  - `HeatMap 2` (cartesian heatmap).
  - `TreeMap 1` (treemap drill-down).
- Tuân thủ guideline `docs_chart_builder_template_guideline.md`:
  - Khai báo template meta với `axis_slots`/`series_slots` phù hợp.
  - Tái sử dụng mapping `xAxis`, `yAxis`, `series[]` và config màu heatmap trong payload.
- Bổ sung khu vực tùy chỉnh màu HeatMap trong admin (low/mid/high), áp dụng cho preview và frontend render.
- Tắt áp màu series tùy chỉnh từ config khi render ngoài frontend cho các template line/area/share, nhằm đảm bảo đồng bộ giao diện frontend.
- Thêm `dataZoom` slider trên trục thời gian khi dữ liệu dài để dễ zoom/scroll.
