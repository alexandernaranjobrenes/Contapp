<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import DetailModal from '../../Components/DetailModal.vue';
import MoneyInput from '../../Components/MoneyInput.vue';
import { useRecordDetail } from '../../Utils/recordDetail';
import { formatMoney } from '../../Utils/money';
import { ArrowLeftIcon, CalendarIcon, HandCoinsIcon } from '@lucide/vue';

const props = defineProps({
    partner: { type: Object, required: true },
    openItems: { type: Array, default: () => [] },
    paymentAccounts: { type: Array, default: () => [] },
});

// Ficha de la partida (CLAUDE.md secc. 20): el detalle y las dos acciones,
// que abren su formulario dentro del mismo modal ('details' → 'payment' o
// 'due-date' → 'details').
const { selected, openDetail: openItemDetail, closeDetail } = useRecordDetail(() => props.openItems);
const mode = ref('details');

function openDetail(item) {
    mode.value = 'details';
    openItemDetail(item);
}

const form = useForm({
    payment_account_id: props.paymentAccounts[0]?.id ?? null,
    amount: '',
    applied_date: new Date().toISOString().slice(0, 10),
});

function openPaymentForm() {
    form.reset();
    form.clearErrors();
    form.payment_account_id = props.paymentAccounts[0]?.id ?? null;
    form.amount = selected.value.balance;
    form.applied_date = new Date().toISOString().slice(0, 10);
    mode.value = 'payment';
}

function submitPayment() {
    form.post(route('business-partners.open-items.apply', [props.partner.id, selected.value.id]), {
        onSuccess: () => { mode.value = 'details'; },
    });
}

// El asiento que abrió esta partida nunca se toca (un asiento contabilizado
// no se altera) — esto corrige solo bp_open_items.due_date, que es lo que
// realmente leen antigüedad de saldos y proyección de cobros/pagos. Sirve
// para arreglar un vencimiento mal tipeado a mano sin tener que reversar y
// recontabilizar todo el asiento original.
const dueDateForm = useForm({ due_date: '' });

function openDueDateEdit() {
    dueDateForm.reset();
    dueDateForm.clearErrors();
    dueDateForm.due_date = selected.value.due_date;
    mode.value = 'due-date';
}

