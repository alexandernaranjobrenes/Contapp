<script setup>
import { computed, ref, watch } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { BotIcon, ChevronDownIcon, HistoryIcon } from '@lucide/vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import UserAvatar from '../../Components/UserAvatar.vue';
import { fullDate, timeAgo } from '../../Utils/relativeTime';
import { requestJson } from '../../Utils/http';

/**
 * Administración → Bitácora (CLAUDE.md secc. 35): lo que se hizo en la
 * compañía y quién lo hizo, de lo más nuevo a lo más viejo.
 *
 * Llega con las primeras 15; «Ver más» pide las 15 siguientes, a partir de
 * la última que ya está en pantalla (ActivityLogController::more), con los
 * mismos filtros. Cada movimiento se despliega con lo que cambió: antes y
 * después.
 */
const props = defineProps({
    log: { type: Object, required: true }, // { entries, has_more }
    pageSize: { type: Number, default: 15 },
    filters: { type: Object, default: () => ({}) }, // { from, to, document, user, search }: los aplicados
    // Solo lo que la persona puede ver, por sección: [{ label, options: [{ value, label }] }]
    documents: { type: Array, default: () => [] },
    // Las personas que aparecen en lo que ve, y «Sistema»: [{ value, label }]
    people: { type: Array, default: () => [] },
});

const page = usePage();

const entries = ref([...props.log.entries]);
const hasMore = ref(props.log.has_more);
const loading = ref(false);
const error = ref('');
const open = ref({});

// ── Filtros ──
const blank = { from: '', to: '', document: '', user: '', search: '' };
const fromProps = (filters) => Object.fromEntries(Object.keys(blank).map((key) => [key, filters?.[key] ?? '']));
const form = ref(fromProps(props.filters));
const consulting = ref(false);

// Los que están aplicados (los de la dirección), para «Ver más».
const applied = computed(() => Object.fromEntries(
    Object.entries(props.filters ?? {}).filter(([, value]) => value !== null && value !== ''),
));
const filtering = computed(() => Object.keys(applied.value).length > 0);
const filterErrors = computed(() => Object.values(page.props.errors ?? {}));

function visit(params) {
    router.get(route('activity-log.index'), params, {
        preserveState: true,
        preserveScroll: true,
        onStart: () => { consulting.value = true; },
        onFinish: () => { consulting.value = false; },
    });
}

function consult() {
    visit(Object.fromEntries(Object.entries(form.value).filter(([, value]) => String(value ?? '').trim() !== '')));
}

function clearFilters() {
    form.value = { ...blank };
    visit({});
}

// Al consultar llega otra primera página: reemplaza a la que había.
watch(() => props.log, (log) => {
    entries.value = [...log.entries];
    hasMore.value = log.has_more;
    open.value = {};
    error.value = '';
});

// Lo aplicado manda (por ejemplo, unas fechas al revés vuelven ordenadas).
watch(() => props.filters, (filters) => { form.value = fromProps(filters); });

async function loadMore() {
    const last = entries.value.at(-1);
    if (! last || loading.value) return;

    loading.value = true;
    error.value = '';
    const params = new URLSearchParams({ ...applied.value, before: last.id });
    const result = await requestJson(`${route('activity-log.more')}?${params}`);
    loading.value = false;

    if (! result.ok) {
        error.value = result.data?.message ?? result.message;
        return;
    }

    entries.value.push(...result.data.entries);
    hasMore.value = result.data.has_more;
}

function toggle(id) {
    open.value = { ...open.value, [id]: ! open.value[id] };
}

// En un alta o una baja no hay «antes y después»: una sola columna con el valor.
const single = (action) => action === 'created' || action === 'deleted';
const valueOf = (field, action) => (action === 'deleted' ? field.old : field.new) ?? '—';
</script>

