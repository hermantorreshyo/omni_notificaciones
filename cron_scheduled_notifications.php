<?php
/**
 * cron_scheduled_notifications.php — Módulo [1007], Fase 4
 *
 * Ejecutar cada minuto vía crontab (ver docs/DEPLOYMENT.md, sección Fase 4):
 *   * * * * * /usr/bin/php /var/www/omni_notificaciones/cron/cron_scheduled_notifications.php
 *
 * No requiere contexto de usuario — corre con credenciales de servicio.
 * Ajustar la ruta de configuración de conexión según la instalación real
 * (este archivo asume una constante DB_DSN/DB_USER/DB_PASS ya definida en
 * un config.php existente del proyecto, no incluido en este repo).
 */

declare(strict_types=1);

require_once __DIR__ . '/ScheduledRuleEngine.php';

// TODO integración: reemplazar por el mecanismo real de conexión del
// proyecto (ver nota "Database::getConnection()" en docs/INTEGRATION_FASE2.md).
$db = new PDO(
    getenv('OMNI_DB_DSN')  ?: 'mysql:host=127.0.0.1;dbname=omni;charset=utf8mb4',
    getenv('OMNI_DB_USER') ?: 'omni_cron',
    getenv('OMNI_DB_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

try {
    ScheduledRuleEngine::run($db);
} catch (Throwable $e) {
    // Nunca dejar que un error de una regla tumbe el cron completo para
    // el resto del minuto — se registra y se reintenta en la siguiente corrida.
    error_log('cron_scheduled_notifications.php error: ' . $e->getMessage());
    exit(1);
}
