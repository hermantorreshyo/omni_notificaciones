# 📑 DOCUMENTACIÓN MAESTRA DE PROMPTS UNIFICADOS (JOSEPAN 360 · OMNI)

## 🗂️ PROMPT [1007]: SUBSISTEMA DE NOTIFICACIONES Y ALERTAS CENTRALIZADAS (OMNI API CORE & FRONT-END)

> **Nota de revisión v2:** este documento corrige la versión original de [1007] para cubrir tres puntos de la premisa de negocio que no estaban resueltos: (a) notificaciones **programadas por hora** además de por evento, (b) un **panel de monitoreo centralizado** para ver consolidados, críticos y detalle de destinatarios, y (c) la distinción entre **rol** y **rango/nivel jerárquico** para mensajes dirigidos a "mandos medios". Además, esta versión **cierra las 5 decisiones de arquitectura pendientes** identificadas en la revisión de completitud: auditoría de origen, aislamiento por entidad legal, idempotencia del motor programado, extensibilidad multicanal, y política de retención. Los cambios están marcados con 🆕 y las decisiones quedan documentadas en la sección 7.

---

## 📌 CONTEXTO DE INGENIERÍA Y ROL

Actúa como un Arquitecto de Software Senior y Desarrollador Full-Stack especializado en sistemas industriales de alta disponibilidad. Tu objetivo es diseñar e implementar el Subsistema de Notificaciones y Alertas [1007] para el ecosistema JOSEPAN 360 · OMNI. Este subsistema operará de manera transversal sobre la base de datos centralizada del API CORE (LAMP Stack: PHP 8.x con tipado estricto, MySQL 8.x, sin frameworks externos ni Composer) y proveerá tanto las estructuras relacionales y lógicas de backend como los componentes de interfaz reutilizables (Mobile-First / Industrial UI) para todos los subsistemas satélite ([1002], [1003], [1004], [1005]).

---

## 📐 1. ARQUITECTURA Y REGLAS TRANSVERSALES DE INTEGRIDAD

1. **Aislamiento Perimetral por Interlocutor (`X-Interlocutor-Id` / JWT):** Las notificaciones críticas de inventario (stock mínimo, agotado, próximo a vencer) están vinculadas al ámbito físico del operario o sede. Un usuario en una sede específica solo consultará las alertas de su propio `interlocutor_id`, mientras que los roles directivos/corporativos con `interlocutor_id` nulo o permisos globales podrán visualizar alertas transversales de toda la jerarquía.
2. **Regla Metrológica de Acero:** Toda cantidad o umbral evaluado para disparar una alerta se procesa en sus unidades base inmutables (gramos, mililitros o unidades exactas).
3. **Resiliencia y Rendimiento en Base de Datos:** Las consultas de notificaciones pendientes no deben bloquear las transacciones del Kardex. Se implementarán índices optimizados en MySQL sobre las claves foráneas y estados de lectura.
4. **Filosofía Fat-Finger y Diseño UI Industrial:** Los elementos visuales de notificación en el Front-end (bandeja flotante, botones de marcado como leído, tarjetas de alerta con código de colores) cumplirán con el estándar de área interactiva mínima de **46px × 46px**, optimizados para pantallas táctiles y entornos húmedos o de uso con guantes.
5. 🆕 **Doble motor de disparo:** el subsistema debe soportar dos mecanismos de generación de notificaciones, no solo uno: (a) **por evento** (una transacción cruza un umbral) y (b) **por hora programada** (un cron evalúa a una hora exacta si una condición sigue sin cumplirse, ej. "sede sin traspaso registrado antes de las 09:00"). Ambos mecanismos escriben sobre la misma tabla `notifications`.
6. 🆕 **Rol vs. Rango jerárquico:** un `role_id` (ej. "Encargado de Tienda") no es necesariamente el mismo concepto que un "rango medio" de la organización, que puede agrupar varios roles distintos. Antes de implementar, **verificar con negocio** si la tabla `roles` ya tiene un nivel jerárquico (`hierarchy_level` o similar) o si hay que introducirlo. Este prompt asume que hay que añadirlo (ver sección 2).

