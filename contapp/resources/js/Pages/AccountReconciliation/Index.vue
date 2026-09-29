<script setup>
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import { ArrowRightLeftIcon, LinkIcon, Undo2Icon } from '@lucide/vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import DetailModal from '../../Components/DetailModal.vue';
import MoneyInput from '../../Components/MoneyInput.vue';
import { confirmAction } from '../../Utils/confirm';
import { formatMoney } from '../../Utils/money';

const props = defineProps({
    account: { type: Object, required: true },
    unreconciled: { type: Array, default: () => [] },
    reconciliations: { type: Array, default: () => [] },
    accounts: { type: Array, default: () => [] },
    businessPartners: { type: Array, default: () => [] },
    currencies: { type: Array, default: () => [] },
});

const page = usePage();
const activeTab = ref('unreconciled');

// --- selección y reconciliación dentro de la misma cuenta ------------------

const selectedIds = ref([]);
// Monto que cada línea seleccionada aporta a ESTA reconciliación — por
// defecto todo lo disponible, pero editable hasta ese tope: así se puede
// reconciliar solo una parte de un documento y dejar el resto libre para
// una reconciliación futura.
const selectedAmounts = ref({});

function toggleSelect(id) {
    const idx = selectedIds.value.indexOf(id);
    if (idx === -1) {
        selectedIds.value.push(id);
        const line = props.unreconciled.find((l) => l.id === id);
        selectedAmounts.value[id] = line?.available_amount ?? '0.00';
    } else {
        selectedIds.value.splice(idx, 1);
        delete selectedAmounts.value[id];
    }
}

const selectedLines = computed(() => props.unreconciled.filter((l) => selectedIds.value.includes(l.id)));

function lineSign(l) {
    return parseFloat(l.debit_local) > 0 ? 1 : -1;
}

const difference = computed(() =>
    selectedLines.value.reduce((sum, l) => sum + lineSign(l) * (parseFloat(selectedAmounts.value[l.id]) || 0), 0)
);

const amountsValid = computed(() => selectedLines.value.every((l) => {
    const amt = parseFloat(selectedAmounts.value[l.id]);
    return ! Number.isNaN(amt) && amt > 0 && amt <= parseFloat(l.available_amount) + 0.001;
}));

const canReconcile = computed(() => selectedIds.value.length >= 2 && amountsValid.value && Math.abs(difference.value) < 0.005);
const canReclassify = computed(() => selectedIds.value.length === 1);

function reconcileSelected() {
    const lines = selectedIds.value.map((id) => ({ journal_detail_id: id, amount: selectedAmounts.value[id] }));

    router.post(route('account-reconciliation.store', props.account.id), { lines }, {
        preserveScroll: true,
        onSuccess: () => { selectedIds.value = []; selectedAmounts.value = {}; },
    });
}

function unreconcile(reconciliation) {
    confirmAction({
        title: 'Deshacer reconciliación',
        message: 'Los movimientos vuelven a quedar sueltos — ningún asiento se modifica.',
        confirmLabel: 'Deshacer',
        danger: true,
        onConfirm: () => router.delete(route('account-reconciliation.destroy', reconciliation.id), { preserveScroll: true }),
    });
}

// --- traspaso a otra cuenta (tipo de documento reservado ARR) --------------

const showTransferModal = ref(false);

const transferForm = useForm({
    posting_date: new Date().toISOString().slice(0, 10),
    description: '',
    amount: '',
    direction: 'debit',
    target_type: 'account',
    target_account_id: null,
    target_business_partner_id: null,
    currency_id: props.currencies[0]?.id ?? null,
});

function openTransferModal() {
    transferForm.reset();
    transferForm.clearErrors();
    transferForm.currency_id = props.currencies[0]?.id ?? null;
    showTransferModal.value = true;
}

function submitTransfer() {
    transferForm.post(route('account-reconciliation.transfer', props.account.id), {
        onSuccess: () => { showTransferModal.value = false; },
    });
}

// --- reclasificar una línea suelta hacia la cuenta/socio correcto ----------
// Atajo de un solo clic sobre transfer(): en vez de contabilizar el traspaso
// y después ir a reconciliarlo a mano, esto hace ambas cosas de una vez
// sobre la línea seleccionada — el monto y la dirección se infieren del
// movimiento original, el usuario solo elige el destino correcto.

