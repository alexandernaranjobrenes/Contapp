<script setup>
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
import { ArrowLeftIcon, CheckIcon } from '@lucide/vue';
import AppLayout from '../../../Layouts/AppLayout.vue';

const props = defineProps({
    pending: { type: Array, default: () => [] },
    receipts: { type: Array, default: () => [] },
    documentTypes: { type: Array, default: () => [] },
});

const page = usePage();
const today = new Date().toISOString().slice(0, 10);

// Cuánto de cada rubro se carga a esta importación. Por defecto, todo lo
// pendiente: el caso normal es asignar el rubro completo.
const selection = reactive(Object.fromEntries(
    props.pending.map((r) => [r.id, { checked: false, amount: r.pending_amount.toFixed(2) }])
));

const form = useForm({
    inventory_document_id: props.receipts[0]?.id ?? '',
    document_type_id: props.documentTypes[0]?.id ?? '',
    posting_date: today,
    description: '',
});

function money(value) {
    return Number(value ?? 0).toLocaleString('es-CR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

const chosen = computed(() => props.pending.filter((r) => selection[r.id].checked));

const total = computed(() => chosen.value.reduce(
    (sum, r) => sum + Number(selection[r.id].amount || 0), 0
));

const selectedReceipt = computed(() =>
    props.receipts.find((r) => r.id === Number(form.inventory_document_id)) ?? null
);

function exceeds(rubro) {
    return Number(selection[rubro.id].amount || 0) > rubro.pending_amount;
}

const invalid = computed(() =>
    ! chosen.value.length
    || ! form.inventory_document_id
    || chosen.value.some((r) => Number(selection[r.id].amount || 0) <= 0 || exceeds(r))
);

function submit() {
    form
        .transform((data) => ({
            ...data,
            description: data.description === '' ? null : data.description,
            accruals: Object.fromEntries(chosen.value.map((r) => [r.id, selection[r.id].amount])),
        }))
        .post(route('import-costs.allocate'));
}
</script>

<template>
    <Head title="Proceso de costeo" />

    <AppLayout title="Proceso de costeo de importaciones">
        <div class="view-toolbar">
            <Link :href="route('import-costs.index')" class="btn btn-ghost"><ArrowLeftIcon /> Rubros</Link>
        </div>

        <div v-if="page.props.errors?.import_cost" class="flash flash-error">{{ page.props.errors.import_cost }}</div>

        <p class="hint">
            Elija la importación y los rubros que se le cargan. Al confirmar, el costo <strong>entra al artículo</strong>
            —repartido entre las líneas por su valor— y la cuenta transitoria se liquida por ese monto. Un rubro se
            puede repartir entre varias importaciones: basta con asignar una parte ahora y el resto después.
        </p>

        <form class="card" @submit.prevent="submit">
            <div class="form-grid">
                <div class="field">
                    <label for="alloc-receipt">Importación que recibe el costo</label>
                    <select id="alloc-receipt" v-model="form.inventory_document_id" required>
                        <option v-for="r in receipts" :key="r.id" :value="r.id">
                            #{{ r.id }} · {{ r.posting_date }} · {{ r.supplier }} ({{ money(r.total_local) }})
                        </option>
                    </select>
                    <span v-if="form.errors.inventory_document_id" class="error">{{ form.errors.inventory_document_id }}</span>
                </div>
                <div class="field">
                    <label for="alloc-doc-type">Tipo de documento</label>
                    <select id="alloc-doc-type" v-model="form.document_type_id" required>
                        <option v-for="t in documentTypes" :key="t.id" :value="t.id">{{ t.code }} — {{ t.name }}</option>
                    </select>
                </div>
                <div class="field">
                    <label for="alloc-date">Fecha de contabilización</label>
                    <input id="alloc-date" v-model="form.posting_date" type="date" required>
                </div>
            </div>

            <!-- Grilla de selección: la casilla elige el rubro y el monto a
                 cargar se ajusta en la fila (CLAUDE.md secc. 20, excepción). -->
            <div class="table-responsive capture-grid alloc-table">
                <table>
                    <thead>
                        <tr>
                            <th>Rubro</th>
                            <th>Proveedor</th>
                            <th class="num">Por asignar</th>
                            <th class="num">A cargar ahora</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in pending" :key="r.id" :class="{ chosen: selection[r.id].checked }">
                            <td>
                                <label class="pick-cell">
                                    <input v-model="selection[r.id].checked" type="checkbox">
                                    <span>
                                        {{ r.concept_label }}
                                        <span class="breakdown">{{ r.posting_date }} · monto {{ money(r.amount) }}</span>
                                    </span>
                                </label>
                            </td>
                            <td data-label="Proveedor">{{ r.supplier }}</td>
                            <td data-label="Por asignar" class="num">{{ money(r.pending_amount) }}</td>
                            <td data-label="A cargar ahora" class="num">
                                <input
                                    v-model="selection[r.id].amount"
                                    type="number" step="0.01" min="0" class="cell-input"
                                    :disabled="!selection[r.id].checked"
                                    aria-label="Monto a cargar ahora"
                                >
                                <span v-if="selection[r.id].checked && exceeds(r)" class="error-text">
                                    Solo quedan {{ money(r.pending_amount) }}.
                                </span>
                            </td>
                        </tr>
                        <tr v-if="!pending.length">
                            <td colspan="4" class="muted empty-row">
                                No hay rubros pendientes de asignar: toda la nacionalización acumulada ya entró al costo.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="field">
                <label for="alloc-description">Descripción (opcional)</label>
                <input id="alloc-description" v-model="form.description" type="text" maxlength="255">
            </div>

            <div class="totals">
                <div><span class="muted small">Rubros seleccionados</span><strong class="num-value">{{ chosen.length }}</strong></div>
                <div><span class="muted small">Costo a cargar</span><strong class="num-value total">{{ money(total) }}</strong></div>
                <div v-if="selectedReceipt">
                    <span class="muted small">Valor de la importación</span>
                    <strong class="num-value">{{ money(selectedReceipt.total_local) }}</strong>
                </div>
            </div>

            <p v-if="chosen.length" class="hint">
                La parte cuya mercancía ya salió del inventario no se puede capitalizar: se lleva a resultados
                automáticamente, en la proporción que corresponda.
            </p>

            <div class="form-actions">
                <Link :href="route('import-costs.index')" class="btn btn-ghost">Cancelar</Link>
                <button type="submit" class="btn btn-primary" :disabled="invalid || form.processing">
                    <CheckIcon /> Asignar al costo
                </button>
            </div>
        </form>
    </AppLayout>
</template>

<style scoped>
.card { padding: 1rem 1.25rem; }

.alloc-table { margin: 0 0 0.75rem; }
table { font-size: 0.85rem; }
.pick-cell { display: inline-flex; align-items: flex-start; gap: 0.5rem; }
.pick-cell input { margin-top: 0.2rem; }
.breakdown { display: block; font-size: 0.7rem; color: var(--color-text-muted); font-weight: 400; }
.chosen td { background: var(--color-primary-soft); }
.cell-input { width: 8rem; text-align: right; }
.num-value { font-variant-numeric: tabular-nums; }

.totals { display: flex; flex-wrap: wrap; gap: 0.75rem 1.75rem; padding: 0.85rem 0; border-top: 1px solid var(--color-border); }
.totals > div { display: flex; flex-direction: column; gap: 0.15rem; }
.total { font-size: 1.05rem; }

@media screen and (max-width: 1024px) {
    .chosen td { background: none; }
    .table-responsive tbody tr.chosen { background: var(--color-primary-soft); }
    .cell-input { width: auto; }
}
</style>
