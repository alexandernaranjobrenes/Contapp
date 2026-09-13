<script setup>
import { Link, router } from '@inertiajs/vue3';

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

<template>
    <div class="doc-toolbar">
        <button type="button" class="tool-btn" title="Buscar" @click="emit('find')">
            <svg viewBox="0 0 24 24"><circle cx="10.5" cy="10.5" r="6.5" /><line x1="20" y1="20" x2="15.3" y2="15.3" /></svg>
        </button>

        <Link v-if="newHref" :href="newHref" class="tool-btn" title="Nuevo">
            <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19" /><line x1="5" y1="12" x2="19" y2="12" /></svg>
        </Link>
        <button v-else-if="canCreate" type="button" class="tool-btn" title="Nuevo" @click="emit('new')">
            <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19" /><line x1="5" y1="12" x2="19" y2="12" /></svg>
        </button>
        <button v-else type="button" class="tool-btn" disabled title="Nuevo">
            <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19" /><line x1="5" y1="12" x2="19" y2="12" /></svg>
        </button>

        <button type="button" class="tool-btn" :disabled="!canSave || saving" title="Guardar" @click="emit('save')">
            <svg viewBox="0 0 24 24"><path d="M5 4h11l3 3v13H5z" /><path d="M8 4v6h8V4" /><path d="M8 20v-6h8v6" /></svg>
        </button>

        <span class="tool-sep" />

        <button type="button" class="tool-btn" title="Imprimir" @click="print">
            <svg viewBox="0 0 24 24"><path d="M6 9V3h12v6" /><rect x="4" y="9" width="16" height="8" rx="1" /><path d="M6 17v4h12v-4" /></svg>
        </button>

        <a v-if="exportHref" :href="exportHref" class="tool-btn" title="Exportar">
            <svg viewBox="0 0 24 24"><path d="M12 3v12" /><path d="M7 10l5 5 5-5" /><path d="M4 20h16" /></svg>
        </a>
        <button v-else type="button" class="tool-btn" disabled title="Exportar">
            <svg viewBox="0 0 24 24"><path d="M12 3v12" /><path d="M7 10l5 5 5-5" /><path d="M4 20h16" /></svg>
        </button>

        <button type="button" class="tool-btn" title="Actualizar" @click="refresh">
            <svg viewBox="0 0 24 24"><path d="M4 4v6h6" /><path d="M20 20v-6h-6" /><path d="M5.5 15a8 8 0 0 0 13.9 2.5M18.5 9A8 8 0 0 0 4.6 6.5" /></svg>
        </button>

        <span class="tool-sep" />

        <Link v-if="firstHref" :href="firstHref" class="tool-btn" title="Primer registro">
            <svg viewBox="0 0 24 24"><polyline points="17 6 11 12 17 18" /><line x1="7" y1="5" x2="7" y2="19" /></svg>
        </Link>
        <button v-else type="button" class="tool-btn" disabled title="Primer registro">
            <svg viewBox="0 0 24 24"><polyline points="17 6 11 12 17 18" /><line x1="7" y1="5" x2="7" y2="19" /></svg>
        </button>

        <Link v-if="prevHref" :href="prevHref" class="tool-btn" title="Registro anterior">
            <svg viewBox="0 0 24 24"><polyline points="15 6 9 12 15 18" /></svg>
        </Link>
        <button v-else type="button" class="tool-btn" disabled title="Registro anterior">
            <svg viewBox="0 0 24 24"><polyline points="15 6 9 12 15 18" /></svg>
        </button>

        <Link v-if="nextHref" :href="nextHref" class="tool-btn" title="Registro siguiente">
            <svg viewBox="0 0 24 24"><polyline points="9 6 15 12 9 18" /></svg>
        </Link>
        <button v-else type="button" class="tool-btn" disabled title="Registro siguiente">
            <svg viewBox="0 0 24 24"><polyline points="9 6 15 12 9 18" /></svg>
        </button>

        <Link v-if="lastHref" :href="lastHref" class="tool-btn" title="Último registro">
            <svg viewBox="0 0 24 24"><polyline points="7 6 13 12 7 18" /><line x1="17" y1="5" x2="17" y2="19" /></svg>
        </Link>
        <button v-else type="button" class="tool-btn" disabled title="Último registro">
            <svg viewBox="0 0 24 24"><polyline points="7 6 13 12 7 18" /><line x1="17" y1="5" x2="17" y2="19" /></svg>
        </button>

        <span class="tool-sep" />

        <button type="button" class="tool-btn" disabled title="Adjuntos (próximamente)">
            <svg viewBox="0 0 24 24"><path d="M8 12V6a4 4 0 1 1 8 0v9a3 3 0 1 1-6 0V7" /></svg>
        </button>

        <button type="button" class="tool-btn" disabled title="Ayuda (próximamente)">
            <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9" /><path d="M9.5 9a2.5 2.5 0 1 1 3.5 2.3c-.8.4-1 .9-1 1.7" /><line x1="12" y1="17" x2="12" y2="17" /></svg>
        </button>
    </div>
</template>

<style scoped>
.doc-toolbar {
    display: flex;
    align-items: center;
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

.tool-btn svg {
    width: 17px;
    height: 17px;
    fill: none;
    stroke: currentColor;
    stroke-width: 1.8;
    stroke-linecap: round;
    stroke-linejoin: round;
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
