<script setup>
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { CheckIcon, PlusIcon } from '@lucide/vue';
import AppLayout from '../../../Layouts/AppLayout.vue';
import DetailModal from '../../../Components/DetailModal.vue';
import { confirmAction } from '../../../Utils/confirm';

const props = defineProps({
    documents: { type: Array, default: () => [] },
    concepts: { type: Object, default: () => ({}) },
    statuses: { type: Object, default: () => ({}) },
    suppliers: { type: Array, default: () => [] },
    documentTypes: { type: Array, default: () => [] },
});

const page = usePage();
const today = new Date().toISOString().slice(0, 10);

const filter = ref('');
const adding = ref(false);

const form = useForm({
    document_type_id: props.documentTypes[0]?.id ?? '',
    business_partner_id: props.suppliers[0]?.id ?? '',
    concept: 'flete',
    amount: '',
    document_date: today,
    posting_date: today,
    due_date: '',
    description: '',
});

const visible = computed(() =>
    filter.value ? props.documents.filter((d) => d.status === filter.value) : props.documents
);

// El saldo vivo de la transitoria: lo que hay acumulado sin cargar a ninguna
// importación. Es la cifra que debería cuadrar contra el balance.
const totalPending = computed(() =>
    props.documents.reduce((sum, d) => sum + (d.status === 'cancelled' ? 0 : d.pending_amount), 0)
);

