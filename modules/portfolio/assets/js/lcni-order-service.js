/**
 * lcni-order-service.js
 * ─────────────────────────────────────────────────────────────
 * LCNIOrderService — Unified order execution pipeline.
 *
 * Handles the full flow for BOTH entry points:
 *   1. Portfolio "Add Transaction" button
 *   2. [lcni_add_transaction_float] shortcode
 *
 * Pipeline:
 *   LCNIOrderService.execute(order)
 *     → (optional) POST /lcni/v1/dnse/order   ← DNSE real order
 *     → POST /lcni/v1/portfolio/tx/add         ← save to portfolio DB
 *     → lcniPortfolioReload()                  ← refresh UI
 *     → CustomEvent 'lcniTxAdded'              ← notify other widgets
 *
 * Used by:
 *   • portfolio.js  → saveTx()
 *   • lcni-transaction-controller.js → submitTransaction()
 *
 * Config source: window.lcniPortfolioConfig || window.lcniTxControllerConfig
 * ─────────────────────────────────────────────────────────────
 */
(function (global) {
  'use strict';

  /* ── Helpers ─────────────────────────────────────────────── */
  function cfg() {
    return global.lcniPortfolioConfig
      || global.lcniTxControllerConfig
      || global.lcniAddTxConfig
      || {};
  }

  function restUrl() { return cfg().restUrl || ''; }
  function nonce()   { return cfg().nonce   || ''; }

  /** VNĐ đầy đủ (21500) → DB format (21.5) */
  function vndToDb(p) { return (parseFloat(p) / 1000).toFixed(4); }

  /** POST wrapper trả về Promise<json> */
  function post(endpoint, body) {
    return fetch(restUrl() + endpoint, {
      method:      'POST',
      headers:     { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce() },
      credentials: 'same-origin',
      body:        JSON.stringify(body),
    }).then(function (r) { return r.json(); });
  }

  /* ══════════════════════════════════════════════════════════
     LCNIOrderService
  ══════════════════════════════════════════════════════════ */
  var LCNIOrderService = {

    /**
     * Execute the full order pipeline.
     *
     * @param {object} order
     * @param {string}  order.symbol        — ticker, uppercase
     * @param {string}  order.type          — 'buy' | 'sell' | 'dividend' | 'fee'
     * @param {number}  order.portfolioId   — portfolio DB id
     * @param {number}  order.priceVnd      — price in full VNĐ (e.g. 21500)
     * @param {string}  order.qty           — quantity string
     * @param {string}  order.date          — YYYY-MM-DD
     * @param {number}  order.fee
     * @param {number}  order.tax
     * @param {string}  order.note
     *
     * DNSE fields (optional — only when user has DNSE connected and checked):
     * @param {boolean} order.sendDnse      — whether to place real DNSE order
     * @param {string}  order.dnseAccountNo — DNSE account number
     * @param {string}  order.dnseOrderType — 'LO'|'ATO'|'ATC'|'MP'
     * @param {string}  order.dnseAccountType — 'spot'|'margin'
     *
     * @returns {Promise<{success:boolean, message?:string, dnseOrderId?:string}>}
     */
    execute: function (order) {
      var self = this;

      // Step 1: Save transaction to portfolio DB
      return self.savePortfolioTx(order)
        .then(function (txRes) {
          if (!txRes.success) {
            return Promise.reject(new Error(txRes.data || 'Lỗi lưu giao dịch.'));
          }

          var txId = txRes.data && txRes.data.tx_id;

          // Step 2: Send DNSE real order if requested
          if (order.sendDnse && order.dnseAccountNo) {
            return self.sendDnseOrder(order)
              .then(function (dnseRes) {
                if (dnseRes.success) {
                  return {
                    success:    true,
                    txId:       txId,
                    dnseOrderId: dnseRes.data && dnseRes.data.order_id,
                    message:    dnseRes.data && dnseRes.data.message,
                  };
                }
                // DNSE failed — transaction already saved, warn user but don't rollback
                return {
                  success:         true,
                  txId:            txId,
                  dnseError:       dnseRes.data || 'Đặt lệnh DNSE thất bại.',
                  dnseOrderId:     null,
                };
              })
              .catch(function () {
                // Network error on DNSE — tx saved, warn only
                return {
                  success:    true,
                  txId:       txId,
                  dnseError:  'Không kết nối được DNSE API.',
                };
              });
          }

          // No DNSE order
          return { success: true, txId: txId };
        })
        .then(function (result) {
          // Step 3: Update portfolio store / refresh UI
          self.updatePortfolio(order.portfolioId, order);
          return result;
        });
    },

    /**
     * Execute order for EDIT flow (update existing transaction, no DNSE order).
     */
    executeUpdate: function (order) {
      var self = this;
      return self.updatePortfolioTx(order)
        .then(function (res) {
          if (!res.success) {
            return Promise.reject(new Error(res.data || 'Lỗi cập nhật giao dịch.'));
          }
          self.updatePortfolio(order.portfolioId, order);
          return { success: true };
        });
    },

    /* ── REST calls ──────────────────────────────────────── */

    savePortfolioTx: function (order) {
      return post('/portfolio/tx/add', {
        portfolio_id: order.portfolioId,
        symbol:       order.symbol,
        type:         order.type,
        trade_date:   order.date,
        quantity:     order.qty,
        price:        vndToDb(order.priceVnd),
        fee:          order.fee  || 0,
        tax:          order.tax  || 0,
        note:         order.note || '',
      });
    },

    updatePortfolioTx: function (order) {
      return post('/portfolio/tx/update', {
        tx_id:        order.txId,
        portfolio_id: order.portfolioId,
        symbol:       order.symbol,
        type:         order.type,
        trade_date:   order.date,
        quantity:     order.qty,
        price:        vndToDb(order.priceVnd),
        fee:          order.fee  || 0,
        tax:          order.tax  || 0,
        note:         order.note || '',
      });
    },

    /**
     * Send real order to DNSE.
     * Delegates entirely to DNSEOrderService.sendOrder() — single source of
     * truth for all DNSE HTTP communication.
     * Pipeline: Controller → LCNIOrderService → DNSEOrderService → API
     */
    sendDnseOrder: function (order) {
      if (global.DNSEOrderService && typeof global.DNSEOrderService.sendOrder === 'function') {
        return global.DNSEOrderService.sendOrder(order);
      }
      // DNSEOrderService not loaded — should never happen given wp_enqueue dependency order
      return Promise.resolve({ success: false, error: 'DNSEOrderService not available.' });
    },

    /* ── Portfolio refresh hooks ─────────────────────────── */

    updatePortfolio: function (portfolioId, order) {
      // Hook 1: portfolio.js reload function
      if (typeof global.lcniPortfolioReload === 'function') {
        global.lcniPortfolioReload();
      }

      // Hook 2: legacy LCNIPortfolioStore (for future use)
      if (global.LCNIPortfolioStore && typeof global.LCNIPortfolioStore.addTransaction === 'function') {
        global.LCNIPortfolioStore.addTransaction(order);
      }

      // Hook 3: legacy LCNIPortfolioUI (for future use)
      if (global.LCNIPortfolioUI && typeof global.LCNIPortfolioUI.refresh === 'function') {
        global.LCNIPortfolioUI.refresh();
      }

      // Hook 4: dispatch event for all subscribers
      global.dispatchEvent(new CustomEvent('lcniTxAdded', {
        detail: {
          portfolioId: portfolioId,
          symbol:      order.symbol,
          type:        order.type,
          price:       order.priceVnd,
        },
      }));
    },
  };

  /* ── Export ──────────────────────────────────────────────── */
  global.LCNIOrderService = LCNIOrderService;

}(window));
