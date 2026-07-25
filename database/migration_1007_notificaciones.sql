-- =============================================================================
-- MIGRACIÓN [1007] — Subsistema de Notificaciones y Alertas Centralizadas
-- OMNI API CORE v6.9 · JOSEPAN 360
--
-- Script sin numerar (migration_1007_notificaciones.sql), aplicado sobre una
-- instalación OMNI ya existente en producción. Sigue el estilo del schema.sql
-- real: INT UNSIGNED para catálogos, BIGINT UNSIGNED solo para tablas de alto
-- volumen tipo log (notifications, notification_recipients, dispatch_log),
-- TIMESTAMP sin fracción, backticks, SET foreign_key_checks=0/1 en vez de
-- transacciones (las DDL de MySQL no son transaccionales — commit implícito).
-- =============================================================================
SET NAMES utf8mb4;
SET foreign_key_checks = 0;

-- -----------------------------------------------------------------------------
-- 1. Catálogo de niveles jerárquicos (independiente de `roles`)
--    Uso: dirigir broadcasts a "mandos medios" sin acoplarse a qué rol puntual
--    tiene cada usuario. No es RBAC — es un criterio de destinatario.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `hierarchy_levels` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `code`        VARCHAR(30)  NOT NULL,
    `name`        VARCHAR(60)  NOT NULL,
    `rank_order`  TINYINT UNSIGNED NOT NULL COMMENT '1=Operativo, 2=Mando Medio, 3=Dirección',
    `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_hierarchy_levels_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Niveles jerárquicos para targeting de notificaciones (no es RBAC)';

INSERT IGNORE INTO `hierarchy_levels` (`code`, `name`, `rank_order`) VALUES
    ('OPERATIVO',   'Operativo',   1),
    ('MANDO_MEDIO', 'Mando Medio', 2),
    ('DIRECCION',   'Dirección',   3);

-- Vínculo con el catálogo de roles existente — nullable, se puebla cuando
-- negocio confirme el mapeo rol→nivel (los 15 roles deterministas del
-- sistema). No rompe nada mientras esté en NULL.
-- ALTER envuelto en SQL dinámico condicional para que el script sea
-- idempotente (MySQL no soporta ADD COLUMN IF NOT EXISTS en todas las 8.x).
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'roles' AND COLUMN_NAME = 'hierarchy_level_id'
);
SET @ddl = IF(@col_exists = 0,
    'ALTER TABLE `roles` ADD COLUMN `hierarchy_level_id` INT UNSIGNED NULL COMMENT ''FK hierarchy_levels.id -- pendiente de mapeo con negocio'' AFTER `description`, ADD CONSTRAINT `fk_roles_hierarchy_level` FOREIGN KEY (`hierarchy_level_id`) REFERENCES `hierarchy_levels`(`id`) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- 2. Catálogo de tipos de notificación
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `notification_types` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `code`          VARCHAR(50)  NOT NULL,
    `name`          VARCHAR(100) NOT NULL,
    `severity`      ENUM('info','warning','critical') NOT NULL DEFAULT 'info',
    `trigger_mode`  ENUM('event','scheduled') NOT NULL DEFAULT 'event',
    `description`   TEXT NULL,
    `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_notification_types_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Catálogo de tipos de notificación con severidad y motor de disparo';

INSERT IGNORE INTO `notification_types` (`code`, `name`, `severity`, `trigger_mode`, `description`) VALUES
    ('STOCK_OUT',               'Stock Agotado',            'critical', 'event',     'El stock de un producto llegó a 0 unidades base.'),
    ('STOCK_MIN',               'Stock Mínimo Alcanzado',   'warning',  'event',     'El stock cruzó a la baja el umbral mínimo configurado.'),
    ('EXPIRING_SOON',           'Próximo a Vencer',         'warning',  'event',     'Un lote entra en ventana de alerta temprana FEFO.'),
    ('BROADCAST_INFO',          'Comunicado Corporativo',   'info',     'event',     'Mensaje informativo inyectado manualmente desde administración.'),
    ('TRANSFER_NOT_REGISTERED', 'Traspaso No Registrado',   'critical', 'scheduled', 'La sede destino no registró solicitud de traspaso antes de la hora límite configurada.');

