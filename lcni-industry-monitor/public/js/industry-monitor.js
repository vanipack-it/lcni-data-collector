(function () {
    function postData(metric, timeframe) {
        var form = new FormData();
        form.append('action', 'lcni_industry_data');
        form.append('nonce', LCNIIndustryMonitor.nonce);
        form.append('metric', metric);
        form.append('timeframe', timeframe);
        form.append('limit', '30');

        return fetch(LCNIIndustryMonitor.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: form
        }).then(function (response) {
            return response.json();
        });
    }

    function renderTable(data) {
        var headerRow = document.getElementById('lcni-industry-header-row');
        var body = document.getElementById('lcni-industry-body');
        if (!headerRow || !body) {
            return;
        }

        headerRow.innerHTML = '<th>Industry</th>';
        body.innerHTML = '';

        (data.columns || []).forEach(function (eventTime) {
            var th = document.createElement('th');
            th.textContent = String(eventTime);
            headerRow.appendChild(th);
        });

        (data.rows || []).forEach(function (row) {
            var tr = document.createElement('tr');

            var industryCell = document.createElement('th');
            industryCell.className = 'lcni-industry-monitor__sticky-col';
            industryCell.textContent = row.industry || '';
            tr.appendChild(industryCell);

            (row.values || []).forEach(function (value) {
                var td = document.createElement('td');
                td.textContent = (value === null || typeof value === 'undefined') ? '-' : String(value);
                tr.appendChild(td);
            });

            body.appendChild(tr);
        });
    }

    function loadData() {
        var metricEl = document.getElementById('lcni-industry-metric');
        var timeframeEl = document.getElementById('lcni-industry-timeframe');
        if (!metricEl || !timeframeEl) {
            return;
        }

        postData(metricEl.value, timeframeEl.value)
            .then(function (payload) {
                if (!payload || !payload.success) {
                    return;
                }
                renderTable(payload.data || {});
            })
            .catch(function () {
                // no-op
            });
    }

    document.addEventListener('DOMContentLoaded', function () {
        var metricEl = document.getElementById('lcni-industry-metric');
        var timeframeEl = document.getElementById('lcni-industry-timeframe');
        if (!metricEl || !timeframeEl) {
            return;
        }

        metricEl.addEventListener('change', loadData);
        timeframeEl.addEventListener('change', loadData);
        loadData();
    });
})();
