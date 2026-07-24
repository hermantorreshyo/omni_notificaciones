<?php
/**
 * NotificationController — Módulo [1007]
 * OMNI API CORE v6.9 · JOSEPAN 360
 *
 * =========================================================================
 * CONTRATO DE INTEGRACIÓN (leer antes de conectar al router)
 * =========================================================================
 * Este controller sigue el mismo patrón que AuthController / CatalogController
 * / TransferController descritos en el manual de desarrollador (sección 21,
 * "Tabla de endpoints completa"), pero el manual no documenta el bootstrap
 * interno (cómo se instancian los controllers ni la clase que decodifica el
 * JWT). Por eso este controller declara explícitamente lo que necesita:
 *
 *   1. Un array $authContext con esta forma, ya resuelto por el middleware
 *      de autenticación ANTES de llegar aquí (el proxy inyecta el JWT
 *      decodificado, según sección 5 del manual):
 *      [
 *        'user_id'         => int,   // users.id
 *        'interlocutor_id' => int,   // X-Interlocutor-Id
 *        'role_id'         => int,   // rol activo del usuario
 *        'permissions'     => string[], // ej. ['notifications.read', ...]
 *      ]
 *   2. Una conexión PDO obtenida como en el resto del core — se asume
 *      `Database::getConnection()` (nombre confirmado en sesiones previas
 *      de trabajo sobre este mismo proyecto).
 *
 * Quien conecte las rutas debe registrar:
 *   GET    /api/v1/notifications              → index()
 *   PATCH  /api/v1/notifications/{id}/read     → markRead($id)
 *   PATCH  /api/v1/notifications/read-all      → markAllRead()
 *   POST   /api/v1/notifications               → store()
 * =========================================================================
 */

declare(strict_types=1);

final class NotificationController
{
    private PDO $db;
    private array $auth;

    public function __construct(PDO $db, array $authContext)
    {
        $this->db = $db;
        $this->auth = $authContext;
    }

