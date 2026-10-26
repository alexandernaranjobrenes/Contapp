# Pivox — Producción en Hostinger, desde GitHub

Pivox corre en el hosting compartido (o Cloud) de Hostinger y se publica
desde GitHub. Se desarrolla en Docker, se hace push a `main` y GitHub
Actions lo despliega solo. También se despliega a mano, desde GitHub o por
SSH. Cloudflare maneja el DNS y guarda las imágenes (R2).

| Archivo | Qué hace |
|---|---|
| `.github/workflows/deploy.yml` (en la raíz del repositorio) | Compila el frontend, corre las pruebas, arma la rama `production` y despliega por SSH |
| `scripts/hostinger/deploy.sh` | El despliegue en el servidor |
| `scripts/hostinger/backup.sh` | El respaldo diario (con `mysql-credentials.php`) |
| `.env.production.example` | La plantilla del `.env` |
| `config/queue.php` (`workers_in_scheduler`) y `routes/console.php` | Las colas atendidas por el cron |
| `config/trustedproxy.php` | La IP y el HTTPS del visitante detrás de Cloudflare |

## Cómo funciona un despliegue

1. **Push a `main`.** GitHub Actions compila el frontend (en el hosting no hay Node) y corre las pruebas: si alguna falla, no se despliega nada.
2. **La rama `production`.** Actions la arma con el código de `main` más `public/build`. Es la única que se instala en el servidor; no se toca a mano.
3. **El despliegue en Hostinger.** Actions entra por SSH y corre `scripts/hostinger/deploy.sh`:
   - pone el sitio en mantenimiento;
   - trae `production`;
   - instala las dependencias sin las de desarrollo;
   - corre las migraciones;
   - arma las cachés (configuración, rutas, eventos, vistas);
   - reinicia las colas y vuelve a abrir el sitio.

   Si algo falla, el sitio queda en mantenimiento y Actions marca el error: se corrige y se despliega de nuevo.

**A mano:** en GitHub, Actions → «Desplegar a Hostinger» → Run workflow. O por SSH, con lo último que compiló Actions:

```bash
cd ~/domains/pivoxcr.com/pivox/contapp && bash scripts/hostinger/deploy.sh
```

**Nunca se edita código en el servidor:** el próximo despliegue lo pisa. Lo único propio del servidor es el `.env`, `storage/` y lo que se sube desde la aplicación.

## 1. El plan y PHP

- **Plan:** Business o Cloud, que tienen SSH y cron.
- **hPanel → Avanzado → Configuración de PHP:**
  - **Versión:** 8.4 (o 8.5). La misma que `PHP_VERSION` en `.github/workflows/deploy.yml`.
  - **Extensiones:** bcmath, exif, fileinfo, gd, intl, mbstring, openssl, pdo_mysql, sodium y zip.
  - **Opciones:** `memory_limit` 512M, `upload_max_filesize` y `post_max_size` 64M, `max_execution_time` 120.
- **hPanel → Avanzado → Acceso SSH:** activalo y anotá IP, puerto (65002) y usuario (`u123456789`).
- **Por SSH, que todo esté:**
  ```bash
  php -v && composer -V && git --version
  php -m | grep -Ei 'bcmath|gd|intl|sodium|zip'
  ```
  Si el `php` de la consola no es la versión elegida, buscá el correcto (`ls /opt/alt/php8*/usr/bin/php`). Usalo en el cron y en la variable `HOSTINGER_PHP_BIN` de GitHub (paso 7).

## 2. La base de datos

- **hPanel → Bases de datos:** creá la base y su usuario (quedan como `u123456789_pivox`).
- **En phpMyAdmin, el tipo de servidor.** Hostinger suele usar **MariaDB**: en ese caso, `DB_CONNECTION=mariadb` en el `.env`.
- **Desarrollo usa MySQL 8 y las pruebas SQLite.** La primera vez, recorré lo principal (asientos, facturas, reportes) antes de dar el sitio por bueno.

