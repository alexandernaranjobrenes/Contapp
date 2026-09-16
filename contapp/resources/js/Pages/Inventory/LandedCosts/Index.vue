<script setup>
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '../../../Layouts/AppLayout.vue';

const props = defineProps({
    receipts: { type: Array, default: () => [] },
    documents: { type: Array, default: () => [] },
    documentTypes: { type: Array, default: () => [] },
    suppliers: { type: Array, default: () => [] },
});

const page = usePage();
const today = new Date().toISOString().slice(0, 10);

const applying = ref(null);

const form = useForm({
    inventory_document_id: null,
    document_type_id: props.documentTypes[0]?.id ?? '',
    business_partner_id: '',
    amount: '',
    document_date: today,
    posting_date: today,
    due_date: '',
    description: '',
});

function money(value) {
    return Number(value ?? 0).toLocaleString('es-CR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function openApply(receipt) {
    form.clearErrors();
    form.inventory_document_id = receipt.id;
    form.business_partner_id = '';
    form.amount = '';
    form.document_date = today;
    form.posting_date = today;
    form.due_date = '';
    form.description = '';
    applying.value = receipt;
}

function submit() {
    form
        .transform((data) => ({
            ...data,
            due_date: data.due_date === '' ? null : data.due_date,
            description: data.description === '' ? null : data.description,
        }))
        .post(route('landed-costs.store'), { onSuccess: () => (applying.value = null) });
}
</script>

<template>
    <Head title="Costos de importación" />

    <AppLayout title="Costos de importación">
        <div v-if="page.props.errors?.landed_cost" class="flash flash-error">{{ page.props.errors.landed_cost }}</div>

        <p class="hint">
            Flete, aranceles, seguro y agencia aduanal se capitalizan al costo del artículo — pero solo en la proporción
            que <strong>sigue en existencia</strong>. Si la mercancía ya se vendió, esa parte no tiene activo que la
            respalde y va a la cuenta de diferencia de precio. El reparto entre artículos es proporcional al valor de
            cada línea de la recepción.
        </p>

        <div class="card">
            <div class="card-header"><span class="muted">Recepciones sobre las que aplicar un costo</span></div>

            <div class="table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Tipo</th>
                            <th>Proveedor</th>
                            <th class="right">Líneas</th>
                            <th class="right">Valor recibido</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in receipts" :key="r.id">
                            <td class="num">{{ r.posting_date }}</td>
                            <td class="code-cell">{{ r.document_type_code }}</td>
                            <td>{{ r.supplier ?? '—' }}</td>
                            <td class="num right">{{ r.lines_count }}</td>
                            <td class="num right">{{ money(r.total_local) }}</td>
                            <td>
                                <button
                                    type="button" class="btn btn-primary"
                                    :disabled="!documentTypes.length || !suppliers.length"
                                    @click="openApply(r)"
                                >
                                    Aplicar costo
                                </button>
                            </td>
                        </tr>
                        <tr v-if="!receipts.length">
                            <td colspan="6" class="muted empty-row">Todavía no hay entradas de mercancía registradas.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <h2 class="section-title">Costos aplicados</h2>

        <div class="card">
            <div class="table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Proveedor</th>
                            <th>Concepto</th>
                            <th>Recepción</th>
                            <th class="right">Total</th>
                            <th class="right">Capitalizado</th>
                            <th class="right">A resultados</th>
                            <th>Asiento</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="d in documents" :key="d.id">
                            <td class="num">{{ d.posting_date }}</td>
                            <td>{{ d.supplier }}</td>
                            <td class="muted small">{{ d.description ?? '—' }}</td>
                            <td>
                                <Link :href="route('inventory-movements.show', d.receipt_id)" class="link">Ver entrada</Link>
                            </td>
                            <td class="num right">{{ money(d.amount) }}</td>
                            <td class="num right">{{ money(d.capitalized_amount) }}</td>
                            <td class="num right" :class="Number(d.expensed_amount) > 0 ? 'warn' : ''">
                                {{ money(d.expensed_amount) }}
                            </td>
                            <td class="num muted small">#{{ d.journal_document_number }}</td>
                        </tr>
                        <tr v-if="!documents.length">
                            <td colspan="8" class="muted empty-row">Todavía no se ha aplicado ningún costo de importación.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div v-if="applying" class="modal-backdrop" @click.self="applying = null">
            <form class="modal-card card" @submit.prevent="submit">
                <h2>Aplicar costo de importación</h2>
                <p class="muted small">
                    Sobre la recepción del {{ applying.posting_date }} por {{ money(applying.total_local) }}.
                </p>

                <div class="grid-2">
                    <div class="field">
                        <label>Tipo de documento</label>
                        <select v-model="form.document_type_id" required>
                            <option v-for="t in documentTypes" :key="t.id" :value="t.id">{{ t.code }} — {{ t.name }}</option>
                        </select>
                        <span v-if="form.errors.document_type_id" class="error">{{ form.errors.document_type_id }}</span>
                    </div>
                    <div class="field">
                        <label>Quién lo cobra</label>
                        <select v-model="form.business_partner_id" required>
                            <option value="" disabled>— Elegir —</option>
                            <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.code }} — {{ s.name }}</option>
                        </select>
                        <span v-if="form.errors.business_partner_id" class="error">{{ form.errors.business_partner_id }}</span>
                    </div>
                </div>

                <div class="grid-2">
                    <div class="field">
                        <label>Monto del costo</label>
                        <input v-model="form.amount" type="number" step="0.01" min="0.01" required>
                        <span v-if="form.errors.amount" class="error">{{ form.errors.amount }}</span>
                    </div>
                    <div class="field">
                        <label>Vencimiento (opcional)</label>
                        <input v-model="form.due_date" type="date">
                    </div>
                </div>

                <div class="grid-2">
                    <div class="field">
                        <label>Fecha del documento</label>
                        <input v-model="form.document_date" type="date" required>
                    </div>
                    <div class="field">
                        <label>Fecha de contabilización</label>
                        <input v-model="form.posting_date" type="date" required>
                        <span v-if="form.errors.posting_date" class="error">{{ form.errors.posting_date }}</span>
                    </div>
                </div>

                <div class="field">
                    <label>Concepto (ej. flete marítimo, arancel)</label>
                    <input v-model="form.description" type="text" maxlength="255">
                </div>

                <div class="modal-actions">
                    <button type="submit" class="btn btn-primary" :disabled="form.processing">Aplicar</button>
                    <button type="button" class="btn btn-ghost" @click="applying = null">Cancelar</button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>