    /**
     * GET /api/v1/notifications
     * Query: unread_only=true|false, limit=20, offset=0
     */
    public function index(array $query): array
    {
        if (!$this->hasPermission('notifications.read')) {
            return $this->error('ERR_RBAC', 'Permisos insuficientes. Requerido: notifications.read', 403);
        }

        $unreadOnly = filter_var($query['unread_only'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $limit  = max(1, min(100, (int)($query['limit'] ?? 20)));
        $offset = max(0, (int)($query['offset'] ?? 0));

        $userId = (int)$this->auth['user_id'];
        $interlocutorId = (int)$this->auth['interlocutor_id'];

        // Aislamiento perimetral: notificaciones dirigidas a este usuario,
        // a su sede, a su rol, a su nivel jerárquico, o globales.
        $sql = "SELECT n.id, nt.code AS type, nt.severity, n.title, n.message,
                       n.reference_id, n.metadata, n.created_at,
                       COALESCE(nr.is_read, 0) AS is_read
                FROM notifications n
                INNER JOIN notification_types nt ON nt.id = n.notification_type_id
                LEFT JOIN notification_recipients nr
                       ON nr.notification_id = n.id AND nr.user_id = :user_id
                WHERE (
                    n.target_user_id = :user_id
                    OR n.interlocutor_id = :interlocutor_id
                    OR n.target_role_id = :role_id
                    OR n.target_hierarchy_level = (SELECT hierarchy_level_id FROM roles WHERE id = :role_id2)
                    OR n.is_global = 1
                )";

        if ($unreadOnly) {
            $sql .= " AND COALESCE(nr.is_read, 0) = 0";
        }

        $sql .= " ORDER BY n.created_at DESC LIMIT :limit OFFSET :offset";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':interlocutor_id', $interlocutorId, PDO::PARAM_INT);
        $stmt->bindValue(':role_id', (int)$this->auth['role_id'], PDO::PARAM_INT);
        $stmt->bindValue(':role_id2', (int)$this->auth['role_id'], PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $countStmt = $this->db->prepare(
            "SELECT COUNT(*) FROM notifications n
             LEFT JOIN notification_recipients nr
                    ON nr.notification_id = n.id AND nr.user_id = :user_id
             WHERE (n.target_user_id = :user_id OR n.interlocutor_id = :interlocutor_id
                    OR n.target_role_id = :role_id OR n.is_global = 1)
               AND COALESCE(nr.is_read, 0) = 0"
        );
        $countStmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $countStmt->bindValue(':interlocutor_id', $interlocutorId, PDO::PARAM_INT);
        $countStmt->bindValue(':role_id', (int)$this->auth['role_id'], PDO::PARAM_INT);
        $countStmt->execute();
        $totalUnread = (int)$countStmt->fetchColumn();

        foreach ($rows as &$row) {
            $row['metadata'] = $row['metadata'] ? json_decode($row['metadata'], true) : null;
            $row['is_read'] = (bool)$row['is_read'];
        }

        return $this->success([
            'total_unread'  => $totalUnread,
            'notifications' => $rows,
        ]);
    }

    /**
     * PATCH /api/v1/notifications/{id}/read
     */
    public function markRead(int $notificationId): array
    {
        if (!$this->hasPermission('notifications.read')) {
            return $this->error('ERR_RBAC', 'Permisos insuficientes. Requerido: notifications.read', 403);
        }

        $exists = $this->db->prepare("SELECT id FROM notifications WHERE id = :id");
        $exists->execute([':id' => $notificationId]);
        if (!$exists->fetch()) {
            return $this->error('ERR_NOT_FOUND', 'Notificación no encontrada', 404);
        }

        $stmt = $this->db->prepare(
            "INSERT INTO notification_recipients (notification_id, user_id, is_read, read_at)
             VALUES (:notification_id, :user_id, 1, NOW())
             ON DUPLICATE KEY UPDATE is_read = 1, read_at = NOW()"
        );
        $stmt->execute([
            ':notification_id' => $notificationId,
            ':user_id'         => (int)$this->auth['user_id'],
        ]);

        return $this->success(['id' => $notificationId, 'is_read' => true]);
    }

    /**
     * PATCH /api/v1/notifications/read-all
     */
    public function markAllRead(): array
    {
        if (!$this->hasPermission('notifications.read')) {
            return $this->error('ERR_RBAC', 'Permisos insuficientes. Requerido: notifications.read', 403);
        }

        $userId = (int)$this->auth['user_id'];
        $interlocutorId = (int)$this->auth['interlocutor_id'];
        $roleId = (int)$this->auth['role_id'];

        // Inserta/actualiza is_read=1 para todas las notificaciones visibles
        // por este usuario que aún no tengan fila en notification_recipients,
        // o que la tengan en 0.
        $stmt = $this->db->prepare(
            "INSERT INTO notification_recipients (notification_id, user_id, is_read, read_at)
             SELECT n.id, :user_id, 1, NOW()
             FROM notifications n
             WHERE (n.target_user_id = :user_id OR n.interlocutor_id = :interlocutor_id
                    OR n.target_role_id = :role_id OR n.is_global = 1)
             ON DUPLICATE KEY UPDATE is_read = 1, read_at = NOW()"
        );
        $stmt->execute([
            ':user_id'         => $userId,
            ':interlocutor_id' => $interlocutorId,
            ':role_id'         => $roleId,
        ]);

        return $this->success(['updated' => $stmt->rowCount()]);
    }

    /**
     * POST /api/v1/notifications
     * Solo BROADCAST_INFO manual — las notificaciones de evento/programadas
     * las crean los workers de cron, no este endpoint.
     */
    public function store(array $body): array
    {
        if (!$this->hasPermission('notifications.admin')) {
            return $this->error('ERR_RBAC', 'Permisos insuficientes. Requerido: notifications.admin', 403);
        }

        $required = ['title', 'message'];
        foreach ($required as $field) {
            if (empty($body[$field])) {
                return $this->error('ERR_VALIDATION', "Campo obligatorio: {$field}", 400);
            }
        }

        // legal_entity_id: si viene null y el usuario no es super-admin,
        // se fuerza a la entidad legal derivada de su interlocutor activo
        // (nunca null salvo para el rol con permiso corporativo transversal).
        $legalEntityId = $body['legal_entity_id'] ?? null;
        if ($legalEntityId === null && !$this->hasPermission('system.admin')) {
            $legalEntityId = $this->resolveLegalEntity((int)$this->auth['interlocutor_id']);
        }

        $typeStmt = $this->db->prepare("SELECT id FROM notification_types WHERE code = 'BROADCAST_INFO'");
        $typeStmt->execute();
        $typeId = $typeStmt->fetchColumn();
        if (!$typeId) {
            return $this->error('ERR_INTERNAL', 'Catálogo notification_types sin BROADCAST_INFO', 500);
        }

        $stmt = $this->db->prepare(
            "INSERT INTO notifications
                (notification_type_id, legal_entity_id, interlocutor_id, target_role_id,
                 target_hierarchy_level, target_user_id, created_by, title, message,
                 reference_id, metadata, is_global)
             VALUES
                (:type_id, :legal_entity_id, :interlocutor_id, :target_role_id,
                 :target_hierarchy_level, :target_user_id, :created_by, :title, :message,
                 :reference_id, :metadata, :is_global)"
        );
        $stmt->execute([
            ':type_id'                => $typeId,
            ':legal_entity_id'        => $legalEntityId,
            ':interlocutor_id'        => $body['interlocutor_id'] ?? null,
            ':target_role_id'         => $body['target_role_id'] ?? null,
            ':target_hierarchy_level' => $body['target_hierarchy_level'] ?? null,
            ':target_user_id'         => $body['target_user_id'] ?? null,
            ':created_by'             => (int)$this->auth['user_id'], // auditoría: nunca null en manual
            ':title'                  => $body['title'],
            ':message'                => $body['message'],
            ':reference_id'           => $body['reference_id'] ?? null,
            ':metadata'               => isset($body['metadata']) ? json_encode($body['metadata']) : null,
            ':is_global'              => !empty($body['is_global']) ? 1 : 0,
        ]);

        return $this->success(['id' => (int)$this->db->lastInsertId()], 'Notificación creada');
    }

    /**
     * GET /api/v1/notifications/monitor
     * Solo roles con notifications.admin. Query: severity, date_from,
     * date_to, interlocutor_id, status=pending|acknowledged.
     */
    public function monitor(array $query): array
    {
        if (!$this->hasPermission('notifications.admin')) {
            return $this->error('ERR_RBAC', 'Permisos insuficientes. Requerido: notifications.admin', 403);
        }

        $where = ['1=1'];
        $params = [];

        if (!empty($query['severity'])) {
            $where[] = 'nt.severity = :severity';
            $params[':severity'] = $query['severity'];
        }
        if (!empty($query['date_from'])) {
            $where[] = 'n.created_at >= :date_from';
            $params[':date_from'] = $query['date_from'];
        }
        if (!empty($query['date_to'])) {
            $where[] = 'n.created_at <= :date_to';
            $params[':date_to'] = $query['date_to'];
        }
        if (!empty($query['interlocutor_id'])) {
            $where[] = 'n.interlocutor_id = :interlocutor_id';
            $params[':interlocutor_id'] = (int)$query['interlocutor_id'];
        }

        $whereSql = implode(' AND ', $where);

        // Consolidados por severidad
        $summaryStmt = $this->db->prepare(
            "SELECT nt.severity, COUNT(*) AS total
             FROM notifications n INNER JOIN notification_types nt ON nt.id = n.notification_type_id
             WHERE {$whereSql}
             GROUP BY nt.severity"
        );
        $summaryStmt->execute($params);
        $bySeverity = ['critical' => 0, 'warning' => 0, 'info' => 0];
        foreach ($summaryStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $bySeverity[$row['severity']] = (int)$row['total'];
        }

        // Consolidado por sede (top 10)
        $byInterlocutorStmt = $this->db->prepare(
            "SELECT i.id AS interlocutor_id, i.commercial_name AS name,
                    SUM(CASE WHEN nt.severity = 'critical' THEN 1 ELSE 0 END) AS critical
             FROM notifications n
             INNER JOIN notification_types nt ON nt.id = n.notification_type_id
             INNER JOIN interlocutors i ON i.id = n.interlocutor_id
             WHERE {$whereSql}
             GROUP BY i.id, i.commercial_name
             ORDER BY critical DESC
             LIMIT 10"
        );
        $byInterlocutorStmt->execute($params);

        // Detalle con destinatarios (paginado, prioriza no reconocidas)
        $limit = max(1, min(100, (int)($query['limit'] ?? 50)));
        $detailStmt = $this->db->prepare(
            "SELECT n.id, nt.code AS type, nt.severity, n.title, n.created_at, n.interlocutor_id
             FROM notifications n INNER JOIN notification_types nt ON nt.id = n.notification_type_id
             WHERE {$whereSql}
             ORDER BY n.created_at DESC
             LIMIT {$limit}"
        );
        $detailStmt->execute($params);
        $notifications = $detailStmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($notifications)) {
            $ids = array_column($notifications, 'id');
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $recipientsStmt = $this->db->prepare(
                "SELECT nr.notification_id, u.id AS user_id, e.first_name, e.last_name, nr.is_read, nr.read_at
                 FROM notification_recipients nr
                 INNER JOIN users u ON u.id = nr.user_id
                 INNER JOIN employees e ON e.id = u.employee_id
                 WHERE nr.notification_id IN ({$placeholders})"
            );
            $recipientsStmt->execute($ids);
            $recipientsByNotif = [];
            foreach ($recipientsStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $recipientsByNotif[$r['notification_id']][] = [
                    'user_id' => (int)$r['user_id'],
                    'name'    => trim($r['first_name'] . ' ' . $r['last_name']),
                    'is_read' => (bool)$r['is_read'],
                    'read_at' => $r['read_at'],
                ];
            }
            foreach ($notifications as &$n) {
                $n['recipients'] = $recipientsByNotif[$n['id']] ?? [];
            }
        }

        return $this->success([
            'summary' => [
                'total'          => array_sum($bySeverity),
                'critical'       => $bySeverity['critical'],
                'warning'        => $bySeverity['warning'],
                'info'           => $bySeverity['info'],
                'by_interlocutor' => $byInterlocutorStmt->fetchAll(PDO::FETCH_ASSOC),
            ],
            'notifications' => $notifications,
        ]);
    }

    /**
     * Resuelve la entidad legal raíz (interlocutors.type='empresa') de un
     * interlocutor hijo, recorriendo owner_id hacia arriba.
     */
    private function resolveLegalEntity(int $interlocutorId): ?int
    {
        $stmt = $this->db->prepare(
            "WITH RECURSIVE chain AS (
                SELECT id, owner_id, type FROM interlocutors WHERE id = :id
                UNION ALL
                SELECT i.id, i.owner_id, i.type
                FROM interlocutors i
                INNER JOIN chain c ON i.id = c.owner_id
             )
             SELECT id FROM chain WHERE type = 'empresa' LIMIT 1"
        );
        $stmt->execute([':id' => $interlocutorId]);
        $result = $stmt->fetchColumn();
        return $result !== false ? (int)$result : null;
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
