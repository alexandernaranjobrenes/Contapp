<script setup>
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '../../../Layouts/AppLayout.vue';
import DetailModal from '../../../Components/DetailModal.vue';
import { useRecordDetail } from '../../../Utils/recordDetail';
import { confirmAction } from '../../../Utils/confirm';
import { electronicBadge } from '../../../Utils/electronicStatus';
import {
    ArrowLeftIcon, ArrowRightIcon, DownloadIcon, FileTextIcon, MailIcon, RefreshCwIcon, RotateCcwIcon, SendIcon,
} from '@lucide/vue';

const props = defineProps({
    document: { type: Object, required: true },
    catalogs: { type: Object, required: true },
    // El comprobante ante Hacienda (SalesDocumentController::electronicSummary);
    // null si se emitió con la facturación electrónica apagada.
    electronic: { type: Object, default: null },
    issuedAt: { type: String, default: null },
});

const page = usePage();
// Reintentar, volver a emitir y reenviar al cliente son de quien puede emitir
// (la pantalla «Nueva factura»); consultar el estado, de quien ve el comprobante.
const canWrite = computed(() => (page.props.screenAccess?.['billing.new_invoice'] ?? 'none') === 'read_write');

function money(value) {
    return Number(value ?? 0).toLocaleString('es-CR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function quantity(value) {
    return Number(value ?? 0).toLocaleString('es-CR', { minimumFractionDigits: 0, maximumFractionDigits: 3 });
}

const currency = props.document.currency?.code ?? '';

// Una nota de crédito no se corrige con otra nota: lo que corresponde ahí es
// una nota de débito, que es otro documento.
// Si es electrónico, además tiene que estar aceptado: para Hacienda uno
// rechazado o en proceso no existe, y la nota sobre él se rechazaría.
const canCredit = props.document.fiscal_document_type !== '03' && props.document.status === 'posted'
    && (! props.electronic || props.electronic.status === 'accepted');

// --- Hacienda ---

function check() {
    router.post(route('sales-documents.hacienda.check', props.document.id), {}, { preserveScroll: true });
}

function retry() {
    router.post(route('sales-documents.hacienda.retry', props.document.id), {}, { preserveScroll: true });
}

function reissue() {
    confirmAction({
        title: 'Emitir de nuevo con clave nueva',
        message: 'Se arma el comprobante otra vez con los datos actuales del emisor y del cliente, con un consecutivo, una clave '
            + 'y una fecha de emisión nuevos, y se envía a Hacienda. Las líneas y los montos son los mismos de la venta. '
            + 'Corregí antes lo que causó el rechazo.',
        confirmLabel: 'Emitir de nuevo',
        onConfirm: () => router.post(route('sales-documents.hacienda.reissue', props.document.id), {}, { preserveScroll: true }),
    });
}

// Reenviar al cliente, o a otros correos.
const delivering = ref(false);
const deliveryForm = useForm({ emails: [] });
const deliveryEmails = ref('');

function openDelivery() {
    deliveryEmails.value = props.document.business_partner?.email ?? '';
    deliveryForm.clearErrors();
    delivering.value = true;
}

function deliver() {
    deliveryForm
        .transform(() => ({ emails: deliveryEmails.value.split(/[,;\s]+/).map((e) => e.trim()).filter(Boolean) }))
        .post(route('sales-documents.hacienda.deliver', props.document.id), {
            preserveScroll: true,
            onSuccess: () => { delivering.value = false; },
        });
}

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
                <a :href="route('sales-documents.xml', document.id)" class="btn btn-ghost"><DownloadIcon /> {{ electronic ? 'XML firmado' : 'Descargar XML' }}</a>
                <a v-if="electronic?.has_response" :href="route('sales-documents.hacienda.response', document.id)" class="btn btn-ghost"><DownloadIcon /> Respuesta de Hacienda</a>
                <a :href="route('sales-documents.pdf', document.id)" class="btn btn-ghost" data-download><FileTextIcon /> PDF</a>
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
            <div v-if="issuedAt"><span class="muted small">Emitido</span><strong>{{ issuedAt }}</strong></div>
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

        <!-- Hacienda (CLAUDE.md secc. 38) -->
        <section v-if="electronic" class="card hacienda" :class="electronic.status">
            <div class="hacienda-head">
                <div>
                    <h2>Hacienda</h2>
                    <p class="muted small">
                        {{ electronic.environment_label }} · Consecutivo {{ electronic.consecutive }}
                        <template v-if="electronic.submitted_at"> · Enviado {{ electronic.submitted_at }}</template>
                        <template v-if="electronic.accepted_at"> · Aceptado {{ electronic.accepted_at }}</template>
                        <template v-if="electronic.rejected_at"> · Rechazado {{ electronic.rejected_at }}</template>
                    </p>
                </div>
                <span class="badge" :class="electronicBadge(electronic.status)">{{ electronic.label }}</span>
            </div>

            <p v-if="electronic.environment === 'stag'" class="flash flash-warning">
                Comprobante de pruebas: se envió al sandbox de Hacienda y no tiene validez fiscal.
            </p>

            <p v-if="electronic.hacienda_message" class="hacienda-message">{{ electronic.hacienda_message }}</p>

            <p v-if="electronic.status === 'rejected' || electronic.status === 'submit_failed'" class="hint">
                Una clave que Hacienda ya procesó no se puede reenviar. Corregí lo que indica el mensaje (los datos del cliente,
                los del emisor) y emitilo de nuevo: va con consecutivo y clave nuevos. Si lo que está mal son los montos, anulá
                la venta con una nota de crédito y emití otra factura.
            </p>
            <p v-else-if="electronic.next_attempt_at && electronic.status !== 'accepted'" class="hint">
                La próxima consulta automática es alrededor de las {{ electronic.next_attempt_at }}.
            </p>

            <p v-if="electronic.status === 'accepted'" class="hint">
                <template v-if="electronic.delivery_status === 'sent'">
                    Enviado al cliente el {{ electronic.delivered_at }} ({{ (electronic.delivery_recipients ?? []).join(', ') }}).
                </template>
                <template v-else-if="electronic.delivery_error">{{ electronic.delivery_error }}</template>
                <template v-else-if="electronic.delivery_status === 'pending'">El envío al cliente está en camino.</template>
            </p>

            <div class="hacienda-actions">
                <button v-if="electronic.can_check" type="button" class="btn btn-ghost" @click="check">
                    <RefreshCwIcon /> Consultar estado
                </button>
                <button v-if="canWrite && electronic.can_retry" type="button" class="btn btn-ghost" @click="retry">
                    <SendIcon /> Reintentar envío
                </button>
                <button v-if="canWrite && electronic.can_deliver" type="button" class="btn btn-ghost" @click="openDelivery">
                    <MailIcon /> Enviar al cliente
                </button>
                <button v-if="canWrite && electronic.can_reissue" type="button" class="btn btn-primary" @click="reissue">
                    <RotateCcwIcon /> Emitir de nuevo
                </button>
            </div>

            <details class="history">
                <summary>Historia ante Hacienda ({{ electronic.events.length }})</summary>
                <ol>
                    <li v-for="event in electronic.events" :key="event.id">
                        <span class="muted small">{{ event.at }} · {{ event.by }}</span>
                        <strong>{{ event.to }}</strong>
                        <span v-if="event.message" class="small">{{ event.message }}</span>
                    </li>
                </ol>
                <template v-if="electronic.previous.length">
                    <h3>Intentos anteriores</h3>
                    <div v-for="attempt in electronic.previous" :key="attempt.id" class="small previous">
                        {{ attempt.consecutive }} · {{ attempt.label }} · {{ attempt.issued_at }}
                        <span v-if="attempt.hacienda_message" class="muted">— {{ attempt.hacienda_message }}</span>
                        <div class="muted clave">{{ attempt.clave }}</div>
                    </div>
                </template>
            </details>
        </section>

        <DetailModal :open="delivering" title="Enviar el comprobante" @close="delivering = false">
            <form id="delivery-form" @submit.prevent="deliver">
                <div class="field">
                    <label for="delivery-emails">Correos</label>
                    <input id="delivery-emails" v-model="deliveryEmails" type="text" placeholder="cliente@empresa.com, otra@empresa.com">
                    <span class="hint">Separados por coma, hasta 4. Va el XML firmado, la respuesta de Hacienda y el PDF.</span>
                    <span v-if="deliveryForm.errors.emails" class="error">{{ deliveryForm.errors.emails }}</span>
                    <span v-for="(message, key) in deliveryForm.errors" v-show="key.startsWith('emails.')" :key="key" class="error">{{ message }}</span>
                </div>
            </form>
            <template #actions>
                <button type="button" class="btn btn-ghost" @click="delivering = false">Cancelar</button>
                <button type="submit" form="delivery-form" class="btn btn-primary" :disabled="deliveryForm.processing"><MailIcon /> Enviar</button>
            </template>
        </DetailModal>

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

.hacienda { padding: 1rem 1.25rem; margin-bottom: 0.75rem; border-left: 3px solid var(--color-border); }
.hacienda.accepted { border-left-color: var(--color-success); }
.hacienda.rejected, .hacienda.submit_failed { border-left-color: var(--color-danger); }
.hacienda.needs_attention, .hacienda.submit_unknown { border-left-color: var(--color-warning); }
.hacienda h2 { font-size: 1rem; margin: 0; }
.hacienda h3 { font-size: 0.8rem; margin: 0.6rem 0 0.3rem; color: var(--color-text-muted); }
.hacienda .flash { margin: 0.6rem 0; }
.hacienda-head { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: flex-start; gap: 0.5rem; }
.hacienda-message { margin: 0.6rem 0; font-size: 0.85rem; white-space: pre-line; overflow-wrap: anywhere; }
.hacienda-actions { display: flex; flex-wrap: wrap; gap: 0.4rem; margin-top: 0.6rem; }
.hint { font-size: 0.78rem; color: var(--color-text-muted); margin: 0.3rem 0; }
.history { margin-top: 0.75rem; font-size: 0.82rem; }
.history summary { cursor: pointer; color: var(--color-text-muted); }
.history ol { list-style: none; margin: 0.5rem 0 0; padding: 0; display: grid; gap: 0.45rem; }
.history li { display: flex; flex-direction: column; gap: 0.1rem; }
.previous { margin-bottom: 0.4rem; }

@media (max-width: 640px) {
    .hacienda-actions .btn { flex: 1 1 calc(50% - 0.4rem); justify-content: center; }
}
.payment { display: flex; justify-content: space-between; font-size: 0.85rem; padding: 0.2rem 0; }
</style>
