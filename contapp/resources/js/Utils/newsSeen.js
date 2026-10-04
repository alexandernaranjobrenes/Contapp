import { computed, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';

/**
 * ¿Hay una noticia del backoffice que esta persona todavía no vio? Lo dice el
 * punto del botón «Comentarios y noticias» de la barra superior y el de la
 * pestaña «Noticias» del panel.
 *
 * El servidor comparte la fecha de la última noticia (latestNewsAt); la de la
 * última que se vio queda en este navegador, por cuenta. Es solo un aviso: en
 * otro dispositivo el punto vuelve a aparecer, y no pasa nada.
 */
const seenAt = ref(null);
let loadedFor = null;

function storageKey(userId) {
    return `contapp-news-seen:${userId}`;
}

export function useNewsSeen() {
    const page = usePage();
    const userId = computed(() => page.props.auth?.user?.id ?? null);
    const latest = computed(() => page.props.latestNewsAt ?? null);

    if (userId.value !== null && loadedFor !== userId.value) {
        loadedFor = userId.value;
        try {
            seenAt.value = localStorage.getItem(storageKey(userId.value));
        } catch {
            seenAt.value = null;
        }
    }

    const hasUnseen = computed(() => !!latest.value && (!seenAt.value || new Date(latest.value) > new Date(seenAt.value)));

    function markSeen() {
        if (!latest.value || userId.value === null) return;

        seenAt.value = latest.value;
        try {
            localStorage.setItem(storageKey(userId.value), latest.value);
        } catch {
            // Sin almacenamiento (navegación privada): el punto vuelve en la próxima visita.
        }
    }

    return { hasUnseen, markSeen };
}
