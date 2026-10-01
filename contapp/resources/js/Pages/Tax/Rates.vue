<script setup>
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import DetailModal from '../../Components/DetailModal.vue';
import { confirmAction } from '../../Utils/confirm';
import { useCrudModal } from '../../Utils/crudModal';
import { PencilIcon, PlusIcon } from '@lucide/vue';

const props = defineProps({
    rates: { type: Array, default: () => [] },
    taxTypes: { type: Array, default: () => [] },
});

const page = usePage();
const today = new Date().toISOString().slice(0, 10);
const search = ref('');

function isVigente(rate) {
    return rate.effective_from <= today && (! rate.effective_to || rate.effective_to >= today);
}

function statusLabel(rate) {
    if (isVigente(rate)) return 'Vigente';
    return rate.effective_from > today ? 'Futura' : 'Vencida';
}

const filtered = computed(() => {
    const q = search.value.trim().toLowerCase();
    if (! q) return props.rates;

    return props.rates.filter((r) => r.code.toLowerCase().includes(q) || r.name.toLowerCase().includes(q));
});

// --- alta/edición de indicadores PROPIOS (el catálogo nacional, is_global,
// es de solo lectura acá — se administra desde el panel de CONTAPP) --------

// Al crear se puede escoger un tipo de impuesto existente o inventar uno
// nuevo; se manda solo lo del modo elegido.
const typeMode = ref('existing');

function payload(data) {
    return typeMode.value === 'new'
        ? { ...data, tax_type_id: null }
        : { ...data, new_tax_type_code: '', new_tax_type_name: '' };
}

const { mode, selected, modalOpen, form, openCreate: openCrudCreate, openDetail, close, startEdit: startCrudEdit, cancelForm, submit } = useCrudModal({
    records: () => props.rates,
    defaults: () => ({
        tax_type_id: props.taxTypes[0]?.id ?? null,
        new_tax_type_code: '',
        new_tax_type_name: '',
        code: '',
        name: '',
        percentage: '',
        grants_fiscal_credit: false,
        fiscal_credit_note: '',
        effective_from: today,
        effective_to: '',
    }),
    toForm: (rate) => ({
        tax_type_id: rate.tax_type_id,
        new_tax_type_code: '',
        new_tax_type_name: '',
        code: rate.code,
        name: rate.name,
        percentage: rate.percentage,
        grants_fiscal_credit: rate.grants_fiscal_credit,
        fiscal_credit_note: rate.fiscal_credit_note ?? '',
        effective_from: rate.effective_from,
        effective_to: rate.effective_to ?? '',
    }),
    store: () => route('tax-rates.store'),
    update: (rate) => route('tax-rates.update', rate.id),
    storePayload: payload,
    updatePayload: payload,
});

function openCreate() {
    typeMode.value = 'existing';
    openCrudCreate();
}

function startEdit() {
    typeMode.value = 'existing';
    startCrudEdit();
}

// Ya usado en asientos: solo se le puede cerrar la vigencia.
const locked = computed(() => mode.value === 'edit' && !!selected.value?.in_use);

const modalTitle = computed(() => {
    if (mode.value === 'create') return 'Nuevo indicador propio';
    return selected.value ? `${selected.value.code} — ${selected.value.name}` : '';
});

function destroy() {
    const rate = selected.value;

    confirmAction({
        title: 'Eliminar indicador',
        message: `El indicador ${rate.code} se elimina de forma definitiva.`,
        confirmLabel: 'Eliminar',
        danger: true,
        onConfirm: () => router.delete(route('tax-rates.destroy', rate.id), { preserveScroll: true }),
    });
}
</script>

