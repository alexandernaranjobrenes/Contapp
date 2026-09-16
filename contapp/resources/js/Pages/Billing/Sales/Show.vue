<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';

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
</script>

<template>
    <Head :title="`Comprobante ${document.consecutive}`" />

    <AppLayout :title="`${catalogs.documentTypes[document.fiscal_document_type]} ${document.consecutive}`">
        <template #actions>
            <a :href="route('sales-documents.xml', document.id)" class="btn btn-ghost">Descargar XML</a>
        </template>

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
            <div class="table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>CAByS</th>
                            <th>Descripción</th>
                            <th>Bodega</th>
                            <th class="right">Cant.</th>
                            <th>U/M</th>
                            <th class="right">Precio</th>
                            <th class="right">Descuento</th>
                            <th class="right">Subtotal</th>
                            <th class="right">Impuesto</th>
                            <th class="right">Total línea</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="line in document.lines" :key="line.id">
                            <td class="num">{{ line.line_number }}</td>
                            <td class="num code-cell">{{ line.cabys_code }}</td>
                            <td>
                                {{ line.description }}
                                <span v-if="line.is_service" class="badge badge-neutral">Servicio</span>
                                <div v-if="line.vin_or_serial" class="muted small">VIN/Serie: {{ line.vin_or_serial }}</div>
                            </td>
                            <td class="code-cell">{{ line.warehouse?.code ?? '—' }}</td>
                            <td class="num right">{{ quantity(line.quantity) }}</td>
                            <td>{{ line.unit_code }}</td>
                            <td class="num right">{{ money(line.unit_price) }}</td>
                            <td class="num right">{{ money(line.discount_amount) }}</td>
                            <td class="num right">{{ money(line.subtotal) }}</td>
                            <td class="num right">
                                {{ money(line.tax_amount) }}
                                <div v-if="Number(line.exonerated_amount) > 0" class="muted small">
                                    exonerado {{ money(line.exonerated_amount) }}
                                </div>
                            </td>
                            <td class="num right">{{ money(line.line_total) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

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
.summary { display: flex; flex-wrap: wrap; gap: 1.75rem; padding: 1rem 1.25rem; margin-bottom: 0.75rem; }
.summary > div { display: flex; flex-direction: column; gap: 0.15rem; }
.clave { font-size: 0.78rem; letter-spacing: 0.02em; }

.table-scroll { overflow-x: auto; }
table { font-size: 0.85rem; width: 100%; }
th, td { text-align: left; padding: 0.5rem 0.9rem; border-top: 1px solid var(--color-border); white-space: nowrap; }
.right { text-align: right; }
.code-cell, .num { font-variant-numeric: tabular-nums; }
.muted { color: var(--color-text-muted); }
.small { font-size: 0.76rem; }
.link { color: var(--color-primary); text-decoration: none; font-weight: 600; }
.link:hover { text-decoration: underline; }

.bottom { display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; margin-top: 0.75rem; }
.totals, .payments { padding: 1rem 1.25rem; }
.totals > div { display: flex; justify-content: space-between; font-size: 0.85rem; padding: 0.2rem 0; }
.totals span { color: var(--color-text-muted); }
.totals .grand { border-top: 1px solid var(--color-border); margin-top: 0.4rem; padding-top: 0.6rem; }
.totals .grand strong { font-size: 1.15rem; }

.payments h3 { font-size: 0.82rem; margin: 0 0 0.5rem; color: var(--color-text-muted); }
.payment { display: flex; justify-content: space-between; font-size: 0.85rem; padding: 0.2rem 0; }
</style>
