<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

/**
 * El marco de las pantallas de acceso: login, activación de licencia y login
 * del backoffice.
 *
 * En escritorio, dos mitades: la marca a la izquierda —fondo profundo con
 * una retícula de libro contable y un brillo del color de acento— y el
 * formulario a la derecha, en una tarjeta. En un teléfono la marca se vuelve
 * un encabezado compacto y la tarjeta se monta un poco sobre él.
 *
 * No hay compañía todavía, así que usa el tema predeterminado (Marino).
 */
defineProps({
    // Plano operativo (Superusuario/Administrador/Usuario) vs. backoffice
    // del Propietario — visualmente distintos a propósito (CLAUDE.md secc.
    // 11: nunca deben confundirse, son dos puertas de entrada distintas).
    dark: { type: Boolean, default: false },
    // El titular grande del panel de marca.
    subtitle: { type: String, default: '' },
    // Un párrafo corto debajo del titular.
    lead: { type: String, default: '' },
    // Activate.vue trae más campos (compañía + usuario en un solo formulario)
    // que un login de 2 campos — necesita más ancho para su grilla de 2 columnas.
    wide: { type: Boolean, default: false },
});

const page = usePage();
const year = new Date().getFullYear();
const flash = computed(() => page.props.flash ?? {});
</script>

<template>
    <div class="auth-shell" :class="{ 'is-dark': dark, 'is-wide': wide }">
        <aside class="auth-panel">
            <div class="auth-brand">
                <span class="brand-mark">C</span>
                <span class="brand-name">CONTAPP</span>
            </div>

            <div class="auth-panel-body">
                <h1 class="auth-headline">{{ subtitle }}</h1>
                <p v-if="lead" class="auth-lead">{{ lead }}</p>

                <div class="auth-panel-extra">
                    <slot name="panel-extra" />
                </div>
            </div>

            <p class="auth-panel-footer">
                © {{ year }} CONTAPP · Hecho por
                <a href="https://ncodedigital.com" target="_blank" rel="noopener">NcodeDigital</a>
            </p>
        </aside>

        <main class="auth-form-side">
            <div class="auth-card card">
                <p v-if="flash.success" class="flash flash-success auth-flash" role="status">{{ flash.success }}</p>
                <p v-if="flash.error" class="flash flash-error auth-flash" role="alert">{{ flash.error }}</p>

                <slot />
            </div>

            <div v-if="$slots.below" class="auth-below">
                <slot name="below" />
            </div>
        </main>
    </div>
</template>

<style scoped>
.auth-shell {
    min-height: 100vh;
    min-height: 100dvh;
    display: grid;
    grid-template-columns: minmax(0, 0.95fr) minmax(0, 1.05fr);
    background-color: var(--color-bg);
    background-image: var(--marble-texture);
    /* El color de los enlaces del formulario. El primario solo no alcanza:
       en modo oscuro es un tono medio que casi no se lee sobre la tarjeta. */
    --auth-link: color-mix(in srgb, var(--color-primary) 55%, var(--color-text));
}

/* ── Panel de marca ───────────────────────────────────────────────────── */

.auth-panel {
    position: relative;
    isolation: isolate;
    display: flex;
    flex-direction: column;
    gap: 2rem;
    padding: 2.5rem clamp(2rem, 5vw, 4.5rem);
    overflow: hidden;
    color: var(--color-on-sidebar);
    background-color: var(--color-sidebar);
    background-image:
        radial-gradient(38rem 28rem at 12% 8%, color-mix(in srgb, var(--color-accent) 22%, transparent), transparent 60%),
        radial-gradient(30rem 24rem at 95% 100%, rgba(255, 255, 255, 0.07), transparent 60%),
        linear-gradient(165deg, transparent 40%, rgba(0, 0, 0, 0.28));
}

