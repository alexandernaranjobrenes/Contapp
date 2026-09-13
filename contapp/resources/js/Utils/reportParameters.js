// Envuelve un valor de fecha fija en el shape {type, value} que espera el
// backend (App\Domains\Reporting\Support\ReportCatalog / SavedReportController)
// para poder distinguirla de una fecha relativa ("inicio de mes", etc.) sin
// tener que adivinar por el formato del string.
export function wrapDate(value) {
    return { type: 'fixed', value };
}
