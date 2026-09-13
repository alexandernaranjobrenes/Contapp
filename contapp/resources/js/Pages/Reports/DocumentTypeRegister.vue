<script setup>
import { Head } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import DocumentTypeRegisterPanel from '../../Components/DocumentTypeRegisterPanel.vue';

defineProps({
    documentTypes: { type: Array, required: true }, // [{ id, code, name }]
});
</script>

<template>
    <Head title="Registro por tipo de documento" />

    <AppLayout title="Registro por tipo de documento">
        <p class="hint">
            Exportá a XLSX todos los asientos de un tipo de documento (ej. todas las facturas de venta, todos los
            diarios) o de todos los tipos a la vez, una fila por línea de detalle — cuenta, socio de negocio, centro
            de costo, débito y crédito. Este mismo panel también está disponible desde el botón "Exportar registro"
            al ver el detalle de un asiento.
        </p>

        <div v-if="!documentTypes.length" class="card empty-card">
            Esta compañía no tiene tipos de documento activos que generen asientos.
        </div>

        <div v-else class="card">
            <DocumentTypeRegisterPanel :document-types="documentTypes" mode="inline" />
        </div>
    </AppLayout>
</template>

<style scoped>
.hint { color: var(--color-text-muted); font-size: 0.8rem; margin-bottom: 1rem; max-width: 680px; }
.card { padding: 1.1rem 1.25rem; max-width: 480px; }
.empty-card { color: var(--color-text-muted); }
</style>
