<script setup>
import { computed, ref } from 'vue';
import { ArrowBigDownIcon, ArrowBigUpIcon, MessageSquareIcon, Trash2Icon } from '@lucide/vue';
import UserAvatar from '../UserAvatar.vue';
import FeedbackComposer from './FeedbackComposer.vue';
import FeedbackImages from './FeedbackImages.vue';
import { confirmAction } from '../../Utils/confirm';
import { requestJson } from '../../Utils/http';
import { notifyError } from '../../Utils/notify';
import { fullDate, timeAgo } from '../../Utils/relativeTime';

/**
 * Una publicación del canal de comentarios: quién, cuándo, el texto y sus
 * imágenes; los votos (a favor y en contra, no «me gusta») y sus comentarios,
 * que se cargan al abrirlos. Quien la escribió puede borrarla.
 *
 * El voto se ve en el acto y se corrige si el servidor no lo acepta.
 */
const props = defineProps({
    post: { type: Object, required: true },
    limits: { type: Object, required: true },
});

const emit = defineEmits(['deleted', 'vote-changed']);

// ── Votos ─────────────────────────────────────────────────────────────────
const voting = ref(false);

async function vote(direction) {
    if (voting.value || props.post.is_mine) return;

    const previous = { score: props.post.score, my_vote: props.post.my_vote };
    // Tocar el mismo voto otra vez lo quita.
    const value = props.post.my_vote === direction ? 0 : direction;

    props.post.score += value - props.post.my_vote;
    props.post.my_vote = value;
    voting.value = true;

    const result = await requestJson(route('feedback.vote', props.post.id), { method: 'PUT', body: { value } });

    voting.value = false;

    if (result.ok) {
        props.post.score = result.data.score;
        props.post.my_vote = result.data.my_vote;
        emit('vote-changed');
        return;
    }

    Object.assign(props.post, previous);
    notifyError(result.status === 422 ? (result.errors.value ?? result.message) : result.message);
}

// ── Comentarios ───────────────────────────────────────────────────────────
const showReplies = ref(false);
const replies = ref(null);
const repliesLoading = ref(false);
const repliesError = ref('');

const repliesLabel = computed(() => {
    const count = props.post.replies_count;
    if (count === 0) return 'Comentar';
    return count === 1 ? '1 comentario' : `${count} comentarios`;
});

async function toggleReplies() {
    showReplies.value = !showReplies.value;
    if (showReplies.value && replies.value === null) await loadReplies();
}

async function loadReplies() {
    repliesLoading.value = true;
    repliesError.value = '';

    const result = await requestJson(route('feedback.replies', props.post.id));

    repliesLoading.value = false;

    if (result.ok) {
        replies.value = result.data.replies;
        props.post.replies_count = replies.value.length;
    } else {
        repliesError.value = result.message;
    }
}

function onReplyCreated(data) {
    replies.value = [...(replies.value ?? []), data.reply];
    props.post.replies_count = replies.value.length;
}

// ── Borrar lo propio ──────────────────────────────────────────────────────
function confirmDelete(reply = null) {
    confirmAction({
        title: reply ? 'Borrar tu comentario' : 'Borrar tu publicación',
        message: reply
            ? 'El comentario se borra con sus imágenes. No se puede deshacer.'
            : 'La publicación se borra con sus votos, sus comentarios y sus imágenes. No se puede deshacer.',
        confirmLabel: 'Borrar',
        danger: true,
        onConfirm: () => destroy(reply),
    });
}

async function destroy(reply) {
    const target = reply ?? props.post;
    const result = await requestJson(route('feedback.destroy', target.id), { method: 'DELETE' });

    if (!result.ok) {
        notifyError(result.message);
        return;
    }

    if (reply) {
        replies.value = replies.value.filter((r) => r.id !== reply.id);
        props.post.replies_count = replies.value.length;
    } else {
        emit('deleted', props.post.id);
    }
}
</script>

