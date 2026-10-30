/**
 * Los números de los gráficos del Panel (CLAUDE.md secc. 44), con la
 * convención de Pivox (es-CR: punto de millares, coma decimal).
 *
 * - amount(): el monto completo, para los valores y los avisos: «₡1.234.567,89».
 * - compact(): corto, para los ejes y los valores grandes de un vistazo:
 *   «₡1,2 M», «₡850 mil».
 * - ticks(): cortes redondos para un eje (0, 250 mil, 500 mil…).
 */
const full = new Intl.NumberFormat('es-CR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const whole = new Intl.NumberFormat('es-CR', { maximumFractionDigits: 0 });
const short = new Intl.NumberFormat('es-CR', { notation: 'compact', maximumFractionDigits: 1 });

function sign(value) {
    return value < 0 ? '−' : '';
}

export function amount(value, format = 'money', currency = null) {
    const n = Number(value ?? 0);
    if (format === 'count') return whole.format(n);

    return `${sign(n)}${currency?.symbol ?? ''}${full.format(Math.abs(n))}`;
}

export function compact(value, format = 'money', currency = null) {
    const n = Number(value ?? 0);
    if (format === 'count') return short.format(n);

    return `${sign(n)}${currency?.symbol ?? ''}${short.format(Math.abs(n))}`;
}

/** Una diferencia con su signo: «+₡12.500,00» o «−₡3.000,00». */
export function signedAmount(value, format = 'money', currency = null) {
    const n = Number(value ?? 0);
    return `${n > 0 ? '+' : ''}${amount(n, format, currency)}`;
}

/**
 * Entre 3 y 5 cortes redondos que cubren [min, max], siempre con el cero si
 * el rango lo cruza o lo toca.
 */
export function ticks(min, max, target = 4) {
    if (min === max) {
        max = min === 0 ? 1 : min + Math.abs(min) * 0.1;
    }

    const span = max - min;
    const raw = span / target;
    const magnitude = 10 ** Math.floor(Math.log10(raw));
    const step = [1, 2, 2.5, 5, 10].map((m) => m * magnitude).find((s) => span / s <= target + 1) ?? 10 * magnitude;

    const start = Math.floor(min / step) * step;
    const end = Math.ceil(max / step) * step;
    const list = [];
    for (let v = start; v <= end + step / 2; v += step) list.push(Math.round(v / step) * step);

    return list;
}
