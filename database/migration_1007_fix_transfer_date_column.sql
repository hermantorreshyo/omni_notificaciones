-- =============================================================================
-- MIGRACIÓN [1007] — Fix: NO_RECORD_BY_TIME debe usar `at_solicitado`, no `created_at`
-- OMNI API CORE v6.9 · JOSEPAN 360
--
-- Contexto: la actualización del core agregó el estado BORRADOR a `transfers`
-- (workflow BORRADOR → SOLICITADO vía PUT /solicitar). `created_at` ahora
-- refleja cuándo se creó el borrador (que puede ser un día distinto al que
-- realmente se solicitó), mientras que `at_solicitado` se setea "al crear
-- (SOLICITADO)" según el manual — es el timestamp real de la solicitud.
--
-- Fix: cambiar target_date_column del patrón NO_RECORD_BY_TIME de
-- `created_at` a `at_solicitado`. Como at_solicitado es NULL mientras el
-- traspaso sigue en BORRADOR, `DATE(at_solicitado) = CURDATE()` excluye
-- automáticamente los borradores sin necesidad de un mecanismo adicional
-- de exclusión por estado — no hace falta tocar el motor (ScheduledRuleEngine),
-- solo el dato del catálogo técnico.
--
-- Este es un UPDATE sobre una fila de `condition_rule_types` — tabla
-- dev-owned, nunca editable desde el panel admin. Coherente con el
-- principio de seguridad ya establecido (docs/prompt_1007_notificaciones_alertas.md).
-- =============================================================================
SET NAMES utf8mb4;

UPDATE `condition_rule_types`
SET `target_date_column` = 'at_solicitado',
    `description` = CONCAT(`description`, ' (usa at_solicitado, no created_at, para no contar traspasos que quedaron en BORRADOR sin enviarse)')
WHERE `code` = 'NO_RECORD_BY_TIME'
  AND `target_date_column` = 'created_at';
