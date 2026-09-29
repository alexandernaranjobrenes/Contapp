<script setup>
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { PencilIcon, PlayIcon } from '@lucide/vue';
import AppLayout from '../../../Layouts/AppLayout.vue';
import DetailModal from '../../../Components/DetailModal.vue';
import { confirmAction } from '../../../Utils/confirm';

const props = defineProps({
    savedReports: { type: Array, default: () => [] },
});

// Ficha del reporte guardado (CLAUDE.md secc. 20): ejecutarlo, editarlo y
// eliminarlo se hace desde ahí. Editar ocurre en el mismo modal.
const selectedId = ref(null);
const mode = ref('details'); // 'details' | 'edit'

const selected = computed(() => props.savedReports.find((r) => r.id === selectedId.value) ?? null);

function openReport(report) {
    selectedId.value = report.id;
    mode.value = 'details';
}

function closeReport() {
    selectedId.value = null;
    mode.value = 'details';
}

function run() {
    router.post(route('saved-reports.invoke', selected.value.id));
}

const editForm = useForm({ name: '', is_shared: false });

function startEdit() {
    editForm.clearErrors();
    editForm.name = selected.value.name;
    editForm.is_shared = selected.value.is_shared;
    mode.value = 'edit';
}

function confirmEdit() {
    editForm.put(route('saved-reports.update', selected.value.id), {
        preserveScroll: true,
        onSuccess: () => { mode.value = 'details'; },
    });
}

function destroy() {
    const report = selected.value;

    confirmAction({
        title: 'Eliminar reporte guardado',
        message: `La configuración «${report.name}» se elimina de forma definitiva.`,
        confirmLabel: 'Eliminar',
        danger: true,
        onConfirm: () => router.delete(route('saved-reports.destroy', report.id), { preserveScroll: true }),
    });
}
</script>

<template>
    <Head title="Reportes guardados" />

    <AppLayout title="Reportes guardados">
        <div class="card">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Nombre</th>
                            <th>Reporte</th>
                            <th>Visibilidad</th>
                            <th>Última ejecución</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="report in savedReports"
                            :key="report.id"
                            class="clickable-row"
                            tabindex="0"
                            @click="openReport(report)"
                            @keydown.enter="openReport(report)"
                            @keydown.space.prevent="openReport(report)"
                        >
                            <td>
                                <span class="name-cell">
                                    {{ report.name }}
                                    <span v-if="report.is_stale" class="badge badge-warning">Desactualizado</span>
                                </span>
                            </td>
                            <td data-label="Reporte">{{ report.report_label }}</td>
                            <td data-label="Visibilidad">
                                <span class="badge" :class="report.is_shared ? 'badge-success' : 'badge-neutral'">
                                    {{ report.is_shared ? 'Compartido' : 'Privado' }}
                                </span>
                            </td>
                            <td data-label="Última ejecución">{{ report.last_run_at ?? '—' }}</td>
                        </tr>
                        <tr v-if="!savedReports.length">
                            <td colspan="4" class="muted empty-row">
                                Todavía no guardaste ninguna configuración. Desde cualquier reporte, usá "Guardar configuración", arriba de la tabla.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <DetailModal :open="!!selected" :title="selected?.name ?? ''" @close="closeReport">
            <template #badge>
                <span v-if="selected" class="badge" :class="selected.is_shared ? 'badge-success' : 'badge-neutral'">
                    {{ selected.is_shared ? 'Compartido' : 'Privado' }}
                </span>
            </template>

            <template v-if="selected && mode === 'details'">
                <dl class="detail-list">
                    <div>
                        <dt>Reporte</dt>
                        <dd>{{ selected.report_label }}</dd>
                    </div>
                    <div>
                        <dt>Creado por</dt>
                        <dd>{{ selected.created_by }}</dd>
                    </div>
                    <div>
                        <dt>Última ejecución</dt>
                        <dd>{{ selected.last_run_at ?? '—' }}</dd>
                    </div>
                </dl>
                <p v-if="selected.is_stale" class="flash flash-warning stale-note">
                    Los parámetros guardados ya no calzan con este reporte: no se puede ejecutar. Eliminalo y guardá la configuración de nuevo desde el reporte.
                </p>
            </template>

            <form v-if="selected && mode === 'edit'" id="saved-report-form" @submit.prevent="confirmEdit">
                <div class="field">
                    <label for="saved-report-name">Nombre</label>
                    <input id="saved-report-name" v-model="editForm.name" type="text" required>
                    <span v-if="editForm.errors.name" class="error">{{ editForm.errors.name }}</span>
                </div>
                <label class="check"><input v-model="editForm.is_shared" type="checkbox"> Compartido con mi compañía</label>
            </form>

            <template #actions>
                <template v-if="selected && mode === 'details'">
                    <button type="button" class="btn btn-ghost btn-danger-text" @click="destroy">Eliminar</button>
                    <button v-if="selected.is_mine" type="button" class="btn btn-ghost" @click="startEdit"><PencilIcon /> Editar</button>
                    <button type="button" class="btn btn-primary" :disabled="selected.is_stale" @click="run"><PlayIcon /> Ejecutar</button>
                </template>
                <template v-else-if="selected">
                    <button type="button" class="btn btn-ghost" @click="mode = 'details'">Cancelar</button>
                    <button type="submit" form="saved-report-form" class="btn btn-primary" :disabled="editForm.processing">Guardar</button>
                </template>
            </template>
        </DetailModal>
    </AppLayout>
</template>

<style scoped>
table { font-size: 0.85rem; }
.name-cell { display: inline-flex; align-items: center; flex-wrap: wrap; gap: 0.4rem; }
.stale-note { margin: 0.9rem 0 0; }
</style>
