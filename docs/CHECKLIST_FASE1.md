# Checklist Fase 1 — estado tras revisar manual + schema.sql reales

## ✅ Resuelto (ya no son supuestos, están validados contra schema.sql)

- `legal_entity_id` → FK a `interlocutors` (no existe tabla `legal_entities`;
  las 7 entidades legales son filas `interlocutors.type='empresa'` en la raíz
  de la jerarquía `owner_id`).
- `condition_rule_types.NO_RECORD_BY_TIME` → apunta a la tabla real `transfers`,
  columnas `created_at` / `interlocutor_id_dest` (no existe `daily_transfers`).
- Tipos de columna FK corregidos a `INT UNSIGNED` para coincidir con
  `users.id`, `roles.id`, `interlocutors.id` (eran `BIGINT`, rompía la FK).
- Migración probada de extremo a extremo contra `schema.sql` real (MariaDB
  10.11): 3 corridas consecutivas, exit code 0, sin duplicar seeds.

## ⚠️ Hallazgo informativo (no bloquea 1007, es un bug preexistente del proyecto)

Al cargar `schema.sql` con `--force` para poder probarlo, aparecieron errores
**no relacionados con este módulo**, en el propio schema base:

- `user_roles.interlocutor_id` es parte de la PRIMARY KEY compuesta, pero
  tiene una FK con `ON DELETE SET NULL` — MySQL/MariaDB no permite `SET NULL`
  sobre una columna que es parte de una PK (las columnas de PK son NOT NULL
  implícitamente). Esto fallaría igual en MySQL 8 real, no es un problema de
  dialecto de mi prueba.
- Varios bloques de trigger/procedimiento almacenado (líneas ~626-672,
  validación de jerarquía de `interlocutors`) usan sintaxis que la CLI de
  `mysql` no procesó correctamente vía `--force` en modo no interactivo —
  posiblemente un bloque `DELIMITER` mal cerrado en el archivo fuente.

No los toco — no son parte de [1007] — pero vale la pena que se lo
menciones a quien mantiene el core, porque un despliegue limpio de
`schema.sql` en un servidor nuevo probablemente falle en esos dos puntos.

## Prerrequisitos verificados por la migración 1007

Ya existen y se referencian por FK (no se crean): `interlocutors`, `roles`,
`users`, `permissions`, `subsystem_screens`.

## Pendiente de negocio (no bloquea el desarrollo)

- Mapeo `roles.hierarchy_level_id` → cuál de los 15 roles deterministas es
  Operativo/Mando Medio/Dirección (columna ya existe, nullable).
- Confirmar qué roles concretos ven las pantallas `notifications_monitor`
  y `notification_rules` (se insertan en `subsystem_screen_permissions`).
