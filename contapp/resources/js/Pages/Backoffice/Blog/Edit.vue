<script setup>
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, defineAsyncComponent, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import BackofficeLayout from '../../../Layouts/BackofficeLayout.vue';
import { confirmAction } from '../../../Utils/confirm';
import { markBusy } from '../../../Utils/busyButtons';
import { fullDate } from '../../../Utils/relativeTime';
import { uploadBlogImage } from '../../../Components/Blog/uploadImage';
import { fullTitle, seoChecks, slugify } from '../../../Components/Blog/seoChecks';
import {
    ArrowLeftIcon, CheckIcon, ExternalLinkIcon, EyeIcon, ImageIcon, NewspaperIcon, RotateCcwIcon, SaveIcon, SendIcon,
    Trash2Icon, TriangleAlertIcon, UploadIcon, XIcon,
} from '@lucide/vue';

// El editor (TipTap) se baja aparte, solo en esta pantalla: la aplicación
// trae todas sus pantallas en un archivo, y no tiene por qué cargar con él.
const BlogEditor = defineAsyncComponent(() => import('../../../Components/Blog/BlogEditor.vue'));

/**
 * Escribir un artículo del blog (BlogPostController, CLAUDE.md secc. 41). Una
 * pantalla propia y no un modal (secc. 21): a la izquierda el título, el
 * resumen y el texto; a la derecha la publicación, la portada, lo de los
 * buscadores y el aviso en Noticias.
 *
 * Lo que se escribe queda también en el navegador mientras no se guarda: si
 * la pestaña se cierra, al volver se ofrece recuperarlo.
 */
const props = defineProps({
    post: { type: Object, default: null },
    categories: { type: Array, required: true },
    authorName: { type: String, default: '' },
    imageRules: { type: Object, required: true },
    blogUrl: { type: String, required: true },
    limits: { type: Object, required: true },
});

const isNew = computed(() => !props.post);
const isPublished = computed(() => props.post?.is_published ?? false);

function fieldsFrom(post) {
    return {
        title: post?.title ?? '',
        slug: post?.slug ?? '',
        excerpt: post?.excerpt ?? '',
        content: post?.content ?? '',
        category_id: post?.category_id ?? '',
        cover_image_id: post?.cover?.id ?? null,
        cover_alt: post?.cover_alt ?? '',
        author_name: post ? (post.author_name ?? '') : props.authorName,
        seo_title: post?.seo_title ?? '',
        meta_description: post?.meta_description ?? '',
        focus_keyword: post?.focus_keyword ?? '',
        canonical_url: post?.canonical_url ?? '',
        noindex: post?.noindex ?? false,
        published_at: post?.published_at ?? '',
        announce_in_news: post?.announce_in_news ?? false,
        news_title: post?.news_title ?? '',
        news_body: post?.news_body ?? '',
    };
}

const form = useForm(fieldsFrom(props.post));
const cover = ref(props.post?.cover ?? null);

// ── Cambios sin guardar ──────────────────────────────────────────────────

const serialize = () => JSON.stringify(form.data());
const saved = ref(serialize());
const dirty = computed(() => serialize() !== saved.value);

// Después de guardar, el servidor manda la dirección definitiva (la arma del
// título si estaba vacía) y la fecha de publicación.
watch(() => props.post, (post) => {
    if (!post) return;

    form.slug = post.slug;
    form.published_at = post.published_at ?? '';
    cover.value = post.cover;
    form.cover_image_id = post.cover?.id ?? null;
    saved.value = serialize();
});

const draftKey = computed(() => `pivox:blog-draft:${props.post?.id ?? 'nuevo'}`);
const recoverable = ref(null);
let draftTimer = null;

watch(() => [form.title, form.excerpt, form.content], () => {
    clearTimeout(draftTimer);
    draftTimer = setTimeout(() => {
        if (!dirty.value) return;

        try {
            localStorage.setItem(draftKey.value, JSON.stringify({
                title: form.title, excerpt: form.excerpt, content: form.content, saved_at: new Date().toISOString(),
            }));
        } catch {
            // Sin almacenamiento del navegador, no hay copia local: nada más.
        }
    }, 1500);
});

