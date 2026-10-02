<script setup>
import { computed } from 'vue';
import { CheckIcon, CircleIcon } from '@lucide/vue';

/**
 * Lo que tiene que tener una contraseña, tildándose mientras se escribe.
 *
 * Los requisitos no están escritos acá: los manda el servidor
 * (App\Domains\Core\Support\PasswordPolicy), cada uno como «al menos `min`
 * caracteres que calcen con `pattern`». La misma lista es la que valida el
 * servidor al guardar, así que lo que esta lista da por cumplido, pasa.
 */
const props = defineProps({
    requirements: { type: Array, required: true },
    password: { type: String, default: '' },
    // La contraseña repetida. Sin ella (null) no se muestra la línea de
    // «escrita igual las dos veces»: crear un usuario la pide una sola vez.
    confirmation: { type: String, default: null },
});

const checks = computed(() => props.requirements.map((requirement) => ({
    key: requirement.key,
    label: requirement.label,
    met: (props.password.match(new RegExp(requirement.pattern, 'gu')) ?? []).length >= requirement.min,
})));

const matches = computed(() => props.password !== '' && props.password === props.confirmation);
</script>

<template>
    <div class="password-requirements">
        <p class="requirements-title">La contraseña tiene que tener al menos:</p>
        <ul>
            <li v-for="check in checks" :key="check.key" :class="{ met: check.met }">
                <CheckIcon v-if="check.met" :size="15" /><CircleIcon v-else :size="15" />
                <span class="requirement">{{ check.label }}</span>
                <span class="sr-only">{{ check.met ? '(cumplido)' : '(falta)' }}</span>
            </li>
        </ul>
        <p v-if="confirmation !== null" class="match" :class="{ met: matches }">
            <CheckIcon v-if="matches" :size="15" /><CircleIcon v-else :size="15" />
            Escrita igual las dos veces
            <span class="sr-only">{{ matches ? '(cumplido)' : '(falta)' }}</span>
        </p>
    </div>
</template>

<style scoped>
.password-requirements {
    font-size: 0.82rem;
    color: var(--color-text-muted);
}

.requirements-title {
    margin: 0 0 0.45rem;
}

/* Dos columnas cuando caben; los textos son cortos a propósito. */
ul {
    list-style: none;
    padding: 0;
    margin: 0;
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(min(100%, 9.5rem), 1fr));
    gap: 0.3rem 1rem;
}

li,
.match {
    display: flex;
    align-items: center;
    gap: 0.45rem;
}

li svg,
.match svg {
    flex-shrink: 0;
}

/* El texto del servidor completa «al menos …», así que viene en minúscula. */
.requirement {
    display: inline-block;
}

.requirement::first-letter {
    text-transform: uppercase;
}

.match {
    margin: 0.55rem 0 0;
    padding-top: 0.55rem;
    border-top: 1px solid var(--color-border);
}

.met {
    color: var(--color-success);
}
</style>
