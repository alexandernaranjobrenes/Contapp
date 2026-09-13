<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import DocumentToolbar from '../../Components/DocumentToolbar.vue';
import { formatMoney } from '../../Utils/money';

const props = defineProps({
    accounts: { type: Array, default: () => [] },
    gainLossAccounts: { type: Array, default: () => [] },
    businessPartners: { type: Array, default: () => [] },
    bpCategories: { type: Array, default: () => [] },
    documentTypes: { type: Array, default: () => [] },
});

const accountTypeLabels = { asset: 'Activos', liability: 'Pasivos', equity: 'Patrimonio', income: 'Ingresos', expense: 'Gastos' };

// --- paso 1: criterios --------------------------------------------------

const step = ref('criteria'); // 'criteria' | 'review'
const error = ref('');
const loading = ref(false);

const includeAccounts = ref(true);
const includeBusinessPartners = ref(true);
const cutoffDate = ref(new Date().toISOString().slice(0, 10));
const documentTypeId = ref(props.documentTypes.find((dt) => dt.code === 'ADC')?.id ?? props.documentTypes[0]?.id ?? null);
const gainAccountId = ref(props.gainLossAccounts[0]?.id ?? null);
const lossAccountId = ref(props.gainLossAccounts[0]?.id ?? null);

const accountTypeFilter = ref('all');
const selectedAccountIds = ref(new Set(props.accounts.map((a) => a.id)));
const visibleAccounts = computed(() =>
    accountTypeFilter.value === 'all' ? props.accounts : props.accounts.filter((a) => a.account_type === accountTypeFilter.value)
);

function toggleAccount(id) {
    if (selectedAccountIds.value.has(id)) selectedAccountIds.value.delete(id);
    else selectedAccountIds.value.add(id);
}

function selectAllVisibleAccounts(select) {
    visibleAccounts.value.forEach((a) => {
        if (select) selectedAccountIds.value.add(a.id);
        else selectedAccountIds.value.delete(a.id);
    });
}

const bpCategoryFilter = ref('all');
const selectedPartnerIds = ref(new Set(props.businessPartners.map((p) => p.id)));
const visiblePartners = computed(() =>
    bpCategoryFilter.value === 'all' ? props.businessPartners : props.businessPartners.filter((p) => p.category_id === bpCategoryFilter.value)
);

function togglePartner(id) {
    if (selectedPartnerIds.value.has(id)) selectedPartnerIds.value.delete(id);
    else selectedPartnerIds.value.add(id);
}

function selectAllVisiblePartners(select) {
    visiblePartners.value.forEach((p) => {
        if (select) selectedPartnerIds.value.add(p.id);
        else selectedPartnerIds.value.delete(p.id);
    });
}

// --- paso 2: revisión -----------------------------------------------------

const closingRate = ref(null);
const rows = ref([]);
const acceptedKeys = ref(new Set());

function rowKey(row) {
    return `${row.account_id}:${row.business_partner_id ?? ''}`;
}

async function runPreview() {
    error.value = '';
    loading.value = true;

    try {
        const res = await fetch(route('fx-revaluation.preview'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            },
            body: JSON.stringify({
                cutoff_date: cutoffDate.value,
                include_accounts: includeAccounts.value,
                include_business_partners: includeBusinessPartners.value,
                account_ids: includeAccounts.value ? [...selectedAccountIds.value] : [],
                business_partner_ids: includeBusinessPartners.value ? [...selectedPartnerIds.value] : [],
            }),
        });

        if (! res.ok) {
            const body = await res.json().catch(() => null);
            throw new Error(body?.message || 'No se pudo calcular el diferencial cambiario.');
        }

        const data = await res.json();
        closingRate.value = data.closing_rate;
        rows.value = data.rows;
        acceptedKeys.value = new Set(data.rows.map(rowKey));
        step.value = 'review';
    } catch (e) {
        error.value = e.message || 'No se pudo calcular el diferencial cambiario.';
    } finally {
        loading.value = false;
    }
}

function toggleRow(row) {
    const key = rowKey(row);
    if (acceptedKeys.value.has(key)) acceptedKeys.value.delete(key);
    else acceptedKeys.value.add(key);
}

function acceptAll() {
    acceptedKeys.value = new Set(rows.value.map(rowKey));
}

function rejectAll() {
    acceptedKeys.value = new Set();
}

const acceptedRows = computed(() => rows.value.filter((r) => acceptedKeys.value.has(rowKey(r))));
const netDifference = computed(() =>
    acceptedRows.value.reduce((sum, r) => sum + parseFloat(r.difference), 0)
);

const createForm = useForm({});

function create() {
    error.value = '';

    createForm.transform(() => ({
        cutoff_date: cutoffDate.value,
        document_type_id: documentTypeId.value,
        gain_account_id: gainAccountId.value,
        loss_account_id: lossAccountId.value,
        selected_groups: acceptedRows.value.map((r) => ({ account_id: r.account_id, business_partner_id: r.business_partner_id })),
    })).post(route('fx-revaluation.store'));
}

