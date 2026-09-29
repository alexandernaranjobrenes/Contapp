<script setup>
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { ListTreeIcon, PencilIcon, PlusIcon } from '@lucide/vue';
import AppLayout from '../../../Layouts/AppLayout.vue';
import DetailModal from '../../../Components/DetailModal.vue';
import { confirmAction } from '../../../Utils/confirm';
import { useCrudModal } from '../../../Utils/crudModal';

const props = defineProps({
    billsOfMaterials: { type: Array, default: () => [] },
    items: { type: Array, default: () => [] },
});

const page = usePage();

// Ficha, alta y edición en un solo modal (CLAUDE.md secc. 20 y 21). El
// código y el producto solo se eligen al crear: para otro producto, otra
// receta.
const { mode, selected, modalOpen, form, openCreate, openDetail, close, startEdit, cancelForm, submit } = useCrudModal({
    records: () => props.billsOfMaterials,
    defaults: () => ({ code: '', name: '', item_id: '', output_quantity: 1, is_default: false, status: 'active', notes: '' }),
    toForm: (b) => ({
        code: b.code,
        name: b.name,
        item_id: b.item_id ?? '',
        output_quantity: b.output_quantity,
        is_default: b.is_default,
        status: b.status,
        notes: b.notes ?? '',
    }),
    store: () => route('bills-of-materials.store'),
    update: (b) => route('bills-of-materials.update', b.id),
    updatePayload: ({ code, item_id, ...data }) => data,
});

const modalTitle = computed(() => {
    if (mode.value === 'create') return 'Nueva receta';
    return selected.value ? `${selected.value.code} — ${selected.value.name}` : '';
});

function destroy() {
    const b = selected.value;

    confirmAction({
        title: 'Eliminar receta',
        message: `La receta ${b.code} — ${b.name} se elimina de forma definitiva.`,
        confirmLabel: 'Eliminar',
        danger: true,
        onConfirm: () => router.delete(route('bills-of-materials.destroy', b.id), { preserveScroll: true }),
    });
}

function quantity(value) {
    return Number(value ?? 0).toLocaleString('es-CR', { minimumFractionDigits: 0, maximumFractionDigits: 6 });
}
</script>

