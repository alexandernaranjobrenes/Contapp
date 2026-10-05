<script setup>
import { Head, router } from '@inertiajs/vue3';
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import MoneyInput from '../../Components/MoneyInput.vue';
import SearchableCombobox from '../../Components/SearchableCombobox.vue';
import LedgerPanel from '../../Components/LedgerPanel.vue';
import DocumentSearchModal from '../../Components/DocumentSearchModal.vue';
import RecordNav from '../../Components/RecordNav.vue';
import { confirmAction } from '../../Utils/confirm';
import { formatMoney } from '../../Utils/money';
import {
    ArrowLeftIcon, CheckIcon, CopyIcon, EllipsisIcon, KeyRoundIcon, PlusIcon, PrinterIcon, SearchIcon, TriangleAlertIcon,
    XIcon,
} from '@lucide/vue';

const props = defineProps({
    entry: { type: Object, default: null }, // presente = editando un borrador existente
    documentTypes: { type: Array, default: () => [] },
    accounts: { type: Array, default: () => [] },
    currencies: { type: Array, default: () => [] },
    costAllocationRules: { type: Array, default: () => [] },
    businessPartners: { type: Array, default: () => [] },
    company: { type: Object, default: () => ({}) },
    exchangeRates: { type: Array, default: () => [] }, // histórico reciente LC/FC, más nuevo primero
    openItems: { type: Array, default: () => [] }, // partidas abiertas (no cerradas) de la compañía, para "aplicar a partida"
    nav: { type: Object, default: () => ({ prev: null, next: null, first: null, last: null }) }, // solo viene poblado editando un borrador existente (JournalEntryController::edit) — al crear uno nuevo no hay "posición" que navegar
});

const accountsById = computed(() => Object.fromEntries(props.accounts.map((a) => [a.id, a])));
const partnersById = computed(() => Object.fromEntries(props.businessPartners.map((p) => [p.id, p])));
const documentTypesById = computed(() => Object.fromEntries(props.documentTypes.map((dt) => [dt.id, dt])));

// Opciones para SearchableCombobox (cuenta y socio): busca por código sin
// guiones o por nombre en cualquier posición — reemplaza el datalist de
// código exacto y el <select> plano, que obligaban a saber el código de
// memoria o a desplazarse por una lista larga.
const accountOptions = computed(() => props.accounts.map((a) => ({ id: a.id, code: a.code, label: a.description_es })));
const partnerOptions = computed(() => props.businessPartners.map((p) => ({ id: p.id, code: p.code, label: p.name })));

// Series disponibles para el tipo de documento elegido ahora mismo — cambiar
// de tipo de documento limpia la serie elegida, porque una serie pertenece
// a un solo tipo de documento (ver setDocumentType).
const availableSeries = computed(() => documentTypesById.value[form.document_type_id]?.number_series ?? []);

function setDocumentType(id) {
    form.document_type_id = id;
    form.number_series_id = null;
}

// La clave numérica electrónica (Hacienda) solo aplica a los tipos de
// documento que la exigen (ver DocumentTypes/Edit.vue). Un mismo asiento
// puede juntar varias facturas de compra a la vez (una línea de gasto + su
// IVA por cada una, contra una sola cuenta de pago) — así que la clave es
// por LÍNEA, no por asiento: en vez de un campo por línea (saturaría la
// tabla con un input de 50 dígitos en cada fila), hay un único editor
// "línea activa" que sigue el cursor — se asocia a la línea donde se hizo
// clic por última vez (ver setActiveLine(), enganchado a @focusin en cada
// fila de la tabla).
const requiresElectronicKey = computed(() => !! documentTypesById.value[form.document_type_id]?.requires_electronic_key);

// Protocolo de control de socio de negocio (ver DocumentType::BP_LINE_REQUIREMENTS
// y PostJournalService::post()): 'none' no muestra nada; 'due_date'/'either'
// habilitan el checkbox "abre partida"; 'application'/'either' habilitan el
// selector "aplicar a partida existente" — ambos solo en líneas modo 'partner'.
const bpLineRequirement = computed(() => documentTypesById.value[form.document_type_id]?.bp_line_requirement ?? 'none');
const bpLineRequirementLabels = {
    none: 'Ninguno',
    due_date: 'Vencimiento (abre partida)',
    application: 'Aplicación (cancela partida existente)',
    either: 'Vencimiento o aplicación',
};
const showsDueDateControl = computed(() => ['due_date', 'either'].includes(bpLineRequirement.value));
const showsApplicationControl = computed(() => ['application', 'either'].includes(bpLineRequirement.value));

// Partidas abiertas del socio de ESTA línea — el selector "aplicar a
// partida" solo tiene sentido ofreciendo partidas del mismo socio (ver
// PostJournalService::applyToExistingOpenItem(), que rechaza cualquier otra).
function openItemsForLine(line) {
    return props.openItems.filter((oi) => oi.business_partner_id === line.business_partner_id);
}

// Cada línea elige UNA sola cosa (ver guarda en JournalLineInput): marcar
// "abre partida" limpia cualquier aplicación ya elegida, y viceversa.
function onOpensItemToggle(line) {
    if (line.opens_item) line.open_item_applications = [];
}

// ── Reparto del monto de la línea entre varias partidas ─────────────────
//
// Un cobro no cancela siempre una sola factura: cancela una, abona otra y
// deja una tercera intacta. Antes acá había un desplegable de UNA partida y
// el motor le aplicaba el monto completo de la línea, así que repartir
// obligaba a partir el asiento en varias líneas contra la misma cuenta —o
// simplemente no se podía.
//
// Ahora la línea lleva una lista de {open_item_id, amount}: el usuario
// escribe cuánto va a cada factura. La regla que lo gobierna —que la suma sea
// exactamente el monto de la línea— la impone JournalLineInput; esta pantalla
// solo la hace visible mientras se digita, para no descubrirla al guardar.

const distributingLineIndex = ref(null);
const distributionDraft = ref([]);

const distributingLine = computed(
    () => (distributingLineIndex.value === null ? null : form.lines[distributingLineIndex.value] ?? null)
);

/** El monto que hay que repartir: el débito o el crédito de la línea. */
function lineAmount(line) {
    return parseFloat(line?.debit) || parseFloat(line?.credit) || 0;
}

function openDistribution(index) {
    const line = form.lines[index];

    // Se arranca del reparto ya guardado, para poder corregirlo sin rehacerlo.
    const existing = Object.fromEntries(
        (line.open_item_applications ?? []).map((a) => [a.open_item_id, a.amount])
    );

    distributionDraft.value = openItemsForLine(line).map((oi) => ({
        open_item_id: oi.id,
        label: `${oi.document_type_code}-${oi.document_number}`,
        due_date: oi.due_date,
        currency: oi.currency?.code ?? null,
        balance: parseFloat(oi.balance) || 0,
        amount: existing[oi.id] ?? '',
    }));

    distributingLineIndex.value = index;
}

function closeDistribution() {
    distributingLineIndex.value = null;
    distributionDraft.value = [];
}

/** Llena la fila con lo que falte por repartir, sin pasarse de su saldo. */
function fillRow(row) {
    const pending = Math.round((lineAmount(distributingLine.value) - distributedTotal.value) * 100) / 100
        + (parseFloat(row.amount) || 0);

    row.amount = Math.min(pending, row.balance).toFixed(2);
}

const distributedTotal = computed(
    () => distributionDraft.value.reduce((sum, r) => sum + (parseFloat(r.amount) || 0), 0)
);

// En céntimos: con decimales, 1000 + 300 no siempre da exactamente 1300.
const distributionRemaining = computed(() => {
    const target = Math.round(lineAmount(distributingLine.value) * 100);

    return (target - Math.round(distributedTotal.value * 100)) / 100;
});

const distributionRows = computed(() => distributionDraft.value.filter((r) => (parseFloat(r.amount) || 0) > 0));

/** Una fila a la que se le quiere aplicar más de lo que debe. */
function rowExceedsBalance(row) {
    return Math.round((parseFloat(row.amount) || 0) * 100) > Math.round(row.balance * 100);
}

