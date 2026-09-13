<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import DocumentToolbar from '../../Components/DocumentToolbar.vue';

defineProps({
    runs: { type: Array, default: () => [] },
});
</script>

<template>
    <Head title="Diferencial cambiario — Historial" />

    <AppLayout title="Diferencial cambiario — Historial">
        <template #actions>
            <Link :href="route('fx-revaluation.create')" class="btn btn-primary">+ Ejecutar proceso</Link>
        </template>

        <DocumentToolbar :new-href="route('fx-revaluation.create')" />

        <div class="card">
            <table>
                <thead>
                    <tr>
                        <th>Corte</th>
                        <th>Documento</th>
                        <th class="num">Tipo de cambio</th>
                        <th>Cta. ganancia</th>
                        <th>Cta. pérdida</th>
                        <th>Ejecutado por</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="run in runs" :key="run.id">
                        <td class="num">{{ run.cutoff_date }}</td>
                        <td>{{ run.document_type_code }}</td>
                        <td class="num">{{ run.exchange_rate_used }}</td>
                        <td>{{ run.gain_account?.code }}</td>
                        <td>{{ run.loss_account?.code }}</td>
                        <td class="muted small">{{ run.executed_by }}<template v-if="run.executed_at"> · {{ run.executed_at }}</template></td>
                        <td class="actions-cell">
                            <Link :href="route('fx-revaluation.show', run.id)" class="btn btn-ghost">Ver</Link>
                            <Link v-if="run.journal_entry_id" :href="route('journal-entries.show', run.journal_entry_id)" class="btn btn-ghost">Asiento</Link>
                        </td>
                    </tr>
                    <tr v-if="!runs.length">
                        <td colspan="7" class="muted empty-row">Todavía no se ha ejecutado ningún proceso de diferencial cambiario.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>

<style scoped>
table { font-size: 0.85rem; width: 100%; }
th, td { text-align: left; padding: 0.5rem 0.9rem; border-top: 1px solid var(--color-border); }
.num { font-variant-numeric: tabular-nums; }
.muted { color: var(--color-text-muted); }
.small { font-size: 0.78rem; }
.empty-row { text-align: center; padding: 1.5rem; }
.actions-cell { display: flex; gap: 0.4rem; }
</style>
