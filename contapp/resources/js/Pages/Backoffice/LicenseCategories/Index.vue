<script setup>
import { Head, useForm, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import BackofficeLayout from '../../../Layouts/BackofficeLayout.vue';
import DocumentToolbar from '../../../Components/DocumentToolbar.vue';

defineProps({
    categories: { type: Array, default: () => [] },
});

const createForm = useForm({
    name: '',
    max_companies: 1,
    max_admins: 3,
    max_users: 10,
    duration_months: 12,
    description: '',
    is_active: true,
});

function create() {
    createForm.post(route('backoffice.license-categories.store'), {
        onSuccess: () => createForm.reset(),
    });
}

const editingId = ref(null);
const editForm = useForm({
    name: '', max_companies: 1, max_admins: 3, max_users: 10, duration_months: 12, description: '', is_active: true,
});

function startEdit(category) {
    editingId.value = category.id;
    editForm.name = category.name;
    editForm.max_companies = category.max_companies;
    editForm.max_admins = category.max_admins;
    editForm.max_users = category.max_users;
    editForm.duration_months = category.duration_months;
    editForm.description = category.description ?? '';
    editForm.is_active = category.is_active;
}

function confirmEdit(category) {
    editForm.put(route('backoffice.license-categories.update', category.id), {
        preserveScroll: true,
        onSuccess: () => { editingId.value = null; },
    });
}

function destroy(category) {
    router.delete(route('backoffice.license-categories.destroy', category.id), { preserveScroll: true });
}
</script>

<template>
    <Head title="Categorías de licencia" />

    <BackofficeLayout title="Categorías de licencia">
        <DocumentToolbar can-save :saving="createForm.processing" @save="create" />

        <div class="card issue-card">
            <h2>Nueva categoría</h2>
            <form class="issue-form" @submit.prevent="create">
                <div class="field">
                    <label>Nombre</label>
                    <input v-model="createForm.name" type="text" required placeholder="Básica, Profesional...">
                </div>
                <div class="field">
                    <label>Empresas máximas</label>
                    <input v-model="createForm.max_companies" type="number" min="1" required>
                </div>
                <div class="field">
                    <label>Administradores máximos</label>
                    <input v-model="createForm.max_admins" type="number" min="0" required>
                </div>
                <div class="field">
                    <label>Usuarios máximos</label>
                    <input v-model="createForm.max_users" type="number" min="0" required>
                </div>
                <div class="field">
                    <label>Duración (meses)</label>
                    <input v-model="createForm.duration_months" type="number" min="1" required>
                </div>
                <div class="field notes-field">
                    <label>Descripción</label>
                    <input v-model="createForm.description" type="text">
                </div>
                <button type="submit" class="btn btn-primary" :disabled="createForm.processing">Crear</button>
            </form>
        </div>

        <div class="card">
            <table>
                <thead>
                    <tr>
                        <th>Nombre</th>
                        <th>Empresas</th>
                        <th>Admins</th>
                        <th>Usuarios</th>
                        <th>Duración</th>
                        <th>Descripción</th>
                        <th>Licencias emitidas</th>
                        <th>Activa</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <template v-for="category in categories" :key="category.id">
                        <tr v-if="editingId !== category.id">
                            <td>{{ category.name }}</td>
                            <td class="num">{{ category.max_companies }}</td>
                            <td class="num">{{ category.max_admins }}</td>
                            <td class="num">{{ category.max_users }}</td>
                            <td class="num">{{ category.duration_months }} m</td>
                            <td>{{ category.description }}</td>
                            <td class="num">{{ category.licenses_count }}</td>
                            <td><span class="badge" :class="category.is_active ? 'badge-success' : 'badge-danger'">{{ category.is_active ? 'Sí' : 'No' }}</span></td>
                            <td class="actions-cell">
                                <button type="button" class="btn btn-ghost" @click="startEdit(category)">Editar</button>
                                <button
                                    v-if="!category.licenses_count"
                                    type="button"
                                    class="btn btn-ghost"
                                    @click="destroy(category)"
                                >Eliminar</button>
                            </td>
                        </tr>
                        <tr v-else>
                            <td colspan="9">
                                <form class="edit-bar" @submit.prevent="confirmEdit(category)">
                                    <input v-model="editForm.name" type="text" required>
                                    <input v-model="editForm.max_companies" type="number" min="1" required title="Empresas máximas">
                                    <input v-model="editForm.max_admins" type="number" min="0" required title="Administradores máximos">
                                    <input v-model="editForm.max_users" type="number" min="0" required title="Usuarios máximos">
                                    <input v-model="editForm.duration_months" type="number" min="1" required>
                                    <input v-model="editForm.description" type="text" placeholder="Descripción">
                                    <label class="check-label"><input v-model="editForm.is_active" type="checkbox"> Activa</label>
                                    <button type="submit" class="btn btn-primary">Guardar</button>
                                    <button type="button" class="btn btn-ghost" @click="editingId = null">Cancelar</button>
                                </form>
                            </td>
                        </tr>
                    </template>
                    <tr v-if="!categories.length">
                        <td colspan="9" class="muted empty-row">Todavía no hay categorías de licencia.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </BackofficeLayout>
</template>

<style scoped>
.issue-card { padding: 1rem 1.1rem; margin-bottom: 1rem; }
.issue-card h2 { font-size: 0.9rem; margin: 0 0 0.75rem; }

.issue-form { display: flex; align-items: flex-end; gap: 1rem; flex-wrap: wrap; }
.issue-form .field { margin-bottom: 0; }
.issue-form input {
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    padding: 0.4rem 0.55rem;
    font-size: 0.85rem;
}
.notes-field { flex: 1; min-width: 220px; }
.notes-field input { width: 100%; }

table { font-size: 0.85rem; }
th, td { text-align: left; padding: 0.5rem 1rem; border-top: 1px solid var(--color-border); }
.muted { color: var(--color-text-muted); }
.empty-row { text-align: center; padding: 1.5rem; }
.actions-cell { display: flex; gap: 0.4rem; }

.edit-bar {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    background: var(--color-surface-alt);
    padding: 0.75rem 1rem;
    font-size: 0.82rem;
    flex-wrap: wrap;
}
.edit-bar input {
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    padding: 0.35rem 0.5rem;
}
.check-label { display: flex; align-items: center; gap: 0.3rem; }
</style>
