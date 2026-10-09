<script setup>
import { Head, Link, useForm, router, usePage } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import { DownloadIcon, PencilIcon, PlusIcon, PrinterIcon, ScanBarcodeIcon, UploadIcon, WandSparklesIcon } from '@lucide/vue';
import AppLayout from '../../../Layouts/AppLayout.vue';
import DetailModal from '../../../Components/DetailModal.vue';
import BarcodePreview from '../../../Components/BarcodePreview.vue';
import BarcodeScanner from '../../../Components/BarcodeScanner.vue';
import BarcodePhoneLink from '../../../Components/BarcodePhoneLink.vue';
import { canCaptureHere } from '../../../Utils/camera';
import { requestJson } from '../../../Utils/http';
import { confirmAction } from '../../../Utils/confirm';
import { useCrudModal } from '../../../Utils/crudModal';

const props = defineProps({
    items: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    itemGroups: { type: Array, default: () => [] },
    unitsOfMeasure: { type: Array, default: () => [] },
    taxRates: { type: Array, default: () => [] },
    fiscalUnits: { type: Object, default: () => ({}) },
    fiscalIvaRates: { type: Object, default: () => ({}) },
    accounts: { type: Array, default: () => [] },
    accountCategories: { type: Object, default: () => ({}) },
    itemAccounts: { type: Object, default: () => ({}) },
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
        // '' significa "sin cuenta propia": viaja como null para que el
        // servidor borre la regla y el artículo vuelva a heredar.
        accounts: Object.fromEntries(
            Object.entries(data.accounts ?? {}).map(([k, v]) => [k, v === '' ? null : v])
        ),
    };
}

// Ficha, alta y edición del artículo en un solo modal (CLAUDE.md secc. 20 y
// 21). Crear y editar comparten los campos salvo el código.
const { mode, selected, modalOpen, form, openCreate, openDetail, close, startEdit, cancelForm, submit } = useCrudModal({
    records: () => rows.value,
    defaults: () => ({
        code: '',
        name: '',
        item_group_id: '',
        uom_id: '',
        barcode: '',
        is_inventory_item: true,
        is_sales_item: true,
        is_purchase_item: true,
        tracks_lots: false,
        tracks_serials: false,
        minimum_stock: 0,
        maximum_stock: '',
        cabys_code: '',
        fiscal_unit_code: '',
        iva_rate_code: '',
        tax_rate_id: '',
        status: 'active',
        // Cuentas por categoría. Vacío = heredar del grupo, del almacén o
        // de la compañía, en ese orden.
        accounts: {},
    }),
    toForm: (item) => ({
        code: item.code,
        name: item.name,
        item_group_id: item.item_group_id ?? '',
        uom_id: item.uom_id,
        barcode: item.barcode ?? '',
        is_inventory_item: item.is_inventory_item,
        is_sales_item: item.is_sales_item,
        is_purchase_item: item.is_purchase_item,
        tracks_lots: item.tracks_lots,
        tracks_serials: item.tracks_serials,
        minimum_stock: item.minimum_stock ?? 0,
        maximum_stock: item.maximum_stock ?? '',
        cabys_code: item.cabys_code ?? '',
        fiscal_unit_code: item.fiscal_unit_code ?? '',
        iva_rate_code: item.iva_rate_code ?? '',
        tax_rate_id: item.tax_rate_id ?? '',
        status: item.status,
        accounts: { ...(props.itemAccounts[item.id] ?? {}) },
    }),
    store: () => route('items.store'),
    update: (item) => route('items.update', item.id),
    storePayload: normalize,
    updatePayload: ({ code, ...data }) => normalize(data),
});

const modalTitle = computed(() => {
    if (mode.value === 'create') return 'Nuevo artículo';
    return selected.value ? `${selected.value.code} — ${selected.value.name}` : '';
});

const taxRateLabels = computed(() => Object.fromEntries(props.taxRates.map((t) => [t.id, `${t.code} — ${t.percentage}%`])));
const accountLabels = computed(() => Object.fromEntries(props.accounts.map((a) => [a.id, a.label])));

const selectedAccounts = computed(() => {
    if (! selected.value) return [];
    const own = props.itemAccounts[selected.value.id] ?? {};

    return Object.entries(props.accountCategories)
        .filter(([key]) => own[key])
        .map(([key, label]) => ({ key, label, account: accountLabels.value[own[key]] ?? own[key] }));
});

