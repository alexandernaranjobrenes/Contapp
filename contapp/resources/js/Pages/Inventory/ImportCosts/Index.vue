<script setup>
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '../../../Layouts/AppLayout.vue';

const props = defineProps({
    documents: { type: Array, default: () => [] },
    concepts: { type: Object, default: () => ({}) },
    statuses: { type: Object, default: () => ({}) },
    suppliers: { type: Array, default: () => [] },
    documentTypes: { type: Array, default: () => [] },
});

const page = usePage();
const today = new Date().toISOString().slice(0, 10);

const filter = ref('');
const adding = ref(false);
const expanded = ref(null);

const form = useForm({
    document_type_id: props.documentTypes[0]?.id ?? '',
    business_partner_id: props.suppliers[0]?.id ?? '',
    concept: 'flete',
    amount: '',
    document_date: today,
    posting_date: today,
    due_date: '',
    description: '',
});

const visible = computed(() =>
    filter.value ? props.documents.filter((d) => d.status === filter.value) : props.documents
);

// El saldo vivo de la transitoria: lo que hay acumulado sin cargar a ninguna
// importación. Es la cifra que debería cuadrar contra el balance.
const totalPending = computed(() =>
    props.documents.reduce((sum, d) => sum + (d.status === 'cancelled' ? 0 : d.pending_amount), 0)
);

function money(value) {
    return Number(value ?? 0).toLocaleString('es-CR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function submit() {
    form
        .transform((data) => ({
            ...data,
            due_date: data.due_date === '' ? null : data.due_date,
            description: data.description === '' ? null : data.description,
        }))
        .post(route('import-costs.store'), { onSuccess: () => { adding.value = false; form.reset('amount', 'description'); } });
}

function cancelDocument(document) {
    router.post(route('import-costs.cancel', document.id), {}, { preserveScroll: true });
}

const badgeClass = {
    pending: 'badge-warning',
    partial: 'badge-warning',
    allocated: 'badge-success',
    cancelled: 'badge-neutral',
};
</script>

<template>
    <Head title="Costos de importación" />

    <AppLayout title="Rubros de nacionalización">
        <template #actions>
            <Link :href="route('import-costs.allocation')" class="btn btn-ghost">Proceso de costeo</Link>
            <button type="button" class="btn btn-primary" @click="adding = true">Registrar rubro</button>
        </template>

        <div v-if="page.props.errors?.import_cost" class="flash flash-error">{{ page.props.errors.import_cost }}</div>

        <p class="hint">
            Cada rubro es la factura de un proveedor de nacionalización —naviera, agencia aduanal, almacén fiscal—
            contabilizada contra la <strong>cuenta transitoria de costos por asignar</strong>. La deuda con ese
            proveedor es real desde ya; el costo entra al artículo después, en el proceso de costeo.
        </p>

        <div class="card totals">
            <div>
                <span class="muted small">Pendiente de asignar (saldo de la transitoria)</span>
                <strong class="num total">{{ money(totalPending) }}</strong>
            </div>
            <div><span class="muted small">Rubros</span><strong class="num">{{ documents.length }}</strong></div>
        </div>

        <div class="card">
            <div class="card-header">
                <span class="muted">{{ visible.length }} rubro(s)</span>
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
                            <th>Fecha</th>
                            <th>Proveedor</th>
                            <th>Rubro</th>
                            <th class="right">Monto</th>
                            <th class="right">Asignado</th>
                            <th class="right">Por asignar</th>
                            <th>Estado</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="d in visible" :key="d.id">
                            <tr>
                                <td class="num">{{ d.number }}</td>
                                <td class="num">{{ d.posting_date }}</td>
                                <td>{{ d.supplier }}</td>
                                <td>{{ d.concept_label }}</td>
                                <td class="num right">{{ money(d.amount) }}</td>
                                <td class="num right muted">{{ money(d.allocated_amount) }}</td>
                                <td class="num right"><strong>{{ money(d.pending_amount) }}</strong></td>
                                <td><span class="badge" :class="badgeClass[d.status]">{{ d.status_label }}</span></td>
                                <td class="actions-cell">
                                    <button
                                        v-if="d.allocations.length"
                                        type="button" class="btn btn-ghost small-btn"
                                        @click="expanded = expanded === d.id ? null : d.id"
                                    >
                                        {{ expanded === d.id ? 'Ocultar' : 'Ver destino' }}
                                    </button>
                                    <button
                                        v-if="d.status === 'pending'"
                                        type="button" class="btn btn-ghost small-btn"
                                        @click="cancelDocument(d)"
                                    >
                                        Cancelar
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="expanded === d.id" class="detail-row">
                                <td colspan="9">
                                    <span class="muted small">Cargado a:</span>
                                    <span v-for="(a, i) in d.allocations" :key="i" class="alloc">
                                        <Link :href="route('inventory-movements.show', a.receipt_id)" class="link">
                                            Importación #{{ a.receipt_id }}
                                        </Link>
                                        <span class="muted">({{ money(a.amount) }} el {{ a.posting_date }})</span>
                                    </span>
                                </td>
                            </tr>
                        </template>
                        <tr v-if="!visible.length">
                            <td colspan="9" class="muted empty-row">No hay rubros con ese estado.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div v-if="adding" class="modal-backdrop" @click.self="adding = false">
            <form class="modal-card card" @submit.prevent="submit">
                <h2>Registrar rubro de nacionalización</h2>
                <p class="muted small">
                    Se contabiliza contra la transitoria y abre la partida por pagar del proveedor.
                </p>

                <div class="grid-2">
                    <div class="field">
                        <label>Proveedor del servicio</label>
                        <select v-model="form.business_partner_id" required>
                            <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.code }} — {{ s.name }}</option>
                        </select>
                        <span v-if="form.errors.business_partner_id" class="error">{{ form.errors.business_partner_id }}</span>
                    </div>
                    <div class="field">
                        <label>Rubro</label>
                        <select v-model="form.concept" required>
                            <option v-for="(label, key) in concepts" :key="key" :value="key">{{ label }}</option>
                        </select>
                    </div>
                </div>

                <div class="grid-2">
                    <div class="field">
                        <label>Tipo de documento</label>
                        <select v-model="form.document_type_id" required>
                            <option v-for="t in documentTypes" :key="t.id" :value="t.id">{{ t.code }} — {{ t.name }}</option>
                        </select>
                        <span v-if="form.errors.document_type_id" class="error">{{ form.errors.document_type_id }}</span>
                    </div>
                    <div class="field">
                        <label>Monto</label>
                        <input v-model="form.amount" type="number" step="0.01" min="0.01" required>
                        <span v-if="form.errors.amount" class="error">{{ form.errors.amount }}</span>
                    </div>
                </div>

                <div class="grid-2">
                    <div class="field">
                        <label>Fecha del documento</label>
                        <input v-model="form.document_date" type="date" required>
                    </div>
                    <div class="field">
                        <label>Vencimiento (opcional)</label>
                        <input v-model="form.due_date" type="date">
                    </div>
                </div>

                <div class="field">
                    <label>Descripción (opcional)</label>
                    <input v-model="form.description" type="text" maxlength="255">
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-ghost" @click="adding = false">Cancelar</button>
                    <button type="submit" class="btn btn-primary" :disabled="form.processing">Registrar rubro</button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>

