<script setup>
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { ArrowLeftIcon, SaveIcon } from '@lucide/vue';
import AppLayout from '../../../Layouts/AppLayout.vue';

const props = defineProps({
    item: { type: Object, required: true },
    rows: { type: Array, default: () => [] },
});

const page = usePage();

const form = useForm({
    levels: props.rows.map((r) => ({
        warehouse_id: r.warehouse_id,
        minimum_stock: r.minimum_stock,
        maximum_stock: r.maximum_stock,
    })),
});

function quantity(value) {
    return Number(value ?? 0).toLocaleString('es-CR', { minimumFractionDigits: 0, maximumFractionDigits: 6 });
}

function available(row) {
    return Number(row.on_hand) - Number(row.reserved) + Number(row.ordered);
}

function submit() {
    form.transform((data) => ({
        // Vacío viaja como null, que es "heredar de la ficha". Mandarlo como
        // '' lo convertiría en 0 y eso significa otra cosa: apagar el reorden
        // en este almacén.
        levels: data.levels.map((l) => ({
            ...l,
            minimum_stock: l.minimum_stock === '' || l.minimum_stock === null ? null : l.minimum_stock,
            maximum_stock: l.maximum_stock === '' || l.maximum_stock === null ? null : l.maximum_stock,
        })),
    })).put(route('reorder.levels.update', props.item.id), { preserveScroll: true });
}
</script>

<template>
    <Head :title="'Niveles de reorden — ' + item.code" />

    <AppLayout :title="'Niveles de reorden — ' + item.code + ' ' + item.name">
        <div class="view-toolbar">
            <Link :href="route('items.index')" class="btn btn-ghost"><ArrowLeftIcon /> Artículos</Link>
            <div class="view-actions">
                <Link :href="route('reorder.index')" class="btn btn-ghost">Sugerencia de compra</Link>
            </div>
        </div>

        <div v-if="page.props.errors?.levels" class="flash flash-error">{{ page.props.errors.levels }}</div>

        <p v-if="!item.is_inventory_item" class="flash flash-warning">
            Este artículo está marcado como servicio: no lleva existencia, así que los niveles de reorden no
            van a disparar nada.
        </p>

        <p class="hint">
            La ficha del artículo define el nivel <strong>por defecto</strong>
            (mínimo <strong>{{ quantity(item.default_minimum) }}</strong>,
            máximo {{ item.default_maximum === null ? '—' : quantity(item.default_maximum) }}).
            Acá solo hace falta llenar los almacenes que necesiten algo distinto:
        </p>

        <ul class="hint rules">
            <li><strong>Campo vacío</strong> → hereda el de la ficha.</li>
            <li><strong>Cero</strong> → este almacén NO lleva control de reorden, aunque el artículo sí. Es lo que
                corresponde en una bodega de tránsito, que existe para estar vacía.</li>
            <li><strong>Un número</strong> → sobrescribe el de la ficha solo en este almacén.</li>
        </ul>

        <!-- Grilla de captura: el mínimo y el máximo se editan en la fila
             (CLAUDE.md secc. 20, excepción). Lo apartado y lo que viene en
             camino se leen debajo del disponible, que es lo que suman. -->
        <form @submit.prevent="submit">
            <div class="card">
                <div class="table-responsive capture-grid">
                    <table>
                        <thead>
                            <tr>
                                <th>Almacén</th>
                                <th class="num">Existencia</th>
                                <th class="num">Disponible</th>
                                <th class="num">Mínimo</th>
                                <th class="num">Máximo</th>
                                <th class="num">Mínimo efectivo</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(row, index) in rows" :key="row.warehouse_id">
                                <td><strong class="code">{{ row.warehouse_code }}</strong> — {{ row.warehouse_name }}</td>
                                <td data-label="Existencia" class="num">{{ quantity(row.on_hand) }}</td>
                                <td data-label="Disponible" class="num">
                                    <strong>{{ quantity(available(row)) }}</strong>
                                    <span class="breakdown">apartado {{ quantity(row.reserved) }} · en camino {{ quantity(row.ordered) }}</span>
                                </td>
                                <td data-label="Mínimo" class="num">
                                    <input
                                        v-model="form.levels[index].minimum_stock"
                                        type="number" step="0.000001" min="0" class="level-input"
                                        :aria-label="`Mínimo en ${row.warehouse_code}`"
                                        :placeholder="'ficha: ' + quantity(item.default_minimum)"
                                    >
                                </td>
                                <td data-label="Máximo" class="num">
                                    <input
                                        v-model="form.levels[index].maximum_stock"
                                        type="number" step="0.000001" min="0" class="level-input"
                                        :aria-label="`Máximo en ${row.warehouse_code}`"
                                        :placeholder="item.default_maximum === null ? '—' : 'ficha: ' + quantity(item.default_maximum)"
                                    >
                                </td>
                                <td data-label="Mínimo efectivo" class="num">
                                    <strong>{{ quantity(row.effective_minimum) }}</strong>
                                    <span v-if="row.minimum_stock === null" class="inherited">heredado</span>
                                </td>
                            </tr>
                            <tr v-if="!rows.length">
                                <td colspan="6" class="muted empty-row">No hay almacenes activos.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary" :disabled="form.processing || !rows.length">
                    <SaveIcon /> Guardar niveles
                </button>
            </div>
        </form>
    </AppLayout>
</template>

<style scoped>
table { font-size: 0.85rem; }
.code { font-variant-numeric: tabular-nums; }
.level-input { width: 7.5rem; text-align: right; }
.rules { margin: 0 0 0.75rem 1.1rem; padding: 0; }
.rules li { margin: 0.15rem 0; }
.inherited,
.breakdown { display: block; font-size: 0.7rem; color: var(--color-text-muted); font-weight: 400; white-space: normal; }

@media screen and (max-width: 1024px) {
    .level-input { width: auto; }
    .breakdown { display: inline; margin-left: 0.4rem; }
}
</style>
