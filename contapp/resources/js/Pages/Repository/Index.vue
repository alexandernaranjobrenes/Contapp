<script setup>
import { Head } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import { ChevronDownIcon, DownloadIcon, EyeIcon, FileTextIcon, FolderIcon, FolderOpenIcon } from '@lucide/vue';

/**
 * La Biblioteca (RepositoryController, CLAUDE.md secc. 39): los documentos de
 * consulta que publica el equipo de Pivox —leyes, reglamentos, resoluciones,
 * artículos—, en las carpetas en que los agrupa el backoffice. Cada uno se
 * abre como PDF en otra pestaña, o se descarga.
 *
 * Las carpetas arrancan cerradas y se abren al tocarlas. Al buscar o filtrar
 * se abren solas las que tienen resultados.
 *
 * Cualquier cuenta lo ve, con cualquier rol. Un enlace de Conti llega con
 * ?document={id}: se abre su carpeta y ese documento se señala.
 */
const props = defineProps({
    folders: { type: Array, required: true },
    highlight: { type: Number, default: null },
});

const search = ref('');
const folderFilter = ref('');

const plain = (text) => (text ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

const visibleFolders = computed(() => {
    const term = plain(search.value.trim());

    return props.folders
        .filter((folder) => !folderFilter.value || folder.id === folderFilter.value)
        .map((folder) => ({
            ...folder,
            documents: term
                ? folder.documents.filter((d) => plain(`${d.title} ${d.description ?? ''} ${folder.name}`).includes(term))
                : folder.documents,
        }))
        .filter((folder) => folder.documents.length);
});

const totalDocuments = computed(() => props.folders.reduce((sum, folder) => sum + folder.documents.length, 0));

// Las carpetas abiertas, por id. Sin buscar ni filtrar, ninguna.
const open = ref(new Set());

function toggle(id) {
    if (open.value.has(id)) open.value.delete(id);
    else open.value.add(id);
}

// Buscando o con una carpeta elegida, se abren las que tienen resultados; al
// limpiar, vuelven a cerrarse.
watch([() => search.value.trim(), folderFilter], ([term, folder]) => {
    open.value = new Set(term || folder ? visibleFolders.value.map((f) => f.id) : []);
});

const longDate = new Intl.DateTimeFormat('es-CR', { dateStyle: 'long' });

function pdfUrl(doc, download = false) {
    return route('repository.pdf', download ? { document: doc.id, download: 1 } : { document: doc.id });
}

// El documento que señaló Conti: su carpeta abierta, el documento a la vista,
// y marcado un momento.
const highlighted = ref(null);
let highlightTimer = null;

watch(() => props.highlight, async (id) => {
    if (!id) return;

    search.value = '';
    folderFilter.value = '';
    highlighted.value = id;

    // Primero que el watch de la búsqueda cierre todo; después se abre la suya.
    await nextTick();
    const folder = props.folders.find((f) => f.documents.some((d) => d.id === id));
    if (folder) open.value.add(folder.id);

    await nextTick();
    document.getElementById(`repository-document-${id}`)?.scrollIntoView({ block: 'center', behavior: 'smooth' });

    clearTimeout(highlightTimer);
    highlightTimer = setTimeout(() => { highlighted.value = null; }, 3500);
}, { immediate: true });

onBeforeUnmount(() => clearTimeout(highlightTimer));
</script>

<template>
    <Head title="Biblioteca" />

    <AppLayout title="Biblioteca">
        <div v-if="totalDocuments" class="view-toolbar">
            <div class="view-filters">
                <label class="filter-field filter-search">
                    <span>Buscar</span>
                    <input v-model="search" type="search" placeholder="Título o tema">
                </label>
                <label v-if="folders.length > 1" class="filter-field">
                    <span>Carpeta</span>
                    <select v-model="folderFilter">
                        <option value="">Todas</option>
                        <option v-for="folder in folders" :key="folder.id" :value="folder.id">{{ folder.name }}</option>
                    </select>
                </label>
            </div>
        </div>

        <p class="hint">
            Leyes, reglamentos, resoluciones de Hacienda y artículos que publica el equipo de Pivox. Cada documento se abre
            como PDF. Es material de consulta: las decisiones tributarias y legales confirmalas con tu contador o asesor.
        </p>

        <section
            v-for="folder in visibleFolders"
            :key="folder.id"
            class="card repository-folder"
            :class="{ 'is-open': open.has(folder.id) }"
            :aria-labelledby="`repository-folder-${folder.id}`"
        >
            <!-- El botón va dentro del título (el patrón de acordeón de WAI-ARIA):
                 un h2 no puede ir dentro de un botón. -->
            <h2 class="repository-folder-heading">
                <button
                    type="button"
                    class="repository-folder-head"
                    :aria-expanded="open.has(folder.id)"
                    :aria-controls="`repository-folder-documents-${folder.id}`"
                    @click="toggle(folder.id)"
                >
                    <span class="repository-folder-icon">
                        <FolderOpenIcon v-if="open.has(folder.id)" :size="18" />
                        <FolderIcon v-else :size="18" />
                    </span>
                    <span class="repository-folder-text">
                        <span :id="`repository-folder-${folder.id}`" class="repository-folder-name">{{ folder.name }}</span>
                        <span v-if="folder.description" class="repository-folder-description muted small">{{ folder.description }}</span>
                    </span>
                    <span class="badge badge-neutral">{{ folder.documents.length }} {{ folder.documents.length === 1 ? 'documento' : 'documentos' }}</span>
                    <ChevronDownIcon class="repository-folder-chevron" />
                </button>
            </h2>

            <ul v-show="open.has(folder.id)" :id="`repository-folder-documents-${folder.id}`" class="repository-documents">
                <li
                    v-for="document in folder.documents"
                    :id="`repository-document-${document.id}`"
                    :key="document.id"
                    class="repository-document"
                    :class="{ 'is-highlighted': highlighted === document.id }"
                >
                    <span class="repository-document-icon"><FileTextIcon :size="18" /></span>
                    <div class="repository-document-main">
                        <a :href="pdfUrl(document)" target="_blank" rel="noopener" class="repository-document-title">{{ document.title }}</a>
                        <p v-if="document.description" class="repository-document-description">{{ document.description }}</p>
                        <span class="muted small">Actualizado el {{ longDate.format(new Date(document.updated_at)) }}</span>
                    </div>
                    <div class="repository-document-actions">
                        <a :href="pdfUrl(document)" target="_blank" rel="noopener" class="btn btn-ghost"><EyeIcon /> Ver PDF</a>
                        <a :href="pdfUrl(document, true)" class="btn btn-ghost" data-download><DownloadIcon /> Descargar</a>
                    </div>
                </li>
            </ul>
        </section>

        <div v-if="!visibleFolders.length" class="card empty-card">
            <template v-if="totalDocuments">Ningún documento coincide con la búsqueda.</template>
            <template v-else>Todavía no hay documentos en la Biblioteca. Cuando el equipo de Pivox publique alguno, va a aparecer acá.</template>
        </div>
    </AppLayout>
</template>

<style scoped>
.filter-search input {
    width: 18rem;
    max-width: 100%;
}

.repository-folder {
    margin-bottom: 1rem;
    padding: 0;
    overflow: hidden;
}

.repository-folder-heading {
    margin: 0;
    font-size: inherit;
}

.repository-folder-head {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    width: 100%;
    padding: 0.9rem 1.1rem;
    border: 0;
    background: var(--color-surface-alt);
    color: inherit;
    font: inherit;
    text-align: left;
    cursor: pointer;
    transition: background-color 0.15s ease;
}

.repository-folder-head:hover {
    background: color-mix(in srgb, var(--color-primary) 6%, var(--color-surface-alt));
}

.repository-folder-head:focus-visible {
    outline: 2px solid var(--color-primary);
    outline-offset: -2px;
}

/* Abierta, la raya separa el encabezado de sus documentos. */
.repository-folder.is-open .repository-folder-head {
    border-bottom: 1px solid var(--color-border);
}

.repository-folder-chevron {
    flex-shrink: 0;
    color: var(--color-text-muted);
    transition: transform 0.2s ease;
}

.repository-folder.is-open .repository-folder-chevron {
    transform: rotate(180deg);
}

@media (prefers-reduced-motion: reduce) {
    .repository-folder-head,
    .repository-folder-chevron {
        transition: none;
    }
}

.repository-folder-icon {
    flex-shrink: 0;
    display: inline-grid;
    place-items: center;
    width: 2.1rem;
    height: 2.1rem;
    border-radius: var(--radius-sm);
    background: color-mix(in srgb, var(--color-primary) 12%, transparent);
    color: var(--color-primary);
}

.repository-folder-text {
    flex: 1;
    min-width: 0;
}

.repository-folder-name {
    display: block;
    font-size: 0.98rem;
    font-weight: 700;
}

.repository-folder-description {
    display: block;
    margin-top: 0.15rem;
    font-weight: 400;
}

.repository-documents {
    margin: 0;
    padding: 0;
    list-style: none;
}

.repository-document {
    display: flex;
    align-items: center;
    gap: 0.85rem;
    padding: 0.85rem 1.1rem;
    border-top: 1px solid var(--color-border);
    transition: background-color 0.6s ease;
}

.repository-document:first-child {
    border-top: 0;
}

/* El documento al que llevó un enlace de Conti. */
.repository-document.is-highlighted {
    background: color-mix(in srgb, var(--color-accent) 16%, transparent);
}

.repository-document-icon {
    flex-shrink: 0;
    color: var(--color-text-muted);
}

.repository-document-main {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 0.15rem;
}

.repository-document-title {
    font-weight: 700;
    color: var(--color-text);
    text-decoration: none;
    overflow-wrap: anywhere;
}

.repository-document-title:hover,
.repository-document-title:focus-visible {
    color: var(--color-primary);
    text-decoration: underline;
}

.repository-document-description {
    margin: 0;
    font-size: 0.84rem;
    line-height: 1.45;
    overflow-wrap: anywhere;
}

.repository-document-actions {
    display: flex;
    flex-wrap: wrap;
    justify-content: flex-end;
    gap: 0.4rem;
}

.empty-card {
    padding: 1.5rem;
    text-align: center;
    color: var(--color-text-muted);
}

/* En un teléfono, las acciones bajan debajo del título, a lo ancho. */
@media (max-width: 640px) {
    .repository-document {
        flex-wrap: wrap;
        align-items: flex-start;
    }

    .repository-document-actions {
        flex-basis: 100%;
        justify-content: stretch;
    }

    .repository-document-actions .btn {
        flex: 1 1 0;
        justify-content: center;
    }
}
</style>
