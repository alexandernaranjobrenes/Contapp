<script setup>
import { XIcon } from '@lucide/vue';
import { nextTick, onBeforeUnmount, ref, useId, watch } from 'vue';

/**
 * Ficha de un registro, abierta al hacer clic en su fila (regla de UI/UX,
 * CLAUDE.md secc. 20): el detalle completo y las acciones viven acá, no
 * como columnas y botones de la tabla.
 *
 * Escape, el fondo y el botón de cerrar lo cierran. Al abrir, el foco entra al modal y
 * no se escapa con Tab hacia la página de atrás; al cerrar, vuelve a la
 * fila que lo abrió, para que con el teclado no haya que recorrer la
 * tabla de nuevo.
 */
const props = defineProps({
    open: { type: Boolean, default: false },
    title: { type: String, default: '' },
    // Para fichas o formularios largos (un artículo, un empleado): el modal
    // pasa de 560px a 920px y el formulario se reparte en columnas.
    wide: { type: Boolean, default: false },
});

const emit = defineEmits(['close']);

const titleId = useId();
const dialog = ref(null);
let returnFocusTo = null;

const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

function onKeydown(event) {
    if (event.key === 'Escape') {
        emit('close');
        return;
    }

    if (event.key !== 'Tab' || !dialog.value) return;

    const focusables = [...dialog.value.querySelectorAll(FOCUSABLE)];
    if (!focusables.length) return;

    const first = focusables[0];
    const last = focusables[focusables.length - 1];

    if (event.shiftKey && (document.activeElement === first || document.activeElement === dialog.value)) {
        event.preventDefault();
        last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
    }
}

watch(() => props.open, async (open) => {
    if (open) {
        returnFocusTo = document.activeElement;
        window.addEventListener('keydown', onKeydown);
        await nextTick();
        dialog.value?.focus();
        return;
    }

    window.removeEventListener('keydown', onKeydown);
    returnFocusTo?.focus?.();
    returnFocusTo = null;
}, { immediate: true });

onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown));
</script>

<template>
    <div v-if="open" class="modal-backdrop" @click.self="emit('close')">
        <div
            ref="dialog"
            class="modal card detail-modal"
            :class="{ 'modal-wide': wide }"
            role="dialog"
            aria-modal="true"
            :aria-labelledby="titleId"
            tabindex="-1"
        >
            <header class="detail-modal-header">
                <div class="detail-modal-heading">
                    <h2 :id="titleId">{{ title }}</h2>
                    <slot name="badge" />
                </div>
                <button type="button" class="detail-modal-close" aria-label="Cerrar" @click="emit('close')"><XIcon :size="18" /></button>
            </header>

            <slot />

            <footer v-if="$slots.actions" class="detail-modal-actions">
                <slot name="actions" />
            </footer>
        </div>
    </div>
</template>

<style scoped>
.detail-modal {
    max-height: 90dvh;
    outline: none;
}

.detail-modal-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 0.75rem;
    margin-bottom: 1rem;
}

.detail-modal-heading {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 0.5rem;
    min-width: 0;
}

.detail-modal-heading h2 {
    margin: 0;
    font-size: 1rem;
    overflow-wrap: anywhere;
}

.detail-modal-close {
    flex-shrink: 0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    margin: -0.35rem -0.35rem 0 0;
    padding: 0;
    border: none;
    border-radius: var(--radius-sm);
    background: transparent;
    color: var(--color-text-muted);
    cursor: pointer;
}

.detail-modal-close:hover {
    background: var(--color-surface-alt);
    color: var(--color-text);
}

.detail-modal-actions {
    display: flex;
    flex-wrap: wrap;
    justify-content: flex-end;
    gap: 0.5rem;
    margin-top: 1.1rem;
    padding-top: 0.9rem;
    border-top: 1px solid var(--color-border);
}

/* En un teléfono, de a dos botones por línea y a lo ancho: más fáciles
   de tocar que una fila de botones angostos. */
@media (max-width: 640px) {
    .detail-modal-actions :slotted(.btn) {
        flex: 1 1 calc(50% - 0.5rem);
        justify-content: center;
    }
}
</style>
