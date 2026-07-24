-- =====================================================================
-- MIGRACIÓN 1007: Subsistema de Notificaciones y Alertas Centralizadas
-- OMNI API CORE · JOSEPAN 360
--
-- Convención del proyecto: script sin numerar (migration_*.sql), aplicado
-- sobre una instalación OMNI ya existente en producción. Idempotente:
-- puede correrse más de una vez sin romper (CREATE TABLE IF NOT EXISTS +
-- INSERT IGNORE en los seeds).
--
-- Prerrequisitos: deben existir ya en la BD las tablas interlocutors,
-- roles, users, legal_entities (del core OMNI). Este script no las toca.
-- =====================================================================

START TRANSACTION;

-- ---------------------------------------------------------------------
-- 1. Catálogo de niveles jerárquicos (independiente de roles puntuales)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS hierarchy_levels (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(30) NOT NULL UNIQUE,
    name VARCHAR(60) NOT NULL,
    rank_order TINYINT NOT NULL,
    created_at TIMESTAMP(3) DEFAULT CURRENT_TIMESTAMP(3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO hierarchy_levels (code, name, rank_order) VALUES
    ('OPERATIVO', 'Operativo', 1),
    ('MANDO_MEDIO', 'Mando Medio', 2),
    ('DIRECCION', 'Dirección', 3);

-- ---------------------------------------------------------------------
-- 2. Catálogo de tipos de notificación
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS notification_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    severity ENUM('info', 'warning', 'critical') DEFAULT 'info',
    trigger_mode ENUM('event', 'scheduled') NOT NULL DEFAULT 'event',
    description TEXT NULL,
    created_at TIMESTAMP(3) DEFAULT CURRENT_TIMESTAMP(3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO notification_types (code, name, severity, trigger_mode, description) VALUES
    ('STOCK_OUT', 'Stock Agotado', 'critical', 'event', 'El stock de un producto llegó a 0 unidades base.'),
    ('STOCK_MIN', 'Stock Mínimo Alcanzado', 'warning', 'event', 'El stock cruzó a la baja el umbral mínimo configurado.'),
    ('EXPIRING_SOON', 'Próximo a Vencer', 'warning', 'event', 'Un lote entra en ventana de alerta temprana FEFO.'),
    ('BROADCAST_INFO', 'Comunicado Corporativo', 'info', 'event', 'Mensaje informativo inyectado manualmente desde administración.'),
    ('TRANSFER_NOT_REGISTERED', 'Traspaso No Registrado', 'critical', 'scheduled', 'La sede no registró traspaso de insumos antes de la hora límite configurada.');

-- ---------------------------------------------------------------------
-- 3. Catálogo TÉCNICO de patrones de condición programada
--    Poblado únicamente por migración de desarrollo. Contiene los
--    identificadores de tabla/columna FIJOS — nunca expuestos en un
--    formulario de administración (ver nota de arquitectura en docs/).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS condition_rule_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(40) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    description TEXT NULL,
    target_table VARCHAR(64) NOT NULL,
    target_date_column VARCHAR(64) NOT NULL,
    target_scope_column VARCHAR(64) NULL,
    requires_threshold BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP(3) DEFAULT CURRENT_TIMESTAMP(3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Nota: 'daily_transfers' se asume como la tabla que respalda el módulo
-- "Traspasos Diarios" ya existente en TIENDAS (admin). Si el nombre real
-- de la tabla/columna difiere, este seed debe ajustarse antes de aplicar
-- el script — ver punto de verificación en docs/CHECKLIST_FASE1.md.
INSERT IGNORE INTO condition_rule_types
    (code, name, description, target_table, target_date_column, target_scope_column, requires_threshold)
VALUES
    ('NO_RECORD_BY_TIME', 'Ausencia de registro a una hora límite',
     'Verifica si NO existe una fila para el interlocutor en la fecha de hoy.',
     'daily_transfers', 'transfer_date', 'interlocutor_id', FALSE),
    ('THRESHOLD_NOT_MET', 'Umbral numérico no alcanzado a una hora',
     'Compara un valor agregado contra un umbral configurado en la instancia.',
     'daily_transfers', 'transfer_date', 'interlocutor_id', TRUE),
    ('FIELD_STATUS_PENDING', 'Columna de estado sigue pendiente',
     'Verifica si una columna de estado permanece en un valor no-completado.',
     'daily_transfers', 'transfer_date', 'interlocutor_id', FALSE);

-- ---------------------------------------------------------------------
-- 4. Catálogo de NEGOCIO: instancias de reglas programadas (admin-owned)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS scheduled_notification_rules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    notification_type_id INT NOT NULL,
    rule_type_id INT NOT NULL,
    check_time TIME NOT NULL,
    scope ENUM('all_pos', 'specific_interlocutor', 'hierarchy_level') NOT NULL DEFAULT 'all_pos',
    interlocutor_id BIGINT NULL,
    target_hierarchy_level INT NULL,
    threshold_operator ENUM('<', '<=', '>', '>=', '=') NULL,
    threshold_value DECIMAL(14,4) NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_by BIGINT NOT NULL,
    created_at TIMESTAMP(3) DEFAULT CURRENT_TIMESTAMP(3),
    updated_at TIMESTAMP(3) DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    FOREIGN KEY (notification_type_id) REFERENCES notification_types(id) ON DELETE RESTRICT,
    FOREIGN KEY (rule_type_id) REFERENCES condition_rule_types(id) ON DELETE RESTRICT,
    FOREIGN KEY (interlocutor_id) REFERENCES interlocutors(id) ON DELETE CASCADE,
    FOREIGN KEY (target_hierarchy_level) REFERENCES hierarchy_levels(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_scheduled_time (check_time, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 5. Tabla central de notificaciones generadas
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS notifications (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    notification_type_id INT NOT NULL,
    legal_entity_id BIGINT NULL,
    interlocutor_id BIGINT NULL,
    target_role_id INT NULL,
    target_hierarchy_level INT NULL,
    target_user_id BIGINT NULL,
    created_by BIGINT NULL,
    title VARCHAR(150) NOT NULL,
    message TEXT NOT NULL,
    reference_id BIGINT NULL,
    metadata JSON NULL,
    is_global BOOLEAN DEFAULT FALSE,
    idempotency_key VARCHAR(120) NULL,
    created_at TIMESTAMP(3) DEFAULT CURRENT_TIMESTAMP(3),
    FOREIGN KEY (notification_type_id) REFERENCES notification_types(id) ON DELETE RESTRICT,
    FOREIGN KEY (legal_entity_id) REFERENCES legal_entities(id) ON DELETE CASCADE,
    FOREIGN KEY (interlocutor_id) REFERENCES interlocutors(id) ON DELETE CASCADE,
    FOREIGN KEY (target_role_id) REFERENCES roles(id) ON DELETE CASCADE,
    FOREIGN KEY (target_hierarchy_level) REFERENCES hierarchy_levels(id) ON DELETE SET NULL,
    FOREIGN KEY (target_user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY uq_notif_idempotency (idempotency_key),
    INDEX idx_notif_interlocutor (interlocutor_id),
    INDEX idx_notif_created (created_at DESC),
    INDEX idx_notif_hierarchy (target_hierarchy_level),
    INDEX idx_notif_legal_entity (legal_entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 6. Seguimiento de lectura por usuario
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS notification_recipients (
    notification_id BIGINT NOT NULL,
    user_id BIGINT NOT NULL,
    is_read BOOLEAN DEFAULT FALSE,
    read_at TIMESTAMP(3) NULL,
    PRIMARY KEY (notification_id, user_id),
    FOREIGN KEY (notification_id) REFERENCES notifications(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_recipients_status (user_id, is_read)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 7. Bitácora de despacho multicanal (MVP: solo in_app)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS notification_dispatch_log (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    notification_id BIGINT NOT NULL,
    channel ENUM('in_app', 'telegram', 'whatsapp', 'email') NOT NULL DEFAULT 'in_app',
    status ENUM('pending', 'sent', 'failed') NOT NULL DEFAULT 'sent',
    dispatched_at TIMESTAMP(3) NULL,
    error_detail VARCHAR(255) NULL,
    FOREIGN KEY (notification_id) REFERENCES notifications(id) ON DELETE CASCADE,
    INDEX idx_dispatch_status (channel, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 8. Archivado para política de retención (90 días)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS notifications_archive LIKE notifications;

COMMIT;
