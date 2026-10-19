<script setup>
/**
 * El código CAByS, elegido de una lista con el catálogo oficial completo
 * (CAByS 2025 del BCCR, unos 20 000 códigos: CabysCatalog en el servidor).
 *
 * Se comporta como un select: cerrado muestra el código y su descripción;
 * abierto, un buscador arriba (por código o por palabras de la descripción) y
 * la lista agrupada por sección, que se va cargando de a 100 al bajar.
 *
 * Al elegir se emite `pick` con la entrada (código, descripción, tarifa de
 * IVA, servicio o mercancía), para que la pantalla precargue lo que quiera.
 * Un código guardado que no está en el catálogo se muestra con el aviso de
 * que Hacienda lo rechaza.
 */
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { ChevronDownIcon, SearchIcon } from '@lucide/vue';

const props = defineProps({
    modelValue: { type: String, default: '' },
    id: { type: String, default: null },
    required: { type: Boolean, default: false },
    placeholder: { type: String, default: '— Elegir del catálogo CAByS —' },
});

const emit = defineEmits(['update:modelValue', 'pick']);

// Lo ya consultado en esta visita: varias líneas con el mismo artículo
// preguntan una sola vez.
const lookups = (window.__cabysLookups ??= new Map());

const open = ref(false);
const query = ref('');
const results = ref([]);
const total = ref(0);
const loading = ref(false);
const failed = ref(false);
const highlighted = ref(0);
const trigger = ref(null);
const panel = ref(null);
const searchInput = ref(null);
const list = ref(null);
const panelStyle = ref({});
const info = ref(null); // { exists, entry } | { unavailable: true }

let searchTimer = null;
let seq = 0;

const taxLabel = (tax) => (tax === 'exento' ? 'Exento de IVA' : tax === 'na' ? 'Sin IVA' : `IVA ${tax} %`);

// --- Lo elegido ---

watch(() => props.modelValue, (code) => describe(code), { immediate: true });

async function describe(code) {
    if (! /^\d{13}$/.test(code ?? '')) {
        info.value = null;
        return;
    }

    if (lookups.has(code)) {
        info.value = lookups.get(code);
        return;
    }

    try {
        const data = await getJson({ code });
        const value = data.available ? { exists: data.exists, entry: data.entry } : { unavailable: true };
        if (data.available) lookups.set(code, value);
        if (props.modelValue === code) info.value = value;
    } catch {
        if (props.modelValue === code) info.value = { unavailable: true };
    }
}

const selectedLabel = computed(() => {
    if (! props.modelValue) return '';
    const description = info.value?.exists ? info.value.entry.description : null;
    return description ? `${props.modelValue} — ${description}` : props.modelValue;
});

// --- La lista ---

async function getJson(params) {
    const res = await fetch(route('hacienda.cabys', params), {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
    });
    if (res.redirected || ! res.ok) throw new Error(`HTTP ${res.status}`);

    return res.json();
}

async function load(reset) {
    const mine = ++seq;
    loading.value = true;
    failed.value = false;

    try {
        const data = await getJson({ q: query.value.trim(), offset: reset ? 0 : results.value.length });
        if (mine !== seq) return;
        results.value = reset ? data.results : [...results.value, ...data.results];
        total.value = data.total;
        if (reset) {
            highlighted.value = Math.max(0, results.value.findIndex((r) => r.code === props.modelValue));
            nextTick(() => { if (list.value) list.value.scrollTop = 0; });
        }
    } catch {
        if (mine === seq) failed.value = true;
    } finally {
        if (mine === seq) loading.value = false;
    }
}

function openPanel() {
    if (open.value) return;
    open.value = true;
    query.value = '';
    position();
    load(true);
    nextTick(() => searchInput.value?.focus());
}

function close() {
    open.value = false;
    clearTimeout(searchTimer);
}

function position() {
    if (! trigger.value) return;
    const rect = trigger.value.getBoundingClientRect();
    const width = Math.min(Math.max(rect.width, 480), window.innerWidth - 16);
    const left = Math.min(rect.left, window.innerWidth - width - 8);
    const below = window.innerHeight - rect.bottom;
    const upwards = below < 320 && rect.top > below;

    panelStyle.value = {
        position: 'fixed',
        left: `${Math.max(8, left)}px`,
        width: `${width}px`,
        ...(upwards ? { bottom: `${window.innerHeight - rect.top + 2}px` } : { top: `${rect.bottom + 2}px` }),
    };
}

