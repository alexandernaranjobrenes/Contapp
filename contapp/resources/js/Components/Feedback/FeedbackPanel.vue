<script setup>
import { nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { ArrowRightIcon, ExternalLinkIcon, MessagesSquareIcon, NewspaperIcon, XIcon } from '@lucide/vue';
import FeedbackComposer from './FeedbackComposer.vue';
import FeedbackPostCard from './FeedbackPostCard.vue';
import { markBusy } from '../../Utils/busyButtons';
import { requestJson } from '../../Utils/http';
import { useNewsSeen } from '../../Utils/newsSeen';
import { fullDate, timeAgo } from '../../Utils/relativeTime';

/**
 * El panel «Comentarios y noticias», que abre el botón de la barra superior
 * (AppLayout.vue). Se despliega a la derecha, debajo de la barra, sin tapar
 * el resto de la pantalla.
 *
 * - Comentarios: lo que la gente propone sobre CONTAPP, con votos a favor y
 *   en contra —lo más votado arriba— y comentarios. Ver FeedbackService.
 * - Noticias: las actualizaciones que publica el backoffice.
 *
 * Habla JSON con el servidor (FeedbackController): está en todas las
 * pantallas, y una visita de Inertia recargaría la de atrás en cada voto.
 * Los datos se piden la primera vez que se abre cada pestaña.
 *
 * Cerrado queda montado (v-show): lo que se estaba escribiendo sigue ahí al
 * volver a abrirlo. Cambiar de pantalla sí lo cierra.
 */
const props = defineProps({
    open: { type: Boolean, default: false },
});

const emit = defineEmits(['close']);

const panel = ref(null);
const tab = ref('comments');
const { hasUnseen, markSeen } = useNewsSeen();

const TABS = [
    { key: 'comments', label: 'Comentarios', icon: MessagesSquareIcon },
    { key: 'news', label: 'Noticias', icon: NewspaperIcon },
];

// Lo que el servidor manda con la primera página; hasta entonces, lo mismo.
const limits = ref({ accept: 'image/jpeg,image/png', formats: 'JPG o PNG', max_megabytes: 5, max_images: 2, max_body: 2000 });

// ── Comentarios ───────────────────────────────────────────────────────────
const posts = ref([]);
const sort = ref('top');
const postsPage = ref(0);
const postsHasMore = ref(false);
const postsLoading = ref(false);
const postsError = ref('');
const postsLoaded = ref(false);

async function loadPosts({ reset = false, button = null } = {}) {
    if (postsLoading.value) return;

    const page = reset ? 1 : postsPage.value + 1;
    postsLoading.value = true;
    postsError.value = '';
    const release = button ? markBusy(button) : () => {};

    const result = await requestJson(`${route('feedback.index')}?sort=${sort.value}&page=${page}`);

    release();
    postsLoading.value = false;

    if (!result.ok) {
        postsError.value = result.message;
        return;
    }

    limits.value = result.data.limits;
    // Entre una página y la siguiente los votos pueden mover una publicación:
    // la que ya está en la lista no se repite.
    const known = new Set(reset ? [] : posts.value.map((p) => p.id));
    const incoming = result.data.posts.filter((p) => !known.has(p.id));

    posts.value = reset ? incoming : [...posts.value, ...incoming];
    postsPage.value = page;
    postsHasMore.value = result.data.has_more;
    postsLoaded.value = true;
}

function setSort(value) {
    if (sort.value === value) return;
    sort.value = value;
    loadPosts({ reset: true });
}

// Lo propio recién publicado va arriba, se ordene como se ordene. El
// formulario está abajo: la lista sube hasta el principio para que se vea.
const postsList = ref(null);

function onPostCreated(data) {
    posts.value = [data.post, ...posts.value.filter((p) => p.id !== data.post.id)];
    nextTick(() => postsList.value?.scrollTo({ top: 0, behavior: 'smooth' }));
}

function removePost(id) {
    posts.value = posts.value.filter((p) => p.id !== id);
}

// ── Noticias ──────────────────────────────────────────────────────────────
const news = ref([]);
const newsPage = ref(0);
const newsHasMore = ref(false);
const newsLoading = ref(false);
const newsError = ref('');
const newsLoaded = ref(false);

async function loadNews({ reset = false, button = null } = {}) {
    if (newsLoading.value) return;

    const page = reset ? 1 : newsPage.value + 1;
    newsLoading.value = true;
    newsError.value = '';
    const release = button ? markBusy(button) : () => {};

    const result = await requestJson(`${route('news.index')}?page=${page}`);

    release();
    newsLoading.value = false;

    if (!result.ok) {
        newsError.value = result.message;
        return;
    }

    const known = new Set(reset ? [] : news.value.map((n) => n.id));
    news.value = [...(reset ? [] : news.value), ...result.data.news.filter((n) => !known.has(n.id))];
    newsPage.value = page;
    newsHasMore.value = result.data.has_more;
    newsLoaded.value = true;
}

// Una pantalla de CONTAPP se abre acá mismo, con Inertia (y el panel se
// cierra con el cambio de pantalla); otro sitio, en otra pestaña.
function openNews(item, event) {
    if (!item.link_url || !item.link_is_internal) return;
    if (event.ctrlKey || event.metaKey || event.shiftKey || event.button === 1) return;

    event.preventDefault();
    router.visit(item.link_url);
}

// ── Pestañas ──────────────────────────────────────────────────────────────
function selectTab(key) {
    tab.value = key;

    if (key === 'news') {
        markSeen();
        if (!newsLoaded.value) loadNews({ reset: true });
    } else if (!postsLoaded.value) {
        loadPosts({ reset: true });
    }
}

// Flechas izquierda y derecha entre las pestañas, como en cualquier tablist.
function onTabKeydown(event) {
    if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;

    event.preventDefault();
    const index = TABS.findIndex((t) => t.key === tab.value);
    const next = event.key === 'Home' ? 0
        : event.key === 'End' ? TABS.length - 1
            : (index + (event.key === 'ArrowRight' ? 1 : -1) + TABS.length) % TABS.length;

    selectTab(TABS[next].key);
    nextTick(() => panel.value?.querySelector(`#feedback-tab-${TABS[next].key}`)?.focus());
}

// ── Abrir y cerrar ────────────────────────────────────────────────────────
let returnFocusTo = null;

// Escape cierra, salvo que encima haya algo que lo use antes: una
// confirmación (borrar lo propio) o una imagen en grande.
const ON_TOP = '.confirm-backdrop, .modal-backdrop, .image-lightbox';

function onKeydown(event) {
    if (event.key !== 'Escape' || document.querySelector(ON_TOP)) return;
    emit('close');
}

// Tocar fuera del panel lo cierra. No cuenta lo que se abre desde él (la
// confirmación, la imagen en grande), ni el botón que lo abre y lo cierra,
// ni los avisos.
function onPointerDown(event) {
    const target = event.target;
    if (!(target instanceof Element)) return;
    if (panel.value?.contains(target)) return;
    if (target.closest(`.feedback-trigger, .toast-stack, ${ON_TOP}`)) return;

    emit('close');
}

watch(() => props.open, async (open) => {
    if (open) {
        // El botón de la barra, aunque el clic no le haya dado el foco (Safari
        // no se lo da a un botón que se toca).
        returnFocusTo = document.querySelector('.feedback-trigger') ?? document.activeElement;
        window.addEventListener('keydown', onKeydown);
        document.addEventListener('pointerdown', onPointerDown, true);

        selectTab(tab.value);
        await nextTick();
        panel.value?.querySelector(`#feedback-tab-${tab.value}`)?.focus();
        return;
    }

    window.removeEventListener('keydown', onKeydown);
    document.removeEventListener('pointerdown', onPointerDown, true);

    // El foco vuelve al botón de la barra solo si estaba en el panel: si se
    // cerró tocando otra cosa, el foco ya está donde se tocó.
    if (panel.value?.contains(document.activeElement)) returnFocusTo?.focus?.();
    returnFocusTo = null;
});

onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKeydown);
    document.removeEventListener('pointerdown', onPointerDown, true);
});
</script>

