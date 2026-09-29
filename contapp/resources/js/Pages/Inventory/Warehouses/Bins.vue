<script setup>
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { ArrowLeftIcon, PencilIcon, PlusIcon } from '@lucide/vue';
import AppLayout from '../../../Layouts/AppLayout.vue';
import DetailModal from '../../../Components/DetailModal.vue';
import { confirmAction } from '../../../Utils/confirm';
import { useCrudModal } from '../../../Utils/crudModal';

const props = defineProps({
    warehouse: { type: Object, required: true },
    bins: { type: Array, default: () => [] },
});

const page = usePage();

function quantity(value) {
    return Number(value ?? 0).toLocaleString('es-CR', { minimumFractionDigits: 0, maximumFractionDigits: 6 });
}

// Ficha, alta y edición de la ubicación en un solo modal (CLAUDE.md secc. 20
// y 21). El código solo se elige al crear.
const { mode, selected, modalOpen, form, openCreate, openDetail, close, startEdit, cancelForm, submit } = useCrudModal({
    records: () => props.bins,
    defaults: () => ({ code: '', name: '', status: 'active' }),
    toForm: (b) => ({ code: b.code, name: b.name ?? '', status: b.status }),
    store: () => route('warehouse-bins.store', props.warehouse.id),
    update: (b) => route('warehouse-bins.update', [props.warehouse.id, b.id]),
    updatePayload: ({ name, status }) => ({ name, status }),
});

const modalTitle = computed(() => {
    if (mode.value === 'create') return 'Nueva ubicación';
    return selected.value ? `Ubicación ${selected.value.code}` : '';
});

function destroy() {
    const b = selected.value;

    confirmAction({
        title: 'Eliminar ubicación',
        message: `La ubicación ${b.code} se elimina de forma definitiva.`,
        confirmLabel: 'Eliminar',
        danger: true,
        onConfirm: () => router.delete(route('warehouse-bins.destroy', [props.warehouse.id, b.id]), { preserveScroll: true }),
    });
}
</script>

<template>
    <Head :title="`Ubicaciones de ${warehouse.code}`" />

    <AppLayout :title="`Ubicaciones — ${warehouse.code} ${warehouse.name}`">
        <div class="view-toolbar">
            <Link :href="route('warehouses.index')" class="btn btn-ghost"><ArrowLeftIcon /> Almacenes</Link>
            <div class="view-actions">
                <button type="button" class="btn btn-primary" @click="openCreate()"><PlusIcon /> Crear nuevo</button>
            </div>
        </div>

        <div v-if="page.props.errors?.bin" class="flash flash-error">{{ page.props.errors.bin }}</div>

        <p v-if="!warehouse.uses_bins" class="flash flash-warning">
            Este almacén todavía no tiene activado el manejo por ubicaciones, así que los movimientos no las van a pedir.
            Activalo desde la ficha del almacén cuando las ubicaciones estén creadas.
        </p>

        <p class="hint">
            Las ubicaciones son una capa <strong>logística</strong>: dicen dónde está cada unidad, no cuánto vale. El costo
            promedio sigue siendo global por artículo, así que mover algo entre ubicaciones no genera asiento.
        </p>

        <div class="card">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Código</th>
                            <th>Nombre</th>
                            <th class="num">Artículos</th>
                            <th class="num">Existencia</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="b in bins"
                            :key="b.id"
                            class="clickable-row"
                            tabindex="0"
                            @click="openDetail(b)"
                            @keydown.enter="openDetail(b)"
                            @keydown.space.prevent="openDetail(b)"
                        >
                            <td class="code-cell">{{ b.code }}</td>
                            <td data-label="Nombre">{{ b.name ?? '—' }}</td>
                            <td data-label="Artículos" class="num">{{ b.items_count }}</td>
                            <td data-label="Existencia" class="num">{{ quantity(b.on_hand) }}</td>
                            <td data-label="Estado">
                                <span class="badge" :class="b.status === 'active' ? 'badge-success' : 'badge-neutral'">
                                    {{ b.status === 'active' ? 'Activa' : 'Inactiva' }}
                                </span>
                            </td>
                        </tr>
                        <tr v-if="!bins.length">
                            <td colspan="5" class="muted empty-row">Este almacén todavía no tiene ubicaciones.</td>
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

            <dl v-if="selected && mode === 'details'" class="detail-list">
                <div class="full">
                    <dt>Nombre</dt>
                    <dd>{{ selected.name ?? '—' }}</dd>
                </div>
                <div>
                    <dt>Artículos</dt>
                    <dd>{{ selected.items_count }}</dd>
                </div>
                <div>
                    <dt>Existencia</dt>
                    <dd>{{ quantity(selected.on_hand) }}</dd>
                </div>
            </dl>

            <form v-if="mode === 'create' || (selected && mode === 'edit')" id="bin-form" @submit.prevent="submit">
                <p v-if="mode === 'edit'" class="muted small">El código no se puede cambiar una vez creada la ubicación.</p>

                <div v-if="mode === 'create'" class="field">
                    <label for="bin-code">Código (ej. A-01-03)</label>
                    <input id="bin-code" v-model="form.code" type="text" maxlength="30" required>
                    <span v-if="form.errors.code" class="error">{{ form.errors.code }}</span>
                </div>

                <div class="field">
                    <label for="bin-name">Nombre (opcional)</label>
                    <input id="bin-name" v-model="form.name" type="text">
                </div>

                <div class="field">
                    <label for="bin-status">Estado</label>
                    <select id="bin-status" v-model="form.status">
                        <option value="active">Activa</option>
                        <option value="inactive">Inactiva</option>
                    </select>
                </div>
            </form>

            <template #actions>
                <template v-if="selected && mode === 'details'">
                    <button type="button" class="btn btn-ghost btn-danger-text" @click="destroy">Eliminar</button>
                    <button type="button" class="btn btn-primary" @click="startEdit"><PencilIcon /> Editar</button>
                </template>
                <template v-else-if="modalOpen">
                    <button type="button" class="btn btn-ghost" @click="cancelForm">Cancelar</button>
                    <button type="submit" form="bin-form" class="btn btn-primary" :disabled="form.processing">
                        {{ mode === 'create' ? 'Crear' : 'Guardar' }}
                    </button>
                </template>
            </template>
        </DetailModal>
    </AppLayout>
</template>

<style scoped>
table { font-size: 0.85rem; }
.code-cell { font-variant-numeric: tabular-nums; white-space: nowrap; }
</style>
