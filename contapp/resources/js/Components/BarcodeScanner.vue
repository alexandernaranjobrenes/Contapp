<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { ImageUpIcon, SmartphoneIcon, XIcon } from '@lucide/vue';

/**
 * Leer un código de barras con la cámara de este dispositivo, ahí mismo en
 * el formulario (no en otro modal: dos modales se pelearían el Escape y el
 * foco). Desde una computadora se ofrece también el teléfono (BarcodePhoneLink).
 *
 * - Con video en vivo cuando el navegador deja usar la cámara: hace falta una
 *   conexión segura (https, o localhost). Lee solo, sin tocar nada.
 * - Si no (http en la red local, sin cámara, sin permiso): con una foto, que
 *   en el teléfono abre la cámara y en la computadora deja subir una imagen.
 * - La lectura es en el navegador (ZXing, que se carga recién al abrir esto):
 *   la imagen no sale del dispositivo.
 *
 * Emite «detected» con el texto leído, «use-phone» y «close».
 */
defineProps({
    // En una computadora: el botón para hacerlo con el teléfono.
    phoneOption: { type: Boolean, default: false },
});

const emit = defineEmits(['detected', 'use-phone', 'close']);

const video = ref(null);
const photoInput = ref(null);
const status = ref('starting'); // starting | live | photo | reading
const message = ref(''); // por qué no hay video
const photoError = ref('');
let controls = null;
let readers = null;
// Cerrado o ya leído: la cámara que termine de abrir después se apaga sola.
let done = false;

const statusText = computed(() => ({
    starting: 'Abriendo la cámara…',
    live: 'Apuntá al código de barras, derecho y que se vea completo: se lee solo.',
    reading: 'Leyendo la foto…',
}[status.value] ?? message.value));

// Solo los códigos de una dimensión, los de los productos: lee más rápido.
// El video prueba cuadro por cuadro, liviano; la foto es una sola, y ahí sí
// se busca a fondo («try harder»), que en cada cuadro trabaría la página.
async function makeReaders() {
    if (readers) return readers;

    const [{ BrowserMultiFormatOneDReader }, { BarcodeFormat, DecodeHintType }] = await Promise.all([
        import('@zxing/browser'),
        import('@zxing/library'),
    ]);
    const formats = ['EAN_13', 'EAN_8', 'UPC_A', 'UPC_E', 'CODE_128', 'CODE_39', 'ITF'].map((f) => BarcodeFormat[f]);
    readers = {
        live: new BrowserMultiFormatOneDReader(new Map([[DecodeHintType.POSSIBLE_FORMATS, formats]]), { delayBetweenScanAttempts: 200 }),
        photo: new BrowserMultiFormatOneDReader(new Map([[DecodeHintType.POSSIBLE_FORMATS, formats], [DecodeHintType.TRY_HARDER, true]])),
    };
    return readers;
}

function photoOnly(text) {
    status.value = 'photo';
    message.value = text;
}

async function startCamera() {
    if (!navigator.mediaDevices?.getUserMedia) {
        photoOnly('Aquí no se puede usar la cámara en vivo (hace falta una conexión segura). Tomá una foto del código.');
        return;
    }

    try {
        const { live } = await makeReaders();
        if (done) return;
        const started = await live.decodeFromConstraints(
            { video: { facingMode: { ideal: 'environment' } } },
            video.value,
            (result) => { if (result) found(result.getText()); },
        );
        if (done) {
            started.stop();
            return;
        }
        controls = started;
        status.value = 'live';
    } catch (error) {
        if (done) return;
        if (error?.name === 'NotAllowedError') {
            photoOnly('No hay permiso para usar la cámara: se da desde el candado de la barra de direcciones. O tomá una foto del código.');
        } else if (['NotFoundError', 'OverconstrainedError', 'NotReadableError'].includes(error?.name)) {
            photoOnly('No encontré una cámara disponible en este dispositivo. Podés subir una foto del código.');
        } else {
            photoOnly('No se pudo abrir la cámara. Podés tomar o subir una foto del código.');
        }
    }
}