const distributionIsValid = computed(
    () => distributionRows.value.length > 0
        && Math.round(distributionRemaining.value * 100) === 0
        && ! distributionDraft.value.some(rowExceedsBalance)
);

function confirmDistribution() {
    const line = distributingLine.value;

    line.open_item_applications = distributionRows.value.map((r) => ({
        open_item_id: r.open_item_id,
        amount: parseFloat(r.amount).toFixed(2),
    }));

    // Abrir partida y aplicar a partidas se excluyen (ver JournalLineInput).
    if (line.open_item_applications.length) line.opens_item = false;

    closeDistribution();
}

function clearDistribution(line) {
    line.open_item_applications = [];
}

/**
 * Lo que el reparto guardado le va a hacer a cada partida, ya resuelto contra
 * el saldo de cada una: cancela, abona (y cuánto queda), o se pasa.
 */
function applicationSummary(line) {
    const applications = line.open_item_applications ?? [];

    if (! applications.length) return null;

    const items = openItemsForLine(line);
    const lineCurrency = props.currencies.find((c) => c.id === line.currency_id)?.code ?? null;

    const rows = applications.map((a) => {
        const item = items.find((oi) => oi.id === a.open_item_id);
        const amount = parseFloat(a.amount) || 0;
        const balance = parseFloat(item?.balance) || 0;
        const cents = Math.round(amount * 100);
        const balanceCents = Math.round(balance * 100);

        return {
            key: a.open_item_id,
            label: item ? `${item.document_type_code}-${item.document_number}` : `#${a.open_item_id}`,
            currencyMismatch: !! (item?.currency?.code && lineCurrency && item.currency.code !== lineCurrency),
            amount,
            remaining: (balanceCents - cents) / 100,
            state: cents > balanceCents ? 'over' : (cents === balanceCents ? 'full' : 'partial'),
        };
    });

    const applied = rows.reduce((sum, r) => sum + r.amount, 0);
    const difference = (Math.round(lineAmount(line) * 100) - Math.round(applied * 100)) / 100;

    return { rows, applied, difference, balanced: difference === 0 };
}

/**
 * Qué le va a pasar a la partida cuando esta línea se contabilice.
 *
 * ── El abono parcial YA funcionaba; lo que faltaba era decirlo ──────────
 *
 * El motor aplica el monto de la línea y, si queda saldo, deja la partida en
 * «parcial» (ApplyPaymentService). Pero la pantalla mostraba el saldo de la
 * partida dentro del desplegable y el monto en la columna de débito o
 * crédito —dos lugares distintos— y dejaba la resta a cargo del usuario.
 *
 * Con una partida de ₡12.339.036,99 y un abono de ₡5.334.337,52, saber si
 * eso cancela o abona exige hacer la cuenta de cabeza, y no hay forma de
 * confirmar la intención antes de contabilizar. De ahí la impresión de que
 * el sistema «no deja hacer un abono»: lo dejaba, pero sin decirlo.
 *
 * El otro caso que resuelve: el SOBREPAGO. Aplicar más de lo que la partida
 * debe lo rechaza el motor al contabilizar, y hasta ahora eso se descubría
 * después de llenar el asiento entero. Acá se ve al escribir el monto.
 */
function openItemApplication(line) {
    if (! line.apply_to_open_item_id) return null;

    const item = openItemsForLine(line).find((oi) => oi.id === line.apply_to_open_item_id);

    if (! item) return null;

    const balance = parseFloat(item.balance) || 0;
    // Una línea lleva débito O crédito, nunca los dos: el que tenga valor es
    // el monto que se va a aplicar.
    const amount = parseFloat(line.debit) || parseFloat(line.credit) || 0;

    // La moneda de la línea y la de la partida tienen que ser la misma: ver
    // la guarda del mismo nombre en PostJournalService.
    const itemCurrency = item.currency?.code ?? null;
    const lineCurrency = props.currencies.find((c) => c.id === line.currency_id)?.code ?? null;

    if (itemCurrency && lineCurrency && itemCurrency !== lineCurrency) {
        return { state: 'currency', balance, amount, itemCurrency, lineCurrency };
    }

    if (amount <= 0) {
        return { state: 'pending', balance, amount, currency: itemCurrency };
    }

    // Se compara en céntimos para no arrastrar el error de coma flotante: con
    // decimales, 5334337.52 + 7004699.47 no da exactamente 12339036.99.
    const cents = Math.round(amount * 100);
    const balanceCents = Math.round(balance * 100);

    if (cents > balanceCents) {
        return {
            state: 'over', balance, amount, currency: itemCurrency,
            excess: (cents - balanceCents) / 100,
        };
    }

    return {
        state: cents === balanceCents ? 'full' : 'partial',
        balance,
        amount,
        currency: itemCurrency,
        remaining: (balanceCents - cents) / 100,
    };
}

// Aviso previo (no autoritativo — PostJournalService::post() es quien
// realmente lo exige al contabilizar) para que el usuario vea de un vistazo
// qué línea todavía le falta resolver antes de intentar contabilizar.
function bpLineRequirementUnmet(line) {
    if (line.mode !== 'partner' || bpLineRequirement.value === 'none') return false;

    const satisfiesDueDate = !! line.opens_item;
    // Mira el reparto, no el campo de una sola partida: ese quedó siempre
    // vacío al reemplazar el desplegable por el repartidor, así que el aviso
    // "falta aplicación" salía incluso con la aplicación ya hecha.
    const satisfiesApplication = (line.open_item_applications ?? []).length > 0;

    if (bpLineRequirement.value === 'due_date') return ! satisfiesDueDate;
    if (bpLineRequirement.value === 'application') return ! satisfiesApplication;

    return ! (satisfiesDueDate || satisfiesApplication);
}

const activeLineIndex = ref(0);

function setActiveLine(index) {
    activeLineIndex.value = index;
}

const activeLine = computed(() => form.lines[activeLineIndex.value] ?? null);

const activeLineLabel = computed(() => {
    const line = activeLine.value;
    if (! line) return '';

    if (line.mode === 'partner' && line.business_partner_id) {
        return partnersById.value[line.business_partner_id]?.name ?? '';
    }

    return accountsById.value[line.account_id]?.description_es ?? '';
});

// Segmentos oficiales de la clave de Hacienda (50 dígitos): país(3) +
// fecha ddMMyy(6) + cédula emisor(12) + consecutivo(20) + situación(1) +
// código de seguridad(8). Solo se usa para agrupar visualmente mientras se
// escribe/pega — lo que se guarda y se envía es el string de 50 dígitos
// sin espacios (line.electronic_key).
const ELECTRONIC_KEY_SEGMENTS = [3, 6, 12, 20, 1, 8];

function formatElectronicKey(digits) {
    let result = '';
    let idx = 0;

    for (const len of ELECTRONIC_KEY_SEGMENTS) {
        if (idx >= digits.length) break;
        result += (result ? ' ' : '') + digits.slice(idx, idx + len);
        idx += len;
    }

    return result;
}

const formattedActiveKey = computed(() => formatElectronicKey(activeLine.value?.electronic_key || ''));

function onActiveKeyInput(e) {
    if (! activeLine.value) return;

    activeLine.value.electronic_key = e.target.value.replace(/\D/g, '').slice(0, 50);
}

const activeLineElectronicKeyError = computed(() => form.errors[`lines.${activeLineIndex.value}.electronic_key`]);

// exchangeRates ya viene ordenado del servidor (más nuevo primero) — el
// primero cuya fecha no supera la de CONTABILIZACIÓN (la rectora, ver
// PostJournalService::post — no la del documento) es el que se resolvería
// automáticamente (rateOnOrBefore). Si el campo se deja vacío, eso es
// exactamente lo que se aplica; si se llena, este valor se ignora y manda
// el que el usuario escribió.
const resolvedRate = computed(() => props.exchangeRates.find((r) => r.rate_date <= form.posting_date)?.rate ?? null);
const isForeignEqualSystem = computed(() =>
    !! props.company?.foreign_currency && !! props.company?.system_currency
    && props.company.foreign_currency.id === props.company.system_currency.id
);

function needsCostAllocationRule(line) {
    return !! accountsById.value[line.account_id]?.requires_cost_center;
}