<template>
    <Teleport to="body">
        <Transition name="feedback-panel">
            <aside
                v-show="open"
                id="feedback-panel"
                ref="panel"
                class="feedback-panel"
                role="dialog"
                aria-modal="false"
                aria-label="Comentarios y noticias"
            >
                <header class="panel-head">
                    <div class="panel-tabs" role="tablist" aria-label="Comentarios y noticias">
                        <button
                            v-for="item in TABS"
                            :id="`feedback-tab-${item.key}`"
                            :key="item.key"
                            type="button"
                            role="tab"
                            class="panel-tab"
                            :class="{ active: tab === item.key }"
                            :aria-selected="tab === item.key"
                            :aria-controls="`feedback-tabpanel-${item.key}`"
                            :tabindex="tab === item.key ? 0 : -1"
                            @click="selectTab(item.key)"
                            @keydown="onTabKeydown"
                        >
                            <component :is="item.icon" />
                            {{ item.label }}
                            <span v-if="item.key === 'news' && hasUnseen" class="tab-dot">
                                <span class="sr-only">(hay noticias nuevas)</span>
                            </span>
                        </button>
                    </div>
                    <button type="button" class="panel-close" aria-label="Cerrar" title="Cerrar" @click="emit('close')">
                        <XIcon :size="18" />
                    </button>
                </header>

                <!-- Como un chat: las publicaciones arriba, con su propio
                     desplazamiento, y el formulario fijo abajo. -->
                <div
                    v-show="tab === 'comments'"
                    id="feedback-tabpanel-comments"
                    class="panel-pane"
                    role="tabpanel"
                    aria-labelledby="feedback-tab-comments"
                >
                    <div ref="postsList" class="panel-body">
                        <div class="panel-sort" role="group" aria-label="Ordenar las publicaciones">
                            <button type="button" :class="{ active: sort === 'top' }" :aria-pressed="sort === 'top'" @click="setSort('top')">Más votadas</button>
                            <button type="button" :class="{ active: sort === 'new' }" :aria-pressed="sort === 'new'" @click="setSort('new')">Más recientes</button>
                        </div>

                        <p v-if="postsLoading && !posts.length" class="panel-state muted">Cargando publicaciones…</p>
                        <p v-else-if="postsError && !posts.length" class="panel-state error">
                            {{ postsError }}
                            <button type="button" class="btn btn-ghost" @click="loadPosts({ reset: true, button: $event.currentTarget })">Reintentar</button>
                        </p>
                        <p v-else-if="postsLoaded && !posts.length" class="panel-state muted">
                            Todavía no hay publicaciones. Contanos la primera idea.
                        </p>

                        <FeedbackPostCard
                            v-for="post in posts"
                            :key="post.id"
                            :post="post"
                            :limits="limits"
                            @deleted="removePost"
                        />

                        <button
                            v-if="postsHasMore"
                            type="button"
                            class="btn btn-ghost panel-more"
                            @click="loadPosts({ button: $event.currentTarget })"
                        >
                            Ver más publicaciones
                        </button>
                        <p v-if="postsError && posts.length" class="panel-state error">{{ postsError }}</p>
                    </div>

                    <div class="panel-compose">
                        <p class="panel-intro">Contanos qué mejorarías de CONTAPP</p>
                        <FeedbackComposer
                            :url="route('feedback.store')"
                            label="Tu publicación"
                            placeholder="Una idea, una sugerencia, algo que te gustaría que CONTAPP hiciera…"
                            submit-label="Publicar"
                            :limits="limits"
                            @created="onPostCreated"
                        />
                    </div>
                </div>

                <div
                    v-show="tab === 'news'"
                    id="feedback-tabpanel-news"
                    class="panel-body"
                    role="tabpanel"
                    aria-labelledby="feedback-tab-news"
                >
                    <p v-if="newsLoading && !news.length" class="panel-state muted">Cargando noticias…</p>
                    <p v-else-if="newsError && !news.length" class="panel-state error">
                        {{ newsError }}
                        <button type="button" class="btn btn-ghost" @click="loadNews({ reset: true, button: $event.currentTarget })">Reintentar</button>
                    </p>
                    <p v-else-if="newsLoaded && !news.length" class="panel-state muted">
                        Todavía no hay noticias. Acá vas a ver las novedades de CONTAPP.
                    </p>

                    <article v-for="item in news" :key="item.id" class="news-card">
                        <component
                            :is="item.link_url ? 'a' : 'div'"
                            class="news-inner"
                            :class="{ 'is-link': item.link_url }"
                            :href="item.link_url || null"
                            :target="item.link_url && !item.link_is_internal ? '_blank' : null"
                            :rel="item.link_url && !item.link_is_internal ? 'noopener noreferrer' : null"
                            @click="openNews(item, $event)"
                        >
                            <img v-if="item.image_url" :src="item.image_url" alt="" class="news-image" loading="lazy">
                            <div class="news-text">
                                <time :datetime="item.created_at" :title="fullDate(item.created_at)" class="news-time">{{ timeAgo(item.created_at) }}</time>
                                <h3 class="news-title">{{ item.title }}</h3>
                                <p class="news-body">{{ item.body }}</p>
                                <span v-if="item.link_url" class="news-cta">
                                    <template v-if="item.link_is_internal">Ir a la pantalla <ArrowRightIcon /></template>
                                    <template v-else>Abrir el enlace <ExternalLinkIcon /></template>
                                </span>
                            </div>
                        </component>
                    </article>

                    <button
                        v-if="newsHasMore"
                        type="button"
                        class="btn btn-ghost panel-more"
                        @click="loadNews({ button: $event.currentTarget })"
                    >
                        Ver más noticias
                    </button>
                    <p v-if="newsError && news.length" class="panel-state error">{{ newsError }}</p>
                </div>
            </aside>
        </Transition>
    </Teleport>
