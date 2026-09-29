import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { LUCIDE_CONTEXT } from '@lucide/vue';
import ConfirmHost from './Components/ConfirmHost.vue';
// Botón ocupado mientras dura la operación que disparó (CLAUDE.md secc. 27).
// Se instala solo al importarlo.
import './Utils/busyButtons';

createInertiaApp({
    title: (title) => (title ? `${title} — CONTAPP` : 'CONTAPP'),
    resolve: (name) => {
        const pages = import.meta.glob('./Pages/**/*.vue', { eager: true });
        return pages[`./Pages/${name}.vue`];
    },
    setup({ el, App, props, plugin }) {
        // ConfirmHost va al lado de la página, no adentro: la confirmación de
        // confirmAction() sigue abierta aunque la acción cambie de página.
        const app = createApp({ render: () => [h(App, props), h(ConfirmHost)] });

        // Ziggy (@routes en app.blade.php) define route() como global de
        // window, pero las plantillas .vue precompiladas resuelven cada
        // identificador vía _ctx (la instancia del componente), no vía
        // window — sin esto, cualquier route(...) usado DIRECTO en un
        // <template> (no dentro de <script setup>) revienta con
        // "_ctx.route is not a function". globalProperties lo deja
        // disponible en _ctx para toda plantilla, sin tocar cada página.
        app.config.globalProperties.route = window.route;

        // Íconos: solo Lucide (CLAUDE.md secc. 23). 16px por defecto —el del
        // texto de botones y tablas— para toda la app; donde haga falta otro
        // tamaño (el menú lateral), el ícono lo pide con :size.
        app.provide(LUCIDE_CONTEXT, { size: 16, strokeWidth: 2 });

        app.use(plugin).mount(el);
    },
});
