<script setup>
import { Head, Link, useForm, router, usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AppLayout from '../../../Layouts/AppLayout.vue';
import DocumentToolbar from '../../../Components/DocumentToolbar.vue';

const props = defineProps({
    accounts: { type: Array, default: () => [] },
    accountCategories: { type: Object, default: () => ({}) },
    warehouseAccounts: { type: Object, default: () => ({}) },
    warehouses: { type: Array, default: () => [] },
});

const page = usePage();
const search = ref('');

const filtered = computed(() => {
    const q = search.value.trim().toLowerCase();
    const sorted = [...props.warehouses].sort((a, b) => a.code.localeCompare(b.code));
    if (! q) return sorted;

    return sorted.filter((w) => w.code.toLowerCase().includes(q) || w.name.toLowerCase().includes(q));
});

const creating = ref(false);

const createForm = useForm({ code: '', name: '', address: '', is_default: false, uses_bins: false, status: 'active', accounts: {} });

function openCreate() {
    createForm.reset();
    createForm.accounts = {};
    creating.value = true;
}

function submitCreate() {
    createForm.transform(withAccounts).post(route('warehouses.store'), { onSuccess: () => (creating.value = false), preserveScroll: true });
}

// '' significa "sin cuenta propia": viaja como null para que el servidor
// borre la regla y vuelva a heredar del nivel de arriba.
function withAccounts(data) {
    return {
        ...data,
        accounts: Object.fromEntries(
            Object.entries(data.accounts ?? {}).map(([k, v]) => [k, v === '' ? null : v])
        ),
    };
}

const editing = ref(null);

const editForm = useForm({ name: '', address: '', is_default: false, uses_bins: false, status: 'active', accounts: {} });

function openEdit(warehouse) {
    editForm.clearErrors();
    editForm.name = warehouse.name;
    editForm.address = warehouse.address ?? '';
    editForm.is_default = warehouse.is_default;
    editForm.uses_bins = warehouse.uses_bins;
    editForm.status = warehouse.status;
    editForm.accounts = { ...(props.warehouseAccounts[warehouse.id] ?? {}) };
    editing.value = warehouse;
}

function submitEdit() {
    editForm.transform(withAccounts).put(route('warehouses.update', editing.value.id), { onSuccess: () => (editing.value = null), preserveScroll: true });
}

function destroy(warehouse) {
    if (! confirm(`¿Eliminar el almacén ${warehouse.code} — ${warehouse.name}?`)) return;

    router.delete(route('warehouses.destroy', warehouse.id), { preserveScroll: true });
}
</script>

<template>
    <Head title="Almacenes" />

    <AppLayout title="Almacenes">
        <template #actions>
            <input v-model="search" type="search" placeholder="Buscar código o nombre..." class="search-input">
        </template>

        <DocumentToolbar can-create @new="openCreate()" />

        <div v-if="page.props.errors?.warehouse" class="flash flash-error">{{ page.props.errors.warehouse }}</div>

        <p class="hint">
            El almacén controla <strong>dónde está</strong> la existencia, no cuánto vale: el costo promedio es global por
            artículo. Un traslado entre almacenes no afecta resultados, y solo genera asiento si cada almacén tiene su
            propia cuenta de inventario en la determinación de cuentas.
        </p>

        <div class="card">
            <div class="card-header">
                <span class="muted">{{ filtered.length }} almacén(es)</span>
                <button type="button" class="btn btn-primary" @click="openCreate()">+ Nuevo almacén</button>
            </div>

            <table>
                <thead>
                    <tr>
                        <th>Código</th>
                        <th>Nombre</th>
                        <th>Dirección</th>
                        <th>Estado</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="w in filtered" :key="w.id">
                        <td class="num code-cell">{{ w.code }}</td>
                        <td>
                            {{ w.name }}
                            <span v-if="w.is_default" class="badge badge-warning">Por defecto</span>
                            <span v-if="w.uses_bins" class="badge badge-neutral">Con ubicaciones</span>
                        </td>
                        <td class="muted small">{{ w.address || '—' }}</td>
                        <td>
                            <span class="badge" :class="w.status === 'active' ? 'badge-success' : 'badge-neutral'">
                                {{ w.status === 'active' ? 'Activo' : 'Inactivo' }}
                            </span>
                        </td>
                        <td class="actions-cell">
                            <Link :href="route('warehouse-bins.index', w.id)" class="btn btn-ghost">Ubicaciones</Link>
                            <button type="button" class="btn btn-ghost" @click="openEdit(w)">Editar</button>
                            <button type="button" class="btn btn-ghost" @click="destroy(w)">Eliminar</button>
                        </td>
                    </tr>
                    <tr v-if="!filtered.length">
                        <td colspan="5" class="muted empty-row">Todavía no hay almacenes registrados.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="creating" class="modal-backdrop" @click.self="creating = false">
            <form class="modal-card card" @submit.prevent="submitCreate">
                <h2>Nuevo almacén</h2>

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

                <div class="field">
                    <label>Dirección (opcional)</label>
                    <input v-model="createForm.address" type="text">
                    <span v-if="createForm.errors.address" class="error">{{ createForm.errors.address }}</span>
                </div>

                <div class="field">
                    <label>Estado</label>
                    <select v-model="createForm.status">
                        <option value="active">Activo</option>
                        <option value="inactive">Inactivo</option>
                    </select>
                </div>

                <label class="check-row">
                    <input v-model="createForm.is_default" type="checkbox">
                    Almacén por defecto (desmarca al actual)
                </label>

                <label class="check-row">
                    <input v-model="createForm.uses_bins" type="checkbox">
                    Maneja ubicaciones (cada movimiento deberá indicar en cuál)
                </label>

                <h3 class="section-heading">Cuentas contables</h3>
                <span class="hint small">Lo que se deje vacío se hereda de la <strong>compañía</strong>. El artículo y su grupo le ganan a lo que se ponga acá.</span>

                <div v-for="(label, key) in accountCategories" :key="key" class="field">
                    <label>{{ label }}</label>
                    <select v-model="createForm.accounts[key]">
                        <option value="">Heredar de la compañía</option>
                        <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.label }}</option>
                    </select>
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
                <p class="muted small">El código no se puede cambiar una vez creado el almacén.</p>

                <div class="field">
                    <label>Nombre</label>
                    <input v-model="editForm.name" type="text" required>
                    <span v-if="editForm.errors.name" class="error">{{ editForm.errors.name }}</span>
                </div>

                <div class="field">
                    <label>Dirección (opcional)</label>
                    <input v-model="editForm.address" type="text">
                    <span v-if="editForm.errors.address" class="error">{{ editForm.errors.address }}</span>
                </div>

                <div class="field">
                    <label>Estado</label>
                    <select v-model="editForm.status">
                        <option value="active">Activo</option>
                        <option value="inactive">Inactivo</option>
                    </select>
                </div>

                <label class="check-row">
                    <input v-model="editForm.is_default" type="checkbox">
                    Almacén por defecto (desmarca al actual)
                </label>

                <h3 class="section-heading">Cuentas contables</h3>
                <span class="hint small">Lo que se deje vacío se hereda de la <strong>compañía</strong>. El artículo y su grupo le ganan a lo que se ponga acá.</span>

                <div v-for="(label, key) in accountCategories" :key="key" class="field">
                    <label>{{ label }}</label>
                    <select v-model="editForm.accounts[key]">
                        <option value="">Heredar de la compañía</option>
                        <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.label }}</option>
                    </select>
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

.check-row {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.85rem;
    margin: 0.5rem 0 1rem;
}

.modal-actions { display: flex; gap: 0.6rem; }
.section-heading { font-size: 0.82rem; text-transform: uppercase; letter-spacing: 0.04em; color: var(--color-text-muted); margin: 1rem 0 0.25rem; }
.hint { font-size: 0.76rem; color: var(--color-text-muted); }
.small { font-size: 0.74rem; }
</style>