<style scoped>
.card-header { padding: 0.85rem 1.1rem; border-bottom: 1px solid var(--color-border); }

.section-title { font-size: 0.92rem; margin: 1.5rem 0 0.6rem; }

.flash { margin-bottom: 0.75rem; padding: 0.6rem 0.9rem; border-radius: var(--radius-sm); font-size: 0.85rem; }
.flash-error { background: var(--color-danger-soft); color: var(--color-danger); }

.hint { font-size: 0.82rem; color: var(--color-text-muted); margin: -0.25rem 0 1rem; }

.table-scroll { overflow-x: auto; }
table { font-size: 0.85rem; width: 100%; }
th, td { text-align: left; padding: 0.5rem 1rem; border-top: 1px solid var(--color-border); white-space: nowrap; }
.right { text-align: right; }
.code-cell, .num { font-variant-numeric: tabular-nums; }
.muted { color: var(--color-text-muted); }
.small { font-size: 0.76rem; }
.warn { color: var(--color-warning); font-weight: 600; }
.empty-row { text-align: center; padding: 1.5rem; white-space: normal; }
.link { color: var(--color-primary); text-decoration: none; font-weight: 600; }
.link:hover { text-decoration: underline; }

.modal-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(11, 31, 58, 0.45);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 50;
    padding: 1rem;
}

.modal-card { width: 620px; max-width: 100%; max-height: 90vh; overflow-y: auto; padding: 1.5rem; }
.modal-card h2 { font-size: 1rem; margin: 0 0 0.25rem; }

.grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 0 1rem; }

.field { display: flex; flex-direction: column; gap: 0.2rem; margin-bottom: 0.75rem; }
.field label { font-size: 0.78rem; color: var(--color-text-muted); }

.field input, .field select {
    width: 100%;
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    padding: 0.45rem 0.6rem;
    font-size: 0.85rem;
    color: var(--color-text);
}

.error { color: var(--color-danger); font-size: 0.76rem; }
.modal-actions { display: flex; gap: 0.6rem; margin-top: 0.5rem; }
</style>
