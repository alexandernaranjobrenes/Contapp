<script setup>
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import DocumentToolbar from '../../../Components/DocumentToolbar.vue';
import { formatMoney } from '../../../Utils/money';

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
    if (! confirm('¿Reabrir esta conciliación? Vas a poder corregir los checks y volver a cerrarla.')) return;

    router.post(route('bank-reconciliations.reopen', props.reconciliation.id), {}, {
        preserveScroll: true,
    });
}

function destroyReconciliation() {
    if (! confirm('¿Eliminar esta conciliación? Esta acción no se puede deshacer. El asiento contable no se ve afectado, solo se pierde el enlace de conciliación.')) return;

    router.delete(route('bank-reconciliations.destroy', props.reconciliation.id));
}
</script>

<template>
    <Head :title="`Conciliación ${reconciliation.cutoff_date}`" />

    <AppLayout :title="`Conciliación — ${reconciliation.bank_account?.bank_name} al ${reconciliation.cutoff_date}`">
        <template #actions>
            <button
                v-if="reconciliation.status !== 'completed'"
                type="button"
                class="btn btn-primary"
                @click="closeReconciliation"
            >Cerrar conciliación</button>
            <template v-else>
                <span class="badge badge-success">Cerrada</span>
                <button type="button" class="btn btn-ghost" @click="reopenReconciliation">Reabrir</button>
            </template>
            <button type="button" class="btn btn-ghost btn-danger" @click="destroyReconciliation">Eliminar</button>
        </template>

        <DocumentToolbar v-if="reconciliation.bank_account" :new-href="route('bank-reconciliations.create', reconciliation.bank_account.id)" />

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

        <div class="card">
            <table>
                <thead>
                    <tr>
                        <th>Cuenta</th>
                        <th>Descripción</th>
                        <th class="num">Débito</th>
                        <th class="num">Crédito</th>
                        <th>Cta</th>
                        <th>Bco</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="line in reconciliation.lines" :key="line.id">
                        <td>{{ line.journal_detail?.account?.code }}</td>
                        <td>{{ line.journal_detail?.description }}</td>
                        <td class="num">{{ formatMoney(line.journal_detail?.debit_local) }}</td>
                        <td class="num">{{ formatMoney(line.journal_detail?.credit_local) }}</td>
                        <td class="check-cell">
                            <span>{{ line.matched_in_books ? '✓' : '' }}</span>
                        </td>
                        <td class="check-cell">
                            <button
                                type="button"
                                class="check-btn"
                                :class="{ checked: line.matched_in_bank }"
                                :disabled="reconciliation.status === 'completed'"
                                :title="line.matched_in_bank ? 'Visto en banco — clic para desmarcar' : 'Marcar como visto en el estado de cuenta del banco'"
                                @click="toggleBank(line)"
                            >{{ line.matched_in_bank ? '✓' : '' }}</button>
                        </td>
                    </tr>
                    <tr v-if="!reconciliation.lines.length">
                        <td colspan="6" class="muted empty-row">Sin movimientos hasta la fecha de corte.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>

<style scoped>
.summary-grid {
    display: grid;
    grid-template-columns: 1fr 1fr auto;
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
th, td { text-align: left; padding: 0.45rem 0.9rem; border-top: 1px solid var(--color-border); }
.muted { color: var(--color-text-muted); }
.empty-row { text-align: center; padding: 1.5rem; }

.check-cell { text-align: center; }
.check-btn {
    width: 22px;
    height: 22px;
    border-radius: 4px;
    border: 1px solid var(--color-border);
    background: var(--color-surface);
    cursor: pointer;
    font-weight: 700;
    color: var(--color-success);
}
.check-btn.checked { background: var(--color-success-soft); }
.check-btn:disabled { cursor: not-allowed; opacity: .6; }

.flash { margin-bottom: 1rem; padding: 0.6rem 0.9rem; border-radius: var(--radius-sm); font-size: 0.85rem; }
.flash-error { background: var(--color-danger-soft); color: var(--color-danger); }
.btn-danger { color: var(--color-danger); }
</style>
