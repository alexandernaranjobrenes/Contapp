<script setup>
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref } from 'vue';
import BackofficeLayout from '../../../Layouts/BackofficeLayout.vue';
import DetailModal from '../../../Components/DetailModal.vue';
import UserAvatar from '../../../Components/UserAvatar.vue';
import FeedbackImages from '../../../Components/Feedback/FeedbackImages.vue';
import { fullDate, timeAgo } from '../../../Utils/relativeTime';
import { CircleCheckBigIcon, ImagePlusIcon, RotateCcwIcon, SearchIcon, SendIcon, Trash2Icon, XIcon } from '@lucide/vue';

/**
 * El canal de comentarios de la aplicación, visto desde el backoffice: lo
 * que la gente escribe en el panel «Comentarios y noticias» de la barra
 * superior (FeedbackModerationController).
 *
 * La fila abre la publicación completa, con sus comentarios (CLAUDE.md
 * secc. 20). Desde ahí se responde como Equipo CONTAPP, se elimina —una
 * publicación o un comentario— con el motivo que le llega por correo al
 * autor, y se marca como solucionada, con un agradecimiento por correo.
 */
const props = defineProps({
    posts: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    counts: { type: Object, default: () => ({}) },
    imageRules: { type: Object, required: true },
});

const STATUS = {
    open: { label: 'Abierta', plural: 'Abiertas', cls: 'badge-success' },
    solved: { label: 'Solucionada', plural: 'Solucionadas', cls: 'badge-neutral' },
    removed: { label: 'Eliminada', plural: 'Eliminadas', cls: 'badge-danger' },
};

// ── Filtros ───────────────────────────────────────────────────────────────
const status = ref(props.filters.status ?? 'open');
const search = ref(props.filters.q ?? '');

function applyFilters() {
    router.get(route('backoffice.feedback.index'), {
        status: status.value === 'open' ? undefined : status.value,
        q: search.value.trim() || undefined,
    }, { preserveState: true, preserveScroll: true, replace: true });
}

const rows = computed(() => props.posts.data ?? []);

// ── La ficha ──────────────────────────────────────────────────────────────
// Se busca en la lista cada vez: después de responder muestra la respuesta
// nueva; al solucionar o eliminar la publicación, sale de la lista y la
// ficha se cierra sola.
const selectedId = ref(null);
const mode = ref('details'); // 'details' | 'remove' | 'solve'
const selected = computed(() => rows.value.find((p) => p.id === selectedId.value) ?? null);

const replyForm = useForm({ body: '', images: [] });
const removeForm = useForm({ reason: '' });
const solveForm = useForm({ note: '' });
// Lo que se va a eliminar: la publicación o uno de sus comentarios.
const removeTarget = ref(null);

function openPost(post) {
    selectedId.value = post.id;
    mode.value = 'details';
    resetReply();
}

// Escape o la X: desde el formulario de eliminar o de solucionar se vuelve a
// la ficha; desde la ficha, se cierra.
function closeModal() {
    if (mode.value !== 'details') {
        mode.value = 'details';
        return;
    }

    selectedId.value = null;
}

const modalTitle = computed(() => {
    if (!selected.value) return '';
    if (mode.value === 'remove') return removeTarget.value?.isPost ? 'Eliminar la publicación' : 'Eliminar el comentario';
    if (mode.value === 'solve') return 'Marcar como solucionada';
    return `Publicación de ${selected.value.author.name}`;
});

// ── Responder como equipo ────────────────────────────────────────────────
const replyPreviews = ref([]);
const replyFileError = ref('');
const replyInput = ref(null);

function pickReplyImages(event) {
    replyFileError.value = '';
    const accepted = props.imageRules.accept.split(',');
    const maxBytes = props.imageRules.max_megabytes * 1024 * 1024;

    for (const file of [...(event.target.files ?? [])]) {
        if (replyForm.images.length >= props.imageRules.max_images) {
            replyFileError.value = `Se pueden adjuntar hasta ${props.imageRules.max_images} imágenes.`;
            break;
        }
        if (!accepted.includes(file.type)) {
            replyFileError.value = `Solo se pueden adjuntar imágenes en ${props.imageRules.formats}.`;
            continue;
        }
        if (file.size > maxBytes) {
            replyFileError.value = `Cada imagen puede pesar hasta ${props.imageRules.max_megabytes} MB.`;
            continue;
        }
        replyForm.images.push(file);
        replyPreviews.value.push(URL.createObjectURL(file));
    }

    event.target.value = '';
}

