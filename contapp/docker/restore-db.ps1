<#
.SYNOPSIS
    Restaura la base de datos de CONTAPP desde un respaldo comprimido.

.DESCRIPTION
    Esta operación BORRA la base actual y la reemplaza. No hay deshacer.

    ── Las dos salvaguardas ────────────────────────────────────────────────

    1. Antes de sobrescribir, el script respalda lo que hay. Cuesta segundos
       y es lo único que salva de restaurar el archivo equivocado — que es
       el error que de verdad ocurre, no el de restaurar sin querer.

    2. Hay que escribir el nombre de la base para confirmar. Un "¿seguro?
       [s/n]" se contesta que sí por reflejo; escribir `bdcontapp` obliga a
       leer qué se está por borrar.

    ── Por qué se borra la base en vez de cargar encima ────────────────────

    Cargar un dump sobre una base que ya tiene datos no la deja como estaba
    el día del respaldo: las tablas que existían entonces se reemplazan, pero
    las que se crearon después siguen ahí, y también las filas de tablas que
    el dump no incluye. El resultado es una mezcla de dos momentos que no
    corresponde a ninguno.

.PARAMETER File
    Ruta al archivo .sql.gz que se va a restaurar.

.PARAMETER Force
    Omite la confirmación escrita. Para uso desatendido; no lo uses a mano.

.PARAMETER SkipSafetyBackup
    No respalda el estado actual antes de sobrescribir. Solo tiene sentido si
    la base ya está vacía o corrupta.

.EXAMPLE
    .\docker\restore-db.ps1 -File "$env:USERPROFILE\OneDrive\Respaldos\CONTAPP\bdcontapp-2026-09-23-2122.sql.gz"
#>
param(
    [Parameter(Mandatory = $true)]
    [string]$File,

    [switch]$Force,

    [switch]$SkipSafetyBackup
)

$ErrorActionPreference = 'Stop'

$projectDir = Split-Path $PSScriptRoot -Parent
Set-Location $projectDir

if (-not (Test-Path $File)) { throw "No existe el archivo $File" }

$source = (Resolve-Path $File).Path

function Read-DotEnv {
    param([string]$Path)

    if (-not (Test-Path $Path)) { throw "No se encontró $Path" }

    $values = @{}

    foreach ($line in Get-Content $Path) {
        $trimmed = $line.Trim()
        if ($trimmed -eq '' -or $trimmed.StartsWith('#')) { continue }

        $split = $trimmed.IndexOf('=')
        if ($split -lt 1) { continue }

        $values[$trimmed.Substring(0, $split).Trim()] =
            $trimmed.Substring($split + 1).Trim().Trim('"').Trim("'")
    }

    return $values
}

$settings = Read-DotEnv (Join-Path $projectDir '.env')

$database = $settings['DB_DATABASE']
$password = $settings['DB_ROOT_PASSWORD']

if ([string]::IsNullOrWhiteSpace($database)) { throw 'DB_DATABASE está vacío en el .env.' }
if ([string]::IsNullOrWhiteSpace($password)) { throw 'DB_ROOT_PASSWORD está vacío en el .env.' }

$running = docker compose ps --status running --services
if ($LASTEXITCODE -ne 0) { throw '¿Está Docker corriendo?' }
if ($running -notcontains 'db') { throw "El contenedor 'db' no está corriendo. Levantalo con: docker compose up -d db" }

# ── Verificar el respaldo ANTES de tocar nada ──────────────────────────────

$inContainer = '/tmp/restore-source.sql.gz'

docker compose cp $source "db:$inContainer"
if ($LASTEXITCODE -ne 0) { throw 'No se pudo copiar el respaldo al contenedor.' }

docker compose exec -T db gzip -t $inContainer
if ($LASTEXITCODE -ne 0) {
    docker compose exec -T db rm -f $inContainer | Out-Null
    throw 'El archivo está dañado: no es un gzip válido. No se tocó la base.'
}

$closing = docker compose exec -T db sh -c "gunzip -c $inContainer | tail -1"
if ($closing -notmatch 'Dump completed') {
    docker compose exec -T db rm -f $inContainer | Out-Null
    throw 'El respaldo está truncado (le falta la línea de cierre de mysqldump). No se tocó la base.'
}

$incomingTables = docker compose exec -T db sh -c "gunzip -c $inContainer | grep -c '^CREATE TABLE'"

# ── Enseñar qué se va a perder ─────────────────────────────────────────────

# La base se elige en la línea de comandos y se cuenta con DATABASE(): así el
# nombre no tiene que ir entre comillas dentro de otras comillas, que es
# donde este tipo de comando se rompe en silencio.
$countSql = 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()'

$currentTables = docker compose exec -T -e "MYSQL_PWD=$password" db sh -c "mysql -u root -N -B $database -e '$countSql'"

Write-Output ''
Write-Output "  Base de datos        $database"
Write-Output "  Ahora tiene          $($currentTables | Select-Object -Last 1) tabla(s)"
Write-Output "  El respaldo trae     $($incomingTables | Select-Object -Last 1) tabla(s)"
Write-Output "  Archivo              $source"
Write-Output ''
Write-Output '  Esto BORRA la base actual y la reemplaza. No hay deshacer.'
Write-Output ''

if (-not $Force) {
    $answer = Read-Host '  Escribí el nombre de la base para confirmar'

    if ($answer -ne $database) {
        docker compose exec -T db rm -f $inContainer | Out-Null
        Write-Output '  Cancelado. No se tocó nada.'
        return
    }
}

# ── Respaldo de seguridad del estado actual ────────────────────────────────

if (-not $SkipSafetyBackup) {
    Write-Output '  Respaldando el estado actual antes de sobrescribirlo ...'

    $safetyDir = Join-Path $projectDir 'storage\backups'
    & (Join-Path $PSScriptRoot 'backup-db.ps1') -Destination $safetyDir -Keep 10
}

# ── Restaurar ──────────────────────────────────────────────────────────────

Write-Output '  Restaurando ...'

# Se recrea el esquema para no dejar una mezcla de dos momentos distintos
# (ver el encabezado). El dump se hizo sin --databases, así que no trae
# CREATE DATABASE y hay que crearla acá.
#
# El nombre va sin comillas invertidas a propósito: dentro de `sh -c` una
# comilla invertida dispara sustitución de comandos, y un identificador
# simple como bdcontapp no las necesita.
$recreate = "mysql -u root -e 'DROP DATABASE IF EXISTS $database; CREATE DATABASE $database CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;'"

docker compose exec -T -e "MYSQL_PWD=$password" db sh -c $recreate
if ($LASTEXITCODE -ne 0) { throw 'No se pudo recrear la base. Revisá el respaldo de seguridad antes de reintentar.' }

docker compose exec -T -e "MYSQL_PWD=$password" db sh -c "gunzip -c $inContainer | mysql -u root $database"
if ($LASTEXITCODE -ne 0) { throw 'La carga falló a medias. La base quedó incompleta: restaurá el respaldo de seguridad.' }

docker compose exec -T db rm -f $inContainer | Out-Null

# ── Comprobar el resultado ─────────────────────────────────────────────────

$restoredTables = docker compose exec -T -e "MYSQL_PWD=$password" db sh -c "mysql -u root -N -B $database -e '$countSql'"

Write-Output ''
Write-Output "  OK  $database restaurada con $($restoredTables | Select-Object -Last 1) tabla(s)."
Write-Output ''
Write-Output '  Antes de usar la aplicación, corré las migraciones pendientes:'
Write-Output '      docker compose exec app php artisan migrate'
