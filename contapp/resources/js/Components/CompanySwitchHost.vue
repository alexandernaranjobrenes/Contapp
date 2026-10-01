<script setup>
import { nextTick, ref, watch } from 'vue';
import { CheckIcon, LogOutIcon, RotateCcwIcon, TriangleAlertIcon, Undo2Icon } from '@lucide/vue';
import { companySwitch, logoutFromSwitch, retryCompanySwitch, stayInCurrentCompany } from '../Utils/companySwitch';

/**
 * La ventana del cambio de compañía (CLAUDE.md secc. 30). El estado y la
 * lógica están en Utils/companySwitch.js; esto solo la dibuja. Va montada al
 * lado de la página en app.js, para que siga en pantalla cuando el cambio
 * termina en otra página.
 *
 * Mientras carga no se puede cerrar ni tocar nada detrás: la página de atrás
 * todavía es de la compañía anterior, y trabajar en ella en ese momento es
 * justamente la confusión que esta ventana evita.
 */
const card = ref(null);

// Con un error, el foco va a la tarjeta, para que el teclado y el lector de
// pantalla lleguen a sus botones.
watch(() => companySwitch.status, async (status) => {
    if (status !== 'error') return;
    await nextTick();
    card.value?.querySelector('button')?.focus();
});
</script>

<template>
    <Teleport to="body">
        <Transition name="company-switch">
            <div
                v-if="companySwitch.visible"
                class="company-switch"
                :class="`is-${companySwitch.status}`"
                :data-company-theme="companySwitch.to?.theme"
                :role="companySwitch.status === 'error' ? null : 'dialog'"
                :aria-modal="companySwitch.status === 'error' ? null : 'true'"
                :aria-label="companySwitch.status === 'error' ? null : `Cambiando a ${companySwitch.to?.name}`"
            >
                <!-- Cargando y listo -->
                <div v-if="companySwitch.status !== 'error'" class="switch-stage" role="status" aria-live="polite">
                    <div class="switch-mark" :class="{ done: companySwitch.status === 'done' || companySwitch.status === 'leaving' }">
                        <span class="switch-ring" aria-hidden="true"></span>
                        <span class="switch-mark-letter">C</span>
                        <span class="switch-mark-check"><CheckIcon :size="28" :stroke-width="3" /></span>
                    </div>

                    <p class="switch-caption">
                        {{ companySwitch.status === 'done' || companySwitch.status === 'leaving' ? 'Ahora estás en' : 'Cambiando a' }}
                    </p>

                    <h2 :key="companySwitch.to?.id" class="switch-name">{{ companySwitch.to?.name }}</h2>

                    <p v-if="companySwitch.from" class="switch-from">desde {{ companySwitch.from.name }}</p>

                    <div class="switch-progress" :class="{ full: companySwitch.status === 'done' || companySwitch.status === 'leaving' }">
                        <span class="switch-progress-bar"></span>
                    </div>

                    <p v-if="companySwitch.status === 'slow'" class="switch-slow">
                        Está tardando más de lo normal. Seguimos esperando la respuesta del servidor.
                    </p>
                    <button
                        v-if="companySwitch.status === 'slow'"
                        type="button"
                        class="switch-logout-link"
                        @click="logoutFromSwitch"
                    ><LogOutIcon :size="15" /> Cerrar sesión</button>
                </div>

                <!-- Error -->
                <div v-else ref="card" class="switch-card card" role="alertdialog" aria-modal="true" aria-labelledby="switch-error-title" aria-describedby="switch-error-message">
                    <span class="switch-card-icon"><TriangleAlertIcon :size="22" /></span>
                    <h2 id="switch-error-title">{{ companySwitch.error?.title }}</h2>
                    <p id="switch-error-message">{{ companySwitch.error?.message }}</p>

                    <div class="switch-card-actions">
                        <template v-if="!companySwitch.error?.sessionExpired">
                            <button type="button" class="btn btn-ghost" @click="stayInCurrentCompany">
                                <Undo2Icon /> Volver a {{ companySwitch.from?.name }}
                            </button>
                            <button type="button" class="btn btn-ghost" @click="retryCompanySwitch">
                                <RotateCcwIcon /> Reintentar
                            </button>
                        </template>
                        <button type="button" class="btn btn-primary" @click="logoutFromSwitch">
                            <LogOutIcon /> Cerrar sesión
                        </button>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
.company-switch {
    position: fixed;
    inset: 0;
    z-index: 300;
    display: grid;
    place-items: center;
    padding: 1.5rem;
    background:
        radial-gradient(circle at 50% 40%, color-mix(in srgb, var(--color-accent) 16%, transparent), transparent 55%),
        var(--color-sidebar);
    color: var(--color-on-sidebar);
}

/* Entrada y salida de toda la ventana. */
.company-switch-enter-active,
.company-switch-leave-active {
    transition: opacity 0.25s ease;
}

.company-switch-enter-from,
.company-switch-leave-to {
    opacity: 0;
}

.switch-stage {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.35rem;
    max-width: 36rem;
    text-align: center;
    animation: switch-rise 0.45s cubic-bezier(0.2, 0.8, 0.2, 1) both;
}

.is-leaving .switch-stage {
    animation: switch-sink 0.25s ease both;
}

/* La marca de CONTAPP dentro de un anillo que gira mientras carga; al
   terminar, el anillo se cierra y la letra da paso a un check. */
