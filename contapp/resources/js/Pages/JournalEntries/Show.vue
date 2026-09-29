<script setup>
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import DetailModal from '../../Components/DetailModal.vue';
import DocumentTypeRegisterPanel from '../../Components/DocumentTypeRegisterPanel.vue';
import LedgerPanel from '../../Components/LedgerPanel.vue';
import DocumentSearchModal from '../../Components/DocumentSearchModal.vue';
import RecordNav from '../../Components/RecordNav.vue';
import { confirmAction } from '../../Utils/confirm';
import { formatMoney } from '../../Utils/money';
import {
    ArrowLeftIcon, CopyIcon, DownloadIcon, FileTextIcon, KeyRoundIcon, LinkIcon, LockOpenIcon, PencilIcon, PrinterIcon,
    ScrollTextIcon,
} from '@lucide/vue';

const props = defineProps({
    entry: { type: Object, required: true },
    company: { type: Object, default: () => ({}) },
    documentTypes: { type: Array, default: () => [] },
    businessPartners: { type: Array, default: () => [] },
    nav: { type: Object, default: () => ({ prev: null, next: null, first: null, last: null }) },
});

// Mayor auxiliar de la cuenta o del socio de una línea (mismo LedgerPanel que
// Reports y que JournalEntries/Create.vue): se abre desde la ficha de la línea.
const ledgerOpen = ref(false);
const ledgerDimension = ref('account');
const ledgerOwnerId = ref(null);
const ledgerOwnerLabel = ref('');

function openLedger(line) {
    if (line.business_partner) {
        ledgerDimension.value = 'business-partner';
        ledgerOwnerId.value = line.business_partner.id;
        ledgerOwnerLabel.value = line.business_partner.name;
    } else if (line.account) {
        ledgerDimension.value = 'account';
        ledgerOwnerId.value = line.account.id;
        ledgerOwnerLabel.value = line.account.description_es;
    } else {
        return;
    }

    ledgerOpen.value = true;
}

// «Buscar»: salta directo a otro documento (a diferencia de Create.vue, acá
// elegir SIEMPRE navega a su Show, sin importar si es preliminar o
// contabilizado).
const documentSearchOpen = ref(false);

function onDocumentSelected(found) {
    documentSearchOpen.value = false;
    router.get(route('journal-entries.show', found.id));
}

const statusLabels = { draft: 'Preliminar', posted: 'Contabilizado', voided: 'Anulado' };
const statusBadge = { draft: 'badge-warning', posted: 'badge-success', voided: 'badge-neutral' };

function reverseEntry() {
    confirmAction({
        title: 'Anular asiento',
        message: 'Se va a contabilizar un asiento nuevo que lo revierte por completo — este no se modifica ni se borra.',
        confirmLabel: 'Anular',
        danger: true,
        onConfirm: () => router.post(route('journal-entries.reverse', props.entry.id)),
    });
}

const isForeignEqualSystem = computed(() =>
    !! props.company?.foreign_currency && !! props.company?.system_currency
    && props.company.foreign_currency.id === props.company.system_currency.id
);

const totalDebitLocal = computed(() =>
    props.entry.lines.reduce((sum, l) => sum + (parseFloat(l.debit_local) || 0), 0).toFixed(2)
);
const totalCreditLocal = computed(() =>
    props.entry.lines.reduce((sum, l) => sum + (parseFloat(l.credit_local) || 0), 0).toFixed(2)
);

// Mismo agrupado visual que JournalEntries/Create.vue, solo para lectura acá.
// Por LÍNEA (no por asiento): un asiento puede juntar varias facturas de
// compra a la vez, cada una con su propia clave — ver JournalEntries/Create.vue.
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

function lineOwner(line) {
    if (line.business_partner) return `${line.business_partner.code} — ${line.business_partner.name}`;
    if (line.account) return `${line.account.code} — ${line.account.description_es}`;
    return '—';
}

