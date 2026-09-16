<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import DocumentToolbar from '../../../Components/DocumentToolbar.vue';

defineProps({
    documents: { type: Array, default: () => [] },
    operations: { type: Object, default: () => ({}) },
});

function goToCreate() {
    router.get(route('inventory-movements.create'));
}
</script>

<template>
    <Head title="Movimientos de inventario" />

    <AppLayout title="Movimientos de inventario">
        <DocumentToolbar can-create @new="goToCreate" />

        <div class="card">
            <div class="card-header">
                <span class="muted">{{ documents.length }} movimiento(s)</span>
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
                            v-for="d in documents" :key="d.id" class="clickable-row"
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
                        <tr v-if="!documents.length">
                            <td colspan="7" class="muted empty-row">Todavía no hay movimientos de inventario registrados.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
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
</style>
