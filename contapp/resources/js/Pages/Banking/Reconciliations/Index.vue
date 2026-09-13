<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import DocumentToolbar from '../../../Components/DocumentToolbar.vue';
import { formatMoney } from '../../../Utils/money';

const props = defineProps({
    bankAccount: { type: Object, required: true },
    reconciliations: { type: Array, default: () => [] },
});

function reopen(r) {
    if (! confirm(`¿Reabrir la conciliación del corte ${r.cutoff_date}? Volverá a estado "En proceso".`)) return;
    router.post(route('bank-reconciliations.reopen', r.id));
}

function destroyReconciliation(r) {
    if (! confirm(`¿Eliminar la conciliación del corte ${r.cutoff_date}? Esta acción no se puede deshacer. El asiento contable no se ve afectado, solo se pierde el enlace de conciliación.`)) return;
    router.delete(route('bank-reconciliations.destroy', r.id));
}
</script>

<template>
    <Head :title="`Conciliaciones — ${bankAccount.bank_name}`" />

    <AppLayout :title="`Conciliaciones — ${bankAccount.bank_name}`">
        <template #actions>
            <Link :href="route('bank-reconciliations.create', bankAccount.id)" class="btn btn-primary">
                + Nueva conciliación
            </Link>
        </template>

        <DocumentToolbar :new-href="route('bank-reconciliations.create', bankAccount.id)" />

        <div class="card">
            <table>
                <thead>
                    <tr>
                        <th>Corte</th>
                        <th class="num">Saldo banco</th>
                        <th class="num">Saldo libros</th>
                        <th>Estado</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="r in reconciliations" :key="r.id">
                        <td>{{ r.cutoff_date }}</td>
                        <td class="num">{{ formatMoney(r.bank_balance) }}</td>
                        <td class="num">{{ formatMoney(r.book_balance) }}</td>
                        <td>
                            <span class="badge" :class="r.status === 'completed' ? 'badge-success' : 'badge-warning'">
                                {{ r.status === 'completed' ? 'Cerrada' : 'En proceso' }}
                            </span>
                        </td>
                        <td class="row-actions">
                            <Link :href="route('bank-reconciliations.show', r.id)" class="btn btn-ghost">
                                {{ r.status === 'completed' ? 'Ver' : 'Continuar' }}
                            </Link>
                            <button v-if="r.status === 'completed'" type="button" class="btn btn-ghost" @click="reopen(r)">
                                Reabrir
                            </button>
                            <button type="button" class="btn btn-ghost btn-danger" @click="destroyReconciliation(r)">
                                Eliminar
                            </button>
                        </td>
                    </tr>
                    <tr v-if="!reconciliations.length">
                        <td colspan="5" class="muted empty-row">Todavía no hay conciliaciones para esta cuenta.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>

<style scoped>
table { font-size: 0.85rem; }
th, td { text-align: left; padding: 0.5rem 1rem; border-top: 1px solid var(--color-border); }
.muted { color: var(--color-text-muted); }
.empty-row { text-align: center; padding: 1.5rem; }
.row-actions { display: flex; gap: 0.4rem; }
.btn-danger { color: var(--color-danger); }
</style>
