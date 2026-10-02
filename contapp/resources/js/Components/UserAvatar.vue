<script setup>
import { computed, ref, watch } from 'vue';

/**
 * La foto de perfil de una cuenta, en un círculo. Sin foto —o si la foto no
 * carga: el bucket no responde, el archivo ya no está— muestra las iniciales
 * del nombre, para que el lugar nunca quede con una imagen rota.
 *
 * Es un adorno al lado del nombre, que siempre va escrito: por eso no lleva
 * texto alternativo.
 */
const props = defineProps({
    url: { type: String, default: null },
    name: { type: String, default: '' },
    // Lado del círculo, en rem.
    size: { type: Number, default: 2 },
});

const failed = ref(false);

// Una dirección nueva (se subió otra foto) merece otro intento.
watch(() => props.url, () => { failed.value = false; });

const initials = computed(() => {
    const words = props.name.trim().split(/\s+/).filter(Boolean);

    return ((words[0]?.[0] ?? '') + (words.length > 1 ? words.at(-1)[0] : '')).toUpperCase();
});
</script>

<template>
    <span class="user-avatar" :style="{ '--avatar-size': `${size}rem` }" aria-hidden="true">
        <img v-if="url && !failed" :src="url" alt="" loading="lazy" @error="failed = true">
        <span v-else class="initials">{{ initials }}</span>
    </span>
</template>

<style scoped>
.user-avatar {
    flex-shrink: 0;
    display: inline-grid;
    place-items: center;
    width: var(--avatar-size);
    height: var(--avatar-size);
    border-radius: 50%;
    overflow: hidden;
    background: color-mix(in srgb, var(--color-primary) 14%, var(--color-surface));
    box-shadow: inset 0 0 0 1px var(--color-border);
    color: color-mix(in srgb, var(--color-primary) 55%, var(--color-text));
    font-size: calc(var(--avatar-size) * 0.38);
    font-weight: 700;
    line-height: 1;
    vertical-align: middle;
}

img {
    /* En bloque: como texto en línea deja un hueco debajo y la fila de una
       tabla con foto queda más alta que las demás. */
    display: block;
    width: 100%;
    height: 100%;
    object-fit: cover;
}
</style>
