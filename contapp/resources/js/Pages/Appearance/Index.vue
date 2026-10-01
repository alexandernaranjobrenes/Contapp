<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import { CheckIcon, PaletteIcon, Undo2Icon } from '@lucide/vue';

/**
 * Administración → Apariencia (CLAUDE.md secc. 31): el tema visual de la
 * compañía. Tocar un tema lo aplica a toda la pantalla como vista previa
 * —para verlo con datos reales, no solo en la tarjeta—; «Guardar tema» lo
 * deja para todos los que trabajan en la compañía. Salir sin guardar
 * devuelve el tema guardado (lo hace app.js al navegar).
 */
const props = defineProps({
    themes: { type: Array, required: true },
    current: { type: String, required: true },
    companyName: { type: String, required: true },
});

const selected = ref(props.current);
const form = useForm({ theme: props.current });

const changed = computed(() => selected.value !== props.current);
const selectedTheme = computed(() => props.themes.find((t) => t.key === selected.value));

function preview(key) {
    selected.value = key;
    document.documentElement.dataset.companyTheme = key;
}

function discard() {
    preview(props.current);
}

function save() {
    form.theme = selected.value;
    form.put(route('appearance.update'), { preserveScroll: true });
}

// Si la página se desmonta sin guardar (otra navegación), app.js repone el
// tema guardado; esto cubre el caso en que la vista previa quedara puesta.
onBeforeUnmount(() => {
    if (changed.value && ! form.processing) {
        document.documentElement.dataset.companyTheme = props.current;
    }
});
</script>

<template>
    <Head title="Apariencia" />

    <AppLayout title="Apariencia">
        <div class="view-toolbar">
            <div class="view-actions">
                <button v-if="changed" type="button" class="btn btn-ghost" @click="discard">
                    <Undo2Icon /> Descartar vista previa
                </button>
                <button type="button" class="btn btn-primary" :disabled="!changed || form.processing" @click="save">
                    <CheckIcon /> Guardar tema
                </button>
            </div>
        </div>

        <p class="hint">
            El tema cambia los colores, la tipografía y las esquinas de CONTAPP para todos los que trabajan en
            <strong>{{ companyName }}</strong>. Tocá un tema para verlo aplicado en toda la pantalla, y guardalo con
            «Guardar tema». Cada tema tiene su versión clara y oscura: el botón de la luna, arriba, cambia entre
            las dos. Los colores de estado —verde, rojo, ámbar— no cambian: significan lo mismo en todas las
            compañías.
        </p>

        <p v-if="changed" class="flash flash-info preview-note">
            <PaletteIcon /> Estás viendo <strong>{{ selectedTheme?.label }}</strong> como vista previa. Todavía no se guardó.
        </p>

        <div class="theme-grid" role="radiogroup" aria-label="Tema de la compañía">
            <button
                v-for="theme in themes"
                :key="theme.key"
                type="button"
                role="radio"
                class="theme-card"
                :class="{ selected: selected === theme.key }"
                :aria-checked="selected === theme.key"
                :data-company-theme="theme.key"
                @click="preview(theme.key)"
            >
                <!-- Una miniatura de CONTAPP pintada con el tema: la barra lateral
                     con su acento, una tarjeta con cifras y el botón primario. -->
                <span class="mini" aria-hidden="true">
                    <span class="mini-sidebar">
                        <span class="mini-brand">C</span>
                        <span class="mini-nav active"></span>
                        <span class="mini-nav"></span>
                        <span class="mini-nav"></span>
                        <span class="mini-nav short"></span>
                    </span>
                    <span class="mini-main">
                        <span class="mini-topbar"><span class="mini-title">Balance general</span></span>
                        <span class="mini-card">
                            <span class="mini-label">Total activo</span>
                            <span class="mini-value">₡ 70 411 960</span>
                            <span class="mini-row"><span class="mini-line"></span><span class="mini-badge">Vigente</span></span>
                            <span class="mini-button">Crear nuevo</span>
                        </span>
                    </span>
                </span>

                <span class="theme-meta">
                    <span class="theme-name">
                        {{ theme.label }}
                        <span v-if="theme.key === current" class="in-use">En uso</span>
                        <span v-if="selected === theme.key" class="check"><CheckIcon :size="14" :stroke-width="3" /></span>
                    </span>
                    <span class="theme-description">{{ theme.description }}</span>
                    <span class="theme-font">Tipografía: {{ theme.font }}</span>
                </span>
            </button>
        </div>
    </AppLayout>
</template>

<style scoped>
.preview-note { display: flex; align-items: center; gap: 0.5rem; }

.theme-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(min(100%, 16rem), 1fr));
    gap: 1rem;
}

