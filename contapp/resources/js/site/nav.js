/**
 * La barra de arriba (una línea abajo al desplazarse) y el cajón del menú en
 * pantallas angostas: lo cierran Escape, la X, el fondo y cualquier enlace, y
 * el foco vuelve al botón que lo abrió.
 */
export function initHeader() {
    const header = document.querySelector('[data-site-header]');
    if (!header) return;

    const update = () => header.classList.toggle('is-scrolled', window.scrollY > 8);

    window.addEventListener('scroll', update, { passive: true });
    update();
}

const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select, textarea, [tabindex]:not([tabindex="-1"])';

export function initNav() {
    const toggle = document.querySelector('[data-nav-toggle]');
    const drawer = document.querySelector('[data-nav-drawer]');
    if (!toggle || !drawer) return;

    const panel = drawer.querySelector('[role="dialog"]');

    function onKeydown(event) {
        if (event.key === 'Escape') {
            close();
            return;
        }

        if (event.key !== 'Tab') return;

        const focusables = [...panel.querySelectorAll(FOCUSABLE)];
        const first = focusables[0];
        const last = focusables[focusables.length - 1];

        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    }

    function open() {
        drawer.hidden = false;
        toggle.setAttribute('aria-expanded', 'true');
        document.body.classList.add('is-locked');
        document.addEventListener('keydown', onKeydown);
        panel.querySelector(FOCUSABLE)?.focus();
    }

    function close() {
        if (drawer.hidden) return;

        drawer.hidden = true;
        toggle.setAttribute('aria-expanded', 'false');
        document.body.classList.remove('is-locked');
        document.removeEventListener('keydown', onKeydown);
        toggle.focus();
    }

    toggle.addEventListener('click', open);
    drawer.querySelectorAll('[data-nav-close]').forEach((element) => element.addEventListener('click', close));

    // Al pasar a una pantalla ancha, el cajón ya no tiene sentido.
    window.matchMedia('(min-width: 961px)').addEventListener?.('change', (event) => {
        if (event.matches) close();
    });
}