// --- ficha de una línea (CLAUDE.md secc. 20) ---
//
// El detalle completo de la línea y sus dos correcciones permitidas sobre un
// asiento contabilizado, cada una en su propio modo del modal: nada se edita
// en filas que se despliegan dentro de la tabla.
const selectedLineId = ref(null);
const lineMode = ref('details'); // 'details' | 'due-date' | 'link-partner'

const selectedLine = computed(() => props.entry.lines.find((l) => l.id === selectedLineId.value) ?? null);

function openLine(line) {
    selectedLineId.value = line.id;
    lineMode.value = 'details';
}

function closeLine() {
    selectedLineId.value = null;
    lineMode.value = 'details';
}

function opensItemRetroactively(line) {
    return !!line.business_partner && !line.has_open_item;
}

// Única excepción a la inmutabilidad del asiento contabilizado: el
// vencimiento de una línea no participa de la partida doble, es puro dato
// de gestión de cartera — pero un error de tipeo ahí corrompe para siempre
// la cédula de antigüedad de saldos si nunca se puede corregir. Nada más
// que este campo se puede tocar acá; el resto de la línea sigue intocable.
const dueDateForm = useForm({ due_date: '' });

function startDueDateEdit() {
    dueDateForm.clearErrors();
    dueDateForm.due_date = selectedLine.value.due_date ?? '';
    lineMode.value = 'due-date';
}

function submitLineDueDate() {
    dueDateForm.put(route('journal-entries.lines.update-due-date', [props.entry.id, selectedLine.value.id]), {
        preserveScroll: true,
        onSuccess: () => { lineMode.value = 'details'; },
    });
}

// Tercera y última excepción, igual de acotada: una línea contabilizada
// contra la cuenta de control de un socio que en su momento todavía no
// existía como registro (ej. se cargó un saldo inicial antes de terminar de
// dar de alta a los socios) queda con socio en null para siempre. Solo se
// ofrece vincular un socio cuya cuenta de control (gl_account_id) sea
// EXACTAMENTE la que ya tiene esta línea — nunca se puede "mover" el monto
// a otra cuenta ni reemplazar un socio que ya esté vinculado.
function candidatePartnersForLine(line) {
    if (! line.account) return [];

    return props.businessPartners.filter((p) => p.gl_account_id === line.account.id);
}

function canLinkPartner(line) {
    return !line.business_partner && props.entry.status === 'posted' && candidatePartnersForLine(line).length > 0;
}

const linkPartnerForm = useForm({ business_partner_id: null });

function startLinkPartner() {
    linkPartnerForm.clearErrors();
    linkPartnerForm.business_partner_id = candidatePartnersForLine(selectedLine.value)[0]?.id ?? null;
    lineMode.value = 'link-partner';
}

function submitLinkPartner() {
    linkPartnerForm.put(route('journal-entries.lines.link-business-partner', [props.entry.id, selectedLine.value.id]), {
        preserveScroll: true,
        onSuccess: () => { lineMode.value = 'details'; },
    });
}

function showLineLedger() {
    const line = selectedLine.value;
    closeLine();
    openLedger(line);
}

const lineModalTitle = computed(() => {
    if (!selectedLine.value) return '';
    if (lineMode.value === 'due-date') return opensItemRetroactively(selectedLine.value) ? 'Abrir partida' : 'Corregir vencimiento';
    if (lineMode.value === 'link-partner') return 'Vincular socio de negocio';
    return lineOwner(selectedLine.value);
});
</script>

