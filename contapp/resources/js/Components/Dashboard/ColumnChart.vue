<script setup>
import { computed, ref } from 'vue';
import { amount, compact, ticks } from '../../Utils/chartFormat';
import { useWidth } from './useWidth';

/**
 * Columnas por período, de una o dos series (lado a lado o apiladas). Las
 * series toman --color-chart-1 y --color-chart-2 del tema, validados para
 * distinguirse con daltonismo. Columnas finas (hasta 24px) con la punta
 * redondeada y la base recta, 2px de aire entre las que se tocan, grilla de
 * una línea. Al pasar el mouse o llegar con el teclado a un período, un
 * aviso dice todas sus series.
 */
const props = defineProps({
    points: { type: Array, required: true },
    series: { type: Array, required: true },
    mode: { type: String, default: 'grouped' },
    format: { type: String, default: 'money' },
    currency: { type: Object, default: null },
    height: { type: Number, default: 210 },
});

const root = ref(null);
const width = useWidth(root);
const active = ref(null);

const TOP = 10;
const BOTTOM = 22;
const GAP = 2;
const RADIUS = 4;

const color = (i) => `var(--color-chart-${i + 1})`;

const totals = computed(() => props.points.map((p) => props.series.reduce((sum, s) => sum + (p.values[s.key] ?? 0), 0)));

const scale = computed(() => {
    const values = props.mode === 'stacked'
        ? totals.value
        : props.points.flatMap((p) => props.series.map((s) => p.values[s.key] ?? 0));
    const list = ticks(Math.min(0, ...values), Math.max(0, ...values), 4);

    return { ticks: list, min: list[0], max: list[list.length - 1] };
});

// El margen izquierdo, a la medida de la etiqueta más larga del eje.
const left = computed(() => 8 + Math.max(...scale.value.ticks.map((t) => compact(t, props.format, props.currency).length)) * 6.4);
const plot = computed(() => ({ x: left.value, w: Math.max(0, width.value - left.value - 4), h: props.height - TOP - BOTTOM }));

const y = (v) => TOP + plot.value.h * (1 - (v - scale.value.min) / (scale.value.max - scale.value.min || 1));
const band = computed(() => (props.points.length ? plot.value.w / props.points.length : 0));

// La columna: punta redondeada, base recta (también hacia abajo, si es negativa).
function columnPath(x, from, to, w, rounded = true) {
    const top = Math.min(from, to);
    const bottom = Math.max(from, to);
    const h = bottom - top;
    if (h < 0.5) return '';
    const r = rounded ? Math.min(RADIUS, w / 2, h) : 0;

    return to <= from
        ? `M${x},${bottom} V${top + r} Q${x},${top} ${x + r},${top} H${x + w - r} Q${x + w},${top} ${x + w},${top + r} V${bottom} Z`
        : `M${x},${top} V${bottom - r} Q${x},${bottom} ${x + r},${bottom} H${x + w - r} Q${x + w},${bottom} ${x + w},${bottom - r} V${top} Z`;
}

const bars = computed(() => {
    const n = props.series.length;
    const zero = y(0);

    return props.points.map((point, i) => {
        const x0 = plot.value.x + band.value * i;

        if (props.mode === 'stacked') {
            const w = Math.min(24, band.value * 0.6);
            const x = x0 + (band.value - w) / 2;
            let base = 0;
            const last = props.series.findLastIndex((s) => (point.values[s.key] ?? 0) > 0);

            return props.series.map((s, si) => {
                const v = point.values[s.key] ?? 0;
                const from = y(base) - (base > 0 ? GAP : 0);
                base += v;
                return { key: s.key, color: color(si), d: v > 0 ? columnPath(x, from, y(base), w, si === last) : '' };
            });
        }

        const w = Math.max(2, Math.min(24, (band.value * 0.72 - GAP * (n - 1)) / n));
        const groupW = w * n + GAP * (n - 1);

        return props.series.map((s, si) => {
            const x = x0 + (band.value - groupW) / 2 + si * (w + GAP);
            return { key: s.key, color: color(si), d: columnPath(x, zero, y(point.values[s.key] ?? 0), w) };
        });
    });
});

// Con poco espacio, una etiqueta sí y una no (siempre la del último período).
const labelEvery = computed(() => (band.value >= 34 ? 1 : 2));
const showLabel = (i) => (props.points.length - 1 - i) % labelEvery.value === 0;

function describe(point) {
    return `${point.title}: ${props.series.map((s) => `${s.label} ${amount(point.values[s.key], props.format, props.currency)}`).join(', ')}`;
}

const tooltipStyle = computed(() => {
    if (active.value === null) return {};
    const center = plot.value.x + band.value * (active.value + 0.5);
    const alignRight = center > width.value * 0.6;
    return alignRight
        ? { right: `${Math.max(0, width.value - center + 10)}px`, top: '4px' }
        : { left: `${center + 10}px`, top: '4px' };
});
</script>

