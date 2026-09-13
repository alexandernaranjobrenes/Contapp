<script setup>
import { Head, usePage } from '@inertiajs/vue3';
import { useForm } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import DocumentToolbar from '../../Components/DocumentToolbar.vue';

const page = usePage();
const fileInput = ref(null);
const importErrors = computed(() => page.props.flash?.importErrors ?? []);

const form = useForm({
    posting_date: new Date().toISOString().slice(0, 10),
    description: '',
    file: null,
});

function onFileSelected(e) {
    const file = e.target.files[0];
    if (! file) return;

    form.file = file;
    form.post(route('opening-balance.import'), {
        preserveScroll: true,
        onFinish: () => {
            if (fileInput.value) fileInput.value.value = '';
        },
    });
}
</script>

<template>
    <Head title="Saldos iniciales" />

    <AppLayout title="Saldos iniciales">
        <DocumentToolbar />

        <div class="card intro-card">
            <h2>Carga de saldos iniciales</h2>
            <p class="muted">
                Para poner en marcha una compañía que ya operaba fuera de este sistema: descargá la plantilla (ya trae una fila por
                cada cuenta y socio de negocio del catálogo), completá los saldos con los que arranca y subila. El sistema
                contabiliza un único asiento de apertura usando un tipo de documento reservado exclusivamente para esto — no
                aparece como opción en el formulario manual de asientos.
            </p>
            <ul class="intro-list muted small">
                <li>Los débitos deben cuadrar exactamente con los créditos: si no cuadra, no se contabiliza nada.</li>
                <li>Un saldo inicial de cliente o proveedor queda registrado como partida pendiente, lista para aplicarle cobros o pagos más adelante.</li>
            </ul>
        </div>

        <form class="card form-card" @submit.prevent>
            <div class="grid">
                <div class="field">
                    <label for="posting_date">Fecha del asiento de apertura</label>
                    <input id="posting_date" v-model="form.posting_date" type="date" required>
                    <span class="hint">Normalmente el primer día del período fiscal en que la compañía empieza a usar el sistema. Fijala antes de subir el archivo — el archivo se contabiliza apenas se elige.</span>
                    <span v-if="form.errors.posting_date" class="error">{{ form.errors.posting_date }}</span>
                </div>

                <div class="field">
                    <label for="description">Descripción (opcional)</label>
                    <input id="description" v-model="form.description" type="text" maxlength="255" placeholder="Carga de saldos iniciales">
                    <span v-if="form.errors.description" class="error">{{ form.errors.description }}</span>
                </div>
            </div>

            <div class="upload-row">
                <a :href="route('opening-balance.template')" class="btn btn-ghost">Descargar plantilla</a>
                <label class="btn btn-primary file-btn" :class="{ disabled: form.processing }">
                    {{ form.processing ? 'Contabilizando...' : 'Subir XLSX y contabilizar' }}
                    <input ref="fileInput" type="file" accept=".xlsx" class="file-input" :disabled="form.processing" @change="onFileSelected">
                </label>
            </div>
            <span v-if="form.errors.file" class="error">{{ form.errors.file }}</span>

            <div v-if="importErrors.length" class="import-errors">
                <p class="import-errors-title">No se contabilizó nada porque el archivo tiene {{ importErrors.length }} error(es). Corregilos y subilo de nuevo:</p>
                <ul>
                    <li v-for="(msg, i) in importErrors" :key="i">{{ msg }}</li>
                </ul>
            </div>
        </form>
    </AppLayout>
</template>

<style scoped>
.intro-card {
    padding: 1.1rem 1.25rem;
    margin-bottom: 0.75rem;
}

.intro-card h2 {
    font-size: 1rem;
    margin: 0 0 0.5rem;
}

.intro-list {
    margin: 0.5rem 0 0;
    padding-left: 1.1rem;
    display: flex;
    flex-direction: column;
    gap: 0.2rem;
}

.form-card {
    padding: 1.25rem;
    max-width: 720px;
}

.grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0 1.25rem;
}

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

.field input {
    width: 100%;
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    padding: 0.45rem 0.6rem;
    font-size: 0.85rem;
    color: var(--color-text);
}

.muted { color: var(--color-text-muted); }
.small { font-size: 0.78rem; }
.hint { font-size: 0.74rem; color: var(--color-text-muted); }
.error { color: var(--color-danger); font-size: 0.76rem; }

.upload-row {
    display: flex;
    gap: 0.5rem;
    margin-top: 0.25rem;
}

.file-btn {
    position: relative;
    cursor: pointer;
    overflow: hidden;
}

.file-btn.disabled {
    opacity: 0.6;
    cursor: default;
}

.file-input {
    position: absolute;
    inset: 0;
    opacity: 0;
    width: 100%;
    cursor: pointer;
}

.import-errors {
    margin-top: 0.9rem;
    padding: 0.75rem 0.9rem;
    border-radius: var(--radius-sm);
    background: var(--color-danger-soft);
    color: var(--color-danger);
    font-size: 0.82rem;
}

.import-errors-title {
    font-weight: 700;
    margin: 0 0 0.4rem;
}

.import-errors ul {
    margin: 0;
    padding-left: 1.1rem;
    display: flex;
    flex-direction: column;
    gap: 0.2rem;
}
</style>
