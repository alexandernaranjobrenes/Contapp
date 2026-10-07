import { reactive } from 'vue';
import { requestJson } from './http';

/**
 * La conversación con Conti (CLAUDE.md secc. 32). Vive acá y no en el
 * panel: el panel (ContiPanel.vue) se monta al lado de la página, y la
 * conversación sigue igual al pasar de una pantalla a otra —por ejemplo, al
 * abrir el enlace para confirmar lo que Conti preparó—.
 *
 * Una conversación por persona y por compañía, guardada en sessionStorage
 * (se pierde al cerrar la pestaña). Al cambiar de compañía se pasa a la de
 * esa compañía: lo que se habló de una no se mezcla con la otra.
 */

const MAX_MESSAGES = 60;
const SLOW_MS = 15000;
// Las respuestas en las que ContiChatController explica qué pasó.
const OWN_REASON_STATUSES = [403, 409, 429, 502, 503];
const GENERIC_MESSAGES = ['Too Many Attempts.', 'Service Unavailable', 'Server Error', 'This action is unauthorized.'];

export const conti = reactive({
    open: false,
    sending: false,
    slow: false,
    key: null,
    sessionId: null,
    // { id, role, … }. role: 'user' | 'conti' | 'notice' | 'error' (con text),
    // 'questions' (preguntas con opciones, ContiQuestions.vue), 'form' (un
    // formulario, ContiForm.vue) o 'action' (algo preparado para confirmar).
    messages: [],
    // Lo que Conti preparó y la persona está revisando en el modal
    // (ContiActionModal.vue): su uuid, o null.
    review: null,
    // Lo que muestra el panel: la conversación, o «Modelo y consumo»
    // (ContiSettings.vue).
    view: 'chat',
    // El modelo recién elegido, hasta que la página traiga el nuevo.
    modelName: null,
});

function newSessionId() {
    const raw = window.crypto?.randomUUID?.() ?? `${Date.now()}-${Math.random().toString(16).slice(2)}`;
    return raw.replace(/[^A-Za-z0-9-]/g, '');
}

function newId() {
    return `${Date.now()}-${Math.random().toString(16).slice(2, 8)}`;
}

function load(key) {
    try {
        const saved = JSON.parse(window.sessionStorage.getItem(key) ?? 'null');
        if (saved && typeof saved.sessionId === 'string' && Array.isArray(saved.messages)) return saved;
    } catch {
        // Sin almacenamiento (ventana privada, bloqueado): se empieza de cero.
    }
    return { sessionId: newSessionId(), messages: [] };
}

function persist() {
    if (!conti.key) return;
    try {
        window.sessionStorage.setItem(conti.key, JSON.stringify({
            sessionId: conti.sessionId,
            messages: conti.messages.filter((m) => m.role !== 'error').slice(-MAX_MESSAGES),
        }));
    } catch {
        // Sin almacenamiento: la conversación dura lo que dure la página.
    }
}

/** La conversación de esta persona en esta compañía. */
export function useConversation(userId, companyId) {
    const key = `contapp:conti:${userId}:${companyId}`;
    if (conti.key === key) return;

    const saved = load(key);
    conti.key = key;
    conti.sessionId = saved.sessionId;
    conti.messages = saved.messages;
    conti.sending = false;
    conti.slow = false;
    conti.review = null;
    conti.view = 'chat';
}

export function newConversation() {
    conti.sessionId = newSessionId();
    conti.messages = [];
    persist();
}

export function toggleConti() {
    conti.open = !conti.open;
}

export function closeConti() {
    conti.open = false;
    // Al volver a abrirlo, la conversación.
    conti.view = 'chat';
}

export function showContiSettings() {
    conti.view = 'settings';
}

export function showContiChat() {
    conti.view = 'chat';
}

/**
 * Le manda un mensaje a Conti (ContiChatController). La pantalla en la que
 * está la persona va de contexto: «¿qué es este campo?» depende de dónde.
 */