<template>
    <Head title="Indicadores de impuesto" />

    <AppLayout title="Indicadores de impuesto">
        <div class="view-toolbar">
            <div class="view-actions">
                <button type="button" class="btn btn-primary" @click="openCreate"><PlusIcon /> Crear nuevo</button>
            </div>
            <div class="view-filters">
                <input v-model="search" type="search" placeholder="Buscar código o nombre..." aria-label="Buscar indicador">
            </div>
        </div>

        <p class="hint">
            Los indicadores <strong>nacionales</strong> (IVA 13%, 1%, 2%, 4%, exento) son ley y están compartidos por todas las
            compañías — se administran desde el panel de CONTAPP, acá se ven de solo lectura. También podés crear tus
            <strong>propios indicadores</strong> (ej. un impuesto municipal) que solo afectan a esta compañía, y editarlos o
            eliminarlos libremente mientras no se hayan usado todavía. Para usar cualquier indicador en los asientos, vinculalo
            a una cuenta contable desde el <a :href="route('chart-of-accounts.index')">catálogo de cuentas</a> (campo
            "Indicador de impuesto vinculado"), indicando si esa cuenta es de IVA Soportado o Devengado.
        </p>

        <div v-if="page.props.errors?.tax_rate" class="flash flash-error">{{ page.props.errors.tax_rate }}</div>

        <div class="card">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Código</th>
                            <th>Nombre</th>
                            <th class="num">Porcentaje</th>
                            <th>Origen</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="rate in filtered"
                            :key="rate.id"
                            class="clickable-row"
                            tabindex="0"
                            @click="openDetail(rate)"
                            @keydown.enter="openDetail(rate)"
                            @keydown.space.prevent="openDetail(rate)"
                        >
                            <td class="code-cell">{{ rate.code }}</td>
                            <td data-label="Nombre">{{ rate.name }}</td>
                            <td data-label="Porcentaje" class="num">{{ rate.percentage }}%</td>
                            <td data-label="Origen">
                                <span class="badge" :class="rate.is_global ? 'badge-neutral' : 'badge-success'">
                                    {{ rate.is_global ? 'Nacional' : 'Propio' }}
                                </span>
                            </td>
                            <td data-label="Estado">
                                <span class="badge" :class="isVigente(rate) ? 'badge-success' : 'badge-neutral'">{{ statusLabel(rate) }}</span>
                            </td>
                        </tr>
                        <tr v-if="!filtered.length">
                            <td colspan="5" class="muted empty-row">
                                {{ rates.length ? 'Ningún indicador coincide con la búsqueda.' : 'Todavía no hay indicadores de impuesto registrados.' }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <DetailModal :open="modalOpen" :title="modalTitle" @close="close">
            <template #badge>
                <template v-if="selected && mode !== 'create'">
                    <span class="badge" :class="selected.is_global ? 'badge-neutral' : 'badge-success'">{{ selected.is_global ? 'Nacional' : 'Propio' }}</span>
                    <span class="badge" :class="isVigente(selected) ? 'badge-success' : 'badge-neutral'">{{ statusLabel(selected) }}</span>
                </template>
            </template>

            <template v-if="selected && mode === 'details'">
                <dl class="detail-list">
                    <div>
                        <dt>Tipo</dt>
                        <dd>{{ selected.tax_type?.name ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt>Porcentaje</dt>
                        <dd>{{ selected.percentage }}%</dd>
                    </div>
                    <div>
                        <dt>Derecho a crédito fiscal</dt>
                        <dd>
                            {{ selected.grants_fiscal_credit ? 'Sí' : 'No' }}
                            <span v-if="selected.fiscal_credit_note" class="muted">({{ selected.fiscal_credit_note }})</span>
                        </dd>
                    </div>
                    <div>
                        <dt>Vigente desde</dt>
                        <dd>{{ selected.effective_from }}</dd>
                    </div>
                    <div>
                        <dt>Vigente hasta</dt>
                        <dd>{{ selected.effective_to ?? '—' }}</dd>
                    </div>
                </dl>
                <p v-if="selected.is_global" class="muted small modal-note">
                    Indicador nacional: se administra desde el panel de CONTAPP.
                </p>
            </template>

            <form v-if="mode === 'create' || (selected && mode === 'edit')" id="tax-rate-form" class="rate-form" @submit.prevent="submit">
                <p v-if="locked" class="muted small">
                    Este indicador ya se usó en asientos contabilizados: solo se le puede cerrar la vigencia.
                    Para un porcentaje nuevo, creá otro indicador (ej. "MUNI-1-B").
                </p>

                <template v-if="mode === 'create'">
                    <div class="mode-toggle" role="group" aria-label="Tipo de impuesto">
                        <button type="button" class="mode-btn" :class="{ active: typeMode === 'existing' }" :aria-pressed="typeMode === 'existing'" @click="typeMode = 'existing'">Tipo existente</button>
                        <button type="button" class="mode-btn" :class="{ active: typeMode === 'new' }" :aria-pressed="typeMode === 'new'" @click="typeMode = 'new'">Tipo nuevo</button>
                    </div>

                    <div v-if="typeMode === 'existing'" class="field">
                        <label for="rate-type">Tipo de impuesto</label>
                        <select id="rate-type" v-model="form.tax_type_id" required>
                            <option v-for="t in taxTypes" :key="t.id" :value="t.id">
                                {{ t.name }} ({{ t.is_global ? 'nacional' : 'propio' }})
                            </option>
                        </select>
                        <span v-if="form.errors.tax_type_id" class="error">{{ form.errors.tax_type_id }}</span>
                    </div>
                    <div v-else class="field-row">
                        <div class="field">
                            <label for="rate-new-type-code">Código del tipo nuevo</label>
                            <input id="rate-new-type-code" v-model="form.new_tax_type_code" type="text" autocomplete="off" placeholder="MUNICIPAL">
                            <span v-if="form.errors.new_tax_type_code" class="error">{{ form.errors.new_tax_type_code }}</span>
                        </div>
                        <div class="field">
                            <label for="rate-new-type-name">Nombre del tipo nuevo</label>
                            <input id="rate-new-type-name" v-model="form.new_tax_type_name" type="text" autocomplete="off" placeholder="Impuesto Municipal">
                            <span v-if="form.errors.new_tax_type_name" class="error">{{ form.errors.new_tax_type_name }}</span>
                        </div>
                    </div>
                </template>
                <div v-else class="field">
                    <label for="rate-type-edit">Tipo de impuesto</label>
                    <select id="rate-type-edit" v-model="form.tax_type_id" :disabled="locked" required>
                        <option v-for="t in taxTypes" :key="t.id" :value="t.id">
                            {{ t.name }} ({{ t.is_global ? 'nacional' : 'propio' }})
                        </option>
                    </select>
                </div>

                <div class="field-row">
                    <div class="field">
                        <label for="rate-code">Código</label>
                        <input id="rate-code" v-model="form.code" type="text" autocomplete="off" placeholder="MUNI-1" :disabled="locked" required>
                        <span v-if="form.errors.code" class="error">{{ form.errors.code }}</span>
                    </div>
                    <div class="field">
                        <label for="rate-percentage">Porcentaje</label>
                        <input id="rate-percentage" v-model="form.percentage" type="number" step="0.01" min="0" max="100" :disabled="locked" required>
                        <span v-if="form.errors.percentage" class="error">{{ form.errors.percentage }}</span>
                    </div>
                </div>

                <div class="field">
                    <label for="rate-name">Nombre</label>
                    <input id="rate-name" v-model="form.name" type="text" autocomplete="off" placeholder="Impuesto municipal 1%" :disabled="locked" required>
                    <span v-if="form.errors.name" class="error">{{ form.errors.name }}</span>
                </div>

                <label class="check">
                    <input v-model="form.grants_fiscal_credit" type="checkbox" :disabled="locked">
                    Da derecho a crédito fiscal
                </label>

                <div class="field">
                    <label for="rate-credit-note">Detalle del crédito fiscal (opcional)</label>
                    <input
                        id="rate-credit-note"
                        v-model="form.fiscal_credit_note"
                        type="text"
                        autocomplete="off"
                        placeholder="Crédito pleno / Tarifa reducida / Salvo exportaciones y exoneraciones..."
                        :disabled="locked"
                    >
                    <span v-if="form.errors.fiscal_credit_note" class="error">{{ form.errors.fiscal_credit_note }}</span>
                </div>

                <div class="field-row">
                    <div class="field">
                        <label for="rate-from">Vigente desde</label>
                        <input id="rate-from" v-model="form.effective_from" type="date" :disabled="locked" required>
                        <span v-if="form.errors.effective_from" class="error">{{ form.errors.effective_from }}</span>
                    </div>
                    <div class="field">
                        <label for="rate-to">Vigente hasta (vacío = sigue vigente)</label>
                        <input id="rate-to" v-model="form.effective_to" type="date">
                        <span v-if="form.errors.effective_to" class="error">{{ form.errors.effective_to }}</span>
                    </div>
                </div>
            </form>

            <template #actions>
                <template v-if="selected && mode === 'details'">
                    <template v-if="!selected.is_global">
                        <button type="button" class="btn btn-ghost btn-danger-text" @click="destroy">Eliminar</button>
                        <button type="button" class="btn btn-primary" @click="startEdit"><PencilIcon /> Editar</button>
                    </template>
                </template>
                <template v-else-if="modalOpen">
                    <button type="button" class="btn btn-ghost" @click="cancelForm">Cancelar</button>
                    <button type="submit" form="tax-rate-form" class="btn btn-primary" :disabled="form.processing">
                        {{ mode === 'create' ? 'Crear' : 'Guardar' }}
                    </button>
                </template>
            </template>
        </DetailModal>
    </AppLayout>
</template>

<style scoped>
.hint a { color: var(--color-primary); }
table { font-size: 0.85rem; }
.code-cell { font-variant-numeric: tabular-nums; white-space: nowrap; }
.modal-note { margin: 0.75rem 0 0; }

.rate-form { display: flex; flex-direction: column; gap: 0.75rem; }
.rate-form .field { margin-bottom: 0; }

.mode-toggle { display: flex; gap: 0.3rem; }

.mode-btn {
    flex: 1;
    min-height: 2.25rem;
    background: var(--color-surface-alt);
    border: 1px solid var(--color-control-border);
    border-radius: var(--radius-sm);
    padding: 0.35rem 0.5rem;
    font: inherit;
    font-size: 0.8rem;
    font-weight: 600;
    color: var(--color-text-muted);
    cursor: pointer;
}

.mode-btn.active {
    background: var(--color-primary);
    border-color: var(--color-primary);
    color: var(--color-on-primary);
}
</style>