<template>
    <article class="feedback-post">
        <header class="post-head">
            <UserAvatar :url="post.author.photo_url" :name="post.author.name" :size="2" />
            <div class="post-meta">
                <strong class="post-author">{{ post.author.name }}</strong>
                <time :datetime="post.created_at" :title="fullDate(post.created_at)" class="post-time">{{ timeAgo(post.created_at) }}</time>
            </div>
            <button
                v-if="post.is_mine"
                type="button"
                class="post-icon-btn"
                aria-label="Borrar tu publicación"
                title="Borrar tu publicación"
                @click="confirmDelete()"
            >
                <Trash2Icon />
            </button>
        </header>

        <p class="post-body">{{ post.body }}</p>
        <FeedbackImages :images="post.images" />

        <footer class="post-foot">
            <button
                type="button"
                class="post-replies-btn"
                :aria-expanded="showReplies"
                :aria-controls="`post-${post.id}-replies`"
                @click="toggleReplies"
            >
                <MessageSquareIcon /> {{ repliesLabel }}
            </button>

            <div class="post-votes" role="group" :aria-label="`Votos: ${post.score}`">
                <button
                    type="button"
                    class="vote-btn vote-up"
                    :class="{ active: post.my_vote === 1 }"
                    :aria-pressed="post.my_vote === 1"
                    :disabled="post.is_mine"
                    :title="post.is_mine ? 'No podés votar tu propia publicación' : 'Votar a favor'"
                    aria-label="Votar a favor"
                    @click="vote(1)"
                >
                    <ArrowBigUpIcon :size="20" />
                </button>
                <span class="vote-score" :class="{ positive: post.score > 0, negative: post.score < 0 }" aria-hidden="true">{{ post.score }}</span>
                <button
                    type="button"
                    class="vote-btn vote-down"
                    :class="{ active: post.my_vote === -1 }"
                    :aria-pressed="post.my_vote === -1"
                    :disabled="post.is_mine"
                    :title="post.is_mine ? 'No podés votar tu propia publicación' : 'Votar en contra'"
                    aria-label="Votar en contra"
                    @click="vote(-1)"
                >
                    <ArrowBigDownIcon :size="20" />
                </button>
            </div>
        </footer>

        <div v-if="showReplies" :id="`post-${post.id}-replies`" class="post-replies">
            <p v-if="repliesLoading" class="muted small">Cargando comentarios…</p>
            <p v-else-if="repliesError" class="error">
                {{ repliesError }}
                <button type="button" class="link-btn" @click="loadReplies">Reintentar</button>
            </p>

            <ul v-if="replies?.length" class="reply-list">
                <li v-for="reply in replies" :key="reply.id" class="reply" :class="{ 'is-team': reply.author.is_team }">
                    <span v-if="reply.author.is_team" class="team-mark" aria-hidden="true">C</span>
                    <UserAvatar v-else :url="reply.author.photo_url" :name="reply.author.name" :size="1.6" />
                    <div class="reply-main">
                        <div class="reply-meta">
                            <strong>{{ reply.author.name }}</strong>
                            <time :datetime="reply.created_at" :title="fullDate(reply.created_at)" class="post-time">{{ timeAgo(reply.created_at) }}</time>
                            <button
                                v-if="reply.is_mine"
                                type="button"
                                class="post-icon-btn reply-delete"
                                aria-label="Borrar tu comentario"
                                title="Borrar tu comentario"
                                @click="confirmDelete(reply)"
                            >
                                <Trash2Icon :size="14" />
                            </button>
                        </div>
                        <p class="post-body">{{ reply.body }}</p>
                        <FeedbackImages :images="reply.images" />
                    </div>
                </li>
            </ul>

            <FeedbackComposer
                compact
                :url="route('feedback.replies.store', post.id)"
                label="Tu comentario"
                placeholder="Escribí un comentario…"
                submit-label="Comentar"
                :limits="limits"
                @created="onReplyCreated"
            />
        </div>
    </article>
</template>

<style scoped>
.feedback-post {
    padding: 0.85rem 0.9rem 0.7rem;
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    background: var(--color-surface);
    box-shadow: var(--shadow-sm);
}

.post-head {
    display: flex;
    align-items: center;
    gap: 0.6rem;
}

.post-meta {
    display: flex;
    flex-direction: column;
    min-width: 0;
    flex: 1;
}

.post-author {
    font-size: 0.85rem;
    overflow-wrap: anywhere;
}

.post-time {
    font-size: 0.74rem;
    color: var(--color-text-muted);
}

