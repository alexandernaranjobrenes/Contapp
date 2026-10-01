<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { EyeIcon, PlusIcon } from '@lucide/vue';
import AppLayout from '../../../Layouts/AppLayout.vue';
import DetailModal from '../../../Components/DetailModal.vue';
import { useRecordDetail } from '../../../Utils/recordDetail';

const props = defineProps({
    writeDowns: { type: Array, default: () => [] },
});

function money(value) {
    return Number(value ?? 0).toLocaleString('es-CR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

// Ficha del avalúo (CLAUDE.md secc. 20): el resumen; el detalle por
// artículo está en «Ver avalúo».
const { selected, openDetail, closeDetail } = useRecordDetail(() => props.writeDowns);
</script>

<template>
    <Head title="Deterioro de inventario" />

    <AppLayout title="Deterioro de inventario">
        <div class="view-toolbar">
            <div class="view-actions">
                <Link :href="route('inventory-write-downs.create')" class="btn btn-primary"><PlusIcon /> Crear nuevo</Link>
            </div>
        </div>

        <p class="hint">
            Avalúos de valor neto realizable (NIC 2 §28). Un efecto negativo significa que el avalúo
            <strong>reversó</strong> estimación reconocida antes, porque el VNR se recuperó (§33).
        </p>

        <div class="card">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Fecha de corte</th>
                            <th>Descripción</th>
                            <th class="num">Efecto en resultados</th>
                            <th>Asiento</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="w in writeDowns"
                            :key="w.id"
                            class="clickable-row"
                            tabindex="0"
                            @click="openDetail(w)"
                            @keydown.enter="openDetail(w)"
                            @keydown.space.prevent="openDetail(w)"
                        >
                            <td class="code-cell">{{ w.as_of }}</td>
                            <td data-label="Descripción">{{ w.description ?? '—' }}</td>
                            <td data-label="Efecto en resultados" class="num" :class="w.total_movement < 0 ? 'reversal' : 'impairment'">
                                {{ money(w.total_movement) }}
                            </td>
                            <td data-label="Asiento" class="code-cell">{{ w.journal_document_number ?? '—' }}</td>
                        </tr>
                        <tr v-if="!writeDowns.length">
                            <td colspan="4" class="muted empty-row">Todavía no hay avalúos de deterioro.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <DetailModal :open="!!selected" :title="selected ? `Avalúo al ${selected.as_of}` : ''" @close="closeDetail">
            <dl v-if="selected" class="detail-list">
                <div class="full">
                    <dt>Descripción</dt>
                    <dd>{{ selected.description ?? '—' }}</dd>
                </div>
                <div>
                    <dt>Artículos</dt>
                    <dd>{{ selected.lines_count }}</dd>
                </div>
                <div>
                    <dt>Efecto en resultados</dt>
                    <dd :class="selected.total_movement < 0 ? 'reversal' : 'impairment'">{{ money(selected.total_movement) }}</dd>
                </div>
                <div>
                    <dt>Asiento</dt>
                    <dd>{{ selected.journal_document_number ?? '—' }}</dd>
                </div>
            </dl>

            <template #actions>
                <Link v-if="selected" :href="route('inventory-write-downs.show', selected.id)" class="btn btn-primary"><EyeIcon /> Ver avalúo</Link>
            </template>
        </DetailModal>
    </AppLayout>
</template>

<style scoped>
table { font-size: 0.85rem; }
.code-cell { font-variant-numeric: tabular-nums; white-space: nowrap; }
.impairment { color: var(--color-danger); }
.reversal { color: var(--color-success); }
</style>
