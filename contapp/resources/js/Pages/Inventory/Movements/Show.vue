<script setup>
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '../../../Layouts/AppLayout.vue';
import ConfirmModal from '../../../Components/ConfirmModal.vue';
import DetailModal from '../../../Components/DetailModal.vue';
import PurchaseCycleMap from '../../../Components/PurchaseCycleMap.vue';
import { ArrowLeftIcon, ChevronDownIcon } from '@lucide/vue';

const props = defineProps({
    document: { type: Object, required: true },
    operationLabel: { type: String, default: '' },
    // Null en los movimientos que no son parte de un ciclo de compra.
    cycle: { type: Object, default: null },
    voidable: { type: Boolean, default: false },
    customsOffices: { type: Object, default: () => ({}) },
});

const customsOfficeLabel = computed(() =>
    props.customsOffices[props.document.customs_office] ?? props.document.customs_office
);

function money(value) {
    return Number(value ?? 0).toLocaleString('es-CR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function quantity(value) {
    return Number(value ?? 0).toLocaleString('es-CR', { minimumFractionDigits: 0, maximumFractionDigits: 6 });
}

function movementOf(line) {
    return line.stock_journals?.[0] ?? null;
}

const totalLocal = props.document.lines.reduce(
    (sum, line) => sum + Number(movementOf(line)?.total_cost_local ?? 0), 0
);

// "Copiar a": el destino depende de en qué punto del ciclo está la compra.
// Sin facturar, lo que falta es la factura del proveedor que liquide la cuenta
// puente; ya facturada, lo único que se puede emitir es una nota de crédito.
const copyTargets = computed(() => {
    if (props.document.operation !== 'purchase_receipt' || props.document.status !== 'posted') {
        return [];
    }

    if (! props.document.invoice_journal_entry_id) {
        return [{
            label: 'Factura de compra',
            hint: 'Cierra el ciclo: liquida la cuenta puente y genera la deuda con el proveedor.',
            href: route('supplier-invoices.index', { receipt: props.document.id }),
        }];
    }

    return [{
        label: 'Nota de crédito',
        hint: 'Devuelve mercancía al proveedor y reduce la deuda de la factura.',
        href: route('supplier-credit-notes.create', props.document.id),
    }];
});

const copyOpen = ref(false);

// Ficha de una línea (CLAUDE.md secc. 20): el resto de sus cifras.
const selectedLineId = ref(null);
const selectedLine = computed(() => props.document.lines.find((l) => l.id === selectedLineId.value) ?? null);

const page = usePage();

const confirmingVoid = ref(false);
const voiding = ref(false);

function submitVoid() {
    voiding.value = true;

    router.post(
        route('inventory-movements.void', props.document.id),
        { posting_date: new Date().toISOString().slice(0, 10) },
        { onFinish: () => { voiding.value = false; confirmingVoid.value = false; } },
    );
}
</script>

<template>
    <Head :title="`Movimiento ${operationLabel}`" />

    <AppLayout :title="operationLabel">
        <div class="view-toolbar">
            <Link :href="route('inventory-movements.index')" class="btn btn-ghost"><ArrowLeftIcon /> Movimientos</Link>
            <div class="view-actions">
                <button v-if="voidable" type="button" class="btn btn-ghost btn-danger-text" @click="confirmingVoid = true">
                    Anular entrada
                </button>

                <div v-if="copyTargets.length" class="copy-to" @keydown.esc="copyOpen = false">
                    <button type="button" class="btn btn-primary" :aria-expanded="copyOpen" @click="copyOpen = ! copyOpen">
                        Copiar a <ChevronDownIcon />
                    </button>
                    <div v-if="copyOpen" class="copy-backdrop" @click="copyOpen = false"></div>
                    <div v-if="copyOpen" class="copy-menu">
                        <Link
                            v-for="target in copyTargets"
                            :key="target.label"
                            :href="target.href"
                            class="copy-option"
                        >
                            <strong>{{ target.label }}</strong>
                            <span class="muted small">{{ target.hint }}</span>
                        </Link>
                    </div>
                </div>
            </div>
        </div>

        <div v-if="page.props.errors?.credit_note" class="flash flash-error">{{ page.props.errors.credit_note }}</div>
        <div v-if="page.props.errors?.void" class="flash flash-error">{{ page.props.errors.void }}</div>

        <PurchaseCycleMap v-if="cycle" :cycle="cycle" />

        <ConfirmModal
            :open="confirmingVoid"
            title="Anular la entrada por compra"
            message="La mercancía saldrá del inventario al mismo costo con que entró y el asiento se revertirá línea por línea, dejando la cuenta puente en cero. No se borra nada: la entrada queda marcada como anulada y el ciclo lo muestra. Esto no se puede deshacer."
            confirm-label="Anular la entrada"
            danger
            :processing="voiding"
            @confirm="submitVoid"
            @cancel="confirmingVoid = false"
        />

        <div class="card summary">
            <div><span class="muted small">Tipo de documento</span><strong>{{ document.document_type?.code }}</strong></div>
            <div><span class="muted small">Fecha de contabilización</span><strong>{{ document.posting_date }}</strong></div>
            <div><span class="muted small">Fecha del documento</span><strong>{{ document.document_date }}</strong></div>
            <div>
                <span class="muted small">Asiento generado</span>
                <Link :href="route('journal-entries.show', document.journal_entry_id)" class="link">
                    #{{ document.journal_entry?.document_number }}
                </Link>
            </div>
            <div v-if="document.business_partner">
                <span class="muted small">Proveedor</span>
                <strong>{{ document.business_partner.code }} — {{ document.business_partner.name }}</strong>
            </div>
            <template v-if="document.is_import">
                <div>
                    <span class="muted small">DUA</span>
                    <strong class="num">{{ document.customs_declaration ?? 'sin indicar' }}</strong>
                </div>
                <div v-if="document.customs_office">
                    <span class="muted small">Aduana</span>
                    <strong>{{ customsOfficeLabel }}</strong>
                </div>
                <div v-if="document.transport_document">
                    <span class="muted small">Documento de transporte</span>
                    <strong>{{ document.transport_document }}</strong>
                </div>
                <div v-if="document.origin_country">
                    <span class="muted small">Origen</span>
                    <strong>{{ document.origin_country }}</strong>
                </div>
                <div v-if="document.customs_date">
                    <span class="muted small">Fecha del DUA</span>
                    <strong class="num">{{ document.customs_date }}</strong>
                </div>
            </template>
            <div v-if="document.operation === 'purchase_receipt'">
                <span class="muted small">Cuenta puente GR/IR</span>
                <Link
                    v-if="document.invoice_journal_entry_id"
                    :href="route('journal-entries.show', document.invoice_journal_entry_id)"
                    class="link"
                >
                    Liquidada por factura #{{ document.invoice_journal_entry?.document_number }}
                </Link>
                <span v-else class="badge badge-warning">Pendiente de facturar</span>
            </div>
            <template v-if="document.operation === 'purchase_return'">
                <div>
                    <span class="muted small">Recepción devuelta</span>
                    <Link :href="route('inventory-movements.show', document.source_document_id)" class="link">
                        #{{ document.source_document_id }} del {{ document.source_document?.posting_date }}
                    </Link>
                </div>
                <div>
                    <span class="muted small">Nota de crédito</span>
                    <Link :href="route('journal-entries.show', document.invoice_journal_entry_id)" class="link">
                        #{{ document.invoice_journal_entry?.document_number }}
                    </Link>
                </div>
            </template>
            <div>
                <span class="muted small">Estado</span>
                <span class="badge" :class="document.status === 'posted' ? 'badge-success' : 'badge-neutral'">
                    {{ document.status === 'posted' ? 'Contabilizado' : 'Anulado' }}
                </span>
            </div>
        </div>

        <p v-if="document.description" class="hint">{{ document.description }}</p>

        <div class="card">
            <div class="table-responsive table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>Artículo</th>
                            <th>Almacén</th>
                            <th>Mov.</th>
                            <th class="num">Cantidad</th>
                            <th class="num">Total (LC)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="line in document.lines"
                            :key="line.id"
                            class="clickable-row"
                            tabindex="0"
                            @click="selectedLineId = line.id"
                            @keydown.enter="selectedLineId = line.id"
                            @keydown.space.prevent="selectedLineId = line.id"
                        >
                            <td><span class="line-number">{{ line.line_number }}.</span> {{ line.item?.code }} — {{ line.item?.name }}</td>
                            <td data-label="Almacén" class="code-cell">{{ line.warehouse?.code }}</td>
                            <td data-label="Mov.">
                                <span class="badge" :class="movementOf(line)?.direction === 'in' ? 'badge-success' : 'badge-warning'">
                                    {{ movementOf(line)?.direction === 'in' ? 'Entra' : 'Sale' }}
                                </span>
                            </td>
                            <td data-label="Cantidad" class="num">{{ quantity(line.quantity) }}</td>
                            <td data-label="Total (LC)" class="num">{{ money(movementOf(line)?.total_cost_local) }}</td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="4" class="total-label"><strong>Total</strong></td>
                            <td data-label="Total (LC)" class="num"><strong>{{ money(totalLocal) }}</strong></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <DetailModal
            :open="!!selectedLine"
            :title="selectedLine ? `${selectedLine.item?.code} — ${selectedLine.item?.name}` : ''"
            @close="selectedLineId = null"
        >
            <template #badge>
                <span v-if="selectedLine" class="badge" :class="movementOf(selectedLine)?.direction === 'in' ? 'badge-success' : 'badge-warning'">
                    {{ movementOf(selectedLine)?.direction === 'in' ? 'Entra' : 'Sale' }}
                </span>
            </template>

            <dl v-if="selectedLine" class="detail-list">
                <div>
                    <dt>Línea</dt>
                    <dd>{{ selectedLine.line_number }}</dd>
                </div>
                <div>
                    <dt>Almacén</dt>
                    <dd>{{ selectedLine.warehouse?.code ?? '—' }}</dd>
                </div>
                <div>
                    <dt>Cantidad</dt>
                    <dd>{{ quantity(selectedLine.quantity) }}</dd>
                </div>
                <div>
                    <dt>Costo unitario (LC)</dt>
                    <dd>{{ money(selectedLine.unit_cost_local) }}</dd>
                </div>
                <div>
                    <dt>Total (LC)</dt>
                    <dd>{{ money(movementOf(selectedLine)?.total_cost_local) }}</dd>
                </div>
                <div>
                    <dt>Total (FC)</dt>
                    <dd>{{ money(movementOf(selectedLine)?.total_cost_foreign) }}</dd>
                </div>
                <div>
                    <dt>Saldo tras el movimiento</dt>
                    <dd>{{ quantity(movementOf(selectedLine)?.balance_quantity) }}</dd>
                </div>
            </dl>
        </DetailModal>

        <p class="hint">
            Cada línea dejó su huella en el kardex y en el asiento contable, que se generaron en la misma transacción.
        </p>
    </AppLayout>
</template>

<style scoped>
.summary {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem 1.5rem;
    padding: 1rem 1.25rem;
    margin-bottom: 0.75rem;
}

.summary > div { display: flex; flex-direction: column; gap: 0.15rem; min-width: 0; }

.hint { margin: 0.75rem 0 0.75rem; }

table { font-size: 0.85rem; }
.code-cell { font-variant-numeric: tabular-nums; }
.line-number { color: var(--color-text-muted); font-variant-numeric: tabular-nums; }
.total-label { text-align: right; }
.link { color: var(--color-primary); text-decoration: none; font-weight: 600; }

@media screen and (max-width: 1024px) {
    .total-label { text-align: left; }
}
.link:hover { text-decoration: underline; }

.copy-to { position: relative; }

/* Cierra el menú al tocar cualquier otro punto de la pantalla. */
.copy-backdrop { position: fixed; inset: 0; z-index: 19; }

.copy-menu {
    position: absolute;
    right: 0;
    top: calc(100% + 0.35rem);
    z-index: 20;
    min-width: min(17rem, calc(100vw - 2rem));
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: 0.5rem;
    box-shadow: 0 8px 24px rgb(0 0 0 / 12%);
    overflow: hidden;
}

.copy-option {
    display: flex;
    flex-direction: column;
    gap: 0.15rem;
    padding: 0.6rem 0.85rem;
    text-decoration: none;
    color: inherit;
    white-space: normal;
}

.copy-option + .copy-option { border-top: 1px solid var(--color-border); }
.copy-option:hover { background: var(--color-surface-muted, rgb(0 0 0 / 4%)); }
</style>
