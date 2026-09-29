<script setup>
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { ArrowLeftIcon, PencilIcon, PlusIcon } from '@lucide/vue';

import AppLayout from '../../Layouts/AppLayout.vue';
import DetailModal from '../../Components/DetailModal.vue';
import { confirmAction } from '../../Utils/confirm';

const props = defineProps({
    documentType: { type: Object, required: true },
    accounts: { type: Array, default: () => [] },
    originModules: { type: Object, required: true },
    currencyModes: { type: Object, required: true },
    bpLineRequirements: { type: Object, required: true },
});

const form = useForm({
    name: props.documentType.name,
    origin_module: props.documentType.origin_module,
    generates_journal: props.documentType.generates_journal,
    requires_electronic_key: props.documentType.requires_electronic_key,
    bp_line_requirement: props.documentType.bp_line_requirement,
    currency_mode: props.documentType.currency_mode,
    default_debit_account_id: props.documentType.default_debit_account_id,
    default_credit_account_id: props.documentType.default_credit_account_id,
    status: props.documentType.status,
});

function submit() {
    form.put(route('document-types.update', props.documentType.id));
}

// --- series de numeración manual ---
//
// Ficha de la serie (CLAUDE.md secc. 20) y alta (secc. 21) en un solo modal:
// la fila lo abre en 'details'; «Crear nuevo», arriba de la tabla, en
// 'create'. Editar ocurre dentro del mismo modal.

const seriesMode = ref('details'); // 'create' | 'details' | 'edit'
const selectedSeriesId = ref(null);

const selectedSeries = computed(() => props.documentType.number_series.find((s) => s.id === selectedSeriesId.value) ?? null);
const seriesModalOpen = computed(() => seriesMode.value === 'create' || !!selectedSeries.value);
const seriesModalTitle = computed(() => (seriesMode.value === 'create' ? 'Nueva serie' : selectedSeries.value?.name ?? ''));

const seriesForm = useForm({ name: '', holder_name: '', range_from: '', range_to: '', is_active: true });

function openCreateSeries() {
    seriesForm.clearErrors();
    Object.assign(seriesForm, { name: '', holder_name: '', range_from: '', range_to: '', is_active: true });
    selectedSeriesId.value = null;
    seriesMode.value = 'create';
}

function openSeries(series) {
    selectedSeriesId.value = series.id;
    seriesMode.value = 'details';
}

function closeSeries() {
    selectedSeriesId.value = null;
    seriesMode.value = 'details';
}

function startEditSeries() {
    const series = selectedSeries.value;
    seriesForm.clearErrors();
    Object.assign(seriesForm, {
        name: series.name,
        holder_name: series.holder_name ?? '',
        range_from: series.range_from,
        range_to: series.range_to,
        is_active: series.is_active,
    });
    seriesMode.value = 'edit';
}

function cancelSeriesForm() {
    if (seriesMode.value === 'create') {
        closeSeries();
        return;
    }

    seriesMode.value = 'details';
}

function submitSeries() {
    if (seriesMode.value === 'create') {
        seriesForm
            .transform(({ name, holder_name, range_from, range_to }) => ({ name, holder_name, range_from, range_to }))
            .post(route('document-type-number-series.store', props.documentType.id), {
                preserveScroll: true,
                onSuccess: closeSeries,
            });
        return;
    }

    seriesForm
        .transform(({ name, holder_name, range_to, is_active }) => ({ name, holder_name, range_to, is_active }))
        .put(route('document-type-number-series.update', selectedSeries.value.id), {
            preserveScroll: true,
            onSuccess: () => { seriesMode.value = 'details'; },
        });
}

function destroySeries() {
    const series = selectedSeries.value;

    confirmAction({
        title: 'Eliminar serie',
        message: `La serie «${series.name}» se elimina de forma definitiva.`,
        confirmLabel: 'Eliminar',
        danger: true,
        onConfirm: () => router.delete(route('document-type-number-series.destroy', series.id), { preserveScroll: true }),
    });
}

function remaining(series) {
    return Math.max(0, series.range_to - series.next_number + 1);
}

function nextNumber(series) {
    return series.next_number > series.range_to ? 'Agotada' : series.next_number;
}
</script>

