<script setup>
import { Head, useForm, router, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';
import BackofficeLayout from '../../../Layouts/BackofficeLayout.vue';
import DocumentToolbar from '../../../Components/DocumentToolbar.vue';

const props = defineProps({
    rates: { type: Array, default: () => [] },
    taxTypes: { type: Array, default: () => [] },
});

const page = usePage();
const today = new Date().toISOString().slice(0, 10);

function isVigente(rate) {
    return rate.effective_from <= today && (! rate.effective_to || rate.effective_to >= today);
}

const editing = ref(null);

const form = useForm({
    tax_type_id: props.taxTypes[0]?.id ?? null,
    code: '',
    name: '',
    percentage: '',
    grants_fiscal_credit: false,
    fiscal_credit_note: '',
    effective_from: today,
    effective_to: '',
});

// form.reset() vuelve a los valores que tenía el form al momento de crear
// useForm() — en teoría alcanza para dejarlo en blanco. Pero como este mismo
// form se reutiliza para editar (openEdit lo llena con los datos de una
// tarifa real) y nada lo limpiaba al cerrar el modal con "Cancelar", cerrar
// una edición sin guardar y abrir "+ Nuevo indicador" después podía dejar
// ver el rastro de esos datos. Ahora openCreate() asigna cada campo a mano
// (no depende de reset()) y closeModal() también limpia el form, así que no
// hay forma de que sobreviva estado de una edición anterior sin importar
// cómo se haya cerrado el modal.
function blankForm() {
    form.clearErrors();
    form.tax_type_id = props.taxTypes[0]?.id ?? null;
    form.code = '';
    form.name = '';
    form.percentage = '';
    form.grants_fiscal_credit = false;
    form.fiscal_credit_note = '';
    form.effective_from = today;
    form.effective_to = '';
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
    editing.value = rate;
}

function closeModal() {
    editing.value = null;
    blankForm();
}

function submit() {
    if (editing.value.isNew) {
        form.post(route('backoffice.tax-rates.store'), { onSuccess: closeModal, preserveScroll: true });
    } else {
        form.put(route('backoffice.tax-rates.update', editing.value.id), { onSuccess: closeModal, preserveScroll: true });
    }
}

function destroy(rate) {
    if (! confirm(`¿Eliminar el indicador ${rate.code}? Esta acción no se puede deshacer.`)) return;

    router.delete(route('backoffice.tax-rates.destroy', rate.id), { preserveScroll: true });
}
</script>

<template>
    <Head title="Indicadores de impuesto" />

    <BackofficeLayout title="Indicadores de impuesto">
        <template #actions>
            <button type="button" class="btn btn-primary" @click="openCreate">+ Nuevo indicador</button>
        </template>

        <DocumentToolbar can-create @new="openCreate" />

        <p class="intro muted">
            Catálogo de indicadores de impuesto (IVA 13%, 1%, 2%, 4%, etc.), compartido por todas las compañías — es ley nacional, no varía por compañía.
        </p>

        <div v-if="page.props.errors?.tax_rate" class="flash flash-error">{{ page.props.errors.tax_rate }}</div>

        <div class="card">
            <table>
                <thead>
                    <tr>
                        <th>Código</th>
                        <th>Nombre</th>
                        <th>Tipo</th>
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
                            <button type="button" class="btn btn-ghost" @click="openEdit(rate)">Editar</button>
                            <button type="button" class="btn btn-ghost" @click="destroy(rate)">Eliminar</button>
                        </td>
                    </tr>
                    <tr v-if="!rates.length">
                        <td colspan="9" class="muted empty-row">Todavía no hay indicadores de impuesto registrados.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="editing" class="modal-backdrop" @click.self="closeModal">
            <form class="modal-card card" @submit.prevent="submit">
                <h2>{{ editing.isNew ? 'Nuevo indicador de impuesto' : `Editar indicador ${editing.code}` }}</h2>

                <p v-if="!editing.isNew && editing.in_use" class="muted small">
                    Este indicador ya se usó en asientos contabilizados: solo se le puede cerrar la vigencia.
                    Para un porcentaje nuevo, creá otro indicador (ej. "IVA-13-B").
                </p>

                <div class="field">
                    <label>Tipo de impuesto</label>
                    <select v-model="form.tax_type_id" :disabled="!editing.isNew && editing.in_use" required>
                        <option v-for="t in taxTypes" :key="t.id" :value="t.id">{{ t.name }}</option>
                    </select>
                </div>

                <div class="field">
                    <label>Código</label>
                    <input v-model="form.code" type="text" autocomplete="off" placeholder="IVA-13" :disabled="!editing.isNew && editing.in_use" required>
                    <span v-if="form.errors.code" class="error">{{ form.errors.code }}</span>
                </div>

                <div class="field">
                    <label>Nombre</label>
                    <input v-model="form.name" type="text" autocomplete="off" placeholder="IVA tarifa general 13%" :disabled="!editing.isNew && editing.in_use" required>
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
                    <span class="hint">Matiz libre del derecho a crédito — ej. "Crédito pleno", "Tarifa reducida", "Salvo exportaciones/exoneraciones".</span>
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
    </BackofficeLayout>
</template>

<style scoped>
.intro {
    font-size: 0.85rem;
    margin: 0 0 0.75rem;
    max-width: 70ch;
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

.hint {
    display: block;
    font-size: 0.74rem;
    color: var(--color-text-muted);
    margin-top: 0.2rem;
}

.modal-actions {
    display: flex;
    gap: 0.6rem;
    margin-top: 0.25rem;
}
</style>
