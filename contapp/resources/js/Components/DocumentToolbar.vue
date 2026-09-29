<script setup>
import { Link, router } from '@inertiajs/vue3';
import {
    ChevronFirstIcon, ChevronLastIcon, ChevronLeftIcon, ChevronRightIcon, CircleHelpIcon, DownloadIcon,
    PaperclipIcon, PlusIcon, PrinterIcon, RefreshCwIcon, SaveIcon, SearchIcon,
} from '@lucide/vue';

const props = defineProps({
    newHref: { type: String, default: null },
    // Para pantallas sin ruta propia de "nuevo" (catálogos con modal en la
    // misma página, ej. ChartOfAccounts/Index.vue): en vez de un link, emite
    // 'new' para que la página abra su propio modal/formulario.
    canCreate: { type: Boolean, default: false },
    canSave: { type: Boolean, default: false },
    saving: { type: Boolean, default: false },
    exportHref: { type: String, default: null },
    firstHref: { type: String, default: null },
    prevHref: { type: String, default: null },
    nextHref: { type: String, default: null },
    lastHref: { type: String, default: null },
});

const emit = defineEmits(['find', 'save', 'new']);

function refresh() {
    router.reload({ preserveScroll: true });
}

// window.print() no se puede llamar directo desde el <template>: los
// identificadores no reconocidos por el compilador de SFC se resuelven
// contra _ctx (el componente), no contra window (mismo motivo por el que
// route() tampoco funciona directo en un <template>, ver app.js).
function print() {
    window.print();
}
</script>

<!-- Botones de solo ícono: el title da el tooltip al pasar el mouse, y el
     aria-label es lo que lee un lector de pantalla. -->
<template>
    <div class="doc-toolbar">
        <button type="button" class="tool-btn" title="Buscar" aria-label="Buscar" @click="emit('find')">
            <SearchIcon :size="17" />
        </button>

        <Link v-if="newHref" :href="newHref" class="tool-btn" title="Nuevo" aria-label="Nuevo">
            <PlusIcon :size="17" />
        </Link>
        <button v-else-if="canCreate" type="button" class="tool-btn" title="Nuevo" aria-label="Nuevo" @click="emit('new')">
            <PlusIcon :size="17" />
        </button>
        <button v-else type="button" class="tool-btn" disabled title="Nuevo" aria-label="Nuevo">
            <PlusIcon :size="17" />
        </button>

        <button type="button" class="tool-btn" :disabled="!canSave || saving" title="Guardar" aria-label="Guardar" @click="emit('save')">
            <SaveIcon :size="17" />
        </button>

        <span class="tool-sep" />

        <button type="button" class="tool-btn" title="Imprimir" aria-label="Imprimir" @click="print">
            <PrinterIcon :size="17" />
        </button>

        <a v-if="exportHref" :href="exportHref" class="tool-btn" title="Exportar" aria-label="Exportar">
            <DownloadIcon :size="17" />
        </a>
        <button v-else type="button" class="tool-btn" disabled title="Exportar" aria-label="Exportar">
            <DownloadIcon :size="17" />
        </button>

        <button type="button" class="tool-btn" title="Actualizar" aria-label="Actualizar" @click="refresh">
            <RefreshCwIcon :size="17" />
        </button>

        <span class="tool-sep" />

        <Link v-if="firstHref" :href="firstHref" class="tool-btn" title="Primer registro" aria-label="Primer registro">
            <ChevronFirstIcon :size="17" />
        </Link>
        <button v-else type="button" class="tool-btn" disabled title="Primer registro" aria-label="Primer registro">
            <ChevronFirstIcon :size="17" />
        </button>

        <Link v-if="prevHref" :href="prevHref" class="tool-btn" title="Registro anterior" aria-label="Registro anterior">
            <ChevronLeftIcon :size="17" />
        </Link>
        <button v-else type="button" class="tool-btn" disabled title="Registro anterior" aria-label="Registro anterior">
            <ChevronLeftIcon :size="17" />
        </button>

        <Link v-if="nextHref" :href="nextHref" class="tool-btn" title="Registro siguiente" aria-label="Registro siguiente">
            <ChevronRightIcon :size="17" />
        </Link>
        <button v-else type="button" class="tool-btn" disabled title="Registro siguiente" aria-label="Registro siguiente">
            <ChevronRightIcon :size="17" />
        </button>

        <Link v-if="lastHref" :href="lastHref" class="tool-btn" title="Último registro" aria-label="Último registro">
            <ChevronLastIcon :size="17" />
        </Link>
        <button v-else type="button" class="tool-btn" disabled title="Último registro" aria-label="Último registro">
            <ChevronLastIcon :size="17" />
        </button>

        <span class="tool-sep" />

        <button type="button" class="tool-btn" disabled title="Adjuntos (próximamente)" aria-label="Adjuntos (próximamente)">
            <PaperclipIcon :size="17" />
        </button>

        <button type="button" class="tool-btn" disabled title="Ayuda (próximamente)" aria-label="Ayuda (próximamente)">
            <CircleHelpIcon :size="17" />
        </button>
    </div>
</template>

<style scoped>
.doc-toolbar {
    display: flex;
    align-items: center;
    /* Sin esto, en un teléfono los quince botones empujan la página hacia
       la derecha. Con espacio de sobra nunca hace falta partir la línea. */
    flex-wrap: wrap;
    gap: 0.15rem;
    padding: 0.4rem 0.6rem;
    background: var(--color-primary);
    border-radius: var(--radius-sm) var(--radius-sm) 0 0;
    border-bottom: 3px solid var(--color-warning);
    margin-bottom: 1rem;
}

.tool-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 30px;
    height: 30px;
    padding: 0;
    background: transparent;
    border: none;
    border-radius: var(--radius-sm);
    color: rgba(244, 246, 250, 0.85);
    cursor: pointer;
    text-decoration: none;
}

.tool-btn:hover:not(:disabled) {
    background: rgba(255, 255, 255, 0.14);
    color: #fff;
}

.tool-btn:disabled {
    color: rgba(244, 246, 250, 0.28);
    cursor: default;
}

.tool-sep {
    width: 1px;
    height: 20px;
    background: rgba(244, 246, 250, 0.18);
    margin: 0 0.3rem;
    flex-shrink: 0;
}
</style>
