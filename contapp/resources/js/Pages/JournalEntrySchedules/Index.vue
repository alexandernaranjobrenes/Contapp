<script setup>
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import DocumentToolbar from '../../Components/DocumentToolbar.vue';

defineProps({
    schedules: { type: Array, required: true },
    pendingEntries: { type: Array, required: true },
});

const page = usePage();

const statusLabels = { active: 'Activa', expired: 'Vencida', cancelled: 'Cancelada' };
const statusBadge = { active: 'badge-success', expired: 'badge-neutral', cancelled: 'badge-neutral' };
const frequencyLabel = (schedule) => {
    const unit = schedule.frequency_type === 'days' ? 'día(s)' : 'mes(es)';
    return `Cada ${schedule.interval_count} ${unit}`;
};

function cancelSchedule(schedule) {
    if (! confirm('¿Cancelar esta programación? No va a generar más asientos preliminares.')) return;

    router.post(route('journal-entry-schedules.cancel', schedule.id), {}, { preserveScroll: true });
}

function processNow() {
    router.post(route('journal-entry-schedules.process-now'), {}, { preserveScroll: true });
}
</script>

<template>
    <Head title="Registros programados" />

    <AppLayout title="Registros pendientes programados">
        <template #actions>
            <button type="button" class="btn btn-ghost" @click="processNow">Procesar vencidas ahora</button>
            <Link :href="route('journal-entries.create')" class="btn btn-primary">+ Nuevo asiento programable</Link>
        </template>

        <DocumentToolbar :new-href="route('journal-entries.create')" />

        <div v-if="page.props.flash?.success" class="flash flash-success">{{ page.props.flash.success }}</div>
        <div v-if="page.props.errors?.schedule" class="flash flash-error">{{ page.props.errors.schedule }}</div>

        <p class="hint">
            Una programación genera un asiento <strong>preliminar</strong> cada vez que llega su fecha — nunca se contabiliza solo.
            "Procesar vencidas ahora" corre el mismo proceso que el comando programado <code>contapp:process-journal-schedules</code>
            (declarado a diario en <code>routes/console.php</code>; usá este botón mientras no haya un cron real corriendo <code>schedule:run</code>).
        </p>

        <div class="card">
            <h3 class="section-title">Registros pendientes programados</h3>
            <table>
                <thead>
                    <tr>
                        <th>Tipo</th>
                        <th>Fecha</th>
                        <th>Descripción</th>
                        <th>Programación</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="entry in pendingEntries" :key="entry.id">
                        <td>{{ entry.document_type?.code }}</td>
                        <td>{{ entry.posting_date }}</td>
                        <td>{{ entry.description || '—' }}</td>
                        <td class="muted small">{{ entry.schedule?.description || '—' }}</td>
                        <td class="actions-cell">
                            <Link :href="route('journal-entries.edit', entry.id)" class="btn btn-ghost">Revisar / Contabilizar</Link>
                        </td>
                    </tr>
                    <tr v-if="!pendingEntries.length">
                        <td colspan="5" class="muted empty-row">No hay borradores generados por programaciones esperando revisión.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="card">
            <h3 class="section-title">Programaciones</h3>
            <table>
                <thead>
                    <tr>
                        <th>Tipo</th>
                        <th>Descripción</th>
                        <th>Frecuencia</th>
                        <th>Próxima corrida</th>
                        <th>Vence</th>
                        <th>Generados</th>
                        <th>Estado</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="schedule in schedules" :key="schedule.id">
                        <td>{{ schedule.document_type?.code }}</td>
                        <td>{{ schedule.description || '—' }}</td>
                        <td>{{ frequencyLabel(schedule) }}</td>
                        <td>{{ schedule.next_run_date }}</td>
                        <td>{{ schedule.expires_at || '— sin vencimiento —' }}</td>
                        <td>{{ schedule.generated_entries_count }}</td>
                        <td>
                            <span class="badge" :class="statusBadge[schedule.status] ?? 'badge-neutral'">
                                {{ statusLabels[schedule.status] ?? schedule.status }}
                            </span>
                        </td>
                        <td class="actions-cell">
                            <button
                                v-if="schedule.status === 'active'"
                                type="button"
                                class="btn btn-ghost"
                                @click="cancelSchedule(schedule)"
                            >Cancelar</button>
                        </td>
                    </tr>
                    <tr v-if="!schedules.length">
                        <td colspan="8" class="muted empty-row">Todavía no hay programaciones. Creá un asiento y marcalo "Programable".</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>

<style scoped>
table {
    font-size: 0.85rem;
    width: 100%;
}

th, td {
    text-align: left;
    padding: 0.55rem 1.1rem;
    border-top: 1px solid var(--color-border);
}

.section-title {
    padding: 0.85rem 1.1rem 0;
    font-size: 0.85rem;
}

.card + .card {
    margin-top: 1rem;
}

.muted {
    color: var(--color-text-muted);
}

.small {
    font-size: 0.78rem;
}

.empty-row {
    text-align: center;
    padding: 1.5rem;
}

.actions-cell {
    display: flex;
    gap: 0.4rem;
}

.hint {
    font-size: 0.82rem;
    color: var(--color-text-muted);
    margin: -0.5rem 0 1rem;
}

.hint code {
    font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace;
    font-size: 0.78rem;
}

.flash-success {
    background: var(--color-success-soft);
    color: var(--color-success);
    padding: 0.6rem 0.9rem;
    border-radius: 6px;
    margin-bottom: 1rem;
    font-size: 0.85rem;
}

.flash-error {
    background: var(--color-danger-soft);
    color: var(--color-danger);
    padding: 0.6rem 0.9rem;
    border-radius: 6px;
    margin-bottom: 1rem;
    font-size: 0.85rem;
}
</style>
