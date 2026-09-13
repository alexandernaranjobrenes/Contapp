<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import DocumentToolbar from '../../Components/DocumentToolbar.vue';
import MoneyInput from '../../Components/MoneyInput.vue';
import { formatMoney } from '../../Utils/money';

const props = defineProps({
    partner: { type: Object, required: true },
    openItems: { type: Array, default: () => [] },
    paymentAccounts: { type: Array, default: () => [] },
});

const activeItemId = ref(null);

const form = useForm({
    payment_account_id: props.paymentAccounts[0]?.id ?? null,
    amount: '',
    applied_date: new Date().toISOString().slice(0, 10),
});

function openPaymentForm(item) {
    editingDueDateId.value = null;
    activeItemId.value = item.id;
    form.reset();
    form.payment_account_id = props.paymentAccounts[0]?.id ?? null;
    form.amount = item.balance;
    form.applied_date = new Date().toISOString().slice(0, 10);
}

function submitPayment(item) {
    form.post(route('business-partners.open-items.apply', [props.partner.id, item.id]), {
        onSuccess: () => { activeItemId.value = null; },
    });
}

// El asiento que abrió esta partida nunca se toca (un asiento contabilizado
// no se altera) — esto corrige solo bp_open_items.due_date, que es lo que
// realmente leen antigüedad de saldos y proyección de cobros/pagos. Sirve
// para arreglar un vencimiento mal tipeado a mano sin tener que reversar y
// recontabilizar todo el asiento original.
const editingDueDateId = ref(null);
const dueDateForm = useForm({ due_date: '' });

function openDueDateEdit(item) {
    activeItemId.value = null;
    editingDueDateId.value = item.id;
    dueDateForm.reset();
    dueDateForm.due_date = item.due_date;
}

function submitDueDate(item) {
    dueDateForm.put(route('business-partners.open-items.update-due-date', [props.partner.id, item.id]), {
        onSuccess: () => { editingDueDateId.value = null; },
    });
}

// Reconciliación interna: varias partidas de ESTE socio que en el fondo ya
// se cancelaban entre sí (ej. un saldo inicial y un pago que ya se había
// contabilizado por fuera de "Aplicar pago", contra la misma cuenta de
// control) — se cierran entre sí sin contabilizar ningún asiento nuevo.
// Solo tiene sentido cuando el neto con signo de lo seleccionado da cero;
// si queda una diferencia real, esa sí se cobra/paga con "Aplicar pago"
// normal contra la partida que quede pendiente.
const selectedIds = ref(new Set());

function toggleSelected(item) {
    const next = new Set(selectedIds.value);
    if (next.has(item.id)) next.delete(item.id); else next.add(item.id);
    selectedIds.value = next;
}

const selectedItems = computed(() => props.openItems.filter((i) => selectedIds.value.has(i.id)));

const selectedNet = computed(() =>
    selectedItems.value.reduce((sum, i) => sum + (parseFloat(i.signed_balance) || 0), 0).toFixed(2)
);

const canReconcile = computed(() => selectedItems.value.length >= 2 && parseFloat(selectedNet.value) === 0);

const reconcileForm = useForm({ open_item_ids: [] });

function submitReconcile() {
    if (! canReconcile.value) return;

    reconcileForm.open_item_ids = Array.from(selectedIds.value);
    reconcileForm.post(route('business-partners.open-items.reconcile', props.partner.id), {
        preserveScroll: true,
        onSuccess: () => { selectedIds.value = new Set(); },
    });
}

const statusLabels = { open: 'Abierta', partial: 'Parcial', closed: 'Cerrada' };
const statusBadge = { open: 'badge-warning', partial: 'badge-warning', closed: 'badge-success' };
</script>

