<script setup>
import { Head, useForm, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import BackofficeLayout from '../../../Layouts/BackofficeLayout.vue';
import ConfirmModal from '../../../Components/ConfirmModal.vue';
import DetailModal from '../../../Components/DetailModal.vue';
import { PlusIcon } from '@lucide/vue';

const props = defineProps({
    rates: { type: Array, default: () => [] },
    taxTypes: { type: Array, default: () => [] },
});

const page = usePage();
const today = new Date().toISOString().slice(0, 10);

function isVigente(rate) {
    return rate.effective_from <= today && (! rate.effective_to || rate.effective_to >= today);
}

function statusOf(rate) {
    if (isVigente(rate)) return { label: 'Vigente', cls: 'badge-success' };
    return { label: rate.effective_from > today ? 'Futura' : 'Vencida', cls: 'badge-neutral' };
}

// Ficha del indicador (CLAUDE.md secc. 20): la fila abre este modal con el
// detalle completo, editar y eliminar. El mismo modal sirve para crear uno
// nuevo (secc. 21): «Crear nuevo», arriba de la tabla, lo abre directo en
// modo formulario.
const selectedId = ref(null);
const mode = ref(null); // null (cerrado) | 'details' | 'edit' | 'create'

// Se busca en props cada vez: al guardar, la ficha muestra el dato nuevo.
const selected = computed(() => props.rates.find((r) => r.id === selectedId.value) ?? null);

const modalOpen = computed(() => mode.value === 'create' || (mode.value !== null && !!selected.value));

const modalTitle = computed(() => {
    if (mode.value === 'create') return 'Nuevo indicador de impuesto';
    return selected.value?.code ?? '';
});

// Un indicador ya usado en asientos contabilizados solo admite cerrarle la
// vigencia: el resto de los campos se bloquea.
const locked = computed(() => mode.value === 'edit' && !!selected.value?.in_use);

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
// form se reutiliza para editar (startEdit lo llena con los datos de una
// tarifa real), cerrar una edición sin guardar y abrir "+ Nuevo indicador"
// después podía dejar ver el rastro de esos datos. Por eso openCreate()
// asigna cada campo a mano (no depende de reset()) y closeModal() también
// limpia el form, así que no sobrevive estado de una edición anterior sin
// importar cómo se haya cerrado el modal.
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

function openRate(rate) {
    selectedId.value = rate.id;
    mode.value = 'details';
}

function openCreate() {
    blankForm();
    selectedId.value = null;
    mode.value = 'create';
}

function startEdit() {
    const rate = selected.value;
    form.clearErrors();
    form.tax_type_id = rate.tax_type_id;
    form.code = rate.code;
    form.name = rate.name;
    form.percentage = rate.percentage;
    form.grants_fiscal_credit = rate.grants_fiscal_credit;
    form.fiscal_credit_note = rate.fiscal_credit_note ?? '';
    form.effective_from = rate.effective_from;
    form.effective_to = rate.effective_to ?? '';
    mode.value = 'edit';
}

function cancelForm() {
    if (mode.value === 'create') {
        closeModal();
        return;
    }

    blankForm();
    mode.value = 'details';
}

function closeModal() {
    mode.value = null;
    selectedId.value = null;
    blankForm();
}

// Escape, el fondo o el botón de cerrar la ficha: con la confirmación de eliminar
// abierta encima, se cierra primero esa confirmación.
function onModalClose() {
    if (confirmingDelete.value) {
        confirmingDelete.value = false;
        return;
    }

    closeModal();
}

function submit() {
    if (mode.value === 'create') {
        form.post(route('backoffice.tax-rates.store'), { onSuccess: closeModal, preserveScroll: true });
        return;
    }

    form.put(route('backoffice.tax-rates.update', selected.value.id), {
        preserveScroll: true,
        onSuccess: () => { mode.value = 'details'; },
    });
}

// Eliminar no tiene vuelta atrás: se confirma antes (CLAUDE.md secc. 20).
const confirmingDelete = ref(false);
const deleting = ref(false);

function destroy() {
    deleting.value = true;
    router.delete(route('backoffice.tax-rates.destroy', selected.value.id), {
        preserveScroll: true,
        onSuccess: closeModal,
        onFinish: () => {
            deleting.value = false;
            confirmingDelete.value = false;
        },
    });
}
</script>

<template>
    <Head title="Indicadores de impuesto" />

    <BackofficeLayout title="Indicadores de impuesto">
        <p class="intro muted">
            Catálogo de indicadores de impuesto (IVA 13%, 1%, 2%, 4%, etc.), compartido por todas las compañías — es ley nacional, no varía por compañía.
        </p>

        <div v-if="page.props.errors?.tax_rate" class="flash flash-error">{{ page.props.errors.tax_rate }}</div>

        <div class="view-toolbar">
            <div class="view-actions">
                <button type="button" class="btn btn-primary" @click="openCreate"><PlusIcon /> Crear nuevo</button>
            </div>
        </div>

        <div class="card">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Código</th>
                            <th>Nombre</th>
                            <th class="num">Porcentaje</th>
                            <th>Crédito fiscal</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="rate in rates"
                            :key="rate.id"
                            class="clickable-row"
                            tabindex="0"
                            @click="openRate(rate)"
                            @keydown.enter="openRate(rate)"
                            @keydown.space.prevent="openRate(rate)"
                        >
                            <td class="code-cell">{{ rate.code }}</td>
                            <td data-label="Nombre">{{ rate.name }}</td>
                            <td data-label="Porcentaje" class="num">{{ rate.percentage }}%</td>
                            <td data-label="Crédito fiscal">
                                <span class="badge" :class="rate.grants_fiscal_credit ? 'badge-success' : 'badge-neutral'">
                                    {{ rate.grants_fiscal_credit ? 'Sí' : 'No' }}
                                </span>
                            </td>
                            <td data-label="Estado">
                                <span class="badge" :class="statusOf(rate).cls">{{ statusOf(rate).label }}</span>
                            </td>
                        </tr>
                        <tr v-if="!rates.length">
                            <td colspan="5" class="muted empty-row">Todavía no hay indicadores de impuesto registrados.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <DetailModal :open="modalOpen" :title="modalTitle" @close="onModalClose">
            <template #badge>
                <span v-if="selected && mode !== 'create'" class="badge" :class="statusOf(selected).cls">{{ statusOf(selected).label }}</span>
            </template>

            <template v-if="selected && mode === 'details'">
                <dl class="detail-list">
                    <div class="full">
                        <dt>Nombre</dt>
                        <dd>{{ selected.name }}</dd>
                    </div>
                    <div>
                        <dt>Tipo</dt>
                        <dd>{{ selected.tax_type?.name ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt>Porcentaje</dt>
                        <dd>{{ selected.percentage }}%</dd>
                    </div>
                    <div class="full">
                        <dt>Derecho a crédito fiscal</dt>
                        <dd>
                            <span class="badge" :class="selected.grants_fiscal_credit ? 'badge-success' : 'badge-neutral'">
                                {{ selected.grants_fiscal_credit ? 'Sí' : 'No' }}
                            </span>
                            <span v-if="selected.fiscal_credit_note" class="muted"> {{ selected.fiscal_credit_note }}</span>
                        </dd>
                    </div>
                    <div>
                        <dt>Vigente desde</dt>
                        <dd>{{ selected.effective_from }}</dd>
                    </div>
                    <div>
                        <dt>Vigente hasta</dt>
                        <dd>{{ selected.effective_to ?? 'Sin fecha de cierre' }}</dd>
                    </div>
                </dl>
                <p v-if="selected.in_use" class="hint">
                    Ya se usó en asientos contabilizados: solo se le puede cerrar la vigencia, y no se puede eliminar.
                </p>
                <p v-if="page.props.errors?.tax_rate" class="flash flash-error">{{ page.props.errors.tax_rate }}</p>
            </template>

            <form v-if="mode === 'create' || (selected && mode === 'edit')" id="tax-rate-form" @submit.prevent="submit">
                <h3 v-if="mode === 'edit'" class="section-title">Editar indicador</h3>

                <p v-if="locked" class="muted small">
                    Este indicador ya se usó en asientos contabilizados: solo se le puede cerrar la vigencia.
                    Para un porcentaje nuevo, creá otro indicador (ej. "IVA-13-B").
                </p>

                <div class="field">
                    <label for="tax-rate-type">Tipo de impuesto</label>
                    <select id="tax-rate-type" v-model="form.tax_type_id" :disabled="locked" required>
                        <option v-for="t in taxTypes" :key="t.id" :value="t.id">{{ t.name }}</option>
                    </select>
                </div>

                <div class="field">
                    <label for="tax-rate-code">Código</label>
                    <input id="tax-rate-code" v-model="form.code" type="text" autocomplete="off" placeholder="IVA-13" :disabled="locked" required>
                    <span v-if="form.errors.code" class="error">{{ form.errors.code }}</span>
                </div>

                <div class="field">
                    <label for="tax-rate-name">Nombre</label>
                    <input id="tax-rate-name" v-model="form.name" type="text" autocomplete="off" placeholder="IVA tarifa general 13%" :disabled="locked" required>
                    <span v-if="form.errors.name" class="error">{{ form.errors.name }}</span>
                </div>

                <div class="field">
                    <label for="tax-rate-percentage">Porcentaje</label>
                    <input id="tax-rate-percentage" v-model="form.percentage" type="number" step="0.01" min="0" max="100" :disabled="locked" required>
                    <span v-if="form.errors.percentage" class="error">{{ form.errors.percentage }}</span>
                </div>

                <label class="check-row">
                    <input v-model="form.grants_fiscal_credit" type="checkbox" :disabled="locked">
                    Da derecho a crédito fiscal
                </label>

                <div class="field">
                    <label for="tax-rate-note">Detalle del crédito fiscal (opcional)</label>
                    <input
                        id="tax-rate-note"
                        v-model="form.fiscal_credit_note"
                        type="text"
                        autocomplete="off"
                        placeholder="Crédito pleno / Tarifa reducida / Salvo exportaciones y exoneraciones..."
                        :disabled="locked"
                    >
                    <span class="hint">Matiz libre del derecho a crédito — ej. "Crédito pleno", "Tarifa reducida", "Salvo exportaciones/exoneraciones".</span>
                    <span v-if="form.errors.fiscal_credit_note" class="error">{{ form.errors.fiscal_credit_note }}</span>
                </div>

                <div class="field">
                    <label for="tax-rate-from">Vigente desde</label>
                    <input id="tax-rate-from" v-model="form.effective_from" type="date" :disabled="locked" required>
                    <span v-if="form.errors.effective_from" class="error">{{ form.errors.effective_from }}</span>
                </div>

                <div class="field">
                    <label for="tax-rate-to">Vigente hasta (opcional — vacío = sigue vigente)</label>
                    <input id="tax-rate-to" v-model="form.effective_to" type="date">
                    <span v-if="form.errors.effective_to" class="error">{{ form.errors.effective_to }}</span>
                </div>
            </form>

            <template #actions>
                <template v-if="selected && mode === 'details'">
                    <button v-if="!selected.in_use" type="button" class="btn btn-ghost btn-danger-text" @click="confirmingDelete = true">Eliminar</button>
                    <button type="button" class="btn btn-primary" @click="startEdit">Editar</button>
                </template>
                <template v-else-if="mode === 'create' || mode === 'edit'">
                    <button type="button" class="btn btn-ghost" @click="cancelForm">Cancelar</button>
                    <button type="submit" form="tax-rate-form" class="btn btn-primary" :disabled="form.processing">Guardar</button>
                </template>
            </template>
        </DetailModal>

        <ConfirmModal
            :open="confirmingDelete && !!selected"
            title="Eliminar indicador"
            :message="selected ? `El indicador ${selected.code} se elimina de forma definitiva. Esta acción no se puede deshacer.` : ''"
            confirm-label="Eliminar"
            danger
            :processing="deleting"
            @confirm="destroy"
            @cancel="confirmingDelete = false"
        />
    </BackofficeLayout>
</template>

<style scoped>
.intro {
    font-size: 0.85rem;
    margin: 0 0 0.75rem;
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
.code-cell { font-weight: 600; white-space: nowrap; }
.empty-row { text-align: center; padding: 1.5rem; }

.section-title { margin-top: 0; }

.field {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
}

.field label {
    font-size: 0.78rem;
    color: var(--color-text-muted);
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
    margin-bottom: 0.9rem;
}

.hint {
    display: block;
    font-size: 0.74rem;
    color: var(--color-text-muted);
    margin-top: 0.2rem;
}
</style>
