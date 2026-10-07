<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { BotIcon, ClipboardCheckIcon, GaugeIcon, MicIcon, RotateCcwIcon, SendHorizontalIcon, XIcon } from '@lucide/vue';
import ContiForm from './ContiForm.vue';
import ContiQuestions from './ContiQuestions.vue';
import ContiSettings from './ContiSettings.vue';
import {
    closeConti, conti, markAnswered, newConversation, openContiAction, retryConti, sendToConti, showContiChat, showContiSettings, useConversation,
} from '../../Utils/contiChat';
import { renderMarkdown } from '../../Utils/contiMarkdown';
import { useSpeechInput } from '../../Utils/speechInput';

/**
 * El chat con Conti, el asistente (CLAUDE.md secc. 32). Lo abre el botón
 * flotante del robot, en la esquina inferior derecha (AppLayout.vue).
 *
 * Va montado al lado de la página (app.js), no adentro: la conversación y el
 * panel abierto siguen al pasar de pantalla.
 *
 * Se despliega a la derecha, debajo de la barra, como «Comentarios y
 * noticias». No se cierra al tocar fuera: se puede seguir trabajando en la
 * pantalla mientras se le pregunta.
 */
const page = usePage();

const enabled = computed(() => Boolean(page.props.conti?.enabled && page.props.auth?.user));
const userName = computed(() => (page.props.auth?.user?.name ?? '').split(' ')[0]);
// El modelo con que le responde: el recién elegido, o el que trae la página.
const modelName = computed(() => conti.modelName ?? page.props.conti?.model ?? null);
const inSettings = computed(() => conti.view === 'settings');

function toggleSettings() {
    if (inSettings.value) showContiChat();
    else showContiSettings();
}
const companyName = computed(() => {
    const company = (page.props.companies ?? []).find((c) => String(c.id) === String(page.props.currentCompanyId));
    return company ? (company.trade_name || company.legal_name) : 'tu compañía';
});

// Una conversación por persona y compañía: al cambiar de compañía, otra.
watch(
    () => [page.props.auth?.user?.id, page.props.currentCompanyId],
    ([userId, companyId]) => {
        if (userId && companyId) useConversation(userId, companyId);
        if (!userId || !companyId) closeConti();
    },
    { immediate: true },
);

watch(enabled, (on) => { if (!on) closeConti(); });

const SUGGESTIONS = [
    '¿Cómo registro un asiento contable?',
    '¿Qué facturas de clientes están vencidas?',
    '¿Cuánto vendimos este mes?',
];

const draft = ref('');
const input = ref(null);
const list = ref(null);
const panel = ref(null);

const canSend = computed(() => draft.value.trim() !== '' && !conti.sending);

// Dictar en vez de escribir (speechInput.js): la voz se vuelve texto en el
// campo, y la persona lo revisa y lo envía. Al terminar, el foco vuelve al
// campo para corregir o mandar con Enter.
const {
    supported: canDictate,
    listening,
    error: dictationError,
    toggle: toggleDictation,
    cancel: cancelDictation,
} = useSpeechInput(draft, { onEnd: () => nextTick(() => input.value?.focus()) });

/** La pantalla en la que está la persona: Conti la usa de contexto. */
function currentScreen() {
    const title = document.title.replace(/\s+—\s+CONTAPP$/, '');
    let name = null;
    try {
        name = window.route().current();
    } catch {
        name = null;
    }
    return name ? `${title} [${name}]` : title;
}

// Lo que eligió en unas preguntas de Conti va como su próximo mensaje.
function answer(message, respuestas) {
    markAnswered(message, respuestas);
    const lines = respuestas.map((r) => `- ${r.pregunta}: ${[...r.elegidas, ...(r.otra ? [r.otra] : [])].join(', ')}`);
    send(`Mis respuestas:\n${lines.join('\n')}`);
}

async function send(text = draft.value) {
    if (!text.trim() || conti.sending) return;
    // Si todavía estaba dictando, se corta: lo que se envía es lo que se ve.
    cancelDictation();
    dictationError.value = '';
    if (text === draft.value) draft.value = '';
    await sendToConti(text, currentScreen());
}

function onKeydown(event) {
    // Enter manda; Mayús+Enter es otro renglón.
    if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
        event.preventDefault();
        send();
    }
}

function autosize() {
    const el = input.value;
    if (!el) return;
    el.style.height = 'auto';
    el.style.height = `${Math.min(el.scrollHeight, 160)}px`;
}

watch(draft, () => nextTick(autosize));

