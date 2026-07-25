# Manual de Pruebas · [1007] Notificaciones y Alertas Centralizadas
## OMNI API CORE v6.9 · JOSEPAN 360

Este manual cubre dos niveles de prueba:
1. **API** — con la collection de Postman incluida en `postman/`.
2. **Interfaz** — checklist manual de verificación visual (widget + panel admin).

Además, el proyecto incluye una tercera capa que no requiere configuración
manual: la suite de QA en `tests/` (carga con 14 sedes y concurrencia real
de sistema operativo) — ver `tests/README.md`. Este manual no la repite,
solo la referencia en la sección 5.

---

## 1. Requisitos previos

- **Postman** (app de escritorio o web) — <https://www.postman.com/downloads/>,
  o **Newman** (CLI de Postman) si prefieres correrlo por línea de comandos:
  ```bash
  npm install -g newman
  ```
- Las migraciones de [1007] ya aplicadas en el entorno a probar (ver
  `docs/DEPLOYMENT.md`).
- **Dos usuarios de prueba**:
  - Uno con permiso `notifications.admin` (para probar todo).
  - Uno **sin** ese permiso, pero con `notifications.read` (para los casos
    negativos de RBAC — ej. un Encargado de Tienda).
- Al menos **una notificación de prueba** en la base de datos (puedes
  generarla con el motor de eventos, con una regla programada, o
  simplemente insertando una fila manualmente).

---

## 2. Configurar la collection de Postman

### 2.1 Importar

1. Abre Postman → **Import**.
2. Arrastra los dos archivos de `postman/`:
   - `1007_notificaciones.postman_collection.json`
   - `1007_notificaciones.postman_environment.json`
3. Selecciona el entorno **"OMNI 1007 - Notificaciones (entorno)"** en el
   selector de entornos (esquina superior derecha de Postman).

### 2.2 Completar las variables de entorno

Abre el entorno importado (ícono de ojo 👁 o el editor de entornos) y
completa:

| Variable | Qué poner |
|---|---|
| `base_url` | URL base del API CORE, ej. `https://api.omni.josepan.app/api/v1` |
| `username` / `password` | Credenciales del usuario **con** `notifications.admin` |
| `username_no_admin` / `password_no_admin` | Credenciales del usuario **sin** ese permiso |
| `interlocutor_id` | ID de una sede real donde el usuario admin tenga acceso |

No necesitas tocar `token`, `token_no_admin`, `notification_id`, `rule_id`,
`notification_type_id`, `rule_type_id`, `hierarchy_level_id` — se rellenan
solos al correr las peticiones (los scripts de test los guardan
automáticamente).

---

## 3. Cómo ejecutar las pruebas

### 3.1 Orden de ejecución (importante)

La collection está numerada en carpetas — **correr en este orden**, no
salteando:

```
0. Autenticacion            ← siempre primero
1. Notificaciones (usuario)
2. Monitoreo (admin)
3. Reglas Programadas (admin)
4. Casos Negativos - RBAC y Validacion
```

Dentro de cada carpeta, correr también en orden (de arriba hacia abajo) —
varias peticiones dependen de variables que dejó la anterior.

### 3.2 Ejecución manual (una por una)

Click en cada request → **Send**. Revisa la pestaña **Test Results** — cada
request tiene entre 1 y 5 verificaciones automáticas (✅/❌).

### 3.3 Ejecución automática con Collection Runner

1. Click derecho sobre la collection → **Run collection**.
2. Selecciona el entorno correcto.
3. **Run OMNI [1007]...** — corre las 20 peticiones en orden y muestra un
   resumen de aserciones pasadas/fallidas.

### 3.4 Ejecución por línea de comandos (Newman, para CI)

```bash
newman run postman/1007_notificaciones.postman_collection.json \
  -e postman/1007_notificaciones.postman_environment.json
```

Termina con código de salida `0` si todo pasó, `1` si algo falló — apto
para integrarlo a un pipeline.

---

## 4. Qué envía y qué debe recibir cada prueba

### 4.1 Carpeta "0. Autenticación"

