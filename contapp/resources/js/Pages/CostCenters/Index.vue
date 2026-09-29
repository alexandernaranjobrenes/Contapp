<script setup>
import { Head, useForm, router, usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import { PencilIcon, PlusIcon, ScrollTextIcon } from '@lucide/vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import DetailModal from '../../Components/DetailModal.vue';
import LedgerPanel from '../../Components/LedgerPanel.vue';
import { confirmAction } from '../../Utils/confirm';

const props = defineProps({
    costCenters: { type: Array, default: () => [] },
});

const page = usePage();
const search = ref('');
const today = new Date().toISOString().slice(0, 10);

const sorted = computed(() => [...props.costCenters].sort((a, b) => a.code.localeCompare(b.code)));

const filtered = computed(() => {
    const q = search.value.trim().toLowerCase();
    if (! q) return sorted.value;

    return sorted.value.filter((c) =>
        c.code.toLowerCase().includes(q) || c.name.toLowerCase().includes(q)
    );
});

function isVigenteHoy(c) {
    if (! c.is_active) return false;
    if (c.start_date > today) return false;
    if (c.end_date && c.end_date < today) return false;
    return true;
}

function statusLabel(c) {
    if (isVigenteHoy(c)) return 'Vigente';
    return c.is_active ? 'Fuera de vigencia' : 'Inactivo';
}

// Ficha del centro de costo (CLAUDE.md secc. 20) y alta (secc. 21) en un
// solo modal: la fila lo abre en 'details'; «Crear nuevo», en 'create'.
// Crear y editar comparten el formulario (el código solo se elige al crear).
const mode = ref('details'); // 'create' | 'details' | 'edit'
const selectedId = ref(null);

const selected = computed(() => props.costCenters.find((c) => c.id === selectedId.value) ?? null);
const modalOpen = computed(() => mode.value === 'create' || !!selected.value);
const modalTitle = computed(() => {
    if (mode.value === 'create') return 'Nuevo centro de costo';
    return selected.value ? `${selected.value.code} — ${selected.value.name}` : '';
});

const form = useForm({
    code: '',
    name: '',
    start_date: today,
    end_date: '',
    is_active: true,
});

function fillForm(values) {
    form.clearErrors();
    for (const [key, value] of Object.entries(values)) form[key] = value;
}

function openCreate() {
    fillForm({ code: '', name: '', start_date: today, end_date: '', is_active: true });
    selectedId.value = null;
    mode.value = 'create';
}

function openCostCenter(costCenter) {
    selectedId.value = costCenter.id;
    mode.value = 'details';
}

function closeCostCenter() {
    selectedId.value = null;
    mode.value = 'details';
}

function startEdit() {
    const c = selected.value;
    fillForm({ code: c.code, name: c.name, start_date: c.start_date, end_date: c.end_date ?? '', is_active: c.is_active });
    mode.value = 'edit';
}

function cancelForm() {
    if (mode.value === 'create') {
        closeCostCenter();
        return;
    }

    mode.value = 'details';
}

function submit() {
    if (mode.value === 'create') {
        form.post(route('cost-centers.store'), { preserveScroll: true, onSuccess: closeCostCenter });
        return;
    }

    form
        .transform(({ name, start_date, end_date, is_active }) => ({ name, start_date, end_date, is_active }))
        .put(route('cost-centers.update', selected.value.id), {
            preserveScroll: true,
            onSuccess: () => { mode.value = 'details'; },
            onFinish: () => form.transform((data) => data),
        });
}

function destroy() {
    const c = selected.value;

    confirmAction({
        title: 'Eliminar centro de costo',
        message: `El centro de costo ${c.code} — ${c.name} se elimina de forma definitiva.`,
        confirmLabel: 'Eliminar',
        danger: true,
        onConfirm: () => router.delete(route('cost-centers.destroy', c.id), { preserveScroll: true }),
    });
}

const ledger = ref({ open: false, ownerId: null, ownerLabel: '' });

// El mayor se abre en lugar de la ficha: son dos paneles que no conviven.
function showMovements() {
    const c = selected.value;
    closeCostCenter();
    ledger.value = { open: true, ownerId: c.id, ownerLabel: `${c.code} — ${c.name}` };
}

function closeLedger() {
    ledger.value.open = false;
}
</script>

<template>
    <Head title="Centros de costo" />

    <AppLayout title="Centros de costo">
        <div class="view-toolbar">
            <div class="view-filters">
                <input v-model="search" type="search" placeholder="Buscar código o nombre..." aria-label="Buscar centro de costo">
            </div>
            <div class="view-actions">
                <button type="button" class="btn btn-primary" @click="openCreate"><PlusIcon /> Crear nuevo</button>
            </div>
        </div>

        <div v-if="page.props.errors?.cost_center" class="flash flash-error">{{ page.props.errors.cost_center }}</div>

        <p class="hint">
            Un centro de costo recibe montos vía una <strong>norma de reparto</strong> — ya no se elige directo en una
            línea de asiento. Ver "Normas de reparto" para configurar cómo se distribuye cada gasto/costo entre ellos.
        </p>

        <div class="card">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Código</th>
                            <th>Nombre</th>
                            <th>Vigencia</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="c in filtered"
                            :key="c.id"
                            class="clickable-row"
                            tabindex="0"
                            @click="openCostCenter(c)"
                            @keydown.enter="openCostCenter(c)"
                            @keydown.space.prevent="openCostCenter(c)"
                        >
                            <td class="code-cell">{{ c.code }}</td>
                            <td data-label="Nombre">{{ c.name }}</td>
                            <td data-label="Vigencia" class="muted date-range">{{ c.start_date }} — {{ c.end_date ?? 'sin fin' }}</td>
                            <td data-label="Estado">
                                <span class="badge" :class="isVigenteHoy(c) ? 'badge-success' : 'badge-neutral'">{{ statusLabel(c) }}</span>
                            </td>
                        </tr>
                        <tr v-if="!filtered.length">
                            <td colspan="4" class="muted empty-row">
                                {{ costCenters.length ? 'Ningún centro de costo coincide con la búsqueda.' : 'Todavía no hay centros de costo registrados.' }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <DetailModal :open="modalOpen" :title="modalTitle" @close="closeCostCenter">
            <template #badge>
                <span v-if="selected && mode !== 'create'" class="badge" :class="isVigenteHoy(selected) ? 'badge-success' : 'badge-neutral'">
                    {{ statusLabel(selected) }}
                </span>
            </template>

            <dl v-if="selected && mode === 'details'" class="detail-list">
                <div>
                    <dt>Vigente desde</dt>
                    <dd>{{ selected.start_date }}</dd>
                </div>
                <div>
                    <dt>Vigente hasta</dt>
                    <dd>{{ selected.end_date ?? 'Sin fin' }}</dd>
                </div>
                <div>
                    <dt>Activo</dt>
                    <dd>{{ selected.is_active ? 'Sí' : 'No' }}</dd>
                </div>
            </dl>

            <form v-if="mode === 'create' || (selected && mode === 'edit')" id="cost-center-form" @submit.prevent="submit">
                <p v-if="mode === 'edit'" class="muted small">El código no se puede cambiar una vez creado el centro de costo.</p>

                <div v-if="mode === 'create'" class="field">
                    <label for="cc-code">Código</label>
                    <input id="cc-code" v-model="form.code" type="text" maxlength="20" required>
                    <span v-if="form.errors.code" class="error">{{ form.errors.code }}</span>
                </div>

                <div class="field">
                    <label for="cc-name">Nombre</label>
                    <input id="cc-name" v-model="form.name" type="text" required>
                    <span v-if="form.errors.name" class="error">{{ form.errors.name }}</span>
                </div>

                <div class="field-row">
                    <div class="field">
                        <label for="cc-start">Vigente desde</label>
                        <input id="cc-start" v-model="form.start_date" type="date" required>
                        <span v-if="form.errors.start_date" class="error">{{ form.errors.start_date }}</span>
                    </div>
                    <div class="field">
                        <label for="cc-end">Vigente hasta (opcional)</label>
                        <input id="cc-end" v-model="form.end_date" type="date">
                        <span v-if="form.errors.end_date" class="error">{{ form.errors.end_date }}</span>
                    </div>
                </div>

                <label class="check">
                    <input v-model="form.is_active" type="checkbox">
                    Activo
                </label>
            </form>

            <template #actions>
                <template v-if="selected && mode === 'details'">
                    <button type="button" class="btn btn-ghost btn-danger-text" @click="destroy">Eliminar</button>
                    <button type="button" class="btn btn-ghost" @click="showMovements"><ScrollTextIcon /> Ver movimientos</button>
                    <button type="button" class="btn btn-primary" @click="startEdit"><PencilIcon /> Editar</button>
                </template>
                <template v-else-if="modalOpen">
                    <button type="button" class="btn btn-ghost" @click="cancelForm">Cancelar</button>
                    <button type="submit" form="cost-center-form" class="btn btn-primary" :disabled="form.processing">
                        {{ mode === 'create' ? 'Crear' : 'Guardar' }}
                    </button>
                </template>
            </template>
        </DetailModal>

        <LedgerPanel
            :open="ledger.open"
            dimension="cost-center"
            :owner-id="ledger.ownerId"
            :owner-label="ledger.ownerLabel"
            @close="closeLedger"
        />
    </AppLayout>
</template>

<style scoped>
table { font-size: 0.85rem; }
.code-cell { font-variant-numeric: tabular-nums; white-space: nowrap; }
.date-range { font-variant-numeric: tabular-nums; white-space: nowrap; }
</style>