function submitDueDate() {
    dueDateForm.put(route('business-partners.open-items.update-due-date', [props.partner.id, selected.value.id]), {
        onSuccess: () => { mode.value = 'details'; },
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

function documentLabel(item) {
    return `${item.document_type_code}-${item.document_number}`;
}
</script>

<template>
    <Head :title="`Partidas — ${partner.code}`" />

    <AppLayout :title="`Partidas abiertas — ${partner.name}`">
        <div class="view-toolbar">
            <Link :href="route('business-partners.index')" class="btn btn-ghost"><ArrowLeftIcon /> Socios de negocio</Link>
            <div class="view-actions">
                <button
                    type="button"
                    class="btn btn-primary"
                    :disabled="!canReconcile || reconcileForm.processing"
                    title="Marcá con la casilla las partidas que se cancelan entre sí"
                    @click="submitReconcile"
                >Reconciliar entre sí</button>
            </div>
        </div>

        <div v-if="reconcileForm.errors.reconciliation" class="flash flash-error">{{ reconcileForm.errors.reconciliation }}</div>

        <div v-if="selectedItems.length" class="reconcile-bar">
            <strong>{{ selectedItems.length }} partida(s) seleccionada(s)</strong>
            <span class="muted">Neto: {{ formatMoney(selectedNet) }}</span>
            <span v-if="selectedItems.length >= 2 && parseFloat(selectedNet) !== 0" class="muted small">
                El neto tiene que dar exactamente 0 para reconciliar — si queda una diferencia real, aplicá un pago
                por esa diferencia contra la partida que quede pendiente.
            </span>
        </div>

        <div class="card">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Documento</th>
                            <th>Vencimiento</th>
                            <th class="num">Saldo</th>
                            <th>Estado</th>
                            <th class="check-col"><span class="sr-only">Reconciliar</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="item in openItems"
                            :key="item.id"
                            class="clickable-row"
                            tabindex="0"
                            @click="openDetail(item)"
                            @keydown.enter.self="openDetail(item)"
                            @keydown.space.self.prevent="openDetail(item)"
                        >
                            <td>{{ documentLabel(item) }}</td>
                            <td data-label="Vencimiento">{{ item.due_date }}</td>
                            <td data-label="Saldo" class="num">{{ formatMoney(item.balance) }}</td>
                            <td data-label="Estado">
                                <span class="badge" :class="statusBadge[item.status]">{{ statusLabels[item.status] }}</span>
                            </td>
                            <!-- La casilla es la selección para reconciliar, no una acción de la fila. -->
                            <td data-label="Reconciliar" class="check-col" @click.stop>
                                <input
                                    v-if="item.status !== 'closed'"
                                    type="checkbox"
                                    :checked="selectedIds.has(item.id)"
                                    :aria-label="`Marcar ${documentLabel(item)} para reconciliar`"
                                    title="Marcar para reconciliar internamente contra otra(s) partida(s) seleccionada(s)"
                                    @change="toggleSelected(item)"
                                >
                            </td>
                        </tr>
                        <tr v-if="!openItems.length">
                            <td colspan="5" class="muted empty-row">Este socio no tiene partidas registradas.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <DetailModal :open="!!selected" :title="selected ? documentLabel(selected) : ''" @close="closeDetail">
            <template #badge>
                <span v-if="selected" class="badge" :class="statusBadge[selected.status]">{{ statusLabels[selected.status] }}</span>
            </template>

            <dl v-if="selected && mode === 'details'" class="detail-list">
                <div>
                    <dt>Vencimiento</dt>
                    <dd>{{ selected.due_date }}</dd>
                </div>
                <div>
                    <dt>Original</dt>
                    <dd>{{ formatMoney(selected.original_amount) }}</dd>
                </div>
                <div>
                    <dt>Aplicado</dt>
                    <dd>{{ formatMoney(selected.applied_amount) }}</dd>
                </div>
                <div>
                    <dt>Saldo</dt>
                    <dd><strong>{{ formatMoney(selected.balance) }}</strong></dd>
                </div>
            </dl>

            <form v-if="selected && mode === 'payment'" id="open-item-payment-form" @submit.prevent="submitPayment">
                <div class="field">
                    <label for="payment-account">Cuenta de pago</label>
                    <select id="payment-account" v-model="form.payment_account_id" required>
                        <option v-for="a in paymentAccounts" :key="a.id" :value="a.id">
                            {{ a.code }} — {{ a.description_es }}
                        </option>
                    </select>
                </div>
                <div class="field-row">
                    <div class="field">
                        <label for="payment-amount">Monto</label>
                        <MoneyInput id="payment-amount" v-model="form.amount" required />
                        <span v-if="form.errors.amount" class="error">{{ form.errors.amount }}</span>
                    </div>
                    <div class="field">
                        <label for="payment-date">Fecha</label>
                        <input id="payment-date" v-model="form.applied_date" type="date" required>
                    </div>
                </div>
            </form>

            <form v-if="selected && mode === 'due-date'" id="open-item-due-date-form" @submit.prevent="submitDueDate">
                <div class="field">
                    <label for="due-date">Nuevo vencimiento</label>
                    <input id="due-date" v-model="dueDateForm.due_date" type="date" required>
                    <span v-if="dueDateForm.errors.due_date" class="error">{{ dueDateForm.errors.due_date }}</span>
                </div>
                <p class="hint">
                    Esto corrige solo el vencimiento de esta partida — el asiento contabilizado que la
                    originó no se modifica. Afecta a los reportes de antigüedad de saldos y proyección
                    de cobros/pagos, que leen este valor.
                </p>
            </form>

            <template #actions>
                <template v-if="selected && mode === 'details' && selected.status !== 'closed'">
                    <button type="button" class="btn btn-ghost" @click="openDueDateEdit"><CalendarIcon /> Corregir vencimiento</button>
                    <button type="button" class="btn btn-primary" @click="openPaymentForm"><HandCoinsIcon /> Aplicar pago</button>
                </template>
                <template v-else-if="selected && mode === 'payment'">
                    <button type="button" class="btn btn-ghost" @click="mode = 'details'">Cancelar</button>
                    <button type="submit" form="open-item-payment-form" class="btn btn-primary" :disabled="form.processing">Confirmar pago</button>
                </template>
                <template v-else-if="selected && mode === 'due-date'">
                    <button type="button" class="btn btn-ghost" @click="mode = 'details'">Cancelar</button>
                    <button type="submit" form="open-item-due-date-form" class="btn btn-primary" :disabled="dueDateForm.processing">Guardar</button>
                </template>
            </template>
        </DetailModal>
    </AppLayout>
</template>

<style scoped>
table { font-size: 0.85rem; }
.check-col { width: 2.5rem; text-align: center; }
.hint { margin: 0; }

.reconcile-bar {
    display: flex;
    align-items: center;
    gap: 0.4rem 1rem;
    padding: 0.7rem 1rem;
    margin-bottom: 0.75rem;
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    background: var(--color-surface-alt);
    flex-wrap: wrap;
    font-size: 0.85rem;
}

@media screen and (max-width: 1024px) {
    .check-col { width: auto; text-align: left; }
}
</style>
