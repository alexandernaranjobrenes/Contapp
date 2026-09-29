<script setup>
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ClipboardCheckIcon, PlayIcon, PlusIcon } from '@lucide/vue';

import AppLayout from '../../Layouts/AppLayout.vue';
import DetailModal from '../../Components/DetailModal.vue';
import { confirmAction } from '../../Utils/confirm';
import { useRecordDetail } from '../../Utils/recordDetail';

const props = defineProps({
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

// Una ficha por tabla (CLAUDE.md secc. 20): el borrador pendiente se revisa
// desde la suya; la programación se cancela desde la suya.
const { selected: selectedEntry, openDetail: openEntry, closeDetail: closeEntry } = useRecordDetail(() => props.pendingEntries);
const { selected: selectedSchedule, openDetail: openSchedule, closeDetail: closeSchedule } = useRecordDetail(() => props.schedules);

function cancelSchedule() {
    const schedule = selectedSchedule.value;

    confirmAction({
        title: 'Cancelar programación',
        message: 'No va a generar más asientos preliminares. Los que ya generó no se tocan.',
        confirmLabel: 'Cancelar programación',
        danger: true,
        onConfirm: () => router.post(route('journal-entry-schedules.cancel', schedule.id), {}, { preserveScroll: true }),
    });
}

function processNow() {
    router.post(route('journal-entry-schedules.process-now'), {}, { preserveScroll: true });
}
</script>

<template>
    <Head title="Registros programados" />

    <AppLayout title="Registros pendientes programados">
        <div class="view-toolbar">
            <div class="view-actions">
                <button type="button" class="btn btn-ghost" @click="processNow"><PlayIcon /> Procesar vencidas ahora</button>
                <Link
                    :href="route('journal-entries.create')"
                    class="btn btn-primary"
                    title="Un asiento nuevo marcado «Programable» se guarda como programación"
                ><PlusIcon /> Crear nuevo</Link>
            </div>
        </div>

        <div v-if="page.props.errors?.schedule" class="flash flash-error">{{ page.props.errors.schedule }}</div>

        <p class="hint">
            Una programación genera un asiento <strong>preliminar</strong> cada vez que llega su fecha — nunca se contabiliza solo.
            "Procesar vencidas ahora" corre el mismo proceso que el comando programado <code>contapp:process-journal-schedules</code>
            (declarado a diario en <code>routes/console.php</code>; usá este botón mientras no haya un cron real corriendo <code>schedule:run</code>).
        </p>

        <h2 class="block-title">Registros pendientes programados</h2>
        <div class="card">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Tipo</th>
                            <th>Fecha</th>
                            <th>Descripción</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="entry in pendingEntries"
                            :key="entry.id"
                            class="clickable-row"
                            tabindex="0"
                            @click="openEntry(entry)"
                            @keydown.enter="openEntry(entry)"
                            @keydown.space.prevent="openEntry(entry)"
                        >
                            <td>{{ entry.document_type?.code }}</td>
                            <td data-label="Fecha">{{ entry.posting_date }}</td>
                            <td data-label="Descripción">{{ entry.description || '—' }}</td>
                        </tr>
                        <tr v-if="!pendingEntries.length">
                            <td colspan="3" class="muted empty-row">No hay borradores generados por programaciones esperando revisión.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <h2 class="block-title">Programaciones</h2>
        <div class="card">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Tipo</th>
                            <th>Descripción</th>
                            <th>Próxima corrida</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="schedule in schedules"
                            :key="schedule.id"
                            class="clickable-row"
                            tabindex="0"
                            @click="openSchedule(schedule)"
                            @keydown.enter="openSchedule(schedule)"
                            @keydown.space.prevent="openSchedule(schedule)"
                        >
                            <td>{{ schedule.document_type?.code }}</td>
                            <td data-label="Descripción">{{ schedule.description || '—' }}</td>
                            <td data-label="Próxima corrida">{{ schedule.next_run_date }}</td>
                            <td data-label="Estado">
                                <span class="badge" :class="statusBadge[schedule.status] ?? 'badge-neutral'">
                                    {{ statusLabels[schedule.status] ?? schedule.status }}
                                </span>
                            </td>
                        </tr>
                        <tr v-if="!schedules.length">
                            <td colspan="4" class="muted empty-row">Todavía no hay programaciones. Creá un asiento y marcalo "Programable".</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <DetailModal
            :open="!!selectedEntry"
            :title="selectedEntry ? `${selectedEntry.document_type?.code ?? ''} — ${selectedEntry.posting_date}` : ''"
            @close="closeEntry"
        >
            <template #badge>
                <span class="badge badge-warning">Preliminar</span>
            </template>

            <dl v-if="selectedEntry" class="detail-list">
                <div class="full">
                    <dt>Descripción</dt>
                    <dd>{{ selectedEntry.description || '—' }}</dd>
                </div>
                <div class="full">
                    <dt>Programación que lo generó</dt>
                    <dd>{{ selectedEntry.schedule?.description || '—' }}</dd>
                </div>
            </dl>

            <template #actions>
                <Link v-if="selectedEntry" :href="route('journal-entries.edit', selectedEntry.id)" class="btn btn-primary">
                    <ClipboardCheckIcon /> Revisar / Contabilizar
                </Link>
            </template>
        </DetailModal>

        <DetailModal
            :open="!!selectedSchedule"
            :title="selectedSchedule ? (selectedSchedule.description || `Programación ${selectedSchedule.document_type?.code ?? ''}`) : ''"
            @close="closeSchedule"
        >
            <template #badge>
                <span v-if="selectedSchedule" class="badge" :class="statusBadge[selectedSchedule.status] ?? 'badge-neutral'">
                    {{ statusLabels[selectedSchedule.status] ?? selectedSchedule.status }}
                </span>
            </template>

            <dl v-if="selectedSchedule" class="detail-list">
                <div>
                    <dt>Tipo de documento</dt>
                    <dd>{{ selectedSchedule.document_type?.code ?? '—' }}</dd>
                </div>
                <div>
                    <dt>Frecuencia</dt>
                    <dd>{{ frequencyLabel(selectedSchedule) }}</dd>
                </div>
                <div>
                    <dt>Próxima corrida</dt>
                    <dd>{{ selectedSchedule.next_run_date }}</dd>
                </div>
                <div>
                    <dt>Vence</dt>
                    <dd>{{ selectedSchedule.expires_at || 'Sin vencimiento' }}</dd>
                </div>
                <div>
                    <dt>Asientos generados</dt>
                    <dd>{{ selectedSchedule.generated_entries_count }}</dd>
                </div>
            </dl>

            <template #actions>
                <button
                    v-if="selectedSchedule?.status === 'active'"
                    type="button"
                    class="btn btn-ghost btn-danger-text"
                    @click="cancelSchedule"
                >Cancelar programación</button>
            </template>
        </DetailModal>
    </AppLayout>
</template>

<style scoped>
table { font-size: 0.85rem; }

.hint code {
    font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace;
    font-size: 0.78rem;
}
</style>