---

## 🗄️ 2. DISEÑO DE BASE DE DATOS (ESQUEMA DDL)

```sql
-- 🆕 Catálogo de niveles jerárquicos (independiente de roles puntuales)
-- Decisión de arquitectura: se crea como catálogo nuevo en vez de asumir que "roles"
-- ya modela jerarquía. Un rol puntual (ej. "Encargado de Tienda") se mapea a un nivel
-- mediante roles.hierarchy_level_id (columna nueva, migración aparte fuera de este módulo).
CREATE TABLE hierarchy_levels (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(30) NOT NULL UNIQUE, -- OPERATIVO, MANDO_MEDIO, DIRECCION
    name VARCHAR(60) NOT NULL,
    rank_order TINYINT NOT NULL, -- 1=Operativo, 2=Mando Medio, 3=Dirección (permite comparar >=, <=)
    created_at TIMESTAMP(3) DEFAULT CURRENT_TIMESTAMP(3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla principal de catálogos de tipos de notificación
CREATE TABLE notification_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) NOT NULL UNIQUE, -- Ej: STOCK_OUT, STOCK_MIN, EXPIRING_SOON, BROADCAST_INFO, TRANSFER_NOT_REGISTERED
    name VARCHAR(100) NOT NULL,
    severity ENUM('info', 'warning', 'critical') DEFAULT 'info',
    trigger_mode ENUM('event', 'scheduled') NOT NULL DEFAULT 'event', -- 🆕 distingue el motor de disparo
    description TEXT NULL,
    created_at TIMESTAMP(3) DEFAULT CURRENT_TIMESTAMP(3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 🆕 Capa 1: catálogo TÉCNICO de patrones (fijo, poblado SOLO por migración de desarrollo,
-- nunca expuesto en el CRUD del admin). Aquí viven los identificadores de tabla/columna,
-- hardcoded y conocidos de antemano, para que cada consulta use índices reales y prepared
-- statements de valores — nunca SQL armado con identificadores dinámicos.
CREATE TABLE condition_rule_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(40) NOT NULL UNIQUE, -- NO_RECORD_BY_TIME, THRESHOLD_NOT_MET, FIELD_STATUS_PENDING
    name VARCHAR(100) NOT NULL,
    description TEXT NULL,
    target_table VARCHAR(64) NOT NULL, -- fijo, definido por desarrollo al crear el patrón (ej. 'daily_transfers')
    target_date_column VARCHAR(64) NOT NULL, -- fijo, ej. 'transfer_date'
    target_scope_column VARCHAR(64) NULL, -- fijo, ej. 'interlocutor_id'
    requires_threshold BOOLEAN DEFAULT FALSE, -- si exige threshold_operator/threshold_value en la regla
    created_at TIMESTAMP(3) DEFAULT CURRENT_TIMESTAMP(3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 🆕 Capa 2: catálogo de NEGOCIO, 100% administrable desde el panel (sin deploy de código).
-- El admin NUNCA elige tabla/columna aquí — solo referencia un rule_type ya construido y
-- configura los VALORES de su instancia: hora, alcance y umbral. Esto es lo que se crea
-- libremente al paso que surgen necesidades, sin tocar código ni arriesgar rendimiento.
CREATE TABLE scheduled_notification_rules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL, -- etiqueta legible para el admin, ej. "Traspaso diario no registrado"
    notification_type_id INT NOT NULL,
    rule_type_id INT NOT NULL,
    check_time TIME NOT NULL, -- hora exacta de evaluación, ej. '09:00:00'
    scope ENUM('all_pos', 'specific_interlocutor', 'hierarchy_level') NOT NULL DEFAULT 'all_pos',
    interlocutor_id BIGINT NULL, -- si scope = specific_interlocutor
    target_hierarchy_level INT NULL, -- si scope = hierarchy_level (ej. avisar a todos los Mandos Medios)
    threshold_operator ENUM('<', '<=', '>', '>=', '=') NULL, -- solo si rule_type.requires_threshold; es un VALOR, no un identificador — seguro de parametrizar
    threshold_value DECIMAL(14,4) NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_by BIGINT NOT NULL, -- 🆕 quién configuró la regla (siempre un admin, nunca el sistema)
    created_at TIMESTAMP(3) DEFAULT CURRENT_TIMESTAMP(3),
    updated_at TIMESTAMP(3) DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    FOREIGN KEY (notification_type_id) REFERENCES notification_types(id) ON DELETE RESTRICT,
    FOREIGN KEY (rule_type_id) REFERENCES condition_rule_types(id) ON DELETE RESTRICT,
    FOREIGN KEY (interlocutor_id) REFERENCES interlocutors(id) ON DELETE CASCADE,
    FOREIGN KEY (target_hierarchy_level) REFERENCES hierarchy_levels(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_scheduled_time (check_time, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla central de notificaciones generadas
CREATE TABLE notifications (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    notification_type_id INT NOT NULL,
    legal_entity_id BIGINT NULL, -- 🆕 NULL = corporativo transversal a las 7 entidades (solo super-admin); si no, acota el broadcast a una entidad legal
    interlocutor_id BIGINT NULL, -- NULL si aplica a toda la corporación (Broadcast global)
    target_role_id INT NULL, -- NULL si va dirigida a un usuario específico, o ID de rol para difusión masiva por rol
    target_hierarchy_level INT NULL, -- 🆕 NULL si no aplica; FK a hierarchy_levels.id
    target_user_id BIGINT NULL, -- NULL si va dirigida al rol/sede, o ID específico de empleado/usuario
    created_by BIGINT NULL, -- 🆕 NULL = generada por evento/cron del sistema; obligatorio (validado en API) cuando es BROADCAST_INFO manual
    title VARCHAR(150) NOT NULL,
    message TEXT NOT NULL,
    reference_id BIGINT NULL, -- ID opcional asociado (ej: product_id, batch_id, purchase_order_id)
    metadata JSON NULL, -- Datos contextuales adicionales en formato JSON estructurado
    is_global BOOLEAN DEFAULT FALSE,
    idempotency_key VARCHAR(120) NULL, -- 🆕 hash determinístico (type+scope+fecha) para triggers de evento/cron; NULL en mensajes manuales
    created_at TIMESTAMP(3) DEFAULT CURRENT_TIMESTAMP(3),
    FOREIGN KEY (notification_type_id) REFERENCES notification_types(id) ON DELETE RESTRICT,
    FOREIGN KEY (legal_entity_id) REFERENCES legal_entities(id) ON DELETE CASCADE,
    FOREIGN KEY (interlocutor_id) REFERENCES interlocutors(id) ON DELETE CASCADE,
    FOREIGN KEY (target_role_id) REFERENCES roles(id) ON DELETE CASCADE,
    FOREIGN KEY (target_hierarchy_level) REFERENCES hierarchy_levels(id) ON DELETE SET NULL,
    FOREIGN KEY (target_user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY uq_notif_idempotency (idempotency_key), -- 🆕 evita duplicados si el cron reintenta o corre dos veces
    INDEX idx_notif_interlocutor (interlocutor_id),
    INDEX idx_notif_created (created_at DESC),
    INDEX idx_notif_hierarchy (target_hierarchy_level), -- 🆕
    INDEX idx_notif_legal_entity (legal_entity_id) -- 🆕
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla pivote de seguimiento de lectura por usuario (Control de estado leída/no leída)
CREATE TABLE notification_recipients (
    notification_id BIGINT NOT NULL,
    user_id BIGINT NOT NULL,
    is_read BOOLEAN DEFAULT FALSE,
    read_at TIMESTAMP(3) NULL,
    PRIMARY KEY (notification_id, user_id),
    FOREIGN KEY (notification_id) REFERENCES notifications(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_recipients_status (user_id, is_read)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 🆕 Bitácora de despacho por canal (MVP: solo 'in_app', diseñada para reusar
-- los adaptadores stub de Telegram/WhatsApp que ya dejó [1006] sin reescribir el core)
CREATE TABLE notification_dispatch_log (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    notification_id BIGINT NOT NULL,
    channel ENUM('in_app', 'telegram', 'whatsapp', 'email') NOT NULL DEFAULT 'in_app',
    status ENUM('pending', 'sent', 'failed') NOT NULL DEFAULT 'sent',
    dispatched_at TIMESTAMP(3) NULL,
    error_detail VARCHAR(255) NULL,
    FOREIGN KEY (notification_id) REFERENCES notifications(id) ON DELETE CASCADE,
    INDEX idx_dispatch_status (channel, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 🆕 Tabla de archivado para retención (mismo esquema que notifications, sin FKs activas)
-- Un cron mensual mueve aquí todo lo > 90 días y lo borra de notifications/notification_recipients
CREATE TABLE notifications_archive LIKE notifications;
```