.post-icon-btn {
    flex-shrink: 0;
    display: inline-grid;
    place-items: center;
    width: 2rem;
    height: 2rem;
    padding: 0;
    border: none;
    border-radius: var(--radius-sm);
    background: transparent;
    color: var(--color-text-muted);
    cursor: pointer;
}

.post-icon-btn:hover {
    background: var(--color-danger-soft);
    color: var(--color-danger);
}

.post-body {
    margin: 0.55rem 0 0;
    font-size: 0.86rem;
    line-height: 1.5;
    white-space: pre-line;
    overflow-wrap: anywhere;
}

.post-foot {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.5rem;
    margin-top: 0.6rem;
}

.post-replies-btn,
.link-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    min-height: 2rem;
    padding: 0.25rem 0.5rem;
    margin-left: -0.5rem;
    border: none;
    border-radius: var(--radius-sm);
    background: transparent;
    font: inherit;
    font-size: 0.8rem;
    font-weight: 600;
    color: var(--color-text-muted);
    cursor: pointer;
}

.post-replies-btn:hover,
.link-btn:hover {
    background: var(--color-surface-alt);
    color: var(--color-text);
}

.link-btn {
    margin-left: 0.25rem;
    color: var(--color-danger);
}

/* Los votos, como en un foro: la flecha hacia arriba a favor, la de abajo en
   contra, y entre las dos el puntaje. La del voto propio va rellena. */
.post-votes {
    display: inline-flex;
    align-items: center;
    gap: 0.1rem;
    padding: 0.1rem;
    border-radius: 999px;
    background: var(--color-surface-alt);
}

.vote-btn {
    display: inline-grid;
    place-items: center;
    width: 2rem;
    height: 2rem;
    padding: 0;
    border: none;
    border-radius: 50%;
    background: transparent;
    color: var(--color-text-muted);
    cursor: pointer;
    transition: background-color .12s ease, color .12s ease;
}

.vote-btn:disabled {
    cursor: not-allowed;
    opacity: .45;
}

.vote-up:not(:disabled):hover,
.vote-up.active {
    color: var(--color-success);
}

.vote-down:not(:disabled):hover,
.vote-down.active {
    color: var(--color-danger);
}

.vote-btn:not(:disabled):hover {
    background: var(--color-surface);
}

.vote-btn.active :deep(svg) {
    fill: currentColor;
}

.vote-score {
    min-width: 1.6rem;
    text-align: center;
    font-size: 0.85rem;
    font-weight: 700;
    font-variant-numeric: tabular-nums;
}

.vote-score.positive { color: var(--color-success); }
.vote-score.negative { color: var(--color-danger); }

.post-replies {
    display: flex;
    flex-direction: column;
    gap: 0.6rem;
    margin-top: 0.6rem;
    padding-top: 0.7rem;
    border-top: 1px solid var(--color-border);
}

.post-replies > p {
    margin: 0;
}

.reply-list {
    display: flex;
    flex-direction: column;
    gap: 0.7rem;
    margin: 0;
    padding: 0;
    list-style: none;
}

.reply {
    display: flex;
    gap: 0.55rem;
}

.reply-main {
    flex: 1;
    min-width: 0;
}

.reply-meta {
    display: flex;
    align-items: baseline;
    flex-wrap: wrap;
    gap: 0.15rem 0.45rem;
    font-size: 0.8rem;
}

.reply .post-body {
    margin-top: 0.15rem;
    font-size: 0.84rem;
}

/* Con márgenes negativos: la papelera no agranda la línea del nombre. */
.reply-delete {
    width: 1.6rem;
    height: 1.6rem;
    margin: -0.35rem -0.3rem -0.35rem auto;
    align-self: center;
}

/* La respuesta del equipo de CONTAPP se distingue: la marca en lugar de la
   foto y un fondo del acento. */
.reply.is-team {
    margin: 0 -0.5rem;
    padding: 0.5rem;
    border-radius: var(--radius-sm);
    background: color-mix(in srgb, var(--color-accent) 10%, transparent);
}

.team-mark {
    flex-shrink: 0;
    display: inline-grid;
    place-items: center;
    width: 1.6rem;
    height: 1.6rem;
    border-radius: 50%;
    background: var(--color-sidebar);
    color: var(--color-on-sidebar);
    font-size: 0.72rem;
    font-weight: 800;
}
</style>
