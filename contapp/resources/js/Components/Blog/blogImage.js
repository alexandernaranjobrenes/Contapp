import { Node } from '@tiptap/core';

/**
 * Una imagen del artículo en el editor del blog (CLAUDE.md secc. 41).
 *
 * Se guarda como <figure class="image-…"><img data-image-id alt><figcaption>,
 * que es lo único que acepta BlogHtml: la imagen se reconoce por su id en el
 * bucket (blog/imag_{id}.{ext}), no por su dirección. Una <img> pegada de otra
 * página no tiene ese id, así que no entra: hay que subirla.
 *
 * El texto alternativo, el pie y el tamaño se cambian desde la fila de la
 * imagen en la barra del editor, no escribiendo dentro de la figura.
 */
const SIZES = { medium: 'image-medium', small: 'image-small' };

function imageOf(element) {
    return element.tagName === 'FIGURE' ? element.querySelector('img[data-image-id]') : element;
}

export const BlogImage = Node.create({
    name: 'blogImage',
    group: 'block',
    atom: true,
    draggable: true,
    selectable: true,

    addAttributes() {
        return {
            id: {
                default: null,
                parseHTML: (element) => imageOf(element)?.getAttribute('data-image-id') ?? null,
            },
            src: {
                default: null,
                parseHTML: (element) => imageOf(element)?.getAttribute('src') ?? null,
            },
            alt: {
                default: '',
                parseHTML: (element) => imageOf(element)?.getAttribute('alt') ?? '',
            },
            caption: {
                default: '',
                parseHTML: (element) => (element.tagName === 'FIGURE' ? element.querySelector('figcaption')?.textContent?.trim() ?? '' : ''),
            },
            size: {
                default: 'wide',
                parseHTML: (element) => Object.keys(SIZES).find((size) => element.classList?.contains(SIZES[size])) ?? 'wide',
            },
        };
    },

    parseHTML() {
        return [
            { tag: 'figure', getAttrs: (element) => (element.querySelector('img[data-image-id]') ? {} : false) },
            { tag: 'img[data-image-id]' },
        ];
    },

    renderHTML({ node }) {
        const { id, src, alt, caption, size } = node.attrs;
        const image = ['img', { src, alt: alt ?? '', 'data-image-id': id }];
        const attributes = SIZES[size] ? { class: SIZES[size] } : {};

        return caption ? ['figure', attributes, image, ['figcaption', {}, caption]] : ['figure', attributes, image];
    },

    addCommands() {
        return {
            insertBlogImage: (attributes) => ({ commands }) => commands.insertContent({ type: this.name, attrs: attributes }),
        };
    },
});