-- -----------------------------------------------------------------------------
-- 3. Catálogo TÉCNICO de patrones de condición programada
--    Poblado SOLO por migración de desarrollo — nunca por el CRUD de admin.
--    Aquí viven los identificadores de tabla/columna, fijos, para que cada
--    patrón use índices reales y valores parametrizados (nunca SQL armado
--    con identificadores que vengan de una fila editable).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `condition_rule_types` (
    `id`                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `code`                 VARCHAR(40)  NOT NULL,
    `name`                 VARCHAR(100) NOT NULL,
    `description`          TEXT NULL,
    `target_table`         VARCHAR(64)  NOT NULL,
    `target_date_column`   VARCHAR(64)  NOT NULL,
    `target_scope_column`  VARCHAR(64)  NULL,
    `requires_threshold`   TINYINT(1)   NOT NULL DEFAULT 0,
    `created_at`           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_condition_rule_types_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Catálogo técnico de patrones de condición — dev-owned, no expuesto en el admin';

-- Único patrón confirmado en esta fase: ausencia de solicitud de traspaso
-- (tabla real `transfers`, sede destino = `interlocutor_id_dest`, fecha = `created_at`).
-- THRESHOLD_NOT_MET y FIELD_STATUS_PENDING se seedean en una migración futura
-- cuando negocio confirme la primera necesidad real que los use.
INSERT IGNORE INTO `condition_rule_types`
    (`code`, `name`, `description`, `target_table`, `target_date_column`, `target_scope_column`, `requires_threshold`)
VALUES
    ('NO_RECORD_BY_TIME', 'Ausencia de registro a una hora límite',
     'Verifica si NO existe una fila para el interlocutor destino en la fecha de hoy.',
     'transfers', 'created_at', 'interlocutor_id_dest', 0);

-- -----------------------------------------------------------------------------
-- 4. Catálogo de NEGOCIO: instancias de reglas programadas
--    Mismo patrón que `catalog_visibility_rules` (ya existente en OMNI):
--    100% administrable desde el panel, borrado lógico, sin identificadores
--    de BD expuestos — el admin solo configura valores.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `scheduled_notification_rules` (
    `id`                     INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`                   VARCHAR(150) NOT NULL COMMENT 'Etiqueta legible, ej. "Traspaso diario no registrado"',
    `notification_type_id`   INT UNSIGNED NOT NULL,
    `rule_type_id`           INT UNSIGNED NOT NULL,
    `check_time`             TIME         NOT NULL COMMENT 'Hora exacta de evaluación, ej. 09:00:00',
    `scope`                  ENUM('all_pos','specific_interlocutor','hierarchy_level') NOT NULL DEFAULT 'all_pos',
    `interlocutor_id`        INT UNSIGNED NULL COMMENT 'Si scope = specific_interlocutor',
    `hierarchy_level_id`     INT UNSIGNED NULL COMMENT 'Si scope = hierarchy_level',
    `threshold_operator`     ENUM('<','<=','>','>=','=') NULL COMMENT 'Solo si rule_type.requires_threshold',
    `threshold_value`        DECIMAL(14,4) NULL,
    `active`                 TINYINT(1)   NOT NULL DEFAULT 1,
    `deleted_at`             TIMESTAMP    NULL COMMENT 'Borrado lógico — NULL = activa',
    `created_by`             INT UNSIGNED NOT NULL,
    `created_at`             TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`             TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_snr_check_time` (`check_time`, `active`),
    CONSTRAINT `fk_snr_notification_type` FOREIGN KEY (`notification_type_id`) REFERENCES `notification_types`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_snr_rule_type`         FOREIGN KEY (`rule_type_id`)         REFERENCES `condition_rule_types`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_snr_interlocutor`      FOREIGN KEY (`interlocutor_id`)      REFERENCES `interlocutors`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_snr_hierarchy_level`   FOREIGN KEY (`hierarchy_level_id`)   REFERENCES `hierarchy_levels`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_snr_created_by`        FOREIGN KEY (`created_by`)           REFERENCES `users`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Reglas de notificación programada — administrable desde Panel Admin, sin deploy de código';

-- -----------------------------------------------------------------------------
-- 5. Tabla central de notificaciones generadas
--    legal_entity_id referencia `interlocutors` (type='empresa'), NO una
--    tabla legal_entities — esa tabla no existe; las 7 entidades legales de
--    JOSEPAN son filas interlocutors.type='empresa' en la raíz de la
--    jerarquía owner_id. La resolución (qué empresa-raíz corresponde a un
--    interlocutor hijo) se hace en el API al crear la notificación.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `notifications` (
    `id`                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `notification_type_id`  INT UNSIGNED NOT NULL,
    `legal_entity_id`        INT UNSIGNED NULL COMMENT '→ interlocutors.id con type=empresa; NULL = corporativo a las 7 entidades (solo super-admin)',
    `interlocutor_id`        INT UNSIGNED NULL COMMENT 'NULL si aplica a toda la corporación',
    `target_role_id`         INT UNSIGNED NULL,
    `target_hierarchy_level` INT UNSIGNED NULL,
    `target_user_id`         INT UNSIGNED NULL,
    `created_by`             INT UNSIGNED NULL COMMENT 'NULL = generada por evento/cron; obligatorio en BROADCAST_INFO manual (validado en API)',
    `title`                  VARCHAR(150) NOT NULL,
    `message`                TEXT         NOT NULL,
    `reference_id`           BIGINT UNSIGNED NULL COMMENT 'ID opcional asociado: product_id, batch_id, transfer_id...',
    `metadata`               JSON NULL,
    `is_global`              TINYINT(1)   NOT NULL DEFAULT 0,
    `idempotency_key`        VARCHAR(120) NULL COMMENT 'Hash determinístico para evitar duplicados del motor programado',
    `created_at`             TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_notifications_idempotency` (`idempotency_key`),
    INDEX `idx_notifications_interlocutor` (`interlocutor_id`),
    INDEX `idx_notifications_created`      (`created_at` DESC),
    INDEX `idx_notifications_hierarchy`    (`target_hierarchy_level`),
    INDEX `idx_notifications_legal_entity` (`legal_entity_id`),
    CONSTRAINT `fk_notif_type`            FOREIGN KEY (`notification_type_id`)  REFERENCES `notification_types`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_notif_legal_entity`    FOREIGN KEY (`legal_entity_id`)       REFERENCES `interlocutors`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_notif_interlocutor`    FOREIGN KEY (`interlocutor_id`)       REFERENCES `interlocutors`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_notif_role`            FOREIGN KEY (`target_role_id`)        REFERENCES `roles`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_notif_hierarchy_level` FOREIGN KEY (`target_hierarchy_level`) REFERENCES `hierarchy_levels`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_notif_target_user`     FOREIGN KEY (`target_user_id`)        REFERENCES `users`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_notif_created_by`      FOREIGN KEY (`created_by`)            REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Tabla central de notificaciones — aislamiento por interlocutor y entidad legal, auditoría de origen';

-- -----------------------------------------------------------------------------
-- 6. Seguimiento de lectura por usuario
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `notification_recipients` (
    `notification_id` BIGINT UNSIGNED NOT NULL,
    `user_id`          INT UNSIGNED    NOT NULL,
    `is_read`          TINYINT(1)      NOT NULL DEFAULT 0,
    `read_at`          TIMESTAMP       NULL,
    PRIMARY KEY (`notification_id`, `user_id`),
    INDEX `idx_notif_recipients_status` (`user_id`, `is_read`),
    CONSTRAINT `fk_nr_notification` FOREIGN KEY (`notification_id`) REFERENCES `notifications`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_nr_user`         FOREIGN KEY (`user_id`)         REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Estado de lectura por usuario y notificación';

-- -----------------------------------------------------------------------------
-- 7. Bitácora de despacho multicanal (MVP: solo in_app)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `notification_dispatch_log` (
    `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `notification_id`  BIGINT UNSIGNED NOT NULL,
    `channel`          ENUM('in_app','telegram','whatsapp','email') NOT NULL DEFAULT 'in_app',
    `status`           ENUM('pending','sent','failed') NOT NULL DEFAULT 'sent',
    `dispatched_at`    TIMESTAMP NULL,
    `error_detail`     VARCHAR(255) NULL,
    PRIMARY KEY (`id`),
    INDEX `idx_dispatch_status` (`channel`, `status`),
    CONSTRAINT `fk_ndl_notification` FOREIGN KEY (`notification_id`) REFERENCES `notifications`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Bitácora de despacho por canal — extensible a Telegram/WhatsApp reusando adaptadores de [1006]';

-- -----------------------------------------------------------------------------
-- 8. Archivado para política de retención (90 días)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `notifications_archive` LIKE `notifications`;

-- -----------------------------------------------------------------------------
-- 9. Integración con el modelo RBAC de pantallas ya existente
--    (subsystem_screens / subsystem_screen_permissions), igual que 1002-1005.
-- -----------------------------------------------------------------------------
INSERT IGNORE INTO `subsystem_screens` (`subsystem`, `screen_key`, `label`, `sort_order`) VALUES
    ('1007', 'notifications_monitor', 'Monitoreo Centralizado de Notificaciones', 1),
    ('1007', 'notification_rules',    'Administración de Reglas Programadas',     2);

-- Permisos atómicos, mismo patrón resource.action del resto del ecosistema.
-- La concesión a roles concretos (role_permissions / subsystem_screen_permissions)
-- queda pendiente de que negocio confirme qué roles ven cada pantalla.
INSERT IGNORE INTO `permissions` (`resource`, `action`, `description`) VALUES
    ('notifications', 'read',  'Leer notificaciones propias (bandeja in-app)'),
    ('notifications', 'admin', 'Ver panel de monitoreo y administrar reglas programadas');

SET foreign_key_checks = 1;
