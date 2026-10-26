#!/usr/bin/env bash
# Respaldo diario en Hostinger (docs/produccion.md), además de los respaldos
# automáticos del plan: la base en un .sql.gz verificado y los archivos que no
# están en git (los documentos del Repositorio y el .env) en un .tgz. Si hay
# rclone configurado, una copia fuera del servidor. Solo los últimos días.
#
# Para el cron de hPanel, todos los días a las 3:00:
#
#     bash /home/USUARIO/domains/pivoxcr.com/pivox/scripts/hostinger/backup.sh >> /home/USUARIO/backups/pivox/backup.log 2>&1
#
# Variables opcionales: PHP_BIN (un php que no sea el del PATH), BACKUP_DIR
# (por defecto ~/backups/pivox), BACKUP_KEEP_DAYS (14) y BACKUP_RCLONE_REMOTE
# (ej. r2:pivox-backups). El .tgz lleva el .env con sus contraseñas: el
# destino tiene que ser privado.
set -euo pipefail

cd "$(dirname "$0")/../.."

php_bin="${PHP_BIN:-php}"
dest="${BACKUP_DIR:-$HOME/backups/pivox}"
keep_days="${BACKUP_KEEP_DAYS:-14}"
remote="${BACKUP_RCLONE_REMOTE:-}"
stamp="$(date +%Y-%m-%d_%H%M)"
db_file="$dest/db-$stamp.sql.gz"
files_file="$dest/files-$stamp.tgz"

mkdir -p "$dest"
umask 077

# La contraseña va en un archivo temporal que solo lee este usuario, no en la
# línea de comandos, donde la vería cualquiera con la lista de procesos.
credentials="$(mktemp)"
trap 'rm -f "$credentials" "$db_file.tmp"' EXIT
database="$("$php_bin" scripts/hostinger/mysql-credentials.php "$credentials")"

echo "[$(date '+%F %T')] Respaldo $stamp de $database"

mysqldump --defaults-extra-file="$credentials" --single-transaction --routines --triggers --no-tablespaces "$database" \
    | gzip > "$db_file.tmp"

# Un respaldo que falló en silencio es peor que ninguno: gzip íntegro, la
# línea de cierre que mysqldump escribe solo si terminó, y tablas adentro.
# Se lee el archivo entero (sin grep -q): con pipefail, cortar antes mataría
# a zcat y daría un error falso.
gzip -t "$db_file.tmp"
last_line="$(zcat "$db_file.tmp" | tail -n 1)"
if [[ "$last_line" != "-- Dump completed"* ]]; then
    echo "El dump no terminó (falta «-- Dump completed»)." >&2
    exit 1
fi
tables="$(zcat "$db_file.tmp" | grep -c '^CREATE TABLE' || true)"
if [ "${tables:-0}" -eq 0 ]; then
    echo "El dump no trae tablas." >&2
    exit 1
fi
mv "$db_file.tmp" "$db_file"

tar czf "$files_file" public/repositories .env

if [ -n "$remote" ]; then
    rclone copy "$db_file" "$remote"
    rclone copy "$files_file" "$remote"
fi

find "$dest" -maxdepth 1 \( -name 'db-*.sql.gz' -o -name 'files-*.tgz' \) -mtime +"$keep_days" -delete

echo "[$(date '+%F %T')] Listo: $tables tablas, $(du -h "$db_file" | cut -f1), $db_file"
