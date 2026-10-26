/**
 * El JavaScript del sitio público (CLAUDE.md secc. 40). Las páginas ya llegan
 * completas desde el servidor: esto solo agrega el modo claro u oscuro, el
 * menú del teléfono, las animaciones y, donde haga falta, una isla de Vue (el
 * configurador de precios), que se baja solo en la página que la tiene.
 */
import { initHeader, initNav } from './nav';
import { initTheme } from './theme';
import { initCounters, initReveal } from './reveal';
import { initDemos } from './demos';

initTheme();
initHeader();
initNav();
initReveal();
initCounters();
initDemos();

const pricing = document.querySelector('[data-island="pricing"]');

if (pricing) {
    import('./islands/pricing.js').then(({ mountPricing }) => mountPricing(pricing));
}

const share = document.querySelector('[data-share]');

if (share) {
    import('./share.js').then(({ initShare }) => initShare(share));
}
