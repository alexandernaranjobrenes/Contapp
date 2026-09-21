<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import DocumentToolbar from '../../../Components/DocumentToolbar.vue';

import { computed, ref } from 'vue';

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

function goToCreate() {
    router.get(route('inventory-movements.create'));
}
</script>

<template>
    <Head title="Movimientos de inventario" />

    <AppLayout title="Movimientos de inventario">
        <template #actions>
            <select v-model="operation" class="search-input" @change="applyFilters">
                <option value="">Todas las operaciones</option>
                <option v-for="(label, key) in operations" :key="key" :value="key">{{ label }}</option>
            </select>
            <input v-model="from" type="date" class="search-input" title="Desde" @change="applyFilters">
            <input v-model="to" type="date" class="search-input" title="Hasta" @change="applyFilters">
        </template>

        <DocumentToolbar can-create @new="goToCreate" />

        <div class="card">
            <div class="card-header">
                <span class="muted">{{ documents.total }} movimiento(s)</span>
                <Link :href="route('inventory-movements.create')" class="btn btn-primary">+ Nuevo movimiento</Link>
            </div>

            <div class="table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Tipo</th>
                            <th>Operación</th>
                            <th class="right">Líneas</th>
                            <th>Asiento</th>
                            <th>Descripción</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="d in rows" :key="d.id" class="clickable-row"
                            @click="router.get(route('inventory-movements.show', d.id))"
                        >
                            <td class="num">{{ d.posting_date }}</td>
                            <td class="code-cell">{{ d.document_type?.code }}</td>
                            <td>{{ operations[d.operation] }}</td>
                            <td class="num right">{{ d.lines_count }}</td>
                            <td class="num muted small">#{{ d.journal_entry?.document_number }}</td>
                            <td class="muted small">{{ d.description || '—' }}</td>
                            <td>
                                <span class="badge" :class="d.status === 'posted' ? 'badge-success' : 'badge-neutral'">
                                    {{ d.status === 'posted' ? 'Contabilizado' : 'Anulado' }}
                                </span>
                            </td>
                        </tr>
                        <tr v-if="!rows.length">
                            <td colspan="7" class="muted empty-row">
                                {{ filters.operation || filters.from || filters.to
                                    ? 'Ningún movimiento coincide con los filtros.'
                                    : 'Todavía no hay movimientos de inventario registrados.' }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <nav v-if="documents.links.length > 3" class="pagination">
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
    </AppLayout>
</template>

<style scoped>
.card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.85rem 1.1rem;
    border-bottom: 1px solid var(--color-border);
}

.table-scroll { overflow-x: auto; }
table { font-size: 0.85rem; width: 100%; }
th, td { text-align: left; padding: 0.5rem 1rem; border-top: 1px solid var(--color-border); white-space: nowrap; }
.right { text-align: right; }
.code-cell, .num { font-variant-numeric: tabular-nums; }
.muted { color: var(--color-text-muted); }
.small { font-size: 0.76rem; }
.empty-row { text-align: center; padding: 1.5rem; white-space: normal; }
.clickable-row { cursor: pointer; }
.clickable-row:hover { background: var(--color-primary-soft); }
.pagination { display: flex; gap: 0.25rem; padding: 0.75rem 1.1rem; flex-wrap: wrap; }
.page-link { padding: 0.3rem 0.6rem; border-radius: var(--radius-sm); font-size: 0.78rem; text-decoration: none; color: var(--color-text-muted); }
.page-link.active { background: var(--color-primary); color: #fff; }
.page-link.disabled { opacity: 0.4; pointer-events: none; }
</style>
