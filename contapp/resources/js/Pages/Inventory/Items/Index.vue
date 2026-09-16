<script setup>
import { Head, Link, useForm, router, usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AppLayout from '../../../Layouts/AppLayout.vue';
import DocumentToolbar from '../../../Components/DocumentToolbar.vue';

const props = defineProps({
    items: { type: Array, default: () => [] },
    itemGroups: { type: Array, default: () => [] },
    unitsOfMeasure: { type: Array, default: () => [] },
    taxRates: { type: Array, default: () => [] },
});

const page = usePage();
const search = ref('');

const filtered = computed(() => {
    const q = search.value.trim().toLowerCase();
    const sorted = [...props.items].sort((a, b) => a.code.localeCompare(b.code));
    if (! q) return sorted;

    return sorted.filter((i) =>
        i.code.toLowerCase().includes(q)
        || i.name.toLowerCase().includes(q)
        || (i.barcode ?? '').toLowerCase().includes(q)
    );
});

function money(value) {
    return Number(value ?? 0).toLocaleString('es-CR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function quantity(value) {
    return Number(value ?? 0).toLocaleString('es-CR', { minimumFractionDigits: 0, maximumFractionDigits: 6 });
}

const blank = {
    code: '',
    name: '',
    item_group_id: '',
    uom_id: '',
    barcode: '',
    is_inventory_item: true,
    is_sales_item: true,
    is_purchase_item: true,
    tax_rate_id: '',
    status: 'active',
};

const creating = ref(false);

const createForm = useForm({ ...blank });

function openCreate() {
    createForm.reset();
    creating.value = true;
}

function submitCreate() {
    createForm
        .transform(normalize)
        .post(route('items.store'), { onSuccess: () => (creating.value = false), preserveScroll: true });
}

const editing = ref(null);

const editForm = useForm({ ...blank });

// Crear y editar comparten los mismos campos salvo el código, así que
// comparten un solo modal; esto resuelve cuál de los dos formularios está
// vivo sin repetir el ternario en cada v-model.
const activeForm = computed(() => (creating.value ? createForm : editForm));

function openEdit(item) {
    editForm.clearErrors();
    editForm.name = item.name;
    editForm.item_group_id = item.item_group_id ?? '';
    editForm.uom_id = item.uom_id;
    editForm.barcode = item.barcode ?? '';
    editForm.is_inventory_item = item.is_inventory_item;
    editForm.is_sales_item = item.is_sales_item;
    editForm.is_purchase_item = item.is_purchase_item;
    editForm.tax_rate_id = item.tax_rate_id ?? '';
    editForm.status = item.status;
    editing.value = item;
}

function submitEdit() {
    editForm
        .transform(normalize)
        .put(route('items.update', editing.value.id), { onSuccess: () => (editing.value = null), preserveScroll: true });
}

// Un <select> sin selección entrega '' y Laravel lo trataría como un id
// inválido en vez de "sin grupo"/"sin impuesto"; null es lo que la regla
// 'nullable' espera.
function normalize(data) {
    return {
        ...data,
        item_group_id: data.item_group_id === '' ? null : data.item_group_id,
        tax_rate_id: data.tax_rate_id === '' ? null : data.tax_rate_id,
        barcode: data.barcode === '' ? null : data.barcode,
    };
}

function destroy(item) {
    if (! confirm(`¿Eliminar el artículo ${item.code} — ${item.name}?`)) return;

    router.delete(route('items.destroy', item.id), { preserveScroll: true });
}
</script>

<template>
    <Head title="Artículos" />

    <AppLayout title="Artículos">
        <template #actions>
            <input v-model="search" type="search" placeholder="Buscar código, nombre o código de barras..." class="search-input">
        </template>

        <DocumentToolbar can-create @new="openCreate()" />

        <div v-if="page.props.errors?.item" class="flash flash-error">{{ page.props.errors.item }}</div>

        <p v-if="!unitsOfMeasure.length" class="flash flash-warning">
            Todavía no hay unidades de medida activas. Creá al menos una antes de registrar artículos.
        </p>

        <div class="card">
            <div class="card-header">
                <span class="muted">{{ filtered.length }} artículo(s)</span>
                <button type="button" class="btn btn-primary" :disabled="!unitsOfMeasure.length" @click="openCreate()">
                    + Nuevo artículo
                </button>
            </div>

            <div class="table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>Código</th>
                            <th>Nombre</th>
                            <th>Grupo</th>
                            <th>U/M</th>
                            <th>Tipo</th>
                            <th class="right">Existencia</th>
                            <th class="right">Costo prom. (LC)</th>
                            <th class="right">Costo prom. (FC)</th>
                            <th>Estado</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="i in filtered" :key="i.id">
                            <td class="num code-cell">{{ i.code }}</td>
                            <td>{{ i.name }}</td>
                            <td class="muted small">{{ i.item_group?.code ?? '—' }}</td>
                            <td class="muted small">{{ i.unit_of_measure?.code ?? '—' }}</td>
                            <td>
                                <span class="badge" :class="i.is_inventory_item ? 'badge-success' : 'badge-neutral'">
                                    {{ i.is_inventory_item ? 'Inventario' : 'Servicio' }}
                                </span>
                            </td>
                            <td class="num right">{{ i.is_inventory_item ? quantity(i.on_hand) : '—' }}</td>
                            <td class="num right">{{ i.is_inventory_item ? money(i.avg_cost_local) : '—' }}</td>
                            <td class="num right">{{ i.is_inventory_item ? money(i.avg_cost_foreign) : '—' }}</td>
                            <td>
                                <span class="badge" :class="i.status === 'active' ? 'badge-success' : 'badge-neutral'">
                                    {{ i.status === 'active' ? 'Activo' : 'Inactivo' }}
                                </span>
                            </td>
                            <td class="actions-cell">
                                <Link v-if="i.is_inventory_item" :href="route('items.kardex', i.id)" class="btn btn-ghost">
                                    Kardex
                                </Link>
                                <button type="button" class="btn btn-ghost" @click="openEdit(i)">Editar</button>
                                <button type="button" class="btn btn-ghost" @click="destroy(i)">Eliminar</button>
                            </td>
                        </tr>
                        <tr v-if="!filtered.length">
                            <td colspan="10" class="muted empty-row">Todavía no hay artículos registrados.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <p class="hint">
            El costo promedio lo mantiene exclusivamente el motor de movimientos de stock, porque cada cambio de costo
            genera su propio asiento — por eso no es editable desde esta pantalla.
        </p>

        <!-- Nuevo / Editar comparten los mismos campos; el código solo existe al crear. -->
        <div v-if="creating || editing" class="modal-backdrop" @click.self="creating = false; editing = null">
            <form class="modal-card card" @submit.prevent="creating ? submitCreate() : submitEdit()">
                <h2>{{ creating ? 'Nuevo artículo' : `Editar ${editing.code}` }}</h2>
                <p v-if="editing" class="muted small">El código no se puede cambiar una vez creado el artículo.</p>

                <div v-if="creating" class="field">
                    <label>Código</label>
                    <input v-model="createForm.code" type="text" maxlength="40" required>
                    <span v-if="createForm.errors.code" class="error">{{ createForm.errors.code }}</span>
                </div>

                <div class="field">
                    <label>Nombre</label>
                    <input v-model="activeForm.name" type="text" required>
                    <span v-if="activeForm.errors.name" class="error">
                        {{ activeForm.errors.name }}
                    </span>
                </div>

                <div class="grid-2">
                    <div class="field">
                        <label>Grupo (opcional)</label>
                        <select v-model="activeForm.item_group_id">
                            <option value="">— Sin grupo —</option>
                            <option v-for="g in itemGroups" :key="g.id" :value="g.id">{{ g.code }} — {{ g.name }}</option>
                        </select>
                        <span v-if="activeForm.errors.item_group_id" class="error">
                            {{ activeForm.errors.item_group_id }}
                        </span>
                    </div>
                    <div class="field">
                        <label>Unidad de medida</label>
                        <select v-model="activeForm.uom_id" required>
                            <option value="" disabled>— Elegir —</option>
                            <option v-for="u in unitsOfMeasure" :key="u.id" :value="u.id">{{ u.code }} — {{ u.name }}</option>
                        </select>
                        <span v-if="activeForm.errors.uom_id" class="error">
                            {{ activeForm.errors.uom_id }}
                        </span>
                    </div>
                </div>

                <div class="grid-2">
                    <div class="field">
                        <label>Código de barras (opcional)</label>
                        <input v-model="activeForm.barcode" type="text">
                    </div>
                    <div class="field">
                        <label>Indicador de impuesto (opcional)</label>
                        <select v-model="activeForm.tax_rate_id">
                            <option value="">— Ninguno —</option>
                            <option v-for="t in taxRates" :key="t.id" :value="t.id">
                                {{ t.code }} — {{ t.percentage }}%
                            </option>
                        </select>
                        <span v-if="activeForm.errors.tax_rate_id" class="error">
                            {{ activeForm.errors.tax_rate_id }}
                        </span>
                    </div>
                </div>

                <label class="check-row">
                    <input v-model="activeForm.is_inventory_item" type="checkbox">
                    Lleva inventario (desmarcado = servicio: se compra/vende pero no lleva kardex ni costo)
                </label>
                <span v-if="activeForm.errors.is_inventory_item" class="error">
                    {{ activeForm.errors.is_inventory_item }}
                </span>

                <label class="check-row">
                    <input v-model="activeForm.is_purchase_item" type="checkbox">
                    Se compra
                </label>

                <label class="check-row">
                    <input v-model="activeForm.is_sales_item" type="checkbox">
                    Se vende
                </label>

                <div class="field">
                    <label>Estado</label>
                    <select v-model="activeForm.status">
                        <option value="active">Activo</option>
                        <option value="inactive">Inactivo</option>
                    </select>
                </div>

                <div class="modal-actions">
                    <button type="submit" class="btn btn-primary" :disabled="activeForm.processing">
                        Guardar
                    </button>
                    <button type="button" class="btn btn-ghost" @click="creating = false; editing = null">Cancelar</button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>

<style scoped>
.search-input {
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    padding: 0.4rem 0.6rem;
    font-size: 0.82rem;
    width: 280px;
}

.card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.85rem 1.1rem;
    border-bottom: 1px solid var(--color-border);
}

.flash { margin-bottom: 0.75rem; padding: 0.6rem 0.9rem; border-radius: var(--radius-sm); font-size: 0.85rem; }
.flash-error { background: var(--color-danger-soft); color: var(--color-danger); }
.flash-warning { background: var(--color-warning-soft); color: var(--color-warning); }

.hint { font-size: 0.82rem; color: var(--color-text-muted); margin: 0.75rem 0 0; }

.table-scroll { overflow-x: auto; }
table { font-size: 0.85rem; width: 100%; }
th, td { text-align: left; padding: 0.5rem 1rem; border-top: 1px solid var(--color-border); white-space: nowrap; }
.right { text-align: right; }
.code-cell { font-variant-numeric: tabular-nums; }
.num { font-variant-numeric: tabular-nums; }
.muted { color: var(--color-text-muted); }
.small { font-size: 0.76rem; }
.empty-row { text-align: center; padding: 1.5rem; white-space: normal; }
.actions-cell { display: flex; gap: 0.4rem; }

.modal-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(11, 31, 58, 0.45);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 50;
    padding: 1rem;
}

.modal-card { width: 560px; max-width: 100%; max-height: 90vh; overflow-y: auto; padding: 1.5rem; }
.modal-card h2 { font-size: 1rem; margin: 0 0 0.5rem; }

.grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 0 1rem; }

.field { display: flex; flex-direction: column; gap: 0.2rem; margin-bottom: 0.75rem; }
.field label { font-size: 0.78rem; color: var(--color-text-muted); }

.field input, .field select {
    width: 100%;
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    padding: 0.45rem 0.6rem;
    font-size: 0.85rem;
    color: var(--color-text);
}

.error { color: var(--color-danger); font-size: 0.76rem; }

.check-row {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.85rem;
    margin: 0.35rem 0;
}

.modal-actions { display: flex; gap: 0.6rem; margin-top: 1rem; }
</style>
