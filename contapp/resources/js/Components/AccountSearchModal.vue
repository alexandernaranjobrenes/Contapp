<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { CheckIcon, XIcon } from '@lucide/vue';

/**
 * La ventana para elegir una cuenta contable (AccountPicker): todo el
 * catálogo de cuentas que reciben asientos, en letra chica para ver muchas a
 * la vez, filtrado mientras se escribe.
 *
 * - Los dígitos buscan por el comienzo del código, sin importar los guiones
 *   («10102» trae 1-01-02-…); después, los códigos que los contienen. Las
 *   palabras buscan en el nombre, sin tildes y en cualquier orden.
 * - La clase (Activos, Pasivos…) acota la lista.
 * - Se elige con doble clic, con Enter o con «Seleccionar» sobre la marcada.
 *   Las flechas mueven la marca sin salir del buscador.
 *
 * Se dibujan hasta MAX_ROWS filas: un catálogo grande se acota escribiendo.
 */
const props = defineProps({
    open: { type: Boolean, default: false },
    // [{ id, code, label, type }]
    options: { type: Array, required: true },
    // clase => nombre, en el orden del catálogo
    types: { type: Object, default: () => ({}) },
    modelValue: { type: [Number, String, null], default: null },
    initialQuery: { type: String, default: '' },
});

const emit = defineEmits(['select', 'close']);

const MAX_ROWS = 400;

const query = ref('');
const type = ref('');
const highlighted = ref(null);
const searchInput = ref(null);
const list = ref(null);
const dialog = ref(null);

const diacritics = /[̀-ͯ]/g;
// Letra por letra, para que las posiciones del texto plegado coincidan con
// las del original y se pueda resaltar lo encontrado.
const fold = (text) => [...(text ?? '')].map((ch) => (ch.normalize('NFD').replace(diacritics, '').toLowerCase() || ch)[0]).join('');
const digitsOf = (text) => (text ?? '').replace(/[^0-9a-z]/gi, '').toLowerCase();

const presentTypes = computed(() => {
    const present = new Set(props.options.map((o) => o.type));
    return Object.entries(props.types).filter(([key]) => present.has(key));
});

const results = computed(() => {
    const q = query.value.trim();
    const pool = type.value ? props.options.filter((o) => o.type === type.value) : props.options;

    if (!q) return pool.map((option) => ({ option, codeHit: 0, words: [] }));

    const isCode = /^[0-9\-.\s]+$/.test(q);
    const digits = digitsOf(q);
    const words = fold(q).split(/\s+/).filter(Boolean);

    // Dígitos: los códigos que empiezan así. Solo si ninguno, los que los
    // tienen en cualquier parte (alguien que recuerda el final del código).
    if (isCode) {
        const starts = pool.filter((o) => digitsOf(o.code).startsWith(digits));
        return starts.length
            ? starts.map((option) => ({ option, codeHit: digits.length, words: [] }))
            : pool.filter((o) => digitsOf(o.code).includes(digits)).map((option) => ({ option, codeHit: 0, words: [] }));
    }

    // Palabras: el nombre las tiene todas; primero los que empiezan con la
    // primera. También un código que empiece con lo escrito («CXC», «1-01»).
    const found = [];
    for (const option of pool) {
        const name = fold(option.label);
        let rank = null;

        if (digits && digitsOf(option.code).startsWith(digits)) rank = 0;
        else if (words.every((w) => name.includes(w))) rank = name.startsWith(words[0]) ? 1 : 2;

        if (rank !== null) found.push({ option, rank, codeHit: rank === 0 ? digits.length : 0, words: rank === 0 ? [] : words });
    }

    // El orden del catálogo (por código) dentro de cada grupo.
    return found.sort((a, b) => a.rank - b.rank);
});

const shown = computed(() => results.value.slice(0, MAX_ROWS));
const highlightedOption = computed(() => shown.value.find((r) => r.option.id === highlighted.value)?.option ?? null);