function forgetLocalDraft() {
    try {
        localStorage.removeItem(draftKey.value);
        localStorage.removeItem('pivox:blog-draft:nuevo');
    } catch {
        // Nada que limpiar.
    }
}

function recover() {
    form.title = recoverable.value.title;
    form.excerpt = recoverable.value.excerpt;
    form.content = recoverable.value.content;
    recoverable.value = null;
}

function discardRecovery() {
    forgetLocalDraft();
    recoverable.value = null;
}

let leaving = false;

const stopGuard = router.on('before', (event) => {
    const visit = event.detail.visit;
    if (leaving || !dirty.value || visit.method !== 'get') return;

    event.preventDefault();
    confirmAction({
        title: 'Salir sin guardar',
        message: 'Hay cambios en el artículo que no guardaste. Si salís, se pierden.',
        confirmLabel: 'Salir sin guardar',
        danger: true,
        onConfirm: () => {
            leaving = true;
            forgetLocalDraft();
            router.visit(visit.url.href ?? visit.url);
        },
    });
});

function onBeforeUnload(event) {
    if (!dirty.value) return;
    event.preventDefault();
    event.returnValue = '';
}

onMounted(() => {
    window.addEventListener('beforeunload', onBeforeUnload);

    try {
        const draft = JSON.parse(localStorage.getItem(draftKey.value) ?? 'null');
        if (!draft) return;

        const newer = !props.post || new Date(draft.saved_at) > new Date(props.post.updated_at);
        const different = draft.content !== form.content || draft.title !== form.title || draft.excerpt !== form.excerpt;

        if (newer && different) recoverable.value = draft;
        else localStorage.removeItem(draftKey.value);
    } catch {
        // Una copia ilegible no se ofrece.
    }
});

onBeforeUnmount(() => {
    stopGuard();
    clearTimeout(draftTimer);
    window.removeEventListener('beforeunload', onBeforeUnload);
});

// ── Guardar, publicar, eliminar ──────────────────────────────────────────

const errorCount = computed(() => Object.keys(form.errors).length);
const firstError = computed(() => Object.values(form.errors)[0] ?? '');

function save(status) {
    form.transform((data) => ({
        ...data,
        status,
        category_id: data.category_id || null,
        noindex: data.noindex ? 1 : 0,
        announce_in_news: data.announce_in_news ? 1 : 0,
    }));

    const options = {
        preserveScroll: true,
        onSuccess: () => {
            forgetLocalDraft();
            saved.value = serialize();
        },
    };

    if (isNew.value) form.post(route('backoffice.blog.store'), options);
    else form.put(route('backoffice.blog.update', props.post.id), options);
}

function unpublish() {
    confirmAction({
        title: 'Pasar a borrador',
        message: props.post?.has_news
            ? 'El artículo deja de verse en el sitio y se quita su noticia de la aplicación. Podés volver a publicarlo cuando quieras.'
            : 'El artículo deja de verse en el sitio. Podés volver a publicarlo cuando quieras.',
        confirmLabel: 'Pasar a borrador',
        onConfirm: () => save('draft'),
    });
}

function destroy() {
    confirmAction({
        title: 'Eliminar artículo',
        message: `«${props.post.title}» se elimina con sus imágenes${props.post.has_news ? ' y su noticia' : ''}. Su dirección deja de existir. No se puede deshacer.`,
        confirmLabel: 'Eliminar',
        danger: true,
        onConfirm: () => {
            leaving = true;
            forgetLocalDraft();
            router.delete(route('backoffice.blog.destroy', props.post.id));
        },
    });
}

// ── Portada ──────────────────────────────────────────────────────────────

const coverInput = ref(null);
const coverButton = ref(null);

async function pickCover(event) {
    const file = event.target.files?.[0];
    event.target.value = '';
    if (!file) return;

    const release = markBusy(coverButton.value);
    const result = await uploadBlogImage(file, props.imageRules);
    release();

    if (!result.ok) {
        form.setError('cover_image_id', result.message);
        return;
    }

    form.clearErrors('cover_image_id');
    cover.value = result.image;
    form.cover_image_id = result.image.id;
}

