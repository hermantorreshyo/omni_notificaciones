<?php
/**
 * test_concurrency_stock.php — Fase 7 (QA)
 *
 * Simula el caso real: dos o más operarios registrando mermas del mismo
 * producto en la misma sede casi al mismo tiempo (ej. dos cajas
 * registrando salida del mismo lote). Ambas llamadas a
 * checkStockThresholds() deberían competir por crear la MISMA
 * notificación STOCK_MIN — la restricción UNIQUE debe garantizar que
 * solo una sobreviva, sin importar el orden de llegada.
 *
 * Uso: php tests/test_concurrency_stock.php [DSN] [USER] [PASS] [N_PROCESOS]
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

// --- Setup: un producto, ubicacion, lote y stock con min_stock ---
$db->exec("DELETE FROM inventory_stock WHERE id IN (SELECT id FROM (SELECT id FROM inventory_stock ORDER BY id DESC LIMIT 0) t)"); // no-op seguro
$empresaId = (int)$db->query("SELECT id FROM interlocutors WHERE type='empresa' LIMIT 1")->fetchColumn();
$skuId = $db->query("SELECT id FROM products_sku LIMIT 1")->fetchColumn();
if (!$skuId) {
    echo "ERROR: no hay ningun products_sku de prueba -- correr primero el setup de Fase 3\n";
    exit(1);
}

$db->exec("DELETE FROM locations WHERE qr_code_uid = 'QA-CONCURRENCY-STOCK'");
$stmt = $db->prepare("INSERT INTO locations (interlocutor_id, area_type, qr_code_uid, description) VALUES (:iid, 'bodega', 'QA-CONCURRENCY-STOCK', 'QA Concurrencia Stock')");
$stmt->execute([':iid' => $empresaId]);
$locId = (int)$db->lastInsertId();

$userId = (int)$db->query("SELECT id FROM users LIMIT 1")->fetchColumn();
$stmt = $db->prepare("INSERT INTO batches (batch_reference, item_id, item_type, expiration_date, responsible_user_id, cost_per_unit) VALUES ('QA-LOTE-CONCURRENCY', :sku, 'sku', '2026-12-31', :user, 1)");
$stmt->execute([':sku' => $skuId, ':user' => $userId]);
$batchId = (int)$db->lastInsertId();

$stmt = $db->prepare("INSERT INTO inventory_stock (location_id, batch_id, item_id, item_type, current_quantity, min_stock, max_stock) VALUES (:loc, :batch, :sku, 'sku', 12000, 15000, 50000)");
$stmt->execute([':loc' => $locId, ':batch' => $batchId, ':sku' => $skuId]);
$stockId = (int)$db->lastInsertId();

echo "Lanzando {$nProcesses} procesos PHP concurrentes registrando la MISMA merma (stock ya bajo el minimo)...\n";

$workerScript = __DIR__ . '/test_concurrency_stock_worker.php';
$processes = [];
$startAll = microtime(true);

for ($i = 0; $i < $nProcesses; $i++) {
    $cmd = sprintf(
        'php %s %s %s %s %d %s',
        escapeshellarg($workerScript), escapeshellarg($dsn), escapeshellarg($user), escapeshellarg($pass),
        $stockId, escapeshellarg('12000')
    );
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $processes[] = ['proc' => $proc, 'pipes' => $pipes];
}

foreach ($processes as $p) {
    stream_get_contents($p['pipes'][1]);
    stream_get_contents($p['pipes'][2]);
    fclose($p['pipes'][1]);
    fclose($p['pipes'][2]);
    proc_close($p['proc']);
}

$elapsedMs = round((microtime(true) - $startAll) * 1000, 2);
echo "Los {$nProcesses} procesos terminaron en {$elapsedMs} ms\n";

$stmt = $db->prepare(
    "SELECT COUNT(*) FROM notifications
     WHERE reference_id = :stock_id
       AND notification_type_id = (SELECT id FROM notification_types WHERE code='STOCK_MIN')"
);
$stmt->execute([':stock_id' => $stockId]);
$count = (int)$stmt->fetchColumn();

check($count === 1, "Tras {$nProcesses} procesos CONCURRENTES registrando la misma merma, debe existir exactamente 1 notificacion STOCK_MIN (obtenido: {$count})");

echo "\n=== RESULTADO: " . ($fail === 0 ? "TODO OK" : "$fail FALLO(S)") . " ===\n";
exit($fail === 0 ? 0 : 1);
