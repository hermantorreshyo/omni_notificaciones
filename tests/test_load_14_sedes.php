<?php
/**
 * test_load_14_sedes.php — Fase 7 (QA)
 *
 * Simula 14 puntos de venta evaluados por ScheduledRuleEngine en una sola
 * corrida, mide el tiempo, y verifica que el conjunto de sedes marcadas
 * como "sin traspaso" sea exactamente el esperado (ni más ni menos).
 *
 * Uso: php tests/test_load_14_sedes.php [DSN] [USER] [PASS]
 */

declare(strict_types=1);

require_once __DIR__ . '/../cron/ScheduledRuleEngine.php';

$dsn  = $argv[1] ?? 'mysql:host=127.0.0.1;dbname=omni_test3;charset=utf8mb4';
$user = $argv[2] ?? 'testphp';
$pass = $argv[3] ?? 'testpass';

$db = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$fail = 0;
function check(bool $cond, string $label): void
{
    global $fail;
    echo ($cond ? '[OK] ' : '[FALLO] ') . $label . "\n";
    if (!$cond) $fail++;
}

// --- Setup: limpiar corridas previas de esta prueba ---
$db->exec("DELETE FROM notifications WHERE notification_type_id = (SELECT id FROM notification_types WHERE code='TRANSFER_NOT_REGISTERED')");
$db->exec("DELETE FROM scheduled_notification_rules WHERE name LIKE 'QA Carga 14 Sedes%'");
$db->exec("DELETE FROM transfers WHERE interlocutor_id_dest IN (SELECT id FROM interlocutors WHERE fiscal_id LIKE 'QA-SEDE-%')");
$db->exec("DELETE FROM locations WHERE qr_code_uid LIKE 'QA-LOC-%'");
$db->exec("DELETE FROM interlocutors WHERE fiscal_id LIKE 'QA-SEDE-%'");

// --- Crear empresa raíz y 1 ubicación de origen ---
$empresaId = (int)$db->query("SELECT id FROM interlocutors WHERE type='empresa' LIMIT 1")->fetchColumn();
if (!$empresaId) {
    $db->exec("INSERT INTO interlocutors (fiscal_name, commercial_name, fiscal_id, type, status) VALUES ('QA Empresa','QA Empresa','QA-EMP-01','empresa','active')");
    $empresaId = (int)$db->lastInsertId();
}
$adminUserId = (int)$db->query("SELECT id FROM users LIMIT 1")->fetchColumn();

$stmtLocOrigen = $db->prepare("INSERT INTO locations (interlocutor_id, area_type, qr_code_uid, description) VALUES (:iid, 'bodega', :qr, 'QA Origen')");
$stmtLocOrigen->execute([':iid' => $empresaId, ':qr' => 'QA-LOC-ORIGEN']);
$locOrigenId = (int)$db->lastInsertId();

// --- Crear 14 puntos de venta: 8 SI solicitan hoy, 6 NO (deben fallar) ---
$totalSedes = 14;
$sedesQueFallan = [];
$sedesQueCumplen = [];

