<script setup>
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AppLayout from '../../../Layouts/AppLayout.vue';

const props = defineProps({
    operations: { type: Object, default: () => ({}) },
    documentTypes: { type: Array, default: () => [] },
    items: { type: Array, default: () => [] },
    warehouses: { type: Array, default: () => [] },
    bins: { type: Array, default: () => [] },
    suppliers: { type: Array, default: () => [] },
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
        <div v-if="page.props.errors?.lines" class="flash flash-error">{{ page.props.errors.lines }}</div>

        <p v-if="!ready" class="flash flash-warning">
            Para registrar movimientos hacen falta: un tipo de documento con módulo de origen "Inventario", al menos un
            artículo de inventario activo y al menos un almacén activo.
        </p>

        <form class="card form-card" @submit.prevent="submit">
            <div class="grid-4">
                <div class="field">
                    <label>Operación</label>
                    <select v-model="form.operation">
                        <option v-for="(label, key) in operations" :key="key" :value="key">{{ label }}</option>
                    </select>
                </div>

                <div class="field">
                    <label>Tipo de documento</label>
                    <select v-model="form.document_type_id" required>
                        <option value="" disabled>— Elegir —</option>
                        <option v-for="t in documentTypes" :key="t.id" :value="t.id">{{ t.code }} — {{ t.name }}</option>
                    </select>
                    <span v-if="form.errors.document_type_id" class="error">{{ form.errors.document_type_id }}</span>
                </div>

                <div class="field">
                    <label>Fecha del documento</label>
                    <input v-model="form.document_date" type="date" required>
                </div>

                <div class="field">
                    <label>Fecha de contabilización</label>
                    <input v-model="form.posting_date" type="date" required>
                    <span v-if="form.errors.posting_date" class="error">{{ form.errors.posting_date }}</span>
                </div>
            </div>

            <div class="grid-2">
                <div v-if="needsSupplier" class="field">
                    <label>Proveedor</label>
                    <select v-model="form.business_partner_id" required>
                        <option value="" disabled>— Elegir —</option>
                        <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.code }} — {{ s.name }}</option>
                    </select>
                    <span v-if="form.errors.business_partner_id" class="error">{{ form.errors.business_partner_id }}</span>
                </div>

                <div class="field">
                    <label>Descripción (opcional)</label>
                    <input v-model="form.description" type="text" maxlength="255">
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
                    <div class="grid-3">
                        <div class="field">
                            <label>Número de DUA</label>
                            <input v-model="form.customs_declaration" type="text" maxlength="40" placeholder="005-2026-123456">
                            <span v-if="form.errors.customs_declaration" class="error">{{ form.errors.customs_declaration }}</span>
                        </div>
                        <div class="field">
                            <label>Aduana</label>
                            <select v-model="form.customs_office">
                                <option value="">— Sin indicar —</option>
                                <option v-for="(label, key) in customsOffices" :key="key" :value="key">{{ label }}</option>
                            </select>
                        </div>
                        <div class="field">
                            <label>Fecha del DUA</label>
                            <input v-model="form.customs_date" type="date">
                        </div>
                    </div>

                    <div class="grid-2">
                        <div class="field">
                            <label>Documento de transporte</label>
                            <input v-model="form.transport_document" type="text" maxlength="60" placeholder="BL, guía aérea o carta de porte">
                        </div>
                        <div class="field">
                            <label>País de origen</label>
                            <input v-model="form.origin_country" type="text" maxlength="60">
                        </div>
                    </div>
                </div>
            </template>

            <p v-if="form.operation === 'count_adjustment'" class="hint">
                En un conteo físico, la cantidad es la <strong>existencia contada</strong>, no la diferencia: el sistema
                calcula el ajuste contra lo que tiene registrado. Una línea que coincide no genera movimiento.
            </p>

            <div class="table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>Artículo</th>
                            <th>Almacén</th>
                            <th v-if="anyWarehouseUsesBins()">Ubicación</th>
                            <th v-if="anyItemTracksLots()">Lote</th>
                            <th class="right">{{ quantityLabel }}</th>
                            <th class="right">Costo unitario</th>
                            <th>Detalle</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(line, index) in form.lines" :key="index">
                            <td>
                                <select v-model="line.item_id" required @change="onLineContextChange(index)">
                                    <option value="" disabled>— Elegir —</option>
                                    <option v-for="i in items" :key="i.id" :value="i.id">{{ i.code }} — {{ i.name }}</option>
                                </select>
                            </td>
                            <td>
                                <select
                                    v-model="line.warehouse_id"
                                    required
                                    @change="line.warehouse_bin_id = ''; onLineContextChange(index)"
                                >
                                    <option value="" disabled>— Elegir —</option>
                                    <option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.code }}</option>
                                </select>
                            </td>
                            <td v-if="anyWarehouseUsesBins()">
                                <select
                                    v-if="usesBins(line.warehouse_id)"
                                    v-model="line.warehouse_bin_id"
                                    required
                                    @change="onLineContextChange(index)"
                                >
                                    <option value="" disabled>— Elegir —</option>
                                    <option v-for="b in binsOf(line.warehouse_id)" :key="b.id" :value="b.id">{{ b.code }}</option>
                                </select>
                                <span v-else class="muted small">—</span>
                            </td>
                            <td v-if="anyItemTracksLots()">
                                <template v-if="tracksLots(line.item_id)">
                                    <select v-model="line.item_lot_id" required>
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
                            <td>
                                <input v-model="line.quantity" type="number" step="0.000001" min="0" required class="right">
                            </td>
                            <td>
                                <input
                                    v-if="costIsEditable"
                                    v-model="line.unit_cost_local"
                                    type="number" step="0.000001" min="0" required class="right"
                                >
                                <span v-else class="muted small">
                                    {{ line.item_id ? `promedio ${avgCostOf(line.item_id)?.toFixed(2) ?? '—'}` : 'promedio' }}
                                </span>
                            </td>
                            <td><input v-model="line.description" type="text" maxlength="255"></td>
                            <td>
                                <button
                                    type="button" class="btn btn-ghost"
                                    :disabled="form.lines.length === 1"
                                    @click="removeLine(index)"
                                >
                                    Quitar
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="actions">
                <button type="button" class="btn btn-ghost" @click="addLine">+ Agregar línea</button>
                <button type="submit" class="btn btn-primary" :disabled="form.processing || !ready">
                    Contabilizar movimiento
                </button>
            </div>
        </form>
    </AppLayout>