const showReclassifyModal = ref(false);

const reclassifyForm = useForm({
    journal_detail_id: null,
    posting_date: new Date().toISOString().slice(0, 10),
    description: '',
    target_type: 'account',
    target_account_id: null,
    target_business_partner_id: null,
});

function openReclassifyModal() {
    if (! canReclassify.value) return;

    reclassifyForm.reset();
    reclassifyForm.clearErrors();
    reclassifyForm.journal_detail_id = selectedIds.value[0];
    showReclassifyModal.value = true;
}

function submitReclassify() {
    reclassifyForm.post(route('account-reconciliation.reclassify', props.account.id), {
        onSuccess: () => {
            showReclassifyModal.value = false;
            selectedIds.value = [];
            selectedAmounts.value = {};
        },
    });
}

</script>

<template>
    <Head :title="`Reconciliación — ${account.code}`" />

    <AppLayout :title="`Reconciliación interna — ${account.code} · ${account.description_es}`">
        <div class="view-toolbar">
            <div class="tab-row" role="tablist" aria-label="Movimientos">
                <button type="button" role="tab" class="chip" :class="{ active: activeTab === 'unreconciled' }" :aria-selected="activeTab === 'unreconciled'" @click="activeTab = 'unreconciled'">
                    No reconciliados ({{ unreconciled.length }})
                </button>
                <button type="button" role="tab" class="chip" :class="{ active: activeTab === 'reconciled' }" :aria-selected="activeTab === 'reconciled'" @click="activeTab = 'reconciled'">
                    Reconciliados ({{ reconciliations.length }})
                </button>
            </div>
            <div class="view-actions">
                <button type="button" class="btn btn-ghost" @click="openTransferModal"><ArrowRightLeftIcon /> Traspaso a otra cuenta (ARR)</button>
            </div>
        </div>

        <div v-if="page.props.errors?.reconciliation" class="flash flash-error">{{ page.props.errors.reconciliation }}</div>

        <p class="muted intro-text">
            Vincula movimientos de esta misma cuenta cuyo neto sea cero — útil para cuentas que no tienen un mecanismo de
            liquidación propio (partidas abiertas de socios, conciliación bancaria). Un movimiento con socio de negocio
            no aparece acá: se reconcilia por su partida abierta. Se puede reconciliar solo una parte de un documento
            (el resto queda disponible para después), y si un movimiento se digitó en la cuenta equivocada, seleccionalo
            solo y usá "Reclasificar a otra cuenta" para corregirlo y reconciliarlo en un solo paso.
        </p>

        <template v-if="activeTab === 'unreconciled'">
            <!-- La selección y sus acciones, arriba de la tabla. -->
            <div class="reconcile-bar">
                <span class="muted small">{{ selectedIds.length }} seleccionado(s)</span>
                <span class="diff" :class="canReconcile ? 'ok' : (selectedIds.length ? 'off' : '')">
                    Diferencia: {{ formatMoney(difference) }}
                </span>
                <div class="view-actions">
                    <button v-if="canReclassify" type="button" class="btn btn-ghost" @click="openReclassifyModal">
                        Reclasificar a otra cuenta
                    </button>
                    <button type="button" class="btn btn-primary" :disabled="!canReconcile" @click="reconcileSelected">
                        <LinkIcon /> Reconciliar seleccionados
                    </button>
                </div>
            </div>

            <!-- Grilla de selección: la casilla elige el movimiento y el monto a
                 reconciliar se edita en la fila (CLAUDE.md secc. 20, excepción de
                 las grillas de captura). -->
            <div class="card">
                <div class="table-responsive capture-grid">
                    <table>
                        <thead>
                            <tr>
                                <th>Fecha</th>
                                <th>Documento</th>
                                <th>Descripción</th>
                                <th class="num">Movimiento</th>
                                <th class="num">Disponible</th>
                                <th class="num">A reconciliar</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="line in unreconciled"
                                :key="line.id"
                                class="selectable-row"
                                :class="{ selected: selectedIds.includes(line.id) }"
                                @click="toggleSelect(line.id)"
                            >
                                <td>
                                    <label class="pick-cell" @click.stop>
                                        <input type="checkbox" :checked="selectedIds.includes(line.id)" @change="toggleSelect(line.id)">
                                        {{ line.posting_date }}
                                    </label>
                                </td>
                                <td data-label="Documento">{{ line.document }}</td>
                                <td data-label="Descripción" class="desc-cell">{{ line.description }}</td>
                                <td data-label="Movimiento" class="num">
                                    {{ formatMoney(lineSign(line) > 0 ? line.debit_local : line.credit_local) }}
                                    <span class="side" :title="lineSign(line) > 0 ? 'Débito' : 'Crédito'">{{ lineSign(line) > 0 ? 'D' : 'C' }}</span>
                                </td>
                                <td data-label="Disponible" class="num">{{ formatMoney(line.available_amount) }}</td>
                                <td data-label="A reconciliar" class="num amount-cell" @click.stop>
                                    <MoneyInput v-if="selectedIds.includes(line.id)" v-model="selectedAmounts[line.id]" aria-label="Monto a reconciliar" />
                                </td>
                            </tr>
                            <tr v-if="!unreconciled.length">
                                <td colspan="6" class="muted empty-row">No hay movimientos sin reconciliar en esta cuenta.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </template>

        <div v-else class="reconciliations-list">
            <section v-for="r in reconciliations" :key="r.id">
                <div class="reconciliation-header">
                    <span class="muted small">
                        Reconciliado el {{ r.reconciled_at }}<template v-if="r.reconciled_by"> por {{ r.reconciled_by }}</template>
                    </span>
                    <button type="button" class="btn btn-ghost btn-danger-text" @click="unreconcile(r)"><Undo2Icon /> Deshacer</button>
                </div>
                <div class="card">
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Fecha</th>
                                    <th>Documento</th>
                                    <th>Descripción</th>
                                    <th class="num">Débito</th>
                                    <th class="num">Crédito</th>
                                    <th class="num">Monto reconciliado</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="line in r.lines" :key="line.id">
                                    <td class="date-cell">{{ line.posting_date }}</td>
                                    <td data-label="Documento">{{ line.document }}</td>
                                    <td data-label="Descripción" class="desc-cell">
                                        {{ line.description }}
                                        <span v-if="line.is_partial" class="partial-badge">parcial</span>
                                    </td>
                                    <td data-label="Débito" class="num">{{ line.debit_local !== '0.00' ? formatMoney(line.debit_local) : '' }}</td>
                                    <td data-label="Crédito" class="num">{{ line.credit_local !== '0.00' ? formatMoney(line.credit_local) : '' }}</td>
                                    <td data-label="Monto reconciliado" class="num">{{ formatMoney(line.amount) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <div v-if="!reconciliations.length" class="card muted empty-row">Todavía no hay reconciliaciones en esta cuenta.</div>
        </div>

        <!-- Traspaso a otra cuenta -->
        <DetailModal :open="showTransferModal" title="Traspaso de reconciliación (ARR)" @close="showTransferModal = false">
            <form id="transfer-form" @submit.prevent="submitTransfer">
                <p class="muted small">
                    Contabiliza un asiento con el tipo de documento reservado "ARR" entre <strong>{{ account.code }}</strong> y la
                    cuenta o socio que elijas. Después, reconciliá dentro de {{ account.code }} el movimiento nuevo junto con el
                    movimiento suelto original.
                </p>

                <div class="field-row">
                    <div class="field">
                        <label for="transfer-date">Fecha</label>
                        <input id="transfer-date" v-model="transferForm.posting_date" type="date" required>
                        <span v-if="transferForm.errors.posting_date" class="error">{{ transferForm.errors.posting_date }}</span>
                    </div>
                    <div class="field">
                        <label for="transfer-amount">Monto</label>
                        <MoneyInput id="transfer-amount" v-model="transferForm.amount" class="num-input" required />
                        <span v-if="transferForm.errors.amount" class="error">{{ transferForm.errors.amount }}</span>
                    </div>
                </div>

                <div class="field-row">
                    <div class="field">
                        <label for="transfer-direction">¿Qué le pasa a {{ account.code }}?</label>
                        <select id="transfer-direction" v-model="transferForm.direction">
                            <option value="debit">Se debita (entra)</option>
                            <option value="credit">Se acredita (sale)</option>
                        </select>
                    </div>
                    <div class="field">
                        <label for="transfer-currency">Moneda</label>
                        <select id="transfer-currency" v-model="transferForm.currency_id" required>
                            <option v-for="c in currencies" :key="c.id" :value="c.id">{{ c.code }}</option>
                        </select>
                    </div>
                </div>

                <div class="mode-toggle" role="group" aria-label="Destino">
                    <button type="button" class="mode-btn" :class="{ active: transferForm.target_type === 'account' }" :aria-pressed="transferForm.target_type === 'account'" @click="transferForm.target_type = 'account'">Otra cuenta</button>
                    <button type="button" class="mode-btn" :class="{ active: transferForm.target_type === 'partner' }" :aria-pressed="transferForm.target_type === 'partner'" @click="transferForm.target_type = 'partner'">Socio de negocio</button>
                </div>

                <div class="field">
                    <label for="transfer-target">{{ transferForm.target_type === 'account' ? 'Cuenta destino' : 'Socio de negocio destino' }}</label>
                    <select v-if="transferForm.target_type === 'account'" id="transfer-target" v-model="transferForm.target_account_id" required>
                        <option :value="null">—</option>
                        <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.code }} — {{ a.description_es }}</option>
                    </select>
                    <select v-else id="transfer-target" v-model="transferForm.target_business_partner_id" required>
                        <option :value="null">—</option>
                        <option v-for="p in businessPartners" :key="p.id" :value="p.id">{{ p.code }} — {{ p.name }}</option>
                    </select>
                    <span v-if="transferForm.errors.target_account_id" class="error">{{ transferForm.errors.target_account_id }}</span>
                    <span v-if="transferForm.errors.target_business_partner_id" class="error">{{ transferForm.errors.target_business_partner_id }}</span>
                </div>

                <div class="field">
                    <label for="transfer-description">Descripción (opcional)</label>
                    <input id="transfer-description" v-model="transferForm.description" type="text" maxlength="255" placeholder="Traspaso de reconciliación">
                </div>
            </form>

            <template #actions>
                <button type="button" class="btn btn-ghost" @click="showTransferModal = false">Cancelar</button>
                <button type="submit" form="transfer-form" class="btn btn-primary" :disabled="transferForm.processing">Contabilizar traspaso</button>
            </template>
        </DetailModal>

        <!-- Reclasificar la línea seleccionada hacia la cuenta/socio correcto -->
        <DetailModal :open="showReclassifyModal" title="Reclasificar movimiento" @close="showReclassifyModal = false">
            <form id="reclassify-form" @submit.prevent="submitReclassify">
                <p class="muted small">
                    Corrige el movimiento seleccionado: contabiliza el traspaso ARR con el mismo monto y dirección
                    contraria para cancelarlo dentro de <strong>{{ account.code }}</strong>, y de una vez lo reconcilia junto
                    con la nueva contrapartida — no hace falta un segundo paso.
                </p>

                <div class="field">
                    <label for="reclassify-date">Fecha</label>
                    <input id="reclassify-date" v-model="reclassifyForm.posting_date" type="date" required>
                    <span v-if="reclassifyForm.errors.posting_date" class="error">{{ reclassifyForm.errors.posting_date }}</span>
                </div>

                <div class="mode-toggle" role="group" aria-label="Destino">
                    <button type="button" class="mode-btn" :class="{ active: reclassifyForm.target_type === 'account' }" :aria-pressed="reclassifyForm.target_type === 'account'" @click="reclassifyForm.target_type = 'account'">Otra cuenta</button>
                    <button type="button" class="mode-btn" :class="{ active: reclassifyForm.target_type === 'partner' }" :aria-pressed="reclassifyForm.target_type === 'partner'" @click="reclassifyForm.target_type = 'partner'">Socio de negocio</button>
                </div>

                <div class="field">
                    <label for="reclassify-target">{{ reclassifyForm.target_type === 'account' ? 'Cuenta correcta' : 'Socio de negocio correcto' }}</label>
                    <select v-if="reclassifyForm.target_type === 'account'" id="reclassify-target" v-model="reclassifyForm.target_account_id" required>
                        <option :value="null">—</option>
                        <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.code }} — {{ a.description_es }}</option>
                    </select>
                    <select v-else id="reclassify-target" v-model="reclassifyForm.target_business_partner_id" required>
                        <option :value="null">—</option>
                        <option v-for="p in businessPartners" :key="p.id" :value="p.id">{{ p.code }} — {{ p.name }}</option>
                    </select>
                    <span v-if="reclassifyForm.errors.target_account_id" class="error">{{ reclassifyForm.errors.target_account_id }}</span>
                    <span v-if="reclassifyForm.errors.target_business_partner_id" class="error">{{ reclassifyForm.errors.target_business_partner_id }}</span>
                </div>

                <div class="field">
                    <label for="reclassify-description">Descripción (opcional)</label>
                    <input id="reclassify-description" v-model="reclassifyForm.description" type="text" maxlength="255" placeholder="Reclasificación">
                </div>
            </form>

            <template #actions>
                <button type="button" class="btn btn-ghost" @click="showReclassifyModal = false">Cancelar</button>
                <button type="submit" form="reclassify-form" class="btn btn-primary" :disabled="reclassifyForm.processing">Reclasificar y reconciliar</button>
            </template>
        </DetailModal>
    </AppLayout>
