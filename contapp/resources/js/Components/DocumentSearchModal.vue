<script setup>
import { ref, watch, nextTick } from 'vue';

// Modal genérico de búsqueda de asientos (backend: JournalEntryController::search,
// GET /journal-entries-search?q=...) — reutilizado por dos pantallas: el botón
// "buscar" del DocumentToolbar en Show.vue (saltar a otro documento) y el
// selector "cargar desde un documento existente" en Create.vue (precargarlo
// como plantilla). El destino de lo elegido lo decide quien use este modal
// (evento "select"), este componente solo busca y lista.
const props = defineProps({
    open: { type: Boolean, default: false },
    title: { type: String, default: 'Buscar documento' },
});

const emit = defineEmits(['close', 'select']);

const query = ref('');
const results = ref([]);
const loading = ref(false);
const searched = ref(false);
const inputEl = ref(null);
let debounceTimer = null;

watch(() => props.open, (isOpen) => {
    if (isOpen) {
        query.value = '';
        results.value = [];
        searched.value = false;
        nextTick(() => inputEl.value?.focus());
    }
});

async function runSearch() {
    const q = query.value.trim();
    if (q.length < 2) {
        results.value = [];
        searched.value = false;
        return;
    }

    loading.value = true;

    try {
        const res = await fetch(`/journal-entries-search?q=${encodeURIComponent(q)}`, {
            headers: { Accept: 'application/json' },
        });
        results.value = res.ok ? await res.json() : [];
    } catch {
        results.value = [];
    } finally {
        loading.value = false;
        searched.value = true;
    }
}

function onInput() {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(runSearch, 250);
}

function choose(entry) {
    emit('select', entry);
}

function close() {
    emit('close');
}

function onKeydown(e) {
    if (e.key === 'Escape') close();
}

const statusLabels = { draft: 'Preliminar', posted: 'Contabilizado', voided: 'Anulado' };
</script>

<template>
    <Teleport to="body">
        <div v-if="open" class="search-backdrop" @click.self="close" @keydown="onKeydown">
            <div class="search-modal" role="dialog" aria-modal="true">
                <header class="search-header">
                    <strong>{{ title }}</strong>
                    <button type="button" class="btn btn-ghost close-btn" title="Cerrar" @click="close">✕</button>
                </header>

                <input
                    ref="inputEl"
                    v-model="query"
                    type="text"
                    class="search-input"
                    placeholder="Número de documento, descripción o tipo..."
                    @input="onInput"
                >

                <div class="search-results">
                    <div v-if="loading" class="search-state muted">Buscando...</div>
                    <template v-else>
                        <button
                            v-for="e in results"
                            :key="e.id"
                            type="button"
                            class="result-row"
                            @click="choose(e)"
                        >
                            <span class="result-label">{{ e.label }}</span>
                            <span class="result-desc">{{ e.description }}</span>
                            <span class="result-date muted">{{ e.posting_date }}</span>
                            <span class="result-status" :class="`status-${e.status}`">{{ statusLabels[e.status] ?? e.status }}</span>
                        </button>
                        <div v-if="searched && !results.length" class="search-state muted">Sin resultados para "{{ query }}".</div>
                        <div v-if="!searched" class="search-state muted">Escribí al menos 2 caracteres para buscar.</div>
                    </template>
                </div>
            </div>
        </div>
    </Teleport>
</template>

<style scoped>
.search-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(11, 31, 58, 0.45);
    z-index: 70;
    display: flex;
    align-items: flex-start;
    justify-content: center;
    padding-top: 10vh;
}

.search-modal {
    width: 560px;
    max-width: calc(100% - 2rem);
    max-height: 70vh;
    background: var(--color-surface);
    border-radius: var(--radius-md);
    box-shadow: var(--shadow-md);
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

.search-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.75rem 1rem;
    border-bottom: 1px solid var(--color-border);
}

.search-input {
    margin: 0.75rem 1rem;
    padding: 0.5rem 0.65rem;
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    background: var(--color-surface-alt);
    font-size: 0.9rem;
    color: var(--color-text);
}

.search-results {
    overflow-y: auto;
    padding: 0 0.5rem 0.75rem;
}

.search-state {
    padding: 1rem;
    text-align: center;
    font-size: 0.85rem;
}

.result-row {
    display: grid;
    grid-template-columns: auto 1fr auto auto;
    align-items: center;
    gap: 0.6rem;
    width: 100%;
    text-align: left;
    background: none;
    border: none;
    border-radius: var(--radius-sm);
    padding: 0.5rem 0.6rem;
    cursor: pointer;
    font-size: 0.85rem;
    color: var(--color-text);
}

.result-row:hover {
    background: var(--color-primary-soft);
}

.result-label {
    font-weight: 700;
    font-variant-numeric: tabular-nums;
    white-space: nowrap;
}

.result-desc {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    color: var(--color-text-muted);
}

.result-date {
    font-size: 0.78rem;
    white-space: nowrap;
}

.result-status {
    font-size: 0.7rem;
    font-weight: 700;
    text-transform: uppercase;
    padding: 0.1rem 0.4rem;
    border-radius: var(--radius-sm);
    white-space: nowrap;
}

.status-draft { background: var(--color-warning-soft); color: var(--color-warning); }
.status-posted { background: var(--color-success-soft); color: var(--color-success); }
.status-voided { background: var(--color-danger-soft); color: var(--color-danger); }
</style>
