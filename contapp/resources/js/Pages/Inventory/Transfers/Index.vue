<script setup>
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import { ArrowRightLeftIcon, PlusIcon, XIcon } from '@lucide/vue';
import AppLayout from '../../../Layouts/AppLayout.vue';
import DetailModal from '../../../Components/DetailModal.vue';
import { useRecordDetail } from '../../../Utils/recordDetail';

const props = defineProps({
    transfers: { type: Array, default: () => [] },
    items: { type: Array, default: () => [] },
    warehouses: { type: Array, default: () => [] },
    bins: { type: Array, default: () => [] },
    documentTypes: { type: Array, default: () => [] },
});

const page = usePage();
const today = new Date().toISOString().slice(0, 10);

const creating = ref(false);

const form = useForm({
    document_type_id: props.documentTypes[0]?.id ?? '',
    posting_date: today,
    description: '',
    lines: [blankLine()],
});

function blankLine() {
    return {
        item_id: '', from_warehouse_id: '', from_warehouse_bin_id: '',
        to_warehouse_id: '', to_warehouse_bin_id: '', quantity: '', description: '',
    };
}

function quantity(value) {
    return Number(value ?? 0).toLocaleString('es-CR', { minimumFractionDigits: 0, maximumFractionDigits: 6 });
}

function usesBins(warehouseId) {
    return props.warehouses.find((w) => w.id === warehouseId)?.uses_bins ?? false;
}

function binsOf(warehouseId) {
    return props.bins.filter((b) => b.warehouse_id === warehouseId);
}

const anyUsesBins = computed(() => props.warehouses.some((w) => w.uses_bins));

const ready = computed(() => props.documentTypes.length && props.items.length && props.warehouses.length >= 1);

function openCreate() {
    form.reset();
    form.clearErrors();
    form.posting_date = today;
    form.lines = [blankLine()];
    creating.value = true;
}

function submit() {
    form
        .transform((data) => ({
            ...data,
            description: data.description === '' ? null : data.description,
            lines: data.lines.map((line) => ({
                ...line,
                from_warehouse_bin_id: line.from_warehouse_bin_id === '' ? null : line.from_warehouse_bin_id,
                to_warehouse_bin_id: line.to_warehouse_bin_id === '' ? null : line.to_warehouse_bin_id,
                description: line.description === '' ? null : line.description,
            })),
        }))
        .post(route('stock-transfers.store'), { onSuccess: () => (creating.value = false) });
}

// La ruta del traslado en una frase: de qué almacenes a cuáles.
function route_(transfer) {
    const from = [...new Set(transfer.lines.map((l) => l.warehouse?.code).filter(Boolean))].join(', ');
    const to = [...new Set(transfer.lines.map((l) => l.to_warehouse?.code).filter(Boolean))].join(', ');
    return `${from || '—'} → ${to || '—'}`;
}

// Ficha del traslado (CLAUDE.md secc. 20): sus líneas y su asiento.
const { selected, openDetail, closeDetail } = useRecordDetail(() => props.transfers);
</script>

