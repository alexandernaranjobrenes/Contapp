<script setup>
import { Head, router, usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import { PencilIcon, PlusIcon } from '@lucide/vue';
import AppLayout from '../../../Layouts/AppLayout.vue';
import DetailModal from '../../../Components/DetailModal.vue';
import { confirmAction } from '../../../Utils/confirm';
import { useCrudModal } from '../../../Utils/crudModal';

const props = defineProps({
    unitsOfMeasure: { type: Array, default: () => [] },
});

const page = usePage();
const search = ref('');

const filtered = computed(() => {
    const q = search.value.trim().toLowerCase();
    const sorted = [...props.unitsOfMeasure].sort((a, b) => a.code.localeCompare(b.code));
    if (! q) return sorted;

    return sorted.filter((u) => u.code.toLowerCase().includes(q) || u.name.toLowerCase().includes(q));
});

// Ficha, alta y edición en un solo modal (CLAUDE.md secc. 20 y 21). El
// código solo se elige al crear.
const { mode, selected, modalOpen, form, openCreate, openDetail, close, startEdit, cancelForm, submit } = useCrudModal({
    records: () => props.unitsOfMeasure,
    defaults: () => ({ code: '', name: '', decimals: 2, status: 'active' }),
    toForm: (u) => ({ code: u.code, name: u.name, decimals: u.decimals, status: u.status }),
    store: () => route('units-of-measure.store'),
    update: (u) => route('units-of-measure.update', u.id),
    updatePayload: ({ name, decimals, status }) => ({ name, decimals, status }),
});

const modalTitle = computed(() => {
    if (mode.value === 'create') return 'Nueva unidad de medida';
    return selected.value ? `${selected.value.code} — ${selected.value.name}` : '';
});

function destroy() {
    const u = selected.value;

    confirmAction({
        title: 'Eliminar unidad de medida',
        message: `La unidad ${u.code} — ${u.name} se elimina de forma definitiva.`,
        confirmLabel: 'Eliminar',
        danger: true,
        onConfirm: () => router.delete(route('units-of-measure.destroy', u.id), { preserveScroll: true }),
    });
}
</script>

<template>
    <Head title="Unidades de medida" />

    <AppLayout title="Unidades de medida">
        <div class="view-toolbar">
            <div class="view-actions">
                <button type="button" class="btn btn-primary" @click="openCreate()"><PlusIcon /> Crear nuevo</button>
            </div>
            <div class="view-filters">
                <input v-model="search" type="search" placeholder="Buscar código o nombre..." aria-label="Buscar unidad de medida">
            </div>
        </div>

        <div v-if="page.props.errors?.unit_of_measure" class="flash flash-error">{{ page.props.errors.unit_of_measure }}</div>

        <p class="hint">
            Los <strong>decimales</strong> definen con qué precisión se puede digitar una cantidad de esta unidad.
            Usá 0 para unidades indivisibles (unidades, cajas) y 2 o 3 para las que se fraccionan (kilos, litros, metros).
        </p>

        <div class="card">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Código</th>
                            <th>Nombre</th>
                            <th class="num">Decimales</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="u in filtered"
                            :key="u.id"
                            class="clickable-row"
                            tabindex="0"
                            @click="openDetail(u)"
                            @keydown.enter="openDetail(u)"
                            @keydown.space.prevent="openDetail(u)"
                        >
                            <td class="code-cell">{{ u.code }}</td>
                            <td data-label="Nombre">{{ u.name }}</td>
                            <td data-label="Decimales" class="num">{{ u.decimals }}</td>
                            <td data-label="Estado">
                                <span class="badge" :class="u.status === 'active' ? 'badge-success' : 'badge-neutral'">
                                    {{ u.status === 'active' ? 'Activa' : 'Inactiva' }}
                                </span>
                            </td>
                        </tr>
                        <tr v-if="!filtered.length">
                            <td colspan="4" class="muted empty-row">
                                {{ unitsOfMeasure.length ? 'Ninguna unidad coincide con la búsqueda.' : 'Todavía no hay unidades de medida registradas.' }}
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

            <dl v-if="selected && mode === 'details'" class="detail-list">
                <div>
                    <dt>Decimales</dt>
                    <dd>{{ selected.decimals }}</dd>
                </div>
            </dl>

            <form v-if="mode === 'create' || (selected && mode === 'edit')" id="uom-form" @submit.prevent="submit">
                <p v-if="mode === 'edit'" class="muted small">El código no se puede cambiar una vez creada la unidad.</p>

                <div v-if="mode === 'create'" class="field">
                    <label for="uom-code">Código</label>
                    <input id="uom-code" v-model="form.code" type="text" maxlength="20" required>
                    <span v-if="form.errors.code" class="error">{{ form.errors.code }}</span>
                </div>

                <div class="field">
                    <label for="uom-name">Nombre</label>
                    <input id="uom-name" v-model="form.name" type="text" required>
                    <span v-if="form.errors.name" class="error">{{ form.errors.name }}</span>
                </div>

                <div class="field-row">
                    <div class="field">
                        <label for="uom-decimals">Decimales</label>
                        <input id="uom-decimals" v-model.number="form.decimals" type="number" min="0" max="6" required>
                        <span v-if="form.errors.decimals" class="error">{{ form.errors.decimals }}</span>
                    </div>
                    <div class="field">
                        <label for="uom-status">Estado</label>
                        <select id="uom-status" v-model="form.status">
                            <option value="active">Activa</option>
                            <option value="inactive">Inactiva</option>
                        </select>
                    </div>
                </div>
            </form>

            <template #actions>
                <template v-if="selected && mode === 'details'">
                    <button type="button" class="btn btn-ghost btn-danger-text" @click="destroy">Eliminar</button>
                    <button type="button" class="btn btn-primary" @click="startEdit"><PencilIcon /> Editar</button>
                </template>
                <template v-else-if="modalOpen">
                    <button type="button" class="btn btn-ghost" @click="cancelForm">Cancelar</button>
                    <button type="submit" form="uom-form" class="btn btn-primary" :disabled="form.processing">
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
