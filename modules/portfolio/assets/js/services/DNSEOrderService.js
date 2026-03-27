/**
 * DNSEOrderService.js
 * ─────────────────────────────────────────────────────────────
 * Dedicated service for sending real trading orders to DNSE API.
 *
 * Called by LCNITransactionController when order type ≠ Manual.
 *
 * Pipeline:
 *   DNSEOrderService.sendOrder(order)
 *     → POST /lcni/v1/dnse/order
 *     → returns { success, order_id, message } | { success:false, error }
 *
 * Price convention:
 *   - LO:           price in DB format (nghìn đồng) e.g. 21.5 = 21,500 VNĐ
 *   - ATO/ATC/MP/PM: price = 0 (market orders — DNSE ignores price)
 * ─────────────────────────────────────────────────────────────
 */
(function (global) {
  'use strict';

  /** Market order types that do not require a price */
  var MARKET_TYPES = ['ATO', 'ATC', 'MP', 'PM'];

  /* ── Config helpers ──────────────────────────────────────── */
  function cfg() {
    return global.lcniPortfolioConfig
      || global.lcniTxControllerConfig
      || global.lcniAddTxConfig
      || {};
  }
  function nonce() { return cfg().nonce || ''; }
  function dnseOrderUrl() {
    var base = cfg().dnseOrderUrl || ((cfg().restUrl || '') + '/dnse/order');
    return base.indexOf('http') === 0 ? base : (cfg().restUrl || '') + '/dnse/order';
  }

  /** VNĐ full (21500) → DB/DNSE format (21.5) */
  function vndToDb(p) {
    return (parseFloat(p) / 1000).toFixed(4);
  }

  /* ══════════════════════════════════════════════════════════
     DNSEOrderService
  ══════════════════════════════════════════════════════════ */
  var DNSEOrderService = {

    /**
     * Send a real order to DNSE.
     *
     * @param {object} order
     * @param {string}  order.symbol          — ticker, uppercase
     * @param {string}  order.type            — 'buy' | 'sell'
     * @param {string}  order.dnseAccountNo   — DNSE account number
     * @param {string}  order.dnseAccountType — 'spot' | 'margin'
     * @param {string}  order.dnseOrderType   — 'LO' | 'ATO' | 'ATC' | 'MP' | 'PM'
     * @param {number}  order.priceVnd        — price in full VNĐ (ignored for market orders)
     * @param {string|number} order.qty       — quantity
     *
     * @returns {Promise<{success:boolean, order_id?:string, message?:string, error?:string}>}
     */
    sendOrder: function (order) {
      var isMarket = MARKET_TYPES.indexOf(order.dnseOrderType || '') !== -1;
      var price    = isMarket ? 0 : parseFloat(vndToDb(order.priceVnd || 0));

      return fetch(dnseOrderUrl(), {
        method:      'POST',
        headers:     { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce() },
        credentials: 'same-origin',
        body: JSON.stringify({
          account_no:   order.dnseAccountNo,
          account_type: order.dnseAccountType || 'spot',
          symbol:       order.symbol,
          side:         order.type === 'sell' ? 'sell' : 'buy',
          order_type:   order.dnseOrderType   || 'LO',
          price:        price,
          quantity:     parseInt(order.qty, 10),
        }),
      })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          if (res && res.success) {
            return {
              success:  true,
              order_id: (res.data && res.data.order_id) || '',
              message:  (res.data && res.data.message)  || 'Đặt lệnh thành công.',
            };
          }
          return {
            success: false,
            error:   (res && (res.data || res.message)) || 'Đặt lệnh thất bại.',
          };
        })
        .catch(function (err) {
          return {
            success: false,
            error:   'Không kết nối được DNSE API.',
          };
        });
    },

    /**
     * Whether this order type requires sending to DNSE.
     * Manual orders do NOT go to DNSE.
     */
    isDnseOrder: function (orderType) {
      return orderType !== 'MANUAL' && orderType !== 'manual' && !!orderType;
    },

    /**
     * Whether this order type is a market order (price disabled).
     */
    isMarketOrder: function (orderType) {
      return MARKET_TYPES.indexOf(orderType || '') !== -1;
    },
  };

  /* ── Export ──────────────────────────────────────────────── */
  global.DNSEOrderService = DNSEOrderService;

}(window));
