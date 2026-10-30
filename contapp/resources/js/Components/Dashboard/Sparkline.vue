<script setup>
import { computed, ref } from 'vue';
import { useWidth } from './useWidth';

/**
 * La tendencia de un resumen: los últimos valores en una línea discreta (el
 * gris del texto secundario), con el actual marcado en --color-chart-1. Es
 * decorativa: el número y su comparación ya están escritos al lado.
 */
const props = defineProps({
    values: { type: Array, required: true },
    height: { type: Number, default: 34 },
});

const root = ref(null);
const width = useWidth(root);

const points = computed(() => {
    const min = Math.min(...props.values);
    const max = Math.max(...props.values);
    const span = max - min || 1;
    const usable = Math.max(0, width.value - 8);
    const step = props.values.length > 1 ? usable / (props.values.length - 1) : 0;

    return props.values.map((v, i) => [4 + i * step, 4 + (props.height - 8) * (1 - (v - min) / span)]);
});

const last = computed(() => points.value[points.value.length - 1]);
</script>

<template>
    <div ref="root" class="sparkline" aria-hidden="true">
        <svg v-if="width > 0 && points.length" :width="width" :height="height">
            <polyline :points="points.map(([x, y]) => `${x.toFixed(1)},${y.toFixed(1)}`).join(' ')" />
            <circle :cx="last[0]" :cy="last[1]" r="3.5" />
        </svg>
    </div>
</template>

<style scoped>
.sparkline { width: 100%; min-width: 0; }
svg { display: block; overflow: visible; }
polyline {
    fill: none;
    stroke: color-mix(in srgb, var(--color-text-muted) 70%, transparent);
    stroke-width: 1.5;
    stroke-linejoin: round;
    stroke-linecap: round;
}
circle { fill: var(--color-chart-1); stroke: var(--color-surface); stroke-width: 2; }
</style>
