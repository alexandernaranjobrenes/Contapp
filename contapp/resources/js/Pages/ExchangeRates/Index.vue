<script setup>
import { Head, useForm, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { PencilIcon, PlusIcon, RefreshCwIcon } from '@lucide/vue';

import AppLayout from '../../Layouts/AppLayout.vue';
import DetailModal from '../../Components/DetailModal.vue';
import { confirmAction } from '../../Utils/confirm';

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

// Ficha de una tasa (CLAUDE.md secc. 20) y carga manual (secc. 21) en un
// solo modal. Cargar a mano una fecha y un tipo que ya existen actualiza esa
// fila: por eso «Corregir» abre el mismo formulario con la tasa elegida.
const mode = ref('details'); // 'create' | 'details'
const selectedId = ref(null);

const selected = computed(() => props.rates.find((r) => r.id === selectedId.value) ?? null);
const modalOpen = computed(() => mode.value === 'create' || !!selected.value);
const modalTitle = computed(() => {
    if (mode.value === 'create') return 'Cargar tipo de cambio';
    return selected.value ? `${selected.value.rate_date} — ${typeLabels[selected.value.rate_type] ?? selected.value.rate_type}` : '';
});

const manualForm = useForm({
    rate_date: props.today,
    rate_type: 'reference',
    rate: '',
    is_locked: false,
});

function openCreate(values = {}) {
    manualForm.clearErrors();
    Object.assign(manualForm, { rate_date: props.today, rate_type: 'reference', rate: '', is_locked: false, ...values });
    selectedId.value = null;
    mode.value = 'create';
}

function openRate(rate) {
    selectedId.value = rate.id;
    mode.value = 'details';
}

function closeModal() {
    selectedId.value = null;
    mode.value = 'details';
}

function startCorrection() {
    const r = selected.value;
    openCreate({ rate_date: r.rate_date, rate_type: r.rate_type, rate: r.rate, is_locked: r.is_locked });
}

function saveManual() {
    manualForm.post(route('exchange-rates.store'), {
        preserveScroll: true,
        onSuccess: closeModal,
    });
}

function destroy() {
    const r = selected.value;

    confirmAction({
        title: 'Eliminar tipo de cambio',
        message: `Se elimina la tasa de ${typeLabels[r.rate_type] ?? r.rate_type} del ${r.rate_date}.`,
        confirmLabel: 'Eliminar',
        danger: true,
        onConfirm: () => router.delete(route('exchange-rates.destroy', r.id), { preserveScroll: true }),
    });
}

const currencyLabel = computed(() => props.foreignCurrency ? `${props.foreignCurrency.code}` : 'moneda extranjera');
</script>

<template>
    <Head title="Tipos de cambio" />

    <AppLayout title="Tipos de cambio">
        <div class="view-toolbar">
            <form class="view-filters" @submit.prevent="sync">
                <label class="filter-field">
                    <span>Fecha a sincronizar</span>
                    <input v-model="syncForm.date" type="date" required>
                </label>
                <button type="submit" class="btn btn-ghost" :disabled="syncForm.processing">
                    <RefreshCwIcon /> {{ syncForm.processing ? 'Consultando...' : 'Sincronizar con BCCR' }}
                </button>
            </form>

            <div class="view-actions">
                <button type="button" class="btn btn-primary" @click="openCreate()"><PlusIcon /> Crear nuevo</button>
            </div>
        </div>

        <div v-if="!bccrConfigured" class="notice card">
            El BCCR todavía no está configurado en este entorno (faltan <code>BCCR_EMAIL</code> / <code>BCCR_TOKEN</code> en <code>.env</code>).
            El botón de sincronizar no va a encontrar datos hasta que se configure; mientras tanto, cargá el tipo de cambio a mano con «Crear nuevo».
        </div>
        <div v-if="syncForm.errors.sync" class="flash flash-error">{{ syncForm.errors.sync }}</div>

        <div class="history-header">
            <h2 class="block-title">Historial — {{ currencyLabel }}</h2>
            <span class="muted small">Últimos {{ rates.length }} registro(s)</span>
        </div>

        <div class="card">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Tipo</th>
                            <th class="num">Tasa</th>
                            <th>Origen</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="r in rates"
                            :key="r.id"
                            class="clickable-row"
                            tabindex="0"
                            @click="openRate(r)"
                            @keydown.enter="openRate(r)"
                            @keydown.space.prevent="openRate(r)"
                        >
                            <td class="date-cell">{{ r.rate_date }}</td>
                            <td data-label="Tipo">{{ typeLabels[r.rate_type] ?? r.rate_type }}</td>
                            <td data-label="Tasa" class="num">{{ r.rate }}</td>
                            <td data-label="Origen">
                                <span class="badges">
                                    <span class="badge" :class="r.source === 'bccr_api' ? 'badge-success' : 'badge-neutral'">
                                        {{ r.source === 'bccr_api' ? 'BCCR' : 'Manual' }}
                                    </span>
                                    <span v-if="r.is_locked" class="badge badge-warning">Bloqueada</span>
                                </span>
                            </td>
                        </tr>
                        <tr v-if="!rates.length">
                            <td colspan="4" class="muted empty-row">Todavía no hay tipos de cambio registrados para {{ currencyLabel }}.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <DetailModal :open="modalOpen" :title="modalTitle" @close="closeModal">
            <template #badge>
                <span v-if="selected && mode === 'details'" class="badge" :class="selected.source === 'bccr_api' ? 'badge-success' : 'badge-neutral'">
                    {{ selected.source === 'bccr_api' ? 'BCCR' : 'Manual' }}
                </span>
            </template>

            <dl v-if="selected && mode === 'details'" class="detail-list">
                <div>
                    <dt>Tasa (₡ por 1 {{ currencyLabel }})</dt>
                    <dd>{{ selected.rate }}</dd>
                </div>
                <div>
                    <dt>Bloqueada</dt>
                    <dd>{{ selected.is_locked ? 'Sí — el sync del BCCR no la sobrescribe' : 'No' }}</dd>
                </div>
                <div>
                    <dt>Cargado por</dt>
                    <dd>{{ selected.created_by?.name ?? '—' }}</dd>
                </div>
            </dl>

            <form v-if="mode === 'create'" id="rate-form" @submit.prevent="saveManual">
                <p class="muted small">Si la fecha y el tipo ya existen, se actualiza esa fila en vez de duplicarla.</p>

                <div class="field-row">
                    <div class="field">
                        <label for="rate-date">Fecha</label>
                        <input id="rate-date" v-model="manualForm.rate_date" type="date" required>
                        <span v-if="manualForm.errors.rate_date" class="error">{{ manualForm.errors.rate_date }}</span>
                    </div>

                    <div class="field">
                        <label for="rate-type">Tipo</label>
                        <select id="rate-type" v-model="manualForm.rate_type">
                            <option value="reference">Referencia</option>
                            <option value="buy">Compra</option>
                            <option value="sell">Venta</option>
                        </select>
                    </div>
                </div>

                <div class="field">
                    <label for="rate-value">Tasa (₡ por 1 {{ currencyLabel }})</label>
                    <input id="rate-value" v-model="manualForm.rate" type="number" step="0.000001" min="0" required>
                    <span v-if="manualForm.errors.rate" class="error">{{ manualForm.errors.rate }}</span>
                </div>

                <label class="check">
                    <input v-model="manualForm.is_locked" type="checkbox">
                    Bloquear (el sync automático del BCCR no la va a sobrescribir)
                </label>
            </form>

            <template #actions>
                <template v-if="selected && mode === 'details'">
                    <button type="button" class="btn btn-ghost btn-danger-text" @click="destroy">Eliminar</button>
                    <button type="button" class="btn btn-primary" @click="startCorrection"><PencilIcon /> Corregir</button>
                </template>
                <template v-else-if="mode === 'create'">
                    <button type="button" class="btn btn-ghost" @click="closeModal">Cancelar</button>
                    <button type="submit" form="rate-form" class="btn btn-primary" :disabled="manualForm.processing">Guardar</button>
                </template>
            </template>
        </DetailModal>
    </AppLayout>
</template>

<style scoped>
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

.history-header {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 0.25rem 1rem;
    margin-bottom: 0.6rem;
}

.history-header .block-title { margin: 0; }

table { font-size: 0.84rem; }
.date-cell { font-variant-numeric: tabular-nums; white-space: nowrap; }
.badges { display: inline-flex; flex-wrap: wrap; gap: 0.3rem; }
</style>
