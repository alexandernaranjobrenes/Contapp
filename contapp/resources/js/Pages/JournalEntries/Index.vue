<script setup>
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import { CopyIcon, DownloadIcon, EyeIcon, PencilIcon, PlusIcon, UploadIcon } from '@lucide/vue';

import AppLayout from '../../Layouts/AppLayout.vue';
import DetailModal from '../../Components/DetailModal.vue';
import { confirmAction } from '../../Utils/confirm';
import { useRecordDetail } from '../../Utils/recordDetail';

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

function documentLabel(entry) {
    return entry.document_number
        ? `${entry.document_type?.code}-${entry.document_number}`
        : `${entry.document_type?.code ?? ''} — sin número aún`;
}

// Ficha del asiento (CLAUDE.md secc. 20): el resumen y lo que se puede hacer
// con él. El asiento completo, con sus líneas, está en «Ver asiento».
const { selected, openDetail, closeDetail } = useRecordDetail(() => props.entries.data);

function destroy() {
    const entry = selected.value;

    confirmAction({
        title: 'Eliminar borrador',
        message: `El asiento preliminar ${documentLabel(entry)} se elimina de forma definitiva.`,
        confirmLabel: 'Eliminar',
        danger: true,
        onConfirm: () => router.delete(route('journal-entries.destroy', entry.id), { preserveScroll: true }),
    });
}