function removeReplyImage(index) {
    URL.revokeObjectURL(replyPreviews.value[index]);
    replyPreviews.value.splice(index, 1);
    replyForm.images.splice(index, 1);
}

// Cada campo se asigna, no se usa reset(): después de un envío exitoso,
// useForm toma lo enviado como valor inicial y reset() lo volvería a poner.
function resetReply() {
    replyPreviews.value.forEach((url) => URL.revokeObjectURL(url));
    replyPreviews.value = [];
    replyFileError.value = '';
    replyForm.body = '';
    replyForm.images = [];
    replyForm.clearErrors();
}

function submitReply() {
    replyForm.post(route('backoffice.feedback.reply', selected.value.id), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: resetReply,
    });
}

onBeforeUnmount(() => replyPreviews.value.forEach((url) => URL.revokeObjectURL(url)));

// ── Eliminar y solucionar ────────────────────────────────────────────────
function startRemove(comment, isPost) {
    removeTarget.value = { id: comment.id, isPost, author: comment.author };
    removeForm.reason = '';
    removeForm.clearErrors();
    mode.value = 'remove';
}

function submitRemove() {
    removeForm.post(route('backoffice.feedback.remove', removeTarget.value.id), {
        preserveScroll: true,
        onSuccess: () => { mode.value = 'details'; },
    });
}

function startSolve() {
    solveForm.note = '';
    solveForm.clearErrors();
    mode.value = 'solve';
}

function submitSolve() {
    solveForm.post(route('backoffice.feedback.solve', selected.value.id), {
        preserveScroll: true,
        onSuccess: () => { mode.value = 'details'; },
    });
}

function reopen() {
    router.post(route('backoffice.feedback.reopen', selected.value.id), {}, { preserveScroll: true });
}

function votesLabel(post) {
    return `${post.upvotes} a favor · ${post.downvotes} en contra`;
}
</script>

