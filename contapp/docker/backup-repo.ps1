<#
.SYNOPSIS
    Respaldo frío del repositorio completo en UN archivo verificado.

.DESCRIPTION
    GitHub ya es el respaldo del día a día, y alcanza para lo que más pasa:
    que esta máquina se rompa. Lo que GitHub no cubre es el otro conjunto de
    casos, menos probable y más definitivo:

      · un `push --force` que reescribe historia y se lleva commits;
      · un repositorio remoto borrado o una cuenta suspendida;
      · una rama local que nunca se subió.

    Un bundle es el historial entero —todas las ramas, todos los tags— en un
    solo archivo, y se restaura clonándolo como si fuera un remoto:

        git clone contapp-2026-09-23.bundle CONTAPP

    Por ser un archivo único y comprimido, es un buen candidato para
    OneDrive; al contrario del directorio .git, que no lo es.

    ── Por qué se verifica ────────────────────────────────────────────────

    `git bundle verify` comprueba que el archivo esté completo y que el
    repositorio pueda reconstruirse desde él. Sin ese paso uno guarda un
    archivo y supone que sirve, que es la forma habitual de descubrir que no
    servía el día que hacía falta.

    ── Lo que además avisa ────────────────────────────────────────────────

    Si hay commits locales que no están en ningún remoto, el script lo dice.
    Ese es el trabajo que un respaldo de GitHub no tiene.

.PARAMETER Destination
    Carpeta donde queda el bundle.

.PARAMETER Keep
    Cuántos bundles conservar.

.EXAMPLE
    .\docker\backup-repo.ps1
#>
param(
    [string]$Destination = "$env:USERPROFILE\OneDrive\Respaldos\CONTAPP",
    [int]$Keep = 6
)

$ErrorActionPreference = 'Stop'

# El repositorio es la carpeta que contiene a contapp/, no contapp/ misma.
$projectDir = Split-Path $PSScriptRoot -Parent
$repoDir = Split-Path $projectDir -Parent
Set-Location $repoDir

# El git de MSYS no puede con https en esta máquina (una directiva de Control
# de aplicaciones bloquea libcurl-4.dll), pero `bundle` es una operación
# local. Se usa el git del sistema, que es el que funciona para todo.
$git = 'C:\Program Files\Git\cmd\git.exe'
if (-not (Test-Path $git)) { $git = 'git' }

$script:gitExitCode = 0

<#
    Git escribe en stderr cosas que NO son errores: el progreso del
    empaquetado, el "is okay" de verify, los refs que contiene el bundle. Con
    ErrorActionPreference en 'Stop', PowerShell 5.1 convierte cualquier stderr
    de un ejecutable nativo en un error terminante, y el script moriría con un
    NativeCommandError justo cuando todo salió bien.

    Este envoltorio baja la preferencia solo alrededor de la llamada y deja
    que la decisión la tome el código de salida, que es el único que
    distingue de verdad éxito de fracaso.
#>
function Invoke-Git {
    param([Parameter(ValueFromRemainingArguments = $true)][string[]]$Arguments)

    $previous = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'

    try {
        $output = & $git @Arguments 2>&1
        $script:gitExitCode = $LASTEXITCODE

        return $output
    }
    finally {
        $ErrorActionPreference = $previous
    }
}

$inside = Invoke-Git rev-parse --is-inside-work-tree
if ($script:gitExitCode -ne 0 -or "$inside".Trim() -ne 'true') {
    throw "$repoDir no es un repositorio de git."
}

if (-not (Test-Path $Destination)) {
    New-Item -ItemType Directory -Path $Destination -Force | Out-Null
}

$stamp = Get-Date -Format 'yyyy-MM-dd'
$target = Join-Path $Destination "contapp-$stamp.bundle"

Write-Output "Empaquetando el historial de $repoDir ..."

# --quiet va ANTES del archivo: después, git lo toma como un ref a empaquetar.
Invoke-Git bundle create --quiet $target --all | Out-Null
if ($script:gitExitCode -ne 0) { throw 'No se pudo crear el bundle.' }

Invoke-Git bundle verify --quiet $target | Out-Null
if ($script:gitExitCode -ne 0) {
    Remove-Item $target -Force
    throw 'El bundle no pasó la verificación. Se descartó.'
}

$size = [math]::Round((Get-Item $target).Length / 1MB, 2)
$commits = "$(Invoke-Git rev-list --all --count)".Trim()

Write-Output ''
Write-Output "OK  $target"
Write-Output "    $commits commit(s), $size MB, verificado."

# ── Lo que no está en ningún remoto ────────────────────────────────────────

$unpushed = Invoke-Git log --oneline --all --not --remotes

if ($unpushed) {
    Write-Output ''
    Write-Output '    Atención: hay commits que no están en ningún remoto.'
    Write-Output '    Este bundle es el único respaldo que los contiene:'
    $unpushed | ForEach-Object { Write-Output "      $_" }
}

# ── Rotación ──────────────────────────────────────────────────────────────

$existing = Get-ChildItem -Path $Destination -Filter 'contapp-*.bundle' |
    Sort-Object LastWriteTime -Descending

if ($existing.Count -gt $Keep) {
    foreach ($file in ($existing | Select-Object -Skip $Keep)) {
        Remove-Item $file.FullName -Force
        Write-Output "    se descartó el bundle viejo $($file.Name)"
    }
}
