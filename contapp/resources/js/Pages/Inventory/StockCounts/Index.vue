<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import { EyeIcon, PlusIcon } from '@lucide/vue';
import AppLayout from '../../../Layouts/AppLayout.vue';
import DetailModal from '../../../Components/DetailModal.vue';
import { useRecordDetail } from '../../../Utils/recordDetail';

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

// Ficha de la toma (CLAUDE.md secc. 20): el resumen; la hoja de conteo y el
// cierre están en «Ver toma».
const { selected, openDetail, closeDetail } = useRecordDetail(() => props.counts);
</script>

<template>
    <Head title="Tomas físicas" />

    <AppLayout title="Tomas físicas de inventario">
        <div class="view-toolbar">
            <div class="view-actions">
                <Link :href="route('stock-counts.create')" class="btn btn-primary"><PlusIcon /> Crear nuevo</Link>
            </div>
            <div class="view-filters">
                <select v-model="filter" aria-label="Estado">
                    <option value="">Todos los estados</option>
                    <option v-for="(label, key) in statuses" :key="key" :value="key">{{ label }}</option>
                </select>
            </div>
        </div>

        <p class="hint">
            Una toma física congela la existencia teórica a una <strong>fecha de corte</strong>, se imprime para
            contar en papel y, al cerrarse, genera el ajuste con las diferencias. Mientras una toma está abierta,
            ese almacén no admite otra.
        </p>

        <div class="card">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Número</th>
                            <th>Corte</th>
                            <th>Almacén</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="count in visible"
                            :key="count.id"
                            class="clickable-row"
                            tabindex="0"
                            @click="openDetail(count)"
                            @keydown.enter="openDetail(count)"
                            @keydown.space.prevent="openDetail(count)"
                        >
                            <td class="code-cell">{{ count.number }}</td>
                            <td data-label="Corte" class="code-cell">{{ count.cutoff_date }}</td>
                            <td data-label="Almacén">{{ count.warehouse }}</td>
                            <td data-label="Estado"><span class="badge" :class="badgeClass[count.status]">{{ count.status_label }}</span></td>
                        </tr>
                        <tr v-if="!visible.length">
                            <td colspan="4" class="muted empty-row">No hay tomas físicas con ese estado.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <DetailModal :open="!!selected" :title="selected ? `Toma ${selected.number}` : ''" @close="closeDetail">
            <template #badge>
                <span v-if="selected" class="badge" :class="badgeClass[selected.status]">{{ selected.status_label }}</span>
            </template>

            <dl v-if="selected" class="detail-list">
                <div>
                    <dt>Fecha de corte</dt>
                    <dd>{{ selected.cutoff_date }}</dd>
                </div>
                <div>
                    <dt>Almacén</dt>
                    <dd>{{ selected.warehouse }}</dd>
                </div>
                <div>
                    <dt>Familia</dt>
                    <dd>{{ selected.item_group ?? 'Todas' }}</dd>
                </div>
                <div>
                    <dt>Líneas</dt>
                    <dd>{{ selected.lines_count }}</dd>
                </div>
                <div>
                    <dt>Modo</dt>
                    <dd>{{ selected.blind ? 'A ciegas' : 'Con existencia' }}</dd>
                </div>
            </dl>

            <template #actions>
                <Link v-if="selected" :href="route('stock-counts.show', selected.id)" class="btn btn-primary"><EyeIcon /> Ver toma</Link>
            </template>
        </DetailModal>
    </AppLayout>
</template>

<style scoped>
table { font-size: 0.85rem; }
.code-cell { font-variant-numeric: tabular-nums; white-space: nowrap; }
</style>