function reverseEntry() {
    const entry = selected.value;

    confirmAction({
        title: 'Anular asiento',
        message: 'Se creará un asiento de reversión que revierte sus montos — el original no se modifica ni se borra.',
        confirmLabel: 'Anular',
        danger: true,
        onConfirm: () => router.post(route('journal-entries.reverse', entry.id), {}, { preserveScroll: true }),
    });
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
        <div class="view-toolbar">
            <div class="view-actions">
                <a :href="exportUrl('journal-entries.list-export')" class="btn btn-ghost"><DownloadIcon /> Exportar XLSX</a>
                <a :href="exportUrl('journal-entries.list-export-pdf')" class="btn btn-ghost"><DownloadIcon /> Exportar PDF</a>
                <a
                    :href="route('journal-entries.template')"
                    class="btn btn-ghost"
                    title="Plantilla de Excel para cargar un asiento completo (encabezado y líneas)"
                ><DownloadIcon /> Descargar plantilla</a>
                <label
                    class="btn btn-ghost file-btn"
                    :class="{ disabled: importForm.processing }"
                    title="Subir la plantilla completa: el asiento queda como preliminar, para revisarlo antes de contabilizar"
                >
                    <UploadIcon /> {{ importForm.processing ? 'Subiendo...' : 'Importar XLSX' }}
                    <input ref="fileInput" type="file" accept=".xlsx" :disabled="importForm.processing" @change="onFileSelected">
                </label>
                <Link :href="route('journal-entries.create')" class="btn btn-primary"><PlusIcon /> Crear nuevo</Link>
            </div>
            <form class="view-filters" @submit.prevent="applyFilters">
                <label class="filter-field">
                    <span>N.º de documento</span>
                    <input v-model="documentNumber" type="search" placeholder="Ej. 123" class="number-filter">
                </label>
                <label class="filter-field">
                    <span>Tipo de documento</span>
                    <select v-model="documentTypeId">
                        <option value="">Todos</option>
                        <option v-for="dt in documentTypes" :key="dt.id" :value="dt.id">{{ dt.code }} — {{ dt.name }}</option>
                    </select>
                </label>
                <label class="filter-field">
                    <span>Desde</span>
                    <input v-model="from" type="date">
                </label>
                <label class="filter-field">
                    <span>Hasta</span>
                    <input v-model="to" type="date">
                </label>
                <button type="submit" class="btn btn-primary">Buscar</button>
                <button v-if="hasActiveFilters" type="button" class="btn btn-ghost" @click="clearFilters">Limpiar</button>
            </form>
        </div>

        <p v-if="importForm.errors.file" class="flash flash-error">{{ importForm.errors.file }}</p>

        <div v-if="importErrors.length" class="import-errors">
            <p>No se importó nada porque el archivo tiene {{ importErrors.length }} error(es). Corregilos y subilo de nuevo:</p>
            <ul>
                <li v-for="(msg, i) in importErrors" :key="i">{{ msg }}</li>
            </ul>
        </div>

        <div class="card">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Documento</th>
                            <th>Fecha</th>
                            <th>Descripción</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="entry in entries.data"
                            :key="entry.id"
                            class="clickable-row"
                            tabindex="0"
                            @click="openDetail(entry)"
                            @keydown.enter="openDetail(entry)"
                            @keydown.space.prevent="openDetail(entry)"
                        >
                            <td class="document-cell">
                                <template v-if="entry.document_number">{{ entry.document_type?.code }}-{{ entry.document_number }}</template>
                                <span v-else class="muted">{{ entry.document_type?.code }} — sin número aún</span>
                            </td>
                            <td data-label="Fecha" class="date-cell">{{ entry.posting_date }}</td>
                            <td data-label="Descripción">{{ entry.description }}</td>
                            <td data-label="Estado">
                                <span class="badge" :class="statusBadge[entry.status] ?? 'badge-neutral'">
                                    {{ statusLabels[entry.status] ?? entry.status }}
                                </span>
                            </td>
                        </tr>
                        <tr v-if="!entries.data.length">
                            <td colspan="4" class="muted empty-row">Todavía no hay asientos contabilizados.</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <nav v-if="entries.links.length > 3" class="pagination" aria-label="Páginas">
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

        <DetailModal :open="!!selected" :title="selected ? documentLabel(selected) : ''" @close="closeDetail">
            <template #badge>
                <span v-if="selected" class="badge" :class="statusBadge[selected.status] ?? 'badge-neutral'">
                    {{ statusLabels[selected.status] ?? selected.status }}
                </span>
            </template>

            <dl v-if="selected" class="detail-list">
                <div>
                    <dt>Tipo de documento</dt>
                    <dd>{{ selected.document_type?.code }}<template v-if="selected.document_type?.name"> — {{ selected.document_type.name }}</template></dd>
                </div>
                <div>
                    <dt>Fecha</dt>
                    <dd>{{ selected.posting_date }}</dd>
                </div>
                <div v-if="selected.number_series" class="full">
                    <dt>Serie de numeración</dt>
                    <dd>
                        {{ selected.number_series.name }}<template v-if="selected.number_series.holder_name"> · {{ selected.number_series.holder_name }}</template>
                        #{{ selected.series_number }}
                    </dd>
                </div>
                <div class="full">
                    <dt>Descripción</dt>
                    <dd>{{ selected.description || '—' }}</dd>
                </div>
            </dl>

            <template #actions>
                <template v-if="selected">
                    <template v-if="selected.status === 'draft'">
                        <button type="button" class="btn btn-ghost btn-danger-text" @click="destroy">Eliminar</button>
                        <Link :href="route('journal-entries.edit', selected.id)" class="btn btn-ghost"><PencilIcon /> Editar</Link>
                    </template>
                    <template v-if="selected.status === 'posted'">
                        <button type="button" class="btn btn-ghost btn-danger-text" @click="reverseEntry">Anular</button>
                        <Link :href="route('journal-entries.duplicate', selected.id)" class="btn btn-ghost"><CopyIcon /> Duplicar</Link>
                    </template>
                    <Link :href="route('journal-entries.show', selected.id)" class="btn btn-primary"><EyeIcon /> Ver asiento</Link>
                </template>
            </template>
        </DetailModal>
    </AppLayout>
</template>

<style scoped>
table { font-size: 0.85rem; }
.document-cell,
.date-cell { white-space: nowrap; font-variant-numeric: tabular-nums; }
.number-filter { width: 9rem; }
</style>