## 3. Cloudflare: el DNS

El dominio va en Cloudflare: el dominio propio de R2 lo necesita.

1. Agregá `pivoxcr.com` en Cloudflare y poné sus nameservers en el registrador. En hPanel, el dominio tiene que estar agregado al plan.
2. **Registros:**
   - `A @` → la IP del sitio en Hostinger (hPanel → detalles del plan);
   - `CNAME www` → `pivoxcr.com`;
   - los del correo que muestra hPanel → Correos → DNS (MX, SPF, DKIM), en «DNS only» (nube gris).
3. **SSL:**
   - primero con el proxy apagado (nube gris), activá el SSL en hPanel → Seguridad → SSL;
   - después prendé el proxy (nube naranja) y en Cloudflare SSL/TLS poné **Full (strict)** y «Always Use HTTPS»;
   - si el certificado de Hostinger no se renueva detrás del proxy, instalá un **Origin Certificate** de Cloudflare en hPanel (SSL personalizado).
4. **Rules:**
   - redirección 301 de `www.pivoxcr.com/*` a `https://pivoxcr.com/${1}`;
   - una Cache Rule para `pivoxcr.com/build/*`: «Eligible for cache», un mes en el borde y un año en el navegador (los archivos llevan hash en el nombre). El HTML y `/backoffice` no se cachean.
5. **Security → WAF:** una regla de rate limiting para `/backoffice/login` y `/precios/solicitud`.

Con el proxy prendido, Laravel ve la IP real del visitante gracias a `config/trustedproxy.php` (la red interna y los rangos de Cloudflare).

## 4. Cloudflare R2: las imágenes

1. **El bucket.** Si llevás la base de desarrollo, el mismo `contapp`: las rutas de las imágenes están en la base. Si la base es nueva, uno para producción.
2. **Settings → Custom Domains:** `media.pivoxcr.com`. Apagá la URL `r2.dev`.
3. **Manage R2 API Tokens:** «Object Read & Write» solo para ese bucket → `AWS_ACCESS_KEY_ID` y `AWS_SECRET_ACCESS_KEY`. El endpoint es `https://<ACCOUNT_ID>.r2.cloudflarestorage.com`.

## 5. El correo

En hPanel → Correos, dos buzones:

- **`servicioalcliente@pivoxcr.com`:** recibe. A ese correo responde quien pidió una licencia.
- **`notificaciones@pivoxcr.com`:** el que envía (`MAIL_USERNAME`).

Los registros SPF, DKIM y DMARC van en Cloudflare (paso 3). Hostinger limita cuántos correos por día manda cada buzón: revisalo si se mandan muchos recibos de pago juntos.

## 6. La primera instalación (una sola vez, por SSH)

1. **Una llave para que el servidor lea el repositorio:**
   ```bash
   ssh-keygen -t ed25519 -f ~/.ssh/github_pivox -N ""
   cat ~/.ssh/github_pivox.pub
   printf 'Host github.com\n  IdentityFile ~/.ssh/github_pivox\n' >> ~/.ssh/config
   ```
   La pública va en GitHub → el repositorio → Settings → Deploy keys → Add deploy key, **sin** permiso de escritura.
2. **La rama `production`.** Hacé commit y push de todo a `main`. Actions corre y, como todavía no tiene los secretos, arma `production` y avisa que no desplegó.
3. **El código:**
   ```bash
   cd ~/domains/pivoxcr.com
   git clone --branch production git@github.com:alexandernaranjobrenes/Contapp.git pivox
   cd pivox/contapp
   composer install --no-dev --optimize-autoloader
   ```
4. **El `.env`:** `cp .env.production.example .env` y completalo (`nano .env`). La `APP_KEY`:
   - si llevás la base de desarrollo, la de desarrollo: con ella están cifradas las credenciales de Hacienda;
   - si es nueva, `php artisan key:generate --force`.
