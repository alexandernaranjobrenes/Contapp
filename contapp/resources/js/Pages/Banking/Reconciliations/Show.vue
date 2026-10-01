<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import { confirmAction } from '../../../Utils/confirm';
import { formatMoney } from '../../../Utils/money';
import { ArrowLeftIcon, CheckIcon, LockIcon, RotateCcwIcon } from '@lucide/vue';

const props = defineProps({
    reconciliation: { type: Object, required: true },
    adjustedBookBalance: { type: String, required: true },
    adjustedBankBalance: { type: String, required: true },
    isBalanced: { type: Boolean, required: true },
});

function toggleBank(line) {
    router.post(route('bank-reconciliations.confirm-line', [props.reconciliation.id, line.id]), {}, {
        preserveScroll: true,
    });
}

function closeReconciliation() {
    router.post(route('bank-reconciliations.close', props.reconciliation.id), {}, {
        preserveScroll: true,
    });
}

function reopenReconciliation() {
    confirmAction({
        title: 'Reabrir conciliación',
        message: 'Vas a poder corregir los checks y volver a cerrarla.',
        confirmLabel: 'Reabrir',
        onConfirm: () => router.post(route('bank-reconciliations.reopen', props.reconciliation.id), {}, { preserveScroll: true }),
    });
}

function destroyReconciliation() {
    confirmAction({
        title: 'Eliminar conciliación',
        message: 'La conciliación se elimina de forma definitiva. El asiento contable no se ve afectado, solo se pierde el enlace de conciliación.',
        confirmLabel: 'Eliminar',
        danger: true,
        onConfirm: () => router.delete(route('bank-reconciliations.destroy', props.reconciliation.id)),
    });
}
</script>

