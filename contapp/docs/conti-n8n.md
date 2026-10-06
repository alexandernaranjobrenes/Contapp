# Conti en n8n: cómo armar el flujo

Conti es el asistente de CONTAPP. El chat vive en CONTAPP (botón **Conti** de la barra superior) y el «cerebro» es un flujo de n8n con un AI Agent. Esta guía explica cómo conectarlos.

## Cómo funciona

1. La persona escribe en el chat de CONTAPP.
2. CONTAPP, desde el servidor, le pasa el mensaje al **Chat Trigger** de n8n con usuario y contraseña (autenticación básica). En la `metadata` del mensaje va un **pase** (`contiToken`) que vale 15 minutos, y datos de contexto: nombre, rol, compañía, pantalla, fecha.
3. El **AI Agent** responde. Cuando necesita datos, llama a la **API de Conti** de CONTAPP con ese pase. La API responde como esa persona, en su compañía y con sus permisos por pantalla, sin datos sensibles.
4. Para guardar algo, el agente lo **prepara** y le da a la persona un enlace. La persona lo revisa en CONTAPP y toca «Confirmar y guardar». El agente no puede confirmar: no tiene la sesión de la persona.

El pase nunca pasa por el navegador, y el Chat Trigger solo acepta mensajes de CONTAPP.

## 1. En CONTAPP

Agregá esto al `.env`:

```
CONTI_WEBHOOK_URL=https://tu-n8n.com/webhook/xxxxxxxx/chat
CONTI_WEBHOOK_USER=contapp
CONTI_WEBHOOK_PASSWORD=una-contraseña-larga
CONTI_TIMEOUT=120
```

- `CONTI_WEBHOOK_URL` es la URL de producción del Chat Trigger (paso 2.1). Mientras esté vacía, Conti no aparece en la aplicación.
- `APP_URL` tiene que ser la dirección pública de CONTAPP: con ella se arman los enlaces de confirmación.
- La primera vez, corré `php artisan migrate` (crea `conti_tokens` y `conti_actions`; no toca nada más).

## 2. El flujo en n8n

Cuatro piezas: Chat Trigger → AI Agent, con un modelo, una memoria y cinco herramientas.

### 2.1 Chat Trigger («When chat message received»)

- **Make Chat Publicly Available:** activado.
- **Mode:** Embedded Chat.
- **Authentication:** Basic Auth, con una credencial que tenga el mismo usuario y contraseña que pusiste en `CONTI_WEBHOOK_USER` y `CONTI_WEBHOOK_PASSWORD`.
- **Response Mode:** «When Last Node Finishes» (o «Streaming»: CONTAPP entiende los dos).
- Copiá la **Chat URL de producción** a `CONTI_WEBHOOK_URL`, y activá el flujo.
- No le cambies el nombre al nodo: las herramientas lo usan para leer el pase. Si se lo cambiás, cambialo también en las expresiones de abajo.

### 2.2 AI Agent

- **Source for Prompt:** Connected Chat Trigger Node.
- **System Message:** el contenido de `docs/prompt-agente-ayuda-contapp.md` (sin el comentario del inicio).
- **Max Iterations:** 10 alcanza; una respuesta suele usar entre una y cuatro herramientas.

### 2.3 Modelo y memoria

- **Chat Model:** uno que maneje bien herramientas y español, por ejemplo Claude Sonnet 5.5 (`claude-sonnet-5-5`).
- **Memory:** «Simple Memory», con la sesión del Chat Trigger (la que viene por defecto) y unos 20 mensajes de contexto. CONTAPP arma la sesión con la persona y la compañía (`u{usuario}-c{compañía}-…`), así que dos personas nunca comparten memoria.

### 2.4 Las cinco herramientas

Son cinco nodos **HTTP Request Tool** conectados al AI Agent. En todos:

- **Headers:**
  - `Authorization`: `Bearer {{ $('When chat message received').item.json.metadata.contiToken }}`
  - `Accept`: `application/json`
- **Options → Response → Never Error:** activado. Así un 403 («no tenés permiso…») o un 422 («falta tal dato») le llega al agente como respuesta, y se lo puede explicar a la persona. Sin esto, la herramienta falla y el agente no sabe por qué.

En la tabla, `{APP_URL}` es la dirección de CONTAPP (la misma de `APP_URL`). El nombre y la descripción son los que lee el modelo para decidir qué usar: copialos tal cual.

