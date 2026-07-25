<?php
/**
 * test_concurrency_worker.php — invocado como proceso hijo por
 * test_concurrency_idempotency.php. Ejecuta ScheduledRuleEngine::run()
 * una vez y termina. Varias copias de este script se lanzan en paralelo
 * (procesos de SO reales, no async de un solo proceso) para verificar
 * que la restricción UNIQUE de idempotency_key resiste una carrera real
 * a nivel de base de datos, no solo repeticiones secuenciales.
 */

declare(strict_types=1);

require_once __DIR__ . '/../cron/ScheduledRuleEngine.php';

$dsn  = $argv[1];
$user = $argv[2];
$pass = $argv[3];

$db = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
ScheduledRuleEngine::run($db);
echo "worker " . getmypid() . " terminado\n";