<template>
    <Head :title="`Editar ${documentType.code}`" />

    <AppLayout :title="`Tipo de documento — ${documentType.code}`">
        <div class="view-toolbar">
            <Link :href="route('document-types.index')" class="btn btn-ghost"><ArrowLeftIcon /> Tipos de documento</Link>
        </div>

        <form class="card form-card" @submit.prevent="submit">
            <div class="form-grid">
                <div class="field">
                    <label for="dt-code">Código</label>
                    <input id="dt-code" :value="documentType.code" type="text" disabled>
                    <span class="muted small">El código no se puede cambiar una vez creado.</span>
                </div>

                <div class="field">
                    <label for="dt-consecutive">Consecutivo interno actual</label>
                    <input id="dt-consecutive" :value="documentType.next_consecutive" type="text" disabled>
                    <span class="muted small">Automático e inalterable.</span>
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
                    <label for="dt-debit">Cuenta débito por defecto</label>
                    <select id="dt-debit" v-model="form.default_debit_account_id">
                        <option :value="null">—</option>
                        <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.code }} — {{ a.description_es }}</option>
                    </select>
                </div>

                <div class="field">
                    <label for="dt-credit">Cuenta crédito por defecto</label>
                    <select id="dt-credit" v-model="form.default_credit_account_id">
                        <option :value="null">—</option>
                        <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.code }} — {{ a.description_es }}</option>
                    </select>
                </div>

                <div class="field">
                    <label for="dt-status">Estado</label>
                    <select id="dt-status" v-model="form.status">
                        <option value="active">Activo</option>
                        <option value="inactive">Inactivo</option>
                    </select>
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

        <section class="series-section">
            <div class="series-header">
                <div>
                    <h2 class="series-title">Series de numeración manual</h2>
                    <p class="muted small series-intro">
                        El consecutivo interno de arriba siempre se asigna solo. Además, opcionalmente,
                        cada documento puede llevar un número de una de estas series (rango fijo, configurable).
                    </p>
                </div>
                <div class="view-actions">
                    <button type="button" class="btn btn-primary" @click="openCreateSeries"><PlusIcon /> Crear nuevo</button>
                </div>
            </div>

            <p v-if="$page.props.errors.series" class="flash flash-error">{{ $page.props.errors.series }}</p>

            <div class="card">
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Nombre</th>
                                <th>Rango</th>
                                <th>Disponibles</th>
                                <th>Estado</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="s in documentType.number_series"
                                :key="s.id"
                                class="clickable-row"
                                tabindex="0"
                                @click="openSeries(s)"
                                @keydown.enter="openSeries(s)"
                                @keydown.space.prevent="openSeries(s)"
                            >
                                <td>{{ s.name }}</td>
                                <td data-label="Rango" class="num">{{ s.range_from }} – {{ s.range_to }}</td>
                                <td data-label="Disponibles" class="num">{{ remaining(s) }}</td>
                                <td data-label="Estado">
                                    <span class="badge" :class="s.is_active ? 'badge-success' : 'badge-neutral'">
                                        {{ s.is_active ? 'Activa' : 'Inactiva' }}
                                    </span>
                                </td>
                            </tr>
                            <tr v-if="!documentType.number_series.length">
                                <td colspan="4" class="muted empty-row">Todavía no hay series configuradas para este tipo de documento.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <DetailModal :open="seriesModalOpen" :title="seriesModalTitle" @close="closeSeries">
            <template #badge>
                <span v-if="selectedSeries && seriesMode !== 'create'" class="badge" :class="selectedSeries.is_active ? 'badge-success' : 'badge-neutral'">
                    {{ selectedSeries.is_active ? 'Activa' : 'Inactiva' }}
                </span>
            </template>

            <dl v-if="selectedSeries && seriesMode === 'details'" class="detail-list">
                <div>
                    <dt>Encargado</dt>
                    <dd>{{ selectedSeries.holder_name || '—' }}</dd>
                </div>
                <div>
                    <dt>Rango</dt>
                    <dd>{{ selectedSeries.range_from }} – {{ selectedSeries.range_to }}</dd>
                </div>
                <div>
                    <dt>Siguiente número</dt>
                    <dd>{{ nextNumber(selectedSeries) }}</dd>
                </div>
                <div>
                    <dt>Disponibles</dt>
                    <dd>{{ remaining(selectedSeries) }}</dd>
                </div>
            </dl>

            <form v-if="seriesMode === 'create' || (selectedSeries && seriesMode === 'edit')" id="series-form" @submit.prevent="submitSeries">
                <p v-if="seriesMode === 'edit'" class="muted small">El número inicial no se puede cambiar una vez creada la serie.</p>

                <div class="field">
                    <label for="series-name">Nombre</label>
                    <input id="series-name" v-model="seriesForm.name" type="text" placeholder="PJ01" required>
                    <span v-if="seriesForm.errors.name" class="error">{{ seriesForm.errors.name }}</span>
                </div>

                <div class="field">
                    <label for="series-holder">Encargado (opcional)</label>
                    <input id="series-holder" v-model="seriesForm.holder_name" type="text" placeholder="Pedro Jiménez">
                    <span class="muted small">A quién se le entrega este talonario/rango — para identificar quién cobró.</span>
                    <span v-if="seriesForm.errors.holder_name" class="error">{{ seriesForm.errors.holder_name }}</span>
                </div>

                <div class="field-row">
                    <div class="field">
                        <label for="series-from">Número inicial</label>
                        <input id="series-from" v-model="seriesForm.range_from" type="number" min="1" :disabled="seriesMode === 'edit'" required>
                        <span v-if="seriesForm.errors.range_from" class="error">{{ seriesForm.errors.range_from }}</span>
                    </div>
                    <div class="field">
                        <label for="series-to">Número final</label>
                        <input id="series-to" v-model="seriesForm.range_to" type="number" min="1" required>
                        <span v-if="seriesForm.errors.range_to" class="error">{{ seriesForm.errors.range_to }}</span>
                    </div>
                </div>

                <label v-if="seriesMode === 'edit'" class="check">
                    <input v-model="seriesForm.is_active" type="checkbox">
                    Activa
                </label>
            </form>

            <template #actions>
                <template v-if="selectedSeries && seriesMode === 'details'">
                    <button type="button" class="btn btn-ghost btn-danger-text" @click="destroySeries">Eliminar</button>
                    <button type="button" class="btn btn-primary" @click="startEditSeries"><PencilIcon /> Editar</button>
                </template>
                <template v-else-if="seriesModalOpen">
                    <button type="button" class="btn btn-ghost" @click="cancelSeriesForm">Cancelar</button>
                    <button type="submit" form="series-form" class="btn btn-primary" :disabled="seriesForm.processing">
                        {{ seriesMode === 'create' ? 'Crear' : 'Guardar' }}
                    </button>
                </template>
            </template>
        </DetailModal>
    </AppLayout>
</template>

<style scoped>
.form-card { padding: 1.25rem; }
.check { font-size: 0.85rem; margin-bottom: 0.75rem; }

.series-section { margin-top: 1.5rem; }

.series-header {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 0.75rem 1rem;
    margin-bottom: 0.9rem;
}

.series-title { margin: 0 0 0.2rem; font-size: 0.95rem; }
.series-intro { margin: 0; }

table { font-size: 0.85rem; }
</style>
