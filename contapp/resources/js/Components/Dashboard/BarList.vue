<script setup>
import { computed } from 'vue';
import { amount } from '../../Utils/chartFormat';

/**
 * Barras horizontales, una por fila, con su valor escrito al lado: el valor
 * ya se lee, así que no hace falta un aviso. Una sola serie en
 * --color-chart-1, barras de 8px con la punta redondeada. Un valor negativo
 * (un sobregiro) crece hacia la izquierda de la línea del cero.
 */
const props = defineProps({
    rows: { type: Array, required: true },
    format: { type: String, default: 'money' },
    currency: { type: Object, default: null },
});

const domain = computed(() => {
    const values = props.rows.map((r) => r.value);
    const min = Math.min(0, ...values);
    const max = Math.max(0, ...values);
    return { min, span: max - min || 1 };
});

function barStyle(value) {
    const { min, span } = domain.value;
    const start = (Math.min(value, 0) - min) / span;
    const size = Math.abs(value) / span;
    return {
        left: `${start * 100}%`,
        width: `${size * 100}%`,
        borderRadius: value < 0 ? '4px 0 0 4px' : '0 4px 4px 0',
    };
}

const zero = computed(() => (domain.value.min < 0 ? `${(-domain.value.min / domain.value.span) * 100}%` : null));
</script>

<template>
    <ul class="bar-list">
        <li v-for="(row, i) in rows" :key="`${row.label}${i}`">
            <div class="bar-head">
                <span class="bar-label">
                    {{ row.label }}
                    <span v-if="row.sublabel" class="bar-sublabel">{{ row.sublabel }}</span>
                </span>
                <span class="bar-value">{{ amount(row.value, format, currency) }}</span>
            </div>
            <div v-if="row.secondary" class="bar-secondary">{{ amount(row.secondary.value, format, row.secondary.currency) }}</div>
            <div class="bar-track" aria-hidden="true">
                <span v-if="zero" class="bar-zero" :style="{ left: zero }"></span>
                <span class="bar" :style="barStyle(row.value)"></span>
            </div>
        </li>
    </ul>
</template>

<style scoped>
.bar-list { display: grid; gap: 0.7rem; margin: 0; padding: 0; list-style: none; }

.bar-head {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 0.75rem;
    font-size: 0.84rem;
}

.bar-label { min-width: 0; overflow-wrap: anywhere; color: var(--color-text); }
.bar-sublabel { margin-left: 0.35rem; font-size: 0.75rem; color: var(--color-text-muted); }
.bar-value { flex-shrink: 0; font-weight: 700; font-variant-numeric: tabular-nums; color: var(--color-text); }
.bar-secondary { margin-top: 0.05rem; font-size: 0.75rem; color: var(--color-text-muted); }

.bar-track { position: relative; height: 8px; margin-top: 0.3rem; }
.bar { position: absolute; top: 0; bottom: 0; min-width: 2px; background: var(--color-chart-1); }
.bar-zero { position: absolute; top: -3px; bottom: -3px; width: 1px; background: var(--color-control-border); }
</style>
