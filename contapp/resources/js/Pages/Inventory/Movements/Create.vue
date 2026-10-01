<script setup>
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AppLayout from '../../../Layouts/AppLayout.vue';
import { ArrowLeftIcon, CheckIcon, PlusIcon, XIcon } from '@lucide/vue';

const props = defineProps({
    operations: { type: Object, default: () => ({}) },
    documentTypes: { type: Array, default: () => [] },
    items: { type: Array, default: () => [] },
    warehouses: { type: Array, default: () => [] },
    bins: { type: Array, default: () => [] },
    suppliers: { type: Array, default: () => [] },
    purchaseOrders: { type: Array, default: () => [] },
    customsOffices: { type: Object, default: () => ({}) },
});

function usesBins(warehouseId) {
    return props.warehouses.find((w) => w.id === warehouseId)?.uses_bins ?? false;
}

function binsOf(warehouseId) {
    return props.bins.filter((b) => b.warehouse_id === warehouseId);
}

const anyWarehouseUsesBins = () => props.warehouses.some((w) => w.uses_bins);

function tracksLots(itemId) {
    return props.items.find((i) => i.id === itemId)?.tracks_lots ?? false;
}

const anyItemTracksLots = () => props.items.some((i) => i.tracks_lots);

/**
 * Opciones de lote por línea, pedidas al servidor en vez de venir en los
 * props: un artículo con rotación alta acumula cientos de lotes al año y
 * mandarlos todos en cada carga del formulario crece sin techo.
 *
 * En una salida el servidor devuelve la sugerencia FEFO —ya sin vencidos,
 * retenidos ni sin saldo— y el primero de la lista es el que conviene tomar.
 * Es una sugerencia: se puede elegir otro y el motor solo exige que sea
 * despachable.
 */
const lotOptions = ref({});

async function loadLots(index) {
    const line = form.lines[index];

    if (! line.item_id || ! tracksLots(line.item_id)) {
        lotOptions.value[index] = null;
        return;
    }

    const params = new URLSearchParams({ operation: form.operation });
    if (line.warehouse_id) params.set('warehouse_id', line.warehouse_id);
    if (line.warehouse_bin_id) params.set('warehouse_bin_id', line.warehouse_bin_id);

    try {
        const res = await fetch(`${route('item-lots.options', line.item_id)}?${params}`, {
            headers: { Accept: 'application/json' },
        });

        lotOptions.value[index] = await res.json();
    } catch {
        lotOptions.value[index] = null;
    }
}

function onLineContextChange(index) {
    form.lines[index].item_lot_id = '';
    loadLots(index);
}

const page = usePage();
const today = new Date().toISOString().slice(0, 10);

const form = useForm({
    operation: 'goods_receipt',
    document_type_id: props.documentTypes[0]?.id ?? '',
    document_date: today,
    posting_date: today,
    description: '',
    business_partner_id: '',
    purchase_order_id: '',
    is_import: false,
    customs_declaration: '',
    customs_office: '',
    transport_document: '',
    origin_country: '',
    customs_date: '',
    lines: [blankLine()],
});

// El proveedor solo aplica a una entrada por compra: es lo que crea la
// cuenta puente GR/IR que después liquida su factura.
const needsSupplier = computed(() => form.operation === 'purchase_receipt');

// Solo las órdenes del proveedor elegido: ofrecer las de otro invita a un
// enlace que el service va a rechazar igual.
const ordersOfSupplier = computed(() => props.purchaseOrders
    .filter((o) => o.business_partner_id === form.business_partner_id));

// Marcar la entrada como importación es lo que después habilita cargarle
// rubros de nacionalización; sin la marca, esos costos no se le pueden asignar.
const canBeImport = computed(() => form.operation === 'purchase_receipt');

function blankLine() {
    return { item_id: '', warehouse_id: '', warehouse_bin_id: '', item_lot_id: '', quantity: '', unit_cost_local: '', description: '' };
}

// El costo unitario solo se digita en una entrada. En una salida y en un
// conteo lo resuelve el costo promedio del artículo: sacar unidades, o
// encontrarlas, no cambia cuánto costaron.
const costIsEditable = computed(() =>
    form.operation === 'goods_receipt' || form.operation === 'purchase_receipt'
);

const quantityLabel = computed(() => form.operation === 'count_adjustment' ? 'Cantidad contada' : 'Cantidad');

const ready = computed(() => props.documentTypes.length && props.items.length && props.warehouses.length);