function backToCriteria() {
    step.value = 'criteria';
}

</script>

<template>
    <Head title="Diferencial cambiario" />

    <AppLayout title="Diferencial cambiario">
        <DocumentToolbar />

        <p class="muted intro-text">
            Compara, cuenta por cuenta y socio por socio, el saldo en moneda extranjera contra el tipo de cambio de la fecha de
            corte. El ajuste actualiza solo el equivalente en moneda local de cada uno — el saldo en moneda extranjera nunca
            cambia — y queda registrado como base para el próximo cálculo (de cierre o al liquidar cada partida más adelante).
        </p>

        <div v-if="error" class="flash flash-error">{{ error }}</div>
        <div v-if="createForm.errors.revaluation" class="flash flash-error">{{ createForm.errors.revaluation }}</div>

        <template v-if="step === 'criteria'">
            <div class="card criteria-card">
                <div class="grid">
                    <div class="field">
                        <label>Fecha de corte</label>
                        <input v-model="cutoffDate" type="date" required>
                    </div>
                    <div class="field">
                        <label>Tipo de documento</label>
                        <select v-model="documentTypeId">
                            <option v-for="dt in documentTypes" :key="dt.id" :value="dt.id">{{ dt.code }} — {{ dt.name }}</option>
                        </select>
                    </div>
                    <div class="field">
                        <label>Cuenta de ganancia cambiaria</label>
                        <select v-model="gainAccountId">
                            <option v-for="a in gainLossAccounts" :key="a.id" :value="a.id">{{ a.code }} — {{ a.description_es }}</option>
                        </select>
                    </div>
                    <div class="field">
                        <label>Cuenta de pérdida cambiaria</label>
                        <select v-model="lossAccountId">
                            <option v-for="a in gainLossAccounts" :key="a.id" :value="a.id">{{ a.code }} — {{ a.description_es }}</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="selection-grid">
                <div class="card selection-card">
                    <label class="check-row section-toggle">
                        <input v-model="includeBusinessPartners" type="checkbox">
                        <strong>Socio de negocio</strong>
                    </label>

                    <template v-if="includeBusinessPartners">
                        <div class="filter-row">
                            <select v-model="bpCategoryFilter">
                                <option value="all">Todas las categorías</option>
                                <option v-for="c in bpCategories" :key="c.id" :value="c.id">{{ c.code }} — {{ c.name }}</option>
                            </select>
                            <button type="button" class="btn btn-ghost" @click="selectAllVisiblePartners(true)">Marcar todos</button>
                            <button type="button" class="btn btn-ghost" @click="selectAllVisiblePartners(false)">Ninguno</button>
                        </div>

                        <div class="pick-list">
                            <label v-for="p in visiblePartners" :key="p.id" class="pick-row">
                                <input type="checkbox" :checked="selectedPartnerIds.has(p.id)" @change="togglePartner(p.id)">
                                {{ p.code }} — {{ p.name }}
                            </label>
                            <p v-if="!visiblePartners.length" class="muted small">No hay socios de negocio para este filtro.</p>
                        </div>
                    </template>
                </div>

                <div class="card selection-card">
                    <label class="check-row section-toggle">
                        <input v-model="includeAccounts" type="checkbox">
                        <strong>Cuentas contables</strong>
                    </label>

                    <template v-if="includeAccounts">
                        <div class="filter-row">
                            <select v-model="accountTypeFilter">
                                <option value="all">Todos los grupos</option>
                                <option v-for="(label, key) in accountTypeLabels" :key="key" :value="key">{{ label }}</option>
                            </select>
                            <button type="button" class="btn btn-ghost" @click="selectAllVisibleAccounts(true)">Marcar todos</button>
                            <button type="button" class="btn btn-ghost" @click="selectAllVisibleAccounts(false)">Ninguno</button>
                        </div>

                        <div class="pick-list">
                            <label v-for="a in visibleAccounts" :key="a.id" class="pick-row">
                                <input type="checkbox" :checked="selectedAccountIds.has(a.id)" @change="toggleAccount(a.id)">
                                {{ a.code }} — {{ a.description_es }}
                            </label>
                            <p v-if="!visibleAccounts.length" class="muted small">No hay cuentas en moneda extranjera para este filtro.</p>
                        </div>
                    </template>
                </div>
            </div>

            <div class="run-bar">
                <button
                    type="button"
                    class="btn btn-primary"
                    :disabled="loading || (!includeAccounts && !includeBusinessPartners)"
                    @click="runPreview"
                >{{ loading ? 'Calculando...' : 'Ejecutar' }}</button>
            </div>
        </template>

        <template v-else>
            <div class="card review-summary">
                <span class="muted small">Tipo de cambio de cierre</span>
                <strong class="num">{{ closingRate }}</strong>
                <span class="muted small">Diferencia neta de lo aceptado</span>
                <strong class="num" :class="netDifference >= 0 ? 'gain' : 'loss'">{{ formatMoney(netDifference) }}</strong>
            </div>

            <div class="card">
                <div class="review-bar">
                    <button type="button" class="btn btn-ghost" @click="backToCriteria">← Volver a criterios</button>
                    <div class="review-bar-actions">
                        <button type="button" class="btn btn-ghost" @click="rejectAll">Rechazar todo</button>
                        <button type="button" class="btn btn-ghost" @click="acceptAll">Aceptar todo</button>
                        <button type="button" class="btn btn-primary" :disabled="createForm.processing || !acceptedRows.length" @click="create">
                            Crear
                        </button>
                    </div>
                </div>

                <table>
                    <thead>
                        <tr>
                            <th></th>
                            <th>Cuenta</th>
                            <th>Socio de negocio</th>
                            <th class="num">Saldo (ME)</th>
                            <th class="num">LC histórico</th>
                            <th class="num">LC revaluado</th>
                            <th class="num">Diferencia</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in rows" :key="rowKey(row)" class="selectable-row" @click="toggleRow(row)">
                            <td class="check-cell" @click.stop="toggleRow(row)">
                                <input type="checkbox" :checked="acceptedKeys.has(rowKey(row))" @change="toggleRow(row)">
                            </td>
                            <td>{{ row.account_code }} — {{ row.account_name }}</td>
                            <td>
                                <span v-if="row.business_partner_code">{{ row.business_partner_code }} — {{ row.business_partner_name }}</span>
                                <span v-else class="muted">—</span>
                            </td>
                            <td class="num">{{ formatMoney(row.foreign_balance) }}</td>
                            <td class="num">{{ formatMoney(row.historical_local_amount) }}</td>
                            <td class="num">{{ formatMoney(row.revalued_local_amount) }}</td>
                            <td class="num" :class="parseFloat(row.difference) >= 0 ? 'gain' : 'loss'">{{ formatMoney(row.difference) }}</td>
                        </tr>
                        <tr v-if="!rows.length">
                            <td colspan="7" class="muted empty-row">No hay diferencial cambiario para lo seleccionado en los criterios.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>
    </AppLayout>
