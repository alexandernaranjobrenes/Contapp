<script setup>
import { computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { BotIcon, CircleCheckIcon } from '@lucide/vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import ContiActionDetails from '../../Components/Conti/ContiActionDetails.vue';

/**
 * Lo que Conti preparó para guardar, para que la persona lo revise y decida
 * (ContiActionController, CLAUDE.md secc. 32). Es el único camino por el que
 * Conti escribe algo: al confirmar se guarda con el usuario de la persona,
 * igual que si lo hiciera desde la pantalla correspondiente.
 *
 * Desde el chat, el enlace abre esto mismo en un modal, sin salir de la
 * pantalla (ContiActionModal.vue). La pantalla queda para el enlace abierto
 * en otra pestaña o recargado.
 */
const props = defineProps({
    action: { type: Object, required: true },
});

const pending = computed(() => props.action.status === 'pending');

const BADGES = {
    pending: 'badge-warning',
    confirmed: 'badge-success',
    discarded: 'badge-neutral',
    failed: 'badge-danger',
    expired: 'badge-neutral',
};

function confirmAction() {
    router.post(route('conti.actions.confirm', props.action.uuid), {}, { preserveScroll: true });
}

function discardAction() {
    router.post(route('conti.actions.discard', props.action.uuid), {}, { preserveScroll: true });
}
</script>

<template>
    <Head title="Confirmar lo que preparó Conti" />

    <AppLayout title="Confirmar lo que preparó Conti">
        <section class="card conti-action">
            <header class="action-head">
                <span class="action-avatar" aria-hidden="true"><BotIcon :size="18" /></span>
                <div class="action-heading">
                    <span class="muted small">Conti preparó esto · {{ action.label }}</span>
                    <h2>{{ action.summary?.titulo }}</h2>
                </div>
                <span class="badge" :class="BADGES[action.status]">{{ action.status_label }}</span>
            </header>

            <ContiActionDetails :action="action" />

            <div v-if="pending" class="form-actions">
                <button type="button" class="btn btn-ghost" :disabled="action.other_company" @click="discardAction">Descartar</button>
                <button type="button" class="btn btn-primary" :disabled="action.other_company" @click="confirmAction">
                    <CircleCheckIcon /> Confirmar y guardar
                </button>
            </div>
        </section>
    </AppLayout>
</template>

<style scoped>
.conti-action {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.action-head {
    display: flex;
    align-items: flex-start;
    gap: 0.75rem;
}

.action-avatar {
    display: inline-grid;
    place-items: center;
    width: 2.25rem;
    height: 2.25rem;
    border-radius: 50%;
    background: var(--color-primary);
    color: var(--color-on-sidebar, #fff);
    flex-shrink: 0;
}

.action-heading {
    display: flex;
    flex-direction: column;
    gap: 0.15rem;
    flex: 1;
    min-width: 0;
}

.action-heading h2 {
    margin: 0;
    font-size: 1.1rem;
    overflow-wrap: anywhere;
}
</style>
