/**
 * El modo claro u oscuro del sitio: la misma preferencia que la aplicación
 * (pivox-theme en el navegador), así quien lo eligió en Pivox lo ve igual
 * acá. Sin preferencia guardada, el del sistema.
 */
const KEY = 'pivox-theme';
const system = window.matchMedia('(prefers-color-scheme: dark)');

function current() {
    const chosen = document.documentElement.getAttribute('data-theme');

    if (chosen === 'light' || chosen === 'dark') return chosen;

    return system.matches ? 'dark' : 'light';
}

export function initTheme() {
    const buttons = document.querySelectorAll('[data-theme-toggle]');

    const label = () => {
        const text = current() === 'dark' ? 'Cambiar a modo claro' : 'Cambiar a modo oscuro';
        buttons.forEach((button) => button.setAttribute('aria-label', text));
    };

    buttons.forEach((button) => button.addEventListener('click', () => {
        const next = current() === 'dark' ? 'light' : 'dark';
        document.documentElement.setAttribute('data-theme', next);

        try {
            localStorage.setItem(KEY, next);
        } catch {
            // Sin almacenamiento (navegación privada), el cambio vale hasta recargar.
        }

        label();
    }));

    system.addEventListener?.('change', label);
    label();
}
