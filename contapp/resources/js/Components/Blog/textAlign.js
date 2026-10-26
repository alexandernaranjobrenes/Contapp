import { Extension } from '@tiptap/core';

/**
 * Alinear un párrafo o un título en el editor del blog, con una clase
 * (text-center, text-right, text-justify) y no con style: BlogHtml no acepta
 * el atributo style, y una clase de la lista es lo único que deja pasar.
 *
 * Lo que se pega de Word o de Google Docs trae la alineación en style: se lee
 * de ahí y se guarda como clase.
 */
const ALIGNMENTS = ['center', 'right', 'justify'];

export const TextAlign = Extension.create({
    name: 'textAlign',

    addOptions() {
        return { types: ['heading', 'paragraph'] };
    },

    addGlobalAttributes() {
        return [{
            types: this.options.types,
            attributes: {
                textAlign: {
                    default: null,
                    parseHTML: (element) => ALIGNMENTS.find((alignment) => element.classList.contains(`text-${alignment}`))
                        ?? (ALIGNMENTS.includes(element.style.textAlign) ? element.style.textAlign : null),
                    renderHTML: (attributes) => (attributes.textAlign ? { class: `text-${attributes.textAlign}` } : {}),
                },
            },
        }];
    },

    addCommands() {
        return {
            setTextAlign: (alignment) => ({ commands }) => {
                const value = ALIGNMENTS.includes(alignment) ? alignment : null;

                return this.options.types
                    .map((type) => commands.updateAttributes(type, { textAlign: value }))
                    .some(Boolean);
            },
        };
    },
});