// Doble clic sobre el selector de cuenta o de socio de una línea abre su
// mayor auxiliar (mismo LedgerPanel que usan los reportes) — tanto en un
// borrador que se está digitando como en un asiento ya contabilizado
// (ver Show.vue), para revisar el saldo/movimientos sin salir del formulario.
const ledgerOpen = ref(false);
const ledgerDimension = ref('account');
const ledgerOwnerId = ref(null);
const ledgerOwnerLabel = ref('');

function openLedger(line, kind) {
    if (kind === 'account') {
        if (! line.account_id) return;
        ledgerDimension.value = 'account';
        ledgerOwnerId.value = line.account_id;
        ledgerOwnerLabel.value = accountsById.value[line.account_id]?.description_es ?? '';
    } else {
        if (! line.business_partner_id) return;
        ledgerDimension.value = 'business-partner';
        ledgerOwnerId.value = line.business_partner_id;
        ledgerOwnerLabel.value = partnersById.value[line.business_partner_id]?.name ?? '';
    }

    ledgerOpen.value = true;
}

// "Buscar y cargar un documento existente": busca por número/descripción/tipo
// (JournalEntryController::search) y lo trae a pantalla. Si el encontrado es
// UN PRELIMINAR PROPIO todavía editable, se abre ESE mismo documento
// (edit) — es el caso más común de "conozco el número, quiero verlo/seguirlo
// digitando". Si ya está contabilizado o anulado (inmutable), se trae como
// COPIA precargada (duplicate) en vez de intentar editarlo.
const documentSearchOpen = ref(false);

function onTemplateSelected(entry) {
    documentSearchOpen.value = false;
    router.get(route(entry.status === 'draft' ? 'journal-entries.edit' : 'journal-entries.duplicate', entry.id));
}

// dueDate: al agregar una línea nueva (addLine), hereda el vencimiento que
// tenga cargado el encabezado en ESE momento — precarga editable, no un
// vínculo que se mantenga sincronizado después (mismo criterio ya usado
// para el indicador de impuesto y el tipo de cambio manual). Las 2 líneas iniciales
// (antes de que exista form) arrancan sin vencimiento, porque el
// encabezado todavía no tiene ninguno cargado en ese punto.
function emptyLine(dueDate = null) {
    return {
        mode: 'account', // 'account' | 'partner' — qué selector se muestra para esta línea
        account_id: props.accounts[0]?.id ?? null,
        business_partner_id: null,
        currency_id: props.currencies[0]?.id ?? null,
        // En blanco (no "0,00") a propósito: precargado en cero obliga a
        // borrarlo antes de tipear el monto real en cada línea nueva, lo que
        // frena la digitación en un documento con muchas líneas. Se
        // normaliza a "0.00" recién al enviar (ver submit()).
        debit: '',
        credit: '',
        description: '',
        cost_allocation_rule_id: null,
        tax_rate_id: null, // solo llena en una línea DERIVADA (el impuesto en sí)
        taxable_base: null,
        tax_rate_account_id: null, // selector "IVA" de esta fila — nunca se envía, ver applyTaxRate()
        electronic_key: null, // clave de Hacienda de la factura que representa ESTA línea, si aplica
        due_date: dueDate, // fecha de vencimiento de ESTA línea, si aplica
        opens_item: false, // true = esta línea queda como partida pendiente (ver bpLineRequirement)
        apply_to_open_item_id: null, // partida existente que cancela esta línea, si aplica
        open_item_applications: [], // reparto de ESTA línea entre varias partidas: [{ open_item_id, amount }]
        reference_document: null, // número del documento fuente de ESTA línea (ej. "Factura #4521"), si aplica
        reference_document_date: null, // fecha de ESE documento, independiente de la fecha de contabilización
        _expanded: false, // UI: fila "detalle" (norma de reparto/IVA/doc. ref./vencimiento) visible aunque no tenga datos — ver showSecondary(); se descarta antes de enviar (submit())
    };
}

// La fila de "detalle" (norma de reparto, IVA, doc. de referencia, fecha,
// vencimiento, control de socio) se mantiene compacta y OCULTA por defecto
// — la mayoría de las líneas son cuenta+monto simples — pero se muestra
// sola si ya trae algún dato (línea copiada/duplicada/cargada desde una
// plantilla) o si el socio de esta línea exige vencimiento/aplicación de
// partida (ver bpLineRequirement); el botón de detalle (Ellipsis) alterna el
// resto de casos.
function lineHasSecondaryData(line) {
    return !! (line.cost_allocation_rule_id || line.reference_document || line.reference_document_date || line.due_date || line.tax_rate_id || line.tax_rate_account_id);
}

function showSecondary(line) {
    return line._expanded || lineHasSecondaryData(line) || (line.mode === 'partner' && bpLineRequirement.value !== 'none');
}

function toggleSecondary(line) {
    line._expanded = ! line._expanded;
}

// Cuentas ya vinculadas a un indicador de impuesto (ver ChartOfAccounts/Index.vue,
// campo "Indicador de impuesto vinculado"), vigentes para la fecha de
// CONTABILIZACIÓN (la rectora) — el selector "IVA" de cada línea ofrece
// solo estas, mismo criterio que PostJournalService/TaxRate::isEffectiveOn()
// exige al contabilizar.
const availableTaxAccounts = computed(() =>
    props.accounts.filter((a) => a.tax_rate
        && a.tax_rate.effective_from <= form.posting_date
        && (! a.tax_rate.effective_to || a.tax_rate.effective_to >= form.posting_date))
);

function taxDirectionLabel(account) {
    return { iva_soportado: 'Soportado', iva_devengado: 'Devengado' }[account.tax_classification] ?? '';
}

// Elegir un indicador en una línea inserta UNA línea nueva justo debajo con
// el impuesto ya calculado, apuntando a la cuenta vinculada a ese indicador
// — así "gastos de papelería + IVA 13%" arma sola la segunda línea (soportado
// o devengado, según cómo esté vinculada esa cuenta), sin que el usuario la
// tenga que tipear a mano. Es una inserción de una sola vez (no queda un
// vínculo activo): si después cambiás el monto de la línea original, hay que
// volver a aplicar el indicador o ajustar la línea de impuesto a mano.
function applyTaxRate(line, index, accountIdRaw) {
    if (! accountIdRaw) return;

    const account = accountsById.value[Number(accountIdRaw)];
    if (! account?.tax_rate) return;

    const base = parseFloat(line.debit) || parseFloat(line.credit) || 0;
    const isDebit = (parseFloat(line.debit) || 0) > 0;
    const percentage = parseFloat(account.tax_rate.percentage);
    const taxAmount = (base * percentage / 100).toFixed(2);
    // La línea de IVA lleva la MISMA descripción que la línea que la originó
    // (lo que el usuario tipeó, o si no tipeó nada, el nombre de la cuenta
    // base) — no un texto compuesto tipo "IVA 13% s/ ..."; el propio detalle
    // de indicador/base/monto ya se ve aparte en el desglose de impuesto.
    const baseDescription = line.description || accountsById.value[line.account_id]?.description_es || '';

    form.lines.splice(index + 1, 0, {
        mode: 'account',
        account_id: account.id,
        business_partner_id: null,
        currency_id: line.currency_id,
        debit: isDebit ? taxAmount : '0.00',
        credit: isDebit ? '0.00' : taxAmount,
        description: baseDescription,
        cost_allocation_rule_id: null,
        tax_rate_id: account.tax_rate.id,
        taxable_base: base.toFixed(2),
        tax_rate_account_id: null,
        // La línea de IVA es parte de la MISMA factura que la originó, así
        // que hereda su misma clave electrónica, vencimiento y documento de
        // referencia (número y fecha), si la línea base ya tenía alguno.
        electronic_key: line.electronic_key ?? null,
        due_date: line.due_date ?? null,
        reference_document: line.reference_document ?? null,
        reference_document_date: line.reference_document_date ?? null,
    });

    if (activeLineIndex.value > index) {
        activeLineIndex.value += 1;
    }

    line.tax_rate_account_id = null;
}