// Siempre a la vista lo último.
function scrollToEnd() {
    nextTick(() => list.value?.scrollTo({ top: list.value.scrollHeight, behavior: 'smooth' }));
}

watch(() => [conti.messages.length, conti.sending], scrollToEnd);

// De vuelta a la conversación: al final, y listo para escribir.
watch(() => conti.view, (view) => {
    if (view !== 'chat') return;
    nextTick(() => {
        list.value?.scrollTo({ top: list.value.scrollHeight });
        input.value?.focus();
    });
});

const ACTION_LINK = /^\/conti\/acciones\/([A-Za-z0-9-]+)\/?$/;

// Un enlace de CONTAPP se abre acá mismo, sin recargar; uno de afuera, en
// otra pestaña. El de confirmar lo que Conti preparó, en un modal encima de
// la pantalla en que se está (ContiActionModal.vue): no hay que irse de ella.
function onMessageClick(event) {
    const anchor = event.target.closest?.('a[data-internal]');
    if (!anchor) return;
    if (event.ctrlKey || event.metaKey || event.shiftKey || event.button === 1) return;

    event.preventDefault();
    const url = new URL(anchor.href, window.location.origin);
    const review = url.pathname.match(ACTION_LINK);
    if (review) {
        openContiAction(review[1]);
        return;
    }
    router.visit(url.pathname + url.search + url.hash);
}

function startOver() {
    if (conti.sending) return;
    newConversation();
    nextTick(() => input.value?.focus());
}

// Escape cierra, salvo que encima haya algo que lo use antes.
const ON_TOP = '.confirm-backdrop, .modal-backdrop, .image-lightbox';

function onWindowKeydown(event) {
    if (event.key !== 'Escape' || document.querySelector(ON_TOP)) return;
    if (!panel.value?.contains(document.activeElement)) return;
    closeConti();
}

watch(() => conti.open, (open) => {
    if (!open) cancelDictation();
    if (open) {
        window.addEventListener('keydown', onWindowKeydown);
        nextTick(() => {
            input.value?.focus();
            list.value?.scrollTo({ top: list.value.scrollHeight });
        });
        return;
    }
    window.removeEventListener('keydown', onWindowKeydown);
});

onBeforeUnmount(() => window.removeEventListener('keydown', onWindowKeydown));
</script>

