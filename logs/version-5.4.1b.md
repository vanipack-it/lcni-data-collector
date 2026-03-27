# LCNI Data Collector - Version 5.4.1b

## Changes
- Nâng version plugin lên `5.4.1b`.
- Triển khai Recommend Engine lightweight theo kiến trúc service/repository:
  - `RuleRepository`
  - `SignalRepository`
  - `PositionEngine`
  - `ExitEngine`
  - `PerformanceCalculator`
  - `DailyCronService`
  - `ShortcodeManager`
- Bổ sung module khởi tạo `LCNI_Recommend_Module` và bootstrap vào plugin chính.
- Tạo 3 bảng dữ liệu mới:
  - `wp_lcni_recommend_rule`
  - `wp_lcni_recommend_signal`
  - `wp_lcni_recommend_performance`
- Bổ sung cron duy nhất hàng ngày `lcni_recommend_daily_cron` với pipeline:
  1) update giá open signals,
  2) tính R,
  3) cập nhật position state,
  4) đóng signal khi đạt điều kiện exit,
  5) scan active rules để mở signal mới,
  6) cập nhật performance.
- Triển khai Position Management theo R-multiple + action mapping.
- Triển khai Exit Engine nhẹ theo 3 điều kiện bắt buộc (SL, exit_at_r, max_hold_days).
- Triển khai Performance Analytics theo closed trades với các chỉ số:
  - winrate
  - avg_r
  - expectancy
  - max_r / min_r
  - avg_hold_days
- Bổ sung shortcode frontend:
  - `[lcni_signals]`
  - `[lcni_performance]`
  - `[lcni_signal]`
- Bổ sung admin UI `LCNI Data -> Recommend` với 3 tabs: Rules / Signals / Performance (WP List Table).
- Bổ sung tài liệu hướng dẫn sử dụng module: `docs_recommend_engine_5.4.1b.md`.
