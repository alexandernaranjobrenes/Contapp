<script setup>
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import DocumentToolbar from '../../Components/DocumentToolbar.vue';
import MoneyInput from '../../Components/MoneyInput.vue';
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
    if (! confirm('¿Deshacer esta reconciliación? Los movimientos vuelven a quedar sueltos — ningún asiento se modifica.')) return;

    router.delete(route('account-reconciliation.destroy', reconciliation.id), { preserveScroll: true });
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
        <template #actions>
            <button type="button" class="btn btn-ghost" @click="openTransferModal">Traspaso a otra cuenta (ARR)</button>
        </template>

        <DocumentToolbar can-create @new="openTransferModal" />

        <div v-if="page.props.flash?.success" class="flash flash-success">{{ page.props.flash.success }}</div>
        <div v-if="page.props.errors?.reconciliation" class="flash flash-error">{{ page.props.errors.reconciliation }}</div>

        <p class="muted intro-text">
            Vincula movimientos de esta misma cuenta cuyo neto sea cero — útil para cuentas que no tienen un mecanismo de
            liquidación propio (partidas abiertas de socios, conciliación bancaria). Un movimiento con socio de negocio
            no aparece acá: se reconcilia por su partida abierta. Se puede reconciliar solo una parte de un documento
            (el resto queda disponible para después), y si un movimiento se digitó en la cuenta equivocada, seleccionalo
            solo y usá "Reclasificar a otra cuenta" para corregirlo y reconciliarlo en un solo paso.
        </p>

        <div class="tab-row">
            <button type="button" class="chip" :class="{ active: activeTab === 'unreconciled' }" @click="activeTab = 'unreconciled'">
                No reconciliados ({{ unreconciled.length }})
            </button>
            <button type="button" class="chip" :class="{ active: activeTab === 'reconciled' }" @click="activeTab = 'reconciled'">
                Reconciliados ({{ reconciliations.length }})
            </button>
        </div>

        <div v-if="activeTab === 'unreconciled'" class="card">
            <div class="reconcile-bar">
                <span class="muted small">{{ selectedIds.length }} seleccionado(s)</span>
                <span class="diff" :class="canReconcile ? 'ok' : (selectedIds.length ? 'off' : '')">
                    Diferencia: {{ formatMoney(difference) }}
                </span>
                <button type="button" class="btn btn-primary" :disabled="!canReconcile" @click="reconcileSelected">
                    Reconciliar seleccionados
                </button>
                <button v-if="canReclassify" type="button" class="btn btn-ghost" @click="openReclassifyModal">
                    Reclasificar a otra cuenta
                </button>
            </div>

            <table>
                <thead>
                    <tr>
                        <th></th>
                        <th>Fecha</th>
                        <th>Documento</th>
                        <th>Descripción</th>
                        <th class="num">Débito</th>
                        <th class="num">Crédito</th>
                        <th class="num">Disponible</th>
                        <th class="num">A reconciliar</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="line in unreconciled" :key="line.id" class="selectable-row" @click="toggleSelect(line.id)">
                        <td class="check-cell" @click.stop="toggleSelect(line.id)">
                            <input type="checkbox" :checked="selectedIds.includes(line.id)" @change="toggleSelect(line.id)">
                        </td>
                        <td class="num">{{ line.posting_date }}</td>
                        <td>{{ line.document }}</td>
                        <td class="desc-cell">{{ line.description }}</td>
                        <td class="num">{{ line.debit_local !== '0.00' ? formatMoney(line.debit_local) : '' }}</td>
                        <td class="num">{{ line.credit_local !== '0.00' ? formatMoney(line.credit_local) : '' }}</td>
                        <td class="num">{{ formatMoney(line.available_amount) }}</td>
                        <td class="num amount-cell" @click.stop>
                            <MoneyInput v-if="selectedIds.includes(line.id)" v-model="selectedAmounts[line.id]" />
                        </td>
                    </tr>
                    <tr v-if="!unreconciled.length">
                        <td colspan="8" class="muted empty-row">No hay movimientos sin reconciliar en esta cuenta.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-else class="reconciliations-list">
            <div v-for="r in reconciliations" :key="r.id" class="card reconciliation-card">
                <div class="reconciliation-header">
                    <span class="muted small">
                        Reconciliado el {{ r.reconciled_at }}<template v-if="r.reconciled_by"> por {{ r.reconciled_by }}</template>
                    </span>
                    <button type="button" class="btn btn-ghost" @click="unreconcile(r)">Deshacer</button>
                </div>
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
                            <td class="num">{{ line.posting_date }}</td>
                            <td>{{ line.document }}</td>
                            <td class="desc-cell">
                                {{ line.description }}
                                <span v-if="line.is_partial" class="partial-badge">parcial</span>
                            </td>
                            <td class="num">{{ line.debit_local !== '0.00' ? formatMoney(line.debit_local) : '' }}</td>
                            <td class="num">{{ line.credit_local !== '0.00' ? formatMoney(line.credit_local) : '' }}</td>
                            <td class="num">{{ formatMoney(line.amount) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="!reconciliations.length" class="card muted empty-row">Todavía no hay reconciliaciones en esta cuenta.</div>
        </div>

        <!-- Traspaso a otra cuenta -->
        <div v-if="showTransferModal" class="modal-backdrop" @click.self="showTransferModal = false">
            <form class="modal-card card" @submit.prevent="submitTransfer">
                <h2>Traspaso de reconciliación (ARR)</h2>
                <p class="muted small">
                    Contabiliza un asiento con el tipo de documento reservado "ARR" entre <strong>{{ account.code }}</strong> y la
                    cuenta o socio que elijas. Después, reconciliá dentro de {{ account.code }} el movimiento nuevo junto con el
                    movimiento suelto original.
                </p>

                <div class="grid-2">
                    <div class="field">
                        <label>Fecha</label>
                        <input v-model="transferForm.posting_date" type="date" required>
                        <span v-if="transferForm.errors.posting_date" class="error">{{ transferForm.errors.posting_date }}</span>
                    </div>
                    <div class="field">
                        <label>Monto</label>
                        <MoneyInput v-model="transferForm.amount" required />
                        <span v-if="transferForm.errors.amount" class="error">{{ transferForm.errors.amount }}</span>
                    </div>
                </div>

                <div class="field">
                    <label>¿Qué le pasa a {{ account.code }}?</label>
                    <select v-model="transferForm.direction">
                        <option value="debit">Se debita (entra)</option>
                        <option value="credit">Se acredita (sale)</option>
                    </select>
                </div>

                <div class="field">
                    <label>Moneda</label>
                    <select v-model="transferForm.currency_id" required>
                        <option v-for="c in currencies" :key="c.id" :value="c.id">{{ c.code }}</option>
                    </select>
                </div>

                <div class="mode-toggle">
                    <button type="button" class="mode-btn" :class="{ active: transferForm.target_type === 'account' }" @click="transferForm.target_type = 'account'">Otra cuenta</button>
                    <button type="button" class="mode-btn" :class="{ active: transferForm.target_type === 'partner' }" @click="transferForm.target_type = 'partner'">Socio de negocio</button>
                </div>

                <div class="field">
                    <label v-if="transferForm.target_type === 'account'">Cuenta destino</label>
                    <label v-else>Socio de negocio destino</label>
                    <select v-if="transferForm.target_type === 'account'" v-model="transferForm.target_account_id" required>
                        <option :value="null">—</option>
                        <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.code }} — {{ a.description_es }}</option>
                    </select>
                    <select v-else v-model="transferForm.target_business_partner_id" required>
                        <option :value="null">—</option>
                        <option v-for="p in businessPartners" :key="p.id" :value="p.id">{{ p.code }} — {{ p.name }}</option>
                    </select>
                    <span v-if="transferForm.errors.target_account_id" class="error">{{ transferForm.errors.target_account_id }}</span>
                    <span v-if="transferForm.errors.target_business_partner_id" class="error">{{ transferForm.errors.target_business_partner_id }}</span>
                </div>

                <div class="field">
                    <label>Descripción (opcional)</label>
                    <input v-model="transferForm.description" type="text" maxlength="255" placeholder="Traspaso de reconciliación">
                </div>

                <div class="modal-actions">
                    <button type="submit" class="btn btn-primary" :disabled="transferForm.processing">Contabilizar traspaso</button>
                    <button type="button" class="btn btn-ghost" @click="showTransferModal = false">Cancelar</button>
                </div>
            </form>
        </div>

        <!-- Reclasificar la línea seleccionada hacia la cuenta/socio correcto -->
        <div v-if="showReclassifyModal" class="modal-backdrop" @click.self="showReclassifyModal = false">
            <form class="modal-card card" @submit.prevent="submitReclassify">
                <h2>Reclasificar movimiento</h2>
                <p class="muted small">
                    Corrige el movimiento seleccionado: contabiliza el traspaso ARR con el mismo monto y dirección
                    contraria para cancelarlo dentro de <strong>{{ account.code }}</strong>, y de una vez lo reconcilia junto
                    con la nueva contrapartida — no hace falta un segundo paso.
                </p>

                <div class="field">
                    <label>Fecha</label>
                    <input v-model="reclassifyForm.posting_date" type="date" required>
                    <span v-if="reclassifyForm.errors.posting_date" class="error">{{ reclassifyForm.errors.posting_date }}</span>
                </div>

                <div class="mode-toggle">
                    <button type="button" class="mode-btn" :class="{ active: reclassifyForm.target_type === 'account' }" @click="reclassifyForm.target_type = 'account'">Otra cuenta</button>
                    <button type="button" class="mode-btn" :class="{ active: reclassifyForm.target_type === 'partner' }" @click="reclassifyForm.target_type = 'partner'">Socio de negocio</button>
                </div>

                <div class="field">
                    <label v-if="reclassifyForm.target_type === 'account'">Cuenta correcta</label>
                    <label v-else>Socio de negocio correcto</label>
                    <select v-if="reclassifyForm.target_type === 'account'" v-model="reclassifyForm.target_account_id" required>
                        <option :value="null">—</option>
                        <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.code }} — {{ a.description_es }}</option>
                    </select>
                    <select v-else v-model="reclassifyForm.target_business_partner_id" required>
                        <option :value="null">—</option>
                        <option v-for="p in businessPartners" :key="p.id" :value="p.id">{{ p.code }} — {{ p.name }}</option>
                    </select>
                    <span v-if="reclassifyForm.errors.target_account_id" class="error">{{ reclassifyForm.errors.target_account_id }}</span>
                    <span v-if="reclassifyForm.errors.target_business_partner_id" class="error">{{ reclassifyForm.errors.target_business_partner_id }}</span>
                </div>

                <div class="field">
                    <label>Descripción (opcional)</label>
                    <input v-model="reclassifyForm.description" type="text" maxlength="255" placeholder="Reclasificación">
                </div>

                <div class="modal-actions">
                    <button type="submit" class="btn btn-primary" :disabled="reclassifyForm.processing">Reclasificar y reconciliar</button>
                    <button type="button" class="btn btn-ghost" @click="showReclassifyModal = false">Cancelar</button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>

