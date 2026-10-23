<script setup>
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import BackofficeLayout from '../../../Layouts/BackofficeLayout.vue';
import DetailModal from '../../../Components/DetailModal.vue';
import { confirmAction } from '../../../Utils/confirm';
import { fullDate, timeAgo } from '../../../Utils/relativeTime';
import { DownloadIcon, EyeIcon, FileTextIcon, FolderIcon, FolderPlusIcon, PencilIcon, PlusIcon, Trash2Icon, UploadIcon } from '@lucide/vue';

/**
 * El Repositorio desde el backoffice (RepositoryController, CLAUDE.md secc.
 * 39): documentos de consulta en Markdown, agrupados en carpetas. De cada uno
 * se decide, por separado, si Conti lo consulta y si las cuentas lo ven en su
 * Repositorio, como PDF.
 *
 * Tabla, ficha y formulario en el mismo modal (CLAUDE.md secc. 20 y 21). Las
 * carpetas, en su propia ventana («Carpetas»). Los filtros son del navegador:
 * el catálogo es chico y llega entero.
 */
const props = defineProps({
    folders: { type: Array, required: true },
    documents: { type: Array, required: true },
    maxMegabytes: { type: Number, required: true },
});

const page = usePage();

// ── Filtros ───────────────────────────────────────────────────────────────
const search = ref('');
const folderFilter = ref('');
const stateFilter = ref('');

const STATES = {
    conti: { label: 'Para Conti', test: (d) => d.conti_enabled },
    public: { label: 'Visibles para las cuentas', test: (d) => d.public_enabled },
    inactive: { label: 'Sin activar', test: (d) => !d.conti_enabled && !d.public_enabled },
};

