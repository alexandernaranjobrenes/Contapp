<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { KeyRoundIcon, LayersIcon, LogOutIcon, MenuIcon, PercentIcon, XIcon } from '@lucide/vue';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';

defineProps({
    title: { type: String, default: '' },
});

const page = usePage();

const nav = [
    { label: 'Licencias', href: route('backoffice.licenses.index'), match: ['backoffice.licenses.*'], icon: KeyRoundIcon },
    { label: 'Categorías de licencia', href: route('backoffice.license-categories.index'), match: ['backoffice.license-categories.*'], icon: LayersIcon },
    { label: 'Indicadores de IVA', href: route('backoffice.tax-rates.index'), match: ['backoffice.tax-rates.*'], icon: PercentIcon },
];

function isCurrent(patterns) {
    return patterns.some((pattern) => route().current(pattern));
}

// Hasta 1024px (una tablet, incluso en horizontal) la barra lateral le quita
// al contenido el espacio que las tablas necesitan: pasa a ser un panel que
// se abre con el botón de menú. Se cierra al navegar, al tocar fuera de él o
// con Escape.
const navOpen = ref(false);

watch(() => page.url, () => {
    navOpen.value = false;
});

function closeOnEscape(event) {
    if (event.key === 'Escape') navOpen.value = false;
}

onMounted(() => window.addEventListener('keydown', closeOnEscape));
onBeforeUnmount(() => window.removeEventListener('keydown', closeOnEscape));
</script>

<template>
    <div class="backoffice-shell" :class="{ 'nav-open': navOpen }">
        <aside id="backoffice-nav" class="sidebar">
            <div class="sidebar-brand">
                <span class="brand-mark">C</span>
                <span class="brand-name">CONTAPP</span>
                <span class="brand-tag">Backoffice</span>
                <button type="button" class="nav-close" aria-label="Cerrar menú" @click="navOpen = false"><XIcon :size="18" /></button>
            </div>

            <nav class="sidebar-nav">
                <Link
                    v-for="item in nav"
                    :key="item.label"
                    :href="item.href"
                    class="sidebar-link"
                    :class="{ active: isCurrent(item.match) }"
                >
                    <span class="sidebar-icon"><component :is="item.icon" :size="18" /></span>
                    <span class="sidebar-label">{{ item.label }}</span>
                </Link>
            </nav>
        </aside>

        <div class="nav-backdrop" @click="navOpen = false" />

        <div class="backoffice-main">
            <header class="topbar">
                <div class="topbar-heading">
                    <button
                        type="button"
                        class="nav-toggle"
                        aria-controls="backoffice-nav"
                        :aria-expanded="navOpen"
                        aria-label="Abrir menú"
                        @click="navOpen = true"
                    >
                        <MenuIcon :size="20" />
                    </button>

                    <h1 class="topbar-title">{{ title }}</h1>
                </div>

                <!-- Igual en todas las pantallas (CLAUDE.md secc. 24): lo propio de
                     cada una va en su .view-toolbar, arriba de la tabla. -->
                <div class="topbar-actions">
                    <Link
                        v-if="page.props.propietario"
                        :href="route('backoffice.logout')"
                        method="delete"
                        as="button"
                        class="btn btn-ghost"
                    >
                        <LogOutIcon /> Salir
                    </Link>
                </div>
            </header>

            <div v-if="page.props.flash?.success" class="flash flash-success">
                {{ page.props.flash.success }}
            </div>
            <div v-if="page.props.flash?.error" class="flash flash-error">
                {{ page.props.flash.error }}
            </div>

            <main class="content">
                <slot />
            </main>
        </div>
    </div>
</template>

<style scoped>
/*
    100dvh después de 100vh: en el navegador de un teléfono, 100vh incluye el
    área que tapa la barra de direcciones y el final de la página quedaría
    debajo de ella. El navegador que no entiende dvh se queda con vh.
*/
.backoffice-shell {
    display: grid;
    grid-template-columns: var(--sidebar-width) 1fr;
    height: 100vh;
    height: 100dvh;
}

.sidebar {
    background: var(--color-sidebar);
    color: var(--color-on-sidebar);
    display: flex;
    flex-direction: column;
    padding: 0.75rem 0.6rem;
    height: 100vh;
    height: 100dvh;
    overflow-y: auto;
}

.sidebar-brand {
    display: flex;
    align-items: baseline;
    gap: 0.5rem;
    padding: 0.5rem 0.4rem 1.25rem;
}

.brand-mark {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    border-radius: 8px;
    background: rgba(255, 255, 255, 0.12);
    font-weight: 800;
}

.brand-name { font-weight: 700; letter-spacing: .03em; }
.brand-tag { font-size: 0.72rem; color: rgba(244, 246, 250, 0.6); }

