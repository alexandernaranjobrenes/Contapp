<script setup>
import { Head, Link, useForm, router, usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AppLayout from '../../../Layouts/AppLayout.vue';
import DocumentToolbar from '../../../Components/DocumentToolbar.vue';

const props = defineProps({
    items: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    itemGroups: { type: Array, default: () => [] },
    unitsOfMeasure: { type: Array, default: () => [] },
    taxRates: { type: Array, default: () => [] },
    fiscalUnits: { type: Object, default: () => ({}) },
    fiscalIvaRates: { type: Object, default: () => ({}) },
});

const page = usePage();

// La búsqueda pasó al servidor cuando el listado se paginó: filtrar en el
// cliente solo alcanzaba mientras venían todos los artículos, y con
// paginación buscaría únicamente dentro de la página que está a la vista.
const search = ref(props.filters?.search ?? '');
const itemGroupId = ref(props.filters?.item_group_id ?? '');
const status = ref(props.filters?.status ?? '');

let searchTimer = null;

function applyFilters() {
    router.get(route('items.index'), {
        search: search.value.trim() === '' ? undefined : search.value.trim(),
        item_group_id: itemGroupId.value === '' ? undefined : itemGroupId.value,
        status: status.value === '' ? undefined : status.value,
    }, { preserveState: true, replace: true, preserveScroll: true });
}

// Con debounce: sin esto cada tecla dispara una request al servidor.
function onSearchInput() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(applyFilters, 350);
}

const rows = computed(() => props.items.data ?? []);

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
    tracks_lots: false,
    minimum_stock: 0,
    maximum_stock: '',
    cabys_code: '',
    fiscal_unit_code: '',
    iva_rate_code: '',
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
    editForm.tracks_lots = item.tracks_lots;
    editForm.minimum_stock = item.minimum_stock ?? 0;
    editForm.maximum_stock = item.maximum_stock ?? '';
    editForm.cabys_code = item.cabys_code ?? '';
    editForm.fiscal_unit_code = item.fiscal_unit_code ?? '';
    editForm.iva_rate_code = item.iva_rate_code ?? '';
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
        maximum_stock: data.maximum_stock === '' ? null : data.maximum_stock,
        cabys_code: data.cabys_code === '' ? null : data.cabys_code,
        fiscal_unit_code: data.fiscal_unit_code === '' ? null : data.fiscal_unit_code,
        iva_rate_code: data.iva_rate_code === '' ? null : data.iva_rate_code,
        tax_rate_id: data.tax_rate_id === '' ? null : data.tax_rate_id,
        barcode: data.barcode === '' ? null : data.barcode,
    };
}

// --- carga masiva (plantilla XLSX) ---

const fileInput = ref(null);
const importForm = useForm({ file: null });
const importErrors = computed(() => page.props.flash?.importErrors ?? []);