<template>
    <Head :title="`Asiento ${entry.document_type?.code ?? ''}${entry.document_number ? '-' + entry.document_number : ''}`" />

    <AppLayout title="Detalle de asiento">
        <div class="view-toolbar">
            <Link :href="route('journal-entries.index')" class="btn btn-ghost"><ArrowLeftIcon /> Asientos</Link>
            <RecordNav
                :nav="nav"
                :current-id="entry.id"
                route-name="journal-entries.show"
                searchable
                @search="documentSearchOpen = true"
            />

            <div class="view-actions">
                <button
                    v-if="entry.status === 'posted'"
                    type="button"
                    class="btn btn-ghost btn-danger-text"
                    @click="reverseEntry"
                >Anular</button>
                <DocumentTypeRegisterPanel
                    :document-types="documentTypes"
                    :default-document-type-id="entry.document_type?.id"
                    mode="popover"
                />
                <a :href="route('journal-entries.export', entry.id)" class="btn btn-ghost"><DownloadIcon /> Exportar XLSX</a>
                <a
                    :href="route('journal-entries.presentation', entry.id)"
                    target="_blank"
                    class="btn btn-ghost"
                    title="Abrir este documento en una pantalla aparte, lista para exportar a XLSX/PDF o imprimir"
                ><PrinterIcon /> Presentar documento</a>
                <Link :href="route('journal-entries.duplicate', entry.id)" class="btn btn-ghost"><CopyIcon /> Duplicar</Link>
                <Link v-if="entry.status === 'draft'" :href="route('journal-entries.edit', entry.id)" class="btn btn-primary"><PencilIcon /> Editar</Link>
            </div>
        </div>

        <div v-if="$page.props.errors?.reversal" class="flash flash-error">{{ $page.props.errors.reversal }}</div>

        <p v-if="entry.reversal_of" class="link-note">
            Este asiento anula a <Link :href="route('journal-entries.show', entry.reversal_of.id)">{{ entry.reversal_of.label }}</Link>.
        </p>
        <p v-if="entry.reversed_by" class="link-note">
            Este asiento fue anulado por <Link :href="route('journal-entries.show', entry.reversed_by.id)">{{ entry.reversed_by.label }}</Link>.
        </p>

        <div class="summary-grid">
            <div class="card summary-card">
                <h3>
                    Documento
                    <span class="badge" :class="statusBadge[entry.status] ?? 'badge-neutral'">
                        {{ statusLabels[entry.status] ?? entry.status }}
                    </span>
                </h3>
                <dl>
                    <dt>Documento</dt>
                    <dd>
                        <template v-if="entry.document_number">{{ entry.document_type?.code }}-{{ entry.document_number }}</template>
                        <span v-else class="muted">{{ entry.document_type?.code }} — sin número (borrador)</span>
                    </dd>
                    <dt>Tipo</dt><dd>{{ entry.document_type?.name }}</dd>
                    <dt>Fecha de contabilización</dt><dd>{{ entry.posting_date }}</dd>
                    <dt>Fecha de documento</dt><dd>{{ entry.document_date }}</dd>
                    <template v-if="entry.due_date">
                        <dt>Vencimiento</dt><dd>{{ entry.due_date }}</dd>
                    </template>
                    <template v-if="entry.number_series">
                        <dt>Serie</dt>
                        <dd>
                            {{ entry.number_series.name }}<template v-if="entry.number_series.holder_name"> · {{ entry.number_series.holder_name }}</template>
                            #{{ entry.series_number }}
                        </dd>
                    </template>
                </dl>
            </div>

            <div class="card summary-card">
                <h3>Autoría</h3>
                <dl>
                    <dt>Creado por</dt><dd>{{ entry.created_by?.name ?? '—' }}</dd>
                    <dt>Contabilizado por</dt><dd>{{ entry.posted_by?.name ?? '— (todavía preliminar)' }}</dd>
                    <template v-if="entry.posted_at">
                        <dt>Contabilizado el</dt><dd>{{ entry.posted_at }}</dd>
                    </template>
                </dl>
            </div>

            <div class="card summary-card">
                <h3>Tipo de cambio</h3>
                <dl v-if="entry.exchange_rate_lc_fc">
                    <dt>{{ company.foreign_currency?.code }} → {{ company.local_currency?.code }}</dt>
                    <dd>1 = {{ entry.exchange_rate_lc_fc }}</dd>
                    <dt>{{ company.foreign_currency?.code }} → {{ company.system_currency?.code }}</dt>
                    <dd>
                        <template v-if="isForeignEqualSystem">1.00 (misma moneda)</template>
                        <template v-else>1 = {{ entry.exchange_rate_fc_sc }}</template>
                    </dd>
                </dl>
                <p v-else class="muted small">Se resuelve al contabilizar formalmente — todavía es un borrador.</p>
            </div>
        </div>

        <p v-if="entry.description" class="description-line"><strong>Descripción:</strong> {{ entry.description }}</p>

        <div class="card">
            <div class="table-responsive">
                <table class="lines-table">
                    <thead>
                        <tr>
                            <th>Cuenta / Socio</th>
                            <th>Centro de costo</th>
                            <th class="num">Débito</th>
                            <th class="num">Crédito</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="line in entry.lines"
                            :key="line.id"
                            class="clickable-row"
                            tabindex="0"
                            @click="openLine(line)"
                            @keydown.enter="openLine(line)"
                            @keydown.space.prevent="openLine(line)"
                        >
                            <td>
                                <span class="owner-cell">
                                    {{ lineOwner(line) }}
                                    <span v-if="line.business_partner && !line.has_open_item" class="badge badge-warning">sin partida abierta</span>
                                </span>
                                <div v-if="line.description" class="muted small">{{ line.description }}</div>
                            </td>
                            <td data-label="Centro de costo">
                                <template v-if="line.cost_center">{{ line.cost_center.code }} — {{ line.cost_center.name }}</template>
                                <template v-else>—</template>
                            </td>
                            <td data-label="Débito" class="num">{{ formatMoney(line.debit_local) }}</td>
                            <td data-label="Crédito" class="num">{{ formatMoney(line.credit_local) }}</td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="2">Total</td>
                            <td data-label="Total débito" class="num total-cell">{{ formatMoney(totalDebitLocal) }}</td>
                            <td data-label="Total crédito" class="num total-cell">{{ formatMoney(totalCreditLocal) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <DetailModal :open="!!selectedLine" :title="lineModalTitle" @close="closeLine">
            <template v-if="selectedLine && lineMode === 'details'">
                <dl class="detail-list">
                    <div class="full">
                        <dt>{{ selectedLine.business_partner ? 'Socio de negocio' : 'Cuenta' }}</dt>
                        <dd>{{ lineOwner(selectedLine) }}</dd>
                    </div>
                    <div v-if="selectedLine.business_partner && selectedLine.account" class="full">
                        <dt>Cuenta</dt>
                        <dd>{{ selectedLine.account.code }} — {{ selectedLine.account.description_es }}</dd>
                    </div>
                    <div v-if="selectedLine.description" class="full">
                        <dt>Descripción</dt>
                        <dd>{{ selectedLine.description }}</dd>
                    </div>
                    <div>
                        <dt>Centro de costo</dt>
                        <dd>
                            <template v-if="selectedLine.cost_center">{{ selectedLine.cost_center.code }} — {{ selectedLine.cost_center.name }}</template>
                            <template v-else>—</template>
                            <span v-if="selectedLine.cost_allocation_rule" class="muted"> · norma {{ selectedLine.cost_allocation_rule.code }}</span>
                        </dd>
                    </div>
                    <div>
                        <dt>Moneda</dt>
                        <dd>{{ selectedLine.currency?.code ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt>Débito</dt>
                        <dd class="num-value">{{ formatMoney(selectedLine.debit_local) }}</dd>
                    </div>
                    <div>
                        <dt>Crédito</dt>
                        <dd class="num-value">{{ formatMoney(selectedLine.credit_local) }}</dd>
                    </div>
                    <div>
                        <dt>Débito ({{ company.system_currency?.code }})</dt>
                        <dd class="num-value">{{ formatMoney(selectedLine.debit_system) }}</dd>
                    </div>
                    <div>
                        <dt>Crédito ({{ company.system_currency?.code }})</dt>
                        <dd class="num-value">{{ formatMoney(selectedLine.credit_system) }}</dd>
                    </div>
                    <div>
                        <dt>Vencimiento</dt>
                        <dd>
                            <span v-if="opensItemRetroactively(selectedLine)" class="badge badge-warning">sin partida abierta</span>
                            <template v-else>{{ selectedLine.due_date ?? 'sin definir' }}</template>
                        </dd>
                    </div>
                    <div v-if="selectedLine.tax">
                        <dt>Impuesto</dt>
                        <dd>{{ selectedLine.tax.tax_rate?.name }} ({{ selectedLine.tax.tax_rate?.percentage }}%) s/ {{ selectedLine.tax.taxable_base }}</dd>
                    </div>
                    <div v-if="selectedLine.electronic_key" class="full">
                        <dt><KeyRoundIcon /> Clave electrónica</dt>
                        <dd class="key-value">{{ formatElectronicKey(selectedLine.electronic_key) }}</dd>
                    </div>
                    <div v-if="selectedLine.reference_document || selectedLine.reference_document_date" class="full">
                        <dt><FileTextIcon /> Documento de referencia</dt>
                        <dd>{{ selectedLine.reference_document || 'Documento sin número' }}<template v-if="selectedLine.reference_document_date"> — {{ selectedLine.reference_document_date }}</template></dd>
                    </div>
                </dl>
            </template>

            <form v-if="selectedLine && lineMode === 'due-date'" id="line-due-date-form" @submit.prevent="submitLineDueDate">
                <p class="muted small">{{ lineOwner(selectedLine) }}</p>
                <div class="field">
                    <label for="line-due-date">Nuevo vencimiento</label>
                    <input id="line-due-date" v-model="dueDateForm.due_date" type="date">
                    <span v-if="dueDateForm.errors.due_date" class="error">{{ dueDateForm.errors.due_date }}</span>
                </div>
                <p class="hint">
                    <template v-if="opensItemRetroactively(selectedLine)">
                        Esta línea nunca abrió partida — al confirmar se crea por el monto ya
                        contabilizado, con este vencimiento. El asiento original no se modifica.
                    </template>
                    <template v-else>
                        Solo se corrige el vencimiento de esta línea (y el de su partida abierta, si
                        tiene una) — el resto del asiento contabilizado no se modifica.
                    </template>
                </p>
            </form>

            <form v-if="selectedLine && lineMode === 'link-partner'" id="line-partner-form" @submit.prevent="submitLinkPartner">
                <div class="field">
                    <label for="line-partner">Socio de negocio</label>
                    <select id="line-partner" v-model="linkPartnerForm.business_partner_id">
                        <option v-for="p in candidatePartnersForLine(selectedLine)" :key="p.id" :value="p.id">
                            {{ p.code }} — {{ p.name }}
                        </option>
                    </select>
                    <span v-if="linkPartnerForm.errors.business_partner_id" class="error">{{ linkPartnerForm.errors.business_partner_id }}</span>
                </div>
                <p class="hint">
                    Solo se puede elegir un socio cuya cuenta de control sea exactamente
                    "{{ selectedLine.account?.code }} — {{ selectedLine.account?.description_es }}" — el monto y la
                    cuenta de esta línea no cambian. Si ya tiene vencimiento definido, se le abre la
                    partida en el mismo paso.
                </p>
            </form>

            <template #actions>
                <template v-if="selectedLine && lineMode === 'details'">
                    <button
                        v-if="canLinkPartner(selectedLine)"
                        type="button"
                        class="btn btn-ghost"
                        title="Esta línea se contabilizó antes de que existiera este socio como registro — vincularlo no cambia la cuenta, el monto ni nada más de la línea"
                        @click="startLinkPartner"
                    ><LinkIcon /> Vincular socio</button>
                    <button
                        v-if="entry.status === 'posted'"
                        type="button"
                        class="btn btn-ghost"
                        :title="opensItemRetroactively(selectedLine)
                            ? 'Esta línea nunca abrió partida — definir un vencimiento acá la abre retroactivamente por el monto ya contabilizado, sin tocar el asiento'
                            : 'El resto de la línea no se puede modificar — solo el vencimiento, para no corromper antigüedad de saldos'"
                        @click="startDueDateEdit"
                    >
                        <LockOpenIcon v-if="opensItemRetroactively(selectedLine)" /><PencilIcon v-else />
                        {{ opensItemRetroactively(selectedLine) ? 'Abrir partida' : 'Corregir vencimiento' }}
                    </button>
                    <button
                        v-if="selectedLine.business_partner || selectedLine.account"
                        type="button"
                        class="btn btn-primary"
                        @click="showLineLedger"
                    ><ScrollTextIcon /> Ver mayor auxiliar</button>
                </template>
                <template v-else-if="selectedLine">
                    <button type="button" class="btn btn-ghost" @click="lineMode = 'details'">Cancelar</button>
                    <button
                        v-if="lineMode === 'due-date'"
                        type="submit"
                        form="line-due-date-form"
                        class="btn btn-primary"
                        :disabled="dueDateForm.processing"
                    >Confirmar</button>
                    <button
                        v-else
                        type="submit"
                        form="line-partner-form"
                        class="btn btn-primary"
                        :disabled="linkPartnerForm.processing"
                    >Confirmar</button>
                </template>
            </template>
        </DetailModal>

        <LedgerPanel
            :open="ledgerOpen"
            :dimension="ledgerDimension"
            :owner-id="ledgerOwnerId"
            :owner-label="ledgerOwnerLabel"
            @close="ledgerOpen = false"
        />

        <DocumentSearchModal
            :open="documentSearchOpen"
            title="Buscar documento"
            @close="documentSearchOpen = false"
            @select="onDocumentSelected"
        />
    </AppLayout>
</template>

<style scoped>
.summary-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(100%, 17rem), 1fr));
    gap: 1rem;
    margin-bottom: 1.25rem;
    align-items: stretch;
}

.summary-card { padding: 1rem 1.1rem; }
.summary-card h3 { display: flex; align-items: center; flex-wrap: wrap; gap: 0.5rem; margin: 0 0 0.6rem; font-size: 0.85rem; }

.summary-card dl { margin: 0; display: grid; grid-template-columns: minmax(0, 1fr) auto; row-gap: 0.35rem; column-gap: 0.75rem; font-size: 0.82rem; }
.summary-card dt { color: var(--color-text-muted); font-weight: 500; }
.summary-card dd { margin: 0; text-align: right; overflow-wrap: anywhere; }

.description-line {
    font-size: 0.85rem;
    color: var(--color-text-muted);
    margin: -0.5rem 0 1.25rem;
}

.description-line strong {
    color: var(--color-text);
    font-weight: 600;
}

.link-note {
    font-size: 0.82rem;
    color: var(--color-text-muted);
    margin: 0 0 1rem;
}

.key-value {
    font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace;
    letter-spacing: 0.05em;
}

table { font-size: 0.85rem; }
.owner-cell { display: inline-flex; align-items: center; flex-wrap: wrap; gap: 0.4rem; }
.total-cell { font-weight: 700; }
tfoot td { font-weight: 700; }
.num-value { font-variant-numeric: tabular-nums; }
.hint { margin: 0; }

@media print {
    .card {
        box-shadow: none;
        border: 1px solid #ccc;
    }

    .summary-grid {
        grid-template-columns: repeat(3, 1fr);
    }
}
</style>