.sidebar-nav {
    display: flex;
    flex-direction: column;
    gap: 0.15rem;
    flex: 1;
}

.sidebar-link {
    display: flex;
    align-items: center;
    gap: 0.65rem;
    padding: 0.55rem 0.6rem;
    border-radius: var(--radius-sm);
    text-decoration: none;
    color: rgba(244, 246, 250, 0.82);
    font-size: 0.86rem;
    font-weight: 600;
}

.sidebar-link:hover { background: rgba(255, 255, 255, 0.08); color: var(--color-on-sidebar); }
.sidebar-link.active { background: rgba(255, 255, 255, 0.16); color: var(--color-on-sidebar); }
.sidebar-icon { display: inline-flex; align-items: center; justify-content: center; width: 1.25rem; flex-shrink: 0; }

.backoffice-main {
    display: flex;
    flex-direction: column;
    min-width: 0;
    height: 100vh;
    height: 100dvh;
    overflow: hidden;
}

/*
    min-height y flex-wrap en vez de una altura fija: si el título y las
    acciones no caben en una línea (un teléfono), las acciones bajan a una
    segunda en vez de encimarse. Con espacio de sobra se ve igual que antes.
*/
.topbar {
    min-height: var(--topbar-height);
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 0.5rem 0.75rem;
    padding: 0.5rem 1rem;
    background: var(--color-surface);
    border-bottom: 1px solid var(--color-border);
    z-index: 10;
}

.topbar-heading {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    flex: 1 1 auto;
    min-width: 0;
}

.topbar-title {
    font-size: 1rem;
    font-weight: 700;
    margin: 0;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.topbar-actions {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 0.5rem;
    margin-left: auto;
}


/* position: relative por lo mismo que en AppLayout.vue: lo que lleve
   `position: absolute` adentro (.sr-only) se desplaza con el contenido en vez
   de agregarle a la página un segundo desplazamiento. */
.content { position: relative; padding: 1.25rem; flex: 1; overflow-y: auto; overflow-x: auto; }

.flash {
    flex-shrink: 0;
    margin: 0.75rem 1.25rem 0;
    padding: 0.6rem 0.9rem;
    border-radius: var(--radius-sm);
    font-size: 0.85rem;
}

.flash-success { background: var(--color-success-soft); color: var(--color-success); }
.flash-error { background: var(--color-danger-soft); color: var(--color-danger); }

/* Solo existen en pantallas angostas (ver la media query de abajo). */
.nav-toggle,
.nav-close,
.nav-backdrop {
    display: none;
}

.nav-toggle {
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    width: 36px;
    height: 36px;
    margin-left: -0.35rem;
    padding: 0;
    border: none;
    border-radius: var(--radius-sm);
    background: transparent;
    color: var(--color-text);
    cursor: pointer;
}

.nav-toggle:hover { background: var(--color-surface-alt); }

.nav-close {
    align-items: center;
    justify-content: center;
    align-self: center;
    width: 32px;
    height: 32px;
    margin-left: auto;
    padding: 0;
    border: none;
    border-radius: var(--radius-sm);
    background: transparent;
    color: inherit;
    cursor: pointer;
}

.nav-close:hover { background: rgba(255, 255, 255, 0.08); }

@media (max-width: 1024px) {
    .backoffice-shell {
        grid-template-columns: minmax(0, 1fr);
    }

    /*
        Cerrado, el panel queda fuera de la pantalla Y oculto (visibility):
        solo con el transform, sus enlaces seguirían recibiendo el foco con
        Tab aunque no se vean. visibility cambia recién al terminar la
        animación de cierre, para que el panel no desaparezca de golpe.
    */
    .sidebar {
        position: fixed;
        inset: 0 auto 0 0;
        z-index: 40;
        width: min(var(--sidebar-width), 85vw);
        box-shadow: var(--shadow-md);
        transform: translateX(-100%);
        visibility: hidden;
        transition: transform .2s ease, visibility 0s linear .2s;
    }

    .nav-open .sidebar {
        transform: none;
        visibility: visible;
        transition: transform .2s ease;
    }

    .nav-open .nav-backdrop {
        display: block;
        position: fixed;
        inset: 0;
        z-index: 30;
        background: rgba(0, 0, 0, 0.4);
    }

    .nav-toggle,
    .nav-close {
        display: inline-flex;
    }
}

@media (max-width: 640px) {
    .topbar { padding: 0.5rem 0.75rem; }
    .content { padding: 0.75rem; }
    .flash { margin: 0.75rem 0.75rem 0; }
}

@media (prefers-reduced-motion: reduce) {
    .sidebar,
    .nav-open .sidebar {
        transition: none;
    }
}
</style>