function onFileSelected(e) {
    const file = e.target.files[0];
    if (! file) return;

    importForm.file = file;
    importForm.post(route('items.import'), {
        preserveScroll: true,
        onFinish: () => {
            importForm.reset();
            if (fileInput.value) fileInput.value.value = '';
        },
    });
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
            <input
                v-model="search"
                type="search"
                placeholder="Buscar código o nombre..."
                class="search-input"
                @input="onSearchInput"
            >
            <select v-model="itemGroupId" class="search-input" @change="applyFilters">
                <option value="">Todos los grupos</option>
                <option v-for="g in itemGroups" :key="g.id" :value="g.id">{{ g.code }} — {{ g.name }}</option>
            </select>
            <select v-model="status" class="search-input" @change="applyFilters">
                <option value="">Todos</option>
                <option value="active">Activos</option>
                <option value="inactive">Inactivos</option>
            </select>
        </template>

        <DocumentToolbar can-create @new="openCreate()" />

        <div class="bulk-bar card">
            <div class="bulk-bar-row">
                <div class="bulk-bar-text">
                    <strong>Carga masiva</strong>
                    <span class="muted small">
                        Descargá la plantilla —trae el catálogo actual y las hojas con los códigos válidos—,
                        completala en Excel y subila. Los códigos que ya existen se actualizan.
                    </span>
                </div>
                <div class="bulk-actions">
                    <a :href="route('items.template')" class="btn btn-ghost">Descargar plantilla</a>
                    <label class="btn btn-primary file-btn" :class="{ disabled: importForm.processing }">
                        {{ importForm.processing ? 'Subiendo...' : 'Importar XLSX' }}
                        <input ref="fileInput" type="file" accept=".xlsx" class="file-input" :disabled="importForm.processing" @change="onFileSelected">
                    </label>
                </div>
            </div>
            <p class="muted small no-stock">
                Existencias y costo promedio no se cargan por acá: los mantiene el motor de movimientos, porque
                cada cambio de costo tiene que generar su asiento. Las existencias iniciales entran por una
                entrada de mercancía.
            </p>
            <span v-if="importForm.errors.file" class="error">{{ importForm.errors.file }}</span>

            <div v-if="importErrors.length" class="import-errors">
                <p class="import-errors-title">No se importó nada porque el archivo tiene {{ importErrors.length }} error(es). Corregilos y subilo de nuevo:</p>
                <ul>
                    <li v-for="(msg, i) in importErrors" :key="i">{{ msg }}</li>
                </ul>
            </div>
        </div>

        <div v-if="page.props.errors?.item" class="flash flash-error">{{ page.props.errors.item }}</div>

        <p v-if="!unitsOfMeasure.length" class="flash flash-warning">
            Todavía no hay unidades de medida activas. Creá al menos una antes de registrar artículos.
        </p>

        <div class="card">
            <div class="card-header">
                <span class="muted">{{ items.total }} artículo(s)</span>
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
                        <tr v-for="i in rows" :key="i.id">
                            <td class="num code-cell">
                                {{ i.code }}
                                <span
                                    v-if="i.is_sales_item && !i.cabys_code"
                                    class="needs-cabys"
                                    title="Se vende pero no tiene código CAByS: habrá que teclearlo en cada factura"
                                >sin CAByS</span>
                            </td>
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
                                <Link v-if="i.tracks_lots" :href="route('item-lots.index', i.id)" class="btn btn-ghost">
                                    Lotes
                                </Link>
                                <Link v-if="i.is_inventory_item" :href="route('reorder.levels', i.id)" class="btn btn-ghost">
                                    Niveles
                                </Link>
                                <button type="button" class="btn btn-ghost" @click="openEdit(i)">Editar</button>
                                <button type="button" class="btn btn-ghost" @click="destroy(i)">Eliminar</button>
                            </td>
                        </tr>
                        <tr v-if="!rows.length">
                            <td colspan="10" class="muted empty-row">
                                {{ filters.search || filters.item_group_id || filters.status
                                    ? 'Ningún artículo coincide con los filtros.'
                                    : 'Todavía no hay artículos registrados.' }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <nav v-if="items.links.length > 3" class="pagination">
                <Link
                    v-for="(link, i) in items.links"
                    :key="i"
                    :href="link.url ?? '#'"
                    class="page-link"
                    :class="{ active: link.active, disabled: !link.url }"
                    v-html="link.label"
                />
            </nav>
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

                <label class="check-row">
                    <input v-model="activeForm.tracks_lots" type="checkbox" :disabled="!activeForm.is_inventory_item">
                    Maneja lotes (cada movimiento va a exigir número de lote)
                </label>
                <span class="hint small">
                    Los lotes son trazabilidad y vencimiento, no valoración: el costo sigue siendo promedio global
                    del artículo. Activalo para medicamentos, alimentos, químicos o cualquier cosa que haya que poder
                    rastrear o que caduque.
                </span>

                <div v-if="activeForm.is_inventory_item" class="grid-2">
                    <div class="field">
                        <label>Mínimo de existencia</label>
                        <input v-model="activeForm.minimum_stock" type="number" step="0.000001" min="0">
                        <span v-if="activeForm.errors.minimum_stock" class="error">
                            {{ activeForm.errors.minimum_stock }}
                        </span>
                    </div>

                    <div class="field">
                        <label>Máximo (opcional)</label>
                        <input v-model="activeForm.maximum_stock" type="number" step="0.000001" min="0" placeholder="—">
                        <span v-if="activeForm.errors.maximum_stock" class="error">
                            {{ activeForm.errors.maximum_stock }}
                        </span>
                    </div>
                </div>

                <span v-if="activeForm.is_inventory_item" class="hint small">
                    Es el nivel <strong>por defecto</strong> del artículo: alimenta la sugerencia de compra y
                    aplica en todos los almacenes, salvo en los que definan el suyo propio desde
                    <em>Niveles</em>. El mínimo dispara la reposición; el máximo dice hasta dónde reponer.
                    En cero significa <strong>sin control de reorden</strong>.
                </span>

                <h3 class="section-heading">Datos para factura electrónica</h3>

                <div class="field">
                    <label>Código CAByS</label>
                    <input
                        v-model="activeForm.cabys_code"
                        type="text" inputmode="numeric" maxlength="13" placeholder="13 dígitos"
                        class="cabys-input"
                    >
                    <span v-if="activeForm.errors.cabys_code" class="error">{{ activeForm.errors.cabys_code }}</span>
                    <span class="hint small">
                        Se precarga en cada línea de la factura electrónica. Hacienda lo exige por línea, así que
                        un artículo que se vende y no lo tenga acá obliga a teclearlo en <strong>cada</strong>
                        factura.
                    </span>
                </div>

                <div class="grid-2">
                    <div class="field">
                        <label>Unidad de medida de Hacienda</label>
                        <select v-model="activeForm.fiscal_unit_code">
                            <option value="">— Sin definir —</option>
                            <option v-for="(label, code) in fiscalUnits" :key="code" :value="code">
                                {{ code }} — {{ label }}
                            </option>
                        </select>
                        <span v-if="activeForm.errors.fiscal_unit_code" class="error">
                            {{ activeForm.errors.fiscal_unit_code }}
                        </span>
                    </div>

                    <div class="field">
                        <label>Tarifa de IVA de Hacienda</label>
                        <select v-model="activeForm.iva_rate_code">
                            <option value="">— Sin definir —</option>
                            <option v-for="(label, code) in fiscalIvaRates" :key="code" :value="code">
                                {{ label }}
                            </option>
                        </select>
                        <span v-if="activeForm.errors.iva_rate_code" class="error">
                            {{ activeForm.errors.iva_rate_code }}
                        </span>
                    </div>
                </div>

                <span class="hint small">
                    La unidad de Hacienda es distinta de la unidad de medida interna: aquella es un catálogo
                    cerrado del XML. Y la tarifa de Hacienda tiene que decir el mismo porcentaje que el indicador
                    de impuesto de arriba — si no, la factura declararía un porcentaje y el asiento registraría
                    otro; el sistema lo rechaza.
                </span>

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
.bulk-bar { padding: 0.85rem 1.1rem; margin-bottom: 0.75rem; }
.bulk-bar-row { display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap; }
.bulk-bar-text { display: flex; flex-direction: column; gap: 0.1rem; font-size: 0.85rem; }
.bulk-actions { display: flex; gap: 0.5rem; flex-shrink: 0; }
.file-btn { position: relative; cursor: pointer; overflow: hidden; }
.file-btn.disabled { opacity: 0.6; cursor: default; }
.file-input { position: absolute; inset: 0; opacity: 0; width: 100%; cursor: pointer; }
.no-stock { display: block; margin: 0.5rem 0 0; }
.error { display: block; margin-top: 0.4rem; color: var(--color-danger); font-size: 0.76rem; }
.import-errors { margin-top: 0.75rem; padding: 0.75rem 0.9rem; border-radius: var(--radius-sm); background: var(--color-danger-soft); color: var(--color-danger); font-size: 0.82rem; }
.import-errors-title { font-weight: 700; margin: 0 0 0.4rem; }
.import-errors ul { margin: 0; padding-left: 1.1rem; display: flex; flex-direction: column; gap: 0.2rem; }
.section-heading { font-size: 0.82rem; text-transform: uppercase; letter-spacing: 0.04em; color: var(--color-text-muted); margin: 1rem 0 0.25rem; }
.cabys-input { font-variant-numeric: tabular-nums; }
.needs-cabys { display: inline-block; margin-left: 0.4rem; font-size: 0.65rem; padding: 0.05rem 0.3rem; border-radius: 3px; background: #fdf0ea; color: #a04000; font-weight: 600; }
.pagination { display: flex; gap: 0.25rem; padding: 0.75rem 1.1rem; flex-wrap: wrap; }
.page-link { padding: 0.3rem 0.6rem; border-radius: var(--radius-sm); font-size: 0.78rem; text-decoration: none; color: var(--color-text-muted); }
.page-link.active { background: var(--color-primary); color: #fff; }
.page-link.disabled { opacity: 0.4; pointer-events: none; }

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
