<script setup>
import { Head, useForm } from '@inertiajs/vue3';

import AppLayout from '../../Layouts/AppLayout.vue';
import DocumentToolbar from '../../Components/DocumentToolbar.vue';

const props = defineProps({
    accounts: { type: Array, default: () => [] },
    originModules: { type: Object, required: true },
    currencyModes: { type: Object, required: true },
    bpLineRequirements: { type: Object, required: true },
});

const form = useForm({
    code: '',
    name: '',
    origin_module: 'contable',
    generates_journal: true,
    requires_electronic_key: false,
    bp_line_requirement: 'none',
    currency_mode: 'libre',
    default_debit_account_id: null,
    default_credit_account_id: null,
    starting_consecutive: '',
    status: 'active',
});

function submit() {
    form.post(route('document-types.store'));
}
</script>

<template>
    <Head title="Nuevo tipo de documento" />

    <AppLayout title="Nuevo tipo de documento">
        <DocumentToolbar :new-href="route('document-types.create')" can-save :saving="form.processing" @save="submit" />

        <form class="card form-card" @submit.prevent="submit">
            <div class="grid">
                <div class="field">
                    <label>Código (3 letras)</label>
                    <input v-model="form.code" type="text" maxlength="3" placeholder="FVE" style="text-transform: uppercase" required>
                    <span v-if="form.errors.code" class="error">{{ form.errors.code }}</span>
                </div>

                <div class="field span-2">
                    <label>Nombre</label>
                    <input v-model="form.name" type="text" required>
                    <span v-if="form.errors.name" class="error">{{ form.errors.name }}</span>
                </div>

                <div class="field">
                    <label>Módulo de origen</label>
                    <select v-model="form.origin_module" required>
                        <option v-for="(label, key) in originModules" :key="key" :value="key">{{ label }}</option>
                    </select>
                </div>

                <div class="field">
                    <label>Moneda del documento</label>
                    <select v-model="form.currency_mode" required>
                        <option v-for="(label, key) in currencyModes" :key="key" :value="key">{{ label }}</option>
                    </select>
                </div>

                <div class="field">
                    <label>Cuenta débito por defecto (opcional)</label>
                    <select v-model="form.default_debit_account_id">
                        <option :value="null">—</option>
                        <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.code }} — {{ a.description_es }}</option>
                    </select>
                </div>

                <div class="field">
                    <label>Cuenta crédito por defecto (opcional)</label>
                    <select v-model="form.default_credit_account_id">
                        <option :value="null">—</option>
                        <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.code }} — {{ a.description_es }}</option>
                    </select>
                </div>

                <div class="field">
                    <label>Consecutivo interno inicial (opcional)</label>
                    <input v-model="form.starting_consecutive" type="number" min="1" placeholder="1">
                    <span class="muted small">Dejalo vacío para arrancar en 1. Una vez creado el tipo de documento, este consecutivo es automático e inalterable — no se puede volver a cambiar.</span>
                    <span v-if="form.errors.starting_consecutive" class="error">{{ form.errors.starting_consecutive }}</span>
                </div>
            </div>

            <label class="check-row">
                <input v-model="form.generates_journal" type="checkbox">
                Genera asiento contable
            </label>

            <label class="check-row">
                <input v-model="form.requires_electronic_key" type="checkbox">
                Exige clave numérica electrónica de Hacienda (50 dígitos)
            </label>

            <div class="field">
                <label>Control de socio de negocio en líneas</label>
                <select v-model="form.bp_line_requirement">
                    <option v-for="(label, key) in bpLineRequirements" :key="key" :value="key">{{ label }}</option>
                </select>
                <span class="muted small">Qué exige este tipo de documento en cada línea que traiga un socio de negocio: abrir una partida nueva (vencimiento), cancelar una existente (aplicación), aceptar cualquiera de las dos, o no exigir nada. Base de los reportes de antigüedad de saldos y estados de cuenta por socio.</span>
                <span v-if="form.errors.bp_line_requirement" class="error">{{ form.errors.bp_line_requirement }}</span>
            </div>

            <div class="modal-actions">
                <button type="submit" class="btn btn-primary" :disabled="form.processing">Guardar</button>
            </div>
        </form>
    </AppLayout>
</template>

<style scoped>
.form-card { padding: 1.25rem; max-width: 720px; }
.grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0 1.25rem; }
.span-2 { grid-column: span 2; }

.field {
    display: flex;
    flex-direction: column;
    gap: 0.2rem;
    margin-bottom: 0.85rem;
}

.field label {
    font-size: 0.78rem;
    color: var(--color-text-muted);
}

.field input, .field select {
    width: 100%;
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    padding: 0.45rem 0.6rem;
    font-size: 0.85rem;
    color: var(--color-text);
}

.muted { color: var(--color-text-muted); }
.small { font-size: 0.74rem; }
.error { color: var(--color-danger); font-size: 0.76rem; }

.check-row {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.85rem;
    margin: 0.25rem 0 1.25rem;
}

.modal-actions { display: flex; gap: 0.6rem; }
</style>
