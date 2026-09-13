<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import DocumentToolbar from '../../Components/DocumentToolbar.vue';

const props = defineProps({
    license: { type: Object, required: true },
});

const form = useForm({
    legal_name: '',
    trade_name: '',
    tax_id: '',
});

function submit() {
    form.post(route('companies.store'));
}
</script>

<template>
    <Head title="Agregar compañía" />

    <AppLayout title="Agregar compañía a tu licencia">
        <DocumentToolbar can-save :saving="form.processing" @save="submit" />

        <div class="card issue-card">
            <p class="quota">
                Licencia {{ license.masked_code }} — {{ license.companies_count }} de {{ license.max_companies }} compañías usadas.
            </p>

            <p v-if="!license.can_activate_another" class="muted empty-state">
                Ya alcanzaste el máximo de compañías de tu licencia. Contactá al soporte de CONTAPP si necesitás más cupo.
            </p>

            <form v-else class="issue-form" @submit.prevent="submit">
                <div class="field">
                    <label for="legal_name">Razón social</label>
                    <input id="legal_name" v-model="form.legal_name" type="text" required>
                    <span v-if="form.errors.legal_name" class="error">{{ form.errors.legal_name }}</span>
                </div>
                <div class="field">
                    <label for="trade_name">Nombre comercial</label>
                    <input id="trade_name" v-model="form.trade_name" type="text">
                </div>
                <div class="field">
                    <label for="tax_id">Cédula jurídica</label>
                    <input id="tax_id" v-model="form.tax_id" type="text">
                </div>
                <button type="submit" class="btn btn-primary" :disabled="form.processing">Crear compañía</button>
            </form>
        </div>
    </AppLayout>
</template>

<style scoped>
.issue-card { padding: 1rem 1.1rem; margin-bottom: 1rem; }
.quota { font-size: 0.85rem; color: var(--color-text-muted); margin: 0 0 1rem; }
.empty-state { padding: 0.5rem 0; }

.issue-form { display: flex; align-items: flex-end; gap: 1rem; flex-wrap: wrap; }
.issue-form .field { margin-bottom: 0; }
.issue-form input {
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    padding: 0.4rem 0.55rem;
    font-size: 0.85rem;
}
.error { display: block; color: var(--color-danger, #c0392b); font-size: 0.75rem; margin-top: 0.2rem; }
</style>