// El código con los primeros n dígitos (sin contar guiones) resaltados.
function codeParts(code, hit) {
    if (!hit) return [{ text: code, hit: false }];
    let count = 0;
    let end = 0;
    for (; end < code.length && count < hit; end++) {
        if (/[0-9a-z]/i.test(code[end])) count++;
    }
    return [{ text: code.slice(0, end), hit: true }, { text: code.slice(end), hit: false }];
}

// El nombre con cada palabra buscada resaltada (la primera vez que aparece).
function labelParts(label, words) {
    if (!words.length) return [{ text: label, hit: false }];
    const folded = fold(label);
    const marks = new Array(label.length).fill(false);
    for (const w of words) {
        const at = folded.indexOf(w);
        if (at >= 0) for (let i = at; i < at + w.length; i++) marks[i] = true;
    }
    const parts = [];
    for (let i = 0; i < label.length; i++) {
        const last = parts[parts.length - 1];
        if (last && last.hit === marks[i]) last.text += label[i];
        else parts.push({ text: label[i], hit: marks[i] });
    }
    return parts;
}

function scrollToHighlighted(block = 'nearest') {
    nextTick(() => {
        list.value?.querySelector(`[data-id="${highlighted.value}"]`)?.scrollIntoView({ block });
    });
}

let returnFocus = null;

watch(() => props.open, async (open) => {
    if (!open) {
        if (returnFocus?.isConnected) returnFocus.focus();
        returnFocus = null;
        return;
    }

    returnFocus = document.activeElement;
    type.value = '';
    query.value = props.initialQuery;

    // Después de que el cambio de búsqueda marque la primera: sin búsqueda,
    // la marca empieza en la cuenta que ya tiene la línea.
    await nextTick();
    if (!props.initialQuery && props.modelValue != null && props.options.some((o) => o.id === props.modelValue)) {
        highlighted.value = props.modelValue;
    } else {
        highlighted.value = results.value[0]?.option.id ?? null;
    }

    await nextTick();
    searchInput.value?.focus();
    const end = query.value.length;
    searchInput.value?.setSelectionRange(end, end);
    scrollToHighlighted('center');
});

// Lo que todavía se escribe en el campo de la línea, mientras la ventana no
// tomó el foco.
watch(() => props.initialQuery, (text) => {
    if (props.open && document.activeElement !== searchInput.value) query.value = text;
});

watch([query, type], () => {
    if (!props.open) return;
    highlighted.value = results.value[0]?.option.id ?? null;
    if (list.value) list.value.scrollTop = 0;
});

function move(step) {
    if (!shown.value.length) return;
    const index = shown.value.findIndex((r) => r.option.id === highlighted.value);
    const next = Math.max(0, Math.min(shown.value.length - 1, (index < 0 ? -1 : index) + step));
    highlighted.value = shown.value[next].option.id;
    scrollToHighlighted();
}

function choose(option = highlightedOption.value) {
    if (option) emit('select', option.id);
}

function onSearchKeydown(event) {
    const steps = { ArrowDown: 1, ArrowUp: -1, PageDown: 12, PageUp: -12 };
    if (event.key in steps) {
        event.preventDefault();
        move(steps[event.key]);
    } else if (event.key === 'Enter') {
        event.preventDefault();
        choose();
    }
}

function onKeydown(event) {
    if (event.key === 'Escape') {
        // Solo esta ventana: el mayor auxiliar u otro panel abierto detrás sigue.
        event.preventDefault();
        event.stopPropagation();
        emit('close');
        return;
    }

    // El foco no sale de la ventana: Tab da la vuelta.
    if (event.key !== 'Tab' || !dialog.value) return;
    const focusable = [...dialog.value.querySelectorAll('input, button:not([disabled])')];
    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
    }
}

const countText = computed(() => {
    const total = results.value.length;
    if (!total) return 'Ninguna cuenta coincide.';
    const noun = total === 1 ? 'cuenta' : 'cuentas';
    return total > MAX_ROWS
        ? `Se ven las primeras ${MAX_ROWS} de ${total.toLocaleString('es-CR')} ${noun}: escribí más para acotar.`
        : `${total.toLocaleString('es-CR')} ${noun}`;
});
</script>

