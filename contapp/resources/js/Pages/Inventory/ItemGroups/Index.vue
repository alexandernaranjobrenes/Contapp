<script setup>
import { Head, useForm, router, usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AppLayout from '../../../Layouts/AppLayout.vue';
import DocumentToolbar from '../../../Components/DocumentToolbar.vue';

const props = defineProps({
    accounts: { type: Array, default: () => [] },
    accountCategories: { type: Object, default: () => ({}) },
    groupAccounts: { type: Object, default: () => ({}) },
    itemGroups: { type: Array, default: () => [] },
});

const page = usePage();
const search = ref('');

const filtered = computed(() => {
    const q = search.value.trim().toLowerCase();
    const sorted = [...props.itemGroups].sort((a, b) => a.code.localeCompare(b.code));
    if (! q) return sorted;

    return sorted.filter((g) => g.code.toLowerCase().includes(q) || g.name.toLowerCase().includes(q));
});

const creating = ref(false);

const createForm = useForm({ code: '', name: '', status: 'active', accounts: {} });

function openCreate() {
    createForm.reset();
    createForm.accounts = {};
    creating.value = true;
}

function submitCreate() {
    createForm.transform(withAccounts).post(route('item-groups.store'), { onSuccess: () => (creating.value = false), preserveScroll: true });
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

const editForm = useForm({ name: '', status: 'active', accounts: {} });

function openEdit(group) {
    editForm.clearErrors();
    editForm.name = group.name;
    editForm.status = group.status;
    editForm.accounts = { ...(props.groupAccounts[group.id] ?? {}) };
    editing.value = group;
}

function submitEdit() {
    editForm.transform(withAccounts).put(route('item-groups.update', editing.value.id), { onSuccess: () => (editing.value = null), preserveScroll: true });
}

function destroy(group) {
    if (! confirm(`¿Eliminar el grupo ${group.code} — ${group.name}?`)) return;

    router.delete(route('item-groups.destroy', group.id), { preserveScroll: true });
}
</script>

<template>
    <Head title="Grupos de artículos" />

    <AppLayout title="Grupos de artículos">
        <template #actions>
            <input v-model="search" type="search" placeholder="Buscar código o nombre..." class="search-input">
        </template>

        <DocumentToolbar can-create @new="openCreate()" />

        <div v-if="page.props.errors?.item_group" class="flash flash-error">{{ page.props.errors.item_group }}</div>

        <p class="hint">
            Además de clasificar el catálogo, el grupo es uno de los niveles de la <strong>determinación de cuentas</strong>:
            permite que todos los artículos de un grupo compartan las mismas cuentas de inventario, costo de ventas y ajuste
            sin configurarlas uno por uno.
        </p>

        <div class="card">
            <div class="card-header">
                <span class="muted">{{ filtered.length }} grupo(s)</span>
                <button type="button" class="btn btn-primary" @click="openCreate()">+ Nuevo grupo</button>
            </div>

            <table>
                <thead>
                    <tr>
                        <th>Código</th>
                        <th>Nombre</th>
                        <th>Artículos</th>
                        <th>Estado</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="g in filtered" :key="g.id">
                        <td class="num code-cell">{{ g.code }}</td>
                        <td>{{ g.name }}</td>
                        <td class="num">{{ g.items_count }}</td>
                        <td>
                            <span class="badge" :class="g.status === 'active' ? 'badge-success' : 'badge-neutral'">
                                {{ g.status === 'active' ? 'Activo' : 'Inactivo' }}
                            </span>
                        </td>
                        <td class="actions-cell">
                            <button type="button" class="btn btn-ghost" @click="openEdit(g)">Editar</button>
                            <button type="button" class="btn btn-ghost" @click="destroy(g)">Eliminar</button>
                        </td>
                    </tr>
                    <tr v-if="!filtered.length">
                        <td colspan="5" class="muted empty-row">Todavía no hay grupos de artículos registrados.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="creating" class="modal-backdrop" @click.self="creating = false">
            <form class="modal-card card" @submit.prevent="submitCreate">
                <h2>Nuevo grupo de artículos</h2>

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
                    <label>Estado</label>
                    <select v-model="createForm.status">
                        <option value="active">Activo</option>
                        <option value="inactive">Inactivo</option>
                    </select>
                </div>

                <h3 class="section-heading">Cuentas contables</h3>
                <span class="hint small">Lo que se deje vacío se hereda del <strong>almacén</strong> y después de la <strong>compañía</strong>. Un artículo que tenga su propia cuenta le gana a la del grupo.</span>

                <div v-for="(label, key) in accountCategories" :key="key" class="field">
                    <label>{{ label }}</label>
                    <select v-model="createForm.accounts[key]">
                        <option value="">Heredar del almacén o la compañía</option>
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
                <p class="muted small">El código no se puede cambiar una vez creado el grupo.</p>

                <div class="field">
                    <label>Nombre</label>
                    <input v-model="editForm.name" type="text" required>
                    <span v-if="editForm.errors.name" class="error">{{ editForm.errors.name }}</span>
                </div>

                <div class="field">
                    <label>Estado</label>
                    <select v-model="editForm.status">
                        <option value="active">Activo</option>
                        <option value="inactive">Inactivo</option>
                    </select>
                </div>

                <h3 class="section-heading">Cuentas contables</h3>
                <span class="hint small">Lo que se deje vacío se hereda del <strong>almacén</strong> y después de la <strong>compañía</strong>. Un artículo que tenga su propia cuenta le gana a la del grupo.</span>

                <div v-for="(label, key) in accountCategories" :key="key" class="field">
                    <label>{{ label }}</label>
                    <select v-model="editForm.accounts[key]">
                        <option value="">Heredar del almacén o la compañía</option>
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
.modal-actions { display: flex; gap: 0.6rem; }
.section-heading { font-size: 0.82rem; text-transform: uppercase; letter-spacing: 0.04em; color: var(--color-text-muted); margin: 1rem 0 0.25rem; }
.hint { font-size: 0.76rem; color: var(--color-text-muted); }
.small { font-size: 0.74rem; }
</style>
