# Checklist antes de aplicar `migration_1007_notificaciones.sql`

## ⚠️ Único supuesto que debes confirmar

El seed de `condition_rule_types` asume que el módulo "Traspasos Diarios"
(sección TIENDAS del admin) persiste en una tabla llamada `daily_transfers`
con columnas `transfer_date` e `interlocutor_id`. **Verifica el nombre real**
con `DESCRIBE daily_transfers;` (o el nombre que tenga esa tabla en tu BD)
antes de correr el script. Si difiere, ajusta el `INSERT` de la sección 3
del script — es la única parte no genérica de la migración.

## Prerrequisitos verificados por el script

El script asume que ya existen (no los crea, solo referencia via FK):
- `interlocutors`
- `roles`
- `users`
- `legal_entities`

Si alguna de estas tablas todavía no existe en tu instalación de OMNI,
la migración fallará en la FK correspondiente — correrla en un entorno
de prueba primero es lo recomendado.

## Cómo aplicarla

```bash
mysql -u <usuario> -p <base_de_datos> < database/migration_1007_notificaciones.sql
```

Es idempotente (`CREATE TABLE IF NOT EXISTS` + `INSERT IGNORE`): correrla
dos veces no duplica nada ni rompe si ya se aplicó parcialmente.
