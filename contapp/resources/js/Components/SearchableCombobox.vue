<script setup>
import { ref, computed, nextTick, onMounted, onBeforeUnmount } from 'vue';

// Combobox de búsqueda genérico (cuentas contables, socios de negocio, o
// cualquier catálogo código+nombre): a diferencia de un <select> o un
// <datalist> nativo, permite buscar por CÓDIGO ignorando guiones (tipear
// "101010101001" encuentra "1-01-01-01-001") o por NOMBRE en cualquier
// posición, y siempre deja visible el nombre completo de lo seleccionado
// debajo del campo — no hace falta pasar el mouse por encima ni que el
// campo sea ancho para saber qué cuenta/socio es.
const props = defineProps({
    options: { type: Array, required: true }, // [{ id, code, label }]
    modelValue: { type: [Number, String, null], default: null },
    placeholder: { type: String, default: 'Código o nombre...' },
    required: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue']);

const query = ref('');
const isOpen = ref(false);
const highlightedIndex = ref(0);
const inputEl = ref(null);
const dropdownStyle = ref({});

function normalize(s) {
    const diacritics = new RegExp('[\u0300-\u036f]', 'g');
    return (s ?? '').toString().toLowerCase().normalize('NFD').replace(diacritics, '').trim();
}

function stripSeparators(s) {
    return (s ?? '').toString().toLowerCase().replace(/[-\s]/g, '');
}

const selected = computed(() => props.options.find((o) => o.id === props.modelValue) ?? null);

const filtered = computed(() => {
    const q = query.value.trim();
    if (! q) return props.options.slice(0, 50);

    const qStripped = stripSeparators(q);
    const qNorm = normalize(q);

    return props.options
        .filter((o) => stripSeparators(o.code).includes(qStripped) || normalize(o.label).includes(qNorm))
        .slice(0, 50);
});

const displayValue = computed(() => (isOpen.value ? query.value : (selected.value?.code ?? '')));

function updateDropdownPosition() {
    if (! inputEl.value) return;
    const rect = inputEl.value.getBoundingClientRect();

    dropdownStyle.value = {
        position: 'fixed',
        top: `${rect.bottom + 2}px`,
        left: `${rect.left}px`,
        width: `${Math.max(rect.width, 260)}px`,
    };
}

function openDropdown() {
    isOpen.value = true;
    query.value = '';
    highlightedIndex.value = 0;
    nextTick(() => {
        updateDropdownPosition();
        inputEl.value?.select();
    });
}

function onInput(e) {
    if (! isOpen.value) isOpen.value = true;
    query.value = e.target.value;
    highlightedIndex.value = 0;
}

function choose(option) {
    emit('update:modelValue', option.id);
    isOpen.value = false;
    query.value = '';
}

function onKeydown(e) {
    if (! isOpen.value) {
        if (e.key === 'ArrowDown' || e.key === 'Enter') {
            e.preventDefault();
            openDropdown();
        }
        return;
    }

    if (e.key === 'ArrowDown') {
        highlightedIndex.value = Math.min(highlightedIndex.value + 1, filtered.value.length - 1);
        e.preventDefault();
    } else if (e.key === 'ArrowUp') {
        highlightedIndex.value = Math.max(highlightedIndex.value - 1, 0);
        e.preventDefault();
    } else if (e.key === 'Enter') {
        e.preventDefault();
        const opt = filtered.value[highlightedIndex.value];
        if (opt) choose(opt);
    } else if (e.key === 'Escape') {
        isOpen.value = false;
        query.value = '';
        inputEl.value?.blur();
    }
}

function onBlur() {
    isOpen.value = false;
    query.value = '';
}

// El dropdown se teletransporta a <body> (ver template) para no quedar
// recortado por el overflow:hidden de las celdas de la tabla de líneas —
// por eso su posición se calcula a mano y se cierra en cualquier scroll
// (capture:true agarra el scroll de la tabla horizontal, no solo el de la
// ventana) en vez de intentar re-posicionarlo en vivo.
function onScroll() {
    if (isOpen.value) isOpen.value = false;
}

onMounted(() => window.addEventListener('scroll', onScroll, true));
onBeforeUnmount(() => window.removeEventListener('scroll', onScroll, true));
</script>

<template>
    <div class="combobox">
        <input
            ref="inputEl"
            type="text"
            :value="displayValue"
            :placeholder="placeholder"
            :required="required && !modelValue"
            autocomplete="off"
            @focus="openDropdown"
            @input="onInput"
            @keydown="onKeydown"
            @blur="onBlur"
        >
        <Teleport to="body">
            <div v-if="isOpen" class="combobox-list" :style="dropdownStyle">
                <button
                    v-for="(opt, i) in filtered"
                    :key="opt.id"
                    type="button"
                    class="combobox-option"
                    :class="{ highlighted: i === highlightedIndex }"
                    @mousedown.prevent="choose(opt)"
                    @mouseenter="highlightedIndex = i"
                >
                    <span class="opt-code">{{ opt.code }}</span>
                    <span class="opt-label">{{ opt.label }}</span>
                </button>
                <div v-if="!filtered.length" class="combobox-empty">Sin resultados.</div>
            </div>
        </Teleport>
        <div v-if="selected" class="combobox-caption" :title="selected.label">{{ selected.label }}</div>
    </div>
</template>

<style scoped>
.combobox {
    position: relative;
}

.combobox input {
    width: 100%;
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    padding: 0.35rem 0.5rem;
    font-size: 0.85rem;
    color: var(--color-text);
}

.combobox-caption {
    font-size: 0.72rem;
    color: var(--color-text-muted);
    margin-top: 0.15rem;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.combobox-list {
    z-index: 200;
    max-height: 260px;
    overflow-y: auto;
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    box-shadow: var(--shadow-md);
}

.combobox-option {
    display: flex;
    align-items: baseline;
    gap: 0.5rem;
    width: 100%;
    text-align: left;
    background: none;
    border: none;
    padding: 0.4rem 0.6rem;
    font-size: 0.82rem;
    color: var(--color-text);
    cursor: pointer;
}

.combobox-option.highlighted, .combobox-option:hover {
    background: var(--color-primary-soft);
}

.opt-code {
    font-weight: 700;
    flex-shrink: 0;
    font-variant-numeric: tabular-nums;
}

.opt-label {
    color: var(--color-text-muted);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.combobox-empty {
    padding: 0.6rem;
    font-size: 0.8rem;
    color: var(--color-text-muted);
    text-align: center;
}
</style>