/* La retícula de un libro contable, desvanecida hacia los bordes. */
.auth-panel::before {
    content: '';
    position: absolute;
    inset: 0;
    z-index: -1;
    background-image:
        linear-gradient(rgba(255, 255, 255, 0.05) 1px, transparent 1px),
        linear-gradient(90deg, rgba(255, 255, 255, 0.05) 1px, transparent 1px);
    background-size: 36px 36px;
    -webkit-mask-image: radial-gradient(ellipse 80% 70% at 35% 45%, #000 20%, transparent 75%);
    mask-image: radial-gradient(ellipse 80% 70% at 35% 45%, #000 20%, transparent 75%);
}

.auth-shell.is-dark .auth-panel {
    /* Deliberadamente distinto al de la marca: esta puerta es exclusiva del
       fabricante de CONTAPP, nunca de un cliente, así que no debe poder
       confundirse con el plano operativo. Grafito con acento ámbar. */
    background-color: #14171c;
    background-image:
        radial-gradient(38rem 28rem at 12% 8%, rgba(240, 180, 41, 0.14), transparent 60%),
        linear-gradient(165deg, transparent 40%, rgba(0, 0, 0, 0.35));
    color: #eef0f3;
}

.auth-brand {
    display: flex;
    align-items: center;
    gap: 0.7rem;
}

.brand-mark {
    display: inline-grid;
    place-items: center;
    width: 42px;
    height: 42px;
    border-radius: 12px;
    background: rgba(255, 255, 255, 0.12);
    box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.14);
    font-weight: 800;
    font-size: 1.15rem;
}

.brand-name {
    font-size: 1.3rem;
    font-weight: 800;
    letter-spacing: 0.04em;
}

.auth-panel-body {
    flex: 1;
    display: flex;
    flex-direction: column;
    justify-content: center;
    max-width: 36rem;
}

.auth-headline {
    margin: 0;
    font-size: clamp(1.9rem, 3.2vw, 2.7rem);
    font-weight: 800;
    line-height: 1.12;
    letter-spacing: -0.015em;
    /* Líneas parejas: sin una palabra sola en la última. */
    text-wrap: balance;
}

.auth-lead {
    margin: 1rem 0 0;
    font-size: 1rem;
    line-height: 1.6;
    color: rgba(244, 246, 250, 0.72);
}

.auth-panel-extra {
    margin-top: 2.25rem;
}

.auth-panel-footer {
    margin: 0;
    font-size: 0.78rem;
    color: rgba(244, 246, 250, 0.5);
}

.auth-panel-footer a {
    color: rgba(244, 246, 250, 0.78);
    font-weight: 600;
    text-decoration: none;
}

.auth-panel-footer a:hover,
.auth-panel-footer a:focus-visible {
    color: var(--color-on-sidebar);
    text-decoration: underline;
}

/* ── Formulario ───────────────────────────────────────────────────────── */

.auth-form-side {
    /* Posicionado para quedar por encima del panel de marca, que también lo
       está: en el teléfono la tarjeta se monta sobre el encabezado. */
    position: relative;
    z-index: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 1.25rem;
    padding: 2.5rem 1.5rem;
}

.auth-card {
    width: 100%;
    max-width: 440px;
    padding: 2.5rem 2.25rem;
    border-radius: calc(var(--radius-md) + 4px);
    box-shadow: 0 1px 2px rgba(11, 31, 58, 0.04), 0 18px 48px -12px rgba(11, 31, 58, 0.18);
}

.auth-shell.is-wide .auth-card {
    max-width: 600px;
}

.auth-flash {
    margin: 0 0 1.25rem;
}

.auth-below {
    width: 100%;
    max-width: 440px;
    text-align: center;
    font-size: 0.8rem;
    color: var(--color-text-muted);
}

.auth-shell.is-wide .auth-below {
    max-width: 600px;
}

/* ── Piezas que las pantallas ponen dentro del marco ──────────────────────
   Van acá, y no repetidas en cada pantalla, porque son las mismas en todas:
   el encabezado del formulario, su botón, el enlace del pie, los pasos
   numerados del panel de marca y el ícono de un estado («revisá tu correo»,
   «este enlace ya no sirve»). */

:slotted(.auth-head) {
    margin-bottom: 1.5rem;
}

:slotted(.auth-title) {
    margin: 0;
    font-size: 1.5rem;
    font-weight: 800;
    letter-spacing: -0.01em;
}

:slotted(.auth-title:focus) {
    outline: none;
}

:slotted(.auth-subtitle) {
    margin: 0.35rem 0 0;
    font-size: 0.9rem;
    line-height: 1.5;
    color: var(--color-text-muted);
}

:slotted(.auth-error) {
    margin: 0 0 1rem;
}

:slotted(.auth-submit) {
    width: 100%;
    min-height: 2.9rem;
    justify-content: center;
    font-size: 0.95rem;
}

:slotted(.auth-alt) {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: center;
    gap: 0.25rem 0.4rem;
    margin-top: 1.5rem;
    padding-top: 1.25rem;
    border-top: 1px solid var(--color-border);
    font-size: 0.85rem;
    color: var(--color-text-muted);
}

:slotted(.auth-alt-link) {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    color: var(--auth-link);
    font-weight: 700;
    text-decoration: none;
}

:slotted(.auth-alt-link:hover) {
    text-decoration: underline;
}

:slotted(.auth-steps) {
    list-style: none;
    padding: 0;
    margin: 0;
    display: flex;
    flex-direction: column;
    gap: 0.9rem;
    font-size: 0.9rem;
    line-height: 1.45;
    color: rgba(244, 246, 250, 0.8);
}

:slotted(.auth-steps li) {
    display: flex;
    align-items: center;
    gap: 0.8rem;
}

:slotted(.auth-step-number) {
    flex-shrink: 0;
    display: inline-grid;
    place-items: center;
    width: 2rem;
    height: 2rem;
    border-radius: 50%;
    background: color-mix(in srgb, var(--color-accent) 20%, transparent);
    color: var(--color-accent);
    font-weight: 800;
    font-size: 0.85rem;
}

:slotted(.auth-state-icon) {
    display: inline-grid;
    place-items: center;
    width: 3.25rem;
    height: 3.25rem;
    margin-bottom: 1rem;
    border-radius: 50%;
    background: var(--color-success-soft);
    color: var(--color-success);
}

:slotted(.auth-state-icon.is-warning) {
    background: var(--color-warning-soft);
    color: var(--color-warning);
}

/* ── Tablet y teléfono ────────────────────────────────────────────────── */

@media (max-width: 860px) {
    /* La marca pasa a ser un encabezado; el formulario, debajo, se monta
       un poco sobre él. */
    .auth-shell {
        grid-template-columns: minmax(0, 1fr);
        grid-template-rows: auto 1fr;
    }

    .auth-panel {
        gap: 1rem;
        padding: 1.5rem 1.5rem 3.25rem;
    }

    .auth-panel-body {
        max-width: none;
    }

    .auth-headline {
        font-size: 1.35rem;
        line-height: 1.25;
    }

    .auth-lead {
        margin-top: 0.4rem;
        font-size: 0.9rem;
    }

    /* Los beneficios y el pie se quedan en escritorio: en un teléfono lo
       que importa es llegar al formulario. */
    .auth-panel-extra,
    .auth-panel-footer {
        display: none;
    }

    .auth-form-side {
        justify-content: flex-start;
        margin-top: -2rem;
        padding: 0 1.25rem 2rem;
    }
}

@media (max-width: 480px) {
    .auth-panel {
        padding: 1.25rem 1rem 3rem;
    }

    .brand-mark {
        width: 36px;
        height: 36px;
        border-radius: 10px;
        font-size: 1rem;
    }

    .brand-name {
        font-size: 1.1rem;
    }

    .auth-form-side {
        padding: 0 0.75rem 1.5rem;
    }

    .auth-card {
        padding: 1.75rem 1.25rem;
    }
}
</style>