<template>
    <Transition name="conti-panel">
        <aside
            v-if="enabled"
            v-show="conti.open"
            id="conti-panel"
            ref="panel"
            class="conti-panel"
            role="dialog"
            aria-modal="false"
            aria-label="Conti, el asistente de CONTAPP"
        >
            <header class="conti-head">
                <span class="conti-avatar" aria-hidden="true"><BotIcon :size="18" /></span>
                <div class="conti-title">
                    <strong>Conti</strong>
                    <span>{{ inSettings ? 'Modelo y consumo' : (modelName ? `Asistente de CONTAPP · ${modelName}` : 'Asistente de CONTAPP') }}</span>
                </div>
                <button
                    type="button"
                    class="conti-icon-btn"
                    :class="{ 'is-active': inSettings }"
                    title="Modelo y consumo"
                    aria-label="Modelo y consumo"
                    :aria-pressed="inSettings"
                    @click="toggleSettings"
                >
                    <GaugeIcon :size="17" />
                </button>
                <button
                    v-show="!inSettings"
                    type="button"
                    class="conti-icon-btn"
                    title="Nueva conversación"
                    aria-label="Nueva conversación"
                    :disabled="conti.sending"
                    @click="startOver"
                >
                    <RotateCcwIcon :size="17" />
                </button>
                <button type="button" class="conti-icon-btn" title="Cerrar" aria-label="Cerrar" @click="closeConti">
                    <XIcon :size="18" />
                </button>
            </header>

            <!-- «Modelo y consumo»: se vuelve a pedir cada vez que se abre. -->
            <ContiSettings v-if="inSettings" />

            <div v-show="!inSettings" ref="list" class="conti-messages" aria-live="polite" @click="onMessageClick">
                <div v-if="!conti.messages.length" class="conti-welcome">
                    <div class="bubble from-conti">
                        <p>¡Hola{{ userName ? `, ${userName}` : '' }}! Soy Conti, el asistente de CONTAPP.</p>
                        <p>
                            Preguntame cómo hacer algo o qué significa un campo, o pedime datos de {{ companyName }}:
                            saldos, facturas, existencias, planilla… Solo veo lo que tus permisos te dejan ver.
                        </p>
                        <p>Si querés que guarde algo, te lo preparo y vos lo confirmás: sin tu confirmación no se guarda nada.</p>
                    </div>
                    <div class="conti-suggestions">
                        <button v-for="text in SUGGESTIONS" :key="text" type="button" class="suggestion" @click="send(text)">
                            {{ text }}
                        </button>
                    </div>
                </div>

                <template v-for="message in conti.messages" :key="message.id">
                    <div v-if="message.role === 'user'" class="bubble from-user">{{ message.text }}</div>
                    <!-- eslint-disable-next-line vue/no-v-html -- renderMarkdown escapa todo antes de dar formato -->
                    <div v-else-if="message.role === 'conti'" class="bubble from-conti markdown" v-html="renderMarkdown(message.text)" />
                    <p v-else-if="message.role === 'notice'" class="conti-notice">{{ message.text }}</p>
                    <ContiQuestions
                        v-else-if="message.role === 'questions'"
                        :message="message"
                        :disabled="conti.sending"
                        @answer="(respuestas) => answer(message, respuestas)"
                    />
                    <ContiForm v-else-if="message.role === 'form'" :message="message" />
                    <!-- Algo preparado: la ventana para confirmar se abrió sola; desde acá se vuelve a abrir. -->
                    <div v-else-if="message.role === 'action'" class="conti-action-card">
                        <ClipboardCheckIcon :size="16" aria-hidden="true" />
                        <span>{{ message.titulo }}</span>
                        <button type="button" class="btn btn-ghost btn-sm" @click="openContiAction(message.actionId)">Revisar y confirmar</button>
                    </div>
                    <div v-else class="bubble is-error" role="alert">
                        <p>{{ message.text }}</p>
                        <button v-if="message.retry" type="button" class="btn btn-ghost btn-sm" :disabled="conti.sending" @click="retryConti(message.retry)">
                            Reintentar
                        </button>
                    </div>
                </template>

                <div v-if="conti.sending" class="bubble from-conti is-thinking" aria-label="Conti está escribiendo">
                    <span class="dots" aria-hidden="true"><i /><i /><i /></span>
                    <span class="thinking-text">{{ conti.slow ? 'Sigue buscando en tus datos…' : 'Pensando…' }}</span>
                </div>
            </div>

            <form v-show="!inSettings" class="conti-compose" @submit.prevent="send()">
                <label for="conti-input" class="sr-only">Tu mensaje para Conti</label>
                <textarea
                    id="conti-input"
                    ref="input"
                    v-model="draft"
                    rows="1"
                    maxlength="4000"
                    :placeholder="listening ? 'Te escucho… hablá y después revisá el texto' : 'Escribile a Conti…'"
                    @keydown="onKeydown"
                />
                <button
                    v-if="canDictate"
                    type="button"
                    class="conti-mic"
                    :class="{ 'is-listening': listening }"
                    :aria-pressed="listening"
                    :aria-label="listening ? 'Dejar de dictar' : 'Dictar por voz'"
                    :title="listening ? 'Dejar de dictar' : 'Dictar por voz'"
                    :disabled="conti.sending"
                    @click="toggleDictation"
                >
                    <MicIcon :size="18" />
                </button>
                <button type="submit" class="conti-send" :disabled="!canSend" aria-label="Enviar" title="Enviar">
                    <SendHorizontalIcon :size="18" />
                </button>
            </form>
            <p v-if="dictationError && !inSettings" class="conti-dictation-error" role="alert">{{ dictationError }}</p>
            <p v-show="!inSettings" class="conti-note">Conti puede equivocarse: revisá lo importante. Nada se guarda sin tu confirmación.</p>
        </aside>
    </Transition>
</template>

