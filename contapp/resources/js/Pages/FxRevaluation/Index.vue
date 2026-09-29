<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { EyeIcon, PlayIcon } from '@lucide/vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import DetailModal from '../../Components/DetailModal.vue';
import { useRecordDetail } from '../../Utils/recordDetail';

const props = defineProps({
    runs: { type: Array, default: () => [] },
});

// Ficha de la corrida (CLAUDE.md secc. 20): lo esencial y los enlaces a su
// detalle y a su asiento.
const { selected, openDetail, closeDetail } = useRecordDetail(() => props.runs);
</script>

<template>
    <Head title="Diferencial cambiario — Historial" />

    <AppLayout title="Diferencial cambiario — Historial">
        <!-- «Ejecutar proceso» y no «Crear nuevo»: no abre un formulario de
             alta, corre el proceso de revaluación (CLAUDE.md secc. 21). -->
        <div class="view-toolbar">
            <div class="view-actions">
                <Link :href="route('fx-revaluation.create')" class="btn btn-primary"><PlayIcon /> Ejecutar proceso</Link>
            </div>
        </div>

        <div class="card">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Corte</th>
                            <th>Documento</th>
                            <th class="num">Tipo de cambio</th>
                            <th>Ejecutado por</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="run in runs"
                            :key="run.id"
                            class="clickable-row"
                            tabindex="0"
                            @click="openDetail(run)"
                            @keydown.enter="openDetail(run)"
                            @keydown.space.prevent="openDetail(run)"
                        >
                            <td class="date-cell">{{ run.cutoff_date }}</td>
                            <td data-label="Documento">{{ run.document_type_code }}</td>
                            <td data-label="Tipo de cambio" class="num">{{ run.exchange_rate_used }}</td>
                            <td data-label="Ejecutado por" class="muted">{{ run.executed_by }}</td>
                        </tr>
                        <tr v-if="!runs.length">
                            <td colspan="4" class="muted empty-row">Todavía no se ha ejecutado ningún proceso de diferencial cambiario.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <DetailModal :open="!!selected" :title="selected ? `Corte al ${selected.cutoff_date}` : ''" @close="closeDetail">
            <dl v-if="selected" class="detail-list">
                <div>
                    <dt>Documento</dt>
                    <dd>{{ selected.document_type_code }}</dd>
                </div>
                <div>
                    <dt>Tipo de cambio usado</dt>
                    <dd>{{ selected.exchange_rate_used }}</dd>
                </div>
                <div>
                    <dt>Cuenta de ganancia</dt>
                    <dd>{{ selected.gain_account?.code ?? '—' }}</dd>
                </div>
                <div>
                    <dt>Cuenta de pérdida</dt>
                    <dd>{{ selected.loss_account?.code ?? '—' }}</dd>
                </div>
                <div class="full">
                    <dt>Ejecutado por</dt>
                    <dd>{{ selected.executed_by }}<template v-if="selected.executed_at"> · {{ selected.executed_at }}</template></dd>
                </div>
            </dl>

            <template #actions>
                <template v-if="selected">
                    <Link v-if="selected.journal_entry_id" :href="route('journal-entries.show', selected.journal_entry_id)" class="btn btn-ghost">Ver asiento</Link>
                    <Link :href="route('fx-revaluation.show', selected.id)" class="btn btn-primary"><EyeIcon /> Ver detalle</Link>
                </template>
            </template>
        </DetailModal>
    </AppLayout>
</template>

<style scoped>
table { font-size: 0.85rem; }
.date-cell { font-variant-numeric: tabular-nums; white-space: nowrap; }
</style>