function removeCover() {
    cover.value = null;
    form.cover_image_id = null;
}

// ── Buscadores ───────────────────────────────────────────────────────────

const stats = reactive({ words: 0, h2: 0, images: 0, missingAlt: 0, internalLinks: 0, firstParagraph: '' });

const slugPreview = computed(() => form.slug || slugify(form.title) || 'direccion-del-articulo');
const blogHost = computed(() => {
    try {
        return new URL(props.blogUrl).host;
    } catch {
        return '';
    }
});
const pageTitle = computed(() => fullTitle(form.seo_title || form.title || 'Título del artículo'));
const effectiveDescription = computed(() => form.meta_description || form.excerpt || stats.firstParagraph);

const checks = computed(() => seoChecks({
    title: form.title,
    seoTitle: form.seo_title,
    description: form.meta_description || form.excerpt,
    slug: slugPreview.value,
    keyword: form.focus_keyword,
    stats,
    hasCover: Boolean(cover.value),
    coverAlt: form.cover_alt,
    categoryId: form.category_id,
}));
const passed = computed(() => checks.value.filter((check) => check.ok).length);

function truncate(text, length) {
    const clean = (text ?? '').trim();

    return clean.length > length ? `${clean.slice(0, length - 1).trimEnd()}…` : clean;
}

function counterClass(length, ideal) {
    return length > ideal ? 'is-over' : '';
}

// ── Noticias ─────────────────────────────────────────────────────────────

// Al encenderlo, la noticia arranca con el título y el resumen del artículo.
watch(() => form.announce_in_news, (on) => {
    if (!on) return;
    if (!form.news_title) form.news_title = form.title.slice(0, props.limits.news_title);
    if (!form.news_body) form.news_body = (form.excerpt || form.meta_description).slice(0, props.limits.news_body);
});
</script>

