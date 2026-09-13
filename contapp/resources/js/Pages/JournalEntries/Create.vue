<script setup>
import { Head, router } from '@inertiajs/vue3';
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import DocumentToolbar from '../../Components/DocumentToolbar.vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import MoneyInput from '../../Components/MoneyInput.vue';
import SearchableCombobox from '../../Components/SearchableCombobox.vue';
import LedgerPanel from '../../Components/LedgerPanel.vue';
import DocumentSearchModal from '../../Components/DocumentSearchModal.vue';
import { formatMoney } from '../../Utils/money';

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
    if (line.opens_item) line.apply_to_open_item_id = null;
}

function onApplyToOpenItemChange(line) {
    if (line.apply_to_open_item_id) line.opens_item = false;
}

// Aviso previo (no autoritativo — PostJournalService::post() es quien
// realmente lo exige al contabilizar) para que el usuario vea de un vistazo
// qué línea todavía le falta resolver antes de intentar contabilizar.
function bpLineRequirementUnmet(line) {
    if (line.mode !== 'partner' || bpLineRequirement.value === 'none') return false;

    const satisfiesDueDate = !! line.opens_item;
    const satisfiesApplication = !! line.apply_to_open_item_id;

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
// partida (ver bpLineRequirement); el botón "⋯" alterna el resto de casos.
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
    if (form.isDirty && ! confirm('¿Salir sin guardar? Se pierde lo digitado en este formulario.')) return;

    router.get(route('journal-entries.index'));
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
        <template #actions>
            <a
                v-if="entry?.id"
                :href="route('journal-entries.presentation', entry.id)"
                target="_blank"
                class="btn btn-ghost"
                title="Abrir este documento en una pantalla aparte, lista para exportar a XLSX/PDF o imprimir"
            >🖶 Presentar documento</a>
            <button type="button" class="btn btn-ghost" @click="cancel">← Salir / Volver</button>
        </template>

        <DocumentToolbar
            :new-href="route('journal-entries.create')"
            can-save
            :saving="form.processing"
            :first-href="entry?.id && nav.first && nav.first !== entry.id ? route('journal-entries.show', nav.first) : null"
            :prev-href="entry?.id && nav.prev ? route('journal-entries.show', nav.prev) : null"
            :next-href="entry?.id && nav.next ? route('journal-entries.show', nav.next) : null"
            :last-href="entry?.id && nav.last && nav.last !== entry.id ? route('journal-entries.show', nav.last) : null"
            @save="submit('draft')"
            @find="documentSearchOpen = true"
        />

        <form class="card form-card" @submit.prevent="submit()">
            <button
                type="button"
                class="load-existing-btn"
                title="Buscar un documento ya registrado por número, descripción o tipo, y cargarlo acá como punto de partida"
                @click="documentSearchOpen = true"
            >
                <svg viewBox="0 0 24 24"><circle cx="10.5" cy="10.5" r="6.5" /><line x1="20" y1="20" x2="15.3" y2="15.3" /></svg>
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
                con 🔑 (ver account-cell) para poder ver de un vistazo cuáles
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
                    <template v-else-if="(activeLine.electronic_key || '').length === 50">✓ 50 dígitos completos para esta línea.</template>
                    <template v-else>Pegá la clave de 50 dígitos de la factura que corresponde a esta línea. Si el asiento junta varias facturas, hacé clic en cada línea y pegale la clave que le toca.</template>
                </span>
                <span v-if="activeLineElectronicKeyError" class="error">{{ activeLineElectronicKeyError }}</span>
            </div>

            <div class="lines-table-scroll">
            <table class="lines-table">
                <colgroup>
                    <col style="width: 21%">
                    <col style="width: 7%">
                    <col style="width: 11%">
                    <col style="width: 11%">
                    <col style="width: 38%">
                    <col style="width: 12%">
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
                                <span v-if="line.electronic_key" class="key-indicator" title="Esta línea tiene una clave numérica electrónica asociada">🔑</span>
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
                        <td>
                            <select v-model="line.currency_id" required>
                                <option v-for="c in currencies" :key="c.id" :value="c.id">{{ c.code }}</option>
                            </select>
                        </td>
                        <td>
                            <MoneyInput class="num-input" :model-value="line.debit" @update:model-value="(v) => onDebitInput(line, v)" />
                        </td>
                        <td>
                            <MoneyInput class="num-input" :model-value="line.credit" @update:model-value="(v) => onCreditInput(line, v)" />
                        </td>
                        <td>
                            <input v-model="line.description" type="text" maxlength="255">
                        </td>
                        <td>
                            <div class="row-actions">
                                <button
                                    type="button"
                                    class="btn btn-ghost detail-btn"
                                    :class="{ active: showSecondary(line) }"
                                    title="Norma de reparto, IVA, documento de referencia, vencimiento..."
                                    @click="toggleSecondary(line)"
                                >⋯</button>
                                <button
                                    type="button"
                                    class="btn btn-ghost duplicate-btn"
                                    title="Duplicar línea"
                                    @click="duplicateLine(index)"
                                >⧉</button>
                                <button
                                    type="button"
                                    class="btn btn-ghost remove-btn"
                                    title="Eliminar línea"
                                    :disabled="form.lines.length <= 1"
                                    @click="removeLine(index)"
                                >✕</button>
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

                                    <select
                                        v-if="showsApplicationControl"
                                        v-model="line.apply_to_open_item_id"
                                        class="bp-open-item-select"
                                        @change="onApplyToOpenItemChange(line)"
                                    >
                                        <option :value="null">— Aplicar a partida —</option>
                                        <option v-for="oi in openItemsForLine(line)" :key="oi.id" :value="oi.id">
                                            {{ oi.document_type_code }}-{{ oi.document_number }} · vence {{ oi.due_date }} · saldo {{ oi.currency?.code }} {{ formatMoney(oi.balance) }}
                                        </option>
                                    </select>
                                    <span v-if="showsApplicationControl && line.business_partner_id && !openItemsForLine(line).length" class="muted small">
                                        Este socio no tiene partidas abiertas.
                                    </span>

                                    <span v-if="bpLineRequirementUnmet(line)" class="bp-requirement-warning" :title="`Este tipo de documento exige: ${bpLineRequirementLabels[bpLineRequirement]}`">
                                        ⚠ falta {{ bpLineRequirementLabels[bpLineRequirement].toLowerCase() }}
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
                            <button type="button" class="btn btn-ghost" @click="addLine">+ Línea</button>
                        </td>
                        <td class="num total-cell">{{ formatMoney(totalDebit) }}</td>
                        <td class="num total-cell">{{ formatMoney(totalCredit) }}</td>
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

.load-existing-btn svg {
    width: 16px;
    height: 16px;
    fill: none;
    stroke: currentColor;
    stroke-width: 2;
    stroke-linecap: round;
    flex-shrink: 0;
}

.header-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 0.6rem 0.85rem;
    margin-bottom: 0.85rem;
}