| Nombre | Descripción para el modelo | Método y URL | Parámetros |
|---|---|---|---|
| `conti_contexto` | Quién es la persona, su compañía, sus permisos y qué consultas, reportes y acciones tiene. Con ver y clave, el detalle de una. | GET `{APP_URL}/api/conti/contexto` | Query: `ver` = `{{ $fromAI('ver', 'opcional: consulta, reporte o accion', 'string') }}`, `clave` = `{{ $fromAI('clave', 'opcional: la clave de la consulta, el reporte o la acción', 'string') }}` |
| `conti_consultar` | Registros de una consulta (socios, asientos, partidas-abiertas, articulos…), con búsqueda, filtros y páginas. Con id, el detalle de uno. | GET `{APP_URL}/api/conti/datos/{{ $fromAI('consulta', 'clave de la consulta, ej. socios', 'string') }}` | Query «Using JSON»: `{{ $fromAI('parametros', 'objeto JSON con buscar, desde, hasta, limite, pagina, id y los filtros de la consulta; {} si no hace falta ninguno', 'json') }}` |
| `conti_reporte` | Corre un reporte de CONTAPP (balance-comprobacion, estado-resultados, mayor, antiguedad-saldos…). | GET `{APP_URL}/api/conti/reportes/{{ $fromAI('reporte', 'clave del reporte', 'string') }}` | Query «Using JSON»: `{{ $fromAI('parametros', 'objeto JSON con los parámetros del reporte; {} para los valores por defecto', 'json') }}` |
| `conti_preparar_accion` | Prepara algo para guardar. No guarda: devuelve un resumen y el enlace para que la persona lo confirme. | POST `{APP_URL}/api/conti/acciones` | Body JSON: `{{ $fromAI('solicitud', 'objeto JSON {"accion": "clave de la acción", "datos": {...}}', 'json') }}` |
| `conti_estado_accion` | En qué quedó una acción preparada: pendiente, guardado, descartado, no se pudo guardar o vencido. | GET `{APP_URL}/api/conti/acciones/{{ $fromAI('id', 'el id de la acción preparada', 'string') }}` | — |

> Según la versión de n8n, el botón ✨ junto a cada campo («Let the model define this parameter») inserta el `$fromAI(...)` por vos. Los nombres de las opciones pueden variar un poco entre versiones.

### 2.5 Probarlo

Probalo desde CONTAPP, no desde el chat de prueba de n8n: ese chat no manda el pase, así que las herramientas responden 401. Para ver qué hizo el agente en cada paso, revisá las ejecuciones del flujo en n8n.

## 3. Lo que la API cuida

- **Permisos:** cada consulta, reporte y acción pide la pantalla del menú correspondiente, igual que la aplicación. Consultar pide Lectura; preparar algo para guardar pide Lectura y escritura. Un Administrador con acceso a pocas pantallas solo ve esas.
- **Compañía:** todo pasa por el filtro de la compañía activa; nada de otra compañía, ni pidiéndolo por id.
- **Datos sensibles:** nunca salen correos, teléfonos, direcciones, números de identificación, número de asegurado, fecha de nacimiento ni contraseñas. Las cuentas bancarias, con los últimos cuatro dígitos. Las anotaciones confidenciales de un trabajador, nunca.
- **Licencia:** suspendida o revocada, la API no responde. Vencida, deja consultar pero no preparar nada.
- **Guardar:** al preparar, CONTAPP valida los datos y prueba la operación en seco (la corre y la deshace), así un período cerrado o un asiento que no cuadra se avisan antes. Al confirmar, vuelve a revisar los permisos y la licencia, y lo prepara de nuevo con los datos de ese momento. Si el resultado no es exactamente lo que la persona vio, no lo guarda.
- **Límites:** 12 mensajes por minuto por persona y 120 consultas por minuto a la API.

## 4. Si algo no anda

| Lo que se ve | Causa probable |
|---|---|
| No aparece el botón Conti | `CONTI_WEBHOOK_URL` vacía, o la persona no está en ninguna compañía. |
| «Conti no está disponible en este momento» | CONTAPP no llega a n8n: la URL está mal o n8n está caído. |
| «Conti no pudo responder esta vez» | n8n respondió con un error: el usuario o la contraseña no coinciden (401), o el flujo está inactivo o falló. Mirá las ejecuciones en n8n. |
| El agente dice que no tiene acceso (401 en las herramientas) | La expresión del pase en `Authorization` no lee la metadata: revisá el nombre del nodo del Chat Trigger. |
| Tarda y se corta | Subí `CONTI_TIMEOUT`. El nginx de Docker espera hasta 300 segundos. |
| Los enlaces de confirmación llevan a otra dirección | Revisá `APP_URL`. |
