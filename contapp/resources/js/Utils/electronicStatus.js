/**
 * El color del estado de un comprobante ante Hacienda (ElectronicDocument),
 * igual en el listado y en la ficha.
 *
 * - Verde: aceptado, vale.
 * - Rojo: rechazado o no recibido; hay que corregirlo con una clave nueva.
 * - Amarillo: requiere atención o no se sabe si llegó.
 * - Gris: en camino, o sustituido por otro.
 */
const BADGES = {
    accepted: 'badge-success',
    rejected: 'badge-danger',
    submit_failed: 'badge-danger',
    needs_attention: 'badge-warning',
    submit_unknown: 'badge-warning',
};

export function electronicBadge(status) {
    return BADGES[status] ?? 'badge-neutral';
}