.field label {
    display: block;
    font-size: 0.7rem;
    font-weight: 700;
    letter-spacing: 0.02em;
    text-transform: uppercase;
    color: var(--color-text-muted);
    margin-bottom: 0.2rem;
}

.description-field {
    grid-column: span 2;
}

.active-line-panel {
    background: var(--color-surface-alt);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    padding: 0.55rem 0.8rem;
    margin-bottom: 0.6rem;
}

.active-key-label {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
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

.active-row {
    background: var(--color-primary-soft);
}

.key-indicator {
    font-size: 0.8rem;
    line-height: 1;
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
    margin-top: 0.2rem;
}

.lines-table-scroll {
    overflow-x: auto;
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
}

.lines-table {
    width: 100%;
    min-width: 760px;
    table-layout: fixed;
    font-size: 0.83rem;
    border-collapse: collapse;
}

.lines-table thead th {
    position: sticky;
    top: 0;
    z-index: 1;
    background: var(--color-surface-alt);
    font-size: 0.7rem;
    font-weight: 700;
    letter-spacing: 0.02em;
    text-transform: uppercase;
    color: var(--color-text-muted);
    text-align: left;
}

.lines-table th, .lines-table td {
    padding: 0.32rem 0.5rem;
    border-top: 1px solid var(--color-border);
    overflow: hidden;
}

.lines-table tbody tr:not(.secondary-row):hover {
    background: var(--color-surface-alt);
}

.lines-table tbody tr.active-row {
    background: var(--color-primary-soft);
}

.num-input {
    width: 100%;
    text-align: right;
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    padding: 0.3rem 0.45rem;
    font-variant-numeric: tabular-nums;
    transition: border-color .12s ease, box-shadow .12s ease;
}

select, .lines-table input[type="text"], .lines-table input[type="date"] {
    width: 100%;
    max-width: 100%;
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    padding: 0.3rem 0.45rem;
    font-size: 0.83rem;
    color: var(--color-text);
    text-overflow: ellipsis;
    transition: border-color .12s ease, box-shadow .12s ease;
}

select:focus, .lines-table input:focus, .num-input:focus {
    outline: none;
    border-color: var(--color-primary);
    box-shadow: 0 0 0 2px var(--color-primary-soft);
}

.row-actions {
    display: flex;
    gap: 0.15rem;
    justify-content: flex-end;
}

.detail-btn, .duplicate-btn, .remove-btn {
    padding: 0.18rem 0.4rem;
    font-size: 0.78rem;
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
    min-width: 140px;
    flex: 1;
}

.field-inline-wide {
    flex-basis: 100%;
}

.field-inline label {
    font-size: 0.66rem;
    font-weight: 700;
    letter-spacing: 0.02em;
    text-transform: uppercase;
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
    font-size: 0.72rem;
    color: var(--color-text-muted);
    white-space: normal;
}

.bp-open-item-select {
    font-size: 0.76rem;
}

.bp-requirement-warning {
    font-size: 0.72rem;
    color: var(--color-warning);
    white-space: normal;
}

.muted {
    color: var(--color-text-muted);
}

.small {
    font-size: 0.76rem;
}

.cost-allocation-rule-select.needs-value {
    border-color: var(--color-warning);
    background: var(--color-warning-soft);
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
    grid-template-columns: repeat(3, 1fr);
    gap: 1rem;
    margin-top: 0.75rem;
}

.schedule-hint {
    grid-column: 1 / -1;
    margin: 0;
}

.form-actions {
    margin-top: 1.25rem;
    display: flex;
    gap: 0.75rem;
}

.cancel-btn {
    margin-left: auto;
}
</style>
