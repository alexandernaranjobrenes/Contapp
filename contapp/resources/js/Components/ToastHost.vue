<script setup>
import { CircleAlertIcon, CircleCheckIcon, XIcon } from '@lucide/vue';
import { dismissToast, toasts } from '../Utils/notify';

/**
 * Los avisos de notify.js: abajo a la derecha (a lo ancho en un teléfono),
 * por encima de todo. Cada uno se cierra solo o con su X.
 *
 * Va montado al lado de la página (app.js), no adentro de un layout: un
 * aviso tiene que seguir a la vista aunque la acción termine en otra
 * pantalla, y tiene que existir también en las pantallas de acceso.
 */
</script>

<template>
    <Teleport to="body">
        <div class="toast-stack">
            <TransitionGroup name="toast">
                <div
                    v-for="toast in toasts"
                    :key="toast.id"
                    class="toast"
                    :class="`toast-${toast.kind}`"
                    :role="toast.kind === 'error' ? 'alert' : 'status'"
                >
                    <CircleAlertIcon v-if="toast.kind === 'error'" class="toast-icon" :size="18" />
                    <CircleCheckIcon v-else class="toast-icon" :size="18" />
                    <p class="toast-message">{{ toast.message }}</p>
                    <button type="button" class="toast-close" aria-label="Cerrar aviso" @click="dismissToast(toast.id)">
                        <XIcon :size="16" />
                    </button>
                </div>
            </TransitionGroup>
        </div>
    </Teleport>
</template>

<style scoped>
.toast-stack {
    position: fixed;
    right: 1.25rem;
    bottom: 1.25rem;
    z-index: 1000;
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 0.6rem;
    width: min(26rem, calc(100vw - 2rem));
    pointer-events: none;
}

.toast {
    pointer-events: auto;
    display: flex;
    align-items: flex-start;
    gap: 0.65rem;
    width: 100%;
    padding: 0.8rem 0.6rem 0.8rem 0.9rem;
    border-radius: var(--radius-md);
    border: 1px solid var(--color-border);
    background: var(--color-surface);
    color: var(--color-text);
    box-shadow: 0 12px 32px -12px rgba(0, 0, 0, 0.35);
    font-size: 0.86rem;
    line-height: 1.45;
}

.toast-error {
    border-color: color-mix(in srgb, var(--color-danger) 45%, var(--color-border));
    box-shadow: inset 4px 0 0 var(--color-danger), 0 12px 32px -12px rgba(0, 0, 0, 0.35);
}

.toast-success {
    border-color: color-mix(in srgb, var(--color-success) 45%, var(--color-border));
    box-shadow: inset 4px 0 0 var(--color-success), 0 12px 32px -12px rgba(0, 0, 0, 0.35);
}

.toast-icon {
    flex-shrink: 0;
    margin-top: 0.1rem;
}

.toast-error .toast-icon { color: var(--color-danger); }
.toast-success .toast-icon { color: var(--color-success); }

.toast-message {
    flex: 1;
    margin: 0;
    overflow-wrap: anywhere;
}

.toast-close {
    flex-shrink: 0;
    display: inline-grid;
    place-items: center;
    width: 1.75rem;
    height: 1.75rem;
    margin: -0.25rem 0;
    padding: 0;
    border: 0;
    border-radius: var(--radius-sm);
    background: transparent;
    color: var(--color-text-muted);
    cursor: pointer;
}

.toast-close:hover,
.toast-close:focus-visible {
    background: var(--color-surface-alt);
    color: var(--color-text);
}

.toast-enter-active,
.toast-leave-active {
    transition: opacity .2s ease, transform .2s ease;
}

.toast-enter-from,
.toast-leave-to {
    opacity: 0;
    transform: translateY(0.5rem);
}

@media (prefers-reduced-motion: reduce) {
    .toast-enter-active,
    .toast-leave-active {
        transition: none;
    }
}

@media (max-width: 640px) {
    .toast-stack {
        right: 1rem;
        bottom: 1rem;
        left: 1rem;
        width: auto;
    }
}
</style>