const plain = (text) => (text ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

const rows = computed(() => {
    const term = plain(search.value.trim());

    return props.documents.filter((d) => (!folderFilter.value || d.folder_id === folderFilter.value)
        && (!stateFilter.value || STATES[stateFilter.value].test(d))
        && (!term || plain(`${d.title} ${d.description ?? ''} ${d.original_name}`).includes(term)));
});

function formatSize(bytes) {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`;

    return `${(bytes / 1024 / 1024).toLocaleString('es-CR', { maximumFractionDigits: 1 })} MB`;
}

// ── Documento: ficha, alta y edición ──────────────────────────────────────
const NEW_FOLDER = 'new';

const selectedId = ref(null);
const mode = ref('details'); // 'create' | 'details' | 'edit'
const selected = computed(() => props.documents.find((d) => d.id === selectedId.value) ?? null);
const modalOpen = computed(() => mode.value === 'create' || !!selected.value);
const modalTitle = computed(() => (mode.value === 'create' ? 'Nuevo documento' : selected.value?.title ?? ''));

const form = useForm({
    title: '', description: '', folder_id: '', new_folder: '', file: null, conti_enabled: false, public_enabled: false,
});
const fileName = ref('');
const fileError = ref('');
const fileInput = ref(null);

function fill(values) {
    form.clearErrors();
    for (const [key, value] of Object.entries(values)) form[key] = value;
    fileName.value = '';
    fileError.value = '';
}

function openCreate() {
    fill({
        title: '', description: '',
        // Sin carpetas todavía, el formulario arranca pidiendo una nueva.
        folder_id: props.folders.length ? (folderFilter.value || '') : NEW_FOLDER,
        new_folder: '', file: null, conti_enabled: false, public_enabled: false,
    });
    selectedId.value = null;
    mode.value = 'create';
}

function openDocument(document) {
    selectedId.value = document.id;
    mode.value = 'details';
}

function startEdit() {
    const document = selected.value;
    fill({
        title: document.title, description: document.description ?? '', folder_id: document.folder_id,
        new_folder: '', file: null, conti_enabled: document.conti_enabled, public_enabled: document.public_enabled,
    });
    mode.value = 'edit';
}

function close() {
    if (mode.value === 'edit') {
        mode.value = 'details';
        return;
    }

    selectedId.value = null;
    mode.value = 'details';
}

// «ley-del-iva_2026.md» → «Ley del iva 2026», como punto de partida.
function titleFromFile(name) {
    const base = name.replace(/\.(md|markdown)$/i, '').replace(/[-_]+/g, ' ').replace(/\s+/g, ' ').trim();

    return base.charAt(0).toUpperCase() + base.slice(1);
}

function pickFile(event) {
    const file = event.target.files?.[0];
    event.target.value = '';
    fileError.value = '';
    if (!file) return;

    if (!/\.(md|markdown)$/i.test(file.name)) {
        fileError.value = 'El archivo tiene que ser un Markdown (.md).';
        return;
    }
    if (file.size > props.maxMegabytes * 1024 * 1024) {
        fileError.value = `El archivo puede pesar hasta ${props.maxMegabytes} MB.`;
        return;
    }

    form.file = file;
    fileName.value = `${file.name} · ${formatSize(file.size)}`;
    if (!form.title.trim()) form.title = titleFromFile(file.name);
}

function submit() {
    const creating = mode.value === 'create';
    const options = {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            if (creating) close();
            else mode.value = 'details';
        },
    };

    // transform() queda puesto para los envíos siguientes: se fija en cada uno.
    form.transform((data) => ({
        title: data.title,
        description: data.description,
        folder_id: data.folder_id === NEW_FOLDER ? '' : data.folder_id,
        new_folder: data.folder_id === NEW_FOLDER ? data.new_folder : '',
        ...(data.file ? { file: data.file } : {}),
        conti_enabled: data.conti_enabled ? 1 : 0,
        public_enabled: data.public_enabled ? 1 : 0,
        // Con un archivo, el envío es multipart: PUT no lo admite, así que
        // va como POST con _method (lo entiende Laravel).
        ...(creating ? {} : { _method: 'put' }),
    }));

    form.post(creating
        ? route('backoffice.repository.documents.store')
        : route('backoffice.repository.documents.update', selected.value.id), options);
}

// Los interruptores se guardan al tocarlos.
const savingFlag = ref(null);

function setAvailability(flag, value) {
    router.put(route('backoffice.repository.documents.availability', selected.value.id), { [flag]: value ? 1 : 0 }, {
        preserveScroll: true,
        onStart: () => { savingFlag.value = flag; },
        onFinish: () => { savingFlag.value = null; },
    });
}

function destroy() {
    const document = selected.value;

    confirmAction({
        title: 'Eliminar documento',
        message: `«${document.title}» se borra del Repositorio, junto con su archivo: Conti deja de consultarlo y las cuentas de verlo. No se puede deshacer.`,
        confirmLabel: 'Eliminar',
        danger: true,
        onConfirm: () => router.delete(route('backoffice.repository.documents.destroy', document.id), {
            preserveScroll: true,
            onSuccess: () => { selectedId.value = null; },
        }),
    });
}

// ── Carpetas ──────────────────────────────────────────────────────────────
const foldersOpen = ref(false);
const folderMode = ref('list'); // 'list' | 'create' | 'edit'
const editingFolderId = ref(null);
const editingFolder = computed(() => props.folders.find((f) => f.id === editingFolderId.value) ?? null);
const folderForm = useForm({ name: '', description: '' });
const folderError = computed(() => page.props.errors?.folder ?? '');

const foldersTitle = computed(() => ({
    list: 'Carpetas',
    create: 'Nueva carpeta',
    edit: editingFolder.value?.name ?? 'Carpeta',
}[folderMode.value]));

function openFolders() {
    folderMode.value = 'list';
    foldersOpen.value = true;
}

function newFolder() {
    folderForm.clearErrors();
    folderForm.name = '';
    folderForm.description = '';
    editingFolderId.value = null;
    folderMode.value = 'create';
}

function editFolder(folder) {
    folderForm.clearErrors();
    folderForm.name = folder.name;
    folderForm.description = folder.description ?? '';
    editingFolderId.value = folder.id;
    folderMode.value = 'edit';
}

function closeFolders() {
    if (folderMode.value !== 'list') {
        folderMode.value = 'list';
        return;
    }

    foldersOpen.value = false;
}

function saveFolder() {
    const options = { preserveScroll: true, onSuccess: () => { folderMode.value = 'list'; } };

    if (folderMode.value === 'create') folderForm.post(route('backoffice.repository.folders.store'), options);
    else folderForm.put(route('backoffice.repository.folders.update', editingFolderId.value), options);
}

function deleteFolder() {
    const folder = editingFolder.value;

    confirmAction({
        title: 'Eliminar carpeta',
        message: `La carpeta «${folder.name}» se elimina. No tiene documentos.`,
        confirmLabel: 'Eliminar',
        danger: true,
        onConfirm: () => router.delete(route('backoffice.repository.folders.destroy', folder.id), {
            preserveScroll: true,
            onSuccess: () => { folderMode.value = 'list'; },
        }),
    });
}
</script>

<template>
    <Head title="Repositorio" />

    <BackofficeLayout title="Repositorio">
        <div class="view-toolbar">
            <div class="view-actions">
                <button type="button" class="btn btn-ghost" @click="openFolders"><FolderIcon /> Carpetas</button>
                <button type="button" class="btn btn-primary" @click="openCreate"><PlusIcon /> Crear nuevo</button>
            </div>
            <div class="view-filters">
                <label class="filter-field filter-search">
                    <span>Buscar</span>
                    <input v-model="search" type="search" placeholder="Título, descripción o archivo">
                </label>
                <label class="filter-field">
                    <span>Carpeta</span>
                    <select v-model="folderFilter">
                        <option value="">Todas</option>
                        <option v-for="folder in folders" :key="folder.id" :value="folder.id">{{ folder.name }}</option>
                    </select>
                </label>
                <label class="filter-field">
                    <span>Estado</span>
                    <select v-model="stateFilter">
                        <option value="">Todos</option>
                        <option v-for="(state, key) in STATES" :key="key" :value="key">{{ state.label }}</option>
                    </select>
                </label>
            </div>
        </div>

        <p class="hint">
            Documentos de consulta en Markdown (.md): leyes, reglamentos, resoluciones de Hacienda, artículos. De cada uno
            decidís si Conti lo consulta para responder y si las cuentas lo ven en su Repositorio, como PDF y agrupado en
            su carpeta. Revisá el PDF antes de activarlo.
        </p>

        <div class="card">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Documento</th>
                            <th>Carpeta</th>
                            <!-- Los dos interruptores se ven en el listado (CLAUDE.md
                                 secc. 20): son lo que se decide de cada documento. -->
                            <th>Conti</th>
                            <th>Cuentas</th>
                            <th>Archivo subido</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="document in rows"
                            :key="document.id"
                            class="clickable-row"
                            tabindex="0"
                            @click="openDocument(document)"
                            @keydown.enter="openDocument(document)"
                            @keydown.space.prevent="openDocument(document)"
                        >
                            <td>
                                <strong>{{ document.title }}</strong>
                                <span class="block muted small">{{ document.original_name }} · {{ formatSize(document.size) }}</span>
                            </td>
                            <td data-label="Carpeta">{{ document.folder }}</td>
                            <td data-label="Conti">
                                <span class="badge" :class="document.conti_enabled ? 'badge-success' : 'badge-neutral'">{{ document.conti_enabled ? 'Lo consulta' : 'No' }}</span>
                            </td>
                            <td data-label="Cuentas">
                                <span class="badge" :class="document.public_enabled ? 'badge-success' : 'badge-neutral'">{{ document.public_enabled ? 'Visible' : 'Oculto' }}</span>
                            </td>
                            <td data-label="Archivo subido">
                                <time v-if="document.file_updated_at" :datetime="document.file_updated_at" :title="fullDate(document.file_updated_at)">{{ timeAgo(document.file_updated_at) }}</time>
                            </td>
                        </tr>
                        <tr v-if="!rows.length">
                            <td colspan="5" class="muted empty-row">
                                <template v-if="documents.length">Ningún documento coincide con los filtros.</template>
                                <template v-else>Todavía no hay documentos. Subí el primero con «Crear nuevo».</template>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Documento -->
        <DetailModal :open="modalOpen" :title="modalTitle" wide @close="close">
            <template v-if="selected && mode === 'details'">
                <p v-if="selected.description" class="document-description">{{ selected.description }}</p>

                <fieldset class="availability">
                    <legend>Disponibilidad</legend>
                    <label class="check">
                        <input
                            type="checkbox"
                            :checked="selected.conti_enabled"
                            :disabled="savingFlag !== null"
                            @change="setAvailability('conti_enabled', $event.target.checked)"
                        >
                        <span>
                            <strong>Conti lo consulta</strong>
                            <span class="block muted small">Lo busca para responder sobre normativa, y lo cita.</span>
                        </span>
                    </label>
                    <label class="check">
                        <input
                            type="checkbox"
                            :checked="selected.public_enabled"
                            :disabled="savingFlag !== null"
                            @change="setAvailability('public_enabled', $event.target.checked)"
                        >
                        <span>
                            <strong>Visible para las cuentas</strong>
                            <span class="block muted small">Aparece en Repositorio, en la carpeta «{{ selected.folder }}», como PDF.</span>
                        </span>
                    </label>
                </fieldset>

                <dl class="detail-list">
                    <div>
                        <dt>Carpeta</dt>
                        <dd>{{ selected.folder }}</dd>
                    </div>
                    <div>
                        <dt>Archivo</dt>
                        <dd>{{ selected.original_name }} · {{ formatSize(selected.size) }}</dd>
                    </div>
                    <div>
                        <dt>En el servidor</dt>
                        <dd><code>public/repositories/{{ selected.file_path }}</code></dd>
                    </div>
                    <div v-if="selected.file_updated_at">
                        <dt>Archivo subido</dt>
                        <dd>{{ fullDate(selected.file_updated_at) }}<template v-if="selected.updated_by"> por {{ selected.updated_by }}</template></dd>
                    </div>
                    <div>
                        <dt>Creado</dt>
                        <dd>{{ fullDate(selected.created_at) }}<template v-if="selected.created_by"> por {{ selected.created_by }}</template></dd>
                    </div>
                </dl>
            </template>

            <form v-if="mode === 'create' || (selected && mode === 'edit')" id="repository-document-form" @submit.prevent="submit">
                <div class="form-grid">
                    <div class="field span-full">
                        <span class="field-label">Archivo (.md)</span>
                        <div class="file-field">
                            <span class="file-name" :class="{ 'is-empty': !fileName }">
                                <FileTextIcon />
                                {{ fileName || (mode === 'edit' ? `${selected.original_name} · se conserva` : 'Ningún archivo elegido') }}
                            </span>
                            <button type="button" class="btn btn-ghost" @click="fileInput?.click()">
                                <UploadIcon /> {{ mode === 'edit' || fileName ? 'Reemplazar archivo' : 'Elegir archivo' }}
                            </button>
                            <input ref="fileInput" type="file" class="sr-only" tabindex="-1" aria-hidden="true" accept=".md,.markdown,text/markdown" @change="pickFile">
                        </div>
                        <span class="field-hint muted">Markdown, hasta {{ maxMegabytes }} MB. Los encabezados (#, ##) ordenan el PDF y ayudan a Conti a encontrar cada sección.</span>
                        <span v-if="fileError || form.errors.file" class="error">{{ fileError || form.errors.file }}</span>
                    </div>
                    <div class="field span-full">
                        <label for="repository-title">Título</label>
                        <input id="repository-title" v-model="form.title" type="text" maxlength="200" placeholder="Si lo dejás vacío, sale del primer encabezado del archivo">
                        <span v-if="form.errors.title" class="error">{{ form.errors.title }}</span>
                    </div>
                    <div class="field">
                        <label for="repository-folder">Carpeta</label>
                        <select id="repository-folder" v-model="form.folder_id" required>
                            <option value="" disabled>Elegí una carpeta</option>
                            <option v-for="folder in folders" :key="folder.id" :value="folder.id">{{ folder.name }}</option>
                            <option :value="NEW_FOLDER">Nueva carpeta…</option>
                        </select>
                        <span v-if="form.errors.folder_id" class="error">{{ form.errors.folder_id }}</span>
                    </div>
                    <div v-if="form.folder_id === NEW_FOLDER" class="field">
                        <label for="repository-new-folder">Nombre de la carpeta nueva</label>
                        <input id="repository-new-folder" v-model="form.new_folder" type="text" maxlength="100" placeholder="Ej. Leyes, Resoluciones DGT" required>
                        <span v-if="form.errors.new_folder" class="error">{{ form.errors.new_folder }}</span>
                    </div>
                    <div class="field span-full">
                        <label for="repository-description">Descripción (opcional)</label>
                        <textarea id="repository-description" v-model="form.description" rows="3" maxlength="1000" placeholder="De qué trata, a quién aplica, desde cuándo rige" />
                        <span v-if="form.errors.description" class="error">{{ form.errors.description }}</span>
                    </div>
                    <div v-if="mode === 'create'" class="field span-full availability-fields">
                        <label class="check">
                            <input v-model="form.conti_enabled" type="checkbox">
                            Conti lo consulta
                        </label>
                        <label class="check">
                            <input v-model="form.public_enabled" type="checkbox">
                            Visible para las cuentas
                        </label>
                        <span class="field-hint muted">Podés dejarlo sin activar, revisar el PDF y activarlo después desde su ficha.</span>
                    </div>
                </div>
            </form>

            <template #actions>
                <template v-if="selected && mode === 'details'">
                    <button type="button" class="btn btn-ghost btn-danger-text footer-start" @click="destroy"><Trash2Icon /> Eliminar</button>
                    <a :href="route('backoffice.repository.documents.markdown', selected.id)" class="btn btn-ghost" data-download><DownloadIcon /> Descargar .md</a>
                    <a :href="route('backoffice.repository.documents.pdf', selected.id)" class="btn btn-ghost" target="_blank" rel="noopener"><EyeIcon /> Ver PDF</a>
                    <button type="button" class="btn btn-primary" @click="startEdit"><PencilIcon /> Editar</button>
                </template>
                <template v-else-if="mode === 'create' || mode === 'edit'">
                    <button type="button" class="btn btn-ghost" @click="close">Cancelar</button>
                    <button type="submit" form="repository-document-form" class="btn btn-primary" :disabled="form.processing">
                        {{ mode === 'create' ? 'Subir documento' : 'Guardar cambios' }}
                    </button>
                </template>
            </template>
        </DetailModal>

        <!-- Carpetas -->
        <DetailModal :open="foldersOpen" :title="foldersTitle" @close="closeFolders">
            <template v-if="folderMode === 'list'">
                <p class="hint small folder-hint">Las cuentas ven los documentos agrupados en estas carpetas, en orden alfabético. Una carpeta sin documentos visibles no les aparece.</p>
                <!-- Dos columnas cortas: sigue siendo tabla en un teléfono. -->
                <div class="table-responsive no-cards">
                    <table>
                        <thead>
                            <tr>
                                <th>Carpeta</th>
                                <th>Documentos</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="folder in folders"
                                :key="folder.id"
                                class="clickable-row"
                                tabindex="0"
                                @click="editFolder(folder)"
                                @keydown.enter="editFolder(folder)"
                                @keydown.space.prevent="editFolder(folder)"
                            >
                                <td>
                                    <strong>{{ folder.name }}</strong>
                                    <span v-if="folder.description" class="block muted small">{{ folder.description }}</span>
                                </td>
                                <td data-label="Documentos">{{ folder.documents_count }}</td>
                            </tr>
                            <tr v-if="!folders.length">
                                <td colspan="2" class="muted empty-row">Todavía no hay carpetas. Creá la primera, o una nueva al subir un documento.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </template>

            <form v-else id="repository-folder-form" @submit.prevent="saveFolder">
                <p v-if="folderError" class="flash flash-error" role="alert">{{ folderError }}</p>
                <div class="form-grid">
                    <div class="field span-full">
                        <label for="folder-name">Nombre</label>
                        <input id="folder-name" v-model="folderForm.name" type="text" maxlength="100" required>
                        <span v-if="folderForm.errors.name" class="error">{{ folderForm.errors.name }}</span>
                    </div>
                    <div class="field span-full">
                        <label for="folder-description">Descripción (opcional)</label>
                        <textarea id="folder-description" v-model="folderForm.description" rows="2" maxlength="500" />
                        <span v-if="folderForm.errors.description" class="error">{{ folderForm.errors.description }}</span>
                    </div>
                </div>
                <p v-if="folderMode === 'edit' && editingFolder?.documents_count" class="hint small">
                    Tiene {{ editingFolder.documents_count }} {{ editingFolder.documents_count === 1 ? 'documento' : 'documentos' }}:
                    para eliminarla, movelos a otra carpeta o eliminalos primero.
                </p>
            </form>

            <template #actions>
                <template v-if="folderMode === 'list'">
                    <button type="button" class="btn btn-primary" @click="newFolder"><FolderPlusIcon /> Nueva carpeta</button>
                </template>
                <template v-else>
                    <button
                        v-if="folderMode === 'edit'"
                        type="button"
                        class="btn btn-ghost btn-danger-text footer-start"
                        :disabled="editingFolder?.documents_count > 0"
                        @click="deleteFolder"
                    ><Trash2Icon /> Eliminar</button>
                    <button type="button" class="btn btn-ghost" @click="folderMode = 'list'">Cancelar</button>
                    <button type="submit" form="repository-folder-form" class="btn btn-primary" :disabled="folderForm.processing">
                        {{ folderMode === 'create' ? 'Crear carpeta' : 'Guardar cambios' }}
                    </button>
                </template>
            </template>
        </DetailModal>
    </BackofficeLayout>
</template>

<style scoped>
.filter-search input {
    width: 18rem;
    max-width: 100%;
}

.document-description {
    margin: 0 0 1rem;
    font-size: 0.88rem;
    line-height: 1.55;
    white-space: pre-line;
    overflow-wrap: anywhere;
}

.availability {
    display: grid;
    gap: 0.6rem;
    margin: 0 0 1rem;
    padding: 0.75rem 0.9rem;
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
}

.availability legend {
    padding: 0 0.3rem;
    font-size: 0.78rem;
    font-weight: 700;
}

.availability .check {
    align-items: flex-start;
}

.availability .check input {
    margin-top: 0.2rem;
}

.availability-fields {
    display: flex;
    flex-wrap: wrap;
    gap: 0.4rem 1.25rem;
}

.availability-fields .field-hint {
    flex-basis: 100%;
}

.field-label {
    display: block;
    margin-bottom: 0.3rem;
    font-size: 0.78rem;
    font-weight: 600;
}

.field-hint {
    display: block;
    margin-top: 0.2rem;
    font-size: 0.75rem;
}

.field textarea {
    width: 100%;
}

.file-field {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.5rem 0.75rem;
}

.file-name {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    min-width: 0;
    font-size: 0.86rem;
    overflow-wrap: anywhere;
}

.file-name.is-empty {
    color: var(--color-text-muted);
}

.folder-hint {
    margin-top: 0;
}

/* Eliminar va a la izquierda del pie (CLAUDE.md secc. 27). */
.footer-start {
    margin-right: auto;
}
</style>
