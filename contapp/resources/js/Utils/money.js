/**
 * Formato es-CR: punto de millares, coma decimal (ej. "135.000,00") — misma
 * convención en toda la app, tanto en pantalla como al exportar.
 */
export function formatMoney(value) {
    const n = parseFloat(value);
    if (Number.isNaN(n)) return value;

    return n.toLocaleString('es-CR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
