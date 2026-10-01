<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '../Layouts/AppLayout.vue';
import DetailModal from '../Components/DetailModal.vue';
import { useRecordDetail } from '../Utils/recordDetail';
import { EyeIcon } from '@lucide/vue';

const props = defineProps({
    noCompany: { type: Boolean, default: false },
    stats: { type: Object, default: () => ({}) },
    recentEntries: { type: Array, default: () => [] },
});

const statusLabels = { draft: 'Preliminar', posted: 'Contabilizado', voided: 'Anulado' };
const statusBadge = { draft: 'badge-warning', posted: 'badge-success', voided: 'badge-neutral' };

function documentLabel(entry) {
    return `${entry.document_type?.code ?? ''}-${entry.document_number}`;
}

// Ficha del documento (CLAUDE.md secc. 20), con el enlace al asiento.
const { selected, openDetail, closeDetail } = useRecordDetail(() => props.recentEntries);
</script>

<template>
    <Head title="Panel" />

    <AppLayout title="Panel">
        <div v-if="noCompany" class="card empty-state">
            <p>Tu usuario no tiene ninguna compañía asignada todavía.</p>
            <p class="muted">Pedile a un administrador que te agregue a una compañía para empezar a trabajar.</p>
        </div>

        <template v-else>
            <div class="stat-grid">
                <div class="card stat-card">
                    <span class="stat-label">Cuentas en el catálogo</span>
                    <span class="stat-value">{{ stats.accounts }}</span>
                </div>
                <div class="card stat-card">
                    <span class="stat-label">Asientos contabilizados</span>
                    <span class="stat-value">{{ stats.journalEntries }}</span>
                </div>
                <div class="card stat-card">
                    <span class="stat-label">Partidas abiertas (CxC/CxP)</span>
                    <span class="stat-value">{{ stats.openItems }}</span>
                </div>
            </div>

            <div class="view-toolbar">
                <h2 class="section-heading">Últimos documentos</h2>
                <div class="view-actions">
                    <Link :href="route('journal-entries.index')" class="btn btn-ghost">Ver todos</Link>
                </div>
            </div>

            <div class="card">
                <div v-if="recentEntries.length" class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Documento</th>
                                <th>Fecha</th>
                                <th>Descripción</th>
                                <th>Estado</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="entry in recentEntries"
                                :key="entry.id"
                                class="clickable-row"
                                tabindex="0"
                                @click="openDetail(entry)"
                                @keydown.enter="openDetail(entry)"
                                @keydown.space.prevent="openDetail(entry)"
                            >
                                <td class="code">{{ documentLabel(entry) }}</td>
                                <td data-label="Fecha" class="code">{{ entry.posting_date }}</td>
                                <td data-label="Descripción">{{ entry.description }}</td>
                                <td data-label="Estado">
                                    <span class="badge" :class="statusBadge[entry.status] ?? 'badge-neutral'">
                                        {{ statusLabels[entry.status] ?? entry.status }}
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-else class="muted empty-row">Todavía no hay documentos contabilizados.</p>
            </div>

            <DetailModal :open="!!selected" :title="selected ? documentLabel(selected) : ''" @close="closeDetail">
                <template #badge>
                    <span v-if="selected" class="badge" :class="statusBadge[selected.status] ?? 'badge-neutral'">
                        {{ statusLabels[selected.status] ?? selected.status }}
                    </span>
                </template>

                <dl v-if="selected" class="detail-list">
                    <div>
                        <dt>Fecha</dt>
                        <dd>{{ selected.posting_date }}</dd>
                    </div>
                    <div>
                        <dt>Descripción</dt>
                        <dd>{{ selected.description ?? '—' }}</dd>
                    </div>
                </dl>

                <template #actions>
                    <Link v-if="selected" :href="route('journal-entries.show', selected.id)" class="btn btn-primary"><EyeIcon /> Ver asiento</Link>
                </template>
            </DetailModal>
        </template>
    </AppLayout>
</template>

<style scoped>
.stat-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(100%, 200px), 1fr));
    gap: 1rem;
    margin-bottom: 1.25rem;
}

.stat-card {
    padding: 1rem 1.1rem;
    display: flex;
    flex-direction: column;
    gap: 0.35rem;
}

.stat-label {
    font-size: 0.78rem;
    color: var(--color-text-muted);
    font-weight: 600;
}

.stat-value {
    font-size: 1.6rem;
    font-weight: 800;
    color: var(--color-primary);
}

.section-heading {
    font-size: 0.95rem;
    margin: 0;
    align-self: center;
}

table { font-size: 0.85rem; }
.code { font-variant-numeric: tabular-nums; white-space: nowrap; }

.empty-state, .empty-row {
    padding: 1.25rem;
}
</style>
