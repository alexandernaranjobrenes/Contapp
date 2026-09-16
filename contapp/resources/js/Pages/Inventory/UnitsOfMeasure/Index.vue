<script setup>
import { Head, useForm, router, usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AppLayout from '../../../Layouts/AppLayout.vue';
import DocumentToolbar from '../../../Components/DocumentToolbar.vue';

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

const creating = ref(false);

const createForm = useForm({ code: '', name: '', decimals: 2, status: 'active' });

function openCreate() {
    createForm.reset();
    creating.value = true;
}

function submitCreate() {
    createForm.post(route('units-of-measure.store'), { onSuccess: () => (creating.value = false), preserveScroll: true });
}

const editing = ref(null);

const editForm = useForm({ name: '', decimals: 2, status: 'active' });

function openEdit(uom) {
    editForm.clearErrors();
    editForm.name = uom.name;
    editForm.decimals = uom.decimals;
    editForm.status = uom.status;
    editing.value = uom;
}

function submitEdit() {
    editForm.put(route('units-of-measure.update', editing.value.id), { onSuccess: () => (editing.value = null), preserveScroll: true });
}

function destroy(uom) {
    if (! confirm(`¿Eliminar la unidad de medida ${uom.code} — ${uom.name}?`)) return;

    router.delete(route('units-of-measure.destroy', uom.id), { preserveScroll: true });
}
</script>

<template>
    <Head title="Unidades de medida" />

    <AppLayout title="Unidades de medida">
        <template #actions>
            <input v-model="search" type="search" placeholder="Buscar código o nombre..." class="search-input">
        </template>

        <DocumentToolbar can-create @new="openCreate()" />

        <div v-if="page.props.errors?.unit_of_measure" class="flash flash-error">{{ page.props.errors.unit_of_measure }}</div>

        <p class="hint">
            Los <strong>decimales</strong> definen con qué precisión se puede digitar una cantidad de esta unidad.
            Usá 0 para unidades indivisibles (unidades, cajas) y 2 o 3 para las que se fraccionan (kilos, litros, metros).
        </p>

        <div class="card">
            <div class="card-header">
                <span class="muted">{{ filtered.length }} unidad(es) de medida</span>
                <button type="button" class="btn btn-primary" @click="openCreate()">+ Nueva unidad</button>
            </div>

            <table>
                <thead>
                    <tr>
                        <th>Código</th>
                        <th>Nombre</th>
                        <th>Decimales</th>
                        <th>Estado</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="u in filtered" :key="u.id">
                        <td class="num code-cell">{{ u.code }}</td>
                        <td>{{ u.name }}</td>
                        <td class="num">{{ u.decimals }}</td>
                        <td>
                            <span class="badge" :class="u.status === 'active' ? 'badge-success' : 'badge-neutral'">
                                {{ u.status === 'active' ? 'Activa' : 'Inactiva' }}
                            </span>
                        </td>
                        <td class="actions-cell">
                            <button type="button" class="btn btn-ghost" @click="openEdit(u)">Editar</button>
                            <button type="button" class="btn btn-ghost" @click="destroy(u)">Eliminar</button>
                        </td>
                    </tr>
                    <tr v-if="!filtered.length">
                        <td colspan="5" class="muted empty-row">Todavía no hay unidades de medida registradas.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="creating" class="modal-backdrop" @click.self="creating = false">
            <form class="modal-card card" @submit.prevent="submitCreate">
                <h2>Nueva unidad de medida</h2>

                <div class="field">
                    <label>Código</label>
                    <input v-model="createForm.code" type="text" maxlength="20" required>
                    <span v-if="createForm.errors.code" class="error">{{ createForm.errors.code }}</span>
                </div>

                <div class="field">
                    <label>Nombre</label>
                    <input v-model="createForm.name" type="text" required>
                    <span v-if="createForm.errors.name" class="error">{{ createForm.errors.name }}</span>
                </div>

                <div class="grid-2">
                    <div class="field">
                        <label>Decimales</label>
                        <input v-model.number="createForm.decimals" type="number" min="0" max="6" required>
                        <span v-if="createForm.errors.decimals" class="error">{{ createForm.errors.decimals }}</span>
                    </div>
                    <div class="field">
                        <label>Estado</label>
                        <select v-model="createForm.status">
                            <option value="active">Activa</option>
                            <option value="inactive">Inactiva</option>
                        </select>
                    </div>
                </div>

                <div class="modal-actions">
                    <button type="submit" class="btn btn-primary" :disabled="createForm.processing">Guardar</button>
                    <button type="button" class="btn btn-ghost" @click="creating = false">Cancelar</button>
                </div>
            </form>
        </div>

        <div v-if="editing" class="modal-backdrop" @click.self="editing = null">
            <form class="modal-card card" @submit.prevent="submitEdit">
                <h2>Editar {{ editing.code }}</h2>
                <p class="muted small">El código no se puede cambiar una vez creada la unidad.</p>

                <div class="field">
                    <label>Nombre</label>
                    <input v-model="editForm.name" type="text" required>
                    <span v-if="editForm.errors.name" class="error">{{ editForm.errors.name }}</span>
                </div>

                <div class="grid-2">
                    <div class="field">
                        <label>Decimales</label>
                        <input v-model.number="editForm.decimals" type="number" min="0" max="6" required>
                        <span v-if="editForm.errors.decimals" class="error">{{ editForm.errors.decimals }}</span>
                    </div>
                    <div class="field">
                        <label>Estado</label>
                        <select v-model="editForm.status">
                            <option value="active">Activa</option>
                            <option value="inactive">Inactiva</option>
                        </select>
                    </div>
                </div>

                <div class="modal-actions">
                    <button type="submit" class="btn btn-primary" :disabled="editForm.processing">Guardar</button>
                    <button type="button" class="btn btn-ghost" @click="editing = null">Cancelar</button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>

<style scoped>
.search-input {
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    padding: 0.4rem 0.6rem;
    font-size: 0.82rem;
    width: 220px;
}

.card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.85rem 1.1rem;
    border-bottom: 1px solid var(--color-border);
}

