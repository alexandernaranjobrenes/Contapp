<script setup>
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import DocumentToolbar from '../../Components/DocumentToolbar.vue';

const props = defineProps({
    rates: { type: Array, default: () => [] },
    taxTypes: { type: Array, default: () => [] },
});

const page = usePage();
const today = new Date().toISOString().slice(0, 10);

function isVigente(rate) {
    return rate.effective_from <= today && (! rate.effective_to || rate.effective_to >= today);
}

// --- alta/edición de indicadores PROPIOS (el catálogo nacional, is_global,
// es de solo lectura acá — se administra desde el panel de CONTAPP) --------

const editing = ref(null);
const typeMode = ref('existing');

const form = useForm({
    tax_type_id: null,
    new_tax_type_code: '',
    new_tax_type_name: '',
    code: '',
    name: '',
    percentage: '',
    grants_fiscal_credit: false,
    fiscal_credit_note: '',
    effective_from: today,
    effective_to: '',
});

function blankForm() {
    form.clearErrors();
    form.tax_type_id = props.taxTypes[0]?.id ?? null;
    form.new_tax_type_code = '';
    form.new_tax_type_name = '';
    form.code = '';
    form.name = '';
    form.percentage = '';
    form.grants_fiscal_credit = false;
    form.fiscal_credit_note = '';
    form.effective_from = today;
    form.effective_to = '';
    typeMode.value = 'existing';
}

function openCreate() {
    blankForm();
    editing.value = { isNew: true };
}

function openEdit(rate) {
    form.clearErrors();
    form.tax_type_id = rate.tax_type_id;
    form.code = rate.code;
    form.name = rate.name;
    form.percentage = rate.percentage;
    form.grants_fiscal_credit = rate.grants_fiscal_credit;
    form.fiscal_credit_note = rate.fiscal_credit_note ?? '';
    form.effective_from = rate.effective_from;
    form.effective_to = rate.effective_to ?? '';
    typeMode.value = 'existing';
    editing.value = rate;
}

function closeModal() {
    editing.value = null;
    blankForm();
}

function submit() {
    if (typeMode.value === 'new') {
        form.tax_type_id = null;
    } else {
        form.new_tax_type_code = '';
        form.new_tax_type_name = '';
    }

    if (editing.value.isNew) {
        form.post(route('tax-rates.store'), { onSuccess: closeModal, preserveScroll: true });
    } else {
        form.put(route('tax-rates.update', editing.value.id), { onSuccess: closeModal, preserveScroll: true });
    }
}

function destroy(rate) {
    if (! confirm(`¿Eliminar el indicador ${rate.code}? Esta acción no se puede deshacer.`)) return;

    router.delete(route('tax-rates.destroy', rate.id), { preserveScroll: true });
}
</script>

