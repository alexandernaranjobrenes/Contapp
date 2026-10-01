<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import DetailModal from '../../../Components/DetailModal.vue';
import { useRecordDetail } from '../../../Utils/recordDetail';
import { ArrowLeftIcon, ArrowRightIcon, DownloadIcon } from '@lucide/vue';

const props = defineProps({
    document: { type: Object, required: true },
    catalogs: { type: Object, required: true },
});

function money(value) {
    return Number(value ?? 0).toLocaleString('es-CR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function quantity(value) {
    return Number(value ?? 0).toLocaleString('es-CR', { minimumFractionDigits: 0, maximumFractionDigits: 3 });
}

const currency = props.document.currency?.code ?? '';

// Una nota de crédito no se corrige con otra nota: lo que corresponde ahí es
// una nota de débito, que es otro documento.
const canCredit = props.document.fiscal_document_type !== '03' && props.document.status === 'posted';

// Ficha de la línea (CLAUDE.md secc. 20): CAByS, bodega, descuento e
// impuesto, que no caben como columnas sin desplazar la tabla de lado.
const { selected: selectedLine, openDetail: openLine, closeDetail: closeLine } = useRecordDetail(() => props.document.lines);
</script>

<template>
    <Head :title="`Comprobante ${document.consecutive}`" />

    <AppLayout :title="`${catalogs.documentTypes[document.fiscal_document_type]} ${document.consecutive}`">
        <div class="view-toolbar">
            <Link :href="route('sales-documents.index')" class="btn btn-ghost"><ArrowLeftIcon /> Comprobantes</Link>
            <div class="view-actions">
                <a :href="route('sales-documents.xml', document.id)" class="btn btn-ghost"><DownloadIcon /> Descargar XML</a>
                <Link
                    v-if="canCredit"
                    :href="route('sales-documents.create', { correct: document.id })"
                    class="btn btn-primary"
                >
                    Copiar a <ArrowRightIcon /> Nota de crédito
                </Link>
            </div>
        </div>

        <div class="card summary">
            <div><span class="muted small">Clave</span><strong class="num clave">{{ document.clave }}</strong></div>
            <div><span class="muted small">Cliente</span><strong>{{ document.business_partner ? document.business_partner.name : 'Consumidor final' }}</strong></div>
            <div><span class="muted small">Condición</span><strong>{{ catalogs.saleConditions[document.sale_condition] }}</strong></div>
            <div v-if="document.due_date"><span class="muted small">Vence</span><strong>{{ document.due_date }}</strong></div>
            <div>
                <span class="muted small">Asiento</span>
                <Link v-if="document.journal_entry_id" :href="route('journal-entries.show', document.journal_entry_id)" class="link">
                    #{{ document.journal_entry?.document_number }}
                </Link>
                <strong v-else>—</strong>
            </div>
            <div>
                <span class="muted small">Salida de inventario</span>
                <Link v-if="document.inventory_document_id" :href="route('inventory-movements.show', document.inventory_document_id)" class="link">
                    Ver movimiento
                </Link>
                <strong v-else>sin stock</strong>
            </div>
        </div>

        <div class="card">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Descripción</th>
                            <th class="num">Cantidad</th>
                            <th class="num">Precio</th>
                            <th class="num">Impuesto</th>
                            <th class="num">Total línea</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="line in document.lines"
                            :key="line.id"
                            class="clickable-row"
                            tabindex="0"
                            @click="openLine(line)"
                            @keydown.enter="openLine(line)"
                            @keydown.space.prevent="openLine(line)"
                        >
                            <td>
                                <span class="muted line-no">{{ line.line_number }}.</span>
                                {{ line.description }}
                                <span v-if="line.is_service" class="badge badge-neutral">Servicio</span>
                            </td>
                            <td data-label="Cantidad" class="num">{{ quantity(line.quantity) }} {{ line.unit_code }}</td>
                            <td data-label="Precio" class="num">{{ money(line.unit_price) }}</td>
                            <td data-label="Impuesto" class="num">{{ money(line.tax_amount) }}</td>
                            <td data-label="Total línea" class="num">{{ money(line.line_total) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <DetailModal
            :open="!!selectedLine"
            :title="selectedLine ? `Línea ${selectedLine.line_number} — ${selectedLine.description}` : ''"
            @close="closeLine"
        >
            <dl v-if="selectedLine" class="detail-list">
                <div>
                    <dt>CAByS</dt>
                    <dd>{{ selectedLine.cabys_code }}</dd>
                </div>
                <div>
                    <dt>Bodega</dt>
                    <dd>{{ selectedLine.warehouse?.code ?? '—' }}</dd>
                </div>
                <div>
                    <dt>Cantidad</dt>
                    <dd>{{ quantity(selectedLine.quantity) }} {{ selectedLine.unit_code }}</dd>
                </div>
                <div>
                    <dt>Precio unitario</dt>
                    <dd>{{ money(selectedLine.unit_price) }}</dd>
                </div>
                <div>
                    <dt>Descuento</dt>
                    <dd>{{ money(selectedLine.discount_amount) }}</dd>
                </div>
                <div>
                    <dt>Subtotal</dt>
                    <dd>{{ money(selectedLine.subtotal) }}</dd>
                </div>
                <div>
                    <dt>Impuesto</dt>
                    <dd>
                        {{ money(selectedLine.tax_amount) }}
                        <span v-if="Number(selectedLine.exonerated_amount) > 0" class="muted">(exonerado {{ money(selectedLine.exonerated_amount) }})</span>
                    </dd>
                </div>
                <div>
                    <dt>Total línea</dt>
                    <dd><strong>{{ money(selectedLine.line_total) }}</strong></dd>
                </div>
                <div v-if="selectedLine.vin_or_serial">
                    <dt>VIN / Serie</dt>
                    <dd>{{ selectedLine.vin_or_serial }}</dd>
                </div>
            </dl>
        </DetailModal>

        <div class="bottom">
            <div class="card totals">
                <div><span>Total venta</span><strong class="num">{{ money(document.total_sale) }}</strong></div>
                <div><span>Descuentos</span><strong class="num">{{ money(document.total_discounts) }}</strong></div>
                <div><span>Venta neta</span><strong class="num">{{ money(document.total_net_sale) }}</strong></div>
                <div><span>Impuesto</span><strong class="num">{{ money(document.total_tax) }}</strong></div>
                <div class="grand"><span>TOTAL</span><strong class="num">{{ currency }} {{ money(document.total_document) }}</strong></div>
            </div>

            <div class="card payments">
                <h3>Medios de pago</h3>
                <div v-for="payment in document.payments" :key="payment.id" class="payment">
                    <span>{{ catalogs.paymentMethods[payment.method_code] }}</span>
                    <strong class="num">{{ money(payment.amount) }}</strong>
                </div>
                <p v-if="!document.payments.length" class="muted small">
                    Venta a crédito: se cobra vía la partida pendiente en Cuentas por Cobrar.
                </p>

                <template v-if="document.references.length">
                    <h3>Referencias</h3>
                    <div v-for="reference in document.references" :key="reference.id" class="muted small">
                        {{ reference.document_type }} · {{ reference.number }} — {{ reference.reason }}
                    </div>
                </template>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
.summary { display: flex; flex-wrap: wrap; gap: 0.75rem 1.75rem; padding: 1rem 1.25rem; margin-bottom: 0.75rem; }
.summary > div { display: flex; flex-direction: column; gap: 0.15rem; min-width: 0; }
.clave { font-size: 0.78rem; letter-spacing: 0.02em; overflow-wrap: anywhere; }

table { font-size: 0.85rem; }
.num { font-variant-numeric: tabular-nums; }
.line-no { font-variant-numeric: tabular-nums; margin-right: 0.2rem; }
.link { color: var(--color-primary); text-decoration: none; font-weight: 600; }
.link:hover { text-decoration: underline; }

.bottom { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 18rem), 1fr)); gap: 0.75rem; margin-top: 0.75rem; }
.totals, .payments { padding: 1rem 1.25rem; }
.totals > div { display: flex; justify-content: space-between; font-size: 0.85rem; padding: 0.2rem 0; }
.totals span { color: var(--color-text-muted); }
.totals .grand { border-top: 1px solid var(--color-border); margin-top: 0.4rem; padding-top: 0.6rem; }
.totals .grand strong { font-size: 1.15rem; }

.payments h3 { font-size: 0.82rem; margin: 0 0 0.5rem; color: var(--color-text-muted); }
.payment { display: flex; justify-content: space-between; font-size: 0.85rem; padding: 0.2rem 0; }
</style>
