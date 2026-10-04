import { reactive } from 'vue';

/**
 * Avisos que no vienen de una página del servidor: el error de una descarga
 * que no se pudo generar, una visita que no llegó al servidor. Los muestra
 * ToastHost.vue, montado una sola vez junto a la aplicación (app.js), así
 * que se ven en cualquier pantalla, también en el backoffice y en las de
 * acceso.
 *
 * Los errores que sí vienen del servidor en una visita de Inertia (un 403,
 * una sesión vencida…) no pasan por acá: llegan como mensaje flash a la
 * página (ver bootstrap/app.php) y los muestra el layout.
 *
 *     notifyError('No se pudo descargar el archivo.');
 */
export const toasts = reactive([]);

let nextId = 1;

// Lo que dura un aviso a la vista si nadie lo cierra. Los errores se quedan
// más: hay que alcanzar a leerlos.
const DURATION_MS = { error: 12000, success: 6000 };

export function dismissToast(id) {
    const index = toasts.findIndex((toast) => toast.id === id);
    if (index !== -1) toasts.splice(index, 1);
}

function push(kind, message) {
    // El mismo aviso dos veces seguidas (dos clics a un enlace roto) no se
    // apila: se renueva el que ya estaba.
    const repeated = toasts.find((toast) => toast.kind === kind && toast.message === message);
    if (repeated) dismissToast(repeated.id);

    const id = nextId++;
    toasts.push({ id, kind, message });
    setTimeout(() => dismissToast(id), DURATION_MS[kind]);

    return id;
}

export function notifyError(message) {
    return push('error', message);
}

export function notifySuccess(message) {
    return push('success', message);
}

/**
 * Qué decirle a la persona según el estado HTTP de una respuesta fallida.
 * Los mismos textos que usa el servidor para las visitas de Inertia
 * (bootstrap/app.php), para que el mismo error se diga igual por las dos vías.
 */
export function messageForStatus(status) {
    if (status === 401) return 'Tu sesión terminó. Recargá la página para volver a entrar.';
    if (status === 403) return 'No tenés permiso para hacer esto.';
    if (status === 404) return 'No encontramos lo que buscabas. Puede que ya no exista.';
    if (status === 419) return 'La página estuvo abierta mucho tiempo y la sesión expiró. Volvé a intentarlo.';
    if (status === 429) return 'Demasiados intentos seguidos. Esperá un momento y volvé a probar.';
    if (status === 503) return 'CONTAPP está en mantenimiento. Probá de nuevo en unos minutos.';
    if (status >= 500) return 'Ocurrió un error en el servidor y la acción no se completó. Si se repite, avisale al equipo de CONTAPP.';

    return 'La acción no se completó. Probá de nuevo.';
}
