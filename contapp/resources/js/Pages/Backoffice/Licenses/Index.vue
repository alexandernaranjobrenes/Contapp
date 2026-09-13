<script setup>
import { Head, useForm, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import BackofficeLayout from '../../../Layouts/BackofficeLayout.vue';
import DocumentToolbar from '../../../Components/DocumentToolbar.vue';
import ConfirmModal from '../../../Components/ConfirmModal.vue';

const props = defineProps({
    licenses: { type: Array, default: () => [] },
    categories: { type: Array, default: () => [] },
});

const issueForm = useForm({
    category_id: '',
    expires_at: '',
    notes: '',
});

function onCategoryChange() {
    const category = props.categories.find((c) => c.id === Number(issueForm.category_id));
    if (category && !issueForm.expires_at) {
        const date = new Date();
        date.setMonth(date.getMonth() + category.duration_months);
        issueForm.expires_at = date.toISOString().slice(0, 10);
    }
}

function issue() {
    issueForm.post(route('backoffice.licenses.store'), {
        onSuccess: () => issueForm.reset(),
    });
}

const renewingId = ref(null);
const renewDate = ref('');

function startRenew(license) {
    renewingId.value = license.id;
    renewDate.value = '';
}

function confirmRenew(license) {
    router.post(route('backoffice.licenses.renew', license.id), { expires_at: renewDate.value }, {
        preserveScroll: true,
        onSuccess: () => { renewingId.value = null; },
    });
}

function reactivate(license) {
    router.post(route('backoffice.licenses.reactivate', license.id), {}, { preserveScroll: true });
}

// Suspender/revocar son cortes administrativos reales sobre el acceso de un
// cliente — confirmación explícita antes de dispararlos, no un solo clic.
const pendingAction = ref(null); // { license, action: 'suspend'|'revoke' }
const confirmProcessing = ref(false);

const ACTION_COPY = {
    suspend: { title: 'Suspender licencia', confirmLabel: 'Suspender', message: (l) => `${l.masked_code} y todas sus compañías quedan sin acceso hasta que la reactivés. Es reversible.` },
    revoke: { title: 'Revocar licencia', confirmLabel: 'Revocar', message: (l) => `${l.masked_code} queda dada de baja de forma definitiva. A diferencia de suspender, esto no tiene vuelta atrás.` },
};

function askConfirm(license, action) {
    pendingAction.value = { license, action };
}

function confirmPendingAction() {
    if (!pendingAction.value) return;
    const { license, action } = pendingAction.value;
    const routeName = action === 'suspend' ? 'backoffice.licenses.suspend' : 'backoffice.licenses.revoke';

    confirmProcessing.value = true;
    router.post(route(routeName, license.id), {}, {
        preserveScroll: true,
        onFinish: () => {
            confirmProcessing.value = false;
            pendingAction.value = null;
        },
    });
}

const editingId = ref(null);
const editForm = useForm({
    category_id: '', max_companies: 1, max_admins: 3, max_users: 10, notes: '',
});

function startEdit(license) {
    editingId.value = license.id;
    editForm.category_id = license.category_id;
    editForm.max_companies = license.max_companies;
    editForm.max_admins = license.max_admins;
    editForm.max_users = license.max_users;
    editForm.notes = license.notes ?? '';
}

function confirmEditLicense(license) {
    editForm.put(route('backoffice.licenses.update', license.id), {
        preserveScroll: true,
        onSuccess: () => { editingId.value = null; },
    });
}

const STATUS_LABELS = {
    active: { label: 'Vigente', cls: 'badge-success' },
    expiring_soon: { label: 'Por vencer', cls: 'badge-warning' },
    expired: { label: 'Vencida', cls: 'badge-warning' },
    suspended: { label: 'Suspendida', cls: 'badge-danger' },
    revoked: { label: 'Revocada', cls: 'badge-danger' },
};

function statusOf(license) {
    return STATUS_LABELS[license.display_status] ?? { label: license.display_status, cls: 'badge' };
}
</script>

<template>
    <Head title="Licencias" />

    <BackofficeLayout title="Licencias">
        <DocumentToolbar can-save :saving="issueForm.processing" @save="issue" />

        <div class="card issue-card">
            <h2>Emitir nueva licencia</h2>
            <form class="issue-form" @submit.prevent="issue">
                <div class="field">
                    <label>Categoría</label>
                    <select v-model="issueForm.category_id" required @change="onCategoryChange">
                        <option value="" disabled>Elegir…</option>
                        <option v-for="category in categories" :key="category.id" :value="category.id">
                            {{ category.name }} ({{ category.max_companies }} empresas)
                        </option>
                    </select>
                </div>
                <div class="field">
                    <label>Vence</label>
                    <input v-model="issueForm.expires_at" type="date" required>
                </div>
                <div class="field notes-field">
                    <label>Notas (cliente, convenio...)</label>
                    <input v-model="issueForm.notes" type="text">
                </div>
                <button type="submit" class="btn btn-primary" :disabled="issueForm.processing">Emitir</button>
            </form>
            <p class="hint">
                ¿Falta una categoría? Se administran en
                <a :href="route('backoffice.license-categories.index')">Categorías de licencia</a>.
            </p>
        </div>

        <div class="card">
            <table>
                <thead>
                    <tr>
                        <th>Código</th>
                        <th>Categoría</th>
                        <th>Superusuario</th>
                        <th>Compañías</th>
                        <th>Admins</th>
                        <th>Usuarios</th>
                        <th>Vence</th>
                        <th>Estado</th>
                        <th>Próximo seguimiento</th>
                        <th>Notas</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <template v-for="license in licenses" :key="license.id">
                        <tr v-if="editingId !== license.id">
                            <td class="code-cell">{{ license.masked_code }}</td>
                            <td>{{ license.category?.name ?? '—' }}</td>
                            <td :title="license.superuser?.email ?? ''">{{ license.superuser?.name ?? '—' }}</td>
                            <td class="num">{{ license.companies_count }} / {{ license.max_companies }}</td>
                            <td class="num">{{ license.admins_count }} / {{ license.max_admins }}</td>
                            <td class="num">{{ license.users_count }} / {{ license.max_users }}</td>
                            <td>{{ license.expires_at }}</td>
                            <td><span class="badge" :class="statusOf(license).cls">{{ statusOf(license).label }}</span></td>
                            <td>
                                <span v-if="license.next_pending_follow_up">
                                    {{ license.next_pending_follow_up.next_action_date }} — {{ license.next_pending_follow_up.action_type }}
                                </span>
                                <span v-else class="muted">—</span>
                            </td>
                            <td>{{ license.notes }}</td>
                            <td class="actions-cell">
                                <a :href="route('backoffice.licenses.commercial-profile.show', license.id)" class="btn btn-ghost">Comercial</a>
                                <button type="button" class="btn btn-ghost" @click="startEdit(license)">Editar</button>
                                <button type="button" class="btn btn-ghost" @click="startRenew(license)">Renovar</button>
                                <button
                                    v-if="license.status === 'active'"
                                    type="button"
                                    class="btn btn-ghost"
                                    @click="askConfirm(license, 'suspend')"
                                >Suspender</button>
                                <button
                                    v-if="license.status === 'suspended'"
                                    type="button"
                                    class="btn btn-ghost"
                                    @click="reactivate(license)"
                                >Reactivar</button>
                                <button
                                    v-if="license.status !== 'revoked'"
                                    type="button"
                                    class="btn btn-ghost btn-danger-text"
                                    @click="askConfirm(license, 'revoke')"
                                >Revocar</button>
                            </td>
                        </tr>
                        <tr v-else>
                            <td colspan="11">
                                <form class="edit-bar" @submit.prevent="confirmEditLicense(license)">
                                    <select v-model="editForm.category_id" required>
                                        <option v-for="category in categories" :key="category.id" :value="category.id">{{ category.name }}</option>
                                    </select>
                                    <input v-model="editForm.max_companies" type="number" min="1" required title="Empresas máximas">
                                    <input v-model="editForm.max_admins" type="number" min="0" required title="Administradores máximos">
                                    <input v-model="editForm.max_users" type="number" min="0" required title="Usuarios máximos">
                                    <input v-model="editForm.notes" type="text" placeholder="Notas">
                                    <button type="submit" class="btn btn-primary">Guardar</button>
                                    <button type="button" class="btn btn-ghost" @click="editingId = null">Cancelar</button>
                                </form>
                                <p v-if="editForm.errors.license" class="error">{{ editForm.errors.license }}</p>
                            </td>
                        </tr>
                        <tr v-if="renewingId === license.id">
                            <td colspan="11">
                                <div class="renew-bar">
                                    <label>Nueva fecha de vencimiento:</label>
                                    <input v-model="renewDate" type="date" required>
                                    <button type="button" class="btn btn-primary" @click="confirmRenew(license)">Confirmar</button>
                                    <button type="button" class="btn btn-ghost" @click="renewingId = null">Cancelar</button>
                                </div>
                            </td>
                        </tr>
                    </template>
                    <tr v-if="!licenses.length">
                        <td colspan="11" class="muted empty-row">Todavía no se ha emitido ninguna licencia.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <ConfirmModal
            :open="!!pendingAction"
            :title="pendingAction ? ACTION_COPY[pendingAction.action].title : ''"
            :message="pendingAction ? ACTION_COPY[pendingAction.action].message(pendingAction.license) : ''"
            :confirm-label="pendingAction ? ACTION_COPY[pendingAction.action].confirmLabel : ''"
            danger
            :processing="confirmProcessing"
            @confirm="confirmPendingAction"
            @cancel="pendingAction = null"
        />
    </BackofficeLayout>
</template>

<style scoped>
.issue-card { padding: 1rem 1.1rem; margin-bottom: 1rem; }
.issue-card h2 { font-size: 0.9rem; margin: 0 0 0.75rem; }

.issue-form { display: flex; align-items: flex-end; gap: 1rem; flex-wrap: wrap; }
.issue-form .field { margin-bottom: 0; }
.issue-form input, .issue-form select {
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    padding: 0.4rem 0.55rem;
    font-size: 0.85rem;
}
.notes-field { flex: 1; min-width: 220px; }
.notes-field input { width: 100%; }
.hint { margin: 0.75rem 0 0; font-size: 0.78rem; color: var(--color-text-muted); }
.hint a { color: var(--color-primary); }

table { font-size: 0.85rem; }
th, td { text-align: left; padding: 0.5rem 1rem; border-top: 1px solid var(--color-border); }
.code-cell { font-variant-numeric: tabular-nums; font-weight: 600; }
.muted { color: var(--color-text-muted); }
.empty-row { text-align: center; padding: 1.5rem; }
.actions-cell { display: flex; gap: 0.4rem; flex-wrap: wrap; }

.renew-bar {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    background: var(--color-surface-alt);
    padding: 0.75rem 1rem;
    font-size: 0.82rem;
}
.renew-bar input {
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    padding: 0.35rem 0.5rem;
}

.edit-bar {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    background: var(--color-surface-alt);
    padding: 0.75rem 1rem;
    font-size: 0.82rem;
    flex-wrap: wrap;
}
.edit-bar input, .edit-bar select {
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    padding: 0.35rem 0.5rem;
}
.btn-danger-text { color: var(--color-danger, #c0392b); }
.error { color: var(--color-danger); font-size: 0.78rem; margin: 0.3rem 1rem 0; }
</style>
