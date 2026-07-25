# QA — Fase 7

Suite de pruebas de carga e idempotencia para el módulo [1007]. No son
pruebas unitarias con mocks — corren contra una base de datos MySQL/MariaDB
real (siguiendo la filosofía del proyecto de probar contra el motor real,
no contra dobles).

## Requisitos

- Una base de datos con el esquema OMNI real + las migraciones de [1007]
  aplicadas (ver `docs/DEPLOYMENT.md`).
- Un usuario de BD con permisos de lectura/escritura sobre las tablas de
  prueba (`interlocutors`, `transfers`, `locations`, `notifications`, etc).
- PHP con `proc_open` habilitado (por defecto lo está; algunos hostings
  restringidos lo deshabilitan — verificar con `php -m | grep -i posix`
  o revisando `disable_functions` en `php.ini`).

## Cómo correr

```bash
# 1. Prueba de carga: 14 sedes, 8 cumplen / 6 fallan, verifica el conjunto
#    exacto y la idempotencia tras 4 corridas secuenciales
php tests/test_load_14_sedes.php "mysql:host=127.0.0.1;dbname=<bd>;charset=utf8mb4" <usuario> <password>

# 2. Prueba de concurrencia real (requiere haber corrido la de arriba primero,
#    reutiliza su escenario): lanza N procesos de SO reales en paralelo
php tests/test_concurrency_idempotency.php "mysql:host=127.0.0.1;dbname=<bd>;charset=utf8mb4" <usuario> <password> 10

# 3. Concurrencia del motor de eventos (stock): simula N operarios
#    registrando la misma merma casi al mismo tiempo
php tests/test_concurrency_stock.php "mysql:host=127.0.0.1;dbname=<bd>;charset=utf8mb4" <usuario> <password> 10
```

Cada script termina con `exit(0)` si todo pasó, `exit(1)` si algo falló —
apto para integrarlo a un pipeline de CI si el proyecto llega a tener uno.

## Resultados de la última corrida (entorno de prueba, MariaDB 10.11)

| Prueba | Resultado | Detalle |
|---|---|---|
| Carga 14 sedes | ✅ | 31 ms, conjunto exacto de sedes fallidas correcto, sin duplicar tras 4 corridas |
| Concurrencia motor programado | ✅ | 10 procesos reales de SO simultáneos → exactamente 1 notificación |
| Concurrencia motor de eventos (stock) | ✅ | 10 procesos reales simultáneos sobre la misma merma → exactamente 1 notificación |

## Qué NO cubre esta suite (fuera de alcance de Fase 7)

- Carga a escala de producción (cientos de sedes) — el patrón actual de
  `ScheduledRuleEngine::runNoRecordByTime()` ejecuta una consulta por
  interlocutor evaluado (`N` queries para `N` sedes). Con 14-50 sedes esto
  es intrascendente (ver tiempos arriba), pero si el catálogo de sedes
  crece a cientos, vale la pena revisar una consulta batched
  (`GROUP BY interlocutor_id_dest` con `HAVING COUNT(*) = 0` no es directo
  porque hay que incluir las sedes con cero filas — requeriría un `LEFT
  JOIN` contra la lista completa de sedes). Se deja como nota para
  cuando el volumen lo justifique, no antes.
- Pruebas de UI automatizadas de extremo a extremo (Selenium/Playwright) —
  el proyecto no tiene esa infraestructura; las Fases 5 y 6 se validaron
  con `jsdom`, que cubre lógica pero no renderizado visual real.