function stop() {
    done = true;
    controls?.stop();
    controls = null;
}

function found(text) {
    if (done) return;
    stop();
    emit('detected', String(text).trim());
}

function loadImage(file) {
    return new Promise((resolve, reject) => {
        const url = URL.createObjectURL(file);
        const image = new Image();
        image.onload = () => { URL.revokeObjectURL(url); resolve(image); };
        image.onerror = () => { URL.revokeObjectURL(url); reject(new Error('foto')); };
        image.src = url;
    });
}

// La foto, achicada: una de 12 megapíxeles tarda mucho en leerse.
function canvasFrom(image, maxSide) {
    const scale = Math.min(1, maxSide / Math.max(image.naturalWidth, image.naturalHeight));
    const canvas = document.createElement('canvas');
    canvas.width = Math.round(image.naturalWidth * scale);
    canvas.height = Math.round(image.naturalHeight * scale);
    canvas.getContext('2d').drawImage(image, 0, 0, canvas.width, canvas.height);
    return canvas;
}

async function onPhoto(event) {
    const file = event.target.files?.[0];
    event.target.value = '';
    if (!file) return;

    const before = status.value;
    status.value = 'reading';
    photoError.value = '';
    try {
        const [image, { photo }] = await Promise.all([loadImage(file), makeReaders()]);
        // Con otro tamaño, a veces sí.
        for (const side of [1600, 1000]) {
            try {
                found(photo.decodeFromCanvas(canvasFrom(image, side)).getText());
                return;
            } catch {
                // sigue
            }
        }
        throw new Error('sin código');
    } catch {
        if (done) return;
        status.value = before;
        photoError.value = 'No encontré un código de barras en la foto. Probá más cerca, derecho, con buena luz y que se vea completo.';
    }
}

onMounted(startCamera);
onBeforeUnmount(stop);
</script>

<template>
    <div class="scanner">
        <div v-show="status === 'starting' || status === 'live'" class="scanner-view">
            <video ref="video" muted playsinline aria-label="Vista de la cámara" />
            <span class="scanner-guide" aria-hidden="true" />
        </div>
        <p class="scanner-status" :class="{ 'is-warning': status === 'photo' }" role="status">{{ statusText }}</p>
        <p v-if="photoError" class="scanner-status is-warning" role="alert">{{ photoError }}</p>
        <div class="scanner-actions">
            <button type="button" class="btn btn-ghost btn-sm" :disabled="status === 'reading'" @click="photoInput?.click()">
                <ImageUpIcon /> Tomar o subir una foto
            </button>
            <button v-if="phoneOption" type="button" class="btn btn-ghost btn-sm" @click="emit('use-phone')">
                <SmartphoneIcon /> Usar el teléfono
            </button>
            <button type="button" class="btn btn-ghost btn-sm" @click="emit('close')"><XIcon /> Cerrar</button>
        </div>
        <input ref="photoInput" type="file" accept="image/*" capture="environment" class="sr-only" tabindex="-1" @change="onPhoto">
    </div>
</template>

<style scoped>
.scanner {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
    padding: 0.75rem;
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    background: var(--color-surface-alt);
}

.scanner-view {
    position: relative;
    width: 100%;
    max-width: 24rem;
    aspect-ratio: 4 / 3;
    overflow: hidden;
    border-radius: var(--radius-sm);
    background: #000;
}

.scanner-view video {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

/* La línea donde poner el código. */
.scanner-guide {
    position: absolute;
    left: 10%;
    right: 10%;
    top: 50%;
    height: 2px;
    background: #ef4444;
    box-shadow: 0 0 6px #ef4444;
}

.scanner-status {
    margin: 0;
    font-size: 0.8rem;
    line-height: 1.45;
}

.scanner-status.is-warning {
    color: var(--color-warning);
}

.scanner-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
}
</style>