</template>

<style scoped>
/*
    Debajo de la barra superior y hasta el fondo de la ventana, a la derecha.
    No es modal: la pantalla de atrás se sigue viendo. Por encima del
    contenido, pero por debajo del menú lateral de las pantallas angostas
    (z-index 30 y 40), de los modales (50) y de la imagen en grande (70).
*/
.feedback-panel {
    position: fixed;
    top: var(--topbar-height);
    right: 0;
    bottom: 0;
    z-index: 25;
    display: flex;
    flex-direction: column;
    width: min(27rem, 100vw);
    background-color: var(--color-bg);
    border-left: 1px solid var(--color-border);
    box-shadow: -8px 0 24px rgba(11, 31, 58, 0.12);
}

.panel-head {
    flex-shrink: 0;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.5rem 0.6rem 0.5rem 0.75rem;
    background: var(--color-surface);
    border-bottom: 1px solid var(--color-border);
}

/* Pestañas: el alto y la letra de los controles (CLAUDE.md secc. 26). */
.panel-tabs {
    display: flex;
    flex: 1;
    gap: 0.25rem;
    min-width: 0;
}

.panel-tab {
    position: relative;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.4rem;
    flex: 1;
    min-height: 2.25rem;
    padding: 0.35rem 0.75rem;
    border: none;
    border-radius: var(--radius-sm);
    background: transparent;
    font: inherit;
    font-size: 0.85rem;
    font-weight: 600;
    color: var(--color-text-muted);
    cursor: pointer;
}

