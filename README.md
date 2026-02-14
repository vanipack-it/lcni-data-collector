# lcni-data-collector

## DNSE Market Data WebSocket

Tài liệu nhanh để thiết lập kết nối WebSocket đến DNSE và nhận dữ liệu thị trường theo thời gian thực.

### Thông tin kết nối chung

- **Base URL**: `wss://ws-openapi.dnse.com.vn`
- **SDK DNSE** hỗ trợ sẵn nhiều nhóm dữ liệu thị trường.
- **Định dạng dữ liệu**:
  - `msgpack`: xử lý nhanh, tiết kiệm băng thông
  - `json`: dễ đọc, thuận tiện khi phát triển
- **Lưu ý mã chứng khoán**: luôn dùng chữ in hoa, ví dụ `ACB`, `HPG`, `41I1G2000`.
- **Thời gian sống của 1 kết nối**: tối đa **8 giờ**, server sẽ chủ động ngắt kết nối sau mốc này.

### Cơ chế giữ kết nối (PING/PONG)

- Server gửi `PING` định kỳ mỗi **3 phút**.
- Client phải phản hồi `PONG` trong vòng **1 phút** kể từ lúc nhận `PING`.
- Nếu không có `PONG` đúng hạn, server sẽ đóng kết nối.
- Client có thể chủ động gửi `PONG` định kỳ để giữ kết nối ổn định (đề phòng miss ping do mạng, NAT timeout, hoặc WS library ẩn ping frame).

#### Ví dụ tương tác

1. **Good interaction**
   - T+0: Server → PING
   - T+1: Client → PONG
   - T+3: Server → PING
   - T+4: Client → PONG
   - ✅ Kết nối duy trì

2. **Bad interaction**
   - T+0: Server → PING
   - Không có PONG
   - T+1: Server disconnect
   - ❌ Mất kết nối

3. **Client-initiated keepalive**
   - Cứ mỗi <= 3 phút: Client gửi keepalive (`PONG`)
   - ✅ Kết nối được duy trì tốt hơn trong điều kiện mạng không ổn định

## Các loại dữ liệu thị trường

### 1) Security Definition (Thông tin mã chứng khoán)

Thông tin giá trần/sàn/tham chiếu và trạng thái mã trong ngày giao dịch. Dữ liệu thường được gửi 1 lần vào đầu ngày.

```json
{
  "marketId": "3",
  "boardId": "2",
  "isin": "VN41I1G20009",
  "symbol": "41I1G2000",
  "productGrpId": "4",
  "securityGroupId": "4",
  "basicPrice": 2066.6,
  "ceilingPrice": 2211.2,
  "floorPrice": 1922.0,
  "openInterestQuantity": "24473",
  "securityStatus": "0",
  "symbolAdminStatusCode": "3",
  "symbolTradingMethodStatusCode": "1",
  "symbolTradingSanctionStatusCode": "1"
}
```

### 2) Trade & Trade Extra (Dữ liệu khớp lệnh)

- **Trade**: dữ liệu khớp lệnh cơ bản, tối ưu tốc độ.
- **Trade Extra**: có thêm dữ liệu tổng hợp như chiều chủ động mua/bán (`side`) và giá trung bình (`avgPrice`).

#### Ví dụ Trade

```json
{
  "marketId": "3",
  "boardId": "2",
  "isin": "VN41I1G20009",
  "symbol": "41I1G2000",
  "price": 1999.8,
  "quantity": 3.0,
  "totalVolumeTraded": 84164,
  "grossTradeAmount": 16817.93009,
  "highestPrice": 2009.6,
  "lowestPrice": 1988.8,
  "openPrice": 2005.6,
  "tradingSessionId": 7
}
```

#### Ví dụ Trade Extra

```json
{
  "marketId": 3,
  "boardId": 2,
  "isin": "VN41I1G20009",
  "symbol": "41I1G2000",
  "price": 1994.0,
  "quantity": 1.0,
  "side": 0,
  "avgPrice": 1997.654,
  "totalVolumeTraded": 104264,
  "grossTradeAmount": 20828.33542,
  "highestPrice": 2009.6,
  "lowestPrice": 1988.8,
  "openPrice": 2005.6,
  "tradingSessionId": 7
}
```

### 3) Quote (Độ sâu thị trường)

Thông tin giá chào mua/chào bán theo mức giá:
- HOSE: tối đa 3 mức
- HNX/UPCOM: tối đa 10 mức

```json
{
  "marketId": "6",
  "boardId": "2",
  "isin": "VN000000HPG4",
  "symbol": "HPG",
  "bid": [
    { "price": 28.3, "quantity": 13330.0 },
    { "price": 28.25, "quantity": 40830.0 },
    { "price": 28.2, "quantity": 50490.0 }
  ],
  "offer": [
    { "price": 28.35, "quantity": 12660.0 },
    { "price": 28.4, "quantity": 27530.0 },
    { "price": 28.45, "quantity": 26710.0 }
  ],
  "totalOfferQtty": 922230,
  "totalBidQtty": 643750
}
```

### 4) OHLC

Nến thời gian thực (open, high, low, close, volume) cho:
- STOCK
- DERIVATIVE
- INDEX

#### Ví dụ STOCK

```json
{
  "time": "1757992500",
  "open": 30.4,
  "high": 30.4,
  "low": 30.25,
  "close": 30.3,
  "volume": "1398200",
  "symbol": "HPG",
  "resolution": "15",
  "lastUpdated": "1757993014",
  "type": "STOCK"
}
```

#### Ví dụ DERIVATIVE

```json
{
  "time": "1757991840",
  "open": 1881.2,
  "high": 1881.2,
  "low": 1881.0,
  "close": 1881.2,
  "volume": "12",
  "symbol": "VN30F1M",
  "resolution": "1",
  "lastUpdated": "1757991844",
  "type": "DERIVATIVE"
}
```

#### Ví dụ INDEX

```json
{
  "time": "1757988000",
  "open": 1696.87,
  "high": 1696.87,
  "low": 1686.02,
  "close": 1686.31,
  "volume": "435873728",
  "symbol": "VNINDEX",
  "resolution": "1D",
  "lastUpdated": "1757993070",
  "type": "INDEX"
}
```

### 5) Expected Price (Giá khớp dự kiến)

Dùng trong phiên định kỳ ATO/ATC để cung cấp giá đóng cửa, giá dự khớp và khối lượng dự khớp.

```json
{
  "marketId": "3",
  "boardId": "2",
  "symbol": "41I1G1000",
  "ISIN": "VN41I1G10000",
  "closePrice": 28.45,
  "expectedTradePrice": 28.45,
  "expectedTradeQuantity": "133780"
}
```


## Ghi chú cập nhật

- Dữ liệu mã chứng khoán hiện được lưu tại bảng `wp_lcni_symbols` (tuỳ prefix).
- Có thể import CSV symbol từ trang admin `LCNI Data` để phục vụ seed queue.
- Nút `Sync Security Definition` sẽ cập nhật các trường Security Definition (giá trần/sàn/tham chiếu, trạng thái giao dịch...) vào `lcni_symbols`.
- Cron tự động đồng bộ Security Definition chạy hàng ngày lúc 08:00.
