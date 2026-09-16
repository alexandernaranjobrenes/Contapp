<script setup>
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AppLayout from '../../../Layouts/AppLayout.vue';
import DocumentToolbar from '../../../Components/DocumentToolbar.vue';

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

const ready = computed(() => props.documentTypes.length && props.items.length && props.warehouses.length >= 1);

function openCreate() {
    form.reset();
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
</script>

<template>
    <Head title="Traslados entre almacenes" />

    <AppLayout title="Traslados entre almacenes">
        <DocumentToolbar can-create @new="openCreate()" />

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
            <div class="card-header">
                <span class="muted">{{ transfers.length }} traslado(s)</span>
                <button type="button" class="btn btn-primary" :disabled="!ready" @click="openCreate()">+ Nuevo traslado</button>
            </div>

            <div class="table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Tipo</th>
                            <th>Artículo</th>
                            <th>Origen</th>
                            <th>Destino</th>
                            <th class="right">Cantidad</th>
                            <th>Asiento</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="t in transfers" :key="t.id">
                            <tr v-for="(line, index) in t.lines" :key="line.id">
                                <td class="num">{{ index === 0 ? t.posting_date : '' }}</td>
                                <td class="code-cell">{{ index === 0 ? t.document_type?.code : '' }}</td>
                                <td>{{ line.item?.code }} — {{ line.item?.name }}</td>
                                <td class="code-cell">{{ line.warehouse?.code }}</td>
                                <td class="code-cell">{{ line.to_warehouse?.code }}</td>
                                <td class="num right">{{ quantity(line.quantity) }}</td>
                                <td class="muted small">
                                    <Link v-if="index === 0 && t.journal_entry_id" :href="route('journal-entries.show', t.journal_entry_id)" class="link">
                                        #{{ t.journal_entry?.document_number }}
                                    </Link>
                                    <span v-else-if="index === 0">sin asiento</span>
                                </td>
                            </tr>
                        </template>
                        <tr v-if="!transfers.length">
                            <td colspan="7" class="muted empty-row">Todavía no hay traslados registrados.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div v-if="creating" class="modal-backdrop" @click.self="creating = false">
            <form class="modal-card card" @submit.prevent="submit">
                <h2>Nuevo traslado</h2>

                <div class="grid-2">
                    <div class="field">
                        <label>Tipo de documento</label>
                        <select v-model="form.document_type_id" required>
                            <option v-for="t in documentTypes" :key="t.id" :value="t.id">{{ t.code }} — {{ t.name }}</option>
                        </select>
                    </div>
                    <div class="field">
                        <label>Fecha de contabilización</label>
                        <input v-model="form.posting_date" type="date" required>
                        <span v-if="form.errors.posting_date" class="error">{{ form.errors.posting_date }}</span>
                    </div>
                </div>

                <div class="field">
                    <label>Motivo (opcional)</label>
                    <input v-model="form.description" type="text" maxlength="255">
                </div>

                <div class="table-scroll">
                    <table class="lines">
                        <thead>
                            <tr>
                                <th>Artículo</th>
                                <th>Desde</th>
                                <th v-if="warehouses.some((w) => w.uses_bins)">Ubic.</th>
                                <th>Hacia</th>
                                <th v-if="warehouses.some((w) => w.uses_bins)">Ubic.</th>
                                <th class="right">Cantidad</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(line, index) in form.lines" :key="index">
                                <td>
                                    <select v-model="line.item_id" required>
                                        <option value="" disabled>— Elegir —</option>
                                        <option v-for="i in items" :key="i.id" :value="i.id">{{ i.code }}</option>
                                    </select>
                                </td>
                                <td>
                                    <select v-model="line.from_warehouse_id" required @change="line.from_warehouse_bin_id = ''">
                                        <option value="" disabled>—</option>
                                        <option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.code }}</option>
                                    </select>
                                </td>
                                <td v-if="warehouses.some((w) => w.uses_bins)">
                                    <select v-if="usesBins(line.from_warehouse_id)" v-model="line.from_warehouse_bin_id" required>
                                        <option value="" disabled>—</option>
                                        <option v-for="b in binsOf(line.from_warehouse_id)" :key="b.id" :value="b.id">{{ b.code }}</option>
                                    </select>
                                    <span v-else class="muted small">—</span>
                                </td>
                                <td>
                                    <select v-model="line.to_warehouse_id" required @change="line.to_warehouse_bin_id = ''">
                                        <option value="" disabled>—</option>
                                        <option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.code }}</option>
                                    </select>
                                </td>
                                <td v-if="warehouses.some((w) => w.uses_bins)">
                                    <select v-if="usesBins(line.to_warehouse_id)" v-model="line.to_warehouse_bin_id" required>
                                        <option value="" disabled>—</option>
                                        <option v-for="b in binsOf(line.to_warehouse_id)" :key="b.id" :value="b.id">{{ b.code }}</option>
                                    </select>
                                    <span v-else class="muted small">—</span>
                                </td>
                                <td><input v-model="line.quantity" type="number" step="0.000001" min="0.000001" required class="right"></td>
                                <td>
                                    <button
                                        type="button" class="btn btn-ghost"
                                        :disabled="form.lines.length === 1"
                                        @click="form.lines.splice(index, 1)"
                                    >
                                        Quitar
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="modal-actions spread">
                    <button type="button" class="btn btn-ghost" @click="form.lines.push(blankLine())">+ Línea</button>
                    <div class="modal-actions">
                        <button type="submit" class="btn btn-primary" :disabled="form.processing">Trasladar</button>
                        <button type="button" class="btn btn-ghost" @click="creating = false">Cancelar</button>
                    </div>
                </div>
            </form>
        </div>
    </AppLayout>
