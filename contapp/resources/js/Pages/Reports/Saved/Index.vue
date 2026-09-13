<script setup>
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '../../../Layouts/AppLayout.vue';

defineProps({
    savedReports: { type: Array, default: () => [] },
});

function run(report) {
    router.post(route('saved-reports.invoke', report.id));
}

const editingId = ref(null);
const editForm = useForm({ name: '', is_shared: false });

function startEdit(report) {
    editingId.value = report.id;
    editForm.name = report.name;
    editForm.is_shared = report.is_shared;
}

function confirmEdit(report) {
    editForm.put(route('saved-reports.update', report.id), {
        preserveScroll: true,
        onSuccess: () => { editingId.value = null; },
    });
}

function destroy(report) {
    if (! confirm(`¿Eliminar el reporte guardado "${report.name}"? Esta acción no se puede deshacer.`)) return;

    router.delete(route('saved-reports.destroy', report.id), { preserveScroll: true });
}
</script>

<template>
    <Head title="Reportes guardados" />

    <AppLayout title="Reportes guardados">
        <div class="card">
            <table>
                <thead>
                    <tr>
                        <th>Nombre</th>
                        <th>Reporte</th>
                        <th>Visibilidad</th>
                        <th>Creado por</th>
                        <th>Última ejecución</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <template v-for="report in savedReports" :key="report.id">
                        <tr v-if="editingId !== report.id">
                            <td>
                                {{ report.name }}
                                <span v-if="report.is_stale" class="badge badge-warning" title="Los parámetros guardados ya no calzan con este reporte">Desactualizado</span>
                            </td>
                            <td>{{ report.report_label }}</td>
                            <td>
                                <span class="badge" :class="report.is_shared ? 'badge-success' : 'badge-neutral'">
                                    {{ report.is_shared ? 'Compartido' : 'Privado' }}
                                </span>
                            </td>
                            <td>{{ report.created_by }}</td>
                            <td>{{ report.last_run_at ?? '—' }}</td>
                            <td class="actions-cell">
                                <button type="button" class="btn btn-primary" :disabled="report.is_stale" @click="run(report)">Ejecutar</button>
                                <button v-if="report.is_mine" type="button" class="btn btn-ghost" @click="startEdit(report)">Editar</button>
                                <button type="button" class="btn btn-ghost" @click="destroy(report)">Eliminar</button>
                            </td>
                        </tr>
                        <tr v-else>
                            <td colspan="6">
                                <form class="edit-bar" @submit.prevent="confirmEdit(report)">
                                    <input v-model="editForm.name" type="text" required>
                                    <label class="check-label"><input v-model="editForm.is_shared" type="checkbox"> Compartido con mi compañía</label>
                                    <button type="submit" class="btn btn-primary">Guardar</button>
                                    <button type="button" class="btn btn-ghost" @click="editingId = null">Cancelar</button>
                                </form>
                            </td>
                        </tr>
                    </template>
                    <tr v-if="!savedReports.length">
                        <td colspan="6" class="muted empty-row">
                            Todavía no guardaste ninguna configuración. Desde cualquier reporte, usá "Guardar configuración" en la barra de acciones.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>

<style scoped>
table { font-size: 0.85rem; }
th, td { text-align: left; padding: 0.5rem 1rem; border-top: 1px solid var(--color-border); }
.muted { color: var(--color-text-muted); }
.empty-row { text-align: center; padding: 1.5rem; }
.actions-cell { display: flex; gap: 0.4rem; flex-wrap: wrap; }
.badge-warning { background: var(--color-warning-soft); color: var(--color-warning); margin-left: 0.4rem; }

.edit-bar {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    background: var(--color-surface-alt);
    padding: 0.75rem 1rem;
    font-size: 0.82rem;
    flex-wrap: wrap;
}
.edit-bar input[type="text"] {
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    padding: 0.35rem 0.5rem;
}
.check-label { display: flex; align-items: center; gap: 0.3rem; }
</style>
