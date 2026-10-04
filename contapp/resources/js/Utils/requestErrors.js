import { router } from '@inertiajs/vue3';
import { companySwitch } from './companySwitch';
import { messageForStatus, notifyError } from './notify';

/**
 * Lo que pasa cuando una visita de Inertia no recibe una página de Inertia,
 * o no recibe nada.
 *
 * Los errores de una acción que escribe (guardar, eliminar…) ya llegan
 * convertidos en un mensaje de la página (bootstrap/app.php). Esto cubre el
 * resto: una visita GET que falla —abrir una pantalla sin permiso o que ya
 * no existe, consultar un reporte que da error—, una respuesta que no es de
 * Inertia (la página de error de un proxy, un 502 o un 504) y una visita que
 * no llegó al servidor. Sin esto, Inertia muestra la respuesta cruda en una
 * ventana superpuesta, o nada.
 *
 * Durante un cambio de compañía no interviene: esa ventana tiene sus propios
 * errores (companySwitch.js).
 */
router.on('invalid', (event) => {
    if (companySwitch.visible) return;

    const status = event.detail.response?.status ?? 0;

    // En desarrollo, un error interno se deja ver como lo muestra Inertia:
    // con la página de Laravel que dice dónde falló.
    if (import.meta.env.DEV && status >= 500) return;

    event.preventDefault();
    notifyError(messageForStatus(status));
});

router.on('exception', (event) => {
    if (companySwitch.visible) return;

    event.preventDefault();
    notifyError('No hay conexión con el servidor y la acción no se completó. Revisá tu conexión y probá de nuevo.');
});