<template>
    <Head title="Traslados entre almacenes" />

    <AppLayout title="Traslados entre almacenes">
        <div class="view-toolbar">
            <div class="view-actions">
                <button type="button" class="btn btn-primary" :disabled="!ready" @click="openCreate()"><PlusIcon /> Crear nuevo</button>
            </div>
        </div>

        <div v-if="page.props.errors?.transfer" class="flash flash-error">{{ page.props.errors.transfer }}</div>

        <p v-if="!ready" class="flash flash-warning">
            Hacen falta un tipo de documento del módulo "Inventario", al menos un artículo de inventario y un almacén.
        </p>

        <p class="hint">
            Un traslado mueve <strong>dónde está</strong> la mercancía, no cuánto vale: el costo promedio es global por
            artículo y no cambia. Por eso solo genera asiento cuando origen y destino tienen cuentas de inventario
            distintas —ahí sí reclasifica valor entre ellas—; si comparten cuenta, el asiento no cambiaría nada y no se
            emite.
        </p>

        <div class="card">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Documento</th>
                            <th>Almacenes</th>
                            <th class="num">Líneas</th>
                            <th>Asiento</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="t in transfers"
                            :key="t.id"
                            class="clickable-row"
                            tabindex="0"
                            @click="openDetail(t)"
                            @keydown.enter="openDetail(t)"
                            @keydown.space.prevent="openDetail(t)"
                        >
                            <td class="date-cell">{{ t.posting_date }}</td>
                            <td data-label="Documento" class="code-cell">{{ t.document_type?.code }}</td>
                            <td data-label="Almacenes" class="code-cell">{{ route_(t) }}</td>
                            <td data-label="Líneas" class="num">{{ t.lines.length }}</td>
                            <td data-label="Asiento" class="muted">{{ t.journal_entry_id ? `#${t.journal_entry?.document_number}` : 'sin asiento' }}</td>
                        </tr>
                        <tr v-if="!transfers.length">
                            <td colspan="5" class="muted empty-row">Todavía no hay traslados registrados.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <DetailModal
            :open="!!selected"
            :title="selected ? `Traslado del ${selected.posting_date}` : ''"
            wide
            @close="closeDetail"
        >
            <template v-if="selected">
                <p v-if="selected.description" class="muted small">{{ selected.description }}</p>
                <div class="table-responsive no-cards">
                    <table>
                        <thead>
                            <tr>
                                <th>Artículo</th>
                                <th>Desde</th>
                                <th>Hacia</th>
                                <th class="num">Cantidad</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="line in selected.lines" :key="line.id">
                                <td>{{ line.item?.code }} — {{ line.item?.name }}</td>
                                <td class="code-cell">{{ line.warehouse?.code }}</td>
                                <td class="code-cell">{{ line.to_warehouse?.code }}</td>
                                <td class="num">{{ quantity(line.quantity) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-if="!selected.journal_entry_id" class="hint no-entry">
                    No generó asiento: origen y destino comparten la cuenta de inventario.
                </p>
            </template>

            <template #actions>
                <Link
                    v-if="selected?.journal_entry_id"
                    :href="route('journal-entries.show', selected.journal_entry_id)"
                    class="btn btn-primary"
                >Ver asiento #{{ selected.journal_entry?.document_number }}</Link>
            </template>
        </DetailModal>

        <DetailModal :open="creating" title="Nuevo traslado" wide @close="creating = false">
            <form id="transfer-form" @submit.prevent="submit">
                <div class="form-grid">
                    <div class="field">
                        <label for="transfer-doc-type">Tipo de documento</label>
                        <select id="transfer-doc-type" v-model="form.document_type_id" required>
                            <option v-for="t in documentTypes" :key="t.id" :value="t.id">{{ t.code }} — {{ t.name }}</option>
                        </select>
                    </div>
                    <div class="field">
                        <label for="transfer-date">Fecha de contabilización</label>
                        <input id="transfer-date" v-model="form.posting_date" type="date" required>
                        <span v-if="form.errors.posting_date" class="error">{{ form.errors.posting_date }}</span>
                    </div>
                    <div class="field">
                        <label for="transfer-description">Motivo (opcional)</label>
                        <input id="transfer-description" v-model="form.description" type="text" maxlength="255">
                    </div>
                </div>

                <!-- Grilla de captura: cada línea se edita en la tabla (CLAUDE.md
                     secc. 20, excepción). -->
                <div class="table-responsive capture-grid">
                    <table class="lines-table">
                        <thead>
                            <tr>
                                <th>Artículo</th>
                                <th>Desde</th>
                                <th v-if="anyUsesBins">Ubicación</th>
                                <th>Hacia</th>
                                <th v-if="anyUsesBins">Ubicación</th>
                                <th class="num">Cantidad</th>
                                <th><span class="sr-only">Quitar</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(line, index) in form.lines" :key="index">
                                <td>
                                    <select v-model="line.item_id" required aria-label="Artículo">
                                        <option value="" disabled>— Elegir —</option>
                                        <option v-for="i in items" :key="i.id" :value="i.id">{{ i.code }} — {{ i.name }}</option>
                                    </select>
                                </td>
                                <td data-label="Desde">
                                    <select v-model="line.from_warehouse_id" required aria-label="Desde" @change="line.from_warehouse_bin_id = ''">
                                        <option value="" disabled>—</option>
                                        <option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.code }}</option>
                                    </select>
                                </td>
                                <td v-if="anyUsesBins" data-label="Ubicación de origen">
                                    <select v-if="usesBins(line.from_warehouse_id)" v-model="line.from_warehouse_bin_id" required aria-label="Ubicación de origen">
                                        <option value="" disabled>—</option>
                                        <option v-for="b in binsOf(line.from_warehouse_id)" :key="b.id" :value="b.id">{{ b.code }}</option>
                                    </select>
                                    <span v-else class="muted small">—</span>
                                </td>
                                <td data-label="Hacia">
                                    <select v-model="line.to_warehouse_id" required aria-label="Hacia" @change="line.to_warehouse_bin_id = ''">
                                        <option value="" disabled>—</option>
                                        <option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.code }}</option>
                                    </select>
                                </td>
                                <td v-if="anyUsesBins" data-label="Ubicación de destino">
                                    <select v-if="usesBins(line.to_warehouse_id)" v-model="line.to_warehouse_bin_id" required aria-label="Ubicación de destino">
                                        <option value="" disabled>—</option>
                                        <option v-for="b in binsOf(line.to_warehouse_id)" :key="b.id" :value="b.id">{{ b.code }}</option>
                                    </select>
                                    <span v-else class="muted small">—</span>
                                </td>
                                <td data-label="Cantidad" class="num">
                                    <input v-model="line.quantity" type="number" step="0.000001" min="0.000001" required class="num-input" aria-label="Cantidad">
                                </td>
                                <td class="remove-cell">
                                    <button
                                        type="button" class="btn btn-ghost remove-btn"
                                        :disabled="form.lines.length === 1"
                                        aria-label="Quitar línea"
                                        title="Quitar línea"
                                        @click="form.lines.splice(index, 1)"
                                    ><XIcon /></button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <button type="button" class="btn btn-ghost add-line" @click="form.lines.push(blankLine())"><PlusIcon /> Línea</button>
            </form>

            <template #actions>
                <button type="button" class="btn btn-ghost" @click="creating = false">Cancelar</button>
                <button type="submit" form="transfer-form" class="btn btn-primary" :disabled="form.processing"><ArrowRightLeftIcon /> Trasladar</button>
            </template>
        </DetailModal>
    </AppLayout>
</template>

<style scoped>
table { font-size: 0.85rem; }
.date-cell, .code-cell { font-variant-numeric: tabular-nums; white-space: nowrap; }
.no-entry { margin: 0.9rem 0 0; }

.lines-table th, .lines-table td { padding: 0.4rem 0.45rem; }
.lines-table select, .lines-table input { width: 100%; min-width: 0; }
.num-input { text-align: right; }
.remove-btn { width: 2.25rem; padding: 0; }
.add-line { margin-top: 0.6rem; }

@media screen and (max-width: 1024px) {
    .remove-cell { justify-content: flex-end; }
}
</style>
