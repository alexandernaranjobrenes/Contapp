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

export const conti = reactive({
    open: false,
    sending: false,
    slow: false,
    key: null,
    sessionId: null,
    messages: [], // { id, role: 'user' | 'conti' | 'error', text, retry? }
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
        conti.messages.push({ id: newId(), role: 'conti', text: result.data.respuesta });
    } else {
        conti.messages.push({ id: newId(), role: 'error', text: result.errors?.mensaje ?? result.message, retry: message });
    }

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