<template>
    <Teleport to="body">
        <div v-if="open" class="account-search-backdrop" @mousedown.self="emit('close')" @keydown="onKeydown">
            <section ref="dialog" class="account-search" role="dialog" aria-modal="true" aria-labelledby="account-search-title">
                <header class="as-head">
                    <h2 id="account-search-title">Buscar cuenta</h2>
                    <button type="button" class="btn btn-ghost as-close" aria-label="Cerrar" title="Cerrar" @click="emit('close')"><XIcon /></button>
                </header>

                <div class="as-tools">
                    <label class="as-search">
                        <span class="sr-only">Código o nombre de la cuenta</span>
                        <input
                            ref="searchInput"
                            v-model="query"
                            type="search"
                            placeholder="Código o nombre de la cuenta…"
                            autocomplete="off"
                            role="combobox"
                            aria-expanded="true"
                            aria-controls="account-search-list"
                            :aria-activedescendant="highlighted != null ? `account-option-${highlighted}` : undefined"
                            @keydown="onSearchKeydown"
                        >
                    </label>

                    <div v-if="presentTypes.length > 1" class="as-types" role="group" aria-label="Clase de cuenta">
                        <button type="button" class="as-chip" :aria-pressed="type === ''" @click="type = ''">Todas</button>
                        <button
                            v-for="[key, label] in presentTypes"
                            :key="key"
                            type="button"
                            class="as-chip"
                            :aria-pressed="type === key"
                            @click="type = type === key ? '' : key"
                        >{{ label }}</button>
                    </div>

                    <p class="as-count" aria-live="polite">{{ countText }}</p>
                </div>

                <div class="as-columns" aria-hidden="true">
                    <span>Código</span>
                    <span>Nombre</span>
                    <span class="as-col-type">Clase</span>
                </div>

                <ul id="account-search-list" ref="list" class="as-list" role="listbox" aria-label="Cuentas">
                    <li
                        v-for="(row, i) in shown"
                        :id="`account-option-${row.option.id}`"
                        :key="row.option.id"
                        :data-id="row.option.id"
                        role="option"
                        class="as-row"
                        :class="{ 'is-odd': i % 2, 'is-highlighted': row.option.id === highlighted }"
                        :aria-selected="row.option.id === highlighted"
                        @click="highlighted = row.option.id"
                        @dblclick="choose(row.option)"
                    >
                        <span class="as-code"><template v-for="(part, p) in codeParts(row.option.code, row.codeHit)" :key="p"><mark v-if="part.hit">{{ part.text }}</mark><template v-else>{{ part.text }}</template></template></span>
                        <span class="as-label"><template v-for="(part, p) in labelParts(row.option.label, row.words)" :key="p"><mark v-if="part.hit">{{ part.text }}</mark><template v-else>{{ part.text }}</template></template></span>
                        <span class="as-col-type">{{ types[row.option.type] ?? '' }}</span>
                    </li>
                </ul>

                <footer class="as-foot">
                    <p class="as-choice">
                        <template v-if="highlightedOption">
                            <strong>{{ highlightedOption.code }}</strong> {{ highlightedOption.label }}
                        </template>
                        <span v-else class="muted">Marcá una cuenta de la lista.</span>
                    </p>
                    <span class="as-hint">Doble clic o Enter para elegir</span>
                    <div class="as-actions">
                        <button type="button" class="btn btn-ghost" @click="emit('close')">Cancelar</button>
                        <button type="button" class="btn btn-primary" :disabled="!highlightedOption" @click="choose()"><CheckIcon /> Seleccionar</button>
                    </div>
                </footer>
            </section>
        </div>
    </Teleport>
</template>

<style scoped>
/* Encima de la pantalla del asiento y de sus paneles; debajo de los avisos. */
.account-search-backdrop {
    position: fixed;
    inset: 0;
    z-index: 70;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 1rem;
    background: rgba(0, 0, 0, 0.4);
}

.account-search {
    display: flex;
    flex-direction: column;
    width: min(62rem, 100%);
    height: min(46rem, calc(100dvh - 2rem));
    overflow: hidden;
    border-radius: var(--radius-md);
    background: var(--color-surface);
    box-shadow: 0 24px 64px rgba(0, 0, 0, 0.3);
}

