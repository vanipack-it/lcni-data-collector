# LCNI Data Collector - Version 5.4.8

## Changes
- Nâng version plugin lên `5.4.8`.
- Bổ sung module Industry Analysis (ICB2): tạo materialized tables `lcni_industry_return`, `lcni_industry_index`, `lcni_industry_metrics`.
- Bổ sung pipeline build dữ liệu ngành từ OHLC + mapping (`sym_icb_market`) và tính:
  - Industry Return (value-weighted)
  - Industry Index (base 1000)
  - Momentum (5d/10d/20d)
  - Relative Strength so với VNINDEX
  - Money Flow Share
  - Breadth
  - Industry Leadership Score + phân loại tiếng Việt (`industry_rating_vi`).
- Bổ sung API REST cho dashboard ngành:
  - `GET /wp-json/lcni/v1/industry/dashboard`
  - `GET /wp-json/lcni/v1/industry/ranking`
  - `GET /wp-json/lcni/v1/industry/index/{id_icb2}`
- Bổ sung shortcode `[lcni_industry_dashboard]` để frontend theme nhúng và tiêu thụ dữ liệu ECharts.
