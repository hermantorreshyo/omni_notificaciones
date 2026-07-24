<?php
/**
 * NotificationTriggerService — Módulo [1007], Fase 3 (motor de eventos)
 * OMNI API CORE v6.9 · JOSEPAN 360
 *
 * =========================================================================
 * CONTRATO DE INTEGRACIÓN (leer antes de conectar)
 * =========================================================================
 * Este servicio NO reemplaza ni modifica la escritura atómica de stock ni
 * el Kardex — eso ya existe y sigue la regla de oro del proyecto ("nunca
 * overwrite, solo ±delta atómico"). Este servicio se limita a leer el
 * resultado DESPUÉS de que esa escritura ya ocurrió, y decidir si dispara
 * una notificación.
 *
 * Puntos de enganche que debe agregar quien mantiene InventoryController /
 * TransferController (un one-liner cada uno, no tocan la lógica existente):
 *
 *   1. Justo después de insertar en `inventory_kardex` (cualquier movimiento
 *      que reduzca stock: Merma, Traslado, Venta), llamar:
 *
 *          NotificationTriggerService::checkStockThresholds(
 *              $db, $inventoryStockId, $resultingBalance
 *          );
 *
 *   2. Dentro del cron diario de Pareto de vencimientos que ya existe
 *      (mencionado en el manual de OMNI, no incluido en este repo), por
 *      cada lote que entra en la ventana de alerta temprana FEFO, llamar:
 *
 *          NotificationTriggerService::notifyExpiringSoon(
 *              $db, $batchId, $daysRemaining
 *          );
 *
 * Ninguno de los dos puntos requiere leer de vuelta el resultado — son
 * "fire and forget": si la notificación falla, no debe revertir la
 * transacción de stock/kardex que ya se confirmó.
 * =========================================================================
 */

declare(strict_types=1);

final class NotificationTriggerService
{
    /**
     * Evalúa STOCK_OUT / STOCK_MIN para una fila de inventory_stock ya
     * actualizada. Idempotente por día: si el stock fluctúa varias veces
     * alrededor del umbral en el mismo día, no re-notifica.
     */
    public static function checkStockThresholds(PDO $db, int $inventoryStockId, string $resultingBalance): void
    {
        $balance = (float)$resultingBalance;

        $stmt = $db->prepare(
            "SELECT s.min_stock, s.item_id, s.item_type, l.interlocutor_id,
                    COALESCE(p.name, si.name) AS item_name
             FROM inventory_stock s
             INNER JOIN locations l ON l.id = s.location_id
             LEFT JOIN products_sku p ON p.id = s.item_id AND s.item_type = 'sku'
             LEFT JOIN supplier_items si ON si.id = s.item_id AND s.item_type = 'supplier_item'
             WHERE s.id = :stock_id"
        );
        $stmt->execute([':stock_id' => $inventoryStockId]);
        $stock = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$stock) {
            return; // fila de stock no encontrada — no es responsabilidad de este servicio decidir por qué
        }

        if ($balance <= 0) {
            self::emit($db, 'STOCK_OUT', (int)$stock['interlocutor_id'], $inventoryStockId,
                "Stock Agotado: {$stock['item_name']}",
                "El producto {$stock['item_name']} llegó a 0 unidades base en esta sede.");
        } elseif ($balance <= (float)$stock['min_stock']) {
            self::emit($db, 'STOCK_MIN', (int)$stock['interlocutor_id'], $inventoryStockId,
                "Stock Mínimo Alcanzado: {$stock['item_name']}",
                "El producto {$stock['item_name']} descendió a su umbral mínimo ({$stock['min_stock']} unidades restantes: {$balance}).");
        }
    }

    /**
     * Notifica EXPIRING_SOON para todas las ubicaciones donde un lote
     * todavía tiene stock > 0, cuando el cron de Pareto lo marca dentro
     * de la ventana de alerta temprana FEFO.
     */
    public static function notifyExpiringSoon(PDO $db, int $batchId, int $daysRemaining): void
    {
        $stmt = $db->prepare(
            "SELECT s.id AS stock_id, l.interlocutor_id,
                    COALESCE(p.name, si.name) AS item_name
             FROM inventory_stock s
             INNER JOIN locations l ON l.id = s.location_id
             LEFT JOIN products_sku p ON p.id = s.item_id AND s.item_type = 'sku'
             LEFT JOIN supplier_items si ON si.id = s.item_id AND s.item_type = 'supplier_item'
             WHERE s.batch_id = :batch_id AND s.current_quantity > 0"
        );
        $stmt->execute([':batch_id' => $batchId]);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            self::emit($db, 'EXPIRING_SOON', (int)$row['interlocutor_id'], (int)$row['stock_id'],
                "Próximo a Vencer: {$row['item_name']}",
                "El lote #{$batchId} de {$row['item_name']} vence en {$daysRemaining} día(s).",
                $batchId);
        }
    }

    /**
     * Inserta la notificación con idempotencia por día — misma clave para
     * el mismo tipo+stock+fecha no duplica, aunque el trigger se dispare
     * varias veces (ej. varias mermas el mismo día bajo el mínimo).
     */
    private static function emit(
        PDO $db,
        string $typeCode,
        int $interlocutorId,
        int $stockOrBatchRefId,
        string $title,
        string $message,
        ?int $referenceId = null
    ): void {
        $typeStmt = $db->prepare("SELECT id FROM notification_types WHERE code = :code");
        $typeStmt->execute([':code' => $typeCode]);
        $typeId = $typeStmt->fetchColumn();
        if (!$typeId) {
            return; // catálogo mal configurado no debe tumbar la transacción de stock que ya ocurrió
        }

        $idempotencyKey = hash('sha256', $typeCode . '|' . $stockOrBatchRefId . '|' . date('Y-m-d'));

        $stmt = $db->prepare(
            "INSERT IGNORE INTO notifications
                (notification_type_id, interlocutor_id, title, message, reference_id, idempotency_key)
             VALUES
                (:type_id, :interlocutor_id, :title, :message, :reference_id, :idempotency_key)"
        );
        $stmt->execute([
            ':type_id'         => $typeId,
            ':interlocutor_id' => $interlocutorId,
            ':title'           => $title,
            ':message'         => $message,
            ':reference_id'    => $referenceId ?? $stockOrBatchRefId,
            ':idempotency_key' => $idempotencyKey,
        ]);
    }
}
