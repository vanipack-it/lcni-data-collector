# LCNI Data Collector v5.5.0

## Added
- New industry metrics upgrade module in `includes/class-industry-metrics-upgrade.php`.
- Safe schema upgrade flow for `wp_lcni_industry_metrics`:
  - Adds `industry_rank`, `momentum_delta`, `trend_state_vi` only when missing.
  - Adds required indexes and unique key when missing.
- New cron hook `lcni_compute_industry_metrics_extra` (every 5 minutes) for batched extra-metrics computation.
- Batch compute functions:
  - `lcni_compute_momentum_delta_batch()`
  - `lcni_compute_trend_state_vi_batch()`
  - `lcni_compute_industry_rank_batch()`
  - `lcni_backfill_missing_metrics()`

## Notes
- Existing column logic is unchanged.
- Computation only targets incomplete rows to avoid full-table recalculation.
