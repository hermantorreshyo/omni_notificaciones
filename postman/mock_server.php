<?php
/**
 * mock_server.php — Servidor de prueba local para validar la collection de
 * Postman de extremo a extremo. Reproduce el CONTRATO de respuesta exacto
 * de NotificationController/NotificationRulesController, sin lógica real de
 * negocio ni base de datos — solo para confirmar que la collection envía y
 * espera lo correcto.
 *
 * Uso: php -S 127.0.0.1:8090 mock_server.php
 */

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$body = json_decode(file_get_contents('php://input'), true) ?? [];
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$isNoAdminToken = strpos($authHeader, 'NOADMIN') !== false;

function respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

// --- Autenticación ---
if ($uri === '/api/v1/auth/login' && $method === 'POST') {
    $isNoAdmin = ($body['username'] ?? '') === 'no_admin_user' || ($body['password'] ?? '') === 'no_admin_pass';
    if (($body['interlocutor_id'] ?? null) == 0) {
        respond(['status' => 'success', 'data' => ['available_interlocutors' => [['id' => 1, 'name' => 'Tienda Mock']]]]);
    }
    $token = $isNoAdmin ? 'FAKE.NOADMIN.TOKEN' : 'FAKE.ADMIN.TOKEN';
    respond(['status' => 'success', 'data' => ['token' => $token]]);
}
if ($uri === '/api/v1/auth/refresh' && $method === 'POST') {
    respond(['status' => 'success', 'data' => ['token' => 'FAKE.ADMIN.TOKEN.REFRESHED']]);
}
if ($uri === '/api/v1/auth/me' && $method === 'GET') {
    respond(['status' => 'success', 'data' => ['username' => 'mock_user', 'role' => 'SuperAdmin']]);
}

// --- Notificaciones (usuario) ---
if ($uri === '/api/v1/notifications' && $method === 'GET') {
    respond(['status' => 'success', 'data' => [
        'total_unread' => 1,
        'notifications' => [
            ['id' => 1042, 'type' => 'STOCK_MIN', 'severity' => 'warning', 'title' => 'Stock Mínimo', 'message' => 'msg', 'reference_id' => 5, 'metadata' => null, 'is_read' => false, 'created_at' => '2026-07-25 10:00:00']
        ]
    ]]);
}
if (preg_match('#^/api/v1/notifications/(\d+)/read$#', $uri, $m) && $method === 'PATCH') {
    if ((int)$m[1] === 999999999) {
        respond(['status' => 'error', 'error_code' => 'ERR_NOT_FOUND', 'message' => 'Notificación no encontrada'], 404);
    }
    respond(['status' => 'success', 'data' => ['id' => (int)$m[1], 'is_read' => true]]);
}
if ($uri === '/api/v1/notifications/read-all' && $method === 'PATCH') {
    respond(['status' => 'success', 'data' => ['updated' => 3]]);
}
if ($uri === '/api/v1/notifications' && $method === 'POST') {
    if ($isNoAdminToken) respond(['status' => 'error', 'error_code' => 'ERR_RBAC', 'message' => 'Permisos insuficientes'], 403);
    respond(['status' => 'success', 'data' => ['id' => 555]], 200);
}

// --- Monitoreo ---
if ($uri === '/api/v1/notifications/monitor' && $method === 'GET') {
    if ($isNoAdminToken) respond(['status' => 'error', 'error_code' => 'ERR_RBAC', 'message' => 'Permisos insuficientes'], 403);
    respond(['status' => 'success', 'data' => [
        'summary' => ['total' => 10, 'critical' => 2, 'warning' => 5, 'info' => 3, 'by_interlocutor' => []],
        'notifications' => [['id' => 1, 'type' => 'STOCK_MIN', 'severity' => 'warning', 'title' => 't', 'created_at' => 'x', 'interlocutor_id' => 1, 'recipients' => []]]
    ]]);
}

// --- Reglas programadas ---
if ($uri === '/api/v1/notifications/rules/form-options' && $method === 'GET') {
    respond(['status' => 'success', 'data' => [
        'notification_types' => [['id' => 5, 'code' => 'TRANSFER_NOT_REGISTERED', 'name' => 'Traspaso No Registrado']],
        'hierarchy_levels' => [['id' => 2, 'code' => 'MANDO_MEDIO', 'name' => 'Mando Medio']],
        'rule_types' => [['id' => 1, 'code' => 'NO_RECORD_BY_TIME', 'name' => 'Ausencia de registro', 'description' => 'desc', 'requires_threshold' => false]],
    ]]);
}
if ($uri === '/api/v1/notifications/rules/types' && $method === 'GET') {
    respond(['status' => 'success', 'data' => ['rule_types' => [['id' => 1, 'code' => 'NO_RECORD_BY_TIME']]]]);
}
if ($uri === '/api/v1/notifications/rules' && $method === 'GET') {
    respond(['status' => 'success', 'data' => ['rules' => [['id' => 10, 'name' => 'Regla mock']]]]);
}
if ($uri === '/api/v1/notifications/rules' && $method === 'POST') {
    if ($isNoAdminToken) respond(['status' => 'error', 'error_code' => 'ERR_RBAC', 'message' => 'Permisos insuficientes'], 403);
    if (($body['scope'] ?? '') === 'specific_interlocutor' && empty($body['interlocutor_id'])) {
        respond(['status' => 'error', 'error_code' => 'ERR_VALIDATION', 'message' => 'interlocutor_id es obligatorio cuando scope = specific_interlocutor'], 400);
    }
    respond(['status' => 'success', 'data' => ['id' => 77]]);
}
if (preg_match('#^/api/v1/notifications/rules/(\d+)$#', $uri, $m) && $method === 'PUT') {
    respond(['status' => 'success', 'data' => ['id' => (int)$m[1]]]);
}
if (preg_match('#^/api/v1/notifications/rules/(\d+)$#', $uri, $m) && $method === 'DELETE') {
    respond(['status' => 'success', 'data' => ['id' => (int)$m[1]]]);
}

respond(['status' => 'error', 'error_code' => 'ERR_NOT_FOUND', 'message' => "Ruta mock no implementada: $method $uri"], 404);