</template>

<style scoped>
.card-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 0.85rem 1.1rem; border-bottom: 1px solid var(--color-border);
}

.flash { margin-bottom: 0.75rem; padding: 0.6rem 0.9rem; border-radius: var(--radius-sm); font-size: 0.85rem; }
.flash-error { background: var(--color-danger-soft); color: var(--color-danger); }
.flash-warning { background: var(--color-warning-soft); color: var(--color-warning); }

.hint { font-size: 0.82rem; color: var(--color-text-muted); margin: -0.25rem 0 1rem; }

.table-scroll { overflow-x: auto; }
table { font-size: 0.85rem; width: 100%; }
th, td { text-align: left; padding: 0.5rem 1rem; border-top: 1px solid var(--color-border); white-space: nowrap; }
table.lines th, table.lines td { padding: 0.4rem 0.5rem; }
.right { text-align: right; }
.code-cell, .num { font-variant-numeric: tabular-nums; }
.muted { color: var(--color-text-muted); }
.small { font-size: 0.76rem; }
.empty-row { text-align: center; padding: 1.5rem; white-space: normal; }
.link { color: var(--color-primary); text-decoration: none; font-weight: 600; }
.link:hover { text-decoration: underline; }

.modal-backdrop {
    position: fixed; inset: 0; background: rgba(11, 31, 58, 0.45);
    display: flex; align-items: center; justify-content: center; z-index: 50; padding: 1rem;
}

.modal-card { width: 860px; max-width: 100%; max-height: 90vh; overflow-y: auto; padding: 1.5rem; }
.modal-card h2 { font-size: 1rem; margin: 0 0 0.75rem; }

.grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 0 1rem; }

.field { display: flex; flex-direction: column; gap: 0.2rem; margin-bottom: 0.75rem; }
.field label { font-size: 0.78rem; color: var(--color-text-muted); }

.field input, .field select, td input, td select {
    width: 100%; background: var(--color-surface); border: 1px solid var(--color-border);
    border-radius: var(--radius-sm); padding: 0.45rem 0.6rem; font-size: 0.85rem; color: var(--color-text);
}

.error { color: var(--color-danger); font-size: 0.76rem; }
.modal-actions { display: flex; gap: 0.6rem; margin-top: 0.75rem; }
.modal-actions.spread { justify-content: space-between; align-items: center; }
</style>