function money(value) {
    return Number(value ?? 0).toLocaleString('es-CR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function openCreate() {
    form.clearErrors();
    form.reset('amount', 'description', 'due_date');
    adding.value = true;
}

function submit() {
    form
        .transform((data) => ({
            ...data,
            due_date: data.due_date === '' ? null : data.due_date,
            description: data.description === '' ? null : data.description,
        }))
        .post(route('import-costs.store'), { onSuccess: () => { adding.value = false; form.reset('amount', 'description'); } });
}

const badgeClass = {
    pending: 'badge-warning',
    partial: 'badge-warning',
    allocated: 'badge-success',
    cancelled: 'badge-neutral',
};

// Ficha del rubro (CLAUDE.md secc. 20): a qué importaciones se cargó y, si
// sigue pendiente, cancelarlo.
const selectedId = ref(null);
const selected = computed(() => props.documents.find((d) => d.id === selectedId.value) ?? null);

function cancelDocument() {
    const document = selected.value;

    confirmAction({
        title: `Cancelar el rubro ${document.number}`,
        message: 'Se revierte su registro contra la transitoria y deja de estar disponible para el costeo.',
        confirmLabel: 'Cancelar rubro',
        danger: true,
        onConfirm: () => router.post(route('import-costs.cancel', document.id), {}, { preserveScroll: true }),
    });
}
</script>

<template>
    <Head title="Costos de importación" />

    <AppLayout title="Rubros de nacionalización">
        <div class="view-toolbar">
            <div class="view-actions">
                <Link :href="route('import-costs.allocation')" class="btn btn-ghost">Proceso de costeo</Link>
                <button type="button" class="btn btn-primary" @click="openCreate"><PlusIcon /> Crear nuevo</button>
            </div>
            <div class="view-filters">
                <select v-model="filter" aria-label="Estado">
                    <option value="">Todos los estados</option>
                    <option v-for="(label, key) in statuses" :key="key" :value="key">{{ label }}</option>
                </select>
            </div>
        </div>

        <div v-if="page.props.errors?.import_cost" class="flash flash-error">{{ page.props.errors.import_cost }}</div>

        <p class="hint">
            Cada rubro es la factura de un proveedor de nacionalización —naviera, agencia aduanal, almacén fiscal—
            contabilizada contra la <strong>cuenta transitoria de costos por asignar</strong>. La deuda con ese
            proveedor es real desde ya; el costo entra al artículo después, en el proceso de costeo.
        </p>

        <div class="card totals">
            <div>
                <span class="muted small">Pendiente de asignar (saldo de la transitoria)</span>
                <strong class="num-value total">{{ money(totalPending) }}</strong>
            </div>
            <div><span class="muted small">Rubros</span><strong class="num-value">{{ documents.length }}</strong></div>
        </div>

        <div class="card">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Número</th>
                            <th>Proveedor</th>
                            <th>Rubro</th>
                            <th class="num">Monto</th>
                            <th class="num">Por asignar</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="d in visible"
                            :key="d.id"
                            class="clickable-row"
                            tabindex="0"
                            @click="selectedId = d.id"
                            @keydown.enter="selectedId = d.id"
                            @keydown.space.prevent="selectedId = d.id"
                        >
                            <td class="code-cell">{{ d.number }}</td>
                            <td data-label="Proveedor">{{ d.supplier }}</td>
                            <td data-label="Rubro">{{ d.concept_label }}</td>
                            <td data-label="Monto" class="num">{{ money(d.amount) }}</td>
                            <td data-label="Por asignar" class="num"><strong>{{ money(d.pending_amount) }}</strong></td>
                            <td data-label="Estado"><span class="badge" :class="badgeClass[d.status]">{{ d.status_label }}</span></td>
                        </tr>
                        <tr v-if="!visible.length">
                            <td colspan="6" class="muted empty-row">No hay rubros con ese estado.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <DetailModal :open="!!selected" :title="selected ? `Rubro ${selected.number}` : ''" @close="selectedId = null">
            <template #badge>
                <span v-if="selected" class="badge" :class="badgeClass[selected.status]">{{ selected.status_label }}</span>
            </template>

            <template v-if="selected">
                <dl class="detail-list">
                    <div class="full">
                        <dt>Proveedor</dt>
                        <dd>{{ selected.supplier }}</dd>
                    </div>
                    <div>
                        <dt>Rubro</dt>
                        <dd>{{ selected.concept_label }}</dd>
                    </div>
                    <div>
                        <dt>Fecha</dt>
                        <dd>{{ selected.posting_date }}</dd>
                    </div>
                    <div>
                        <dt>Monto</dt>
                        <dd>{{ money(selected.amount) }}</dd>
                    </div>
                    <div>
                        <dt>Asignado</dt>
                        <dd>{{ money(selected.allocated_amount) }}</dd>
                    </div>
                    <div>
                        <dt>Por asignar</dt>
                        <dd>{{ money(selected.pending_amount) }}</dd>
                    </div>
                </dl>

                <template v-if="selected.allocations.length">
                    <h3 class="section-title">Cargado a</h3>
                    <ul class="alloc-list">
                        <li v-for="(a, i) in selected.allocations" :key="i">
                            <Link :href="route('inventory-movements.show', a.receipt_id)" class="link">Importación #{{ a.receipt_id }}</Link>
                            <span class="muted">{{ money(a.amount) }} el {{ a.posting_date }}</span>
                        </li>
                    </ul>
                </template>
            </template>

            <template #actions>
                <button
                    v-if="selected?.status === 'pending'"
                    type="button" class="btn btn-ghost btn-danger-text"
                    @click="cancelDocument"
                >Cancelar rubro</button>
            </template>
        </DetailModal>

        <DetailModal :open="adding" title="Registrar rubro de nacionalización" @close="adding = false">
            <form id="import-cost-form" @submit.prevent="submit">
                <p class="muted small">
                    Se contabiliza contra la transitoria y abre la partida por pagar del proveedor.
                </p>

                <div class="field-row">
                    <div class="field">
                        <label for="ic-supplier">Proveedor del servicio</label>
                        <select id="ic-supplier" v-model="form.business_partner_id" required>
                            <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.code }} — {{ s.name }}</option>
                        </select>
                        <span v-if="form.errors.business_partner_id" class="error">{{ form.errors.business_partner_id }}</span>
                    </div>
                    <div class="field">
                        <label for="ic-concept">Rubro</label>
                        <select id="ic-concept" v-model="form.concept" required>
                            <option v-for="(label, key) in concepts" :key="key" :value="key">{{ label }}</option>
                        </select>
                    </div>
                </div>

                <div class="field-row">
                    <div class="field">
                        <label for="ic-doc-type">Tipo de documento</label>
                        <select id="ic-doc-type" v-model="form.document_type_id" required>
                            <option v-for="t in documentTypes" :key="t.id" :value="t.id">{{ t.code }} — {{ t.name }}</option>
                        </select>
                        <span v-if="form.errors.document_type_id" class="error">{{ form.errors.document_type_id }}</span>
                    </div>
                    <div class="field">
                        <label for="ic-amount">Monto</label>
                        <input id="ic-amount" v-model="form.amount" type="number" step="0.01" min="0.01" required>
                        <span v-if="form.errors.amount" class="error">{{ form.errors.amount }}</span>
                    </div>
                </div>

                <div class="field-row">
                    <div class="field">
                        <label for="ic-doc-date">Fecha del documento</label>
                        <input id="ic-doc-date" v-model="form.document_date" type="date" required>
                    </div>
                    <div class="field">
                        <label for="ic-due">Vencimiento (opcional)</label>
                        <input id="ic-due" v-model="form.due_date" type="date">
                    </div>
                </div>

                <div class="field">
                    <label for="ic-description">Descripción (opcional)</label>
                    <input id="ic-description" v-model="form.description" type="text" maxlength="255">
                </div>
            </form>

            <template #actions>
                <button type="button" class="btn btn-ghost" @click="adding = false">Cancelar</button>
                <button type="submit" form="import-cost-form" class="btn btn-primary" :disabled="form.processing"><CheckIcon /> Registrar rubro</button>
            </template>
        </DetailModal>
    </AppLayout>
</template>

<style scoped>
.totals { display: flex; flex-wrap: wrap; gap: 0.75rem 2rem; padding: 0.9rem 1.25rem; margin-bottom: 0.9rem; }
.totals > div { display: flex; flex-direction: column; gap: 0.15rem; }
.total { font-size: 1.1rem; }
.num-value { font-variant-numeric: tabular-nums; }

table { font-size: 0.85rem; }
.code-cell { font-variant-numeric: tabular-nums; white-space: nowrap; }

.alloc-list { margin: 0; padding: 0; list-style: none; }
.alloc-list li { display: flex; justify-content: space-between; flex-wrap: wrap; gap: 0.25rem 1rem; padding: 0.4rem 0; border-top: 1px solid var(--color-border); font-size: 0.85rem; }
.link { color: var(--color-primary); text-decoration: none; font-weight: 600; }
.link:hover { text-decoration: underline; }
</style>
