/**
 * ¿Se escanea con este dispositivo o con el teléfono? Lo usan el escaneo de
 * documentos de Conti (ContiScanModal.vue) y el de códigos de barras de los
 * artículos (BarcodePhoneLink.vue).
 *
 * Un teléfono o una tableta (pantalla táctil) con cámara: se escanea ahí
 * mismo. Una computadora, o un dispositivo sin cámara: el QR para hacerlo con
 * el teléfono.
 *
 * La cámara se averigua sin pedir permiso: la lista de dispositivos dice si
 * hay una, aunque sin nombre. Si el navegador no la da, en un teléfono se
 * asume que sí (el campo de la foto abre la cámara igual).
 */
export async function canCaptureHere() {
    const touch = window.matchMedia?.('(pointer: coarse)').matches && (navigator.maxTouchPoints ?? 0) > 0;

    if (!touch) return false;

    try {
        const devices = await navigator.mediaDevices?.enumerateDevices?.();
        if (Array.isArray(devices) && devices.length) return devices.some((device) => device.kind === 'videoinput');
    } catch {
        // Sin la lista: se asume que sí.
    }

    return true;
}
