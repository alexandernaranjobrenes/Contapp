import { router } from '@inertiajs/vue3';
import { markBusy } from './busyButtons';
import { messageForStatus, notifyError } from './notify';

/**
 * Las descargas —Exportar XLSX, Exportar PDF, Descargar plantilla, el XML de
 * una factura…— con el botón ocupado mientras el archivo se genera, y el
 * error dicho en pantalla si no se pudo (CLAUDE.md secc. 27).
 *
 * Un enlace que descarga, dejado al navegador, no avisa nada: el reporte
 * tarda unos segundos en armarse y no se sabe si el clic se registró, y si
 * el servidor falla el navegador abandona la pantalla para mostrar una
 * página de error. Acá el archivo se pide con fetch(), el botón queda con su
 * spinner hasta que llega, y recién entonces se guarda.
 *
 * Qué enlaces: los que llevan el ícono de descarga de Lucide (CLAUDE.md
 * secc. 23: «exportar o descargar, Download»), o el atributo `download` o
 * `data-download` para los que descargan con otro ícono. Funciona solo:
 * ninguna pantalla tiene que hacer nada. Un enlace con target="_blank" (ver
 * e imprimir en otra pestaña) no se toca.
 */

const DOWNLOAD_ICON = 'svg[class*="lucide-download"]';

function isDownloadLink(anchor) {
    if (!anchor || anchor.target === '_blank' || anchor.hasAttribute('data-no-intercept')) return false;
    if (anchor.origin !== window.location.origin) return false;

    return anchor.hasAttribute('download')
        || anchor.hasAttribute('data-download')
        || anchor.querySelector(DOWNLOAD_ICON) !== null;
}

/** El nombre que manda el servidor en Content-Disposition. */
function filenameFrom(disposition) {
    if (!disposition) return null;

    const encoded = disposition.match(/filename\*\s*=\s*UTF-8''([^;]+)/i);
    if (encoded) {
        try {
            return decodeURIComponent(encoded[1].trim().replace(/^"|"$/g, ''));
        } catch {
            // Mal codificado: se prueba con el nombre simple.
        }
    }

    const plain = disposition.match(/filename\s*=\s*("([^"]*)"|[^;]+)/i);

    return plain ? (plain[2] ?? plain[1]).trim() : null;
}

function fallbackName(anchor) {
    const last = new URL(anchor.href).pathname.split('/').filter(Boolean).pop();

    return last || 'descarga';
}

function save(blob, filename) {
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    // Para que este mismo módulo no lo intercepte otra vez.
    link.setAttribute('data-no-intercept', '');
    link.style.display = 'none';
    document.body.append(link);
    link.click();
    link.remove();
    // Se suelta después: algunos navegadores leen el archivo un rato después
    // del clic.
    setTimeout(() => URL.revokeObjectURL(url), 60_000);
}

/**
 * El mensaje de una respuesta fallida. Las descargas mandan la cabecera
 * X-Contapp-Download, y el servidor contesta sus errores en JSON (ver
 * bootstrap/app.php): un 422 trae el error de validación, un 403 el motivo.
 */
async function errorMessage(response) {
    const generic = messageForStatus(response.status);

    if (!(response.headers.get('Content-Type') ?? '').includes('application/json')) return generic;

    try {
        const data = await response.json();
        const firstError = data.errors ? Object.values(data.errors).flat()[0] : null;
        const message = firstError ?? data.message;

        // Sin un motivo propio, Laravel manda el nombre del estado en inglés
        // («Forbidden», «Server Error»): ese no se muestra.
        if (!message || /^[A-Z][A-Za-z ]+\.?$/.test(message)) return generic;

        return `No se pudo descargar el archivo: ${message}`;
    } catch {
        return generic;
    }
}

async function download(anchor) {
    const release = markBusy(anchor);

    try {
        const response = await fetch(anchor.href, {
            credentials: 'same-origin',
            // Una redirección no se sigue: suele ser la vuelta a la pantalla
            // con un mensaje de error en la sesión, o al login.
            redirect: 'manual',
            headers: { 'X-Contapp-Download': '1' },
        });

        if (response.type === 'opaqueredirect') {
            // Se recarga la pantalla para que muestre lo que dejó el
            // servidor (un mensaje, o el login si la sesión venció).
            router.reload({ preserveScroll: true });
            return;
        }

        if (!response.ok) {
            notifyError(await errorMessage(response));
            return;
        }

        // Una página en vez de un archivo: el enlace no era una descarga. Se
        // sigue como lo habría seguido el navegador.
        if ((response.headers.get('Content-Type') ?? '').includes('text/html')) {
            window.location.assign(anchor.href);
            return;
        }

        save(await response.blob(), filenameFrom(response.headers.get('Content-Disposition')) ?? fallbackName(anchor));
    } catch {
        notifyError('No se pudo descargar el archivo: no hay conexión con el servidor. Probá de nuevo.');
    } finally {
        release();
    }
}

document.addEventListener('click', (event) => {
    // Ctrl/Cmd/Mayús + clic (abrir en otra pestaña o ventana) se le deja al
    // navegador, igual que un clic que otro código ya atendió.
    if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;

    const anchor = event.target.closest?.('a[href]');
    if (!isDownloadLink(anchor)) return;

    event.preventDefault();

    // Uno ya en curso: busyButtons.js frena el clic antes de llegar acá, pero
    // por si el clic vino del teclado.
    if (anchor.hasAttribute('data-busy')) return;

    download(anchor);
});
