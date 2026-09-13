<script setup>
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import DocumentToolbar from '../../Components/DocumentToolbar.vue';
import DocumentTypeRegisterPanel from '../../Components/DocumentTypeRegisterPanel.vue';
import LedgerPanel from '../../Components/LedgerPanel.vue';
import DocumentSearchModal from '../../Components/DocumentSearchModal.vue';
import { formatMoney } from '../../Utils/money';

const props = defineProps({
    entry: { type: Object, required: true },
    company: { type: Object, default: () => ({}) },
    documentTypes: { type: Array, default: () => [] },
    businessPartners: { type: Array, default: () => [] },
    nav: { type: Object, default: () => ({ prev: null, next: null, first: null, last: null }) },
});

// Doble clic sobre la cuenta/socio de una línea abre su mayor auxiliar
// (mismo LedgerPanel que Reports y que JournalEntries/Create.vue) — también
// disponible acá, en la vista de solo lectura de un asiento ya contabilizado.
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

// Botón "buscar" del DocumentToolbar: salta directo a otro documento
// (a diferencia de Create.vue, acá selecciona SIEMPRE navega a su Show, sin
// importar si es preliminar o contabilizado).
const documentSearchOpen = ref(false);

function onDocumentSelected(found) {
    documentSearchOpen.value = false;
    router.get(route('journal-entries.show', found.id));
}

const statusLabels = { draft: 'Preliminar', posted: 'Contabilizado', voided: 'Anulado' };
const statusBadge = { draft: 'badge-warning', posted: 'badge-success', voided: 'badge-neutral' };

