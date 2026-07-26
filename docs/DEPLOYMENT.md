# Manual de Despliegue e Implantación — `omni_notificaciones` (Módulo [1007])

> **Servidor destino:** Debian 13 (Trixie) · **Stack:** LAMP nativo (Apache2 + PHP 8.x + MySQL 8.x), sin frameworks ni Composer
> **Repositorio:** `omni_notificaciones`
> **Este documento se actualiza en cada fase** — es un manual vivo, no un entregable único. Cada sección nueva se agrega en el commit de la fase que la introduce.

> **Documentación relacionada:** `MANUAL_USUARIO.md` (uso diario), `MANUAL_DESARROLLADOR.md` (arquitectura y extensión), `API_REFERENCE.md` (endpoints).

---

## Índice

1. Prerrequisitos del servidor
2. Configuración del subdominio notificaciones.josepan.app
3. Clonado y estructura del repositorio
4. Fase 1 — Base de datos (migración)
5. Verificación post-despliegue de Fase 1
6. Rollback
7. Fase 2 — Endpoints API CORE
8. Fase 3 — Motor de eventos (sin pasos de despliegue propios)
9. Fase 4 — Cron de notificaciones programadas
10. Fase 5 — Widget de campana (frontend)
11. Fase 6 — Panel de administración (monitoreo + reglas)
12. Fix — BORRADOR en transfers (aplicar tras Fase 4)
13. Plan de Implantación Gradual

---

## 1. Prerrequisitos del servidor (Debian 13)

```bash
# Actualizar índices de paquetes
sudo apt update

# Apache2
sudo apt install -y apache2

# PHP 8.x + extensiones que usa OMNI API CORE (PDO MySQL, JSON, mbstring)
sudo apt install -y php php-mysql php-json php-mbstring libapache2-mod-php

# Cliente MySQL (si el servidor de BD es remoto, solo se necesita el cliente)
sudo apt install -y default-mysql-client

# Verificar versiones
php -v          # Esperado: PHP 8.x
mysql --version # Esperado: cliente compatible con MySQL 8.x
apache2 -v
```

Si el motor de base de datos corre en el mismo servidor:

```bash
sudo apt install -y mariadb-server
sudo mysql_secure_installation
```

> **Nota:** el proyecto usa MySQL 8.x en producción (según el manual de
> desarrollador de OMNI API CORE). Si Debian 13 trae MariaDB por defecto en
> sus repos, validar compatibilidad de sintaxis antes de aplicar migraciones
> — algunas construcciones DDL difieren entre motores (ver hallazgo en
> `docs/CHECKLIST_FASE1.md` sobre bugs de sintaxis encontrados al probar
> `schema.sql` bajo MariaDB).

Habilitar mod_rewrite y mod_headers (necesarios para el proxy PHP sin CORS descrito en el manual de OMNI):

```bash
sudo a2enmod rewrite headers
sudo systemctl restart apache2
```

---

## 2. Configuración del subdominio `notificaciones.josepan.app`

El subdominio ya apunta (DNS + hosting) a `/var/www/omni/omni_notificaciones`.
Esta sección deja Apache sirviendo esa carpeta correctamente.

### 2.1 Habilitar módulos necesarios

```bash
sudo a2enmod rewrite headers ssl
```

### 2.2 Copiar el VirtualHost

El archivo `deploy/notificaciones.josepan.app.conf` (incluido en este repo)
ya trae la configuración completa: HTTPS forzado, y bloqueo de las carpetas
que nunca deben ser accesibles vía HTTP (`database/`, `cron/`, `docs/`,
`tests/`, `postman/`, y **`api/`** — ver nota importante más abajo).

```bash
sudo cp /var/www/omni/omni_notificaciones/deploy/notificaciones.josepan.app.conf \
    /etc/apache2/sites-available/notificaciones.josepan.app.conf
sudo a2ensite notificaciones.josepan.app.conf
```

### 2.3 Verificar que el DNS público ya resuelve (antes de pedir el certificado)

**"El subdominio apunta a la carpeta del proyecto" significa que el vhost
de Apache ya sabe qué servir — NO significa que el DNS público de
`josepan.app` ya tenga el registro que le dice a Internet a qué IP ir.**
Son dos pasos distintos, en dos lugares distintos (Apache vs. el proveedor
de DNS del dominio). Confirma el segundo antes de correr certbot:

