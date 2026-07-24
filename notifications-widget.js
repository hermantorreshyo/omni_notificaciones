/**
 * notifications-widget.js — Módulo [1007]
 * OMNI API CORE v6.9 · JOSEPAN 360
 *
 * Componente reutilizable para integrar en cualquier subsistema ([1002]-[1005]).
 * Requiere que api-client.js ya esté cargado antes que este archivo.
 *
 * Uso:
 *   <div id="omni-notif-widget"></div>
 *   <script src="/assets/js/api-client.js"></script>
 *   <script src="/assets/js/notifications-widget.js"></script>
 *   <script>OmniNotificationsWidget.mount('omni-notif-widget');</script>
 *
 * Cumple los estándares del manual de desarrollador (sección 5):
 * - Área táctil mínima 46x46px (Filosofía Fat-Finger, entornos con guantes)
 * - Polling cada 60s
 * - Código de color por severidad: crítico/advertencia/informativo
 */

(function (global) {
    'use strict';

    const POLL_INTERVAL_MS = 60000;

    const SEVERITY = {
        critical: { color: '#c0392b', label: 'Crítico' },
        warning:  { color: '#d98a3a', label: 'Advertencia' },
        info:     { color: '#6b7280', label: 'Informativo' },
    };

    const STYLE_ID = 'omni-notif-widget-styles';

    function injectStyles() {
        if (document.getElementById(STYLE_ID)) return;

        const css = `
            .omni-notif-root { position: relative; display: inline-block; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; }
            .omni-notif-bell {
                width: 46px; height: 46px; min-width: 46px; min-height: 46px;
                display: flex; align-items: center; justify-content: center;
                background: #F7F3EE; border: 1px solid #e2dcd3; border-radius: 10px;
                cursor: pointer; position: relative; font-size: 20px;
                color: #642a72; user-select: none;
            }
            .omni-notif-bell:active { background: #efe7dc; }
            .omni-notif-badge {
                position: absolute; top: -6px; right: -6px;
                background: #c0392b; color: #fff; border-radius: 999px;
                min-width: 20px; height: 20px; padding: 0 5px;
                font-size: 11px; font-family: 'IBM Plex Mono', monospace;
                display: flex; align-items: center; justify-content: center;
                font-weight: 600; line-height: 1;
            }
            .omni-notif-panel {
                position: absolute; right: 0; top: 54px; width: 340px; max-width: 92vw;
                max-height: 420px; overflow-y: auto; background: #ffffff;
                border: 1px solid #e2dcd3; border-radius: 12px;
                box-shadow: 0 8px 24px rgba(0,0,0,0.12); z-index: 1000; display: none;
            }
            .omni-notif-panel.open { display: block; }
            .omni-notif-panel-header {
                display: flex; align-items: center; justify-content: space-between;
                padding: 12px 14px; border-bottom: 1px solid #f0ece5;
                font-weight: 600; color: #642a72;
            }
            .omni-notif-mark-all {
                font-size: 12px; color: #642a72; background: none; border: none;
                cursor: pointer; min-height: 46px; padding: 0 8px; font-weight: 600;
            }
            .omni-notif-item {
                display: flex; gap: 10px; padding: 12px 14px;
                border-bottom: 1px solid #f5f2ed; align-items: flex-start;
            }
            .omni-notif-item.unread { background: #FAF7F2; }
            .omni-notif-dot {
                width: 10px; height: 10px; border-radius: 999px; margin-top: 6px; flex-shrink: 0;
            }
            .omni-notif-content { flex: 1; min-width: 0; }
            .omni-notif-title { font-size: 13.5px; font-weight: 600; color: #2a2a2a; margin: 0 0 2px; }
            .omni-notif-message { font-size: 12.5px; color: #5b5b5b; margin: 0 0 4px; line-height: 1.4; }
            .omni-notif-time { font-size: 11px; color: #9a9a9a; font-family: 'IBM Plex Mono', monospace; }
            .omni-notif-read-btn {
                min-width: 46px; min-height: 46px; margin: -12px -6px -12px 0;
                background: none; border: none; cursor: pointer; color: #642a72;
                font-size: 18px; flex-shrink: 0;
            }
            .omni-notif-empty { padding: 24px 14px; text-align: center; color: #9a9a9a; font-size: 13px; }
        `;
        const style = document.createElement('style');
        style.id = STYLE_ID;
        style.textContent = css;
        document.head.appendChild(style);
    }

    function timeAgo(isoString) {
        const diffMs = Date.now() - new Date(isoString.replace(' ', 'T') + 'Z').getTime();
        const mins = Math.floor(diffMs / 60000);
        if (mins < 1) return 'ahora';
        if (mins < 60) return `hace ${mins} min`;
        const hours = Math.floor(mins / 60);
        if (hours < 24) return `hace ${hours} h`;
        return `hace ${Math.floor(hours / 24)} d`;
    }

    class NotificationsWidget {
        constructor(containerId) {
            this.container = document.getElementById(containerId);
            if (!this.container) {
                throw new Error(`omni-notif: contenedor #${containerId} no encontrado`);
            }
            this.notifications = [];
            this.totalUnread = 0;
            this.isOpen = false;
            this.pollTimer = null;
        }

        async init() {
            injectStyles();
            this.render();
            await this.refresh();
            this.pollTimer = setInterval(() => this.refresh(), POLL_INTERVAL_MS);
        }

        destroy() {
            if (this.pollTimer) clearInterval(this.pollTimer);
        }

        async refresh() {
            try {
                const res = await global.OmniApiClient.Notifications.list({ limit: 20 });
                if (res.status === 'success') {
                    this.notifications = res.data.notifications;
                    this.totalUnread = res.data.total_unread;
                    this.renderBadge();
                    this.renderList();
                }
            } catch (err) {
                console.error('omni-notif: error al refrescar', err);
            }
        }

        render() {
            this.container.innerHTML = `
                <div class="omni-notif-root">
                    <button class="omni-notif-bell" type="button" aria-label="Notificaciones">
                        🔔
                        <span class="omni-notif-badge" style="display:none;"></span>
                    </button>
                    <div class="omni-notif-panel">
                        <div class="omni-notif-panel-header">
                            <span>Notificaciones</span>
                            <button class="omni-notif-mark-all" type="button">Marcar todas leídas</button>
                        </div>
                        <div class="omni-notif-list"></div>
                    </div>
                </div>
            `;

            const bell = this.container.querySelector('.omni-notif-bell');
            const panel = this.container.querySelector('.omni-notif-panel');
            const markAllBtn = this.container.querySelector('.omni-notif-mark-all');

            bell.addEventListener('click', () => {
                this.isOpen = !this.isOpen;
                panel.classList.toggle('open', this.isOpen);
            });

            document.addEventListener('click', (e) => {
                if (this.isOpen && !this.container.contains(e.target)) {
                    this.isOpen = false;
                    panel.classList.remove('open');
                }
            });

            markAllBtn.addEventListener('click', async () => {
                await global.OmniApiClient.Notifications.markAllRead();
                await this.refresh();
            });
        }

        renderBadge() {
            const badge = this.container.querySelector('.omni-notif-badge');
            if (this.totalUnread > 0) {
                badge.style.display = 'flex';
                badge.textContent = this.totalUnread > 99 ? '99+' : String(this.totalUnread);
            } else {
                badge.style.display = 'none';
            }
        }

        renderList() {
            const list = this.container.querySelector('.omni-notif-list');

            if (this.notifications.length === 0) {
                list.innerHTML = '<div class="omni-notif-empty">Sin notificaciones</div>';
                return;
            }

            list.innerHTML = this.notifications.map((n) => {
                const sev = SEVERITY[n.severity] || SEVERITY.info;
                return `
                    <div class="omni-notif-item ${n.is_read ? '' : 'unread'}" data-id="${n.id}">
                        <span class="omni-notif-dot" style="background:${sev.color}" title="${sev.label}"></span>
                        <div class="omni-notif-content">
                            <p class="omni-notif-title">${this.escapeHtml(n.title)}</p>
                            <p class="omni-notif-message">${this.escapeHtml(n.message)}</p>
                            <span class="omni-notif-time">${timeAgo(n.created_at)}</span>
                        </div>
                        ${n.is_read ? '' : '<button class="omni-notif-read-btn" type="button" title="Marcar como leída">✓</button>'}
                    </div>
                `;
            }).join('');

            list.querySelectorAll('.omni-notif-read-btn').forEach((btn) => {
                btn.addEventListener('click', async (e) => {
                    const id = e.target.closest('.omni-notif-item').dataset.id;
                    await global.OmniApiClient.Notifications.markRead(id);
                    await this.refresh();
                });
            });
        }

        escapeHtml(str) {
            const div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML;
        }
    }

    global.OmniNotificationsWidget = {
        mount(containerId) {
            const widget = new NotificationsWidget(containerId);
            widget.init();
            return widget;
        },
    };
})(typeof window !== 'undefined' ? window : globalThis);
