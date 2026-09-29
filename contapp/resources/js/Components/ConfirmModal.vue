<script setup>
import { nextTick, onBeforeUnmount, ref, useId, watch } from 'vue';

/**
 * Confirmación de una acción de corte o irreversible (CLAUDE.md secc. 20).
 * Se usa directo en una pantalla, o a través de confirmAction()
 * (resources/js/Utils/confirm.js), que no necesita declararlo.
 *
 * Mientras la acción confirmada se procesa (processing), el modal no se
 * cierra: ni con Cancelar, ni con Escape, ni con el fondo. El botón que se
 * presionó muestra el spinner (secc. 27) hasta que termina.
 */
const props = defineProps({
    open: { type: Boolean, default: false },
    title: { type: String, required: true },
    message: { type: String, required: true },
    confirmLabel: { type: String, default: 'Confirmar' },
    danger: { type: Boolean, default: false },
    processing: { type: Boolean, default: false },
});

const emit = defineEmits(['confirm', 'cancel']);

const titleId = useId();
const messageId = useId();
const dialog = ref(null);
const cancelButton = ref(null);
let returnFocusTo = null;

function cancel() {
    if (!props.processing) emit('cancel');
}

// En captura y sin dejar seguir el evento: Escape cierra esta confirmación,
// no la ficha que suele estar abierta debajo.
function onKeydown(event) {
    if (event.key === 'Escape') {
        event.preventDefault();
        event.stopImmediatePropagation();
        cancel();
        return;
    }

    if (event.key !== 'Tab' || !dialog.value) return;

    const focusables = [...dialog.value.querySelectorAll('button:not([disabled])')];
    if (!focusables.length) return;

    const first = focusables[0];
    const last = focusables[focusables.length - 1];

    if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        event.stopImmediatePropagation();
        last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        event.stopImmediatePropagation();
        first.focus();
    }
}

// Al abrir, el foco va a Cancelar: con Enter no se confirma sin querer una
// acción que no tiene vuelta atrás.
watch(() => props.open, async (open) => {
    if (open) {
        returnFocusTo = document.activeElement;
        window.addEventListener('keydown', onKeydown, true);
        await nextTick();
        cancelButton.value?.focus();
        return;
    }

    window.removeEventListener('keydown', onKeydown, true);
    if (returnFocusTo?.isConnected) returnFocusTo.focus();
    returnFocusTo = null;
}, { immediate: true });

onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown, true));
</script>

<template>
    <div v-if="open" class="confirm-backdrop" @click.self="cancel">
        <div
            ref="dialog"
            class="confirm-card"
            role="alertdialog"
            aria-modal="true"
            :aria-labelledby="titleId"
            :aria-describedby="messageId"
        >
            <h2 :id="titleId" class="confirm-title">{{ title }}</h2>
            <p :id="messageId" class="confirm-message">{{ message }}</p>
            <div class="confirm-actions">
                <button ref="cancelButton" type="button" class="btn btn-ghost" :disabled="processing" @click="cancel">Cancelar</button>
                <button
                    type="button"
                    class="btn"
                    :class="danger ? 'btn-danger' : 'btn-primary'"
                    :disabled="processing"
                    @click="emit('confirm')"
                >
                    {{ confirmLabel }}
                </button>
            </div>
        </div>
    </div>
</template>

<style scoped>
/* Por encima de cualquier modal de la pantalla (z-index 50): la
   confirmación se abre encima de la ficha que la pidió. */
.confirm-backdrop {
    position: fixed;
    inset: 0;
    z-index: 100;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 1rem;
    background: rgba(0, 0, 0, 0.45);
}

.confirm-card {
    width: min(420px, 100%);
    padding: 1.25rem 1.4rem;
    border-radius: var(--radius-md, 10px);
    background: var(--color-surface);
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.25);
}

.confirm-title {
    margin: 0 0 0.5rem;
    font-size: 1rem;
    font-weight: 700;
}

.confirm-message {
    margin: 0 0 1.1rem;
    font-size: 0.85rem;
    color: var(--color-text-muted);
    overflow-wrap: anywhere;
}

.confirm-actions {
    display: flex;
    flex-wrap: wrap;
    justify-content: flex-end;
    gap: 0.5rem;
}

@media (max-width: 640px) {
    .confirm-actions .btn {
        flex: 1 1 auto;
    }
}
</style>