<template>
    <Head :title="`Conciliación ${reconciliation.cutoff_date}`" />

    <AppLayout :title="`Conciliación — ${reconciliation.bank_account?.bank_name} al ${reconciliation.cutoff_date}`">
        <div class="view-toolbar">
            <Link
                v-if="reconciliation.bank_account"
                :href="route('bank-reconciliations.index', reconciliation.bank_account.id)"
                class="btn btn-ghost"
            ><ArrowLeftIcon /> Historial de la cuenta</Link>
            <div class="view-actions">
                <span v-if="reconciliation.status === 'completed'" class="badge badge-success">Cerrada</span>
                <button type="button" class="btn btn-ghost btn-danger-text" @click="destroyReconciliation">Eliminar</button>
                <button
                    v-if="reconciliation.status !== 'completed'"
                    type="button"
                    class="btn btn-primary"
                    @click="closeReconciliation"
                ><LockIcon /> Cerrar conciliación</button>
                <button v-else type="button" class="btn btn-ghost" @click="reopenReconciliation"><RotateCcwIcon /> Reabrir</button>
            </div>
        </div>

        <div v-if="$page.props.errors?.balance" class="flash flash-error">{{ $page.props.errors.balance }}</div>

        <div class="summary-grid">
            <div class="card summary-card">
                <h3>Banco</h3>
                <dl>
                    <dt>Saldo según banco</dt><dd class="num">{{ formatMoney(reconciliation.bank_balance) }}</dd>
                    <dt>Depósitos no acreditados</dt><dd class="num">{{ formatMoney(reconciliation.unrecorded_deposits) }}</dd>
                    <dt>Cheques no pagados</dt><dd class="num">−{{ formatMoney(reconciliation.unpaid_checks) }}</dd>
                    <dt class="total">Saldo banco ajustado</dt><dd class="num total">{{ formatMoney(adjustedBankBalance) }}</dd>
                </dl>
            </div>

            <div class="card summary-card">
                <h3>Libros</h3>
                <dl>
                    <dt>Saldo de cuenta</dt><dd class="num">{{ formatMoney(reconciliation.book_balance) }}</dd>
                    <dt>Créd. banco no registrados</dt><dd class="num">{{ formatMoney(reconciliation.unrecorded_bank_credits) }}</dd>
                    <dt>Déb. banco no registrados</dt><dd class="num">−{{ formatMoney(reconciliation.unrecorded_bank_debits) }}</dd>
                    <dt class="total">Saldo libros ajustado</dt><dd class="num total">{{ formatMoney(adjustedBookBalance) }}</dd>
                </dl>
            </div>

            <div class="card status-card" :class="isBalanced ? 'ok' : 'off'">
                <span class="status-label">{{ isBalanced ? 'Cuadra' : 'No cuadra' }}</span>
            </div>
        </div>

        <!-- La marca «visto en banco» es la captura de esta pantalla, no una
             acción sobre el registro: por eso se queda en la fila. -->
        <div class="card">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Cuenta</th>
                            <th>Descripción</th>
                            <th class="num">Débito</th>
                            <th class="num">Crédito</th>
                            <th title="Conciliado en libros">Cta</th>
                            <th title="Visto en el estado de cuenta del banco">Bco</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="line in reconciliation.lines" :key="line.id">
                            <td>{{ line.journal_detail?.account?.code }}</td>
                            <td data-label="Descripción">{{ line.journal_detail?.description }}</td>
                            <td data-label="Débito" class="num">{{ formatMoney(line.journal_detail?.debit_local) }}</td>
                            <td data-label="Crédito" class="num">{{ formatMoney(line.journal_detail?.credit_local) }}</td>
                            <td data-label="En libros" class="check-cell">
                                <CheckIcon v-if="line.matched_in_books" aria-label="Conciliado en libros" role="img" />
                            </td>
                            <td data-label="En banco" class="check-cell">
                                <button
                                    type="button"
                                    class="check-btn"
                                    :class="{ checked: line.matched_in_bank }"
                                    :disabled="reconciliation.status === 'completed'"
                                    :title="line.matched_in_bank ? 'Visto en banco — clic para desmarcar' : 'Marcar como visto en el estado de cuenta del banco'"
                                    :aria-label="line.matched_in_bank ? 'Visto en banco — clic para desmarcar' : 'Marcar como visto en el estado de cuenta del banco'"
                                    @click="toggleBank(line)"
                                ><CheckIcon v-if="line.matched_in_bank" /></button>
                            </td>
                        </tr>
                        <tr v-if="!reconciliation.lines.length">
                            <td colspan="6" class="muted empty-row">Sin movimientos hasta la fecha de corte.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
.summary-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(100%, 16rem), 1fr));
    gap: 1rem;
    margin-bottom: 1.25rem;
    align-items: stretch;
}

.summary-card { padding: 1rem 1.1rem; }
.summary-card h3 { margin: 0 0 0.6rem; font-size: 0.85rem; }

dl { margin: 0; display: grid; grid-template-columns: 1fr auto; row-gap: 0.35rem; font-size: 0.82rem; }
dt { color: var(--color-text-muted); font-weight: 500; }
dd { margin: 0; }
dt.total, dd.total { font-weight: 800; border-top: 1px solid var(--color-border); padding-top: 0.35rem; margin-top: 0.15rem; color: var(--color-text); }

.status-card {
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 1rem 1.5rem;
    min-width: 140px;
}

.status-card.ok { background: var(--color-success-soft); }
.status-card.off { background: var(--color-danger-soft); }
.status-label { font-weight: 800; font-size: 0.95rem; }
.status-card.ok .status-label { color: var(--color-success); }
.status-card.off .status-label { color: var(--color-danger); }

table { font-size: 0.85rem; }

.check-cell { text-align: center; }
.check-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0;
    width: 26px;
    height: 26px;
    border-radius: 4px;
    border: 1px solid var(--color-border);
    background: var(--color-surface);
    cursor: pointer;
    font-weight: 700;
    color: var(--color-success);
}
.check-btn.checked { background: var(--color-success-soft); }
.check-btn:disabled { cursor: not-allowed; opacity: .6; }

@media screen and (max-width: 1024px) {
    .check-cell { text-align: left; }
}
</style>
