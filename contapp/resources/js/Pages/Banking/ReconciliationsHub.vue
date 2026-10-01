<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import DetailModal from '../../Components/DetailModal.vue';
import { useRecordDetail } from '../../Utils/recordDetail';
import { HistoryIcon, ListChecksIcon } from '@lucide/vue';

const props = defineProps({
    bankAccounts: { type: Array, default: () => [] },
});

// Ficha de la cuenta (CLAUDE.md secc. 20): conciliar o ver el historial se
// hace desde acá, no desde botones en la fila.
const { selected, openDetail, closeDetail } = useRecordDetail(() => props.bankAccounts);

function statusBadge(account) {
    const last = account.last_reconciliation;
    if (! last) return null;

    return last.status === 'completed'
        ? { label: 'Cerrada', class: 'badge-success' }
        : { label: 'En proceso', class: 'badge-warning' };
}
</script>

<template>
    <Head title="Conciliaciones bancarias" />

    <AppLayout title="Conciliaciones bancarias">
        <div class="view-toolbar">
            <div class="view-actions">
                <Link :href="route('bank-accounts.index')" class="btn btn-ghost">Cuentas bancarias</Link>
            </div>
        </div>

        <div class="card">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Banco</th>
                            <th>Número</th>
                            <th>Última conciliación</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="account in bankAccounts"
                            :key="account.id"
                            class="clickable-row"
                            tabindex="0"
                            @click="openDetail(account)"
                            @keydown.enter="openDetail(account)"
                            @keydown.space.prevent="openDetail(account)"
                        >
                            <td>{{ account.bank_name }}</td>
                            <td data-label="Número" class="code-cell">{{ account.account_number }}</td>
                            <td data-label="Última conciliación">{{ account.last_reconciliation?.cutoff_date ?? '—' }}</td>
                            <td data-label="Estado">
                                <span v-if="statusBadge(account)" class="badge" :class="statusBadge(account).class">{{ statusBadge(account).label }}</span>
                                <span v-else class="muted small">Nunca conciliada</span>
                            </td>
                        </tr>
                        <tr v-if="!bankAccounts.length">
                            <td colspan="4" class="muted empty-row">Todavía no hay cuentas bancarias registradas.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <DetailModal :open="!!selected" :title="selected ? `${selected.bank_name} — ${selected.account_number}` : ''" @close="closeDetail">
            <template #badge>
                <span v-if="selected && statusBadge(selected)" class="badge" :class="statusBadge(selected).class">{{ statusBadge(selected).label }}</span>
            </template>

            <dl v-if="selected" class="detail-list">
                <div>
                    <dt>Cuenta contable</dt>
                    <dd>{{ selected.gl_account?.code }} — {{ selected.gl_account?.description_es }}</dd>
                </div>
                <div>
                    <dt>Última conciliación</dt>
                    <dd>{{ selected.last_reconciliation?.cutoff_date ?? 'Nunca conciliada' }}</dd>
                </div>
            </dl>

            <template #actions>
                <template v-if="selected">
                    <Link :href="route('bank-reconciliations.index', selected.id)" class="btn btn-ghost"><HistoryIcon /> Historial</Link>
                    <Link :href="route('bank-reconciliations.create', selected.id)" class="btn btn-primary"><ListChecksIcon /> Conciliar</Link>
                </template>
            </template>
        </DetailModal>
    </AppLayout>
</template>

<style scoped>
table { font-size: 0.85rem; }
.code-cell { font-variant-numeric: tabular-nums; }
</style>