<template>
    <Head :title="isNew ? 'Nuevo artículo' : 'Editar artículo'" />

    <BackofficeLayout :title="isNew ? 'Nuevo artículo' : 'Editar artículo'">
        <div class="view-toolbar">
            <Link :href="route('backoffice.blog.index')" class="btn btn-ghost"><ArrowLeftIcon /> Blog</Link>
            <div class="view-actions">
                <span class="badge" :class="isPublished ? 'badge-success' : 'badge-neutral'">{{ isPublished ? 'Publicado' : 'Borrador' }}</span>
                <a v-if="post?.url" :href="post.url" target="_blank" rel="noopener" class="btn btn-ghost"><ExternalLinkIcon /> Ver en el sitio</a>
                <a v-if="post" :href="post.preview_url" target="_blank" rel="noopener" class="btn btn-ghost"><EyeIcon /> Vista previa</a>
                <button v-if="isPublished" type="button" class="btn btn-ghost" :disabled="form.processing" @click="unpublish"><RotateCcwIcon /> Pasar a borrador</button>
                <button v-else type="button" class="btn btn-ghost" :disabled="form.processing" @click="save('draft')"><SaveIcon /> Guardar borrador</button>
                <button type="button" class="btn btn-primary" :disabled="form.processing" @click="save('published')">
                    <SendIcon /> {{ isPublished ? 'Guardar cambios' : 'Publicar' }}
                </button>
            </div>
        </div>

        <div v-if="recoverable" class="flash flash-info recover">
            <span>Hay cambios de este artículo que no se guardaron, del {{ fullDate(recoverable.saved_at) }}. ¿Los recuperás?</span>
            <span class="recover-actions">
                <button type="button" class="btn btn-ghost" @click="discardRecovery">Descartar</button>
                <button type="button" class="btn btn-primary" @click="recover">Recuperar</button>
            </span>
        </div>

        <p v-if="errorCount" class="flash flash-error" role="alert">
            <TriangleAlertIcon /> No se guardó: {{ firstError }}
        </p>

        <div class="blog-edit">
            <div class="blog-edit-main">
                <div class="card blog-edit-card">
                    <div class="field">
                        <label for="post-title">Título</label>
                        <input id="post-title" v-model="form.title" type="text" :maxlength="limits.title" required>
                        <span v-if="form.errors.title" class="error">{{ form.errors.title }}</span>
                    </div>

                    <div class="field">
                        <label for="post-excerpt">Resumen (opcional)</label>
                        <textarea id="post-excerpt" v-model="form.excerpt" rows="2" :maxlength="limits.excerpt" aria-describedby="post-excerpt-hint" />
                        <span id="post-excerpt-hint" class="field-hint">
                            Va debajo del título y en la tarjeta del blog. {{ form.excerpt.length }} de {{ limits.excerpt }}.
                        </span>
                        <span v-if="form.errors.excerpt" class="error">{{ form.errors.excerpt }}</span>
                    </div>

                    <div class="field">
                        <span class="field-label">Texto</span>
                        <BlogEditor
                            v-model="form.content"
                            :image-rules="imageRules"
                            :site-host="blogHost"
                            :invalid="Boolean(form.errors.content)"
                            @stats="Object.assign(stats, $event)"
                        />
                        <span class="field-hint">{{ stats.words }} palabras · {{ Math.max(1, Math.ceil(stats.words / 200)) }} min de lectura</span>
                        <span v-if="form.errors.content" class="error">{{ form.errors.content }}</span>
                    </div>
                </div>
            </div>

            <!-- La columna de la derecha (abajo en una pantalla angosta). -->
            <aside class="blog-edit-side">
                <section class="card blog-side-card" aria-labelledby="side-publication">
                    <h2 id="side-publication" class="side-title">Publicación</h2>

                    <div class="field">
                        <label for="post-category">Categoría</label>
                        <select id="post-category" v-model="form.category_id">
                            <option value="">Sin categoría</option>
                            <option v-for="category in categories" :key="category.id" :value="category.id">{{ category.name }}</option>
                        </select>
                        <span class="field-hint">Las categorías se manejan en la lista del blog.</span>
                        <span v-if="form.errors.category_id" class="error">{{ form.errors.category_id }}</span>
                    </div>

                    <div class="field">
                        <label for="post-author">Autor</label>
                        <input id="post-author" v-model="form.author_name" type="text" maxlength="120" placeholder="Equipo Pivox">
                        <span v-if="form.errors.author_name" class="error">{{ form.errors.author_name }}</span>
                    </div>

                    <div class="field">
                        <label for="post-published-at">Fecha de publicación (opcional)</label>
                        <input id="post-published-at" v-model="form.published_at" type="datetime-local" aria-describedby="post-published-hint">
                        <span id="post-published-hint" class="field-hint">Vacía, la del momento en que se publica. No puede ser futura. Hora de Costa Rica.</span>
                        <span v-if="form.errors.published_at" class="error">{{ form.errors.published_at }}</span>
                    </div>

                    <button v-if="post" type="button" class="btn btn-ghost btn-danger-text side-delete" @click="destroy"><Trash2Icon /> Eliminar artículo</button>
                </section>

                <section class="card blog-side-card" aria-labelledby="side-cover">
                    <h2 id="side-cover" class="side-title">Portada</h2>

                    <div class="cover-preview" :class="{ 'is-empty': !cover }">
                        <img v-if="cover" :src="cover.url" alt="">
                        <span v-else><ImageIcon :size="24" /> Sin portada</span>
                    </div>

                    <div class="cover-actions">
                        <button ref="coverButton" type="button" class="btn btn-ghost" @click="coverInput?.click()"><UploadIcon /> {{ cover ? 'Cambiar portada' : 'Elegir portada' }}</button>
                        <button v-if="cover" type="button" class="btn btn-ghost btn-danger-text" @click="removeCover"><XIcon /> Quitar</button>
                        <input ref="coverInput" type="file" class="sr-only" tabindex="-1" aria-hidden="true" :accept="imageRules.accept" @change="pickCover">
                    </div>
                    <span class="field-hint">
                        {{ imageRules.formats }}, hasta {{ imageRules.max_megabytes }} MB; ideal 1200 × 630 px o más. Es la imagen de la tarjeta del blog,
                        de las redes sociales y de la noticia. Hace falta para publicar.
                    </span>
                    <span v-if="form.errors.cover_image_id" class="error">{{ form.errors.cover_image_id }}</span>

                    <div v-if="cover" class="field cover-alt">
                        <label for="post-cover-alt">Qué muestra la portada</label>
                        <input id="post-cover-alt" v-model="form.cover_alt" type="text" :maxlength="limits.cover_alt" placeholder="Por ejemplo: Contadora revisando el balance en Pivox">
                        <span v-if="form.errors.cover_alt" class="error">{{ form.errors.cover_alt }}</span>
                    </div>
                </section>

                <section class="card blog-side-card" aria-labelledby="side-seo">
                    <h2 id="side-seo" class="side-title">Buscadores (SEO)</h2>

                    <!-- Cómo se vería en Google. -->
                    <div class="serp" aria-label="Vista previa en Google">
                        <div class="serp-site">
                            <span class="serp-icon"><img src="/images/logos/logo_pivox_corto.png" alt=""></span>
                            <span>
                                <b>Pivox</b>
                                <small>{{ blogHost }} › blog › {{ slugPreview }}</small>
                            </span>
                        </div>
                        <div class="serp-title">{{ truncate(pageTitle, 62) }}</div>
                        <p class="serp-description">{{ truncate(effectiveDescription, 160) || 'La descripción que muestre Google.' }}</p>
                    </div>

                    <div class="field">
                        <label for="post-slug">Dirección</label>
                        <div class="slug-field">
                            <span class="slug-prefix">/blog/</span>
                            <input id="post-slug" v-model="form.slug" type="text" :maxlength="limits.slug" :placeholder="slugify(form.title) || 'se arma del título'">
                        </div>
                        <span class="field-hint">Si cambia en un artículo ya publicado, la anterior redirige a la nueva.</span>
                        <span v-if="form.errors.slug" class="error">{{ form.errors.slug }}</span>
                    </div>

                    <div class="field">
                        <label for="post-seo-title">Título para buscadores (opcional)</label>
                        <input id="post-seo-title" v-model="form.seo_title" type="text" :maxlength="limits.seo_title" :placeholder="form.title">
                        <span class="field-hint" :class="counterClass(pageTitle.length, limits.title_ideal)">
                            Con « | Pivox», {{ pageTitle.length }} de {{ limits.title_ideal }} caracteres.
                        </span>
                        <span v-if="form.errors.seo_title" class="error">{{ form.errors.seo_title }}</span>
                    </div>

                    <div class="field">
                        <label for="post-meta">Descripción para buscadores (opcional)</label>
                        <textarea id="post-meta" v-model="form.meta_description" rows="3" :maxlength="limits.meta_description" :placeholder="form.excerpt" />
                        <span class="field-hint" :class="counterClass(form.meta_description.length, limits.description_ideal)">
                            {{ form.meta_description.length }} de {{ limits.description_ideal }}. Vacía, se usa el resumen.
                        </span>
                        <span v-if="form.errors.meta_description" class="error">{{ form.errors.meta_description }}</span>
                    </div>

                    <div class="field">
                        <label for="post-keyword">Palabra clave (opcional)</label>
                        <input id="post-keyword" v-model="form.focus_keyword" type="text" maxlength="80" placeholder="Por ejemplo: factura electrónica">
                        <span class="field-hint">Lo que buscaría alguien para llegar a este artículo.</span>
                    </div>

                    <div class="seo-checks">
                        <p class="seo-score"><b>{{ passed }} de {{ checks.length }}</b> listos</p>
                        <ul>
                            <li v-for="check in checks" :key="check.label" :class="check.ok ? 'is-ok' : 'is-pending'">
                                <CheckIcon v-if="check.ok" aria-label="Listo" role="img" />
                                <TriangleAlertIcon v-else aria-label="Falta" role="img" />
                                <span>{{ check.label }}<small v-if="check.hint && !check.ok">{{ check.hint }}</small></span>
                            </li>
                        </ul>
                    </div>

                    <details class="seo-advanced">
                        <summary>Avanzado</summary>
                        <div class="field">
                            <label for="post-canonical">URL canónica (opcional)</label>
                            <input id="post-canonical" v-model="form.canonical_url" type="url" maxlength="500" placeholder="https://…">
                            <span class="field-hint">Solo si el artículo se publicó primero en otro sitio: la dirección original.</span>
                            <span v-if="form.errors.canonical_url" class="error">{{ form.errors.canonical_url }}</span>
                        </div>
                        <label class="check"><input v-model="form.noindex" type="checkbox"> No mostrar en buscadores (noindex)</label>
                    </details>
                </section>

                <section class="card blog-side-card" aria-labelledby="side-news">
                    <h2 id="side-news" class="side-title"><NewspaperIcon /> Aviso en Noticias</h2>

                    <label class="check news-toggle">
                        <input v-model="form.announce_in_news" type="checkbox">
                        <span>
                            <strong>Avisar en la sección Noticias de Pivox</strong>
                            <span class="field-hint">Las cuentas lo ven en el panel «Comentarios y noticias», con la portada y un enlace al artículo.</span>
                        </span>
                    </label>

                    <template v-if="form.announce_in_news">
                        <div class="field">
                            <label for="post-news-title">Título de la noticia</label>
                            <input id="post-news-title" v-model="form.news_title" type="text" :maxlength="limits.news_title">
                            <span v-if="form.errors.news_title" class="error">{{ form.errors.news_title }}</span>
                        </div>
                        <div class="field">
                            <label for="post-news-body">Descripción de la noticia</label>
                            <textarea id="post-news-body" v-model="form.news_body" rows="3" :maxlength="limits.news_body" />
                            <span v-if="form.errors.news_body" class="error">{{ form.errors.news_body }}</span>
                        </div>

                        <!-- Como se ve en la pestaña Noticias de la aplicación. -->
                        <div class="news-preview" aria-label="Vista previa de la noticia">
                            <img v-if="cover" :src="cover.url" alt="" class="news-preview-image">
                            <div class="news-preview-text">
                                <small>recién</small>
                                <b>{{ form.news_title || 'Título de la noticia' }}</b>
                                <p>{{ form.news_body || 'Descripción de la noticia.' }}</p>
                                <span class="news-preview-cta">Leer el artículo <ExternalLinkIcon /></span>
                            </div>
                        </div>
                        <p class="field-hint">
                            Sale cuando el artículo se publica. Si vuelve a borrador o se elimina, la noticia se quita.
                            <template v-if="post?.has_news"> Ya está publicada.</template>
                        </p>
                    </template>
                </section>
            </aside>
        </div>
    </BackofficeLayout>