<template>
    <div ref="root" class="chart">
        <ul v-if="series.length > 1" class="legend">
            <li v-for="(s, i) in series" :key="s.key">
                <span class="swatch" :style="{ background: color(i) }" aria-hidden="true"></span>{{ s.label }}
            </li>
        </ul>

        <div class="plot" @pointerleave="active = null">
            <svg v-if="width > 0" :width="width" :height="height" role="group" :aria-label="`Gráfico de columnas: ${series.map((s) => s.label).join(' y ')}`">
                <g aria-hidden="true">
                    <template v-for="t in scale.ticks" :key="t">
                        <line :x1="plot.x" :x2="plot.x + plot.w" :y1="y(t)" :y2="y(t)" :class="t === 0 ? 'baseline' : 'grid'" />
                        <text :x="plot.x - 6" :y="y(t)" class="tick" text-anchor="end" dominant-baseline="middle">{{ compact(t, format, currency) }}</text>
                    </template>
                </g>

                <rect
                    v-if="active !== null"
                    :x="plot.x + band * active"
                    :y="TOP"
                    :width="band"
                    :height="plot.h"
                    class="hover-band"
                    aria-hidden="true"
                />

                <g v-for="(group, i) in bars" :key="points[i].label + i" aria-hidden="true">
                    <path v-for="bar in group" :key="bar.key" :d="bar.d" :fill="bar.color" />
                </g>

                <g aria-hidden="true">
                    <text
                        v-for="(point, i) in points"
                        v-show="showLabel(i)"
                        :key="`l${i}`"
                        :x="plot.x + band * (i + 0.5)"
                        :y="height - 6"
                        class="tick"
                        text-anchor="middle"
                    >{{ point.label }}</text>
                </g>

                <!-- Lo que se toca: el período entero, más grande que la columna. -->
                <rect
                    v-for="(point, i) in points"
                    :key="`h${i}`"
                    :x="plot.x + band * i"
                    :y="TOP"
                    :width="band"
                    :height="plot.h"
                    class="hit"
                    tabindex="0"
                    role="img"
                    :aria-label="describe(point)"
                    @pointerenter="active = i"
                    @focus="active = i"
                    @blur="active = null"
                />
            </svg>

            <div v-if="active !== null" class="tooltip" :style="tooltipStyle" aria-hidden="true">
                <strong class="tooltip-title">{{ points[active].title }}</strong>
                <div v-for="(s, si) in series" :key="s.key" class="tooltip-row">
                    <span class="key" :style="{ background: color(si) }"></span>
                    <span class="tooltip-value">{{ amount(points[active].values[s.key], format, currency) }}</span>
                    <span class="tooltip-label">{{ s.label }}</span>
                </div>
                <div v-if="mode === 'stacked' && series.length > 1" class="tooltip-row total">
                    <span class="key"></span>
                    <span class="tooltip-value">{{ amount(totals[active], format, currency) }}</span>
                    <span class="tooltip-label">Total</span>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.chart { position: relative; width: 100%; min-width: 0; }
.plot { position: relative; }
svg { display: block; overflow: visible; }

.legend {
    display: flex;
    flex-wrap: wrap;
    gap: 0.35rem 1rem;
    margin: 0 0 0.5rem;
    padding: 0;
    list-style: none;
    font-size: 0.78rem;
    color: var(--color-text-muted);
}

.legend li { display: inline-flex; align-items: center; gap: 0.4rem; }
.swatch { width: 10px; height: 10px; border-radius: 2px; }

.grid { stroke: var(--color-border); stroke-width: 1; shape-rendering: crispEdges; }
.baseline { stroke: var(--color-control-border); stroke-width: 1; shape-rendering: crispEdges; }

.tick {
    fill: var(--color-text-muted);
    font-size: 11px;
    font-variant-numeric: tabular-nums;
}

.hover-band { fill: color-mix(in srgb, var(--color-text) 5%, transparent); }

.hit { fill: transparent; cursor: default; outline: none; }
.hit:focus-visible { stroke: var(--color-primary); stroke-width: 2; }

.tooltip {
    position: absolute;
    z-index: 2;
    min-width: 9.5rem;
    padding: 0.5rem 0.65rem;
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    background: var(--color-surface);
    box-shadow: var(--shadow-md);
    font-size: 0.78rem;
    pointer-events: none;
}

.tooltip-title { display: block; margin-bottom: 0.3rem; font-size: 0.75rem; color: var(--color-text-muted); font-weight: 600; }
.tooltip-row { display: grid; grid-template-columns: 12px auto 1fr; align-items: center; gap: 0.4rem; }
.tooltip-row + .tooltip-row { margin-top: 0.2rem; }
.tooltip-row.total { padding-top: 0.25rem; border-top: 1px solid var(--color-border); }
.key { width: 12px; height: 2px; border-radius: 1px; }
.tooltip-value { font-weight: 700; color: var(--color-text); white-space: nowrap; }
.tooltip-label { color: var(--color-text-muted); white-space: nowrap; }
</style>