// Cambiar de operación cambia qué lotes son elegibles: una salida solo
// ofrece los despachables con saldo (FEFO), una entrada ofrece todos los
// vigentes. Sin esto, la lista quedaría con las opciones de la operación
// anterior.
watch(() => form.operation, () => {
    form.lines.forEach((_, index) => onLineContextChange(index));
});

function addLine() {
    form.lines.push(blankLine());
}

function removeLine(index) {
    if (form.lines.length === 1) return;
    form.lines.splice(index, 1);
}

function avgCostOf(itemId) {
    const item = props.items.find((i) => i.id === itemId);
    return item ? Number(item.avg_cost_local) : null;
}

function submit() {
    form
        .transform((data) => ({
            ...data,
            business_partner_id: needsSupplier.value && data.business_partner_id !== '' ? data.business_partner_id : null,
            purchase_order_id: needsSupplier.value && data.purchase_order_id !== '' ? data.purchase_order_id : null,
            // Si la operación no admite importación, los campos viajan vacíos
            // aunque hayan quedado escritos antes de cambiar de operación.
            is_import: canBeImport.value && data.is_import,
            customs_declaration: canBeImport.value && data.customs_declaration !== '' ? data.customs_declaration : null,
            customs_office: canBeImport.value && data.customs_office !== '' ? data.customs_office : null,
            transport_document: canBeImport.value && data.transport_document !== '' ? data.transport_document : null,
            origin_country: canBeImport.value && data.origin_country !== '' ? data.origin_country : null,
            customs_date: canBeImport.value && data.customs_date !== '' ? data.customs_date : null,
            lines: data.lines.map((line) => ({
                ...line,
                warehouse_bin_id: line.warehouse_bin_id === '' ? null : line.warehouse_bin_id,
                item_lot_id: line.item_lot_id === '' ? null : line.item_lot_id,
                unit_cost_local: costIsEditable.value && line.unit_cost_local !== '' ? line.unit_cost_local : null,
                description: line.description === '' ? null : line.description,
            })),
        }))
        .post(route('inventory-movements.store'));
}
</script>

