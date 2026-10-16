<script setup>
import { Head } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import CompanyBackupPanel from '../../Components/CompanyBackupPanel.vue';

/**
 * Administración → Respaldos (CLAUDE.md secc. 37): solo el Superusuario, con
 * las compañías de su licencia.
 */
defineProps({
    license: { type: Object, required: true },
    companies: { type: Array, required: true },
    currentCompanyId: { type: Number, default: null },
    sections: { type: Array, required: true },
    minPassword: { type: Number, default: 10 },
    imports: { type: Array, default: () => [] },
});

const routes = {
    preview: 'backups.preview',
    export: 'backups.export',
    import: 'backups.import',
    confirm: 'backups.import.confirm',
    status: 'backups.import.status',
};
</script>

<template>
    <Head title="Respaldos" />

    <AppLayout title="Respaldos">
        <p class="hint intro">
            Un respaldo es toda la información de una compañía —o la de un período— en un archivo de texto cifrado. Sirve para guardarla
            aparte o para pasar una compañía a otra licencia. Solo vos, como Superusuario, podés exportar e importar.
        </p>

        <CompanyBackupPanel
            :companies="companies"
            :current-company-id="currentCompanyId"
            :sections="sections"
            :license="license"
            :min-password="minPassword"
            :imports="imports"
            :routes="routes"
        />
    </AppLayout>
</template>

<style scoped>
.intro { margin: 0 0 1rem; }
</style>
