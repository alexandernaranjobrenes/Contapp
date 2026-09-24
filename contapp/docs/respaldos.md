# CONTAPP — Respaldos y recuperación

## Qué está respaldado y qué no

| Qué | Dónde vive | Respaldo |
|---|---|---|
| Código y migraciones | `.git` + GitHub | GitHub (`origin/main`) y bundle |
| Configuración local (`.env`) | solo en disco | **ninguno** — no se versiona a propósito |
| Base de datos `bdcontapp` | volumen de Docker | `backup-db.ps1` |
| Fotografías de empleados | `storage/app/public` | `backup-db.ps1` **no las incluye** |

Las dos filas sin respaldo automático son deliberadas la primera y una
limitación conocida la última:

- **`.env` no se versiona** porque lleva contraseñas. Guardá una copia en un
  gestor de contraseñas, no en el repositorio. Sin él, la aplicación no
  arranca y hay que reconstruirlo desde `.env.example`.
- **Las fotografías de empleados** viven en `storage/app/public/employees/`.
  Son archivos, no datos de la base, y el dump no las toca. Si importan,
  copiá esa carpeta aparte.

## Por qué la base de datos es el punto frágil

La base **no está en el repositorio ni en OneDrive**: vive en un volumen de
Docker (`contapp_contapp_db_data`). Ni GitHub ni la sincronización de
archivos la respaldan, y un `docker compose down -v` la borra sin dejar de
dónde traerla.

El nombre de ese volumen sale del nombre del proyecto, que está **fijo** en
`docker-compose.yml` (`name: contapp`) y no se deriva del nombre de la
carpeta. Si se derivara, clonar el proyecto en una carpeta con otro nombre
haría que Docker creara volúmenes nuevos y vacíos, y parecería que la base se
borró.

## Uso diario

```powershell
# Base de datos (hacelo a diario)
.\docker\backup-db.ps1

# Historial completo del repositorio (semanal alcanza)
.\docker\backup-repo.ps1
```

Ambos escriben en `%USERPROFILE%\OneDrive\Respaldos\CONTAPP` y rotan solos:
14 respaldos de base, 6 bundles. Se cambia con `-Destination` y `-Keep`.

Un archivo comprimido por día es exactamente para lo que OneDrive sirve bien.
Lo que **no** conviene sincronizar es el directorio `.git` ni las carpetas
`node_modules` y `vendor` — ver «El repositorio y OneDrive» más abajo.

### Automatizarlo

Programador de tareas de Windows, a diario:

```
Programa:   powershell.exe
Argumentos: -NoProfile -ExecutionPolicy Bypass -File "C:\...\contapp\docker\backup-db.ps1"
Iniciar en: C:\...\contapp
```

## Qué verifican los scripts

Un respaldo que falló en silencio es peor que ninguno, porque uno cree que lo
tiene. Antes de dar un archivo por bueno se comprueba:

- **`backup-db.ps1`** — que el gzip esté íntegro, que el dump traiga su línea
  de cierre `-- Dump completed` (solo aparece si `mysqldump` terminó; sin ella
  el dump está truncado) y que contenga al menos una tabla. Si algo falla, el
  archivo parcial se descarta en vez de quedar ahí pareciendo un respaldo.
- **`backup-repo.ps1`** — `git bundle verify`, y además avisa si hay commits
  locales que no están en ningún remoto: ese es el trabajo que un respaldo de
  GitHub no tiene.

El dump corre **dentro del contenedor `db`** y no desde `app`, porque `app`
trae el cliente de MariaDB 11.8 contra un servidor MySQL 8.0 y esa
combinación produce respaldos sutilmente dañados: MariaDB no conoce las
colaciones `utf8mb4_0900_*` de MySQL 8 y las escribe mal.

## Restaurar la base de datos

```powershell
.\docker\restore-db.ps1 -File "$env:USERPROFILE\OneDrive\Respaldos\CONTAPP\bdcontapp-2026-09-23-2130.sql.gz"
docker compose exec app php artisan migrate
```

Esto **borra** la base actual y la reemplaza. El script tiene dos
salvaguardas:

