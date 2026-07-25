<?php
declare(strict_types=1);
require_once __DIR__ . '/../cron/NotificationTriggerService.php';

$dsn = $argv[1]; $user = $argv[2]; $pass = $argv[3]; $stockId = (int)$argv[4]; $balance = $argv[5];

$db = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
NotificationTriggerService::checkStockThresholds($db, $stockId, $balance);
echo "worker " . getmypid() . " ok\n";
