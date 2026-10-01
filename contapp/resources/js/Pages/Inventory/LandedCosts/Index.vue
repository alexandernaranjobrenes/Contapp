<script setup>
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { CheckIcon, EyeIcon, PackagePlusIcon } from '@lucide/vue';
import AppLayout from '../../../Layouts/AppLayout.vue';
import DetailModal from '../../../Components/DetailModal.vue';

const props = defineProps({
    receipts: { type: Array, default: () => [] },
    documents: { type: Array, default: () => [] },
    documentTypes: { type: Array, default: () => [] },
    suppliers: { type: Array, default: () => [] },
});

const page = usePage();
const today = new Date().toISOString().slice(0, 10);

// Ficha de la recepción (CLAUDE.md secc. 20): «Aplicar costo» pasa el mismo
// modal al formulario del costo.
const selectedReceiptId = ref(null);
const receiptMode = ref('details'); // 'details' | 'apply'
const selectedReceipt = computed(() => props.receipts.find((r) => r.id === selectedReceiptId.value) ?? null);

function openReceipt(receipt) {
    selectedReceiptId.value = receipt.id;
    receiptMode.value = 'details';
}

function closeReceipt() {
    selectedReceiptId.value = null;
    receiptMode.value = 'details';
}

const form = useForm({
    inventory_document_id: null,
    document_type_id: props.documentTypes[0]?.id ?? '',
    business_partner_id: '',
    amount: '',
    document_date: today,
    posting_date: today,
    due_date: '',
    description: '',
});