| Request | Envía | Debe recibir |
|---|---|---|
| Login Paso 1 | `{username, password, interlocutor_id: 0}` | `data.available_interlocutors` (lista de sedes) |
| Login Paso 2 (admin) | `{username, password, interlocutor_id}` | `data.token` — se guarda solo en `{{token}}` |
| Login (sin admin) | `{username_no_admin, password_no_admin, interlocutor_id}` | `data.token` — se guarda solo en `{{token_no_admin}}` |
| Refrescar token | — (header `Authorization`) | Nuevo `data.token` (usar si el token expira a mitad de sesión) |
| GET /auth/me | — | Datos del usuario autenticado (para confirmar que el token es el correcto) |

### 4.2 Carpeta "1. Notificaciones (usuario)"

| Request | Envía | Debe recibir |
|---|---|---|
| GET /notifications | Query: `unread_only`, `limit`, `offset` | `data.total_unread` (número) + `data.notifications[]`. El primer `id` se guarda en `{{notification_id}}` |
| PATCH /notifications/{id}/read | — | `data.is_read = true` |
| PATCH /notifications/read-all | — | `data.updated` (número de filas afectadas) |
| POST /notifications | `{title, message, is_global, interlocutor_id}` | `data.id` de la notificación creada (BROADCAST_INFO) |

### 4.3 Carpeta "2. Monitoreo (admin)"

| Request | Envía | Debe recibir |
|---|---|---|
| GET /notifications/monitor | Query opcionales: `severity`, `date_from`, `date_to`, `interlocutor_id`, `limit` | `data.summary` (total/critical/warning/info/by_interlocutor) + `data.notifications[]` con `recipients[]` por cada una |

**Prueba manual adicional recomendada**: correr este request varias veces
cambiando `severity` a `critical`, luego `warning`, luego `info` — confirmar
que `data.notifications` solo trae de esa severidad.

### 4.4 Carpeta "3. Reglas Programadas (admin)"

| Request | Envía | Debe recibir |
|---|---|---|
| GET /rules/form-options | — | `notification_types[]`, `hierarchy_levels[]`, `rule_types[]` — **ninguno debe traer `target_table`** (verificación de seguridad automática incluida) |
| GET /rules/types | — | `rule_types[]` |
| GET /rules | — | `rules[]` |
| POST /rules | `{name, notification_type_id, rule_type_id, check_time, scope}` | `data.id` de la regla creada |
| PUT /rules/{id} | Mismo body + `active` | `data.id` |
| DELETE /rules/{id} | — | `data.id` — **verificar en BD** que `deleted_at` quedó con fecha, la fila sigue existiendo |

### 4.5 Carpeta "4. Casos Negativos" (deben fallar — si no fallan, hay una regresión)

| Request | Envía | Debe recibir |
|---|---|---|
| GET /monitor sin admin | Token sin `notifications.admin` | HTTP 403, `error_code: ERR_RBAC` |
| POST /rules sin admin | Token sin `notifications.admin` | HTTP 403, `error_code: ERR_RBAC` |
| POST /rules scope=specific_interlocutor sin interlocutor_id | Token admin, body incompleto | HTTP 400, `error_code: ERR_VALIDATION` |
| PATCH /notifications/999999999/read | ID inexistente | HTTP 404, `error_code: ERR_NOT_FOUND` |

---

## 5. Validación de la collection (ya ejecutada)

Antes de entregarte esta collection, la corrí de extremo a extremo contra
un servidor mock que reproduce el contrato exacto de los controllers reales
(sin red externa disponible en mi entorno de trabajo para llegar al
servidor de producción). Resultado con Newman:

```
requests:       20 ejecutadas, 0 fallidas
test-scripts:   18 ejecutados, 0 fallidos
assertions:     39 ejecutadas, 0 fallidas
```

Esto confirma que la **estructura** de la collection (URLs, headers, bodies,
scripts de test, encadenamiento de variables) es correcta. Lo que **no**
puedo validar desde mi entorno es la respuesta del servidor real — eso lo
confirmas tú al correrla contra `api.omni.josepan.app`.

