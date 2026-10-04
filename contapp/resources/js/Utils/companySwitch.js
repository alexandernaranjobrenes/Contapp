import { reactive } from 'vue';
import { router } from '@inertiajs/vue3';

/**
 * Cambio de compañía con transición (CLAUDE.md secc. 30).
 *
 * Al elegir otra compañía en la barra superior, la pantalla se cubre con una
 * ventana que dice a qué compañía se está pasando, y no se quita hasta que el
 * servidor confirma el cambio y la página nueva ya trae los datos de esa
 * compañía. Si algo falla lo dice, y ofrece reintentar, volver a la compañía
 * anterior o cerrar la sesión.
 *
 * El estado vive acá y la ventana la dibuja CompanySwitchHost.vue, montado
 * al lado de la página en app.js: el cambio termina en otra página (la
 * anterior, o el Panel), y una ventana dentro de AppLayout desaparecería con
 * la página que la abrió.
 */

// Lo mínimo que se ve la ventana, aunque el servidor conteste antes: si se
// abre y se cierra en un parpadeo, no se nota el cambio, que es su razón de ser.
const MIN_VISIBLE_MS = 1100;
// Lo que se ve la confirmación «Ahora estás en…» antes de irse.
const DONE_MS = 800;
// Desde cuándo avisa que está tardando, y cuándo se da por perdido.
const SLOW_MS = 10000;
const TIMEOUT_MS = 30000;

export const companySwitch = reactive({
    visible: false,
    // 'loading' | 'slow' | 'done' | 'error' | 'leaving'
    status: 'idle',
    to: null, // { id, name }
    from: null, // { id, name }
    error: null, // { title, message, sessionExpired }
});

let revertSelect = null;
let timers = [];
let cancelToken = null;
let startedAt = 0;
let listeners = [];
let settled = false;
// Al seguir al Panel después de un cambio que sí se hizo, la visita nueva
// cancela la del cambio: esa cancelación no es un error.
let followingHome = false;

function later(fn, ms) {
    timers.push(setTimeout(fn, ms));
}

function clearTimers() {
    timers.forEach(clearTimeout);
    timers = [];
}

function attach() {
    detach();

    // Una respuesta que no es de Inertia (403, 419, 500…) abre por defecto el
    // modal de error de Inertia con la página cruda del servidor. Mientras
    // dura el cambio, la respuesta la interpreta esta ventana.
    listeners.push(router.on('invalid', (event) => {
        if (settled) return;
        event.preventDefault();
        handleInvalid(event.detail.response);
    }));

    listeners.push(router.on('exception', (event) => {
        if (settled) return;
        event.preventDefault();
        fail('network');
    }));
}

function detach() {
    listeners.forEach((off) => off());
    listeners = [];
}

export function startCompanySwitch({ to, from, revert }) {
    revertSelect = revert;
    companySwitch.to = to;
    companySwitch.from = from;
    companySwitch.visible = true;
    run();
}

function run() {
    clearTimers();
    settled = false;
    followingHome = false;
    companySwitch.status = 'loading';
    companySwitch.error = null;
    startedAt = performance.now();

    later(() => {
        if (companySwitch.status === 'loading') companySwitch.status = 'slow';
    }, SLOW_MS);

    later(() => {
        if (! settled) cancelToken?.cancel();
    }, TIMEOUT_MS);

    attach();

    router.put(route('company-switch'), { company_id: companySwitch.to.id }, {
        onCancelToken: (token) => { cancelToken = token; },
        onSuccess: (page) => verify(page),
        // «license»: la licencia de esa compañía está suspendida o revocada
        // (CompanySwitchController). Reintentar no cambia nada.
        onError: (errors) => (errors?.license ? fail('blocked', errors.license) : fail('rejected', Object.values(errors ?? {})[0])),
        onCancel: () => { if (! settled && ! followingHome) fail('timeout'); },
        onFinish: () => { cancelToken = null; },
    });
}

// Que la respuesta haya llegado no alcanza: la página nueva tiene que decir
// que la compañía activa es la elegida.
function verify(page) {
    if (settled) return;

    if (String(page?.props?.currentCompanyId) !== String(companySwitch.to.id)) {
        fail('not-applied', page?.props?.flash?.error);
        return;
    }

    settled = true;
    detach();

    const wait = Math.max(0, MIN_VISIBLE_MS - (performance.now() - startedAt));

    later(() => {
        companySwitch.status = 'done';
        later(close, DONE_MS);
    }, wait);
}

