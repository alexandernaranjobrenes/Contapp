<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import qrcode from 'qrcode-generator';
import { CameraIcon, ChevronLeftIcon, ImageUpIcon, ScanLineIcon, XIcon } from '@lucide/vue';
import DetailModal from '../DetailModal.vue';
import { conti, pushForm } from '../../Utils/contiChat';
import { canCaptureHere, closeScanner, scanner, shrinkPhoto } from '../../Utils/contiScan';
import { requestJson } from '../../Utils/http';

/**
 * Escanear un documento para registrarlo (CLAUDE.md secc. 32):
 *
 * 1. Se elige qué registrar (solo lo que los permisos dejan).
 * 2. En un teléfono o una tableta con cámara, se toman las fotos ahí mismo.
 *    En una computadora, un QR para hacerlo con el teléfono: la página que
 *    abre solo sirve para subir las fotos de este escaneo, y esta ventana va
 *    preguntando hasta que el documento está leído.
 * 3. La IA lee el documento y llena el formulario, que aparece en el chat
 *    para revisarlo, corregirlo y enviarlo. Después, la confirmación de
 *    siempre. Las fotos nunca se guardan (ContiScanService).
 */
const MAX_PHOTOS = 3;
const POLL_MS = 2500;

const step = ref('choose'); // choose | qr | capture | reading
const here = ref(false); // ¿se toman las fotos en este dispositivo?
const actions = ref(null);
const chosen = ref(null);
const error = ref('');
const loading = ref(false);

// QR
const session = ref(null); // { id, url, minutos }
const qrSvg = ref('');
const phoneState = ref('esperando');
const phoneMessage = ref('');
const secondsLeft = ref(0);
let pollTimer = null;
let clockTimer = null;

// Fotos
const photos = ref([]); // { blob, preview }
const camera = ref(null);

const title = computed(() => (chosen.value ? `Escanear: ${chosen.value.titulo}` : 'Escanear un documento'));
const clock = computed(() => `${Math.floor(secondsLeft.value / 60)}:${String(secondsLeft.value % 60).padStart(2, '0')}`);

watch(() => scanner.open, async (open) => {
    reset();
    if (!open) return;

    here.value = await canCaptureHere();
    loading.value = true;
    const result = await requestJson(window.route('conti.scan.actions'));
    loading.value = false;

    if (result.ok) actions.value = result.data.acciones;
    else error.value = result.data?.message ?? result.message;
});

function reset() {
    stopPolling();
    photos.value.forEach((photo) => URL.revokeObjectURL(photo.preview));
    step.value = 'choose';
    chosen.value = null;
    actions.value = null;
    error.value = '';
    session.value = null;
    qrSvg.value = '';
    phoneState.value = 'esperando';
    phoneMessage.value = '';
    photos.value = [];
}

function close() {
    closeScanner();
}

async function choose(action) {
    chosen.value = action;
    error.value = '';

    if (here.value) {
        step.value = 'capture';
        return;
    }

    await startQr();
}

// ── Con el teléfono: el QR ───────────────────────────────────────────────
async function startQr() {
    stopPolling();
    loading.value = true;
    error.value = '';

    const result = await requestJson(window.route('conti.scan.start'), {
        method: 'POST',
        body: { accion: chosen.value.accion, sesion: conti.sessionId },
    });

    loading.value = false;

    if (!result.ok) {
        error.value = result.data?.message ?? result.message;
        return;
    }

    session.value = result.data;
    const qr = qrcode(0, 'M');
    qr.addData(result.data.url);
    qr.make();
    // SVG armado por la librería a partir de nuestra propia dirección.
    qrSvg.value = qr.createSvgTag({ cellSize: 5, margin: 2, scalable: true });
    phoneState.value = 'esperando';
    phoneMessage.value = '';
    secondsLeft.value = result.data.minutos * 60;
    step.value = 'qr';

    clockTimer = setInterval(() => {
        secondsLeft.value = Math.max(0, secondsLeft.value - 1);
        if (secondsLeft.value === 0) {
            stopPolling();
            phoneState.value = 'vencido';
        }
    }, 1000);
    poll();
}

async function poll() {
    const id = session.value?.id;
    if (!id) return;

    const result = await requestJson(window.route('conti.scan.status', id));
    if (session.value?.id !== id || !scanner.open) return;

    if (result.ok) {
        phoneState.value = result.data.estado;
        phoneMessage.value = result.data.mensaje ?? '';

        if (result.data.estado === 'listo') {
            deliver(result.data.formulario);
            return;
        }

        if (['error', 'vencido'].includes(result.data.estado)) {
            stopPolling();
            return;
        }
    }

    pollTimer = setTimeout(poll, POLL_MS);
}

function stopPolling() {
    clearTimeout(pollTimer);
    clearInterval(clockTimer);
    pollTimer = null;
    clockTimer = null;
}

