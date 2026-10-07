<script setup>
import { computed, onBeforeUnmount, onMounted, ref, useId } from 'vue';
import { requestJson } from '../../Utils/http';

/**
 * Un campo «buscar» de los formularios del chat (ContiForm.vue): se escribe
 * el código, o parte del nombre, y aparecen sugerencias de la consulta de
 * Conti que corresponde (cuentas, socios, artículos…), con los permisos de
 * la persona (ContiFormController::options). Sin permiso para esa consulta,
 * queda como un campo de texto: el código se valida igual al enviar.
 */
const props = defineProps({
    fuente: { type: String, required: true },
    filtros: { type: Object, default: null },
    inputId: { type: String, default: null },
    invalid: { type: Boolean, default: false },
});

const model = defineModel({ type: [String, Number], default: '' });

const listId = useId();
const options = ref([]);
const unavailable = ref(false);
let timer = null;
let ticket = 0;

// Lo que se eligió, con su nombre al lado («1-01-01-001 · Caja general»).
const chosen = computed(() => options.value.find((o) => o.valor === String(model.value ?? '')) ?? null);

function search() {
    if (unavailable.value) return;
    clearTimeout(timer);
    timer = setTimeout(load, 250);
}

async function load() {
    const mine = ++ticket;
    const params = { fuente: props.fuente, q: String(model.value ?? '').trim() };
    if (props.filtros) params.filtros = props.filtros;

    const result = await requestJson(window.route('conti.forms.options', params));
    if (mine !== ticket) return;

    if (result.ok) {
        options.value = result.data.opciones ?? [];
    } else if ([403, 404, 422].includes(result.status)) {
        // Sin permiso para esa consulta: se escribe el código a mano.
        unavailable.value = true;
    }
}

// Precargado: se busca para mostrar su nombre al lado.
onMounted(() => { if (String(model.value ?? '').trim() !== '') load(); });
onBeforeUnmount(() => clearTimeout(timer));
</script>

<template>
    <div class="conti-lookup">
        <input
            :id="inputId"
            v-model="model"
            type="text"
            autocomplete="off"
            :list="unavailable ? null : listId"
            :aria-invalid="invalid || null"
            :placeholder="unavailable ? 'Código' : 'Código o nombre'"
            @focus="search"
            @input="search"
        >
        <datalist v-if="!unavailable" :id="listId">
            <option v-for="option in options" :key="option.valor" :value="option.valor">{{ option.etiqueta }}</option>
        </datalist>
        <span v-if="chosen?.etiqueta" class="lookup-chosen">{{ chosen.etiqueta }}</span>
    </div>
</template>

<style scoped>
.conti-lookup {
    display: flex;
    flex-direction: column;
    gap: 0.15rem;
    min-width: 0;
}

.lookup-chosen {
    font-size: 0.72rem;
    color: var(--color-text-muted);
    overflow-wrap: anywhere;
}
</style>