// Cambiar a "socio de negocio" resuelve la cuenta contable a partir del
// socio elegido (su cuenta de control CxC/CxP) — el usuario nunca digita
// la cuenta a mano en ese modo, evita ligar un socio a la cuenta que no es.
function setLineMode(line, mode) {
    line.mode = mode;
    // opens_item/apply_to_open_item_id solo tienen sentido con socio de
    // negocio (ver guardas de JournalLineInput) — se limpian al salir de
    // modo 'partner' para no dejar una referencia a un socio que ya no está.
    line.opens_item = false;
    line.apply_to_open_item_id = null;

    if (mode === 'account') {
        line.business_partner_id = null;
        if (! line.account_id) line.account_id = props.accounts[0]?.id ?? null;
    } else {
        const firstPartner = props.businessPartners[0];
        line.business_partner_id = firstPartner?.id ?? null;
        line.account_id = firstPartner?.gl_account_id ?? null;
        applySuggestedDueDate(line);
    }
}

function onPartnerChange(line) {
    line.account_id = partnersById.value[line.business_partner_id]?.gl_account_id ?? null;
    // La partida elegida pertenece al socio ANTERIOR — cambiar de socio la
    // invalida (PostJournalService::applyToExistingOpenItem() la rechazaría).
    line.apply_to_open_item_id = null;
    applySuggestedDueDate(line);
}

// Un vencimiento tipeado a mano es justo lo que termina corrompiendo la
// cédula de antigüedad de saldos cuando alguien se equivoca — si el socio
// tiene días de crédito configurados, el vencimiento se sugiere solo a
// partir de la fecha del documento de ESTA línea (o la fecha de documento
// del encabezado si la línea todavía no tiene la suya propia). Es una
// PRECARGA editable, no un vínculo que se mantenga sincronizado (mismo
// criterio que el vencimiento de encabezado → línea, docs/decisiones.md
// 2026-08-22): se recalcula al cambiar de socio o de fecha de referencia,
// pero el usuario sigue pudiendo corregirla a mano después si de verdad
// hay una excepción de pago.
function applySuggestedDueDate(line) {
    if (line.mode !== 'partner' || ! line.business_partner_id) return;

    const partner = partnersById.value[line.business_partner_id];
    if (! partner?.payment_terms_days) return;

    const baseDate = line.reference_document_date || form.document_date;
    if (! baseDate) return;

    const date = new Date(`${baseDate}T00:00:00`);
    date.setDate(date.getDate() + Number(partner.payment_terms_days));

    line.due_date = date.toISOString().slice(0, 10);
}

const form = useForm({
    intent: 'post',
    document_type_id: props.entry?.document_type_id ?? props.documentTypes[0]?.id ?? null,
    // document_date: fecha del documento fuente (ej. la factura), puramente
    // informativa. posting_date: fecha de contabilización, la RECTORA —
    // fija período fiscal, tipo de cambio y vigencias (ver
    // PostJournalService::post, docs/decisiones.md 2026-08-22). Las dos
    // arrancan iguales (hoy) por default, como ya pasaba con document_date
    // sola antes de esta separación; el usuario ajusta la que corresponda.
    document_date: props.entry?.document_date ?? new Date().toISOString().slice(0, 10),
    posting_date: props.entry?.posting_date ?? new Date().toISOString().slice(0, 10),
    due_date: props.entry?.due_date ?? '',
    description: props.entry?.description ?? '',
    number_series_id: props.entry?.number_series_id ?? null,
    exchange_rate: '',
    lines: props.entry?.lines?.length
        ? props.entry.lines.map((l) => ({ ...l, _expanded: lineHasSecondaryData(l) }))
        : [emptyLine(), emptyLine()],
});

// Copia los campos "de identidad" de la última línea (cuenta/socio, moneda,
// descripción, norma de reparto, vencimiento, documento de referencia) para
// no repetir todo a mano en registros con muchas líneas parecidas — pero
// nunca copia el monto (arranca en 0.00, cada línea necesita el suyo), ni la
// clave electrónica (es única por línea, copiarla generaría un duplicado),
// ni nada de norma de reparto pendiente de aplicar impuesto/partida (son
// decisiones puntuales de esa línea, no algo para heredar a ciegas).
function addLine() {
    const previous = form.lines[form.lines.length - 1];

    if (! previous) {
        form.lines.push(emptyLine(form.due_date || null));
        return;
    }

    form.lines.push({
        ...previous,
        debit: '',
        credit: '',
        tax_rate_id: null,
        taxable_base: null,
        tax_rate_account_id: null,
        electronic_key: null,
        opens_item: false,
        apply_to_open_item_id: null,
        open_item_applications: [],
        _expanded: false,
    });
}

function removeLine(index) {
    if (form.lines.length > 1) {
        form.lines.splice(index, 1);

        if (activeLineIndex.value >= form.lines.length) {
            activeLineIndex.value = form.lines.length - 1;
        }
    }
}

// Copia esta línea (cuenta/socio, montos, descripción, norma de reparto,
// vencimiento, etc.) justo debajo — útil para partidas casi idénticas que
// solo cambian en un par de campos, sin tener que rearmar todo desde una
// línea en blanco.
function duplicateLine(index) {
    form.lines.splice(index + 1, 0, { ...form.lines[index] });

    if (activeLineIndex.value > index) {
        activeLineIndex.value += 1;
    }
}

// Una línea nunca puede tener débito y crédito a la vez (PostJournalService
// la rechazaría al contabilizar) — en vez de dejar que el usuario lo
// descubra recién al enviar el formulario, tipear un monto de un lado limpia
// el otro de inmediato.
function onDebitInput(line, value) {
    line.debit = value;
    if (parseFloat(value) > 0) line.credit = '';
}

function onCreditInput(line, value) {
    line.credit = value;
    if (parseFloat(value) > 0) line.debit = '';
}

const totalDebit = computed(() =>
    form.lines.reduce((sum, l) => sum + (parseFloat(l.debit) || 0), 0).toFixed(2)
);
const totalCredit = computed(() =>
    form.lines.reduce((sum, l) => sum + (parseFloat(l.credit) || 0), 0).toFixed(2)
);
const isBalanced = computed(() => totalDebit.value === totalCredit.value);

// props.entry sin id = precarga de un duplicado (JournalEntryController::duplicate()):
// se ve igual que editar un borrador, pero "Contabilizar"/"Guardar" tienen
// que crear un documento NUEVO, no actualizar el que se copió.
function submit(intent = 'post') {
    form.intent = intent;

    // Los montos arrancan en blanco en pantalla (ver emptyLine/addLine) para
    // no frenar la digitación, pero el backend exige "numeric" — se
    // normaliza a "0.00" recién acá, justo antes de enviar.
    form.transform((data) => ({
        ...data,
        lines: data.lines.map(({ _expanded, ...l }) => ({ ...l, debit: l.debit || '0.00', credit: l.credit || '0.00' })),
    }));

    if (props.entry?.id) {
        form.put(route('journal-entries.update', props.entry.id));
    } else {
        form.post(route('journal-entries.store'));
    }
}

// Salir/Cancelar (botón del encabezado y de las acciones del formulario):
// confirma antes de descartar si hay algo tipeado, para no perder digitación
// por un clic accidental — form.isDirty ya compara contra los valores con
// los que arrancó el formulario (útil tanto en "nuevo" como editando un
// borrador existente).
function cancel() {
    if (! form.isDirty) {
        router.get(route('journal-entries.index'));
        return;
    }

    confirmAction({
        title: 'Salir sin guardar',
        message: 'Se pierde lo digitado en este formulario.',
        confirmLabel: 'Salir',
        danger: true,
        onConfirm: () => router.get(route('journal-entries.index')),
    });
}

// "Programable": en vez de contabilizar/guardar ESTE asiento, guarda una
// PLANTILLA (JournalEntryScheduleService) que genera un asiento preliminar
// nuevo cada vez que llega next_run_date, hasta expires_at — no toca
// journal-entries.store en absoluto, es un recurso aparte
// (journal-entry-schedules) que reutiliza el mismo tipo de documento/líneas
// ya cargados en este formulario.
const schedulable = ref(false);
const scheduleForm = useForm({
    frequency_type: 'months',
    interval_count: 1,
    expires_at: '',
});