</template>

<style scoped>
.form-card { padding: 1.25rem; }

.flash { margin-bottom: 0.75rem; padding: 0.6rem 0.9rem; border-radius: var(--radius-sm); font-size: 0.85rem; }
.flash-error { background: var(--color-danger-soft); color: var(--color-danger); }
.flash-warning { background: var(--color-warning-soft); color: var(--color-warning); }

.hint { font-size: 0.82rem; color: var(--color-text-muted); margin: 0.25rem 0 0.75rem; }

.grid-4 { display: grid; grid-template-columns: repeat(4, 1fr); gap: 0 1rem; }
.grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 0 1rem; }
.grid-3 { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 0 1rem; }

.check { display: flex; gap: 0.6rem; align-items: flex-start; margin: 0.25rem 0 0.75rem; }
.check span { display: flex; flex-direction: column; gap: 0.1rem; }
.muted { color: var(--color-text-muted); }
.small { font-size: 0.76rem; }

/* Los datos del trámite aduanal, separados del resto del encabezado: son de
   otra naturaleza y solo aparecen cuando la entrada es importación. */
.import-box {
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm, 6px);
    padding: 0.85rem 1rem 0.1rem;
    margin-bottom: 0.75rem;
}

@media (max-width: 720px) { .grid-3 { grid-template-columns: 1fr; } }

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

.table-scroll { overflow-x: auto; margin-top: 0.5rem; }
table { font-size: 0.85rem; width: 100%; }
th, td { text-align: left; padding: 0.4rem 0.5rem; border-top: 1px solid var(--color-border); }
th.right, td .right { text-align: right; }
.muted { color: var(--color-text-muted); }
.small { font-size: 0.76rem; }
.error { color: var(--color-danger); font-size: 0.76rem; }

.actions { display: flex; justify-content: space-between; gap: 0.6rem; margin-top: 1rem; }
</style>
