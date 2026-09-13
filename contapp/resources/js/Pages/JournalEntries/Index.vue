<script setup>
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import DocumentToolbar from '../../Components/DocumentToolbar.vue';

const props = defineProps({
    entries: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    documentTypes: { type: Array, default: () => [] },
});

// Buscador de la lista: número de documento, tipo de documento y rango de
// fechas — tres filtros independientes (no una búsqueda difusa), enviados
// como query string para que quede en la URL (compartible, sobrevive un
// refresh) y sea justo lo que también usan los botones de exportar.
const documentNumber = ref(props.filters.document_number ?? '');
const documentTypeId = ref(props.filters.document_type_id ?? '');
const from = ref(props.filters.from ?? '');
const to = ref(props.filters.to ?? '');

const currentFilters = computed(() => ({
    document_number: documentNumber.value || null,
    document_type_id: documentTypeId.value || null,
    from: from.value || null,
    to: to.value || null,
}));

const hasActiveFilters = computed(() =>
    Object.values(currentFilters.value).some((v) => v !== null)
);

function applyFilters() {
    router.get(route('journal-entries.index'), currentFilters.value, { preserveState: true });
}

function clearFilters() {
    documentNumber.value = '';
    documentTypeId.value = '';
    from.value = '';
    to.value = '';
    router.get(route('journal-entries.index'), {}, { preserveState: true });
}

function exportUrl(routeName) {
    return route(routeName, currentFilters.value);
}

const statusLabels = { draft: 'Preliminar', posted: 'Contabilizado', voided: 'Anulado' };
const statusBadge = { draft: 'badge-warning', posted: 'badge-success', voided: 'badge-neutral' };

function destroy(entry) {
    if (! confirm('¿Eliminar este borrador? Esta acción no se puede deshacer.')) return;

    router.delete(route('journal-entries.destroy', entry.id), { preserveScroll: true });
}

function reverseEntry(entry) {
    if (! confirm('¿Anular este asiento? Se creará un asiento de reversión que revierte sus montos — el original no se modifica ni se borra.')) return;

    router.post(route('journal-entries.reverse', entry.id), {}, { preserveScroll: true });
}

// --- importar un asiento completo desde xlsx (queda como preliminar) ---

const page = usePage();
const fileInput = ref(null);
const importForm = useForm({ file: null });
const importErrors = computed(() => page.props.flash?.importErrors ?? []);

function onFileSelected(e) {
    const file = e.target.files[0];
    if (! file) return;

    importForm.file = file;
    importForm.post(route('journal-entries.import'), {
        preserveScroll: true,
        onFinish: () => {
            importForm.reset();
            if (fileInput.value) fileInput.value.value = '';
        },
    });
}
</script>

<template>
    <Head title="Asientos" />

    <AppLayout title="Asientos">
        <template #actions>
            <Link :href="route('journal-entries.create')" class="btn btn-primary">+ Nuevo asiento</Link>
        </template>

        <DocumentToolbar :new-href="route('journal-entries.create')" />

        <div class="card filter-bar">
            <div class="filter-fields">
                <div class="field">
                    <label for="filter-document-number">Número de documento</label>
                    <input
                        id="filter-document-number"
                        v-model="documentNumber"
                        type="text"
                        placeholder="Ej. 123"
                        @keyup.enter="applyFilters"
                    >
                </div>
                <div class="field">
                    <label for="filter-document-type">Tipo de documento</label>
                    <select id="filter-document-type" v-model="documentTypeId">
                        <option value="">— Todos —</option>
                        <option v-for="dt in documentTypes" :key="dt.id" :value="dt.id">{{ dt.code }} — {{ dt.name }}</option>
                    </select>
                </div>
                <div class="field">
                    <label for="filter-from">Desde</label>
                    <input id="filter-from" v-model="from" type="date">
                </div>
                <div class="field">
                    <label for="filter-to">Hasta</label>
                    <input id="filter-to" v-model="to" type="date">
                </div>
                <div class="filter-actions">
                    <button type="button" class="btn btn-primary" @click="applyFilters">Buscar</button>
                    <button v-if="hasActiveFilters" type="button" class="btn btn-ghost" @click="clearFilters">Limpiar</button>
                </div>
            </div>
            <div class="filter-export">
                <a :href="exportUrl('journal-entries.list-export')" class="btn btn-ghost">⤓ Exportar XLSX</a>
                <a :href="exportUrl('journal-entries.list-export-pdf')" class="btn btn-ghost">⤓ Exportar PDF</a>
            </div>
        </div>

        <div class="bulk-bar card">
            <div class="bulk-bar-row">
                <div class="bulk-bar-text">
                    <strong>Importar un asiento desde Excel</strong>
                    <span class="muted small">Descargá la plantilla, completá un asiento completo (encabezado y líneas) y subila — queda como preliminar para revisarlo antes de contabilizar.</span>
                </div>
                <div class="bulk-actions">
                    <a :href="route('journal-entries.template')" class="btn btn-ghost">Descargar plantilla</a>
                    <label class="btn btn-primary file-btn" :class="{ disabled: importForm.processing }">
                        {{ importForm.processing ? 'Subiendo...' : 'Importar XLSX' }}
                        <input ref="fileInput" type="file" accept=".xlsx" class="file-input" :disabled="importForm.processing" @change="onFileSelected">
                    </label>
                </div>
            </div>
            <span v-if="importForm.errors.file" class="error">{{ importForm.errors.file }}</span>

            <div v-if="importErrors.length" class="import-errors">
                <p class="import-errors-title">No se importó nada porque el archivo tiene {{ importErrors.length }} error(es). Corregilos y subilo de nuevo:</p>
                <ul>
                    <li v-for="(msg, i) in importErrors" :key="i">{{ msg }}</li>
                </ul>
            </div>
        </div>

        <div class="card">
            <table>
                <thead>
                    <tr>
                        <th>Documento</th>
                        <th>Serie</th>
                        <th>Fecha</th>
                        <th>Descripción</th>
                        <th>Estado</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="entry in entries.data" :key="entry.id">
                        <td>
                            <template v-if="entry.document_number">{{ entry.document_type?.code }}-{{ entry.document_number }}</template>
                            <span v-else class="muted">{{ entry.document_type?.code }} — sin número aún</span>
                        </td>
                        <td>
                            <span v-if="entry.number_series" class="muted small">
                                {{ entry.number_series.name }}<template v-if="entry.number_series.holder_name"> · {{ entry.number_series.holder_name }}</template>
                                #{{ entry.series_number }}
                            </span>
                        </td>
                        <td>{{ entry.posting_date }}</td>
                        <td>{{ entry.description }}</td>
                        <td>
                            <span class="badge" :class="statusBadge[entry.status] ?? 'badge-neutral'">
                                {{ statusLabels[entry.status] ?? entry.status }}
                            </span>
                        </td>
                        <td class="actions-cell">
                            <Link :href="route('journal-entries.show', entry.id)" class="btn btn-ghost">Ver</Link>
                            <template v-if="entry.status === 'draft'">
                                <Link :href="route('journal-entries.edit', entry.id)" class="btn btn-ghost">Editar</Link>
                                <button type="button" class="btn btn-ghost" @click="destroy(entry)">Eliminar</button>
                            </template>
                            <template v-if="entry.status === 'posted'">
                                <Link :href="route('journal-entries.duplicate', entry.id)" class="btn btn-ghost">Duplicar</Link>
                                <button type="button" class="btn btn-ghost" @click="reverseEntry(entry)">Anular</button>
                            </template>
                        </td>
                    </tr>
                    <tr v-if="!entries.data.length">
                        <td colspan="6" class="muted empty-row">Todavía no hay asientos contabilizados.</td>
                    </tr>
                </tbody>
            </table>

            <nav v-if="entries.links.length > 3" class="pagination">
                <Link
                    v-for="(link, i) in entries.links"
                    :key="i"
                    :href="link.url ?? '#'"
                    class="page-link"
                    :class="{ active: link.active, disabled: !link.url }"
                    v-html="link.label"
                />
            </nav>
        </div>
    </AppLayout>
