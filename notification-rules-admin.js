/**
 * notification-rules-admin.js — Módulo [1007], Fase 6
 * El formulario nunca envía ni pide identificadores de tabla/columna —
 * solo IDs de catálogo (notification_type_id, rule_type_id, hierarchy_level_id)
 * y valores (nombre, hora, alcance, umbral).
 */
(function () {
    'use strict';

    const scopeSel = document.getElementById('f-scope');
    const wrapInterlocutor = document.getElementById('wrap-interlocutor');
    const wrapHierarchy = document.getElementById('wrap-hierarchy');
    const errorBox = document.getElementById('form-error');

    function toggleScopeFields() {
        wrapInterlocutor.classList.toggle('hidden', scopeSel.value !== 'specific_interlocutor');
        wrapHierarchy.classList.toggle('hidden', scopeSel.value !== 'hierarchy_level');
    }
    scopeSel.addEventListener('change', toggleScopeFields);

    async function loadFormOptions() {
        const res = await window.OmniApiClient.Rules.formOptions();
        if (res.status === 'error') {
            showError(res.message);
            return;
        }
        const { notification_types, hierarchy_levels, rule_types } = res.data;

        document.getElementById('f-notification-type').innerHTML =
            notification_types.map((t) => `<option value="${t.id}">${t.name}</option>`).join('');

        document.getElementById('f-hierarchy-level').innerHTML =
            hierarchy_levels.map((h) => `<option value="${h.id}">${h.name}</option>`).join('');

        document.getElementById('f-rule-type').innerHTML =
            rule_types.map((r) => `<option value="${r.id}" title="${r.description}">${r.name}</option>`).join('');
    }

    async function loadRules() {
        const res = await window.OmniApiClient.Rules.list();
        if (res.status === 'error') {
            showError(res.message);
            return;
        }
        const tbody = document.getElementById('rules-table-body');
        tbody.innerHTML = res.data.rules.map((r) => `
            <tr>
                <td>${escapeHtml(r.name)}</td>
                <td>${r.notification_type}</td>
                <td>${r.check_time}</td>
                <td>${scopeLabel(r)}</td>
                <td>${r.active ? 'Sí' : 'No'}</td>
                <td>${r.notifications_last_30d}</td>
                <td><button class="btn-delete" data-id="${r.id}">🗑</button></td>
            </tr>
        `).join('');

        tbody.querySelectorAll('.btn-delete').forEach((btn) => {
            btn.addEventListener('click', async (e) => {
                const id = e.target.dataset.id;
                if (!confirm('¿Eliminar esta regla?')) return;
                await window.OmniApiClient.Rules.remove(id);
                await loadRules();
            });
        });
    }

    function scopeLabel(r) {
        if (r.scope === 'all_pos') return 'Todas las sedes';
        if (r.scope === 'specific_interlocutor') return r.interlocutor_name || `Sede #${r.interlocutor_id}`;
        if (r.scope === 'hierarchy_level') return r.hierarchy_level_name || `Nivel #${r.hierarchy_level_id}`;
        return r.scope;
    }

    function showError(msg) {
        errorBox.textContent = msg;
        errorBox.classList.remove('hidden');
    }
    function clearError() {
        errorBox.classList.add('hidden');
    }

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    document.getElementById('btn-save').addEventListener('click', async () => {
        clearError();

        const scope = scopeSel.value;
        const payload = {
            name: document.getElementById('f-name').value.trim(),
            notification_type_id: document.getElementById('f-notification-type').value,
            rule_type_id: document.getElementById('f-rule-type').value,
            check_time: document.getElementById('f-check-time').value,
            scope,
        };
        if (scope === 'specific_interlocutor') {
            payload.interlocutor_id = document.getElementById('f-interlocutor-id').value;
        }
        if (scope === 'hierarchy_level') {
            payload.hierarchy_level_id = document.getElementById('f-hierarchy-level').value;
        }

        if (!payload.name) {
            showError('El nombre es obligatorio');
            return;
        }

        const res = await window.OmniApiClient.Rules.create(payload);
        if (res.status === 'error') {
            showError(res.message);
            return;
        }
        document.getElementById('f-name').value = '';
        await loadRules();
    });

    toggleScopeFields();
    loadFormOptions();
    loadRules();
})();