.panel-tab:hover {
    background: var(--color-surface-alt);
    color: var(--color-text);
}

.panel-tab.active {
    background: var(--color-primary-soft);
    color: color-mix(in srgb, var(--color-primary) 55%, var(--color-text));
}

.tab-dot {
    width: 0.5rem;
    height: 0.5rem;
    border-radius: 50%;
    background: var(--color-accent);
}

.panel-close {
    flex-shrink: 0;
    display: inline-grid;
    place-items: center;
    width: 2.25rem;
    height: 2.25rem;
    padding: 0;
    border: none;
    border-radius: var(--radius-sm);
    background: transparent;
    color: var(--color-text-muted);
    cursor: pointer;
}

.panel-close:hover {
    background: var(--color-surface-alt);
    color: var(--color-text);
}

.panel-body {
    flex: 1;
    min-height: 0;
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
    padding: 0.9rem;
    overflow-y: auto;
    overscroll-behavior: contain;
}

/* Comentarios: la lista se desplaza y el formulario queda fijo al pie. */
.panel-pane {
    flex: 1;
    min-height: 0;
    display: flex;
    flex-direction: column;
}

.panel-compose {
    flex-shrink: 0;
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
    padding: 0.75rem 0.9rem 0.9rem;
    background: var(--color-surface);
    border-top: 1px solid var(--color-border);
    box-shadow: 0 -4px 12px rgba(11, 31, 58, 0.06);
}

