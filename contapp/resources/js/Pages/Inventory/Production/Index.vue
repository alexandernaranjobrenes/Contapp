<script setup>
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import { CheckIcon, LockIcon, PackageCheckIcon, PackageMinusIcon, PlusIcon, XIcon } from '@lucide/vue';
import AppLayout from '../../../Layouts/AppLayout.vue';
import DetailModal from '../../../Components/DetailModal.vue';
import { confirmAction } from '../../../Utils/confirm';

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
    createForm.clearErrors();
    createForm.order_date = today;
    creating.value = true;
}

function submitCreate() {
    createForm
        .transform((data) => ({ ...data, description: data.description === '' ? null : data.description }))
        .post(route('production-orders.store'), { onSuccess: () => (creating.value = false), preserveScroll: true });
}

// --- ficha de la orden (CLAUDE.md secc. 20) ---
//
// Emitir, recibir y cerrar se hacen desde la ficha: 'issue' y 'receive' pasan
// el mismo modal a su formulario.
const selectedId = ref(null);
const orderMode = ref('details'); // 'details' | 'issue' | 'receive'
const selected = computed(() => props.orders.find((o) => o.id === selectedId.value) ?? null);

function openOrder(order) {
    selectedId.value = order.id;
    orderMode.value = 'details';
}

function closeOrderModal() {
    selectedId.value = null;
    orderMode.value = 'details';
}

const orderModalTitle = computed(() => {
    if (! selected.value) return '';
    if (orderMode.value === 'issue') return `Emitir materia prima — orden #${selected.value.id}`;
    if (orderMode.value === 'receive') return `Recibir producto — orden #${selected.value.id}`;
    return `Orden #${selected.value.id} — ${selected.value.item}`;
});

// --- emitir materia prima ---

const issueForm = useForm({
    document_type_id: props.documentTypes[0]?.id ?? '',
    posting_date: today,
    lines: [blankLine()],
});

function blankLine() {
    return { item_id: '', warehouse_id: '', warehouse_bin_id: '', quantity: '' };
}

const bomShortages = ref([]);

