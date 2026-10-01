<script setup>
import { computed, ref } from 'vue';
import DetailModal from './DetailModal.vue';
import { formatMoney } from '../Utils/money';

// Un grupo por moneda de la antigüedad de saldos y de la proyección de cobros
// y pagos: los totales por tramo arriba, en una rejilla que crece hacia abajo
// y no de lado —la cantidad de tramos la escoge el usuario—, y una tabla por
// socio con lo que decide la gestión de cobro: cuánto debe y qué tan viejo es
// lo más viejo. El reparto por tramo y los documentos están en la ficha del
// socio (CLAUDE.md secc. 20).
const props = defineProps({
    group: { type: Object, required: true },
    bucketLabels: { type: Object, required: true },
});

const bucketKeys = computed(() => Object.keys(props.bucketLabels));

function oldestBucket(row) {
    const key = [...bucketKeys.value].reverse().find((k) => Number(row.buckets[k] ?? 0) !== 0);
    return key ? props.bucketLabels[key] : '—';
}

const selectedCode = ref(null);
const selected = computed(() => props.group.rows.find((r) => r.partner_code === selectedCode.value) ?? null);
</script>

<template>
    <div class="card group">
        <h3>Moneda: {{ group.currency_code }}</h3>

        <div class="bucket-grid">
            <div v-for="key in bucketKeys" :key="key" class="bucket-tile">
                <span class="bucket-label">{{ bucketLabels[key] }}</span>
                <strong class="bucket-value">{{ formatMoney(group.bucket_totals[key]) }}</strong>
            </div>
            <div class="bucket-tile bucket-total">
                <span class="bucket-label">Total</span>
                <strong class="bucket-value">{{ formatMoney(group.total) }}</strong>
            </div>
        </div>

        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>Socio</th>
                        <th class="num">Documentos</th>
                        <th>Tramo más antiguo</th>
                        <th class="num">Total</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in group.rows"
                        :key="row.partner_code"
                        class="clickable-row"
                        tabindex="0"
                        @click="selectedCode = row.partner_code"
                        @keydown.enter="selectedCode = row.partner_code"
                        @keydown.space.prevent="selectedCode = row.partner_code"
                    >
                        <td><strong class="code">{{ row.partner_code }}</strong> — {{ row.partner_name }}</td>
                        <td data-label="Documentos" class="num">{{ row.documents.length }}</td>
                        <td data-label="Tramo más antiguo">{{ oldestBucket(row) }}</td>
                        <td data-label="Total" class="num">{{ formatMoney(row.total) }}</td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="3" class="total-label">Totales</td>
                        <td data-label="Total" class="num total-value">{{ formatMoney(group.total) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <DetailModal
        :open="!!selected"
        wide
        :title="selected ? `${selected.partner_code} — ${selected.partner_name}` : ''"
        @close="selectedCode = null"
    >
        <template v-if="selected">
            <dl class="detail-list">
                <div v-for="key in bucketKeys" :key="key">
                    <dt>{{ bucketLabels[key] }}</dt>
                    <dd>{{ formatMoney(selected.buckets[key]) }}</dd>
                </div>
                <div>
                    <dt>Total ({{ group.currency_code }})</dt>
                    <dd><strong>{{ formatMoney(selected.total) }}</strong></dd>
                </div>
            </dl>

            <h4 class="block-title">Documentos</h4>
            <div class="table-responsive">
                <table class="documents-table">
                    <thead>
                        <tr>
                            <th>Documento</th>
                            <th>Vencimiento</th>
                            <th class="num">Monto original</th>
                            <th class="num">Saldo pendiente</th>
                            <th>Tramo</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(doc, i) in selected.documents" :key="i">
                            <td>{{ doc.document_label }}</td>
                            <td data-label="Vencimiento">{{ doc.due_date ?? '—' }}</td>
                            <td data-label="Monto original" class="num">{{ formatMoney(doc.original_amount) }}</td>
                            <td data-label="Saldo pendiente" class="num">{{ formatMoney(doc.balance) }}</td>
                            <td data-label="Tramo" class="muted">{{ bucketLabels[doc.bucket] }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>
    </DetailModal>
</template>

<style scoped>
.group { margin-bottom: 1.25rem; }
h3 { margin: 0 0 0.75rem; font-size: 0.95rem; }
table { font-size: 0.84rem; }
.code { font-variant-numeric: tabular-nums; }
.total-label { text-align: right; font-weight: 700; }
.total-value { font-weight: 800; color: var(--color-primary); }

.bucket-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(min(100%, 9rem), 1fr));
    gap: 0.5rem;
    margin-bottom: 0.9rem;
}
.bucket-tile {
    display: flex; flex-direction: column; gap: 0.15rem;
    padding: 0.55rem 0.75rem;
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    background: var(--color-surface-alt);
}
.bucket-label { font-size: 0.74rem; color: var(--color-text-muted); }
.bucket-value { font-size: 0.9rem; font-variant-numeric: tabular-nums; }
.bucket-total { border-color: var(--color-primary); }

.documents-table { font-size: 0.8rem; }

@media screen and (max-width: 1024px) {
    .total-label { text-align: left; }
}
</style>