<template>
    <Head title="Indicadores de impuesto" />

    <AppLayout title="Indicadores de impuesto">
        <template #actions>
            <button type="button" class="btn btn-primary" @click="openCreate">+ Nuevo indicador propio</button>
        </template>

        <DocumentToolbar can-create @new="openCreate" />

        <p class="intro muted">
            Los indicadores <strong>nacionales</strong> (IVA 13%, 1%, 2%, 4%, exento) son ley y están compartidos por todas las
            compañías — se administran desde el panel de CONTAPP, acá se ven de solo lectura. También podés crear tus
            <strong>propios indicadores</strong> (ej. un impuesto municipal) que solo afectan a esta compañía, y editarlos o
            eliminarlos libremente mientras no se hayan usado todavía. Para usar cualquier indicador en los asientos, vinculalo
            a una cuenta contable desde el <a :href="route('chart-of-accounts.index')">catálogo de cuentas</a> (campo
            "Indicador de impuesto vinculado"), indicando si esa cuenta es de IVA Soportado o Devengado.
        </p>

        <div v-if="page.props.errors?.tax_rate" class="flash flash-error">{{ page.props.errors.tax_rate }}</div>

        <div class="card">
            <table>
                <thead>
                    <tr>
                        <th>Código</th>
                        <th>Nombre</th>
                        <th>Tipo</th>
                        <th>Origen</th>
                        <th class="num">Porcentaje</th>
                        <th>Derecho a crédito fiscal</th>
                        <th>Vigente desde</th>
                        <th>Vigente hasta</th>
                        <th>Estado</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="rate in rates" :key="rate.id">
                        <td class="num">{{ rate.code }}</td>
                        <td>{{ rate.name }}</td>
                        <td>{{ rate.tax_type?.name }}</td>
                        <td>
                            <span class="badge" :class="rate.is_global ? 'badge-neutral' : 'badge-success'">
                                {{ rate.is_global ? 'Nacional' : 'Propio' }}
                            </span>
                        </td>
                        <td class="num">{{ rate.percentage }}%</td>
                        <td>
                            <span class="badge" :class="rate.grants_fiscal_credit ? 'badge-success' : 'badge-neutral'">
                                {{ rate.grants_fiscal_credit ? 'Sí' : 'No' }}
                            </span>
                            <div v-if="rate.fiscal_credit_note" class="muted small">({{ rate.fiscal_credit_note }})</div>
                        </td>
                        <td>{{ rate.effective_from }}</td>
                        <td>{{ rate.effective_to ?? '—' }}</td>
                        <td>
                            <span class="badge" :class="isVigente(rate) ? 'badge-success' : 'badge-neutral'">
                                {{ isVigente(rate) ? 'Vigente' : (rate.effective_from > today ? 'Futura' : 'Vencida') }}
                            </span>
                        </td>
                        <td class="actions-cell">
                            <template v-if="!rate.is_global">
                                <button type="button" class="btn btn-ghost" @click="openEdit(rate)">Editar</button>
                                <button type="button" class="btn btn-ghost" @click="destroy(rate)">Eliminar</button>
                            </template>
                        </td>
                    </tr>
                    <tr v-if="!rates.length">
                        <td colspan="10" class="muted empty-row">Todavía no hay indicadores de impuesto registrados.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="editing" class="modal-backdrop" @click.self="closeModal">
            <form class="modal-card card" @submit.prevent="submit">
                <h2>{{ editing.isNew ? 'Nuevo indicador propio' : `Editar indicador ${editing.code}` }}</h2>

                <p v-if="!editing.isNew && editing.in_use" class="muted small">
                    Este indicador ya se usó en asientos contabilizados: solo se le puede cerrar la vigencia.
                    Para un porcentaje nuevo, creá otro indicador (ej. "MUNI-1-B").
                </p>

                <template v-if="editing.isNew">
                    <div class="mode-toggle">
                        <button type="button" class="mode-btn" :class="{ active: typeMode === 'existing' }" @click="typeMode = 'existing'">Tipo existente</button>
                        <button type="button" class="mode-btn" :class="{ active: typeMode === 'new' }" @click="typeMode = 'new'">Tipo nuevo</button>
                    </div>

                    <div class="field" v-if="typeMode === 'existing'">
                        <label>Tipo de impuesto</label>
                        <select v-model="form.tax_type_id" required>
                            <option v-for="t in taxTypes" :key="t.id" :value="t.id">
                                {{ t.name }} ({{ t.is_global ? 'nacional' : 'propio' }})
                            </option>
                        </select>
                        <span v-if="form.errors.tax_type_id" class="error">{{ form.errors.tax_type_id }}</span>
                    </div>
                    <template v-else>
                        <div class="field">
                            <label>Código del tipo nuevo</label>
                            <input v-model="form.new_tax_type_code" type="text" autocomplete="off" placeholder="MUNICIPAL">
                            <span v-if="form.errors.new_tax_type_code" class="error">{{ form.errors.new_tax_type_code }}</span>
                        </div>
                        <div class="field">
                            <label>Nombre del tipo nuevo</label>
                            <input v-model="form.new_tax_type_name" type="text" autocomplete="off" placeholder="Impuesto Municipal">
                            <span v-if="form.errors.new_tax_type_name" class="error">{{ form.errors.new_tax_type_name }}</span>
                        </div>
                    </template>
                </template>
                <div class="field" v-else>
                    <label>Tipo de impuesto</label>
                    <select v-model="form.tax_type_id" :disabled="editing.in_use" required>
                        <option v-for="t in taxTypes" :key="t.id" :value="t.id">
                            {{ t.name }} ({{ t.is_global ? 'nacional' : 'propio' }})
                        </option>
                    </select>
                </div>

                <div class="field">
                    <label>Código</label>
                    <input v-model="form.code" type="text" autocomplete="off" placeholder="MUNI-1" :disabled="!editing.isNew && editing.in_use" required>
                    <span v-if="form.errors.code" class="error">{{ form.errors.code }}</span>
                </div>

                <div class="field">
                    <label>Nombre</label>
                    <input v-model="form.name" type="text" autocomplete="off" placeholder="Impuesto municipal 1%" :disabled="!editing.isNew && editing.in_use" required>
                    <span v-if="form.errors.name" class="error">{{ form.errors.name }}</span>
                </div>

                <div class="field">
                    <label>Porcentaje</label>
                    <input v-model="form.percentage" type="number" step="0.01" min="0" max="100" :disabled="!editing.isNew && editing.in_use" required>
                    <span v-if="form.errors.percentage" class="error">{{ form.errors.percentage }}</span>
                </div>

                <label class="check-row">
                    <input v-model="form.grants_fiscal_credit" type="checkbox" :disabled="!editing.isNew && editing.in_use">
                    Da derecho a crédito fiscal
                </label>

                <div class="field">
                    <label>Detalle del crédito fiscal (opcional)</label>
                    <input
                        v-model="form.fiscal_credit_note"
                        type="text"
                        autocomplete="off"
                        placeholder="Crédito pleno / Tarifa reducida / Salvo exportaciones y exoneraciones..."
                        :disabled="!editing.isNew && editing.in_use"
                    >
                    <span v-if="form.errors.fiscal_credit_note" class="error">{{ form.errors.fiscal_credit_note }}</span>
                </div>

                <div class="field">
                    <label>Vigente desde</label>
                    <input v-model="form.effective_from" type="date" :disabled="!editing.isNew && editing.in_use" required>
                    <span v-if="form.errors.effective_from" class="error">{{ form.errors.effective_from }}</span>
                </div>

                <div class="field">
                    <label>Vigente hasta (opcional — vacío = sigue vigente)</label>
                    <input v-model="form.effective_to" type="date">
                    <span v-if="form.errors.effective_to" class="error">{{ form.errors.effective_to }}</span>
                </div>

                <div class="modal-actions">
                    <button type="submit" class="btn btn-primary" :disabled="form.processing">Guardar</button>
                    <button type="button" class="btn btn-ghost" @click="closeModal">Cancelar</button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>

