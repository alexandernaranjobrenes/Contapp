<script setup>
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    reportCode: { type: String, required: true },
    // Objeto ya armado con la forma exacta que espera el backend para este
    // reporte (fechas ya envueltas con wrapDate() donde corresponda) — ver
    // resources/js/Utils/reportParameters.js.
    parameters: { type: Object, required: true },
});

const open = ref(false);
const name = ref('');
const saving = ref(false);

function startSave() {
    open.value = true;
    name.value = '';
}

function confirmSave() {
    if (! name.value.trim()) return;

    saving.value = true;
    router.post(route('saved-reports.store'), {
        report_code: props.reportCode,
        name: name.value.trim(),
        parameters: props.parameters,
        is_shared: false,
    }, {
        preserveScroll: true,
        preserveState: true,
        onFinish: () => { saving.value = false; open.value = false; },
    });
}
</script>

<template>
    <span class="save-report">
        <button v-if="!open" type="button" class="btn btn-ghost" @click="startSave">Guardar configuración</button>
        <span v-else class="save-report-form">
            <input
                v-model="name"
                type="text"
                class="date-input"
                placeholder="Nombre del reporte guardado"
                autofocus
                @keyup.enter="confirmSave"
                @keyup.escape="open = false"
            >
            <button type="button" class="btn btn-primary" :disabled="saving || !name.trim()" @click="confirmSave">Guardar</button>
            <button type="button" class="btn btn-ghost" @click="open = false">Cancelar</button>
        </span>
    </span>
</template>

<style scoped>
.save-report-form { display: inline-flex; align-items: center; gap: 0.4rem; }
</style>