<template>
    <Head title="Comentarios" />

    <BackofficeLayout title="Comentarios">
        <div class="view-toolbar">
            <form class="view-filters" @submit.prevent="applyFilters">
                <label class="filter-field">
                    <span>Estado</span>
                    <select v-model="status" @change="applyFilters">
                        <option v-for="(meta, key) in STATUS" :key="key" :value="key">{{ meta.plural }} ({{ counts[key] ?? 0 }})</option>
                    </select>
                </label>
                <label class="filter-field filter-search">
                    <span>Buscar</span>
                    <input v-model="search" type="search" placeholder="Texto, nombre o correo del autor">
                </label>
                <button type="submit" class="btn btn-primary"><SearchIcon /> Consultar</button>
            </form>
        </div>

        <p class="hint">
            Lo que la gente escribe en el panel «Comentarios y noticias» de la aplicación, que ven todas las cuentas.
            Las abiertas van por votos: lo más pedido primero. Solucionar o eliminar una publicación la saca de la
            aplicación y le avisa al autor por correo.
        </p>

        <div class="card">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Publicación</th>
                            <th class="num">Votos</th>
                            <th class="num">Comentarios</th>
                            <th>Fecha</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="post in rows"
                            :key="post.id"
                            class="clickable-row"
                            tabindex="0"
                            @click="openPost(post)"
                            @keydown.enter="openPost(post)"
                            @keydown.space.prevent="openPost(post)"
                        >
                            <td>
                                <strong>{{ post.author.name }}</strong>
                                <span class="block muted small excerpt">{{ post.excerpt }}</span>
                            </td>
                            <td data-label="Votos" class="num" :class="{ positive: post.score > 0, negative: post.score < 0 }">{{ post.score }}</td>
                            <td data-label="Comentarios" class="num">{{ post.open_replies_count }}</td>
                            <td data-label="Fecha">
                                <time :datetime="post.created_at" :title="fullDate(post.created_at)">{{ timeAgo(post.created_at) }}</time>
                            </td>
                        </tr>
                        <tr v-if="!rows.length">
                            <td colspan="4" class="muted empty-row">
                                <template v-if="filters.q">Nada coincide con «{{ filters.q }}».</template>
                                <template v-else-if="status === 'open'">No hay publicaciones abiertas.</template>
                                <template v-else>No hay publicaciones {{ STATUS[status].plural.toLowerCase() }}.</template>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div v-if="posts.last_page > 1" class="pagination">
            <Link
                v-for="link in posts.links"
                :key="link.label"
                :href="link.url ?? ''"
                class="page-link"
                :class="{ active: link.active, disabled: !link.url }"
                preserve-scroll
                v-html="link.label"
            />
        </div>

        <DetailModal :open="!!selected" :title="modalTitle" wide @close="closeModal">
            <template #badge>
                <span v-if="selected && mode === 'details'" class="badge" :class="STATUS[selected.status].cls">{{ STATUS[selected.status].label }}</span>
            </template>

            <!-- La publicación, sus comentarios y la respuesta del equipo. -->
            <template v-if="selected && mode === 'details'">
                <div class="author">
                    <UserAvatar :url="selected.author.photo_url" :name="selected.author.name" :size="2.4" />
                    <div class="author-meta">
                        <strong>{{ selected.author.name }}</strong>
                        <span v-if="selected.author.email" class="muted small">{{ selected.author.email }}</span>
                    </div>
                    <time class="muted small author-time" :datetime="selected.created_at" :title="fullDate(selected.created_at)">{{ timeAgo(selected.created_at) }}</time>
                </div>

                <p class="post-body">{{ selected.body }}</p>
                <FeedbackImages :images="selected.images" />

                <dl class="detail-list post-facts">
                    <div>
                        <dt>Votos</dt>
                        <dd><strong>{{ selected.score }}</strong> <span class="muted">({{ votesLabel(selected) }})</span></dd>
                    </div>
                    <div v-if="selected.status === 'solved'">
                        <dt>Solucionada</dt>
                        <dd>{{ fullDate(selected.solved_at) }}</dd>
                    </div>
                    <div v-if="selected.status === 'removed'">
                        <dt>Eliminada</dt>
                        <dd>{{ fullDate(selected.removed_at) }}</dd>
                    </div>
                    <div v-if="selected.status === 'removed'" class="full">
                        <dt>Motivo</dt>
                        <dd>{{ selected.removal_reason }}</dd>
                    </div>
                </dl>

                <h3 class="section-title">Comentarios ({{ selected.replies.length }})</h3>
                <p v-if="!selected.replies.length" class="muted small">Todavía nadie comentó esta publicación.</p>
                <ul v-else class="reply-list">
                    <li v-for="reply in selected.replies" :key="reply.id" class="reply" :class="{ 'is-team': reply.author.is_team, 'is-removed': reply.status === 'removed' }">
                        <span v-if="reply.author.is_team" class="team-mark" aria-hidden="true">C</span>
                        <UserAvatar v-else :url="reply.author.photo_url" :name="reply.author.name" :size="1.8" />
                        <div class="reply-main">
                            <div class="reply-meta">
                                <strong>{{ reply.author.name }}</strong>
                                <span v-if="reply.author.email" class="muted">{{ reply.author.email }}</span>
                                <time class="muted" :datetime="reply.created_at" :title="fullDate(reply.created_at)">{{ timeAgo(reply.created_at) }}</time>
                                <span v-if="reply.status === 'removed'" class="badge badge-danger">Eliminado</span>
                            </div>
                            <p class="post-body">{{ reply.body }}</p>
                            <FeedbackImages :images="reply.images" />
                            <p v-if="reply.status === 'removed'" class="muted small removed-reason">Motivo: {{ reply.removal_reason }}</p>
                            <button
                                v-else-if="selected.status === 'open'"
                                type="button"
                                class="btn btn-ghost btn-danger-text reply-remove"
                                @click="startRemove(reply, false)"
                            >
                                <Trash2Icon /> Eliminar comentario
                            </button>
                        </div>
                    </li>
                </ul>

                <form v-if="selected.status === 'open'" class="team-reply" @submit.prevent="submitReply">
                    <div class="field">
                        <label for="team-reply-body">Responder como Equipo CONTAPP</label>
                        <textarea id="team-reply-body" v-model="replyForm.body" rows="3" maxlength="2000" required @input="replyForm.clearErrors('body')" />
                        <span v-if="replyForm.errors.body" class="error">{{ replyForm.errors.body }}</span>
                    </div>

                    <ul v-if="replyPreviews.length" class="reply-previews">
                        <li v-for="(url, index) in replyPreviews" :key="url">
                            <img :src="url" alt="">
                            <button type="button" class="preview-remove" :aria-label="`Quitar la imagen ${index + 1}`" @click="removeReplyImage(index)"><XIcon :size="14" /></button>
                        </li>
                    </ul>
                    <span v-if="replyFileError || replyForm.errors.images" class="error">{{ replyFileError || replyForm.errors.images }}</span>

                    <div class="team-reply-actions">
                        <button
                            type="button"
                            class="btn btn-ghost"
                            :disabled="replyForm.images.length >= imageRules.max_images"
                            @click="replyInput?.click()"
                        >
                            <ImagePlusIcon /> Imagen ({{ replyForm.images.length }}/{{ imageRules.max_images }})
                        </button>
                        <input ref="replyInput" type="file" class="sr-only" tabindex="-1" aria-hidden="true" :accept="imageRules.accept" multiple @change="pickReplyImages">
                        <button type="submit" class="btn btn-primary" :disabled="replyForm.processing || !replyForm.body.trim()">
                            <SendIcon /> Responder
                        </button>
                    </div>
                </form>
            </template>

            <!-- Eliminar: la publicación o un comentario, con el motivo. -->
            <form v-if="selected && mode === 'remove'" id="feedback-remove-form" @submit.prevent="submitRemove">
                <p class="hint">
                    {{ removeTarget.isPost ? 'La publicación' : 'El comentario' }} de <strong>{{ removeTarget.author.name }}</strong>
                    deja de verse en la aplicación y se borran sus imágenes.
                    <template v-if="removeTarget.author.email">
                        Le enviamos este motivo por correo a <strong>{{ removeTarget.author.email }}</strong>.
                    </template>
                </p>
                <div class="field">
                    <label for="feedback-remove-reason">Motivo</label>
                    <textarea id="feedback-remove-reason" v-model="removeForm.reason" rows="4" maxlength="1000" required placeholder="Por qué se elimina: lo va a leer quien lo escribió." />
                    <span v-if="removeForm.errors.reason" class="error">{{ removeForm.errors.reason }}</span>
                </div>
            </form>

            <!-- Solucionar: con un mensaje opcional para el agradecimiento. -->
            <form v-if="selected && mode === 'solve'" id="feedback-solve-form" @submit.prevent="submitSolve">
                <p class="hint">
                    La publicación deja de verse en la aplicación.
                    <template v-if="selected.author.email">
                        <strong>{{ selected.author.name }}</strong> recibe un correo de agradecimiento en
                        <strong>{{ selected.author.email }}</strong>, con tu mensaje si lo escribís.
                    </template>
                </p>
                <div class="field">
                    <label for="feedback-solve-note">Mensaje para el autor (opcional)</label>
                    <textarea id="feedback-solve-note" v-model="solveForm.note" rows="4" maxlength="1000" placeholder="Por ejemplo: ya está disponible en Bancos → Reportes." />
                    <span v-if="solveForm.errors.note" class="error">{{ solveForm.errors.note }}</span>
                </div>
            </form>

            <template #actions>
                <template v-if="selected && mode === 'details'">
                    <button v-if="selected.status !== 'removed'" type="button" class="btn btn-ghost btn-danger-text footer-start" @click="startRemove(selected, true)">
                        <Trash2Icon /> Eliminar
                    </button>
                    <button v-if="selected.status === 'solved'" type="button" class="btn btn-ghost" @click="reopen">
                        <RotateCcwIcon /> Reabrir
                    </button>
                    <button v-if="selected.status === 'open'" type="button" class="btn btn-primary" @click="startSolve">
                        <CircleCheckBigIcon /> Marcar como solucionada
                    </button>
                </template>
                <template v-else-if="selected && mode === 'remove'">
                    <button type="button" class="btn btn-ghost" @click="mode = 'details'">Cancelar</button>
                    <button type="submit" form="feedback-remove-form" class="btn btn-danger" :disabled="removeForm.processing">
                        <Trash2Icon /> Eliminar y avisar
                    </button>
                </template>
                <template v-else-if="selected && mode === 'solve'">
                    <button type="button" class="btn btn-ghost" @click="mode = 'details'">Cancelar</button>
                    <button type="submit" form="feedback-solve-form" class="btn btn-primary" :disabled="solveForm.processing">
                        <CircleCheckBigIcon /> Solucionar y agradecer
                    </button>
                </template>
            </template>
        </DetailModal>
    </BackofficeLayout>
