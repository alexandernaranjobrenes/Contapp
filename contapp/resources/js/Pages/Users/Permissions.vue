<script setup>
import { Head, useForm, Link } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';

const props = defineProps({
    targetUser: { type: Object, required: true },
    modules: { type: Array, default: () => [] },
});

const LEVELS = ['none', 'read', 'read_write'];
const LEVEL_LABELS = { none: 'Sin acceso', read: 'Lectura', read_write: 'Lectura/escritura' };

function levelRank(level) {
    return LEVELS.indexOf(level);
}

function selectableLevels(module) {
    return LEVELS.filter((level) => levelRank(level) <= levelRank(module.max_access_level));
}

const form = useForm({
    permissions: Object.fromEntries(props.modules.map((m) => [m.id, m.current_access_level])),
});

function submit() {
    form.put(route('users.permissions.update', props.targetUser.id));
}
</script>

<template>
    <Head title="Editar permisos" />

    <AppLayout title="Editar permisos">
        <template #actions>
            <Link :href="route('users.index')" class="btn btn-ghost">← Volver</Link>
        </template>

        <p class="user-line">Permisos de <strong>{{ targetUser.name }}</strong> ({{ targetUser.email }})</p>

        <div class="card form-card">
            <form @submit.prevent="submit">
                <p class="hint">Solo podés otorgar hasta el nivel de acceso que vos mismo tenés en cada módulo.</p>
                <table class="permissions-table">
                    <thead>
                        <tr><th>Módulo</th><th>Nivel de acceso</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="module in modules" :key="module.id">
                            <td>{{ module.name }}</td>
                            <td>
                                <select v-model="form.permissions[module.id]">
                                    <option v-for="level in selectableLevels(module)" :key="level" :value="level">{{ LEVEL_LABELS[level] }}</option>
                                </select>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <p v-if="form.errors.permissions" class="error">{{ form.errors.permissions }}</p>

                <button type="submit" class="btn btn-primary" :disabled="form.processing">Guardar</button>
            </form>
        </div>
    </AppLayout>
</template>

<style scoped>
.user-line { color: var(--color-text-muted); font-size: 0.85rem; margin-bottom: 1rem; }
.form-card { padding: 1.25rem 1.5rem; max-width: 640px; }
.hint { font-size: 0.78rem; color: var(--color-text-muted); margin: 0 0 0.6rem; }
.permissions-table { width: 100%; font-size: 0.85rem; margin-bottom: 0.75rem; }
.permissions-table th, .permissions-table td { text-align: left; padding: 0.4rem 0.2rem; border-top: 1px solid var(--color-border); }
select {
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    padding: 0.45rem 0.6rem;
    font-size: 0.88rem;
}
.error { color: var(--color-danger); font-size: 0.78rem; margin: 0.2rem 0 0; }
</style>
