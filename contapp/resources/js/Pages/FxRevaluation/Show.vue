<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import DocumentToolbar from '../../Components/DocumentToolbar.vue';
import { formatMoney } from '../../Utils/money';

const props = defineProps({
    run: { type: Object, required: true },
});
</script>

<template>
    <Head :title="`Diferencial cambiario — ${run.cutoff_date}`" />

    <AppLayout :title="`Diferencial cambiario — ${run.cutoff_date}`">
        <template #actions>
            <Link :href="route('fx-revaluation.index')" class="btn btn-ghost">← Historial</Link>
            <Link v-if="run.journal_entry_id" :href="route('journal-entries.show', run.journal_entry_id)" class="btn btn-ghost">Ver asiento</Link>
        </template>

        <DocumentToolbar :new-href="route('fx-revaluation.create')" />

        <div class="summary-grid">
            <div class="card summary-card">
                <dl>
                    <dt>Tipo de documento</dt><dd>{{ run.document_type?.code }} — {{ run.document_type?.name }}</dd>
                    <dt>Tipo de cambio de cierre</dt><dd class="num">{{ run.exchange_rate_used }}</dd>
                    <dt>Cuenta de ganancia</dt><dd>{{ run.gain_account?.code }} — {{ run.gain_account?.description_es }}</dd>
                    <dt>Cuenta de pérdida</dt><dd>{{ run.loss_account?.code }} — {{ run.loss_account?.description_es }}</dd>
                    <dt>Ejecutado por</dt><dd>{{ run.executed_by }}<template v-if="run.executed_at"> · {{ run.executed_at }}</template></dd>
                </dl>
            </div>
        </div>

        <div class="card">
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
                        <td>
                            <span v-if="d.business_partner">{{ d.business_partner.code }} — {{ d.business_partner.name }}</span>
                            <span v-else class="muted">—</span>
                        </td>
                        <td class="num">{{ formatMoney(d.foreign_balance) }}</td>
                        <td class="num">{{ formatMoney(d.historical_local_amount) }}</td>
                        <td class="num">{{ formatMoney(d.revalued_local_amount) }}</td>
                        <td class="num" :class="parseFloat(d.difference) >= 0 ? 'gain' : 'loss'">{{ formatMoney(d.difference) }}</td>
                    </tr>
                    <tr v-if="!run.details.length">
                        <td colspan="6" class="muted empty-row">Este proceso no generó ningún ajuste.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>

<style scoped>
.summary-grid { margin-bottom: 0.75rem; }
.summary-card { padding: 1rem 1.1rem; }
dl { margin: 0; display: grid; grid-template-columns: auto 1fr; column-gap: 1rem; row-gap: 0.35rem; font-size: 0.85rem; }
dt { color: var(--color-text-muted); font-weight: 500; }
dd { margin: 0; }

table { font-size: 0.85rem; width: 100%; }
th, td { text-align: left; padding: 0.5rem 0.9rem; border-top: 1px solid var(--color-border); }
.num { font-variant-numeric: tabular-nums; text-align: right; }
thead th.num { text-align: right; }
.muted { color: var(--color-text-muted); }
.empty-row { text-align: center; padding: 1.5rem; }
.gain { color: var(--color-success); }
.loss { color: var(--color-danger); }
</style>
