# Manual de Desarrollador · [1007] Notificaciones y Alertas Centralizadas
## OMNI API CORE v6.9 · JOSEPAN 360

> Consolida y reemplaza `CHECKLIST_FASE1.md`, `INTEGRATION_FASE2.md` e
> `INTEGRATION_FASE3.md` (esos archivos quedan como bitácora histórica de
> cada fase, pero este documento es la referencia técnica vigente).

---

## 1. Arquitectura general

```
┌─────────────────┐     ┌──────────────────────┐     ┌─────────────────────┐
│  Motor de        │────▶│  Tabla notifications │◀────│  Motor programado    │
│  eventos         │     │  (central, con       │     │  (cron cada minuto)  │
│  (hooks en       │     │   idempotency_key)   │     │  ScheduledRuleEngine │
│  Kardex/Pareto)  │     └──────────┬───────────┘     └─────────────────────┘
└─────────────────┘                │
                                    ▼
                     ┌──────────────────────────┐
                     │  API CORE (Controllers)  │
                     │  - listar / marcar leída  │
                     │  - monitor / CRUD reglas  │
                     └──────────┬───────────────┘
                                 │
              ┌──────────────────┴───────────────────┐
              ▼                                       ▼
  ┌───────────────────────┐            ┌──────────────────────────┐
  │ Widget (subsistemas)   │            │ Panel Admin               │
  │ notifications-widget.js│            │ monitoreo + CRUD reglas   │
  └───────────────────────┘            └──────────────────────────┘
```

Dos motores de disparo independientes, una sola tabla central:
- **Por evento**: `NotificationTriggerService` se invoca desde el código que
  ya existe (Kardex, cron de Pareto de vencimientos) justo después de que
  la transacción de negocio ya se confirmó. Fire-and-forget.
- **Por hora programada**: `ScheduledRuleEngine`, invocado por
  `cron_scheduled_notifications.php` cada minuto, evalúa reglas
  configurables desde el Panel Admin.

## 2. Mapa de archivos

| Archivo | Responsabilidad |
|---|---|
| `database/migration_1007_notificaciones.sql` | Esquema completo (8 tablas + ALTER a `roles` + seeds) |
| `database/migration_1007_fix_transfer_date_column.sql` | Fix: `NO_RECORD_BY_TIME` usa `at_solicitado`, no `created_at` |
| `api/NotificationController.php` | CRUD de notificaciones del usuario + `monitor()` |
| `api/NotificationRulesController.php` | CRUD de `scheduled_notification_rules` (admin) |
| `cron/NotificationTriggerService.php` | Motor de eventos (`STOCK_OUT`, `STOCK_MIN`, `EXPIRING_SOON`) |
| `cron/ScheduledRuleEngine.php` | Motor programado (`TRANSFER_NOT_REGISTERED` y futuros) |
| `cron/cron_scheduled_notifications.php` | Entry point invocado por crontab cada minuto |
| `assets/js/api-client.js` | Cliente HTTP (proxy `/api/omni.php?action=`) |
| `assets/js/notifications-widget.js` | Campana + panel, para subsistemas |
| `assets/js/notifications-monitor.js` / `notification-rules-admin.js` | Lógica del Panel Admin |
| `admin/notifications-monitor.html` / `notification-rules.html` | Vistas del Panel Admin |
| `tests/` | Suite de QA (carga + concurrencia real) |

## 3. Modelo de datos — decisiones de diseño que hay que respetar

### 3.1 Separación estricta identificador / valor

**Regla no negociable**: los nombres de tabla/columna que consulta el motor
programado viven ÚNICAMENTE en `condition_rule_types` (poblada solo por
migración de desarrollo). `scheduled_notification_rules` — la tabla que
edita el admin desde el panel — **nunca** contiene identificadores de base
de datos, solo valores (hora, alcance, umbral).

Por qué: los nombres de tabla/columna no se pueden parametrizar con
`PDO::prepare()` (solo los valores se bindean). Si un formulario de admin
pudiera elegir tabla/columna libremente, cualquier consulta se armaría con
identificadores no confiables — rompe la garantía de índices y el estándar
de "prepared statements siempre" del proyecto. Ver la discusión completa en
`docs/prompt_1007_notificaciones_alertas.md`, sección 7.