<style scoped>
.intro-text {
    font-size: 0.85rem;
    max-width: 760px;
    margin-bottom: 0.9rem;
}

.tab-row {
    display: flex;
    gap: 0.4rem;
    margin-bottom: 0.75rem;
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
    gap: 1rem;
    padding: 0.75rem 1.1rem;
    border-bottom: 1px solid var(--color-border);
}

.diff {
    font-weight: 700;
    font-variant-numeric: tabular-nums;
}

.diff.ok { color: var(--color-success); }
.diff.off { color: var(--color-danger); }

table { font-size: 0.85rem; width: 100%; }
th, td { text-align: left; padding: 0.5rem 0.9rem; border-top: 1px solid var(--color-border); }
.num { font-variant-numeric: tabular-nums; text-align: right; }
thead th.num { text-align: right; }
.desc-cell { white-space: normal; max-width: 320px; }
.muted { color: var(--color-text-muted); }
.small { font-size: 0.78rem; }
.empty-row { text-align: center; padding: 1.5rem; }

.check-cell { width: 2rem; text-align: center; }
.selectable-row { cursor: pointer; }
.selectable-row:hover { background: var(--color-primary-soft); }

.amount-cell input {
    width: 100px;
    text-align: right;
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    padding: 0.25rem 0.4rem;
    font-size: 0.82rem;
    color: var(--color-text);
}

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
    gap: 0.75rem;
}

.reconciliation-card { padding: 0; }
.reconciliation-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.65rem 1.1rem;
    border-bottom: 1px solid var(--color-border);
}

.flash { margin-bottom: 0.9rem; padding: 0.6rem 0.9rem; border-radius: var(--radius-sm); font-size: 0.85rem; }
.flash-success { background: var(--color-success-soft); color: var(--color-success); }
.flash-error { background: var(--color-danger-soft); color: var(--color-danger); }

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

.modal-card {
    width: 480px;
    max-width: 100%;
    padding: 1.5rem;
    max-height: 90vh;
    overflow-y: auto;
}

.modal-card h2 { font-size: 1rem; margin: 0 0 0.5rem; }

.grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 0 1rem; }

.field {
    display: flex;
    flex-direction: column;
    gap: 0.2rem;
    margin-bottom: 0.85rem;
}

.field label {
    font-size: 0.78rem;
    color: var(--color-text-muted);
}

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

.modal-actions { display: flex; gap: 0.6rem; margin-top: 0.5rem; }
</style>