1. **Respalda lo que hay antes de sobrescribirlo**, en `storage/backups`.
   Cuesta segundos y es lo único que salva de restaurar el archivo
   equivocado — que es el error que de verdad ocurre.
2. **Pide escribir el nombre de la base** para confirmar. Un «¿seguro? [s/n]»
   se contesta que sí por reflejo; escribir `bdcontapp` obliga a leer qué se
   está por borrar.

Se recrea el esquema en vez de cargar encima, a propósito: cargar un dump
sobre una base con datos no la deja como estaba el día del respaldo — las
tablas del dump se reemplazan, pero las creadas después siguen ahí. El
resultado sería una mezcla de dos momentos que no corresponde a ninguno.

El paso de `artisan migrate` importa: si el respaldo es de antes de una
migración, la base queda vieja para el código actual.

## Restaurar el repositorio desde un bundle

```powershell
git clone "$env:USERPROFILE\OneDrive\Respaldos\CONTAPP\contapp-2026-09-23.bundle" CONTAPP
cd CONTAPP
git remote set-url origin https://github.com/alexandernaranjobrenes/Contapp.git
```

El bundle trae **todas las ramas y todo el historial**. Después hay que
reponer lo que no se versiona:

```powershell
copy <tu copia de .env> contapp\.env
docker compose up -d
docker compose exec app php artisan key:generate   # solo si el .env es nuevo
docker compose exec app php artisan migrate
```

### Rutas largas de Windows

`core.longpaths` tiene que estar activo o el checkout falla con
«Filename too long» en las migraciones de nombre largo:

```powershell
git config --global core.longpaths true
```

Es un tropiezo real: el repositorio tiene rutas de 104 caracteres, y con el
límite de 260 de Windows basta clonar en una carpeta algo profunda para que
la recuperación falle. Conviene clonar en una ruta corta (`C:\dev\`).

## El repositorio y OneDrive

Hoy el proyecto vive dentro de OneDrive. Conviene sacarlo, y la razón no es
teórica:

- OneDrive puede tener tomado un archivo que git está reescribiendo (`index`,
  `packed-refs`, objetos), y git falla a mitad de escritura.
- **Files On-Demand** puede deshidratar `.git/objects` en marcadores de
  posición; git lee un stub y reporta corrupción.
- `git gc` empaqueta objetos sueltos y los borra, lo que OneDrive presenta
  como un borrado masivo. **Nunca le des «Restaurar»** a ese aviso: devolvería
  objetos que git ya empaquetó y dejaría el almacén inconsistente.
- Dos computadoras sincronizando el mismo `.git` lo rompen sin remedio.

Además se sincronizan **más de 15.000 archivos** de `node_modules` y `vendor`
que git ignora y que **Docker tampoco usa** —los tapa con volúmenes
nombrados—; solo le sirven al autocompletado del IDE. Ese tráfico es el que
hace que OneDrive esté peleando por archivos justo cuando git escribe.

### Cómo moverlo

Con un clon nuevo, **no copiando la carpeta**: una copia arrastraría cualquier
daño latente.

```powershell
cd C:\dev
git clone https://github.com/alexandernaranjobrenes/Contapp.git Contapp
copy "<ruta vieja>\contapp\.env" "C:\dev\Contapp\contapp\.env"
cd Contapp\contapp
docker compose up -d
```

Los volúmenes de Docker no se mueven ni se tocan: la base sigue donde estaba
y el proyecto la vuelve a encontrar porque el nombre está fijo en el compose.

### Si preferís dejarlo en OneDrive

Es la opción inferior, pero si hay una razón: excluí la carpeta en
*Configuración de OneDrive → Cuenta → Elegir carpetas*, o al menos marcá el
árbol como *Mantener siempre en este dispositivo* para que Files On-Demand no
deshidrate `.git/objects`.

## Comprobar que todo sigue sano

```powershell
git fsck                                    # integridad del repositorio
docker volume ls --filter name=contapp      # que los volúmenes existan
dir "$env:USERPROFILE\OneDrive\Respaldos\CONTAPP"   # que haya respaldos recientes
```

Tarda segundos y es lo que evita descubrir un problema el día que hace falta
el respaldo.
