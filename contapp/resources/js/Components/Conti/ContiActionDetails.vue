<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { ArrowRightIcon, CircleCheckIcon, CircleXIcon, ClockIcon, TriangleAlertIcon } from '@lucide/vue';

/**
 * Lo que Conti preparó para guardar: el resumen y en qué quedó. Lo usan el
 * modal que se abre desde el chat (ContiActionModal.vue) y la pantalla
 * Conti/Action.vue; los botones para decidir los pone cada uno.
 */
const props = defineProps({
    action: { type: Object, required: true },
});

// Al tocar «Ver lo que se guardó»: el modal se cierra.
const emit = defineEmits(['leave']);

const pending = computed(() => props.action.status === 'pending');
const summary = computed(() => props.action.summary ?? {});

// El enlace al resultado: una pantalla de CONTAPP.
const resultLink = computed(() => {
    const url = props.action.result?.enlace;
    if (!url) return null;
    try {
        const parsed = new URL(url, window.location.origin);
        return parsed.origin === window.location.origin ? parsed.pathname + parsed.search : null;
    } catch {
        return null;
    }
});
</script>

<template>
    <div class="conti-action-details">
        <div v-if="action.other_company && pending" class="flash flash-warning">
            Esto es de {{ action.company }}: cambiá a esa compañía (arriba, en el selector) para confirmarlo o descartarlo.
        </div>

        <p class="action-meta muted small">
            <ClockIcon :size="14" />
            Preparado el {{ action.created_at }}
            <template v-if="pending"> · se puede confirmar hasta el {{ action.expires_at }}</template>
            <template v-else-if="action.decided_at"> · decidido el {{ action.decided_at }}</template>
            <template v-if="action.screen"> · {{ action.screen }}</template>
        </p>

        <dl v-if="summary.datos?.length" class="action-data">
            <div v-for="item in summary.datos" :key="item.campo" class="action-row">
                <dt>{{ item.campo }}</dt>
                <dd>{{ item.valor }}</dd>
            </div>
        </dl>

        <div v-if="summary.tabla?.filas?.length" class="table-responsive action-table">
            <table>
                <thead>
                    <tr><th v-for="column in summary.tabla.columnas" :key="column">{{ column }}</th></tr>
                </thead>
                <tbody>
                    <tr v-for="(row, index) in summary.tabla.filas" :key="index">
                        <td v-for="(cell, c) in row" :key="c" :data-label="summary.tabla.columnas[c]">{{ cell ?? '—' }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <ul v-if="summary.avisos?.length" class="action-warnings">
            <li v-for="warning in summary.avisos" :key="warning"><TriangleAlertIcon :size="15" /> {{ warning }}</li>
        </ul>

        <!-- Pendiente: la persona decide. -->
        <p v-if="pending" class="hint">
            Revisalo antes de confirmar: se guarda con tu usuario, igual que si lo hicieras desde la pantalla, y con las mismas
            validaciones. Si algo no está bien, descartalo y pedile a Conti que lo corrija.
        </p>

        <!-- Ya se decidió. -->
        <div v-else-if="action.status === 'confirmed'" class="action-outcome is-success">
            <CircleCheckIcon :size="18" />
            <div>
                <strong>{{ action.result?.mensaje ?? 'Guardado.' }}</strong>
                <Link v-if="resultLink" :href="resultLink" class="btn btn-ghost" @click="emit('leave')">
                    {{ action.result?.enlace_texto ?? 'Ver lo que se guardó' }} <ArrowRightIcon />
                </Link>
            </div>
        </div>
        <div v-else-if="action.status === 'failed'" class="action-outcome is-error">
            <CircleXIcon :size="18" />
            <div>
                <strong>No se guardó.</strong>
                <p>{{ action.error }}</p>
                <p class="muted small">Contale a Conti qué pasó y pedile que lo prepare de nuevo.</p>
            </div>
        </div>
        <p v-else-if="action.status === 'discarded'" class="hint">Lo descartaste: no se guardó nada.</p>
        <p v-else class="hint">Venció sin confirmarse y ya no se puede guardar. Si todavía lo necesitás, pedile a Conti que lo prepare de nuevo.</p>
    </div>
</template>

<style scoped>
.conti-action-details {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.conti-action-details .flash,
.conti-action-details .hint {
    margin: 0;
}

.action-meta {
    margin: 0;
}

.action-meta svg {
    margin-right: 0.25rem;
    vertical-align: -2px;
}

.action-data {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(min(100%, 15rem), 1fr));
    gap: 0.75rem 1.25rem;
    margin: 0;
}

.action-row dt {
    font-size: 0.75rem;
    color: var(--color-text-muted);
}

.action-row dd {
    margin: 0.1rem 0 0;
    font-weight: 600;
    overflow-wrap: anywhere;
    white-space: pre-line;
}

.action-warnings {
    display: flex;
    flex-direction: column;
    gap: 0.3rem;
    margin: 0;
    padding: 0;
    list-style: none;
    color: var(--color-warning);
    font-size: 0.86rem;
}

.action-warnings li {
    display: flex;
    align-items: center;
    gap: 0.35rem;
}

.action-outcome {
    display: flex;
    align-items: flex-start;
    gap: 0.6rem;
    padding: 0.8rem 0.9rem;
    border-radius: var(--radius-md);
}

.action-outcome > div {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 0.4rem;
}

.action-outcome p {
    margin: 0;
}

.action-outcome.is-success {
    background: var(--color-success-soft);
    color: var(--color-success);
}

.action-outcome.is-error {
    background: var(--color-danger-soft);
    color: var(--color-danger);
}

.action-outcome .btn {
    color: var(--color-text);
}
</style>
