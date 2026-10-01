<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { ArrowLeftIcon } from '@lucide/vue';
import AppLayout from '../../../Layouts/AppLayout.vue';
import DetailModal from '../../../Components/DetailModal.vue';

const props = defineProps({
    writeDown: { type: Object, required: true },
    lines: { type: Array, default: () => [] },
});

function money(value) {
    return Number(value ?? 0).toLocaleString('es-CR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function quantity(value) {
    return Number(value ?? 0).toLocaleString('es-CR', { minimumFractionDigits: 0, maximumFractionDigits: 6 });
}

const total = computed(() => props.lines.reduce((sum, l) => sum + Number(l.movement_local), 0));

// Ficha de un artículo del avalúo (CLAUDE.md secc. 20): los unitarios, las
// estimaciones y el motivo, que no caben como columnas.
const selectedLineNumber = ref(null);
const selectedLine = computed(() => props.lines.find((l) => l.line_number === selectedLineNumber.value) ?? null);
</script>

<template>
    <Head :title="`Avalúo de deterioro al ${writeDown.as_of}`" />

    <AppLayout :title="`Avalúo de deterioro al ${writeDown.as_of}`">
        <div class="view-toolbar">
            <Link :href="route('inventory-write-downs.index')" class="btn btn-ghost"><ArrowLeftIcon /> Deterioro de inventario</Link>
            <div class="view-actions">
                <Link
                    v-if="writeDown.journal_entry_id"
                    :href="route('journal-entries.show', writeDown.journal_entry_id)"
                    class="btn btn-ghost"
                >
                    Ver asiento {{ writeDown.journal_document_number }}
                </Link>
            </div>
        </div>

        <div class="card summary">
            <div><span class="muted small">Corte</span><strong>{{ writeDown.as_of }}</strong></div>
            <div><span class="muted small">Tipo de documento</span><strong>{{ writeDown.document_type ?? '—' }}</strong></div>
            <div><span class="muted small">Descripción</span><strong>{{ writeDown.description ?? '—' }}</strong></div>
        </div>

        <div class="card">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Artículo</th>
                            <th class="num">Existencia</th>
                            <th class="num">Costo total</th>
                            <th class="num">VNR total</th>
                            <th class="num">Contabilizado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="l in lines"
                            :key="l.line_number"
                            class="clickable-row"
                            tabindex="0"
                            @click="selectedLineNumber = l.line_number"
                            @keydown.enter="selectedLineNumber = l.line_number"
                            @keydown.space.prevent="selectedLineNumber = l.line_number"
                        >
                            <td><strong class="code">{{ l.item_code }}</strong> — {{ l.item_name }}</td>
                            <td data-label="Existencia" class="num">{{ quantity(l.quantity) }}</td>
                            <td data-label="Costo total" class="num">{{ money(l.cost_value_local) }}</td>
                            <td data-label="VNR total" class="num">{{ money(l.nrv_value_local) }}</td>
                            <td data-label="Contabilizado" class="num" :class="l.movement_local < 0 ? 'reversal' : 'impairment'">
                                {{ money(l.movement_local) }}
                            </td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="4" class="total-label">Efecto neto en resultados</td>
                            <td data-label="Efecto neto" class="num total-value" :class="total < 0 ? 'reversal' : 'impairment'">{{ money(total) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <DetailModal
            :open="!!selectedLine"
            :title="selectedLine ? `${selectedLine.item_code} — ${selectedLine.item_name}` : ''"
            @close="selectedLineNumber = null"
        >
            <dl v-if="selectedLine" class="detail-list">
                <div>
                    <dt>Existencia</dt>
                    <dd>{{ quantity(selectedLine.quantity) }}</dd>
                </div>
                <div>
                    <dt>Costo unitario</dt>
                    <dd>{{ money(selectedLine.unit_cost_local) }}</dd>
                </div>
                <div>
                    <dt>Costo total</dt>
                    <dd>{{ money(selectedLine.cost_value_local) }}</dd>
                </div>
                <div>
                    <dt>VNR unitario</dt>
                    <dd>{{ money(selectedLine.nrv_unit_local) }}</dd>
                </div>
                <div>
                    <dt>VNR total</dt>
                    <dd>{{ money(selectedLine.nrv_value_local) }}</dd>
                </div>
                <div>
                    <dt>Estimación previa</dt>
                    <dd>{{ money(selectedLine.previous_allowance_local) }}</dd>
                </div>
                <div>
                    <dt>Estimación objetivo</dt>
                    <dd>{{ money(selectedLine.target_allowance_local) }}</dd>
                </div>
                <div>
                    <dt>Contabilizado</dt>
                    <dd :class="selectedLine.movement_local < 0 ? 'reversal' : 'impairment'">{{ money(selectedLine.movement_local) }}</dd>
                </div>
                <div class="full">
                    <dt>Motivo</dt>
                    <dd>{{ selectedLine.reason ?? '—' }}</dd>
                </div>
            </dl>
        </DetailModal>
    </AppLayout>
</template>

<style scoped>
table { font-size: 0.85rem; }
.code { font-variant-numeric: tabular-nums; }
.summary { display: flex; gap: 0.75rem 2rem; flex-wrap: wrap; padding: 1rem 1.25rem; margin-bottom: 0.9rem; }
.summary div { display: flex; flex-direction: column; min-width: 0; }
.total-label { text-align: right; font-weight: 600; }
.total-value { font-weight: 700; }
.impairment { color: var(--color-danger); }
.reversal { color: var(--color-success); }

@media screen and (max-width: 1024px) {
    .total-label { text-align: left; }
}
</style>
