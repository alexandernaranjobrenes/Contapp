<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import { formatMoney } from '../../Utils/money';
import { ArrowLeftIcon } from '@lucide/vue';

const props = defineProps({
    run: { type: Object, required: true },
});
</script>

<template>
    <Head :title="`Diferencial cambiario — ${run.cutoff_date}`" />

    <AppLayout :title="`Diferencial cambiario — ${run.cutoff_date}`">
        <div class="view-toolbar">
            <Link :href="route('fx-revaluation.index')" class="btn btn-ghost"><ArrowLeftIcon /> Historial</Link>
            <div class="view-actions">
                <Link v-if="run.journal_entry_id" :href="route('journal-entries.show', run.journal_entry_id)" class="btn btn-ghost">Ver asiento</Link>
            </div>
        </div>

        <div class="card summary-card">
            <dl>
                <dt>Tipo de documento</dt><dd>{{ run.document_type?.code }} — {{ run.document_type?.name }}</dd>
                <dt>Tipo de cambio de cierre</dt><dd class="num-value">{{ run.exchange_rate_used }}</dd>
                <dt>Cuenta de ganancia</dt><dd>{{ run.gain_account?.code }} — {{ run.gain_account?.description_es }}</dd>
                <dt>Cuenta de pérdida</dt><dd>{{ run.loss_account?.code }} — {{ run.loss_account?.description_es }}</dd>
                <dt>Ejecutado por</dt><dd>{{ run.executed_by }}<template v-if="run.executed_at"> · {{ run.executed_at }}</template></dd>
            </dl>
        </div>

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
                        <tr v-for="(d, i) in run.details" :key="i">
                            <td>{{ d.account?.code }} — {{ d.account?.description_es }}</td>
                            <td data-label="Socio de negocio">
                                <span v-if="d.business_partner">{{ d.business_partner.code }} — {{ d.business_partner.name }}</span>
                                <span v-else class="muted">—</span>
                            </td>
                            <td data-label="Saldo (ME)" class="num">{{ formatMoney(d.foreign_balance) }}</td>
                            <td data-label="LC histórico" class="num">{{ formatMoney(d.historical_local_amount) }}</td>
                            <td data-label="LC revaluado" class="num">{{ formatMoney(d.revalued_local_amount) }}</td>
                            <td data-label="Diferencia" class="num" :class="parseFloat(d.difference) >= 0 ? 'gain' : 'loss'">{{ formatMoney(d.difference) }}</td>
                        </tr>
                        <tr v-if="!run.details.length">
                            <td colspan="6" class="muted empty-row">Este proceso no generó ningún ajuste.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
.summary-card { padding: 1rem 1.1rem; margin-bottom: 0.9rem; }
.summary-card dl { margin: 0; display: grid; grid-template-columns: auto minmax(0, 1fr); column-gap: 1rem; row-gap: 0.35rem; font-size: 0.85rem; }
.summary-card dt { color: var(--color-text-muted); font-weight: 500; }
.summary-card dd { margin: 0; overflow-wrap: anywhere; }
.num-value { font-variant-numeric: tabular-nums; }

/* Seis columnas en los ~720px que deja la barra lateral a 1025px. */
table { font-size: 0.82rem; }
th, td { padding: 0.5rem 0.7rem; }
.gain { color: var(--color-success); }
.loss { color: var(--color-danger); }

@media (max-width: 640px) {
    .summary-card dl { grid-template-columns: minmax(0, 1fr); row-gap: 0.1rem; }
    .summary-card dd { margin-bottom: 0.5rem; }
}
</style>
