# Respaldos de la base de datos

## Qué hace

- El superadmin genera respaldos desde **/admin/backups** (Respaldos) y los descarga.
- Hay un respaldo automático diario a la hora configurada, con retención de los últimos N.
- Los manuales no se borran solos.
- Archivos: `storage/app/private/backups/sentinel_YYYY-MM-DD_HHmmss_{manual|programado}_{id}.sql.gz`.
- Formato: SQL estándar comprimido (gzip), generado con `druidfi/mysqldump-php` en una sola transacción.
- Incluye `DROP TABLE IF EXISTS`, triggers y rutinas.
- `jobs`, `failed_jobs`, `job_batches`, `cache`, `cache_locks` y `sessions` van solo con estructura.

## Cómo funciona

`respaldos:ejecutar` corre cada minuto desde el programador de Laravel (no usa la cola). El botón del panel solo deja una solicitud pendiente, que el comando toma en menos de un minuto.

Para generar uno desde consola:

```bash
php artisan respaldos:ejecutar --ahora
```

## Requisitos en el servidor

1. El cron ejecuta el programador cada minuto: `* * * * * cd /ruta/sentinel-back && php artisan schedule:run >> /dev/null 2>&1`. Si no corre, el panel muestra el aviso "Los respaldos automáticos no se están ejecutando".
2. `storage/app/private/backups` es escribible por el usuario del cron **y** del servidor web: uno crea los archivos y el otro los descarga o borra.
3. Tras desplegar: `composer install`, `php artisan migrate`, `php artisan route:clear`, `php artisan config:clear`.

## Verificar un respaldo descargado

El panel no muestra el sha256; está en la columna `checksum` de `database_backups`.

```bash
sha256sum sentinel_2026-10-10_020000_programado_12.sql.gz
gunzip -t sentinel_2026-10-10_020000_programado_12.sql.gz
```

## Restaurar (a mano; el panel no restaura)

> Restaurar **sobrescribe** todas las tablas de todas las empresas. Primero respalda la base actual.

1. Prueba en una base temporal:

   ```bash
   mysql -u root -p -e "CREATE DATABASE sentinel_restore_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
   gunzip -c respaldo.sql.gz | mysql -u root -p sentinel_restore_test
   ```

2. Revisa que los conteos de tablas clave coincidan con lo esperado.
3. Pon la app en mantenimiento (`php artisan down`) y genera un respaldo de la base actual (`php artisan respaldos:ejecutar --ahora`).
4. Restaura sobre la base real: `gunzip -c respaldo.sql.gz | mysql -u root -p sentinel`.
5. `php artisan cache:clear` y `php artisan up`.
6. Borra la base temporal.