export async function sendToConti(text, screen = null) {
    const message = text.trim();
    if (!message || conti.sending) return;

    // Un error anterior ya no sirve: el mensaje nuevo lo reemplaza.
    conti.messages = conti.messages.filter((m) => m.role !== 'error');
    conti.messages.push({ id: newId(), role: 'user', text: message });
    conti.sending = true;
    conti.slow = false;
    persist();

    const key = conti.key;
    const slowTimer = setTimeout(() => { conti.slow = true; }, SLOW_MS);

    const result = await requestJson(window.route('conti.messages.store'), {
        method: 'POST',
        body: { mensaje: message, sesion: conti.sessionId, pantalla: screen },
    });

    clearTimeout(slowTimer);

    // Se cambió de compañía mientras Conti respondía: esa respuesta ya no va acá.
    if (conti.key !== key) return;

    conti.sending = false;
    conti.slow = false;

    if (result.ok) {
        const { respuesta, interaccion, acciones, aviso } = result.data;

        if (respuesta) conti.messages.push({ id: newId(), role: 'conti', text: respuesta });

        // Lo que Conti le pide a la persona: elegir entre opciones o completar
        // un formulario, ahí mismo en el chat.
        if (interaccion?.tipo === 'preguntas') {
            conti.messages.push({ id: newId(), role: 'questions', preguntas: interaccion.preguntas, respuestas: null });
        } else if (interaccion?.tipo === 'formulario') {
            pushForm(interaccion.formulario, false);
        }

        // Lo que preparó: la ventana para confirmarlo se abre sola.
        for (const action of acciones ?? []) addActionCard(action);
        if (acciones?.length) openContiAction(acciones.at(-1).id);

        // Un límite de uso va por encima del 80 %.
        if (aviso) conti.messages.push({ id: newId(), role: 'notice', text: aviso });
    } else {
        // El motivo que da Conti («Se alcanzó el límite diario…», «Conti no
        // está disponible…»); los textos genéricos de Laravel, en inglés, no.
        const own = result.data?.message;
        const reason = OWN_REASON_STATUSES.includes(result.status) && own && !GENERIC_MESSAGES.includes(own) ? own : null;
        // Sin Conti en la licencia, o sin cupo: reintentar no cambia nada.
        const final = reason !== null && [403, 429].includes(result.status);
        conti.messages.push({ id: newId(), role: 'error', text: result.errors?.mensaje ?? reason ?? result.message, retry: final ? null : message });
    }

    persist();
}

/**
 * El enlace para confirmar algo que Conti preparó se abre en un modal, encima
 * de la pantalla en que se está: no hay que irse de ella para decidir.
 */
export function openContiAction(uuid) {
    conti.review = uuid;
}

export function closeContiAction() {
    conti.review = null;
}

/** Algo preparado para confirmar, como tarjeta en la conversación: vuelve a abrir la ventana. */
function addActionCard(action) {
    conti.messages.push({ id: newId(), role: 'action', actionId: action.id, titulo: action.titulo });
}

/**
 * Un formulario en la conversación (de Conti, o el de «Corregir»). Los
 * valores viven en el mensaje: si se pasa a otra pantalla, lo escrito sigue.
 */
export function pushForm(formulario, save = true) {
    conti.messages.push({
        id: newId(),
        role: 'form',
        formulario,
        valores: JSON.parse(JSON.stringify(formulario.valores ?? {})),
        // Lo que sugirió CONTAPP según cómo se viene trabajando, y lo que se
        // leyó de un documento escaneado: campo => { valor, motivo, origen? }.
        sugeridos: {
            ...(formulario.sugeridos ?? {}),
            ...Object.fromEntries(Object.entries(formulario.leidos ?? {})
                .map(([campo, valor]) => [campo, { valor, motivo: 'Leído del documento.', origen: 'documento' }])),
        },
        // Lo que Conti avisa al leer un documento (lo que no encontró…).
        nota: formulario.nota ?? null,
        enviado: null,
    });
    conti.view = 'chat';
    conti.open = true;
    if (save) persist();
}

/**
 * Envía un formulario del chat (ContiFormController). Si se preparó, la
 * ventana para confirmar se abre sola. Devuelve los errores de validación,
 * campo por campo («lineas.0.cuenta»), para mostrarlos junto a cada uno.
 */
export async function submitContiForm(message) {
    const result = await requestJson(window.route('conti.forms.submit'), {
        method: 'POST',
        body: { accion: message.formulario.accion, datos: message.valores, sesion: conti.sessionId },
    });

    if (!result.ok) {
        return {
            errors: result.status === 422 ? (result.data?.errors ?? {}) : {},
            message: result.status === 422 ? null : (result.data?.message && [403, 404, 409].includes(result.status) ? result.data.message : result.message),
        };
    }

    // El formulario enviado queda como la tarjeta de lo preparado: desde ahí
    // se vuelve a abrir la ventana.
    message.enviado = result.data.accion;
    openContiAction(result.data.accion.id);
    persist();

    return { errors: {}, message: null };
}

/** La persona eligió en unas preguntas de Conti: quedan respondidas. */
export function markAnswered(message, respuestas) {
    message.respuestas = respuestas;
    persist();
}

/** Cómo quedó lo que Conti preparó, como nota en la conversación. */
export function noteInConversation(text) {
    conti.messages.push({ id: newId(), role: 'notice', text });
    persist();
}

/** Volver a mandar un mensaje que falló. */
export function retryConti(message) {
    // Sin el error y sin el mensaje que falló: sendToConti lo vuelve a poner.
    const list = conti.messages.filter((m) => m.role !== 'error');
    const last = list.at(-1);
    conti.messages = last?.role === 'user' && last.text === message ? list.slice(0, -1) : list;

    return sendToConti(message);
}