.switch-mark {
    position: relative;
    display: grid;
    place-items: center;
    width: 4.25rem;
    height: 4.25rem;
    margin: 0.75rem 0 1.5rem;
    border-radius: 18px;
    background: rgba(255, 255, 255, 0.10);
    font-size: 1.6rem;
    font-weight: 800;
}

.switch-ring {
    position: absolute;
    inset: -14px;
    border-radius: 50%;
    border: 2px solid rgba(255, 255, 255, 0.10);
    border-top-color: var(--color-accent);
    animation: switch-spin 0.9s linear infinite;
    transition: border-color 0.3s ease;
}

.switch-mark.done .switch-ring {
    animation: none;
    border-color: var(--color-accent);
}

.switch-mark-letter,
.switch-mark-check {
    grid-area: 1 / 1;
    transition: opacity 0.2s ease, transform 0.3s cubic-bezier(0.2, 0.8, 0.2, 1);
}

.switch-mark-check {
    display: inline-flex;
    color: var(--color-accent);
    opacity: 0;
    transform: scale(0.4);
}

.switch-mark.done .switch-mark-letter {
    opacity: 0;
    transform: scale(0.6);
}

.switch-mark.done .switch-mark-check {
    opacity: 1;
    transform: scale(1);
}

.switch-caption {
    margin: 0;
    font-size: 0.74rem;
    font-weight: 700;
    letter-spacing: 0.14em;
    text-transform: uppercase;
    color: rgba(244, 246, 250, 0.6);
}

.switch-name {
    margin: 0;
    font-size: clamp(1.6rem, 4vw, 2.4rem);
    font-weight: 800;
    line-height: 1.15;
    overflow-wrap: anywhere;
    animation: switch-name-in 0.6s 0.1s cubic-bezier(0.2, 0.8, 0.2, 1) both;
}

.switch-from {
    margin: 0;
    font-size: 0.85rem;
    color: rgba(244, 246, 250, 0.55);
}

/* Progreso indeterminado mientras carga; lleno al terminar. */
.switch-progress {
    position: relative;
    width: min(16rem, 70vw);
    height: 3px;
    margin-top: 1.4rem;
    overflow: hidden;
    border-radius: 3px;
    background: rgba(255, 255, 255, 0.14);
}

.switch-progress-bar {
    position: absolute;
    inset: 0 auto 0 0;
    width: 40%;
    border-radius: 3px;
    background: var(--color-accent);
    animation: switch-indeterminate 1.1s ease-in-out infinite;
}

.switch-progress.full .switch-progress-bar {
    width: 100%;
    animation: none;
    transform: none;
    transition: width 0.3s ease;
}

.switch-slow {
    margin: 1rem 0 0;
    max-width: 26rem;
    font-size: 0.82rem;
    color: rgba(244, 246, 250, 0.75);
}

.switch-logout-link {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    margin-top: 0.5rem;
    padding: 0.4rem 0.7rem;
    border: 1px solid rgba(255, 255, 255, 0.25);
    border-radius: var(--radius-sm);
    background: transparent;
    color: var(--color-on-sidebar);
    font: inherit;
    font-size: 0.82rem;
    font-weight: 600;
    cursor: pointer;
}

.switch-logout-link:hover {
    background: rgba(255, 255, 255, 0.08);
}

/* Error: una tarjeta normal, con los colores de la aplicación. */
.switch-card {
    width: min(30rem, 100%);
    padding: 1.5rem;
    color: var(--color-text);
    text-align: center;
    animation: switch-rise 0.35s cubic-bezier(0.2, 0.8, 0.2, 1) both;
}

.switch-card-icon {
    display: inline-grid;
    place-items: center;
    width: 2.75rem;
    height: 2.75rem;
    margin-bottom: 0.6rem;
    border-radius: 50%;
    background: var(--color-warning-soft);
    color: var(--color-warning);
}

.switch-card h2 {
    margin: 0 0 0.4rem;
    font-size: 1.05rem;
}

.switch-card p {
    margin: 0;
    font-size: 0.88rem;
    color: var(--color-text-muted);
}

.switch-card-actions {
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    gap: 0.5rem;
    margin-top: 1.25rem;
}

@media (max-width: 640px) {
    .switch-card-actions .btn {
        flex: 1 1 100%;
        justify-content: center;
    }
}

@keyframes switch-rise {
    from { opacity: 0; transform: translateY(14px) scale(0.98); }
    to { opacity: 1; transform: none; }
}

@keyframes switch-sink {
    to { opacity: 0; transform: translateY(-10px) scale(0.98); }
}

@keyframes switch-name-in {
    from { opacity: 0; transform: translateY(12px); letter-spacing: 0.04em; }
    to { opacity: 1; transform: none; letter-spacing: normal; }
}

@keyframes switch-spin {
    to { transform: rotate(360deg); }
}

@keyframes switch-indeterminate {
    0% { transform: translateX(-100%); }
    100% { transform: translateX(250%); }
}

/* Sin animaciones para quien las pidió apagadas: la ventana igual avisa,
   solo que sin movimiento. */
@media (prefers-reduced-motion: reduce) {
    .switch-stage,
    .switch-name,
    .switch-card,
    .is-leaving .switch-stage {
        animation: none;
    }

    .switch-ring,
    .switch-progress-bar {
        animation: none;
    }

    .switch-progress-bar {
        width: 100%;
        opacity: 0.5;
    }
}
</style>
