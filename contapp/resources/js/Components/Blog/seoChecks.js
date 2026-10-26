/**
 * La lista de chequeo de SEO del editor del blog (CLAUDE.md secc. 41): lo que
 * un buscador mira de un artículo, revisado mientras se escribe. Es una
 * guía, no una regla: nada de esto impide publicar.
 */

/** Sin tildes ni mayúsculas, para buscar la palabra clave. */
export function normalize(text) {
    return (text ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();
}

/**
 * «Facturación electrónica 2026» → «facturacion-electronica-2026», como
 * Str::slug: la puntuación se borra («v4.4» → «v44»), no se vuelve guion.
 */
export function slugify(text) {
    return normalize(text)
        .replace(/_+/g, '-')
        .replace(/@/g, '-at-')
        .replace(/[^a-z0-9\s-]+/g, '')
        .replace(/[\s-]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .slice(0, 190)
        .replace(/-+$/g, '');
}

/** El <title> que va a tener la página (Seo::title): «… | Pivox». */
export function fullTitle(title) {
    const clean = (title ?? '').trim();

    return clean.includes('Pivox') ? clean : `${clean} | Pivox`;
}

const TITLE_IDEAL = 60;
const DESCRIPTION_MIN = 120;
const DESCRIPTION_IDEAL = 160;
const WORDS_MIN = 300;

export function seoChecks({ title, seoTitle, description, slug, keyword, stats, hasCover, coverAlt, categoryId }) {
    const pageTitle = fullTitle(seoTitle || title);
    const descriptionLength = (description ?? '').trim().length;
    const checks = [];

    const add = (ok, label, hint = '') => checks.push({ ok, label, hint });

    add(
        (title ?? '').trim() !== '' && pageTitle.length <= TITLE_IDEAL,
        `Título para buscadores: ${pageTitle.length} de ${TITLE_IDEAL} caracteres`,
        pageTitle.length > TITLE_IDEAL ? 'Más largo, Google lo corta. Acortalo en «Título para buscadores».' : '',
    );

    add(
        descriptionLength >= DESCRIPTION_MIN && descriptionLength <= DESCRIPTION_IDEAL,
        descriptionLength === 0
            ? 'Descripción para buscadores: falta'
            : `Descripción para buscadores: ${descriptionLength} caracteres`,
        descriptionLength === 0
            ? 'Sin ella se usa el resumen o el comienzo del texto.'
            : `Lo ideal: entre ${DESCRIPTION_MIN} y ${DESCRIPTION_IDEAL}.`,
    );

    add(stats.words >= WORDS_MIN, `${stats.words} palabras`, stats.words < WORDS_MIN ? `Un artículo de al menos ${WORDS_MIN} palabras posiciona mejor.` : '');
    add(stats.h2 > 0, stats.h2 > 0 ? `${stats.h2} ${stats.h2 === 1 ? 'título' : 'títulos'} en el texto` : 'Sin títulos en el texto', stats.h2 > 0 ? '' : 'Dividí el texto con «Título»: ayuda a leerlo y a que Google lo entienda.');
    add(hasCover && (coverAlt ?? '').trim() !== '', hasCover ? 'Portada con descripción' : 'Sin portada', hasCover ? '' : 'Es la imagen de la tarjeta, de las redes sociales y de la noticia.');

    if (stats.images > 0) {
        add(stats.missingAlt === 0, stats.missingAlt === 0 ? 'Todas las imágenes tienen texto alternativo' : `${stats.missingAlt} ${stats.missingAlt === 1 ? 'imagen sin' : 'imágenes sin'} texto alternativo`, stats.missingAlt ? 'Elegí la imagen y completá su texto alternativo.' : '');
    }

    add(stats.internalLinks > 0, stats.internalLinks > 0 ? 'Enlaza a otra página de Pivox' : 'Sin enlaces a otras páginas de Pivox', stats.internalLinks > 0 ? '' : 'Un enlace a otro artículo o a /precios ayuda a que Google recorra el sitio.');
    add(Boolean(categoryId), categoryId ? 'Con categoría' : 'Sin categoría');

    const word = normalize(keyword);

    if (word !== '') {
        add(normalize(seoTitle || title).includes(word), 'La palabra clave está en el título');
        add((slug ?? '').includes(slugify(keyword)), 'La palabra clave está en la dirección');
        add(normalize(description).includes(word), 'La palabra clave está en la descripción');
        add(normalize(stats.firstParagraph).includes(word), 'La palabra clave está en el primer párrafo');
    }

    return checks;
}
