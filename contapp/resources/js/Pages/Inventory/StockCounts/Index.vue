<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AppLayout from '../../../Layouts/AppLayout.vue';

const props = defineProps({
    counts: { type: Array, default: () => [] },
    statuses: { type: Object, default: () => ({}) },
});

const filter = ref('');

const visible = computed(() =>
    filter.value ? props.counts.filter((c) => c.status === filter.value) : props.counts
);

const badgeClass = {
    open: 'badge-warning',
    posted: 'badge-success',
    cancelled: 'badge-neutral',
};
</script>

<template>
    <Head title="Tomas físicas" />

    <AppLayout title="Tomas físicas de inventario">
        <template #actions>
            <Link :href="route('stock-counts.create')" class="btn btn-primary">Nueva toma física</Link>
        </template>

        <p class="hint">
            Una toma física congela la existencia teórica a una <strong>fecha de corte</strong>, se imprime para
            contar en papel y, al cerrarse, genera el ajuste con las diferencias. Mientras una toma está abierta,
            ese almacén no admite otra.
        </p>

        <div class="card">
            <div class="card-header">
                <span class="muted">{{ visible.length }} toma(s)</span>
                <select v-model="filter" class="filter">
                    <option value="">Todos los estados</option>
                    <option v-for="(label, key) in statuses" :key="key" :value="key">{{ label }}</option>
                </select>
            </div>

            <div class="table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>Número</th>
                            <th>Corte</th>
                            <th>Almacén</th>
                            <th>Familia</th>
                            <th class="right">Líneas</th>
                            <th>Modo</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="count in visible" :key="count.id">
                            <td class="num">
                                <Link :href="route('stock-counts.show', count.id)" class="link">{{ count.number }}</Link>
                            </td>
                            <td class="num">{{ count.cutoff_date }}</td>
                            <td>{{ count.warehouse }}</td>
                            <td class="muted">{{ count.item_group ?? 'Todas' }}</td>
                            <td class="num right">{{ count.lines_count }}</td>
                            <td class="muted small">{{ count.blind ? 'A ciegas' : 'Con existencia' }}</td>
                            <td><span class="badge" :class="badgeClass[count.status]">{{ count.status_label }}</span></td>
                        </tr>
                        <tr v-if="!visible.length">
                            <td colspan="7" class="muted empty-row">No hay tomas físicas con ese estado.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
.hint { font-size: 0.82rem; color: var(--color-text-muted); margin: 0 0 0.75rem; }
.card-header { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: 0.75rem 1.25rem; }
.filter { font-size: 0.82rem; padding: 0.3rem 0.5rem; }
.table-scroll { overflow-x: auto; }
table { font-size: 0.85rem; width: 100%; }
th, td { text-align: left; padding: 0.5rem 1rem; border-top: 1px solid var(--color-border); white-space: nowrap; }
.right { text-align: right; }
.num { font-variant-numeric: tabular-nums; }
.muted { color: var(--color-text-muted); }
.small { font-size: 0.76rem; }
.empty-row { text-align: center; padding: 1.5rem; }
.link { color: var(--color-primary); text-decoration: none; font-weight: 600; }
.link:hover { text-decoration: underline; }
</style>