5. **Si llevás la base de desarrollo:**
   - sacá el respaldo con `.\docker\backup-db.ps1` y subilo por el administrador de archivos de hPanel;
   - en MariaDB, cambiá la colación de MySQL 8 al importar:
     ```bash
     gunzip -c bdcontapp-AAAA-MM-DD.sql.gz | sed 's/utf8mb4_0900_ai_ci/utf8mb4_unicode_ci/g' | mysql -u USUARIO -p NOMBRE_DE_LA_BASE
     ```
   - **antes de que corra el cron**, revisá en phpMyAdmin la tabla `jobs`. Los recibos de pago que se encolaron en desarrollo se enviarían; borrá los de prueba;
   - subí también `storage/app/public/` (fotos viejas de empleados), si hay.
6. **La carpeta pública:** el dominio tiene que servir `contapp/public`:
   ```bash
   php artisan storage:link
   cd ~/domains/pivoxcr.com
   mv public_html public_html_anterior
   ln -s pivox/contapp/public public_html
   ```
7. **El primer despliegue:** `cd pivox/contapp && bash scripts/hostinger/deploy.sh`.
8. **Si la base es nueva:**
   ```bash
   php artisan db:seed --class=CurrencySeeder --force
   php artisan db:seed --class=ModuleSeeder --force
   php artisan tinker --execute="App\Domains\Licensing\Models\Propietario::create(['name' => 'Tu nombre', 'email' => 'tu@pivoxcr.com', 'password' => 'una-clave-larga']);"
   ```
   La última línea crea tu cuenta del backoffice. Nunca `DemoCompanySeeder` ni los `Test*Seeder`.

## 7. GitHub Actions: los secretos

1. **Una llave para que Actions entre al servidor**, en tu computadora:
   ```bash
   ssh-keygen -t ed25519 -f pivox-actions -N ""
   ```
   La pública (`pivox-actions.pub`) va en hPanel → Acceso SSH → Llaves SSH. La privada va en el secreto `HOSTINGER_SSH_KEY`.
2. **GitHub → el repositorio → Settings → Secrets and variables → Actions:**

   | Secreto | Valor |
   |---|---|
   | `HOSTINGER_SSH_HOST` | la IP del paso 1 |
   | `HOSTINGER_SSH_PORT` | `65002` |
   | `HOSTINGER_SSH_USER` | `u123456789` |
   | `HOSTINGER_SSH_KEY` | el contenido de `pivox-actions` |
   | `HOSTINGER_APP_PATH` | `/home/u123456789/domains/pivoxcr.com/pivox/contapp` |
   | `HOSTINGER_SSH_KNOWN_HOSTS` | opcional: la salida de `ssh-keyscan -p 65002 IP` |

   Variable (pestaña Variables), solo si hace falta: `HOSTINGER_PHP_BIN`, la ruta del php del paso 1.
3. **Probalo:** Actions → «Desplegar a Hostinger» → Run workflow. Borrá `pivox-actions` de tu computadora: la llave ya está en GitHub.

## 8. El cron (hPanel → Avanzado → Cron Jobs)

| Cuándo | Comando |
|---|---|
| Cada minuto | `cd /home/u123456789/domains/pivoxcr.com/pivox/contapp && php artisan schedule:run >> /dev/null 2>&1` |
| Todos los días, 3:00 | `bash /home/u123456789/domains/pivoxcr.com/pivox/contapp/scripts/hostinger/backup.sh >> /home/u123456789/backups/pivox/backup.log 2>&1` |

Si el `php` no es el del PATH, poné la ruta completa en el primero, y `PHP_BIN=/ruta/al/php` delante del segundo.

`schedule:run` corre todo lo programado de `routes/console.php`:

| Tarea | Cuándo |
|---|---|
| `pivox:hacienda-sync` (envía, consulta y entrega comprobantes) | cada minuto |
| Un worker por cola: `hacienda`, `default` (recibos de pago por correo) y `backups` (importar respaldos de compañía) | cada minuto, si `QUEUE_WORKERS_IN_SCHEDULER=true` |
| `pivox:prune-backups`, `pivox:prune-blog-images` | cada hora |
| `pivox:process-journal-schedules` | cada día |
| `pivox:sync-bccr-rates` (tipo de cambio) | 6:00 y 13:00, hora de Costa Rica |
| `pivox:hacienda-certificates` (llaves por vencer) | 7:00, hora de Costa Rica |

