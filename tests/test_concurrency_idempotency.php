<?php
/**
 * test_concurrency_idempotency.php — Fase 7 (QA)
 *
 * Prueba de concurrencia REAL: lanza N procesos de sistema operativo
 * (proc_open, no async cooperativo dentro del mismo proceso) ejecutando
 * ScheduledRuleEngine::run() al mismo tiempo, contra la MISMA sede
 * fallida. El objetivo es confirmar que la garantía de idempotencia no
 * depende de que las corridas sean secuenciales — la restricción UNIQUE
 * de MySQL debe resolver la carrera aunque dos procesos intenten
 * insertar la misma notificación en el mismo instante.
 *
 * Uso: php tests/test_concurrency_idempotency.php [DSN] [USER] [PASS] [N_PROCESOS]
 */

declare(strict_types=1);

$dsn  = $argv[1] ?? 'mysql:host=127.0.0.1;dbname=omni_test3;charset=utf8mb4';
$user = $argv[2] ?? 'testphp';
$pass = $argv[3] ?? 'testpass';
$nProcesses = (int)($argv[4] ?? 8);

$db = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$fail = 0;
function check(bool $cond, string $label): void
{
    global $fail;
    echo ($cond ? '[OK] ' : '[FALLO] ') . $label . "\n";
    if (!$cond) $fail++;
}

// --- Reusar el escenario de la prueba de carga de 14 sedes (debe correrse antes) ---
$sedeFallidaId = (int)$db->query(
    "SELECT id FROM interlocutors WHERE fiscal_id = 'QA-SEDE-013' LIMIT 1"
)->fetchColumn();

if (!$sedeFallidaId) {
    echo "ERROR: correr primero test_load_14_sedes.php para tener datos de escenario\n";
    exit(1);
}

// Limpiar notificaciones previas de esa sede para partir de cero
$db->exec("DELETE FROM notifications WHERE interlocutor_id = $sedeFallidaId AND notification_type_id = (SELECT id FROM notification_types WHERE code='TRANSFER_NOT_REGISTERED')");

echo "Lanzando {$nProcesses} procesos PHP concurrentes ejecutando ScheduledRuleEngine::run()...\n";

$workerScript = __DIR__ . '/test_concurrency_worker.php';
$processes = [];
$startAll = microtime(true);

for ($i = 0; $i < $nProcesses; $i++) {
    $cmd = sprintf(
        'php %s %s %s %s',
        escapeshellarg($workerScript),
        escapeshellarg($dsn),
        escapeshellarg($user),
        escapeshellarg($pass)
    );
    $descriptorSpec = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($cmd, $descriptorSpec, $pipes);
    $processes[] = ['proc' => $proc, 'pipes' => $pipes];
}

// Esperar a que todos terminen
foreach ($processes as $p) {
    stream_get_contents($p['pipes'][1]);
    stream_get_contents($p['pipes'][2]);
    fclose($p['pipes'][1]);
    fclose($p['pipes'][2]);
    proc_close($p['proc']);
}

$elapsedMs = round((microtime(true) - $startAll) * 1000, 2);
echo "Los {$nProcesses} procesos concurrentes terminaron en {$elapsedMs} ms\n";

// --- Verificar: exactamente 1 notificación para esa sede, pese a N procesos concurrentes ---
$stmt = $db->prepare(
    "SELECT COUNT(*) FROM notifications
     WHERE interlocutor_id = :sede
       AND notification_type_id = (SELECT id FROM notification_types WHERE code='TRANSFER_NOT_REGISTERED')"
);
$stmt->execute([':sede' => $sedeFallidaId]);
$count = (int)$stmt->fetchColumn();

check($count === 1, "Tras {$nProcesses} procesos CONCURRENTES reales, debe existir exactamente 1 notificación (obtenido: {$count})");

echo "\n=== RESULTADO: " . ($fail === 0 ? "TODO OK" : "$fail FALLO(S)") . " ===\n";
exit($fail === 0 ? 0 : 1);
