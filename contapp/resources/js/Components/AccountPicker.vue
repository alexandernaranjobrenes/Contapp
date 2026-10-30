<script setup>
import { computed, nextTick, ref, useId } from 'vue';
import AccountSearchModal from './AccountSearchModal.vue';
import { SearchIcon } from '@lucide/vue';

/**
 * El campo de cuenta de una línea de asiento: muestra el código elegido y,
 * debajo, el nombre. Para elegir, la ventana con todo el catálogo
 * (AccountSearchModal):
 *
 * - escribir en el campo la abre con lo escrito como búsqueda;
 * - la lupa, o la flecha abajo o Enter, la abre sin búsqueda;
 * - entrar al campo o hacer clic no abre nada: sin escribir, no hay nada que
 *   buscar todavía.
 *
 * El doble clic en el campo queda para quien lo usa (el asiento abre el
 * mayor auxiliar de la cuenta).
 */
const props = defineProps({
    options: { type: Array, required: true }, // [{ id, code, label, type }]
    types: { type: Object, default: () => ({}) },
    modelValue: { type: [Number, String, null], default: null },
    placeholder: { type: String, default: 'Código o nombre de cuenta…' },
    required: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue']);

const captionId = useId();
const field = ref(null);
const open = ref(false);
const initialQuery = ref('');

const selected = computed(() => props.options.find((o) => o.id === props.modelValue) ?? null);

function openSearch(query = '') {
    initialQuery.value = query;
    open.value = true;
}

// Lo que se escribe pasa a la búsqueda de la ventana. Si se escribe rápido,
// las teclas que llegan antes de que la ventana tome el foco siguen sumando
// a la búsqueda. Al cerrarla, el campo vuelve a mostrar el código elegido.
function onInput(event) {
    const text = event.target.value;

    if (open.value) {
        initialQuery.value = text;
    } else if (text.trim() !== '') {
        openSearch(text);
    } else {
        restoreField();
    }
}

function restoreField() {
    if (field.value) field.value.value = selected.value?.code ?? '';
}

function close() {
    open.value = false;
    nextTick(restoreField);
}

function onKeydown(event) {
    if (event.key === 'ArrowDown' || event.key === 'Enter' || event.key === 'F4') {
        event.preventDefault();
        openSearch();
    }
}

async function select(id) {
    emit('update:modelValue', id);
    open.value = false;
    await nextTick();
    restoreField();
    field.value?.focus();
}
</script>

<template>
    <div class="account-picker">
        <div class="picker-row">
            <input
                ref="field"
                type="text"
                :value="selected?.code ?? ''"
                :placeholder="placeholder"
                :required="required && !modelValue"
                autocomplete="off"
                aria-haspopup="dialog"
                :aria-describedby="selected ? captionId : undefined"
                @focus="$event.target.select()"
                @input="onInput"
                @keydown="onKeydown"
            >
            <button
                type="button"
                class="btn btn-ghost picker-search"
                aria-label="Buscar cuenta"
                title="Buscar cuenta"
                @click="openSearch()"
                @dblclick.stop
            >
                <SearchIcon />
            </button>
        </div>
        <div v-if="selected" :id="captionId" class="picker-caption" :title="selected.label">{{ selected.label }}</div>

        <AccountSearchModal
            :open="open"
            :options="options"
            :types="types"
            :model-value="modelValue"
            :initial-query="initialQuery"
            @select="select"
            @close="close"
        />
    </div>
</template>

<style scoped>
.picker-row { display: flex; gap: 0.3rem; }

/* El aspecto es el de todos los campos (app.scss); acá solo el ancho. */
.picker-row input { flex: 1; min-width: 0; width: 100%; }

.picker-search { flex-shrink: 0; width: 2.25rem; padding: 0; justify-content: center; }

.picker-caption {
    margin-top: 0.15rem;
    font-size: 0.72rem;
    color: var(--color-text-muted);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
</style>