En un hosting compartido no puede quedar un proceso corriendo. Por eso cada minuto arranca un worker por cola, que la vacía y termina, de a uno por cola. Un correo o un comprobante puede tardar hasta un minuto en salir.

## 9. Respaldos

- **Los de Hostinger:** hPanel → Archivos → Copias de seguridad (automáticos según el plan). Cubren el servidor entero.
- **`scripts/hostinger/backup.sh`, con el cron del paso 8:**
  - guarda la base en `~/backups/pivox/db-*.sql.gz`, verificada (gzip íntegro, la línea de cierre de mysqldump y que traiga tablas);
  - guarda `public/repositories` con el `.env` en `files-*.tgz`;
  - conserva 14 días (`BACKUP_KEEP_DAYS`);
  - con rclone en `~/bin` y `BACKUP_RCLONE_REMOTE=r2:pivox-backups`, copia fuera del servidor a un bucket privado.
- **Bajalos de vez en cuando**, por el administrador de archivos o SFTP. El `.tgz` lleva las contraseñas del `.env`.
- **Restaurar** (borra la base actual):
  ```bash
  gunzip -c ~/backups/pivox/db-AAAA-MM-DD_HHMM.sql.gz | mysql -u USUARIO -p NOMBRE_DE_LA_BASE
  bash scripts/hostinger/deploy.sh
  ```

## 10. Comprobaciones después del primer despliegue

- `https://pivoxcr.com/robots.txt` trae `Sitemap:` y **no** `Disallow: /` (eso sería `APP_ENV` distinto de `production`).
- `https://pivoxcr.com/sitemap.xml`: todas las direcciones con `https://pivoxcr.com`.
- **El backoffice:** entrar, y subir una imagen a un artículo; tiene que cargar desde `media.pivoxcr.com`.
- **Una solicitud real en `/precios`:** el aviso llega a servicios@ncodedigital.com. Respondela desde Solicitudes y fijate que la respuesta llegue.
- **El programador:** `php artisan schedule:list`, y en un par de minutos, `storage/logs` sin errores del cron.
- **Search Console:** verificá el dominio con un registro TXT en Cloudflare y enviá el sitemap.
- **El contenido:** cargá Planes y precios y los primeros artículos.
- **Facturación electrónica:** cada compañía la configura en producción con su Superusuario.
- La página de privacidad la revisa un abogado antes de anunciar el sitio.

## 11. El día a día

- **Un cambio:** se prueba en Docker, commit y push a `main`. En unos minutos está en producción; en la pestaña Actions de GitHub se ve cómo va, y GitHub avisa por correo si falla.
- **Volver atrás:** `git revert` del commit en `main` y push. Se despliega como cualquier cambio.
- **Un cambio en el `.env` de producción:** editalo por SSH y corré `bash scripts/hostinger/deploy.sh`, porque la configuración está en caché.
- **Mantenimiento a mano:** `php artisan down` y `php artisan up`.
- **Los logs:** `storage/logs/laravel-AAAA-MM-DD.log`, los últimos 14 días.

## 12. Los límites del hosting compartido

- **Las colas van al ritmo del cron:** hasta un minuto de espera.
- **Hostinger puede cortar un proceso muy largo.** Puede pasar al importar el respaldo de una compañía grande: si se corta, se vuelve a intentar.
- **Los correos por día de cada buzón** (paso 5).
- **Con el proxy de Cloudflare:** corta un envío de más de 100 MB y una respuesta que tarda más de 100 s.
- **Si Pivox crece**, el paso siguiente es un VPS con el mismo Docker de desarrollo: workers que no paran, sin límites de procesos.
