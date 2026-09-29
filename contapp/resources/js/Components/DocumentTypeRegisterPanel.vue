<script setup>
import { computed, onBeforeUnmount, onMounted, ref, useId } from 'vue';
import { DownloadIcon } from '@lucide/vue';

const props = defineProps({
    documentTypes: { type: Array, required: true }, // [{ id, code, name }]
    defaultDocumentTypeId: { type: [Number, String], default: null },
    // 'inline': se renderiza directo (Reports/DocumentTypeRegister.vue).
    // 'popover': un botón dispara un panel flotante (ej. JournalEntries/Show.vue).
    mode: { type: String, default: 'inline' },
});

const open = ref(props.mode === 'inline');
const documentTypeId = ref(props.defaultDocumentTypeId ?? props.documentTypes[0]?.id ?? null);
const from = ref('');
const to = ref('');
const ids = { type: useId(), from: useId(), to: useId() };

const statusOptions = [
    { value: 'draft', label: 'Preliminar' },
    { value: 'posted', label: 'Contabilizado' },
    { value: 'voided', label: 'Anulado' },
];
const statuses = ref({ draft: false, posted: true, voided: false });

const selectedStatuses = computed(() => Object.entries(statuses.value).filter(([, on]) => on).map(([key]) => key));
// documentTypeId === null es válido a propósito: significa "Todos los tipos
// de documento", no "todavía sin elegir" — por eso acá ya no se exige que
// sea truthy, solo que haya al menos un estado marcado.
const canExport = computed(() => selectedStatuses.value.length > 0);

function exportUrl() {
    const params = new URLSearchParams();
    if (documentTypeId.value !== null) params.append('document_type_id', documentTypeId.value);
    if (from.value) params.append('from', from.value);
    if (to.value) params.append('to', to.value);
    selectedStatuses.value.forEach((s) => params.append('statuses[]', s));

    return route('reports.document-type-register.export') + '?' + params.toString();
}

// Solo aplica en modo popover: clic afuera del panel lo cierra, para que no
// se quede flotando estorbando el resto de la pantalla.
const root = ref(null);

function onDocumentClick(e) {
    if (props.mode === 'inline') return;
    if (root.value && ! root.value.contains(e.target)) {
        open.value = false;
    }
}

onMounted(() => document.addEventListener('click', onDocumentClick));
onBeforeUnmount(() => document.removeEventListener('click', onDocumentClick));
</script>

<template>
    <div ref="root" class="dtr-panel" :class="mode">
        <button
            v-if="mode === 'popover'"
            type="button"
            class="btn btn-ghost"
            :aria-expanded="open"
            @click="open = !open"
        ><DownloadIcon /> Exportar registro</button>

        <div v-if="open" class="dtr-fields" :class="{ floating: mode === 'popover' }">
            <p v-if="mode === 'popover'" class="dtr-hint">
                Exporta a XLSX todos los asientos del tipo de documento elegido (o de todos, si dejás la opción
                "Todos los tipos de documento"), una fila por línea de detalle.
            </p>

            <!-- A lo ancho de la pantalla en su página, en una columna dentro
                 del panel flotante: la grilla reparte los campos según el
                 ancho que haya (CLAUDE.md secc. 25). -->
            <div class="dtr-grid">
                <div class="field">
                    <label :for="ids.type">Tipo de documento</label>
                    <select :id="ids.type" v-model="documentTypeId">
                        <option :value="null">— Todos los tipos de documento —</option>
                        <option v-for="dt in documentTypes" :key="dt.id" :value="dt.id">
                            {{ dt.code }} — {{ dt.name }}
                        </option>
                    </select>
                </div>

                <div class="field">
                    <label :for="ids.from">Desde <span class="hint-inline">(opcional)</span></label>
                    <input :id="ids.from" v-model="from" type="date">
                </div>

                <div class="field">
                    <label :for="ids.to">Hasta <span class="hint-inline">(opcional)</span></label>
                    <input :id="ids.to" v-model="to" type="date">
                </div>

                <fieldset class="field status-field">
                    <legend>Estado</legend>
                    <div class="status-options">
                        <label v-for="opt in statusOptions" :key="opt.value" class="check">
                            <input v-model="statuses[opt.value]" type="checkbox">
                            {{ opt.label }}
                        </label>
                    </div>
                    <span v-if="!selectedStatuses.length" class="error">Marcá al menos un estado.</span>
                </fieldset>
            </div>

            <div class="actions-row">
                <a
                    :href="canExport ? exportUrl() : null"
                    class="btn btn-primary"
                    :class="{ disabled: !canExport }"
                    :aria-disabled="!canExport"
                >
                    <DownloadIcon /> Exportar XLSX
                </a>
            </div>
        </div>
    </div>
</template>

<style scoped>
.dtr-panel.popover {
    position: relative;
    display: inline-block;
}

.dtr-fields.floating {
    position: absolute;
    top: calc(100% + 0.4rem);
    right: 0;
    z-index: 30;
    width: min(320px, calc(100vw - 2rem));
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    box-shadow: 0 12px 28px rgba(0, 0, 0, 0.28);
    padding: 0.9rem 1rem;
}

.dtr-hint {
    color: var(--color-text-muted);
    font-size: 0.76rem;
    margin: 0 0 0.75rem;
}

.dtr-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(min(100%, 14rem), 1fr));
    gap: 0 1rem;
    align-items: start;
}

.hint-inline { color: var(--color-text-muted); font-size: 0.74rem; font-weight: 400; }

.status-field {
    margin: 0 0 0.9rem;
    padding: 0;
    border: 0;
}

.status-field legend {
    margin-bottom: 0.3rem;
    padding: 0;
    font-size: 0.78rem;
    font-weight: 600;
    color: var(--color-text-muted);
}

.status-options {
    display: flex;
    flex-wrap: wrap;
    gap: 0.25rem 1rem;
}

.status-options .check {
    margin: 0;
    min-height: 2.25rem;
}

.error {
    display: block;
    color: var(--color-danger);
    font-size: 0.76rem;
}

.actions-row { margin-top: 0.25rem; }

.btn.disabled {
    opacity: 0.5;
    pointer-events: none;
}
</style>