<template>
    <Head title="Nuevo movimiento de inventario" />

    <AppLayout title="Nuevo movimiento de inventario">
        <div class="view-toolbar">
            <Link :href="route('inventory-movements.index')" class="btn btn-ghost"><ArrowLeftIcon /> Movimientos</Link>
        </div>

        <div v-if="page.props.errors?.lines" class="flash flash-error">{{ page.props.errors.lines }}</div>

        <p v-if="!ready" class="flash flash-warning">
            Para registrar movimientos hacen falta: un tipo de documento con módulo de origen "Inventario", al menos un
            artículo de inventario activo y al menos un almacén activo.
        </p>

        <form class="card form-card" @submit.prevent="submit">
            <div class="form-grid">
                <div class="field">
                    <label for="mov-operation">Operación</label>
                    <select id="mov-operation" v-model="form.operation">
                        <option v-for="(label, key) in operations" :key="key" :value="key">{{ label }}</option>
                    </select>
                </div>

                <div class="field">
                    <label for="mov-doc-type">Tipo de documento</label>
                    <select id="mov-doc-type" v-model="form.document_type_id" required>
                        <option value="" disabled>— Elegir —</option>
                        <option v-for="t in documentTypes" :key="t.id" :value="t.id">{{ t.code }} — {{ t.name }}</option>
                    </select>
                    <span v-if="form.errors.document_type_id" class="error">{{ form.errors.document_type_id }}</span>
                </div>

                <div class="field">
                    <label for="mov-doc-date">Fecha del documento</label>
                    <input id="mov-doc-date" v-model="form.document_date" type="date" required>
                </div>

                <div class="field">
                    <label for="mov-posting-date">Fecha de contabilización</label>
                    <input id="mov-posting-date" v-model="form.posting_date" type="date" required>
                    <span v-if="form.errors.posting_date" class="error">{{ form.errors.posting_date }}</span>
                </div>
            </div>

            <div class="form-grid">
                <div v-if="needsSupplier" class="field">
                    <label for="mov-supplier">Proveedor</label>
                    <select id="mov-supplier" v-model="form.business_partner_id" required>
                        <option value="" disabled>— Elegir —</option>
                        <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.code }} — {{ s.name }}</option>
                    </select>
                    <span v-if="form.errors.business_partner_id" class="error">{{ form.errors.business_partner_id }}</span>
                </div>

                <div v-if="needsSupplier" class="field">
                    <label for="mov-po">Orden de compra (opcional)</label>
                    <select id="mov-po" v-model="form.purchase_order_id">
                        <option value="">Sin orden previa</option>
                        <option v-for="o in ordersOfSupplier" :key="o.id" :value="o.id">{{ o.label }}</option>
                    </select>
                    <span class="muted small">
                        Enlazarla descarga lo recibido de su pendiente. No toda compra pasa por una orden formal.
                    </span>
                </div>

                <div class="field">
                    <label for="mov-description">Descripción (opcional)</label>
                    <input id="mov-description" v-model="form.description" type="text" maxlength="255">
                </div>
            </div>

            <p v-if="needsSupplier" class="hint">
                La deuda queda en la <strong>cuenta puente GR/IR</strong> hasta que llegue la factura del proveedor. Vas a
                poder liquidarla desde "Facturas de proveedor".
            </p>

            <template v-if="canBeImport">
                <label class="check">
                    <input v-model="form.is_import" type="checkbox">
                    <span>
                        <strong>Esta entrada es una importación</strong>
                        <span class="muted small">
                            Marcarla es lo que permite cargarle después los rubros de nacionalización —flete,
                            aranceles, agencia—. Una compra local no los admite.
                        </span>
                    </span>
                </label>

                <div v-if="form.is_import" class="import-box">
                    <div class="form-grid">
                        <div class="field">
                            <label for="mov-dua">Número de DUA</label>
                            <input id="mov-dua" v-model="form.customs_declaration" type="text" maxlength="40" placeholder="005-2026-123456">
                            <span v-if="form.errors.customs_declaration" class="error">{{ form.errors.customs_declaration }}</span>
                        </div>
                        <div class="field">
                            <label for="mov-customs">Aduana</label>
                            <select id="mov-customs" v-model="form.customs_office">
                                <option value="">— Sin indicar —</option>
                                <option v-for="(label, key) in customsOffices" :key="key" :value="key">{{ label }}</option>
                            </select>
                        </div>
                        <div class="field">
                            <label for="mov-customs-date">Fecha del DUA</label>
                            <input id="mov-customs-date" v-model="form.customs_date" type="date">
                        </div>
                        <div class="field">
                            <label for="mov-transport">Documento de transporte</label>
                            <input id="mov-transport" v-model="form.transport_document" type="text" maxlength="60" placeholder="BL, guía aérea o carta de porte">
                        </div>
                        <div class="field">
                            <label for="mov-origin">País de origen</label>
                            <input id="mov-origin" v-model="form.origin_country" type="text" maxlength="60">
                        </div>
                    </div>
                </div>
            </template>

            <p v-if="form.operation === 'count_adjustment'" class="hint">
                En un conteo físico, la cantidad es la <strong>existencia contada</strong>, no la diferencia: el sistema
                calcula el ajuste contra lo que tiene registrado. Una línea que coincide no genera movimiento.
            </p>

            <!-- Grilla de captura: cada línea se edita en la tabla y conserva su
                 botón de quitar (CLAUDE.md secc. 20, excepción). Anchos fijos
                 para lo de largo conocido: cabe en los ~720px que deja la barra
                 lateral a 1025px; en ≤ 1024px, una tarjeta por línea. -->
            <div class="table-responsive capture-grid lines-wrap">
                <table class="lines-table">
                    <colgroup>
                        <col>
                        <col class="col-warehouse">
                        <col v-if="anyWarehouseUsesBins()" class="col-bin">
                        <col v-if="anyItemTracksLots()" class="col-lot">
                        <col class="col-qty">
                        <col class="col-cost">
                        <col class="col-detail">
                        <col class="col-remove">
                    </colgroup>
                    <thead>
                        <tr>
                            <th>Artículo</th>
                            <th>Almacén</th>
                            <th v-if="anyWarehouseUsesBins()">Ubicación</th>
                            <th v-if="anyItemTracksLots()">Lote</th>
                            <th class="num">{{ quantityLabel }}</th>
                            <th class="num">Costo unitario</th>
                            <th>Detalle</th>
                            <th><span class="sr-only">Quitar</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(line, index) in form.lines" :key="index">
                            <td>
                                <select v-model="line.item_id" required aria-label="Artículo" @change="onLineContextChange(index)">
                                    <option value="" disabled>— Elegir —</option>
                                    <option v-for="i in items" :key="i.id" :value="i.id">{{ i.code }} — {{ i.name }}</option>
                                </select>
                            </td>
                            <td data-label="Almacén">
                                <select
                                    v-model="line.warehouse_id"
                                    required
                                    aria-label="Almacén"
                                    @change="line.warehouse_bin_id = ''; onLineContextChange(index)"
                                >
                                    <option value="" disabled>— Elegir —</option>
                                    <option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.code }}</option>
                                </select>
                            </td>
                            <td v-if="anyWarehouseUsesBins()" data-label="Ubicación">
                                <select
                                    v-if="usesBins(line.warehouse_id)"
                                    v-model="line.warehouse_bin_id"
                                    required
                                    aria-label="Ubicación"
                                    @change="onLineContextChange(index)"
                                >
                                    <option value="" disabled>— Elegir —</option>
                                    <option v-for="b in binsOf(line.warehouse_id)" :key="b.id" :value="b.id">{{ b.code }}</option>
                                </select>
                                <span v-else class="muted small">—</span>
                            </td>
                            <td v-if="anyItemTracksLots()" data-label="Lote">
                                <template v-if="tracksLots(line.item_id)">
                                    <select v-model="line.item_lot_id" required aria-label="Lote">
                                        <option value="" disabled>— Elegir —</option>
                                        <option
                                            v-for="(l, pos) in (lotOptions[index]?.lots ?? [])"
                                            :key="l.id"
                                            :value="l.id"
                                        >
                                            {{ l.code }}{{ l.expires_at ? ` · vence ${l.expires_at}` : '' }}{{ l.on_hand !== null ? ` · ${l.on_hand}` : '' }}{{ lotOptions[index]?.fefo && pos === 0 ? ' · sugerido' : '' }}
                                        </option>
                                    </select>
                                    <span v-if="lotOptions[index] && !lotOptions[index].lots.length" class="error small">
                                        Sin lotes disponibles acá.
                                    </span>
                                </template>
                                <span v-else class="muted small">—</span>
                            </td>
                            <td :data-label="quantityLabel" class="num">
                                <input v-model="line.quantity" type="number" step="0.000001" min="0" required class="num-input" :aria-label="quantityLabel">
                            </td>
                            <td data-label="Costo unitario" class="num">
                                <input
                                    v-if="costIsEditable"
                                    v-model="line.unit_cost_local"
                                    type="number" step="0.000001" min="0" required class="num-input"
                                    aria-label="Costo unitario"
                                >
                                <span v-else class="muted small">
                                    {{ line.item_id ? `promedio ${avgCostOf(line.item_id)?.toFixed(2) ?? '—'}` : 'promedio' }}
                                </span>
                            </td>
                            <td data-label="Detalle"><input v-model="line.description" type="text" maxlength="255" aria-label="Detalle"></td>
                            <td class="remove-cell">
                                <button
                                    type="button" class="btn btn-ghost remove-btn"
                                    :disabled="form.lines.length === 1"
                                    aria-label="Quitar línea"
                                    title="Quitar línea"
                                    @click="removeLine(index)"
                                ><XIcon /></button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="form-actions lines-actions">
                <button type="button" class="btn btn-ghost" @click="addLine"><PlusIcon /> Agregar línea</button>
                <button type="submit" class="btn btn-primary" :disabled="form.processing || !ready">
                    <CheckIcon /> Contabilizar movimiento
                </button>
            </div>
        </form>
    </AppLayout>