// --- código de barras: escanearlo o generar uno interno ---
// (ItemBarcodeService). Se dibuja con BarcodePreview mientras se escribe.
//
// Escanear es como el escaneo de documentos de Conti (CLAUDE.md secc. 33):
// en un teléfono o una tableta con cámara, ahí mismo (BarcodeScanner); en una
// computadora, un QR para hacerlo con el teléfono (BarcodePhoneLink), con la
// opción de usar la cámara o una foto de la computadora.

const scanMode = ref(null); // null | 'here' | 'phone'
const onPhoneDevice = ref(false);
const generating = ref(false);
const barcodeError = ref('');

// Al cerrar o cambiar de modo, la cámara y el QR se apagan y no vuelven solos.
watch([modalOpen, mode], () => {
    scanMode.value = null;
    barcodeError.value = '';
});

async function toggleScan() {
    if (scanMode.value) {
        scanMode.value = null;
        return;
    }
    onPhoneDevice.value = await canCaptureHere();
    scanMode.value = onPhoneDevice.value ? 'here' : 'phone';
}

function onScanned(code) {
    scanMode.value = null;
    form.barcode = code;
    form.clearErrors('barcode');
}

async function generateBarcode() {
    generating.value = true;
    barcodeError.value = '';
    const result = await requestJson(route('items.next-barcode'));
    generating.value = false;

    if (!result.ok) {
        barcodeError.value = result.data?.message ?? 'No se pudo generar el código. Probá de nuevo en un momento.';
        return;
    }
    form.barcode = result.data.barcode;
    form.clearErrors('barcode');
}