### 5.1 Si quieres reproducir esta validación tú mismo (sin tocar producción)

Incluí el mock que usé (`postman/mock_server.php`) — un servidor PHP de una
sola línea de comando que reproduce el contrato de respuesta exacto, sin
lógica de negocio real ni base de datos. Útil para validar la collection
en tu máquina antes de apuntarla al servidor real:

```bash
php -S 127.0.0.1:8090 postman/mock_server.php
# en otra terminal:
newman run postman/1007_notificaciones.postman_collection.json \
  -e postman/1007_notificaciones.postman_environment.json \
  --env-var base_url=http://127.0.0.1:8090/api/v1
```

---

## 6. Verificación vía interfaz (checklist manual)

### 6.1 Widget de campana (cualquier subsistema: 1002-1005)

- [ ] El ícono 🔔 aparece en el header, con área táctil de tamaño cómodo
- [ ] Si hay no leídas, aparece el badge numérico en rojo
- [ ] Al tocar la campana, se abre el panel con las notificaciones más
      recientes primero
- [ ] Cada tarjeta tiene el punto de color correcto según severidad
      (rojo=crítico, naranja=advertencia, gris=info)
- [ ] Tocar el ✓ de una notificación la marca como leída y el badge baja
- [ ] "Marcar todas leídas" vacía el badge
- [ ] Esperar 60 segundos sin tocar nada — el panel debe refrescarse solo
      (polling) si llega una notificación nueva
- [ ] Iniciar sesión con un usuario de **otra sede** — no debe ver las
      notificaciones de stock de la primera sede

### 6.2 Panel de Monitoreo (`admin/notifications-monitor.html`)

- [ ] Con usuario **sin** `notifications.admin`: la página debe mostrar el
      mensaje de error en vez de datos
- [ ] Con usuario **con** el permiso: deben cargar las 4 tarjetas KPI
      (Total/Críticas/Advertencias/Informativas)
- [ ] Cambiar el filtro de severidad y confirmar que la tabla se actualiza
- [ ] Verificar que la columna "Destinatarios" muestra `X/Y leídas` con
      números coherentes

### 6.3 Administración de Reglas (`admin/notification-rules.html`)

- [ ] Los 3 dropdowns (tipo de notificación, patrón de condición, nivel
      jerárquico) cargan opciones al abrir la página
- [ ] Cambiar "Alcance" a *Una sede específica* muestra el campo de ID de
      sede; cambiar a *Nivel jerárquico* muestra el selector de nivel
- [ ] Crear una regla de prueba → aparece en la tabla inmediatamente
- [ ] Eliminarla (🗑) → desaparece de la tabla, pero en BD sigue existiendo
      con `deleted_at` poblado (verificar con `SELECT * FROM
      scheduled_notification_rules WHERE id = <id>`)

### 6.4 Verificación end-to-end del motor programado (requiere esperar o ajustar la hora)

1. Crear una regla con `check_time` = el minuto siguiente al actual.
2. Esperar a que el cron corra (o ejecutar manualmente
   `php cron/cron_scheduled_notifications.php` si tienes acceso al servidor).
3. Confirmar que aparece una notificación nueva en la campana de la sede
   afectada (o el resumen consolidado, si el alcance era por nivel
   jerárquico).

---

## 7. Otros mecanismos de prueba (ya incluidos en el proyecto)

- **`tests/test_load_14_sedes.php`** — prueba de carga con 14 sedes
  simuladas, verifica el conjunto exacto de sedes que deberían fallar.
- **`tests/test_concurrency_idempotency.php`** — lanza procesos reales de
  sistema operativo en paralelo para confirmar que no se duplican
  notificaciones bajo concurrencia real (no solo repeticiones secuenciales).
- **`tests/test_concurrency_stock.php`** — mismo enfoque para el motor de
  eventos de stock.

Ver `tests/README.md` para cómo correrlas — requieren acceso directo a PHP
y a la base de datos (no son pruebas HTTP como la collection de Postman).
