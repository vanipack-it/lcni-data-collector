/**
 * lcni-inbox.js  v1.0
 * Bell + dropdown + badge + full inbox page
 */
(function () {
    'use strict';

    var CFG = window.lcniInboxCfg || {};
    var BASE = CFG.restBase || '';
    var NONCE = CFG.nonce || '';
    var INBOX_URL = CFG.inboxUrl || '/';
    var POLL_MS = (CFG.pollInterval || 60) * 1000;

    // ── HTTP helpers ──────────────────────────────────────────────────────────

    function apiFetch(path, opts) {
        opts = opts || {};
        // path bắt đầu bằng '/' → thêm trực tiếp; bắt đầu bằng '?' → query string
        var url = BASE + (path.charAt(0) === '?' ? path : (path.charAt(0) === '/' ? path : '/' + path));
        return fetch(url, {
            method: opts.method || 'GET',
            credentials: 'same-origin',
            headers: Object.assign({ 'X-WP-Nonce': NONCE, 'Content-Type': 'application/json' }, opts.headers || {}),
            body: opts.body ? JSON.stringify(opts.body) : undefined,
        }).then(function (r) { return r.json(); });
    }

    // ── Badge ─────────────────────────────────────────────────────────────────

    var _unread = 0;

    function setBadge(count) {
        var prev = _unread;
        _unread = Math.max(0, count);
        var badge = document.getElementById('lcni-bell-badge');
        if (!badge) return;
        badge.hidden = _unread === 0;
        badge.textContent = _unread > 99 ? '99+' : String(_unread);
        // Animate khi có thông báo mới
        if (_unread > prev) {
            badge.style.animation = 'none';
            void badge.offsetWidth; // reflow
            badge.style.animation = '';
            var btn = document.getElementById('lcni-bell-btn');
            if (btn) {
                btn.style.animation = 'none';
                void btn.offsetWidth;
                btn.classList.add('lcni-bell-shake');
                setTimeout(function() { btn.classList.remove('lcni-bell-shake'); }, 600);
            }
        }
    }

    function fetchCount() {
        apiFetch('/count').then(function (data) {
            setBadge(data.unread_count || 0);
        }).catch(function () {});
    }

    // ── Dropdown ──────────────────────────────────────────────────────────────

    var _dropOpen = false;
    var _dropLoaded = false;

    function renderDropdownItem(item) {
        var cls = item.is_read ? 'lcni-drop-item lcni-drop-item--read' : 'lcni-drop-item lcni-drop-item--unread';
        var detailUrl = INBOX_URL + (INBOX_URL.indexOf('?') >= 0 ? '&' : '?') + 'notif_id=' + item.id;
        return '<a class="' + cls + '" href="' + escHtml(detailUrl) + '" data-id="' + item.id + '">' +
            '<div class="lcni-drop-item__icon">' + (item.type_label || '🔔').charAt(0) + '</div>' +
            '<div class="lcni-drop-item__content">' +
                '<div class="lcni-drop-item__title">' + escHtml(item.title) + '</div>' +
                '<div class="lcni-drop-item__time">' + escHtml(item.time_ago) + '</div>' +
            '</div>' +
            (item.is_read ? '' : '<div class="lcni-drop-item__dot"></div>') +
        '</a>';
    }

    function openDropdown() {
        var drop = document.getElementById('lcni-bell-dropdown');
        if (!drop) return;
        _dropOpen = true;
        drop.hidden = false;

        drop.innerHTML =
            '<div class="lcni-drop-header">' +
                '<span class="lcni-drop-title">🔔 Thông báo</span>' +
                '<button class="lcni-drop-mark-all" id="lcni-drop-mark-all">✓ Đánh dấu đọc hết</button>' +
            '</div>' +
            '<div class="lcni-drop-list" id="lcni-drop-list"><div class="lcni-drop-loading">Đang tải...</div></div>' +
            '<div class="lcni-drop-footer">' +
                '<a href="' + escHtml(INBOX_URL) + '">Xem tất cả thông báo →</a>' +
            '</div>';

        // Load items
        apiFetch('?per_page=8&filter=all').then(function (data) {
            var list = document.getElementById('lcni-drop-list');
            if (!list) return;
            var items = (data.items || []);
            if (!items.length) {
                list.innerHTML = '<div class="lcni-drop-empty">Không có thông báo nào.</div>';
            } else {
                list.innerHTML = items.map(renderDropdownItem).join('');
            }
            setBadge(data.unread_count || 0);
            _dropLoaded = true;

            // Mark read on item click
            list.addEventListener('click', function (e) {
                var link = e.target.closest('[data-id]');
                if (!link) return;
                var id = parseInt(link.dataset.id, 10);
                apiFetch('/mark-read', { method: 'POST', body: { ids: [id] } })
                    .then(function (r) { setBadge(r.unread_count || 0); });
                link.classList.remove('lcni-drop-item--unread');
                link.classList.add('lcni-drop-item--read');
                var dot = link.querySelector('.lcni-drop-item__dot');
                if (dot) dot.remove();
            });
        }).catch(function () {
            var list = document.getElementById('lcni-drop-list');
            if (list) list.innerHTML = '<div class="lcni-drop-empty">Không thể tải thông báo.</div>';
        });

        // Mark all
        var markAll = document.getElementById('lcni-drop-mark-all');
        if (markAll) {
            markAll.addEventListener('click', function () {
                apiFetch('/mark-read', { method: 'POST', body: { ids: 'all' } })
                    .then(function (r) {
                        setBadge(0);
                        var list = document.getElementById('lcni-drop-list');
                        if (list) list.querySelectorAll('.lcni-drop-item--unread').forEach(function (el) {
                            el.classList.remove('lcni-drop-item--unread');
                            el.classList.add('lcni-drop-item--read');
                            var dot = el.querySelector('.lcni-drop-item__dot');
                            if (dot) dot.remove();
                        });
                    });
            });
        }
    }

    function closeDropdown() {
        var drop = document.getElementById('lcni-bell-dropdown');
        if (drop) drop.hidden = true;
        _dropOpen = false;
    }

    function toggleDropdown() {
        if (_dropOpen) { closeDropdown(); } else { openDropdown(); }
    }

    // ── Bell bind ─────────────────────────────────────────────────────────────

    function bindBell() {
        var btn = document.getElementById('lcni-bell-btn');
        if (!btn || btn.dataset.lcniBound) return;
        btn.dataset.lcniBound = '1';

        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            toggleDropdown();
        });

        document.addEventListener('click', function (e) {
            var wrap = document.getElementById('lcni-bell-wrap');
            if (wrap && !wrap.contains(e.target)) closeDropdown();
        });

        // Keyboard
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && _dropOpen) closeDropdown();
        });
    }

    // ── Inbox Page ────────────────────────────────────────────────────────────

    var InboxPage = {
        page: 1,
        filter: 'all',
        typeFilter: '',
        hasMore: true,
        loading: false,

        init: function (hostId) {
            var host = document.getElementById(hostId);
            if (!host) return;
            this.host = host;
            this.loadItems(true);
            this.bindEvents();
            this.loadPrefs();
        },

        bindEvents: function () {
            var self = this;
            var host = this.host;

            // Filter tabs (read/unread/all + type)
            host.querySelectorAll('.lcni-inbox-tab').forEach(function (tab) {
                tab.addEventListener('click', function () {
                    // Tách 2 nhóm: filter tabs và type tabs
                    if (tab.classList.contains('lcni-inbox-tab--type')) {
                        // Toggle type filter
                        var wasActive = tab.classList.contains('active');
                        host.querySelectorAll('.lcni-inbox-tab--type').forEach(function (t) { t.classList.remove('active'); });
                        if (!wasActive) {
                            tab.classList.add('active');
                            self.typeFilter = tab.dataset.type || '';
                        } else {
                            self.typeFilter = '';
                        }
                    } else {
                        host.querySelectorAll('.lcni-inbox-tab:not(.lcni-inbox-tab--type)').forEach(function (t) { t.classList.remove('active'); });
                        tab.classList.add('active');
                        self.filter = tab.dataset.filter || 'all';
                    }
                    self.loadItems(true);
                });
            });

            // Mark all read
            var markAll = document.getElementById('lcni-inbox-mark-all');
            if (markAll) markAll.addEventListener('click', function () {
                apiFetch('/mark-read', { method: 'POST', body: { ids: 'all' } }).then(function (r) {
                    setBadge(0);
                    host.querySelectorAll('.lcni-inbox-item--unread').forEach(function (el) {
                        el.classList.remove('lcni-inbox-item--unread');
                    });
                });
            });

            // Load more
            var loadMore = document.getElementById('lcni-inbox-load-more');
            if (loadMore) loadMore.addEventListener('click', function () {
                self.page++;
                self.loadItems(false);
            });

            // Prefs toggle
            var prefsToggle = document.getElementById('lcni-inbox-prefs-toggle');
            var prefsPanel  = document.getElementById('lcni-inbox-prefs');
            if (prefsToggle && prefsPanel) {
                prefsToggle.addEventListener('click', function () {
                    prefsPanel.hidden = !prefsPanel.hidden;
                });
            }

            // Save prefs
            var savePrefs = document.getElementById('lcni-inbox-save-prefs');
            if (savePrefs) savePrefs.addEventListener('click', function () {
                self.savePrefs();
            });
        },

        loadItems: function (reset) {
            if (this.loading) return;
            var self = this;
            if (reset) { this.page = 1; this.hasMore = true; }
            this.loading = true;

            var listEl = document.getElementById('lcni-inbox-list');
            var loadMore = document.getElementById('lcni-inbox-load-more');
            if (reset && listEl) listEl.innerHTML = '<div class="lcni-inbox-loading">Đang tải...</div>';

            apiFetch('?per_page=20&page=' + this.page + '&filter=' + this.filter + (this.typeFilter ? '&type=' + this.typeFilter : '')).then(function (data) {
                self.loading = false;
                var items = data.items || [];
                setBadge(data.unread_count || 0);

                if (reset && listEl) listEl.innerHTML = '';
                if (!items.length && reset && listEl) {
                    listEl.innerHTML = '<div class="lcni-inbox-empty">Không có thông báo nào.</div>';
                    if (loadMore) loadMore.hidden = true;
                    return;
                }

                items.forEach(function (item) {
                    var el = self.createItem(item);
                    if (listEl) listEl.appendChild(el);
                });

                self.hasMore = items.length >= 20;
                if (loadMore) loadMore.hidden = !self.hasMore;
            }).catch(function () {
                self.loading = false;
                var listEl = document.getElementById('lcni-inbox-list');
                if (listEl) listEl.innerHTML = '<div class="lcni-inbox-empty">Lỗi tải thông báo.</div>';
            });
        },

        createItem: function (item) {
            var el  = document.createElement('div');
            var cls = 'lcni-inbox-item' + ( item.is_read ? '' : ' lcni-inbox-item--unread' );
            var detailUrl = INBOX_URL + (INBOX_URL.indexOf('?') >= 0 ? '&' : '?') + 'notif_id=' + item.id;
            el.className = cls;
            el.dataset.id = item.id;
            el.innerHTML =
                '<div class="lcni-inbox-item__indicator"></div>' +
                '<div class="lcni-inbox-item__main">' +
                    '<div class="lcni-inbox-item__top">' +
                        '<span class="lcni-inbox-type-badge lcni-inbox-type-' + escAttr(item.type) + '">' + escHtml(item.type_label) + '</span>' +
                        '<span class="lcni-inbox-item__time">' + escHtml(item.time_ago) + '</span>' +
                    '</div>' +
                    '<div class="lcni-inbox-item__title">' + escHtml(item.title) + '</div>' +
                    '<div class="lcni-inbox-item__preview">' + stripTags(item.body).substring(0, 80) + '...</div>' +
                '</div>' +
                '<a class="lcni-inbox-item__link" href="' + escHtml(detailUrl) + '">Xem →</a>';

            // Mark read on click
            el.addEventListener('click', function () {
                if (!item.is_read) {
                    apiFetch('/mark-read', { method: 'POST', body: { ids: [item.id] } })
                        .then(function (r) { setBadge(r.unread_count || 0); });
                    el.classList.remove('lcni-inbox-item--unread');
                    item.is_read = true;
                }
            });

            return el;
        },

        loadPrefs: function () {
            var listEl = document.getElementById('lcni-inbox-prefs-list');
            if (!listEl) return;
            apiFetch('/prefs').then(function (data) {
                if (!Array.isArray(data)) return;
                listEl.innerHTML = data.map(function (p) {
                    return '<label class="lcni-prefs-item">' +
                        '<input type="checkbox" name="pref_' + escAttr(p.type) + '" value="' + escAttr(p.type) + '"' +
                        (p.enabled ? ' checked' : '') + '> ' + escHtml(p.label) + '</label>';
                }).join('');
            });
        },

        savePrefs: function () {
            var listEl = document.getElementById('lcni-inbox-prefs-list');
            if (!listEl) return;
            var prefs = {};
            listEl.querySelectorAll('input[type="checkbox"]').forEach(function (cb) {
                prefs[cb.value] = cb.checked;
            });
            apiFetch('/prefs', { method: 'POST', body: { prefs: prefs } }).then(function () {
                var btn = document.getElementById('lcni-inbox-save-prefs');
                if (btn) { btn.textContent = '✅ Đã lưu'; setTimeout(function () { btn.textContent = '💾 Lưu tùy chọn'; }, 2000); }
            });
        },
    };

    // ── Utils ─────────────────────────────────────────────────────────────────

    function escHtml(str) {
        return String(str || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
    function escAttr(str) { return escHtml(str); }
    function stripTags(str) { return String(str || '').replace(/<[^>]+>/g, ''); }

    // ── Polling ───────────────────────────────────────────────────────────────

    function startPolling() {
        fetchCount();
        var pollTimer = setInterval(fetchCount, POLL_MS);
        // Pause polling khi tab ẩn, resume khi active
        document.addEventListener('visibilitychange', function () {
            if (document.hidden) {
                clearInterval(pollTimer);
            } else {
                fetchCount(); // Fetch ngay khi quay lại
                pollTimer = setInterval(fetchCount, POLL_MS);
            }
        });
    }

    // ── Boot ──────────────────────────────────────────────────────────────────

    function boot() {
        // Expose InboxPage cho shortcode
        window.LCNIInboxPage = InboxPage;

        // Auto-init page nếu shortcode đã set lcniInboxPageId
        var pageId = window.lcniInboxPageId;
        if (pageId && document.getElementById(pageId)) {
            InboxPage.init(pageId);
        }

        // Bind bell nếu đã được inject (từ ThemeIntegrationModule)
        var tryBind = 0;
        var interval = setInterval(function () {
            bindBell();
            tryBind++;
            if (document.getElementById('lcni-bell-btn') || tryBind > 20) clearInterval(interval);
        }, 200);

        startPolling();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
