<script setup>
defineProps({
    // Plano operativo (Superusuario/Administrador/Usuario) vs. backoffice
    // del Propietario — visualmente distintos a propósito (CLAUDE.md secc.
    // 11: nunca deben confundirse, son dos puertas de entrada distintas).
    dark: { type: Boolean, default: false },
    subtitle: { type: String, default: '' },
    // Activate.vue trae más campos (compañía + usuario en un solo formulario)
    // que un login de 2 campos — necesita más ancho para su grilla de 2 columnas.
    wide: { type: Boolean, default: false },
});
</script>

<template>
    <div class="auth-shell" :class="{ 'is-dark': dark, 'is-wide': wide }">
        <div class="auth-panel">
            <div class="auth-brand">
                <span class="brand-mark">C</span>
                <span class="brand-name">CONTAPP</span>
            </div>
            <p class="auth-panel-subtitle">{{ subtitle }}</p>
            <div class="auth-panel-extra">
                <slot name="panel-extra" />
            </div>
        </div>

        <div class="auth-form-side">
            <div class="auth-card card">
                <slot />
            </div>
        </div>
    </div>
</template>

<style scoped>
.auth-shell {
    min-height: 100vh;
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 1.15fr);
}

.auth-panel {
    display: flex;
    flex-direction: column;
    justify-content: center;
    padding: 3rem 3rem;
    background: var(--color-primary) var(--marble-texture);
    color: var(--color-on-primary);
}

.auth-shell.is-dark .auth-panel {
    /* Deliberadamente distinto al azul marino de marca (var(--color-primary)):
       esta puerta es exclusiva del fabricante de CONTAPP, nunca de un
       cliente, así que no debe poder confundirse con el plano operativo. */
    background: #14171c;
    color: #eef0f3;
}

.auth-brand {
    display: flex;
    align-items: center;
    gap: 0.7rem;
    margin-bottom: 1rem;
}

.brand-mark {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 40px;
    height: 40px;
    border-radius: 10px;
    background: rgba(255, 255, 255, 0.12);
    font-weight: 800;
    font-size: 1.1rem;
}

.brand-name {
    font-size: 1.4rem;
    font-weight: 800;
    letter-spacing: .02em;
}

.auth-panel-subtitle {
    font-size: 1rem;
    opacity: .82;
    margin: 0;
    max-width: 26em;
    line-height: 1.5;
}

.auth-panel-extra {
    margin-top: 1.75rem;
}

.auth-form-side {
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 2rem 1.5rem;
    background: var(--color-bg) var(--marble-texture);
}

.auth-card {
    width: 100%;
    max-width: 420px;
    padding: 2rem 2rem;
}

.auth-shell.is-wide .auth-card {
    max-width: 560px;
}

@media (max-width: 860px) {
    .auth-shell {
        grid-template-columns: 1fr;
    }

    .auth-panel {
        padding: 1.75rem 1.5rem 1.25rem;
    }

    .auth-panel-subtitle {
        max-width: none;
    }

    .auth-panel-extra {
        margin-top: 1rem;
    }
}
</style>