<style scoped>
.intro {
    font-size: 0.85rem;
    margin: 0 0 0.75rem;
    max-width: 80ch;
}

.intro a {
    color: var(--color-primary);
}

.small {
    font-size: 0.76rem;
}

.muted {
    color: var(--color-text-muted);
}

.flash { margin-bottom: 1rem; padding: 0.6rem 0.9rem; border-radius: var(--radius-sm); font-size: 0.85rem; }
.flash-error { background: var(--color-danger-soft); color: var(--color-danger); }

table { font-size: 0.85rem; width: 100%; }
th, td { text-align: left; padding: 0.55rem 1.1rem; border-top: 1px solid var(--color-border); }
.num { font-variant-numeric: tabular-nums; }
.empty-row { text-align: center; padding: 1.5rem; }
.actions-cell { display: flex; gap: 0.4rem; }

.modal-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(11, 31, 58, 0.45);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 50;
    padding: 1rem;
}

.modal-card {
    width: 420px;
    max-width: 100%;
    max-height: 90vh;
    overflow-y: auto;
    padding: 1.5rem;
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.modal-card h2 { font-size: 1rem; margin: 0; }

.mode-toggle {
    display: flex;
    gap: 0.3rem;
}

.mode-btn {
    flex: 1;
    background: var(--color-surface-alt);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    padding: 0.35rem 0.5rem;
    font-size: 0.78rem;
    font-weight: 600;
    color: var(--color-text-muted);
    cursor: pointer;
}

.mode-btn.active {
    background: var(--color-primary);
    border-color: var(--color-primary);
    color: var(--color-on-primary);
}

.field {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
}

.field label {
    font-size: 0.78rem;
    color: var(--color-text-muted);
}

.field input, .field select {
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    padding: 0.45rem 0.6rem;
    font-size: 0.85rem;
    color: var(--color-text);
}

.error {
    color: var(--color-danger);
    font-size: 0.76rem;
}

.check-row {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.85rem;
}

.modal-actions {
    display: flex;
    gap: 0.6rem;
    margin-top: 0.25rem;
}
</style>
