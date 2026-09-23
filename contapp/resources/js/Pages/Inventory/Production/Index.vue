<script setup>
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import AppLayout from '../../../Layouts/AppLayout.vue';
import DocumentToolbar from '../../../Components/DocumentToolbar.vue';

const props = defineProps({
    orders: { type: Array, default: () => [] },
    documentTypes: { type: Array, default: () => [] },
    items: { type: Array, default: () => [] },
    warehouses: { type: Array, default: () => [] },
    bins: { type: Array, default: () => [] },
    billsOfMaterials: { type: Array, default: () => [] },
});

const page = usePage();
const today = new Date().toISOString().slice(0, 10);

function money(value) {
    return Number(value ?? 0).toLocaleString('es-CR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function quantity(value) {
    return Number(value ?? 0).toLocaleString('es-CR', { minimumFractionDigits: 0, maximumFractionDigits: 6 });
}

const ready = computed(() => props.documentTypes.length && props.items.length && props.warehouses.length);

// --- crear orden ---

const creating = ref(false);

const createForm = useForm({
    item_id: '', bill_of_material_id: '', warehouse_id: '', planned_quantity: '', order_date: today, description: '',
});

// Solo las recetas del producto elegido: una receta de otro artículo
// sugeriría emitir insumos que no tienen nada que ver.
const bomsForItem = computed(
    () => props.billsOfMaterials.filter((b) => b.item_id === createForm.item_id)
);

// Al cambiar de producto, la receta elegida deja de corresponder. Se
// preselecciona la predeterminada, o la única si hay una sola.
watch(() => createForm.item_id, () => {
    const options = bomsForItem.value;

    createForm.bill_of_material_id = options.find((b) => b.is_default)?.id
        ?? (options.length === 1 ? options[0].id : '');
});

function openCreate() {
    createForm.reset();
    createForm.order_date = today;
    creating.value = true;
}

function submitCreate() {
    createForm
        .transform((data) => ({ ...data, description: data.description === '' ? null : data.description }))
        .post(route('production-orders.store'), { onSuccess: () => (creating.value = false), preserveScroll: true });
}

// --- emitir materia prima ---

const issuing = ref(null);

const issueForm = useForm({
    document_type_id: props.documentTypes[0]?.id ?? '',
    posting_date: today,
    lines: [blankLine()],
});

function blankLine() {
    return { item_id: '', warehouse_id: '', warehouse_bin_id: '', quantity: '' };
}

const bomShortages = ref([]);

async function openIssue(order) {
    issueForm.clearErrors();
    issueForm.posting_date = today;
    issueForm.lines = [blankLine()];
    bomShortages.value = [];
    issuing.value = order;

    if (! order.bill_of_material_id) return;

    // La receta precarga las líneas: es lo que antes había que recordar de
    // memoria, y olvidar un componente hace que la orden cierre con una
    // desviación que nadie sabe explicar. Siguen siendo editables — quien
    // fabrica sabe si hoy lleva otra cosa.
    try {
        const url = route('bills-of-materials.explode', [
            order.bill_of_material_id,
            { quantity: order.planned_quantity, warehouse_id: order.warehouse_id },
        ]);

        const response = await fetch(url, { headers: { Accept: 'application/json' } });
        if (! response.ok) return;

        const data = await response.json();

        if (! data.lines?.length) return;

        issueForm.lines = data.lines.map((l) => ({
            item_id: l.component_item_id,
            warehouse_id: l.warehouse_id ?? order.warehouse_id ?? '',
            warehouse_bin_id: '',
            quantity: l.required_quantity,
        }));

        bomShortages.value = data.lines.filter((l) => Number(l.shortage) > 0);
    } catch {
        // Sin receta cargada la emisión se digita igual que antes: es una
        // ayuda, no un requisito para poder fabricar.
    }
}

function usesBins(warehouseId) {
    return props.warehouses.find((w) => w.id === warehouseId)?.uses_bins ?? false;
}

function binsOf(warehouseId) {
    return props.bins.filter((b) => b.warehouse_id === warehouseId);
}

function submitIssue() {
    issueForm
        .transform((data) => ({
            ...data,
            lines: data.lines.map((line) => ({
                ...line,
                warehouse_bin_id: line.warehouse_bin_id === '' ? null : line.warehouse_bin_id,
            })),
        }))
        .post(route('production-orders.issue', issuing.value.id), { onSuccess: () => (issuing.value = null) });
}

// --- recibir producto terminado ---

const receiving = ref(null);

const receiveForm = useForm({
    document_type_id: props.documentTypes[0]?.id ?? '',
    posting_date: today,
    quantity: '',
});

function openReceive(order) {
    receiveForm.clearErrors();
    receiveForm.posting_date = today;
    receiveForm.quantity = '';
    receiving.value = order;
}

const unitCostPreview = computed(() => {
    if (! receiving.value || ! Number(receiveForm.quantity)) return null;
    return Number(receiving.value.wip_balance) / Number(receiveForm.quantity);
});

function submitReceive() {
    receiveForm.post(route('production-orders.receive', receiving.value.id), { onSuccess: () => (receiving.value = null) });
}

// --- cerrar ---

const closeForm = useForm({ document_type_id: props.documentTypes[0]?.id ?? '', posting_date: today });

function closeOrder(order) {
    const wip = Number(order.wip_balance);

    const message = wip === 0
        ? `¿Cerrar la orden #${order.id}?`
        : `La orden #${order.id} tiene ${money(wip)} en proceso sin convertir en producto. Al cerrarla, ese costo se manda a desviación de fabricación. ¿Continuar?`;

    if (! confirm(message)) return;

    closeForm.post(route('production-orders.close', order.id), { preserveScroll: true });
}
</script>

<template>
    <Head title="Órdenes de fabricación" />

    <AppLayout title="Órdenes de fabricación">
        <DocumentToolbar can-create @new="openCreate()" />

        <div v-if="page.props.errors?.production" class="flash flash-error">{{ page.props.errors.production }}</div>

        <p v-if="!ready" class="flash flash-warning">
            Hacen falta un tipo de documento del módulo "Inventario", al menos un artículo de inventario activo y un almacén.
        </p>

        <p class="hint">
            La materia prima emitida se acumula en <strong>Producto en Proceso</strong>, y el producto terminado descarga
            ese costo al ingresar: su costo unitario es el <strong>real consumido</strong>, no uno estimado. Si al cerrar
            la orden queda saldo en proceso —materia prima que nunca se convirtió en producto— se manda a desviación de
            fabricación.
        </p>

        <div class="card">
            <div class="card-header">
                <span class="muted">{{ orders.length }} orden(es)</span>
                <button type="button" class="btn btn-primary" :disabled="!ready" @click="openCreate()">+ Nueva orden</button>
            </div>

            <div class="table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Fecha</th>
                            <th>Producto</th>
                            <th>Almacén</th>
                            <th class="right">Planificado</th>
                            <th class="right">Producido</th>
                            <th class="right">En proceso</th>
                            <th class="right">Desviación</th>
                            <th>Estado</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="o in orders" :key="o.id">
                            <td class="num">{{ o.id }}</td>
                            <td class="num">{{ o.order_date }}</td>
                            <td>{{ o.item }}</td>
                            <td class="code-cell">{{ o.warehouse_code }}</td>
                            <td class="num right">{{ quantity(o.planned_quantity) }}</td>
                            <td class="num right">{{ quantity(o.produced_quantity) }}</td>
                            <td class="num right">
                                <template v-if="o.status === 'open'">{{ money(o.wip_balance) }}</template>
                                <span v-else class="muted">—</span>
                            </td>
                            <td class="num right">
                                <Link
                                    v-if="o.variance_journal_entry_id"
                                    :href="route('journal-entries.show', o.variance_journal_entry_id)"
                                    class="warn"
                                >{{ money(o.wip_balance) }}</Link>
                                <span v-else class="muted">—</span>
                            </td>
                            <td>
                                <span class="badge" :class="o.status === 'open' ? 'badge-success' : 'badge-neutral'">
                                    {{ o.status === 'open' ? 'Abierta' : 'Cerrada' }}
                                </span>
                            </td>
                            <td class="actions-cell">
                                <template v-if="o.status === 'open'">
                                    <button type="button" class="btn btn-ghost" @click="openIssue(o)">Emitir</button>
                                    <button type="button" class="btn btn-ghost" @click="openReceive(o)">Recibir</button>
                                    <button type="button" class="btn btn-ghost" @click="closeOrder(o)">Cerrar</button>
                                </template>
                            </td>
                        </tr>
                        <tr v-if="!orders.length">
                            <td colspan="10" class="muted empty-row">Todavía no hay órdenes de fabricación.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Nueva orden -->
        <div v-if="creating" class="modal-backdrop" @click.self="creating = false">
            <form class="modal-card card" @submit.prevent="submitCreate">
                <h2>Nueva orden de fabricación</h2>

                <div class="field">
                    <label>Producto a fabricar</label>
                    <select v-model="createForm.item_id" required>
                        <option value="" disabled>— Elegir —</option>
                        <option v-for="i in items" :key="i.id" :value="i.id">{{ i.code }} — {{ i.name }}</option>
                    </select>
                    <span v-if="createForm.errors.item_id" class="error">{{ createForm.errors.item_id }}</span>
                </div>

                <div class="field">
                    <label>Receta</label>
                    <select v-model="createForm.bill_of_material_id">
                        <option value="">Sin receta (la emisión se digita)</option>
                        <option v-for="b in bomsForItem" :key="b.id" :value="b.id">
                            {{ b.code }} — {{ b.name }} (rinde {{ quantity(b.output_quantity) }})
                        </option>
                    </select>
                    <span v-if="createForm.errors.bill_of_material_id" class="error">
                        {{ createForm.errors.bill_of_material_id }}
                    </span>
                    <span v-if="createForm.item_id && !bomsForItem.length" class="hint small">
                        Este producto no tiene recetas activas. Se puede fabricar igual, pero la emisión habrá
                        que digitarla componente por componente.
                    </span>
                    <span v-else-if="createForm.bill_of_material_id" class="hint small">
                        Al emitir materia prima, las líneas van a llegar precargadas y escaladas a la cantidad
                        de esta orden. Siguen siendo editables.
                    </span>
                </div>

                <div class="grid-2">
                    <div class="field">
                        <label>Almacén de ingreso</label>
                        <select v-model="createForm.warehouse_id" required>
                            <option value="" disabled>— Elegir —</option>
                            <option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.code }} — {{ w.name }}</option>
                        </select>
                        <span v-if="createForm.errors.warehouse_id" class="error">{{ createForm.errors.warehouse_id }}</span>
                    </div>
                    <div class="field">
                        <label>Cantidad planificada</label>
                        <input v-model="createForm.planned_quantity" type="number" step="0.000001" min="0.000001" required>
                        <span v-if="createForm.errors.planned_quantity" class="error">{{ createForm.errors.planned_quantity }}</span>
                    </div>
                </div>

                <div class="grid-2">
                    <div class="field">
                        <label>Fecha</label>
                        <input v-model="createForm.order_date" type="date" required>
                    </div>
                    <div class="field">
                        <label>Descripción (opcional)</label>
                        <input v-model="createForm.description" type="text" maxlength="255">
                    </div>
                </div>

                <div class="modal-actions">
                    <button type="submit" class="btn btn-primary" :disabled="createForm.processing">Crear</button>
                    <button type="button" class="btn btn-ghost" @click="creating = false">Cancelar</button>
                </div>
            </form>
        </div>

        <!-- Emitir materia prima -->
        <div v-if="issuing" class="modal-backdrop" @click.self="issuing = null">
            <form class="modal-card wide card" @submit.prevent="submitIssue">
                <h2>Emitir materia prima — orden #{{ issuing.id }}</h2>
                <p class="muted small">Sale al costo promedio vigente de cada artículo y se acumula en Producto en Proceso.</p>

                <p v-if="issuing.bill_of_material_id" class="muted small">
                    Líneas precargadas desde la receta y escaladas a {{ quantity(issuing.planned_quantity) }}
                    unidad(es), con la merma ya aplicada. Se pueden cambiar.
                </p>

                <div v-if="bomShortages.length" class="flash flash-warning">
                    <strong>No alcanza la existencia de {{ bomShortages.length }} componente(s).</strong>
                    La emisión va a ser rechazada tal como está; ajustá las cantidades o reponé primero.
                    <ul class="shortage-list">
                        <li v-for="s in bomShortages" :key="s.component_item_id">
                            {{ s.item_code }} — hacen falta {{ quantity(s.required_quantity) }},
                            hay {{ quantity(s.on_hand) }} (faltan {{ quantity(s.shortage) }})
                        </li>
                    </ul>
                </div>

                <div class="grid-2">
                    <div class="field">
                        <label>Tipo de documento</label>
                        <select v-model="issueForm.document_type_id" required>
                            <option v-for="t in documentTypes" :key="t.id" :value="t.id">{{ t.code }} — {{ t.name }}</option>
                        </select>
                    </div>
                    <div class="field">
                        <label>Fecha de contabilización</label>
                        <input v-model="issueForm.posting_date" type="date" required>
                    </div>
                </div>

                <table class="lines">
                    <thead>
                        <tr>
                            <th>Artículo</th>
                            <th>Almacén</th>
                            <th>Ubicación</th>
                            <th class="right">Cantidad</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(line, index) in issueForm.lines" :key="index">
                            <td>
                                <select v-model="line.item_id" required>
                                    <option value="" disabled>— Elegir —</option>
                                    <option v-for="i in items" :key="i.id" :value="i.id">{{ i.code }}</option>
                                </select>
                            </td>
                            <td>
                                <select v-model="line.warehouse_id" required @change="line.warehouse_bin_id = ''">
                                    <option value="" disabled>— Elegir —</option>
                                    <option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.code }}</option>
                                </select>
                            </td>
                            <td>
                                <select v-if="usesBins(line.warehouse_id)" v-model="line.warehouse_bin_id" required>
                                    <option value="" disabled>— Elegir —</option>
                                    <option v-for="b in binsOf(line.warehouse_id)" :key="b.id" :value="b.id">
                                        {{ b.code }}
                                    </option>
                                </select>
                                <span v-else class="muted small">—</span>
                            </td>
                            <td><input v-model="line.quantity" type="number" step="0.000001" min="0.000001" required class="right"></td>
                            <td>
                                <button
                                    type="button" class="btn btn-ghost"
                                    :disabled="issueForm.lines.length === 1"
                                    @click="issueForm.lines.splice(index, 1)"
                                >
                                    Quitar
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <div class="modal-actions spread">
                    <button type="button" class="btn btn-ghost" @click="issueForm.lines.push(blankLine())">+ Línea</button>
                    <div class="modal-actions">
                        <button type="submit" class="btn btn-primary" :disabled="issueForm.processing">Emitir</button>
                        <button type="button" class="btn btn-ghost" @click="issuing = null">Cancelar</button>
                    </div>
                </div>
            </form>
        </div>

        <!-- Recibir producto terminado -->
        <div v-if="receiving" class="modal-backdrop" @click.self="receiving = null">
            <form class="modal-card card" @submit.prevent="submitReceive">
                <h2>Recibir producto — orden #{{ receiving.id }}</h2>
                <p class="muted small">
                    Descarga los {{ money(receiving.wip_balance) }} acumulados en proceso. El costo unitario sale de ahí,
                    no se digita.
                </p>

                <div class="grid-2">
                    <div class="field">
                        <label>Tipo de documento</label>
                        <select v-model="receiveForm.document_type_id" required>
                            <option v-for="t in documentTypes" :key="t.id" :value="t.id">{{ t.code }} — {{ t.name }}</option>
                        </select>
                    </div>
                    <div class="field">
                        <label>Fecha de contabilización</label>
                        <input v-model="receiveForm.posting_date" type="date" required>
                    </div>
                </div>

                <div class="field">
                    <label>Cantidad producida</label>
                    <input v-model="receiveForm.quantity" type="number" step="0.000001" min="0.000001" required>
                    <span v-if="receiveForm.errors.quantity" class="error">{{ receiveForm.errors.quantity }}</span>
                    <span v-if="unitCostPreview" class="preview">Costo unitario resultante: {{ money(unitCostPreview) }}</span>
                </div>

                <div class="modal-actions">
                    <button type="submit" class="btn btn-primary" :disabled="receiveForm.processing">Recibir</button>
                    <button type="button" class="btn btn-ghost" @click="receiving = null">Cancelar</button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>

