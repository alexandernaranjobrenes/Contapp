<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { EyeIcon, PlusIcon } from '@lucide/vue';
import AppLayout from '../../../Layouts/AppLayout.vue';
import DetailModal from '../../../Components/DetailModal.vue';
import { useRecordDetail } from '../../../Utils/recordDetail';

const props = defineProps({
    documents: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    operations: { type: Object, default: () => ({}) },
});

const operation = ref(props.filters?.operation ?? '');
const from = ref(props.filters?.from ?? '');
const to = ref(props.filters?.to ?? '');

const rows = computed(() => props.documents.data ?? []);

function applyFilters() {
    router.get(route('inventory-movements.index'), {
        operation: operation.value === '' ? undefined : operation.value,
        from: from.value === '' ? undefined : from.value,
        to: to.value === '' ? undefined : to.value,
    }, { preserveState: true, replace: true, preserveScroll: true });
}

// Ficha del movimiento (CLAUDE.md secc. 20): el resumen; el documento
// completo, con sus líneas y acciones, está en «Ver movimiento».
const { selected, openDetail, closeDetail } = useRecordDetail(() => rows.value);
</script>

<template>
    <Head title="Movimientos de inventario" />

    <AppLayout title="Movimientos de inventario">
        <div class="view-toolbar">
            <div class="view-actions">
                <Link :href="route('inventory-movements.create')" class="btn btn-primary"><PlusIcon /> Crear nuevo</Link>
            </div>
            <div class="view-filters">
                <label class="filter-field">
                    <span>Operación</span>
                    <select v-model="operation" @change="applyFilters">
                        <option value="">Todas las operaciones</option>
                        <option v-for="(label, key) in operations" :key="key" :value="key">{{ label }}</option>
                    </select>
                </label>
                <label class="filter-field">
                    <span>Desde</span>
                    <input v-model="from" type="date" @change="applyFilters">
                </label>
                <label class="filter-field">
                    <span>Hasta</span>
                    <input v-model="to" type="date" @change="applyFilters">
                </label>
            </div>
        </div>

        <div class="card">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Operación</th>
                            <th>Descripción</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="d in rows"
                            :key="d.id"
                            class="clickable-row"
                            tabindex="0"
                            @click="openDetail(d)"
                            @keydown.enter="openDetail(d)"
                            @keydown.space.prevent="openDetail(d)"
                        >
                            <td class="date-cell">{{ d.posting_date }}</td>
                            <td data-label="Operación">
                                <span class="doc-type">{{ d.document_type?.code }}</span>
                                {{ operations[d.operation] }}
                            </td>
                            <td data-label="Descripción" class="muted">{{ d.description || '—' }}</td>
                            <td data-label="Estado">
                                <span class="badge" :class="d.status === 'posted' ? 'badge-success' : 'badge-neutral'">
                                    {{ d.status === 'posted' ? 'Contabilizado' : 'Anulado' }}
                                </span>
                            </td>
                        </tr>
                        <tr v-if="!rows.length">
                            <td colspan="4" class="muted empty-row">
                                {{ filters.operation || filters.from || filters.to
                                    ? 'Ningún movimiento coincide con los filtros.'
                                    : 'Todavía no hay movimientos de inventario registrados.' }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <nav v-if="documents.links.length > 3" class="pagination" aria-label="Páginas">
                <Link
                    v-for="(link, i) in documents.links"
                    :key="i"
                    :href="link.url ?? '#'"
                    class="page-link"
                    :class="{ active: link.active, disabled: !link.url }"
                    v-html="link.label"
                />
            </nav>
        </div>

        <DetailModal
            :open="!!selected"
            :title="selected ? `${operations[selected.operation] ?? ''} — ${selected.posting_date}` : ''"
            @close="closeDetail"
        >
            <template #badge>
                <span v-if="selected" class="badge" :class="selected.status === 'posted' ? 'badge-success' : 'badge-neutral'">
                    {{ selected.status === 'posted' ? 'Contabilizado' : 'Anulado' }}
                </span>
            </template>

            <dl v-if="selected" class="detail-list">
                <div>
                    <dt>Tipo de documento</dt>
                    <dd>{{ selected.document_type?.code ?? '—' }}</dd>
                </div>
                <div>
                    <dt>Asiento</dt>
                    <dd>{{ selected.journal_entry?.document_number ? `#${selected.journal_entry.document_number}` : '—' }}</dd>
                </div>
                <div>
                    <dt>Líneas</dt>
                    <dd>{{ selected.lines_count }}</dd>
                </div>
                <div class="full">
                    <dt>Descripción</dt>
                    <dd>{{ selected.description || '—' }}</dd>
                </div>
            </dl>

            <template #actions>
                <Link v-if="selected" :href="route('inventory-movements.show', selected.id)" class="btn btn-primary"><EyeIcon /> Ver movimiento</Link>
            </template>
        </DetailModal>
    </AppLayout>
</template>

<style scoped>
table { font-size: 0.85rem; }
.date-cell { font-variant-numeric: tabular-nums; white-space: nowrap; }
.doc-type { margin-right: 0.3rem; font-weight: 700; font-variant-numeric: tabular-nums; }
</style>