/* La tarjeta entera se pinta con su tema: data-company-theme le pone sus
   colores, su tipografía y sus esquinas. */
.theme-card {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
    padding: 0.75rem;
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    background: var(--color-surface);
    color: var(--color-text);
    font: inherit;
    font-family: var(--font-sans);
    text-align: left;
    cursor: pointer;
    box-shadow: var(--shadow-sm);
    transition: transform 0.12s ease, box-shadow 0.12s ease, border-color 0.12s ease;
}

.theme-card:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-md);
}

.theme-card:focus-visible {
    outline: 2px solid var(--color-primary);
    outline-offset: 2px;
}

.theme-card.selected {
    border-color: var(--color-primary);
    box-shadow: 0 0 0 2px var(--color-primary), var(--shadow-md);
}

/* ── Miniatura ────────────────────────────────────────────────────────── */

.mini {
    display: flex;
    height: 8.5rem;
    overflow: hidden;
    border-radius: var(--radius-sm);
    border: 1px solid var(--color-border);
    background: var(--color-bg);
}

.mini-sidebar {
    display: flex;
    flex-direction: column;
    gap: 0.35rem;
    width: 3.1rem;
    padding: 0.45rem 0.4rem;
    background: var(--color-sidebar);
}

.mini-brand {
    display: grid;
    place-items: center;
    width: 1.2rem;
    height: 1.2rem;
    margin-bottom: 0.2rem;
    border-radius: 4px;
    background: rgba(255, 255, 255, 0.14);
    color: var(--color-on-sidebar);
    font-size: 0.6rem;
    font-weight: 800;
}

.mini-nav {
    height: 0.35rem;
    border-radius: 3px;
    background: rgba(255, 255, 255, 0.22);
}

.mini-nav.active {
    background: var(--color-accent);
}

.mini-nav.short { width: 60%; }

.mini-main {
    flex: 1;
    display: flex;
    flex-direction: column;
    min-width: 0;
}

.mini-topbar {
    display: flex;
    align-items: center;
    height: 1.35rem;
    padding: 0 0.5rem;
    background: var(--color-surface);
    border-bottom: 1px solid var(--color-border);
}

.mini-title {
    font-size: 0.62rem;
    font-weight: 700;
    color: var(--color-text);
    white-space: nowrap;
}

.mini-card {
    display: flex;
    flex-direction: column;
    gap: 0.2rem;
    margin: 0.45rem;
    padding: 0.45rem 0.5rem;
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    background: var(--color-surface);
}

.mini-label {
    font-size: 0.52rem;
    color: var(--color-text-muted);
}

.mini-value {
    font-size: 0.82rem;
    font-weight: 700;
    color: var(--color-text);
    white-space: nowrap;
    font-variant-numeric: tabular-nums;
}

.mini-row {
    display: flex;
    align-items: center;
    gap: 0.35rem;
}

.mini-line {
    flex: 1;
    height: 0.3rem;
    border-radius: 3px;
    background: var(--color-surface-alt);
}

.mini-badge {
    padding: 0.05rem 0.3rem;
    border-radius: 999px;
    background: var(--color-success-soft);
    color: var(--color-success);
    font-size: 0.45rem;
    font-weight: 700;
    text-transform: uppercase;
}

.mini-button {
    align-self: flex-end;
    margin-top: 0.15rem;
    padding: 0.18rem 0.45rem;
    border-radius: var(--radius-sm);
    background: var(--color-primary);
    color: var(--color-on-primary);
    font-size: 0.52rem;
    font-weight: 700;
}

/* ── Datos del tema ───────────────────────────────────────────────────── */

.theme-meta {
    display: flex;
    flex-direction: column;
    gap: 0.2rem;
}

.theme-name {
    display: flex;
    align-items: center;
    gap: 0.45rem;
    font-size: 1rem;
    font-weight: 700;
}

.in-use {
    padding: 0.1rem 0.45rem;
    border: 1px solid var(--color-border);
    border-radius: 999px;
    background: var(--color-surface-alt);
    color: var(--color-text);
    font-size: 0.64rem;
    font-weight: 700;
    letter-spacing: 0.04em;
    text-transform: uppercase;
}

.check {
    display: inline-grid;
    place-items: center;
    width: 1.3rem;
    height: 1.3rem;
    margin-left: auto;
    border-radius: 50%;
    background: var(--color-primary);
    color: var(--color-on-primary);
}

.theme-description {
    font-size: 0.8rem;
    color: var(--color-text-muted);
}

.theme-font {
    font-size: 0.74rem;
    color: var(--color-text-muted);
}

@media (prefers-reduced-motion: reduce) {
    .theme-card { transition: none; }
    .theme-card:hover { transform: none; }
}
</style>