</template>

<style scoped>
/*
    Dos columnas: el texto a la izquierda, a todo lo que dé, y una columna de
    ajustes a la derecha con su propio ancho (el de unos campos cortos). En
    una pantalla angosta, una debajo de la otra.
*/
.blog-edit {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(19rem, 23rem);
    align-items: start;
    gap: 1.1rem;
}

@media (max-width: 1200px) {
    .blog-edit {
        grid-template-columns: minmax(0, 1fr);
    }
}

.blog-edit-card,
.blog-side-card {
    padding: 1.1rem;
}

.blog-edit-side {
    display: grid;
    gap: 1.1rem;
    min-width: 0;
}

.side-title {
    display: flex;
    align-items: center;
    gap: 0.45rem;
    margin: 0 0 0.9rem;
    font-size: 0.95rem;
}

.field :is(input:not([type="checkbox"], [type="radio"]), select, textarea) {
    width: 100%;
}

.field-label {
    font-size: 0.78rem;
    font-weight: 600;
    color: var(--color-text-muted);
}

.field-hint {
    display: block;
    margin-top: 0.15rem;
    color: var(--color-text-muted);
    font-size: 0.75rem;
    line-height: 1.4;
}

.field-hint.is-over {
    color: var(--color-warning);
    font-weight: 600;
}

