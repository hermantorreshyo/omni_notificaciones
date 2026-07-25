# Referencia de API · [1007] Notificaciones y Alertas Centralizadas
## OMNI API CORE v6.9 · JOSEPAN 360

Prefijo base: `/api/v1/notifications` (vía proxy `/api/omni.php?action=notifications...`
desde el frontend — ver `manual-desarrollador-subsistemas.md` sección 6).

Todas las rutas requieren `Authorization: Bearer {token}` y
`X-Interlocutor-Id: {sede activa}` (inyectados automáticamente por el
proxy), salvo que se indique lo contrario.

Formato de respuesta estándar:
```json
{ "status": "success", "data": { ... }, "message": "" }
```
Error:
```json
{ "status": "error", "error_code": "ERR_X", "message": "..." }
```

Códigos de error usados por este módulo (reutilizados del catálogo OMNI,
no se inventaron códigos nuevos): `ERR_VALIDATION`, `ERR_RBAC`,
`ERR_NOT_FOUND`, `ERR_INTERNAL`.

---

## Notificaciones del usuario

### `GET /notifications`
**Controller:** `NotificationController::index()`
**Permiso:** `notifications.read`

Query params:
| Param | Tipo | Descripción |
|---|---|---|
| `unread_only` | bool | Si `true`, solo no leídas |
| `limit` | int | Default 20, máx 100 |
| `offset` | int | Default 0 |

Respuesta:
```json
{
  "status": "success",
  "data": {
    "total_unread": 3,
    "notifications": [
      {
        "id": 1042, "type": "STOCK_MIN", "severity": "warning",
        "title": "Stock Mínimo Alcanzado", "message": "...",
        "reference_id": 405, "metadata": null,
        "is_read": false, "created_at": "2026-07-24 10:15:30"
      }
    ]
  }
}
```

### `PATCH /notifications/{id}/read`
**Controller:** `NotificationController::markRead($id)`
**Permiso:** `notifications.read`
Sin body. Respuesta: `{ "id": 1042, "is_read": true }`

### `PATCH /notifications/read-all`
**Controller:** `NotificationController::markAllRead()`
**Permiso:** `notifications.read`
Sin body. Respuesta: `{ "updated": 5 }`

### `POST /notifications`
**Controller:** `NotificationController::store($body)`
**Permiso:** `notifications.admin`
Solo crea notificaciones tipo `BROADCAST_INFO` manuales — las de
evento/programadas las generan los workers, no este endpoint.

Body:
```json
{
  "title": "string (requerido)",
  "message": "string (requerido)",
  "is_global": false,
  "interlocutor_id": null,
  "target_role_id": null,
  "target_hierarchy_level": null,
  "target_user_id": null,
  "legal_entity_id": null,
  "reference_id": null,
  "metadata": {}
}
```
Si `legal_entity_id` viene `null` y el usuario no tiene `system.admin`, se
resuelve automáticamente a la entidad legal de su interlocutor activo (vía
CTE recursivo) — nunca queda `null` salvo para roles con permiso
corporativo transversal.

Respuesta: `{ "id": 1055 }`

---

## Panel de monitoreo (admin)

### `GET /notifications/monitor`
**Controller:** `NotificationController::monitor($query)`
**Permiso:** `notifications.admin`

Query params opcionales: `severity` (`critical`|`warning`|`info`),
`date_from`, `date_to` (formato `YYYY-MM-DD` o datetime), `interlocutor_id`,
`limit` (default 50, máx 100).

Respuesta:
```json
{
  "status": "success",
  "data": {
    "summary": {
      "total": 128, "critical": 14, "warning": 52, "info": 62,
      "by_interlocutor": [
        { "interlocutor_id": 12, "name": "Tienda Chapinero", "critical": 3 }
      ]
    },
    "notifications": [
      {
        "id": 1042, "type": "STOCK_MIN", "severity": "warning",
        "title": "...", "created_at": "...", "interlocutor_id": 12,
        "recipients": [
          { "user_id": 88, "name": "Juan Pérez", "is_read": true, "read_at": "..." }
        ]
      }
    ]
  }
}
```