// ── Ahí mismo: la cámara (o una imagen, en la computadora) ───────────────
function pickPhoto() {
    camera.value?.click();
}

async function onPhoto(event) {
    const file = event.target.files?.[0];
    event.target.value = '';
    if (!file) return;

    try {
        const blob = await shrinkPhoto(file);
        photos.value.push({ blob, preview: URL.createObjectURL(blob) });
        error.value = '';
    } catch (e) {
        error.value = e.message;
    }
}

function removePhoto(index) {
    URL.revokeObjectURL(photos.value[index].preview);
    photos.value.splice(index, 1);
}

async function readHere() {
    if (!photos.value.length) return;

    const body = new FormData();
    body.append('accion', chosen.value.accion);
    if (conti.sessionId) body.append('sesion', conti.sessionId);
    photos.value.forEach((photo, i) => body.append('fotos[]', photo.blob, `pagina-${i + 1}.jpg`));

    step.value = 'reading';
    error.value = '';
    const result = await requestJson(window.route('conti.scan.read'), { method: 'POST', body });

    if (!scanner.open) return;

    if (result.ok) {
        deliver(result.data.formulario);
        return;
    }

    step.value = 'capture';
    error.value = result.status === 422
        ? (Object.values(result.data?.errors ?? {})[0]?.[0] ?? result.message)
        : (result.data?.message ?? result.message);
}

// El formulario, con lo leído, al chat.
function deliver(formulario) {
    stopPolling();
    closeScanner();
    pushForm(formulario);
}

onBeforeUnmount(stopPolling);
</script>

<template>
    <DetailModal :open="scanner.open" :title="title" @close="close">
        <div class="scan">
            <!-- 1. Qué registrar -->
            <template v-if="step === 'choose'">
                <p class="scan-intro">
                    Conti lee el documento y te deja el formulario listo para revisar. Nada se guarda sin tu confirmación, y las fotos no se guardan.
                </p>
                <p v-if="loading" class="muted">Cargando…</p>
                <p v-else-if="actions && !actions.length" class="scan-empty">
                    Con tus permisos no podés registrar nada desde un documento. Si lo necesitás, pedíselo al Superusuario o a un Administrador.
                </p>
                <div v-else-if="actions" class="scan-options">
                    <p class="scan-question">¿Qué querés registrar?</p>
                    <button v-for="action in actions" :key="action.accion" type="button" class="scan-option" @click="choose(action)">
                        <strong>{{ action.titulo }}</strong>
                        <span>{{ action.descripcion }}</span>
                    </button>
                </div>
            </template>

            <!-- 2a. Con el teléfono: el QR -->
            <template v-else-if="step === 'qr'">
                <div class="qr-box">
                    <!-- eslint-disable-next-line vue/no-v-html -- SVG de qrcode-generator, de nuestra propia dirección -->
                    <div class="qr" :class="{ 'is-faded': ['vencido', 'error'].includes(phoneState) }" v-html="qrSvg" />
                    <div class="qr-text">
                        <p><strong>Escaneá este código con la cámara del teléfono.</strong></p>
                        <p class="muted">Se abre una página para fotografiar el documento. Sirve una sola vez y para este escaneo.</p>
                        <p class="qr-state" :class="phoneState" role="status">
                            <template v-if="phoneState === 'esperando'">
                                <span class="dot" /> Esperando las fotos del teléfono… (vence en {{ clock }})
                            </template>
                            <template v-else-if="phoneState === 'leyendo'"><span class="spinner" /> Conti está leyendo el documento…</template>
                            <template v-else-if="phoneState === 'vencido'">El código venció.</template>
                            <template v-else>No se pudo leer el documento.</template>
                        </p>
                        <p v-if="phoneMessage" class="scan-error">{{ phoneMessage }}</p>
                        <button v-if="['vencido', 'error'].includes(phoneState)" type="button" class="btn btn-primary btn-sm" @click="startQr">Generar otro código</button>
                    </div>
                </div>
                <button type="button" class="btn btn-ghost btn-sm scan-alt" @click="step = 'capture'; stopPolling()">
                    <ImageUpIcon /> ¿Tenés la imagen en la computadora? Subila
                </button>
            </template>

            <!-- 2b. Ahí mismo: fotos -->
            <template v-else-if="step === 'capture' || step === 'reading'">
                <p class="scan-intro">
                    {{ here ? 'Tomá una foto del documento de frente, completo y con buena luz.' : 'Elegí la imagen del documento.' }}
                    Si tiene varias páginas, una foto por página (hasta {{ MAX_PHOTOS }}).
                </p>

                <div v-if="photos.length" class="photos">
                    <div v-for="(photo, i) in photos" :key="photo.preview" class="photo">
                        <img :src="photo.preview" :alt="`Página ${i + 1}`">
                        <button type="button" :aria-label="`Quitar la página ${i + 1}`" :disabled="step === 'reading'" @click="removePhoto(i)"><XIcon :size="14" /></button>
                    </div>
                </div>

                <input ref="camera" type="file" accept="image/*" :capture="here ? 'environment' : null" class="sr-only" tabindex="-1" @change="onPhoto">

                <div class="capture-actions">
                    <button type="button" class="btn btn-ghost" :disabled="photos.length >= MAX_PHOTOS || step === 'reading'" @click="pickPhoto">
                        <CameraIcon v-if="here" /><ImageUpIcon v-else />
                        {{ photos.length ? 'Agregar otra página' : (here ? 'Tomar foto' : 'Elegir imagen') }}
                    </button>
                    <button
                        type="button"
                        class="btn btn-primary"
                        :disabled="!photos.length || step === 'reading'"
                        :data-busy="step === 'reading' ? '' : null"
                        @click="readHere"
                    ><ScanLineIcon /> Leer documento</button>
                </div>
                <p v-if="step === 'reading'" class="muted" role="status">Conti está leyendo el documento…</p>
            </template>

            <p v-if="error" class="scan-error" role="alert">{{ error }}</p>
        </div>

        <template v-if="step !== 'choose'" #actions>
            <button type="button" class="btn btn-ghost" :disabled="step === 'reading'" @click="stopPolling(); step = 'choose'; chosen = null">
                <ChevronLeftIcon /> Elegir otra cosa
            </button>
        </template>
    </DetailModal>
