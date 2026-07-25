<?php
/**
 * NotificationRulesController — Módulo [1007], Fase 6
 * OMNI API CORE v6.9 · JOSEPAN 360
 *
 * CRUD de scheduled_notification_rules para el panel admin. El admin SOLO
 * puede elegir un rule_type_id de los ya construidos por desarrollo — este
 * controller nunca acepta target_table/target_column desde el request (ver
 * docs/prompt_1007_notificaciones_alertas.md, sección de decisiones de
 * arquitectura, y el comentario de cabecera de ScheduledRuleEngine.php).
 *
 * Rutas sugeridas (mismo contrato de integración que NotificationController):
 *   GET    /api/v1/notifications/rules            → index()
 *   GET    /api/v1/notifications/rules/types       → ruleTypes() (para el dropdown)
 *   POST   /api/v1/notifications/rules             → store()
 *   PUT    /api/v1/notifications/rules/{id}        → update($id)
 *   DELETE /api/v1/notifications/rules/{id}        → destroy($id) — borrado lógico
 */

declare(strict_types=1);

final class NotificationRulesController
{
    private PDO $db;
    private array $auth;

    public function __construct(PDO $db, array $authContext)
    {
        $this->db = $db;
        $this->auth = $authContext;
    }

    /**
     * GET /api/v1/notifications/rules/form-options
     * Agrupa los 3 catálogos que necesita el formulario del admin en una
     * sola llamada (notification_types, hierarchy_levels, rule_types).
     */
    public function formOptions(): array
    {
        if (!$this->hasPermission('notifications.admin')) {
            return $this->error('ERR_RBAC', 'Permisos insuficientes. Requerido: notifications.admin', 403);
        }

        $notificationTypes = $this->db->query(
            "SELECT id, code, name FROM notification_types WHERE trigger_mode = 'scheduled' ORDER BY name"
        )->fetchAll(PDO::FETCH_ASSOC);

        $hierarchyLevels = $this->db->query(
            "SELECT id, code, name FROM hierarchy_levels ORDER BY rank_order"
        )->fetchAll(PDO::FETCH_ASSOC);

        $ruleTypes = $this->db->query(
            "SELECT id, code, name, description, requires_threshold FROM condition_rule_types ORDER BY name"
        )->fetchAll(PDO::FETCH_ASSOC);

        return $this->success([
            'notification_types' => $notificationTypes,
            'hierarchy_levels'    => $hierarchyLevels,
            'rule_types'          => $ruleTypes,
        ]);
    }

