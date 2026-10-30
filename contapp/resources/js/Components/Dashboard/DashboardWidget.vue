<script setup>
import { computed, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import BarList from './BarList.vue';
import ColumnChart from './ColumnChart.vue';
import LineChart from './LineChart.vue';
import Sparkline from './Sparkline.vue';
import { SECTIONS } from './sections';
import { amount, signedAmount } from '../../Utils/chartFormat';
import {
    ArrowDownIcon, ArrowUpIcon, ChartColumnIcon, ChevronRightIcon, CircleCheckIcon, MinusIcon, Table2Icon,
    TrendingDownIcon, TrendingUpIcon, TriangleAlertIcon, XIcon,
} from '@lucide/vue';

/**
 * Un elemento del Panel (CLAUDE.md secc. 44): su encabezado (ícono de la
 * sección, título, «Ver más») y el cuerpo según su forma. Los gráficos de
 * columnas y de línea tienen además su tabla con los mismos datos, para
 * quien no puede o no quiere leer el gráfico.
 *
 * Mientras se personaliza el Panel, el cuerpo se atenúa y el encabezado
 * lleva mover y quitar. Uno recién agregado todavía no tiene datos: los
 * trae al guardar.
 */
const props = defineProps({
    widget: { type: Object, required: true },
    editing: { type: Boolean, default: false },
    first: { type: Boolean, default: false },
    last: { type: Boolean, default: false },
});

const emit = defineEmits(['move', 'remove']);

const section = computed(() => SECTIONS[props.widget.section] ?? SECTIONS.accounting);
const data = computed(() => props.widget.data ?? null);
const pending = computed(() => props.editing && props.widget.data === undefined);

const charted = computed(() => ['columns', 'line'].includes(props.widget.kind) && !!data.value);
const asTable = ref(false);

const hasPoints = computed(() => (data.value?.points?.length ?? 0) > 0);
const allZero = computed(() => props.widget.kind === 'columns'
    && hasPoints.value
    && data.value.points.every((p) => Object.values(p.values).every((v) => !v)));

const subtitle = computed(() => {
    if (!data.value) return null;
    if (props.widget.kind === 'stat') return data.value.period ?? null;
    if (props.widget.kind === 'line') return data.value.subject ?? null;
    if (props.widget.kind === 'list' && data.value.count !== undefined) {
        return data.value.count === 1 ? '1 en total' : `${data.value.count} en total`;
    }
    return null;
});

// Arriba o abajo es bueno según el dato; sin esa idea, va en gris.
function deltaTone(delta) {
    if (!delta || !delta.value || delta.good === null) return 'neutral';
    return (delta.value > 0) === (delta.good === 'up') ? 'good' : 'bad';
}

// «₡3.000,00 menos que en setiembre» (una comparación) o «+₡12,40 desde el
// 30 set» (un cambio).
function deltaText(delta, format, currency) {
    if (delta.style === 'comparison') {
        if (!delta.value) return `Igual ${delta.versus}`;
        return `${amount(Math.abs(delta.value), format, currency)} ${delta.value > 0 ? 'más' : 'menos'} ${delta.versus}`;
    }
    return delta.value ? `${signedAmount(delta.value, format, currency)} ${delta.versus}` : `Sin cambio ${delta.versus}`;
}

const meterRatio = computed(() => {
    const limit = data.value?.limit;
    return limit && limit.value > 0 ? limit.used / limit.value : 0;
});
</script>

<template>
    <article class="card widget" :class="[`w-${widget.size}`, { editing }]" :aria-labelledby="`widget-${widget.key}`">
        <header class="widget-head">
            <span class="widget-icon" :title="section.label" aria-hidden="true"><component :is="section.icon" :size="16" /></span>
            <div class="widget-titles">
                <h2 :id="`widget-${widget.key}`" class="widget-title">{{ widget.title }}</h2>
                <p v-if="subtitle" class="widget-subtitle">{{ subtitle }}</p>
            </div>

            <div v-if="editing" class="widget-actions">
                <button type="button" class="btn btn-ghost icon-btn" :disabled="first" :aria-label="`Mover ${widget.title} antes`" title="Mover antes" @click="emit('move', -1)">
                    <ArrowUpIcon />
                </button>
                <button type="button" class="btn btn-ghost icon-btn" :disabled="last" :aria-label="`Mover ${widget.title} después`" title="Mover después" @click="emit('move', 1)">
                    <ArrowDownIcon />
                </button>
                <button type="button" class="btn btn-ghost icon-btn" :aria-label="`Quitar ${widget.title} del Panel`" title="Quitar del Panel" @click="emit('remove')">
                    <XIcon />
                </button>
            </div>
            <div v-else class="widget-actions">
                <button
                    v-if="charted && hasPoints && !allZero"
                    type="button"
                    class="btn btn-ghost icon-btn"
                    :aria-pressed="asTable"
                    :aria-label="asTable ? 'Ver como gráfico' : 'Ver como tabla'"
                    :title="asTable ? 'Ver como gráfico' : 'Ver como tabla'"
                    @click="asTable = !asTable"
                >
                    <ChartColumnIcon v-if="asTable" />
                    <Table2Icon v-else />
                </button>
                <Link v-if="widget.href" :href="widget.href" class="btn btn-ghost icon-btn" :aria-label="`Abrir ${widget.title}`" title="Ver más">
                    <ChevronRightIcon />
                </Link>
            </div>
        </header>

        <div class="widget-body">
            <p v-if="pending" class="widget-note">
                <component :is="section.icon" :size="18" /> {{ widget.description }} Los datos aparecen al guardar el Panel.
            </p>

            <p v-else-if="widget.error" class="widget-note is-error">
                <TriangleAlertIcon :size="18" /> No se pudo cargar este elemento. Recargá la página; si sigue igual, avisale al equipo de Pivox.
            </p>

            <!-- Un número -->
            <template v-else-if="widget.kind === 'stat'">
                <p class="stat-value">
                    {{ data.format === 'count' ? data.value : amount(data.value, data.format, data.currency) }}
                    <span v-if="data.unit" class="stat-unit">{{ data.unit }}</span>
                </p>
                <p v-if="data.delta" class="stat-delta" :class="`is-${deltaTone(data.delta)}`">
                    <TrendingUpIcon v-if="data.delta.value > 0" :size="15" />
                    <TrendingDownIcon v-else-if="data.delta.value < 0" :size="15" />
                    <MinusIcon v-else :size="15" />
                    <span>{{ deltaText(data.delta, data.format, data.currency) }}</span>
                </p>
                <p v-if="data.note" class="stat-note">{{ data.note }}</p>
                <dl v-if="data.details" class="stat-details">
                    <div v-for="d in data.details" :key="d.label">
                        <dt>{{ d.label }}</dt>
                        <dd>{{ amount(d.value, data.format, data.currency) }}</dd>
                    </div>
                </dl>
                <Sparkline v-if="data.trend?.length > 1" :values="data.trend" class="stat-trend" />
            </template>

            <!-- Columnas -->
            <template v-else-if="widget.kind === 'columns'">
                <p v-if="!hasPoints || allZero" class="widget-empty">{{ data.empty ?? 'Todavía no hay movimientos para mostrar.' }}</p>
                <template v-else>
                    <ColumnChart
                        v-if="!asTable"
                        :points="data.points"
                        :series="data.series"
                        :mode="data.mode"
                        :format="data.format"
                        :currency="data.currency"
                    />
                    <div v-else class="table-responsive no-cards widget-table">
                        <table>
                            <thead>
                                <tr>
                                    <th scope="col">Período</th>
                                    <th v-for="s in data.series" :key="s.key" scope="col" class="num">{{ s.label }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="p in data.points" :key="p.title">
                                    <th scope="row">{{ p.title }}</th>
                                    <td v-for="s in data.series" :key="s.key" class="num">{{ amount(p.values[s.key], data.format, data.currency) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <dl v-if="data.summary" class="summary">
                        <div v-for="s in data.summary" :key="s.label">
                            <dt>{{ s.label }}</dt>
                            <dd>{{ amount(s.value, data.format, data.currency) }}</dd>
                        </div>
                    </dl>
                </template>
            </template>

            <!-- Línea -->
            <template v-else-if="widget.kind === 'line'">
                <p v-if="!hasPoints" class="widget-empty">{{ data.empty }}</p>
                <template v-else>
                    <p class="stat-value is-compact">
                        {{ amount(data.value, data.format, data.currency) }}
                        <span v-if="data.asOf" class="stat-unit">al {{ data.asOf }}</span>
                    </p>
                    <p v-if="data.delta" class="stat-delta is-neutral">
                        <TrendingUpIcon v-if="data.delta.value > 0" :size="15" />
                        <TrendingDownIcon v-else-if="data.delta.value < 0" :size="15" />
                        <MinusIcon v-else :size="15" />
                        <span>{{ deltaText(data.delta, data.format, data.currency) }}</span>
                    </p>
                    <LineChart v-if="!asTable" :points="data.points" :format="data.format" :currency="data.currency" :label="widget.title" />
                    <div v-else class="table-responsive no-cards widget-table">
                        <table>
                            <thead><tr><th scope="col">Día</th><th scope="col" class="num">Valor</th></tr></thead>
                            <tbody>
                                <tr v-for="p in data.points" :key="p.date">
                                    <th scope="row">{{ p.label }}</th>
                                    <td class="num">{{ amount(p.value, data.format, data.currency) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </template>
            </template>

            <!-- Barras -->
            <template v-else-if="widget.kind === 'bars'">
                <p v-if="!data.rows.length" class="widget-empty">{{ data.empty }}</p>
                <template v-else>
                    <BarList :rows="data.rows" :format="data.format" :currency="data.currency" />
                    <dl v-if="data.total" class="summary is-total">
                        <div>
                            <dt>{{ data.total.label }}</dt>
                            <dd>{{ amount(data.total.value, data.format, data.currency) }}</dd>
                        </div>
                    </dl>
                    <p v-if="data.others?.length" class="widget-footnote">
                        También hay saldos en
                        <template v-for="(o, i) in data.others" :key="o.currency">{{ i ? ', ' : '' }}{{ o.currency }} ({{ amount(o.value, 'money') }})</template>.
                    </p>
                </template>
            </template>

            <!-- Lista -->
            <template v-else-if="widget.kind === 'list'">
                <p v-if="!data.rows.length" class="widget-empty" :class="{ 'is-good': data.emptyGood }">
                    <CircleCheckIcon v-if="data.emptyGood" :size="18" /> {{ data.empty }}
                </p>
                <ul v-else class="widget-list">
                    <li v-for="(row, i) in data.rows" :key="`${row.title}${i}`">
                        <component :is="row.href ? Link : 'div'" :href="row.href" class="widget-row">
                            <span class="row-main">
                                <span class="row-title">{{ row.title }}</span>
                                <span v-if="row.subtitle" class="row-subtitle">{{ row.subtitle }}</span>
                            </span>
                            <span class="row-side">
                                <span v-if="row.badge" class="badge" :class="row.badge.badge">{{ row.badge.label }}</span>
                                <span v-if="row.meta" class="row-meta">{{ row.meta }}</span>
                            </span>
                        </component>
                    </li>
                </ul>
                <p v-if="data.more" class="widget-footnote">
                    Y {{ data.more }} más<template v-if="widget.href">: <Link :href="widget.href">ver todos</Link></template>.
                </p>
            </template>

            <!-- Lo usado de un límite -->
            <template v-else-if="widget.kind === 'meter'">
                <p class="stat-value is-compact">
                    {{ amount(data.used, 'money') }} <span class="stat-unit">{{ data.unit }}</span>
                </p>
                <p class="stat-note">{{ data.caption }}</p>
                <template v-if="data.limit">
                    <div
                        class="meter"
                        :class="{ 'is-warning': meterRatio >= 0.8 && meterRatio < 1, 'is-danger': meterRatio >= 1 }"
                        role="meter"
                        :aria-valuenow="data.limit.used"
                        aria-valuemin="0"
                        :aria-valuemax="data.limit.value"
                        :aria-label="data.limit.label"
                    >
                        <span :style="{ width: `${Math.min(100, meterRatio * 100)}%` }"></span>
                    </div>
                    <p class="stat-note">
                        {{ data.limit.label }}: {{ amount(data.limit.used, 'money') }} de {{ amount(data.limit.value, 'money') }} {{ data.unit }}.
                        {{ data.limit.renews }}
                    </p>
                </template>
                <p v-else class="stat-note">{{ data.empty }}</p>
            </template>
        </div>
    </article>
</template>

<style scoped>
/* Cada tarjeta mide su propio ancho: el número grande se achica en una
   tarjeta angosta en vez de partirse en dos líneas. */
.widget {
    display: flex;
    flex-direction: column;
    min-width: 0;
    padding: 0;
    overflow: visible;
    container-type: inline-size;
}

.widget-head {
    display: flex;
    align-items: center;
    gap: 0.65rem;
    padding: 0.8rem 0.9rem 0.4rem 1rem;
}

.widget-icon {
    flex-shrink: 0;
    display: grid;
    place-items: center;
    width: 1.9rem;
    height: 1.9rem;
    border-radius: var(--radius-sm);
    background: var(--color-primary-soft);
    color: var(--color-primary);
}

.widget-titles { flex: 1; min-width: 0; }
.widget-title { margin: 0; font-size: 0.92rem; line-height: 1.3; }
.widget-subtitle { margin: 0.05rem 0 0; font-size: 0.76rem; color: var(--color-text-muted); }

.widget-actions { display: flex; gap: 0.15rem; flex-shrink: 0; }
.icon-btn { min-height: 2rem; width: 2rem; padding: 0; justify-content: center; }

.widget-body {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 0.4rem;
    min-width: 0;
    padding: 0.35rem 1rem 1rem;
}

/* Personalizando: el contenido atrás, los controles adelante. */
.widget.editing { outline: 2px dashed var(--color-control-border); outline-offset: -2px; }
.widget.editing .widget-body { opacity: 0.55; pointer-events: none; }

.stat-value { margin: 0.1rem 0 0; font-size: clamp(1.25rem, 9.5cqi, 1.85rem); font-weight: 700; line-height: 1.15; overflow-wrap: anywhere; }
.stat-value.is-compact { font-size: clamp(1.15rem, 8cqi, 1.45rem); }
.stat-unit { font-size: 0.82rem; font-weight: 500; color: var(--color-text-muted); }

.stat-delta { display: flex; align-items: center; gap: 0.35rem; margin: 0; font-size: 0.8rem; }
.stat-delta.is-good { color: var(--color-success); }
.stat-delta.is-bad { color: var(--color-danger); }
.stat-delta.is-neutral { color: var(--color-text-muted); }

.stat-note { margin: 0; font-size: 0.8rem; color: var(--color-text-muted); }

.stat-details,
.summary {
    display: flex;
    flex-wrap: wrap;
    gap: 0.35rem 1.25rem;
    margin: 0.35rem 0 0;
}

.stat-details dt,
.summary dt { font-size: 0.72rem; color: var(--color-text-muted); }
.stat-details dd,
.summary dd { margin: 0; font-size: 0.86rem; font-weight: 700; }

.summary { padding-top: 0.55rem; border-top: 1px solid var(--color-border); }
.summary.is-total { justify-content: flex-end; text-align: right; }

.stat-trend { margin-top: auto; padding-top: 0.4rem; }

.widget-empty,
.widget-note {
    display: flex;
    align-items: flex-start;
    gap: 0.45rem;
    margin: 0.25rem 0;
    font-size: 0.84rem;
    color: var(--color-text-muted);
}

.widget-empty.is-good { color: var(--color-success); }
.widget-note.is-error { color: var(--color-danger); }
.widget-note svg,
.widget-empty svg { flex-shrink: 0; margin-top: 0.1rem; }

.widget-footnote { margin: 0.2rem 0 0; font-size: 0.78rem; color: var(--color-text-muted); }

.widget-list { margin: 0 -0.5rem; padding: 0; list-style: none; }
.widget-list li + li { border-top: 1px solid var(--color-border); }

.widget-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
    padding: 0.5rem;
    border-radius: var(--radius-sm);
    color: inherit;
    text-decoration: none;
}

a.widget-row:hover { background: var(--color-surface-alt); }
a.widget-row:focus-visible { outline: 2px solid var(--color-primary); outline-offset: -2px; }

.row-main { display: flex; flex-direction: column; min-width: 0; }
.row-title { font-size: 0.85rem; font-weight: 600; overflow-wrap: anywhere; }
.row-subtitle { font-size: 0.78rem; color: var(--color-text-muted); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.row-side { display: flex; flex-direction: column; align-items: flex-end; gap: 0.2rem; flex-shrink: 0; }
.row-meta { font-size: 0.76rem; color: var(--color-text-muted); white-space: nowrap; }

.widget-table { max-height: 16rem; overflow-y: auto; }
.widget-table table { font-size: 0.8rem; }
.widget-table th[scope="row"] { font-weight: 500; text-align: left; }
.num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }

/* La pista del medidor es un tono claro del mismo color que la llena. */
.meter {
    height: 8px;
    margin: 0.3rem 0 0.1rem;
    border-radius: 999px;
    background: color-mix(in srgb, var(--color-chart-1) 18%, transparent);
    overflow: hidden;
}

.meter span { display: block; height: 100%; border-radius: 999px; background: var(--color-chart-1); }
.meter.is-warning span { background: var(--color-warning); }
.meter.is-danger span { background: var(--color-danger); }
</style>