.recover {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 0.6rem 1rem;
}

.recover-actions {
    display: flex;
    gap: 0.5rem;
}

.flash svg {
    vertical-align: -0.2em;
}

.side-delete {
    width: 100%;
    margin-top: 0.25rem;
}

.cover-preview {
    display: grid;
    place-items: center;
    aspect-ratio: 1200 / 630;
    margin-bottom: 0.7rem;
    overflow: hidden;
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    background: var(--color-surface-alt);
    color: var(--color-text-muted);
    font-size: 0.82rem;
}

.cover-preview img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.cover-preview.is-empty span {
    display: inline-flex;
    flex-direction: column;
    align-items: center;
    gap: 0.3rem;
}

.cover-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    margin-bottom: 0.35rem;
}

.cover-alt {
    margin-top: 0.8rem;
}

/* «/blog/» delante del campo, como texto: el campo conserva su aspecto (secc. 26). */
.slug-field {
    display: flex;
    align-items: center;
    gap: 0.4rem;
}

.slug-prefix {
    flex-shrink: 0;
    color: var(--color-text-muted);
    font-size: 0.82rem;
}

.slug-field input {
    min-width: 0;
}

/* La vista previa de Google: sus colores son los de Google, no los del tema. */
.serp {
    margin-bottom: 1rem;
    padding: 0.85rem 0.95rem;
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    background: var(--color-surface);
}

