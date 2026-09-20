<script setup>
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
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
        <template #actions>
            <Link :href="route('import-costs.index')" class="btn btn-ghost">Ver rubros</Link>
        </template>

        <div v-if="page.props.errors?.import_cost" class="flash flash-error">{{ page.props.errors.import_cost }}</div>

        <p class="hint">
            Elija la importación y los rubros que se le cargan. Al confirmar, el costo <strong>entra al artículo</strong>
            —repartido entre las líneas por su valor— y la cuenta transitoria se liquida por ese monto. Un rubro se
            puede repartir entre varias importaciones: basta con asignar una parte ahora y el resto después.
        </p>

        <form class="card" @submit.prevent="submit">
            <div class="grid-3">
                <div class="field">
                    <label>Importación que recibe el costo</label>
                    <select v-model="form.inventory_document_id" required>
                        <option v-for="r in receipts" :key="r.id" :value="r.id">
                            #{{ r.id }} · {{ r.posting_date }} · {{ r.supplier }} ({{ money(r.total_local) }})
                        </option>
                    </select>
                    <span v-if="form.errors.inventory_document_id" class="error">{{ form.errors.inventory_document_id }}</span>
                </div>
                <div class="field">
                    <label>Tipo de documento</label>
                    <select v-model="form.document_type_id" required>
                        <option v-for="t in documentTypes" :key="t.id" :value="t.id">{{ t.code }} — {{ t.name }}</option>
                    </select>
                </div>
                <div class="field">
                    <label>Fecha de contabilización</label>
                    <input v-model="form.posting_date" type="date" required>
                </div>
            </div>

            <div class="table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th></th>
                            <th>Rubro</th>
                            <th>Proveedor</th>
                            <th>Fecha</th>
                            <th class="right">Monto</th>
                            <th class="right">Por asignar</th>
                            <th class="right">A cargar ahora</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in pending" :key="r.id" :class="{ chosen: selection[r.id].checked }">
                            <td><input v-model="selection[r.id].checked" type="checkbox"></td>
                            <td>{{ r.concept_label }}</td>
                            <td>{{ r.supplier }}</td>
                            <td class="num">{{ r.posting_date }}</td>
                            <td class="num right muted">{{ money(r.amount) }}</td>
                            <td class="num right">{{ money(r.pending_amount) }}</td>
                            <td class="right">
                                <input
                                    v-model="selection[r.id].amount"
                                    type="number" step="0.01" min="0" class="cell-input"
                                    :disabled="!selection[r.id].checked"
                                >
                                <span v-if="selection[r.id].checked && exceeds(r)" class="error">
                                    Solo quedan {{ money(r.pending_amount) }}.
                                </span>
                            </td>
                        </tr>
                        <tr v-if="!pending.length">
                            <td colspan="7" class="muted empty-row">
                                No hay rubros pendientes de asignar: toda la nacionalización acumulada ya entró al costo.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="field">
                <label>Descripción (opcional)</label>
                <input v-model="form.description" type="text" maxlength="255">
            </div>

            <div class="totals">
                <div><span class="muted small">Rubros seleccionados</span><strong class="num">{{ chosen.length }}</strong></div>
                <div><span class="muted small">Costo a cargar</span><strong class="num total">{{ money(total) }}</strong></div>
                <div v-if="selectedReceipt">
                    <span class="muted small">Valor de la importación</span>
                    <strong class="num">{{ money(selectedReceipt.total_local) }}</strong>
                </div>
            </div>

            <p v-if="chosen.length" class="hint">
                La parte cuya mercancía ya salió del inventario no se puede capitalizar: se lleva a resultados
                automáticamente, en la proporción que corresponda.
            </p>

            <div class="actions">
                <Link :href="route('import-costs.index')" class="btn btn-ghost">Cancelar</Link>
                <button type="submit" class="btn btn-primary" :disabled="invalid || form.processing">
                    Asignar al costo
                </button>
            </div>
        </form>
    </AppLayout>
</template>

<style scoped>
.hint { font-size: 0.82rem; color: var(--color-text-muted); margin: 0 0 0.75rem; }
.card { padding: 1rem 1.25rem; }

.grid-3 { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 1rem; }
.field { display: flex; flex-direction: column; gap: 0.25rem; margin-bottom: 0.75rem; }
.field label { font-size: 0.78rem; color: var(--color-text-muted); }
.error { color: var(--color-danger, #b91c1c); font-size: 0.76rem; }

.table-scroll { overflow-x: auto; margin: 0 -1.25rem 0.75rem; }
table { font-size: 0.85rem; width: 100%; }
th, td { text-align: left; padding: 0.5rem 1rem; border-top: 1px solid var(--color-border); white-space: nowrap; }
.right { text-align: right; }
.num { font-variant-numeric: tabular-nums; }
.muted { color: var(--color-text-muted); }
.small { font-size: 0.76rem; }
.empty-row { text-align: center; padding: 1.5rem; }
.chosen { background: var(--color-surface-muted, rgb(0 0 0 / 3%)); }
.cell-input { width: 8rem; text-align: right; }

.totals { display: flex; gap: 1.75rem; padding: 0.85rem 0; border-top: 1px solid var(--color-border); }
.totals > div { display: flex; flex-direction: column; gap: 0.15rem; }
.total { font-size: 1.05rem; }

.actions { display: flex; justify-content: flex-end; gap: 0.5rem; }

.flash { margin-bottom: 0.75rem; padding: 0.6rem 0.9rem; border-radius: var(--radius-sm); font-size: 0.85rem; }
.flash-error { background: var(--color-danger-soft); color: var(--color-danger); }

@media (max-width: 860px) { .grid-3 { grid-template-columns: 1fr; } }
</style>
