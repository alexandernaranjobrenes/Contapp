import { reactive } from 'vue';

/**
 * Confirmación de una acción de corte o irreversible (CLAUDE.md secc. 20),
 * con el ConfirmModal de la aplicación en vez del confirm() del navegador:
 *
 *     confirmAction({
 *         title: 'Eliminar almacén',
 *         message: `El almacén ${warehouse.code} se elimina de forma definitiva.`,
 *         confirmLabel: 'Eliminar',
 *         danger: true,
 *         onConfirm: () => router.delete(route('warehouses.destroy', warehouse.id)),
 *     });
 *
 * Lo muestra ConfirmHost.vue, montado una sola vez junto a la aplicación
 * (resources/js/app.js). Si onConfirm dispara una visita de Inertia, el modal
 * queda abierto —con el botón ocupado, secc. 27— hasta que la visita
 * termina; si no dispara ninguna, se cierra enseguida.
 */
export const confirmState = reactive({
    open: false,
    title: '',
    message: '',
    confirmLabel: 'Confirmar',
    danger: false,
    processing: false,
    onConfirm: null,
});

export function confirmAction({ title, message, confirmLabel = 'Confirmar', danger = false, onConfirm }) {
    Object.assign(confirmState, {
        open: true,
        title,
        message,
        confirmLabel,
        danger,
        processing: false,
        onConfirm,
    });
}