```bash
# IP pública real de este servidor
curl -4 ifconfig.me

# ¿El subdominio ya resuelve?
dig +short A notificaciones.josepan.app
```

Si `dig` no devuelve nada (o Let's Encrypt reporta `NXDOMAIN`), falta
agregar el registro DNS en el proveedor donde se gestiona `josepan.app`:

```
Tipo: A · Nombre: notificaciones · Valor: <IP pública de arriba>
```

Espera a la propagación (minutos a horas) y repite `dig` hasta que
devuelva la IP correcta — **recién ahí** continuar con 2.4.

### 2.4 Certificado SSL (Let's Encrypt)

```bash
sudo apt install -y certbot python3-certbot-apache
sudo certbot --apache -d notificaciones.josepan.app
```

Certbot detecta el `VirtualHost` recién habilitado y completa las 3 líneas
`SSLEngine on` / `SSLCertificateFile` / `SSLCertificateKeyFile` él mismo —
por eso el `.conf` del repo las trae **comentadas** de fábrica. Si las
descomentas a mano antes de correr certbot, `apache2ctl configtest` falla
con `SSLCertificateFile: file ... does not exist or is empty` y certbot no
puede ni arrancar (el certificado que buscan esas líneas todavía no existe).

**Si ya te pasó esto** (el error de arriba): edita
`/etc/apache2/sites-enabled/notificaciones.josepan.app.conf`, vuelve a
comentar esas 3 líneas, corre `sudo systemctl reload apache2`, y vuelve a
correr `certbot --apache` — esta vez sí encontrará el bloque `:443` ya
existente y le insertará las líneas correctas sin tocar el resto de la
configuración (el bloqueo de `database/`, `cron/`, `api/`, etc. se conserva).

### 2.5 Recargar Apache

```bash
sudo apache2ctl configtest   # debe responder "Syntax OK"
sudo systemctl reload apache2
```

### 2.6 Verificación

```bash
curl -I https://notificaciones.josepan.app/assets/js/api-client.js
# Debe responder 200 OK, Content-Type: application/javascript (o text/plain)

curl -I https://notificaciones.josepan.app/database/migration_1007_notificaciones.sql
# Debe responder 403 Forbidden -- si responde 200, el bloqueo no quedó aplicado

curl -I https://notificaciones.josepan.app/api/NotificationController.php
# Debe responder 403 Forbidden -- ver nota importante abajo sobre por qué
```

### ⚠️ Nota importante — el proxy `/api/omni.php` no está incluido en este repo

Las páginas `admin/notifications-monitor.html` y `admin/notification-rules.html`
llaman a `fetch('/api/omni.php?action=...')` **relativo a su propio origen**
— es decir, cuando se abren desde `notificaciones.josepan.app`, esa llamada
va a `notificaciones.josepan.app/api/omni.php`, no directamente al API CORE.

Este repo **nunca incluyó ese archivo proxy** — construí los *controllers de
negocio* (`api/NotificationController.php`, `api/NotificationRulesController.php`)
que van del otro lado del proxy, integrados al router de OMNI API CORE (por
eso están bloqueados del acceso web directo arriba, en la sección 2.2).

**Para que las 2 páginas de administración funcionen en este subdominio,
falta copiar el mismo `api/omni.php`** que ya usan los demás subsistemas
(1002-1004) a la raíz pública de este proyecto (ej.
`/var/www/omni/omni_notificaciones/api-proxy/omni.php`, fuera del `api/`
bloqueado, o renombrando la carpeta bloqueada a `api-core/` y dejando
`api/omni.php` como el proxy público). Como no tengo ese archivo en este
repo, no puedo generarlo sin arriesgarme a inventar su contenido — cópialo
tal cual desde uno de los subsistemas existentes.

---

## 3. Clonado y estructura del repositorio

```bash
cd /var/www
sudo git clone <url-del-repo> omni_notificaciones
cd omni_notificaciones
sudo chown -R www-data:www-data /var/www/omni_notificaciones
```

Estructura esperada del repo:

```
omni_notificaciones/
├── database/    → migraciones SQL
├── api/         → endpoints PHP (Fase 2)
├── cron/        → workers de eventos y programados (Fase 3-4)
├── admin/       → panel de monitoreo y CRUD de reglas (Fase 6)
├── assets/js/   → widget de campana + cliente API (Fase 5)
└── docs/        → este manual y la documentación del prompt maestro
```

**Archivos y carpetas fuera del webroot público**, según el estándar de
ingeniería del proyecto ("Files kept outside webroot con reglas `.htaccess`
deny"):

```apache
# .htaccess en la raíz del repo, o configuración equivalente en el vhost
<FilesMatch "\.(sql|md|log)$">
    Require all denied
</FilesMatch>

<DirectoryMatch "^/var/www/omni_notificaciones/(database|docs|cron)">
    Require all denied
</DirectoryMatch>
```

Solo `api/`, `admin/` y `assets/` deben quedar accesibles vía HTTP.

---

## 4. Fase 1 — Base de datos (migración)

### 4.1 Verificar el supuesto de negocio pendiente

Antes de aplicar, confirma que la instalación real de OMNI ya tiene:

```sql
SHOW TABLES LIKE 'interlocutors';
SHOW TABLES LIKE 'roles';
SHOW TABLES LIKE 'users';
SHOW TABLES LIKE 'permissions';
SHOW TABLES LIKE 'subsystem_screens';
```

Las cinco deben existir — la migración de [1007] no las crea, solo las referencia.

### 4.2 Backup previo (obligatorio en producción)

```bash
mysqldump -u <usuario> -p --single-transaction --routines --triggers \
  <base_de_datos> > /var/backups/omni_pre_1007_$(date +%Y%m%d_%H%M%S).sql
```

### 4.3 Aplicar la migración

```bash
cd /var/www/omni_notificaciones
mysql -u <usuario> -p <base_de_datos> < database/migration_1007_notificaciones.sql
echo "Exit code: $?"
```

El script es **idempotente** — correrlo de nuevo no duplica seeds ni falla
(validado: `CREATE TABLE IF NOT EXISTS`, `INSERT IGNORE`, y el `ALTER TABLE
roles` envuelto en SQL dinámico condicional).

---

## 5. Verificación post-despliegue de Fase 1

```sql
-- Deben existir las 8 tablas nuevas
SHOW TABLES LIKE 'hierarchy_levels';
SHOW TABLES LIKE 'notification_types';
SHOW TABLES LIKE 'condition_rule_types';
SHOW TABLES LIKE 'scheduled_notification_rules';
SHOW TABLES LIKE 'notifications';
SHOW TABLES LIKE 'notification_recipients';
SHOW TABLES LIKE 'notification_dispatch_log';
SHOW TABLES LIKE 'notifications_archive';

-- Deben verse los 3 niveles jerárquicos
SELECT * FROM hierarchy_levels;                 -- 3 filas

-- Deben verse los 5 tipos de notificación base
SELECT code, severity, trigger_mode FROM notification_types;  -- 5 filas

-- Debe verse el patrón de condición apuntando a la tabla real `transfers`
SELECT code, target_table, target_date_column, target_scope_column
FROM condition_rule_types;                      -- 1 fila: NO_RECORD_BY_TIME

-- roles debe tener la columna nueva, nullable
SHOW COLUMNS FROM roles LIKE 'hierarchy_level_id';

-- Deben verse las 2 pantallas RBAC de 1007
SELECT * FROM subsystem_screens WHERE subsystem = '1007';

-- Deben verse los 2 permisos atómicos
SELECT * FROM permissions WHERE resource = 'notifications';
```

Si alguna consulta devuelve vacío, la migración no se aplicó completa —
revisar el log de errores de `mysql` antes de continuar a Fase 2.

---

## 6. Rollback

Si algo sale mal y hay que revertir Fase 1:

```sql
SET foreign_key_checks = 0;
DROP TABLE IF EXISTS notifications_archive;
DROP TABLE IF EXISTS notification_dispatch_log;
DROP TABLE IF EXISTS notification_recipients;
DROP TABLE IF EXISTS notifications;
DROP TABLE IF EXISTS scheduled_notification_rules;
DROP TABLE IF EXISTS condition_rule_types;
DROP TABLE IF EXISTS notification_types;
ALTER TABLE roles DROP FOREIGN KEY fk_roles_hierarchy_level;
ALTER TABLE roles DROP COLUMN hierarchy_level_id;
DROP TABLE IF EXISTS hierarchy_levels;
DELETE FROM subsystem_screens WHERE subsystem = '1007';
DELETE FROM permissions WHERE resource = 'notifications';
SET foreign_key_checks = 1;
```

O, más simple y seguro, restaurar el backup de la sección 4.2:

```bash
mysql -u <usuario> -p <base_de_datos> < /var/backups/omni_pre_1007_<fecha>.sql
```

---

## 7. Fase 2 — Endpoints API CORE

### 7.1 Copiar los controllers

```bash
scp -r api/NotificationController.php api/NotificationRulesController.php \
    usuario@servidor:/var/www/omni_notificaciones/api/
chown www-data:www-data /var/www/omni_notificaciones/api/*.php
chmod 644 /var/www/omni_notificaciones/api/*.php
```

### 7.2 Registrar las rutas

Ver la tabla completa de rutas, métodos, permisos y contratos de request/
response en `docs/API_REFERENCE.md`. Quien mantiene el router central de
OMNI API CORE debe:

1. Registrar las 11 rutas de la tabla resumen de `API_REFERENCE.md`.
2. Armar el array `$authContext` (ver contrato en la cabecera de
   `NotificationController.php`) a partir de lo que el proxy ya inyecta
   (JWT decodificado, `X-Interlocutor-Id`).
3. Confirmar el nombre real de la clase/método de conexión a BD.

Ninguno de estos 3 puntos requiere cambios en la lógica de negocio de los
controllers — son cableado (wiring), no diseño. Ver
`docs/MANUAL_DESARROLLADOR.md` sección 6 para más detalle.

### 7.3 Verificación

```bash
curl -X GET "https://tu-dominio/api/omni.php?action=notifications" \
  -H "Authorization: Bearer <token>" -H "X-Interlocutor-Id: <id>"
```
Debe responder `{"status":"success","data":{"total_unread":0,"notifications":[]}}`
en una instalación recién migrada (sin notificaciones aún).

---

## 8. Fase 3 — Motor de eventos (sin pasos de despliegue propios)

`cron/NotificationTriggerService.php` no se despliega como script independiente
— sus métodos estáticos se invocan desde dentro de InventoryController y del
cron de Pareto de vencimientos ya existentes (ver `docs/INTEGRATION_FASE3.md`
para los 2 puntos exactos de enganche). No requiere entrada en crontab.

---

## 9. Fase 4 — Cron de notificaciones programadas

### 9.1 Variables de entorno

El script `cron/cron_scheduled_notifications.php` lee la conexión a BD desde
variables de entorno (no hardcodeadas). Configurarlas en el entorno del
usuario que ejecuta el cron (ej. `/etc/environment` o el propio crontab):

```bash
OMNI_DB_DSN="mysql:host=127.0.0.1;dbname=<base_de_datos>;charset=utf8mb4"
OMNI_DB_USER="omni_cron"
OMNI_DB_PASS="<password>"
```

> Usar un usuario de MySQL dedicado (`omni_cron`) con permisos limitados a
> `SELECT` sobre `transfers` y las tablas de catálogo, e `INSERT` sobre
> `notifications` — no reutilizar el usuario de la aplicación web.

### 9.2 Registrar el cron (cada minuto)

```bash
sudo crontab -u www-data -e
```

Agregar:

```cron
* * * * * /usr/bin/php /var/www/omni_notificaciones/cron/cron_scheduled_notifications.php >> /var/log/omni_notificaciones_cron.log 2>&1
```

### 9.3 Verificación

```bash
# Ver que el cron corrió sin errores
tail -f /var/log/omni_notificaciones_cron.log

# Confirmar que las notificaciones programadas se están generando
mysql -u <usuario> -p <base_de_datos> -e \
  "SELECT nt.code, COUNT(*) FROM notifications n
   JOIN notification_types nt ON nt.id = n.notification_type_id
   WHERE nt.trigger_mode = 'scheduled' AND n.created_at > NOW() - INTERVAL 1 DAY
   GROUP BY nt.code;"
```

Si una regla no dispara a la hora esperada, revisar:
1. `scheduled_notification_rules.active = 1` y `deleted_at IS NULL`
2. `check_time` coincide exactamente con `HH:MM:00` (el motor compara al minuto)
3. El log de errores de PHP — `ScheduledRuleEngine` registra con `error_log()`
   cualquier `rule_type` no reconocido o tabla fuera de whitelist

### 9.4 Crear la primera regla (ejemplo real, probado)

```sql
INSERT INTO scheduled_notification_rules
  (name, notification_type_id, rule_type_id, check_time, scope, created_by)
VALUES
  ('Traspaso diario no registrado (todas las tiendas)',
   (SELECT id FROM notification_types WHERE code='TRANSFER_NOT_REGISTERED'),
   (SELECT id FROM condition_rule_types WHERE code='NO_RECORD_BY_TIME'),
   '09:00:00', 'all_pos', <id_del_admin_que_crea_la_regla>);
```

Para un resumen consolidado a Mandos Medios en vez de una alerta por sede:

```sql
INSERT INTO scheduled_notification_rules
  (name, notification_type_id, rule_type_id, check_time, scope, hierarchy_level_id, created_by)
VALUES
  ('Resumen consolidado a Mandos Medios',
   (SELECT id FROM notification_types WHERE code='TRANSFER_NOT_REGISTERED'),
   (SELECT id FROM condition_rule_types WHERE code='NO_RECORD_BY_TIME'),
   '09:00:00', 'hierarchy_level',
   (SELECT id FROM hierarchy_levels WHERE code='MANDO_MEDIO'),
   <id_del_admin_que_crea_la_regla>);
```

---

## 10. Fase 5 — Widget de campana (frontend)

### 10.1 Sin build step

Los archivos `assets/js/api-client.js` y `assets/js/notifications-widget.js`
son JS vanilla sin dependencias — no requieren npm, webpack, ni build. Se
sirven directamente como estáticos vía Apache.

### 10.2 Integración en cada subsistema ([1002]-[1005])

Agregar en el layout HTML de cada subsistema, antes del cierre de `</body>`:

```html
<div id="omni-notif-widget"></div>
<script src="/assets/js/api-client.js"></script>
<script src="/assets/js/notifications-widget.js"></script>
<script>
  OmniNotificationsWidget.mount('omni-notif-widget');
</script>
```

Colocar el `<div id="omni-notif-widget">` en el header de cada subsistema,
junto al resto de iconos de navegación.

### 10.3 Verificación visual rápida

1. Abrir cualquier subsistema logueado — debe verse el ícono de campana.
2. Si hay notificaciones no leídas, debe verse el badge numérico rojo.
3. Al tocar la campana, debe desplegarse el panel con las notificaciones
   ordenadas de más reciente a más antigua, coloreadas por severidad
   (rojo=crítico, naranja=advertencia, gris=informativo).
4. Tocar el check de una notificación la marca como leída y el badge baja.
5. "Marcar todas leídas" vacía el badge.

### 10.4 Pruebas automatizadas

Los 2 archivos se validaron con `node --check` (sintaxis) y con una
suite funcional usando `jsdom` que simula el proxy `/api/omni.php` y
verifica: badge inicial, apertura del panel, colores de severidad,
marcado individual y masivo de lectura, y presencia del área táctil
de 46px. Ver el commit de esta fase para el script de prueba.

---

## 11. Fase 6 — Panel de administración (monitoreo + reglas)

### 11.1 Rutas nuevas a registrar

```
GET    /api/v1/notifications/monitor              → NotificationController::monitor()
GET    /api/v1/notifications/rules                → NotificationRulesController::index()
GET    /api/v1/notifications/rules/types           → NotificationRulesController::ruleTypes()
GET    /api/v1/notifications/rules/form-options    → NotificationRulesController::formOptions()
POST   /api/v1/notifications/rules                 → NotificationRulesController::store()
PUT    /api/v1/notifications/rules/{id}             → NotificationRulesController::update($id)
DELETE /api/v1/notifications/rules/{id}             → NotificationRulesController::destroy($id)
```

Las 6 requieren el permiso `notifications.admin` (ya insertado en Fase 1).
Asignar ese permiso a los roles correspondientes en `role_permissions` es
una decisión de negocio pendiente (ver `CHECKLIST_FASE1.md`).

### 11.2 Páginas a publicar

```
admin/notifications-monitor.html   → Panel de monitoreo (KPIs + tabla filtrable)
admin/notification-rules.html      → CRUD de reglas programadas
```

Enlazar ambas desde el menú del Panel Admin de OMNI, en las pantallas
`notifications_monitor` y `notification_rules` ya registradas en
`subsystem_screens` (subsystem `1007`).

### 11.3 Verificación

1. Con un usuario que tenga `notifications.admin`: abrir
   `admin/notification-rules.html` — deben cargar los 3 dropdowns
   (tipo de notificación, patrón de condición, nivel jerárquico).
2. Crear una regla de prueba y confirmar que aparece en la tabla.
3. Eliminarla y confirmar que desaparece (borrado lógico — verificar en
   BD que `deleted_at` quedó con fecha, la fila sigue existiendo).
4. Abrir `admin/notifications-monitor.html` — deben verse las tarjetas
   KPI y la tabla de detalle con destinatarios.
5. Con un usuario SIN `notifications.admin`: ambas páginas deben mostrar
   el mensaje de error RBAC en vez de datos.

### 11.4 Pruebas automatizadas

`NotificationRulesController.php` y `NotificationController::monitor()` se
probaron funcionalmente contra datos reales (PHP 8.3 + PDO): catálogo de
patrones sin exponer tabla/columna, RBAC, validaciones de scope, CRUD
completo con borrado lógico, y consolidados correctos. Las 2 páginas admin
se probaron con `jsdom`, incluyendo una verificación explícita de que el
formulario nunca envía identificadores de tabla/columna al backend.

---

## 12. Fix — BORRADOR en transfers (aplicar tras Fase 4)

El core agregó el estado `BORRADOR` a `transfers` (workflow BORRADOR →
SOLICITADO). Esto afecta el patrón `NO_RECORD_BY_TIME` de la Fase 4: si
seguía usando `created_at`, un traspaso dejado en borrador (sin enviarse)
se contaba incorrectamente como "registrado".

```bash
mysql -u <usuario> -p <base_de_datos> < database/migration_1007_fix_transfer_date_column.sql
```

Es un `UPDATE` de una sola fila sobre `condition_rule_types` (tabla técnica,
no tocada por el admin) — cambia `target_date_column` de `created_at` a
`at_solicitado`, que es el timestamp real de cuándo la tienda solicitó
formalmente (queda `NULL` mientras el traspaso sigue en `BORRADOR`, así que
se excluye automáticamente sin lógica adicional). No requiere cambios en
`ScheduledRuleEngine.php` ni reinicio de cron.

Verificación:
```sql
SELECT code, target_date_column FROM condition_rule_types WHERE code = 'NO_RECORD_BY_TIME';
-- debe mostrar: at_solicitado
```

---

## 13. Plan de Implantación Gradual

No se recomienda activar `scope=all_pos` para las 14+ sedes desde el primer
día. Plan sugerido, consistente con el enfoque de implantación por fases ya
usado en otros subsistemas de OMNI:

### Fase A — Piloto (1-2 sedes, 1 semana)
1. Aplicar las migraciones (sección 4) y desplegar el widget solo en el
   layout de 1-2 sedes piloto (o dejarlo activo para todas pero crear la
   regla programada con `scope=specific_interlocutor` apuntando solo a
   esas sedes).
2. Confirmar que el equipo de esas sedes entiende la campana y sabe marcar
   notificaciones como leídas (usar `docs/MANUAL_USUARIO.md`).
3. Revisar a diario el Panel de Monitoreo — confirmar que no hay ruido
   excesivo (ej. una regla mal configurada disparando todos los días).

### Fase B — Motor de eventos (todas las sedes, stock)
1. Enganchar `NotificationTriggerService` en producción (ver
   `MANUAL_DESARROLLADOR.md` sección 6, punto 3).
2. Este motor es transparente para el usuario (no requiere que cree nada) —
   se puede activar para todas las sedes desde el inicio, ya que solo
   refleja umbrales de stock que ya existen.

### Fase C — Motor programado (rollout progresivo por sede)
1. Cambiar la regla piloto de `specific_interlocutor` a `all_pos` cuando
   se tenga confianza en el patrón.
2. Considerar empezar con `scope=hierarchy_level` (resumen consolidado a
   Mandos Medios) antes que notificar a cada sede individualmente — genera
   menos ruido mientras el equipo se acostumbra.

### Fase D — Panel Admin abierto a más roles
1. Asignar `notifications.admin` solo a 1-2 personas al inicio (ej.
   Dirección de Tecnología).
2. Una vez validado el patrón de reglas, extender el permiso a Mandos
   Medios que necesiten crear sus propias reglas de seguimiento.

### Rollback en cualquier fase

Si una fase genera problemas, desactivar es no-destructivo:
- Reglas programadas: `UPDATE scheduled_notification_rules SET active = 0 WHERE id = ...`
- Motor de eventos: comentar la línea de invocación en `InventoryController`
  (no requiere revertir la migración).
- Rollback completo de esquema: ver sección 6.