<template>
    <Head title="Bitácora" />

    <AppLayout title="Bitácora">
        <div class="view-toolbar">
            <form class="view-filters" @submit.prevent="consult">
                <label class="filter-field">
                    <span>Desde</span>
                    <input v-model="form.from" type="date" :max="form.to || undefined">
                </label>
                <label class="filter-field">
                    <span>Hasta</span>
                    <input v-model="form.to" type="date" :min="form.from || undefined">
                </label>
                <label class="filter-field">
                    <span>Documento</span>
                    <select v-model="form.document">
                        <option value="">Todos</option>
                        <optgroup v-for="group in documents" :key="group.label" :label="group.label">
                            <option v-for="option in group.options" :key="option.value" :value="option.value">{{ option.label }}</option>
                        </optgroup>
                    </select>
                </label>
                <label class="filter-field">
                    <span>Usuario</span>
                    <select v-model="form.user">
                        <option value="">Todos</option>
                        <option v-for="person in people" :key="person.value" :value="person.value">{{ person.label }}</option>
                    </select>
                </label>
                <label class="filter-field search-field">
                    <span>Número, código o nombre</span>
                    <input v-model="form.search" type="search" maxlength="100" placeholder="Ej. AS-0005, CLI-001 o un nombre">
                </label>
                <button type="submit" class="btn btn-primary" :disabled="consulting" :data-busy="consulting ? '' : null" :aria-busy="consulting">
                    Consultar
                </button>
                <button v-if="filtering" type="button" class="btn btn-ghost" :disabled="consulting" @click="clearFilters">Limpiar</button>
            </form>
        </div>

        <p v-if="filterErrors.length" class="flash flash-error" role="alert">{{ filterErrors.join(' ') }}</p>

        <p class="hint intro">
            Todo lo que se hizo en la compañía y quién lo hizo, de lo más reciente a lo más antiguo. «Sistema» es lo que hicieron los
            procesos automáticos. Cada movimiento se despliega con lo que cambió. Las contraseñas y claves nunca se guardan; las cuentas bancarias, solo con sus
            últimos cuatro dígitos.
        </p>

        <div class="card log">
            <p v-if="! entries.length" class="muted empty">
                {{ filtering ? 'No hay movimientos con esos filtros.' : 'Todavía no hay movimientos registrados.' }}
            </p>

            <ol v-else class="timeline">
                <li v-for="entry in entries" :key="entry.id" class="event">
                    <span v-if="entry.actor.system" class="system-avatar" aria-hidden="true"><BotIcon /></span>
                    <UserAvatar v-else :url="entry.actor.photo_url" :name="entry.actor.name" :size="2.25" />

                    <div class="event-body">
                        <p class="event-line">
                            <strong>{{ entry.actor.name }}</strong> {{ entry.sentence }}
                        </p>
                        <p class="event-meta">
                            <time :datetime="entry.at" :title="fullDate(entry.at)">{{ timeAgo(entry.at) }}</time>
                            <template v-if="entry.section"> · <span>{{ entry.section }}</span></template>
                            <template v-if="entry.ip"> · <span class="ip">IP {{ entry.ip }}</span></template>
                        </p>

                        <button
                            v-if="entry.groups.length"
                            type="button"
                            class="details-toggle"
                            :aria-expanded="!! open[entry.id]"
                            :aria-controls="`event-${entry.id}`"
                            @click="toggle(entry.id)"
                        >
                            <ChevronDownIcon class="chevron" :class="{ 'is-open': open[entry.id] }" />
                            {{ open[entry.id] ? 'Ocultar cambios' : 'Ver cambios' }}
                        </button>

                        <div v-if="open[entry.id]" :id="`event-${entry.id}`" class="details">
                            <section v-for="(group, g) in entry.groups" :key="g" class="record">
                                <header class="record-head">
                                    <span class="record-title">{{ group.count > 1 ? `${group.count} ${group.title.toLowerCase()}` : group.title }}</span>
                                    <span v-if="group.badge" class="badge badge-neutral">{{ group.badge }}</span>
                                </header>

                                <table v-if="group.fields.length" class="fields" :class="{ 'is-single': single(group.action) }">
                                    <thead>
                                        <tr v-if="single(group.action)"><th>Campo</th><th>Valor</th></tr>
                                        <tr v-else><th>Campo</th><th>Antes</th><th>Después</th></tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="(field, f) in group.fields" :key="f">
                                            <td data-label="Campo">{{ field.label }}</td>
                                            <td v-if="single(group.action)" data-label="Valor">{{ valueOf(field, group.action) }}</td>
                                            <template v-else>
                                                <td data-label="Antes" class="old">{{ field.old ?? '—' }}</td>
                                                <td data-label="Después">{{ field.new ?? '—' }}</td>
                                            </template>
                                        </tr>
                                    </tbody>
                                </table>

                                <details v-if="group.items.length" class="lines">
                                    <summary>Ver {{ group.items.length === 1 ? 'la línea' : `las ${group.items.length}` }}</summary>
                                    <div v-for="(item, i) in group.items" :key="i" class="line">
                                        <p class="line-title">{{ item.title }}</p>
                                        <table v-if="item.fields.length" class="fields" :class="{ 'is-single': single(item.action) }">
                                            <tbody>
                                                <tr v-for="(field, f) in item.fields" :key="f">
                                                    <td data-label="Campo">{{ field.label }}</td>
                                                    <td v-if="single(item.action)" data-label="Valor">{{ valueOf(field, item.action) }}</td>
                                                    <template v-else>
                                                        <td data-label="Antes" class="old">{{ field.old ?? '—' }}</td>
                                                        <td data-label="Después">{{ field.new ?? '—' }}</td>
                                                    </template>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </details>
                            </section>
                            <p v-if="entry.omitted" class="muted small">{{ entry.omitted }}</p>
                        </div>
                    </div>
                </li>
            </ol>

            <p v-if="error" class="flash flash-error" role="alert">{{ error }}</p>

            <div v-if="hasMore" class="more">
                <button type="button" class="btn btn-ghost" :disabled="loading" :data-busy="loading ? '' : null" :aria-busy="loading" @click="loadMore">
                    <HistoryIcon /> {{ loading ? 'Cargando…' : `Ver ${pageSize} más` }}
                </button>
            </div>
            <p v-else-if="entries.length" class="muted small end">
                {{ filtering ? 'No hay más movimientos con esos filtros.' : 'No hay movimientos más antiguos.' }}
            </p>
        </div>
    </AppLayout>