</template>

<style scoped>
.scan {
    display: flex;
    flex-direction: column;
    gap: 0.85rem;
}

.scan-intro,
.scan-question,
.scan-empty {
    margin: 0;
    font-size: 0.86rem;
    line-height: 1.5;
}

.scan-question {
    font-weight: 600;
}

.scan-options {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.scan-option {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 0.15rem;
    width: 100%;
    padding: 0.7rem 0.85rem;
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    background: var(--color-surface);
    font: inherit;
    color: var(--color-text);
    text-align: left;
    cursor: pointer;
}

.scan-option:hover,
.scan-option:focus-visible {
    border-color: var(--color-primary);
    background: var(--color-primary-soft);
}

.scan-option strong {
    font-size: 0.9rem;
}

.scan-option span {
    font-size: 0.78rem;
    color: var(--color-text-muted);
}

.qr-box {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 1rem 1.25rem;
}

/* El QR siempre negro sobre blanco: así lo leen todas las cámaras, también en oscuro. */
.qr {
    flex-shrink: 0;
    width: 13rem;
    height: 13rem;
    padding: 0.4rem;
    border-radius: var(--radius-md);
    background: #fff;
}

.qr :deep(svg) {
    width: 100%;
    height: 100%;
    display: block;
}

.qr.is-faded {
    opacity: 0.25;
}

.qr-text {
    flex: 1;
    min-width: 13rem;
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 0.45rem;
    font-size: 0.86rem;
}

.qr-text p {
    margin: 0;
}

.qr-state {
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    font-size: 0.82rem;
    color: var(--color-text-muted);
}

.qr-state.leyendo {
    color: var(--color-text);
    font-weight: 600;
}

.dot {
    width: 0.55rem;
    height: 0.55rem;
    border-radius: 50%;
    background: var(--color-primary);
    animation: scan-blink 1.4s ease-in-out infinite;
}

.spinner {
    width: 0.9rem;
    height: 0.9rem;
    border: 2px solid currentColor;
    border-right-color: transparent;
    border-radius: 50%;
    animation: scan-spin 0.8s linear infinite;
}

@keyframes scan-blink { 50% { opacity: 0.25; } }
@keyframes scan-spin { to { transform: rotate(360deg); } }

@media (prefers-reduced-motion: reduce) {
    .dot,
    .spinner {
        animation: none;
    }
}

.scan-alt {
    align-self: flex-start;
}

.photos {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 7rem));
    gap: 0.5rem;
}

.photo {
    position: relative;
    aspect-ratio: 3 / 4;
    overflow: hidden;
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    background: var(--color-surface-alt);
}

.photo img {
    display: block;
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.photo button {
    position: absolute;
    top: 0.25rem;
    right: 0.25rem;
    display: inline-grid;
    place-items: center;
    width: 1.6rem;
    height: 1.6rem;
    padding: 0;
    border: 0;
    border-radius: 50%;
    background: rgba(0, 0, 0, 0.6);
    color: #fff;
    cursor: pointer;
}

.capture-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
}

.capture-actions .btn {
    flex: 1 1 12rem;
    justify-content: center;
}

.scan-error {
    margin: 0;
    font-size: 0.8rem;
    color: var(--color-danger);
}
</style>
