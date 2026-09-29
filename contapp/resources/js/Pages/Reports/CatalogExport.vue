<script setup>
import { Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { DownloadIcon } from '@lucide/vue';
import AppLayout from '../../Layouts/AppLayout.vue';

const props = defineProps({
    catalogs: { type: Object, required: true }, // { 'chart-of-accounts': 'Cuentas contables', ... }
});

const catalogEntries = Object.entries(props.catalogs);
const selected = ref(Object.fromEntries(catalogEntries.map(([key]) => [key, true])));
const includeInactive = ref(false);

const anySelected = computed(() => Object.values(selected.value).some(Boolean));
const allSelected = computed(() => Object.values(selected.value).every(Boolean));

function toggleAll() {
    const next = ! allSelected.value;
    catalogEntries.forEach(([key]) => { selected.value[key] = next; });
}

function exportUrl() {
    const params = new URLSearchParams();
    catalogEntries.forEach(([key]) => {
        if (selected.value[key]) params.append('catalogs[]', key);
    });
    if (includeInactive.value) params.append('include_inactive', '1');

    return route('reports.catalog-export.export') + '?' + params.toString();
}
</script>

<template>
    <Head title="Exportar catálogos" />

    <AppLayout title="Exportar catálogos">
        <p class="hint">
            Un solo archivo XLSX con una hoja por cada catálogo que marqués abajo — elegí solo los que necesités,
            no hace falta descargar los cinco cada vez.
        </p>

        <!-- A todo el ancho (CLAUDE.md secc. 25): los catálogos se reparten en
             columnas en vez de quedar en una lista angosta a la izquierda. -->
        <div class="card export-card">
            <div class="select-all-row">
                <label class="option-row">
                    <input type="checkbox" :checked="allSelected" @change="toggleAll">
                    <strong>Seleccionar todos</strong>
                </label>
            </div>

            <div class="options-grid">
                <label v-for="[key, label] in catalogEntries" :key="key" class="option-row">
                    <input v-model="selected[key]" type="checkbox">
                    {{ label }}
                </label>
            </div>

            <label class="option-row inactive-row">
                <input v-model="includeInactive" type="checkbox">
                Incluir inactivos / no vigentes
            </label>

            <div class="form-actions actions-row">
                <span v-if="!anySelected" class="muted small">Marcá al menos un catálogo para exportar.</span>
                <a
                    :href="anySelected ? exportUrl() : null"
                    class="btn btn-primary"
                    :class="{ disabled: !anySelected }"
                    :aria-disabled="!anySelected"
                >
                    <DownloadIcon /> Exportar XLSX
                </a>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
.export-card { padding: 1.1rem 1.25rem; }

.select-all-row {
    padding-bottom: 0.6rem;
    margin-bottom: 0.6rem;
    border-bottom: 1px solid var(--color-border);
}

.options-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(min(100%, 14rem), 1fr));
    gap: 0 1rem;
}

.option-row {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    padding: 0.5rem 0;
    font-size: 0.9rem;
    cursor: pointer;
}

.inactive-row {
    margin-top: 0.4rem;
    padding-top: 0.6rem;
    border-top: 1px solid var(--color-border);
    color: var(--color-text-muted);
    font-size: 0.85rem;
}

.actions-row {
    align-items: center;
    margin-top: 1rem;
}

.btn.disabled {
    opacity: 0.5;
    pointer-events: none;
}
</style>