</template>

<style scoped>
/* El buscador, el filtro más usado, es también el más ancho (como en app.scss). */
.search-field input { width: 15rem; max-width: 100%; }

.intro { margin: 0 0 1rem; }

.log { padding: 0.5rem 1.25rem 1rem; }

.empty { padding: 1rem 0; }

.timeline {
    list-style: none;
    margin: 0;
    padding: 0;
}

.event {
    display: flex;
    gap: 0.85rem;
    padding: 0.85rem 0;
}

.event + .event {
    border-top: 1px solid var(--color-border);
}

.system-avatar {
    display: inline-grid;
    place-items: center;
    flex-shrink: 0;
    width: 2.25rem;
    height: 2.25rem;
    border-radius: 50%;
    background: var(--color-surface-alt);
    color: var(--color-text-muted);
}

.system-avatar :deep(svg) { width: 1.1rem; height: 1.1rem; }

.event-body {
    flex: 1;
    min-width: 0;
}

.event-line {
    margin: 0;
    font-size: 0.9rem;
    line-height: 1.45;
    overflow-wrap: anywhere;
}

.event-meta {
    margin: 0.15rem 0 0;
    font-size: 0.76rem;
    color: var(--color-text-muted);
}

.ip { font-variant-numeric: tabular-nums; }

.details-toggle {
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
    margin-top: 0.35rem;
    padding: 0.15rem 0;
    border: none;
    background: none;
    color: var(--color-primary);
    font: inherit;
    font-size: 0.8rem;
    font-weight: 600;
    cursor: pointer;
}

.chevron { width: 0.95rem; height: 0.95rem; transform: rotate(-90deg); transition: transform .15s ease; }
.chevron.is-open { transform: none; }

.details {
    display: flex;
    flex-direction: column;
    gap: 0.6rem;
    margin-top: 0.5rem;
}

.record {
    padding: 0.55rem 0.7rem;
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    background: var(--color-surface-alt);
}

.record-head {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 0.4rem 0.6rem;
    margin-bottom: 0.35rem;
}

.record-title {
    font-size: 0.84rem;
    font-weight: 600;
    overflow-wrap: anywhere;
}

.fields {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.8rem;
}

.fields th {
    text-align: left;
    font-size: 0.68rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .04em;
    color: var(--color-text-muted);
    padding: 0.2rem 0.4rem;
}

.fields td {
    padding: 0.25rem 0.4rem;
    border-top: 1px solid var(--color-border);
    vertical-align: top;
    overflow-wrap: anywhere;
}

.fields td:first-child { width: 30%; color: var(--color-text-muted); }
.fields .old { color: var(--color-text-muted); text-decoration: line-through; text-decoration-color: color-mix(in srgb, var(--color-danger) 55%, transparent); }

.lines { margin-top: 0.35rem; font-size: 0.8rem; }
.lines summary { cursor: pointer; color: var(--color-primary); font-weight: 600; }
.line { margin-top: 0.45rem; }
.line-title { margin: 0 0 0.2rem; font-weight: 600; font-size: 0.8rem; }

.more {
    display: flex;
    justify-content: center;
    padding-top: 0.75rem;
    border-top: 1px solid var(--color-border);
}

.end { text-align: center; padding-top: 0.75rem; border-top: 1px solid var(--color-border); margin: 0; }

@media (max-width: 640px) {
    .search-field input { width: 100%; }

    .log { padding: 0.25rem 0.85rem 0.85rem; }

    /* Antes y después, uno debajo del otro. */
    .fields thead { display: none; }
    .fields tr { display: block; padding: 0.3rem 0; border-top: 1px solid var(--color-border); }
    .fields td { display: block; border: none; padding: 0.05rem 0.2rem; }
    .fields td:first-child { width: auto; font-weight: 600; }
    .fields td:not(:first-child)::before { content: attr(data-label) ': '; color: var(--color-text-muted); }
    .fields.is-single td:not(:first-child)::before { content: none; }
}

@media (prefers-reduced-motion: reduce) {
    .chevron { transition: none; }
}
</style>
