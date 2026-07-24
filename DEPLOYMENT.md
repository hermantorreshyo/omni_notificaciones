# Manual de Despliegue — `omni_notificaciones` (Módulo [1007])

> **Servidor destino:** Debian 13 (Trixie) · **Stack:** LAMP nativo (Apache2 + PHP 8.x + MySQL 8.x), sin frameworks ni Composer
> **Repositorio:** `omni_notificaciones`
> **Este documento se actualiza en cada fase** — es un manual vivo, no un entregable único. Cada sección nueva se agrega en el commit de la fase que la introduce.

---

## Índice

1. Prerrequisitos del servidor
2. Clonado y estructura del repositorio
3. Fase 1 — Base de datos (migración)
4. Verificación post-despliegue de Fase 1
5. Rollback
6. (Se agrega en Fase 2) Configuración del vhost Apache y endpoints
7. (Se agrega en Fase 4) Cron de notificaciones programadas
8. (Se agrega en Fase 6) Panel de administración

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

## 2. Clonado y estructura del repositorio

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

## 3. Fase 1 — Base de datos (migración)

### 3.1 Verificar el supuesto de negocio pendiente

Antes de aplicar, confirma que la instalación real de OMNI ya tiene:

```sql
SHOW TABLES LIKE 'interlocutors';
SHOW TABLES LIKE 'roles';
SHOW TABLES LIKE 'users';
SHOW TABLES LIKE 'permissions';
SHOW TABLES LIKE 'subsystem_screens';
```

Las cinco deben existir — la migración de [1007] no las crea, solo las referencia.

### 3.2 Backup previo (obligatorio en producción)

```bash
mysqldump -u <usuario> -p --single-transaction --routines --triggers \
  <base_de_datos> > /var/backups/omni_pre_1007_$(date +%Y%m%d_%H%M%S).sql
```

### 3.3 Aplicar la migración

```bash
cd /var/www/omni_notificaciones
mysql -u <usuario> -p <base_de_datos> < database/migration_1007_notificaciones.sql
echo "Exit code: $?"
```

El script es **idempotente** — correrlo de nuevo no duplica seeds ni falla
(validado: `CREATE TABLE IF NOT EXISTS`, `INSERT IGNORE`, y el `ALTER TABLE
roles` envuelto en SQL dinámico condicional).

---

## 4. Verificación post-despliegue de Fase 1

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

## 5. Rollback

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

O, más simple y seguro, restaurar el backup de la sección 3.2:

```bash
mysql -u <usuario> -p <base_de_datos> < /var/backups/omni_pre_1007_<fecha>.sql
```

---

## 6. Fase 3 — Motor de eventos (sin pasos de despliegue propios)

`cron/NotificationTriggerService.php` no se despliega como script independiente
— sus métodos estáticos se invocan desde dentro de InventoryController y del
cron de Pareto de vencimientos ya existentes (ver `docs/INTEGRATION_FASE3.md`
para los 2 puntos exactos de enganche). No requiere entrada en crontab.

---

## 7. Fase 4 — Cron de notificaciones programadas

### 7.1 Variables de entorno

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

### 7.2 Registrar el cron (cada minuto)

```bash
sudo crontab -u www-data -e
```

Agregar:

```cron
* * * * * /usr/bin/php /var/www/omni_notificaciones/cron/cron_scheduled_notifications.php >> /var/log/omni_notificaciones_cron.log 2>&1
```

### 7.3 Verificación

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

### 7.4 Crear la primera regla (ejemplo real, probado)

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
