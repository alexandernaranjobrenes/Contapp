#!/usr/bin/env bash
# Despliegue en Hostinger (docs/produccion.md). Lo corre GitHub Actions
# después de cada push a main, y se puede correr a mano por SSH:
#
#     bash scripts/hostinger/deploy.sh
#
# Despliega la rama «production»: el código de main con el frontend ya
# compilado (public/build) por GitHub Actions, porque en el hosting no hay
# Node. Variables opcionales: PHP_BIN (un php que no sea el del PATH),
# DEPLOY_BRANCH (production) y DEPLOY_PATH (la carpeta de Pivox; por defecto,
# la de este archivo). GitHub Actions manda este archivo por SSH
# (`bash -s`), para correr la versión del commit que despliega, con
# DEPLOY_PATH.
#
# Todo va dentro de main(): bash la lee entera antes de empezar, así que el
# git reset de abajo puede reemplazar este archivo sin cortar lo que corre.
set -euo pipefail

main() {
    cd "${DEPLOY_PATH:-$(dirname "$0")/../..}"

    local php_bin="${PHP_BIN:-php}"
    local branch="${DEPLOY_BRANCH:-production}"
    local composer=(composer)
    if [ -n "${PHP_BIN:-}" ]; then
        composer=("$php_bin" "$(command -v composer)")
    fi

    if [ ! -f .env ]; then
        echo "Falta el .env: copiá .env.production.example a .env y completalo (docs/produccion.md)." >&2
        exit 1
    fi

    if grep -q "CAMBIAR" .env; then
        echo "El .env todavía tiene valores «CAMBIAR»:" >&2
        grep -n "CAMBIAR" .env | cut -d= -f1 >&2
        exit 1
    fi

    if ! grep -q "^APP_ENV=production" .env || ! grep -q "^APP_DEBUG=false" .env; then
        echo "El .env no es de producción: falta APP_ENV=production o APP_DEBUG=false." >&2
        exit 1
    fi

    # Si algo falla, el sitio queda en mantenimiento: mejor una página que dice
    # «en mantenimiento» que el código nuevo contra una base sin migrar. Se
    # corrige y se vuelve a correr (o se levanta con artisan up).
    trap 'echo "El despliegue falló: el sitio quedó en mantenimiento. Corregí el error y volvé a correr este script, o levantalo con: $php_bin artisan up" >&2' ERR

    echo "→ Mantenimiento"
    # La primera vez todavía no hay vendor: no hay nada que poner en mantenimiento.
    if [ -f vendor/autoload.php ]; then
        "$php_bin" artisan down --retry=15
    fi

    echo "→ Código ($branch)"
    git fetch --prune origin "$branch"
    git reset --hard "origin/$branch"
    echo "   $(git log -1 --format='%h %s')"

    echo "→ Dependencias"
    "${composer[@]}" install --no-dev --no-interaction --prefer-dist --optimize-autoloader

    echo "→ Migraciones"
    "$php_bin" artisan migrate --force

    echo "→ Cachés"
    # Configuración, eventos, rutas y vistas. Por eso un cambio en el .env pide
    # volver a correr este script.
    "$php_bin" artisan optimize

    # Los workers que arranca el programador terminan solos en menos de un
    # minuto; esto les avisa que salgan antes, al terminar el trabajo en curso.
    "$php_bin" artisan queue:restart

    "$php_bin" artisan up
    echo "Listo: $(grep '^APP_URL=' .env | cut -d= -f2-)"
}

main "$@"
