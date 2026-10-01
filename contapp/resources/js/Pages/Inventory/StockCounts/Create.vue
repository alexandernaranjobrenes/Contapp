<script setup>
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { ArrowLeftIcon, ClipboardListIcon } from '@lucide/vue';
import AppLayout from '../../../Layouts/AppLayout.vue';

const props = defineProps({
    warehouses: { type: Array, default: () => [] },
    itemGroups: { type: Array, default: () => [] },
    documentTypes: { type: Array, default: () => [] },
    busyWarehouses: { type: Array, default: () => [] },
});

const page = usePage();
const today = new Date().toISOString().slice(0, 10);

const form = useForm({
    document_type_id: props.documentTypes[0]?.id ?? '',
    cutoff_date: today,
    warehouse_id: props.warehouses.find((w) => ! props.busyWarehouses.includes(w.id))?.id ?? '',
    item_group_id: '',
    blind: false,
    description: '',
});

function isBusy(warehouseId) {
    return props.busyWarehouses.includes(warehouseId);
}

const selectedBusy = computed(() => isBusy(form.warehouse_id));

function submit() {
    form
        .transform((data) => ({
            ...data,
            item_group_id: data.item_group_id === '' ? null : data.item_group_id,
            description: data.description === '' ? null : data.description,
        }))
        .post(route('stock-counts.store'));
}
</script>

<template>
    <Head title="Nueva toma física" />

    <AppLayout title="Nueva toma física de inventario">
        <div class="view-toolbar">
            <Link :href="route('stock-counts.index')" class="btn btn-ghost"><ArrowLeftIcon /> Tomas físicas</Link>
        </div>

        <div v-if="page.props.errors?.count" class="flash flash-error">{{ page.props.errors.count }}</div>

        <p v-if="!documentTypes.length" class="flash flash-warning">
            Hace falta al menos un tipo de documento activo del módulo de inventario para poder contabilizar el ajuste.
        </p>

        <p class="hint">
            Abrir la toma <strong>congela</strong> la existencia teórica a la fecha de corte y arma una línea por
            cada artículo con movimiento en ese almacén. Nada se ajusta todavía: el ajuste se genera al cerrarla,
            con las diferencias que resulten del conteo.
        </p>

        <form class="card" @submit.prevent="submit">
            <div class="form-grid">
                <div class="field">
                    <label for="sc-cutoff">Fecha de corte</label>
                    <input id="sc-cutoff" v-model="form.cutoff_date" type="date" required>
                    <span class="muted small">La hoja dirá lo que el kardex tenía a esta fecha, no lo de hoy.</span>
                    <span v-if="form.errors.cutoff_date" class="error">{{ form.errors.cutoff_date }}</span>
                </div>
                <div class="field">
                    <label for="sc-warehouse">Almacén</label>
                    <select id="sc-warehouse" v-model="form.warehouse_id" required>
                        <option v-for="w in warehouses" :key="w.id" :value="w.id" :disabled="isBusy(w.id)">
                            {{ w.code }} — {{ w.name }}{{ isBusy(w.id) ? ' (ya tiene una toma abierta)' : '' }}
                        </option>
                    </select>
                    <span v-if="selectedBusy" class="error">Ese almacén ya tiene una toma abierta.</span>
                    <span v-if="form.errors.warehouse_id" class="error">{{ form.errors.warehouse_id }}</span>
                </div>

                <div class="field">
                    <label for="sc-group">Familia de artículos</label>
                    <select id="sc-group" v-model="form.item_group_id">
                        <option value="">— Todas las familias —</option>
                        <option v-for="g in itemGroups" :key="g.id" :value="g.id">{{ g.code }} — {{ g.name }}</option>
                    </select>
                    <span class="muted small">Contar por familia permite repartir el inventario en varias jornadas.</span>
                </div>
                <div class="field">
                    <label for="sc-doc-type">Tipo de documento del ajuste</label>
                    <select id="sc-doc-type" v-model="form.document_type_id" required>
                        <option v-for="t in documentTypes" :key="t.id" :value="t.id">{{ t.code }} — {{ t.name }}</option>
                    </select>
                    <span v-if="form.errors.document_type_id" class="error">{{ form.errors.document_type_id }}</span>
                </div>
            </div>

            <label class="check">
                <input v-model="form.blind" type="checkbox">
                <span>
                    <strong>Conteo a ciegas</strong>
                    <span class="muted small">
                        La hoja impresa oculta la existencia del sistema. Es el modo recomendado: ver el número que
                        "tiene que dar" sesga el conteo.
                    </span>
                </span>
            </label>

            <div class="field">
                <label for="sc-description">Descripción (opcional)</label>
                <input id="sc-description" v-model="form.description" type="text" maxlength="255">
            </div>

            <div class="form-actions">
                <Link :href="route('stock-counts.index')" class="btn btn-ghost">Cancelar</Link>
                <button
                    type="submit" class="btn btn-primary"
                    :disabled="form.processing || selectedBusy || !documentTypes.length"
                >
                    <ClipboardListIcon /> Abrir toma física
                </button>
            </div>
        </form>
    </AppLayout>
</template>

<style scoped>
.card { padding: 1rem 1.25rem; }
.check { align-items: flex-start; margin-bottom: 1rem; }
.check span { display: flex; flex-direction: column; gap: 0.1rem; }
</style>
