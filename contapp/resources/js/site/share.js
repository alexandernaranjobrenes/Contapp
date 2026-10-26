/**
 * Compartir un artículo del blog: los enlaces a cada red ya funcionan sin
 * JavaScript; esto agrega «Copiar enlace» y, en un teléfono, el menú de
 * compartir del sistema.
 */
export function initShare(root) {
    const url = root.dataset.share;
    const title = root.dataset.shareTitle ?? document.title;

    const copy = root.querySelector('[data-share-copy]');
    const status = root.querySelector('[data-share-status]');

    copy?.addEventListener('click', async () => {
        try {
            await navigator.clipboard.writeText(url);
            if (status) status.textContent = 'Enlace copiado.';
        } catch {
            if (status) status.textContent = 'No se pudo copiar: seleccioná la dirección del navegador.';
        }

        setTimeout(() => { if (status) status.textContent = ''; }, 3000);
    });

    const native = root.querySelector('[data-share-native]');

    if (native && navigator.share) {
        native.hidden = false;
        native.addEventListener('click', () => navigator.share({ title, url }).catch(() => {}));
    }
}
