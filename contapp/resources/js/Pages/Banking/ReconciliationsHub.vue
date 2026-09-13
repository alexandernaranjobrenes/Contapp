<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import DocumentToolbar from '../../Components/DocumentToolbar.vue';

defineProps({
    bankAccounts: { type: Array, default: () => [] },
});
</script>

<template>
    <Head title="Conciliaciones bancarias" />

    <AppLayout title="Conciliaciones bancarias">
        <template #actions>
            <Link :href="route('bank-accounts.index')" class="btn btn-ghost">Cuentas bancarias</Link>
        </template>

        <DocumentToolbar :new-href="route('bank-accounts.create')" />

        <div class="card">
            <table>
                <thead>
                    <tr>
                        <th>Banco</th>
                        <th>Número</th>
                        <th>Cuenta contable</th>
                        <th>Última conciliación</th>
                        <th>Estado</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="account in bankAccounts" :key="account.id">
                        <td>{{ account.bank_name }}</td>
                        <td class="num code-cell">{{ account.account_number }}</td>
                        <td>{{ account.gl_account?.code }} — {{ account.gl_account?.description_es }}</td>
                        <td class="num">{{ account.last_reconciliation?.cutoff_date ?? '—' }}</td>
                        <td>
                            <span
                                v-if="account.last_reconciliation"
                                class="badge"
                                :class="account.last_reconciliation.status === 'completed' ? 'badge-success' : 'badge-warning'"
                            >
                                {{ account.last_reconciliation.status === 'completed' ? 'Cerrada' : 'En proceso' }}
                            </span>
                            <span v-else class="muted small">Nunca conciliada</span>
                        </td>
                        <td class="actions-cell">
                            <Link :href="route('bank-reconciliations.create', account.id)" class="btn btn-ghost">Conciliar</Link>
                            <Link :href="route('bank-reconciliations.index', account.id)" class="btn btn-ghost">Historial</Link>
                        </td>
                    </tr>
                    <tr v-if="!bankAccounts.length">
                        <td colspan="6" class="muted empty-row">Todavía no hay cuentas bancarias registradas.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>

<style scoped>
table { font-size: 0.85rem; width: 100%; }
th, td { text-align: left; padding: 0.5rem 1rem; border-top: 1px solid var(--color-border); }
.code-cell { font-variant-numeric: tabular-nums; }
.num { font-variant-numeric: tabular-nums; }
.muted { color: var(--color-text-muted); }
.small { font-size: 0.78rem; }
.empty-row { text-align: center; padding: 1.5rem; }
.actions-cell { display: flex; gap: 0.4rem; }
</style>
