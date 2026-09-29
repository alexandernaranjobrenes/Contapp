<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import { formatMoney } from '../../Utils/money';
import { ArrowLeftIcon, CalculatorIcon, CheckIcon } from '@lucide/vue';

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
        <p class="muted intro-text">
            Compara, cuenta por cuenta y socio por socio, el saldo en moneda extranjera contra el tipo de cambio de la fecha de
            corte. El ajuste actualiza solo el equivalente en moneda local de cada uno — el saldo en moneda extranjera nunca
            cambia — y queda registrado como base para el próximo cálculo (de cierre o al liquidar cada partida más adelante).
        </p>

        <div v-if="error" class="flash flash-error">{{ error }}</div>
        <div v-if="createForm.errors.revaluation" class="flash flash-error">{{ createForm.errors.revaluation }}</div>

        <template v-if="step === 'criteria'">
            <div class="card criteria-card">
                <div class="form-grid">
                    <div class="field">
                        <label for="fx-cutoff">Fecha de corte</label>
                        <input id="fx-cutoff" v-model="cutoffDate" type="date" required>
                    </div>
                    <div class="field">
                        <label for="fx-doc-type">Tipo de documento</label>
                        <select id="fx-doc-type" v-model="documentTypeId">
                            <option v-for="dt in documentTypes" :key="dt.id" :value="dt.id">{{ dt.code }} — {{ dt.name }}</option>
                        </select>
                    </div>
                    <div class="field">
                        <label for="fx-gain">Cuenta de ganancia cambiaria</label>
                        <select id="fx-gain" v-model="gainAccountId">
                            <option v-for="a in gainLossAccounts" :key="a.id" :value="a.id">{{ a.code }} — {{ a.description_es }}</option>
                        </select>
                    </div>
                    <div class="field">
                        <label for="fx-loss">Cuenta de pérdida cambiaria</label>
                        <select id="fx-loss" v-model="lossAccountId">
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
                            <select v-model="bpCategoryFilter" aria-label="Categoría de socio">
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
                            <select v-model="accountTypeFilter" aria-label="Grupo de cuentas">
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

            <!-- El cálculo previo no es una visita de Inertia (es un fetch que
                 trae la revisión), así que el botón se marca ocupado a mano:
                 mismo spinner que cualquier otro (CLAUDE.md secc. 27). -->
            <div class="form-actions run-bar">
                <button
                    type="button"
                    class="btn btn-primary"
                    :disabled="loading || (!includeAccounts && !includeBusinessPartners)"
                    :data-busy="loading ? '' : null"
                    :aria-busy="loading"
                    @click="runPreview"
                ><CalculatorIcon /> {{ loading ? 'Calculando...' : 'Ejecutar' }}</button>
            </div>
        </template>

        <template v-else>
            <div class="card review-summary">
                <span class="muted small">Tipo de cambio de cierre</span>
                <strong class="num">{{ closingRate }}</strong>
                <span class="muted small">Diferencia neta de lo aceptado</span>
                <strong class="num" :class="netDifference >= 0 ? 'gain' : 'loss'">{{ formatMoney(netDifference) }}</strong>
            </div>

            <div class="view-toolbar">
                <button type="button" class="btn btn-ghost" @click="backToCriteria"><ArrowLeftIcon /> Volver a criterios</button>
                <div class="view-actions">
                    <button type="button" class="btn btn-ghost" @click="rejectAll">Rechazar todo</button>
                    <button type="button" class="btn btn-ghost" @click="acceptAll">Aceptar todo</button>
                    <button type="button" class="btn btn-primary" :disabled="createForm.processing || !acceptedRows.length" @click="create">
                        <CheckIcon /> Crear
                    </button>
                </div>
            </div>

            <!-- Tabla de selección: la casilla de cada fila acepta o rechaza ese
                 ajuste (no es un botón de acción), y la fila entera la alterna. -->
            <div class="card">
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Cuenta</th>
                                <th>Socio de negocio</th>
                                <th class="num">Saldo (ME)</th>
                                <th class="num">LC histórico</th>
                                <th class="num">LC revaluado</th>
                                <th class="num">Diferencia</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="row in rows"
                                :key="rowKey(row)"
                                class="selectable-row"
                                :class="{ rejected: !acceptedKeys.has(rowKey(row)) }"
                                @click="toggleRow(row)"
                            >
                                <td>
                                    <label class="pick-cell" @click.stop>
                                        <input type="checkbox" :checked="acceptedKeys.has(rowKey(row))" @change="toggleRow(row)">
                                        {{ row.account_code }} — {{ row.account_name }}
                                    </label>
                                </td>
                                <td data-label="Socio de negocio">
                                    <span v-if="row.business_partner_code">{{ row.business_partner_code }} — {{ row.business_partner_name }}</span>
                                    <span v-else class="muted">—</span>
                                </td>
                                <td data-label="Saldo (ME)" class="num">{{ formatMoney(row.foreign_balance) }}</td>
                                <td data-label="LC histórico" class="num">{{ formatMoney(row.historical_local_amount) }}</td>
                                <td data-label="LC revaluado" class="num">{{ formatMoney(row.revalued_local_amount) }}</td>
                                <td data-label="Diferencia" class="num" :class="parseFloat(row.difference) >= 0 ? 'gain' : 'loss'">{{ formatMoney(row.difference) }}</td>
                            </tr>
                            <tr v-if="!rows.length">
                                <td colspan="6" class="muted empty-row">No hay diferencial cambiario para lo seleccionado en los criterios.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </template>
    </AppLayout>
</template>

<style scoped>
.intro-text {
    font-size: 0.85rem;
    margin-bottom: 0.9rem;
}

.criteria-card { padding: 1.1rem 1.25rem 0.4rem; margin-bottom: 0.75rem; }

.selection-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(100%, 22rem), 1fr));
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
    flex-wrap: wrap;
    gap: 0.4rem;
    margin-bottom: 0.5rem;
}

.filter-row select {
    flex: 1 1 12rem;
    min-width: 0;
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

.run-bar { margin: 0 0 1rem; }

.review-summary {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 0.5rem 1.5rem;
    padding: 0.85rem 1.1rem;
    margin-bottom: 0.9rem;
}

.review-summary .num { font-variant-numeric: tabular-nums; font-size: 1rem; }

/* Seis columnas en los ~720px que deja la barra lateral a 1025px. */
table { font-size: 0.82rem; }
th, td { padding: 0.5rem 0.7rem; }

.selectable-row { cursor: pointer; }
.selectable-row:hover { background: var(--color-surface-alt); }
.selectable-row.rejected { opacity: 0.55; }

.pick-cell {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    cursor: pointer;
}

.gain { color: var(--color-success); }
.loss { color: var(--color-danger); }
</style>
