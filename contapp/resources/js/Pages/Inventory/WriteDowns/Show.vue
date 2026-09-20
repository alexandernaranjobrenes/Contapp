<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '../../../Layouts/AppLayout.vue';

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
</script>

<template>
    <Head :title="`Avalúo de deterioro al ${writeDown.as_of}`" />

    <AppLayout :title="`Avalúo de deterioro al ${writeDown.as_of}`">
        <template #actions>
            <Link
                v-if="writeDown.journal_entry_id"
                :href="route('journal-entries.show', writeDown.journal_entry_id)"
                class="btn btn-ghost"
            >
                Ver asiento {{ writeDown.journal_document_number }}
            </Link>
            <Link :href="route('inventory-write-downs.index')" class="btn btn-ghost">Volver</Link>
        </template>

        <div class="card summary">
            <div><span class="muted small">Corte</span><strong>{{ writeDown.as_of }}</strong></div>
            <div><span class="muted small">Tipo de documento</span><strong>{{ writeDown.document_type ?? '—' }}</strong></div>
            <div><span class="muted small">Descripción</span><strong>{{ writeDown.description ?? '—' }}</strong></div>
        </div>

        <div class="card">
            <table>
                <thead>
                    <tr>
                        <th>Artículo</th>
                        <th class="num">Existencia</th>
                        <th class="num">Costo unitario</th>
                        <th class="num">Costo total</th>
                        <th class="num">VNR unitario</th>
                        <th class="num">VNR total</th>
                        <th class="num">Estimación previa</th>
                        <th class="num">Estimación objetivo</th>
                        <th class="num">Contabilizado</th>
                        <th>Motivo</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="l in lines" :key="l.line_number">
                        <td><strong class="num">{{ l.item_code }}</strong> — {{ l.item_name }}</td>
                        <td class="num">{{ quantity(l.quantity) }}</td>
                        <td class="num">{{ money(l.unit_cost_local) }}</td>
                        <td class="num">{{ money(l.cost_value_local) }}</td>
                        <td class="num">{{ money(l.nrv_unit_local) }}</td>
                        <td class="num">{{ money(l.nrv_value_local) }}</td>
                        <td class="num muted">{{ money(l.previous_allowance_local) }}</td>
                        <td class="num">{{ money(l.target_allowance_local) }}</td>
                        <td class="num" :class="l.movement_local < 0 ? 'reversal' : 'impairment'">
                            {{ money(l.movement_local) }}
                        </td>
                        <td class="muted small">{{ l.reason ?? '—' }}</td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="8" class="total-label">Efecto neto en resultados</td>
                        <td class="num total-value" :class="total < 0 ? 'reversal' : 'impairment'">{{ money(total) }}</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </AppLayout>
</template>

<style scoped>
.num { text-align: right; }
.summary { display: flex; gap: 2rem; flex-wrap: wrap; }
.summary div { display: flex; flex-direction: column; }
.total-label { text-align: right; font-weight: 600; }
.total-value { font-weight: 700; }
.impairment { color: #a02020; }
.reversal { color: #1d7a3c; }
</style>