function destroy() {
    const item = selected.value;

    confirmAction({
        title: 'Eliminar artículo',
        message: `El artículo ${item.code} — ${item.name} se elimina de forma definitiva.`,
        confirmLabel: 'Eliminar',
        danger: true,
        onConfirm: () => router.delete(route('items.destroy', item.id), { preserveScroll: true }),
    });
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
</script>

<template>
    <Head title="Artículos" />

    <AppLayout title="Artículos">
        <div class="view-toolbar">
            <div class="view-actions">
                <a
                    :href="route('items.template')"
                    class="btn btn-ghost"
                    title="Trae el catálogo actual y las hojas con los códigos válidos"
                ><DownloadIcon /> Descargar plantilla</a>
                <label class="btn btn-ghost file-btn" :class="{ disabled: importForm.processing }" title="Los códigos que ya existen se actualizan">
                    <UploadIcon /> {{ importForm.processing ? 'Subiendo...' : 'Importar XLSX' }}
                    <input ref="fileInput" type="file" accept=".xlsx" :disabled="importForm.processing" @change="onFileSelected">
                </label>
                <button type="button" class="btn btn-primary" :disabled="!unitsOfMeasure.length" @click="openCreate()">
                    <PlusIcon /> Crear nuevo
                </button>
            </div>
            <div class="view-filters">
                <input
                    v-model="search"
                    type="search"
                    placeholder="Buscar código, nombre o código de barras..."
                    aria-label="Buscar artículo"
                    @input="onSearchInput"
                >
                <select v-model="itemGroupId" aria-label="Grupo" @change="applyFilters">
                    <option value="">Todos los grupos</option>
                    <option v-for="g in itemGroups" :key="g.id" :value="g.id">{{ g.code }} — {{ g.name }}</option>
                </select>
                <select v-model="status" aria-label="Estado" @change="applyFilters">
                    <option value="">Todos los estados</option>
                    <option value="active">Activos</option>
                    <option value="inactive">Inactivos</option>
                </select>
            </div>
        </div>

        <p v-if="importForm.errors.file" class="flash flash-error">{{ importForm.errors.file }}</p>

        <div v-if="importErrors.length" class="import-errors">
            <p>No se importó nada porque el archivo tiene {{ importErrors.length }} error(es). Corregilos y subilo de nuevo:</p>
            <ul>
                <li v-for="(msg, i) in importErrors" :key="i">{{ msg }}</li>
            </ul>
        </div>

        <div v-if="page.props.errors?.item" class="flash flash-error">{{ page.props.errors.item }}</div>

        <p v-if="!unitsOfMeasure.length" class="flash flash-warning">
            Todavía no hay unidades de medida activas. Creá al menos una antes de registrar artículos.
        </p>

        <p class="hint">
            Existencias y costo promedio no se cargan ni se editan acá: los mantiene el motor de movimientos, porque cada
            cambio de costo tiene que generar su asiento. Las existencias iniciales entran por una entrada de mercancía.
        </p>

        <div class="card">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Código</th>
                            <th>Nombre</th>
                            <th>Tipo</th>
                            <th class="num">Existencia</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="i in rows"
                            :key="i.id"
                            class="clickable-row"
                            tabindex="0"
                            @click="openDetail(i)"
                            @keydown.enter="openDetail(i)"
                            @keydown.space.prevent="openDetail(i)"
                        >
                            <td>
                                <span class="code-cell">
                                    {{ i.code }}
                                    <span
                                        v-if="i.is_sales_item && !i.cabys_code"
                                        class="badge badge-warning"
                                        title="Se vende pero no tiene código CAByS: habrá que teclearlo en cada factura"
                                    >Sin CAByS</span>
                                </span>
                            </td>
                            <td data-label="Nombre">{{ i.name }}</td>
                            <td data-label="Tipo">
                                <span class="badge" :class="i.is_inventory_item ? 'badge-success' : 'badge-neutral'">
                                    {{ i.is_inventory_item ? 'Inventario' : 'Servicio' }}
                                </span>
                            </td>
                            <td data-label="Existencia" class="num">{{ i.is_inventory_item ? quantity(i.on_hand) : '—' }}</td>
                            <td data-label="Estado">
                                <span class="badge" :class="i.status === 'active' ? 'badge-success' : 'badge-neutral'">
                                    {{ i.status === 'active' ? 'Activo' : 'Inactivo' }}
                                </span>
                            </td>
                        </tr>
                        <tr v-if="!rows.length">
                            <td colspan="5" class="muted empty-row">
                                {{ filters.search || filters.item_group_id || filters.status
                                    ? 'Ningún artículo coincide con los filtros.'
                                    : 'Todavía no hay artículos registrados.' }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <nav v-if="items.links.length > 3" class="pagination" aria-label="Páginas">
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

        <DetailModal :open="modalOpen" :title="modalTitle" wide @close="close">
            <template #badge>
                <span v-if="selected && mode !== 'create'" class="badge" :class="selected.status === 'active' ? 'badge-success' : 'badge-neutral'">
                    {{ selected.status === 'active' ? 'Activo' : 'Inactivo' }}
                </span>
            </template>

            <template v-if="selected && mode === 'details'">
                <dl class="detail-list detail-grid">
                    <div>
                        <dt>Tipo</dt>
                        <dd>{{ selected.is_inventory_item ? 'Inventario' : 'Servicio' }}</dd>
                    </div>
                    <div>
                        <dt>Grupo</dt>
                        <dd>{{ selected.item_group ? [selected.item_group.code, selected.item_group.name].filter(Boolean).join(' — ') : '—' }}</dd>
                    </div>
                    <div>
                        <dt>Unidad de medida</dt>
                        <dd>{{ selected.unit_of_measure?.code ?? '—' }}</dd>
                    </div>
                    <template v-if="selected.is_inventory_item">
                        <div>
                            <dt>Existencia</dt>
                            <dd>{{ quantity(selected.on_hand) }}</dd>
                        </div>
                        <div>
                            <dt>Costo promedio (LC)</dt>
                            <dd>{{ money(selected.avg_cost_local) }}</dd>
                        </div>
                        <div>
                            <dt>Costo promedio (FC)</dt>
                            <dd>{{ money(selected.avg_cost_foreign) }}</dd>
                        </div>
                        <div>
                            <dt>Mínimo / máximo</dt>
                            <dd>{{ quantity(selected.minimum_stock) }} / {{ selected.maximum_stock ? quantity(selected.maximum_stock) : '—' }}</dd>
                        </div>
                    </template>
                    <div>
                        <dt>Se compra / se vende</dt>
                        <dd>{{ selected.is_purchase_item ? 'Sí' : 'No' }} / {{ selected.is_sales_item ? 'Sí' : 'No' }}</dd>
                    </div>
                    <div>
                        <dt>Lotes / series</dt>
                        <dd>{{ selected.tracks_lots ? 'Lotes' : '—' }} / {{ selected.tracks_serials ? 'Series' : '—' }}</dd>
                    </div>
                    <div>
                        <dt>Código de barras</dt>
                        <dd>
                            <BarcodePreview v-if="selected.barcode" :value="selected.barcode" compact />
                            <template v-else>—</template>
                        </dd>
                    </div>
                    <div>
                        <dt>Indicador de impuesto</dt>
                        <dd>{{ taxRateLabels[selected.tax_rate_id] ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt>Código CAByS</dt>
                        <dd>{{ selected.cabys_code || '—' }}</dd>
                    </div>
                    <div>
                        <dt>Unidad / tarifa de Hacienda</dt>
                        <dd>{{ selected.fiscal_unit_code || '—' }} / {{ fiscalIvaRates[selected.iva_rate_code] ?? '—' }}</dd>
                    </div>
                </dl>

                <h3 class="section-title">Cuentas contables propias</h3>
                <dl v-if="selectedAccounts.length" class="detail-list">
                    <div v-for="row in selectedAccounts" :key="row.key" class="full">
                        <dt>{{ row.label }}</dt>
                        <dd>{{ row.account }}</dd>
                    </div>
                </dl>
                <p v-else class="muted small">Ninguna: todas se heredan del grupo, del almacén o de la compañía.</p>
            </template>

            <form v-if="mode === 'create' || (selected && mode === 'edit')" id="item-form" @submit.prevent="submit">
                <p v-if="mode === 'edit'" class="muted small">El código no se puede cambiar una vez creado el artículo.</p>

                <div class="form-grid">
                    <div v-if="mode === 'create'" class="field">
                        <label for="item-code">Código</label>
                        <input id="item-code" v-model="form.code" type="text" maxlength="40" required>
                        <span v-if="form.errors.code" class="error">{{ form.errors.code }}</span>
                    </div>

                    <div class="field">
                        <label for="item-name">Nombre</label>
                        <input id="item-name" v-model="form.name" type="text" required>
                        <span v-if="form.errors.name" class="error">{{ form.errors.name }}</span>
                    </div>

                    <div class="field">
                        <label for="item-group">Grupo (opcional)</label>
                        <select id="item-group" v-model="form.item_group_id">
                            <option value="">— Sin grupo —</option>
                            <option v-for="g in itemGroups" :key="g.id" :value="g.id">{{ g.code }} — {{ g.name }}</option>
                        </select>
                        <span v-if="form.errors.item_group_id" class="error">{{ form.errors.item_group_id }}</span>
                    </div>

                    <div class="field">
                        <label for="item-uom">Unidad de medida</label>
                        <select id="item-uom" v-model="form.uom_id" required>
                            <option value="" disabled>— Elegir —</option>
                            <option v-for="u in unitsOfMeasure" :key="u.id" :value="u.id">{{ u.code }} — {{ u.name }}</option>
                        </select>
                        <span v-if="form.errors.uom_id" class="error">{{ form.errors.uom_id }}</span>
                    </div>

                    <div class="field">
                        <label for="item-tax">Indicador de impuesto (opcional)</label>
                        <select id="item-tax" v-model="form.tax_rate_id">
                            <option value="">— Ninguno —</option>
                            <option v-for="t in taxRates" :key="t.id" :value="t.id">
                                {{ t.code }} — {{ t.percentage }}%
                            </option>
                        </select>
                        <span v-if="form.errors.tax_rate_id" class="error">{{ form.errors.tax_rate_id }}</span>
                    </div>

                    <div class="field">
                        <label for="item-status">Estado</label>
                        <select id="item-status" v-model="form.status">
                            <option value="active">Activo</option>
                            <option value="inactive">Inactivo</option>
                        </select>
                    </div>

                    <!-- El código de barras: escrito, escaneado o generado, y dibujado abajo. -->
                    <div class="field span-full barcode-field">
                        <label for="item-barcode">Código de barras (opcional)</label>
                        <div class="barcode-input">
                            <input
                                id="item-barcode"
                                v-model="form.barcode"
                                type="text"
                                autocomplete="off"
                                spellcheck="false"
                                placeholder="Escribilo, escanealo o generá uno"
                                @input="form.clearErrors('barcode')"
                            >
                            <button type="button" class="btn btn-ghost" :aria-expanded="scanMode !== null" @click="toggleScan">
                                <ScanBarcodeIcon /> Escanear
                            </button>
                            <button
                                type="button"
                                class="btn btn-ghost"
                                :disabled="generating || !!form.barcode"
                                :data-busy="generating ? '' : null"
                                :aria-busy="generating"
                                :title="form.barcode ? 'Borrá el código actual para generar otro' : 'Un código interno, que ningún otro artículo tiene'"
                                @click="generateBarcode"
                            >
                                <WandSparklesIcon /> Generar
                            </button>
                        </div>
                        <span v-if="form.errors.barcode" class="error">{{ form.errors.barcode }}</span>
                        <span v-if="barcodeError" class="error">{{ barcodeError }}</span>
                        <p class="hint small">
                            Con un lector USB, hacé clic en el campo y escaneá. «Generar» crea un código interno (empieza
                            en 200, el rango reservado para uso dentro del negocio) para lo que no trae uno de fábrica.
                        </p>
                        <BarcodeScanner
                            v-if="scanMode === 'here'"
                            :phone-option="!onPhoneDevice"
                            @detected="onScanned"
                            @use-phone="scanMode = 'phone'"
                            @close="scanMode = null"
                        />
                        <BarcodePhoneLink
                            v-else-if="scanMode === 'phone'"
                            :label="form.name || form.code || ''"
                            @detected="onScanned"
                            @use-here="scanMode = 'here'"
                            @close="scanMode = null"
                        />
                        <BarcodePreview :value="form.barcode" />
                    </div>
                </div>

                <label class="check">
                    <input v-model="form.is_inventory_item" type="checkbox">
                    Lleva inventario (desmarcado = servicio: se compra/vende pero no lleva kardex ni costo)
                </label>
                <span v-if="form.errors.is_inventory_item" class="error-text">{{ form.errors.is_inventory_item }}</span>

                <label class="check">
                    <input v-model="form.is_purchase_item" type="checkbox">
                    Se compra
                </label>

                <label class="check">
                    <input v-model="form.is_sales_item" type="checkbox">
                    Se vende
                </label>

                <label class="check">
                    <input v-model="form.tracks_lots" type="checkbox" :disabled="!form.is_inventory_item">
                    Maneja lotes (cada movimiento va a exigir número de lote)
                </label>
                <p class="hint small">
                    Los lotes son trazabilidad y vencimiento, no valoración: el costo sigue siendo promedio global
                    del artículo. Activalo para medicamentos, alimentos, químicos o cualquier cosa que haya que poder
                    rastrear o que caduque.
                </p>

                <label class="check">
                    <input v-model="form.tracks_serials" type="checkbox" :disabled="!form.is_inventory_item">
                    Maneja números de serie (una serie por unidad en cada movimiento)
                </label>
                <p class="hint small">
                    A diferencia del lote, que es un balde con cantidad, una serie es una unidad: cada movimiento
                    va a exigir <strong>exactamente una serie por unidad</strong> y el artículo no va a admitir
                    cantidades fraccionarias. Activalo para equipos, electrodomésticos o cualquier cosa con
                    garantía individual. Tampoco toca el costeo.
                </p>

                <template v-if="form.is_inventory_item">
                    <div class="form-grid">
                        <div class="field">
                            <label for="item-min">Mínimo de existencia</label>
                            <input id="item-min" v-model="form.minimum_stock" type="number" step="0.000001" min="0">
                            <span v-if="form.errors.minimum_stock" class="error">{{ form.errors.minimum_stock }}</span>
                        </div>

                        <div class="field">
                            <label for="item-max">Máximo (opcional)</label>
                            <input id="item-max" v-model="form.maximum_stock" type="number" step="0.000001" min="0" placeholder="—">
                            <span v-if="form.errors.maximum_stock" class="error">{{ form.errors.maximum_stock }}</span>
                        </div>
                    </div>

                    <p class="hint small">
                        Es el nivel <strong>por defecto</strong> del artículo: alimenta la sugerencia de compra y
                        aplica en todos los almacenes, salvo en los que definan el suyo propio desde
                        <em>Niveles</em>. El mínimo dispara la reposición; el máximo dice hasta dónde reponer.
                        En cero significa <strong>sin control de reorden</strong>.
                    </p>
                </template>

                <h3 class="section-title">Cuentas contables</h3>
                <p class="hint small">
                    Lo que se deje vacío se hereda, en este orden:
                    <strong>grupo del artículo → almacén → compañía</strong>.
                    Solo hace falta llenar acá lo que este artículo tenga distinto.
                </p>

                <div class="form-grid">
                    <div v-for="(label, key) in accountCategories" :key="key" class="field">
                        <label :for="`item-account-${key}`">{{ label }}</label>
                        <select :id="`item-account-${key}`" v-model="form.accounts[key]">
                            <option value="">Heredar del grupo, almacén o compañía</option>
                            <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.label }}</option>
                        </select>
                    </div>
                </div>

                <h3 class="section-title">Datos para factura electrónica</h3>

                <div class="form-grid">
                    <div class="field">
                        <label for="item-cabys">Código CAByS</label>
                        <input
                            id="item-cabys"
                            v-model="form.cabys_code"
                            type="text" inputmode="numeric" maxlength="13" placeholder="13 dígitos"
                            class="cabys-input"
                        >
                        <span v-if="form.errors.cabys_code" class="error">{{ form.errors.cabys_code }}</span>
                    </div>

                    <div class="field">
                        <label for="item-fiscal-unit">Unidad de medida de Hacienda</label>
                        <select id="item-fiscal-unit" v-model="form.fiscal_unit_code">
                            <option value="">— Sin definir —</option>
                            <option v-for="(label, code) in fiscalUnits" :key="code" :value="code">
                                {{ code }} — {{ label }}
                            </option>
                        </select>
                        <span v-if="form.errors.fiscal_unit_code" class="error">{{ form.errors.fiscal_unit_code }}</span>
                    </div>

                    <div class="field">
                        <label for="item-iva-rate">Tarifa de IVA de Hacienda</label>
                        <select id="item-iva-rate" v-model="form.iva_rate_code">
                            <option value="">— Sin definir —</option>
                            <option v-for="(label, code) in fiscalIvaRates" :key="code" :value="code">
                                {{ label }}
                            </option>
                        </select>
                        <span v-if="form.errors.iva_rate_code" class="error">{{ form.errors.iva_rate_code }}</span>
                    </div>
                </div>

                <p class="hint small">
                    El CAByS se precarga en cada línea de la factura electrónica: Hacienda lo exige por línea, así que un
                    artículo que se vende y no lo tenga acá obliga a teclearlo en <strong>cada</strong> factura. La unidad
                    de Hacienda es distinta de la unidad de medida interna: aquella es un catálogo cerrado del XML. Y la
                    tarifa de Hacienda tiene que decir el mismo porcentaje que el indicador de impuesto de arriba — si no,
                    la factura declararía un porcentaje y el asiento registraría otro; el sistema lo rechaza.
                </p>
            </form>

            <template #actions>
                <template v-if="selected && mode === 'details'">
                    <button type="button" class="btn btn-ghost btn-danger-text" @click="destroy">Eliminar</button>
                    <Link v-if="selected.is_inventory_item" :href="route('items.kardex', selected.id)" class="btn btn-ghost">Kardex</Link>
                    <Link v-if="selected.tracks_lots" :href="route('item-lots.index', selected.id)" class="btn btn-ghost">Lotes</Link>
                    <Link v-if="selected.tracks_serials" :href="route('item-serials.index', selected.id)" class="btn btn-ghost">Series</Link>
                    <Link v-if="selected.is_inventory_item" :href="route('reorder.levels', selected.id)" class="btn btn-ghost">Niveles</Link>
                    <a
                        v-if="selected.barcode"
                        :href="route('items.labels', selected.id)"
                        target="_blank"
                        rel="noopener"
                        class="btn btn-ghost"
                        title="Etiquetas con el código de barras, para imprimir"
                    ><PrinterIcon /> Etiquetas</a>
                    <button type="button" class="btn btn-primary" @click="startEdit"><PencilIcon /> Editar</button>
                </template>
                <template v-else-if="modalOpen">
                    <button type="button" class="btn btn-ghost" @click="cancelForm">Cancelar</button>
                    <button type="submit" form="item-form" class="btn btn-primary" :disabled="form.processing">
                        {{ mode === 'create' ? 'Crear' : 'Guardar' }}
                    </button>
                </template>
            </template>
        </DetailModal>
    </AppLayout>
</template>

<style scoped>
table { font-size: 0.85rem; }
.code-cell { display: inline-flex; align-items: center; flex-wrap: wrap; gap: 0.4rem; font-variant-numeric: tabular-nums; }
.cabys-input { font-variant-numeric: tabular-nums; }

/* El código de barras: el campo con sus dos botones, y el dibujo abajo. */
.barcode-field { gap: 0.45rem; }
.barcode-input { display: flex; flex-wrap: wrap; gap: 0.5rem; }
.barcode-input input { flex: 1 1 14rem; max-width: 24rem; font-variant-numeric: tabular-nums; }
.barcode-field .hint.small { margin: 0; }
.hint.small { margin-top: 0.1rem; }

/* La ficha del artículo, en el modal ancho: tres columnas de datos. */
.detail-grid { grid-template-columns: repeat(auto-fill, minmax(min(100%, 13rem), 1fr)); }
</style>