.flash { margin-bottom: 0.75rem; padding: 0.6rem 0.9rem; border-radius: var(--radius-sm); font-size: 0.85rem; }
.flash-error { background: var(--color-danger-soft); color: var(--color-danger); }

.hint { font-size: 0.82rem; color: var(--color-text-muted); margin: -0.5rem 0 1rem; }

table { font-size: 0.85rem; width: 100%; }
th, td { text-align: left; padding: 0.5rem 1rem; border-top: 1px solid var(--color-border); white-space: nowrap; }
.code-cell { font-variant-numeric: tabular-nums; }
.num { font-variant-numeric: tabular-nums; }
.muted { color: var(--color-text-muted); }
.small { font-size: 0.76rem; }
.empty-row { text-align: center; padding: 1.5rem; white-space: normal; }
.actions-cell { display: flex; gap: 0.4rem; }

.modal-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(11, 31, 58, 0.45);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 50;
    padding: 1rem;
}

.modal-card { width: 460px; max-width: 100%; max-height: 90vh; overflow-y: auto; padding: 1.5rem; }
.modal-card h2 { font-size: 1rem; margin: 0 0 0.5rem; }

.grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 0 1rem; }

.field { display: flex; flex-direction: column; gap: 0.2rem; margin-bottom: 0.75rem; }
.field label { font-size: 0.78rem; color: var(--color-text-muted); }

.field input, .field select {
    width: 100%;
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    padding: 0.45rem 0.6rem;
    font-size: 0.85rem;
    color: var(--color-text);
}

.error { color: var(--color-danger); font-size: 0.76rem; }
.modal-actions { display: flex; gap: 0.6rem; }
</style>