<style scoped>
/*
    Como «Comentarios y noticias»: debajo de la barra superior y hasta el
    fondo, a la derecha. Mismo nivel (z-index 25): por debajo del menú lateral
    de las pantallas angostas, de los modales y de los avisos.
*/
.conti-panel {
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

.conti-head {
    flex-shrink: 0;
    display: flex;
    align-items: center;
    gap: 0.6rem;
    padding: 0.55rem 0.6rem 0.55rem 0.85rem;
    background: var(--color-surface);
    border-bottom: 1px solid var(--color-border);
}

.conti-avatar {
    display: inline-grid;
    place-items: center;
    width: 2.1rem;
    height: 2.1rem;
    border-radius: 50%;
    background: var(--color-primary);
    color: var(--color-on-sidebar, #fff);
    flex-shrink: 0;
}

.conti-title {
    display: flex;
    flex-direction: column;
    flex: 1;
    min-width: 0;
    line-height: 1.2;
}

.conti-title strong {
    font-size: 0.95rem;
}

.conti-title span {
    font-size: 0.75rem;
    color: var(--color-text-muted);
}

.conti-icon-btn {
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

.conti-icon-btn:hover:not(:disabled) {
    background: var(--color-surface-alt);
    color: var(--color-text);
}

/* «Modelo y consumo» abierto. El color, mezclado con el del texto: el
   primario solo, en oscuro, casi no se ve sobre su fondo. */
.conti-icon-btn.is-active {
    background: var(--color-primary-soft);
    color: color-mix(in srgb, var(--color-primary) 70%, var(--color-text));
}

.conti-icon-btn:disabled {
    opacity: 0.5;
    cursor: default;
}

.conti-messages {
    flex: 1;
    min-height: 0;
    display: flex;
    flex-direction: column;
    gap: 0.6rem;
    padding: 0.9rem;
    overflow-y: auto;
    overscroll-behavior: contain;
}

.bubble {
    max-width: 92%;
    padding: 0.6rem 0.8rem;
    border-radius: var(--radius-md);
    font-size: 0.86rem;
    line-height: 1.5;
    overflow-wrap: anywhere;
}

.bubble p {
    margin: 0;
}

.bubble p + p {
    margin-top: 0.45rem;
}

.from-user {
    align-self: flex-end;
    background: var(--color-primary);
    color: var(--color-on-sidebar, #fff);
    border-bottom-right-radius: 0.25rem;
    white-space: pre-wrap;
}

.from-conti {
    align-self: flex-start;
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-bottom-left-radius: 0.25rem;
    box-shadow: var(--shadow-sm);
    color: var(--color-text);
}

.is-error {
    align-self: flex-start;
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 0.45rem;
    background: color-mix(in srgb, var(--color-danger) 8%, var(--color-surface));
    border: 1px solid color-mix(in srgb, var(--color-danger) 35%, var(--color-border));
    color: var(--color-text);
}

/* El Markdown de Conti (contiMarkdown.js): llega por v-html, así que los
   estilos van con :deep. */
.markdown :deep(p),
.markdown :deep(ul),
.markdown :deep(ol) {
    margin: 0;
}

/* Entre bloques, siempre el mismo aire: párrafo, lista o tabla. */
.markdown :deep(:is(p, ul, ol, .md-table) + :is(p, ul, ol, .md-table)) {
    margin-top: 0.5rem;
}

.markdown :deep(ul),
.markdown :deep(ol) {
    padding-left: 1.2rem;
}

.markdown :deep(li + li) {
    margin-top: 0.15rem;
}

.markdown :deep(.md-heading) {
    font-weight: 700;
}

.markdown :deep(code) {
    padding: 0.05rem 0.3rem;
    border-radius: 0.25rem;
    background: var(--color-surface-alt);
    font-size: 0.8rem;
}

.markdown :deep(a) {
    font-weight: 600;
    color: color-mix(in srgb, var(--color-primary) 70%, var(--color-text));
    text-decoration: underline;
    text-underline-offset: 2px;
}

.markdown :deep(.md-table) {
    max-width: 100%;
    overflow-x: auto;
}

.markdown :deep(table) {
    border-collapse: collapse;
    font-size: 0.78rem;
    white-space: nowrap;
}

.markdown :deep(th),
.markdown :deep(td) {
    padding: 0.3rem 0.5rem;
    border-bottom: 1px solid var(--color-border);
    text-align: left;
}

.markdown :deep(th) {
    font-weight: 700;
    background: var(--color-surface-alt);
}

/* Lo que Conti preparó para confirmar. */
.conti-action-card {
    align-self: stretch;
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.4rem 0.55rem;
    padding: 0.55rem 0.7rem;
    border: 1px solid color-mix(in srgb, var(--color-primary) 30%, var(--color-border));
    border-radius: var(--radius-md);
    background: var(--color-primary-soft);
    font-size: 0.82rem;
}

.conti-action-card svg {
    color: color-mix(in srgb, var(--color-primary) 70%, var(--color-text));
}

.conti-action-card span {
    flex: 1;
    min-width: 0;
    font-weight: 600;
    overflow-wrap: anywhere;
}

/* El aviso de que un límite de uso va por encima del 80 %. */
.conti-notice {
    align-self: center;
    margin: 0;
    padding: 0.3rem 0.75rem;
    border-radius: 999px;
    background: var(--color-warning-soft);
    color: var(--color-warning);
    font-size: 0.76rem;
    text-align: center;
}

.conti-welcome {
    display: flex;
    flex-direction: column;
    gap: 0.6rem;
}

.conti-suggestions {
    display: flex;
    flex-wrap: wrap;
    gap: 0.4rem;
}

.suggestion {
    padding: 0.4rem 0.7rem;
    border: 1px solid var(--color-border);
    border-radius: 999px;
    background: var(--color-surface);
    font: inherit;
    font-size: 0.8rem;
    color: var(--color-text);
    text-align: left;
    cursor: pointer;
}

.suggestion:hover {
    border-color: color-mix(in srgb, var(--color-primary) 40%, var(--color-border));
    background: var(--color-primary-soft);
}

.is-thinking {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    color: var(--color-text-muted);
}

.thinking-text {
    font-size: 0.8rem;
}

.dots {
    display: inline-flex;
    gap: 0.2rem;
}

.dots i {
    width: 0.38rem;
    height: 0.38rem;
    border-radius: 50%;
    background: var(--color-text-muted);
    animation: conti-dot 1.2s infinite ease-in-out;
}

.dots i:nth-child(2) { animation-delay: .15s; }
.dots i:nth-child(3) { animation-delay: .3s; }

@keyframes conti-dot {
    0%, 80%, 100% { opacity: .25; transform: translateY(0); }
    40% { opacity: 1; transform: translateY(-2px); }
}

.conti-compose {
    flex-shrink: 0;
    display: flex;
    align-items: flex-end;
    gap: 0.5rem;
    padding: 0.7rem 0.9rem 0.35rem;
    background: var(--color-surface);
    border-top: 1px solid var(--color-border);
}

/* El aspecto de los campos de la aplicación (CLAUDE.md secc. 26). */
.conti-compose textarea {
    flex: 1;
    min-height: 2.5rem;
    max-height: 10rem;
    resize: none;
    line-height: 1.4;
}

.conti-send {
    flex-shrink: 0;
    display: inline-grid;
    place-items: center;
    width: 2.5rem;
    height: 2.5rem;
    border: none;
    border-radius: var(--radius-sm);
    background: var(--color-primary);
    color: var(--color-on-sidebar, #fff);
    cursor: pointer;
}

.conti-send:disabled {
    opacity: 0.45;
    cursor: default;
}

/* Dictar por voz: mientras escucha, en rojo y latiendo. */
.conti-mic {
    flex-shrink: 0;
    display: inline-grid;
    place-items: center;
    width: 2.5rem;
    height: 2.5rem;
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    background: var(--color-surface);
    color: var(--color-text-muted);
    cursor: pointer;
}

.conti-mic:hover:not(:disabled) {
    border-color: color-mix(in srgb, var(--color-primary) 45%, var(--color-border));
    color: var(--color-text);
}

.conti-mic:disabled {
    opacity: 0.45;
    cursor: default;
}

.conti-mic.is-listening {
    border-color: var(--color-danger);
    background: var(--color-danger);
    color: #fff;
    animation: conti-mic-pulse 1.4s ease-out infinite;
}

@keyframes conti-mic-pulse {
    0% { box-shadow: 0 0 0 0 color-mix(in srgb, var(--color-danger) 45%, transparent); }
    70% { box-shadow: 0 0 0 0.5rem color-mix(in srgb, var(--color-danger) 0%, transparent); }
    100% { box-shadow: 0 0 0 0 transparent; }
}

.conti-dictation-error {
    flex-shrink: 0;
    margin: 0;
    padding: 0.1rem 0.9rem 0.3rem;
    background: var(--color-surface);
    font-size: 0.74rem;
    color: var(--color-danger);
}

.conti-note {
    flex-shrink: 0;
    margin: 0;
    padding: 0 0.9rem 0.6rem;
    background: var(--color-surface);
    font-size: 0.7rem;
    color: var(--color-text-muted);
}

.conti-panel-enter-active,
.conti-panel-leave-active {
    transition: transform .2s ease, opacity .2s ease;
}

.conti-panel-enter-from,
.conti-panel-leave-to {
    transform: translateX(1.5rem);
    opacity: 0;
}

@media (prefers-reduced-motion: reduce) {
    .conti-panel-enter-active,
    .conti-panel-leave-active {
        transition: none;
    }

    .dots i,
    .conti-mic.is-listening {
        animation: none;
    }
}
</style>