</template>

<style scoped>
.filter-search {
    flex: 1 1 16rem;
}

/* Debajo del nombre, también en la tarjeta de teléfono (que pone en
   negrita la primera celda). */
.excerpt {
    display: block;
    margin-top: 0.1rem;
    font-weight: 400;
    overflow-wrap: anywhere;
}

.num.positive { color: var(--color-success); }
.num.negative { color: var(--color-danger); }

.author {
    display: flex;
    align-items: center;
    gap: 0.7rem;
}

.author-meta {
    display: flex;
    flex-direction: column;
    min-width: 0;
    flex: 1;
    overflow-wrap: anywhere;
}

.author-time {
    flex-shrink: 0;
}

.post-body {
    margin: 0.7rem 0 0;
    font-size: 0.88rem;
    line-height: 1.55;
    white-space: pre-line;
    overflow-wrap: anywhere;
}

.post-facts {
    margin-top: 1rem;
}

.section-title {
    margin: 1.2rem 0 0.6rem;
    font-size: 0.74rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .06em;
    color: var(--color-text-muted);
}

.reply-list {
    display: flex;
    flex-direction: column;
    gap: 0.6rem;
    margin: 0;
    padding: 0;
    list-style: none;
}

.reply {
    display: flex;
    gap: 0.6rem;
    padding: 0.6rem;
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
}

