<script setup>
import { computed, onBeforeUnmount, ref, useId } from 'vue';
import { ImagePlusIcon, SendIcon, XIcon } from '@lucide/vue';
import { markBusy } from '../../Utils/busyButtons';
import { requestJson } from '../../Utils/http';
import { notifyError } from '../../Utils/notify';

/**
 * Escribir en el canal de comentarios: una publicación nueva o un comentario
 * a una. El texto y hasta dos imágenes, que se ven en miniatura antes de
 * mandar. Lo publica él mismo (JSON, ver requestJson) y avisa con 'created'.
 *
 * El botón queda ocupado mientras sube (CLAUDE.md secc. 27). Un error de un
 * campo se dice debajo del texto; cualquier otro, en un aviso.
 */
const props = defineProps({
    url: { type: String, required: true },
    label: { type: String, required: true },
    placeholder: { type: String, default: '' },
    submitLabel: { type: String, default: 'Publicar' },
    limits: { type: Object, required: true },
    // Más bajo, para comentar dentro de una publicación.
    compact: { type: Boolean, default: false },
});

const emit = defineEmits(['created']);

const id = useId();
const body = ref('');
const files = ref([]); // { file, preview }
const error = ref('');
const sending = ref(false);
const fileInput = ref(null);
const submitButton = ref(null);

const canAttach = computed(() => files.value.length < props.limits.max_images);
const canSend = computed(() => body.value.trim() !== '' && !sending.value);

function pick(event) {
    error.value = '';
    const picked = [...(event.target.files ?? [])];
    event.target.value = '';

    const accepted = props.limits.accept.split(',');
    const maxBytes = props.limits.max_megabytes * 1024 * 1024;

    for (const file of picked) {
        if (files.value.length >= props.limits.max_images) {
            error.value = `Se pueden adjuntar hasta ${props.limits.max_images} imágenes.`;
            break;
        }
        if (!accepted.includes(file.type)) {
            error.value = `Solo se pueden adjuntar imágenes en ${props.limits.formats}.`;
            continue;
        }
        if (file.size > maxBytes) {
            error.value = `Cada imagen puede pesar hasta ${props.limits.max_megabytes} MB.`;
            continue;
        }

        files.value.push({ file, preview: URL.createObjectURL(file) });
    }
}

function removeFile(index) {
    URL.revokeObjectURL(files.value[index].preview);
    files.value.splice(index, 1);
    error.value = '';
}

function reset() {
    body.value = '';
    files.value.forEach((entry) => URL.revokeObjectURL(entry.preview));
    files.value = [];
    error.value = '';
}

async function submit() {
    if (!canSend.value) return;

    const data = new FormData();
    data.append('body', body.value);
    files.value.forEach((entry) => data.append('images[]', entry.file));

    sending.value = true;
    error.value = '';
    const release = submitButton.value ? markBusy(submitButton.value) : () => {};

    const result = await requestJson(props.url, { method: 'POST', body: data });

    release();
    sending.value = false;

    if (result.ok) {
        emit('created', result.data);
        reset();
        return;
    }

    if (result.status === 422 && (result.errors.body || result.errors.images)) {
        error.value = result.errors.body ?? result.errors.images;
        return;
    }

    notifyError(result.message);
}

// Ctrl+Enter (o Cmd+Enter) también manda, como en cualquier chat.
function onKeydown(event) {
    if (event.key === 'Enter' && (event.ctrlKey || event.metaKey)) {
        event.preventDefault();
        submit();
    }
}

onBeforeUnmount(() => files.value.forEach((entry) => URL.revokeObjectURL(entry.preview)));
</script>

<template>
    <form class="feedback-composer" :class="{ compact }" @submit.prevent="submit">
        <label :for="id" class="sr-only">{{ label }}</label>
        <textarea
            :id="id"
            v-model="body"
            :rows="compact ? 2 : 3"
            :maxlength="limits.max_body"
            :placeholder="placeholder"
            :aria-describedby="error ? `${id}-error` : null"
            :aria-invalid="error ? 'true' : null"
            @input="error = ''"
            @keydown="onKeydown"
        />

        <ul v-if="files.length" class="composer-previews">
            <li v-for="(entry, index) in files" :key="entry.preview">
                <img :src="entry.preview" alt="">
                <button type="button" class="composer-remove" :aria-label="`Quitar la imagen ${index + 1}`" title="Quitar" @click="removeFile(index)">
                    <XIcon :size="14" />
                </button>
            </li>
        </ul>

        <span v-if="error" :id="`${id}-error`" class="error" role="alert">{{ error }}</span>

        <div class="composer-actions">
            <button
                type="button"
                class="btn btn-ghost composer-attach"
                :disabled="!canAttach || sending"
                :title="`Adjuntar imágenes (${limits.formats}, hasta ${limits.max_images})`"
                @click="fileInput?.click()"
            >
                <ImagePlusIcon /> Imagen
                <span class="composer-count">{{ files.length }}/{{ limits.max_images }}</span>
            </button>
            <input
                ref="fileInput"
                type="file"
                class="sr-only"
                tabindex="-1"
                aria-hidden="true"
                :accept="limits.accept"
                multiple
                @change="pick"
            >

            <button ref="submitButton" type="submit" class="btn btn-primary" :disabled="!canSend">
                <SendIcon /> {{ submitLabel }}
            </button>
        </div>
    </form>
</template>

<style scoped>
.feedback-composer {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.feedback-composer textarea {
    width: 100%;
    resize: vertical;
}

.composer-previews {
    display: flex;
    gap: 0.5rem;
    margin: 0;
    padding: 0;
    list-style: none;
}

.composer-previews li {
    position: relative;
    width: 4.5rem;
    height: 4.5rem;
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    overflow: hidden;
    background: var(--color-surface-alt);
}

.composer-previews img {
    display: block;
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.composer-remove {
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

.composer-actions {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.5rem;
}

.composer-attach {
    color: var(--color-text-muted);
}

.composer-count {
    font-size: 0.72rem;
    font-weight: 600;
    color: var(--color-text-muted);
}

.compact .btn {
    min-height: 2rem;
    padding: 0.3rem 0.7rem;
    font-size: 0.8rem;
}
</style>
