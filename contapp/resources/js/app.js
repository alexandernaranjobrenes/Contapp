import { createApp, h } from 'vue';
import { createInertiaApp, router } from '@inertiajs/vue3';
import { LUCIDE_CONTEXT } from '@lucide/vue';
import CompanySwitchHost from './Components/CompanySwitchHost.vue';
import ConfirmHost from './Components/ConfirmHost.vue';
import ContiActionModal from './Components/Conti/ContiActionModal.vue';
import ContiPanel from './Components/Conti/ContiPanel.vue';
import ToastHost from './Components/ToastHost.vue';
// Botón ocupado mientras dura la operación que disparó, también en las
// descargas, y el error dicho en pantalla si falla (CLAUDE.md secc. 27).
// Cada uno se instala solo al importarlo.
import './Utils/busyButtons';
import './Utils/downloads';
import './Utils/requestErrors';

// El tema visual de la compañía activa (CLAUDE.md secc. 31): cada página
// trae el suyo en companyTheme. La primera carga ya lo trae puesto desde
// app.blade.php; esto lo mantiene al día —al cambiar de compañía, y al salir
// de Apariencia sin guardar una vista previa—.
//
// En 'success' y no solo en 'navigate': una visita que termina en la misma
// dirección (el cambio de compañía vuelve a la página en que se estaba)
// reemplaza la entrada del historial y Inertia no dispara 'navigate'.
// 'navigate' queda para ir atrás y adelante con el navegador.
function applyCompanyTheme(page) {
    const theme = page?.props?.companyTheme;
    if (theme) document.documentElement.dataset.companyTheme = theme;
}

router.on('success', (event) => applyCompanyTheme(event.detail.page));
router.on('navigate', (event) => applyCompanyTheme(event.detail.page));

createInertiaApp({
    title: (title) => (title ? `${title} — CONTAPP` : 'CONTAPP'),
    resolve: (name) => {
        const pages = import.meta.glob('./Pages/**/*.vue', { eager: true });
        return pages[`./Pages/${name}.vue`];
    },
    setup({ el, App, props, plugin }) {
        // ConfirmHost, CompanySwitchHost y ToastHost van al lado de la
        // página, no adentro: la confirmación de confirmAction(), la ventana
        // del cambio de compañía y los avisos siguen a la vista aunque la
        // acción termine en otra página. El chat de Conti, igual: la
        // conversación sigue abierta al pasar de pantalla, y el modal para
        // confirmar lo que preparó se abre encima de cualquiera.
        const app = createApp({ render: () => [h(App, props), h(ContiPanel), h(ContiActionModal), h(ConfirmHost), h(CompanySwitchHost), h(ToastHost)] });

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