<template>
    <Head :title="`Partidas — ${partner.code}`" />

    <AppLayout :title="`Partidas abiertas — ${partner.name}`">
        <DocumentToolbar :new-href="route('business-partners.create')" />

        <div v-if="reconcileForm.errors.reconciliation" class="flash flash-error">{{ reconcileForm.errors.reconciliation }}</div>

        <div class="card">
            <table>
                <thead>
                    <tr>
                        <th class="check-col"></th>
                        <th>Documento</th>
                        <th>Vencimiento</th>
                        <th class="num">Original</th>
                        <th class="num">Aplicado</th>
                        <th class="num">Saldo</th>
                        <th>Estado</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <template v-for="item in openItems" :key="item.id">
                        <tr>
                            <td class="check-col">
                                <input
                                    v-if="item.status !== 'closed'"
                                    type="checkbox"
                                    :checked="selectedIds.has(item.id)"
                                    title="Marcar para reconciliar internamente contra otra(s) partida(s) seleccionada(s)"
                                    @change="toggleSelected(item)"
                                >
                            </td>
                            <td>{{ item.document_type_code }}-{{ item.document_number }}</td>
                            <td>{{ item.due_date }}</td>
                            <td class="num">{{ formatMoney(item.original_amount) }}</td>
                            <td class="num">{{ formatMoney(item.applied_amount) }}</td>
                            <td class="num">{{ formatMoney(item.balance) }}</td>
                            <td>
                                <span class="badge" :class="statusBadge[item.status]">{{ statusLabels[item.status] }}</span>
                            </td>
                            <td class="actions-cell">
                                <button
                                    v-if="item.status !== 'closed'"
                                    type="button"
                                    class="btn btn-ghost"
                                    @click="openPaymentForm(item)"
                                >Aplicar pago</button>
                                <button
                                    v-if="item.status !== 'closed'"
                                    type="button"
                                    class="btn btn-ghost"
                                    title="El asiento original no se toca — esto corrige solo el vencimiento de esta partida"
                                    @click="openDueDateEdit(item)"
                                >Corregir vencimiento</button>
                            </td>
                        </tr>
                        <tr v-if="activeItemId === item.id">
                            <td colspan="8">
                                <form class="payment-form" @submit.prevent="submitPayment(item)">
                                    <div class="field">
                                        <label>Cuenta de pago</label>
                                        <select v-model="form.payment_account_id" required>
                                            <option v-for="a in paymentAccounts" :key="a.id" :value="a.id">
                                                {{ a.code }} — {{ a.description_es }}
                                            </option>
                                        </select>
                                    </div>
                                    <div class="field">
                                        <label>Monto</label>
                                        <MoneyInput v-model="form.amount" required />
                                    </div>
                                    <div class="field">
                                        <label>Fecha</label>
                                        <input v-model="form.applied_date" type="date" required>
                                    </div>
                                    <div class="field actions">
                                        <button type="submit" class="btn btn-primary" :disabled="form.processing">Confirmar</button>
                                        <button type="button" class="btn btn-ghost" @click="activeItemId = null">Cancelar</button>
                                    </div>
                                    <p v-if="form.errors.amount" class="error span-all">{{ form.errors.amount }}</p>
                                </form>
                            </td>
                        </tr>
                        <tr v-if="editingDueDateId === item.id">
                            <td colspan="8">
                                <form class="payment-form" @submit.prevent="submitDueDate(item)">
                                    <div class="field">
                                        <label>Nuevo vencimiento</label>
                                        <input v-model="dueDateForm.due_date" type="date" required>
                                    </div>
                                    <div class="field actions">
                                        <button type="submit" class="btn btn-primary" :disabled="dueDateForm.processing">Confirmar</button>
                                        <button type="button" class="btn btn-ghost" @click="editingDueDateId = null">Cancelar</button>
                                    </div>
                                    <p class="hint span-all">
                                        Esto corrige solo el vencimiento de esta partida — el asiento contabilizado que la
                                        originó no se modifica. Afecta a los reportes de antigüedad de saldos y proyección
                                        de cobros/pagos, que leen este valor.
                                    </p>
                                    <p v-if="dueDateForm.errors.due_date" class="error span-all">{{ dueDateForm.errors.due_date }}</p>
                                </form>
                            </td>
                        </tr>
                    </template>
                    <tr v-if="!openItems.length">
                        <td colspan="8" class="muted empty-row">Este socio no tiene partidas registradas.</td>
                    </tr>
                </tbody>
            </table>

            <div v-if="selectedItems.length" class="reconcile-bar">
                <div class="reconcile-summary">
                    <strong>{{ selectedItems.length }} partida(s) seleccionada(s)</strong>
                    <span class="muted">Neto: {{ formatMoney(selectedNet) }}</span>
                </div>
                <button type="button" class="btn btn-primary" :disabled="!canReconcile || reconcileForm.processing" @click="submitReconcile">
                    Reconciliar entre sí
                </button>
                <span v-if="selectedItems.length >= 2 && parseFloat(selectedNet) !== 0" class="muted small">
                    El neto tiene que dar exactamente 0 para reconciliar — si queda una diferencia real, aplicá un pago
                    por esa diferencia contra la partida que quede pendiente.
                </span>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
table { font-size: 0.85rem; }
th, td { text-align: left; padding: 0.5rem 1rem; border-top: 1px solid var(--color-border); }
.check-col { width: 2rem; }
.muted { color: var(--color-text-muted); }
.empty-row { text-align: center; padding: 1.5rem; }

.actions-cell { display: flex; gap: 0.4rem; }

.payment-form {
    display: flex;
    align-items: flex-end;
    gap: 1rem;
    background: var(--color-surface-alt);
    padding: 0.85rem 1rem;
    flex-wrap: wrap;
}

.payment-form .field { margin-bottom: 0; min-width: 160px; }
.payment-form select, .payment-form input {
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    padding: 0.4rem 0.55rem;
    font-size: 0.85rem;
}
.actions { flex-direction: row; gap: 0.5rem; }
.span-all { width: 100%; }
.hint { color: var(--color-text-muted); font-size: 0.78rem; margin: 0; }
.small { font-size: 0.78rem; }

.reconcile-bar {
    display: flex;
    align-items: center;
    gap: 1rem;
    padding: 0.85rem 1rem;
    border-top: 1px solid var(--color-border);
    background: var(--color-surface-alt);
    flex-wrap: wrap;
}

.reconcile-summary {
    display: flex;
    flex-direction: column;
    gap: 0.15rem;
    font-size: 0.85rem;
}

.flash-error {
    background: var(--color-danger-soft);
    color: var(--color-danger);
    padding: 0.6rem 0.9rem;
    border-radius: 6px;
    margin-bottom: 1rem;
    font-size: 0.85rem;
}
</style>