---

## 🔌 3. ESPECIFICACIÓN DE ENDPOINTS DEL API CORE

Prefijo: `/api/v1/notifications`

1. **Listar Notificaciones del Usuario (`GET /api/v1/notifications`)**
   - Cabeceras: `Authorization: Bearer <token>`, `X-Interlocutor-Id: <id>`
   - Query opcionales: `unread_only`, `limit`, `offset`
   - Devuelve notificaciones filtradas por el alcance perimetral del usuario (sede, rol, y 🆕 nivel jerárquico si aplica).

2. **Marcar Notificación como Leída (`PATCH /api/v1/notifications/{id}/read`)**

3. **Marcar Todas como Leídas (`PATCH /api/v1/notifications/read-all`)**

4. **Crear Alerta o Mensaje Informativo Corporativo (`POST /api/v1/notifications`)**
   - Restricción: privilegios administrativos o disparo automático por daemons/cron.
   - Payload ejemplo:
   ```json
   {
     "notification_type_code": "BROADCAST_INFO",
     "interlocutor_id": null,
     "target_role_id": null,
     "target_hierarchy_level": 2,
     "title": "Reunión de mandos medios",
     "message": "Convocatoria a reunión de coordinación el viernes a las 15:00.",
     "is_global": false
   }
   ```

