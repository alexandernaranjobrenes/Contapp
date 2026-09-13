<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

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
            @click="open = !open"
        >Exportar registro</button>

        <div v-if="open" class="dtr-fields" :class="{ floating: mode === 'popover' }">
            <p v-if="mode === 'popover'" class="dtr-hint">
                Exporta a XLSX todos los asientos del tipo de documento elegido (o de todos, si dejás la opción
                "Todos los tipos de documento"), una fila por línea de detalle.
            </p>

            <div class="field">
                <label>Tipo de documento</label>
                <select v-model="documentTypeId">
                    <option :value="null">— Todos los tipos de documento —</option>
                    <option v-for="dt in documentTypes" :key="dt.id" :value="dt.id">
                        {{ dt.code }} — {{ dt.name }}
                    </option>
                </select>
            </div>

            <div class="field-row">
                <div class="field">
                    <label>Desde <span class="hint-inline">(opcional)</span></label>
                    <input v-model="from" type="date">
                </div>
                <div class="field">
                    <label>Hasta <span class="hint-inline">(opcional)</span></label>
                    <input v-model="to" type="date">
                </div>
            </div>

            <div class="field">
                <span class="field-label">Estado</span>
                <label v-for="opt in statusOptions" :key="opt.value" class="option-row">
                    <input v-model="statuses[opt.value]" type="checkbox">
                    {{ opt.label }}
                </label>
                <span v-if="!selectedStatuses.length" class="error">Marcá al menos un estado.</span>
            </div>

            <div class="actions-row">
                <a
                    :href="canExport ? exportUrl() : null"
                    class="btn btn-primary"
                    :class="{ disabled: !canExport }"
                    :aria-disabled="!canExport"
                >
                    Exportar XLSX
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
    width: 300px;
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

.field { margin-bottom: 0.85rem; }
.field:last-of-type { margin-bottom: 0; }
.field-label { display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.3rem; }

.field-row { display: flex; gap: 0.75rem; }
.field-row .field { flex: 1; }

label {
    display: block;
    font-size: 0.85rem;
    font-weight: 600;
    margin-bottom: 0.3rem;
}

.hint-inline { color: var(--color-text-muted); font-size: 0.78rem; font-weight: 400; }

select, input[type="date"] {
    width: 100%;
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    padding: 0.45rem 0.6rem;
    font-size: 0.85rem;
    color: var(--color-text);
}

.option-row {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.85rem;
    font-weight: 400;
    padding: 0.3rem 0;
    cursor: pointer;
}

.error {
    display: block;
    margin-top: 0.3rem;
    color: var(--color-danger);
    font-size: 0.78rem;
}

.actions-row { margin-top: 1rem; }

.btn.disabled {
    opacity: 0.5;
    pointer-events: none;
}
</style>
