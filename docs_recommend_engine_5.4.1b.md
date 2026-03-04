# Recommend Engine 5.4.1b

## Tổng quan
Recommend Engine là module tactical semi-quant chạy nhẹ trong WordPress production với:
- 3 bảng dữ liệu riêng cho rule/signal/performance.
- 1 cron duy nhất chạy mỗi ngày.
- Position Management theo R-multiple.
- Analytics theo closed trade.
- Shortcode hiển thị frontend.
- Admin UI dạng tab dùng WP List Table.

## Cấu trúc dữ liệu
### 1) `wp_lcni_recommend_rule`
Định nghĩa chiến lược:
- `name`, `timeframe`.
- `entry_conditions` (JSON, dùng field sẵn có trong `wp_lcni_ohlc`).
- `initial_sl_pct`, `risk_reward`, `add_at_r`, `exit_at_r`, `max_hold_days`, `is_active`.

Ví dụ `entry_conditions`:
```json
{
  "is_break_h1m": 1,
  "rs_rank_min": 80,
  "smart_money": 1
}
```

Quy ước điều kiện:
- Mặc định là AND.
- `xxx_min` => `xxx >= value`.
- `xxx_max` => `xxx <= value`.
- Còn lại => `xxx = value`.

### 2) `wp_lcni_recommend_signal`
Trade instance cho từng symbol/rule:
- Entry: `entry_time`, `entry_price`, `initial_sl`, `risk_per_share`.
- Runtime: `current_price`, `r_multiple`, `position_state`, `holding_days`, `status`.
- Exit: `exit_price`, `exit_time`, `final_r`.

### 3) `wp_lcni_recommend_performance`
Analytics theo từng rule:
- `total_trades`, `win_trades`, `lose_trades`.
- `avg_r`, `winrate`, `expectancy`.
- `max_r`, `min_r`, `avg_hold_days`.

## Position Engine (R-multiple)
- `risk = entry_price - initial_sl`
- `R = (current_price - entry_price) / risk`

State machine:
- `R < 0` => `CUT_ZONE`
- `0 <= R < 1` => `EARLY`
- `1 <= R < add_at_r` => `HOLD`
- `add_at_r <= R < exit_at_r` => `ADD_ZONE`
- `R >= exit_at_r` => `TAKE_PROFIT_ZONE`

Action mapping:
- `CUT_ZONE` => Cắt
- `EARLY` => Theo dõi
- `HOLD` => Nắm giữ
- `ADD_ZONE` => Gia tăng
- `TAKE_PROFIT_ZONE` => Chốt từng phần

## Exit engine
Signal tự đóng khi một trong các điều kiện đúng:
1. `current_price <= initial_sl`
2. `R >= exit_at_r`
3. `holding_days > max_hold_days`

## Daily cron (1 cron/ngày)
Hook: `lcni_recommend_daily_cron`

Pipeline xử lý:
1. Update `current_price` cho signal open.
2. Tính `r_multiple`.
3. Update `position_state`.
4. Nếu thỏa exit thì close signal.
5. Scan active rules và tạo signal mới.
6. Rebuild performance analytics.

Đảm bảo:
- Không tạo trùng signal open theo cặp `rule_id + symbol`.
- Idempotent mức tác vụ theo ngày (chạy lại không tạo open duplicate).

## Performance công thức
Chỉ tính trên signal `status = closed` và có `final_r`.

- `winrate = win_trades / total_trades`
- `avg_r = AVG(final_r)`
- `expectancy = (winrate × avg_win_R) - ((1 - winrate) × avg_loss_R)`

Trong đó:
- `avg_win_R` là trung bình `final_r` của trade thắng (`final_r >= 0`).
- `avg_loss_R` là trung bình trị tuyệt đối của `final_r` trade thua (`final_r < 0`).

## Shortcode
### A. Danh sách signal
`[lcni_signals rule_id="1" status="open" limit="20" symbol="DCM"]`

Cột render:
`Symbol | Entry | Current | R | State | Action | Status`

### B. Performance
`[lcni_performance rule_id="1"]`

Cột render:
`Rule | Total | Win | Lose | Winrate | Avg R | Expectancy | Max R | Min R`

### C. Signal Card
`[lcni_signal symbol="DCM"]`

Hiển thị:
- Rule Name
- Entry price
- Current price
- R multiple
- Position state
- Action

Nếu không có signal open: hiển thị thông báo.

## Admin UI
Menu: `LCNI Data -> Recommend`

Tabs:
- Rules
- Signals
- Performance

Rules tab có form tạo rule nhanh và bảng list rules.

## Class bắt buộc
- `RuleRepository`
- `SignalRepository`
- `PositionEngine`
- `ExitEngine`
- `PerformanceCalculator`
- `DailyCronService`
- `ShortcodeManager`

Module khởi tạo: `LCNI_Recommend_Module`

## Vận hành cơ bản
1. Vào `LCNI Data -> Recommend -> Rules` tạo rule active.
2. Đợi cron ngày chạy hoặc trigger thủ công hook `lcni_recommend_daily_cron`.
3. Xem signals/performance trong admin hoặc shortcode frontend.
