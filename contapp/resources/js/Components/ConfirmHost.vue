<script setup>
import { router } from '@inertiajs/vue3';
import ConfirmModal from './ConfirmModal.vue';
import { confirmState } from '../Utils/confirm';

/**
 * El ConfirmModal de confirmAction() (resources/js/Utils/confirm.js).
 *
 * Va montado una sola vez al lado de la aplicación, no dentro de cada
 * layout: así sobrevive al cambio de página. Si la acción confirmada
 * redirige a otra pantalla, el modal sigue abierto —con su spinner— hasta
 * que la visita termina, y no reaparece un instante en la pantalla nueva.
 */
function close() {
    confirmState.open = false;
    confirmState.processing = false;
    confirmState.onConfirm = null;
}

function cancel() {
    if (!confirmState.processing) close();
}

// Inertia dispara "start" dentro de la misma llamada a router.delete() o
// form.post(), y "finish" trae el mismo objeto de visita: así se sabe cuál
// de todas las visitas es la de esta confirmación.
function confirm() {
    let visit = null;
    const stopListening = router.on('start', (event) => {
        visit ??= event.detail.visit;
    });

    try {
        confirmState.onConfirm?.();
    } finally {
        stopListening();
    }

    if (!visit) {
        close();
        return;
    }

    confirmState.processing = true;
    const stopWaiting = router.on('finish', (event) => {
        if (event.detail.visit !== visit) return;
        stopWaiting();
        close();
    });
}
</script>

<template>
    <ConfirmModal
        :open="confirmState.open"
        :title="confirmState.title"
        :message="confirmState.message"
        :confirm-label="confirmState.confirmLabel"
        :danger="confirmState.danger"
        :processing="confirmState.processing"
        @confirm="confirm"
        @cancel="cancel"
    />
</template>
