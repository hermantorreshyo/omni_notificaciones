<?php
/**
 * ScheduledRuleEngine — Módulo [1007], Fase 4 (motor programado por hora)
 * OMNI API CORE v6.9 · JOSEPAN 360
 *
 * =========================================================================
 * PRINCIPIO DE SEGURIDAD (no negociable — ver docs/prompt_1007_notificaciones_alertas.md)
 * =========================================================================
 * Los identificadores de tabla/columna SOLO se leen de `condition_rule_types`
 * (poblada exclusivamente por migración de desarrollo). NUNCA se toman de
 * `scheduled_notification_rules` (editable por el admin) ni de ningún input
 * externo. El admin configura valores (hora, alcance, umbral) — nunca
 * identificadores de base de datos. Este motor no debe modificarse para
 * aceptar target_table/target_column desde una fuente editable por usuario.
 * =========================================================================
 *
 * CONTRATO DE INTEGRACIÓN: este archivo se invoca desde un cron de sistema
 * cada minuto (ver crontab sugerido en docs/DEPLOYMENT.md, sección Fase 4).
 * No requiere contexto de usuario autenticado — corre con las credenciales
 * de servicio de la BD.
 */

declare(strict_types=1);

final class ScheduledRuleEngine
{
    /** Tablas permitidas para el patrón NO_RECORD_BY_TIME — defensa en profundidad:
     *  aunque target_table ya viene de una tabla dev-owned, esta whitelist
     *  es una segunda barrera si alguna vez se relaja ese control. */
    private const ALLOWED_TABLES = ['transfers'];

    public static function run(PDO $db): void
    {
        $stmt = $db->prepare(
            "SELECT r.*, crt.code AS rule_type_code, crt.target_table, crt.target_date_column,
                    crt.target_scope_column, nt.code AS notification_type_code
             FROM scheduled_notification_rules r
             INNER JOIN condition_rule_types crt ON crt.id = r.rule_type_id
             INNER JOIN notification_types nt ON nt.id = r.notification_type_id
             WHERE r.active = 1 AND r.deleted_at IS NULL
               AND r.check_time = TIME_FORMAT(NOW(), '%H:%i:00')"
        );
        $stmt->execute();

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $rule) {
            self::executeRule($db, $rule);
        }
    }

    private static function executeRule(PDO $db, array $rule): void
    {
        switch ($rule['rule_type_code']) {
            case 'NO_RECORD_BY_TIME':
                self::runNoRecordByTime($db, $rule);
                break;
            default:
                // THRESHOLD_NOT_MET / FIELD_STATUS_PENDING: sin fila seed todavía
                // (ver Fase 1) — el motor no falla, solo omite lo que no reconoce.
                error_log("ScheduledRuleEngine: rule_type '{$rule['rule_type_code']}' aún no implementado (rule id {$rule['id']})");
                break;
        }
    }

    private static function runNoRecordByTime(PDO $db, array $rule): void
    {
        $table  = $rule['target_table'];
        $dateCol = $rule['target_date_column'];
        $scopeCol = $rule['target_scope_column'];

        if (!in_array($table, self::ALLOWED_TABLES, true)) {
            error_log("ScheduledRuleEngine: tabla '{$table}' no está en la whitelist — regla {$rule['id']} omitida");
            return;
        }

        // Identificadores validados contra whitelist — se interpolan (no se
        // pueden bindear como parámetro), pero solo después de esa validación.
        $checkStmt = $db->prepare(
            "SELECT COUNT(*) FROM `{$table}` WHERE `{$scopeCol}` = :interlocutor_id AND DATE(`{$dateCol}`) = CURDATE()"
        );

        $interlocutorIds = self::resolveInterlocutorsToCheck($db, $rule);
        $failing = [];

        foreach ($interlocutorIds as $interlocutorId) {
            $checkStmt->execute([':interlocutor_id' => $interlocutorId]);
            if ((int)$checkStmt->fetchColumn() === 0) {
                $failing[] = $interlocutorId;
            }
        }

        if (empty($failing)) {
            return;
        }

        if ($rule['scope'] === 'hierarchy_level') {
            self::emitAggregated($db, $rule, $failing);
        } else {
            foreach ($failing as $interlocutorId) {
                self::emitPerInterlocutor($db, $rule, $interlocutorId);
            }
        }
    }

    /**
     * Determina qué interlocutores evaluar según el scope de la regla.
     */
    private static function resolveInterlocutorsToCheck(PDO $db, array $rule): array
    {
        if ($rule['scope'] === 'specific_interlocutor') {
            return [(int)$rule['interlocutor_id']];
        }

        // all_pos e hierarchy_level evalúan todos los puntos de venta activos
        $stmt = $db->prepare("SELECT id FROM interlocutors WHERE type = 'punto_venta' AND status = 'active'");
        $stmt->execute();
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * scope = specific_interlocutor | all_pos: una notificación por sede
     * que falló, dirigida a esa misma sede. Idempotente por regla+sede+día.
     */
    private static function emitPerInterlocutor(PDO $db, array $rule, int $interlocutorId): void
    {
        $idempotencyKey = hash('sha256', "rule:{$rule['id']}|interlocutor:{$interlocutorId}|" . date('Y-m-d'));

        $stmt = $db->prepare(
            "INSERT IGNORE INTO notifications
                (notification_type_id, interlocutor_id, title, message, reference_id, idempotency_key)
             VALUES (:type_id, :interlocutor_id, :title, :message, :reference_id, :idempotency_key)"
        );
        $stmt->execute([
            ':type_id'         => $rule['notification_type_id'],
            ':interlocutor_id' => $interlocutorId,
            ':title'           => $rule['name'],
            ':message'         => "La sede no tiene registro en `{$rule['target_table']}` para hoy antes de las {$rule['check_time']}.",
            ':reference_id'    => $interlocutorId,
            ':idempotency_key' => $idempotencyKey,
        ]);
    }

    /**
     * scope = hierarchy_level: UNA notificación consolidada con el listado
     * de sedes que fallaron, dirigida al nivel jerárquico configurado (ej.
     * Mandos Medios), no a cada sede individualmente. Idempotente por
     * regla+nivel+día — sin importar cuántas sedes fallen, es una sola alerta.
     */
    private static function emitAggregated(PDO $db, array $rule, array $failingInterlocutorIds): void
    {
        $names = self::getInterlocutorNames($db, $failingInterlocutorIds);
        $idempotencyKey = hash('sha256', "rule:{$rule['id']}|level:{$rule['hierarchy_level_id']}|" . date('Y-m-d'));

        $stmt = $db->prepare(
            "INSERT IGNORE INTO notifications
                (notification_type_id, target_hierarchy_level, title, message, idempotency_key)
             VALUES (:type_id, :hierarchy_level_id, :title, :message, :idempotency_key)"
        );
        $stmt->execute([
            ':type_id'            => $rule['notification_type_id'],
            ':hierarchy_level_id' => $rule['hierarchy_level_id'],
            ':title'              => $rule['name'],
            ':message'            => count($failingInterlocutorIds) . ' sede(s) sin registro antes de las '
                . $rule['check_time'] . ': ' . implode(', ', $names),
            ':idempotency_key'    => $idempotencyKey,
        ]);
    }

    private static function getInterlocutorNames(PDO $db, array $ids): array
    {
        if (empty($ids)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("SELECT commercial_name FROM interlocutors WHERE id IN ({$placeholders})");
        $stmt->execute($ids);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
}
