import { reactive } from 'vue';

/**
 * La entrada al sistema. Al iniciar sesión, la pantalla se cubre con el logo
 * de Pivox mientras arranca lo que la primera pantalla necesita (sus datos,
 * el tema y la tipografía de la compañía), y se despide con un saludo y la
 * compañía a la que se entra. Mismo espíritu que el cambio de compañía
 * (companySwitch.js), pero sin errores que manejar: la ventana aparece cuando
 * el servidor ya inició la sesión. Unas credenciales incorrectas vuelven al
 * formulario como siempre, sin animación.
 *
 * El estado vive acá y lo dibuja StartupHost.vue, montado al lado de la
 * página en app.js: la entrada empieza en la pantalla de acceso y termina en
 * otra.
 */

// Lo mínimo que se ve el logo arrancando, aunque todo ya haya cargado: si se
// abre y se cierra en un parpadeo, no se lee como una entrada.
const MIN_VISIBLE_MS = 1400;
// Lo más que se espera a la tipografía del tema: nunca se queda pegada.
const MAX_WAIT_MS = 4000;
// Lo que se ve el saludo antes de irse.
const DONE_MS = 950;
// Lo que dura la salida en StartupHost.vue.
const LEAVE_MS = 400;
// Sin movimiento (prefers-reduced-motion), cada paso es más corto: no hay
// ninguna animación que mirar.
const CALM_MS = 600;

export const startup = reactive({
    visible: false,
    // 'loading' | 'done' | 'leaving'
    status: 'idle',
    theme: null,
    name: null, // el nombre de pila
    entering: null, // 'Entrando a Compañía S.A.', 'Entrando al backoffice' o null
});

// La puerta por la que se está entrando, mientras dura el envío del
// formulario: 'app' (las cuentas de compañía) o 'backoffice' (Propietario).
let armed = null;
let timers = [];
let runs = 0;

function later(fn, ms) {
    timers.push(setTimeout(fn, ms));
}

function clearTimers() {
    timers.forEach(clearTimeout);
    timers = [];
}

/**
 * Las opciones de la visita del formulario que inicia sesión (login, activar
 * una licencia, aceptar una invitación). Si la respuesta trae la sesión
 * iniciada, StartupHost abre la entrada en el mismo cuadro en que se pinta la
 * página nueva: nunca se ve la pantalla de destino antes del logo.
 */
export function startupVisit(options = {}, door = 'app') {
    return {
        ...options,
        onStart: (visit) => {
            armed = door;
            return options.onStart?.(visit);
        },
        onFinish: (visit) => {
            armed = null;
            options.onFinish?.(visit);
        },
    };
}

/**
 * Cambió quién tiene la sesión en la página (lo vigila StartupHost). Si fue
 * el formulario que se acaba de enviar, empieza la entrada.
 */
export function sessionChanged(props, [userId, ownerId], [prevUserId, prevOwnerId]) {
    const entered = armed === 'backoffice'
        ? ownerId != null && ownerId !== prevOwnerId
        : armed === 'app' && userId != null && userId !== prevUserId;

    if (! entered) return;

    const door = armed;
    armed = null;
    begin(props, door);
}

function begin(props, door) {
    clearTimers();
    const run = ++runs;
    const calm = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false;

    startup.theme = props.companyTheme;
    startup.name = firstName(door === 'backoffice' ? props.propietario?.name : props.auth?.user?.name);
    startup.entering = entering(props, door);
    startup.status = 'loading';
    startup.visible = true;

    const startedAt = performance.now();

    systemReady().then(() => {
        if (run !== runs) return;

        const wait = Math.max(0, (calm ? CALM_MS : MIN_VISIBLE_MS) - (performance.now() - startedAt));

        later(() => {
            startup.status = 'done';
            later(close, calm ? CALM_MS : DONE_MS);
        }, wait);
    });
}

// La primera pantalla ya está en la página; falta la tipografía del tema de
// la compañía, que el navegador pide recién al pintar el texto. Dos cuadros
// después ya la pidió: se espera a que llegue, con un tope.
function systemReady() {
    const fonts = new Promise((resolve) => {
        requestAnimationFrame(() => requestAnimationFrame(() => {
            (document.fonts?.ready ?? Promise.resolve()).then(resolve, resolve);
        }));
    });

    return Promise.race([fonts, new Promise((resolve) => later(resolve, MAX_WAIT_MS))]);
}

function close() {
    clearTimers();
    startup.status = 'leaving';

    later(() => {
        startup.visible = false;
        startup.status = 'idle';
    }, LEAVE_MS);
}

function firstName(name) {
    return (name ?? '').trim().split(/\s+/)[0] || null;
}

// La compañía, con el mismo nombre que muestra el selector de la barra
// superior. Sin compañía activa no se dice nada.
function entering(props, door) {
    if (door === 'backoffice') return 'Entrando al backoffice';

    const company = (props.companies ?? []).find((c) => String(c.id) === String(props.currentCompanyId));

    return company ? `Entrando a ${company.trade_name || company.legal_name}` : null;
}
