import { onBeforeUnmount, ref } from 'vue';

/**
 * Dictado por voz para un campo de texto (el chat de Conti, CLAUDE.md
 * secc. 32). La voz se convierte en texto en el navegador, con su
 * reconocimiento de voz (Web Speech API): no pasa por el modelo de Conti ni
 * gasta créditos, y a CONTAPP no le llega audio, solo el texto que la
 * persona decide enviar.
 *
 * - Lo tienen Chrome, Edge y Safari; Firefox no. Sin él, el botón no se
 *   muestra (speechSupported).
 * - Chrome y Edge reconocen la voz en los servidores de Google o Microsoft.
 * - El permiso del micrófono lo pide el navegador al empezar a dictar, es
 *   decir, cuando la persona toca el botón; nunca antes.
 * - El texto va apareciendo en el campo mientras se habla, se suma a lo que
 *   ya estaba escrito, y queda ahí para revisarlo: no se envía solo.
 * - Se detiene solo cuando la persona deja de hablar, o al tocar de nuevo.
 */
const Recognition = typeof window === 'undefined' ? null : (window.SpeechRecognition ?? window.webkitSpeechRecognition ?? null);

export const speechSupported = Recognition !== null;

const ERRORS = {
    'not-allowed': 'Para dictar, permití el uso del micrófono en el navegador (el candado de la barra de direcciones).',
    'service-not-allowed': 'El navegador no deja dictar en esta página. Podés escribir el mensaje.',
    'no-speech': 'No te escuché. Tocá el micrófono y probá de nuevo.',
    'audio-capture': 'No encontramos un micrófono conectado.',
    network: 'No hay conexión para reconocer la voz. Probá de nuevo o escribí el mensaje.',
    'language-not-supported': 'El navegador no reconoce el español para dictar.',
};

/**
 * @param {import('vue').Ref<string>} text  el campo donde va quedando lo dictado
 * @param {{ lang?: string, maxLength?: number, onEnd?: () => void }} options
 */
export function useSpeechInput(text, { lang = 'es-CR', maxLength = 4000, onEnd = null } = {}) {
    const listening = ref(false);
    const error = ref('');
    let recognition = null;

    function start() {
        if (!Recognition || recognition) return;

        error.value = '';
        // Lo que ya estaba escrito queda, y lo dictado se suma después.
        const before = text.value.trim() ? `${text.value.trimEnd()} ` : '';
        let heard = '';

        recognition = new Recognition();
        recognition.lang = lang;
        recognition.interimResults = true;
        recognition.continuous = false;
        recognition.maxAlternatives = 1;

        recognition.onresult = (event) => {
            let pending = '';

            for (let i = event.resultIndex; i < event.results.length; i++) {
                const piece = event.results[i][0].transcript;
                if (event.results[i].isFinal) heard += piece;
                else pending += piece;
            }

            const spoken = (heard + pending).replace(/\s+/g, ' ').trim();
            // La primera letra en mayúscula si empieza el mensaje o una oración.
            const startsSentence = before === '' || /[.!?¡¿]\s*$/.test(before);
            const shown = startsSentence ? spoken.charAt(0).toUpperCase() + spoken.slice(1) : spoken;
            text.value = (before + shown).slice(0, maxLength);
        };

        recognition.onerror = (event) => {
            // «aborted» es el que corta el propio CONTAPP (al enviar, al cerrar).
            if (event.error !== 'aborted') {
                error.value = ERRORS[event.error] ?? 'No se pudo reconocer la voz. Probá de nuevo o escribí el mensaje.';
            }
        };

        recognition.onend = () => {
            recognition = null;
            listening.value = false;
            onEnd?.();
        };

        try {
            recognition.start();
            listening.value = true;
        } catch {
            recognition = null;
            error.value = 'No se pudo empezar a dictar. Probá de nuevo.';
        }
    }

    /** Termina de escuchar y se queda con lo reconocido. */
    function stop() {
        recognition?.stop();
    }

    /** Corta sin esperar lo que falte reconocer (al enviar o al cerrar el chat). */
    function cancel() {
        recognition?.abort();
    }

    function toggle() {
        if (listening.value) stop();
        else start();
    }

    onBeforeUnmount(cancel);

    return { supported: speechSupported, listening, error, toggle, stop, cancel };
}