function handleInvalid(response) {
    const status = response?.status;
    const finalUrl = response?.request?.responseURL ?? '';

    // El cambio responde con una redirección a la página anterior. Si lo que
    // falló es esa página —y no el cambio—, la compañía ya cambió: la página
    // era de la compañía anterior (un asiento que en esta no existe, un
    // módulo al que en esta no hay acceso). Se sigue al Panel.
    const switchSucceeded = finalUrl !== '' && ! finalUrl.includes('/company-switch');

    if (switchSucceeded && (status === 403 || status === 404)) {
        followingHome = true;
        router.get(route('dashboard'), {}, {
            onSuccess: (page) => verify(page),
            onError: () => fail('server', null, status),
        });
        return;
    }

    if (status === 419 || status === 401) fail('session');
    else if (status === 403) fail('forbidden');
    else fail('server', null, status);
}

function fail(kind, detail = null, status = null) {
    if (settled) return;

    settled = true;
    clearTimers();
    detach();

    const to = companySwitch.to?.name ?? 'la compañía';
    const from = companySwitch.from?.name ?? 'la compañía anterior';
    // Cierra la oración con el nombre, sin doble punto si ya termina en uno
    // («Compañía S.A.»).
    const end = (name) => (name.endsWith('.') ? name : `${name}.`);

    const errors = {
        network: {
            title: 'Sin conexión con el servidor',
            message: `No se pudo completar el cambio a ${end(to)} Revisá tu conexión e intentá de nuevo.`,
        },
        timeout: {
            title: 'El servidor no respondió',
            message: `El cambio a ${to} tardó demasiado y se canceló. Seguís en ${end(from)}`,
        },
        session: {
            title: 'Tu sesión venció',
            message: 'Para seguir trabajando hay que volver a iniciar sesión.',
            sessionExpired: true,
        },
        forbidden: {
            title: 'Sin acceso a esa compañía',
            message: `Tu usuario no tiene acceso activo a ${to}. Seguís en ${end(from)}`,
        },
        blocked: {
            title: `No se puede entrar a ${to}`,
            message: `${detail} Seguís en ${end(from)}`,
            canRetry: false,
        },
        rejected: {
            title: 'No se pudo cambiar de compañía',
            message: detail ?? `El sistema rechazó el cambio a ${to}. Seguís en ${end(from)}`,
        },
        // El servidor no dejó la compañía elegida como activa. Si dijo por
        // qué (la licencia, ver SetCurrentCompany), va ese motivo.
        'not-applied': {
            title: `No se pudo entrar a ${to}`,
            message: detail ?? `Seguís en ${end(from)} Puede que tu acceso a ${to} haya cambiado hace un momento: reintentá, y si se repite, avisale a quien administra tu cuenta.`,
            canRetry: !detail,
        },
        server: {
            title: 'Error al cargar la compañía',
            message: `El servidor respondió con un error${status ? ` (${status})` : ''} al pasar a ${to}. Podés reintentar, o volver a ${end(from)}`,
        },
    };

    companySwitch.error = errors[kind] ?? errors.server;
    companySwitch.status = 'error';
}

function close() {
    clearTimers();
    companySwitch.status = 'leaving';

    // Lo que dura la salida en CompanySwitchHost.vue.
    later(() => {
        companySwitch.visible = false;
        companySwitch.status = 'idle';
        companySwitch.error = null;
    }, 250);
}

export function retryCompanySwitch() {
    run();
}

// Quedarse en la compañía anterior: el selector vuelve a mostrarla, y la
// página se recarga por si el servidor alcanzó a cambiar antes de fallar —
// así lo que se ve coincide con la compañía activa de verdad.
export function stayInCurrentCompany() {
    revertSelect?.();
    close();
    router.reload();
}

// Cerrar la sesión. Si la sesión ya venció, la petición también puede
// fallar (el token CSRF murió con ella): entonces se va directo al login.
export function logoutFromSwitch() {
    clearTimers();
    detach();

    const goToLogin = () => window.location.assign(route('login'));

    const offInvalid = router.on('invalid', (event) => { event.preventDefault(); goToLogin(); });
    const offException = router.on('exception', (event) => { event.preventDefault(); goToLogin(); });

    router.delete(route('logout'), {
        onSuccess: () => {
            companySwitch.visible = false;
            companySwitch.status = 'idle';
        },
        onFinish: () => { offInvalid(); offException(); },
    });
}
