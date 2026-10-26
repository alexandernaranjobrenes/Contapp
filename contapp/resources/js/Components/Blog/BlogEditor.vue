<script setup>
import { EditorContent, useEditor } from '@tiptap/vue-3';
import StarterKit from '@tiptap/starter-kit';
import Highlight from '@tiptap/extension-highlight';
import { Table, TableCell, TableHeader, TableRow } from '@tiptap/extension-table';
import { CharacterCount, Placeholder } from '@tiptap/extensions';
import {
    AlignCenterIcon, AlignJustifyIcon, AlignLeftIcon, AlignRightIcon, BetweenHorizontalEndIcon, BetweenHorizontalStartIcon,
    BetweenVerticalEndIcon, BetweenVerticalStartIcon, BoldIcon, CheckIcon, CodeIcon, HighlighterIcon, ImagePlusIcon,
    ItalicIcon, Link2OffIcon, LinkIcon, ListIcon, ListOrderedIcon, MinusIcon, PanelTopIcon, QuoteIcon, Redo2Icon,
    RemoveFormattingIcon, StrikethroughIcon, TableIcon, Trash2Icon, UnderlineIcon, Undo2Icon, XIcon,
} from '@lucide/vue';
import { computed, nextTick, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { markBusy } from '../../Utils/busyButtons';
import { notifyError } from '../../Utils/notify';
import { BlogImage } from './blogImage';
import { TextAlign } from './textAlign';
import { uploadBlogImage } from './uploadImage';

/**
 * El editor de los artículos del blog (CLAUDE.md secc. 41): como escribir en
 * Word —títulos, negrita, listas, tablas, enlaces, imágenes—, con TipTap.
 *
 * - TipTap no trae interfaz: la barra es nuestra, con los íconos de Lucide
 *   (secc. 23).
 * - Lo que se pega de Word o de Google Docs conserva títulos, negritas,
 *   listas, tablas y enlaces; el resto (colores, tipografías, estilos de
 *   Word) se cae, porque el editor solo conoce lo que BlogHtml acepta.
 * - Las imágenes se suben al bucket al elegirlas, pegarlas o arrastrarlas
 *   (blog/imag_{id}.{ext}). Una imagen copiada de otra página no se pega: no
 *   es nuestra y podría desaparecer.
 *
 * Emite el HTML con v-model y, en `stats`, lo que mira la lista de chequeo
 * de SEO (palabras, subtítulos, imágenes sin texto alternativo…).
 */
const props = defineProps({
    modelValue: { type: String, default: '' },
    imageRules: { type: Object, required: true },
    // El dominio del sitio: un enlace a él cuenta como interno para el SEO.
    siteHost: { type: String, default: '' },
    invalid: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue', 'stats']);

let lastEmitted = props.modelValue;

const editor = useEditor({
    content: props.modelValue || '',
    extensions: [
        StarterKit.configure({
            // El título del artículo es el h1: el texto empieza en h2.
            heading: { levels: [2, 3, 4] },
            codeBlock: false,
            link: {
                openOnClick: false,
                autolink: true,
                defaultProtocol: 'https',
                HTMLAttributes: { rel: null, target: null, class: null },
            },
        }),
        Highlight,
        TextAlign,
        BlogImage,
        Table.configure({ resizable: false }),
        TableRow,
        TableHeader,
        TableCell,
        Placeholder.configure({ placeholder: 'Escribí el artículo… Podés pegar desde Word o Google Docs.' }),
        CharacterCount,
    ],
    editorProps: {
        attributes: { class: 'blog-prose', 'aria-label': 'Texto del artículo', 'aria-multiline': 'true', role: 'textbox' },
        handlePaste: (view, event) => onPaste(event),
        handleDrop: (view, event, slice, moved) => onDrop(view, event, moved),
    },
    onCreate: () => emitStats(),
    onUpdate: ({ editor: instance }) => {
        lastEmitted = instance.getHTML();
        emit('update:modelValue', lastEmitted);
        emitStats();
    },
});

watch(() => props.modelValue, (value) => {
    if (!editor.value || value === lastEmitted) return;

    lastEmitted = value;
    editor.value.commands.setContent(value || '', { emitUpdate: false });
    emitStats();
});

onBeforeUnmount(() => editor.value?.destroy());

// ── Lo que mira la lista de chequeo de SEO ────────────────────────────────

function isInternal(href) {
    if (!href) return false;
    if (href.startsWith('/') || href.startsWith('#')) return true;

    try {
        return props.siteHost !== '' && new URL(href).host === props.siteHost;
    } catch {
        return false;
    }
}

function emitStats() {
    const instance = editor.value;
    if (!instance) return;

    const stats = { words: instance.storage.characterCount.words(), h2: 0, images: 0, missingAlt: 0, internalLinks: 0, firstParagraph: '' };

    instance.state.doc.descendants((node) => {
        if (node.type.name === 'heading' && node.attrs.level === 2) stats.h2 += 1;

        if (node.type.name === 'blogImage') {
            stats.images += 1;
            if (!node.attrs.alt?.trim()) stats.missingAlt += 1;
        }

        if (node.isText && node.marks.some((mark) => mark.type.name === 'link' && isInternal(mark.attrs.href))) {
            stats.internalLinks += 1;
        }

        if (!stats.firstParagraph && node.type.name === 'paragraph' && node.textContent.trim()) {
            stats.firstParagraph = node.textContent;
        }
    });

    emit('stats', stats);
}

// ── Formato ──────────────────────────────────────────────────────────────

const blockType = computed(() => {
    const instance = editor.value;
    if (!instance) return 'paragraph';

    for (const level of [2, 3, 4]) {
        if (instance.isActive('heading', { level })) return `h${level}`;
    }

    return 'paragraph';
});

function setBlock(value) {
    const chain = editor.value.chain().focus();

    if (value === 'paragraph') chain.setParagraph().run();
    else chain.setHeading({ level: Number(value.slice(1)) }).run();
}

const alignment = computed(() => {
    const instance = editor.value;
    if (!instance) return 'left';

    return ['center', 'right', 'justify'].find((value) => instance.isActive({ textAlign: value })) ?? 'left';
});

function align(value) {
    editor.value.chain().focus().setTextAlign(value).run();
}

const marks = [
    { name: 'bold', label: 'Negrita (Ctrl+B)', icon: BoldIcon, run: (chain) => chain.toggleBold() },
    { name: 'italic', label: 'Cursiva (Ctrl+I)', icon: ItalicIcon, run: (chain) => chain.toggleItalic() },
    { name: 'underline', label: 'Subrayado (Ctrl+U)', icon: UnderlineIcon, run: (chain) => chain.toggleUnderline() },
    { name: 'strike', label: 'Tachado', icon: StrikethroughIcon, run: (chain) => chain.toggleStrike() },
    { name: 'highlight', label: 'Resaltar', icon: HighlighterIcon, run: (chain) => chain.toggleHighlight() },
    { name: 'code', label: 'Código', icon: CodeIcon, run: (chain) => chain.toggleCode() },
];

const blocks = [
    { name: 'bulletList', label: 'Lista con viñetas', icon: ListIcon, run: (chain) => chain.toggleBulletList() },
    { name: 'orderedList', label: 'Lista numerada', icon: ListOrderedIcon, run: (chain) => chain.toggleOrderedList() },
    { name: 'blockquote', label: 'Cita', icon: QuoteIcon, run: (chain) => chain.toggleBlockquote() },
];

const alignments = [
    { value: 'left', label: 'Alinear a la izquierda', icon: AlignLeftIcon },
    { value: 'center', label: 'Centrar', icon: AlignCenterIcon },
    { value: 'right', label: 'Alinear a la derecha', icon: AlignRightIcon },
    { value: 'justify', label: 'Justificar', icon: AlignJustifyIcon },
];

function run(action) {
    action(editor.value.chain().focus()).run();
}

// ── Enlaces ──────────────────────────────────────────────────────────────

const linkPanel = ref(false);
const linkForm = reactive({ href: '', newTab: false });
const linkInput = ref(null);

function openLink() {
    const attributes = editor.value.getAttributes('link');
    linkForm.href = attributes.href ?? '';
    linkForm.newTab = attributes.target === '_blank';
    linkPanel.value = true;
    nextTick(() => linkInput.value?.focus());
}

function applyLink() {
    const href = linkForm.href.trim();
    const chain = editor.value.chain().focus().extendMarkRange('link');

    if (href === '') {
        chain.unsetLink().run();
        linkPanel.value = false;
        return;
    }

    const url = /^(https?:|mailto:|tel:|\/|#)/i.test(href) ? href : `https://${href}`;
    const attributes = { href: url, target: linkForm.newTab ? '_blank' : null };

    // Sin texto elegido, el enlace se escribe con su propia dirección.
    if (editor.value.state.selection.empty && !editor.value.isActive('link')) {
        chain.insertContent({ type: 'text', text: url, marks: [{ type: 'link', attrs: attributes }] }).run();
    } else {
        chain.setLink(attributes).run();
    }

    linkPanel.value = false;
}

function removeLink() {
    editor.value.chain().focus().extendMarkRange('link').unsetLink().run();
    linkPanel.value = false;
}

// ── Imágenes ─────────────────────────────────────────────────────────────

const fileInput = ref(null);
const altInput = ref(null);
const uploading = ref(0);

const selectedImage = computed(() => (editor.value?.isActive('blogImage') ? editor.value.getAttributes('blogImage') : null));

function selectImage(id) {
    let found = null;

    editor.value.state.doc.descendants((node, position) => {
        if (node.type.name === 'blogImage' && node.attrs.id === id) found = position;
    });

    if (found !== null) {
        editor.value.commands.setNodeSelection(found);
        nextTick(() => altInput.value?.focus());
    }
}

async function upload(files, position = null, button = null) {
    const images = [...files].filter((file) => file.type.startsWith('image/'));
    if (!images.length) return;

    const release = button ? markBusy(button) : () => {};
    uploading.value += images.length;
    let last = null;

    for (const file of images) {
        const result = await uploadBlogImage(file, props.imageRules);
        uploading.value -= 1;

        if (!result.ok) {
            notifyError(result.message);
            continue;
        }

        const attributes = { id: String(result.image.id), src: result.image.url, alt: '', caption: '', size: 'wide' };

        if (position !== null) editor.value.chain().insertContentAt(position, { type: 'blogImage', attrs: attributes }).run();
        else editor.value.chain().focus().insertBlogImage(attributes).run();

        last = attributes.id;
    }

    release();

    // Recién puesta, se elige: la fila de la imagen pide su texto alternativo.
    if (last !== null) selectImage(last);
}

function pickImages(event) {
    const files = event.target.files;
    upload(files, null, event.target.closest('.blog-editor')?.querySelector('[data-image-button]'));
    event.target.value = '';
}

// Las propiedades de la imagen elegida se cambian sin sacar el foco del
// campo: la selección del editor sigue en la imagen.
function updateImage(attributes) {
    editor.value.commands.updateAttributes('blogImage', attributes);
    emitStats();
}

function removeImage() {
    editor.value.chain().focus().deleteSelection().run();
}

const sizes = [
    { value: 'wide', label: 'A lo ancho' },
    { value: 'medium', label: 'Mediana' },
    { value: 'small', label: 'Chica' },
];

/**
 * Pegar: una imagen sola (una captura) se sube. Lo que viene de Word trae,
 * además del texto, una foto de lo copiado: si hay texto, se pega el texto y
 * la foto no. Una <img> de otra página no entra al editor (no tiene id).
 */
function onPaste(event) {
    const data = event.clipboardData;
    if (!data) return false;

    const html = data.getData('text/html');
    const text = data.getData('text/plain');
    const files = [...data.files].filter((file) => file.type.startsWith('image/'));

    if (files.length && !html && !text) {
        event.preventDefault();
        upload(files);
        return true;
    }

    if (/<img[\s>]/i.test(html) && !/data-image-id/i.test(html)) {
        notifyError('Las imágenes copiadas de otra página o de Word no se pegan: subilas con el botón «Imagen».');
    }

    return false;
}

function onDrop(view, event, moved) {
    if (moved) return false;

    const files = [...(event.dataTransfer?.files ?? [])].filter((file) => file.type.startsWith('image/'));
    if (!files.length) return false;

    event.preventDefault();
    upload(files, view.posAtCoords({ left: event.clientX, top: event.clientY })?.pos ?? null);

    return true;
}

// ── Tablas ───────────────────────────────────────────────────────────────

const inTable = computed(() => editor.value?.isActive('table') ?? false);

const tableActions = [
    { label: 'Fila arriba', icon: BetweenHorizontalStartIcon, run: (chain) => chain.addRowBefore() },
    { label: 'Fila abajo', icon: BetweenHorizontalEndIcon, run: (chain) => chain.addRowAfter() },
    { label: 'Columna a la izquierda', icon: BetweenVerticalStartIcon, run: (chain) => chain.addColumnBefore() },
    { label: 'Columna a la derecha', icon: BetweenVerticalEndIcon, run: (chain) => chain.addColumnAfter() },
    { label: 'Fila de encabezado', icon: PanelTopIcon, run: (chain) => chain.toggleHeaderRow() },
    { label: 'Quitar fila', icon: XIcon, run: (chain) => chain.deleteRow() },
    { label: 'Quitar columna', icon: XIcon, run: (chain) => chain.deleteColumn() },
];

function insertTable() {
    editor.value.chain().focus().insertTable({ rows: 3, cols: 3, withHeaderRow: true }).run();
}
</script>

<template>
    <div class="blog-editor" :class="{ 'is-invalid': invalid }">
        <div v-if="editor" class="blog-toolbar" role="toolbar" aria-label="Formato del texto">
            <div class="tb-group">
                <button type="button" class="tb-button" title="Deshacer (Ctrl+Z)" aria-label="Deshacer" :disabled="!editor.can().undo()" @click="run((c) => c.undo())"><Undo2Icon /></button>
                <button type="button" class="tb-button" title="Rehacer (Ctrl+Y)" aria-label="Rehacer" :disabled="!editor.can().redo()" @click="run((c) => c.redo())"><Redo2Icon /></button>
            </div>

            <label class="tb-group tb-select">
                <span class="sr-only">Estilo del párrafo</span>
                <select :value="blockType" @change="setBlock($event.target.value)">
                    <option value="paragraph">Párrafo</option>
                    <option value="h2">Título</option>
                    <option value="h3">Subtítulo</option>
                    <option value="h4">Título menor</option>
                </select>
            </label>

            <div class="tb-group">
                <button
                    v-for="mark in marks"
                    :key="mark.name"
                    type="button"
                    class="tb-button"
                    :class="{ 'is-active': editor.isActive(mark.name) }"
                    :aria-pressed="editor.isActive(mark.name)"
                    :title="mark.label"
                    :aria-label="mark.label"
                    @click="run(mark.run)"
                ><component :is="mark.icon" /></button>
            </div>

            <div class="tb-group">
                <button
                    v-for="item in alignments"
                    :key="item.value"
                    type="button"
                    class="tb-button"
                    :class="{ 'is-active': alignment === item.value }"
                    :aria-pressed="alignment === item.value"
                    :title="item.label"
                    :aria-label="item.label"
                    @click="align(item.value)"
                ><component :is="item.icon" /></button>
            </div>

            <div class="tb-group">
                <button
                    v-for="block in blocks"
                    :key="block.name"
                    type="button"
                    class="tb-button"
                    :class="{ 'is-active': editor.isActive(block.name) }"
                    :aria-pressed="editor.isActive(block.name)"
                    :title="block.label"
                    :aria-label="block.label"
                    @click="run(block.run)"
                ><component :is="block.icon" /></button>
                <button type="button" class="tb-button" title="Línea separadora" aria-label="Línea separadora" @click="run((c) => c.setHorizontalRule())"><MinusIcon /></button>
            </div>

            <div class="tb-group">
                <button type="button" class="tb-button" :class="{ 'is-active': editor.isActive('link') || linkPanel }" title="Enlace" aria-label="Enlace" @click="openLink"><LinkIcon /></button>
                <button type="button" class="tb-button tb-wide" data-image-button title="Agregar una imagen" @click="fileInput?.click()"><ImagePlusIcon /> Imagen</button>
                <button type="button" class="tb-button" title="Insertar una tabla" aria-label="Insertar una tabla" @click="insertTable"><TableIcon /></button>
                <button type="button" class="tb-button" title="Quitar el formato" aria-label="Quitar el formato" @click="run((c) => c.unsetAllMarks().clearNodes())"><RemoveFormattingIcon /></button>
            </div>

            <span v-if="uploading" class="tb-status" role="status">Subiendo {{ uploading === 1 ? 'la imagen' : `${uploading} imágenes` }}…</span>
            <input ref="fileInput" type="file" class="sr-only" tabindex="-1" aria-hidden="true" multiple :accept="imageRules.accept" @change="pickImages">
        </div>

        <!-- La fila de un enlace -->
        <form v-if="editor && linkPanel" class="blog-context" @submit.prevent="applyLink">
            <label class="context-field context-grow">
                <span>Dirección del enlace</span>
                <input ref="linkInput" v-model="linkForm.href" type="text" placeholder="https://… o /precios" @keydown.escape.prevent="linkPanel = false">
            </label>
            <label class="check context-check"><input v-model="linkForm.newTab" type="checkbox"> Abrir en otra pestaña</label>
            <div class="context-actions">
                <button v-if="editor.isActive('link')" type="button" class="btn btn-ghost btn-danger-text" @click="removeLink"><Link2OffIcon /> Quitar enlace</button>
                <button type="button" class="btn btn-ghost" @click="linkPanel = false">Cancelar</button>
                <button type="submit" class="btn btn-primary"><CheckIcon /> Aplicar</button>
            </div>
        </form>

        <!-- La fila de la imagen elegida -->
        <div v-if="editor && selectedImage" class="blog-context">
            <label class="context-field context-grow">
                <span>Texto alternativo (qué muestra la imagen)</span>
                <input ref="altInput" :value="selectedImage.alt" type="text" maxlength="250" placeholder="Por ejemplo: Pantalla de la factura electrónica en Pivox" @input="updateImage({ alt: $event.target.value })">
            </label>
            <label class="context-field context-grow">
                <span>Pie de foto (opcional)</span>
                <input :value="selectedImage.caption" type="text" maxlength="250" @input="updateImage({ caption: $event.target.value })">
            </label>
            <div class="context-actions">
                <div class="tb-segmented" role="group" aria-label="Tamaño de la imagen">
                    <button
                        v-for="size in sizes"
                        :key="size.value"
                        type="button"
                        class="tb-button tb-wide"
                        :class="{ 'is-active': selectedImage.size === size.value }"
                        :aria-pressed="selectedImage.size === size.value"
                        @click="updateImage({ size: size.value })"
                    >{{ size.label }}</button>
                </div>
                <button type="button" class="btn btn-ghost btn-danger-text" @click="removeImage"><Trash2Icon /> Quitar imagen</button>
            </div>
        </div>

        <!-- La fila de una tabla -->
        <div v-if="editor && inTable" class="blog-context blog-context-table" role="toolbar" aria-label="Tabla">
            <button
                v-for="action in tableActions"
                :key="action.label"
                type="button"
                class="tb-button tb-wide"
                @click="run(action.run)"
            ><component :is="action.icon" /> {{ action.label }}</button>
            <button type="button" class="tb-button tb-wide tb-danger" @click="run((c) => c.deleteTable())"><Trash2Icon /> Quitar tabla</button>
        </div>

        <EditorContent :editor="editor" class="blog-editor-content" />
    </div>
</template>

<style scoped>
.blog-editor {
    border: 1px solid var(--color-control-border);
    border-radius: var(--radius-md);
    background: var(--color-surface);
}

.blog-editor.is-invalid {
    border-color: var(--color-danger);
}

/* La barra queda arriba mientras se escribe un artículo largo. */
.blog-toolbar {
    position: sticky;
    top: 0;
    z-index: 3;
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.35rem 0.6rem;
    padding: 0.5rem 0.6rem;
    border-bottom: 1px solid var(--color-border);
    border-radius: var(--radius-md) var(--radius-md) 0 0;
    background: var(--color-surface-alt);
}

.tb-group {
    display: inline-flex;
    align-items: center;
    gap: 0.15rem;
}

.tb-group + .tb-group {
    padding-left: 0.6rem;
    border-left: 1px solid var(--color-border);
}

.tb-select select {
    width: 9.5rem;
}

.tb-button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.35rem;
    min-width: 2.25rem;
    min-height: 2.25rem;
    padding: 0 0.45rem;
    border: 1px solid transparent;
    border-radius: var(--radius-sm);
    background: transparent;
    color: var(--color-text);
    font: inherit;
    font-size: 0.82rem;
    font-weight: 600;
    cursor: pointer;
}

.tb-button:hover:not(:disabled) {
    background: var(--color-surface);
    border-color: var(--color-border);
}

.tb-button.is-active {
    background: var(--color-primary-soft);
    border-color: color-mix(in srgb, var(--color-primary) 35%, transparent);
    color: color-mix(in srgb, var(--color-primary) 80%, var(--color-text));
}

.tb-button:disabled {
    opacity: 0.4;
    cursor: not-allowed;
}

.tb-button:focus-visible {
    outline: 2px solid var(--color-primary);
    outline-offset: 1px;
}

.tb-wide {
    padding: 0 0.65rem;
}

.tb-danger {
    color: var(--color-danger);
}

.tb-status {
    color: var(--color-text-muted);
    font-size: 0.8rem;
}

.tb-segmented {
    display: inline-flex;
    gap: 0.15rem;
    padding: 0.15rem;
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    background: var(--color-surface);
}

/* Las filas que aparecen con un enlace, una imagen o una tabla elegidos. */
.blog-context {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-end;
    gap: 0.6rem 0.9rem;
    padding: 0.7rem 0.8rem;
    border-bottom: 1px solid var(--color-border);
    background: var(--color-primary-soft);
}

.blog-context-table {
    align-items: center;
    gap: 0.25rem;
}

.context-field {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
    min-width: 0;
}

.context-field > span {
    color: var(--color-text-muted);
    font-size: 0.74rem;
    font-weight: 600;
}

.context-grow {
    flex: 1 1 16rem;
}

.context-field input {
    width: 100%;
}

.context-check {
    min-height: 2.25rem;
    margin: 0;
}

.context-actions {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.4rem;
}

/* ── El texto, como se va a ver en el sitio (site/_blog.scss) ─────────── */

.blog-editor-content :deep(.blog-prose) {
    min-height: 24rem;
    padding: 1.5rem clamp(1rem, 3vw, 2.5rem);
    color: var(--color-text);
    font-size: 1.02rem;
    line-height: 1.75;
    outline: none;
    overflow-wrap: break-word;
}

.blog-editor-content :deep(.blog-prose:focus-visible) {
    box-shadow: inset 0 0 0 2px color-mix(in srgb, var(--color-primary) 35%, transparent);
}

.blog-editor-content :deep(.blog-prose > *:first-child) {
    margin-top: 0;
}

.blog-editor-content :deep(.blog-prose p.is-editor-empty:first-child::before) {
    content: attr(data-placeholder);
    float: left;
    height: 0;
    color: var(--color-text-muted);
    pointer-events: none;
}

.blog-editor-content :deep(:is(p, ul, ol, blockquote, figure, hr, .tableWrapper, table)) {
    margin: 0 0 1.1rem;
}

.blog-editor-content :deep(:is(h2, h3, h4)) {
    margin: 1.8rem 0 0.7rem;
    line-height: 1.25;
    letter-spacing: -0.01em;
}

.blog-editor-content :deep(h2) { font-size: 1.6rem; }
.blog-editor-content :deep(h3) { font-size: 1.28rem; }
.blog-editor-content :deep(h4) { font-size: 1.1rem; }

.blog-editor-content :deep(:is(ul, ol)) {
    padding-left: 1.4rem;
}

.blog-editor-content :deep(li > p) {
    margin: 0 0 0.3rem;
}

.blog-editor-content :deep(a) {
    color: color-mix(in srgb, var(--color-primary) 60%, var(--color-text));
    font-weight: 600;
    text-decoration: underline;
}

.blog-editor-content :deep(blockquote) {
    padding-left: 1.1rem;
    border-left: 4px solid var(--color-accent);
    color: var(--color-text-muted);
    font-style: italic;
}

.blog-editor-content :deep(mark) {
    padding: 0 0.15em;
    border-radius: 0.2em;
    background: color-mix(in srgb, var(--color-accent) 38%, transparent);
    color: inherit;
}

.blog-editor-content :deep(code) {
    padding: 0.1em 0.35em;
    border-radius: 0.3em;
    background: var(--color-surface-alt);
    font-size: 0.9em;
}

.blog-editor-content :deep(hr) {
    border: 0;
    border-top: 2px solid var(--color-border);
}

.blog-editor-content :deep(hr.ProseMirror-selectednode) {
    border-top-color: var(--color-primary);
}

.blog-editor-content :deep(.text-center) { text-align: center; }
.blog-editor-content :deep(.text-right) { text-align: right; }
.blog-editor-content :deep(.text-justify) { text-align: justify; }

.blog-editor-content :deep(figure) {
    margin-inline: 0;
}

.blog-editor-content :deep(figure img) {
    display: block;
    max-width: 100%;
    height: auto;
    margin-inline: auto;
    border-radius: var(--radius-md);
}

.blog-editor-content :deep(figure.image-medium img) { width: 70%; }
.blog-editor-content :deep(figure.image-small img) { width: 45%; }

.blog-editor-content :deep(figcaption) {
    margin-top: 0.45rem;
    color: var(--color-text-muted);
    font-size: 0.88rem;
    text-align: center;
}

.blog-editor-content :deep(figure.ProseMirror-selectednode img) {
    outline: 3px solid var(--color-primary);
    outline-offset: 3px;
}

.blog-editor-content :deep(.tableWrapper) {
    overflow-x: auto;
}

.blog-editor-content :deep(table) {
    width: 100%;
    border: 1px solid var(--color-border);
    font-size: 0.94rem;
}

.blog-editor-content :deep(:is(th, td)) {
    position: relative;
    min-width: 6rem;
    padding: 0.5rem 0.7rem;
    border: 1px solid var(--color-border);
    vertical-align: top;
}

.blog-editor-content :deep(th) {
    background: var(--color-surface-alt);
    font-weight: 700;
    text-align: left;
}

.blog-editor-content :deep(:is(th, td) > p) {
    margin: 0;
}

.blog-editor-content :deep(.selectedCell)::after {
    content: '';
    position: absolute;
    inset: 0;
    background: color-mix(in srgb, var(--color-primary) 14%, transparent);
    pointer-events: none;
}

@media (max-width: 640px) {
    .tb-group + .tb-group {
        padding-left: 0;
        border-left: 0;
    }

    .blog-editor-content :deep(.blog-prose) {
        min-height: 18rem;
        padding: 1rem;
    }
}
</style>
