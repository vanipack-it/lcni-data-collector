PROJECT: LCNI DATA PLATFORM

This repository is the DATA LAYER.

It provides data for a separate theme repository: lcni-theme.

RESPONSIBILITIES:

- Fetch stock data from external APIs
- Store into custom database tables
- Provide REST API for frontend
- Provide PHP public helper functions

DO NOT:

- Render HTML
- Handle UI
- Depend on theme

DATA CONTRACT:

Primary endpoint:

/wp-json/lcni/v1/stock/{symbol}

Response format:

{
  symbol: string,
  price: float,
  change: float,
  volume: int,
  ohlc: [],
  indicators: {
    ma: [],
    rsi: []
  }
}

ARCHITECTURE:

- Repository pattern for DB access
- Service layer for business logic
- REST Controller for API