---

## CRUD de reglas programadas (admin)

Todas requieren `notifications.admin`. El body **nunca** acepta ni recibe
`target_table`/`target_date_column`/`target_scope_column` — esos
identificadores son dev-owned (ver `MANUAL_DESARROLLADOR.md` sección 3.1).

### `GET /notifications/rules/form-options`
**Controller:** `NotificationRulesController::formOptions()`
Agrupa los 3 catálogos que necesita el formulario en una sola llamada:
```json
{
  "notification_types": [{ "id": 5, "code": "TRANSFER_NOT_REGISTERED", "name": "..." }],
  "hierarchy_levels":    [{ "id": 2, "code": "MANDO_MEDIO", "name": "Mando Medio" }],
  "rule_types":          [{ "id": 1, "code": "NO_RECORD_BY_TIME", "name": "...", "description": "...", "requires_threshold": false }]
}
```

### `GET /notifications/rules/types`
**Controller:** `NotificationRulesController::ruleTypes()`
Solo el catálogo de patrones (subconjunto de `form-options`).

### `GET /notifications/rules`
**Controller:** `NotificationRulesController::index()`
Lista todas las reglas activas (`deleted_at IS NULL`), con nombre de sede/
nivel jerárquico resuelto y conteo de notificaciones generadas en 30 días.

### `POST /notifications/rules`
**Controller:** `NotificationRulesController::store($body)`

Body:
```json
{
  "name": "string (requerido)",
  "notification_type_id": 5,
  "rule_type_id": 1,
  "check_time": "09:00:00",
  "scope": "all_pos | specific_interlocutor | hierarchy_level",
  "interlocutor_id": null,
  "hierarchy_level_id": null,
  "threshold_operator": null,
  "threshold_value": null
}
```
Validaciones: `interlocutor_id` obligatorio si `scope=specific_interlocutor`;
`hierarchy_level_id` obligatorio si `scope=hierarchy_level`;
`threshold_operator`/`threshold_value` obligatorios si el `rule_type`
elegido tiene `requires_threshold=true`.

### `PUT /notifications/rules/{id}`
**Controller:** `NotificationRulesController::update($id, $body)`
Mismo body que `POST`, más `active` (bool).

### `DELETE /notifications/rules/{id}`
**Controller:** `NotificationRulesController::destroy($id)`
Borrado lógico (`deleted_at = NOW()`, `active = 0`) — nunca elimina la fila.

---

## Tabla resumen de rutas

| Método | Ruta | Controller::método | Permiso |
|---|---|---|---|
| GET | `/notifications` | `NotificationController::index` | `notifications.read` |
| PATCH | `/notifications/{id}/read` | `NotificationController::markRead` | `notifications.read` |
| PATCH | `/notifications/read-all` | `NotificationController::markAllRead` | `notifications.read` |
| POST | `/notifications` | `NotificationController::store` | `notifications.admin` |
| GET | `/notifications/monitor` | `NotificationController::monitor` | `notifications.admin` |
| GET | `/notifications/rules/form-options` | `NotificationRulesController::formOptions` | `notifications.admin` |
| GET | `/notifications/rules/types` | `NotificationRulesController::ruleTypes` | `notifications.admin` |
| GET | `/notifications/rules` | `NotificationRulesController::index` | `notifications.admin` |
| POST | `/notifications/rules` | `NotificationRulesController::store` | `notifications.admin` |
| PUT | `/notifications/rules/{id}` | `NotificationRulesController::update` | `notifications.admin` |
| DELETE | `/notifications/rules/{id}` | `NotificationRulesController::destroy` | `notifications.admin` |

> Estas rutas necesitan registrarse en el router central de OMNI API CORE
> — ver `MANUAL_DESARROLLADOR.md` sección 6, punto 1.