</template>

<style scoped>
.filter-bar {
    padding: 0.85rem 1.1rem;
    margin-bottom: 0.75rem;
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    gap: 1rem;
    flex-wrap: wrap;
}

.filter-fields {
    display: flex;
    align-items: flex-end;
    gap: 0.75rem;
    flex-wrap: wrap;
}

.filter-fields .field {
    display: flex;
    flex-direction: column;
    gap: 0.2rem;
}

.filter-fields label {
    font-size: 0.7rem;
    font-weight: 700;
    letter-spacing: 0.02em;
    text-transform: uppercase;
    color: var(--color-text-muted);
}

.filter-fields input, .filter-fields select {
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    padding: 0.4rem 0.55rem;
    font-size: 0.82rem;
    min-width: 150px;
}

.filter-actions {
    display: flex;
    gap: 0.5rem;
}

.filter-export {
    display: flex;
    gap: 0.5rem;
    flex-shrink: 0;
}

table {
    font-size: 0.85rem;
}

th, td {
    text-align: left;
    padding: 0.55rem 1.1rem;
    border-top: 1px solid var(--color-border);
}

.muted {
    color: var(--color-text-muted);
}

.small {
    font-size: 0.78rem;
}

.empty-row {
    text-align: center;
    padding: 1.5rem;
}

.actions-cell {
    display: flex;
    gap: 0.4rem;
}

.pagination {
    display: flex;
    gap: 0.25rem;
    padding: 0.75rem 1.1rem;
    flex-wrap: wrap;
}

.page-link {
    padding: 0.3rem 0.6rem;
    border-radius: var(--radius-sm);
    font-size: 0.78rem;
    text-decoration: none;
    color: var(--color-text-muted);
    border: 1px solid var(--color-border);
}

.page-link.active {
    background: var(--color-primary);
    color: var(--color-on-primary);
    border-color: var(--color-primary);
}

.page-link.disabled {
    opacity: 0.4;
    pointer-events: none;
}

.bulk-bar {
    padding: 0.85rem 1.1rem;
    margin-bottom: 0.75rem;
}

.bulk-bar-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
}

.bulk-bar-text {
    display: flex;
    flex-direction: column;
    gap: 0.1rem;
    font-size: 0.85rem;
}

.bulk-actions {
    display: flex;
    gap: 0.5rem;
    flex-shrink: 0;
}

.file-btn {
    position: relative;
    cursor: pointer;
    overflow: hidden;
}

.file-btn.disabled {
    opacity: 0.6;
    cursor: default;
}

.file-input {
    position: absolute;
    inset: 0;
    opacity: 0;
    width: 100%;
    cursor: pointer;
}

.error {
    display: block;
    margin-top: 0.4rem;
    color: var(--color-danger);
    font-size: 0.76rem;
}

.import-errors {
    margin-top: 0.75rem;
    padding: 0.75rem 0.9rem;
    border-radius: var(--radius-sm);
    background: var(--color-danger-soft);
    color: var(--color-danger);
    font-size: 0.82rem;
}

.import-errors-title {
    font-weight: 700;
    margin: 0 0 0.4rem;
}

.import-errors ul {
    margin: 0;
    padding-left: 1.1rem;
    display: flex;
    flex-direction: column;
    gap: 0.2rem;
}
</style>
