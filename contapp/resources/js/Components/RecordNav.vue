<script setup>
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { ChevronFirstIcon, ChevronLastIcon, ChevronLeftIcon, ChevronRightIcon, SearchIcon } from '@lucide/vue';

/**
 * Recorrer los documentos de un mismo tipo sin volver al listado: buscar uno
 * y pasar al primero, anterior, siguiente o último. Va en la barra de la
 * vista (.view-toolbar), no en la barra superior (CLAUDE.md secc. 24).
 *
 * nav trae los id vecinos que manda el controlador ({ first, prev, next,
 * last }); el botón de un vecino que no existe queda deshabilitado.
 */
const props = defineProps({
    nav: { type: Object, required: true },
    currentId: { type: [Number, String], default: null },
    routeName: { type: String, required: true },
    searchable: { type: Boolean, default: false },
});

const emit = defineEmits(['search']);

const steps = computed(() => [
    { key: 'first', label: 'Primer registro', icon: ChevronFirstIcon, id: props.nav.first !== props.currentId ? props.nav.first : null },
    { key: 'prev', label: 'Registro anterior', icon: ChevronLeftIcon, id: props.nav.prev },
    { key: 'next', label: 'Registro siguiente', icon: ChevronRightIcon, id: props.nav.next },
    { key: 'last', label: 'Último registro', icon: ChevronLastIcon, id: props.nav.last !== props.currentId ? props.nav.last : null },
]);
</script>

<template>
    <div class="record-nav">
        <button v-if="searchable" type="button" class="btn btn-ghost" @click="emit('search')"><SearchIcon /> Buscar</button>

        <div class="record-nav-steps" role="group" aria-label="Recorrer registros">
            <template v-for="step in steps" :key="step.key">
                <Link
                    v-if="step.id"
                    :href="route(routeName, step.id)"
                    class="btn btn-ghost record-nav-step"
                    :title="step.label"
                    :aria-label="step.label"
                ><component :is="step.icon" /></Link>
                <button
                    v-else
                    type="button"
                    class="btn btn-ghost record-nav-step"
                    disabled
                    :title="step.label"
                    :aria-label="step.label"
                ><component :is="step.icon" /></button>
            </template>
        </div>
    </div>
</template>

<style scoped>
.record-nav {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.5rem;
}

/* Los cuatro pasos, pegados: se leen como un solo control. */
.record-nav-steps {
    display: inline-flex;
}

.record-nav-step {
    width: 2.25rem;
    padding: 0;
    border-radius: 0;
}

.record-nav-step + .record-nav-step {
    margin-left: -1px;
}

.record-nav-step:first-child {
    border-radius: var(--radius-sm) 0 0 var(--radius-sm);
}

.record-nav-step:last-child {
    border-radius: 0 var(--radius-sm) var(--radius-sm) 0;
}
</style>
