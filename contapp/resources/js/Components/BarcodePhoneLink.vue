<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import qrcode from 'qrcode-generator';
import { MonitorIcon, XIcon } from '@lucide/vue';
import { requestJson } from '../Utils/http';

/**
 * Escanear el código de barras con el teléfono, desde la computadora: igual
 * que el escaneo de documentos de Conti (CLAUDE.md secc. 33).
 *
 * - Un QR con un pase de un solo uso (BarcodeScanService). La página que abre
 *   en el teléfono lee el código ahí mismo y manda solo el texto.
 * - Este panel va preguntando hasta que llega, y lo emite («detected»).
 * - «Usar esta computadora» («use-here»): con su cámara o con una foto.
 *
 * Va dentro del formulario del artículo, no en otro modal: dos modales se
 * pelearían el Escape y el foco.
 */
const props = defineProps({
    // Qué se escanea, para que el teléfono lo diga: el nombre del artículo.
    label: { type: String, default: '' },
});

const emit = defineEmits(['detected', 'use-here', 'close']);

const POLL_MS = 2500;

const session = ref(null); // { id, url, minutos }
const qrSvg = ref('');
const state = ref('cargando'); // cargando | esperando | vencido | error
const error = ref('');
const secondsLeft = ref(0);
let pollTimer = null;
let clockTimer = null;
let stopped = false;

const clock = computed(() => `${Math.floor(secondsLeft.value / 60)}:${String(secondsLeft.value % 60).padStart(2, '0')}`);

async function start() {
    stopPolling();
    state.value = 'cargando';
    error.value = '';

    const result = await requestJson(window.route('items.barcode-scans.start'), {
        method: 'POST',
        body: { para: props.label.trim() || null },
    });
    if (stopped) return;

    if (!result.ok) {
        state.value = 'error';
        error.value = result.data?.message ?? result.message;
        return;
    }

    session.value = result.data;
    const qr = qrcode(0, 'M');
    qr.addData(result.data.url);
    qr.make();
    // SVG armado por la librería a partir de nuestra propia dirección.
    qrSvg.value = qr.createSvgTag({ cellSize: 5, margin: 2, scalable: true });
    state.value = 'esperando';
    secondsLeft.value = result.data.minutos * 60;

    clockTimer = setInterval(() => {
        secondsLeft.value = Math.max(0, secondsLeft.value - 1);
        if (secondsLeft.value === 0) {
            stopPolling();
            state.value = 'vencido';
        }
    }, 1000);
    pollTimer = setTimeout(poll, POLL_MS);
}

async function poll() {
    const id = session.value?.id;
    if (!id || stopped) return;

    const result = await requestJson(window.route('items.barcode-scans.status', id));
    if (stopped || session.value?.id !== id) return;

    if (result.ok && result.data.estado === 'listo') {
        stopPolling();
        emit('detected', result.data.codigo);
        return;
    }

    if (result.ok && result.data.estado === 'vencido') {
        stopPolling();
        state.value = 'vencido';
        return;
    }

    pollTimer = setTimeout(poll, POLL_MS);
}

function stopPolling() {
    clearTimeout(pollTimer);
    clearInterval(clockTimer);
    pollTimer = null;
    clockTimer = null;
}

onMounted(start);
onBeforeUnmount(() => {
    stopped = true;
    stopPolling();
});
</script>

<template>
    <div class="phone-link">
        <div class="qr-box">
            <!-- eslint-disable-next-line vue/no-v-html -- SVG de qrcode-generator, de nuestra propia dirección -->
            <div class="qr" :class="{ 'is-faded': state !== 'esperando' }" v-html="qrSvg" />
            <div class="qr-text">
                <p><strong>Escaneá este código con la cámara del teléfono.</strong></p>
                <p class="muted">Se abre una página que lee el código de barras con el teléfono y lo manda acá. Sirve una sola vez.</p>
                <p class="qr-state" role="status">
                    <template v-if="state === 'cargando'"><span class="spinner" /> Generando el código…</template>
                    <template v-else-if="state === 'esperando'"><span class="dot" /> Esperando el código del teléfono… (vence en {{ clock }})</template>
                    <template v-else-if="state === 'vencido'">El código venció.</template>
                </p>
                <p v-if="error" class="qr-error" role="alert">{{ error }}</p>
                <button v-if="['vencido', 'error'].includes(state)" type="button" class="btn btn-primary btn-sm" @click="start">Generar otro código</button>
            </div>
        </div>
        <div class="phone-actions">
            <button type="button" class="btn btn-ghost btn-sm" @click="emit('use-here')"><MonitorIcon /> Usar esta computadora</button>
            <button type="button" class="btn btn-ghost btn-sm" @click="emit('close')"><XIcon /> Cerrar</button>
        </div>
    </div>
</template>

<style scoped>
.phone-link {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
    padding: 0.75rem;
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    background: var(--color-surface-alt);
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
    width: 11rem;
    height: 11rem;
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

.qr-error {
    font-size: 0.8rem;
    color: var(--color-danger);
}

.phone-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
}

.dot {
    width: 0.55rem;
    height: 0.55rem;
    border-radius: 50%;
    background: var(--color-primary);
    animation: link-blink 1.4s ease-in-out infinite;
}

.spinner {
    width: 0.9rem;
    height: 0.9rem;
    border: 2px solid currentColor;
    border-right-color: transparent;
    border-radius: 50%;
    animation: link-spin 0.8s linear infinite;
}

@keyframes link-blink { 50% { opacity: 0.25; } }
@keyframes link-spin { to { transform: rotate(360deg); } }

@media (prefers-reduced-motion: reduce) {
    .dot, .spinner { animation: none; }
}
</style>
