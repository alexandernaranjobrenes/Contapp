import { createApp } from 'vue';
import { LUCIDE_CONTEXT } from '@lucide/vue';
import PricingConfigurator from './PricingConfigurator.vue';

/**
 * La isla de Vue de /precios (CLAUDE.md secc. 42): el configurador y el
 * formulario de solicitud. Se baja solo en esta página. El catálogo llega en
 * el <script type="application/json"> que arma el servidor.
 *
 * Los botones «Personalizar y solicitar» de las tarjetas (fuera de la isla)
 * eligen ese paquete en el configurador; sin JavaScript son un enlace con
 * ?paquete=.
 */
export function mountPricing(element) {
    const catalog = JSON.parse(document.getElementById('pricing-catalog')?.textContent ?? 'null');
    if (!catalog) return;

    const app = createApp(PricingConfigurator, {
        catalog,
        preselect: element.dataset.preselect || null,
        started: element.dataset.started,
        requestUrl: element.dataset.requestUrl,
        privacyUrl: element.dataset.privacyUrl,
        contactEmail: element.dataset.contact,
    });

    // Los íconos como en la aplicación (app.js): 16px y trazo 2.
    app.provide(LUCIDE_CONTEXT, { size: 16, strokeWidth: 2 });

    const instance = app.mount(element);

    document.querySelectorAll('[data-package]').forEach((link) => {
        link.addEventListener('click', (event) => {
            event.preventDefault();
            instance.choosePackage(link.dataset.package);
            document.getElementById('configurador')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    });
}
