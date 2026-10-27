<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeftIcon } from '@lucide/vue';
import BackofficeLayout from '../../../Layouts/BackofficeLayout.vue';
import CompanyBackupPanel from '../../../Components/CompanyBackupPanel.vue';

/**
 * Respaldos de las compañías de una licencia, desde el backoffice (CLAUDE.md
 * secc. 37). Si se exporta, al Superusuario le llega un correo.
 */
const props = defineProps({
    license: { type: Object, required: true },
    superuser: { type: Object, default: null },
    licenseCompanies: { type: Array, required: true },
    sections: { type: Array, required: true },
    minPassword: { type: Number, default: 10 },
    imports: { type: Array, default: () => [] },
});

const routes = {
    preview: 'backoffice.licenses.backups.preview',
    export: 'backoffice.licenses.backups.export',
    import: 'backoffice.licenses.backups.import',
    compare: 'backoffice.licenses.backups.import.compare',
    confirm: 'backoffice.licenses.backups.import.confirm',
    cancel: 'backoffice.licenses.backups.import.cancel',
    status: 'backoffice.licenses.backups.import.status',
};
</script>

<template>
    <Head :title="`Respaldos — ${license.code}`" />

    <BackofficeLayout :title="`Respaldos — ${license.code}`">
        <div class="view-toolbar">
            <Link :href="route('backoffice.licenses.index')" class="btn btn-ghost"><ArrowLeftIcon /> Licencias</Link>
        </div>

        <p class="hint intro">
            Las compañías de la licencia {{ license.code }}<template v-if="superuser">, de {{ superuser.name }} ({{ superuser.email }})</template>.
            Exportar le avisa por correo al Superusuario: es su información.
        </p>

        <p v-if="! licenseCompanies.length" class="muted">Esta licencia todavía no tiene compañías: solo se puede importar en una nueva.</p>

        <CompanyBackupPanel
            :companies="licenseCompanies"
            :sections="sections"
            :license="license"
            :min-password="minPassword"
            :imports="imports"
            :routes="routes"
            :route-params="{ license: props.license.id }"
            account-label="Tu contraseña del backoffice"
            backoffice
        />
    </BackofficeLayout>
</template>

<style scoped>
.intro { margin: 0 0 1rem; }
</style>
