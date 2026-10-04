/**
 * Cuánto hace de algo, como se dice: «hace 5 minutos», «ayer», «hace 3
 * días». Pasada una semana, la fecha («12 sept 2026»), que ya se lee mejor.
 */
const relative = new Intl.RelativeTimeFormat('es', { numeric: 'auto' });
const dateFormat = new Intl.DateTimeFormat('es-CR', { day: 'numeric', month: 'short', year: 'numeric' });
const fullFormat = new Intl.DateTimeFormat('es-CR', { dateStyle: 'long', timeStyle: 'short' });

export function timeAgo(iso) {
    const date = new Date(iso);
    const seconds = Math.round((date.getTime() - Date.now()) / 1000);
    const abs = Math.abs(seconds);

    if (abs < 60) return 'recién';
    if (abs < 3600) return relative.format(Math.round(seconds / 60), 'minute');
    if (abs < 86400) return relative.format(Math.round(seconds / 3600), 'hour');
    if (abs < 7 * 86400) return relative.format(Math.round(seconds / 86400), 'day');

    return dateFormat.format(date);
}

/** La fecha y la hora completas, para el title de un <time>. */
export function fullDate(iso) {
    return fullFormat.format(new Date(iso));
}
