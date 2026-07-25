# Integración pendiente — Fase 3 (motor de eventos)

`NotificationTriggerService.php` está completo y **probado funcionalmente**
contra datos reales (STOCK_MIN, STOCK_OUT, EXPIRING_SOON, e idempotencia
confirmada — ver resultados en el commit de esta fase).

## Puntos de enganche (wiring, no diseño)

1. En el código que hace el `INSERT` en `inventory_kardex` tras cualquier
   movimiento que reduzca stock (Merma, Traslado, Venta), agregar una línea
   inmediatamente después:
   ```php
   NotificationTriggerService::checkStockThresholds($db, $inventoryStockId, $resultingBalance);
   ```
2. Dentro del cron diario de Pareto de vencimientos que ya existe (no
   incluido en este repo), por cada lote que entra en la ventana FEFO:
   ```php
   NotificationTriggerService::notifyExpiringSoon($db, $batchId, $daysRemaining);
   ```

Ambos son "fire and forget" — si la notificación falla, no debe revertir
la transacción de stock/kardex que ya se confirmó.

## Bugs preexistentes encontrados en `schema.sql` (no relacionados con [1007])

Durante la prueba funcional de esta fase necesité crear un `products_sku`
de prueba y encontré un **segundo bug real** en el schema base (el primero
fue el de `user_roles.interlocutor_id` reportado en `CHECKLIST_FASE1.md`):

- **Falta una coma** en la definición de `products_sku`, entre la columna
  `pack_size` y `ean_code` (línea ~230 de `schema.sql`). Sin esa coma, la
  tabla `products_sku` no se crea — lo cual además tumba en cascada la
  creación de `agora_item_mapping` y `production_order_items` (dependen de
  `products_sku` por FK).

  ```sql
  -- Como está ahora (falta la coma final):
  `pack_size`       DECIMAL(12,4) NOT NULL DEFAULT 1
                    COMMENT '...'
  `ean_code`        VARCHAR(13)  NULL COMMENT '...'

  -- Corrección:
  `pack_size`       DECIMAL(12,4) NOT NULL DEFAULT 1
                    COMMENT '...',
  `ean_code`        VARCHAR(13)  NULL COMMENT '...'
  ```

Vale la pena reportarlo a quien mantiene el core — un despliegue limpio de
`schema.sql` en un servidor nuevo fallaría en `products_sku` y arrastraría
otras dos tablas con él.
