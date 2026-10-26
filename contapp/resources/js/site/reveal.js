/**
 * Las entradas al aparecer en pantalla ([data-reveal]) y las cifras que
 * cuentan hasta su valor ([data-count-to]). Con movimiento reducido, o sin
 * IntersectionObserver, todo se muestra quieto desde el principio.
 */
const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

export function initReveal() {
    const items = [...document.querySelectorAll('[data-reveal]')];

    if (reduced || !('IntersectionObserver' in window)) {
        items.forEach((item) => item.classList.add('is-visible'));
        return;
    }

    // Los hermanos de una misma grilla entran uno detrás de otro.
    items.forEach((item) => {
        const siblings = [...item.parentElement.children].filter((child) => child.hasAttribute('data-reveal'));
        const index = siblings.indexOf(item);
        if (siblings.length > 1 && index > 0) item.style.transitionDelay = `${Math.min(index, 6) * 70}ms`;
    });

    const observer = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
            if (!entry.isIntersecting) return;

            entry.target.classList.add('is-visible');
            observer.unobserve(entry.target);
        });
    }, { rootMargin: '0px 0px -8% 0px', threshold: 0.12 });

    items.forEach((item) => observer.observe(item));
}

// Punto de millares, como en la aplicación (Utils/money.js): «1.286».
const thousands = (value) => String(value).replace(/\B(?=(\d{3})+(?!\d))/g, '.');

export function initCounters() {
    if (reduced) return;

    document.querySelectorAll('[data-count-to]').forEach((element) => {
        const target = Number(element.dataset.countTo);
        if (!Number.isFinite(target)) return;

        const started = performance.now();
        const duration = 1400;

        const frame = (now) => {
            const progress = Math.min(1, (now - started) / duration);
            const eased = 1 - (1 - progress) ** 3;
            element.textContent = thousands(Math.round(target * eased));
            if (progress < 1) requestAnimationFrame(frame);
        };

        requestAnimationFrame(frame);
    });
}
