<script setup>
import { Link, usePage } from '@inertiajs/vue3';

defineProps({
    title: { type: String, default: '' },
});

const page = usePage();

const nav = [
    { label: 'Licencias', href: route('backoffice.licenses.index'), match: ['backoffice.licenses.*'], icon: '🔑' },
    { label: 'Categorías de licencia', href: route('backoffice.license-categories.index'), match: ['backoffice.license-categories.*'], icon: '🗂' },
    { label: 'Indicadores de IVA', href: route('backoffice.tax-rates.index'), match: ['backoffice.tax-rates.*'], icon: '§' },
];

function isCurrent(patterns) {
    return patterns.some((pattern) => route().current(pattern));
}
</script>

<template>
    <div class="backoffice-shell">
        <aside class="sidebar">
            <div class="sidebar-brand">
                <span class="brand-mark">C</span>
                <span class="brand-name">CONTAPP</span>
                <span class="brand-tag">Backoffice</span>
            </div>

            <nav class="sidebar-nav">
                <Link
                    v-for="item in nav"
                    :key="item.label"
                    :href="item.href"
                    class="sidebar-link"
                    :class="{ active: isCurrent(item.match) }"
                >
                    <span class="sidebar-icon">{{ item.icon }}</span>
                    <span class="sidebar-label">{{ item.label }}</span>
                </Link>
            </nav>
        </aside>

        <div class="backoffice-main">
            <header class="topbar">
                <h1 class="topbar-title">{{ title }}</h1>

                <div class="topbar-actions">
                    <slot name="actions" />

                    <span v-if="page.props.propietario" class="topbar-user">{{ page.props.propietario.name }}</span>

                    <Link
                        v-if="page.props.propietario"
                        :href="route('backoffice.logout')"
                        method="delete"
                        as="button"
                        class="btn btn-ghost"
                    >
                        Salir
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
.backoffice-shell {
    display: grid;
    grid-template-columns: var(--sidebar-width) 1fr;
    height: 100vh;
}

.sidebar {
    background: var(--color-primary);
    color: var(--color-on-primary);
    display: flex;
    flex-direction: column;
    padding: 0.75rem 0.6rem;
    height: 100vh;
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

.sidebar-link:hover { background: rgba(255, 255, 255, 0.08); color: var(--color-on-primary); }
.sidebar-link.active { background: rgba(255, 255, 255, 0.16); color: var(--color-on-primary); }
.sidebar-icon { width: 1.2em; text-align: center; }

.backoffice-main {
    display: flex;
    flex-direction: column;
    min-width: 0;
    height: 100vh;
    overflow: hidden;
}

.topbar {
    height: var(--topbar-height);
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 1rem;
    background: var(--color-surface);
    border-bottom: 1px solid var(--color-border);
    z-index: 10;
}

.topbar-title { font-size: 1rem; font-weight: 700; margin: 0; }
.topbar-actions { display: flex; align-items: center; gap: 0.5rem; }
.topbar-user { font-size: 0.82rem; color: var(--color-text-muted); }

.content { padding: 1.25rem; flex: 1; overflow-y: auto; overflow-x: auto; }

.flash {
    flex-shrink: 0;
    margin: 0.75rem 1.25rem 0;
    padding: 0.6rem 0.9rem;
    border-radius: var(--radius-sm);
    font-size: 0.85rem;
}

.flash-success { background: var(--color-success-soft); color: var(--color-success); }
.flash-error { background: var(--color-danger-soft); color: var(--color-danger); }
</style>
