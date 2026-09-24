<#
.SYNOPSIS
    Respalda la base de datos de CONTAPP en un archivo comprimido y verificado.

.DESCRIPTION
    La base de datos NO vive en el repositorio ni en OneDrive: vive en un
    volumen de Docker (contapp_contapp_db_data). Eso significa que ni GitHub
    ni la sincronización de archivos la respaldan, y que un
    `docker compose down -v` la borra sin dejar de dónde traerla.

    Este script es el único respaldo que existe. Conviene que corra a diario.

    ── Por qué el dump corre DENTRO del contenedor db ──────────────────────

    El contenedor `app` también tiene mysqldump, pero es el cliente de
    MariaDB 11.8 apuntando a un servidor MySQL 8.0. Esa combinación produce
    respaldos sutilmente dañados: MariaDB no conoce las colaciones
    utf8mb4_0900_* de MySQL 8 y las escribe mal en el dump. El contenedor
    `db` trae el mysqldump 8.0 que corresponde al servidor, y es el que se
    usa acá.

    ── Por qué el archivo se arma dentro del contenedor y luego se copia ───

    PowerShell convierte a texto lo que pasa por una redirección `>`, y eso
    corrompe un .gz. El dump se comprime dentro del contenedor, se verifica
    ahí mismo, y se trae con `docker compose cp`, que copia bytes.

    ── La verificación no es decorativa ───────────────────────────────────

    Un respaldo que falló en silencio es peor que ninguno, porque uno cree
    que lo tiene. Se comprueban tres cosas antes de dar el archivo por bueno:
    que el gzip esté íntegro, que el dump traiga su línea de cierre
    (`-- Dump completed`, que solo aparece si mysqldump terminó) y que
    contenga tablas.

.PARAMETER Destination
    Carpeta donde queda el respaldo. Por defecto, una carpeta de OneDrive:
    un archivo comprimido por día es exactamente para lo que OneDrive sirve
    bien, al contrario de los quince mil archivos de node_modules.

.PARAMETER Keep
    Cuántos respaldos conservar. Los más viejos se borran.

.EXAMPLE
    .\docker\backup-db.ps1

.EXAMPLE
    .\docker\backup-db.ps1 -Destination D:\Respaldos -Keep 30
#>
param(
    [string]$Destination = "$env:USERPROFILE\OneDrive\Respaldos\CONTAPP",
    [int]$Keep = 14
)

$ErrorActionPreference = 'Stop'

# El script vive en contapp/docker/; el docker-compose.yml está un nivel arriba.
$projectDir = Split-Path $PSScriptRoot -Parent
Set-Location $projectDir

function Read-DotEnv {
    param([string]$Path)

    if (-not (Test-Path $Path)) {
        throw "No se encontró $Path. Sin el .env no se sabe ni el nombre de la base ni la contraseña."
    }

    $values = @{}

    foreach ($line in Get-Content $Path) {
        $trimmed = $line.Trim()

        if ($trimmed -eq '' -or $trimmed.StartsWith('#')) { continue }

        $split = $trimmed.IndexOf('=')
        if ($split -lt 1) { continue }

        $key = $trimmed.Substring(0, $split).Trim()
        $value = $trimmed.Substring($split + 1).Trim().Trim('"').Trim("'")

        $values[$key] = $value
    }

    return $values
}

$settings = Read-DotEnv (Join-Path $projectDir '.env')

$database = $settings['DB_DATABASE']
$password = $settings['DB_ROOT_PASSWORD']

if ([string]::IsNullOrWhiteSpace($database)) { throw 'DB_DATABASE está vacío en el .env.' }
if ([string]::IsNullOrWhiteSpace($password)) { throw 'DB_ROOT_PASSWORD está vacío en el .env.' }

# El contenedor tiene que estar arriba: un dump contra una base apagada no
# falla con un mensaje claro, devuelve un archivo casi vacío.
$running = docker compose ps --status running --services
if ($LASTEXITCODE -ne 0) { throw 'No se pudo consultar el estado de los contenedores. ¿Está Docker corriendo?' }
if ($running -notcontains 'db') { throw "El contenedor 'db' no está corriendo. Levantalo con: docker compose up -d db" }

$stamp = Get-Date -Format 'yyyy-MM-dd-HHmm'
$fileName = "$database-$stamp.sql.gz"
$inContainer = "/tmp/$fileName"

Write-Output "Respaldando $database ..."

# MYSQL_PWD en vez de -p en la línea de comandos: evita el aviso de mysqldump
# y que la contraseña quede visible en la lista de procesos del contenedor.
$dumpCommand = "mysqldump -u root --single-transaction --routines --triggers --events --no-tablespaces $database | gzip -c > $inContainer"

docker compose exec -T -e "MYSQL_PWD=$password" db sh -c $dumpCommand
if ($LASTEXITCODE -ne 0) {
    docker compose exec -T db rm -f $inContainer | Out-Null
    throw 'mysqldump falló. El archivo parcial se descartó.'
}

# ── Verificación, antes de darlo por bueno ─────────────────────────────────

docker compose exec -T db gzip -t $inContainer
if ($LASTEXITCODE -ne 0) {
    docker compose exec -T db rm -f $inContainer | Out-Null
    throw 'El archivo comprimido salió dañado. Se descartó.'
}

# `-- Dump completed` lo escribe mysqldump al final: si el proceso se cortó a
# medias, la línea no está y el dump serviría a medias sin avisarlo.
$closing = docker compose exec -T db sh -c "gunzip -c $inContainer | tail -1"
if ($closing -notmatch 'Dump completed') {
    docker compose exec -T db rm -f $inContainer | Out-Null
    throw 'El dump no trae su línea de cierre: quedó truncado. Se descartó.'
}

$tableCount = docker compose exec -T db sh -c "gunzip -c $inContainer | grep -c '^CREATE TABLE'"
if ([int]$tableCount -lt 1) {
    docker compose exec -T db rm -f $inContainer | Out-Null
    throw 'El dump no contiene ninguna tabla. Se descartó.'
}

# ── Traer el archivo y limpiar ─────────────────────────────────────────────

if (-not (Test-Path $Destination)) {
    New-Item -ItemType Directory -Path $Destination -Force | Out-Null
}

$target = Join-Path $Destination $fileName

docker compose cp "db:$inContainer" $target
if ($LASTEXITCODE -ne 0) {
    throw "No se pudo copiar el respaldo a $target."
}

docker compose exec -T db rm -f $inContainer | Out-Null

$size = [math]::Round((Get-Item $target).Length / 1KB, 1)

Write-Output "OK  $target"
Write-Output "    $tableCount tablas, $size KB, gzip verificado."

# ── Rotación ──────────────────────────────────────────────────────────────

$existing = Get-ChildItem -Path $Destination -Filter "$database-*.sql.gz" |
    Sort-Object LastWriteTime -Descending

if ($existing.Count -gt $Keep) {
    $obsolete = $existing | Select-Object -Skip $Keep

    foreach ($file in $obsolete) {
        Remove-Item $file.FullName -Force
        Write-Output "    se descartó el respaldo viejo $($file.Name)"
    }
}

$kept = [math]::Min($existing.Count, $Keep)
Write-Output "    $kept respaldo(s) conservado(s) en $Destination"
