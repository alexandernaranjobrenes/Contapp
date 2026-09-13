<script setup>
import { Head, useForm, router } from '@inertiajs/vue3';
import { computed } from 'vue';

import AppLayout from '../../Layouts/AppLayout.vue';
import DocumentToolbar from '../../Components/DocumentToolbar.vue';

const props = defineProps({
    rates: { type: Array, default: () => [] },
    foreignCurrency: { type: Object, default: null },
    bccrConfigured: { type: Boolean, default: false },
    today: { type: String, required: true },
});

const typeLabels = { buy: 'Compra', sell: 'Venta', reference: 'Referencia' };

const syncForm = useForm({ date: props.today });

function sync() {
    syncForm.post(route('exchange-rates.sync'), { preserveScroll: true });
}

const manualForm = useForm({
    rate_date: props.today,
    rate_type: 'reference',
    rate: '',
    is_locked: false,
});

function saveManual() {
    manualForm.post(route('exchange-rates.store'), {
        preserveScroll: true,
        onSuccess: () => manualForm.reset('rate'),
    });
}

function destroy(rateRow) {
    if (! confirm(`¿Eliminar el tipo de cambio del ${rateRow.rate_date}?`)) return;

    router.delete(route('exchange-rates.destroy', rateRow.id), { preserveScroll: true });
}

const currencyLabel = computed(() => props.foreignCurrency ? `${props.foreignCurrency.code}` : 'moneda extranjera');
</script>

<template>
    <Head title="Tipos de cambio" />

    <AppLayout title="Tipos de cambio">
        <template #actions>
            <form class="sync-bar" @submit.prevent="sync">
                <input v-model="syncForm.date" type="date" class="date-input">
                <button type="submit" class="btn btn-primary" :disabled="syncForm.processing">
                    {{ syncForm.processing ? 'Consultando...' : 'Sincronizar con BCCR' }}
                </button>
            </form>
        </template>

        <DocumentToolbar can-save :saving="manualForm.processing" @save="saveManual" />

        <div v-if="!bccrConfigured" class="notice card">
            El BCCR todavía no está configurado en este entorno (faltan <code>BCCR_EMAIL</code> / <code>BCCR_TOKEN</code> en <code>.env</code>).
            El botón de sincronizar no va a encontrar datos hasta que se configure; mientras tanto, cargá el tipo de cambio a mano abajo.
        </div>
        <div v-if="syncForm.errors.sync" class="flash flash-error">{{ syncForm.errors.sync }}</div>

        <div class="layout">
            <div class="card">
                <div class="card-header">
                    <strong>Historial — {{ currencyLabel }}</strong>
                    <span class="muted small">Últimos {{ rates.length }} registro(s)</span>
                </div>

                <table>
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Tipo</th>
                            <th>Tasa</th>
                            <th>Origen</th>
                            <th>Bloqueada</th>
                            <th>Cargado por</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in rates" :key="r.id">
                            <td class="num">{{ r.rate_date }}</td>
                            <td>{{ typeLabels[r.rate_type] ?? r.rate_type }}</td>
                            <td class="num">{{ r.rate }}</td>
                            <td>
                                <span class="badge" :class="r.source === 'bccr_api' ? 'badge-success' : 'badge-neutral'">
                                    {{ r.source === 'bccr_api' ? 'BCCR' : 'Manual' }}
                                </span>
                            </td>
                            <td>
                                <span v-if="r.is_locked" class="badge badge-warning">Bloqueada</span>
                            </td>
                            <td class="muted">{{ r.created_by?.name ?? '—' }}</td>
                            <td>
                                <button type="button" class="btn btn-ghost" @click="destroy(r)">Eliminar</button>
                            </td>
                        </tr>
                        <tr v-if="!rates.length">
                            <td colspan="7" class="muted empty-row">Todavía no hay tipos de cambio registrados para {{ currencyLabel }}.</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <form class="card manual-card" @submit.prevent="saveManual">
                <strong>Cargar / corregir a mano</strong>
                <span class="muted small">Si la fecha y el tipo ya existen, se actualiza esa fila en vez de duplicarla.</span>

                <div class="field">
                    <label>Fecha</label>
                    <input v-model="manualForm.rate_date" type="date" required>
                    <span v-if="manualForm.errors.rate_date" class="error">{{ manualForm.errors.rate_date }}</span>
                </div>

                <div class="field">
                    <label>Tipo</label>
                    <select v-model="manualForm.rate_type">
                        <option value="reference">Referencia</option>
                        <option value="buy">Compra</option>
                        <option value="sell">Venta</option>
                    </select>
                </div>

                <div class="field">
                    <label>Tasa (₡ por 1 {{ currencyLabel }})</label>
                    <input v-model="manualForm.rate" type="number" step="0.000001" min="0" required>
                    <span v-if="manualForm.errors.rate" class="error">{{ manualForm.errors.rate }}</span>
                </div>

                <label class="check-row">
                    <input v-model="manualForm.is_locked" type="checkbox">
                    Bloquear (el sync automático del BCCR no la va a sobrescribir)
                </label>

                <button type="submit" class="btn btn-primary" :disabled="manualForm.processing">Guardar</button>
            </form>
        </div>
    </AppLayout>
</template>

<style scoped>
.sync-bar {
    display: flex;
    gap: 0.5rem;
}

.date-input {
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    padding: 0.4rem 0.6rem;
    font-size: 0.82rem;
    color: var(--color-text);
}

.notice {
    padding: 0.75rem 1rem;
    margin-bottom: 0.75rem;
    font-size: 0.82rem;
    color: var(--color-text-muted);
}

.notice code {
    background: var(--color-surface-alt);
    padding: 0.05rem 0.3rem;
    border-radius: 4px;
}

.flash {
    margin-bottom: 0.75rem;
    padding: 0.6rem 0.9rem;
    border-radius: var(--radius-sm);
    font-size: 0.85rem;
}

.flash-error {
    background: var(--color-danger-soft);
    color: var(--color-danger);
}

.layout {
    display: grid;
    grid-template-columns: 1fr 280px;
    gap: 1rem;
    align-items: start;
}

.card-header {
    display: flex;
    flex-direction: column;
    gap: 0.1rem;
    padding: 0.85rem 1.1rem;
    border-bottom: 1px solid var(--color-border);
}

table { font-size: 0.82rem; width: 100%; }
th, td { text-align: left; padding: 0.5rem 0.9rem; border-top: 1px solid var(--color-border); white-space: nowrap; }
.num { font-variant-numeric: tabular-nums; }
.muted { color: var(--color-text-muted); }
.small { font-size: 0.72rem; }
.empty-row { text-align: center; padding: 1.25rem; white-space: normal; }

.manual-card {
    padding: 1rem;
    display: flex;
    flex-direction: column;
    gap: 0.6rem;
}

.field {
    display: flex;
    flex-direction: column;
    gap: 0.2rem;
}

.field label {
    font-size: 0.78rem;
    color: var(--color-text-muted);
}

.field input, .field select {
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    padding: 0.45rem 0.6rem;
    font-size: 0.85rem;
    color: var(--color-text);
}

.check-row {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.8rem;
    color: var(--color-text-muted);
}

.error {
    color: var(--color-danger);
    font-size: 0.76rem;
}
</style>
