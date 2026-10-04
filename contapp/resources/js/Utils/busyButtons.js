import { router } from '@inertiajs/vue3';

/**
 * Botón ocupado (CLAUDE.md secc. 27): el botón que dispara una operación
 * —guardar, eliminar, contabilizar, generar, consultar un reporte…— queda
 * deshabilitado y con un spinner hasta que la operación termina, bien o mal.
 * Funciona solo, para toda la aplicación: ninguna pantalla tiene que hacer
 * nada. Las descargas (Exportar XLSX, Exportar PDF…) las maneja
 * downloads.js con este mismo marcado.
 *
 * Cómo: un clic (o el envío de un formulario) recuerda qué botón fue. Si
 * mientras se atiende ese clic empieza una visita de Inertia, el botón queda
 * marcado con data-busy (el aspecto está en app.scss) hasta que esa visita
 * termina. Una visita que escribe (POST, PUT, DELETE) marca cualquier cosa
 * que se haya tocado; una GET —consultar, generar un reporte, pasar de
 * pantalla— solo marca un botón (un <button> o algo con .btn): los enlaces
 * del menú y de la paginación navegan sin spinner.
 *
 * Si la operación falla, el botón se libera igual y el error queda a la
 * vista: los de validación junto a cada campo, los del servidor como
 * mensaje de la página (bootstrap/app.php) o como aviso (requestErrors.js).
 *
 * Inertia dispara "start" sin esperar nada, dentro del mismo clic que llamó
 * a router.post() o form.put(), y "start" y "finish" de una misma visita
 * traen el mismo objeto: por eso cada botón se libera con su visita, y no
 * con la de otro pedido que termine antes.
 */

const TRIGGER = 'button, a[href], [role="button"], input[type="submit"], input[type="button"]';

// Cuánto vive el botón recordado. Alcanza para una llamada que espera un
// nextTick() o una confirmación antes de guardar; no alcanza para que un
// clic viejo termine marcando una operación que otro código dispara solo.
const REMEMBER_MS = 400;

let pending = null;
let pendingTimer = null;
const busyByVisit = new Map();

function remember(element) {
    pending = element;
    clearTimeout(pendingTimer);
    pendingTimer = setTimeout(() => {
        pending = null;
    }, REMEMBER_MS);
}

/** ¿Se ve como un botón? Solo esos se marcan en una visita GET. */
function looksLikeButton(element) {
    return element.matches('button, input[type="submit"], input[type="button"], .btn');
}

/**
 * Marca el elemento como ocupado y devuelve la función que lo libera. La usa
 * también downloads.js.
 */
export function markBusy(element) {
    const previousAriaDisabled = element.getAttribute('aria-disabled');
    element.setAttribute('data-busy', '');
    element.setAttribute('aria-busy', 'true');
    element.setAttribute('aria-disabled', 'true');

    return () => {
        element.removeAttribute('data-busy');
        element.removeAttribute('aria-busy');
        if (previousAriaDisabled === null) {
            element.removeAttribute('aria-disabled');
        } else {
            element.setAttribute('aria-disabled', previousAriaDisabled);
        }
    };
}

function submitterOf(form, event) {
    if (event.submitter) return event.submitter;

    // requestSubmit() sin botón, o Enter en un campo: el botón de envío del
    // formulario, esté adentro o afuera con el atributo form="…" (el pie de
    // un DetailModal).
    return form.querySelector('[type="submit"]')
        ?? (form.id ? document.querySelector(`[type="submit"][form="${CSS.escape(form.id)}"]`) : null);
}

// Fase de captura: corre antes que los manejadores de la pantalla, así un
// botón ocupado no llega a disparar su @click ni su @submit otra vez.
document.addEventListener('click', (event) => {
    const trigger = event.target.closest?.(TRIGGER);
    if (!trigger) return;

    if (trigger.hasAttribute('data-busy')) {
        event.preventDefault();
        event.stopImmediatePropagation();
        return;
    }

    remember(trigger);
}, true);

document.addEventListener('submit', (event) => {
    const submitter = submitterOf(event.target, event);

    if (submitter?.hasAttribute('data-busy')) {
        event.preventDefault();
        event.stopImmediatePropagation();
        return;
    }

    if (submitter) remember(submitter);
}, true);

// «Importar XLSX» es un <label class="btn"> con un <input type="file">
// adentro: la operación no la dispara el clic —que solo abre el selector de
// archivos— sino el archivo elegido. Ese botón es el que queda ocupado.
document.addEventListener('change', (event) => {
    if (event.target.type !== 'file') return;

    const label = event.target.closest?.('label.btn');
    if (label) remember(label);
}, true);

router.on('start', (event) => {
    const visit = event.detail.visit;
    if (!pending || visit.prefetch) return;
    if (visit.method === 'get' && !looksLikeButton(pending)) return;

    const element = pending;
    pending = null;

    if (element.isConnected) {
        busyByVisit.set(visit, markBusy(element));
    }
});

router.on('finish', (event) => {
    const release = busyByVisit.get(event.detail.visit);
    if (!release) return;

    busyByVisit.delete(event.detail.visit);
    release();
});
