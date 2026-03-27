# Hướng dẫn tạo Chart Template mẫu (dễ tùy chỉnh trong Admin)

Tài liệu này mô tả chuẩn code để template mới tương thích với **Frontend Setting → Chart Builder**,
đặc biệt phần **Axis & Series mapping** và nút **Tùy chỉnh thuộc tính** theo từng series.

## 1) Khai báo template meta ở admin

Trong `includes/class-lcni-settings.php` (hàm `render_frontend_chart_builder_form`), thêm template vào `$chart_templates`:

```php
'new_template_key' => [
    'label' => 'New Template Label',
    'axis_slots' => 1, // 1 hoặc 2
    'series_slots' => 3, // số series kéo-thả
],
```

Yêu cầu:
- `axis_slots=2` khi template cần cả X/Y category (vd heatmap).
- `series_slots` quyết định số row mapping và số panel thuộc tính được sinh tự động.

## 2) Chuẩn payload cần lưu

`LCNI_Chart_Builder_Service::sanitize_payload()` đang sanitize và lưu `config_json` theo cấu trúc:

```json
{
  "xAxis": "event_time",
  "yAxis": "",
  "series": [
    {
      "name": "Series 1",
      "field": "close",
      "type": "line|bar",
      "color": "#5470c6",
      "stack": true,
      "area": true,
      "label_show": false,
      "line_style": "solid|dashed"
    }
  ],
  "filters": ["symbol"]
}
```

Khi thêm template mới, ưu tiên tái sử dụng cấu trúc `series[]` này để tự động sync với UI tùy chỉnh thuộc tính.

## 3) Rule render frontend

Trong `modules/chart-builder/assets/chart-builder.js`:
- Dùng `payload.chart_type` để branch template.
- Đọc `payload.config.series[]` để dựng `option.series`.
- Không hardcode màu/line style nếu đã có trong `series[i].color`, `series[i].line_style`.

Khung mẫu:

```js
if (chartType === 'new_template_key') {
  return {
    xAxis: { type: 'category', data: rows.map((r) => r[cfg.xAxis]) },
    yAxis: { type: 'value' },
    series: (cfg.series || []).map((item) => ({
      name: item.name || item.field,
      type: item.type || 'line',
      data: rows.map((r) => Number(r[item.field] || 0)),
      lineStyle: { type: item.line_style || 'solid', color: item.color || '#5470c6' },
      itemStyle: { color: item.color || '#5470c6' },
      stack: item.stack ? 'Total' : undefined,
      areaStyle: item.area ? {} : undefined,
      label: item.label_show ? { show: true } : undefined,
    })),
  };
}
```

## 4) Checklist để template mới “tùy chỉnh được”

- Có mặt trong `$chart_templates`.
- Dùng mapping `xAxis/yAxis/series[]` từ `config_json`.
- Mỗi series đọc được: `type`, `color`, `line_style`, `stack`, `area`, `label_show`.
- Preview trong admin render ổn định khi đổi template, đổi mapping, mở/tắt panel thuộc tính.
- Không thêm logic render HTML frontend ngoài scope data/chart config.