<template>
    <Head title="Listas de materiales" />

    <AppLayout title="Listas de materiales">
        <div class="view-toolbar">
            <div class="view-actions">
                <Link :href="route('production-orders.index')" class="btn btn-ghost">Órdenes de fabricación</Link>
                <button type="button" class="btn btn-primary" :disabled="!items.length" @click="openCreate()">
                    <PlusIcon /> Crear nuevo
                </button>
            </div>
        </div>

        <div v-if="page.props.errors?.bill_of_material" class="flash flash-error">
            {{ page.props.errors.bill_of_material }}
        </div>

        <p class="hint">
            La receta dice <strong>qué lleva</strong> un producto y <strong>cuánto</strong>, nunca a qué costo:
            el costo lo pone el motor de movimientos al contabilizar la emisión, al promedio vigente de cada
            componente. La cantidad se guarda <strong>por lote</strong>, tal como está escrita la fórmula.
        </p>

        <div class="card">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Código</th>
                            <th>Nombre</th>
                            <th>Produce</th>
                            <th class="num">Componentes</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="b in billsOfMaterials"
                            :key="b.id"
                            class="clickable-row"
                            tabindex="0"
                            @click="openDetail(b)"
                            @keydown.enter="openDetail(b)"
                            @keydown.space.prevent="openDetail(b)"
                        >
                            <td>
                                <span class="code-cell">
                                    {{ b.code }}
                                    <span v-if="b.is_default" class="badge badge-warning">Predeterminada</span>
                                </span>
                            </td>
                            <td data-label="Nombre">{{ b.name }}</td>
                            <td data-label="Produce"><strong class="item-code">{{ b.item_code }}</strong> — {{ b.item_name }}</td>
                            <td data-label="Componentes" class="num" :class="{ warn: !b.lines_count }">{{ b.lines_count }}</td>
                            <td data-label="Estado">
                                <span class="badge" :class="b.status === 'active' ? 'badge-success' : 'badge-neutral'">
                                    {{ b.status === 'active' ? 'Activa' : 'Inactiva' }}
                                </span>
                            </td>
                        </tr>
                        <tr v-if="!billsOfMaterials.length">
                            <td colspan="5" class="muted empty-row">
                                Todavía no hay recetas. Sin una, cada emisión a producción se digita de memoria.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <DetailModal :open="modalOpen" :title="modalTitle" @close="close">
            <template #badge>
                <span v-if="selected && mode !== 'create'" class="badge" :class="selected.status === 'active' ? 'badge-success' : 'badge-neutral'">
                    {{ selected.status === 'active' ? 'Activa' : 'Inactiva' }}
                </span>
            </template>

            <template v-if="selected && mode === 'details'">
                <dl class="detail-list">
                    <div class="full">
                        <dt>Produce</dt>
                        <dd>{{ selected.item_code }} — {{ selected.item_name }}</dd>
                    </div>
                    <div>
                        <dt>Rinde (receta completa)</dt>
                        <dd>{{ quantity(selected.output_quantity) }}</dd>
                    </div>
                    <div>
                        <dt>Componentes</dt>
                        <dd>{{ selected.lines_count }}</dd>
                    </div>
                    <div>
                        <dt>Predeterminada</dt>
                        <dd>{{ selected.is_default ? 'Sí' : 'No' }}</dd>
                    </div>
                    <div class="full">
                        <dt>Notas</dt>
                        <dd>{{ selected.notes || '—' }}</dd>
                    </div>
                </dl>
                <p v-if="!selected.lines_count" class="flash flash-warning no-lines">
                    Todavía no tiene componentes: una orden de fabricación con esta receta no emitiría nada.
                </p>
            </template>

            <form v-if="mode === 'create' || (selected && mode === 'edit')" id="bom-form" @submit.prevent="submit">
                <div v-if="mode === 'create'" class="field">
                    <label for="bom-code">Código</label>
                    <input id="bom-code" v-model="form.code" type="text" maxlength="20" required>
                    <span v-if="form.errors.code" class="error">{{ form.errors.code }}</span>
                </div>

                <div class="field">
                    <label for="bom-name">Nombre</label>
                    <input id="bom-name" v-model="form.name" type="text" maxlength="255" required>
                    <span v-if="form.errors.name" class="error">{{ form.errors.name }}</span>
                </div>

                <div v-if="mode === 'create'" class="field">
                    <label for="bom-item">Producto que fabrica</label>
                    <select id="bom-item" v-model="form.item_id" required>
                        <option value="">Elegí un artículo</option>
                        <option v-for="i in items" :key="i.id" :value="i.id">{{ i.code }} — {{ i.name }}</option>
                    </select>
                    <span v-if="form.errors.item_id" class="error">{{ form.errors.item_id }}</span>
                    <span class="muted small">
                        Solo artículos de inventario: un servicio no se fabrica. El producto no puede cambiarse
                        después — para otro producto, otra receta.
                    </span>
                </div>

                <div class="field">
                    <label for="bom-output">Unidades que rinde la receta completa</label>
                    <input id="bom-output" v-model="form.output_quantity" type="number" step="0.000001" min="0.000001" required>
                    <span v-if="form.errors.output_quantity" class="error">{{ form.errors.output_quantity }}</span>
                    <span class="muted small">
                        Si la fórmula rinde 100 litros, poné 100 y cargá los insumos del lote completo. Guardarla
                        por unidad y volver a multiplicar arrastra redondeo lote tras lote.
                    </span>
                </div>

                <label class="check">
                    <input v-model="form.is_default" type="checkbox">
                    Predeterminada para este producto
                </label>

                <div class="field">
                    <label for="bom-status">Estado</label>
                    <select id="bom-status" v-model="form.status">
                        <option value="active">Activa</option>
                        <option value="inactive">Inactiva</option>
                    </select>
                </div>

                <div class="field">
                    <label for="bom-notes">Notas</label>
                    <input id="bom-notes" v-model="form.notes" type="text" maxlength="255">
                </div>
            </form>

            <template #actions>
                <template v-if="selected && mode === 'details'">
                    <button type="button" class="btn btn-ghost btn-danger-text" @click="destroy">Eliminar</button>
                    <Link :href="route('bills-of-materials.lines', selected.id)" class="btn btn-ghost"><ListTreeIcon /> Componentes</Link>
                    <button type="button" class="btn btn-primary" @click="startEdit"><PencilIcon /> Editar</button>
                </template>
                <template v-else-if="modalOpen">
                    <button type="button" class="btn btn-ghost" @click="cancelForm">Cancelar</button>
                    <button type="submit" form="bom-form" class="btn btn-primary" :disabled="form.processing">
                        {{ mode === 'create' ? 'Crear' : 'Guardar' }}
                    </button>
                </template>
            </template>
        </DetailModal>
    </AppLayout>
</template>

<style scoped>
table { font-size: 0.85rem; }
.code-cell { display: inline-flex; align-items: center; flex-wrap: wrap; gap: 0.4rem; font-variant-numeric: tabular-nums; }
.item-code { font-variant-numeric: tabular-nums; }
.warn { color: var(--color-warning); font-weight: 700; }
.no-lines { margin: 0.9rem 0 0; }
</style>