function reverseEntry() {
    if (! confirm('¿Anular este asiento? Se va a contabilizar un asiento nuevo que lo revierte por completo — este no se modifica ni se borra.')) return;

    router.post(route('journal-entries.reverse', props.entry.id));
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

// Única excepción a la inmutabilidad del asiento contabilizado: el
// vencimiento de una línea no participa de la partida doble, es puro dato
// de gestión de cartera — pero un error de tipeo ahí corrompe para siempre
// la cédula de antigüedad de saldos si nunca se puede corregir. Nada más
// que este campo se puede tocar acá; el resto de la línea sigue intocable.
const editingDueDateLineId = ref(null);
const dueDateForm = useForm({ due_date: '' });

function openLineDueDateEdit(line) {
    editingDueDateLineId.value = line.id;
    dueDateForm.reset();
    dueDateForm.due_date = line.due_date;
}

function submitLineDueDate(line) {
    dueDateForm.put(route('journal-entries.lines.update-due-date', [props.entry.id, line.id]), {
        preserveScroll: true,
        onSuccess: () => { editingDueDateLineId.value = null; },
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

const linkingPartnerLineId = ref(null);
const linkPartnerForm = useForm({ business_partner_id: null });

function openLinkPartner(line) {
    linkingPartnerLineId.value = line.id;
    linkPartnerForm.reset();
    linkPartnerForm.business_partner_id = candidatePartnersForLine(line)[0]?.id ?? null;
}

function submitLinkPartner(line) {
    linkPartnerForm.put(route('journal-entries.lines.link-business-partner', [props.entry.id, line.id]), {
        preserveScroll: true,
        onSuccess: () => { linkingPartnerLineId.value = null; },
    });
}
</script>

<template>
    <Head :title="`Asiento ${entry.document_type?.code ?? ''}${entry.document_number ? '-' + entry.document_number : ''}`" />

    <AppLayout title="Detalle de asiento">
        <template #actions>
            <span class="badge" :class="statusBadge[entry.status] ?? 'badge-neutral'">
                {{ statusLabels[entry.status] ?? entry.status }}
            </span>
            <Link v-if="entry.status === 'draft'" :href="route('journal-entries.edit', entry.id)" class="btn btn-ghost">Editar</Link>
            <Link :href="route('journal-entries.duplicate', entry.id)" class="btn btn-ghost">Duplicar</Link>
            <a
                :href="route('journal-entries.presentation', entry.id)"
                target="_blank"
                class="btn btn-ghost"
                title="Abrir este documento en una pantalla aparte, lista para exportar a XLSX/PDF o imprimir"
            >🖶 Presentar documento</a>
            <button
                v-if="entry.status === 'posted'"
                type="button"
                class="btn btn-ghost"
                @click="reverseEntry"
            >Anulación</button>
            <DocumentTypeRegisterPanel
                :document-types="documentTypes"
                :default-document-type-id="entry.document_type?.id"
                mode="popover"
            />
            <Link :href="route('journal-entries.index')" class="btn btn-ghost">← Volver</Link>
        </template>

        <DocumentToolbar
            :new-href="route('journal-entries.create')"
            :export-href="route('journal-entries.export', entry.id)"
            :first-href="nav.first && nav.first !== entry.id ? route('journal-entries.show', nav.first) : null"
            :prev-href="nav.prev ? route('journal-entries.show', nav.prev) : null"
            :next-href="nav.next ? route('journal-entries.show', nav.next) : null"
            :last-href="nav.last && nav.last !== entry.id ? route('journal-entries.show', nav.last) : null"
            @find="documentSearchOpen = true"
        />

        <div v-if="$page.props.errors?.reversal" class="flash flash-error">{{ $page.props.errors.reversal }}</div>

        <p v-if="entry.reversal_of" class="link-note">
            Este asiento anula a <Link :href="route('journal-entries.show', entry.reversal_of.id)">{{ entry.reversal_of.label }}</Link>.
        </p>
        <p v-if="entry.reversed_by" class="link-note">
            Este asiento fue anulado por <Link :href="route('journal-entries.show', entry.reversed_by.id)">{{ entry.reversed_by.label }}</Link>.
        </p>

        <div class="summary-grid">
            <div class="card summary-card">
                <h3>Documento</h3>
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
            <table class="lines-table">
                <thead>
                    <tr>
                        <th>Cuenta / Socio</th>
                        <th>Centro de costo</th>
                        <th>Moneda</th>
                        <th class="num">Débito</th>
                        <th class="num">Crédito</th>
                        <th class="num">Débito ({{ company.system_currency?.code }})</th>
                        <th class="num">Crédito ({{ company.system_currency?.code }})</th>
                    </tr>
                </thead>
                <tbody>
                    <template v-for="line in entry.lines" :key="line.id">
                        <tr>
                            <td
                                class="account-cell-ro"
                                :class="{ clickable: line.business_partner || line.account }"
                                title="Doble clic: ver mayor auxiliar"
                                @dblclick="openLedger(line)"
                            >
                                <template v-if="line.business_partner">{{ line.business_partner.code }} — {{ line.business_partner.name }}</template>
                                <template v-else-if="line.account">{{ line.account.code }} — {{ line.account.description_es }}</template>
                                <div v-if="!line.business_partner && entry.status === 'posted' && candidatePartnersForLine(line).length" class="muted small">
                                    <button
                                        type="button"
                                        class="link-btn"
                                        title="Esta línea se contabilizó antes de que existiera este socio como registro — vincularlo no cambia la cuenta, el monto ni nada más de la línea"
                                        @click="openLinkPartner(line)"
                                    >🔗 vincular socio de negocio</button>
                                </div>
                                <div v-if="line.description" class="muted small">{{ line.description }}</div>
                                <div v-if="line.electronic_key" class="muted small key-value">
                                    🔑 {{ formatElectronicKey(line.electronic_key) }}
                                </div>
                                <div v-if="line.tax" class="muted small">
                                    {{ line.tax.tax_rate?.name }} ({{ line.tax.tax_rate?.percentage }}%) s/ {{ line.tax.taxable_base }}
                                </div>
                                <div class="muted small due-date-row">
                                    <template v-if="line.business_partner && !line.has_open_item">
                                        <span class="badge badge-warning">sin partida abierta</span>
                                    </template>
                                    <template v-else>
                                        Vence: {{ line.due_date ?? 'sin definir' }}
                                    </template>
                                    <button
                                        v-if="entry.status === 'posted'"
                                        type="button"
                                        class="link-btn"
                                        :title="line.business_partner && !line.has_open_item
                                            ? 'Esta línea nunca abrió partida — definir un vencimiento acá la abre retroactivamente por el monto ya contabilizado, sin tocar el asiento'
                                            : 'El resto de la línea no se puede modificar — solo el vencimiento, para no corromper antigüedad de saldos'"
                                        @click="openLineDueDateEdit(line)"
                                    >{{ line.business_partner && !line.has_open_item ? '🔓 abrir partida' : '✎ corregir' }}</button>
                                </div>
                                <div v-if="line.reference_document || line.reference_document_date" class="muted small">
                                    📄 {{ line.reference_document || 'Documento sin número' }}<template v-if="line.reference_document_date"> — {{ line.reference_document_date }}</template>
                                </div>
                            </td>
                            <td>
                                <template v-if="line.cost_center">{{ line.cost_center.code }} — {{ line.cost_center.name }}</template>
                                <template v-else>—</template>
                                <div v-if="line.cost_allocation_rule" class="muted small">norma {{ line.cost_allocation_rule.code }}</div>
                            </td>
                            <td>{{ line.currency?.code }}</td>
                            <td class="num">{{ formatMoney(line.debit_local) }}</td>
                            <td class="num">{{ formatMoney(line.credit_local) }}</td>
                            <td class="num muted">{{ formatMoney(line.debit_system) }}</td>
                            <td class="num muted">{{ formatMoney(line.credit_system) }}</td>
                        </tr>
                        <tr v-if="editingDueDateLineId === line.id">
                            <td colspan="7">
                                <form class="due-date-edit-form" @submit.prevent="submitLineDueDate(line)">
                                    <div class="field">
                                        <label>Nuevo vencimiento</label>
                                        <input v-model="dueDateForm.due_date" type="date">
                                    </div>
                                    <div class="field actions">
                                        <button type="submit" class="btn btn-primary" :disabled="dueDateForm.processing">Confirmar</button>
                                        <button type="button" class="btn btn-ghost" @click="editingDueDateLineId = null">Cancelar</button>
                                    </div>
                                    <p class="hint span-all">
                                        <template v-if="line.business_partner && !line.has_open_item">
                                            Esta línea nunca abrió partida — al confirmar se crea por el monto ya
                                            contabilizado, con este vencimiento. El asiento original no se modifica.
                                        </template>
                                        <template v-else>
                                            Solo se corrige el vencimiento de esta línea (y el de su partida abierta, si
                                            tiene una) — el resto del asiento contabilizado no se modifica.
                                        </template>
                                    </p>
                                    <p v-if="dueDateForm.errors.due_date" class="error span-all">{{ dueDateForm.errors.due_date }}</p>
                                </form>
                            </td>
                        </tr>
                        <tr v-if="linkingPartnerLineId === line.id">
                            <td colspan="7">
                                <form class="due-date-edit-form" @submit.prevent="submitLinkPartner(line)">
                                    <div class="field">
                                        <label>Socio de negocio</label>
                                        <select v-model="linkPartnerForm.business_partner_id">
                                            <option v-for="p in candidatePartnersForLine(line)" :key="p.id" :value="p.id">
                                                {{ p.code }} — {{ p.name }}
                                            </option>
                                        </select>
                                    </div>
                                    <div class="field actions">
                                        <button type="submit" class="btn btn-primary" :disabled="linkPartnerForm.processing">Confirmar</button>
                                        <button type="button" class="btn btn-ghost" @click="linkingPartnerLineId = null">Cancelar</button>
                                    </div>
                                    <p class="hint span-all">
                                        Solo se puede elegir un socio cuya cuenta de control sea exactamente
                                        "{{ line.account?.code }} — {{ line.account?.description_es }}" — el monto y la
                                        cuenta de esta línea no cambian. Si ya tiene vencimiento definido, se le abre la
                                        partida en el mismo paso.
                                    </p>
                                    <p v-if="linkPartnerForm.errors.business_partner_id" class="error span-all">{{ linkPartnerForm.errors.business_partner_id }}</p>
                                </form>
                            </td>
                        </tr>
                    </template>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="3">Total</td>
                        <td class="num total-cell">{{ formatMoney(totalDebitLocal) }}</td>
                        <td class="num total-cell">{{ formatMoney(totalCreditLocal) }}</td>
                        <td colspan="2"></td>
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
            title="Buscar documento"
            @close="documentSearchOpen = false"
            @select="onDocumentSelected"
        />
    </AppLayout>
</template>

<style scoped>
.summary-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 1rem;
    margin-bottom: 1.25rem;
    align-items: stretch;
}

.summary-card { padding: 1rem 1.1rem; }
.summary-card h3 { margin: 0 0 0.6rem; font-size: 0.85rem; }

dl { margin: 0; display: grid; grid-template-columns: 1fr auto; row-gap: 0.35rem; column-gap: 0.75rem; font-size: 0.82rem; }
dt { color: var(--color-text-muted); font-weight: 500; }
dd { margin: 0; text-align: right; }

.description-line {
    font-size: 0.85rem;
    color: var(--color-text-muted);
    margin: -0.5rem 0 1.25rem;
}

.description-line strong {
    color: var(--color-text);
    font-weight: 600;
}

.flash-error {
    background: var(--color-danger-soft);
    color: var(--color-danger);
    padding: 0.6rem 0.9rem;
    border-radius: 6px;
    margin: -0.5rem 0 1.25rem;
    font-size: 0.85rem;
}

.link-note {
    font-size: 0.82rem;
    color: var(--color-text-muted);
    margin: -0.5rem 0 1.25rem;
}

.key-value {
    font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace;
    letter-spacing: 0.05em;
}

table { font-size: 0.85rem; width: 100%; }
th, td { text-align: left; padding: 0.45rem 0.9rem; border-top: 1px solid var(--color-border); }
.num { text-align: right; }
.muted { color: var(--color-text-muted); }
.small { font-size: 0.76rem; }
.total-cell { font-weight: 700; }

.due-date-row { display: flex; align-items: baseline; gap: 0.4rem; }

.link-btn {
    background: none;
    border: none;
    padding: 0;
    color: var(--color-primary);
    font-size: 0.76rem;
    cursor: pointer;
    text-decoration: underline dotted;
}

.due-date-edit-form {
    display: flex;
    align-items: flex-end;
    gap: 1rem;
    background: var(--color-surface-alt);
    padding: 0.75rem 0.9rem;
    flex-wrap: wrap;
}

.due-date-edit-form .field { margin-bottom: 0; min-width: 160px; }
.due-date-edit-form label { display: block; font-size: 0.78rem; color: var(--color-text-muted); margin-bottom: 0.25rem; }
.due-date-edit-form input {
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    padding: 0.4rem 0.55rem;
    font-size: 0.85rem;
}
.due-date-edit-form .actions { flex-direction: row; gap: 0.5rem; }
.span-all { width: 100%; }
.hint { color: var(--color-text-muted); font-size: 0.76rem; margin: 0; }
.error { color: var(--color-danger); font-size: 0.78rem; margin: 0; }

.account-cell-ro.clickable {
    cursor: pointer;
}

.account-cell-ro.clickable:hover {
    background: var(--color-primary-soft);
}

@media print {
    .link-btn,
    .due-date-row button {
        display: none !important;
    }

    .card {
        box-shadow: none;
        border: 1px solid #ccc;
    }

    .summary-grid {
        grid-template-columns: repeat(3, 1fr);
    }
}
</style>
