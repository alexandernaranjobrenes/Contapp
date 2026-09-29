<script setup>
import { useForm } from '@inertiajs/vue3';
import { ref, useId } from 'vue';
import { BookmarkPlusIcon } from '@lucide/vue';
import DetailModal from './DetailModal.vue';

const props = defineProps({
    reportCode: { type: String, required: true },
    // Objeto ya armado con la forma exacta que espera el backend para este
    // reporte (fechas ya envueltas con wrapDate() donde corresponda) — ver
    // resources/js/Utils/reportParameters.js.
    parameters: { type: Object, required: true },
});

// El nombre se pide en un modal (CLAUDE.md secc. 21), no en un campo que se
// abre dentro de la barra de la vista: en un teléfono no cabía al lado de
// los demás botones.
const open = ref(false);
const nameId = useId();
const form = useForm({ name: '' });

function startSave() {
    form.clearErrors();
    form.name = '';
    open.value = true;
}

function confirmSave() {
    if (! form.name.trim()) return;

    form
        .transform(({ name }) => ({
            report_code: props.reportCode,
            name: name.trim(),
            parameters: props.parameters,
            is_shared: false,
        }))
        .post(route('saved-reports.store'), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => { open.value = false; },
        });
}
</script>

<template>
    <button type="button" class="btn btn-ghost" @click="startSave"><BookmarkPlusIcon /> Guardar configuración</button>

    <DetailModal :open="open" title="Guardar configuración del reporte" @close="open = false">
        <form :id="`${nameId}-form`" @submit.prevent="confirmSave">
            <p class="hint">Guarda los filtros de ahora con un nombre, para volver a correr el reporte igual desde «Reportes guardados».</p>
            <div class="field">
                <label :for="nameId">Nombre</label>
                <input :id="nameId" v-model="form.name" type="text" maxlength="255" placeholder="Ej. Cierre de mes — sin cuentas en cero" required>
                <span v-if="form.errors.name" class="error">{{ form.errors.name }}</span>
                <span v-if="form.errors.parameters" class="error">{{ form.errors.parameters }}</span>
            </div>
        </form>

        <template #actions>
            <button type="button" class="btn btn-ghost" @click="open = false">Cancelar</button>
            <button type="submit" :form="`${nameId}-form`" class="btn btn-primary" :disabled="form.processing || !form.name.trim()">Guardar</button>
        </template>
    </DetailModal>
</template>
