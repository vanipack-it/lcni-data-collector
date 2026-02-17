document.addEventListener("DOMContentLoaded", async () => {
  const container = document.querySelector("[data-lcni-chart]");
  if (!container) {
    return;
  }

  const apiUrl = container.dataset.apiUrl;
  console.log("LCNI: API URL", apiUrl);

  if (!apiUrl) {
    container.textContent = "NO DATA";
    console.error("LCNI: missing API URL");
    return;
  }

  try {
    const response = await fetch(apiUrl, { credentials: "same-origin" });
    if (!response.ok) {
      throw new Error(`LCNI: request failed (${response.status})`);
    }

    const data = await response.json();
    if (!Array.isArray(data)) {
      throw new Error("LCNI: invalid candles payload");
    }

    console.log("LCNI: data length", data.length);
    console.log("LCNI: first candle", data[0] || null);

    if (!data.length) {
      console.error("LCNI: empty dataset");
      container.textContent = "NO DATA";
      return;
    }

    const chart = LightweightCharts.createChart(container, {
      autoSize: true,
      layout: { background: { color: "#fff" }, textColor: "#333" }
    });

    const series = chart.addCandlestickSeries();

    series.setData(data);
  } catch (error) {
    console.error(error);
    container.textContent = "NO DATA";
  }
});