    /**
     * GET /api/v1/notifications/rules/types
     * Catálogo de patrones ya construidos, para el dropdown del formulario.
     * El admin nunca ve target_table/target_column — solo code/name/description.
     */
    public function ruleTypes(): array
    {
        if (!$this->hasPermission('notifications.admin')) {
            return $this->error('ERR_RBAC', 'Permisos insuficientes. Requerido: notifications.admin', 403);
        }

        $stmt = $this->db->query(
            "SELECT id, code, name, description, requires_threshold FROM condition_rule_types ORDER BY name"
        );
        return $this->success(['rule_types' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    /**
     * GET /api/v1/notifications/rules
     */
    public function index(): array
    {
        if (!$this->hasPermission('notifications.admin')) {
            return $this->error('ERR_RBAC', 'Permisos insuficientes. Requerido: notifications.admin', 403);
        }

        $stmt = $this->db->query(
            "SELECT r.id, r.name, nt.code AS notification_type, crt.code AS rule_type,
                    r.check_time, r.scope, r.interlocutor_id, i.commercial_name AS interlocutor_name,
                    r.hierarchy_level_id, hl.name AS hierarchy_level_name,
                    r.threshold_operator, r.threshold_value, r.active,
                    r.created_at, r.updated_at,
                    (SELECT COUNT(*) FROM notifications n2
                       WHERE n2.idempotency_key LIKE CONCAT('%', r.id, '%')
                         AND n2.created_at > NOW() - INTERVAL 30 DAY) AS notifications_last_30d
             FROM scheduled_notification_rules r
             INNER JOIN notification_types nt ON nt.id = r.notification_type_id
             INNER JOIN condition_rule_types crt ON crt.id = r.rule_type_id
             LEFT JOIN interlocutors i ON i.id = r.interlocutor_id
             LEFT JOIN hierarchy_levels hl ON hl.id = r.hierarchy_level_id
             WHERE r.deleted_at IS NULL
             ORDER BY r.created_at DESC"
        );

        return $this->success(['rules' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    /**
     * POST /api/v1/notifications/rules
     */
    public function store(array $body): array
    {
        if (!$this->hasPermission('notifications.admin')) {
            return $this->error('ERR_RBAC', 'Permisos insuficientes. Requerido: notifications.admin', 403);
        }

        $validationError = $this->validate($body);
        if ($validationError) {
            return $this->error('ERR_VALIDATION', $validationError, 400);
        }

        $stmt = $this->db->prepare(
            "INSERT INTO scheduled_notification_rules
                (name, notification_type_id, rule_type_id, check_time, scope,
                 interlocutor_id, hierarchy_level_id, threshold_operator, threshold_value,
                 active, created_by)
             VALUES
                (:name, :notification_type_id, :rule_type_id, :check_time, :scope,
                 :interlocutor_id, :hierarchy_level_id, :threshold_operator, :threshold_value,
                 1, :created_by)"
        );
        $stmt->execute([
            ':name'                  => $body['name'],
            ':notification_type_id'  => (int)$body['notification_type_id'],
            ':rule_type_id'          => (int)$body['rule_type_id'],
            ':check_time'            => $body['check_time'],
            ':scope'                 => $body['scope'],
            ':interlocutor_id'       => $body['interlocutor_id'] ?? null,
            ':hierarchy_level_id'    => $body['hierarchy_level_id'] ?? null,
            ':threshold_operator'    => $body['threshold_operator'] ?? null,
            ':threshold_value'       => $body['threshold_value'] ?? null,
            ':created_by'            => (int)$this->auth['user_id'],
        ]);

        return $this->success(['id' => (int)$this->db->lastInsertId()], 'Regla creada');
    }

    /**
     * PUT /api/v1/notifications/rules/{id}
     */
    public function update(int $id, array $body): array
    {
        if (!$this->hasPermission('notifications.admin')) {
            return $this->error('ERR_RBAC', 'Permisos insuficientes. Requerido: notifications.admin', 403);
        }

        $exists = $this->db->prepare("SELECT id FROM scheduled_notification_rules WHERE id = :id AND deleted_at IS NULL");
        $exists->execute([':id' => $id]);
        if (!$exists->fetch()) {
            return $this->error('ERR_NOT_FOUND', 'Regla no encontrada', 404);
        }

        $validationError = $this->validate($body);
        if ($validationError) {
            return $this->error('ERR_VALIDATION', $validationError, 400);
        }

        $stmt = $this->db->prepare(
            "UPDATE scheduled_notification_rules SET
                name = :name, notification_type_id = :notification_type_id,
                rule_type_id = :rule_type_id, check_time = :check_time, scope = :scope,
                interlocutor_id = :interlocutor_id, hierarchy_level_id = :hierarchy_level_id,
                threshold_operator = :threshold_operator, threshold_value = :threshold_value,
                active = :active
             WHERE id = :id"
        );
        $stmt->execute([
            ':name'                 => $body['name'],
            ':notification_type_id' => (int)$body['notification_type_id'],
            ':rule_type_id'         => (int)$body['rule_type_id'],
            ':check_time'           => $body['check_time'],
            ':scope'                => $body['scope'],
            ':interlocutor_id'      => $body['interlocutor_id'] ?? null,
            ':hierarchy_level_id'   => $body['hierarchy_level_id'] ?? null,
            ':threshold_operator'   => $body['threshold_operator'] ?? null,
            ':threshold_value'      => $body['threshold_value'] ?? null,
            ':active'               => !empty($body['active']) ? 1 : 0,
            ':id'                   => $id,
        ]);

        return $this->success(['id' => $id], 'Regla actualizada');
    }

    /**
     * DELETE /api/v1/notifications/rules/{id} — borrado lógico (deleted_at),
     * mismo patrón que routes_catalog ya existente en OMNI.
     */
    public function destroy(int $id): array
    {
        if (!$this->hasPermission('notifications.admin')) {
            return $this->error('ERR_RBAC', 'Permisos insuficientes. Requerido: notifications.admin', 403);
        }

        $stmt = $this->db->prepare(
            "UPDATE scheduled_notification_rules SET deleted_at = NOW(), active = 0 WHERE id = :id AND deleted_at IS NULL"
        );
        $stmt->execute([':id' => $id]);

        if ($stmt->rowCount() === 0) {
            return $this->error('ERR_NOT_FOUND', 'Regla no encontrada o ya eliminada', 404);
        }

        return $this->success(['id' => $id], 'Regla eliminada');
    }

    /**
     * Validaciones de negocio — nunca acepta target_table/target_column.
     */
    private function validate(array $body): ?string
    {
        foreach (['name', 'notification_type_id', 'rule_type_id', 'check_time', 'scope'] as $field) {
            if (empty($body[$field])) {
                return "Campo obligatorio: {$field}";
            }
        }

        if (!in_array($body['scope'], ['all_pos', 'specific_interlocutor', 'hierarchy_level'], true)) {
            return "scope inválido: debe ser all_pos, specific_interlocutor o hierarchy_level";
        }
        if ($body['scope'] === 'specific_interlocutor' && empty($body['interlocutor_id'])) {
            return "interlocutor_id es obligatorio cuando scope = specific_interlocutor";
        }
        if ($body['scope'] === 'hierarchy_level' && empty($body['hierarchy_level_id'])) {
            return "hierarchy_level_id es obligatorio cuando scope = hierarchy_level";
        }

        // Confirmar que el rule_type_id enviado existe en el catálogo técnico
        // real — nunca se acepta table/column del body, solo el ID de referencia.
        $ruleTypeStmt = $this->db->prepare("SELECT requires_threshold FROM condition_rule_types WHERE id = :id");
        $ruleTypeStmt->execute([':id' => (int)$body['rule_type_id']]);
        $ruleType = $ruleTypeStmt->fetch(PDO::FETCH_ASSOC);
        if (!$ruleType) {
            return "rule_type_id no existe en el catálogo técnico";
        }
        if ($ruleType['requires_threshold'] && empty($body['threshold_operator'])) {
            return "Este tipo de regla requiere threshold_operator y threshold_value";
        }

        if (!preg_match('/^\d{2}:\d{2}:\d{2}$/', $body['check_time'])) {
            return "check_time debe tener formato HH:MM:SS";
        }

        return null;
    }

    private function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->auth['permissions'] ?? [], true)
            || in_array('system.admin', $this->auth['permissions'] ?? [], true);
    }

    private function success(array $data, string $message = ''): array
    {
        return ['status' => 'success', 'data' => $data, 'message' => $message];
    }

    private function error(string $code, string $message, int $httpStatus): array
    {
        http_response_code($httpStatus);
        return ['status' => 'error', 'error_code' => $code, 'message' => $message];
    }
}
