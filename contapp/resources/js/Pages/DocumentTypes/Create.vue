<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeftIcon } from '@lucide/vue';

import AppLayout from '../../Layouts/AppLayout.vue';

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
        <div class="view-toolbar">
            <Link :href="route('document-types.index')" class="btn btn-ghost"><ArrowLeftIcon /> Tipos de documento</Link>
        </div>

        <form class="card form-card" @submit.prevent="submit">
            <div class="form-grid">
                <div class="field">
                    <label for="dt-code">Código (3 letras)</label>
                    <input id="dt-code" v-model="form.code" type="text" maxlength="3" placeholder="FVE" class="code-input" required>
                    <span v-if="form.errors.code" class="error">{{ form.errors.code }}</span>
                </div>

                <div class="field">
                    <label for="dt-name">Nombre</label>
                    <input id="dt-name" v-model="form.name" type="text" required>
                    <span v-if="form.errors.name" class="error">{{ form.errors.name }}</span>
                </div>

                <div class="field">
                    <label for="dt-module">Módulo de origen</label>
                    <select id="dt-module" v-model="form.origin_module" required>
                        <option v-for="(label, key) in originModules" :key="key" :value="key">{{ label }}</option>
                    </select>
                </div>

                <div class="field">
                    <label for="dt-currency">Moneda del documento</label>
                    <select id="dt-currency" v-model="form.currency_mode" required>
                        <option v-for="(label, key) in currencyModes" :key="key" :value="key">{{ label }}</option>
                    </select>
                </div>

                <div class="field">
                    <label for="dt-debit">Cuenta débito por defecto (opcional)</label>
                    <select id="dt-debit" v-model="form.default_debit_account_id">
                        <option :value="null">—</option>
                        <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.code }} — {{ a.description_es }}</option>
                    </select>
                </div>

                <div class="field">
                    <label for="dt-credit">Cuenta crédito por defecto (opcional)</label>
                    <select id="dt-credit" v-model="form.default_credit_account_id">
                        <option :value="null">—</option>
                        <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.code }} — {{ a.description_es }}</option>
                    </select>
                </div>

                <div class="field">
                    <label for="dt-consecutive">Consecutivo interno inicial (opcional)</label>
                    <input id="dt-consecutive" v-model="form.starting_consecutive" type="number" min="1" placeholder="1">
                    <span class="muted small">Dejalo vacío para arrancar en 1. Una vez creado el tipo de documento, este consecutivo es automático e inalterable — no se puede volver a cambiar.</span>
                    <span v-if="form.errors.starting_consecutive" class="error">{{ form.errors.starting_consecutive }}</span>
                </div>

                <div class="field">
                    <label for="dt-bp">Control de socio de negocio en líneas</label>
                    <select id="dt-bp" v-model="form.bp_line_requirement">
                        <option v-for="(label, key) in bpLineRequirements" :key="key" :value="key">{{ label }}</option>
                    </select>
                    <span class="muted small">Qué exige este tipo de documento en cada línea que traiga un socio de negocio: abrir una partida nueva (vencimiento), cancelar una existente (aplicación), aceptar cualquiera de las dos, o no exigir nada. Base de los reportes de antigüedad de saldos y estados de cuenta por socio.</span>
                    <span v-if="form.errors.bp_line_requirement" class="error">{{ form.errors.bp_line_requirement }}</span>
                </div>
            </div>

            <label class="check">
                <input v-model="form.generates_journal" type="checkbox">
                Genera asiento contable
            </label>

            <label class="check">
                <input v-model="form.requires_electronic_key" type="checkbox">
                Exige clave numérica electrónica de Hacienda (50 dígitos)
            </label>

            <div class="form-actions">
                <Link :href="route('document-types.index')" class="btn btn-ghost">Cancelar</Link>
                <button type="submit" class="btn btn-primary" :disabled="form.processing">Guardar</button>
            </div>
        </form>
    </AppLayout>
</template>

<style scoped>
.form-card { padding: 1.25rem; }
.code-input { text-transform: uppercase; }
.check { font-size: 0.85rem; margin-bottom: 0.75rem; }
</style>