.as-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
    padding: 0.85rem 1rem 0.4rem;
}

.as-head h2 { margin: 0; font-size: 1rem; }
.as-close { min-height: 2rem; width: 2rem; padding: 0; justify-content: center; }

.as-tools {
    display: grid;
    gap: 0.55rem;
    padding: 0 1rem 0.6rem;
}

/* La lupa la dibuja el campo de búsqueda (app.scss). */
.as-search { display: block; }
.as-search input { width: 100%; }

.as-types { display: flex; flex-wrap: wrap; gap: 0.35rem; }

/* Un control hecho a medida: la altura mínima de los campos (CLAUDE.md secc. 26). */
.as-chip {
    min-height: 2.25rem;
    padding: 0 0.75rem;
    border: 1px solid var(--color-border);
    border-radius: 999px;
    background: var(--color-surface);
    color: var(--color-text-muted);
    font: inherit;
    font-size: 0.76rem;
    cursor: pointer;
}

.as-chip:hover { border-color: var(--color-control-border); color: var(--color-text); }
.as-chip[aria-pressed="true"] { border-color: var(--color-primary); background: var(--color-primary); color: var(--color-on-primary); }
.as-chip:focus-visible { outline: 2px solid var(--color-primary); outline-offset: 2px; }

.as-count { margin: 0; font-size: 0.74rem; color: var(--color-text-muted); }

/* La lista, en letra chica: más cuentas a la vista. Código, nombre y clase en
   columnas fijas, para leer de un vistazo. */
.as-columns,
.as-row {
    display: grid;
    grid-template-columns: 9.5rem minmax(0, 1fr) 8.5rem;
    gap: 0.75rem;
    align-items: center;
}

.as-columns {
    padding: 0.35rem 1rem;
    border-top: 1px solid var(--color-border);
    border-bottom: 1px solid var(--color-border);
    background: var(--color-surface-alt);
    font-size: 0.68rem;
    font-weight: 700;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    color: var(--color-text-muted);
}

.as-list {
    flex: 1;
    min-height: 0;
    margin: 0;
    padding: 0.2rem 0;
    overflow-y: auto;
    list-style: none;
}

.as-row {
    min-height: 1.6rem;
    padding: 0.18rem 1rem;
    border-left: 3px solid transparent;
    font-size: 0.76rem;
    line-height: 1.3;
    cursor: pointer;
    user-select: none;
}

.as-row.is-odd { background: color-mix(in srgb, var(--color-surface-alt) 55%, transparent); }
.as-row:hover { background: color-mix(in srgb, var(--color-primary-soft) 70%, transparent); }
.as-row.is-highlighted { border-left-color: var(--color-primary); background: var(--color-primary-soft); }

.as-code { font-weight: 700; font-variant-numeric: tabular-nums; white-space: nowrap; }
.as-label { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.as-col-type { color: var(--color-text-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

mark {
    padding: 0;
    border-radius: 2px;
    background: color-mix(in srgb, var(--color-accent) 35%, transparent);
    color: inherit;
}

.as-foot {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.5rem 1rem;
    padding: 0.7rem 1rem;
    border-top: 1px solid var(--color-border);
}

.as-choice {
    flex: 1;
    min-width: 12rem;
    margin: 0;
    font-size: 0.8rem;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.as-hint { font-size: 0.72rem; color: var(--color-text-muted); }
.as-actions { display: flex; gap: 0.5rem; }

/* En un teléfono: a pantalla completa, sin la columna de la clase, y los
   botones a lo ancho. */
@media (max-width: 640px) {
    .account-search-backdrop { padding: 0; }
    .account-search { width: 100%; height: 100dvh; border-radius: 0; }
    .as-columns,
    .as-row { grid-template-columns: 7.5rem minmax(0, 1fr); }
    .as-col-type { display: none; }
    .as-row { min-height: 2.2rem; }
    .as-hint { display: none; }
    .as-actions { width: 100%; }
    .as-actions .btn { flex: 1; justify-content: center; }
}
</style>
