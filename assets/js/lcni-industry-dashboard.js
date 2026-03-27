(function () {
    'use strict';

    function bootstrapIndustryDashboard(node) {
        var apiBase = node.getAttribute('data-api-base');
        var timeframe = node.getAttribute('data-timeframe') || '1D';
        var limit = parseInt(node.getAttribute('data-limit') || '20', 10);
        var title = node.getAttribute('data-title') || 'Industry Leadership';

        if (!apiBase) {
            return;
        }

        var url = new URL(apiBase, window.location.origin);
        url.searchParams.set('timeframe', timeframe);
        url.searchParams.set('limit', String(limit));

        fetch(url.toString(), {
            credentials: 'same-origin'
        })
            .then(function (response) { return response.json(); })
            .then(function (payload) {
                var data = payload && payload.data ? payload.data : {};
                node.dataset.loaded = '1';
                node.dataset.eventTime = String(data.event_time || 0);

                window.dispatchEvent(new CustomEvent('lcni:industry-dashboard-ready', {
                    detail: {
                        title: title,
                        container: node,
                        payload: data
                    }
                }));
            })
            .catch(function () {
                node.dataset.loaded = '0';
            });
    }

    document.addEventListener('DOMContentLoaded', function () {
        var nodes = document.querySelectorAll('[data-lcni-industry-dashboard]');
        for (var i = 0; i < nodes.length; i += 1) {
            bootstrapIndustryDashboard(nodes[i]);
        }
    });
})();
