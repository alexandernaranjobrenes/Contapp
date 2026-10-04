<script setup>
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref } from 'vue';
import BackofficeLayout from '../../../Layouts/BackofficeLayout.vue';
import DetailModal from '../../../Components/DetailModal.vue';
import { confirmAction } from '../../../Utils/confirm';
import { fullDate, timeAgo } from '../../../Utils/relativeTime';
import { ExternalLinkIcon, ImageIcon, PencilIcon, PlusIcon, Trash2Icon, UploadIcon, XIcon } from '@lucide/vue';

/**
 * Las noticias de CONTAPP (NewsController): las que todas las cuentas ven en
 * la pestaña «Noticias» del panel de la barra superior. Título, descripción,
 * un enlace opcional (otro sitio, o una pantalla de CONTAPP) y una imagen
 * opcional.
 *
 * Tabla, ficha y formulario en el mismo modal (CLAUDE.md secc. 20 y 21).
 */
const props = defineProps({
    news: { type: Object, required: true },
    imageRules: { type: Object, required: true },
});

const rows = computed(() => props.news.data ?? []);

const selectedId = ref(null);
const mode = ref('details'); // 'create' | 'details' | 'edit'
const selected = computed(() => rows.value.find((n) => n.id === selectedId.value) ?? null);
const modalOpen = computed(() => mode.value === 'create' || !!selected.value);
const modalTitle = computed(() => (mode.value === 'create' ? 'Nueva noticia' : selected.value?.title ?? ''));

const form = useForm({ title: '', body: '', link_url: '', image: null, remove_image: false });

// La vista previa de la imagen: la elegida, o la que ya tenía la noticia.
const imagePreview = ref(null);
const imageError = ref('');
const imageInput = ref(null);

function clearPreview() {
    if (imagePreview.value?.startsWith('blob:')) URL.revokeObjectURL(imagePreview.value);
    imagePreview.value = null;
}

function fill(values, preview = null) {
    form.clearErrors();
    for (const [key, value] of Object.entries(values)) form[key] = value;
    clearPreview();
    imagePreview.value = preview;
    imageError.value = '';
}

function openCreate() {
    fill({ title: '', body: '', link_url: '', image: null, remove_image: false });
    selectedId.value = null;
    mode.value = 'create';
}

function openNews(item) {
    selectedId.value = item.id;
    mode.value = 'details';
}

function startEdit() {
    const item = selected.value;
    fill({ title: item.title, body: item.body, link_url: item.link_url ?? '', image: null, remove_image: false }, item.image_url);
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

function pickImage(event) {
    const file = event.target.files?.[0];
    event.target.value = '';
    imageError.value = '';
    if (!file) return;

    if (!props.imageRules.accept.split(',').includes(file.type)) {
        imageError.value = `La imagen tiene que estar en ${props.imageRules.formats}.`;
        return;
    }
    if (file.size > props.imageRules.max_megabytes * 1024 * 1024) {
        imageError.value = `La imagen puede pesar hasta ${props.imageRules.max_megabytes} MB.`;
        return;
    }

    clearPreview();
    form.image = file;
    form.remove_image = false;
    imagePreview.value = URL.createObjectURL(file);
}

function removeImage() {
    clearPreview();
    form.image = null;
    form.remove_image = true;
}

function submit() {
    const options = {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            if (mode.value === 'create') close();
            else mode.value = 'details';
        },
    };

    // transform() queda puesto para los envíos siguientes: se fija en cada uno.
    if (mode.value === 'create') {
        form.transform((data) => ({ ...data, remove_image: 0 }))
            .post(route('backoffice.news.store'), options);
        return;
    }

    // Con un archivo, el envío es multipart: PUT no lo admite, así que va
    // como POST con _method (lo entiende Laravel).
    form.transform((data) => ({ ...data, remove_image: data.remove_image ? 1 : 0, _method: 'put' }))
        .post(route('backoffice.news.update', selected.value.id), options);
}

function destroy() {
    const item = selected.value;

    confirmAction({
        title: 'Eliminar noticia',
        message: `«${item.title}» deja de verse en la aplicación y se borra su imagen. No se puede deshacer.`,
        confirmLabel: 'Eliminar',
        danger: true,
        onConfirm: () => router.delete(route('backoffice.news.destroy', item.id), { preserveScroll: true }),
    });
}