<style scoped>
.hint { font-size: 0.82rem; color: var(--color-text-muted); margin: 0 0 0.75rem; }
.totals { display: flex; gap: 2rem; padding: 0.9rem 1.25rem; margin-bottom: 0.75rem; }
.totals > div { display: flex; flex-direction: column; gap: 0.15rem; }
.total { font-size: 1.1rem; }

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
.actions-cell { display: flex; gap: 0.35rem; }
.small-btn { font-size: 0.74rem; padding: 0.2rem 0.5rem; }
.detail-row td { background: var(--color-surface-muted, rgb(0 0 0 / 3%)); white-space: normal; }
.alloc { margin-left: 0.6rem; }
.link { color: var(--color-primary); text-decoration: none; font-weight: 600; }
.link:hover { text-decoration: underline; }

.modal-backdrop {
    position: fixed; inset: 0; background: rgba(0, 0, 0, 0.45);
    display: flex; align-items: center; justify-content: center; z-index: 100;
}
.modal-card { max-width: 560px; width: calc(100% - 2rem); padding: 1.25rem 1.4rem; }
.modal-card h2 { margin: 0 0 0.25rem; font-size: 1rem; }
.modal-actions { display: flex; justify-content: flex-end; gap: 0.5rem; margin-top: 0.75rem; }

.grid-2 { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1rem; }
.field { display: flex; flex-direction: column; gap: 0.25rem; margin-bottom: 0.6rem; }
.field label { font-size: 0.78rem; color: var(--color-text-muted); }
.error { color: var(--color-danger, #b91c1c); font-size: 0.76rem; }

.flash { margin-bottom: 0.75rem; padding: 0.6rem 0.9rem; border-radius: var(--radius-sm); font-size: 0.85rem; }
.flash-error { background: var(--color-danger-soft); color: var(--color-danger); }

@media (max-width: 640px) { .grid-2 { grid-template-columns: 1fr; } }
</style>