</template>

<style scoped>
.intro-text {
    font-size: 0.85rem;
    margin-bottom: 0.9rem;
}

.tab-row {
    display: flex;
    flex-wrap: wrap;
    gap: 0.4rem;
}

.chip {
    background: var(--color-surface-alt);
    border: 1px solid var(--color-border);
    border-radius: 999px;
    padding: 0.3rem 0.85rem;
    font-size: 0.8rem;
    cursor: pointer;
    color: var(--color-text);
}

.chip.active {
    background: var(--color-primary);
    border-color: var(--color-primary);
    color: var(--color-on-primary);
}

.reconcile-bar {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 0.5rem 1rem;
    margin-bottom: 0.75rem;
}

.diff {
    font-weight: 700;
    font-variant-numeric: tabular-nums;
}

.diff.ok { color: var(--color-success); }
.diff.off { color: var(--color-danger); }

/* Seis columnas en los ~720px que deja la barra lateral a 1025px. */
table { font-size: 0.82rem; }
th, td { padding: 0.5rem 0.7rem; }
.date-cell { font-variant-numeric: tabular-nums; white-space: nowrap; }
.desc-cell { overflow-wrap: anywhere; }

.selectable-row { cursor: pointer; }
.selectable-row:hover { background: var(--color-surface-alt); }
.selectable-row.selected { background: var(--color-primary-soft); }

