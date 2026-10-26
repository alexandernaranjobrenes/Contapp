/**
 * Las demostraciones animadas de la landing (site/demos/*.blade.php).
 *
 * Cada demo es una línea de tiempo: una lista de pasos con su duración. Al
 * alcanzar el paso n la demo recibe las clases s1…sn, y el CSS
 * (resources/css/site/_demos.scss) dice qué se ve desde cada paso. Al
 * terminar, se desvanece y vuelve a empezar.
 *
 * Solo corren mientras se ven en pantalla, y se pausan con la pestaña en
 * segundo plano. Con movimiento reducido quedan quietas en `still`, el paso
 * que mejor se explica solo.
 */
const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

const wait = (ms) => new Promise((resolve) => { setTimeout(resolve, ms); });

/** Escribe el texto de [data-type] letra por letra, en el tiempo del paso. */
function typeText(demo, ms, isCurrent) {
    const target = demo.querySelector('[data-type]');
    if (!target) return Promise.resolve();

    const text = target.dataset.type;
    const delay = Math.max(16, Math.floor((ms - 350) / text.length));

    return (async () => {
        target.textContent = '';

        for (const character of text) {
            if (!isCurrent()) return;
            target.textContent += character;
            await wait(delay);
        }
    })();
}

const TIMELINES = {
    // El robot se abre, el chat, lo que se escribe, el formulario, la
    // confirmación y el asiento guardado.
    conti: {
        steps: [900, 800, { ms: 2600, run: typeText }, 550, 1200, 2900, 500, 2300, 450, 2900],
        still: 6,
        reset: (demo) => {
            const target = demo.querySelector('[data-type]');
            if (target) target.textContent = '';
        },
    },
    // El teléfono, el escaneo, la foto, «Listo», los campos y la nota.
    scanner: { steps: [900, 2600, 700, 1000, 2800, 2600], still: 6 },
    // El láser y el artículo encontrado.
    barcode: { steps: [1900, 2800], still: 2 },
    // Firmado, enviado, en proceso, aceptado y entregado.
    einvoice: { steps: [1000, 1200, 1700, 1500, 2800], still: 5 },
};

function timeline(demo, { steps, still, reset }) {
    let index = 0;
    let active = false;
    let generation = 0;

    const show = (step) => {
        for (let n = 1; n <= steps.length; n += 1) demo.classList.toggle(`s${n}`, n <= step);
    };

    async function loop() {
        const mine = ++generation;
        const isCurrent = () => active && mine === generation;

        while (isCurrent()) {
            if (index >= steps.length) {
                demo.classList.add('is-resetting');
                await wait(650);
                if (!isCurrent()) return;

                show(0);
                reset?.(demo);
                demo.classList.remove('is-resetting');
                index = 0;
                await wait(600);
                continue;
            }

            const step = steps[index];
            index += 1;
            show(index);

            const ms = typeof step === 'number' ? step : step.ms;
            await Promise.all([wait(ms), step.run ? step.run(demo, ms, isCurrent) : null]);
        }
    }

    if (reduced) {
        show(still);
        return { start() {}, stop() {} };
    }

    return {
        start() {
            if (active) return;
            active = true;
            loop();
        },
        stop() {
            active = false;
            generation += 1;
        },
    };
}

/**
 * Los temas: la maqueta pasa de compañía en compañía con la ventana del
 * cambio de compañía, cada una con su tema. Tocar una muestra aplica ese
 * tema y frena el recorrido.
 */
function themes(demo) {
    const preview = demo.querySelector('[data-theme-preview]');
    const overlay = demo.querySelector('[data-theme-switch]');
    const companyName = demo.querySelector('[data-company-name]');
    const switchName = demo.querySelector('[data-switch-name]');
    const swatches = [...demo.querySelectorAll('[data-theme-swatch]')];
    const companies = JSON.parse(demo.dataset.companies || '[]');

    let index = 0;
    let automatic = !reduced;
    let visible = false;
    let timer = null;

    const press = (theme) => swatches.forEach((swatch) => {
        swatch.setAttribute('aria-pressed', String(swatch.dataset.themeSwatch === theme));
    });

    const apply = (theme) => {
        preview.dataset.companyTheme = theme;
        press(theme);
    };

    async function switchTo(company) {
        overlay.dataset.companyTheme = company.theme;
        switchName.textContent = company.name;
        preview.classList.add('is-switching');
        await wait(1200);

        if (!automatic) {
            preview.classList.remove('is-switching');
            return;
        }

        apply(company.theme);
        companyName.textContent = company.name;
        await wait(350);
        preview.classList.remove('is-switching');
    }

    function schedule() {
        clearTimeout(timer);
        if (!automatic || !visible || companies.length < 2) return;

        timer = setTimeout(async () => {
            index = (index + 1) % companies.length;
            await switchTo(companies[index]);
            schedule();
        }, 3400);
    }

    swatches.forEach((swatch) => swatch.addEventListener('click', () => {
        automatic = false;
        clearTimeout(timer);
        preview.classList.remove('is-switching');
        apply(swatch.dataset.themeSwatch);
    }));

    return {
        start() {
            visible = true;
            schedule();
        },
        stop() {
            visible = false;
            clearTimeout(timer);
        },
    };
}

export function initDemos() {
    const demos = [...document.querySelectorAll('[data-demo]')]
        .map((element) => {
            const name = element.dataset.demo;
            const controller = name === 'themes'
                ? themes(element)
                : TIMELINES[name] ? timeline(element, TIMELINES[name]) : null;

            return controller ? { element, controller, visible: false } : null;
        })
        .filter(Boolean);

    if (!demos.length) return;

    const sync = (demo) => {
        if (demo.visible && !document.hidden) demo.controller.start();
        else demo.controller.stop();
    };

    if (!('IntersectionObserver' in window)) {
        demos.forEach((demo) => { demo.visible = true; sync(demo); });
        return;
    }

    const observer = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
            const demo = demos.find((item) => item.element === entry.target);
            demo.visible = entry.isIntersecting;
            sync(demo);
        });
    }, { threshold: 0.35 });

    demos.forEach((demo) => observer.observe(demo.element));
    document.addEventListener('visibilitychange', () => demos.forEach(sync));
}
