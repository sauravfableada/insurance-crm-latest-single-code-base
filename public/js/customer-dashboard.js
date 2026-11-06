(function () {
    function init() {
        const dashboard = document.querySelector('.customer-dashboard');
        if (!dashboard) return;
        const search = document.getElementById('customerRecordSearch');
        const empty = document.getElementById('customerRecordSearchEmpty');
        function filter() {
            const query = (search?.value || '').trim().toLowerCase();
            const pane = dashboard.querySelector('.tab-pane.active');
            let visible = 0;
            pane?.querySelectorAll('tbody tr').forEach(function (row) {
                if (row.classList.contains('customer-member-editor-row')) {
                    row.hidden = row.previousElementSibling?.hidden || false;
                    return;
                }
                row.hidden = query !== '' && !row.textContent.toLowerCase().includes(query);
                if (!row.hidden) visible++;
            });
            empty?.classList.toggle('d-none', !query || visible > 0);
        }
        search?.addEventListener('input', filter);
        dashboard.querySelectorAll('[data-bs-toggle="tab"]').forEach(function (tab) {
            tab.addEventListener('shown.bs.tab', filter);
        });
        const target = dashboard.dataset.familyErrors === '1' ? '#customer-family' : window.location.hash;
        if (/^#customer-[a-z]+$/.test(target) && window.bootstrap?.Tab) {
            const tab = dashboard.querySelector('[data-bs-target="' + target + '"]');
            if (tab) window.bootstrap.Tab.getOrCreateInstance(tab).show();
        }
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