**Si necesitas agregar un patrón de condición nuevo** (ej. "cierre de caja
no registrado"): agrega una fila en `condition_rule_types` vía una
migración nueva, nunca lo aceptes como input del admin.

### 3.2 Idempotencia

Toda notificación generada por un motor automático (evento o programado)
lleva un `idempotency_key` (hash SHA-256) y la tabla tiene un
`UNIQUE KEY` sobre esa columna. El patrón de inserción es siempre
`INSERT IGNORE`, nunca `INSERT` simple — así, si el mismo evento se
dispara dos veces (o dos procesos compiten por la misma fila), solo una
notificación sobrevive. Esto se validó con concurrencia real de sistema
operativo, no solo repeticiones secuenciales (ver `tests/`).

### 3.3 Aislamiento por interlocutor y entidad legal

- `notifications.interlocutor_id`: sede/bodega dueña de la alerta.
- `notifications.legal_entity_id`: referencia a `interlocutors` con
  `type='empresa'` — **no existe una tabla `legal_entities`** separada; las
  7 entidades legales de JOSEPAN son la raíz de la jerarquía `owner_id`.
  `NotificationController::resolveLegalEntity()` la resuelve con un CTE
  recursivo caminando `owner_id` hacia arriba.

### 3.4 Rol vs. nivel jerárquico

`hierarchy_levels` es un catálogo **independiente** de `roles` — sirve
para dirigir broadcasts a "mandos medios" sin acoplarse a qué rol puntual
tiene cada usuario. `roles.hierarchy_level_id` (agregado por migración) es
nullable y su mapeo real (qué rol de los 15 deterministas es Operativo/
Mando Medio/Dirección) es una decisión de negocio pendiente de confirmar
— no bloquea el funcionamiento del resto del sistema.

## 4. Cómo extender

### 4.1 Agregar un `notification_type` nuevo

```sql
INSERT INTO notification_types (code, name, severity, trigger_mode, description)
VALUES ('MI_CODIGO_NUEVO', 'Nombre', 'warning', 'event', 'Descripción');
```

Si es disparado por evento, llamar a `NotificationTriggerService::emit()`
(privado — hay que agregar un método público nuevo que lo invoque con el
código correcto, siguiendo el patrón de `checkStockThresholds`).

### 4.2 Agregar un `condition_rule_type` nuevo (patrón técnico)

1. Confirmar la tabla/columna reales con `DESCRIBE` — nunca asumir nombres.
2. Migración nueva:
   ```sql
   INSERT INTO condition_rule_types (code, name, description, target_table, target_date_column, target_scope_column, requires_threshold)
   VALUES ('MI_PATRON', 'Nombre', 'Descripción', 'tabla_real', 'columna_fecha', 'columna_scope', 0);
   ```
3. Si el patrón necesita lógica distinta a "ausencia de registro" (ej.
   `THRESHOLD_NOT_MET`), agregar el `case` correspondiente en
   `ScheduledRuleEngine::executeRule()` y el método privado que lo ejecute.
4. Agregar la tabla a `ScheduledRuleEngine::ALLOWED_TABLES` (whitelist de
   defensa en profundidad).

### 4.3 Agregar un canal de despacho (Telegram/WhatsApp/email)

`notification_dispatch_log` ya está modelada para esto (MVP solo `in_app`).
Falta: un servicio de despacho que, tras insertar en `notifications`,
inserte una fila en `notification_dispatch_log` por canal y reuse los
adaptadores stub de Telegram/WhatsApp que ya dejó [1006]. No implementado
en las fases actuales — es una extensión futura, no un pendiente bloqueante.

## 5. Convenciones seguidas

- **Respuesta HTTP**: `{status: 'success'|'error', data: {...}, message: '...'}`
  en éxito; `{status: 'error', error_code: 'ERR_X', message: '...'}` en error.
- **Códigos de error reutilizados** del resto de OMNI: `ERR_RBAC`,
  `ERR_VALIDATION`, `ERR_NOT_FOUND`, `ERR_INTERNAL` — no se inventaron
  códigos nuevos para este módulo.
- **RBAC**: dos permisos atómicos, `notifications.read` (bandeja propia) y
  `notifications.admin` (monitoreo + CRUD de reglas), siguiendo el patrón
  `resource.action` del resto del ecosistema.
- **PDO**: siempre prepared statements con valores bindeados; los únicos
  identificadores interpolados son los ya validados contra
  `condition_rule_types` + whitelist (ver 3.1).

## 6. Puntos de integración pendientes (wiring, no diseño)

Estos NO son parte de este módulo — son el cableado que debe hacer quien
mantiene el router/bootstrap central de OMNI API CORE:

1. **Registrar las rutas** de `NotificationController` y
   `NotificationRulesController` en el router central (ver tabla de rutas
   en `docs/API_REFERENCE.md`).
2. **Confirmar la clase de conexión a BD real** — este módulo asume
   `Database::getConnection()` (nombre usado en sesiones previas del
   proyecto, no confirmado literalmente en el manual de subsistemas).
3. **Enganchar `NotificationTriggerService`** en el código real de
   `InventoryController` (tras el INSERT en `inventory_kardex`) y en el
   cron de Pareto de vencimientos ya existente — ver ejemplos exactos en
   la sección 4 de `docs/DEPLOYMENT.md`.
4. **Asignar el permiso `notifications.admin`** a los roles que
   correspondan en `role_permissions` — decisión de negocio, no técnica.

## 7. Testing

Ver `tests/README.md`. Resumen: toda la lógica se probó contra una base de
datos MySQL/MariaDB real (no mocks) — funcional (creación, listado, RBAC,
CRUD), de carga (14 sedes), y de concurrencia real (procesos de sistema
operativo genuinamente paralelos, no solo repeticiones secuenciales).
