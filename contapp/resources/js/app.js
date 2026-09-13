import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';

createInertiaApp({
    title: (title) => (title ? `${title} — CONTAPP` : 'CONTAPP'),
    resolve: (name) => {
        const pages = import.meta.glob('./Pages/**/*.vue', { eager: true });
        return pages[`./Pages/${name}.vue`];
    },
    setup({ el, App, props, plugin }) {
        const app = createApp({ render: () => h(App, props) });

        // Ziggy (@routes en app.blade.php) define route() como global de
        // window, pero las plantillas .vue precompiladas resuelven cada
        // identificador vía _ctx (la instancia del componente), no vía
        // window — sin esto, cualquier route(...) usado DIRECTO en un
        // <template> (no dentro de <script setup>) revienta con
        // "_ctx.route is not a function". globalProperties lo deja
        // disponible en _ctx para toda plantilla, sin tocar cada página.
        app.config.globalProperties.route = window.route;

        app.use(plugin).mount(el);
    },
});
