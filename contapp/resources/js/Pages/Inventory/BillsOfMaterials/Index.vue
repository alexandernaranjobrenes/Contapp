<script setup>
import { Head, Link, useForm, router, usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AppLayout from '../../../Layouts/AppLayout.vue';
import DocumentToolbar from '../../../Components/DocumentToolbar.vue';

defineProps({
    billsOfMaterials: { type: Array, default: () => [] },
    items: { type: Array, default: () => [] },
});

const page = usePage();

const blank = {
    code: '',
    name: '',
    item_id: '',
    output_quantity: 1,
    is_default: false,
    status: 'active',
    notes: '',
};

const creating = ref(false);
const createForm = useForm({ ...blank });

function openCreate() {
    createForm.reset();
    creating.value = true;
}

const editing = ref(null);
const editForm = useForm({ ...blank });

const activeForm = computed(() => (creating.value ? createForm : editForm));

function openEdit(bom) {
    editForm.clearErrors();
    editForm.name = bom.name;
    editForm.output_quantity = bom.output_quantity;
    editForm.is_default = bom.is_default;
    editForm.status = bom.status;
    editForm.notes = bom.notes ?? '';
    editing.value = bom;
}

function submitCreate() {
    createForm.post(route('bills-of-materials.store'), {
        onSuccess: () => (creating.value = false), preserveScroll: true,
    });
}

function submitEdit() {
    editForm.put(route('bills-of-materials.update', editing.value.id), {
        onSuccess: () => (editing.value = null), preserveScroll: true,
    });
}

function destroy(bom) {
    if (! confirm(`¿Eliminar la receta ${bom.code} — ${bom.name}?`)) return;

    router.delete(route('bills-of-materials.destroy', bom.id), { preserveScroll: true });
}

function quantity(value) {
    return Number(value ?? 0).toLocaleString('es-CR', { minimumFractionDigits: 0, maximumFractionDigits: 6 });
}
</script>

<template>
    <Head title="Listas de materiales" />

    <AppLayout title="Listas de materiales">
        <template #actions>
            <Link :href="route('production-orders.index')" class="btn btn-ghost">Órdenes de fabricación</Link>
        </template>

        <DocumentToolbar can-create @new="openCreate()" />

        <div v-if="page.props.errors?.bill_of_material" class="flash flash-error">
            {{ page.props.errors.bill_of_material }}
        </div>

        <p class="hint">
            La receta dice <strong>qué lleva</strong> un producto y <strong>cuánto</strong>, nunca a qué costo:
            el costo lo pone el motor de movimientos al contabilizar la emisión, al promedio vigente de cada
            componente. La cantidad se guarda <strong>por lote</strong>, tal como está escrita la fórmula.
        </p>

        <div class="card">
            <div class="card-header">
                <span class="muted">{{ billsOfMaterials.length }} receta(s)</span>
                <button type="button" class="btn btn-primary" :disabled="!items.length" @click="openCreate()">
                    + Nueva receta
                </button>
            </div>

            <div class="table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>Código</th>
                            <th>Nombre</th>
                            <th>Produce</th>
                            <th class="right">Rinde</th>
                            <th class="right">Componentes</th>
                            <th>Estado</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="b in billsOfMaterials" :key="b.id">
                            <td class="num">
                                {{ b.code }}
                                <span v-if="b.is_default" class="badge-default">predeterminada</span>
                            </td>
                            <td>{{ b.name }}</td>
                            <td><strong class="num">{{ b.item_code }}</strong> — {{ b.item_name }}</td>
                            <td class="right">{{ quantity(b.output_quantity) }}</td>
                            <td class="right" :class="{ warn: !b.lines_count }">
                                {{ b.lines_count }}
                            </td>
                            <td>{{ b.status === 'active' ? 'Activa' : 'Inactiva' }}</td>
                            <td class="row-actions">
                                <Link :href="route('bills-of-materials.lines', b.id)" class="btn btn-ghost btn-sm">
                                    Componentes
                                </Link>
                                <button type="button" class="btn btn-ghost btn-sm" @click="openEdit(b)">Editar</button>
                                <button type="button" class="btn btn-ghost btn-sm" @click="destroy(b)">Eliminar</button>
                            </td>
                        </tr>
                        <tr v-if="!billsOfMaterials.length">
                            <td colspan="7" class="muted empty-row">
                                Todavía no hay recetas. Sin una, cada emisión a producción se digita de memoria.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div v-if="creating || editing" class="modal-backdrop" @click.self="creating = false; editing = null">
            <form class="modal card" @submit.prevent="creating ? submitCreate() : submitEdit()">
                <h2>{{ creating ? 'Nueva receta' : 'Editar ' + editing.code }}</h2>

                <div v-if="creating" class="field">
                    <label>Código</label>
                    <input v-model="createForm.code" type="text" maxlength="20" required>
                    <span v-if="createForm.errors.code" class="error">{{ createForm.errors.code }}</span>
                </div>

                <div class="field">
                    <label>Nombre</label>
                    <input v-model="activeForm.name" type="text" maxlength="255" required>
                    <span v-if="activeForm.errors.name" class="error">{{ activeForm.errors.name }}</span>
                </div>

                <div v-if="creating" class="field">
                    <label>Producto que fabrica</label>
                    <select v-model="createForm.item_id" required>
                        <option value="">Elegí un artículo</option>
                        <option v-for="i in items" :key="i.id" :value="i.id">{{ i.code }} — {{ i.name }}</option>
                    </select>
                    <span v-if="createForm.errors.item_id" class="error">{{ createForm.errors.item_id }}</span>
                    <span class="hint small">
                        Solo artículos de inventario: un servicio no se fabrica. El producto no puede cambiarse
                        después — para otro producto, otra receta.
                    </span>
                </div>

                <div class="field">
                    <label>Unidades que rinde la receta completa</label>
                    <input v-model="activeForm.output_quantity" type="number" step="0.000001" min="0.000001" required>
                    <span v-if="activeForm.errors.output_quantity" class="error">{{ activeForm.errors.output_quantity }}</span>
                    <span class="hint small">
                        Si la fórmula rinde 100 litros, poné 100 y cargá los insumos del lote completo. Guardarla
                        por unidad y volver a multiplicar arrastra redondeo lote tras lote.
                    </span>
                </div>

                <label class="check">
                    <input v-model="activeForm.is_default" type="checkbox">
                    Predeterminada para este producto
                </label>

                <div class="field">
                    <label>Estado</label>
                    <select v-model="activeForm.status">
                        <option value="active">Activa</option>
                        <option value="inactive">Inactiva</option>
                    </select>
                </div>

                <div class="field">
                    <label>Notas</label>
                    <input v-model="activeForm.notes" type="text" maxlength="255">
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-ghost" @click="creating = false; editing = null">Cancelar</button>
                    <button type="submit" class="btn btn-primary" :disabled="activeForm.processing">Guardar</button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>

<style scoped>
.right { text-align: right; }
.num { font-variant-numeric: tabular-nums; }
.small { font-size: 0.76rem; }
.hint { color: var(--color-text-muted); font-size: 0.82rem; margin: 0 0 0.75rem; }
.empty-row { text-align: center; padding: 1.5rem; }
.row-actions { display: flex; gap: 0.3rem; justify-content: flex-end; }
.warn { color: #a04000; font-weight: 600; }
.badge-default { display: inline-block; margin-left: 0.4rem; font-size: 0.65rem; padding: 0.05rem 0.3rem; border-radius: 3px; background: var(--color-primary-soft, #e8eef7); color: var(--color-primary, #0B1F3A); font-weight: 600; }
.modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.4); display: flex; align-items: center; justify-content: center; z-index: 50; padding: 1rem; }
.modal { width: min(520px, 100%); max-height: 90vh; overflow-y: auto; padding: 1.2rem; }
.modal h2 { margin: 0 0 0.8rem; font-size: 1rem; }
.field { display: flex; flex-direction: column; gap: 0.2rem; margin-bottom: 0.7rem; }
.field label { font-size: 0.78rem; font-weight: 600; }
.check { display: flex; align-items: center; gap: 0.4rem; font-size: 0.82rem; margin-bottom: 0.5rem; }
.error { color: var(--color-danger); font-size: 0.76rem; }
.modal-actions { display: flex; justify-content: flex-end; gap: 0.5rem; margin-top: 0.8rem; }
.flash { margin-bottom: 0.75rem; padding: 0.6rem 0.9rem; border-radius: var(--radius-sm); font-size: 0.85rem; }
.flash-error { background: var(--color-danger-soft); color: var(--color-danger); }
</style>