for ($i = 1; $i <= $totalSedes; $i++) {
    $db->exec("INSERT INTO interlocutors (fiscal_name, commercial_name, fiscal_id, type, owner_id, status)
               VALUES ('QA Sede $i','QA Sede $i','QA-SEDE-" . str_pad((string)$i, 3, '0', STR_PAD_LEFT) . "','punto_venta',$empresaId,'active')");
    $sedeId = (int)$db->lastInsertId();

    $stmtLocDest = $db->prepare("INSERT INTO locations (interlocutor_id, area_type, qr_code_uid, description) VALUES (:iid, 'bodega', :qr, :desc)");
    $stmtLocDest->execute([':iid' => $sedeId, ':qr' => "QA-LOC-DEST-$i", ':desc' => "QA Destino $i"]);
    $locDestId = (int)$db->lastInsertId();

    // 8 de las 14 SI solicitan hoy (id impar de 1 a 14 más los primeros -> definimos explicitamente pares/impares)
    $solicitaHoy = ($i % 7 !== 0); // 12 de 14 solicitan, 2 no -- variamos el patron abajo tambien con BORRADOR
    if ($i <= 8) {
        // Las primeras 8: SI solicitan (SOLICITADO con at_solicitado = hoy)
        $stmt = $db->prepare(
            "INSERT INTO transfers (state, location_id_origin, location_id_destination, interlocutor_id_origin, interlocutor_id_dest, created_by, solicited_by, at_solicitado)
             VALUES ('SOLICITADO', :origen, :dest, :empresa, :sede, :user, :user, NOW())"
        );
        $stmt->execute([':origen' => $locOrigenId, ':dest' => $locDestId, ':empresa' => $empresaId, ':sede' => $sedeId, ':user' => $adminUserId]);
        $sedesQueCumplen[] = $sedeId;
    } elseif ($i <= 12) {
        // Sedes 9-12: dejan el traspaso en BORRADOR (no cuenta)
        $stmt = $db->prepare(
            "INSERT INTO transfers (state, location_id_origin, location_id_destination, interlocutor_id_origin, interlocutor_id_dest, created_by)
             VALUES ('BORRADOR', :origen, :dest, :empresa, :sede, :user)"
        );
        $stmt->execute([':origen' => $locOrigenId, ':dest' => $locDestId, ':empresa' => $empresaId, ':sede' => $sedeId, ':user' => $adminUserId]);
        $sedesQueFallan[] = $sedeId;
    } else {
        // Sedes 13-14: no crean ningun traspaso
        $sedesQueFallan[] = $sedeId;
    }
}

check(count($sedesQueCumplen) === 8, "8 sedes deberian cumplir (crearon transfers.SOLICITADO hoy)");
check(count($sedesQueFallan) === 6, "6 sedes deberian fallar (BORRADOR o sin ningun traspaso)");

// --- Crear la regla programada con check_time = ahora ---
$currentTime = $db->query("SELECT TIME_FORMAT(NOW(), '%H:%i:00')")->fetchColumn();
$typeId = $db->query("SELECT id FROM notification_types WHERE code='TRANSFER_NOT_REGISTERED'")->fetchColumn();
$ruleTypeId = $db->query("SELECT id FROM condition_rule_types WHERE code='NO_RECORD_BY_TIME'")->fetchColumn();

$stmt = $db->prepare(
    "INSERT INTO scheduled_notification_rules (name, notification_type_id, rule_type_id, check_time, scope, created_by)
     VALUES ('QA Carga 14 Sedes', :type_id, :rule_type_id, :check_time, 'all_pos', :created_by)"
);
$stmt->execute([':type_id' => $typeId, ':rule_type_id' => $ruleTypeId, ':check_time' => $currentTime, ':created_by' => $adminUserId]);

// --- Ejecutar el motor y medir tiempo ---
$start = microtime(true);
ScheduledRuleEngine::run($db);
$elapsedMs = round((microtime(true) - $start) * 1000, 2);

echo "\nTiempo de ejecucion para {$totalSedes} sedes: {$elapsedMs} ms\n";
check($elapsedMs < 2000, "El motor deberia resolver 14 sedes en menos de 2 segundos (entorno de prueba)");

// --- Verificar el conjunto exacto de sedes notificadas ---
$stmt = $db->prepare(
    "SELECT n.interlocutor_id FROM notifications n
     JOIN notification_types nt ON nt.id = n.notification_type_id
     WHERE nt.code = 'TRANSFER_NOT_REGISTERED' AND n.interlocutor_id IN (
        SELECT id FROM interlocutors WHERE fiscal_id LIKE 'QA-SEDE-%'
     )"
);
$stmt->execute();
$notificadas = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

sort($notificadas);
sort($sedesQueFallan);

check($notificadas === $sedesQueFallan, "El conjunto exacto de sedes notificadas debe coincidir con las que fallaron: [" . implode(',', $sedesQueFallan) . "] vs obtenido [" . implode(',', $notificadas) . "]");

foreach ($sedesQueCumplen as $sedeId) {
    check(!in_array($sedeId, $notificadas, true), "Sede $sedeId (SI cumplio) NO debe estar notificada");
}

// --- Re-ejecutar 3 veces mas para confirmar idempotencia bajo carga ---
for ($r = 0; $r < 3; $r++) {
    ScheduledRuleEngine::run($db);
}
$stmt->execute();
$notificadasTrasRepetir = $stmt->fetchAll(PDO::FETCH_COLUMN);
check(count($notificadasTrasRepetir) === count($sedesQueFallan), "Tras 4 corridas totales, sigue habiendo exactamente " . count($sedesQueFallan) . " notificaciones (sin duplicar)");

echo "\n=== RESULTADO: " . ($fail === 0 ? "TODO OK" : "$fail FALLO(S)") . " ===\n";
exit($fail === 0 ? 0 : 1);