function onQuery(e) {
    query.value = e.target.value;
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => load(true), 250);
}

function onListScroll() {
    const el = list.value;
    if (! el || loading.value || results.value.length >= total.value) return;
    if (el.scrollTop + el.clientHeight >= el.scrollHeight - 120) load(false);
}

function choose(entry) {
    lookups.set(entry.code, { exists: true, entry });
    info.value = { exists: true, entry };
    emit('update:modelValue', entry.code);
    emit('pick', entry);
    close();
    trigger.value?.focus();
}

function onKeydown(e) {
    if (e.key === 'ArrowDown') {
        highlighted.value = Math.min(highlighted.value + 1, results.value.length - 1);
        scrollToHighlighted();
        e.preventDefault();
    } else if (e.key === 'ArrowUp') {
        highlighted.value = Math.max(highlighted.value - 1, 0);
        scrollToHighlighted();
        e.preventDefault();
    } else if (e.key === 'Enter') {
        e.preventDefault();
        if (results.value[highlighted.value]) choose(results.value[highlighted.value]);
    } else if (e.key === 'Escape' || e.key === 'Tab') {
        close();
    }
}

function scrollToHighlighted() {
    nextTick(() => list.value?.querySelector('.cabys-option.highlighted')?.scrollIntoView({ block: 'nearest' }));
}

// Se cierra al tocar afuera o al desplazar la página (la lista se ubica a
// mano, sobre todo lo demás).
function onPointerDown(e) {
    if (open.value && ! panel.value?.contains(e.target) && ! trigger.value?.contains(e.target)) close();
}

function onScroll(e) {
    if (open.value && ! panel.value?.contains(e.target)) close();
}

onMounted(() => {
    document.addEventListener('mousedown', onPointerDown, true);
    window.addEventListener('scroll', onScroll, true);
    window.addEventListener('resize', close);
});

onBeforeUnmount(() => {
    document.removeEventListener('mousedown', onPointerDown, true);
    window.removeEventListener('scroll', onScroll, true);
    window.removeEventListener('resize', close);
    clearTimeout(searchTimer);
});
</script>

<template>
    <div class="cabys-picker">
        <button
            :id="id"
            ref="trigger"
            type="button"
            class="cabys-trigger"
            :class="{ empty: !modelValue, open }"
            :title="selectedLabel"
            aria-haspopup="listbox"
            :aria-expanded="open"
            @click="open ? close() : openPanel()"
            @keydown.down.prevent="openPanel"
        >
            <span class="cabys-value">{{ selectedLabel || placeholder }}</span>
            <ChevronDownIcon class="cabys-chevron" />
        </button>
        <!-- Para que «obligatorio» funcione como en un select del formulario. -->
        <input class="cabys-validator" :value="modelValue" :required="required" tabindex="-1" aria-hidden="true" @focus="openPanel">

        <Teleport to="body">
            <div v-if="open" ref="panel" class="cabys-panel" :style="panelStyle">
                <div class="cabys-search">
                    <SearchIcon />
                    <input
                        ref="searchInput"
                        type="text"
                        :value="query"
                        placeholder="Buscar por código o descripción"
                        autocomplete="off"
                        @input="onQuery"
                        @keydown="onKeydown"
                    >
                </div>
                <div ref="list" class="cabys-list" role="listbox" @scroll="onListScroll">
                    <template v-for="(r, i) in results" :key="r.code">
                        <div v-if="i === 0 || results[i - 1].section !== r.section" class="cabys-section">{{ r.code[0] }} · {{ r.section }}</div>
                        <button
                            type="button"
                            role="option"
                            class="cabys-option"
                            :class="{ highlighted: i === highlighted, selected: r.code === modelValue }"
                            :aria-selected="r.code === modelValue"
                            @mousedown.prevent="choose(r)"
                            @mouseenter="highlighted = i"
                        >
                            <span class="opt-code">{{ r.code }}</span>
                            <span class="opt-label">{{ r.description }}</span>
                            <span class="opt-meta">{{ r.is_service ? 'Servicio' : 'Mercancía' }} · {{ taxLabel(r.tax) }}</span>
                        </button>
                    </template>
                    <div v-if="loading" class="cabys-empty">Cargando…</div>
                    <div v-else-if="failed" class="cabys-empty">No se pudo cargar el catálogo. Probá de nuevo.</div>
                    <div v-else-if="!results.length" class="cabys-empty">Nada en el catálogo CAByS con ese texto.</div>
                </div>
                <div class="cabys-footer">
                    {{ results.length.toLocaleString('es-CR') }} de {{ total.toLocaleString('es-CR') }} códigos
                    {{ query.trim() ? 'que coinciden' : 'del catálogo CAByS 2025' }}
                </div>
            </div>
        </Teleport>

        <div v-if="info?.exists" class="cabys-caption">
            {{ info.entry.is_service ? 'Servicio' : 'Mercancía' }} · {{ taxLabel(info.entry.tax) }}
        </div>
        <div v-else-if="info && info.exists === false" class="cabys-caption warn">
            No está en el catálogo CAByS de Hacienda: un comprobante con este código se rechaza. Elegí otro de la lista.
        </div>
        <div v-else-if="info?.unavailable" class="cabys-caption">No se pudo revisar el código con Hacienda.</div>
    </div>