.pick-cell {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    white-space: nowrap;
    font-variant-numeric: tabular-nums;
    cursor: pointer;
}

.side {
    display: inline-block;
    min-width: 1rem;
    margin-left: 0.25rem;
    font-size: 0.7rem;
    font-weight: 700;
    color: var(--color-text-muted);
}

.amount-cell input {
    width: 7.5rem;
    text-align: right;
}

.num-input { text-align: right; }

.partial-badge {
    display: inline-block;
    margin-left: 0.4rem;
    padding: 0.05rem 0.4rem;
    border-radius: 999px;
    background: var(--color-warning-soft);
    color: var(--color-warning);
    font-size: 0.7rem;
    font-weight: 700;
    text-transform: uppercase;
}

.reconciliations-list {
    display: flex;
    flex-direction: column;
    gap: 1.25rem;
}

.reconciliation-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 0.5rem;
    margin-bottom: 0.5rem;
}

.mode-toggle {
    display: flex;
    gap: 0.3rem;
    margin-bottom: 0.85rem;
}

.mode-btn {
    flex: 1;
    background: var(--color-surface-alt);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    padding: 0.35rem 0.5rem;
    font-size: 0.78rem;
    font-weight: 600;
    color: var(--color-text-muted);
    cursor: pointer;
}

.mode-btn.active {
    background: var(--color-primary);
    border-color: var(--color-primary);
    color: var(--color-on-primary);
}

@media screen and (max-width: 1024px) {
    .amount-cell input { width: auto; }
}
</style>
