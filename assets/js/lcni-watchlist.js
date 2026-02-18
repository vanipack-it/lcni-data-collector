(function () {
  async function request(url, options = {}) {
    const res = await fetch(url, {
      credentials: "same-origin",
      headers: { "Content-Type": "application/json" },
      ...options,
    });
    return res.json();
  }

  function renderTable(root, data) {
    const body = root.querySelector(".lcni-watchlist__body");
    if (!data || !Array.isArray(data.items)) {
      body.innerHTML = "<p>Vui lòng đăng nhập để xem watchlist.</p>";
      return;
    }

    const fields = data.fields || ["symbol"];
    const head = fields.map((f) => `<th>${f}</th>`).join("");
    const rows = data.items
      .map((row) => {
        const cols = fields
          .map((f) => {
            const val = row[f] ?? "-";
            if (f === "symbol") {
              return `<td><button class=\"lcni-symbol-link\" data-symbol=\"${val}\">${val}</button></td>`;
            }
            return `<td>${val}</td>`;
          })
          .join("");
        return `<tr>${cols}</tr>`;
      })
      .join("");

    body.innerHTML = `<table><thead><tr>${head}</tr></thead><tbody>${rows}</tbody></table>`;
  }

  async function loadWatchlist(root) {
    const api = root.dataset.apiBase;
    const data = await request(`${api}/watchlist`);
    renderTable(root, data);
  }

  document.querySelectorAll("[data-lcni-watchlist]").forEach((root) => {
    loadWatchlist(root).catch(() => {
      root.querySelector(".lcni-watchlist__body").innerHTML = "<p>Không tải được watchlist.</p>";
    });

    const settingsBtn = root.querySelector(".lcni-watchlist__settings");
    settingsBtn?.addEventListener("click", async () => {
      const api = root.dataset.apiBase;
      const settings = await request(`${api}/watchlist/settings`);
      const next = prompt("Nhập danh sách cột cách nhau dấu phẩy", (settings.selected_fields || []).join(","));
      if (next === null) return;
      const fields = next
        .split(",")
        .map((v) => v.trim())
        .filter(Boolean);
      await request(`${api}/watchlist/settings`, {
        method: "POST",
        body: JSON.stringify({ fields }),
      });
      loadWatchlist(root);
    });

    root.addEventListener("click", async (e) => {
      const target = e.target.closest(".lcni-symbol-link");
      if (!target) return;
      const symbol = target.dataset.symbol;
      alert(`${symbol}\nOverview + Chart + LCNI Signal`);
    });
  });

  document.querySelectorAll("[data-lcni-watchlist-add]").forEach((btn) => {
    btn.addEventListener("click", async () => {
      const symbol = btn.dataset.symbol;
      await request(`${window.location.origin}/wp-json/lcni/v1/watchlist`, {
        method: "POST",
        body: JSON.stringify({ symbol }),
      });
      btn.classList.add("added");
    });
  });
})();