</template>

<style scoped>
.cabys-picker { position: relative; }

/* Con el aspecto de un select del formulario (app.scss). */
.cabys-trigger {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    width: 100%;
    min-height: 2.25rem;
    padding: 0.4rem 0.65rem;
    background-color: var(--color-surface);
    color: var(--color-text);
    border: 1px solid var(--color-control-border);
    border-radius: var(--radius-sm);
    font-family: inherit;
    font-size: 0.86rem;
    line-height: 1.3;
    text-align: left;
    cursor: pointer;
}

.cabys-trigger.empty .cabys-value { color: var(--color-text-muted); }
.cabys-trigger:focus-visible, .cabys-trigger.open { outline: 2px solid var(--color-primary); outline-offset: 1px; }
.cabys-value { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-variant-numeric: tabular-nums; }
.cabys-chevron { width: 1rem; height: 1rem; flex-shrink: 0; color: var(--color-text-muted); }

.cabys-validator {
    position: absolute;
    left: 0;
    top: 2rem;
    width: 100%;
    height: 1px;
    min-height: 0;
    padding: 0;
    border: 0;
    opacity: 0;
    pointer-events: none;
}

.cabys-caption {
    font-size: 0.72rem;
    color: var(--color-text-muted);
    margin-top: 0.15rem;
}

.cabys-caption.warn { color: var(--color-danger); }

.cabys-panel {
    z-index: 300;
    display: flex;
    flex-direction: column;
    max-height: 22rem;
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    box-shadow: var(--shadow-md);
}

.cabys-search {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.45rem 0.6rem;
    border-bottom: 1px solid var(--color-border);
}

.cabys-search svg { width: 1rem; height: 1rem; color: var(--color-text-muted); flex-shrink: 0; }
.cabys-search input { flex: 1; min-width: 0; border: none; outline: none; background: transparent; padding: 0.2rem 0; }

.cabys-list { flex: 1; overflow-y: auto; }

.cabys-section {
    position: sticky;
    top: 0;
    padding: 0.3rem 0.6rem;
    font-size: 0.7rem;
    font-weight: 600;
    color: var(--color-text-muted);
    background: var(--color-surface-alt);
    text-transform: uppercase;
    letter-spacing: 0.02em;
}

.cabys-option {
    display: grid;
    grid-template-columns: auto 1fr;
    column-gap: 0.5rem;
    width: 100%;
    text-align: left;
    background: none;
    border: none;
    padding: 0.4rem 0.6rem;
    font-size: 0.82rem;
    color: var(--color-text);
    cursor: pointer;
}

.cabys-option.highlighted { background: var(--color-primary-soft); }
.cabys-option.selected .opt-code { color: var(--color-primary); }
.opt-code { font-weight: 700; font-variant-numeric: tabular-nums; }
.opt-meta { grid-column: 2; font-size: 0.72rem; color: var(--color-text-muted); }

.cabys-empty { padding: 0.6rem; font-size: 0.8rem; color: var(--color-text-muted); text-align: center; }

.cabys-footer {
    padding: 0.3rem 0.6rem;
    font-size: 0.7rem;
    color: var(--color-text-muted);
    border-top: 1px solid var(--color-border);
}
</style>
