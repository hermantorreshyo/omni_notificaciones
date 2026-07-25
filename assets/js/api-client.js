/**
 * api-client.js — Módulo [1007]
 * OMNI API CORE v6.9 · JOSEPAN 360
 *
 * Sigue al pie de la letra el patrón de proxy documentado en el manual de
 * desarrollador (sección 6): el subsistema nunca llama al API directamente,
 * siempre a través de /api/omni.php?action=... en el mismo dominio. El JWT
 * nunca toca el navegador — vive en la cookie HttpOnly que gestiona el proxy.
 *
 * No usa fetch con headers de auth manuales — el proxy los inyecta.
 */

(function (global) {
    'use strict';

    /**
     * Llamada genérica al proxy — mismo contrato que el resto de subsistemas.
     */
    async function apiCall(action, method = 'GET', body = null) {
        const opts = { method, headers: { 'Content-Type': 'application/json' } };
        if (body) opts.body = JSON.stringify(body);
        const response = await fetch(`/api/omni.php?action=${encodeURIComponent(action)}`, opts);
        return response.json();
    }

    /**
     * Cliente específico de notificaciones — envuelve apiCall con los
     * endpoints de [1007] documentados en docs/prompt_1007_notificaciones_alertas.md
     */
    const NotificationsAPI = {
        /**
         * @param {{unreadOnly?: boolean, limit?: number, offset?: number}} opts
         */
        list(opts = {}) {
            const params = new URLSearchParams();
            if (opts.unreadOnly) params.set('unread_only', 'true');
            if (opts.limit) params.set('limit', String(opts.limit));
            if (opts.offset) params.set('offset', String(opts.offset));
            const qs = params.toString();
            return apiCall(`notifications${qs ? '?' + qs : ''}`, 'GET');
        },

        markRead(notificationId) {
            return apiCall(`notifications/${notificationId}/read`, 'PATCH');
        },

        markAllRead() {
            return apiCall('notifications/read-all', 'PATCH');
        },
    };

    /**
     * Cliente del panel de monitoreo (Fase 6) — requiere notifications.admin
     */
    const MonitorAPI = {
        get(filters = {}) {
            const params = new URLSearchParams(filters);
            const qs = params.toString();
            return apiCall(`notifications/monitor${qs ? '?' + qs : ''}`, 'GET');
        },
    };

    /**
     * Cliente CRUD de reglas programadas (Fase 6) — requiere notifications.admin.
     * Nunca envía ni recibe identificadores de tabla/columna — solo IDs de
     * catálogo y valores (hora, alcance, umbral).
     */
    const RulesAPI = {
        ruleTypes() {
            return apiCall('notifications/rules/types', 'GET');
        },
        formOptions() {
            return apiCall('notifications/rules/form-options', 'GET');
        },
        list() {
            return apiCall('notifications/rules', 'GET');
        },
        create(rule) {
            return apiCall('notifications/rules', 'POST', rule);
        },
        update(id, rule) {
            return apiCall(`notifications/rules/${id}`, 'PUT', rule);
        },
        remove(id) {
            return apiCall(`notifications/rules/${id}`, 'DELETE');
        },
    };

    global.OmniApiClient = global.OmniApiClient || {};
    global.OmniApiClient.apiCall = apiCall;
    global.OmniApiClient.Notifications = NotificationsAPI;
    global.OmniApiClient.Monitor = MonitorAPI;
    global.OmniApiClient.Rules = RulesAPI;
})(typeof window !== 'undefined' ? window : globalThis);