function submitSchedule() {
    scheduleForm.transform((data) => ({
        ...data,
        document_type_id: form.document_type_id,
        description: form.description,
        start_date: form.posting_date,
        lines: form.lines.map((l) => ({
            account_id: l.account_id,
            currency_id: l.currency_id,
            debit: l.debit,
            credit: l.credit,
            description: l.description,
            business_partner_id: l.business_partner_id,
            cost_allocation_rule_id: l.cost_allocation_rule_id,
        })),
    })).post(route('journal-entry-schedules.store'));
}
</script>

<template>
    <Head :title="entry?.id ? 'Editar borrador' : 'Nuevo asiento'" />

    <AppLayout :title="entry?.id ? 'Editar borrador' : 'Nuevo asiento'">
        <div class="view-toolbar">
            <button type="button" class="btn btn-ghost" @click="cancel"><ArrowLeftIcon /> Salir / Volver</button>
            <!-- Recorrer asientos solo tiene sentido editando uno que ya existe:
                 uno nuevo todavía no tiene posición. -->
            <RecordNav v-if="entry?.id" :nav="nav" :current-id="entry.id" route-name="journal-entries.show" />

            <div v-if="entry?.id" class="view-actions">
                <a
                    :href="route('journal-entries.presentation', entry.id)"
                    target="_blank"
                    class="btn btn-ghost"
                    title="Abrir este documento en una pantalla aparte, lista para exportar a XLSX/PDF o imprimir"
                ><PrinterIcon /> Presentar documento</a>
            </div>
        </div>

        <form class="card form-card" @submit.prevent="submit()">
            <button
                type="button"
                class="load-existing-btn"
                title="Buscar un documento ya registrado por número, descripción o tipo, y cargarlo acá como punto de partida"
                @click="documentSearchOpen = true"
            >
                <SearchIcon />
                Buscar y cargar un documento existente…
            </button>

            <div class="header-grid">
                <div class="field">
                    <label for="document_type_id">Tipo de documento</label>
                    <select id="document_type_id" :value="form.document_type_id" required @change="setDocumentType(Number($event.target.value))">
                        <option v-for="dt in documentTypes" :key="dt.id" :value="dt.id">
                            {{ dt.code }} — {{ dt.name }}
                        </option>
                    </select>
                    <span v-if="form.errors.document_type_id" class="error">{{ form.errors.document_type_id }}</span>
                </div>

                <div class="field">
                    <label for="document_date">
                        Fecha de documento
                        <span class="hint-inline">(la de la factura/comprobante)</span>
                    </label>
                    <input id="document_date" v-model="form.document_date" type="date" required>
                    <span v-if="form.errors.document_date" class="error">{{ form.errors.document_date }}</span>
                </div>

                <div class="field">
                    <label for="posting_date">
                        Fecha de contabilización
                        <span class="hint-inline">(fija período, TC y vigencias)</span>
                    </label>
                    <input id="posting_date" v-model="form.posting_date" type="date" required>
                    <span v-if="form.errors.posting_date" class="error">{{ form.errors.posting_date }}</span>
                </div>

                <div class="field">
                    <label for="number_series_id">Serie (opcional — manual)</label>
                    <select id="number_series_id" v-model="form.number_series_id" :disabled="!availableSeries.length">
                        <option :value="null">— Solo consecutivo automático —</option>
                        <option v-for="s in availableSeries" :key="s.id" :value="s.id">
                            {{ s.name }}<template v-if="s.holder_name"> — {{ s.holder_name }}</template> (siguiente: {{ s.next_number }} de {{ s.range_to }})
                        </option>
                    </select>
                    <span v-if="form.errors.number_series_id" class="error">{{ form.errors.number_series_id }}</span>
                </div>

                <div class="field">
                    <label for="due_date">Fecha de vencimiento <span class="hint-inline">(opcional)</span></label>
                    <input id="due_date" v-model="form.due_date" type="date">
                    <span class="hint">Precarga el vencimiento de cada línea nueva que agregués; cada línea lo puede cambiar aparte.</span>
                    <span v-if="form.errors.due_date" class="error">{{ form.errors.due_date }}</span>
                </div>

                <div class="field">
                    <label for="exchange_rate">
                        Tipo de cambio
                        <span v-if="company.foreign_currency && company.local_currency" class="hint-inline">
                            ({{ company.foreign_currency.code }} → {{ company.local_currency.code }})
                        </span>
                    </label>
                    <input
                        id="exchange_rate"
                        v-model="form.exchange_rate"
                        type="number"
                        step="0.000001"
                        min="0"
                        :placeholder="resolvedRate ? `Automático: ${resolvedRate}` : 'Sin TC registrado para esta fecha'"
                    >
                    <span class="hint">
                        <template v-if="isForeignEqualSystem">Moneda de sistema = {{ company.foreign_currency.code }}: el factor a sistema siempre es 1.00.</template>
                        <template v-else-if="resolvedRate">1 {{ company.foreign_currency?.code }} = {{ resolvedRate }} {{ company.local_currency?.code }}. Dejar vacío para usar este valor automático.</template>
                        <template v-else>Se puede cargar en <a :href="route('exchange-rates.index')" target="_blank">Tipos de cambio</a>, o escribirlo acá manualmente para este asiento.</template>
                    </span>
                    <span v-if="form.errors.exchange_rate" class="error">{{ form.errors.exchange_rate }}</span>
                </div>

                <div class="field description-field">
                    <label for="description">Descripción</label>
                    <input id="description" v-model="form.description" type="text" maxlength="255">
                </div>
            </div>

            <!--
                Clave numérica electrónica: por LÍNEA (un asiento puede juntar
                varias facturas de compra), pero un input de 50 dígitos en
                cada fila saturaría la tabla. En su lugar, un único editor
                "línea activa" que sigue el cursor: hacé clic en cualquier
                campo de una fila (@focusin más abajo) y este editor pasa a
                apuntar a esa línea. Cada fila con clave ya cargada se marca
                con el ícono de llave (ver account-cell) para poder ver de un vistazo cuáles
                ya la tienen. Documento de referencia y fecha, en cambio, ya
                son columnas propias de la tabla (más abajo) — son compactos,
                no necesitan este editor compartido.
            -->
            <div v-if="requiresElectronicKey" class="active-line-panel">
                <div class="active-key-label">
                    <label for="active_electronic_key">
                        Clave numérica electrónica
                        <span v-if="activeLine" class="hint-inline">— línea {{ activeLineIndex + 1 }}<template v-if="activeLineLabel"> ({{ activeLineLabel }})</template></span>
                    </label>
                    <span class="hint-inline">{{ (activeLine?.electronic_key || '').length }}/50</span>
                </div>
                <input
                    id="active_electronic_key"
                    class="electronic-key-input"
                    :value="formattedActiveKey"
                    type="text"
                    inputmode="numeric"
                    autocomplete="off"
                    :disabled="!activeLine"
                    placeholder="506 260826 010120001234 00100001010000000001 1 12345678"
                    @input="onActiveKeyInput"
                >
                <span class="hint">
                    <template v-if="!activeLine">Hacé clic en una línea de la tabla para asociarle una clave.</template>
                    <template v-else-if="(activeLine.electronic_key || '').length === 50"><CheckIcon /> 50 dígitos completos para esta línea.</template>
                    <template v-else>Pegá la clave de 50 dígitos de la factura que corresponde a esta línea. Si el asiento junta varias facturas, hacé clic en cada línea y pegale la clave que le toca.</template>
                </span>
                <span v-if="activeLineElectronicKeyError" class="error">{{ activeLineElectronicKeyError }}</span>
            </div>

            <!-- Grilla de captura: editar dentro de la tabla es la tarea, así que
                 cada línea conserva sus botones (CLAUDE.md secc. 20, excepción).
                 En ≤ 1024px cada línea es una tarjeta, una por fila, con su
                 detalle pegado debajo. -->
            <div class="table-responsive capture-grid lines-table-wrap">
            <table class="lines-table">
                <colgroup>
                    <col class="col-account">
                    <col class="col-currency">
                    <col class="col-amount">
                    <col class="col-amount">
                    <col>
                    <col class="col-actions">
                </colgroup>
                <thead>
                    <tr>
                        <th>Cuenta / socio</th>
                        <th>Moneda</th>
                        <th class="num">Débito</th>
                        <th class="num">Crédito</th>
                        <th>Descripción</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <template v-for="(line, index) in form.lines" :key="index">
                    <tr
                        :class="{ 'active-row': index === activeLineIndex }"
                        @focusin="setActiveLine(index)"
                    >
                        <td class="account-cell">
                            <div class="mode-toggle">
                                <span v-if="line.electronic_key" class="key-indicator" title="Esta línea tiene una clave numérica electrónica asociada"><KeyRoundIcon :size="14" /></span>
                                <button
                                    type="button"
                                    class="mode-btn"
                                    :class="{ active: line.mode === 'account' }"
                                    @click="setLineMode(line, 'account')"
                                >Cuenta</button>
                                <button
                                    type="button"
                                    class="mode-btn"
                                    :class="{ active: line.mode === 'partner' }"
                                    @click="setLineMode(line, 'partner')"
                                >Socio</button>
                            </div>

                            <SearchableCombobox
                                v-if="line.mode === 'account'"
                                :options="accountOptions"
                                :model-value="line.account_id"
                                required
                                placeholder="Código o nombre de cuenta..."
                                title="Doble clic: ver mayor auxiliar"
                                @update:model-value="(id) => (line.account_id = id)"
                                @dblclick="openLedger(line, 'account')"
                            />
                            <SearchableCombobox
                                v-else-if="businessPartners.length"
                                :options="partnerOptions"
                                :model-value="line.business_partner_id"
                                required
                                placeholder="Código o nombre de socio..."
                                title="Doble clic: ver estado de cuenta"
                                @update:model-value="(id) => { line.business_partner_id = id; onPartnerChange(line); }"
                                @dblclick="openLedger(line, 'partner')"
                            />
                            <span v-else class="muted small">No hay socios de negocio activos.</span>
                        </td>
                        <td data-label="Moneda">
                            <select v-model="line.currency_id" required aria-label="Moneda">
                                <option v-for="c in currencies" :key="c.id" :value="c.id">{{ c.code }}</option>
                            </select>
                        </td>
                        <td data-label="Débito">
                            <MoneyInput class="num-input" aria-label="Débito" :model-value="line.debit" @update:model-value="(v) => onDebitInput(line, v)" />
                        </td>
                        <td data-label="Crédito">
                            <MoneyInput class="num-input" aria-label="Crédito" :model-value="line.credit" @update:model-value="(v) => onCreditInput(line, v)" />
                        </td>
                        <td data-label="Descripción">
                            <input v-model="line.description" type="text" maxlength="255" aria-label="Descripción">
                        </td>
                        <td class="actions-td">
                            <div class="row-actions">
                                <button
                                    type="button"
                                    class="btn btn-ghost detail-btn"
                                    :class="{ active: showSecondary(line) }"
                                    title="Norma de reparto, IVA, documento de referencia, vencimiento..."
                                    aria-label="Detalle de la línea"
                                    :aria-expanded="showSecondary(line)"
                                    @click="toggleSecondary(line)"
                                ><EllipsisIcon /></button>
                                <button
                                    type="button"
                                    class="btn btn-ghost duplicate-btn"
                                    title="Duplicar línea"
                                    aria-label="Duplicar línea"
                                    @click="duplicateLine(index)"
                                ><CopyIcon /></button>
                                <button
                                    type="button"
                                    class="btn btn-ghost remove-btn"
                                    title="Eliminar línea"
                                    aria-label="Eliminar línea"
                                    :disabled="form.lines.length <= 1"
                                    @click="removeLine(index)"
                                ><XIcon /></button>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="showSecondary(line)" class="secondary-row" :class="{ 'active-row': index === activeLineIndex }">
                        <td colspan="6">
                            <div class="secondary-grid">
                                <div class="field-inline">
                                    <label>Norma de reparto</label>
                                    <select
                                        v-model="line.cost_allocation_rule_id"
                                        class="cost-allocation-rule-select"
                                        :class="{ 'needs-value': needsCostAllocationRule(line) && !line.cost_allocation_rule_id }"
                                    >
                                        <option :value="null">—</option>
                                        <option v-for="r in costAllocationRules" :key="r.id" :value="r.id">{{ r.code }} — {{ r.name }}</option>
                                    </select>
                                </div>
                                <div class="field-inline">
                                    <label>IVA</label>
                                    <select
                                        :value="line.tax_rate_account_id"
                                        :disabled="!availableTaxAccounts.length"
                                        :title="availableTaxAccounts.length ? '' : 'Ninguna cuenta tiene un indicador de impuesto vinculado todavía. Vinculá uno desde Catálogo de cuentas (campo \'Indicador de impuesto vinculado\') para habilitar este selector.'"
                                        @change="applyTaxRate(line, index, $event.target.value)"
                                    >
                                        <option value="">—</option>
                                        <option v-for="a in availableTaxAccounts" :key="a.id" :value="a.id">
                                            {{ a.tax_rate.percentage }}% ({{ taxDirectionLabel(a) }})
                                        </option>
                                    </select>
                                </div>
                                <div class="field-inline">
                                    <label>Doc. de referencia</label>
                                    <input v-model="line.reference_document" type="text" maxlength="255" placeholder="Ej. Factura #4521">
                                </div>
                                <div class="field-inline">
                                    <label>Fecha del doc.</label>
                                    <input
                                        :value="line.reference_document_date"
                                        type="date"
                                        @change="line.reference_document_date = $event.target.value || null; applySuggestedDueDate(line)"
                                    >
                                </div>
                                <div class="field-inline">
                                    <label>Vencimiento</label>
                                    <input v-model="line.due_date" type="date" class="due-date-input">
                                </div>

                                <div v-if="line.mode === 'partner' && bpLineRequirement !== 'none'" class="bp-control field-inline-wide">
                                    <label v-if="showsDueDateControl" class="bp-check-row">
                                        <input v-model="line.opens_item" type="checkbox" @change="onOpensItemToggle(line)">
                                        Abre partida (vencimiento)
                                    </label>

                                    <!--
                                        Aplicar a partidas: el monto de la línea se reparte entre
                                        las facturas que cancela o abona, cada una con el suyo. El
                                        desplegable de una sola partida que había antes aplicaba
                                        siempre el monto completo de la línea, así que repartir un
                                        cobro entre dos facturas era imposible.
                                    -->
                                    <button
                                        v-if="showsApplicationControl && openItemsForLine(line).length"
                                        type="button"
                                        class="btn btn-ghost btn-sm bp-apply-btn"
                                        @click="openDistribution(index)"
                                    >
                                        <SearchIcon />
                                        <template v-if="(line.open_item_applications ?? []).length">
                                            {{ line.open_item_applications.length }} partida(s) ·
                                            {{ formatMoney(applicationSummary(line).applied) }}
                                        </template>
                                        <template v-else>Aplicar a partidas…</template>
                                    </button>

                                    <button
                                        v-if="(line.open_item_applications ?? []).length"
                                        type="button" class="btn btn-ghost btn-sm bp-clear-btn"
                                        title="Quitar el reparto de esta línea"
                                        @click="clearDistribution(line)"
                                    >
                                        <XIcon />
                                    </button>

                                    <span v-if="showsApplicationControl && line.business_partner_id && !openItemsForLine(line).length" class="muted small">
                                        Este socio no tiene partidas abiertas.
                                    </span>

                                    <!--
                                        Qué le pasa a la partida con el monto de esta línea.
                                        Se dice en palabras —abono o cancelación— y con la resta
                                        hecha, para no obligar a hacerla de cabeza entre el saldo
                                        del desplegable y el monto de la columna.
                                    -->
                                    <!--
                                        Qué le pasa a cada partida con el reparto guardado: se dice
                                        en palabras y con la resta hecha, para no obligar a hacerla
                                        de cabeza entre el saldo y el monto.
                                    -->
                                    <span v-if="applicationSummary(line)" class="open-item-effect">
                                        <span
                                            v-for="row in applicationSummary(line).rows"
                                            :key="row.key"
                                            class="oie-row"
                                            :class="`oie-${row.currencyMismatch ? 'over' : row.state}`"
                                        >
                                            <strong>{{ row.label }}:</strong>
                                            <template v-if="row.currencyMismatch">
                                                <TriangleAlertIcon /> está en otra moneda que la línea.
                                            </template>
                                            <template v-else-if="row.state === 'full'">
                                                cancela la partida ({{ formatMoney(row.amount) }}).
                                            </template>
                                            <template v-else-if="row.state === 'partial'">
                                                abono de {{ formatMoney(row.amount) }}, quedan
                                                <strong>{{ formatMoney(row.remaining) }}</strong> pendientes.
                                            </template>
                                            <template v-else>
                                                <TriangleAlertIcon /> {{ formatMoney(row.amount) }} excede su saldo.
                                            </template>
                                        </span>

                                        <span v-if="! applicationSummary(line).balanced" class="oie-row oie-over">
                                            <TriangleAlertIcon />
                                            Lo repartido ({{ formatMoney(applicationSummary(line).applied) }}) no
                                            coincide con el monto de la línea: hay una diferencia de
                                            <strong>{{ formatMoney(applicationSummary(line).difference) }}</strong>.
                                        </span>
                                    </span>

                                    <span v-if="bpLineRequirementUnmet(line)" class="bp-requirement-warning" :title="`Este tipo de documento exige: ${bpLineRequirementLabels[bpLineRequirement]}`">
                                        <TriangleAlertIcon /> falta {{ bpLineRequirementLabels[bpLineRequirement].toLowerCase() }}
                                    </span>
                                </div>
                            </div>
                        </td>
                    </tr>
                    </template>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="2">
                            <button type="button" class="btn btn-ghost" @click="addLine"><PlusIcon /> Línea</button>
                        </td>
                        <td data-label="Total débito" class="num total-cell">{{ formatMoney(totalDebit) }}</td>
                        <td data-label="Total crédito" class="num total-cell">{{ formatMoney(totalCredit) }}</td>
                        <td colspan="2">
                            <span class="badge" :class="isBalanced ? 'badge-success' : 'badge-danger'">
                                {{ isBalanced ? 'Cuadrado' : 'No cuadra' }}
                            </span>
                        </td>
                    </tr>
                </tfoot>
            </table>
            </div>

            <LedgerPanel
                :open="ledgerOpen"
                :dimension="ledgerDimension"
                :owner-id="ledgerOwnerId"
                :owner-label="ledgerOwnerLabel"
                @close="ledgerOpen = false"
            />

            <DocumentSearchModal
                :open="documentSearchOpen"
                title="Cargar desde un documento existente"
                @close="documentSearchOpen = false"
                @select="onTemplateSelected"
            />

            <!--
                El repartidor: una fila por partida abierta del socio y una
                COLUMNA DE MONTO donde se escribe cuánto va a cada una.

                No se puede confirmar hasta que lo repartido sea exactamente el
                monto de la línea: es la misma regla que impone el motor, pero
                dicha mientras se digita en vez de al guardar.
            -->
            <div v-if="distributingLine" class="modal-backdrop" @click.self="closeDistribution">
                <div class="modal card distribution-modal">
                    <h2>Aplicar a partidas</h2>

                    <p class="hint small">
                        Escribí cuánto de esta línea va a cada factura. Podés cancelar una y abonar otra:
                        lo único que se exige es que la suma sea el monto de la línea.
                    </p>

                    <div class="distribution-totals">
                        <span>Monto de la línea <strong>{{ formatMoney(lineAmount(distributingLine)) }}</strong></span>
                        <span>Repartido <strong>{{ formatMoney(distributedTotal) }}</strong></span>
                        <span :class="{ 'dist-pending': distributionRemaining !== 0 }">
                            Falta <strong>{{ formatMoney(distributionRemaining) }}</strong>
                        </span>
                    </div>

                    <div class="table-scroll">
                        <table>
                            <thead>
                                <tr>
                                    <th>Documento</th>
                                    <th>Vence</th>
                                    <th class="num">Saldo</th>
                                    <th class="num">Monto a aplicar</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="row in distributionDraft" :key="row.open_item_id">
                                    <td>{{ row.label }}</td>
                                    <td class="small muted">{{ row.due_date }}</td>
                                    <td class="num">{{ row.currency }} {{ formatMoney(row.balance) }}</td>
                                    <td class="num">
                                        <input
                                            v-model="row.amount"
                                            type="number" step="0.01" min="0"
                                            class="dist-amount"
                                            :class="{ 'dist-over': rowExceedsBalance(row) }"
                                            :max="row.balance"
                                        >
                                    </td>
                                    <td>
                                        <button type="button" class="btn btn-ghost btn-sm" @click="fillRow(row)">
                                            Todo
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <p v-if="distributionDraft.some(rowExceedsBalance)" class="error">
                        Hay partidas con un monto mayor a su saldo. A una partida no se le puede aplicar
                        más de lo que debe.
                    </p>

                    <div class="modal-actions">
                        <button type="button" class="btn btn-ghost" @click="closeDistribution">Cancelar</button>
                        <button
                            type="button" class="btn btn-primary"
                            :disabled="! distributionIsValid"
                            @click="confirmDistribution"
                        >
                            Aplicar
                        </button>
                    </div>
                </div>
            </div>

            <p v-if="form.errors.lines" class="error form-error">{{ form.errors.lines }}</p>

            <div v-if="!entry?.id" class="schedule-panel">
                <label class="bp-check-row">
                    <input v-model="schedulable" type="checkbox">
                    <strong>Programable</strong> — en vez de contabilizar este asiento ahora, guardarlo como plantilla que genera un preliminar nuevo automáticamente según la frecuencia indicada.
                </label>

                <div v-if="schedulable" class="schedule-fields">
                    <div class="field">
                        <label for="frequency_type">Frecuencia</label>
                        <select id="frequency_type" v-model="scheduleForm.frequency_type">
                            <option value="days">Cada cantidad de días</option>
                            <option value="months">Cada cantidad de meses (períodos)</option>
                        </select>
                    </div>
                    <div class="field">
                        <label for="interval_count">Cada</label>
                        <input id="interval_count" v-model="scheduleForm.interval_count" type="number" min="1" step="1">
                        <span v-if="scheduleForm.errors.interval_count" class="error">{{ scheduleForm.errors.interval_count }}</span>
                    </div>
                    <div class="field">
                        <label for="schedule_expires_at">Fecha límite de vencimiento <span class="hint-inline">(opcional)</span></label>
                        <input id="schedule_expires_at" v-model="scheduleForm.expires_at" type="date">
                        <span v-if="scheduleForm.errors.expires_at" class="error">{{ scheduleForm.errors.expires_at }}</span>
                    </div>
                    <p class="hint schedule-hint">
                        Primera corrida: {{ form.posting_date }} (la fecha de contabilización de arriba). Cada asiento que se genere queda como
                        <strong>preliminar</strong> bajo "Registros pendientes programados" — nadie contabiliza nada sin revisarlo antes.
                    </p>
                    <span v-if="scheduleForm.errors.schedule" class="error">{{ scheduleForm.errors.schedule }}</span>
                </div>
            </div>

            <div class="form-actions">
                <template v-if="schedulable">
                    <button type="button" class="btn btn-primary" :disabled="scheduleForm.processing" @click="submitSchedule">
                        Programar
                    </button>
                </template>
                <template v-else>
                    <button type="button" class="btn btn-ghost" :disabled="form.processing" @click="submit('draft')">
                        Guardar como preliminar
                    </button>
                    <button type="button" class="btn btn-primary" :disabled="form.processing || !isBalanced" @click="submit('post')">
                        Contabilizar
                    </button>
                </template>
                <button type="button" class="btn btn-ghost cancel-btn" @click="cancel">Cancelar</button>
            </div>
        </form>
    </AppLayout>
