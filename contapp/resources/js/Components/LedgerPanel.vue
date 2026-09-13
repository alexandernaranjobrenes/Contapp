<script setup>
import { ref, computed, watch, nextTick } from 'vue';
import { formatMoney } from '../Utils/money';

const props = defineProps({
    open: { type: Boolean, default: false },
    dimension: { type: String, default: 'account' },
    ownerId: { type: [Number, String, null], default: null },
    ownerLabel: { type: String, default: '' },
});

const emit = defineEmits(['close']);

const loading = ref(false);
const error = ref('');
const data = ref(null);
const from = ref('');
const to = ref('');
const activePreset = ref('all');

function todayIso() {
    return new Date().toISOString().slice(0, 10);
}

function startOfMonthIso() {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-01`;
}

function startOfYearIso() {
    return `${new Date().getFullYear()}-01-01`;
}

async function fetchLedger() {
    if (! props.ownerId) return;

    loading.value = true;
    error.value = '';

    const params = new URLSearchParams();
    if (from.value) params.set('from', from.value);
    if (to.value) params.set('to', to.value);

    try {
        const res = await fetch(`/ledger/${props.dimension}/${props.ownerId}?${params.toString()}`, {
            headers: { Accept: 'application/json' },
        });

        if (! res.ok) throw new Error(res.status === 404 ? 'No se encontró el registro.' : 'No se pudo cargar el mayor.');

        data.value = await res.json();
    } catch (e) {
        error.value = e.message || 'No se pudo cargar el mayor.';
        data.value = null;
    } finally {
        loading.value = false;
    }
}

function applyPreset(preset) {
    activePreset.value = preset;

    if (preset === 'all') {
        from.value = '';
        to.value = '';
    } else if (preset === 'month') {
        from.value = startOfMonthIso();
        to.value = todayIso();
    } else if (preset === 'year') {
        from.value = startOfYearIso();
        to.value = todayIso();
    }

    fetchLedger();
}

function applyCustomRange() {
    activePreset.value = 'custom';
    fetchLedger();
}

watch(() => [props.open, props.ownerId], ([isOpen, ownerId]) => {
    if (isOpen && ownerId) {
        activePreset.value = 'all';
        from.value = '';
        to.value = '';
        data.value = null;
        nextTick(fetchLedger);
    }
});

function close() {
    emit('close');
}

function onKeydown(e) {
    if (e.key === 'Escape') close();
}

const exportHref = computed(() => {
    if (! props.ownerId) return null;

    const params = new URLSearchParams();
    if (from.value) params.set('from', from.value);
    if (to.value) params.set('to', to.value);

    return `/ledger/${props.dimension}/${props.ownerId}/export?${params.toString()}`;
});

// Sparkline: puntos normalizados del saldo acumulado a lo largo de los
// movimientos visibles, dibujado a mano (sin librería) sobre un viewBox fijo.
const sparklinePoints = computed(() => {
    const moves = data.value?.movements ?? [];
    if (moves.length < 2) return '';

    const values = [parseFloat(data.value.opening_balance), ...moves.map((m) => parseFloat(m.balance))];
    const min = Math.min(...values);
    const max = Math.max(...values);
    const span = max - min || 1;
    const stepX = 300 / (values.length - 1);

    return values
        .map((v, i) => `${(i * stepX).toFixed(1)},${(50 - ((v - min) / span) * 46 - 2).toFixed(1)}`)
        .join(' ');
});

const isPositiveTrend = computed(() => {
    if (! data.value) return true;
    return parseFloat(data.value.closing_balance) >= parseFloat(data.value.opening_balance);
});
</script>

<template>
    <Teleport to="body">
        <div v-if="open" class="ledger-backdrop" @click.self="close" @keydown="onKeydown">
            <aside class="ledger-panel" :class="{ 'is-open': open }" tabindex="-1">
                <header class="ledger-header">
                    <div>
                        <div class="ledger-owner">{{ data ? `${data.owner_code} — ${data.owner_name}` : ownerLabel }}</div>
                        <div class="ledger-sub muted">Mayor auxiliar</div>
                    </div>
                    <button type="button" class="btn btn-ghost close-btn" @click="close" title="Cerrar">✕</button>
                </header>

                <div class="ledger-filters">
                    <div class="preset-row">
                        <button type="button" class="chip" :class="{ active: activePreset === 'all' }" @click="applyPreset('all')">Todo</button>
                        <button type="button" class="chip" :class="{ active: activePreset === 'month' }" @click="applyPreset('month')">Este mes</button>
                        <button type="button" class="chip" :class="{ active: activePreset === 'year' }" @click="applyPreset('year')">Este año</button>
                    </div>
                    <div class="date-row">
                        <input v-model="from" type="date" class="date-input" @change="applyCustomRange">
                        <span class="muted">—</span>
                        <input v-model="to" type="date" class="date-input" @change="applyCustomRange">
                        <a v-if="data" :href="exportHref" class="btn btn-ghost export-btn" title="Exportar a Excel">⤓ Excel</a>
                    </div>
                </div>

                <div v-if="loading" class="ledger-state muted">Cargando movimientos...</div>
                <div v-else-if="error" class="ledger-state error">{{ error }}</div>

                <template v-else-if="data">
                    <div class="balance-block">
                        <div class="balance-row">
                            <div class="balance-cell">
                                <span class="muted small">Saldo inicial</span>
                                <strong class="num">₡{{ formatMoney(data.opening_balance) }}</strong>
                            </div>
                            <svg v-if="sparklinePoints" class="sparkline" viewBox="0 0 300 50" preserveAspectRatio="none">
                                <polyline :points="sparklinePoints" :class="isPositiveTrend ? 'trend-up' : 'trend-down'" fill="none" stroke-width="2" />
                            </svg>
                            <div class="balance-cell align-right">
                                <span class="muted small">Saldo final</span>
                                <strong class="num closing">₡{{ formatMoney(data.closing_balance) }}</strong>
                            </div>
                        </div>
                    </div>

                    <div class="ledger-table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Fecha</th>
                                    <th>Documento</th>
                                    <th>Descripción</th>
                                    <th class="num">Débito</th>
                                    <th class="num">Crédito</th>
                                    <th class="num">Saldo</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(m, i) in data.movements" :key="i">
                                    <td class="num">{{ m.date }}</td>
                                    <td>{{ m.document }}</td>
                                    <td class="desc-cell">{{ m.description }}</td>
                                    <td class="num">{{ m.debit !== '0.00' ? formatMoney(m.debit) : '' }}</td>
                                    <td class="num">{{ m.credit !== '0.00' ? formatMoney(m.credit) : '' }}</td>
                                    <td class="num">{{ formatMoney(m.balance) }}</td>
                                </tr>
                                <tr v-if="!data.movements.length">
                                    <td colspan="6" class="muted empty-row">No hay movimientos contabilizados en este rango.</td>
                                </tr>
                            </tbody>
                        </table>
                        <p v-if="data.truncated" class="muted small truncated-note">
                            Se muestran los primeros 1000 movimientos del rango; achicá el rango de fechas para ver el resto.
                        </p>
                    </div>
                </template>
            </aside>
        </div>
    </Teleport>
</template>

<style scoped>
.ledger-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(11, 31, 58, 0.45);
    z-index: 60;
    display: flex;
    justify-content: flex-end;
}

.ledger-panel {
    width: 620px;
    max-width: 100%;
    height: 100%;
    background: var(--color-surface);
    box-shadow: var(--shadow-md);
    display: flex;
    flex-direction: column;
    transform: translateX(100%);
    animation: slide-in .18s ease forwards;
}

@keyframes slide-in {
    to { transform: translateX(0); }
}

.ledger-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 1rem 1.25rem;
    background: var(--color-primary);
    color: var(--color-on-primary);
    border-bottom: 3px solid var(--color-warning);
}

.ledger-owner {
    font-weight: 700;
    font-size: 0.95rem;
}

.ledger-sub {
    font-size: 0.76rem;
    color: rgba(244, 246, 250, 0.7);
}

.close-btn {
    color: var(--color-on-primary);
    padding: 0.2rem 0.55rem;
}

.ledger-filters {
    padding: 0.85rem 1.25rem;
    border-bottom: 1px solid var(--color-border);
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.preset-row, .date-row {
    display: flex;
    align-items: center;
    gap: 0.4rem;
}

.chip {
    background: var(--color-surface-alt);
    border: 1px solid var(--color-border);
    border-radius: 999px;
    padding: 0.25rem 0.75rem;
    font-size: 0.78rem;
    cursor: pointer;
    color: var(--color-text);
}

.chip.active {
    background: var(--color-primary);
    border-color: var(--color-primary);
    color: var(--color-on-primary);
}

.date-input {
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    padding: 0.3rem 0.5rem;
    font-size: 0.8rem;
    color: var(--color-text);
}

.export-btn {
    margin-left: auto;
    font-size: 0.78rem;
}

.ledger-state {
    padding: 2rem 1.25rem;
    text-align: center;
    font-size: 0.85rem;
}

.ledger-state.error {
    color: var(--color-danger);
}

.balance-block {
    padding: 0.9rem 1.25rem;
    border-bottom: 1px solid var(--color-border);
}

.balance-row {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.balance-cell {
    display: flex;
    flex-direction: column;
    gap: 0.15rem;
    flex-shrink: 0;
}

.balance-cell.align-right {
    align-items: flex-end;
    text-align: right;
}

.balance-cell .num {
    font-size: 1.15rem;
    font-variant-numeric: tabular-nums;
}

.balance-cell .closing {
    color: var(--color-primary);
}

.sparkline {
    flex: 1;
    height: 50px;
    min-width: 0;
}

.sparkline polyline.trend-up {
    stroke: var(--color-success);
}

.sparkline polyline.trend-down {
    stroke: var(--color-danger);
}

.ledger-table-wrap {
    flex: 1;
    overflow-y: auto;
    padding: 0 1.25rem 1.25rem;
}

table { font-size: 0.8rem; width: 100%; }
th, td { text-align: left; padding: 0.45rem 0.6rem; border-top: 1px solid var(--color-border); white-space: nowrap; }
.num { font-variant-numeric: tabular-nums; text-align: right; }
thead th.num { text-align: right; }
.desc-cell { white-space: normal; max-width: 220px; }
.muted { color: var(--color-text-muted); }
.small { font-size: 0.74rem; }
.empty-row { text-align: center; padding: 1.5rem; white-space: normal; }
.truncated-note { padding-top: 0.5rem; }
</style>