.reply.is-team {
    background: color-mix(in srgb, var(--color-accent) 10%, transparent);
}

.reply.is-removed {
    opacity: .7;
    border-style: dashed;
}

.reply-main {
    flex: 1;
    min-width: 0;
}

.reply-meta {
    display: flex;
    flex-wrap: wrap;
    align-items: baseline;
    gap: 0.15rem 0.5rem;
    font-size: 0.8rem;
    overflow-wrap: anywhere;
}

.reply .post-body {
    margin-top: 0.2rem;
    font-size: 0.85rem;
}

.removed-reason {
    margin: 0.35rem 0 0;
}

.reply-remove {
    min-height: 1.9rem;
    margin-top: 0.4rem;
    padding: 0.2rem 0.6rem;
    font-size: 0.78rem;
}

.team-mark {
    flex-shrink: 0;
    display: inline-grid;
    place-items: center;
    width: 1.8rem;
    height: 1.8rem;
    border-radius: 50%;
    background: var(--color-sidebar);
    color: var(--color-on-sidebar);
    font-size: 0.75rem;
    font-weight: 800;
}

.team-reply {
    margin-top: 1rem;
    padding-top: 1rem;
    border-top: 1px solid var(--color-border);
}

.team-reply textarea {
    width: 100%;
}

.reply-previews {
    display: flex;
    gap: 0.5rem;
    margin: 0 0 0.5rem;
    padding: 0;
    list-style: none;
}

.reply-previews li {
    position: relative;
    width: 4.5rem;
    height: 4.5rem;
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    overflow: hidden;
}

.reply-previews img {
    display: block;
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.preview-remove {
    position: absolute;
    top: 0.2rem;
    right: 0.2rem;
    display: inline-grid;
    place-items: center;
    width: 1.35rem;
    height: 1.35rem;
    padding: 0;
    border: none;
    border-radius: 50%;
    background: var(--color-surface);
    color: var(--color-text);
    box-shadow: var(--shadow-sm);
    cursor: pointer;
}

.team-reply-actions {
    display: flex;
    flex-wrap: wrap;
    justify-content: space-between;
    gap: 0.5rem;
    margin-top: 0.5rem;
}

/* Eliminar va a la izquierda del pie (CLAUDE.md secc. 27). */
.footer-start {
    margin-right: auto;
}
</style>
