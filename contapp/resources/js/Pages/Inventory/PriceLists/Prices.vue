<script setup>
import { Head, Link, useForm, router, usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AppLayout from '../../../Layouts/AppLayout.vue';

const props = defineProps({
    priceList: { type: Object, required: true },
    items: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    belowCost: { type: Array, default: () => [] },
});

const page = usePage();

const search = ref(props.filters?.search ?? '');

let searchTimer = null;

function applyFilters() {
    router.get(route('price-lists.prices', props.priceList.id), {
        search: search.value.trim() === '' ? undefined : search.value.trim(),
    }, { preserveState: true, replace: true, preserveScroll: true });
}

function onSearchInput() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(applyFilters, 350);
}

const rows = computed(() => props.items.data ?? []);

const form = useForm({
    prices: rows.value.map((i) => ({ item_id: i.id, unit_price: i.unit_price ?? '' })),
});

function money(value) {
    return Number(value ?? 0).toLocaleString('es-CR', { minimumFractionDigits: 2, maximumFractionDigits: 5 });
}

// Margen contra el costo promedio, solo como aviso. Hay razones legítimas
// para vender bajo costo —liquidar, producto gancho, un costo inflado por un
// flete mal asignado— y el sistema no tiene con qué distinguirlas.
function margin(row, index) {
    const price = Number(form.prices[index]?.unit_price ?? 0);
    const cost = Number(row.avg_cost_local ?? 0);

    if (! price || ! cost) return null;

    return ((price - cost) / price) * 100;
}

function submit() {
    // Vacío viaja como null, que QUITA el artículo de la lista. Es distinto
    // de cero, que es un precio y significa regalarlo.
    form.transform((data) => ({
        prices: data.prices.map((p) => ({
            ...p,
            unit_price: p.unit_price === '' || p.unit_price === null ? null : p.unit_price,
        })),
    })).put(route('price-lists.prices.update', props.priceList.id), { preserveScroll: true });
}
</script>

<template>
    <Head :title="'Precios — ' + priceList.code" />

    <AppLayout :title="'Precios — ' + priceList.code + ' ' + priceList.name">
        <template #actions>
            <input
                v-model="search"
                type="search"
                placeholder="Buscar código o nombre..."
                class="search-input"
                @input="onSearchInput"
            >
            <Link :href="route('price-lists.index')" class="btn btn-ghost">Volver a las listas</Link>
        </template>

        <p class="hint">
            Lista en <strong>{{ priceList.currency_code }}</strong>,
            {{ priceList.prices_include_tax ? 'con IVA incluido' : 'sin IVA' }}.
            <template v-if="priceList.valid_from || priceList.valid_to">
                Vigente {{ priceList.valid_from ?? 'desde siempre' }} a {{ priceList.valid_to ?? 'sin límite' }}.
            </template>
            Dejar un precio <strong>vacío</strong> quita el artículo de la lista; poner <strong>cero</strong> es
            un precio y significa regalarlo.
        </p>

        <p v-if="priceList.status !== 'active'" class="flash flash-warning">
            Esta lista está inactiva: no se va a aplicar a ninguna factura.
        </p>

        <div v-if="belowCost.length" class="flash flash-warning">
            <strong>{{ belowCost.length }} artículo(s) con precio por debajo del costo promedio.</strong>
            Puede ser deliberado —liquidación, producto gancho— o un costo inflado por un flete mal asignado;
            el sistema avisa pero no lo impide.
            <ul class="below-list">
                <li v-for="b in belowCost" :key="b.item_id">
                    {{ b.item_code }} — {{ b.item_name }}: precio {{ money(b.unit_price) }},
                    costo {{ money(b.avg_cost_local) }}
                </li>
            </ul>
        </div>

        <p v-if="!priceList.is_local_currency" class="hint small">
            La lista no está en moneda local, así que no se compara contra el costo promedio: el resultado no
            tendría significado sin fijar un tipo de cambio.
        </p>

        <form class="card" @submit.prevent="submit">
            <div class="table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>Código</th>
                            <th>Artículo</th>
                            <th class="right">Costo prom. (LC)</th>
                            <th class="right">Precio ({{ priceList.currency_code }})</th>
                            <th class="right">Margen</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(i, index) in rows" :key="i.id">
                            <td class="num">{{ i.code }}</td>
                            <td>{{ i.name }}</td>
                            <td class="right muted">{{ i.is_inventory_item ? money(i.avg_cost_local) : '—' }}</td>
                            <td class="right">
                                <input
                                    v-model="form.prices[index].unit_price"
                                    type="number" step="0.00001" min="0" class="price-input"
                                    placeholder="sin precio"
                                >
                            </td>
                            <td class="right">
                                <span
                                    v-if="priceList.is_local_currency && margin(i, index) !== null"
                                    :class="{ negative: margin(i, index) < 0 }"
                                >{{ margin(i, index).toFixed(1) }}%</span>
                                <span v-else class="muted">—</span>
                            </td>
                        </tr>
                        <tr v-if="!rows.length">
                            <td colspan="5" class="muted empty-row">
                                No hay artículos de venta activos que coincidan.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="form-actions">
                <span class="muted small">{{ items.total }} artículo(s) de venta · página {{ items.current_page }} de {{ items.last_page }}</span>
                <button type="submit" class="btn btn-primary" :disabled="form.processing || !rows.length">
                    Guardar precios
                </button>
            </div>
        </form>

        <div v-if="items.last_page > 1" class="pagination">
            <Link
                v-for="link in items.links"
                :key="link.label"
                :href="link.url ?? ''"
                class="page-link"
                :class="{ active: link.active, disabled: !link.url }"
                preserve-scroll
                v-html="link.label"
            />
        </div>
        <p v-if="items.last_page > 1" class="hint small">
            Los precios se guardan por página: guardá antes de pasar a la siguiente.
        </p>
    </AppLayout>
</template>

<style scoped>
.right { text-align: right; }
.num { font-variant-numeric: tabular-nums; }
.small { font-size: 0.76rem; }
.hint { color: var(--color-text-muted); font-size: 0.82rem; margin: 0 0 0.75rem; }
.price-input { width: 8rem; text-align: right; }
.negative { color: var(--color-danger); font-weight: 600; }
.empty-row { text-align: center; padding: 1.5rem; }
.form-actions { display: flex; align-items: center; justify-content: space-between; padding: 0.75rem 1.1rem; }
.below-list { margin: 0.4rem 0 0 1.1rem; padding: 0; font-size: 0.78rem; }
.flash { margin-bottom: 0.75rem; padding: 0.6rem 0.9rem; border-radius: var(--radius-sm); font-size: 0.85rem; }
.flash-warning { background: #fdf0ea; color: #a04000; }
.pagination { display: flex; gap: 0.25rem; margin-top: 0.75rem; flex-wrap: wrap; }
.page-link { padding: 0.25rem 0.55rem; border-radius: var(--radius-sm); font-size: 0.8rem; }
.page-link.active { background: var(--color-primary, #0B1F3A); color: #fff; }
.page-link.disabled { opacity: 0.4; pointer-events: none; }
</style>