<style scoped>
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

.hint { font-size: 0.82rem; color: var(--color-text-muted); margin: -0.25rem 0 1rem; }

.table-scroll { overflow-x: auto; }
table { font-size: 0.85rem; width: 100%; }
th, td { text-align: left; padding: 0.5rem 1rem; border-top: 1px solid var(--color-border); white-space: nowrap; }
table.lines th, table.lines td { padding: 0.4rem 0.5rem; }
.right { text-align: right; }
.code-cell, .num { font-variant-numeric: tabular-nums; }
.muted { color: var(--color-text-muted); }
.small { font-size: 0.76rem; }
.warn { color: var(--color-warning); font-weight: 600; }
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

.modal-card { width: 520px; max-width: 100%; max-height: 90vh; overflow-y: auto; padding: 1.5rem; }
.modal-card.wide { width: 760px; }
.modal-card h2 { font-size: 1rem; margin: 0 0 0.25rem; }

.grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 0 1rem; }

.field { display: flex; flex-direction: column; gap: 0.2rem; margin-bottom: 0.75rem; }
.field label { font-size: 0.78rem; color: var(--color-text-muted); }

.field input, .field select, td input, td select {
    width: 100%;
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    padding: 0.45rem 0.6rem;
    font-size: 0.85rem;
    color: var(--color-text);
}

.error { color: var(--color-danger); font-size: 0.76rem; }
.preview { color: var(--color-text-muted); font-size: 0.76rem; }
.modal-actions { display: flex; gap: 0.6rem; margin-top: 0.75rem; }
.modal-actions.spread { justify-content: space-between; align-items: center; }
.shortage-list { margin: 0.4rem 0 0 1.1rem; padding: 0; font-size: 0.78rem; }
</style>