.serp-site {
    display: flex;
    align-items: center;
    gap: 0.55rem;
    font-size: 0.78rem;
}

.serp-site b,
.serp-site small {
    display: block;
}

.serp-site small {
    overflow: hidden;
    max-width: 15rem;
    color: var(--color-text-muted);
    text-overflow: ellipsis;
    white-space: nowrap;
}

.serp-icon {
    display: inline-grid;
    place-items: center;
    width: 1.6rem;
    height: 1.6rem;
    padding: 0.2rem;
    border: 1px solid var(--color-border);
    border-radius: 50%;
    background: #fff;
}

.serp-icon img {
    width: 100%;
    height: 100%;
    object-fit: contain;
}

.serp-title {
    margin-top: 0.45rem;
    color: color-mix(in srgb, var(--color-info) 85%, var(--color-text));
    font-size: 1.02rem;
    line-height: 1.3;
}

.serp-description {
    margin: 0.25rem 0 0;
    color: var(--color-text-muted);
    font-size: 0.82rem;
    line-height: 1.45;
}

.seo-checks {
    margin: 0.3rem 0 0.8rem;
    padding: 0.8rem 0.9rem;
    border-radius: var(--radius-sm);
    background: var(--color-surface-alt);
}

.seo-score {
    margin: 0 0 0.5rem;
    font-size: 0.85rem;
}

.seo-checks ul {
    display: grid;
    gap: 0.4rem;
    margin: 0;
    padding: 0;
    list-style: none;
    font-size: 0.8rem;
}

.seo-checks li {
    display: flex;
    gap: 0.45rem;
    line-height: 1.35;
}

.seo-checks li svg {
    flex-shrink: 0;
    margin-top: 0.1rem;
}

.seo-checks li.is-ok svg {
    color: var(--color-success);
}

.seo-checks li.is-pending svg {
    color: var(--color-warning);
}

.seo-checks small {
    display: block;
    color: var(--color-text-muted);
}

.seo-advanced summary {
    cursor: pointer;
    font-size: 0.85rem;
    font-weight: 600;
}

.seo-advanced[open] summary {
    margin-bottom: 0.75rem;
}

.news-toggle {
    align-items: flex-start;
    margin-bottom: 0.9rem;
}

.news-toggle input {
    margin-top: 0.15rem;
}

.news-toggle strong {
    display: block;
    font-size: 0.85rem;
}

/* La tarjeta de la pestaña Noticias (FeedbackPanel.vue). */
.news-preview {
    overflow: hidden;
    margin-bottom: 0.5rem;
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    background: var(--color-surface);
}

.news-preview-image {
    display: block;
    width: 100%;
    aspect-ratio: 16 / 9;
    object-fit: cover;
}

.news-preview-text {
    display: flex;
    flex-direction: column;
    gap: 0.2rem;
    padding: 0.75rem 0.85rem;
    font-size: 0.82rem;
}

.news-preview-text small {
    color: var(--color-text-muted);
}

.news-preview-text b {
    font-size: 0.92rem;
}

.news-preview-text p {
    margin: 0;
    color: var(--color-text-muted);
    white-space: pre-line;
}

.news-preview-cta {
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
    margin-top: 0.2rem;
    color: color-mix(in srgb, var(--color-primary) 70%, var(--color-text));
    font-weight: 700;
}
</style>