5. 🆕 **Panel de Monitoreo Centralizado (`GET /api/v1/notifications/monitor`)**
   - Cabeceras: `Authorization: Bearer <token>` (solo roles administrativos/corporativos).
   - Query opcionales: `severity=critical|warning|info`, `date_from`, `date_to`, `interlocutor_id`, `status=pending|acknowledged`.
   - Devuelve consolidados (totales por tipo/severidad/sede) y el detalle de cada notificación con su lista de destinatarios y estado de lectura por cada uno.
   - Respuesta ejemplo:
   ```json
   {
     "status": "success",
     "data": {
       "summary": {
         "total": 128,
         "critical": 14,
         "warning": 52,
         "info": 62,
         "by_interlocutor": [
           {"interlocutor_id": 12, "name": "Tienda Chapinero", "critical": 3}
         ]
       },
       "notifications": [
         {
           "id": 1042,
           "type": "STOCK_MIN",
           "severity": "warning",
           "title": "Stock Mínimo Alcanzado",
           "created_at": "2026-07-24T10:15:30.123Z",
           "recipients": [
             {"user_id": 88, "name": "Juan Pérez", "is_read": true, "read_at": "2026-07-24T10:20:00.000Z"},
             {"user_id": 91, "name": "Ana Gómez", "is_read": false, "read_at": null}
           ]
         }
       ]
     }
   }
   ```

---

## ⚙️ 4. LÓGICA DE GENERACIÓN AUTOMÁTICA EN BACKEND (WORKERS / TRIGGERS)

**Por evento (Kardex/lotes):**
- **Agotamiento de Stock (`STOCK_OUT`):** cuando una transacción actualiza el stock a `0` en `inventory_stock`.
- **Stock Mínimo (`STOCK_MIN`):** cuando el saldo cruza a la baja el umbral configurado.
- **Próximos a Vencer (`EXPIRING_SOON`):** ejecución diaria cruzando `expiration_date` con el parámetro de alerta temprana (estándar FEFO).
- **Mensajes a Voluntad (`BROADCAST_INFO`):** inyección manual desde el Panel de Administración, dirigible por sede, por rol o 🆕 por nivel jerárquico (`target_hierarchy_level`).

