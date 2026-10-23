<script setup>
import { watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { sessionChanged, startup } from '../Utils/startup';

/**
 * La entrada al sistema al iniciar sesión (Utils/startup.js): el logo de
 * Pivox mientras arranca la primera pantalla, y un saludo al terminar. Va
 * montada al lado de la página en app.js, como CompanySwitchHost.
 *
 * Vigila quién tiene la sesión. El watch corre en el mismo ciclo en que se
 * pinta la página nueva, antes de que el navegador la muestre: la ventana
 * sale en el mismo cuadro que la pantalla de destino, que nunca se ve antes
 * del logo. Por eso aparece sin fundido; solo se va con uno.
 */
const page = usePage();

watch(
    [() => page.props.auth?.user?.id ?? null, () => page.props.propietario?.id ?? null],
    (now, before) => sessionChanged(page.props, now, before),
);
</script>

<template>
    <Teleport to="body">
        <Transition name="startup">
            <div
                v-if="startup.visible"
                class="startup"
                :class="`is-${startup.status}`"
                :data-company-theme="startup.theme"
                role="status"
                aria-live="polite"
                :aria-busy="startup.status === 'loading'"
            >
                <div class="startup-stage">
                    <div class="startup-mark" :class="{ done: startup.status !== 'loading' }">
                        <span class="startup-ring" aria-hidden="true"></span>
                        <span class="startup-tile"><img src="/images/logos/logo_pivox_corto.png" alt=""></span>
                    </div>

                    <img class="startup-wordmark" src="/images/logos/pivox_nombre_blanco.png" alt="Pivox">

                    <div class="startup-message">
                        <Transition name="startup-swap">
                            <p v-if="startup.status === 'loading'" class="startup-caption">Iniciando sesión</p>
                            <div v-else class="startup-welcome">
                                <p class="startup-greeting">¡Hola{{ startup.name ? `, ${startup.name}` : '' }}!</p>
                                <p v-if="startup.entering" class="startup-entering">{{ startup.entering }}</p>
                            </div>
                        </Transition>
                    </div>

                    <div class="startup-progress" :class="{ full: startup.status !== 'loading' }">
                        <span class="startup-progress-bar"></span>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
.startup {
    position: fixed;
    inset: 0;
    z-index: 300;
    isolation: isolate;
    display: grid;
    place-items: center;
    padding: 1.5rem;
    overflow: hidden;
    background:
        radial-gradient(circle at 50% 42%, color-mix(in srgb, var(--color-accent) 18%, transparent), transparent 58%),
        linear-gradient(165deg, transparent 45%, rgba(0, 0, 0, 0.25)),
        var(--color-sidebar);
    color: var(--color-on-sidebar);
}

/* La retícula de libro contable del panel de acceso (AuthShell),
   desvanecida hacia los bordes: la entrada sigue la pantalla de la que viene. */
.startup::before {
    content: '';
    position: absolute;
    inset: 0;
    z-index: -1;
    background-image:
        linear-gradient(rgba(255, 255, 255, 0.05) 1px, transparent 1px),
        linear-gradient(90deg, rgba(255, 255, 255, 0.05) 1px, transparent 1px);
    background-size: 36px 36px;
    -webkit-mask-image: radial-gradient(ellipse 60% 55% at 50% 45%, #000 15%, transparent 70%);
    mask-image: radial-gradient(ellipse 60% 55% at 50% 45%, #000 15%, transparent 70%);
    animation: startup-grid 1.2s ease-out both;
}

/* Solo la salida tiene fundido (ver el comentario de arriba). */
.startup-leave-active {
    transition: opacity 0.4s ease;
}

.startup-leave-to {
    opacity: 0;
}

.startup-stage {
    display: flex;
    flex-direction: column;
    align-items: center;
    max-width: 36rem;
    text-align: center;
}

.is-leaving .startup-stage {
    animation: startup-out 0.4s ease both;
}

/* El ícono sobre blanco, como en la barra lateral, dentro de un anillo que
   gira mientras carga. Al terminar, el anillo se completa y se expande como
   una onda. */
.startup-mark {
    position: relative;
    display: grid;
    place-items: center;
    width: 5.5rem;
    height: 5.5rem;
    margin-bottom: 1.6rem;
    animation: startup-pop 0.7s cubic-bezier(0.2, 0.9, 0.25, 1.2) both;
}

.startup-tile {
    display: grid;
    place-items: center;
    width: 100%;
    height: 100%;
    border-radius: 24px;
    background: #fff;
    box-shadow: 0 18px 40px -14px rgba(0, 0, 0, 0.55);
    outline: 2px solid transparent;
    outline-offset: 5px;
    transition: outline-color 0.4s ease;
}

.startup-mark.done .startup-tile {
    outline-color: var(--color-accent);
}

.startup-tile img {
    width: 3.4rem;
    height: 3.4rem;
    object-fit: contain;
    animation: startup-icon 0.8s 0.15s cubic-bezier(0.2, 0.8, 0.2, 1) both;
}

.startup-ring {
    position: absolute;
    inset: -16px;
    border-radius: 50%;
    border: 2px solid rgba(255, 255, 255, 0.10);
    border-top-color: var(--color-accent);
    animation: startup-fade-in 0.4s 0.3s both, startup-spin 0.9s linear infinite;
}

.startup-mark.done .startup-ring {
    border-color: var(--color-accent);
    animation: startup-ripple 0.7s ease-out both;
}

/* El nombre se escribe de izquierda a derecha. */
.startup-wordmark {
    display: block;
    width: auto;
    height: 2.3rem;
    animation: startup-write 0.8s 0.25s cubic-bezier(0.2, 0.8, 0.2, 1) both;
}

/* «Iniciando sesión» y el saludo ocupan el mismo lugar: uno da paso al otro
   sin que se mueva lo de abajo. */
.startup-message {
    display: grid;
    place-items: center;
    min-height: 3.7rem;
    margin-top: 1.1rem;
}

.startup-message > * {
    grid-area: 1 / 1;
}

.startup-caption {
    margin: 0;
    font-size: 0.74rem;
    font-weight: 700;
    letter-spacing: 0.14em;
    text-transform: uppercase;
    color: rgba(244, 246, 250, 0.6);
    animation: startup-fade-in 0.5s 0.45s both;
}

.startup-greeting {
    margin: 0;
    font-size: clamp(1.3rem, 3vw, 1.6rem);
    font-weight: 800;
    line-height: 1.2;
}

.startup-entering {
    margin: 0.3rem 0 0;
    font-size: 0.9rem;
    color: rgba(244, 246, 250, 0.7);
    overflow-wrap: anywhere;
}

.startup-swap-enter-active,
.startup-swap-leave-active {
    transition: opacity 0.3s ease, transform 0.35s cubic-bezier(0.2, 0.8, 0.2, 1);
}

.startup-swap-enter-from {
    opacity: 0;
    transform: translateY(8px);
}

.startup-swap-leave-to {
    opacity: 0;
    transform: translateY(-6px);
}

/* Progreso indeterminado mientras carga; lleno al terminar. */
.startup-progress {
    position: relative;
    width: min(14rem, 60vw);
    height: 3px;
    margin-top: 1.1rem;
    overflow: hidden;
    border-radius: 3px;
    background: rgba(255, 255, 255, 0.14);
    animation: startup-fade-in 0.5s 0.5s both;
}

.startup-progress-bar {
    position: absolute;
    inset: 0 auto 0 0;
    width: 40%;
    border-radius: 3px;
    background: var(--color-accent);
    animation: startup-indeterminate 1.1s ease-in-out infinite;
}

.startup-progress.full .startup-progress-bar {
    width: 100%;
    animation: none;
    transform: none;
    transition: width 0.3s ease;
}

@media (max-width: 480px) {
    .startup-mark {
        width: 4.6rem;
        height: 4.6rem;
    }

    .startup-tile {
        border-radius: 20px;
    }

    .startup-tile img {
        width: 2.8rem;
        height: 2.8rem;
    }

    .startup-wordmark {
        height: 1.9rem;
    }
}

@keyframes startup-grid {
    from { opacity: 0; transform: scale(1.06); }
    to { opacity: 1; transform: none; }
}

@keyframes startup-pop {
    from { opacity: 0; transform: scale(0.6); }
    to { opacity: 1; transform: none; }
}

@keyframes startup-icon {
    from { opacity: 0; transform: translateX(-10px) scale(0.85); }
    to { opacity: 1; transform: none; }
}

@keyframes startup-write {
    from { opacity: 0; clip-path: inset(0 100% 0 0); transform: translateY(6px); }
    to { opacity: 1; clip-path: inset(0 0 0 0); transform: none; }
}

@keyframes startup-fade-in {
    from { opacity: 0; }
    to { opacity: 1; }
}

@keyframes startup-spin {
    to { transform: rotate(360deg); }
}

@keyframes startup-ripple {
    from { opacity: 1; transform: scale(1); }
    to { opacity: 0; transform: scale(1.25); }
}

@keyframes startup-indeterminate {
    0% { transform: translateX(-100%); }
    100% { transform: translateX(250%); }
}

@keyframes startup-out {
    to { opacity: 0; transform: scale(1.04); }
}

/* Sin animaciones para quien las pidió apagadas: la entrada igual saluda,
   solo que sin movimiento (y más corta, ver startup.js). */
@media (prefers-reduced-motion: reduce) {
    .startup::before,
    .startup-mark,
    .startup-tile img,
    .startup-ring,
    .startup-mark.done .startup-ring,
    .startup-wordmark,
    .startup-caption,
    .startup-progress,
    .startup-progress-bar,
    .is-leaving .startup-stage {
        animation: none;
    }

    .startup-progress-bar {
        width: 100%;
        opacity: 0.5;
    }

    .startup-swap-enter-active,
    .startup-swap-leave-active {
        transition: none;
    }
}
</style>
