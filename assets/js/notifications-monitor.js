/**
 * notifications-monitor.js — Módulo [1007], Fase 6
 * Requiere que el usuario tenga notifications.admin — si no, el API
 * responde ERR_RBAC y esta página lo muestra en vez de la data.
 */
(function () {
    'use strict';

    async function load() {
        const filters = {};
        const sev = document.getElementById('filter-severity').value;
        const from = document.getElementById('filter-date-from').value;
        const to = document.getElementById('filter-date-to').value;
        if (sev) filters.severity = sev;
        if (from) filters.date_from = from;
        if (to) filters.date_to = to;

        const res = await window.OmniApiClient.Monitor.get(filters);

        if (res.status === 'error') {
            document.getElementById('kpi-row').innerHTML =
                `<div class="kpi-card"><div class="kpi-label">${res.message}</div></div>`;
            return;
        }

        renderKpis(res.data.summary);
        renderTable(res.data.notifications);
    }

    function renderKpis(summary) {
        document.getElementById('kpi-row').innerHTML = `
            <div class="kpi-card"><div class="kpi-value">${summary.total}</div><div class="kpi-label">Total</div></div>
            <div class="kpi-card kpi-critical"><div class="kpi-value">${summary.critical}</div><div class="kpi-label">Críticas</div></div>
            <div class="kpi-card kpi-warning"><div class="kpi-value">${summary.warning}</div><div class="kpi-label">Advertencias</div></div>
            <div class="kpi-card kpi-info"><div class="kpi-value">${summary.info}</div><div class="kpi-label">Informativas</div></div>
        `;
    }

    function renderTable(notifications) {
        const tbody = document.getElementById('notif-table-body');
        if (!notifications.length) {
            tbody.innerHTML = '<tr><td colspan="6">Sin notificaciones para este filtro</td></tr>';
            return;
        }
        tbody.innerHTML = notifications.map((n) => {
            const readCount = (n.recipients || []).filter((r) => r.is_read).length;
            const total = (n.recipients || []).length;
            return `
                <tr>
                    <td><span class="sev-dot sev-${n.severity}"></span>${n.severity}</td>
                    <td>${n.type}</td>
                    <td>${escapeHtml(n.title)}</td>
                    <td>${n.interlocutor_id ?? '—'}</td>
                    <td>${n.created_at}</td>
                    <td class="recipients-count">${readCount}/${total} leídas</td>
                </tr>
            `;
        }).join('');
    }

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    document.getElementById('btn-filter').addEventListener('click', load);
    load();
})();
