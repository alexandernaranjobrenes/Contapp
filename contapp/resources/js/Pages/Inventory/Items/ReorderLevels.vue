<script setup>
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
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
        <template #actions>
            <Link :href="route('reorder.index')" class="btn btn-ghost">Sugerencia de compra</Link>
            <Link :href="route('items.index')" class="btn btn-ghost">Volver a artículos</Link>
        </template>

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

        <form class="card" @submit.prevent="submit">
            <table>
                <thead>
                    <tr>
                        <th>Almacén</th>
                        <th class="num">Existencia</th>
                        <th class="num">Apartado</th>
                        <th class="num">En camino</th>
                        <th class="num">Disponible</th>
                        <th class="num">Mínimo</th>
                        <th class="num">Máximo</th>
                        <th class="num">Mínimo efectivo</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(row, index) in rows" :key="row.warehouse_id">
                        <td><strong class="num">{{ row.warehouse_code }}</strong> — {{ row.warehouse_name }}</td>
                        <td class="num">{{ quantity(row.on_hand) }}</td>
                        <td class="num muted">{{ quantity(row.reserved) }}</td>
                        <td class="num muted">{{ quantity(row.ordered) }}</td>
                        <td class="num"><strong>{{ quantity(available(row)) }}</strong></td>
                        <td class="num">
                            <input
                                v-model="form.levels[index].minimum_stock"
                                type="number" step="0.000001" min="0" class="level-input"
                                :placeholder="'ficha: ' + quantity(item.default_minimum)"
                            >
                        </td>
                        <td class="num">
                            <input
                                v-model="form.levels[index].maximum_stock"
                                type="number" step="0.000001" min="0" class="level-input"
                                :placeholder="item.default_maximum === null ? '—' : 'ficha: ' + quantity(item.default_maximum)"
                            >
                        </td>
                        <td class="num">
                            <strong>{{ quantity(row.effective_minimum) }}</strong>
                            <span v-if="row.minimum_stock === null" class="inherited">heredado</span>
                        </td>
                    </tr>
                    <tr v-if="!rows.length">
                        <td colspan="8" class="muted empty-row">No hay almacenes activos.</td>
                    </tr>
                </tbody>
            </table>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary" :disabled="form.processing || !rows.length">
                    Guardar niveles
                </button>
            </div>
        </form>
    </AppLayout>
</template>

<style scoped>
.num { text-align: right; }
.level-input { width: 7rem; text-align: right; }
.rules { margin: 0 0 0.75rem 1.1rem; padding: 0; }
.rules li { margin: 0.15rem 0; }
.inherited { display: block; font-size: 0.7rem; color: var(--color-text-muted); font-weight: 400; }
.form-actions { padding: 0.75rem 1.1rem; }
</style>