async function startIssue() {
    const order = selected.value;
    issueForm.clearErrors();
    issueForm.posting_date = today;
    issueForm.lines = [blankLine()];
    bomShortages.value = [];
    orderMode.value = 'issue';

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

const anyUsesBins = computed(() => props.warehouses.some((w) => w.uses_bins));

function submitIssue() {
    issueForm
        .transform((data) => ({
            ...data,
            lines: data.lines.map((line) => ({
                ...line,
                warehouse_bin_id: line.warehouse_bin_id === '' ? null : line.warehouse_bin_id,
            })),
        }))
        .post(route('production-orders.issue', selected.value.id), { onSuccess: () => { orderMode.value = 'details'; } });
}

// --- recibir producto terminado ---

const receiveForm = useForm({
    document_type_id: props.documentTypes[0]?.id ?? '',
    posting_date: today,
    quantity: '',
});

function startReceive() {
    receiveForm.clearErrors();
    receiveForm.posting_date = today;
    receiveForm.quantity = '';
    orderMode.value = 'receive';
}

const unitCostPreview = computed(() => {
    if (orderMode.value !== 'receive' || ! selected.value || ! Number(receiveForm.quantity)) return null;
    return Number(selected.value.wip_balance) / Number(receiveForm.quantity);
});

function submitReceive() {
    receiveForm.post(route('production-orders.receive', selected.value.id), { onSuccess: () => { orderMode.value = 'details'; } });
}

// --- cerrar ---

const closeForm = useForm({ document_type_id: props.documentTypes[0]?.id ?? '', posting_date: today });

function closeOrder() {
    const order = selected.value;
    const wip = Number(order.wip_balance);

    confirmAction({
        title: `Cerrar la orden #${order.id}`,
        message: wip === 0
            ? 'La orden se cierra y ya no admite emisiones ni recibos.'
            : `Tiene ${money(wip)} en proceso sin convertir en producto. Al cerrarla, ese costo se manda a desviación de fabricación.`,
        confirmLabel: 'Cerrar orden',
        danger: wip !== 0,
        onConfirm: () => closeForm.post(route('production-orders.close', order.id), { preserveScroll: true }),
    });
}
</script>

<template>
    <Head title="Órdenes de fabricación" />

    <AppLayout title="Órdenes de fabricación">
        <div class="view-toolbar">
            <div class="view-actions">
                <button type="button" class="btn btn-primary" :disabled="!ready" @click="openCreate()"><PlusIcon /> Crear nuevo</button>
            </div>
        </div>

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
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Orden</th>
                            <th>Producto</th>
                            <th class="num">Producido</th>
                            <th class="num">En proceso</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="o in orders"
                            :key="o.id"
                            class="clickable-row"
                            tabindex="0"
                            @click="openOrder(o)"
                            @keydown.enter="openOrder(o)"
                            @keydown.space.prevent="openOrder(o)"
                        >
                            <td class="code-cell">#{{ o.id }} · {{ o.order_date }}</td>
                            <td data-label="Producto">{{ o.item }}</td>
                            <td data-label="Producido" class="num">{{ quantity(o.produced_quantity) }} / {{ quantity(o.planned_quantity) }}</td>
                            <td data-label="En proceso" class="num">
                                <template v-if="o.status === 'open'">{{ money(o.wip_balance) }}</template>
                                <span v-else class="muted">—</span>
                            </td>
                            <td data-label="Estado">
                                <span class="badge" :class="o.status === 'open' ? 'badge-success' : 'badge-neutral'">
                                    {{ o.status === 'open' ? 'Abierta' : 'Cerrada' }}
                                </span>
                            </td>
                        </tr>
                        <tr v-if="!orders.length">
                            <td colspan="5" class="muted empty-row">Todavía no hay órdenes de fabricación.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Ficha de la orden, con sus formularios de emitir y recibir -->
        <DetailModal :open="!!selected" :title="orderModalTitle" :wide="orderMode === 'issue'" @close="closeOrderModal">
            <template #badge>
                <span v-if="selected && orderMode === 'details'" class="badge" :class="selected.status === 'open' ? 'badge-success' : 'badge-neutral'">
                    {{ selected.status === 'open' ? 'Abierta' : 'Cerrada' }}
                </span>
            </template>

            <dl v-if="selected && orderMode === 'details'" class="detail-list">
                <div>
                    <dt>Fecha</dt>
                    <dd>{{ selected.order_date }}</dd>
                </div>
                <div>
                    <dt>Almacén de ingreso</dt>
                    <dd>{{ selected.warehouse_code }}</dd>
                </div>
                <div>
                    <dt>Planificado</dt>
                    <dd>{{ quantity(selected.planned_quantity) }}</dd>
                </div>
                <div>
                    <dt>Producido</dt>
                    <dd>{{ quantity(selected.produced_quantity) }}</dd>
                </div>
                <div>
                    <dt>En proceso</dt>
                    <dd>{{ selected.status === 'open' ? money(selected.wip_balance) : '—' }}</dd>
                </div>
                <div>
                    <dt>Desviación</dt>
                    <dd>
                        <Link
                            v-if="selected.variance_journal_entry_id"
                            :href="route('journal-entries.show', selected.variance_journal_entry_id)"
                            class="warn"
                        >{{ money(selected.wip_balance) }}</Link>
                        <span v-else class="muted">—</span>
                    </dd>
                </div>
            </dl>

            <!-- Emitir materia prima -->
            <form v-if="selected && orderMode === 'issue'" id="issue-form" @submit.prevent="submitIssue">
                <p class="muted small">Sale al costo promedio vigente de cada artículo y se acumula en Producto en Proceso.</p>

                <p v-if="selected.bill_of_material_id" class="muted small">
                    Líneas precargadas desde la receta y escaladas a {{ quantity(selected.planned_quantity) }}
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

                <div class="form-grid">
                    <div class="field">
                        <label for="issue-doc-type">Tipo de documento</label>
                        <select id="issue-doc-type" v-model="issueForm.document_type_id" required>
                            <option v-for="t in documentTypes" :key="t.id" :value="t.id">{{ t.code }} — {{ t.name }}</option>
                        </select>
                    </div>
                    <div class="field">
                        <label for="issue-date">Fecha de contabilización</label>
                        <input id="issue-date" v-model="issueForm.posting_date" type="date" required>
                    </div>
                </div>

                <!-- Grilla de captura (CLAUDE.md secc. 20, excepción). -->
                <div class="table-responsive capture-grid">
                    <table class="lines-table">
                        <thead>
                            <tr>
                                <th>Artículo</th>
                                <th>Almacén</th>
                                <th v-if="anyUsesBins">Ubicación</th>
                                <th class="num">Cantidad</th>
                                <th><span class="sr-only">Quitar</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(line, index) in issueForm.lines" :key="index">
                                <td>
                                    <select v-model="line.item_id" required aria-label="Artículo">
                                        <option value="" disabled>— Elegir —</option>
                                        <option v-for="i in items" :key="i.id" :value="i.id">{{ i.code }} — {{ i.name }}</option>
                                    </select>
                                </td>
                                <td data-label="Almacén">
                                    <select v-model="line.warehouse_id" required aria-label="Almacén" @change="line.warehouse_bin_id = ''">
                                        <option value="" disabled>— Elegir —</option>
                                        <option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.code }}</option>
                                    </select>
                                </td>
                                <td v-if="anyUsesBins" data-label="Ubicación">
                                    <select v-if="usesBins(line.warehouse_id)" v-model="line.warehouse_bin_id" required aria-label="Ubicación">
                                        <option value="" disabled>— Elegir —</option>
                                        <option v-for="b in binsOf(line.warehouse_id)" :key="b.id" :value="b.id">
                                            {{ b.code }}
                                        </option>
                                    </select>
                                    <span v-else class="muted small">—</span>
                                </td>
                                <td data-label="Cantidad" class="num">
                                    <input v-model="line.quantity" type="number" step="0.000001" min="0.000001" required class="num-input" aria-label="Cantidad">
                                </td>
                                <td class="remove-cell">
                                    <button
                                        type="button" class="btn btn-ghost remove-btn"
                                        :disabled="issueForm.lines.length === 1"
                                        aria-label="Quitar línea"
                                        title="Quitar línea"
                                        @click="issueForm.lines.splice(index, 1)"
                                    ><XIcon /></button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <button type="button" class="btn btn-ghost add-line" @click="issueForm.lines.push(blankLine())"><PlusIcon /> Línea</button>
            </form>

            <!-- Recibir producto terminado -->
            <form v-if="selected && orderMode === 'receive'" id="receive-form" @submit.prevent="submitReceive">
                <p class="muted small">
                    Descarga los {{ money(selected.wip_balance) }} acumulados en proceso. El costo unitario sale de ahí,
                    no se digita.
                </p>

                <div class="field-row">
                    <div class="field">
                        <label for="receive-doc-type">Tipo de documento</label>
                        <select id="receive-doc-type" v-model="receiveForm.document_type_id" required>
                            <option v-for="t in documentTypes" :key="t.id" :value="t.id">{{ t.code }} — {{ t.name }}</option>
                        </select>
                    </div>
                    <div class="field">
                        <label for="receive-date">Fecha de contabilización</label>
                        <input id="receive-date" v-model="receiveForm.posting_date" type="date" required>
                    </div>
                </div>

                <div class="field">
                    <label for="receive-quantity">Cantidad producida</label>
                    <input id="receive-quantity" v-model="receiveForm.quantity" type="number" step="0.000001" min="0.000001" required>
                    <span v-if="receiveForm.errors.quantity" class="error">{{ receiveForm.errors.quantity }}</span>
                    <span v-if="unitCostPreview" class="muted small">Costo unitario resultante: {{ money(unitCostPreview) }}</span>
                </div>
            </form>

            <template #actions>
                <template v-if="selected && orderMode === 'details' && selected.status === 'open'">
                    <button type="button" class="btn btn-ghost btn-danger-text" @click="closeOrder"><LockIcon /> Cerrar orden</button>
                    <button type="button" class="btn btn-ghost" @click="startReceive"><PackageCheckIcon /> Recibir producto</button>
                    <button type="button" class="btn btn-primary" @click="startIssue"><PackageMinusIcon /> Emitir materia prima</button>
                </template>
                <template v-else-if="selected && orderMode === 'issue'">
                    <button type="button" class="btn btn-ghost" @click="orderMode = 'details'">Cancelar</button>
                    <button type="submit" form="issue-form" class="btn btn-primary" :disabled="issueForm.processing"><CheckIcon /> Emitir</button>
                </template>
                <template v-else-if="selected && orderMode === 'receive'">
                    <button type="button" class="btn btn-ghost" @click="orderMode = 'details'">Cancelar</button>
                    <button type="submit" form="receive-form" class="btn btn-primary" :disabled="receiveForm.processing"><CheckIcon /> Recibir</button>
                </template>
            </template>
        </DetailModal>

        <!-- Nueva orden -->
        <DetailModal :open="creating" title="Nueva orden de fabricación" @close="creating = false">
            <form id="production-form" @submit.prevent="submitCreate">
                <div class="field">
                    <label for="po-item">Producto a fabricar</label>
                    <select id="po-item" v-model="createForm.item_id" required>
                        <option value="" disabled>— Elegir —</option>
                        <option v-for="i in items" :key="i.id" :value="i.id">{{ i.code }} — {{ i.name }}</option>
                    </select>
                    <span v-if="createForm.errors.item_id" class="error">{{ createForm.errors.item_id }}</span>
                </div>

                <div class="field">
                    <label for="po-bom">Receta</label>
                    <select id="po-bom" v-model="createForm.bill_of_material_id">
                        <option value="">Sin receta (la emisión se digita)</option>
                        <option v-for="b in bomsForItem" :key="b.id" :value="b.id">
                            {{ b.code }} — {{ b.name }} (rinde {{ quantity(b.output_quantity) }})
                        </option>
                    </select>
                    <span v-if="createForm.errors.bill_of_material_id" class="error">
                        {{ createForm.errors.bill_of_material_id }}
                    </span>
                    <span v-if="createForm.item_id && !bomsForItem.length" class="muted small">
                        Este producto no tiene recetas activas. Se puede fabricar igual, pero la emisión habrá
                        que digitarla componente por componente.
                    </span>
                    <span v-else-if="createForm.bill_of_material_id" class="muted small">
                        Al emitir materia prima, las líneas van a llegar precargadas y escaladas a la cantidad
                        de esta orden. Siguen siendo editables.
                    </span>
                </div>

                <div class="field-row">
                    <div class="field">
                        <label for="po-warehouse">Almacén de ingreso</label>
                        <select id="po-warehouse" v-model="createForm.warehouse_id" required>
                            <option value="" disabled>— Elegir —</option>
                            <option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.code }} — {{ w.name }}</option>
                        </select>
                        <span v-if="createForm.errors.warehouse_id" class="error">{{ createForm.errors.warehouse_id }}</span>
                    </div>
                    <div class="field">
                        <label for="po-quantity">Cantidad planificada</label>
                        <input id="po-quantity" v-model="createForm.planned_quantity" type="number" step="0.000001" min="0.000001" required>
                        <span v-if="createForm.errors.planned_quantity" class="error">{{ createForm.errors.planned_quantity }}</span>
                    </div>
                </div>

                <div class="field-row">
                    <div class="field">
                        <label for="po-date">Fecha</label>
                        <input id="po-date" v-model="createForm.order_date" type="date" required>
                    </div>
                    <div class="field">
                        <label for="po-description">Descripción (opcional)</label>
                        <input id="po-description" v-model="createForm.description" type="text" maxlength="255">
                    </div>
                </div>
            </form>

            <template #actions>
                <button type="button" class="btn btn-ghost" @click="creating = false">Cancelar</button>
                <button type="submit" form="production-form" class="btn btn-primary" :disabled="createForm.processing">Crear</button>
            </template>
        </DetailModal>
    </AppLayout>
</template>

<style scoped>
table { font-size: 0.85rem; }
.code-cell { font-variant-numeric: tabular-nums; white-space: nowrap; }
.warn { color: var(--color-warning); font-weight: 600; }
.shortage-list { margin: 0.4rem 0 0 1.1rem; padding: 0; font-size: 0.78rem; }

.lines-table th, .lines-table td { padding: 0.4rem 0.5rem; }
.lines-table select, .lines-table input { width: 100%; min-width: 0; }
.num-input { text-align: right; }
.remove-btn { width: 2.25rem; padding: 0; }
.add-line { margin-top: 0.6rem; }

@media screen and (max-width: 1024px) {
    .remove-cell { justify-content: flex-end; }
}
</style>
