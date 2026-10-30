<script setup>
import { computed } from 'vue';
import DetailModal from '../DetailModal.vue';
import { SECTIONS, SIZES } from './sections';
import { CheckIcon, PlusIcon } from '@lucide/vue';

/**
 * «Agregar elemento»: el catálogo de lo que se puede poner en el Panel,
 * agrupado por sección del menú. Trae solo lo que ve quien edita (el
 * servidor ya lo filtró): nadie puede agregar algo de una pantalla a la que
 * no entra. Lo que ya está en el Panel se marca, y con el Panel lleno no se
 * agrega nada más.
 */
const props = defineProps({
    open: { type: Boolean, default: false },
    catalog: { type: Array, required: true },
    selected: { type: Array, required: true },
    full: { type: Boolean, default: false },
    max: { type: Number, required: true },
});

const emit = defineEmits(['add', 'close']);

const groups = computed(() => {
    const bySection = new Map();
    for (const item of props.catalog) {
        if (!bySection.has(item.section)) bySection.set(item.section, []);
        bySection.get(item.section).push(item);
    }
    return [...bySection.entries()].map(([key, items]) => ({ key, ...(SECTIONS[key] ?? { label: key }), items }));
});
</script>

<template>
    <DetailModal :open="open" title="Agregar un elemento al Panel" wide @close="emit('close')">
        <p class="muted hint">
            Cada persona ve solo los elementos de las pantallas a las que tiene acceso. Acá aparecen los que vos podés ver.
        </p>
        <p v-if="full" class="flash flash-warning">
            El Panel ya tiene {{ max }} elementos, el máximo. Quitá alguno para agregar otro.
        </p>

        <section v-for="group in groups" :key="group.key" class="catalog-group" :aria-labelledby="`catalog-${group.key}`">
            <h3 :id="`catalog-${group.key}`" class="section-title">{{ group.label }}</h3>
            <ul class="catalog-grid">
                <li v-for="item in group.items" :key="item.key" class="catalog-item">
                    <span class="catalog-icon" aria-hidden="true"><component :is="group.icon" :size="18" /></span>
                    <div class="catalog-text">
                        <strong>{{ item.title }}</strong>
                        <span class="catalog-description">{{ item.description }}</span>
                        <span class="catalog-size">{{ SIZES[item.size] }}</span>
                    </div>
                    <span v-if="selected.includes(item.key)" class="badge badge-success catalog-added">
                        <CheckIcon :size="13" /> En el Panel
                    </span>
                    <button
                        v-else
                        type="button"
                        class="btn btn-ghost catalog-add"
                        :disabled="full"
                        :aria-label="`Agregar ${item.title}`"
                        @click="emit('add', item.key)"
                    >
                        <PlusIcon /> Agregar
                    </button>
                </li>
            </ul>
        </section>

        <template #actions>
            <button type="button" class="btn btn-primary" @click="emit('close')">Listo</button>
        </template>
    </DetailModal>
</template>

<style scoped>
.catalog-group + .catalog-group { margin-top: 0.5rem; }

.catalog-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(min(100%, 22rem), 1fr));
    gap: 0.6rem;
    margin: 0;
    padding: 0;
    list-style: none;
}

.catalog-item {
    display: grid;
    grid-template-columns: auto 1fr auto;
    align-items: start;
    gap: 0.7rem;
    padding: 0.75rem;
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    background: var(--color-surface);
}

.catalog-icon {
    display: grid;
    place-items: center;
    width: 2.1rem;
    height: 2.1rem;
    border-radius: var(--radius-sm);
    background: var(--color-primary-soft);
    color: var(--color-primary);
}

.catalog-text { display: flex; flex-direction: column; gap: 0.15rem; min-width: 0; }
.catalog-text strong { font-size: 0.88rem; }
.catalog-description { font-size: 0.8rem; line-height: 1.45; color: var(--color-text-muted); }
.catalog-size { font-size: 0.72rem; color: var(--color-text-muted); }

.catalog-added { display: inline-flex; align-items: center; gap: 0.25rem; white-space: nowrap; }
.catalog-add { white-space: nowrap; }

/* En un teléfono, el botón baja debajo del texto, a lo ancho. */
@media (max-width: 640px) {
    .catalog-item { grid-template-columns: auto 1fr; }
    .catalog-add,
    .catalog-added { grid-column: 1 / -1; justify-content: center; }
}
</style>