function linkLabel(link) {
    if (!link) return '—';
    if (link.startsWith('/')) return `Pantalla de CONTAPP (${link})`;

    try {
        return new URL(link).host;
    } catch {
        return link;
    }
}

onBeforeUnmount(clearPreview);
</script>

<template>
    <Head title="Noticias" />

    <BackofficeLayout title="Noticias">
        <div class="view-toolbar">
            <div class="view-actions">
                <button type="button" class="btn btn-primary" @click="openCreate"><PlusIcon /> Crear nuevo</button>
            </div>
        </div>

        <p class="hint">
            Las novedades de CONTAPP que ven todas las cuentas en la pestaña «Noticias» del panel de la barra superior,
            de la más nueva a la más vieja. Una noticia nueva le enciende un punto a ese botón hasta que se ve.
        </p>

        <div class="card">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Noticia</th>
                            <th>Enlace</th>
                            <th>Publicada</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="item in rows"
                            :key="item.id"
                            class="clickable-row"
                            tabindex="0"
                            @click="openNews(item)"
                            @keydown.enter="openNews(item)"
                            @keydown.space.prevent="openNews(item)"
                        >
                            <td>
                                <span class="news-cell">
                                    <img v-if="item.image_url" :src="item.image_url" alt="" class="news-thumb" loading="lazy">
                                    <span v-else class="news-thumb is-empty" aria-hidden="true"><ImageIcon /></span>
                                    <span class="news-cell-text">
                                        <strong>{{ item.title }}</strong>
                                        <span class="block muted small news-excerpt">{{ item.body }}</span>
                                    </span>
                                </span>
                            </td>
                            <td data-label="Enlace">{{ linkLabel(item.link_url) }}</td>
                            <td data-label="Publicada">
                                <time :datetime="item.created_at" :title="fullDate(item.created_at)">{{ timeAgo(item.created_at) }}</time>
                            </td>
                        </tr>
                        <tr v-if="!rows.length">
                            <td colspan="3" class="muted empty-row">Todavía no hay noticias. Publicá la primera con «Crear nuevo».</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div v-if="news.last_page > 1" class="pagination">
            <Link
                v-for="link in news.links"
                :key="link.label"
                :href="link.url ?? ''"
                class="page-link"
                :class="{ active: link.active, disabled: !link.url }"
                preserve-scroll
                v-html="link.label"
            />
        </div>

        <DetailModal :open="modalOpen" :title="modalTitle" wide @close="close">
            <template v-if="selected && mode === 'details'">
                <img v-if="selected.image_url" :src="selected.image_url" alt="" class="news-image">
                <p class="news-body">{{ selected.body }}</p>
                <dl class="detail-list">
                    <div>
                        <dt>Enlace</dt>
                        <dd>
                            <a v-if="selected.link_url" :href="selected.link_url" target="_blank" rel="noopener noreferrer">
                                {{ linkLabel(selected.link_url) }} <ExternalLinkIcon />
                            </a>
                            <template v-else>Sin enlace</template>
                        </dd>
                    </div>
                    <div>
                        <dt>Publicada</dt>
                        <dd>{{ fullDate(selected.created_at) }}<template v-if="selected.created_by"> por {{ selected.created_by }}</template></dd>
                    </div>
                    <div v-if="selected.updated_at !== selected.created_at">
                        <dt>Última edición</dt>
                        <dd>{{ fullDate(selected.updated_at) }}</dd>
                    </div>
                </dl>
            </template>

            <form v-if="mode === 'create' || (selected && mode === 'edit')" id="news-form" @submit.prevent="submit">
                <div class="form-grid">
                    <div class="field span-full">
                        <label for="news-title">Título</label>
                        <input id="news-title" v-model="form.title" type="text" maxlength="150" required>
                        <span v-if="form.errors.title" class="error">{{ form.errors.title }}</span>
                    </div>
                    <div class="field span-full">
                        <label for="news-body">Descripción</label>
                        <textarea id="news-body" v-model="form.body" rows="5" maxlength="2000" required />
                        <span v-if="form.errors.body" class="error">{{ form.errors.body }}</span>
                    </div>
                    <div class="field span-full">
                        <label for="news-link">Enlace (opcional)</label>
                        <input id="news-link" v-model="form.link_url" type="text" maxlength="500" placeholder="https://… o /dashboard" aria-describedby="news-link-hint">
                        <span id="news-link-hint" class="field-hint muted">
                            Una dirección completa se abre en otra pestaña. Una pantalla de CONTAPP (que empieza con «/»)
                            se abre en la misma.
                        </span>
                        <span v-if="form.errors.link_url" class="error">{{ form.errors.link_url }}</span>
                    </div>
                    <div class="field span-full">
                        <span class="field-label">Imagen (opcional)</span>
                        <div class="image-field">
                            <img v-if="imagePreview" :src="imagePreview" alt="Vista previa de la imagen" class="image-preview">
                            <span v-else class="image-preview is-empty"><ImageIcon :size="22" /> Sin imagen</span>
                            <div class="image-actions">
                                <button type="button" class="btn btn-ghost" @click="imageInput?.click()">
                                    <UploadIcon /> {{ imagePreview ? 'Cambiar imagen' : 'Elegir imagen' }}
                                </button>
                                <button v-if="imagePreview" type="button" class="btn btn-ghost btn-danger-text" @click="removeImage">
                                    <XIcon /> Quitar
                                </button>
                                <span class="field-hint muted">{{ imageRules.formats }}, hasta {{ imageRules.max_megabytes }} MB. Se ve a lo ancho de la noticia (16:9).</span>
                            </div>
                            <input ref="imageInput" type="file" class="sr-only" tabindex="-1" aria-hidden="true" :accept="imageRules.accept" @change="pickImage">
                        </div>
                        <span v-if="imageError || form.errors.image" class="error">{{ imageError || form.errors.image }}</span>
                    </div>
                </div>
            </form>

            <template #actions>
                <template v-if="selected && mode === 'details'">
                    <button type="button" class="btn btn-ghost btn-danger-text footer-start" @click="destroy"><Trash2Icon /> Eliminar</button>
                    <button type="button" class="btn btn-primary" @click="startEdit"><PencilIcon /> Editar</button>
                </template>
                <template v-else-if="mode === 'create' || mode === 'edit'">
                    <button type="button" class="btn btn-ghost" @click="close">Cancelar</button>
                    <button type="submit" form="news-form" class="btn btn-primary" :disabled="form.processing">
                        {{ mode === 'create' ? 'Publicar' : 'Guardar cambios' }}
                    </button>
                </template>
            </template>
        </DetailModal>
    </BackofficeLayout>