</template>

<style scoped>
.form-card {
    padding: 1rem 1.1rem;
}

.load-existing-btn {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    width: 100%;
    background: var(--color-surface-alt);
    border: 1px dashed var(--color-border);
    border-radius: var(--radius-md);
    padding: 0.55rem 0.8rem;
    margin-bottom: 0.85rem;
    font-size: 0.83rem;
    font-weight: 600;
    color: var(--color-text-muted);
    cursor: pointer;
    transition: border-color .12s ease, color .12s ease, background .12s ease;
}

.load-existing-btn:hover {
    border-color: var(--color-primary);
    color: var(--color-primary);
    background: var(--color-primary-soft);
}

/* Todo el ancho: tantas columnas como quepan (cuatro en un monitor, una en
   un teléfono). La descripción ocupa la fila entera. */
.header-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(min(100%, 14rem), 1fr));
    gap: 0 0.85rem;
    margin-bottom: 0.85rem;
}

.header-grid .field {
    margin-bottom: 0.6rem;
}

.description-field {
    grid-column: 1 / -1;
}

.active-line-panel {
    background: var(--color-surface-alt);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    padding: 0.55rem 0.8rem;
    margin-bottom: 0.6rem;
}

.active-line-panel input {
    width: 100%;
}

.active-key-label {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 0.5rem;
    font-size: 0.78rem;
    color: var(--color-text-muted);
    margin-bottom: 0.3rem;
}

