<script setup>
import { computed, ref } from 'vue';
import { amount, compact, ticks } from '../../Utils/chartFormat';
import { useWidth } from './useWidth';

/**
 * Una línea en el tiempo (2px, con un velo del 10% debajo), de una sola
 * serie en --color-chart-1. El eje no arranca en cero: muestra cuánto se
 * movió el valor. Una línea vertical sigue al mouse o a las flechas del
 * teclado y el aviso dice la fecha y el valor.
 */
const props = defineProps({
    points: { type: Array, required: true },
    format: { type: String, default: 'money' },
    currency: { type: Object, default: null },
    label: { type: String, default: '' },
    height: { type: Number, default: 150 },
});

const root = ref(null);
const width = useWidth(root);
const active = ref(null);

const TOP = 10;
const BOTTOM = 20;

const scale = computed(() => {
    const values = props.points.map((p) => p.value);
    const list = ticks(Math.min(...values), Math.max(...values), 3);
    return { ticks: list, min: list[0], max: list[list.length - 1] };
});

const left = computed(() => 8 + Math.max(...scale.value.ticks.map((t) => compact(t, props.format, props.currency).length)) * 6.4);
const plot = computed(() => ({ x: left.value, w: Math.max(0, width.value - left.value - 8), h: props.height - TOP - BOTTOM }));

const x = (i) => plot.value.x + (props.points.length > 1 ? (plot.value.w * i) / (props.points.length - 1) : plot.value.w / 2);
const y = (v) => TOP + plot.value.h * (1 - (v - scale.value.min) / (scale.value.max - scale.value.min || 1));

const line = computed(() => props.points.map((p, i) => `${i ? 'L' : 'M'}${x(i).toFixed(1)},${y(p.value).toFixed(1)}`).join(' '));
const area = computed(() => props.points.length
    ? `${line.value} L${x(props.points.length - 1).toFixed(1)},${TOP + plot.value.h} L${x(0).toFixed(1)},${TOP + plot.value.h} Z`
    : '');

const last = computed(() => props.points.length - 1);
const shown = computed(() => active.value ?? last.value);

function nearest(event) {
    const rect = event.currentTarget.getBoundingClientRect();
    const px = event.clientX - rect.left - plot.value.x;
    const step = props.points.length > 1 ? plot.value.w / (props.points.length - 1) : 1;
    active.value = Math.max(0, Math.min(last.value, Math.round(px / step)));
}

function onKey(event) {
    if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
        event.preventDefault();
        const from = active.value ?? last.value;
        active.value = Math.max(0, Math.min(last.value, from + (event.key === 'ArrowRight' ? 1 : -1)));
    }
}

const tooltipStyle = computed(() => {
    const px = x(shown.value);
    return px > width.value * 0.6
        ? { right: `${Math.max(0, width.value - px + 10)}px`, top: '0' }
        : { left: `${px + 10}px`, top: '0' };
});
</script>

<template>
    <div ref="root" class="chart">
        <svg
            v-if="width > 0 && points.length"
            :width="width"
            :height="height"
            tabindex="0"
            role="img"
            :aria-label="`${label}: ${points[shown].label}, ${amount(points[shown].value, format, currency)}. Usá las flechas para recorrer los días.`"
            @pointermove="nearest"
            @pointerleave="active = null"
            @keydown="onKey"
            @blur="active = null"
        >
            <g aria-hidden="true">
                <template v-for="t in scale.ticks" :key="t">
                    <line :x1="plot.x" :x2="plot.x + plot.w" :y1="y(t)" :y2="y(t)" class="grid" />
                    <text :x="plot.x - 6" :y="y(t)" class="tick" text-anchor="end" dominant-baseline="middle">{{ compact(t, format, currency) }}</text>
                </template>

                <path :d="area" class="area" />
                <path :d="line" class="line" />

                <line v-if="active !== null" :x1="x(active)" :x2="x(active)" :y1="TOP" :y2="TOP + plot.h" class="crosshair" />
                <circle :cx="x(shown)" :cy="y(points[shown].value)" r="4" class="dot" />

                <text :x="x(0)" :y="height - 4" class="tick" text-anchor="start">{{ points[0].label }}</text>
                <text v-if="last > 0" :x="x(last)" :y="height - 4" class="tick" text-anchor="end">{{ points[last].label }}</text>
            </g>
        </svg>

        <div v-if="active !== null" class="tooltip" :style="tooltipStyle" aria-hidden="true">
            <strong class="tooltip-value">{{ amount(points[active].value, format, currency) }}</strong>
            <span class="tooltip-label">{{ points[active].label }}</span>
        </div>
    </div>
</template>

<style scoped>
.chart { position: relative; width: 100%; min-width: 0; }
svg { display: block; overflow: visible; outline: none; border-radius: var(--radius-sm); }
svg:focus-visible { box-shadow: 0 0 0 2px var(--color-primary); }

.grid { stroke: var(--color-border); stroke-width: 1; shape-rendering: crispEdges; }
.tick { fill: var(--color-text-muted); font-size: 11px; font-variant-numeric: tabular-nums; }

.area { fill: var(--color-chart-1); opacity: 0.1; }
.line { fill: none; stroke: var(--color-chart-1); stroke-width: 2; stroke-linejoin: round; stroke-linecap: round; }
.crosshair { stroke: var(--color-text-muted); stroke-width: 1; shape-rendering: crispEdges; }
/* El punto, con un anillo del color de la superficie para leerse sobre la línea. */
.dot { fill: var(--color-chart-1); stroke: var(--color-surface); stroke-width: 2; }

.tooltip {
    position: absolute;
    z-index: 2;
    display: grid;
    padding: 0.4rem 0.6rem;
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    background: var(--color-surface);
    box-shadow: var(--shadow-md);
    font-size: 0.78rem;
    pointer-events: none;
}

.tooltip-value { color: var(--color-text); white-space: nowrap; }
.tooltip-label { color: var(--color-text-muted); white-space: nowrap; }
</style>