**🆕 Por hora programada (`scheduled_notification_rules`, catálogo de negocio 100% administrable):**
- Un cron (`cron_scheduled_notifications.php`) se ejecuta cada minuto y evalúa qué filas de `scheduled_notification_rules` (activas) tienen `check_time` igual a la hora actual.
- Cada fila referencia un `rule_type_id`. La tabla/columna a consultar **no vive en la fila del admin** — vive en `condition_rule_types`, poblada únicamente por migración de desarrollo. Esto es intencional: los identificadores de tabla/columna deben quedar fijos en código/BD técnica, nunca en un formulario editable, para garantizar que cada patrón use sus índices reales y consultas preparadas de valores (nunca SQL armado con identificadores dinámicos).
- **Crear una condición nueva no requiere desarrollo, siempre que encaje en un `rule_type` ya existente**: un admin con permisos entra al panel, elige el `rule_type` (la gran mayoría de "hora límite" son `NO_RECORD_BY_TIME`), define nombre, hora, alcance y umbral (si aplica), y guarda — cero identificadores, solo valores. Solo si aparece un patrón genuinamente nuevo (una tabla que ningún `rule_type` cubre todavía), desarrollo agrega una fila en `condition_rule_types` vía migración — es una inserción de una línea, no una reescritura, y mantiene la disciplina de seguridad/rendimiento intacta.
- Ejemplo ya definido: `rule_type = NO_RECORD_BY_TIME` con `target_table='daily_transfers'` (fijo en `condition_rule_types`) → notificación `TRANSFER_NOT_REGISTERED` si la sede no registró traspaso antes de la hora configurada en la instancia.
- **Idempotencia:** antes de insertar, el cron calcula `idempotency_key = SHA1(rule_id . interlocutor_id . CURDATE())` e intenta el `INSERT ... ON DUPLICATE KEY UPDATE id=id` contra el `UNIQUE KEY uq_notif_idempotency`. Si el cron corre dos veces el mismo día para la misma regla e interlocutor, la segunda ejecución no duplica la notificación.
- El destino por defecto es la sede (`interlocutor_id`) y, en copia, el rol de supervisión de zona; si la regla debe llegarle a mandos medios independientemente de su sede, se usa `scope = hierarchy_level` en vez de `interlocutor_id`.

---

## 💻 5. COMPONENTES FRONT-END (INTEGRACIÓN EN SUBSISTEMAS)

1. **Indicador Visual en Cabecera:** icono de campana con badge numérico (`total_unread`), área táctil ≥ 46px × 46px, despliega panel flotante.
2. **Panel Desplegable de Alertas (`notifications-widget.js`):** listado cronológico clasificado por color:
   - 🔴 Crítico: stock agotado / productos vencidos / traspaso no registrado tras vencer el plazo.
   - 🟡 Advertencia: stock mínimo / próximos a vencer.
   - 🔵 Informativo: comunicados corporativos o mensajes a voluntad.
   Cada tarjeta permite marcar como leída y, si aplica, enlaza al módulo correspondiente (ej. [1003] Almacenes).
3. **Módulo de Cliente API (`api-client.js`):** polling asíncrono cada 60s o bajo demanda, adjuntando `Authorization` y `X-Interlocutor-Id`.

---

## 🆕 6. MÓDULO DE MONITOREO CENTRALIZADO (PANEL ADMIN)

Nueva sección en el panel de administración (`admin/index.php?section=notifications_monitor`), visible solo para roles corporativos/directivos:

1. **Vista de Consolidados:** tarjetas KPI con totales por severidad (crítico/advertencia/info), comparativo por sede, y tendencia de los últimos 7 días.
2. **Vista de Críticos:** listado filtrable priorizando severidad `critical` no reconocida (`is_read = false` en todos sus destinatarios), con tiempo transcurrido desde su creación para detectar alertas ignoradas.
3. **Vista de Detalle:** al seleccionar una notificación, se muestra el mensaje completo, el tipo, la sede/interlocutor de origen, y la tabla de destinatarios con su estado individual de lectura (`notification_recipients`), permitiendo identificar exactamente quién la vio y quién no.
4. **Filtros:** por rango de fechas, severidad, tipo, sede/interlocutor y estado de reconocimiento.
5. **Diseño:** mismo estándar Mobile-First / Industrial UI (áreas táctiles 46px × 46px), coherente con el resto del panel admin de OMNI.
6. **Rendimiento:** las consultas de consolidados se sirven desde una vista o tabla resumen (`notification_summary_daily`) recalculada por cron, evitando agregaciones pesadas en tiempo real sobre `notifications`/`notification_recipients`.
7. 🆕 **Administración del catálogo de reglas programadas (`admin/index.php?section=notification_rules`):** CRUD sobre `scheduled_notification_rules` para que un admin cree condiciones nuevas sin desarrollo — formulario con: nombre descriptivo, tipo de notificación, `rule_type` (dropdown de los patrones ya construidos por desarrollo), hora de evaluación, alcance (sede específica / todas las sedes / nivel jerárquico) y umbral si el patrón lo requiere. El admin **nunca ve ni elige tabla/columna** — eso es responsabilidad de desarrollo en `condition_rule_types`, protegido del CRUD. Incluye vista de "reglas activas" con última ejecución y cuántas notificaciones generó cada una, para detectar reglas mal configuradas (ej. que disparan todos los días sin falta).

---

## ✅ 7. DECISIONES DE ARQUITECTURA (pendientes ya resueltos)

| # | Pendiente | Decisión tomada | Impacto |
|---|---|---|---|
| 1 | Auditoría de origen | Se agrega `notifications.created_by`, nullable (NULL = evento/cron; obligatorio por validación de API cuando el tipo es `BROADCAST_INFO` manual) | DDL sección 2 |
| 2 | Aislamiento por entidad legal | Se agrega `notifications.legal_entity_id`; `NULL` queda reservado solo a super-admin para comunicados verdaderamente corporativos a las 7 entidades; cualquier otro broadcast debe fijar la entidad | DDL sección 2 |
| 3 | Idempotencia del motor programado | `idempotency_key` + `UNIQUE KEY` en `notifications`; el cron calcula el hash antes de insertar | DDL + sección 4 |
| 4 | Canal único vs. multicanal | MVP queda **in-app únicamente**, pero se modela `notification_dispatch_log` desde ya para poder reusar los adaptadores stub de Telegram/WhatsApp de [1006] en una fase posterior sin re-arquitectura | DDL sección 2 |
| 5 | Retención | Política: notificaciones con `created_at` > 90 días se mueven mensualmente (cron) a `notifications_archive` y se purgan de `notifications`/`notification_recipients` | DDL sección 2 |
| 6 | Granularidad de `hierarchy_levels` | Seed inicial de 3 niveles (Operativo / Mando Medio / Dirección), estándar de mercado para esta estructura organizacional; la tabla ya es administrable por diseño si se necesita un nivel más adelante | DDL sección 2 |
| 7 | Catálogo de condiciones programadas administrable | Modelo de dos capas con separación estricta identificador/valor: `condition_rule_types` (tabla/columna fijas, pobladas solo por migración de desarrollo — nunca en un formulario) + `scheduled_notification_rules` (instancias 100% admin: hora, alcance, umbral — solo valores, nunca identificadores de BD). Evita el antipatrón de SQL dinámico armado desde configuración editable, que rompería la garantía de índices y el estándar "prepared statements siempre" | DDL sección 2, sección 4, sección 6.7 |

## ⏳ Único punto que sigue abierto (no bloquea el desarrollo)

- Priorizar cuáles condiciones concretas se configuran primero además de "traspaso no registrado" (ej. cierre de caja, checklist de apertura) — esto ya no requiere desarrollo adicional, solo que negocio las registre en el panel una vez esté construido.