.active-key-label label {
    font-weight: 600;
}

.electronic-key-input {
    font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace;
    letter-spacing: 0.05em;
}

.key-indicator {
    display: inline-flex;
    align-items: center;
    margin-right: 0.15rem;
}

.hint-inline {
    font-weight: 400;
    color: var(--color-text-muted);
}

.hint {
    display: block;
    font-size: 0.76rem;
    color: var(--color-text-muted);
    margin: 0.2rem 0 0;
}

.lines-table-wrap {
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
}

/* Anchos fijos para lo que tiene un largo conocido (moneda, montos, los
   botones de la línea); la descripción se queda con lo que sobra. Sin un
   ancho mínimo: la tabla cabe en los ~720px que deja la barra lateral a
   1025px, sin desplazarse de lado. */
.lines-table {
    width: 100%;
    table-layout: fixed;
    font-size: 0.83rem;
}

.col-account { width: 27%; }
.col-currency { width: 6rem; }
.col-amount { width: 13%; }
.col-actions { width: 7.5rem; }

.lines-table thead th {
    position: sticky;
    top: 0;
    z-index: 1;
    background: var(--color-surface-alt);
    font-size: 0.72rem;
    font-weight: 700;
    color: var(--color-text-muted);
}

.lines-table th, .lines-table td {
    padding: 0.35rem 0.5rem;
    border-top: 1px solid var(--color-border);
}

