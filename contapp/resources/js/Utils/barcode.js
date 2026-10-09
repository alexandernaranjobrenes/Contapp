import JsBarcode from 'jsbarcode';

/**
 * Códigos de barras de los artículos (ItemBarcodeService, en el servidor).
 *
 * - 13, 12 u 8 dígitos con el verificador bien: EAN-13, UPC-A o EAN-8, los de
 *   los productos. Con el verificador mal, casi siempre es un número mal
 *   tecleado: se avisa y se dibuja igual, como Code 128.
 * - Cualquier otro texto (letras sin tilde, números, símbolos): Code 128.
 * - Con tildes o ñ no hay código que lo lleve: no se dibuja.
 */

/** El dígito verificador de un EAN-13, EAN-8 o UPC-A: los dígitos sin el último. */
export function checkDigit(digits) {
    let sum = 0;
    // Desde la derecha, el primero pesa 3, el siguiente 1, y así.
    [...digits].reverse().forEach((digit, i) => { sum += Number(digit) * (i % 2 === 0 ? 3 : 1); });
    return (10 - (sum % 10)) % 10;
}

const PRODUCT_FORMATS = { 13: ['EAN13', 'EAN-13'], 12: ['UPC', 'UPC-A'], 8: ['EAN8', 'EAN-8'] };

/**
 * Cómo se dibuja un código: { format, label, warning, drawable }.
 * `format` es el de JsBarcode; `label`, el nombre para la persona.
 */
export function describeBarcode(value) {
    const code = String(value ?? '').trim();
    if (code === '') return { format: null, label: null, warning: null, drawable: false };

    if (!/^[\x20-\x7E]+$/.test(code)) {
        return { format: null, label: null, warning: 'Con tildes o ñ no se puede dibujar: usá letras sin tilde, números y símbolos comunes.', drawable: false };
    }

    const product = /^\d+$/.test(code) ? PRODUCT_FORMATS[code.length] : null;
    if (product) {
        if (checkDigit(code.slice(0, -1)) === Number(code.at(-1))) {
            return { format: product[0], label: product[1], warning: null, drawable: true };
        }
        return {
            format: 'CODE128',
            label: 'Code 128',
            warning: `Si es el código del producto (${product[1]}), el último dígito no calza: revisá que esté bien escrito. Se dibuja igual, como Code 128.`,
            drawable: true,
        };
    }

    return {
        format: 'CODE128',
        label: 'Code 128',
        warning: code.length > 30 ? 'Es largo: en una etiqueta chica las barras quedan muy finas para el lector.' : null,
        drawable: true,
    };
}

/**
 * Dibuja el código en un <svg>. Las barras siempre negras sobre blanco: así
 * las leen los lectores, también con el tema oscuro.
 */
export function drawBarcode(svg, value, options = {}) {
    const { format, drawable } = describeBarcode(value);
    if (!svg || !drawable) return false;

    try {
        JsBarcode(svg, String(value).trim(), {
            format,
            width: 2,
            height: 60,
            margin: 8,
            fontSize: 16,
            textMargin: 2,
            background: '#ffffff',
            lineColor: '#000000',
            displayValue: true,
            font: 'monospace',
            ...options,
        });
        return true;
    } catch {
        return false;
    }
}

/** El mismo dibujo, como texto SVG (para las etiquetas). */
export function barcodeSvg(value, options = {}) {
    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    return drawBarcode(svg, value, options) ? svg.outerHTML : null;
}
