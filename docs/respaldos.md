# Respaldos de la base de datos

## Qué hace

- El superadmin genera respaldos desde **/admin/backups** (Respaldos) y los descarga.
- Hay un respaldo automático diario a la hora configurada, con retención de los últimos N.
- Los manuales no se borran solos.
- Archivos: cada respaldo vive en su propia carpeta, `storage/app/private/backups/sentinel_YYYY-MM-DD_HHmmss_{manual|programado}_{id}/sentinel_YYYY-MM-DD_HHmmss_{manual|programado}_{id}.sql.gz`. Los respaldos hechos antes de las carpetas siguen sueltos en `backups/` y el panel los encuentra igual; al eliminar un respaldo se quita también su carpeta.
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
2. `storage/app/private/backups` es escribible por el usuario del cron **y** del servidor web: uno crea los archivos y el otro los descarga o borra. Crea la carpeta una vez con un grupo compartido por ambos usuarios y el bit setgid (adapta el nombre del grupo):

   ```bash
   mkdir -p storage/app/private/backups
   chgrp -R www-data storage/app/private/backups && chmod 2775 storage/app/private/backups
   ```

   La app ya no cambia los permisos de la carpeta en cada corrida: solo la crea (0775) si no existe. Si el usuario web no puede entrar en ella, el panel muestra todos los respaldos como no disponibles y no podrá borrarlos (la app lo detecta y conserva las filas en lugar de dejar volcados huérfanos).
3. Ejecuta `php artisan respaldos:ejecutar --ahora` **con el mismo usuario que el servidor web**. Si lo corres como root, los archivos quedan con un dueño que el panel no puede borrar.
4. PHP con la extensión `ext-zlib` (para el gzip).
5. El usuario de la base de datos necesita `SELECT`, `SHOW VIEW` y `TRIGGER` (y, en MySQL 8.0.20 o superior, el privilegio de lectura de rutinas). No hace falta `LOCK TABLES` porque el volcado usa una sola transacción.
6. Tras desplegar: `composer install`, `php artisan migrate`, `php artisan route:clear`, `php artisan config:clear`.
7. Si el servidor se reinicia a mitad de un volcado, ejecuta `php artisan schedule:clear-cache`: el candado contra solapamiento vive en `cache_locks` hasta 3 h y bloquearía las corridas siguientes.

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
4. Restaura sobre la base real. Primero elimina y vuelve a crear la base (si no, quedan las tablas creadas por migraciones posteriores al respaldo y una tabla `migrations` inconsistente):

   ```bash
   mysql -u root -p -e "DROP DATABASE sentinel; CREATE DATABASE sentinel CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
   gunzip -c respaldo.sql.gz | mysql -u root -p sentinel
   ```

5. `php artisan cache:clear` y `php artisan up`.
6. Borra la base temporal.

### Después de restaurar

Las filas de `database_backups` reflejan el momento del respaldo: vuelven también las que estaban pendientes o en proceso en ese instante. El programador procesará una fila `pendiente` restaurada y marcará como fallida una `en_proceso` antigua pasadas 2 h. Revisa la lista en /admin/backups y elimina las que no correspondan.