.lines-table tbody tr:not(.secondary-row):hover {
    background: var(--color-surface-alt);
}

.lines-table tbody tr.active-row {
    background: var(--color-primary-soft);
}

.lines-table select,
.lines-table input,
.lines-table .combobox {
    width: 100%;
    min-width: 0;
}

.num-input {
    text-align: right;
    font-variant-numeric: tabular-nums;
}

.row-actions {
    display: flex;
    gap: 0.15rem;
    justify-content: flex-end;
}

.detail-btn, .duplicate-btn, .remove-btn {
    width: 2.1rem;
    min-height: 2.1rem;
    padding: 0;
}

.detail-btn.active {
    background: var(--color-primary-soft);
    color: var(--color-primary);
}

.account-cell {
    vertical-align: top;
}

.mode-toggle {
    display: flex;
    align-items: center;
    gap: 0.2rem;
    margin-bottom: 0.25rem;
}

.mode-btn {
    flex: 1;
    background: var(--color-surface-alt);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    padding: 0.08rem 0.3rem;
    font-size: 0.66rem;
    font-weight: 700;
    letter-spacing: 0.02em;
    text-transform: uppercase;
    color: var(--color-text-muted);
    cursor: pointer;
    transition: background .12s ease, color .12s ease;
}

.mode-btn.active {
    background: var(--color-primary);
    border-color: var(--color-primary);
    color: var(--color-on-primary);
}

.secondary-row td {
    border-top: none;
    padding: 0 0.5rem 0.5rem;
    background: var(--color-surface-alt);
}

.secondary-grid {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem 0.7rem;
    padding: 0.5rem 0.6rem;
    border-radius: var(--radius-sm);
    background: var(--color-surface);
    border: 1px dashed var(--color-border);
}

.field-inline {
    display: flex;
    flex-direction: column;
    gap: 0.15rem;
    min-width: min(100%, 140px);
    flex: 1;
}

.field-inline-wide {
    flex-basis: 100%;
}

.field-inline label {
    font-size: 0.72rem;
    font-weight: 600;
    color: var(--color-text-muted);
}

.bp-control {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
    margin-top: 0.35rem;
}

.bp-check-row {
    display: flex;
    align-items: center;
    gap: 0.35rem;
    font-size: 0.76rem;
    color: var(--color-text-muted);
    white-space: normal;
}

.bp-requirement-warning {
    font-size: 0.74rem;
    color: var(--color-warning);
    white-space: normal;
}

/* El efecto sobre la partida: ocupa la fila completa para que la frase se lea
   entera, en vez de cortarse al lado del desplegable. */
.open-item-effect {
    display: block;
    flex-basis: 100%;
    margin-top: 0.3rem;
    font-size: 0.74rem;
    line-height: 1.35;
    white-space: normal;
}

.oie-row { display: block; }
.oie-partial { color: var(--color-info); }
.oie-full { color: var(--color-success); }
.oie-over,
.oie-currency { color: var(--color-danger); }
.oie-pending { color: var(--color-text-muted); }

/* El botón de aplicar y el de quitar van juntos en la misma fila: el de
   quitar solo tiene sentido pegado al que muestra el reparto. */
.bp-apply-btn { white-space: nowrap; }
.bp-clear-btn { flex: 0 0 auto; }

.distribution-modal { min-width: min(42rem, 92vw); }

.distribution-totals {
    display: flex;
    flex-wrap: wrap;
    gap: 1.25rem;
    margin-bottom: 0.75rem;
    font-size: 0.82rem;
}

/* Mientras falte repartir, el número que falta es el que hay que mirar. */
.dist-pending { color: var(--color-warning); }

.dist-amount {
    width: 10rem;
    text-align: right;
}

.dist-over {
    border-color: var(--color-danger);
    background-color: var(--color-danger-soft);
}

.cost-allocation-rule-select.needs-value {
    border-color: var(--color-warning);
    background-color: var(--color-warning-soft);
}

.total-cell {
    font-weight: 700;
}

.form-error {
    margin-top: 0.75rem;
}

.schedule-panel {
    background: var(--color-surface-alt);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    padding: 0.75rem 0.9rem;
    margin-top: 1rem;
}

.schedule-panel .bp-check-row {
    font-size: 0.85rem;
    color: var(--color-text);
}

.schedule-fields {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(min(100%, 14rem), 1fr));
    gap: 0 1rem;
    margin-top: 0.75rem;
}

.schedule-hint {
    grid-column: 1 / -1;
    margin: 0;
}

.form-actions {
    margin-top: 1.25rem;
}

.cancel-btn {
    order: -1;
    margin-right: auto;
}

/* En tarjetas (≤ 1024px): la cuenta o el socio es el título de la línea,
   con el selector de modo arriba y el buscador a lo ancho; los botones de la
   línea, abajo a la derecha. */
@media screen and (max-width: 1024px) {
    .lines-table-wrap {
        border: 0;
        border-radius: 0;
    }

    .lines-table tbody td.account-cell {
        flex-direction: column;
        align-items: stretch;
    }

    .lines-table tbody td.actions-td {
        justify-content: flex-end;
    }

    .lines-table tbody tr.secondary-row {
        margin-top: -0.5rem;
        border-top-left-radius: 0;
        border-top-right-radius: 0;
        background: var(--color-surface-alt);
    }

    .secondary-row td {
        padding: 0;
    }

    .lines-table tfoot td[colspan] {
        justify-content: flex-start;
    }
}

@media (max-width: 640px) {
    .cancel-btn {
        order: 0;
        margin-right: 0;
    }
}
</style>
