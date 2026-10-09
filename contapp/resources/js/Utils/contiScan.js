import { reactive } from 'vue';

/**
 * Escanear un documento con Conti (ContiScanModal.vue, CLAUDE.md secc. 32):
 * dónde se abre, cómo se achican las fotos y si este dispositivo puede
 * tomarlas ahí mismo.
 */
export const scanner = reactive({
    open: false,
});

export function openScanner() {
    scanner.open = true;
}

export function closeScanner() {
    scanner.open = false;
}

// ¿Se pueden tomar las fotos acá, o con el teléfono? Compartido con los
// códigos de barras: vive en Utils/camera.js.
export { canCaptureHere } from './camera';

/**
 * La foto achicada a 1600 px por el lado más largo, en JPEG: sube rápido y la
 * IA la lee igual (y gasta menos). Las fotos no se guardan en ningún lado:
 * viven en el navegador hasta que se envían.
 */
export function shrinkPhoto(file, maxSide = 1600) {
    return new Promise((resolve, reject) => {
        const image = new Image();
        const url = URL.createObjectURL(file);

        image.onload = () => {
            const scale = Math.min(1, maxSide / Math.max(image.naturalWidth, image.naturalHeight));
            const canvas = document.createElement('canvas');
            canvas.width = Math.round(image.naturalWidth * scale);
            canvas.height = Math.round(image.naturalHeight * scale);
            canvas.getContext('2d').drawImage(image, 0, 0, canvas.width, canvas.height);
            URL.revokeObjectURL(url);
            canvas.toBlob((blob) => (blob ? resolve(blob) : reject(new Error('No se pudo usar esa foto.'))), 'image/jpeg', 0.82);
        };

        image.onerror = () => {
            URL.revokeObjectURL(url);
            reject(new Error('No se pudo usar esa foto.'));
        };

        image.src = url;
    });
}