</template>

<style scoped>
.form-card { padding: 1.25rem; }

.hint { margin: 0.25rem 0 0.75rem; }

.check { align-items: flex-start; margin: 0.25rem 0 0.75rem; }
.check span { display: flex; flex-direction: column; gap: 0.1rem; }

/* Los datos del trámite aduanal, separados del resto del encabezado: son de
   otra naturaleza y solo aparecen cuando la entrada es importación. */
.import-box {
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm, 6px);
    padding: 0.85rem 1rem 0.1rem;
    margin-bottom: 0.75rem;
}

.lines-wrap { margin-top: 0.5rem; }
.lines-table { table-layout: fixed; font-size: 0.85rem; }
.lines-table th, .lines-table td { padding: 0.4rem 0.5rem; }
.col-warehouse { width: 6rem; }
.col-bin { width: 6rem; }
.col-lot { width: 8rem; }
.col-qty { width: 6rem; }
.col-cost { width: 7rem; }
.col-detail { width: 11%; }
.col-remove { width: 2.75rem; }
.lines-table select,
.lines-table input { width: 100%; min-width: 0; }
.num-input { text-align: right; }
.remove-btn { width: 2.25rem; padding: 0; }
.lines-actions { justify-content: space-between; margin-top: 1rem; }

@media screen and (max-width: 1024px) {
    .remove-cell { justify-content: flex-end; }
}
</style>
