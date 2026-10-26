<script setup>
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import BackofficeLayout from '../../../Layouts/BackofficeLayout.vue';
import DetailModal from '../../../Components/DetailModal.vue';
import { confirmAction } from '../../../Utils/confirm';
import { fullDate, timeAgo } from '../../../Utils/relativeTime';
import { ExternalLinkIcon, EyeIcon, ImageIcon, NewspaperIcon, PencilIcon, PlusIcon, TagIcon, Trash2Icon } from '@lucide/vue';

/**
 * El blog del sitio público desde el backoffice (BlogPostController,
 * CLAUDE.md secc. 41): los artículos, publicados y en borrador. La fila abre
 * la ficha; escribir y editar es una pantalla propia (secc. 21). Las
 * categorías, en su ventana («Categorías»). Los filtros son del navegador.
 */
const props = defineProps({
    posts: { type: Array, required: true },
    categories: { type: Array, required: true },
});

const page = usePage();

// ── Filtros ───────────────────────────────────────────────────────────────
const search = ref('');
const statusFilter = ref('');
const categoryFilter = ref('');

const plain = (text) => (text ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

const rows = computed(() => {
    const term = plain(search.value.trim());

    return props.posts.filter((post) => (!statusFilter.value || (statusFilter.value === 'published' ? post.is_published : !post.is_published))
        && (!categoryFilter.value || post.category_id === categoryFilter.value)
        && (!term || plain(`${post.title} ${post.slug} ${post.excerpt ?? ''}`).includes(term)));
});

// ── Ficha ─────────────────────────────────────────────────────────────────
const selectedId = ref(null);
const selected = computed(() => props.posts.find((post) => post.id === selectedId.value) ?? null);

function destroy() {
    const post = selected.value;

    confirmAction({
        title: 'Eliminar artículo',
        message: `«${post.title}» se elimina con sus imágenes${post.has_news ? ' y su noticia' : ''}. Su dirección deja de existir. No se puede deshacer.`,
        confirmLabel: 'Eliminar',
        danger: true,
        onConfirm: () => router.delete(route('backoffice.blog.destroy', post.id), {
            preserveScroll: true,
            onSuccess: () => { selectedId.value = null; },
        }),
    });
}

// ── Categorías ────────────────────────────────────────────────────────────
const categoriesOpen = ref(false);
const categoryMode = ref('list'); // 'list' | 'create' | 'edit'
const editingId = ref(null);
const editing = computed(() => props.categories.find((category) => category.id === editingId.value) ?? null);
const categoryForm = useForm({ name: '', description: '' });
const categoryError = computed(() => page.props.errors?.category ?? '');

const categoriesTitle = computed(() => ({
    list: 'Categorías',
    create: 'Nueva categoría',
    edit: editing.value?.name ?? 'Categoría',
}[categoryMode.value]));

function openCategories() {
    categoryMode.value = 'list';
    categoriesOpen.value = true;
}

function newCategory() {
    categoryForm.clearErrors();
    categoryForm.name = '';
    categoryForm.description = '';
    editingId.value = null;
    categoryMode.value = 'create';
}

function editCategory(category) {
    categoryForm.clearErrors();
    categoryForm.name = category.name;
    categoryForm.description = category.description ?? '';
    editingId.value = category.id;
    categoryMode.value = 'edit';
}

function closeCategories() {
    if (categoryMode.value !== 'list') {
        categoryMode.value = 'list';
        return;
    }

    categoriesOpen.value = false;
}

function saveCategory() {
    const options = { preserveScroll: true, onSuccess: () => { categoryMode.value = 'list'; } };

    if (categoryMode.value === 'create') categoryForm.post(route('backoffice.blog.categories.store'), options);
    else categoryForm.put(route('backoffice.blog.categories.update', editingId.value), options);
}

function deleteCategory() {
    const category = editing.value;

    confirmAction({
        title: 'Eliminar categoría',
        message: `La categoría «${category.name}» se elimina. No tiene artículos.`,
        confirmLabel: 'Eliminar',
        danger: true,
        onConfirm: () => router.delete(route('backoffice.blog.categories.destroy', category.id), {
            preserveScroll: true,
            onSuccess: () => { categoryMode.value = 'list'; },
        }),
    });
}
</script>

<template>
    <Head title="Blog" />

    <BackofficeLayout title="Blog">
        <div class="view-toolbar">
            <div class="view-actions">
                <button type="button" class="btn btn-ghost" @click="openCategories"><TagIcon /> Categorías</button>
                <Link :href="route('backoffice.blog.create')" class="btn btn-primary"><PlusIcon /> Crear nuevo</Link>
            </div>
            <div class="view-filters">
                <label class="filter-field">
                    <span>Buscar</span>
                    <input v-model="search" type="search" placeholder="Título o dirección">
                </label>
                <label class="filter-field">
                    <span>Estado</span>
                    <select v-model="statusFilter">
                        <option value="">Todos</option>
                        <option value="published">Publicados</option>
                        <option value="draft">Borradores</option>
                    </select>
                </label>
                <label class="filter-field">
                    <span>Categoría</span>
                    <select v-model="categoryFilter">
                        <option value="">Todas</option>
                        <option v-for="category in categories" :key="category.id" :value="category.id">{{ category.name }}</option>
                    </select>
                </label>
            </div>
        </div>

        <p class="hint">
            Los artículos del blog del sitio (pivoxcr.com/blog). Se escriben como en Word, con imágenes, y cada uno puede
            avisarse en la pestaña Noticias de la aplicación. Un borrador no se ve en el sitio: revisalo con «Vista previa».
        </p>

        <div class="card">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Artículo</th>
                            <th>Estado</th>
                            <!-- El aviso en Noticias se ve en el listado: es lo que se decide de cada artículo (secc. 20). -->
                            <th>Noticias</th>
                            <th>Actualizado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="post in rows"
                            :key="post.id"
                            class="clickable-row"
                            tabindex="0"
                            @click="selectedId = post.id"
                            @keydown.enter="selectedId = post.id"
                            @keydown.space.prevent="selectedId = post.id"
                        >
                            <td>
                                <span class="post-cell">
                                    <img v-if="post.cover_url" :src="post.cover_url" alt="" class="post-thumb" loading="lazy">
                                    <span v-else class="post-thumb is-empty" aria-hidden="true"><ImageIcon /></span>
                                    <span class="post-cell-text">
                                        <strong>{{ post.title }}</strong>
                                        <span class="block muted small">{{ post.category ?? 'Sin categoría' }} · {{ post.reading_minutes }} min</span>
                                    </span>
                                </span>
                            </td>
                            <td data-label="Estado">
                                <span class="badge" :class="post.is_published ? 'badge-success' : 'badge-neutral'">{{ post.is_published ? 'Publicado' : 'Borrador' }}</span>
                            </td>
                            <td data-label="Noticias">
                                <span v-if="post.has_news" class="badge badge-success">Avisado</span>
                                <span v-else-if="post.announce_in_news" class="badge badge-warning">Al publicar</span>
                                <span v-else class="muted">—</span>
                            </td>
                            <td data-label="Actualizado">
                                <time :datetime="post.updated_at" :title="fullDate(post.updated_at)">{{ timeAgo(post.updated_at) }}</time>
                            </td>
                        </tr>
                        <tr v-if="!rows.length">
                            <td colspan="4" class="muted empty-row">
                                <template v-if="posts.length">Ningún artículo coincide con los filtros.</template>
                                <template v-else>Todavía no hay artículos. Escribí el primero con «Crear nuevo».</template>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- La ficha del artículo -->
        <DetailModal :open="!!selected" :title="selected?.title ?? ''" wide @close="selectedId = null">
            <template #badge>
                <span v-if="selected" class="badge" :class="selected.is_published ? 'badge-success' : 'badge-neutral'">{{ selected.is_published ? 'Publicado' : 'Borrador' }}</span>
            </template>

            <template v-if="selected">
                <img v-if="selected.cover_url" :src="selected.cover_url" alt="" class="post-cover">
                <p v-if="selected.excerpt" class="post-excerpt">{{ selected.excerpt }}</p>

                <dl class="detail-list">
                    <div class="full">
                        <dt>Dirección</dt>
                        <dd>
                            <a v-if="selected.url" :href="selected.url" target="_blank" rel="noopener">{{ selected.url }} <ExternalLinkIcon /></a>
                            <template v-else>/blog/{{ selected.slug }} (todavía no se ve: es un borrador)</template>
                        </dd>
                    </div>
                    <div>
                        <dt>Categoría</dt>
                        <dd>{{ selected.category ?? 'Sin categoría' }}</dd>
                    </div>
                    <div>
                        <dt>Publicado</dt>
                        <dd>{{ selected.published_at ? fullDate(selected.published_at) : 'Nunca' }}</dd>
                    </div>
                    <div>
                        <dt>Última edición</dt>
                        <dd>{{ fullDate(selected.updated_at) }}<template v-if="selected.updated_by"> por {{ selected.updated_by }}</template></dd>
                    </div>
                    <div>
                        <dt>Extensión</dt>
                        <dd>{{ selected.word_count }} palabras · {{ selected.reading_minutes }} min de lectura</dd>
                    </div>
                    <div class="full">
                        <dt>Así se ve en un buscador</dt>
                        <dd>
                            <span class="serp-title">{{ selected.seo_title }} | Pivox</span>
                            <span class="block muted small">{{ selected.meta_description }}</span>
                            <span v-if="selected.noindex" class="badge badge-warning">No se muestra en buscadores</span>
                        </dd>
                    </div>
                    <div class="full">
                        <dt>Noticias</dt>
                        <dd>
                            <template v-if="selected.has_news"><NewspaperIcon /> Avisado en la pestaña Noticias de la aplicación.</template>
                            <template v-else-if="selected.announce_in_news">Se avisa en Noticias cuando se publique.</template>
                            <template v-else>No se avisa en Noticias.</template>
                        </dd>
                    </div>
                </dl>
            </template>

            <template #actions>
                <template v-if="selected">
                    <button type="button" class="btn btn-ghost btn-danger-text footer-start" @click="destroy"><Trash2Icon /> Eliminar</button>
                    <a :href="selected.preview_url" target="_blank" rel="noopener" class="btn btn-ghost"><EyeIcon /> Vista previa</a>
                    <Link :href="selected.edit_url" class="btn btn-primary"><PencilIcon /> Editar</Link>
                </template>
            </template>
        </DetailModal>

        <!-- Las categorías -->
        <DetailModal :open="categoriesOpen" :title="categoriesTitle" @close="closeCategories">
            <template v-if="categoryMode === 'list'">
                <p class="hint">Agrupan los artículos y tienen su propia página en el sitio (/blog/categoria/…), con su descripción para los buscadores.</p>
                <p v-if="categoryError" class="flash flash-error">{{ categoryError }}</p>
                <ul v-if="categories.length" class="category-list">
                    <li v-for="category in categories" :key="category.id">
                        <button type="button" class="category-item" @click="editCategory(category)">
                            <span>
                                <strong>{{ category.name }}</strong>
                                <span class="block muted small">{{ category.posts_count }} {{ category.posts_count === 1 ? 'artículo' : 'artículos' }} · /blog/categoria/{{ category.slug }}</span>
                            </span>
                            <PencilIcon />
                        </button>
                    </li>
                </ul>
                <p v-else class="muted">Todavía no hay categorías.</p>
            </template>

            <form v-else id="blog-category-form" @submit.prevent="saveCategory">
                <div class="field">
                    <label for="category-name">Nombre</label>
                    <input id="category-name" v-model="categoryForm.name" type="text" maxlength="80" required>
                    <span v-if="categoryForm.errors.name" class="error">{{ categoryForm.errors.name }}</span>
                </div>
                <div class="field">
                    <label for="category-description">Descripción (opcional)</label>
                    <textarea id="category-description" v-model="categoryForm.description" rows="3" maxlength="300" />
                    <span class="muted small">Se muestra en la página de la categoría y es su descripción para los buscadores.</span>
                    <span v-if="categoryForm.errors.description" class="error">{{ categoryForm.errors.description }}</span>
                </div>
            </form>

            <template #actions>
                <template v-if="categoryMode === 'list'">
                    <button type="button" class="btn btn-ghost" @click="categoriesOpen = false">Cerrar</button>
                    <button type="button" class="btn btn-primary" @click="newCategory"><PlusIcon /> Crear nuevo</button>
                </template>
                <template v-else>
                    <button
                        v-if="categoryMode === 'edit' && editing && editing.posts_count === 0"
                        type="button"
                        class="btn btn-ghost btn-danger-text footer-start"
                        @click="deleteCategory"
                    ><Trash2Icon /> Eliminar</button>
                    <button type="button" class="btn btn-ghost" @click="categoryMode = 'list'">Cancelar</button>
                    <button type="submit" form="blog-category-form" class="btn btn-primary" :disabled="categoryForm.processing">Guardar</button>
                </template>
            </template>
        </DetailModal>
    </BackofficeLayout>
</template>

<style scoped>
.block { display: block; }

.post-cell {
    display: flex;
    align-items: center;
    gap: 0.7rem;
}

.post-cell-text {
    min-width: 0;
}

.post-thumb {
    flex-shrink: 0;
    display: block;
    width: 4.5rem;
    aspect-ratio: 16 / 9;
    border-radius: var(--radius-sm);
    object-fit: cover;
    background: var(--color-surface-alt);
}

.post-thumb.is-empty {
    display: grid;
    place-items: center;
    color: var(--color-text-muted);
}

.post-cover {
    display: block;
    width: 100%;
    aspect-ratio: 1200 / 630;
    margin-bottom: 0.9rem;
    border-radius: var(--radius-sm);
    object-fit: cover;
}

.post-excerpt {
    margin: 0 0 1rem;
    color: var(--color-text-muted);
    font-size: 0.9rem;
}

.serp-title {
    display: block;
    color: color-mix(in srgb, var(--color-info) 85%, var(--color-text));
}

.category-list {
    display: grid;
    gap: 0.4rem;
    margin: 0;
    padding: 0;
    list-style: none;
}

.category-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    width: 100%;
    min-height: 2.75rem;
    padding: 0.55rem 0.75rem;
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    background: var(--color-surface);
    color: var(--color-text);
    font: inherit;
    text-align: left;
    cursor: pointer;
}

.category-item:hover {
    background: var(--color-surface-alt);
}

.field textarea,
.field input:not([type="checkbox"], [type="radio"]) {
    width: 100%;
}

/* Eliminar va a la izquierda del pie (CLAUDE.md secc. 27). */
.footer-start {
    margin-right: auto;
}
</style>
