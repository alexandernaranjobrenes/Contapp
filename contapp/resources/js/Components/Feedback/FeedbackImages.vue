<script setup>
import { nextTick, onBeforeUnmount, ref } from 'vue';
import { ExternalLinkIcon, XIcon } from '@lucide/vue';

/**
 * Las imágenes de un comentario (hasta dos), en miniatura. Tocar una la abre
 * grande, encima de todo; Escape, el fondo o la X la cierran. Se usa en el
 * panel de la barra superior y en el backoffice.
 */
defineProps({
    images: { type: Array, default: () => [] },
});

const viewing = ref(null);
const closeButton = ref(null);
let returnFocusTo = null;

async function open(url, event) {
    returnFocusTo = event.currentTarget;
    viewing.value = url;
    window.addEventListener('keydown', onKeydown, true);
    await nextTick();
    closeButton.value?.focus();
}

function close() {
    viewing.value = null;
    window.removeEventListener('keydown', onKeydown, true);
    returnFocusTo?.focus?.();
    returnFocusTo = null;
}

// En la fase de captura y sin dejarlo seguir: Escape cierra la imagen, no el
// panel ni el modal que está debajo.
function onKeydown(event) {
    if (event.key === 'Escape') {
        event.stopPropagation();
        event.preventDefault();
        close();
    }
}

onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown, true));
</script>

<template>
    <div v-if="images.length" class="feedback-images" :class="{ single: images.length === 1 }">
        <button
            v-for="(url, index) in images"
            :key="url"
            type="button"
            class="feedback-thumb"
            :aria-label="`Ver la imagen ${index + 1} en grande`"
            @click="open(url, $event)"
        >
            <img :src="url" alt="" loading="lazy">
        </button>
    </div>

    <Teleport to="body">
        <div v-if="viewing" class="image-lightbox" role="dialog" aria-modal="true" aria-label="Imagen adjunta" @click.self="close">
            <img :src="viewing" alt="Imagen adjunta" @click.self="close">
            <div class="image-lightbox-actions">
                <a :href="viewing" target="_blank" rel="noopener noreferrer" class="btn btn-ghost lightbox-btn">
                    <ExternalLinkIcon /> Abrir en otra pestaña
                </a>
                <button ref="closeButton" type="button" class="btn btn-ghost lightbox-btn" aria-label="Cerrar imagen" @click="close">
                    <XIcon /> Cerrar
                </button>
            </div>
        </div>
    </Teleport>
</template>

<style scoped>
/* Miniaturas: en el panel ocupan su ancho; en la ficha del backoffice, que
   es más ancha, no pasan de ~540px (la imagen completa se ve al tocarla). */
.feedback-images {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.4rem;
    max-width: 34rem;
    margin-top: 0.55rem;
}

.feedback-images.single {
    grid-template-columns: minmax(0, 1fr);
}

.feedback-thumb {
    display: block;
    padding: 0;
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    overflow: hidden;
    background: var(--color-surface-alt);
    cursor: zoom-in;
    aspect-ratio: 16 / 10;
}

.single .feedback-thumb {
    aspect-ratio: 16 / 9;
}

.feedback-thumb img {
    display: block;
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.feedback-thumb:hover img {
    opacity: .92;
}

.image-lightbox {
    position: fixed;
    inset: 0;
    z-index: 70;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 0.75rem;
    padding: 1rem;
    background: rgba(0, 0, 0, 0.82);
}

.image-lightbox img {
    max-width: 100%;
    max-height: calc(100dvh - 6rem);
    border-radius: var(--radius-sm);
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.4);
    background: var(--color-surface);
}

.image-lightbox-actions {
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    gap: 0.5rem;
}

/* Sobre el fondo oscuro, en los dos temas. */
.lightbox-btn {
    background: rgba(255, 255, 255, 0.12);
    border-color: rgba(255, 255, 255, 0.28);
    color: var(--color-on-sidebar);
}

.lightbox-btn:hover {
    background: rgba(255, 255, 255, 0.2);
}
</style>