function money(value) {
    return Number(value ?? 0).toLocaleString('es-CR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function startApply() {
    form.clearErrors();
    form.inventory_document_id = selectedReceipt.value.id;
    form.business_partner_id = '';
    form.amount = '';
    form.document_date = today;
    form.posting_date = today;
    form.due_date = '';
    form.description = '';
    receiptMode.value = 'apply';
}

function submit() {
    form
        .transform((data) => ({
            ...data,
            due_date: data.due_date === '' ? null : data.due_date,
            description: data.description === '' ? null : data.description,
        }))
        .post(route('landed-costs.store'), { onSuccess: closeReceipt });
}

// Ficha de un costo ya aplicado.
const selectedDocumentId = ref(null);
const selectedDocument = computed(() => props.documents.find((d) => d.id === selectedDocumentId.value) ?? null);
</script>

<template>
    <Head title="Costos de importación" />

    <AppLayout title="Costos de importación">
        <div v-if="page.props.errors?.landed_cost" class="flash flash-error">{{ page.props.errors.landed_cost }}</div>

        <p class="hint">
            Flete, aranceles, seguro y agencia aduanal se capitalizan al costo del artículo — pero solo en la proporción
            que <strong>sigue en existencia</strong>. Si la mercancía ya se vendió, esa parte no tiene activo que la
            respalde y va a la cuenta de diferencia de precio. El reparto entre artículos es proporcional al valor de
            cada línea de la recepción.
        </p>

        <h2 class="block-title">Recepciones sobre las que aplicar un costo</h2>

        <div class="card">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Proveedor</th>
                            <th class="num">Líneas</th>
                            <th class="num">Valor recibido</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="r in receipts"
                            :key="r.id"
                            class="clickable-row"
                            tabindex="0"
                            @click="openReceipt(r)"
                            @keydown.enter="openReceipt(r)"
                            @keydown.space.prevent="openReceipt(r)"
                        >
                            <td class="code-cell">{{ r.posting_date }}</td>
                            <td data-label="Proveedor">{{ r.supplier ?? '—' }}</td>
                            <td data-label="Líneas" class="num">{{ r.lines_count }}</td>
                            <td data-label="Valor recibido" class="num">{{ money(r.total_local) }}</td>
                        </tr>
                        <tr v-if="!receipts.length">
                            <td colspan="4" class="muted empty-row">Todavía no hay entradas de mercancía registradas.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <h2 class="block-title">Costos aplicados</h2>

        <div class="card">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Proveedor</th>
                            <th class="num">Total</th>
                            <th class="num">Capitalizado</th>
                            <th class="num">A resultados</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="d in documents"
                            :key="d.id"
                            class="clickable-row"
                            tabindex="0"
                            @click="selectedDocumentId = d.id"
                            @keydown.enter="selectedDocumentId = d.id"
                            @keydown.space.prevent="selectedDocumentId = d.id"
                        >
                            <td class="code-cell">{{ d.posting_date }}</td>
                            <td data-label="Proveedor">{{ d.supplier }}</td>
                            <td data-label="Total" class="num">{{ money(d.amount) }}</td>
                            <td data-label="Capitalizado" class="num">{{ money(d.capitalized_amount) }}</td>
                            <td data-label="A resultados" class="num" :class="Number(d.expensed_amount) > 0 ? 'warn' : ''">
                                {{ money(d.expensed_amount) }}
                            </td>
                        </tr>
                        <tr v-if="!documents.length">
                            <td colspan="5" class="muted empty-row">Todavía no se ha aplicado ningún costo de importación.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <DetailModal
            :open="!!selectedReceipt"
            :title="selectedReceipt ? (receiptMode === 'apply' ? 'Aplicar costo de importación' : `Recepción del ${selectedReceipt.posting_date}`) : ''"
            @close="closeReceipt"
        >
            <dl v-if="selectedReceipt && receiptMode === 'details'" class="detail-list">
                <div class="full">
                    <dt>Proveedor</dt>
                    <dd>{{ selectedReceipt.supplier ?? '—' }}</dd>
                </div>
                <div>
                    <dt>Tipo de documento</dt>
                    <dd>{{ selectedReceipt.document_type_code }}</dd>
                </div>
                <div>
                    <dt>Líneas</dt>
                    <dd>{{ selectedReceipt.lines_count }}</dd>
                </div>
                <div>
                    <dt>Valor recibido</dt>
                    <dd>{{ money(selectedReceipt.total_local) }}</dd>
                </div>
            </dl>

            <form v-if="selectedReceipt && receiptMode === 'apply'" id="landed-cost-form" @submit.prevent="submit">
                <p class="muted small">
                    Sobre la recepción del {{ selectedReceipt.posting_date }} por {{ money(selectedReceipt.total_local) }}.
                </p>

                <div class="field-row">
                    <div class="field">
                        <label for="lc-doc-type">Tipo de documento</label>
                        <select id="lc-doc-type" v-model="form.document_type_id" required>
                            <option v-for="t in documentTypes" :key="t.id" :value="t.id">{{ t.code }} — {{ t.name }}</option>
                        </select>
                        <span v-if="form.errors.document_type_id" class="error">{{ form.errors.document_type_id }}</span>
                    </div>
                    <div class="field">
                        <label for="lc-supplier">Quién lo cobra</label>
                        <select id="lc-supplier" v-model="form.business_partner_id" required>
                            <option value="" disabled>— Elegir —</option>
                            <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.code }} — {{ s.name }}</option>
                        </select>
                        <span v-if="form.errors.business_partner_id" class="error">{{ form.errors.business_partner_id }}</span>
                    </div>
                </div>

                <div class="field-row">
                    <div class="field">
                        <label for="lc-amount">Monto del costo</label>
                        <input id="lc-amount" v-model="form.amount" type="number" step="0.01" min="0.01" required>
                        <span v-if="form.errors.amount" class="error">{{ form.errors.amount }}</span>
                    </div>
                    <div class="field">
                        <label for="lc-due">Vencimiento (opcional)</label>
                        <input id="lc-due" v-model="form.due_date" type="date">
                    </div>
                </div>

                <div class="field-row">
                    <div class="field">
                        <label for="lc-doc-date">Fecha del documento</label>
                        <input id="lc-doc-date" v-model="form.document_date" type="date" required>
                    </div>
                    <div class="field">
                        <label for="lc-posting">Fecha de contabilización</label>
                        <input id="lc-posting" v-model="form.posting_date" type="date" required>
                        <span v-if="form.errors.posting_date" class="error">{{ form.errors.posting_date }}</span>
                    </div>
                </div>

                <div class="field">
                    <label for="lc-description">Concepto (ej. flete marítimo, arancel)</label>
                    <input id="lc-description" v-model="form.description" type="text" maxlength="255">
                </div>
            </form>

            <template #actions>
                <template v-if="selectedReceipt && receiptMode === 'details'">
                    <button
                        type="button" class="btn btn-primary"
                        :disabled="!documentTypes.length || !suppliers.length"
                        @click="startApply"
                    ><PackagePlusIcon /> Aplicar costo</button>
                </template>
                <template v-else-if="selectedReceipt">
                    <button type="button" class="btn btn-ghost" @click="receiptMode = 'details'">Cancelar</button>
                    <button type="submit" form="landed-cost-form" class="btn btn-primary" :disabled="form.processing"><CheckIcon /> Aplicar</button>
                </template>
            </template>
        </DetailModal>

        <DetailModal
            :open="!!selectedDocument"
            :title="selectedDocument ? `Costo del ${selectedDocument.posting_date}` : ''"
            @close="selectedDocumentId = null"
        >
            <dl v-if="selectedDocument" class="detail-list">
                <div class="full">
                    <dt>Proveedor</dt>
                    <dd>{{ selectedDocument.supplier }}</dd>
                </div>
                <div class="full">
                    <dt>Concepto</dt>
                    <dd>{{ selectedDocument.description ?? '—' }}</dd>
                </div>
                <div>
                    <dt>Total</dt>
                    <dd>{{ money(selectedDocument.amount) }}</dd>
                </div>
                <div>
                    <dt>Capitalizado</dt>
                    <dd>{{ money(selectedDocument.capitalized_amount) }}</dd>
                </div>
                <div>
                    <dt>A resultados</dt>
                    <dd :class="Number(selectedDocument.expensed_amount) > 0 ? 'warn' : ''">{{ money(selectedDocument.expensed_amount) }}</dd>
                </div>
                <div>
                    <dt>Asiento</dt>
                    <dd>#{{ selectedDocument.journal_document_number }}</dd>
                </div>
            </dl>

            <template #actions>
                <Link v-if="selectedDocument" :href="route('inventory-movements.show', selectedDocument.receipt_id)" class="btn btn-primary"><EyeIcon /> Ver entrada</Link>
            </template>
        </DetailModal>
    </AppLayout>
</template>

<style scoped>
table { font-size: 0.85rem; }
.code-cell { font-variant-numeric: tabular-nums; white-space: nowrap; }
.warn { color: var(--color-warning); font-weight: 600; }
</style>
