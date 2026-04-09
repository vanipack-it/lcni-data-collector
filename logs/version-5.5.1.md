# Version 5.5.1 — Performance Metrics v2 & Equity Curve

## Tóm tắt
Nâng cấp module Recommend: thêm 4 metrics mới (Profit Factor, Kelly %, Avg Win R, Avg Loss R, Avg Hold Days hiển thị), tích hợp biểu đồ Equity Curve trong admin và frontend shortcode.

---

## Files đã sửa

### 1. `includes/Recommend/RecommendDB.php`
- Thêm 4 cột vào schema bảng `wp_lcni_recommend_performance`:
  - `avg_win_r DECIMAL(16,6)` — R trung bình các lệnh thắng
  - `avg_loss_r DECIMAL(16,6)` — R trung bình các lệnh thua
  - `profit_factor DECIMAL(16,6)` — tổng lợi nhuận / tổng lỗ
  - `kelly_pct DECIMAL(16,6)` — Kelly % (0–1)
- Thêm migration an toàn trong `ensure_tables_exist()` bằng `ALTER TABLE ... ADD COLUMN IF NOT EXISTS` pattern (idempotent)

### 2. `includes/Recommend/PerformanceCalculator.php`
- `refresh_rule()`: tính và lưu 4 metrics mới cùng lúc với các metrics hiện tại
- Thêm method `get_equity_curve(int $rule_id): array` — truy vấn các lệnh closed theo thứ tự exit_time, tính cumulative R từng bước
- Thêm static method `compute_score(array $perf): float` — tính điểm 0–100 (Expectancy 40pts + Winrate 20pts + Profit Factor 20pts + Kelly 20pts)
- Thêm static method `score_badge(float $score): string` — trả về 'good'|'neutral'|'weak'

### 3. `includes/Recommend/Admin/RecommendAdminPage.php`
- `LCNI_Recommend_Performance_List_Table::get_columns()`: thêm 5 cột mới: Avg Win R, Avg Loss R, Profit Factor, Kelly %, Avg Hold, Score
- `column_default()`: override để format đúng từng cột (%, R, badge màu)
- `render_performance_tab()`: thêm panel Equity Curve bên dưới bảng, render ECharts khi click nút "📈 Equity"
- Thêm AJAX handler `ajax_equity_curve()` (only admin, nonce: `lcni_equity_curve`)

### 4. `includes/Recommend/ShortcodeManager.php`
- Thêm shortcode `[lcni_performance_v2]`
- Thêm shortcode `[lcni_equity_curve]`
- Thêm AJAX endpoint `lcni_public_equity_curve` (public + nopriv)

---

## Files đã tạo mới

### 5. `logs/version-5.5.1.md` (file này)

---

## Shortcodes mới

### `[lcni_performance_v2]`
Bảng performance đầy đủ với tất cả metrics mới + nút 📈 mở Equity Curve inline.

**Attributes:**
| Attribute | Default | Mô tả |
|---|---|---|
| `rule_id` | `0` | Lọc theo rule cụ thể. 0 = hiển thị tất cả |
| `show_chart` | `1` | Hiện nút chart. Set `"0"` để ẩn |

**Ví dụ:**
```
[lcni_performance_v2]
[lcni_performance_v2 rule_id="3"]
[lcni_performance_v2 show_chart="0"]
```

---

### `[lcni_equity_curve]`
Biểu đồ Equity Curve standalone cho 1 rule. Data render server-side (không cần AJAX).

**Attributes:**
| Attribute | Default | Mô tả |
|---|---|---|
| `rule_id` | bắt buộc | ID của rule cần hiển thị |
| `height` | `320` | Chiều cao chart (px), min 200, max 800 |

**Ví dụ:**
```
[lcni_equity_curve rule_id="1"]
[lcni_equity_curve rule_id="2" height="450"]
```

---

## Metrics mới — Giải thích

### Profit Factor
```
Profit Factor = Tổng R của các lệnh thắng / Tổng R của các lệnh thua
```
- > 2.0: Tốt
- > 1.5: Chấp nhận được
- < 1.0: Rule đang lỗ vốn

### Kelly %
```
Kelly% = Winrate - (Lossrate / (Avg_Win_R / Avg_Loss_R))
```
Kết quả được clamp về [0, 1]. **Trong thực tế nên dùng Half-Kelly** (Kelly/2) để giảm rủi ro.
- Kelly% 30%: Có thể đặt 30% vốn vào rule này (Full Kelly)
- Hiển thị thêm Half-Kelly để tham khảo thực tế

### Score (0–100)
Điểm tổng hợp:
- Expectancy: tối đa 40 điểm (đạt full khi Expectancy ≥ 2.67R)
- Winrate: tối đa 20 điểm (đạt full khi ≥ 57%)
- Profit Factor: tối đa 20 điểm (đạt full khi PF ≥ 3)
- Kelly %: tối đa 20 điểm (đạt full khi Kelly ≥ 33%)

Badge:
- 🟢 Tốt: ≥ 65 điểm
- 🟡 Trung bình: 40–64 điểm
- 🔴 Kém: < 40 điểm

### Equity Curve
Biểu đồ đường thể hiện tổng R cộng dồn qua từng lệnh đã đóng (exit_time ASC).
- Màu xanh: đoạn tăng (cumulative R dương)
- Màu đỏ: đoạn giảm (cumulative R âm)
- Tooltip: ngày, mã, R lệnh, R cộng dồn
- Hỗ trợ zoom bằng chuột hoặc thanh kéo bên dưới

---

## Backward Compatibility

- Shortcode cũ `[lcni_performance]` **không thay đổi**, vẫn hoạt động bình thường
- Dữ liệu cũ trong DB không bị xóa; 4 cột mới sẽ có giá trị `0` cho đến khi `PerformanceCalculator::refresh_all()` chạy lại
- Cron hàng ngày sẽ tự cập nhật metrics mới trong lần chạy tiếp theo
- Có thể trigger thủ công bằng cách vào tab **Performance** → click nút **Refresh** (nếu đã có) hoặc chờ cron

---

## Lưu ý triển khai
1. ECharts được load từ CDN (`cdn.jsdelivr.net`). Nếu site offline/intranet, hãy download `echarts.min.js` và thay URL trong `ShortcodeManager.php` và `RecommendAdminPage.php`
2. Sau khi deploy, vào wp-admin → **LCNi Recommend → Performance** → bảng sẽ tự refresh metrics mới trong lần cron tiếp theo hoặc khi quét lại rule thủ công