.panel-intro {
    margin: 0;
    font-size: 0.8rem;
    font-weight: 600;
    color: var(--color-text-muted);
}

.panel-sort {
    display: inline-flex;
    align-self: flex-start;
    padding: 0.15rem;
    border-radius: 999px;
    background: var(--color-surface-alt);
}

.panel-sort button {
    min-height: 2rem;
    padding: 0.2rem 0.8rem;
    border: none;
    border-radius: 999px;
    background: transparent;
    font: inherit;
    font-size: 0.78rem;
    font-weight: 600;
    color: var(--color-text-muted);
    cursor: pointer;
}

.panel-sort button.active {
    background: var(--color-surface);
    color: var(--color-text);
    box-shadow: var(--shadow-sm);
}

.panel-state {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.5rem;
    margin: 0;
    padding: 1.5rem 0.5rem;
    text-align: center;
    font-size: 0.84rem;
}

.panel-state.error {
    color: var(--color-danger);
}

.panel-more {
    align-self: center;
}

/* ── Noticias ─────────────────────────────────────────────────────────── */

.news-card {
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    background: var(--color-surface);
    box-shadow: var(--shadow-sm);
    overflow: hidden;
}

.news-inner {
    display: block;
    color: inherit;
    text-decoration: none;
}

.news-inner.is-link:hover .news-title {
    text-decoration: underline;
}

.news-inner.is-link:hover {
    background: color-mix(in srgb, var(--color-surface-alt) 50%, var(--color-surface));
}

.news-image {
    display: block;
    width: 100%;
    aspect-ratio: 16 / 9;
    object-fit: cover;
    background: var(--color-surface-alt);
    border-bottom: 1px solid var(--color-border);
}

.news-text {
    display: flex;
    flex-direction: column;
    gap: 0.3rem;
    padding: 0.8rem 0.9rem 0.85rem;
}

.news-time {
    font-size: 0.72rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .04em;
    color: var(--color-text-muted);
}

.news-title {
    margin: 0;
    font-size: 0.95rem;
    line-height: 1.3;
    overflow-wrap: anywhere;
}

.news-body {
    margin: 0;
    font-size: 0.84rem;
    line-height: 1.5;
    white-space: pre-line;
    overflow-wrap: anywhere;
    color: var(--color-text);
}

.news-cta {
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
    margin-top: 0.2rem;
    font-size: 0.8rem;
    font-weight: 700;
    color: color-mix(in srgb, var(--color-primary) 60%, var(--color-text));
}

/* ── Entrada y salida ─────────────────────────────────────────────────── */

.feedback-panel-enter-active,
.feedback-panel-leave-active {
    transition: transform .2s ease, opacity .2s ease;
}

.feedback-panel-enter-from,
.feedback-panel-leave-to {
    transform: translateX(1.5rem);
    opacity: 0;
}

@media (prefers-reduced-motion: reduce) {
    .feedback-panel-enter-active,
    .feedback-panel-leave-active {
        transition: none;
    }
}
</style>