</template>

<style scoped>
.news-cell {
    display: flex;
    align-items: center;
    gap: 0.7rem;
}

.news-cell-text {
    min-width: 0;
}

.news-excerpt {
    font-weight: 400;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    overflow-wrap: anywhere;
}

.news-thumb {
    flex-shrink: 0;
    display: block;
    width: 4.5rem;
    aspect-ratio: 16 / 9;
    border-radius: var(--radius-sm);
    object-fit: cover;
    background: var(--color-surface-alt);
}

.news-thumb.is-empty {
    display: grid;
    place-items: center;
    color: var(--color-text-muted);
}

.news-image {
    display: block;
    width: 100%;
    aspect-ratio: 16 / 9;
    object-fit: cover;
    border-radius: var(--radius-sm);
    margin-bottom: 0.9rem;
}

.news-body {
    margin: 0 0 1rem;
    font-size: 0.88rem;
    line-height: 1.55;
    white-space: pre-line;
    overflow-wrap: anywhere;
}

.field textarea {
    width: 100%;
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

.image-field {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.9rem;
}

.image-preview {
    flex-shrink: 0;
    width: 12rem;
    max-width: 100%;
    aspect-ratio: 16 / 9;
    border-radius: var(--radius-sm);
    border: 1px solid var(--color-border);
    object-fit: cover;
    background: var(--color-surface-alt);
}

.image-preview.is-empty {
    display: grid;
    place-items: center;
    align-content: center;
    gap: 0.25rem;
    font-size: 0.78rem;
    color: var(--color-text-muted);
}

.image-actions {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.5rem;
    flex: 1 1 14rem;
}

.image-actions .field-hint {
    flex-basis: 100%;
}

/* Eliminar va a la izquierda del pie (CLAUDE.md secc. 27). */
.footer-start {
    margin-right: auto;
}
</style>