</template>

<style scoped>
.intro-text {
    font-size: 0.85rem;
    max-width: 780px;
    margin-bottom: 0.9rem;
}

.flash { margin-bottom: 0.9rem; padding: 0.6rem 0.9rem; border-radius: var(--radius-sm); font-size: 0.85rem; }
.flash-error { background: var(--color-danger-soft); color: var(--color-danger); }

.criteria-card { padding: 1.1rem 1.25rem; margin-bottom: 0.75rem; }
.grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 0 1rem; }

.field { display: flex; flex-direction: column; gap: 0.2rem; margin-bottom: 0.6rem; }
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

.selection-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
    margin-bottom: 0.75rem;
}

.selection-card { padding: 1rem 1.1rem; }

.section-toggle {
    font-size: 0.9rem;
    margin-bottom: 0.6rem;
}

.check-row { display: flex; align-items: center; gap: 0.5rem; }

.filter-row {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    margin-bottom: 0.5rem;
}

.filter-row select {
    flex: 1;
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    padding: 0.35rem 0.5rem;
    font-size: 0.8rem;
}

.pick-list {
    max-height: 260px;
    overflow-y: auto;
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    padding: 0.4rem 0.6rem;
}

.pick-row {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.3rem 0;
    font-size: 0.82rem;
}

.run-bar { display: flex; justify-content: flex-end; margin-bottom: 1rem; }

.review-summary {
    display: flex;
    align-items: center;
    gap: 1.5rem;
    padding: 0.85rem 1.1rem;
    margin-bottom: 0.75rem;
}

.review-summary .num { font-variant-numeric: tabular-nums; font-size: 1rem; }

.review-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.75rem 1.1rem;
    border-bottom: 1px solid var(--color-border);
}

.review-bar-actions { display: flex; gap: 0.5rem; }

table { font-size: 0.85rem; width: 100%; }
th, td { text-align: left; padding: 0.5rem 0.9rem; border-top: 1px solid var(--color-border); }
.num { font-variant-numeric: tabular-nums; text-align: right; }
thead th.num { text-align: right; }
.muted { color: var(--color-text-muted); }
.small { font-size: 0.78rem; }
.empty-row { text-align: center; padding: 1.5rem; }
.check-cell { width: 2rem; text-align: center; }
.selectable-row { cursor: pointer; }
.selectable-row:hover { background: var(--color-primary-soft); }

.gain { color: var(--color-success); }
.loss { color: var(--color-danger); }
</style>
