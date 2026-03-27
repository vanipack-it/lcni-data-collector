# Hướng dẫn sử dụng template **Mini Line Charts (Sparkline)** trong Chart Builder

Tài liệu này hướng dẫn cách tạo chart bằng template **Mini Line Charts (Sparkline)** trong Admin: **Frontend Setting → Chart Builder**.

## 1) Khi nào dùng template này?

Template `mini_line_sparkline` phù hợp khi bạn muốn hiển thị **nhiều ô sparkline nhỏ** theo dạng ma trận:

- **Trục X (cột):** nhóm theo một chiều dữ liệu (ví dụ: `symbol`, `week`, `month`).
- **Trục Y (hàng):** nhóm theo chiều dữ liệu thứ hai (ví dụ: `industry_name`, `exchange`).
- **Series:** giá trị số để vẽ đường mini line trong từng ô (ví dụ: `close`, `avg_price`, `volume`).

> Lưu ý: template này yêu cầu map đủ 2 axis (`axis_slots = 2`) và tối đa 3 series (`series_slots = 3`).

---

## 2) Các bước cấu hình trong Chart Builder

### Bước 1 — Chọn template

1. Vào **Frontend Setting → Chart Builder**.
2. Ở phần **Template**, chọn: **Mini Line Charts (Sparkline)**.

### Bước 2 — Chọn Data Source

Chọn bảng dữ liệu phù hợp với mục tiêu phân tích (ví dụ: `wp_lcni_ohlc`, `wp_lcni_stock_stats`, ...).

### Bước 3 — Mapping Axis & Series

Trong khối kéo-thả trường dữ liệu:

- **X Axis:** thả trường dùng làm nhóm cột.
- **Y Axis:** thả trường dùng làm nhóm hàng.
- **Series 1/2/3:** thả các trường số để vẽ nhiều đường trong cùng mini chart.

Khuyến nghị:

- Các trường `Series` nên là số và có đủ dữ liệu theo từng cặp `X Axis + mini chart` tại cùng mốc `X Axis`.
- Nếu dữ liệu quá thưa, nhiều ô sẽ không hiển thị line.

### Bước 4 — Tùy chỉnh thuộc tính series

Ở panel thuộc tính của **Series 1**, có thể chỉnh:

- `name`: tên series.
- `color`: màu đường.
- `line_style`: kiểu nét (`solid`, `dashed`).
- `label_show`: bật/tắt nhãn điểm.
- `area`: bật vùng nền dưới line (nếu cần).

### Bước 5 — Mapping Filters (nếu có)

Nếu chart cần lọc theo điều kiện đầu vào (ví dụ `symbol`, `industry_name`, `date`), map thêm ở phần **Filters** để frontend truyền tham số khi gọi chart.

### Bước 6 — Preview và lưu chart

1. Bấm **Preview** để kiểm tra chart.
2. Nếu ổn, lưu chart và lấy `slug` để nhúng ra frontend.

---

## 3) Quy ước dữ liệu gợi ý

Để chart hiển thị ổn định, mỗi bản ghi nên có:

- `xAxis` (string/category)
- `yAxis` (string/category)
- `series field` (number)

Ví dụ dữ liệu logic:

```json
[
  {"symbol":"FPT","industry":"Công nghệ","close":120.5},
  {"symbol":"FPT","industry":"Công nghệ","close":121.2},
  {"symbol":"VCB","industry":"Ngân hàng","close":89.1}
]
```

Với map:

- X Axis = `symbol`
- Y Axis = `industry`
- Series 1 = `close`

Hệ thống sẽ tạo các ô sparkline theo từng giao điểm `symbol × industry`.

---

## 4) Checklist nhanh trước khi publish

- [ ] Đã chọn đúng template **Mini Line Charts (Sparkline)**.
- [ ] Đã map đủ **X Axis**, **Y Axis**, và ít nhất **Series 1** (có thể thêm Series 2/3).
- [ ] Series là trường số (không phải text).
- [ ] Preview hiển thị đầy đủ các ô cần thiết.
- [ ] Filter mapping đúng với tham số frontend cần truyền.
- [ ] Đã lưu chart và kiểm tra slug.

---

## 5) Lỗi thường gặp

1. **Chart trống hoàn toàn**
   - Chưa map đủ X/Y/Series hoặc series không phải numeric.

2. **Chỉ hiện một phần ô**
   - Dữ liệu thiếu ở một số tổ hợp `X × Y`.

3. **Tooltip/line khó đọc**
   - Giảm số nhóm X/Y hoặc lọc theo khoảng thời gian nhỏ hơn.

4. **Màu line không đổi**
   - Kiểm tra lại panel thuộc tính của series và lưu lại chart.

---

## 6) Gợi ý sử dụng thực tế

- Mini trend theo `symbol × industry`.
- Mini trend theo `week × exchange`.
- Mini trend theo `month × market_cap_bucket`.

Template này phù hợp cho dashboard mật độ cao, cần nhìn nhanh xu hướng ngắn gọn thay vì biểu đồ lớn chi tiết.
